<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * GameEngine
 *
 * CodeIgniter 3 library. Place at application/libraries/GameEngine.php.
 * Load with: $this->load->library('GameEngine');
 * Access via: $this->gameengine->method()
 *
 * This is the authoritative PHP port of every formula in engine.js.
 * Section numbers (§) refer to the Campaign Domination Race whitepaper v3.0.
 *
 * Two public entry points used by Cron:
 *
 *   compute_power_multipliers(array $totals, int $ag_acts, bool $cold)
 *     → returns $mults array (all intermediate values for §9.9 and storage)
 *
 *   compute_campaign_power(array $totals, array $mults,
 *                          float $pop_mod, float $peace_mod,
 *                          float $pow_event_mod, float $conflict_product,
 *                          float $noise, object $round)
 *     → returns float (final CampaignPower)
 *
 * Plus helper methods used by Conflict_model and live_tick:
 *   compute_population_modifiers(array $all_totals, array $player_counts)
 *   compute_peace_modifiers(array $all_totals)
 *   compute_win_chances(array $powers)
 *   compute_target_score(array $att_totals, float $att_power, array $def_totals, float $def_power)
 *
 * All monetary values are internal units (display × SCALE = 1000).
 * safe_div() is applied to every ratio. Constants mirror ENGINE in engine.js exactly.
 */
class GameEngine
{
    // =========================================================================
    // §2.1 SCALE
    // =========================================================================
    const SCALE = 1000;

    // =========================================================================
    // §7 DERIVED STAT CONSTANTS
    // =========================================================================
    const TRUST_FLOOR           = 0.10;
    const TRUST_ATTACK_COEFF    = 0.60;
    const TRUST_NEG_COEFF       = 0.80;

    const CRED_FLOOR            = 0.30;
    const CRED_CEIL             = 1.50;
    const CRED_POLICY_COEFF     = 0.50;
    const CRED_ORG_COEFF        = 0.30;
    const CRED_NEG_COEFF        = 0.60;

    const MSG_SAT_HARD          = 0.50;
    const SAT_FACTOR_COEFF      = 0.40;

    // =========================================================================
    // §8 STABILITY CONSTANTS
    // =========================================================================
    const FUND_COVER_COEFF      = 10;
    const ORG_COVER_COEFF       = 8;
    const LOG_COVER_COEFF       = 6;
    const COAL_SUPPORT_MAX      = 5;
    const COAL_SUPPORT_COEFF    = 40;
    const AGGR_STRESS_COEFF     = 20;

    const STAB_STABLE_MIN       =  0;
    const STAB_STRAINED_MIN     = -10;
    const STAB_UNSTABLE_MIN     = -25;

    // §8.3 Sustainability — percentage-of-TotalCore thresholds
    const FUND_REQ_FRAC         = 0.15;
    const ORG_REQ_FRAC          = 0.12;
    const LOG_REQ_FRAC          = 0.08;

    // Stability state power modifiers
    const STAB_MOD = [
        'stable'              => 1.10,
        'strained'            => 0.90,
        'unstable'            => 0.75,
        'critical_instability'=> 0.50,
    ];

    // =========================================================================
    // §9 POWER CONSTANTS
    // =========================================================================
    const BASE_POWER_EXP        = 0.90;
    const EFF_COEFF             = 0.40;
    const INFRA_COEFF           = 0.50;
    const POLICY_IMPACT_CAP     = 1.25;
    const POLICY_COEFF          = 0.40;
    const POLICY_WEIGHT         = 0.70;
    const FUND_WEIGHT           = 0.30;
    const CHARISMA_COEFF        = 0.30;
    const TRUST_MOD_BASE        = 0.80;
    const TRUST_MOD_COEFF       = 0.40;
    const CRED_MOD_BASE         = 0.80;
    const CRED_MOD_COEFF        = 0.30;
    const BURNRATE_COEFF        = 0.10;
    const BURNRATE_CAP          = 0.50;
    const AGGR_PEN_COEFF        = 0.50;
    const AGGR_PEN_CAP          = 0.20;

    // =========================================================================
    // §9.6 POPULATION CONSTANTS
    // =========================================================================
    const OVERPOP_CAP           = 0.35;
    const OVERPOP_COEFF         = 0.25;
    const UNDERDOG_CAP          = 0.40;
    const UNDERDOG_COEFF        = 0.50;
    const POP_RATIO_TRIGGER     = 1.05;

    // =========================================================================
    // §9.7 PEACE CONSTANTS
    // =========================================================================
    const PEACE_BONUS_MAX       = 0.08;
    const PEACE_BONUS_COEFF     = 0.50;

    // =========================================================================
    // §9.9 WIN CHANCE EXPONENT
    // =========================================================================
    const WIN_CHANCE_EXP        = 0.90;

    // =========================================================================
    // §10 CONFLICT CONSTANTS
    // =========================================================================
    const CONFLICT_IMPACT_MIN   = 0.85;
    const CONFLICT_IMPACT_MAX   = 1.25;
    const CONFLICT_INTEN_CAP    = 0.50;
    const ATK_CRED_MOD_MIN      = 0.60;
    const MULTI_ATK_PEN_COEFF   = 0.15;
    const MULTI_ATK_PEN_FLOOR   = 0.40;
    const BACKFIRE_TRUST_COEFF  = 0.50;
    const BACKFIRE_NEG_COEFF    = 0.30;
    const PULSE_MOD             = 1.12;

