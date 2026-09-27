<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Game — the player's own surfaces and every command they can issue.
 *
 * GET methods render pages that carry the bottom nav and the normal logo
 * header — no back arrow, because the nav is the way out. The 'nav' option
 * says which item lights up: Advisor, Intel, Threats and Round history all sit
 * under Game, since that is the item whose pull-up opens them.
 * The one exception is choose(), which has no nav on purpose — picking a
 * campaign is a required step and should not offer escape routes.
 * POST methods are JSON commands fired from the cockpit's floating pads and
 * the allocation console.
 *
 * Every rule lives in GameEngine or M_play. This controller only checks who is
 * asking, validates shape, and reports the outcome.
 */
class Game extends MY_Controller
{
    // =========================================================================
    // PAGES
    // =========================================================================

    public function index()
    {
        redirect(base_url());
    }

    /** Pick a campaign. First pick is free; switching costs. */
    public function choose()
    {
        $this->require_login();

        $round = $this->m_round->current();
        $proj  = $round ? $this->m_round->project($round, $this->me) : null;

        $this->view('game/choose', [
            'round' => $round,
            'proj'  => $proj,
            'penalty_next' => $this->me->campaign_id === null ? 0.0
                : $this->gameengine->migration_penalty_rate((int)$this->me->switch_count + 1),
        ], [
            'title' => $this->me->campaign_id === null ? 'Choose your side' : 'Switch campaign',
            'back'  => base_url(),
        ]);
    }

    /** The allocation console. */
    public function allocate()
    {
        $this->require_campaign();

        $round = $this->m_round->current();
        $phase = $this->m_round->phase_info($round);
        $ur    = $round ? $this->m_play->user_round($round->round_id, $this->me->user_id) : null;
        $proj  = $round ? $this->m_round->project($round, $this->me) : null;

        $council = $this->gameengine->council_recommendation(
            $round,
            $round ? $this->m_play->event_revealed($round->round_id, $this->me->user_id) : false,
            [], (int)$this->me->rounds_played
        );

        $this->view('game/allocate', [
            'round'   => $round,
            'phase'   => $phase,
            'ur'      => $ur,
            'proj'    => $proj,
            'council' => $council,
            'mine'    => $round ? $this->m_play->user_alloc($round->round_id, $this->me->user_id) : GameEngine::zero_alloc(),
        ], ['title' => 'Allocate', 'back' => base_url(), 'nav' => 'allocate']);
    }

    public function account()
    {
        $this->require_login();
        $this->view('game/account', [
            'traits'  => $this->m_user->traits($this->me->user_id),
            'history' => $this->m_play->user_history($this->me->user_id, 15),
            'maturity'=> $this->m_user->maturity($this->me),
            'age_days'=> $this->m_user->account_age_days($this->me),
        ], ['title' => 'Account', 'back' => base_url(), 'nav' => 'account']);
    }

    public function advisor()
    {
        $this->require_campaign();

        $round = $this->m_round->current();
        $proj  = $round ? $this->m_round->project($round, $this->me) : null;
        $ur    = $round ? $this->m_play->user_round($round->round_id, $this->me->user_id) : null;

        $council = $this->gameengine->council_recommendation(
            $round,
            $round ? $this->m_play->event_revealed($round->round_id, $this->me->user_id) : false,
            [], (int)$this->me->rounds_played
        );

        $this->view('game/advisor', [
            'round'   => $round,
            'proj'    => $proj,
            'ur'      => $ur,
            'council' => $council,
            'coach'   => $this->coach_items($round, $proj, $ur),
        ], ['title' => 'Advisor', 'back' => base_url(), 'nav' => 'game']);
    }

    public function intel()
    {
        $this->require_campaign();

        $round = $this->m_round->current();
        $proj  = $round ? $this->m_round->project($round, $this->me) : null;

        $this->view('game/intel', [
            'round'    => $round,
            'proj'     => $proj,
            'scans'    => $round ? $this->m_play->recent_scans($round->round_id, $this->me->user_id, 6) : [],
            'revealed' => $round ? $this->m_play->event_revealed($round->round_id, $this->me->user_id) : false,
            'signals'  => $round ? $this->m_play->recent_signals($round->round_id, 10) : [],
            'history'  => $this->m_round->recent(10),
        ], ['title' => 'Intel', 'back' => base_url(), 'nav' => 'game']);
    }

