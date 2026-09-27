<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * M_play — everything a participant does inside a round.
 *
 * Contributions, conflicts, pulses, scans, signals and the live feed. Bots go
 * through exactly these methods, so a bot can never do something a person
 * cannot, and every rule is enforced in one place.
 */
class M_play extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('GameEngine');
    }

    // ── the live feed ───────────────────────────────────────────────────────

    public function feed($round_id, $kind, $campaign_id, $actor, $body, $severity = 'info')
    {
        $this->db->insert('pc_feed', [
            'round_id'    => $round_id ? (int)$round_id : null,
            'kind'        => $kind,
            'campaign_id' => ($campaign_id === null) ? null : (int)$campaign_id,
            'actor'       => $actor,
            'body'        => mb_substr($body, 0, 255),
            'severity'    => $severity,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        return (int)$this->db->insert_id();
    }

    /**
     * Incremental poll — returns OLDEST-first, and that is deliberate.
     *
     * The client prepends each row it receives, so feeding it in ascending
     * order leaves the newest sitting on top once the loop finishes. Api::feed()
     * also takes end() of this array as the new head id, which only works while
     * the last element is the highest id. Do not "align" this with
     * feed_latest() below — they are consumed in opposite ways.
     */
    public function feed_since($since_id = 0, $limit = 40)
    {
        $q = $this->db->order_by('id', 'DESC')->limit((int)$limit);
        if ($since_id > 0) $q = $q->where('id >', (int)$since_id);
        $rows = $q->get('pc_feed')->result();
        return array_reverse($rows);
    }

    /**
     * First paint — returns NEWEST-first, matching the order the ticker grows in.
     *
     * This used to reverse into ascending order, so the server painted the
     * oldest event at the top while every row arriving afterwards was prepended
     * above it. The result was a feed you had to scroll DOWN to read, whose
     * newest entries then appeared off-screen above. Same order both ways now:
     * newest on top, always visible without scrolling.
     */
    public function feed_latest($limit = 30)
    {
        return $this->db->order_by('id', 'DESC')->limit((int)$limit)->get('pc_feed')->result();
    }

    /** Housekeeping — the ticker is disposable, keep the table small. */
    public function prune_feed($keep = 3000)
    {
        $row = $this->db->select('id')->order_by('id', 'DESC')
                        ->limit(1, (int)$keep)->get('pc_feed')->row();
        if ($row) $this->db->where('id <', (int)$row->id)->delete('pc_feed');
    }

    // ── per-user round row ──────────────────────────────────────────────────

    public function user_round($round_id, $user_id, $campaign_id = null, $create = false)
    {
        $row = $this->db->get_where('pc_user_round',
            ['round_id' => (int)$round_id, 'user_id' => (int)$user_id])->row();

        if (!$row && $create && $campaign_id !== null) {
            $this->db->insert('pc_user_round', [
                'round_id'    => (int)$round_id,
                'user_id'     => (int)$user_id,
                'campaign_id' => (int)$campaign_id,
            ]);
            $row = $this->db->get_where('pc_user_round',
                ['round_id' => (int)$round_id, 'user_id' => (int)$user_id])->row();
        }
        return $row;
    }

    /** What one player actually put where this round, as an alloc array. */
    public function user_alloc($round_id, $user_id)
    {
        $a = GameEngine::zero_alloc();
        $rows = $this->db->select('system_key, SUM(effective_amount) AS amt')
                         ->where(['round_id' => (int)$round_id, 'user_id' => (int)$user_id])
                         ->group_by('system_key')
                         ->get('pc_contributions')->result();
        foreach ($rows as $r) {
            if (isset($a[$r->system_key])) $a[$r->system_key] = (float)$r->amt;
        }
        return $a;
    }

    /**
     * §9.6 Per-player contribution lists, keyed by campaign.
     * Feeds the population weighting — this is why a campaign of many small
     * backers is not the same as a campaign of a few big ones.
     */
    public function per_player_contribs($round_id)
    {
        $out = [0 => [], 1 => [], 2 => []];
        $rows = $this->db->select('campaign_id, contributed')
                         ->where('round_id', (int)$round_id)
                         ->where('contributed >', 0)
                         ->get('pc_user_round')->result();
        foreach ($rows as $r) {
            $c = (int)$r->campaign_id;
            if (isset($out[$c])) $out[$c][] = (float)$r->contributed;
        }
        return $out;
    }

    // ── contributing ────────────────────────────────────────────────────────

    /**
     * §5 Confirm a batch of allocations.
     *
     * $amounts is [system_key => face tokens]. Everything is validated here:
     * balance, round state, token granularity, and the late-phase cap that
     * stops a whale sitting out the day and dumping at 20:59.
     */
    public function contribute($round, $user, array $amounts, array $phase)
    {
        if (!$round || $round->status !== 'open' || !$phase['is_open']) {
            return ['ok' => false, 'reason' => 'round_closed'];
        }
        if ($user->campaign_id === null) {
            return ['ok' => false, 'reason' => 'no_campaign'];
        }

        $cid  = (int)$user->campaign_id;
        $keys = GameEngine::all_keys();

        // Normalise: whole hundreds only, never negative.
        $clean = []; $face_total = 0;
        foreach ($keys as $k) {
            $v = isset($amounts[$k]) ? (int)$amounts[$k] : 0;
            $v = max(0, (int)(floor($v / 100) * 100));
            if ($v > 0) { $clean[$k] = $v; $face_total += $v; }
        }
        if ($face_total <= 0) return ['ok' => false, 'reason' => 'nothing_to_place'];
        if ($face_total > (int)$user->balance) return ['ok' => false, 'reason' => 'insufficient_balance'];

        $ur       = $this->user_round($round->round_id, $user->user_id, $cid, true);
        $so_far   = $ur ? (int)$ur->contributed : 0;

        // §5 Late-phase cap
        if ($phase['phase'] === 'late') {
            $st  = $this->db->get_where('pc_round_campaign',
                     ['round_id' => (int)$round->round_id, 'campaign_id' => $cid])->row();
            $n   = max(1, $st ? (int)$st->contributor_count : 1);
            $avg = $st ? ((float)$st->total_core / $n) : 0.0;
            $cap = (int)floor($this->gameengine->max_late_contrib($so_far, $avg));
            if ($cap > 0 && $face_total > $cap) {
                return ['ok' => false, 'reason' => 'late_cap', 'cap' => $cap];
            }
        }

        $tw    = $phase['time_weight'];
        $twx   = (int)round($tw * 1000);
        $now   = date('Y-m-d H:i:s');
        $first = ($so_far === 0);

        $this->db->trans_start();

        // Debit first — the conditional UPDATE is the concurrency guard.
        $this->db->where('user_id', (int)$user->user_id)
                 ->where('balance >=', $face_total)
                 ->set('balance', 'balance - '.$face_total, FALSE)
                 ->set('lifetime_spent', 'lifetime_spent + '.$face_total, FALSE)
                 ->update('my_users');
        if ($this->db->affected_rows() < 1) {
            $this->db->trans_complete();
            return ['ok' => false, 'reason' => 'insufficient_balance'];
        }

        $eff_by_key = []; $eff_core = 0.0; $eff_all = 0.0; $batch = [];

        foreach ($clean as $k => $face) {
            $emod = $this->gameengine->contrib_event_mod($k, $round);
            $eff  = $this->gameengine->effective_amount($face, $k, $tw, $round);

            $eff_by_key[$k] = $eff;
            $eff_all += $eff;
            if (in_array($k, GameEngine::$CORE_KEYS, true)) $eff_core += $eff;

            $batch[] = [
                'round_id'          => (int)$round->round_id,
                'user_id'           => (int)$user->user_id,
                'campaign_id'       => $cid,
                'system_key'        => $k,
                'face_amount'       => $face,
                'effective_amount'  => $eff,
                'time_weight_x1000' => $twx,
                'event_mod_x1000'   => (int)round($emod * 1000),
                'phase'             => $phase['phase'],
                'created_at'        => $now,
            ];
        }

        $this->db->insert_batch('pc_contributions', $batch);

        // Roll the campaign's running totals forward.
        $this->db->where(['round_id' => (int)$round->round_id, 'campaign_id' => $cid]);
        foreach ($eff_by_key as $k => $eff) {
            $this->db->set($k, $k.' + '.(float)$eff, FALSE);
        }
        $this->db->set('total_core', 'total_core + '.$eff_core, FALSE)
                 ->set('total_all',  'total_all + '.$eff_all, FALSE)
                 ->set('face_total', 'face_total + '.$face_total, FALSE)
                 ->set('updated_at', $now)
                 ->update('pc_round_campaign');

        if ($first) {
            $this->db->where(['round_id' => (int)$round->round_id, 'campaign_id' => $cid])
                     ->set('contributor_count', 'contributor_count + 1', FALSE)
                     ->update('pc_round_campaign');
        }

        $this->db->where('id', (int)$ur->id)
                 ->set('contributed', 'contributed + '.$face_total, FALSE)
                 ->set('eff_contributed', 'eff_contributed + '.$eff_all, FALSE)
                 ->update('pc_user_round');

        $this->db->trans_complete();
        if ($this->db->trans_status() === FALSE) {
            return ['ok' => false, 'reason' => 'db_error'];
        }

        // Feed line — name the biggest system so the ticker reads like news.
        arsort($clean);
        $top = key($clean);
        $this->feed($round->round_id, 'contribution', $cid, $user->username,
            $user->username.' committed '.number_format($face_total).' to '
            .GameEngine::$SYS_META[$top]['label'].' and '.max(0, count($clean) - 1).' other systems.',
            'info');

        return [
            'ok'          => true,
            'face'        => $face_total,
            'effective'   => $eff_all,
            'time_weight' => $tw,
            'phase'       => $phase['phase'],
            'lost_to_timing' => max(0, $face_total - $eff_all),
        ];
    }

    // ── conflicts (§10) ─────────────────────────────────────────────────────

    /** Π of resolved, non-backfired impacts against each campaign. */
    public function conflict_products($round_id)
    {
        $out = [0 => 1.0, 1 => 1.0, 2 => 1.0];
        $rows = $this->db->select('to_campaign, impact')
                         ->where(['round_id' => (int)$round_id, 'resolved' => 1, 'backfired' => 0])
                         ->get('pc_conflicts')->result();
        foreach ($rows as $r) {
            $c = (int)$r->to_campaign;
            if (isset($out[$c])) $out[$c] *= (float)$r->impact;
        }
        return $out;
    }

    public function pending_conflicts($round_id, $to_campaign = null)
    {
        $this->db->where(['round_id' => (int)$round_id, 'resolved' => 0]);
        if ($to_campaign !== null) $this->db->where('to_campaign', (int)$to_campaign);
        return $this->db->order_by('resolve_at', 'ASC')->get('pc_conflicts')->result();
    }

    public function all_conflicts($round_id)
    {
        return $this->db->where('round_id', (int)$round_id)
                        ->order_by('id', 'DESC')->limit(40)->get('pc_conflicts')->result();
    }

    /**
     * §10 Open a conflict. Returns null when the target is not worth attacking
     * (target score under the floor) or a Cold Campaign is suppressing conflict.
     */
    public function open_conflict($round, $from_cid, $to_cid, $user, array $allocs,
                                  array $powers, $att_traits = [], $def_traits = [])
    {
        $ev = $this->gameengine->conflict_event_mod($round);
        if ($ev['cold']) return ['ok' => false, 'reason' => 'cold_campaign'];
        if ((int)$from_cid === (int)$to_cid) return ['ok' => false, 'reason' => 'same_campaign'];

        $att = $allocs[$from_cid];
        $def = $allocs[$to_cid];

        $def_bonus = isset($def_traits['eff_defense']) ? (float)$def_traits['eff_defense'] : 0.0;
        $att_bonus = isset($att_traits['outcome_factor']) ? (float)$att_traits['outcome_factor'] : 0.0;

        $ts = $this->gameengine->compute_target_score(
            $att, max(1.0, $powers[$from_cid]), $def, max(1.0, $powers[$to_cid]), $def_bonus);

        if ($ts < GameEngine::TARGET_SCORE_MIN) {
            return ['ok' => false, 'reason' => 'target_too_hard', 'target_score' => $ts];
        }

        // §10.7 N is 1-based over UNRESOLVED attacks already aimed at this target.
        $n = 1 + (int)$this->db->where([
                'round_id' => (int)$round->round_id,
                'to_campaign' => (int)$to_cid,
                'resolved' => 0,
            ])->count_all_results('pc_conflicts');

        $pulse_off = $this->pulse_active($round->round_id, $from_cid, 'offense');
        $pulse_def = $this->pulse_active($round->round_id, $to_cid,   'defense');

        $eci = $this->gameengine->compute_effective_conflict_impact(
            $att, $def, $n, $pulse_off, $pulse_def, $round, $def_bonus, $att_bonus);
        $bfc = $this->gameengine->compute_backfire_chance($att, $ev['bf_double']);

        $delay = $this->gameengine->draw_resolve_delay();
        $now   = time();

        $this->db->insert('pc_conflicts', [
            'round_id'          => (int)$round->round_id,
            'from_campaign'     => (int)$from_cid,
            'to_campaign'       => (int)$to_cid,
            'initiator_user_id' => $user ? (int)$user->user_id : null,
            'impact'            => $eci,
            'backfire_chance'   => $bfc,
            'n_index'           => $n,
            'target_score'      => $ts,
            'outcome_factor'    => $this->gameengine->compute_outcome_factor($att, $def, $att_bonus),
            'attack_cred_mod'   => $this->gameengine->compute_attack_cred_mod($att),
            'multi_atk_penalty' => $this->gameengine->compute_multi_attack_penalty($n),
            'pulse_off'         => $pulse_off ? 1 : 0,
            'pulse_def'         => $pulse_def ? 1 : 0,
            'resolve_at'        => date('Y-m-d H:i:s', $now + $delay),
            'created_at'        => date('Y-m-d H:i:s', $now),
        ]);

        // §9.5 Opening a conflict is what burns budget efficiency, not allocating.
        $this->db->where(['round_id' => (int)$round->round_id, 'campaign_id' => (int)$from_cid])
                 ->set('ag_acts', 'ag_acts + 1', FALSE)
                 ->update('pc_round_campaign');

        $this->feed($round->round_id, 'attack', $from_cid, $user ? $user->username : null,
            GameEngine::campaign_name($from_cid).' opened an offensive against '
            .GameEngine::campaign_name($to_cid).'. Impact lands in '.ceil($delay / 60).' min.',
            'bad');

        return ['ok' => true, 'impact' => $eci, 'backfire_chance' => $bfc,
                'eta' => $delay, 'n' => $n, 'target_score' => $ts];
    }

    /** Resolve conflicts whose timer has expired. Called by the tick cron. */
    public function resolve_due_conflicts($round_id, $round = null)
    {
        $due = $this->db->where(['round_id' => (int)$round_id, 'resolved' => 0])
                        ->where('resolve_at <=', date('Y-m-d H:i:s'))
                        ->get('pc_conflicts')->result();
        return $this->do_resolve($due, $round_id, $round);
    }

    /** Force-resolve everything still pending. Called at settlement. */
    public function resolve_all_conflicts($round_id, $round = null)
    {
        $all = $this->db->where(['round_id' => (int)$round_id, 'resolved' => 0])
                        ->get('pc_conflicts')->result();
        return $this->do_resolve($all, $round_id, $round);
    }

    private function do_resolve($rows, $round_id, $round)
    {
        $ev   = $this->gameengine->conflict_event_mod($round);
        $done = 0;

        foreach ($rows as $c) {
            // §14.2 Cold Campaign — conflicts simply do not land this round.
            if ($ev['cold']) {
                $this->db->where('id', $c->id)->update('pc_conflicts',
                    ['resolved' => 1, 'backfired' => 0, 'impact' => 1.0]);
                $done++;
                continue;
            }

            $backfired = (GameEngine::rand_float(0, 1) < (float)$c->backfire_chance);
            $this->db->where('id', $c->id)->update('pc_conflicts',
                ['resolved' => 1, 'backfired' => $backfired ? 1 : 0]);

            if ($backfired) {
                $this->feed($round_id, 'backfire', (int)$c->from_campaign, null,
                    GameEngine::campaign_name($c->from_campaign).'\'s attack on '
                    .GameEngine::campaign_name($c->to_campaign).' backfired. Voters turned on the messenger.',
                    'warn');
            } else {
                $pct = round((1 - (float)$c->impact) * 100, 1);
                $this->feed($round_id, 'attack', (int)$c->to_campaign, null,
                    GameEngine::campaign_name($c->to_campaign).' absorbed an attack from '
                    .GameEngine::campaign_name($c->from_campaign)
                    .($pct > 0 ? ' — power down '.$pct.'%.' : ' — negligible impact.'),
                    'bad');
            }
            $done++;
        }
        return $done;
    }

    // ── pulses (§10.5) ──────────────────────────────────────────────────────

    public function pulse_active($round_id, $campaign_id, $type)
    {
        return (bool)$this->db->where([
            'round_id'    => (int)$round_id,
            'campaign_id' => (int)$campaign_id,
            'pulse_type'  => $type,
        ])->where('expires_at >', date('Y-m-d H:i:s'))->count_all_results('pc_pulses');
    }

    public function pulse_expiry($round_id, $campaign_id, $type)
    {
        $r = $this->db->select_max('expires_at', 'x')->where([
            'round_id' => (int)$round_id, 'campaign_id' => (int)$campaign_id, 'pulse_type' => $type,
        ])->where('expires_at >', date('Y-m-d H:i:s'))->get('pc_pulses')->row();
        return ($r && $r->x) ? strtotime($r->x) : 0;
    }

    public function add_pulse($round_id, $user, $type, $cost)
    {
        $this->db->insert('pc_pulses', [
            'round_id'    => (int)$round_id,
            'user_id'     => (int)$user->user_id,
            'campaign_id' => (int)$user->campaign_id,
            'pulse_type'  => $type,
            'cost'        => (int)$cost,
            'expires_at'  => date('Y-m-d H:i:s', time() + GameEngine::PULSE_DURATION_S),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->db->where(['round_id' => (int)$round_id, 'user_id' => (int)$user->user_id])
                 ->set('action_spend', 'action_spend + '.(int)$cost, FALSE)
                 ->update('pc_user_round');

        $this->feed($round_id, 'pulse', (int)$user->campaign_id, $user->username,
            $user->username.' surged '.GameEngine::campaign_name($user->campaign_id).'\'s '
            .($type === 'offense' ? 'offensive' : 'defensive').' posture for 15 minutes.', 'info');
    }

    // ── scans (§15) ─────────────────────────────────────────────────────────

    /** How many scans this player has run inside the decay window. */
    public function scan_load($round_id, $user_id)
    {
        return 1 + (int)$this->db->where(['round_id' => (int)$round_id, 'user_id' => (int)$user_id])
            ->where('created_at >', date('Y-m-d H:i:s', time() - GameEngine::SCAN_LOAD_WINDOW_S))
            ->count_all_results('pc_scans');
    }

    public function record_scan($round_id, $user, $target_cid, $accuracy, $cost, array $payload, $revealed)
    {
        $this->db->insert('pc_scans', [
            'round_id'        => (int)$round_id,
            'user_id'         => (int)$user->user_id,
            'target_campaign' => (int)$target_cid,
            'accuracy'        => $accuracy,
            'cost'            => (int)$cost,
            'payload_json'    => json_encode($payload),
            'event_revealed'  => $revealed ? 1 : 0,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->db->where(['round_id' => (int)$round_id, 'user_id' => (int)$user->user_id])
                 ->set('action_spend', 'action_spend + '.(int)$cost, FALSE)
                 ->update('pc_user_round');

        $this->feed($round_id, 'scan', (int)$user->campaign_id, $user->username,
            $user->username.' ran opposition research on '.GameEngine::campaign_name($target_cid).'.', 'info');
    }

    public function recent_scans($round_id, $user_id, $limit = 5)
    {
        return $this->db->where(['round_id' => (int)$round_id, 'user_id' => (int)$user_id])
                        ->order_by('id', 'DESC')->limit((int)$limit)
                        ->get('pc_scans')->result();
    }

    // ── event reveal (§14.1) ────────────────────────────────────────────────

    public function event_revealed($round_id, $user_id)
    {
        return (bool)$this->db->where(['round_id' => (int)$round_id, 'user_id' => (int)$user_id])
                              ->count_all_results('pc_event_reveals');
    }

    public function reveal_event($round_id, $user_id)
    {
        $this->db->replace('pc_event_reveals', [
            'round_id' => (int)$round_id, 'user_id' => (int)$user_id,
            'revealed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ── signals ─────────────────────────────────────────────────────────────

    public function add_signal($round_id, $user, $sig_type, $is_fake = false)
    {
        $this->db->insert('pc_signals', [
            'round_id'    => (int)$round_id,
            'user_id'     => (int)$user->user_id,
            'campaign_id' => (int)$user->campaign_id,
            'sig_type'    => $sig_type,
            'is_fake'     => $is_fake ? 1 : 0,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->feed($round_id, 'signal', (int)$user->campaign_id, $user->username,
            $user->username.' signalled '.$sig_type.' for '
            .GameEngine::campaign_name($user->campaign_id).'.', 'info');
    }

    public function recent_signals($round_id, $limit = 12)
    {
        return $this->db->select('s.*, u.username')
                        ->from('pc_signals s')
                        ->join('my_users u', 'u.user_id = s.user_id', 'left')
                        ->where('s.round_id', (int)$round_id)
                        ->order_by('s.id', 'DESC')->limit((int)$limit)
                        ->get()->result();
    }

    // ── history ─────────────────────────────────────────────────────────────

    public function user_history($user_id, $limit = 20)
    {
        return $this->db->select('ur.*, r.round_no, r.winner_campaign, r.settled_at')
                        ->from('pc_user_round ur')
                        ->join('pc_rounds r', 'r.round_id = ur.round_id', 'inner')
                        ->where('ur.user_id', (int)$user_id)
                        ->where('r.status', 'settled')
                        ->order_by('r.round_no', 'DESC')->limit((int)$limit)
                        ->get()->result();
    }
}
