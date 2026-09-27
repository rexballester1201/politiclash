<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * GameEngine — PolitiClash
 *
 * Authoritative server-side port of references/GameEngine-JS.js v2.2.
 * The JS file is the design source of record; this file is the ruling
 * implementation. Where they ever disagree, THIS file decides the outcome
 * and the JS display layer must be corrected to match.
 *
 * Ported faithfully, with these deliberate departures — each one is a fix
 * for a defect in the previous PHP port, or a consequence of dropping the
 * money layer:
 *
 *   1. PERSISTENCE MULTIPLIER IS INCLUDED.  The old PHP port omitted
 *      _getPersistenceMultiplier entirely, so any player on a win or loss
 *      streak got different power from the two engines. See §23 below.
 *
 *   2. POPULATION WEIGHT IS COMPUTED PROPERLY.  The old port approximated
 *      WeightedPopulation as a raw player count. It now sums the real
 *      per-player weights, min(1, contrib_i / avg), which is what the
 *      formula always meant.
 *
 *   3. §22 TOKEN TRANSFERS ARE GONE.  That subsystem existed solely to stop
 *      real-money collusion. With no money there is nothing to collude over.
 *
 *   4. TIME WEIGHTS SPAN A 21-HOUR ROUND (00:00–21:00) instead of 5 minutes.
 *      The phase *fractions* are unchanged, so the shape of the incentive is
 *      identical — only the wall-clock scale moved.
 *
 *   5. LOSER_FLAT_RETURN IS REMOVED.  It was unreachable: classify_outcome()
 *      only ever assigns 'narrow' to a winner, so the flat-return branch in
 *      the JS computeReward could never execute.
 *
 * Every method is PURE. No database, no session, no clock reads except where
 * a timestamp is passed in. Models supply data, the engine only does maths.
 *
 * Section markers (§) are retained as cross-reference keys between this file
 * and GameEngine-JS.js. They no longer point at the lost whitepaper.
 */
class GameEngine
{
    // =========================================================================
    // §2.1 ENGINE CONSTANTS — single source of truth for every magic number
    // =========================================================================
    const SCALE                   = 1000;

    /* Economy */
    const STARTING_BALANCE        = 100000;
    const PLATFORM_FEE            = 0.05;   // token sink, keeps the faucet honest

    /* §20.3 Soft cap */
    const SOFT_CAP_FRAC           = 0.25;
    const SOFT_CAP_EXCESS         = 0.50;

    /* §7.1 Trust */
    const TRUST_FLOOR             = 0.10;
    const TRUST_ATTACK_COEFF      = 0.60;
    const TRUST_NEG_COEFF         = 0.80;

    /* §7.2 Credibility */
    const CRED_FLOOR              = 0.30;
    const CRED_CEIL               = 1.50;
    const CRED_POLICY_COEFF       = 0.50;
    const CRED_ORG_COEFF          = 0.30;
    const CRED_NEG_COEFF          = 0.60;

    /* §7.3 Saturation */
    const MSG_SAT_HARD            = 0.50;
    const MSG_SAT_WARN            = 0.28;
    const SAT_FACTOR_COEFF        = 0.40;

    /* §8.1 Stability score coefficients */
    const FUND_COVER_COEFF        = 10;
    const ORG_COVER_COEFF         = 8;
    const LOG_COVER_COEFF         = 6;
    const COAL_SUPPORT_MAX        = 5;
    const COAL_SUPPORT_COEFF      = 40;
    const AGGR_STRESS_COEFF       = 20;

    /* §8.2 Stability thresholds */
    const STAB_STABLE_MIN         = 0;
    const STAB_STRAINED_MIN       = -10;
    const STAB_UNSTABLE_MIN       = -25;

    /* §8.3 Sustainability — percentage of TotalCore */
    const FUND_REQ_FRAC           = 0.15;
    const ORG_REQ_FRAC            = 0.12;
    const LOG_REQ_FRAC            = 0.08;

    /* §9.1 Core power multipliers */
    const EFF_COEFF               = 0.40;
    const INFRA_COEFF             = 0.50;

    /* §9.2 Policy impact */
    const POLICY_IMPACT_CAP       = 1.25;
    const POLICY_COEFF            = 0.40;
    const POLICY_WEIGHT           = 0.70;
    const FUND_WEIGHT             = 0.30;

    /* §9.3 Charisma */
    const CHARISMA_COEFF          = 0.30;

    /* §9.4 Trust / Credibility modifiers */
    const TRUST_MOD_BASE          = 0.80;
    const TRUST_MOD_COEFF         = 0.40;
    const CRED_MOD_BASE           = 0.80;
    const CRED_MOD_COEFF          = 0.30;

    /* §9.5 Aggression cost */
    const BURNRATE_COEFF          = 0.10;
    const BURNRATE_CAP            = 0.50;
    const AGGR_PEN_COEFF          = 0.50;
    const AGGR_PEN_CAP            = 0.20;

    /* §9.6 Population */
    const OVERPOP_CAP             = 0.35;
    const OVERPOP_COEFF           = 0.25;
    const UNDERDOG_CAP            = 0.40;
    const UNDERDOG_COEFF          = 0.50;
    const POP_RATIO_TRIGGER       = 1.05;

    /* §9.7 Peace */
    const PEACE_BONUS_MAX         = 0.08;
    const PEACE_BONUS_COEFF       = 0.50;

    /* §9.9 Power formula
     *
     * BASE_POWER_EXP was 0.90, which — compounded with WIN_CHANCE_EXP (0.90)
     * — made win probability scale as tokens^0.81. That is very nearly linear:
     * a campaign holding 4x the tokens took roughly 3x the odds, while the
     * only corrective terms in the whole chain (OVERPOP_CAP 0.35 and
     * UNDERDOG_CAP 0.40) saturate at about +/-40%. The correction could never
     * catch the advantage, so every round's winnings bought the next round's
     * odds and the game locked to whoever led first.
     *
     * Measured over 8 x 300-round simulations: the richest campaign won 75.5%
     * of rounds and the leader ended up holding ~80% of all tokens.
     *
     * At 0.60 the chain gives tokens^0.54 — a 4x war chest now buys ~2x the
     * odds, which the caps CAN pull back. Money still matters; it just stops
     * being decisive on its own, which is what "power buys odds, never
     * certainty" was supposed to mean. Strategy multipliers (efficiency,
     * infra, trust, credibility, stability) gain weight relative to raw spend.
     */
    const BASE_POWER_EXP          = 0.60;
    const NOISE_MIN               = 0.95;
    const NOISE_MAX               = 1.05;

    /* §10 Conflict */
    const CONFLICT_IMPACT_MIN     = 0.85;
    const CONFLICT_IMPACT_MAX     = 1.25;
    const CONFLICT_INTEN_CAP      = 0.50;
    const ATK_CRED_MOD_MIN        = 0.60;
    const MULTI_ATK_PEN_COEFF     = 0.15;
    const MULTI_ATK_PEN_FLOOR     = 0.40;
    const BACKFIRE_TRUST_COEFF    = 0.50;
    const BACKFIRE_NEG_COEFF      = 0.30;

    /* §10.5 Pulse */
    const PULSE_MOD               = 1.12;
    const PULSE_DURATION_S        = 900;    // 15 min on a 21 h round

    /* §10.6 Conflict resolution delay (seconds) */
    const RESOLVE_DELAY_MIN       = 600;    // 10 min
    const RESOLVE_DELAY_MAX       = 1800;   // 30 min

    /* §10.7 Targeting thresholds */
    const TARGET_SCORE_MIN        = 0.05;
    const TARGET_SCORE_WARN       = 0.15;
    const TARGET_SCORE_VULN       = 0.18;

    /* §11 Coalition defense diminishing returns */
    const COALITION_DR_THRESH     = 0.12;
    const COALITION_DR_COEFF      = 0.40;

    /* §13 Win condition */
    const WIN_CHANCE_EXP          = 0.90;
    const VICTORY_GAP_PCT         = 20;
    const NARROW_GAP_PCT          = 5;

    /* §14 Event probabilities */
    const EVENT_NONE_PROB         = 0.50;
    const EVENT_STANDARD_PROB     = 0.40;

    /* §14.1 Event confidence */
    const EV_CONF_BASE            = 0.70;
    const EV_CONF_COEFF           = 0.20;

    /* §15 Scan */
    const SCAN_ACC_FLOOR          = 0.50;
    const SCAN_ACC_DECAY          = 0.10;
    const SCAN_NOISE_BASE         = 0.05;
    const SCAN_NOISE_COEFF        = 0.20;
    const SCAN_COST_MIN_PCT       = 0.01;
    const SCAN_COST_MAX_PCT       = 0.03;
    const SCAN_LOAD_WINDOW_S      = 1800;   // scan load decays over 30 min
    const INTEL_WIN_DISCOUNT      = 0.30;

    /* §17.3 Micro-action costs */
    const PULSE_COST_PCT          = 0.02;