    // =========================================================================
    // §11 COALITION DEFENSE
    // =========================================================================
    const COALITION_DR_THRESH   = 0.12;
    const COALITION_DR_COEFF    = 0.40;

    // =========================================================================
    // §23 TRAIT BONUSES
    // =========================================================================
    const IDENTITY_TRAIT_BONUS  = 0.05;

    // =========================================================================
    // §2.2 SAFE_DIV — global rule
    // =========================================================================

    /**
     * §2.2 safe_div: returns 0 when denominator ≤ 0.
     * Applied to every ratio in this file.
     */
    public static function safe_div($x, $y)
    {
        return ($y <= 0) ? 0.0 : (float)($x / $y);
    }

    /** Clamp a value between lo and hi inclusive. */
    public static function clamp($lo, $hi, $v)
    {
        return max((float)$lo, min((float)$hi, (float)$v));
    }

    // =========================================================================
    // §2.3 ALLOCATION TOTALS
    // =========================================================================

    /** Keys that make up TotalCore. */
    private static $_core_keys = [
        'funding', 'organization', 'logistics', 'policy',
        'charisma', 'messaging', 'opposition_research',
    ];

    /** Keys outside TotalCore (Attack, NC, Coalitions). */
    private static $_nc_keys = [
        'attack_ads', 'negative_campaigning', 'coalitions',
    ];

    /**
     * Safely read a system value from a totals array; returns 0 if absent.
     */
    private function _g(array $t, $key)
    {
        return isset($t[$key]) ? (float)$t[$key] : 0.0;
    }

    // =========================================================================
    // §7.1 TRUST
    // =========================================================================

    /**
     * §7.1  T = max(0.10, 1 − attack_ads/TotalAll×0.60 − neg_camp/TotalAll×0.80)
     *
     * @param  array $totals  campaign totals with 'total_all' pre-computed
     * @return float
     */
    public function compute_trust(array $totals)
    {
        $ta = max(1.0, $this->_g($totals, 'total_all'));
        return max(
            self::TRUST_FLOOR,
            1.0
            - self::safe_div($this->_g($totals, 'attack_ads'),           $ta) * self::TRUST_ATTACK_COEFF
            - self::safe_div($this->_g($totals, 'negative_campaigning'), $ta) * self::TRUST_NEG_COEFF
        );
    }

    // =========================================================================
    // §7.2 CREDIBILITY
    // =========================================================================

    /**
     * §7.2  C = max(0.30, min(1.50,
     *              1 + policy/TC×0.50 + org/TC×0.30 − neg_camp/TA×0.60))
     */
    public function compute_credibility(array $totals)
    {
        $tc = max(1.0, $this->_g($totals, 'total_core'));
        $ta = max(1.0, $this->_g($totals, 'total_all'));
        return max(
            self::CRED_FLOOR,
            min(
                self::CRED_CEIL,
                1.0
                + self::safe_div($this->_g($totals, 'policy'),               $tc) * self::CRED_POLICY_COEFF
                + self::safe_div($this->_g($totals, 'organization'),         $tc) * self::CRED_ORG_COEFF
                - self::safe_div($this->_g($totals, 'negative_campaigning'), $ta) * self::CRED_NEG_COEFF
            )
        );
    }

    // =========================================================================
    // §7.3 SATURATION & EFFECTIVE MESSAGING
    // =========================================================================

    /**
     * §7.3  S = min(0.50, messaging/TotalCore)
     *       SaturationFactor = 1 − S×0.40
     *       EffectiveMessaging = messaging × SaturationFactor
     *
     * Returns ['S', 'saturation_factor', 'effective_msg', 'effective_msg_pow']
     * effective_msg_pow applies the Media Blackout event multiplier if active.
     */
    public function compute_messaging(array $totals, $blackout_active = false)
    {
        $tc  = max(1.0, $this->_g($totals, 'total_core'));
        $raw = $this->_g($totals, 'messaging');

        $S                = min(self::MSG_SAT_HARD, self::safe_div($raw, $tc));
        $saturation_factor = 1.0 - $S * self::SAT_FACTOR_COEFF;
        $effective_msg     = $raw * $saturation_factor;
        $effective_msg_pow = $blackout_active ? $effective_msg * 0.50 : $effective_msg;

        return [
            'S'                => $S,
            'saturation_factor'=> $saturation_factor,
            'effective_msg'    => $effective_msg,
            'effective_msg_pow'=> $effective_msg_pow,
        ];
    }

    // =========================================================================
    // §8 STABILITY
    // =========================================================================

