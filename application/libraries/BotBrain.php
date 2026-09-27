<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * BotBrain — the synthetic electorate.
 *
 * At launch there will be one real person in the room, and an empty war room
 * is a dead war room. These bots exist so the board is always moving: money
 * arriving, sides shifting, attacks landing, someone always doing something.
 *
 * The roster is 100 accounts:
 *   50 rabid      never leave their campaign, spend hard, defend loudly
 *   30 switchers  chase whoever is winning; loyalty is thin and priced
 *   20 lurkers    mostly silent, occasionally decisive, often skip a round
 *
 * They are NOT scheduled to move together. At round open each bot draws its
 * own plan — how much it intends to spend, in how many bursts, and when the
 * first one lands. Wake times are spread across the whole 21-hour window and
 * centred on its middle, so activity peaks through daylight and across the mid
 * phase — which is also where the time weight rewards play.
 * A bot can hesitate, push its next action back, refuse to act at all, or
 * change sides mid-round.
 *
 * Every action a bot takes goes through the same M_play methods a human uses,
 * so a bot can never do something a person could not.
 */
class BotBrain
{
    /** @var CI_Controller */
    private $CI;

    const ROSTER = ['rabid' => 50, 'switcher' => 30, 'lurker' => 20];

    /** First names / handles are generated, never scraped from real people. */
    private static $HANDLE_A = [
        'Barangay','Tindero','Jeepney','Sampaguita','Bayanihan','Banatero','Tambay','Balut',
        'Sari','Kuya','Ate','Lolo','Lola','Pinoy','Manila','Cebu','Davao','Iloilo','Baguio',
        'Bicol','Ilocano','Batangas','Cavite','Laguna','Pampanga','Quezon','Zambales','Tarlac',
        'Palawan','Samar','Leyte','Bohol','Negros','Aklan','Rizal','Bulacan','Nueva','Isabela',
    ];
    private static $HANDLE_B = [
        'Watcher','Voter','Runner','Analyst','Volunteer','Organiser','Captain','Scribe','Clerk',
        'Broker','Whip','Delegate','Canvasser','Poller','Steward','Herald','Marshal','Aide',
        'Strategist','Operative','Loyalist','Sceptic','Idealist','Pragmatist','Firebrand',
    ];

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->library('GameEngine');
        $this->CI->load->model('m_user');
        $this->CI->load->model('m_play');
    }

    // =========================================================================
    // SEEDING
    // =========================================================================

    /**
     * Create the roster. Idempotent — tops up to the target count, never
     * duplicates. Safe to call from cron.
     */
    public function seed($target = 100)
    {
        $have = (int)$this->CI->db->where('is_bot', 1)->count_all_results('my_users');
        if ($have >= $target) return ['created' => 0, 'total' => $have];

        $created = 0;
        $now     = date('Y-m-d H:i:s');
        $start   = $this->CI->m_user->starting_balance();

        // Work out how many of each personality are still missing.
        $need = [];
        foreach (self::ROSTER as $p => $n) {
            $scale = (int)round($n * $target / array_sum(self::ROSTER));
            $has   = (int)$this->CI->db->where('personality', $p)->count_all_results('pc_bot_profiles');
            $need[$p] = max(0, $scale - $has);
        }

        /**
         * Deal each personality round-robin instead of rolling mt_rand(0, 2)
         * per bot.
         *
         * An independent 3-way coin flip per bot has a standard deviation of
         * about 4.7 over 100 draws, so a 42/37/21 opening roster is a perfectly
         * ordinary outcome — and it is not a cosmetic one, because the 50 rabid
         * bots are the heaviest spenders in the game (35–78% of balance every
         * round) and nothing ever moves them off their seed campaign. A lopsided
         * deal is therefore a permanent funding advantage, handed out before a
         * single round is played.
         *
         * Starting the offset from the roster already in the table keeps the
         * balance when seed() runs again to replenish losses.
         */
        $deal = (int)$this->CI->db->where('is_bot', 1)->count_all_results('my_users');

        foreach ($need as $personality => $count) {
            for ($i = 0; $i < $count; $i++) {
                $username = $this->unique_handle();
                if (!$username) continue;

                $home = $deal++ % 3;
                $traits = $this->draw_traits($personality);

                $this->CI->db->insert('my_users', [
                    'username'       => $username,
                    'email'          => strtolower($username).'@bots.politiclash.local',
                    'password_hash'  => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                    'email_verified' => 1,
                    'balance'        => (int)($start * (0.8 + mt_rand(0, 40) / 100)),
                    'campaign_id'    => $home,
                    'joined_campaign_at' => $now,
                    'is_bot'         => 1,
                    'created_at'     => date('Y-m-d H:i:s', time() - mt_rand(0, 60) * 86400),
                ]);
                $uid = (int)$this->CI->db->insert_id();
                if (!$uid) continue;

                $this->CI->db->insert('pc_bot_profiles', array_merge([
                    'user_id'       => $uid,
                    'personality'   => $personality,
                    'home_campaign' => $home,
                ], $traits));

                $created++;
            }
        }

        return ['created' => $created,
                'total' => (int)$this->CI->db->where('is_bot', 1)->count_all_results('my_users')];
    }

    /** Personality decides the shape of the dice, not the outcome. */
    private function draw_traits($personality)
    {
        $r = function ($lo, $hi) { return round(mt_rand((int)($lo * 1000), (int)($hi * 1000)) / 1000, 3); };

        switch ($personality) {
            case 'rabid':
                return [
                    'archetype' => $this->pick(['aggressive', 'aggressive', 'balanced']),
                    'loyalty'   => $r(0.85, 1.00),
                    'greed'     => $r(0.10, 0.40),
                    'integrity' => $r(0.20, 0.65),
                    'aggression'=> $r(0.45, 0.95),
                    'activity'  => $r(0.55, 0.95),
                ];
            case 'switcher':
                return [
                    'archetype' => $this->pick(['balanced', 'aggressive', 'defensive']),
                    'loyalty'   => $r(0.10, 0.45),
                    'greed'     => $r(0.60, 1.00),
                    'integrity' => $r(0.25, 0.80),
                    'aggression'=> $r(0.20, 0.70),
                    'activity'  => $r(0.40, 0.85),
                ];
            default: // lurker
                return [
                    'archetype' => $this->pick(['defensive', 'balanced', 'defensive']),
                    'loyalty'   => $r(0.40, 0.80),
                    'greed'     => $r(0.15, 0.55),
                    'integrity' => $r(0.55, 1.00),
                    'aggression'=> $r(0.00, 0.25),
                    'activity'  => $r(0.08, 0.35),
                ];
        }
    }

    private function pick(array $a) { return $a[mt_rand(0, count($a) - 1)]; }

    private function unique_handle()
    {
        for ($try = 0; $try < 25; $try++) {
            $h = self::$HANDLE_A[mt_rand(0, count(self::$HANDLE_A) - 1)]
               . self::$HANDLE_B[mt_rand(0, count(self::$HANDLE_B) - 1)]
               . mt_rand(2, 989);
            if (!$this->CI->m_user->username_taken($h)) return $h;
        }
        return null;
    }

    // =========================================================================
    // PLANNING — called once, at round open
    // =========================================================================

    /**
     * Give every bot its own schedule for the day.
     *
     * The wake time is drawn across the whole round and centred on its middle,
     * so the roster comes alive through daylight and across the mid phase
     * rather than spending itself overnight. A slice of every personality
     * deliberately draws a plan of zero — those bots sit the round out
     * entirely, which is what makes the ones who do show up feel like
     * decisions rather than clockwork.
     */
    public function plan_round($round)
    {
        $opens  = strtotime($round->opens_at);
        $closes = strtotime($round->closes_at);
        $span   = max(60, $closes - $opens);

        /**
         * Only plan bots that are NOT already planned for this round.
         *
         * Planning rewrites budget_plan, zeroes budget_spent and hands out a
         * fresh burst count. Re-running it mid-round on a bot that has already
         * spent gives that bot a brand new wallet — and the tick loop calls
         * this whenever it notices a single unplanned bot, so seeding one new
         * bot at midday would have re-armed the entire roster.
         *
         * At round open every bot still carries yesterday's id (or NULL), so
         * this filter is a no-op there and everyone gets planned as intended.
         */
        $bots = $this->CI->db->select('b.*, u.balance')
                             ->from('pc_bot_profiles b')
                             ->join('my_users u', 'u.user_id = b.user_id', 'inner')
                             ->where('u.status', 'active')
                             ->group_start()
                                 ->where('b.plan_round_id !=', (int)$round->round_id)
                                 ->or_where('b.plan_round_id IS NULL')
                             ->group_end()
                             ->get()->result();

        $planned = 0; $sitting_out = 0;

        foreach ($bots as $b) {
            $activity = (float)$b->activity;

            // Will this bot engage at all today?
            if (GameEngine::rand_float(0, 1) > $activity + 0.25) {
                $this->CI->db->where('user_id', $b->user_id)->update('pc_bot_profiles', [
                    'plan_round_id'  => (int)$round->round_id,
                    'budget_plan'    => 0,
                    'budget_spent'   => 0,
                    'bursts_total'   => 0,
                    'bursts_left'    => 0,
                    'next_action_at' => null,
                ]);
                $sitting_out++;
                continue;
            }

            // How much of the wallet is it willing to commit?
            $frac = $this->commit_fraction($b->personality, $activity);
            $plan = (int)(floor((int)$b->balance * $frac / 100) * 100);
            $plan = max(0, min($plan, (int)$b->balance));

            $bursts = ($b->personality === 'lurker') ? mt_rand(1, 2) : mt_rand(1, 4);

            // Centre the first wake on the MIDDLE of the window, not the start.
            //
            // Two draws averaged gives a triangular distribution peaking at the
            // midpoint, which puts the bulk of bot activity in daylight and
            // across the mid phase — where the time weight is best anyway.
            //
            // Taking min() instead (the earlier approach) peaks at the start,
            // which was fine on a 06:00 open but empties the whole roster out
            // overnight now that the round opens at midnight: two thirds of
            // first actions landed before 08:00, so a player arriving in the
            // morning found the board already decided and the feed silent.
            //
            // The tails still reach both ends, so the board is never completely
            // static at either edge of the round.
            $d1 = mt_rand(0, (int)($span * 0.88));
            $d2 = mt_rand(0, (int)($span * 0.88));
            $offset = intdiv($d1 + $d2, 2) + mt_rand(0, 900);

            $this->CI->db->where('user_id', $b->user_id)->update('pc_bot_profiles', [
                'plan_round_id'  => (int)$round->round_id,
                'budget_plan'    => $plan,
                'budget_spent'   => 0,
                'bursts_total'   => $bursts,
                'bursts_left'    => $bursts,
                'next_action_at' => date('Y-m-d H:i:s', $opens + $offset),
            ]);
            $planned++;
        }

        return ['planned' => $planned, 'sitting_out' => $sitting_out];
    }

    private function commit_fraction($personality, $activity)
    {
        switch ($personality) {
            case 'rabid':    return 0.35 + $activity * 0.45;   // 0.35 – 0.78
            case 'switcher': return 0.20 + $activity * 0.40;   // 0.20 – 0.54
            default:         return 0.05 + $activity * 0.30;   // 0.05 – 0.15
        }
    }

    // =========================================================================
    // TICK — called every minute by cron
    // =========================================================================

    /**
     * Wake any bots whose timer is up and let each decide independently.
     * $limit keeps a single cron run bounded so one tick cannot stall.
     */
    public function tick($round, $phase, $limit = 12)
    {
        if (!$round || $round->status !== 'open' || !$phase['is_open']) {
            return ['acted' => 0, 'reason' => 'round_not_open'];
        }

        $due = $this->CI->db->select('b.*, u.user_id, u.username, u.balance, u.campaign_id, u.rounds_played')
                            ->from('pc_bot_profiles b')
                            ->join('my_users u', 'u.user_id = b.user_id', 'inner')
                            ->where('u.status', 'active')
                            ->where('b.plan_round_id', (int)$round->round_id)
                            ->where('b.bursts_left >', 0)
                            ->where('b.next_action_at IS NOT NULL')
                            ->where('b.next_action_at <=', date('Y-m-d H:i:s'))
                            ->order_by('b.next_action_at', 'ASC')
                            ->limit((int)$limit)
                            ->get()->result();

        $acted = 0; $skipped = 0; $switched = 0; $attacked = 0;

        foreach ($due as $b) {
            // Hesitation: sometimes a bot wakes, looks at the board, and waits.
            if (GameEngine::rand_float(0, 1) > (float)$b->activity) {
                $this->defer($b, $phase, mt_rand(600, 2700));
                $skipped++;
                continue;
            }

            // Switchers reconsider their allegiance before spending.
            if ($b->personality === 'switcher' && $this->maybe_switch($b, $round)) {
                $switched++;
                $b = $this->refresh($b);
            }

            if ($this->do_burst($b, $round, $phase)) $acted++;

            // Aggressive bots may open a conflict after committing.
            if ($this->maybe_attack($b, $round, $phase)) $attacked++;

            // Occasionally broadcast intent — a quarter of them are bluffing.
            if (GameEngine::rand_float(0, 1) < 0.12) $this->maybe_signal($b, $round);
        }

        return ['acted' => $acted, 'skipped' => $skipped,
                'switched' => $switched, 'attacked' => $attacked, 'due' => count($due)];
    }

    private function refresh($b)
    {
        $u = $this->CI->m_user->by_id($b->user_id);
        if ($u) { $b->campaign_id = $u->campaign_id; $b->balance = $u->balance; }
        return $b;
    }

    /** Push a bot's next wake back, never past the end of the round. */
    private function defer($b, $phase, $seconds)
    {
        $next = min(time() + $seconds, $phase['closes_ts'] - 120);
        $this->CI->db->where('user_id', $b->user_id)->update('pc_bot_profiles', [
            'next_action_at' => date('Y-m-d H:i:s', $next),
        ]);
    }

    // ── contributing ────────────────────────────────────────────────────────

    private function do_burst($b, $round, $phase)
    {
        $left = max(0, (int)$b->budget_plan - (int)$b->budget_spent);
        if ($left < 100 || (int)$b->bursts_left <= 0) {
            $this->CI->db->where('user_id', $b->user_id)
                         ->update('pc_bot_profiles', ['bursts_left' => 0, 'next_action_at' => null]);
            return false;
        }

        // Last burst spends what remains; earlier ones take a slice.
        $share = ((int)$b->bursts_left === 1)
               ? 1.0
               : GameEngine::rand_float(0.25, 0.70);
        $spend = (int)(floor($left * $share / 100) * 100);
        $spend = max(100, min($spend, $left, (int)$b->balance));
        if ($spend < 100) return false;

        $amounts = $this->shape_allocation($b, $spend);
        if (!$amounts) return false;

        $user = $this->CI->m_user->by_id($b->user_id);
        if (!$user) return false;

        $res = $this->CI->m_play->contribute($round, $user, $amounts, $phase);

        if (!$res['ok']) {
            // Late-phase cap or a thin wallet — back off and retry later.
            $this->defer($b, $phase, mt_rand(900, 2400));
            return false;
        }

        $bursts_left = (int)$b->bursts_left - 1;
        $next = null;
        if ($bursts_left > 0) {
            $gap  = mt_rand(1200, 5400);   // 20–90 min between bursts
            $next = date('Y-m-d H:i:s', min(time() + $gap, $phase['closes_ts'] - 120));
        }

        $this->CI->db->where('user_id', $b->user_id)->update('pc_bot_profiles', [
            'budget_spent'   => (int)$b->budget_spent + $res['face'],
            'bursts_left'    => $bursts_left,
            'next_action_at' => $next,
            'last_action_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Turn a spend figure into a per-system split.
     *
     * The archetype template is the base. Integrity then pulls money out of the
     * two attack systems and puts it back into Policy and Coalitions — a bot
     * with high integrity simply will not run a dirty campaign, whatever its
     * archetype says.
     */
    private function shape_allocation($b, $spend)
    {
        $tpl = GameEngine::$BOT_TEMPLATES[$b->archetype];
        $w   = [];
        foreach (GameEngine::all_keys() as $k) $w[$k] = isset($tpl[$k]) ? (float)$tpl[$k] : 0.0;

        // Integrity suppresses the attack systems.
        $integrity = (float)$b->integrity;
        $moved = 0.0;
        foreach (['attack_ads', 'negative_campaigning'] as $k) {
            $cut = $w[$k] * $integrity;
            $w[$k] -= $cut;
            $moved += $cut;
        }
        $w['policy']     += $moved * 0.6;
        $w['coalitions'] += $moved * 0.4;

        // Aggression pushes a little the other way.
        $aggr = (float)$b->aggression;
        if ($aggr > 0.6) {
            $shift = 0.04 * ($aggr - 0.6) / 0.4;
            $w['attack_ads'] += $shift;
            $w['policy']      = max(0, $w['policy'] - $shift);
        }

        // A little noise so no two bots produce an identical shape.
        foreach ($w as $k => $v) {
            $w[$k] = max(0.0, $v * GameEngine::rand_float(0.82, 1.18));
        }

        $sum = array_sum($w);
        if ($sum <= 0) return null;

        $out = []; $allocated = 0;
        foreach ($w as $k => $v) {
            $amt = (int)(floor($spend * ($v / $sum) / 100) * 100);
            if ($amt > 0) { $out[$k] = $amt; $allocated += $amt; }
        }
        if ($allocated <= 0) return null;

        // Rounding remainder goes to Finance — it is the safest sink and it
        // helps bots clear the sustainability floor rather than fail it.
        $rem = $spend - $allocated;
        if ($rem >= 100) $out['funding'] = (isset($out['funding']) ? $out['funding'] : 0) + $rem;

        return $out;
    }

    // ── switching sides ─────────────────────────────────────────────────────

    /**
     * §12 A switcher reconsiders. Greed pulls toward the campaign that is
     * currently ahead; loyalty resists; the migration penalty is real money
     * out of their wallet, so it is not a free move for them either.
     */
    private function maybe_switch($b, $round)
    {
        // Only worth considering before the money is committed.
        if ((int)$b->budget_spent > 0 && GameEngine::rand_float(0, 1) > 0.25) return false;

        $states = $this->CI->db->select('campaign_id, total_all')
                               ->where('round_id', (int)$round->round_id)
                               ->get('pc_round_campaign')->result();
        if (!$states) return false;

        $best = null; $best_v = -1; $mine_v = 0;
        foreach ($states as $s) {
            $v = (float)$s->total_all;
            if ((int)$s->campaign_id === (int)$b->campaign_id) $mine_v = $v;
            if ($v > $best_v) { $best_v = $v; $best = (int)$s->campaign_id; }
        }
        if ($best === null || $best === (int)$b->campaign_id) return false;

        // How far behind is their side? Nothing to chase if it is close.
        $behind = GameEngine::sd($best_v - $mine_v, max(1.0, $best_v));
        if ($behind < 0.18) return false;

        $urge = $behind * (float)$b->greed * (1.0 - (float)$b->loyalty);
        if (GameEngine::rand_float(0, 1) > $urge) return false;

        $res = $this->CI->m_user->join_campaign($b->user_id, $best, (int)$round->round_no);
        if (empty($res['ok'])) return false;

        $this->CI->m_play->feed($round->round_id, 'switch', $best, $b->username,
            $b->username.' abandoned '.GameEngine::campaign_name($b->campaign_id)
            .' for '.GameEngine::campaign_name($best).' — '
            .number_format($res['penalty_amount']).' tokens forfeited in the move.', 'warn');

        return true;
    }

    // ── conflicts ───────────────────────────────────────────────────────────

    private function maybe_attack($b, $round, $phase)
    {
        if ((float)$b->aggression < 0.35) return false;
        if (GameEngine::rand_float(0, 1) > (float)$b->aggression * 0.18) return false;
        if ($b->campaign_id === null) return false;

        $ur = $this->CI->m_play->user_round($round->round_id, $b->user_id);
        if (!$ur || (int)$ur->contributed <= 0) return false;

        $this->CI->load->model('m_round');
        $allocs = $this->CI->m_round->allocs($round->round_id);
        $proj   = $this->CI->m_round->project($round, null);

        $powers = [];
        foreach (GameEngine::$CAMPAIGNS as $cid => $_) $powers[$cid] = $proj[$cid]['power'];

        // Pick the rival that currently looks softest.
        $mine   = (int)$b->campaign_id;
        $target = null; $best = -1;
        foreach (GameEngine::$CAMPAIGNS as $cid => $_) {
            if ($cid === $mine) continue;
            $ts = $this->CI->gameengine->compute_target_score(
                $allocs[$mine], max(1.0, $powers[$mine]),
                $allocs[$cid],  max(1.0, $powers[$cid]));
            if ($ts > $best) { $best = $ts; $target = $cid; }
        }
        if ($target === null) return false;

        $user = $this->CI->m_user->by_id($b->user_id);
        $res  = $this->CI->m_play->open_conflict($round, $mine, $target, $user, $allocs, $powers);
        return !empty($res['ok']);
    }

    private function maybe_signal($b, $round)
    {
        if ($b->campaign_id === null) return;
        $user = $this->CI->m_user->by_id($b->user_id);
        if (!$user) return;

        $ur = $this->CI->m_play->user_round($round->round_id, $b->user_id);
        if (!$ur || (int)$ur->contributed <= 0) return;

        // A quarter of bot signals are deliberate misdirection.
        $fake = (GameEngine::rand_float(0, 1) < 0.25);
        $type = GameEngine::$SIG_TYPES[mt_rand(0, count(GameEngine::$SIG_TYPES) - 1)];
        $this->CI->m_play->add_signal($round->round_id, $user, $type, $fake);
    }
}