    /* §5 Time weights */
    const EARLY_WEIGHT            = 1.000;
    const MID_WEIGHT              = 1.050;
    const LATE_WEIGHT_BASE        = 0.900;
    const LATE_WEIGHT_VOL         = 0.050;
    const EARLY_PHASE_END         = 0.50;
    const MID_PHASE_END           = 0.85;
    const LATE_CAP_SELF_FRAC      = 0.25;
    const LATE_CAP_AVG_FRAC       = 0.15;

    /* §20 Reward */
    const TEAM_SHARE_FRAC         = 0.40;
    const INDIV_SHARE_FRAC        = 0.60;
    const MIN_CONTRIB_FRAC        = 0.015;

    /* §20.4 Participation maturity */
    const MATURITY_BASE           = 0.60;
    const MATURITY_ROUNDS_COEFF   = 0.08;
    const MATURITY_AGE_COEFF      = 0.08;
    const MATURITY_AGE_DAYS       = 30;

    /* §23 Persistent progression */
    const WINNER_BONUS_R1         = 0.10;
    const WINNER_BONUS_R2         = 0.05;
    const LOSER_UNDERDOG_BONUS    = 0.15;
    const LOGISTICS_CARRY_FRAC    = 0.50;
    const POLICY_CARRY_FRAC       = 0.50;

    /* §23 Identity traits */
    const IDENTITY_TRAIT_BONUS    = 0.05;
    const TRAIT_STREAK_REQUIRED   = 3;
    const TRAIT_POLICY_THRESH     = 0.22;
    const TRAIT_ORG_THRESH        = 0.14;
    const TRAIT_CHARISMA_THRESH   = 0.07;
    const TRAIT_COALITION_THRESH  = 0.10;
    const TRAIT_ATTACK_THRESH     = 0.10;

    /* §12 Player migration */
    const MIGRATION_PENALTY_1     = 0.05;
    const MIGRATION_PENALTY_2     = 0.10;
    const MIGRATION_PENALTY_3     = 0.20;


    // =========================================================================
    // STATIC DATA
    // =========================================================================
    /**
     * Campaign identity. This array is the single source of truth for the
     * colours — the API ships them to the client, so nothing downstream should
     * hardcode a hex.
     *
     * Pink / green / red follow the real campaign branding. They are also far
     * enough apart on the hue wheel to stay legible as 9px dots and 5px bars:
     * pink 322deg, green 132deg, red 4deg. Two constraints shaped the exact
     * values — the green has to clear the teal UI accent (173deg) so a campaign
     * bar never reads as chrome, and the red has to differ from --loss so a
     * falling number and the MARCOS campaign are not the same colour.
     */
    public static $CAMPAIGNS = [
        0 => ['id' => 0, 'name' => 'LENI',   'label' => 'LENI Campaign',   'color' => '#FF2BB5'],
        1 => ['id' => 1, 'name' => 'SARA',   'label' => 'SARA Campaign',   'color' => '#3DDB5C'],
        2 => ['id' => 2, 'name' => 'MARCOS', 'label' => 'MARCOS Campaign', 'color' => '#FF4438'],
    ];

    public static $CORE_KEYS = [
        'funding', 'organization', 'logistics', 'policy',
        'charisma', 'messaging', 'opposition_research',
    ];

    public static $NC_KEYS = ['attack_ads', 'negative_campaigning', 'coalitions'];

    public static $SYS_META = [
        'funding'              => ['label' => 'Finance',    'icon' => 'bank',          'group' => 'core'],
        'organization'         => ['label' => 'Organisation','icon' => 'users-three',  'group' => 'core'],
        'logistics'            => ['label' => 'Logistics',  'icon' => 'strategy',      'group' => 'core'],
        'policy'               => ['label' => 'Policy',     'icon' => 'scroll',        'group' => 'core'],
        'charisma'             => ['label' => 'Charisma',   'icon' => 'fire',          'group' => 'core'],
        'messaging'            => ['label' => 'Messaging',  'icon' => 'megaphone',     'group' => 'core'],
        'opposition_research'  => ['label' => 'Opp. Research','icon' => 'magnifying-glass','group' => 'core'],
        'attack_ads'           => ['label' => 'Attack Ads', 'icon' => 'sword',         'group' => 'attack'],
        'negative_campaigning' => ['label' => 'Neg. Campaign','icon' => 'hand-fist',   'group' => 'attack'],
        'coalitions'           => ['label' => 'Coalitions', 'icon' => 'handshake',     'group' => 'defense'],
    ];

    public static $STABILITY_STATES = [
        'stable'   => ['label' => 'Stable',   'mod' => 1.10],
        'strained' => ['label' => 'Strained', 'mod' => 0.90],
        'unstable' => ['label' => 'Unstable', 'mod' => 0.75],
        'critical' => ['label' => 'Critical', 'mod' => 0.50],
    ];

    /**
     * 'resilient' was 0.15. Because refunds are taken OUT of the winners' pool
     * before it is divided (see compute_reward), this number is really the
     * dial that says how much of the losing side's money changes hands each
     * round — at 0.15, the winner swept ~80% of it. Over a long season that
     * is what drains the losing campaigns' war chests toward zero, and a
     * campaign with no tokens cannot contest the next round either.
     *
     * At 0.35 a disciplined loser keeps enough to come back, the winner still
     * clears ~60% of the pot, and no tokens are created or destroyed by the
     * change. Simulated effect on its own: longest win streak 39.6 -> 27.1
     * rounds, leader's share of all tokens 79.7% -> 67.3%.
     */
    public static $OUTCOME_MULTIPLIERS = [
        'victory'   => 1.00,
        'narrow'    => 0.85,
        'resilient' => 0.35,
        'collapse'  => 0.00,
    ];

    /** §14 Event pool. layer decides WHERE the modifier lands. */
    public static $EVENTS = [
        ['name' => 'Funding Surge',        'layer' => 'ContribEventMod',  'sys' => 'funding',   'mod' => 1.40, 'super' => 0, 'desc' => 'Finance contributions x1.40'],
        ['name' => 'Policy Breakthrough',  'layer' => 'ContribEventMod',  'sys' => 'policy',    'mod' => 2.00, 'super' => 0, 'desc' => 'Policy contributions x2.00'],
        ['name' => 'Media Surge',          'layer' => 'ContribEventMod',  'sys' => 'messaging', 'mod' => 1.40, 'super' => 0, 'desc' => 'Messaging contributions x1.40'],
        ['name' => 'Logistics Breakdown',  'layer' => 'ContribEventMod',  'sys' => 'logistics', 'mod' => 0.70, 'super' => 0, 'desc' => 'Logistics contributions x0.70'],
        ['name' => 'Talent Drought',       'layer' => 'PowerEventMod',    'sys' => 'eff_cap',   'mod' => 1.05, 'super' => 0, 'desc' => 'Efficiency capped at x1.05'],
        ['name' => 'Regulation Crackdown', 'layer' => 'ConflictEventMod', 'sys' => 'def_boost', 'mod' => 1.50, 'super' => 0, 'desc' => 'Defence x1.50, attacks x0.70'],
        ['name' => 'Cold Campaign',        'layer' => 'ConflictEventMod', 'sys' => 'cold',      'mod' => 1.00, 'super' => 0, 'desc' => 'No conflicts resolve this round'],
        ['name' => 'Grassroots Surge',     'layer' => 'ContribEventMod',  'sys' => 'policy',    'mod' => 3.00, 'super' => 1, 'desc' => 'Policy contributions x3.00'],
        ['name' => 'Financial Crisis',     'layer' => 'ContribEventMod',  'sys' => 'funding',   'mod' => 0.40, 'super' => 1, 'desc' => 'Finance contributions x0.40'],
        ['name' => 'Credibility Collapse', 'layer' => 'ConflictEventMod', 'sys' => 'bf_double', 'mod' => 2.00, 'super' => 1, 'desc' => 'Backfire chance doubled'],
        ['name' => 'Scandal Wave',         'layer' => 'PowerEventMod',    'sys' => 'scandal',   'mod' => 0.60, 'super' => 1, 'desc' => 'Trust below 0.50 costs 40% power'],
        ['name' => 'Media Blackout',       'layer' => 'PowerEventMod',    'sys' => 'blackout',  'mod' => 0.50, 'super' => 1, 'desc' => 'Effective messaging halved'],
    ];

    public static $TRAIT_META = [
        'policyPowerhouse'     => ['label' => 'Policy Powerhouse',     'icon' => 'scroll',    'effect' => '+5% Policy Impact'],
        'groundGameMachine'    => ['label' => 'Ground Game Machine',   'icon' => 'users-three','effect' => '+5% Efficiency'],
        'mediaDarling'         => ['label' => 'Media Darling',         'icon' => 'megaphone', 'effect' => '+5% Charisma Impact'],
        'allianceBuilder'      => ['label' => 'Alliance Builder',      'icon' => 'handshake', 'effect' => '+5% Effective Defence'],
        'oppositionSpecialist' => ['label' => 'Opposition Specialist', 'icon' => 'target',    'effect' => '+5% Outcome Factor'],
    ];

    public static $SIG_TYPES = ['ATTACK', 'DEFEND', 'PUSH POLICY', 'HOLD'];