    /**
     * §8.1–8.3  Compute full stability result.
     *
     * @param  array $totals  campaign contribution totals
     * @param  bool  $cold    true when Cold Campaign event active
     * @return array {
     *   score, state, mod, collapse,
     *   funding_failed, org_failed, log_failed,
     *   req_funding, req_org, req_log,
     *   funding_coverage, org_coverage, log_coverage,
     *   coalition_support, aggression_stress, defense_factor
     * }
     */
    public function compute_stability(array $totals, $cold = false)
    {
        $tc = max(1.0, $this->_g($totals, 'total_core'));
        $ta = max(1.0, $this->_g($totals, 'total_all'));

        // §8.3 Percentage-of-TotalCore thresholds
        $req_funding = $tc * self::FUND_REQ_FRAC;
        $req_org     = $tc * self::ORG_REQ_FRAC;
        $req_log     = $tc * self::LOG_REQ_FRAC;

        $defense_factor = self::safe_div($this->_g($totals, 'coalitions'), $ta);

        // §8.1 Coverage scores
        $funding_coverage  = (self::safe_div($this->_g($totals, 'funding'),      $req_funding) - 1.0) * self::FUND_COVER_COEFF;
        $org_coverage      = (self::safe_div($this->_g($totals, 'organization'), $req_org)     - 1.0) * self::ORG_COVER_COEFF;
        $log_coverage      = (self::safe_div($this->_g($totals, 'logistics'),    $req_log)     - 1.0) * self::LOG_COVER_COEFF;
        $coalition_support = min(self::COAL_SUPPORT_MAX, $defense_factor * self::COAL_SUPPORT_COEFF);

        // §14.2 Cold Campaign: AggressionStressScore excluded this round
        if ($cold) {
            $aggression_stress = 0.0;
        } else {
            $aggression_stress = self::safe_div(
                $this->_g($totals, 'attack_ads') + $this->_g($totals, 'negative_campaigning'),
                $ta
            ) * self::AGGR_STRESS_COEFF;
        }

        $score = $funding_coverage + $org_coverage + $log_coverage
               + $coalition_support - $aggression_stress;

        // §8.2 State classification
        if ($score >= self::STAB_STABLE_MIN) {
            $state = 'stable';
        } elseif ($score >= self::STAB_STRAINED_MIN) {
            $state = 'strained';
        } elseif ($score >= self::STAB_UNSTABLE_MIN) {
            $state = 'unstable';
        } else {
            $state = 'critical_instability';
        }

        $mod = self::STAB_MOD[$state];

        // §13.1 Collapse: any threshold failed
        $funding_failed = $this->_g($totals, 'funding')      < $req_funding;
        $org_failed     = $this->_g($totals, 'organization')  < $req_org;
        $log_failed     = $this->_g($totals, 'logistics')     < $req_log;
        $collapse       = $funding_failed || $org_failed || $log_failed;

        return [
            'score'            => $score,
            'state'            => $state,
            'mod'              => $mod,
            'collapse'         => $collapse,
            'funding_failed'   => $funding_failed,
            'org_failed'       => $org_failed,
            'log_failed'       => $log_failed,
            'req_funding'      => $req_funding,
            'req_org'          => $req_org,
            'req_log'          => $req_log,
            'funding_coverage' => $funding_coverage,
            'org_coverage'     => $org_coverage,
            'log_coverage'     => $log_coverage,
            'coalition_support'=> $coalition_support,
            'aggression_stress'=> $aggression_stress,
            'defense_factor'   => $defense_factor,
        ];
    }

    // =========================================================================
    // §9.5 AGGRESSION LEVEL AND COST
    // =========================================================================

    /**
     * §9.5  AggressionLevel = safe_div(attack_ads + neg_camp, TotalAll)
     */
    public function compute_aggression_level(array $totals)
    {
        $ta = max(1.0, $this->_g($totals, 'total_all'));
        return self::safe_div(
            $this->_g($totals, 'attack_ads') + $this->_g($totals, 'negative_campaigning'),
            $ta
        );
    }

    /**
     * §9.5  BurnRate = min(0.50, ag_acts × 0.10)
     *       AggressionPenalty = min(0.20, al × 0.50)
     *       FinalAggressionCost = (1−BurnRate) × (1−AggressionPenalty)
     *
     * @return array {burn_rate, aggr_penalty, final_cost}
     */
    public function compute_aggression_cost($aggression_level, $ag_acts)
    {
        $burn_rate    = min(self::BURNRATE_CAP, (int)$ag_acts * self::BURNRATE_COEFF);
        $aggr_penalty = min(self::AGGR_PEN_CAP, (float)$aggression_level * self::AGGR_PEN_COEFF);
        $final_cost   = (1.0 - $burn_rate) * (1.0 - $aggr_penalty);
        return [
            'burn_rate'    => $burn_rate,
            'aggr_penalty' => $aggr_penalty,
            'final_cost'   => $final_cost,
        ];
    }

    // =========================================================================
    // §9.8 EVENT MODIFIERS
    // =========================================================================

    /**
     * §9.8 PowerEventMod — multiplier on final CampaignPower.
     * Reads the round's active_event and event_layer.
     * Scandal Wave: applies ×0.60 only when Trust < 0.5.
     * Talent Drought, Media Blackout: handled inside compute_power_multipliers.
     *
     * @param  object $round   cwr_rounds row
     * @param  float  $trust   T value for this campaign
     * @return float
     */
    public function get_power_event_mod($round, $trust)
    {
        if ( ! $round || $round->event_layer !== 'power' || empty($round->active_event)) {
            return 1.0;
        }
        switch ($round->active_event) {
            case 'Scandal Wave':
                return $trust < 0.5 ? 0.60 : 1.0;
            case 'Media Blackout':
                return 1.0; // handled via effective_msg_pow in messaging
            case 'Talent Drought':
                return 1.0; // handled via efficiency cap in compute_power_multipliers
            default:
                return 1.0;
        }
    }

