<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * M_user — accounts, allegiance, progression, the token faucet.
 *
 * Bots live in this table too (is_bot = 1) so every gameplay path — joining a
 * campaign, contributing, being settled, being topped up — runs the same code
 * for a bot as for a person. There is no separate bot ledger to drift.
 */
class M_user extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('GameEngine');
    }

    // ── lookups ─────────────────────────────────────────────────────────────
    public function by_id($id)
    {
        return $this->db->get_where('my_users', ['user_id' => (int)$id])->row();
    }

    public function by_login($login)
    {
        return $this->db->where('username', $login)
                        ->or_where('email', $login)
                        ->limit(1)->get('my_users')->row();
    }

    public function by_verify_token($token)
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        return $this->db->get_where('my_users', ['verify_token' => $token])->row();
    }

    public function username_taken($username)
    {
        return (bool)$this->db->where('username', $username)->count_all_results('my_users');
    }

    public function email_taken($email)
    {
        return (bool)$this->db->where('email', $email)->count_all_results('my_users');
    }

    // ── registration ────────────────────────────────────────────────────────

    /**
     * Create an account and hand over the starting stake.
     * Returns [user_id, verify_token] or null on a uniqueness race.
     */
    public function create($username, $email, $password)
    {
        $token = bin2hex(random_bytes(32));
        $now   = date('Y-m-d H:i:s');

        $data = [
            'username'       => $username,
            'email'          => $email,
            'password_hash'  => password_hash($password, PASSWORD_DEFAULT),
            'email_verified' => 0,
            'verify_token'   => $token,
            'verify_expires' => date('Y-m-d H:i:s', strtotime('+48 hours')),
            'balance'        => $this->starting_balance(),
            'created_at'     => $now,
        ];

        // The UNIQUE indexes are the real guard — a check-then-insert can race.
        $this->db->set($data)->insert('my_users');
        if ($this->db->affected_rows() < 1) return null;

        return ['user_id' => (int)$this->db->insert_id(), 'token' => $token];
    }

    public function mark_verified($user_id)
    {
        $this->db->where('user_id', (int)$user_id)->update('my_users', [
            'email_verified' => 1,
            'verify_token'   => null,
            'verify_expires' => null,
        ]);
    }

    public function touch_login($user_id)
    {
        $now = date('Y-m-d H:i:s');
        $this->db->where('user_id', (int)$user_id)
                 ->update('my_users', ['last_login_at' => $now, 'last_seen_at' => $now]);
    }

    public function touch_seen($user_id)
    {
        $this->db->where('user_id', (int)$user_id)
                 ->update('my_users', ['last_seen_at' => date('Y-m-d H:i:s')]);
    }

    public function new_verify_token($user_id)
    {
        $token = bin2hex(random_bytes(32));
        $this->db->where('user_id', (int)$user_id)->update('my_users', [
            'verify_token'   => $token,
            'verify_expires' => date('Y-m-d H:i:s', strtotime('+48 hours')),
        ]);
        return $token;
    }

    // ── allegiance ──────────────────────────────────────────────────────────

    /**
     * §12 Join or switch campaign.
     * The first pick is free. Every switch after that costs a slice of the
     * wallet AND voids the carry-over, because identity is meant to be sticky.
     */
    public function join_campaign($user_id, $campaign_id, $round_no)
    {
        $u = $this->by_id($user_id);
        if (!$u) return ['ok' => false, 'reason' => 'no_user'];

        // NULL means "no campaign yet". Test that BEFORE any int cast — casting
        // NULL yields 0, which would make LENI (campaign 0) look like the
        // player's current side and reject every new player's first pick.
        $first = ($u->campaign_id === null);
        if (!$first && (int)$campaign_id === (int)$u->campaign_id) {
            return ['ok' => false, 'reason' => 'same_campaign'];
        }

        $penalty = 0.0;
        $amount  = 0;

        if (!$first) {
            // A switch more than one round after the last one resets the ladder.
            $count = ((int)$round_no - (int)$u->last_switch_round > 1) ? 1 : (int)$u->switch_count + 1;
            $penalty = $this->gameengine->migration_penalty_rate($count);
            $amount  = (int)floor($u->balance * $penalty);

            $this->db->where('user_id', (int)$user_id)->update('my_users', [
                'campaign_id'       => (int)$campaign_id,
                'balance'           => max(0, (int)$u->balance - $amount),
                'switch_count'      => $count,
                'last_switch_round' => (int)$round_no,
                'joined_campaign_at'=> date('Y-m-d H:i:s'),
            ]);

            // Carry-over must not bleed across campaigns.
            $this->db->where('user_id', (int)$user_id)->delete('pc_user_carryover');
            // A switch also breaks every identity streak.
            $this->db->where('user_id', (int)$user_id)
                     ->update('pc_user_traits', ['streak_rounds' => 0, 'active' => 0,
                                                 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            $this->db->where('user_id', (int)$user_id)->update('my_users', [
                'campaign_id'        => (int)$campaign_id,
                'joined_campaign_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return ['ok' => true, 'first' => $first, 'penalty' => $penalty, 'penalty_amount' => $amount];
    }

    // ── balance ─────────────────────────────────────────────────────────────

    /** Atomic debit. Returns false if the balance would go negative. */
    public function debit($user_id, $amount)
    {
        $amount = (int)$amount;
        if ($amount <= 0) return true;

        $this->db->where('user_id', (int)$user_id)
                 ->where('balance >=', $amount)
                 ->set('balance', 'balance - '.$amount, FALSE)
                 ->set('lifetime_spent', 'lifetime_spent + '.$amount, FALSE)
                 ->update('my_users');

        return $this->db->affected_rows() > 0;
    }

    public function credit($user_id, $amount)
    {
        $amount = (int)$amount;
        if ($amount <= 0) return;
        $this->db->where('user_id', (int)$user_id)
                 ->set('balance', 'balance + '.$amount, FALSE)
                 ->set('lifetime_earned', 'lifetime_earned + '.$amount, FALSE)
                 ->update('my_users');
    }

    // ── the faucet ──────────────────────────────────────────────────────────
    //
    // Without a money layer the economy is closed and leaks 5% per round to the
    // platform sink, so every balance drifts to zero and the player is locked
    // out permanently. The stipend is what keeps the game playable: fall under
    // the floor and you are topped back up to the target at round open.

    public function starting_balance()
    {
        return (int)$this->setting('starting_balance', GameEngine::STARTING_BALANCE);
    }

    /**
     * Top up everyone sitting below the floor. Returns rows touched.
     * Called once per round open by cron, for humans and bots alike.
     */
    public function run_stipend()
    {
        $floor  = (int)$this->setting('stipend_floor', 20000);
        $target = (int)$this->setting('stipend_target', 60000);

        $rows = $this->db->select('user_id, username, balance, is_bot')
                         ->where('status', 'active')
                         ->where('balance <', $floor)
                         ->get('my_users')->result();

        foreach ($rows as $r) {
            // Bots get a randomised top-up so they do not all move in lockstep.
            $amt = $r->is_bot
                 ? (int)($target * (0.7 + mt_rand(0, 60) / 100)) - (int)$r->balance
                 : $target - (int)$r->balance;
            if ($amt > 0) $this->credit($r->user_id, $amt);
        }
        return count($rows);
    }

    // ── progression ─────────────────────────────────────────────────────────

    public function record_round_result($user_id, $won, $round_no)
    {
        $set = ['rounds_played' => 'rounds_played + 1'];
        if ($won) {
            $this->db->where('user_id', (int)$user_id)
                     ->set('rounds_played', 'rounds_played + 1', FALSE)
                     ->set('rounds_won', 'rounds_won + 1', FALSE)
                     ->set('rounds_since_win', 1)
                     ->set('last_win_round', (int)$round_no)
                     ->update('my_users');
        } else {
            $this->db->where('user_id', (int)$user_id)
                     ->set('rounds_played', 'rounds_played + 1', FALSE)
                     ->set('rounds_since_win', 'LEAST(rounds_since_win + 1, 999)', FALSE)
                     ->update('my_users');
        }
    }

    public function account_age_days($user)
    {
        if (!$user || empty($user->created_at)) return 0;
        return max(0, (int)floor((time() - strtotime($user->created_at)) / 86400));
    }

    public function maturity($user)
    {
        return $this->gameengine->compute_maturity(
            (int)$user->rounds_played,
            $this->account_age_days($user)
        );
    }

    // ── identity traits (§23) ───────────────────────────────────────────────

    /** [trait_key => ['streak_rounds'=>int,'active'=>bool]] */
    public function traits($user_id)
    {
        $out = [];
        foreach (GameEngine::$TRAIT_META as $k => $_) {
            $out[$k] = ['streak_rounds' => 0, 'active' => false];
        }
        $rows = $this->db->get_where('pc_user_traits', ['user_id' => (int)$user_id])->result();
        foreach ($rows as $r) {
            if (isset($out[$r->trait_key])) {
                $out[$r->trait_key] = [
                    'streak_rounds' => (int)$r->streak_rounds,
                    'active'        => (bool)$r->active,
                ];
            }
        }
        return $out;
    }

    /**
     * Update trait streaks against the allocation the player actually ran.
     * Three consecutive qualifying rounds activates; one miss resets to zero.
     */
    public function update_traits($user_id, array $alloc, $round_no)
    {
        $q   = $this->gameengine->evaluate_trait_qualification($alloc);
        $cur = $this->traits($user_id);
        $now = date('Y-m-d H:i:s');

        foreach ($q as $key => $qualified) {
            $streak = $qualified ? (int)$cur[$key]['streak_rounds'] + 1 : 0;
            $active = $streak >= GameEngine::TRAIT_STREAK_REQUIRED;

            $this->db->replace('pc_user_traits', [
                'user_id'         => (int)$user_id,
                'trait_key'       => $key,
                'streak_rounds'   => $streak,
                'active'          => $active ? 1 : 0,
                'activated_round' => ($active && !$cur[$key]['active']) ? (int)$round_no : 0,
                'updated_at'      => $now,
            ]);
        }
    }

    // ── carry-over (§23) ────────────────────────────────────────────────────

    public function carryover($user_id)
    {
        return $this->db->get_where('pc_user_carryover',
            ['user_id' => (int)$user_id, 'consumed' => 0])->row();
    }

    public function set_carryover($user_id, $campaign_id, $round_id, array $carry)
    {
        $this->db->replace('pc_user_carryover', [
            'user_id'       => (int)$user_id,
            'campaign_id'   => (int)$campaign_id,
            'from_round_id' => (int)$round_id,
            'logistics'     => (float)$carry['logistics'],
            'policy'        => (float)$carry['policy'],
            'consumed'      => 0,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    public function consume_carryover($user_id)
    {
        $this->db->where('user_id', (int)$user_id)
                 ->update('pc_user_carryover', ['consumed' => 1]);
    }

    // ── leaderboard ─────────────────────────────────────────────────────────

    public function leaderboard($limit = 10)
    {
        return $this->db->select('username, campaign_id, is_bot, balance, rounds_played, rounds_won, net_tokens')
                        ->from('pc_v_leaderboard')
                        ->where('rounds_played >', 0)
                        ->order_by('rounds_won', 'DESC')
                        ->order_by('net_tokens', 'DESC')
                        ->limit((int)$limit)
                        ->get()->result();
    }

    /** Head-count per campaign, used for the cockpit and population weights. */
    public function campaign_headcount()
    {
        $out = [0 => 0, 1 => 0, 2 => 0];
        $rows = $this->db->select('campaign_id, COUNT(*) AS n')
                         ->where('status', 'active')
                         ->where('campaign_id IS NOT NULL')
                         ->group_by('campaign_id')
                         ->get('my_users')->result();
        foreach ($rows as $r) $out[(int)$r->campaign_id] = (int)$r->n;
        return $out;
    }

    // ── settings ────────────────────────────────────────────────────────────

    public function setting($key, $default = null)
    {
        $r = $this->db->get_where('pc_settings', ['skey' => $key])->row();
        return $r ? $r->sval : $default;
    }

    public function set_setting($key, $val)
    {
        $this->db->replace('pc_settings', [
            'skey' => $key, 'sval' => (string)$val, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