    /** §7 Bot allocation templates. Each sums to 1.00 — verified. */
    public static $BOT_TEMPLATES = [
        'aggressive' => ['funding'=>.14,'organization'=>.10,'logistics'=>.08,'policy'=>.12,'charisma'=>.06,'messaging'=>.16,'opposition_research'=>.04,'attack_ads'=>.14,'negative_campaigning'=>.08,'coalitions'=>.08],
        'defensive'  => ['funding'=>.18,'organization'=>.15,'logistics'=>.14,'policy'=>.20,'charisma'=>.07,'messaging'=>.10,'opposition_research'=>.06,'attack_ads'=>.00,'negative_campaigning'=>.00,'coalitions'=>.10],
        'balanced'   => ['funding'=>.17,'organization'=>.14,'logistics'=>.12,'policy'=>.20,'charisma'=>.07,'messaging'=>.13,'opposition_research'=>.05,'attack_ads'=>.05,'negative_campaigning'=>.00,'coalitions'=>.07],
    ];


    // =========================================================================
    // §2.2 UTILITIES
    // =========================================================================

    /** §2.2 safe_div — global rule: 0 when the denominator is not positive. */
    public static function sd($x, $y)
    {
        return ($y <= 0) ? 0.0 : ((float)$x / (float)$y);
    }

    public static function clamp($lo, $hi, $v)
    {
        return max((float)$lo, min((float)$hi, (float)$v));
    }

    public static function rand_float($lo, $hi)
    {
        return $lo + (mt_rand(0, PHP_INT_MAX - 1) / (PHP_INT_MAX - 1)) * ($hi - $lo);
    }

    public static function all_keys()
    {
        return array_merge(self::$CORE_KEYS, self::$NC_KEYS);
    }

    /** Read a system value from an allocation array; absent means zero. */
    private function g(array $a, $k)
    {
        return isset($a[$k]) ? (float)$a[$k] : 0.0;
    }

    /** Zero-filled allocation array. */
    public static function zero_alloc()
    {
        $o = [];
        foreach (self::all_keys() as $k) { $o[$k] = 0.0; }
        return $o;
    }

    // =========================================================================
    // §2.3 ALLOCATION TOTALS
    // =========================================================================

    /** TotalCore — the seven core systems only. */
    public function tc(array $a)
    {
        $s = 0.0;
        foreach (self::$CORE_KEYS as $k) { $s += $this->g($a, $k); }
        return $s;
    }

    /** TotalAll — core plus attack plus coalitions. */
    public function ta(array $a)
    {
        $s = 0.0;
        foreach (self::all_keys() as $k) { $s += $this->g($a, $k); }
        return $s;
    }


    // =========================================================================
    // §5 CONTRIBUTION TIMING
    //
    // A round runs 00:00 → 21:00. Phase boundaries are fractions of that
    // window, identical to the JS engine, so the incentive curve is unchanged:
    //   early  frac < 0.50   weight 1.000   (00:00 – 10:30)
    //   mid    frac < 0.85   weight 1.050   (10:30 – 17:51)   <- the sweet spot
    //   late   otherwise     weight 0.85–0.95 drawn once at round open (17:51 – 21:00)
    // =========================================================================

    /** Fraction of the round elapsed, clamped to [0,1]. */
    public function phase_frac($now_ts, $opens_ts, $closes_ts)
    {
        $span = max(1, $closes_ts - $opens_ts);
        return self::clamp(0.0, 1.0, ($now_ts - $opens_ts) / $span);
    }

    public function phase_for_frac($frac)
    {
        if ($frac < self::EARLY_PHASE_END) return 'early';
        if ($frac < self::MID_PHASE_END)   return 'mid';
        return 'late';
    }

    /**
     * §5 Time weight for a phase.
     * The late weight is drawn ONCE at round open and stored on the round row,
     * so it cannot be sampled repeatedly by refreshing.
     */
    public function time_weight($phase, $late_weight_x1000 = 900)
    {
        switch ($phase) {
            case 'early': return self::EARLY_WEIGHT;
            case 'mid':   return self::MID_WEIGHT;
            case 'late':  return (int)$late_weight_x1000 / 1000;
            default:      return self::EARLY_WEIGHT;
        }
    }

    /** Draw the late-phase weight. Called once, at round open. */
    public function draw_late_weight_x1000()
    {
        $v = self::LATE_WEIGHT_BASE + self::rand_float(-self::LATE_WEIGHT_VOL, self::LATE_WEIGHT_VOL);
        return (int)round($v * 1000);
    }

    /**
     * §5 effective_amount — what the campaign actually banks.
     * floor(amount x timeWeight x contribEventMod x SCALE) / SCALE
     */
    public function effective_amount($amount, $system_key, $time_weight, $round = null)
    {
        $cem = $this->contrib_event_mod($system_key, $round);
        return floor((float)$amount * (float)$time_weight * $cem * self::SCALE) / self::SCALE;
    }

    /**
     * §5 Late-phase per-player cap.
     * maxLateContrib = min(0.25 x playerRoundTotal, 0.15 x avgCampaignContrib)
     * Stops a whale from sitting out the round then dumping at 20:59.
     */
    public function max_late_contrib($player_round_total, $avg_campaign_contrib)
    {
        return min(
            self::LATE_CAP_SELF_FRAC * (float)$player_round_total,
            self::LATE_CAP_AVG_FRAC  * (float)$avg_campaign_contrib
        );
    }


    // =========================================================================
    // §14 / §9.8 EVENT MODIFIER LAYERS
    // =========================================================================

    /** §9.8 ContribEventMod — applied at contribution time, per system. */
    public function contrib_event_mod($system_key, $round = null)
    {
        if (!$round || empty($round->active_event)) return 1.0;
        if ($round->event_layer !== 'ContribEventMod') return 1.0;
        return ($round->event_sys === $system_key) ? (float)$round->event_mod : 1.0;
    }

    /** §9.8 PowerEventMod — applied to final CampaignPower. */
    public function power_event_mod($round, $trust)
    {
        if (!$round || empty($round->active_event)) return 1.0;
        if ($round->event_layer !== 'PowerEventMod') return 1.0;
        switch ($round->event_sys) {
            case 'eff_cap':  return 1.0;  // handled inside compute_power_multipliers
            case 'blackout': return 1.0;  // handled inside compute_messaging
            case 'scandal':  return ($trust < 0.5) ? (float)$round->event_mod : 1.0;
            default:         return (float)$round->event_mod;
        }
    }

    /** §9.8 ConflictEventMod — reshapes conflict resolution. */
    public function conflict_event_mod($round)
    {
        $none = ['def_boost' => 1.0, 'atk_mod' => 1.0, 'bf_double' => false, 'cold' => false];
        if (!$round || empty($round->active_event))        return $none;
        if ($round->event_layer !== 'ConflictEventMod')    return $none;
        return [
            'def_boost' => ($round->event_sys === 'def_boost') ? (float)$round->event_mod : 1.0,
            'atk_mod'   => ($round->event_sys === 'def_boost') ? 0.70 : 1.0,
            'bf_double' => ($round->event_sys === 'bf_double'),
            'cold'      => ($round->event_sys === 'cold'),
        ];
    }

    /** §14 Draw an event for a new round. 50% none / 40% standard / 10% super. */
    public function draw_event()
    {
        $roll = self::rand_float(0, 1);
        if ($roll < self::EVENT_NONE_PROB) return null;

        $super = ($roll >= self::EVENT_NONE_PROB + self::EVENT_STANDARD_PROB);
        $pool  = array_values(array_filter(self::$EVENTS, function ($e) use ($super) {
            return (bool)$e['super'] === $super;
        }));
        return $pool[mt_rand(0, count($pool) - 1)];
    }

    /** §14.1 EventConfidence — Opposition Research buys a reveal roll. Never certain. */
    public function compute_event_confidence(array $alloc)
    {
        $tc = max(1.0, $this->tc($alloc));
        return min(
            self::EV_CONF_BASE + self::EV_CONF_COEFF,
            self::EV_CONF_BASE + self::sd($this->g($alloc, 'opposition_research'), $tc) * self::EV_CONF_COEFF
        );
    }


    // =========================================================================
    // §7 DERIVED STATS
    // =========================================================================

    /** §7.1 Trust — attack spending is what destroys it. Floor 0.10. */
    public function compute_trust(array $alloc)
    {
        $ta = $this->ta($alloc);
        return max(
            self::TRUST_FLOOR,
            1.0
            - self::sd($this->g($alloc, 'attack_ads'),           $ta) * self::TRUST_ATTACK_COEFF
            - self::sd($this->g($alloc, 'negative_campaigning'), $ta) * self::TRUST_NEG_COEFF
        );
    }

    /** §7.2 Credibility — policy and organisation build it, negative campaigning burns it. */
    public function compute_credibility(array $alloc)
    {
        $tc = $this->tc($alloc);
        $ta = $this->ta($alloc);
        return max(self::CRED_FLOOR, min(self::CRED_CEIL,
            1.0
            + self::sd($this->g($alloc, 'policy'),               $tc) * self::CRED_POLICY_COEFF
            + self::sd($this->g($alloc, 'organization'),         $tc) * self::CRED_ORG_COEFF
            - self::sd($this->g($alloc, 'negative_campaigning'), $ta) * self::CRED_NEG_COEFF
        ));
    }