    public function threats()
    {
        $this->require_campaign();

        $round = $this->m_round->current();
        $proj  = $round ? $this->m_round->project($round, $this->me) : null;

        $this->view('game/threats', [
            'round'     => $round,
            'proj'      => $proj,
            'pending'   => $round ? $this->m_play->pending_conflicts($round->round_id) : [],
            'resolved'  => $round ? $this->m_play->all_conflicts($round->round_id) : [],
        ], ['title' => 'Threats', 'back' => base_url(), 'nav' => 'game']);
    }

    public function history()
    {
        $this->require_login();
        $this->view('game/history', [
            'rounds' => $this->m_round->recent(20),
            'mine'   => $this->m_play->user_history($this->me->user_id, 20),
        ], ['title' => 'Round history', 'back' => base_url(), 'nav' => 'game']);
    }

    // =========================================================================
    // COMMANDS (JSON)
    // =========================================================================

    /** POST game/join {campaign_id} */
    public function join()
    {
        if (!$this->require_post_login()) return;

        $cid = (int)$this->input->post('campaign_id');
        if (!isset(GameEngine::$CAMPAIGNS[$cid])) return $this->fail('bad_campaign');

        $round = $this->m_round->current();
        $res = $this->m_user->join_campaign($this->me->user_id, $cid, $round ? $round->round_no : 0);
        if (!$res['ok']) return $this->fail($res['reason']);

        $this->m_play->feed($round ? $round->round_id : null, 'switch', $cid, $this->me->username,
            $res['first']
                ? $this->me->username.' declared for '.GameEngine::campaign_name($cid).'.'
                : $this->me->username.' defected to '.GameEngine::campaign_name($cid)
                  .' — '.round($res['penalty'] * 100).'% of their wallet forfeited.',
            $res['first'] ? 'good' : 'warn');

        $this->json(['ok' => true] + $res);
    }

    /** POST game/confirm {alloc[system]=amount} */
    public function confirm()
    {
        if (!$this->require_post_login()) return;
        if ($this->me->campaign_id === null) return $this->fail('no_campaign');

        $round = $this->m_round->current();
        $phase = $this->m_round->phase_info($round);

        $alloc = $this->input->post('alloc');
        if (!is_array($alloc)) return $this->fail('bad_payload');

        $res = $this->m_play->contribute($round, $this->me, $alloc, $phase);
        if (!$res['ok']) return $this->fail($res['reason'], 400, $res);

        $this->json(['ok' => true] + $res + ['balance' => (int)$this->m_user->by_id($this->me->user_id)->balance]);
    }

    /** POST game/scan {target} */
    public function scan()
    {
        if (!$this->require_post_login()) return;
        if ($this->me->campaign_id === null) return $this->fail('no_campaign');

        $round = $this->m_round->current();
        $phase = $this->m_round->phase_info($round);
        if (!$phase['is_open']) return $this->fail('round_closed');

        $target = (int)$this->input->post('target');
        if (!isset(GameEngine::$CAMPAIGNS[$target])) return $this->fail('bad_target');
        if ($target === (int)$this->me->campaign_id) return $this->fail('cannot_scan_self');

        $ur = $this->m_play->user_round($round->round_id, $this->me->user_id);
        $contributed = $ur ? (int)$ur->contributed : 0;
        if ($contributed <= 0) return $this->fail('must_contribute_first');

        $load     = $this->m_play->scan_load($round->round_id, $this->me->user_id);
        $accuracy = $this->gameengine->compute_scan_accuracy($load);
        $cost     = $this->gameengine->compute_scan_cost($contributed, false);

        if ((int)$this->me->balance < $cost) return $this->fail('insufficient_balance', 400, ['cost' => $cost]);
        if (!$this->m_user->debit($this->me->user_id, $cost)) return $this->fail('insufficient_balance');

        $states  = $this->m_round->campaign_states($round->round_id);
        $allocs  = $this->m_round->allocs($round->round_id);
        $ag_acts = isset($states[$target]) ? (int)$states[$target]->ag_acts : 0;
        $cold    = $this->gameengine->conflict_event_mod($round)['cold'];

        $payload = $this->gameengine->build_scan_payload($allocs[$target], $accuracy, $ag_acts, $cold);

        // §14.1 A scan is also a chance to unmask the round's hidden event.
        $revealed = false;
        if (!empty($round->active_event) && !$this->m_play->event_revealed($round->round_id, $this->me->user_id)) {
            $conf = $this->gameengine->compute_event_confidence($allocs[(int)$this->me->campaign_id]);
            if (GameEngine::rand_float(0, 1) < $conf) {
                $this->m_play->reveal_event($round->round_id, $this->me->user_id);
                $revealed = true;
            }
        }

        $this->m_play->record_scan($round->round_id, $this->me, $target, $accuracy, $cost, $payload, $revealed);

        $this->json([
            'ok' => true, 'cost' => $cost, 'target' => $target,
            'target_name' => GameEngine::campaign_name($target),
            'accuracy' => round($accuracy, 3), 'scan_load' => $load,
            'payload' => $payload,
            'event_revealed' => $revealed,
            'event' => $revealed ? ['name' => $round->active_event, 'desc' => $this->event_desc($round->active_event)] : null,
            'balance' => (int)$this->m_user->by_id($this->me->user_id)->balance,
        ]);
    }