    // =========================================================================
    // §9 FULL POWER MULTIPLIER BUNDLE
    // =========================================================================

    /**
     * §9.1–9.8  Compute every intermediate multiplier for one campaign.
     *
     * This is the primary method called by both live_tick (noise=1.0) and
     * settle_round (with actual noise).
     *
     * Accepts optional $trait_bonuses array produced by the caller from
     * cwr_user_traits rows.  If absent, all bonuses default to zero
     * (no trait active).
     *
     * Returns $mults array — every key is consumed by either
     * compute_campaign_power() or Payout_model::write_campaign_result():
     *   base_power, T, C, msg, stab, al, ac,
     *   efficiency, infra, policy_impact, charisma_impact,
     *   trust_mod, cred_mod, pow_event_mod,
     *   peace_mod, pop_mod,
     *   eff_cap_active, blackout_active
     *
     * peace_mod and pop_mod are passed in from the caller (they require
     * cross-campaign data).  Default 1.0 is used when not provided.
     *
     * @param  array  $totals          from Contribution_model::get_campaign_totals()
     * @param  int    $ag_acts         from cwr_round_campaign_state.ag_acts
     * @param  bool   $cold            true when Cold Campaign event active
     * @param  object|null $round      cwr_rounds row (for event mods)
     * @param  array  $trait_bonuses   optional {policy_impact:f, efficiency:f,
     *                                   charisma:f, eff_defense:f, outcome_factor:f}
     * @return array  $mults
     */
    public function compute_power_multipliers(
        array $totals,
        $ag_acts  = 0,
        $cold     = false,
        $round    = null,
        array $trait_bonuses = []
    ) {
        $tc = max(0.0, $this->_g($totals, 'total_core'));
        $ta = max(0.0, $this->_g($totals, 'total_all'));

        // §7.1–7.3 Derived stats
        $T   = $this->compute_trust($totals);
        $C   = $this->compute_credibility($totals);
        $al  = $this->compute_aggression_level($totals);
        $ac  = $this->compute_aggression_cost($al, $ag_acts);

        // Event flags
        $eff_cap_active  = $round && $round->active_event === 'Talent Drought';
        $blackout_active = $round && $round->active_event === 'Media Blackout';

        $msg = $this->compute_messaging($totals, $blackout_active);

        // §8 Stability
        $stab = $this->compute_stability($totals, $cold);

        // §9.9 BasePower = TotalCore ^ 0.9
        $base_power = $tc > 0 ? pow($tc, self::BASE_POWER_EXP) : 0.0;

        // §9.1 Efficiency = 1 + org/TC×0.40  (+trait bonus)
        $trait_eff_bonus = isset($trait_bonuses['efficiency']) ? (float)$trait_bonuses['efficiency'] : 0.0;
        $raw_efficiency  = 1.0 + self::safe_div($this->_g($totals, 'organization'), $tc) * self::EFF_COEFF
                         + $trait_eff_bonus;
        // Talent Drought caps Efficiency at ×1.05 (including trait)
        $eff_cap    = $eff_cap_active ? 1.05 : PHP_FLOAT_MAX;
        $efficiency = min($eff_cap, $raw_efficiency);

        // §9.1 Infrastructure = 1 + logistics/TC×0.50
        $infra = 1.0 + self::safe_div($this->_g($totals, 'logistics'), $tc) * self::INFRA_COEFF;

        // §9.2 PolicyImpact = min(1.25, 1 + (policy×0.7+funding×0.3)/TC×0.40)  (+trait)
        $trait_pi_bonus  = isset($trait_bonuses['policy_impact']) ? (float)$trait_bonuses['policy_impact'] : 0.0;
        $raw_pi = 1.0 + self::safe_div(
            $this->_g($totals, 'policy')  * self::POLICY_WEIGHT
            + $this->_g($totals, 'funding') * self::FUND_WEIGHT,
            $tc
        ) * self::POLICY_COEFF;
        $policy_impact = min(self::POLICY_IMPACT_CAP, $raw_pi + $trait_pi_bonus);

        // §9.3 CharismaImpact = 1 + charisma/TC×0.30  (+trait)
        $trait_ch_bonus   = isset($trait_bonuses['charisma']) ? (float)$trait_bonuses['charisma'] : 0.0;
        $charisma_impact  = 1.0 + self::safe_div($this->_g($totals, 'charisma'), $tc) * self::CHARISMA_COEFF
                          + $trait_ch_bonus;

        // §9.4 TrustMod / CredibilityMod
        $trust_mod = self::TRUST_MOD_BASE + $T * self::TRUST_MOD_COEFF;
        $cred_mod  = self::CRED_MOD_BASE  + $C * self::CRED_MOD_COEFF;

        // §9.8 PowerEventMod
        $pow_event_mod = $this->get_power_event_mod($round, $T);

        return [
            // Raw stats
            'T'              => $T,
            'C'              => $C,
            'al'             => $al,
            'ac'             => $ac,              // {burn_rate, aggr_penalty, final_cost}
            'msg'            => $msg,             // {S, saturation_factor, effective_msg, …}
            'stab'           => $stab,            // {score, state, mod, collapse, …}
            // Power multipliers
            'base_power'     => $base_power,
            'efficiency'     => $efficiency,
            'infra'          => $infra,
            'policy_impact'  => $policy_impact,
            'charisma_impact'=> $charisma_impact,
            'trust_mod'      => $trust_mod,
            'cred_mod'       => $cred_mod,
            'pow_event_mod'  => $pow_event_mod,
            // Cross-campaign mods — populated by caller, default 1.0
            'pop_mod'        => 1.0,
            'peace_mod'      => 1.0,
            // Event flags
            'eff_cap_active' => $eff_cap_active,
            'blackout_active'=> $blackout_active,
        ];
    }