    /** §7.3 Saturation — messaging past half your core is wasted spend. */
    public function compute_messaging(array $alloc, $blackout_active = false)
    {
        $tc  = $this->tc($alloc);
        $raw = $this->g($alloc, 'messaging');

        $S      = min(self::MSG_SAT_HARD, self::sd($raw, $tc));
        $factor = 1.0 - $S * self::SAT_FACTOR_COEFF;
        $eff    = $raw * $factor;

        return [
            'raw'               => $raw,
            'S'                 => $S,
            'saturation_factor' => $factor,
            'saturation_pct'    => (int)round($S * 100),
            'effective_msg'     => $eff,
            'effective_msg_pow' => $blackout_active ? $eff * 0.50 : $eff,
            'gap'               => $raw - $eff,
            'at_warn'           => $S >= self::MSG_SAT_WARN,
            'at_hard'           => $S >= self::MSG_SAT_HARD,
            'blackout_active'   => (bool)$blackout_active,
        ];
    }

    /** §9.5 AggressionLevel — share of the whole budget spent on attacking. */
    public function compute_aggression_level(array $alloc)
    {
        return self::sd(
            $this->g($alloc, 'attack_ads') + $this->g($alloc, 'negative_campaigning'),
            $this->ta($alloc)
        );
    }

    /** §9.5 Aggression cost — opening conflicts burns budget efficiency. */
    public function compute_aggression_cost($aggression_level, $ag_acts)
    {
        $burn = min(self::BURNRATE_CAP, (int)$ag_acts * self::BURNRATE_COEFF);
        $pen  = min(self::AGGR_PEN_CAP, (float)$aggression_level * self::AGGR_PEN_COEFF);
        return [
            'burn_rate'    => $burn,
            'aggr_penalty' => $pen,
            'final_cost'   => (1.0 - $burn) * (1.0 - $pen),
            'ag_acts'      => (int)$ag_acts,
        ];
    }


    // =========================================================================
    // §8 STABILITY
    //
    // The hard gate of the whole game. Miss any one of the three sustainability
    // thresholds and the campaign is in collapse: losers who collapse earn
    // nothing at all, while losers who stayed sustainable still get 15% back.
    // =========================================================================
    public function compute_stability(array $alloc, $cold = false)
    {
        $tc = $this->tc($alloc);
        $ta = $this->ta($alloc);

        $req_f = $tc * self::FUND_REQ_FRAC;
        $req_o = $tc * self::ORG_REQ_FRAC;
        $req_l = $tc * self::LOG_REQ_FRAC;

        $defense_factor = self::sd($this->g($alloc, 'coalitions'), $ta);

        $cov_f = (self::sd($this->g($alloc, 'funding'),      $req_f) - 1.0) * self::FUND_COVER_COEFF;
        $cov_o = (self::sd($this->g($alloc, 'organization'), $req_o) - 1.0) * self::ORG_COVER_COEFF;
        $cov_l = (self::sd($this->g($alloc, 'logistics'),    $req_l) - 1.0) * self::LOG_COVER_COEFF;
        $coal  = min(self::COAL_SUPPORT_MAX, $defense_factor * self::COAL_SUPPORT_COEFF);

        // §14.2 Cold Campaign suspends the aggression stress term for the round
        $stress = $cold ? 0.0 : self::sd(
            $this->g($alloc, 'attack_ads') + $this->g($alloc, 'negative_campaigning'), $ta
        ) * self::AGGR_STRESS_COEFF;

        $score = $cov_f + $cov_o + $cov_l + $coal - $stress;

        if      ($score >= self::STAB_STABLE_MIN)   $state = 'stable';
        elseif  ($score >= self::STAB_STRAINED_MIN) $state = 'strained';
        elseif  ($score >= self::STAB_UNSTABLE_MIN) $state = 'unstable';
        else                                        $state = 'critical';

        $f_fail = $this->g($alloc, 'funding')      < $req_f;
        $o_fail = $this->g($alloc, 'organization') < $req_o;
        $l_fail = $this->g($alloc, 'logistics')    < $req_l;

        // Diagnostic used by the coach panel: how far to the next state up
        $to_next = null;
        if ($state !== 'stable') {
            $target = ($state === 'critical') ? self::STAB_UNSTABLE_MIN
                    : (($state === 'unstable') ? self::STAB_STRAINED_MIN : self::STAB_STABLE_MIN);
            $to_next = (int)ceil(abs($target - $score) * 10);
        }

        return [
            'score'             => $score,
            'state'             => $state,
            'mod'               => self::$STABILITY_STATES[$state]['mod'],
            'collapse'          => ($f_fail || $o_fail || $l_fail),
            'funding_failed'    => $f_fail,
            'org_failed'        => $o_fail,
            'log_failed'        => $l_fail,
            'req_funding'       => $req_f,
            'req_org'           => $req_o,
            'req_log'           => $req_l,
            'funding_coverage'  => $cov_f,
            'org_coverage'      => $cov_o,
            'log_coverage'      => $cov_l,
            'coalition_support' => $coal,
            'aggression_stress' => $stress,
            'defense_factor'    => $defense_factor,
            'tokens_to_next'    => $to_next,
        ];
    }

    /** §11 Effective defence, with diminishing returns above 0.12. */
    public function compute_effective_defense(array $alloc, $trait_bonus = 0.0)
    {
        $df = self::sd($this->g($alloc, 'coalitions'), $this->ta($alloc)) + (float)$trait_bonus;
        if ($df <= self::COALITION_DR_THRESH) return $df;
        return self::COALITION_DR_THRESH
             + ($df - self::COALITION_DR_THRESH) * self::COALITION_DR_COEFF;
    }


    // =========================================================================
    // §9.6 POPULATION MODIFIERS
    //
    // FIX vs the previous PHP port: WeightedPopulation is the SUM of real
    // per-player weights, not a raw head count. A campaign of 50 people who
    // each put in a token is not the same as 50 people who each put in 2,000.
    //
    //   PlayerPopWeight_i   = min(1, contrib_i / avgContrib)
    //   WeightedPopulation  = Σ PlayerPopWeight_i
    //
    // @param array $per_campaign_contribs [campaign_id => [contrib, contrib, …]]
    // =========================================================================
    public function compute_population_modifiers(array $per_campaign_contribs)
    {
        $wps = [];
        foreach (self::$CAMPAIGNS as $cid => $_) {
            $list = isset($per_campaign_contribs[$cid]) ? $per_campaign_contribs[$cid] : [];
            $n    = count($list);
            if ($n === 0) { $wps[$cid] = 0.0; continue; }

            $total = array_sum($list);
            $avg   = self::sd($total, $n);
            if ($avg <= 0) { $wps[$cid] = 0.0; continue; }

            $w = 0.0;
            foreach ($list as $c) { $w += min(1.0, self::sd($c, $avg)); }
            $wps[$cid] = $w;
        }

        /**
         * WHY THIS ALSO WEIGHS MONEY.
         *
         * $wp is a headcount, and it can only ever be a headcount: the per
         * member term is min(1, c/avg), and without that cap the sum is
         * exactly total/avg = n for any distribution. So the cap is the only
         * thing separating $wp from n, and it can only push $wp DOWN, never
         * up. Nothing in it responds to how much money a campaign holds.
         *
         * That left the one balancing mechanism in the game blind to the one
         * quantity the power formula actually runs on. Two campaigns with 30
         * members each were "balanced" and neither was touched, even when one
         * of them was fielding ten times the war chest.
         *
         * The imbalance ratio is now the geometric mean of the head ratio and
         * the war-chest ratio, and the campaign that gets the penalty is the
         * one leading on heads x tokens. A crowd is still an imbalance; so is
         * a small campaign with all the money.
         */
        $chest = [];
        foreach (self::$CAMPAIGNS as $cid => $_) {
            $list = isset($per_campaign_contribs[$cid]) ? $per_campaign_contribs[$cid] : [];
            $chest[$cid] = (float)array_sum($list);
        }

        $vals  = array_values($wps);
        $hr    = self::sd(max(array_merge($vals, [0.001])), max(0.001, min($vals)));
        $cvals = array_values($chest);
        $tr    = self::sd(max(array_merge($cvals, [1.0])), max(1.0, min($cvals)));
        $ratio = sqrt(max(1.0, $hr) * max(1.0, $tr));

        // Who is actually the biggest presence, counting bodies and money.
        $score = [];
        foreach ($wps as $cid => $wp) $score[$cid] = $wp * max(1.0, $chest[$cid]);
        $svals = array_values($score);
        $mx = max($svals);
        $mn = min($svals);

        $out = [];

        // A ratio under 1.05 counts as balanced — no nudging either way.
        if ($ratio < self::POP_RATIO_TRIGGER) {
            foreach ($wps as $cid => $wp) {
                $out[$cid] = ['modifier' => 1.0, 'state' => 'balanced',
                              'penalty' => 0.0, 'bonus' => 0.0, 'ratio' => $ratio, 'wp' => $wp];
            }
            return $out;
        }

        $imb = $ratio - 1.0;
        $ovp = min(self::OVERPOP_CAP,  $imb * self::OVERPOP_COEFF);
        $udg = min(self::UNDERDOG_CAP, (1.0 - self::sd(1.0, $ratio)) * self::UNDERDOG_COEFF);

        foreach ($wps as $cid => $wp) {
            $sc = $score[$cid];
            if ($sc >= $mx - 0.001) {
                $out[$cid] = ['modifier' => 1.0 - $ovp, 'state' => 'overpop',
                              'penalty' => $ovp, 'bonus' => 0.0, 'ratio' => $ratio, 'wp' => $wp];
            } elseif ($sc <= $mn + 0.001) {
                $out[$cid] = ['modifier' => 1.0 + $udg, 'state' => 'underdog',
                              'penalty' => 0.0, 'bonus' => $udg, 'ratio' => $ratio, 'wp' => $wp];
            } else {
                $out[$cid] = ['modifier' => 1.0, 'state' => 'balanced',
                              'penalty' => 0.0, 'bonus' => 0.0, 'ratio' => $ratio, 'wp' => $wp];
            }
        }
        return $out;
    }

