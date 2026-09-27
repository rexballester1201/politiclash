<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * M_round — round lifecycle and settlement.
 *
 * A round is one day: opens 00:00, closes 21:00 (Asia/Manila), settles just
 * after close. Everything the engine needs is read from pc_round_campaign,
 * which carries running effective totals so the live cockpit never has to
 * aggregate the whole contribution ledger.
 */
class M_round extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('GameEngine');
        $this->load->model('m_user');
        $this->load->model('m_play');
    }

    // ── lookup ──────────────────────────────────────────────────────────────

    /** The round currently open, or null. */
    public function open_round()
    {
        return $this->db->where('status', 'open')
                        ->order_by('round_no', 'DESC')->limit(1)
                        ->get('pc_rounds')->row();
    }

    /** Open round if there is one, otherwise the most recent settled one. */
    public function current()
    {
        $r = $this->open_round();
        if ($r) return $r;
        return $this->db->order_by('round_no', 'DESC')->limit(1)->get('pc_rounds')->row();
    }

    public function by_id($id)
    {
        return $this->db->get_where('pc_rounds', ['round_id' => (int)$id])->row();
    }

    public function last_settled()
    {
        return $this->db->where('status', 'settled')
                        ->order_by('round_no', 'DESC')->limit(1)
                        ->get('pc_rounds')->row();
    }

    public function recent($limit = 12)
    {
        return $this->db->where('status', 'settled')
                        ->order_by('round_no', 'DESC')->limit((int)$limit)
                        ->get('pc_rounds')->result();
    }

    /**
     * Everything the settlement report needs, for one settled round.
     *
     * Reads only what settle() already wrote. Nothing here recomputes a result —
     * a report that can disagree with the payout is worse than no report, and
     * the engine's own numbers are sitting in pc_round_campaign and
     * pc_user_round waiting to be read.
     *
     * @param  object   $round    a settled pc_rounds row
     * @param  int|null $user_id  null for a guest — the board still explains
     * @return array|null
     */
    public function report($round, $user_id = null)
    {
        if (!$round || $round->status !== 'settled') return null;

        $rows = $this->db->where('round_id', (int)$round->round_id)
                         ->order_by('campaign_id', 'ASC')
                         ->get('pc_round_campaign')->result();
        if (!$rows) return null;

        $winner = $round->winner_campaign === null ? null : (int)$round->winner_campaign;
        $grand  = 0;
        foreach ($rows as $rc) $grand += (int)$rc->face_total;

        $camps = [];
        foreach ($rows as $rc) {
            $cid = (int)$rc->campaign_id;
            $camps[$cid] = [
                'id'         => $cid,
                'name'       => GameEngine::campaign_name($cid),
                'color'      => GameEngine::$CAMPAIGNS[$cid]['color'],
                'won'        => ($winner !== null && $cid === $winner),
                'power'      => (float)$rc->power,
                'win_chance' => (float)$rc->win_chance * 100,
                'face_total' => (int)$rc->face_total,
                'share'      => $grand > 0 ? (int)$rc->face_total / $grand * 100 : 0,
                'backers'    => (int)$rc->contributor_count,
                'stability'  => $rc->stability_state,
                'collapsed'  => (bool)$rc->collapsed,
                'ag_acts'    => (int)$rc->ag_acts,
                'why'        => GameEngine::explain_power($rc),
            ];
        }

        /* THE WINNER IS DRAWN, NOT CROWNED. settle() picks it with draw_winner()
           against the win chances, so the strongest campaign is the favourite and
           nothing more — an underdog taking the round is the system working, and
           it is the most interesting thing that can happen in one. Reporting it
           needs the power leader as well as the winner; a margin alone goes
           negative and says something absurd. */
        $leader = null; $best = -1.0;
        foreach ($camps as $cid => $c) {
            if ($c['power'] > $best) { $best = $c['power']; $leader = $cid; }
        }

        $margin = null;
        if ($winner !== null && isset($camps[$winner])) {
            $rival = 0.0;
            foreach ($camps as $cid => $c) if ($cid !== $winner && $c['power'] > $rival) $rival = $c['power'];
            if ($rival > 0) $margin = ($camps[$winner]['power'] - $rival) / $rival * 100;
        }

        $me = null;
        if ($user_id) {
            $me = $this->db->where(['round_id' => (int)$round->round_id, 'user_id' => (int)$user_id])
                           ->get('pc_user_round')->row();
        }

        return [
            'round'   => $round,
            'winner'  => $winner,
            'leader'  => $leader,          // strongest on power — often not the winner
            'upset'   => ($winner !== null && $leader !== null && $winner !== $leader),
            'margin'  => $margin,
            'camps'   => $camps,
            'grand'   => $grand,
            'pool'    => (int)$round->loser_pool,
            'me'      => $me,
            'event'   => $round->active_event,
        ];
    }

    // ── timing ──────────────────────────────────────────────────────────────

    public function phase_info($round, $now_ts = null)
    {
        $now = $now_ts ? $now_ts : time();
        if (!$round) {
            return ['phase' => 'closed', 'frac' => 0.0, 'time_weight' => 1.0,
                    'remaining' => 0, 'elapsed' => 0, 'is_open' => false];
        }

        $o = strtotime($round->opens_at);
        $c = strtotime($round->closes_at);
        $frac  = $this->gameengine->phase_frac($now, $o, $c);
        $phase = $this->gameengine->phase_for_frac($frac);
        $open  = ($round->status === 'open' && $now >= $o && $now < $c);

        return [
            'phase'       => $open ? $phase : 'closed',
            'raw_phase'   => $phase,
            'frac'        => $frac,
            'time_weight' => $this->gameengine->time_weight($phase, $round->late_weight_x1000),
            'remaining'   => max(0, $c - $now),
            'elapsed'     => max(0, $now - $o),
            'is_open'     => $open,
            'opens_ts'    => $o,
            'closes_ts'   => $c,
        ];
    }

    // ── opening a round ─────────────────────────────────────────────────────

    /**
     * Open today's round. Idempotent — calling it twice does nothing the
     * second time, because cron retries are a fact of life.
     */
    public function open_today()
    {
        if ($this->open_round()) return ['ok' => false, 'reason' => 'already_open'];

        $open_t  = $this->m_user->setting('round_opens', PC_ROUND_OPEN);
        $close_t = $this->m_user->setting('round_closes', PC_ROUND_CLOSE);
        $opens   = date('Y-m-d ').$open_t.':00';
        $closes  = date('Y-m-d ').$close_t.':00';

        // Already ran today?
        $dupe = $this->db->where('opens_at', $opens)->count_all_results('pc_rounds');
        if ($dupe) return ['ok' => false, 'reason' => 'already_created'];

        $last = $this->db->select_max('round_no')->get('pc_rounds')->row();
        $no   = $last && $last->round_no ? (int)$last->round_no + 1 : 1;

        $event = $this->gameengine->draw_event();
        $now   = date('Y-m-d H:i:s');

        $this->db->insert('pc_rounds', [
            'round_no'          => $no,
            'status'            => 'open',
            'opens_at'          => $opens,
            'closes_at'         => $closes,
            'active_event'      => $event ? $event['name']  : null,
            'event_layer'       => $event ? $event['layer'] : null,
            'event_sys'         => $event ? $event['sys']   : null,
            'event_mod'         => $event ? $event['mod']   : null,
            'event_is_super'    => $event ? (int)$event['super'] : 0,
            'late_weight_x1000' => $this->gameengine->draw_late_weight_x1000(),
            'seed'              => bin2hex(random_bytes(8)),
            'created_at'        => $now,
        ]);
        $round_id = (int)$this->db->insert_id();

        // Seed the three campaign rows.
        $counts = $this->m_user->campaign_headcount();
        foreach (GameEngine::$CAMPAIGNS as $cid => $_) {
            $this->db->insert('pc_round_campaign', [
                'round_id'     => $round_id,
                'campaign_id'  => $cid,
                'player_count' => isset($counts[$cid]) ? $counts[$cid] : 0,
                'updated_at'   => $now,
            ]);
        }

        // §23 Apply everyone's carry-over into the opening totals.
        $this->apply_all_carryover($round_id);

        // Faucet, so nobody is permanently locked out by a bad run.
        $topped = $this->m_user->run_stipend();

        $this->m_play->feed($round_id, 'round', null, null,
            'Round '.$no.' is open. Contributions accepted until '.$close_t.'.', 'good');

        if ($event) {
            // The event itself stays hidden — only that one exists is public.
            $this->m_play->feed($round_id, 'event', null, null,
                'An unannounced national event is in play this round. Opposition Research may reveal it.', 'warn');
        }
        if ($topped) {
            $this->m_play->feed($round_id, 'stipend', null, null,
                $topped.' campaign accounts received a solidarity stipend.', 'info');
        }

        return ['ok' => true, 'round_id' => $round_id, 'round_no' => $no, 'event' => $event, 'stipend' => $topped];
    }

    /**
     * §23 Carry-over: half of each player's Logistics and Policy survives.
     * Credited to their campaign's opening totals, then marked consumed.
     */
    private function apply_all_carryover($round_id)
    {
        $rows = $this->db->select('c.*, u.campaign_id AS current_campaign')
                         ->from('pc_user_carryover c')
                         ->join('my_users u', 'u.user_id = c.user_id', 'inner')
                         ->where('c.consumed', 0)
                         ->where('u.status', 'active')
                         ->get()->result();

        $acc = [];
        foreach ($rows as $r) {
            // Only if they are still with the same campaign.
            if ((int)$r->campaign_id !== (int)$r->current_campaign) continue;
            $cid = (int)$r->campaign_id;
            if (!isset($acc[$cid])) $acc[$cid] = ['logistics' => 0.0, 'policy' => 0.0];
            $acc[$cid]['logistics'] += (float)$r->logistics;
            $acc[$cid]['policy']    += (float)$r->policy;
        }

        foreach ($acc as $cid => $v) {
            $total = $v['logistics'] + $v['policy'];
            if ($total <= 0) continue;
            $this->db->where(['round_id' => $round_id, 'campaign_id' => $cid])
                     ->set('logistics',  'logistics + '.(float)$v['logistics'], FALSE)
                     ->set('policy',     'policy + '.(float)$v['policy'], FALSE)
                     ->set('total_core', 'total_core + '.$total, FALSE)
                     ->set('total_all',  'total_all + '.$total, FALSE)
                     ->update('pc_round_campaign');
        }

        $this->db->where('consumed', 0)->update('pc_user_carryover', ['consumed' => 1]);
    }

    // ── campaign state ──────────────────────────────────────────────────────

    /** [campaign_id => row] */
    public function campaign_states($round_id)
    {
        $out  = [];
        $rows = $this->db->get_where('pc_round_campaign', ['round_id' => (int)$round_id])->result();
        foreach ($rows as $r) $out[(int)$r->campaign_id] = $r;
        return $out;
    }

    /** [campaign_id => alloc array] straight from the running totals. */
    public function allocs($round_id)
    {
        $out = [];
        foreach ($this->campaign_states($round_id) as $cid => $row) {
            $out[$cid] = $this->gameengine->alloc_from_row($row);
        }
        foreach (GameEngine::$CAMPAIGNS as $cid => $_) {
            if (!isset($out[$cid])) $out[$cid] = GameEngine::zero_alloc();
        }
        return $out;
    }

    // ── live projection ─────────────────────────────────────────────────────

    /**
     * Compute every campaign's live figures with noise pinned at 1.0 so the
     * cockpit reads stable. This is the same maths settlement runs — the only
     * differences are the noise term and the un-resolved conflicts.
     *
     * @return array [campaign_id => ['power'=>..,'win_chance'=>..,'mults'=>[..], ...]]
     */
    public function project($round, $for_user = null)
    {
        $round_id = $round ? (int)$round->round_id : 0;
        $states   = $this->campaign_states($round_id);
        $allocs   = $this->allocs($round_id);

        $pop   = $this->gameengine->compute_population_modifiers(
                    $this->m_play->per_player_contribs($round_id));
        $peace = $this->gameengine->compute_peace_modifiers($allocs);

        // Resolved, non-backfired conflicts already bite; pending ones do not yet.
        $cprod = $this->m_play->conflict_products($round_id);

        // §23 persistence, contribution-weighted across each campaign's members.
        $persist = $this->campaign_persistence($round_id);

        $out = [];
        foreach (GameEngine::$CAMPAIGNS as $cid => $meta) {
            $st      = isset($states[$cid]) ? $states[$cid] : null;
            $ag_acts = $st ? (int)$st->ag_acts : 0;

            // Trait bonuses are per-player. For a campaign-level projection we
            // use the requesting player's own traits when they belong to this
            // campaign, otherwise none — the same convention the JS engine used.
            $traits = [];
            if ($for_user && (int)$for_user->campaign_id === $cid) {
                $traits = $this->gameengine->compute_trait_bonuses(
                    $this->m_user->traits($for_user->user_id));
            }

            $m = $this->gameengine->compute_power_multipliers($allocs[$cid], $ag_acts, $round, $traits);
            $p = $this->gameengine->compute_campaign_power(
                $m,
                isset($pop[$cid]['modifier']) ? $pop[$cid]['modifier'] : 1.0,
                isset($peace['per_campaign'][$cid]['modifier']) ? $peace['per_campaign'][$cid]['modifier'] : 1.0,
                isset($cprod[$cid]) ? $cprod[$cid] : 1.0,
                isset($persist[$cid]) ? $persist[$cid] : 1.0,
                1.0   // noise pinned — display must not jitter
            );

            $out[$cid] = [
                'power'            => $p,
                'mults'            => $m,
                'pop'              => isset($pop[$cid]) ? $pop[$cid] : ['modifier' => 1.0, 'state' => 'balanced'],
                'peace'            => isset($peace['per_campaign'][$cid]) ? $peace['per_campaign'][$cid] : ['modifier' => 1.0],
                'conflict_product' => isset($cprod[$cid]) ? $cprod[$cid] : 1.0,
                'persist'          => isset($persist[$cid]) ? $persist[$cid] : 1.0,
                'state'            => $st,
                'alloc'            => $allocs[$cid],
            ];
        }

        $powers = [];
        foreach ($out as $cid => $v) $powers[$cid] = $v['power'];
        $wc = $this->gameengine->compute_win_chances($powers);
        foreach ($wc as $cid => $c) $out[$cid]['win_chance'] = $c;

        $out['_global'] = [
            'global_aggression' => $peace['global_aggression'],
            'peace_bonus'       => $peace['peace_bonus'],
        ];
        return $out;
    }

    /**
     * §23 Campaign-level persistence multiplier.
     *
     * The JS engine applied the streak bonus to "the player's campaign" because
     * a prototype has exactly one human. With a real roster that does not
     * generalise, so we take the contribution-weighted mean of every
     * contributor's own multiplier. A campaign carried by people on a winning
     * streak really is running hotter; one contributor cannot swing it alone.
     */
    public function campaign_persistence($round_id)
    {
        $out = [0 => 1.0, 1 => 1.0, 2 => 1.0];

        $rows = $this->db->select('ur.campaign_id, ur.contributed, u.rounds_since_win, u.rounds_played')
                         ->from('pc_user_round ur')
                         ->join('my_users u', 'u.user_id = ur.user_id', 'inner')
                         ->where('ur.round_id', (int)$round_id)
                         ->where('ur.contributed >', 0)
                         ->get()->result();

        $num = [0 => 0.0, 1 => 0.0, 2 => 0.0];
        $den = [0 => 0.0, 1 => 0.0, 2 => 0.0];

        foreach ($rows as $r) {
            $cid = (int)$r->campaign_id;
            if (!isset($num[$cid])) continue;
            $pm = $this->gameengine->persistence_multiplier(
                (int)$r->rounds_since_win, ((int)$r->rounds_played > 0));
            $num[$cid] += $pm * (float)$r->contributed;
            $den[$cid] += (float)$r->contributed;
        }

        foreach ($num as $cid => $n) {
            $out[$cid] = ($den[$cid] > 0) ? ($n / $den[$cid]) : 1.0;
        }
        return $out;
    }

    // ── settlement ──────────────────────────────────────────────────────────

    /**
     * Close and settle a round. Idempotent by status guard.
     *
     * Order matters:
     *   1  resolve every outstanding conflict (so the impact product is final)
     *   2  compute powers with real noise
     *   3  draw the winner from the win chances (the underdog can still win)
     *   4  pay everyone, per outcome class
     *   5  update traits, carry-over and streaks LAST, from the final alloc
     */
    public function settle($round)
    {
        if (!$round || $round->status === 'settled') {
            return ['ok' => false, 'reason' => 'not_settleable'];
        }

        $round_id = (int)$round->round_id;

        /**
         * The whole settlement is ONE transaction, claimed atomically.
         *
         * Two problems this closes, both of which mint tokens from nothing:
         *
         *  1. DOUBLE PAYOUT. The old code read the status, then wrote
         *     'resolving' in a separate statement. Two overlapping cron runs —
         *     trivially possible, the job fires every minute and settlement is
         *     the slowest thing the app does — could both read 'open', both
         *     proceed, and pay every player twice.
         *
         *  2. HALF-PAID ROUNDS. pay_round() credits balances one at a time. A
         *     timeout or fatal partway through left some players paid and some
         *     not, and a retry would pay the first group again.
         *
         * The UPDATE below is a compare-and-swap on 'open'. InnoDB row-locks
         * the round, so a second run blocks until the first commits, then
         * re-evaluates the WHERE, matches nothing, and backs out without
         * touching a balance. Because the status flip is inside the same
         * transaction, a crash rolls it back to 'open' and the next run
         * retries cleanly instead of wedging at 'resolving' forever.
         */
        $this->db->trans_begin();

        $this->db->where('round_id', $round_id)
                 ->where('status', 'open')
                 ->update('pc_rounds', ['status' => 'resolving']);

        if ($this->db->affected_rows() < 1) {
            $this->db->trans_rollback();
            return ['ok' => false, 'reason' => 'already_settling'];
        }

        // 1 ── resolve pending conflicts
        $resolved = $this->m_play->resolve_all_conflicts($round_id, $round);

        // 2 ── powers
        $states  = $this->campaign_states($round_id);
        $allocs  = $this->allocs($round_id);
        $pop     = $this->gameengine->compute_population_modifiers(
                      $this->m_play->per_player_contribs($round_id));
        $peace   = $this->gameengine->compute_peace_modifiers($allocs);
        $cprod   = $this->m_play->conflict_products($round_id);
        $persist = $this->campaign_persistence($round_id);

        $powers = []; $bundle = [];
        foreach (GameEngine::$CAMPAIGNS as $cid => $_) {
            $st      = isset($states[$cid]) ? $states[$cid] : null;
            $ag_acts = $st ? (int)$st->ag_acts : 0;

            $m     = $this->gameengine->compute_power_multipliers($allocs[$cid], $ag_acts, $round, []);
            $noise = $this->gameengine->draw_noise_x1000();
            $p     = $this->gameengine->compute_campaign_power(
                $m,
                isset($pop[$cid]['modifier']) ? $pop[$cid]['modifier'] : 1.0,
                isset($peace['per_campaign'][$cid]['modifier']) ? $peace['per_campaign'][$cid]['modifier'] : 1.0,
                isset($cprod[$cid]) ? $cprod[$cid] : 1.0,
                isset($persist[$cid]) ? $persist[$cid] : 1.0,
                $noise / 1000
            );

            $powers[$cid] = $p;
            $bundle[$cid] = ['m' => $m, 'noise' => $noise];
        }

        $wc      = $this->gameengine->compute_win_chances($powers);
        $winner  = $this->gameengine->draw_winner($wc);

        // 3 ── persist the per-campaign snapshot
        foreach (GameEngine::$CAMPAIGNS as $cid => $_) {
            $m = $bundle[$cid]['m'];
            $this->db->where(['round_id' => $round_id, 'campaign_id' => $cid])
                     ->update('pc_round_campaign', [
                'power'            => $powers[$cid],
                'win_chance'       => $wc[$cid],
                'trust'            => $m['T'],
                'credibility'      => $m['C'],
                'stability_score'  => $m['stab']['score'],
                'stability_state'  => $m['stab']['state'],
                'collapsed'        => $m['stab']['collapse'] ? 1 : 0,
                'pop_mod'          => isset($pop[$cid]['modifier']) ? $pop[$cid]['modifier'] : 1.0,
                'peace_mod'        => isset($peace['per_campaign'][$cid]['modifier']) ? $peace['per_campaign'][$cid]['modifier'] : 1.0,
                'conflict_product' => isset($cprod[$cid]) ? $cprod[$cid] : 1.0,
                'noise_x1000'      => $bundle[$cid]['noise'],
                'mults_json'       => json_encode($this->slim_mults($m)),
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);
        }

        // 4 ── pay everyone
        $face_totals = [];
        foreach (GameEngine::$CAMPAIGNS as $cid => $_) {
            $face_totals[$cid] = isset($states[$cid]) ? (float)$states[$cid]->face_total : 0.0;
        }

        $payout = $this->pay_round($round, $round_id, $winner, $powers, $bundle, $face_totals);

        // 5 ── close the round
        $grand = array_sum($face_totals);
        $this->db->where('round_id', $round_id)->update('pc_rounds', [
            'status'            => 'settled',
            'settled_at'        => date('Y-m-d H:i:s'),
            'winner_campaign'   => $winner,
            'global_aggression' => $peace['global_aggression'],
            'peace_bonus'       => $peace['peace_bonus'],
            'grand_total'       => (int)$grand,
            // What winners actually divided, after the sink and the refunds.
            'loser_pool'        => isset($payout['pool']) ? (int)$payout['pool'] : 0,
        ]);

        // 6 ── commit, or throw the whole thing away
        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            log_message('error', 'PolitiClash: settlement rolled back for round '.$round->round_no);
            return ['ok' => false, 'reason' => 'db_error', 'round' => (int)$round->round_no];
        }
        $this->db->trans_commit();

        // Feed line goes out AFTER the commit — if settlement rolled back there
        // is no result to announce, and this must not be undone by the rollback.
        $this->m_play->feed($round_id, 'result', $winner, null,
            GameEngine::campaign_name($winner).' wins round '.$round->round_no.'. '
            .number_format($payout['paid_out']).' tokens distributed to '.$payout['winners'].' backers.', 'good');

        return [
            'ok' => true, 'winner' => $winner, 'powers' => $powers,
            'win_chances' => $wc, 'conflicts_resolved' => $resolved,
            'paid' => $payout,
        ];
    }

    /** Trim the multiplier bundle down to what the breakdown screen needs. */
    private function slim_mults(array $m)
    {
        return [
            'base_power'      => round($m['base_power'], 3),
            'efficiency'      => round($m['efficiency'], 4),
            'infra'           => round($m['infra'], 4),
            'policy_impact'   => round($m['policy_impact'], 4),
            'charisma_impact' => round($m['charisma_impact'], 4),
            'trust_mod'       => round($m['trust_mod'], 4),
            'cred_mod'        => round($m['cred_mod'], 4),
            'stab_mod'        => $m['stab']['mod'],
            'stab_state'      => $m['stab']['state'],
            'stab_score'      => round($m['stab']['score'], 2),
            'aggr_cost'       => round($m['ac']['final_cost'], 4),
            'burn_rate'       => round($m['ac']['burn_rate'], 3),
            'saturation'      => $m['msg']['saturation_pct'],
            'pow_event_mod'   => $m['pow_event_mod'],
        ];
    }

    /**
     * Distribute the pool. Winners split it 60/40 (pro-rata / team-weighted);
     * sustainable losers recover 15%; collapsed losers get nothing.
     */
    private function pay_round($round, $round_id, $winner, array $powers, array $bundle, array $face_totals)
    {
        $rows = $this->db->select('ur.*, u.rounds_played, u.created_at, u.username, u.is_bot')
                         ->from('pc_user_round ur')
                         ->join('my_users u', 'u.user_id = ur.user_id', 'inner')
                         ->where('ur.round_id', $round_id)
                         ->get()->result();

        if (!$rows) return ['paid_out' => 0, 'winners' => 0, 'players' => 0];

        // Average contribution per campaign, for the participation floor.
        $sum = [0 => 0.0, 1 => 0.0, 2 => 0.0];
        $cnt = [0 => 0,   1 => 0,   2 => 0];
        foreach ($rows as $r) {
            $c = (int)$r->campaign_id;
            if (!isset($sum[$c])) continue;
            $sum[$c] += (float)$r->contributed;
            $cnt[$c]++;
        }

        // ── pass 1 ──────────────────────────────────────────────────────────
        // Classify everyone, total the team weights that divide the pool, and
        // total what the sustainable losers are owed. The refunds have to be
        // known BEFORE the pool is sized, because they come out of it.
        $soft     = $this->gameengine->compute_soft_cap($face_totals[$winner]);
        $team_sum = 0.0;
        $refunds  = 0;
        $plan     = [];

        foreach ($rows as $r) {
            $cid     = (int)$r->campaign_id;
            $contrib = (float)$r->contributed;
            $mat     = $this->gameengine->compute_maturity(
                          (int)$r->rounds_played,
                          max(0, (int)floor((time() - strtotime($r->created_at)) / 86400)));

            $avg   = ($cnt[$cid] > 0) ? $sum[$cid] / $cnt[$cid] : 0.0;
            $floor = $this->gameengine->compute_min_contrib_threshold($avg);
            $above = ($contrib >= $floor);

            $stab    = isset($bundle[$cid]) ? $bundle[$cid]['m']['stab'] : null;
            $outcome = $this->gameengine->classify_outcome($winner, $cid, $powers, $stab);

            $plan[$r->id] = ['row' => $r, 'cid' => $cid, 'contrib' => $contrib,
                             'mat' => $mat, 'above' => $above, 'outcome' => $outcome];

            if ($contrib <= 0 || !$above) continue;

            if ($cid === (int)$winner) {
                $effc = $this->gameengine->compute_effective_contribution($contrib, $soft);
                $team_sum += $this->gameengine->compute_team_weight($effc, $mat);
            } elseif ($outcome === 'resilient') {
                $refunds += (int)floor($contrib * GameEngine::$OUTCOME_MULTIPLIERS['resilient']);
            }
        }

        // The pool winners actually divide: the losing side's stake, less the
        // 5% sink, less what the sustainable losers take back with them.
        $grand_all  = array_sum($face_totals);
        $loser_take = $grand_all - $face_totals[$winner];
        $pool       = max(0, (int)floor($loser_take * (1 - GameEngine::PLATFORM_FEE)) - $refunds);

        // ── pass 2 ──────────────────────────────────────────────────────────
        $paid = 0; $winners = 0;
        $now  = date('Y-m-d H:i:s');

        foreach ($rows as $r) {
            $p       = $plan[$r->id];
            $cid     = $p['cid'];
            $contrib = $p['contrib'];
            $mat     = $p['mat'];

            if ($contrib <= 0) {
                $this->db->where('id', $r->id)->update('pc_user_round', [
                    'reward' => 0, 'profit' => 0, 'outcome' => 'observer',
                    'maturity' => $mat, 'settled_at' => $now,
                ]);
                $this->m_user->record_round_result($r->user_id, false, $round->round_no);
                continue;
            }

            $outcome = $p['outcome'];
            $above   = $p['above'];   // §20.2 participation floor — dust does not earn

            $res = $this->gameengine->compute_reward(
                $winner, $cid, $above ? $contrib : 0,
                $face_totals, $outcome, $mat, $team_sum, $pool
            );

            $this->db->where('id', $r->id)->update('pc_user_round', [
                'reward'           => $res['reward'],
                'profit'           => $res['reward'] - (int)$contrib,
                'outcome'          => $outcome,
                'efficiency_score' => $res['efficiency_score'],
                'excluded_reward'  => $above ? 0 : 1,
                'maturity'         => $mat,
                'settled_at'       => $now,
            ]);

            if ($res['reward'] > 0) {
                $this->m_user->credit($r->user_id, $res['reward']);
                $paid += $res['reward'];
            }
            if ($cid === (int)$winner) $winners++;

            $this->m_user->record_round_result($r->user_id, $cid === (int)$winner, $round->round_no);

            // §23 traits + carry-over, from what this player actually ran.
            $alloc = $this->m_play->user_alloc($round_id, $r->user_id);
            $this->m_user->update_traits($r->user_id, $alloc, $round->round_no);
            $this->m_user->set_carryover($r->user_id, $cid, $round_id,
                $this->gameengine->build_carryover($alloc));
        }

        return ['paid_out' => $paid, 'winners' => $winners, 'players' => count($rows),
                'pool' => $pool, 'refunds' => $refunds];
    }
}