    // =========================================================================
    // §9.9 CAMPAIGN POWER — FINAL FORMULA
    // =========================================================================

    /**
     * §9.9  CampaignPower = BasePower
     *                     × Efficiency
     *                     × Infrastructure
     *                     × PolicyImpact
     *                     × CharismaImpact
     *                     × TrustMod
     *                     × CredibilityMod
     *                     × StabilityModifier
     *                     × PopulationModifier
     *                     × PeaceModifier
     *                     × PowerEventMod
     *                     × ConflictProduct (Π EffConflictImpact)
     *                     × FinalAggressionCost
     *                     × Noise
     *
     * pop_mod, peace_mod, pow_event_mod, conflict_product and noise are
     * passed in separately because they require cross-campaign or
     * stochastic data the caller holds.
     *
     * For live_tick (stable display): pass noise=1.0, pop_mod=1.0, peace_mod=1.0.
     * For settlement: pass the actual drawn noise and computed modifiers.
     *
     * @param  array  $totals           campaign contribution totals
     * @param  array  $mults            from compute_power_multipliers()
     * @param  float  $pop_mod          PopulationModifier (§9.6)
     * @param  float  $peace_mod        PeaceModifier (§9.7)
     * @param  float  $pow_event_mod    PowerEventMod override (pass 1.0 to use mults value)
     * @param  float  $conflict_product Π(EffectiveConflictImpact) — default 1.0
     * @param  float  $noise            random(0.95, 1.05) — pass 1.0 for display
     * @param  object|null $round       unused here; kept for signature compatibility
     * @return float
     */
    public function compute_campaign_power(
        array  $totals,
        array  $mults,
        $pop_mod          = 1.0,
        $peace_mod        = 1.0,
        $pow_event_mod    = 1.0,
        $conflict_product = 1.0,
        $noise            = 1.0,
        $round            = null
    ) {
        // Use the mults PowerEventMod if caller passed 1.0 (no override)
        $effective_pow_event = ($pow_event_mod == 1.0)
            ? $mults['pow_event_mod']
            : (float)$pow_event_mod;

        $power = $mults['base_power']
               * $mults['efficiency']
               * $mults['infra']
               * $mults['policy_impact']
               * $mults['charisma_impact']
               * $mults['trust_mod']
               * $mults['cred_mod']
               * $mults['stab']['mod']
               * (float)$pop_mod
               * (float)$peace_mod
               * $effective_pow_event
               * (float)$conflict_product
               * $mults['ac']['final_cost']
               * (float)$noise;

        return max(0.0, $power);
    }

    // =========================================================================
    // §9.6 POPULATION MODIFIERS
    // =========================================================================

    /**
     * §9.6  Compute PopulationModifier for every campaign.
     *
     * $all_totals:    [campaign_id => totals_array]  (from Contribution_model)
     * $player_counts: [campaign_id => int]           (total players per campaign)
     *
     * WeightedPopulation per campaign ≈ sum of PlayerPopWeight values.
     * Since this runs server-side without individual player contribution
     * breakdowns, we approximate:
     *   WeightedPopulation ≈ player_count  (each player at exactly average)
     * This is the correct value when all players contribute equally.
     * For the live-tick display this approximation is acceptable; the
     * settlement cron can pass refined per-player weights if desired.
     *
     * Returns [campaign_id => float modifier]
     */
    public function compute_population_modifiers(array $all_totals, array $player_counts)
    {
        // Weighted populations
        $wps = [];
        foreach ($all_totals as $cid => $totals) {
            $n       = max(1, isset($player_counts[$cid]) ? (int)$player_counts[$cid] : 1);
            $tc      = max(1.0, $this->_g($totals, 'total_all'));
            // Approximate: all players contribute at the average
            $wps[$cid] = (float)$n;
        }

        if (empty($wps)) {
            return [];
        }

        $max_wp = max($wps);
        $min_wp = min($wps);
        $ratio  = self::safe_div($max_wp, max(0.001, $min_wp));

        // If ratio < 1.05 consider it balanced
        if ($ratio < self::POP_RATIO_TRIGGER) {
            return array_map(function() { return 1.0; }, $wps);
        }

        $imb = $ratio - 1.0;
        $ovp = min(self::OVERPOP_CAP, $imb * self::OVERPOP_COEFF);
        $udg = min(self::UNDERDOG_CAP, (1.0 - self::safe_div(1.0, $ratio)) * self::UNDERDOG_COEFF);

        $result = [];
        foreach ($wps as $cid => $wp) {
            if ($wp >= $max_wp - 0.001) {
                $result[$cid] = 1.0 - $ovp;  // larger campaign — penalty
            } elseif ($wp <= $min_wp + 0.001) {
                $result[$cid] = 1.0 + $udg;  // smaller campaign — bonus
            } else {
                $result[$cid] = 1.0;
            }
        }
        return $result;
    }