    /**
     * §9.7 Peace modifiers — a collective-restraint dividend.
     * If everyone stays calm, everyone at or below average aggression gains.
     *
     * @param array $allocs [campaign_id => alloc array]
     */
    public function compute_peace_modifiers(array $allocs)
    {
        $als = [];
        foreach ($allocs as $cid => $alloc) {
            $als[$cid] = $this->compute_aggression_level($alloc);
        }

        $n      = max(1, count($als));
        $global = array_sum($als) / $n;
        $bonus  = max(0.0, self::PEACE_BONUS_MAX - $global * self::PEACE_BONUS_COEFF);

        $per = [];
        foreach ($als as $cid => $al) {
            $q = ($al <= $global);
            $per[$cid] = [
                'aggression_level' => $al,
                'qualifying'       => $q,
                'modifier'         => $q ? 1.0 + $bonus : 1.0,
                'peace_bonus'      => $q ? $bonus : 0.0,
            ];
        }

        return ['global_aggression' => $global, 'peace_bonus' => $bonus, 'per_campaign' => $per];
    }


    // =========================================================================
    // §23 IDENTITY TRAITS
    //
    // Hold an allocation shape for three consecutive rounds and it becomes a
    // permanent passive. This is the spine of long-term play: it rewards having
    // a consistent political identity, and switching campaigns throws it away.
    // =========================================================================

    /** Does this round's allocation qualify for each trait? */
    public function evaluate_trait_qualification(array $alloc)
    {
        $tc = $this->tc($alloc);
        $ta = $this->ta($alloc);
        return [
            'policyPowerhouse'     => self::sd($this->g($alloc,'policy'), $tc)       >= self::TRAIT_POLICY_THRESH
                                   && self::sd($this->g($alloc,'funding'), $tc)      >= self::TRAIT_POLICY_THRESH * 0.8,
            'groundGameMachine'    => self::sd($this->g($alloc,'organization'), $tc) >= self::TRAIT_ORG_THRESH
                                   && self::sd($this->g($alloc,'logistics'), $tc)    >= self::TRAIT_ORG_THRESH,
            'mediaDarling'         => self::sd($this->g($alloc,'charisma'), $tc)     >= self::TRAIT_CHARISMA_THRESH
                                   && self::sd($this->g($alloc,'messaging'), $tc)    >= self::TRAIT_CHARISMA_THRESH * 1.5,
            'allianceBuilder'      => self::sd($this->g($alloc,'coalitions'), $ta)   >= self::TRAIT_COALITION_THRESH,
            'oppositionSpecialist' => self::sd($this->g($alloc,'attack_ads') + $this->g($alloc,'negative_campaigning'), $ta)
                                      >= self::TRAIT_ATTACK_THRESH,
        ];
    }

    /**
     * Turn stored trait rows into the bonus bundle the power maths consumes.
     * @param array $trait_rows [trait_key => ['active' => bool, 'streak_rounds' => int]]
     */
    public function compute_trait_bonuses(array $trait_rows = [])
    {
        $on = function ($k) use ($trait_rows) {
            return !empty($trait_rows[$k]['active']) ? self::IDENTITY_TRAIT_BONUS : 0.0;
        };
        $active = [];
        foreach (self::$TRAIT_META as $k => $_) {
            if (!empty($trait_rows[$k]['active'])) $active[] = $k;
        }
        return [
            'policy_impact'  => $on('policyPowerhouse'),
            'efficiency'     => $on('groundGameMachine'),
            'charisma'       => $on('mediaDarling'),
            'eff_defense'    => $on('allianceBuilder'),
            'outcome_factor' => $on('oppositionSpecialist'),
            'active'         => $active,
        ];
    }

    /**
     * §23 Persistence multiplier.
     *
     * THIS IS THE METHOD THE PREVIOUS PHP PORT WAS MISSING. Without it a
     * player on a streak got one power figure from the browser and a
     * different one from the server.
     *
     *   rounds_since_win === 1 → the round right after a win   +10%
     *   rounds_since_win === 2 → the round after that          +5%
     *   rounds_since_win === 3 → one-off underdog pity bonus   +15%
     *
     * The underdog bonus fires ONCE and does not repeat, otherwise a player
     * who never wins would accumulate a permanent advantage.
     */
    public function persistence_multiplier($rounds_since_win, $any_round_resolved = true)
    {
        if (!$any_round_resolved) return 1.0;   // no phantom bonus on round 1
        $r = (int)$rounds_since_win;
        if ($r === 1) return 1.0 + self::WINNER_BONUS_R1;
        if ($r === 2) return 1.0 + self::WINNER_BONUS_R2;
        if ($r === 3) return 1.0 + self::LOSER_UNDERDOG_BONUS;
        return 1.0;
    }


    // =========================================================================
    // §9 POWER
    // =========================================================================

    /**
     * §9.1–9.8 Every intermediate multiplier for one campaign.
     * Returned whole so the post-round breakdown screen can explain itself.
     */
    public function compute_power_multipliers(array $alloc, $ag_acts = 0, $round = null, array $traits = [])
    {
        $tc = max(0.0, $this->tc($alloc));

        $cold      = $this->conflict_event_mod($round)['cold'];
        $eff_cap_on = ($round && $round->event_sys === 'eff_cap' && $round->event_layer === 'PowerEventMod');
        $blackout   = ($round && $round->event_sys === 'blackout' && $round->event_layer === 'PowerEventMod');

        $T   = $this->compute_trust($alloc);
        $C   = $this->compute_credibility($alloc);
        $msg = $this->compute_messaging($alloc, $blackout);
        $al  = $this->compute_aggression_level($alloc);
        $ac  = $this->compute_aggression_cost($al, $ag_acts);
        $st  = $this->compute_stability($alloc, $cold);

        $tb = [
            'policy_impact'  => isset($traits['policy_impact'])  ? (float)$traits['policy_impact']  : 0.0,
            'efficiency'     => isset($traits['efficiency'])     ? (float)$traits['efficiency']     : 0.0,
            'charisma'       => isset($traits['charisma'])       ? (float)$traits['charisma']       : 0.0,
        ];

        /* §9.9 BasePower — sub-linear, so raw money alone cannot run away */
        $base = ($tc > 0) ? pow($tc, self::BASE_POWER_EXP) : 0.0;

        /* §9.1 Efficiency.
           Talent Drought caps this AFTER the GroundGameMachine trait is added,
           which nullifies the trait for anyone already at the cap. That is
           intentional — the event targets the efficiency system as a whole.
           Do not "fix" this. */
        $raw_eff = 1.0 + self::sd($this->g($alloc,'organization'), $tc) * self::EFF_COEFF + $tb['efficiency'];
        $eff     = $eff_cap_on ? min((float)$round->event_mod, $raw_eff) : $raw_eff;

        /* §9.1 Infrastructure */
        $infra = 1.0 + self::sd($this->g($alloc,'logistics'), $tc) * self::INFRA_COEFF;

        /* §9.2 PolicyImpact — hard capped, so policy cannot be the only answer */
        $raw_pi = 1.0 + self::sd(
            $this->g($alloc,'policy') * self::POLICY_WEIGHT + $this->g($alloc,'funding') * self::FUND_WEIGHT,
            $tc
        ) * self::POLICY_COEFF;
        $pi = min(self::POLICY_IMPACT_CAP, $raw_pi + $tb['policy_impact']);

        /* §9.3 CharismaImpact */
        $ci = 1.0 + self::sd($this->g($alloc,'charisma'), $tc) * self::CHARISMA_COEFF + $tb['charisma'];

        /* §9.4 Trust / Credibility as power modifiers */
        $trust_mod = self::TRUST_MOD_BASE + $T * self::TRUST_MOD_COEFF;
        $cred_mod  = self::CRED_MOD_BASE  + $C * self::CRED_MOD_COEFF;

        return [
            'tc' => $tc, 'ta' => $this->ta($alloc),
            'T' => $T, 'C' => $C, 'msg' => $msg, 'al' => $al, 'ac' => $ac, 'stab' => $st,
            'base_power'      => $base,
            'efficiency'      => $eff,
            'infra'           => $infra,
            'policy_impact'   => $pi,
            'policy_headroom' => self::POLICY_IMPACT_CAP - $pi,
            'charisma_impact' => $ci,
            'trust_mod'       => $trust_mod,
            'cred_mod'        => $cred_mod,
            'pow_event_mod'   => $this->power_event_mod($round, $T),
            'eff_cap_active'  => $eff_cap_on,
            'blackout_active' => $blackout,
            'cold'            => $cold,
        ];
    }