    /** POST game/pulse {type: offense|defense} */
    public function pulse()
    {
        if (!$this->require_post_login()) return;
        if ($this->me->campaign_id === null) return $this->fail('no_campaign');

        $type = (string)$this->input->post('type');
        if (!in_array($type, ['offense', 'defense'], true)) return $this->fail('bad_type');

        $round = $this->m_round->current();
        $phase = $this->m_round->phase_info($round);
        if (!$phase['is_open']) return $this->fail('round_closed');

        $ur = $this->m_play->user_round($round->round_id, $this->me->user_id);
        $contributed = $ur ? (int)$ur->contributed : 0;
        if ($contributed <= 0) return $this->fail('must_contribute_first');

        if ($this->m_play->pulse_active($round->round_id, (int)$this->me->campaign_id, $type)) {
            return $this->fail('already_active');
        }

        $cost = $this->gameengine->compute_pulse_cost($contributed);
        if (!$this->m_user->debit($this->me->user_id, $cost)) {
            return $this->fail('insufficient_balance', 400, ['cost' => $cost]);
        }

        $this->m_play->add_pulse($round->round_id, $this->me, $type, $cost);

        $this->json([
            'ok' => true, 'type' => $type, 'cost' => $cost,
            'expires_in' => GameEngine::PULSE_DURATION_S,
            'balance' => (int)$this->m_user->by_id($this->me->user_id)->balance,
        ]);
    }

    /** POST game/attack {target} */
    public function attack()
    {
        if (!$this->require_post_login()) return;
        if ($this->me->campaign_id === null) return $this->fail('no_campaign');

        $round = $this->m_round->current();
        $phase = $this->m_round->phase_info($round);
        if (!$phase['is_open']) return $this->fail('round_closed');

        $target = (int)$this->input->post('target');
        if (!isset(GameEngine::$CAMPAIGNS[$target])) return $this->fail('bad_target');
        if ($target === (int)$this->me->campaign_id) return $this->fail('cannot_attack_self');

        $ur = $this->m_play->user_round($round->round_id, $this->me->user_id);
        if (!$ur || (int)$ur->contributed <= 0) return $this->fail('must_contribute_first');

        $allocs = $this->m_round->allocs($round->round_id);
        $proj   = $this->m_round->project($round, $this->me);
        $powers = [];
        foreach (GameEngine::$CAMPAIGNS as $cid => $_) $powers[$cid] = $proj[$cid]['power'];

        $my_traits = $this->gameengine->compute_trait_bonuses($this->m_user->traits($this->me->user_id));

        $res = $this->m_play->open_conflict(
            $round, (int)$this->me->campaign_id, $target,
            $this->me, $allocs, $powers, $my_traits, []
        );

        if (!$res['ok']) return $this->fail($res['reason'], 400, $res);
        $this->json(['ok' => true] + $res);
    }

    /** POST game/signal {type} */
    public function signal()
    {
        if (!$this->require_post_login()) return;
        if ($this->me->campaign_id === null) return $this->fail('no_campaign');

        $type = (string)$this->input->post('type');
        if (!in_array($type, GameEngine::$SIG_TYPES, true)) return $this->fail('bad_type');

        $round = $this->m_round->current();
        $phase = $this->m_round->phase_info($round);
        if (!$phase['is_open']) return $this->fail('round_closed');

        $ur = $this->m_play->user_round($round->round_id, $this->me->user_id);
        if (!$ur || (int)$ur->contributed <= 0) return $this->fail('must_contribute_first');

        $this->m_play->add_signal($round->round_id, $this->me, $type, false);
        $this->json(['ok' => true, 'type' => $type]);
    }

    // =========================================================================
    // COACH — the strategist panel
    // =========================================================================