    // =========================================================================
    // §9.7 PEACE MODIFIERS
    // =========================================================================

    /**
     * §9.7  Compute PeaceModifier for every campaign.
     *
     * GlobalAggression = average AggressionLevel across all campaigns.
     * PeaceBonus = max(0, 0.08 − GlobalAggression × 0.50)
     * Campaign qualifies if its AggressionLevel ≤ GlobalAggression.
     *
     * @param  array $all_totals  [campaign_id => totals_array]
     * @return array {global_aggression, peace_bonus, modifiers: [cid => float]}
     */
    public function compute_peace_modifiers(array $all_totals)
    {
        $als = [];
        foreach ($all_totals as $cid => $totals) {
            $als[$cid] = $this->compute_aggression_level($totals);
        }

        $n                 = max(1, count($als));
        $global_aggression = array_sum($als) / $n;
        $peace_bonus       = max(0.0, self::PEACE_BONUS_MAX - $global_aggression * self::PEACE_BONUS_COEFF);

        $modifiers = [];
        foreach ($als as $cid => $al) {
            $modifiers[$cid] = $al <= $global_aggression
                ? 1.0 + $peace_bonus
                : 1.0;
        }

        return [
            'global_aggression' => $global_aggression,
            'peace_bonus'       => $peace_bonus,
            'modifiers'         => $modifiers,
        ];
    }

    // =========================================================================
    // §13 WIN CHANCES
    // =========================================================================

    /**
     * §13  WinChance_i = (Power_i^0.9) / Σ(Power_j^0.9)
     *
     * @param  array $powers  [campaign_id => float]
     * @return array          [campaign_id => float] — sums to ~1.0
     */
    public function compute_win_chances(array $powers)
    {
        $weighted = [];
        $sum      = 0.0;

        foreach ($powers as $cid => $p) {
            $w           = pow(max(0.0, (float)$p), self::WIN_CHANCE_EXP);
            $weighted[$cid] = $w;
            $sum += $w;
        }

        if ($sum <= 0.0) {
            $n = max(1, count($powers));
            return array_map(function() use ($n) { return 1.0 / $n; }, $powers);
        }

        $chances = [];
        foreach ($weighted as $cid => $w) {
            $chances[$cid] = $w / $sum;
        }
        return $chances;
    }

    // =========================================================================
    // §11 EFFECTIVE DEFENSE
    // =========================================================================

    /**
     * §11  EffectiveDefense with diminishing returns above 0.12.
     *
     *   if DefenseFactor ≤ 0.12:  return DefenseFactor
     *   else: 0.12 + (DF − 0.12) × 0.40
     */
    public function compute_effective_defense(array $totals, $trait_bonus = 0.0)
    {
        $ta = max(1.0, $this->_g($totals, 'total_all'));
        $df = self::safe_div($this->_g($totals, 'coalitions'), $ta);
        $df += (float)$trait_bonus; // §23 AllianceBuilder

        if ($df <= self::COALITION_DR_THRESH) {
            return $df;
        }
        return self::COALITION_DR_THRESH
             + ($df - self::COALITION_DR_THRESH) * self::COALITION_DR_COEFF;
    }

    // =========================================================================
    // §10.1 TARGET SCORE
    // =========================================================================

    /**
     * §10.1  TargetScore(A→B) = AggressionLevel_A
     *                          × (Power_B / Power_A)^0.5
     *                          × (1 − EffectiveDefense_B)
     *
     * @param  array $att_totals  attacker contribution totals
     * @param  float $att_power   attacker estimated power
     * @param  array $def_totals  defender contribution totals
     * @param  float $def_power   defender estimated power
     * @return float
     */
    public function compute_target_score(
        array $att_totals, $att_power,
        array $def_totals, $def_power
    ) {
        $al      = $this->compute_aggression_level($att_totals);
        $eff_def = $this->compute_effective_defense($def_totals);
        $p_ratio = self::safe_div((float)$def_power, max(0.0001, (float)$att_power));
        return $al * pow($p_ratio, 0.5) * (1.0 - $eff_def);
    }

    // =========================================================================
    // §10.2 OUTCOME FACTOR
    // =========================================================================

    /**
     * §10.2  OutcomeFactor = (max(EffMsg_A,1)/max(EffMsg_B,1))^0.6
     *                      × (max(Fund_A,1)/max(Fund_B,1))^0.4
     *
     * §23 OppositionSpecialist trait: +5% multiplicative on OutcomeFactor.
     */
    public function compute_outcome_factor(
        array $att_totals,
        array $def_totals,
        $trait_bonus = 0.0
    ) {
        $att_msg = $this->compute_messaging($att_totals);
        $def_msg = $this->compute_messaging($def_totals);

        $em_a = max(1.0, $att_msg['effective_msg']);
        $em_b = max(1.0, $def_msg['effective_msg']);
        $f_a  = max(1.0, $this->_g($att_totals, 'funding'));
        $f_b  = max(1.0, $this->_g($def_totals, 'funding'));

        return pow($em_a / $em_b, 0.6)
             * pow($f_a  / $f_b,  0.4)
             * (1.0 + (float)$trait_bonus);
    }