    /**
     * §9.9 CampaignPower — the full product chain.
     *
     * BasePower x Efficiency x Infrastructure x PolicyImpact x CharismaImpact
     *   x TrustMod x CredibilityMod x StabilityMod x PopulationMod x PeaceMod
     *   x PowerEventMod x ΠConflictImpact x AggressionCost x Persistence x Noise
     *
     * Pass noise = 1.0 for any display read so the cockpit does not jitter.
     * The real noise is drawn once, at settlement, and is never shown.
     */
    public function compute_campaign_power(
        array $m,
        $pop_mod = 1.0,
        $peace_mod = 1.0,
        $conflict_product = 1.0,
        $persist_mod = 1.0,
        $noise = 1.0
    ) {
        $p = $m['base_power']
           * $m['efficiency']
           * $m['infra']
           * $m['policy_impact']
           * $m['charisma_impact']
           * $m['trust_mod']
           * $m['cred_mod']
           * $m['stab']['mod']
           * (float)$pop_mod
           * (float)$peace_mod
           * $m['pow_event_mod']
           * (float)$conflict_product
           * $m['ac']['final_cost']
           * (float)$persist_mod
           * (float)$noise;

        return max(0.0, $p);
    }

    /** §13 WinChance — power buys odds, never certainty. */
    public function compute_win_chances(array $powers)
    {
        $w = []; $sum = 0.0;
        foreach ($powers as $cid => $p) {
            $x = pow(max(0.0, (float)$p), self::WIN_CHANCE_EXP);
            $w[$cid] = $x;
            $sum += $x;
        }
        if ($sum <= 0) {
            $n = max(1, count($powers));
            return array_map(function () use ($n) { return 1.0 / $n; }, $powers);
        }
        foreach ($w as $cid => $x) { $w[$cid] = $x / $sum; }
        return $w;
    }

    /** §9.9 Draw the hidden noise term. Called once per campaign per settlement. */
    public function draw_noise_x1000()
    {
        return mt_rand((int)(self::NOISE_MIN * 1000), (int)(self::NOISE_MAX * 1000));
    }

    /**
     * §24 Weighted winner draw. Returns the winning campaign id.
     * Uses the win chances directly, so the underdog genuinely can win.
     */
    public function draw_winner(array $win_chances)
    {
        $roll = self::rand_float(0, 1);
        $cum  = 0.0;
        foreach ($win_chances as $cid => $wc) {
            $cum += (float)$wc;
            if ($roll <= $cum) return (int)$cid;
        }
        $keys = array_keys($win_chances);
        return (int)end($keys);
    }


    // =========================================================================
    // §10 CONFLICT
    // =========================================================================

    /** §10.1 TargetScore — how attractive is B as a target for A? */
    public function compute_target_score(array $att, $att_power, array $def, $def_power, $def_trait_bonus = 0.0)
    {
        $al      = $this->compute_aggression_level($att);
        $eff_def = $this->compute_effective_defense($def, $def_trait_bonus);
        $ratio   = self::sd((float)$def_power, max(0.0001, (float)$att_power));
        return $al * pow($ratio, 0.5) * (1.0 - $eff_def);
    }

    /** §10.2 OutcomeFactor — messaging and money decide who lands the blow. */
    public function compute_outcome_factor(array $att, array $def, $trait_bonus = 0.0)
    {
        $ma = max(1.0, $this->compute_messaging($att)['effective_msg']);
        $mb = max(1.0, $this->compute_messaging($def)['effective_msg']);
        $fa = max(1.0, $this->g($att, 'funding'));
        $fb = max(1.0, $this->g($def, 'funding'));
        return pow($ma / $mb, 0.6) * pow($fa / $fb, 0.4) * (1.0 + (float)$trait_bonus);
    }

    /** §10.3 A low-credibility attacker lands a weaker blow. */
    public function compute_attack_cred_mod(array $att)
    {
        $cm = self::CRED_MOD_BASE + $this->compute_credibility($att) * self::CRED_MOD_COEFF;
        return min(1.0, max(self::ATK_CRED_MOD_MIN, $cm));
    }

    /**
     * §10.4 ConflictImpact — the multiplier applied to the DEFENDER's power.
     *   below 1.0 = the attack landed        above 1.0 = it generated sympathy
     *
     * FIXED vs GameEngine-JS.js (two defects, both confirmed by live play):
     *
     *   1. SIGN INVERSION. The JS computes `1 + (of - 1) * intensity * acm`,
     *      and this value multiplies the *target's* power. OutcomeFactor above
     *      1 means the ATTACKER is stronger — so under the JS formula the
     *      better-resourced attacker made its target MORE powerful. Backwards.
     *      The sign is flipped here: attacker advantage now costs the defender.
     *
     *   2. PULSE APPLIED TO THE VALUE, NOT THE EFFECT. `ci * PULSE_MOD` pushes
     *      a damaging 0.90 to 1.008 — an offensive surge that *helps* the
     *      target. The surge now amplifies the deviation from neutral instead.
     *
     * A botched attack from a weaker campaign still backfires upward, capped at
     * +15%: swinging at someone above your weight and generating sympathy for
     * them is a real dynamic, and it is bounded exactly like the damage side.
     */
    public function compute_conflict_impact(array $att, array $def, $pulse_off = false, $of_trait_bonus = 0.0)
    {
        $al  = $this->compute_aggression_level($att);
        $of  = $this->compute_outcome_factor($att, $def, $of_trait_bonus);
        $acm = $this->compute_attack_cred_mod($att);
        $int = min(self::CONFLICT_INTEN_CAP, $al);

        // Negative deviation = damage to the defender.
        $dev = -($of - 1.0) * $int * $acm;
        if ($pulse_off) $dev *= self::PULSE_MOD;

        return self::clamp(self::CONFLICT_IMPACT_MIN, self::CONFLICT_IMPACT_MAX, 1.0 + $dev);
    }

    /** §10.7 Dogpiling decays. The fourth attack on a target is worth 55% of the first. */
    public function compute_multi_attack_penalty($n)
    {
        return max(self::MULTI_ATK_PEN_FLOOR, 1.0 - self::MULTI_ATK_PEN_COEFF * ((int)$n - 1));
    }

    /** §10.9 Backfire — low trust means your own attack can hit you. */
    public function compute_backfire_chance(array $att, $bf_double = false)
    {
        $T  = $this->compute_trust($att);
        $ta = $this->ta($att);
        $c  = (1.0 - $T) * self::BACKFIRE_TRUST_COEFF
            + self::sd($this->g($att, 'negative_campaigning'), $ta) * self::BACKFIRE_NEG_COEFF;
        if ($bf_double) $c *= 2.0;
        return min(0.95, $c);
    }

    /**
     * §10.8 EffectiveConflictImpact — every dampener applied, ready to multiply
     * into the defender's power.
     *
     * FIXED vs GameEngine-JS.js. The JS returns
     *   `ci * map * (1 - effDef*0.5) * atkMod`
     * which scales the multiplier ITSELF toward zero. But 1.0 means "no
     * effect", not zero — so a multi-attack penalty, a strong defence and a
     * Regulation Crackdown, all of which are supposed to make an attack
     * WEAKER, instead compounded it into annihilation. Six ordinary attacks
     * on one campaign took it to 5% of its power in live testing:
     *   0.85 x 0.40 x 0.50 = 0.17 per strike, then multiplied together.
     *
     * Dampeners now scale the DEVIATION from 1.0, so every one of them pulls
     * the result toward "no effect", which is what they were always meant to
     * do. A single strike still cannot move a campaign more than 15%, and
     * stacking waves decays instead of compounding.
     */
    public function compute_effective_conflict_impact(
        array $att, array $def, $n,
        $pulse_off = false, $pulse_def = false,
        $round = null, $def_trait_bonus = 0.0, $att_trait_bonus = 0.0
    ) {
        $ev  = $this->conflict_event_mod($round);
        $ci  = $this->compute_conflict_impact($att, $def, $pulse_off, $att_trait_bonus);
        $map = $this->compute_multi_attack_penalty($n);

        $eff_def = $this->compute_effective_defense($def, $def_trait_bonus);
        $eff_def = min(1.0, $eff_def * $ev['def_boost'] * ($pulse_def ? self::PULSE_MOD : 1.0));

        // Deviation from neutral, damped by wave decay, defence and events.
        $dev = ($ci - 1.0) * $map * (1.0 - $eff_def * 0.5) * $ev['atk_mod'];

        return self::clamp(self::CONFLICT_IMPACT_MIN, self::CONFLICT_IMPACT_MAX, 1.0 + $dev);
    }

    /** §10.6 Attacks land on a delay, so a target has time to react. */
    public function draw_resolve_delay()
    {
        return mt_rand(self::RESOLVE_DELAY_MIN, self::RESOLVE_DELAY_MAX);
    }