    /**
     * Turn the current position into plain-language advice.
     * Ordered most urgent first; the cockpit shows the top few.
     */
    private function coach_items($round, $proj, $ur)
    {
        $items = [];
        if (!$round || !$proj || $this->me->campaign_id === null) return $items;

        $cid  = (int)$this->me->campaign_id;
        $p    = $proj[$cid];
        $m    = $p['mults'];
        $mine = $ur ? (int)$ur->contributed : 0;

        if ($m['stab']['collapse']) {
            $miss = [];
            if ($m['stab']['funding_failed']) $miss[] = 'Finance';
            if ($m['stab']['org_failed'])     $miss[] = 'Organisation';
            if ($m['stab']['log_failed'])     $miss[] = 'Logistics';
            $items[] = ['icon' => 'warning-octagon', 'tone' => 'bad',
                'title' => 'Sustainability failure: '.implode(', ', $miss),
                'body'  => 'Your campaign is below its minimum operating thresholds. Lose this round in this state and backers earn nothing at all. Fund '.implode(' and ', $miss).' now.'];
        } elseif ($m['stab']['state'] !== 'stable') {
            $items[] = ['icon' => 'warning', 'tone' => 'warn',
                'title' => ucfirst($m['stab']['state']).' operations',
                'body'  => 'Stability modifier is x'.number_format($m['stab']['mod'], 2)
                          .'. Roughly '.number_format((int)$m['stab']['tokens_to_next'])
                          .' more tokens into Finance, Organisation or Logistics lifts you a tier.'];
        }

        if ($m['T'] < 0.75) {
            $items[] = ['icon' => 'heartbeat', 'tone' => 'warn',
                'title' => 'Voter trust is slipping',
                'body'  => 'Trust '.number_format($m['T'], 2).'. Attack spending is what drives this down, and it also raises your backfire risk. Shift budget to Policy or Charisma to recover.'];
        }

        if ($m['msg']['at_hard']) {
            $items[] = ['icon' => 'megaphone', 'tone' => 'warn',
                'title' => 'Messaging is saturated',
                'body'  => 'At '.$m['msg']['saturation_pct'].'% of core, further messaging spend is largely wasted. Roughly '
                          .number_format((int)$m['msg']['gap']).' tokens of reach have already been lost to saturation.'];
        }

        if ($m['policy_impact'] < 1.10) {
            $items[] = ['icon' => 'scroll', 'tone' => 'info',
                'title' => 'Thin policy platform',
                'body'  => 'Policy Impact x'.number_format($m['policy_impact'], 3).' against a cap of 1.25. Policy also feeds Credibility, so this is the cheapest power still on the table.'];
        }

        if ($m['efficiency'] < 1.07) {
            $items[] = ['icon' => 'users-three', 'tone' => 'info',
                'title' => 'Organisation under-built',
                'body'  => 'Efficiency x'.number_format($m['efficiency'], 3).'. Organisation multiplies everything else you spend — it is rarely the wrong answer early.'];
        }

        // Openings on rivals
        foreach (GameEngine::$CAMPAIGNS as $rid => $meta) {
            if ($rid === $cid) continue;
            $ed = $this->gameengine->compute_effective_defense($proj[$rid]['alloc']);
            if ($ed < 0.05) {
                $items[] = ['icon' => 'target', 'tone' => 'ok',
                    'title' => 'Opening against '.$meta['name'],
                    'body'  => 'Their coalition defence is only '.number_format($ed * 100, 1).'%. An offensive lands harder than usual right now — but check your own trust first.'];
            }
        }

        // Incoming
        $inc = $this->m_play->pending_conflicts($round->round_id, $cid);
        if ($inc) {
            $eta = max(0, strtotime($inc[0]->resolve_at) - time());
            $items[] = ['icon' => 'shield', 'tone' => 'bad',
                'title' => count($inc).' attack'.(count($inc) > 1 ? 's' : '').' inbound',
                'body'  => 'First impact in '.max(1, (int)ceil($eta / 60)).' min. Coalition spending now reduces the damage; a defensive pulse adds 12% on top.'];
        }

        if ($mine === 0) {
            $items[] = ['icon' => 'coins', 'tone' => 'info',
                'title' => 'You are an observer this round',
                'body'  => 'Nothing placed yet. Observers earn nothing regardless of who wins. Even a small stake puts you in the payout.'];
        }

        if (!$items) {
            $items[] = ['icon' => 'check-circle', 'tone' => 'ok',
                'title' => 'Position is balanced',
                'body'  => 'No critical gaps. Watch for incoming conflicts and keep an eye on the phase clock — late contributions are worth less.'];
        }

        return $items;
    }

    private function event_desc($name)
    {
        foreach (GameEngine::$EVENTS as $e) if ($e['name'] === $name) return $e['desc'];
        return '';
    }
}