    // =========================================================================
    // §10.3 ATTACK CREDIBILITY MOD
    // =========================================================================

    /**
     * §10.3  AttackCredibilityMod = min(1.0, max(0.60, CredibilityMod_A))
     */
    public function compute_attack_cred_mod(array $att_totals)
    {
        $C        = $this->compute_credibility($att_totals);
        $cred_mod = self::CRED_MOD_BASE + $C * self::CRED_MOD_COEFF;
        return min(1.0, max(self::ATK_CRED_MOD_MIN, $cred_mod));
    }

    // =========================================================================
    // §10.4 CONFLICT IMPACT
    // =========================================================================

    /**
     * §10.4  ConflictIntensity = min(0.50, AggressionLevel_A)
     *        raw = 1 + (OutcomeFactor − 1) × Intensity × AttackCredMod
     *        ConflictImpact = clamp(0.85, 1.25, raw)
     *
     * §10.5  If pulse_off_active: multiply by PULSE_MOD (×1.12)
     */
    public function compute_conflict_impact(
        array $att_totals,
        array $def_totals,
        $pulse_off_active = false,
        $outcome_factor_trait_bonus = 0.0
    ) {
        $al        = $this->compute_aggression_level($att_totals);
        $of        = $this->compute_outcome_factor($att_totals, $def_totals, $outcome_factor_trait_bonus);
        $acm       = $this->compute_attack_cred_mod($att_totals);
        $intensity = min(self::CONFLICT_INTEN_CAP, $al);

        $raw = 1.0 + ($of - 1.0) * $intensity * $acm;
        $ci  = self::clamp(self::CONFLICT_IMPACT_MIN, self::CONFLICT_IMPACT_MAX, $raw);

        return $pulse_off_active ? $ci * self::PULSE_MOD : $ci;
    }

    // =========================================================================
    // §10.7 MULTI-ATTACK PENALTY
    // =========================================================================

    /**
     * §10.7  MultiAttackPenalty(N) = max(0.40, 1 − 0.15×(N−1))
     *
     * @param  int $n  1-based attack index against this target this round
     * @return float
     */
    public function compute_multi_attack_penalty($n)
    {
        return max(
            self::MULTI_ATK_PEN_FLOOR,
            1.0 - self::MULTI_ATK_PEN_COEFF * ((int)$n - 1)
        );
    }

    // =========================================================================
    // §10.8 EFFECTIVE CONFLICT IMPACT
    // =========================================================================

    /**
     * §10.8  EffectiveConflictImpact(N) =
     *          ConflictImpact
     *        × MultiAttackPenalty(N)
     *        × (1 − EffectiveDefense_B × 0.5)
     *        × ConflictEventMod
     *
     * §10.5  PulseDefenseMod = 1.12 × EffectiveDefense  (when pulse active)
     * §23    AllianceBuilder: adds to EffectiveDefense via trait_bonus
     */
    public function compute_effective_conflict_impact(
        array $att_totals,
        array $def_totals,
        $n,
        $pulse_off_active   = false,
        $pulse_def_active   = false,
        $conflict_event_mod = 1.0,
        $def_trait_bonus    = 0.0,
        $att_trait_bonus    = 0.0
    ) {
        $ci  = $this->compute_conflict_impact($att_totals, $def_totals, $pulse_off_active, $att_trait_bonus);
        $map = $this->compute_multi_attack_penalty($n);

        $eff_def = $this->compute_effective_defense($def_totals, $def_trait_bonus);
        if ($pulse_def_active) {
            $eff_def = min(1.0, $eff_def * self::PULSE_MOD);
        }

        return $ci * $map * (1.0 - $eff_def * 0.5) * (float)$conflict_event_mod;
    }

    // =========================================================================
    // §10.9 BACKFIRE CHANCE
    // =========================================================================

    /**
     * §10.9  BackfireChance = (1−T)×0.50 + safe_div(NegCamp, TotalAll)×0.30
     *        Doubles when Credibility Collapse event active.
     *
     * @param  array  $att_totals
     * @param  bool   $bf_double   true when Credibility Collapse event active
     * @return float  clamped to [0, 0.95]
     */
    public function compute_backfire_chance(array $att_totals, $bf_double = false)
    {
        $T  = $this->compute_trust($att_totals);
        $ta = max(1.0, $this->_g($att_totals, 'total_all'));

        $chance = (1.0 - $T) * self::BACKFIRE_TRUST_COEFF
                + self::safe_div($this->_g($att_totals, 'negative_campaigning'), $ta)
                  * self::BACKFIRE_NEG_COEFF;

        if ($bf_double) {
            $chance *= 2.0;
        }

        return min(0.95, $chance);
    }

    // =========================================================================
    // §14.1 EVENT CONFIDENCE
    // =========================================================================