    // =========================================================================
    // §15 SCAN — paid intel, deliberately imperfect
    // =========================================================================

    /** Accuracy falls as you scan repeatedly inside the decay window. */
    public function compute_scan_accuracy($scan_load)
    {
        return max(self::SCAN_ACC_FLOOR, 1.0 - (max(1, (int)$scan_load) - 1) * self::SCAN_ACC_DECAY);
    }

    public function compute_display_noise($accuracy)
    {
        return self::SCAN_NOISE_BASE + (1.0 - $accuracy) * self::SCAN_NOISE_COEFF;
    }

    /** §15 Scan cost: 1–3% of what you have already committed this round. */
    public function compute_scan_cost($round_contrib, $intel_window = false, $fixed_pct = null)
    {
        $pct  = ($fixed_pct === null)
              ? self::rand_float(self::SCAN_COST_MIN_PCT, self::SCAN_COST_MAX_PCT)
              : (float)$fixed_pct;
        $base = max(100, (int)(floor(max(100, $round_contrib) * $pct / 100) * 100));
        $disc = $intel_window ? self::INTEL_WIN_DISCOUNT : 0.0;
        return max(100, (int)(round($base * (1 - $disc) / 100) * 100));
    }

    /** Stable midpoint estimate for the UI, so the displayed price does not flicker. */
    public function scan_cost_display($round_contrib, $intel_window = false)
    {
        return $this->compute_scan_cost(
            $round_contrib, $intel_window,
            (self::SCAN_COST_MIN_PCT + self::SCAN_COST_MAX_PCT) / 2
        );
    }

    /** §17.3 Pulse cost — a flat 2% of committed spend. */
    public function compute_pulse_cost($round_contrib)
    {
        return max(100, (int)(floor(max(100, $round_contrib) * self::PULSE_COST_PCT / 100) * 100));
    }

    /**
     * §15 Build a scan payload: the target's shape, blurred by display noise.
     * The caller has already charged for it.
     */
    public function build_scan_payload(array $target_alloc, $accuracy, $target_ag_acts, $cold = false)
    {
        $noise = $this->compute_display_noise($accuracy);
        $tc    = max(1.0, $this->tc($target_alloc));

        $breakdown = [];
        foreach (self::$CORE_KEYS as $k) {
            $actual = self::sd($this->g($target_alloc, $k), $tc) * 100;
            $shown  = max(0, $actual + (self::rand_float(0, 1) - 0.5) * $noise * 2 * 100);
            $breakdown[$k] = [
                'label'     => self::$SYS_META[$k]['label'],
                'displayed' => (int)round($shown),
            ];
        }

        $T    = $this->compute_trust($target_alloc);
        $stab = $this->compute_stability($target_alloc, $cold);

        return [
            'accuracy'         => $accuracy,
            'noise'            => $noise,
            'system_breakdown' => $breakdown,
            'trust_level'      => $T > 0.75 ? 'High' : ($T > 0.50 ? 'Medium' : 'Low'),
            'burn_rate'        => min(self::BURNRATE_CAP, (int)$target_ag_acts * self::BURNRATE_COEFF),
            'ag_acts'          => (int)$target_ag_acts,
            'aggression_level' => $this->compute_aggression_level($target_alloc),
            'backfire_chance'  => $this->compute_backfire_chance($target_alloc),
            'stability_state'  => $stab['state'],
            'collapse_risk'    => $stab['collapse'],
            'effective_defense'=> $this->compute_effective_defense($target_alloc),
        ];
    }


    // =========================================================================
    // §20 REWARD
    //
    // Winners split the losers' pool, net of the platform sink.
    //   60% pro-rata by (soft-capped) contribution
    //   40% by sqrt-weighted team share x participation maturity
    // Losers who stayed sustainable recover 15%. Losers who collapsed get nothing.
    // =========================================================================

    public function compute_soft_cap($winner_total)
    {
        return (float)$winner_total * self::SOFT_CAP_FRAC;
    }

    /** §20.3 Contribution above the soft cap counts at half. Anti-runaway. */
    public function compute_effective_contribution($contrib, $soft_cap)
    {
        if ($contrib <= $soft_cap) return (float)$contrib;
        return $soft_cap + ((float)$contrib - $soft_cap) * self::SOFT_CAP_EXCESS;
    }

    public function compute_team_weight($eff_contrib, $maturity)
    {
        return sqrt(max(0.0, (float)$eff_contrib)) * (float)$maturity;
    }

    /** §20.2 Below this you are a spectator, not a participant. */
    public function compute_min_contrib_threshold($avg_campaign_contrib)
    {
        return (float)$avg_campaign_contrib * self::MIN_CONTRIB_FRAC;
    }

    /** §20.4 Maturity — rewards showing up, caps out so veterans cannot farm it. */
    public function compute_maturity($rounds_played, $account_age_days)
    {
        return min(1.0,
            self::MATURITY_BASE
            + (int)$rounds_played * self::MATURITY_ROUNDS_COEFF
            + ((int)$account_age_days / self::MATURITY_AGE_DAYS) * self::MATURITY_AGE_COEFF
        );
    }

    /**
     * §13.1 Outcome class.
     * Winners: victory (gap > 20%) or narrow. Losers: resilient or collapse.
     */
    public function classify_outcome($winner_cid, $player_cid, array $powers, array $player_stab = null)
    {
        if ((int)$winner_cid !== (int)$player_cid) {
            return (!empty($player_stab['collapse'])) ? 'collapse' : 'resilient';
        }
        $mine   = isset($powers[$player_cid]) ? (float)$powers[$player_cid] : 0.0;
        $rivals = [];
        foreach ($powers as $cid => $p) { if ((int)$cid !== (int)$player_cid) $rivals[] = (float)$p; }
        $max_rival = $rivals ? max($rivals) : 0.0;
        $gap = ($max_rival > 0) ? (($mine - $max_rival) / $max_rival) * 100 : 100;
        return ($gap > self::VICTORY_GAP_PCT) ? 'victory' : 'narrow';
    }

    /** What a round event actually did, by its stored display name. */
    public static function event_desc($name)
    {
        foreach (self::$EVENTS as $e) {
            if ($e['name'] === $name) return $e['desc'];
        }
        return '';
    }

    /**
     * Why a campaign finished where it did.
     *
     * Power is a PRODUCT of multipliers, so "why" is not a matter of opinion:
     * rank them by how far each sits from 1.0 and the biggest movers are the
     * real reasons. Every number here was written at settlement — mults_json is
     * the bundle that produced the result — so this explains the actual round
     * rather than re-deriving a plausible story from the allocation afterwards.
     * That distinction matters: an explanation that can disagree with the score
     * is worse than none.
     *
     * @param  object $rc  a pc_round_campaign row
     * @return array  factors, largest effect first, each
     *                ['mult', 'label', 'note', 'pct']
     */
    public static function explain_power($rc)
    {
        $m = json_decode(isset($rc->mults_json) ? (string)$rc->mults_json : '', true);
        if (!is_array($m)) $m = [];

        $f = [];
        $add = function ($mult, $label, $note) use (&$f) {
            if ($mult === null || $mult === '') return;
            $mult = (float)$mult;
            /* Under half a percent is not a reason, it is noise. */
            if ($mult <= 0 || abs($mult - 1.0) < 0.005) return;
            $f[] = [
                'mult'  => $mult,
                'label' => $label,
                'note'  => $note,
                'pct'   => ($mult - 1.0) * 100,
            ];
        };

        $g = function ($k, $d = null) use ($m) { return isset($m[$k]) ? $m[$k] : $d; };

        $state = $g('stab_state', isset($rc->stability_state) ? $rc->stability_state : null);
        $add($g('stab_mod'), 'Campaign stability',
             $state ? ucfirst($state).' operations' : 'Core systems');

        /* Aggression is TWO penalties multiplied, and they have different cures,
           so reporting the product tells a player nothing about what to change.
           burn_rate comes from conflicts OPENED and caps out early; the rest is
           the standing penalty for holding budget in attack systems at all.
           Reported together they look identical for a campaign that opened four
           and one that opened fourteen — which is exactly what happened, and is
           the sort of thing a report is supposed to stop happening. */
        $aggr = $g('aggr_cost');
        $burn = $g('burn_rate');
        $acts = (int)$rc->ag_acts;
        $n    = $acts === 1 ? '1 conflict opened' : $acts.' conflicts opened';

        if ($aggr !== null && $burn !== null && (float)$burn < 1.0) {
            $cap_at = (int)ceil(self::BURNRATE_CAP / self::BURNRATE_COEFF);
            $add(1.0 - (float)$burn, 'Conflict burn rate',
                 $acts >= $cap_at
                   ? $n.' — capped; the first '.$cap_at.' cost all of it'
                   : $n);
            $add((float)$aggr / (1.0 - (float)$burn), 'Attack spending',
                 'Budget held in attack systems');
        } else {
            $add($aggr, 'Aggression cost', $n);   // pre-burn_rate rows
        }
        $add($g('infra'),           'Field infrastructure', 'Organisation and logistics balance');
        $add($g('policy_impact'),   'Policy platform',      'Policy spend converted to appeal');
        $add($g('charisma_impact'), 'Charisma',             'Candidate pull');
        $add($g('trust_mod'),       'Voter trust',
             'Trust '.number_format((float)$rc->trust, 3));
        $add($g('cred_mod'),        'Credibility',
             'Credibility '.number_format((float)$rc->credibility, 3));
        $add($g('efficiency'),      'Operational efficiency', 'Spend converted to power');
        $add($g('pow_event_mod'),   'Round event',          'Event pressure on this campaign');

        /* These three are columns rather than bundle keys — they are decided
           after the per-campaign multipliers, by what the rest of the board did. */
        $add(isset($rc->pop_mod) ? $rc->pop_mod : null, 'Popularity',
             (float)$rc->pop_mod < 1 ? 'Crowded — too many backers' : 'Underdog momentum');
        $add(isset($rc->peace_mod) ? $rc->peace_mod : null, 'Peace bonus',
             'Restraint across the board');
        $add(isset($rc->conflict_product) ? $rc->conflict_product : null, 'Conflict damage',
             'Attacks that landed this round');

        usort($f, function ($a, $b) {
            $da = abs($a['mult'] - 1.0); $db = abs($b['mult'] - 1.0);
            if ($da === $db) return 0;
            return ($da < $db) ? 1 : -1;
        });
        return $f;
    }

