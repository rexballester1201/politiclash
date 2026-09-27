<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Api — the real-time surface.
 *
 * The cockpit polls /api/state on a short interval and /api/feed incrementally.
 * Everything here is read-only and guest-safe: a visitor with no account sees
 * the full live board, which is the point — you should be able to watch the
 * game being played before you decide to join it.
 */
class Api extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->output->set_header('Cache-Control: no-store, max-age=0');
    }

    // =========================================================================
    // GET /api/state
    // =========================================================================
    public function state()
    {
        $round = $this->m_round->current();
        $phase = $this->m_round->phase_info($round);

        if (!$round) {
            return $this->json([
                'ok' => true, 'now' => time(), 'round' => null,
                'campaigns' => [], 'me' => $this->me_block(null, null),
                'feed_head' => 0,
                'message' => 'No round has been scheduled yet.',
            ]);
        }

        $proj    = $this->m_round->project($round, $this->me);
        $global  = $proj['_global'];
        unset($proj['_global']);

        $revealed = ($this->me && $this->m_play->event_revealed($round->round_id, $this->me->user_id));

        // ── campaigns ───────────────────────────────────────────────────────
        $campaigns = [];
        foreach (GameEngine::$CAMPAIGNS as $cid => $meta) {
            $p  = $proj[$cid];
            $m  = $p['mults'];
            $st = $p['state'];

            // Allocation shape as percentages — safe to show publicly, it is
            // aggregate and every player can see the same board.
            $tc    = max(1.0, $m['tc']);
            $shape = [];
            foreach (GameEngine::all_keys() as $k) {
                $shape[$k] = round(GameEngine::sd($p['alloc'][$k], $tc) * 100, 1);
            }

            $campaigns[] = [
                'id'          => $cid,
                'name'        => $meta['name'],
                'label'       => $meta['label'],
                'color'       => $meta['color'],
                'power'       => round($p['power'], 2),
                'win_chance'  => round($p['win_chance'] * 100, 1),
                'trust'       => round($m['T'], 3),
                'credibility' => round($m['C'], 3),
                'stability'   => [
                    'state'    => $m['stab']['state'],
                    'label'    => GameEngine::$STABILITY_STATES[$m['stab']['state']]['label'],
                    'score'    => round($m['stab']['score'], 1),
                    'mod'      => $m['stab']['mod'],
                    'collapse' => (bool)$m['stab']['collapse'],
                    'to_next'  => $m['stab']['tokens_to_next'],
                ],
                'aggression'    => round($m['al'], 4),
                'aggr_cost'     => round($m['ac']['final_cost'], 4),
                'burn_rate'     => round($m['ac']['burn_rate'], 3),
                'ag_acts'       => $m['ac']['ag_acts'],
                'saturation'    => $m['msg']['saturation_pct'],
                'pop_mod'       => round($p['pop']['modifier'], 4),
                'pop_state'     => $p['pop']['state'],
                'peace_mod'     => round($p['peace']['modifier'], 4),
                'persist_mod'   => round($p['persist'], 4),
                'eff_defense'   => round($this->gameengine->compute_effective_defense($p['alloc']), 4),
                'conflict_mod'  => round($p['conflict_product'], 4),
                'efficiency'    => round($m['efficiency'], 4),
                'infra'         => round($m['infra'], 4),
                'policy_impact' => round($m['policy_impact'], 4),
                'charisma'      => round($m['charisma_impact'], 4),
                'total_all'     => $st ? round((float)$st->total_all, 0) : 0,
                'total_core'    => $st ? round((float)$st->total_core, 0) : 0,
                'face_total'    => $st ? (int)$st->face_total : 0,
                'contributors'  => $st ? (int)$st->contributor_count : 0,
                'players'       => $st ? (int)$st->player_count : 0,
                'shape'         => $shape,
            ];
        }

        // ── conflicts in flight ─────────────────────────────────────────────
        $threats = [];
        foreach ($this->m_play->pending_conflicts($round->round_id) as $c) {
            // The radar flies a projectile along its trajectory, so it needs to
            // know how far through the flight the conflict is — and the delay is
            // drawn per conflict between RESOLVE_DELAY_MIN and _MAX, so `eta`
            // alone cannot say. `flight` is the whole span; progress is
            // 1 - eta/flight. `id` gives each attack a stable identity, which is
            // what keeps its arc and lane from being redrawn on every poll.
            $flight = strtotime($c->resolve_at) - strtotime($c->created_at);
            $threats[] = [
                'id'        => (int)$c->id,
                'from'      => (int)$c->from_campaign,
                'from_name' => GameEngine::campaign_name($c->from_campaign),
                'to'        => (int)$c->to_campaign,
                'to_name'   => GameEngine::campaign_name($c->to_campaign),
                'eta'       => max(0, strtotime($c->resolve_at) - time()),
                'flight'    => max(1, $flight),
                'severity'  => round((1 - (float)$c->impact) * 100, 1),
                'n'         => (int)$c->n_index,
                'mine'      => ($this->me && (int)$this->me->campaign_id === (int)$c->to_campaign),
            ];
        }

        // ── signals ─────────────────────────────────────────────────────────
        $signals = [];
        foreach ($this->m_play->recent_signals($round->round_id, 8) as $s) {
            $signals[] = [
                'campaign' => (int)$s->campaign_id,
                'name'     => GameEngine::campaign_name($s->campaign_id),
                'type'     => $s->sig_type,
                'actor'    => $s->username,
                'ago'      => time() - strtotime($s->created_at),
            ];
        }

        $head = $this->db->select_max('id', 'x')->get('pc_feed')->row();

        $this->json([
            'ok'    => true,
            'now'   => time(),
            'round' => [
                'no'          => (int)$round->round_no,
                'status'      => $round->status,
                'phase'       => $phase['phase'],
                'frac'        => round($phase['frac'], 4),
                'time_weight' => round($phase['time_weight'], 3),
                'remaining'   => $phase['remaining'],
                'is_open'     => $phase['is_open'],
                'opens_at'    => $round->opens_at,
                'closes_at'   => $round->closes_at,
                'winner'      => $round->winner_campaign === null ? null : (int)$round->winner_campaign,
                'has_event'   => !empty($round->active_event),
                'event'       => (!empty($round->active_event) && $revealed) ? [
                    'name'  => $round->active_event,
                    'desc'  => $this->event_desc($round->active_event),
                    'layer' => $round->event_layer,
                    'super' => (bool)$round->event_is_super,
                ] : null,
            ],
            'campaigns'         => $campaigns,
            'global_aggression' => round($global['global_aggression'], 4),
            'peace_bonus'       => round($global['peace_bonus'], 4),
            'threats'           => $threats,
            'signals'           => $signals,
            'me'                => $this->me_block($round, $proj),
            'feed_head'         => $head ? (int)$head->x : 0,
        ]);
    }

    /** The requesting player's private slice. Empty-ish for guests. */
    private function me_block($round, $proj)
    {
        if (!$this->me) {
            return ['logged_in' => false, 'guest' => true];
        }

        $u   = $this->me;
        $cid = $u->campaign_id === null ? null : (int)$u->campaign_id;

        $ur = ($round && $cid !== null)
            ? $this->m_play->user_round($round->round_id, $u->user_id)
            : null;

        $contributed = $ur ? (int)$ur->contributed : 0;
        $traits      = $this->m_user->traits($u->user_id);
        $active      = [];
        foreach ($traits as $k => $t) {
            if ($t['active']) $active[] = [
                'key'    => $k,
                'label'  => GameEngine::$TRAIT_META[$k]['label'],
                'icon'   => GameEngine::$TRAIT_META[$k]['icon'],
                'effect' => GameEngine::$TRAIT_META[$k]['effect'],
            ];
        }

        $out = [
            'logged_in'     => true,
            'guest'         => false,
            'user_id'       => (int)$u->user_id,
            'username'      => $u->username,
            'verified'      => (bool)$u->email_verified,
            'balance'       => (int)$u->balance,
            'campaign_id'   => $cid,
            'campaign_name' => $cid === null ? null : GameEngine::campaign_name($cid),
            'contributed'   => $contributed,
            'available'     => (int)$u->balance,
            'rounds_played' => (int)$u->rounds_played,
            'rounds_won'    => (int)$u->rounds_won,
            'maturity'      => round($this->m_user->maturity($u), 3),
            'persist_mod'   => round($this->gameengine->persistence_multiplier(
                                  (int)$u->rounds_since_win, (int)$u->rounds_played > 0), 3),
            'rounds_since_win' => (int)$u->rounds_since_win,
            'traits'        => $active,
            'trait_streaks' => $traits,
            'switch_count'  => (int)$u->switch_count,
        ];

        if ($round && $cid !== null) {
            $out['scan_cost']  = $this->gameengine->scan_cost_display(max(100, $contributed));
            $out['pulse_cost'] = $this->gameengine->compute_pulse_cost(max(100, $contributed));
            $out['pulse_off']  = $this->m_play->pulse_active($round->round_id, $cid, 'offense');
            $out['pulse_def']  = $this->m_play->pulse_active($round->round_id, $cid, 'defense');
            $out['event_revealed'] = $this->m_play->event_revealed($round->round_id, $u->user_id);
            $out['can_act']    = ($contributed > 0);

            // §20.2 where the participation floor currently sits
            if ($proj && isset($proj[$cid])) {
                $st  = $proj[$cid]['state'];
                $n   = $st ? max(1, (int)$st->contributor_count) : 1;
                $avg = $st ? ((float)$st->face_total / $n) : 0.0;
                $out['min_threshold'] = (int)ceil($this->gameengine->compute_min_contrib_threshold($avg));
                $out['above_floor']   = $contributed >= $out['min_threshold'];
            }
        }

        return $out;
    }

    /** Moved to GameEngine so the settlement report resolves it the same way. */
    private function event_desc($name)
    {
        return GameEngine::event_desc($name);
    }

    // =========================================================================
    // GET /api/feed?since=<id>
    // =========================================================================
    public function feed()
    {
        $since = (int)$this->input->get('since');
        $rows  = $this->m_play->feed_since($since, 40);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'       => (int)$r->id,
                'kind'     => $r->kind,
                'campaign' => $r->campaign_id === null ? null : (int)$r->campaign_id,
                'actor'    => $r->actor,
                'body'     => $r->body,
                'severity' => $r->severity,
                'ts'       => strtotime($r->created_at),
                'ago'      => time() - strtotime($r->created_at),
            ];
        }
        $this->json(['ok' => true, 'items' => $out, 'head' => $out ? end($out)['id'] : $since]);
    }

    // =========================================================================
    // GET /api/leaders
    // =========================================================================
    public function leaders()
    {
        $rows = $this->m_user->leaderboard(10);
        $out  = [];
        foreach ($rows as $r) {
            $out[] = [
                'username'   => $r->username,
                'campaign'   => $r->campaign_id === null ? null : (int)$r->campaign_id,
                'camp_name'  => $r->campaign_id === null ? '-' : GameEngine::campaign_name($r->campaign_id),
                'is_bot'     => (bool)$r->is_bot,
                'balance'    => (int)$r->balance,
                'rounds'     => (int)$r->rounds_played,
                'wins'       => (int)$r->rounds_won,
                'net'        => (int)$r->net_tokens,
            ];
        }

        // Which campaign has taken the most rounds overall
        $tally = $this->db->select('winner_campaign, COUNT(*) AS n')
                          ->where('status', 'settled')
                          ->where('winner_campaign IS NOT NULL')
                          ->group_by('winner_campaign')
                          ->order_by('n', 'DESC')
                          ->get('pc_rounds')->result();

        $champs = [];
        foreach ($tally as $t) {
            $champs[] = [
                'campaign' => (int)$t->winner_campaign,
                'name'     => GameEngine::campaign_name($t->winner_campaign),
                'wins'     => (int)$t->n,
            ];
        }

        $this->json(['ok' => true, 'leaders' => $out, 'campaign_wins' => $champs]);
    }
}