    /**
     * §14.1  EventConfidence = 0.70 + safe_div(OppositionResearch, TotalCore)×0.20
     *        Capped at 0.90 (never certain).
     */
    public function compute_event_confidence(array $totals)
    {
        $tc = max(1.0, $this->_g($totals, 'total_core'));
        return min(
            0.90,
            0.70 + self::safe_div($this->_g($totals, 'opposition_research'), $tc) * 0.20
        );
    }

    // =========================================================================
    // §20.4 PARTICIPATION MATURITY
    // =========================================================================

    /**
     * §20.4  ParticipationMaturity = min(1.0,
     *            0.60 + rounds_played×0.08 + (account_age_days/30)×0.08)
     *
     * @param  int $rounds_played
     * @param  int $account_age_days
     * @return float
     */
    public function compute_maturity($rounds_played, $account_age_days)
    {
        return min(
            1.0,
            0.60
            + (int)$rounds_played * 0.08
            + ((int)$account_age_days / 30.0) * 0.08
        );
    }

    // =========================================================================
    // §12 MIGRATION PENALTY
    // =========================================================================

    /**
     * §12.3  Migration penalty rate by switch count.
     *
     * @param  int $switch_count  1-based number of switches in current cycle
     * @return float  penalty rate (0.05 / 0.10 / 0.20)
     */
    public function migration_penalty_rate($switch_count)
    {
        if ((int)$switch_count === 1) return 0.05;
        if ((int)$switch_count === 2) return 0.10;
        return 0.20;
    }

    // =========================================================================
    // §22.1 TRANSFER TAX
    // =========================================================================

    /**
     * §22.1  TransferTax = 0.10 + (volume / DailyLimit) × 0.10
     *        DailyLimit = balance × 0.075
     *        Returns tax rate (0.0–0.20).
     *
     * @param  float $amount       gross transfer amount (internal units)
     * @param  float $today_volume already-transferred today (internal units)
     * @param  float $balance      total wallet balance (internal units)
     * @return float
     */
    public function compute_transfer_tax($amount, $today_volume, $balance)
    {
        $daily_limit = max(1.0, $balance * 0.075);
        $vol_ratio   = self::safe_div($today_volume + $amount, $daily_limit);
        return min(0.20, 0.10 + $vol_ratio * 0.10);
    }

    // =========================================================================
    // §5 TIME WEIGHT
    // =========================================================================

    /**
     * §5  time_weight × 1000 for a given phase.
     * Late phase weight is drawn once at round start and stored — this
     * method accepts the pre-drawn value for the late phase.
     *
     * @param  string $phase              'early' | 'mid' | 'late'
     * @param  int    $late_weight_x1000  pre-drawn late weight (850–950)
     * @return int
     */
    public function time_weight_for_phase($phase, $late_weight_x1000 = 900)
    {
        switch ($phase) {
            case 'early': return 1000;
            case 'mid':   return 1050;
            case 'late':  return (int)$late_weight_x1000;
            default:      return 1000;
        }
    }

    // =========================================================================
    // §9.9 NOISE DRAW
    // =========================================================================

    /**
     * §9.9  Draw a noise value × 1000 in [950, 1050].
     * Called once per campaign per settlement — stored in cwr_campaign_results.
     *
     * @return int  950–1050
     */
    public function draw_noise_x1000()
    {
        return mt_rand(950, 1050);
    }

    // =========================================================================
    // FULL CAMPAIGN POWER — convenience wrapper for settlement
    // =========================================================================

    /**
     * Compute final power for a campaign at settlement time.
     * Combines compute_power_multipliers() + compute_campaign_power()
     * with all cross-campaign modifiers passed in.
     *
     * This is a convenience wrapper — Cron::_settle_one() can also call
     * the two methods separately to inspect intermediate values.
     *
     * @param  array  $totals           campaign contribution totals
     * @param  int    $ag_acts
     * @param  bool   $cold
     * @param  object $round
     * @param  float  $pop_mod          from compute_population_modifiers()
     * @param  float  $peace_mod        from compute_peace_modifiers()
     * @param  float  $conflict_product Π(EffConflictImpact)
     * @param  float  $noise            drawn via draw_noise_x1000() / 1000
     * @param  array  $trait_bonuses    optional
     * @return array  ['power' => float, 'mults' => array]
     */
    public function full_campaign_power(
        array  $totals,
        $ag_acts         = 0,
        $cold            = false,
        $round           = null,
        $pop_mod         = 1.0,
        $peace_mod       = 1.0,
        $conflict_product= 1.0,
        $noise           = 1.0,
        array  $trait_bonuses = []
    ) {
        $mults = $this->compute_power_multipliers($totals, $ag_acts, $cold, $round, $trait_bonuses);

        // Inject the cross-campaign mods so they are readable from $mults
        $mults['pop_mod']   = (float)$pop_mod;
        $mults['peace_mod'] = (float)$peace_mod;

        $power = $this->compute_campaign_power(
            $totals, $mults,
            $pop_mod, $peace_mod,
            1.0,       // pow_event_mod already inside $mults, no override
            $conflict_product,
            $noise
        );

        return ['power' => $power, 'mults' => $mults];
    }
}