    /**
     * §20 Reward for one player.
     *
     * @param int    $winner_cid
     * @param int    $player_cid
     * @param float  $player_contrib   face value, already zeroed if below floor
     * @param array  $face_totals      [campaign_id => face total]
     * @param string $outcome
     * @param float  $maturity
     * @param float  $winner_team_weight_sum  Σ sqrt(effContrib_i) x maturity_i over winners
     * @param int|null $loser_pool     the pool actually available to winners.
     *        MUST be supplied by the caller once the resilient refunds are
     *        known — see the note below. Derived only as a fallback.
     */
    public function compute_reward(
        $winner_cid, $player_cid, $player_contrib,
        array $face_totals, $outcome, $maturity,
        $winner_team_weight_sum = 0.0,
        $loser_pool = null
    ) {
        $blank = ['reward' => 0, 'profit' => -(int)$player_contrib, 'efficiency_score' => 0,
                  'outcome' => $outcome, 'indiv_share' => 0.0, 'team_share' => 0.0, 'loser_pool' => 0];

        if ($player_contrib <= 0) {
            return array_merge($blank, ['profit' => 0, 'efficiency_score' => null]);
        }

        $grand  = array_sum($face_totals);
        $wtotal = isset($face_totals[$winner_cid]) ? (float)$face_totals[$winner_cid] : 0.0;
        $ltotal = $grand - $wtotal;

        /**
         * The pool winners divide.
         *
         * FIXED vs GameEngine-JS.js. The JS hands winners the full
         * `loserTotal x 0.95` AND separately refunds sustainable losers 15% of
         * their stake — obligations of 110% against 100% of the losing side's
         * money. On a decisive win that mints ~10% of the losing pot out of
         * nothing every round, which compounds into runaway inflation once the
         * game runs daily.
         *
         * The refunds now come OUT of the pool before winners divide it, so the
         * only tokens ever destroyed are the 5% platform sink. It also makes the
         * design read better: a disciplined loser who stays solvent genuinely
         * denies the winner part of the spoils.
         */
        $pool = ($loser_pool === null)
              ? (int)floor($ltotal * (1 - self::PLATFORM_FEE))
              : max(0, (int)$loser_pool);

        $mult   = isset(self::$OUTCOME_MULTIPLIERS[$outcome]) ? self::$OUTCOME_MULTIPLIERS[$outcome] : 0.0;
        $indiv  = 0.0; $team = 0.0; $reward = 0;

        if ($outcome === 'collapse') {
            $reward = 0;

        } elseif ((int)$winner_cid === (int)$player_cid) {
            $soft  = $this->compute_soft_cap($wtotal);
            $effc  = $this->compute_effective_contribution($player_contrib, $soft);

            // §20.6 IndivShare (60%) — straight pro-rata on capped contribution
            $indiv = self::sd($effc, max(1.0, $wtotal)) * $pool * self::INDIV_SHARE_FRAC;

            // §20.6 TeamShare (40%) — sqrt weighting compresses the gap between
            // big and small contributors, then maturity scales it.
            $mine  = $this->compute_team_weight($effc, $maturity);
            $denom = ($winner_team_weight_sum > 0) ? $winner_team_weight_sum : max(1.0, $mine);
            $team  = $pool * self::TEAM_SHARE_FRAC * self::sd($mine, $denom);

            // Stake back, plus the winnings scaled by how decisive the win was
            $reward = (int)$player_contrib + (int)floor(max(0.0, ($indiv + $team) * $mult));

        } else {
            // Sustainable loss. Discipline pays even in defeat.
            $reward = (int)floor($player_contrib * self::$OUTCOME_MULTIPLIERS['resilient']);
        }

        $profit = $reward - (int)$player_contrib;

        return [
            'reward'           => $reward,
            'profit'           => $profit,
            'efficiency_score' => (int)min(500, max(0, round(self::sd($reward, $player_contrib) * 100))),
            'outcome'          => $outcome,
            'indiv_share'      => $indiv,
            'team_share'       => $team,
            'loser_pool'       => $pool,
            'grand_total'      => $grand,
            'winner_total'     => $wtotal,
            'loser_total'      => $ltotal,
            'outcome_mult'     => $mult,
        ];
    }


    // =========================================================================
    // §12 MIGRATION — switching campaigns costs money and voids your carry-over
    // =========================================================================
    public function migration_penalty_rate($switch_count)
    {
        $n = (int)$switch_count;
        if ($n <= 0) return 0.0;
        if ($n === 1) return self::MIGRATION_PENALTY_1;
        if ($n === 2) return self::MIGRATION_PENALTY_2;
        return self::MIGRATION_PENALTY_3;
    }


    // =========================================================================
    // §23 CARRY-OVER — half your Logistics and Policy survives into next round
    // =========================================================================
    public function build_carryover(array $alloc)
    {
        return [
            'logistics' => floor($this->g($alloc, 'logistics') * self::LOGISTICS_CARRY_FRAC),
            'policy'    => floor($this->g($alloc, 'policy')    * self::POLICY_CARRY_FRAC),
        ];
    }


    // =========================================================================
    // §21 COUNCIL — a fallible in-game advisor
    //
    // It nudges toward the revealed event and is deliberately imperfect. Its
    // confidence is earned from its own track record, so a player learns how
    // much to trust it rather than being told.
    // =========================================================================
    public function council_recommendation($round = null, $event_revealed = false, array $history = [], $rounds_played = 0)
    {
        $rec = [
            'funding' => .18, 'organization' => .16, 'logistics' => .12, 'policy' => .22,
            'charisma' => .07, 'messaging' => .12, 'opposition_research' => .05,
            'attack_ads' => .02, 'negative_campaigning' => .01, 'coalitions' => .05,
        ];

        if ($round && $event_revealed && !empty($round->event_sys)) {
            switch ($round->event_sys) {
                case 'policy':
                    $rec['policy'] += 0.08; $rec['charisma'] -= 0.04; $rec['messaging'] -= 0.04; break;
                case 'funding':
                    $rec['funding'] += 0.08; $rec['coalitions'] -= 0.04; $rec['charisma'] -= 0.04; break;
                case 'messaging':
                    $rec['messaging'] += 0.06; $rec['charisma'] -= 0.03; $rec['logistics'] -= 0.03; break;
                case 'logistics':
                    $rec['logistics'] -= 0.05; $rec['organization'] += 0.03; $rec['policy'] += 0.02; break;
            }
        }

        foreach ($rec as $k => $v) { $rec[$k] = max(0.0, $v); }
        $tot = array_sum($rec);
        if ($tot > 0) { foreach ($rec as $k => $v) { $rec[$k] = $v / $tot; } }

        $good = count(array_filter($history, function ($h) { return $h === 'good'; }));
        $conf = ($rounds_played < 3) ? 'Unproven'
              : (($good >= 3) ? 'High' : (($good >= 1) ? 'Medium' : 'Low'));

        arsort($rec);
        $top = array_slice($rec, 0, 3, true);
        ksort($rec);

        return ['rec' => $rec, 'confidence' => $conf, 'top' => $top];
    }


    // =========================================================================
    // HELPERS FOR THE PRESENTATION LAYER
    // =========================================================================

    public static function campaign($cid)
    {
        return isset(self::$CAMPAIGNS[$cid]) ? self::$CAMPAIGNS[$cid] : null;
    }

    public static function campaign_name($cid)
    {
        $c = self::campaign($cid);
        return $c ? $c['name'] : 'UNKNOWN';
    }

    /** Turn a pc_round_campaign row into the flat alloc array the engine wants. */
    public function alloc_from_row($row)
    {
        $a = self::zero_alloc();
        if (!$row) return $a;
        foreach (self::all_keys() as $k) {
            $a[$k] = isset($row->$k) ? (float)$row->$k : 0.0;
        }
        return $a;
    }
}
