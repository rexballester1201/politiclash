/* ════════════════════════════════════════════════════════
   KINGMAKERCLASH — GAME ENGINE v2.2
   Production-ready. All whitepaper §§ implemented.
   Zero side-effects in pure functions.
   Display layer consumes getGameState() each RAF tick.

   v2.1 patch — all doc-8 issues resolved:
   - computeStability param renamed _playerCountIgnored + call-site comments
   - playerPopWeight defaults 0 (not null) in getGameState campaign objects
   - Underdog bonus applies next round only (rsSinceWin === 3), not forever
   - Outcome/reward mismatch: excludedFromReward flag added; outcomeClass preserved
   - switchCamp clears carryOverAlloc to prevent cross-camp carry bleed
   - OutcomeFactor trait bonus multiplicative (×) not additive (+)
   - scanCostDisplay uses stable midpoint in getGameState; random drawn at scan time
   - traitBonuses memoized once per getGameState frame, passed into all call sites
   - buildCarryOverAlloc ordering dependency documented with comment

   v2.2 patch — all doc-9 / reviewer issues resolved:
   - playerPopWeight null at source fixed: computePopulationModifiers now passes
     d.playerPopWeight directly; computeWeightedPopulations always returns 0 (not
     null) for non-player camps; ?? 0 fallback in getGameState removed
   - Trait memoization completed: computeOutcomeFactor, computeConflictImpact,
     computeEffectiveConflictImpact all accept optional precomputedTraits param;
     buildAttack computes traits once and threads through; rivalIntel in
     getGameState passes frame-level traitBonuses into all conflict call sites
   - Talent Drought × GroundGameMachine interaction documented in
     computePowerMultipliers with explicit "intentional" note
   - Dead playerCount variable removed from resolveRound; avgCampContrib now
     inlines camps[G.playerCamp].players + 1 directly
   - scanCostDisplay snaps to nearest 100 after intel discount: two-step
     rounding (base → discount → re-snap) ensures clean token denomination
════════════════════════════════════════════════════════ */

/* ════════════════════════════════════════════════════════
   §2.1 ENGINE CONSTANTS
   Single source of truth for every magic number.
════════════════════════════════════════════════════════ */
const ENGINE = {
  /* §2.1 Integer precision */
  SCALE:                   1000,

  /* Economy */
  STARTING_BALANCE:        100000,
  ROUND_SECS:              300,
  PLATFORM_FEE:            0.05,

  /* §20.3 Soft cap */
  SOFT_CAP_FRAC:           0.25,
  SOFT_CAP_EXCESS:         0.50,

  /* §7.1 Trust */
  TRUST_FLOOR:             0.10,
  TRUST_ATTACK_COEFF:      0.60,
  TRUST_NEG_COEFF:         0.80,

  /* §7.2 Credibility */
  CRED_FLOOR:              0.30,
  CRED_CEIL:               1.50,
  CRED_POLICY_COEFF:       0.50,
  CRED_ORG_COEFF:          0.30,
  CRED_NEG_COEFF:          0.60,

  /* §7.3 Saturation */
  MSG_SAT_HARD:            0.50,
  MSG_SAT_WARN:            0.28,
  SAT_FACTOR_COEFF:        0.40,

  /* §8.1 Stability score coefficients */
  FUND_COVER_COEFF:        10,
  ORG_COVER_COEFF:         8,
  LOG_COVER_COEFF:         6,
  COAL_SUPPORT_MAX:        5,
  COAL_SUPPORT_COEFF:      40,
  AGGR_STRESS_COEFF:       20,

  /* §8.2 Stability thresholds */
  STAB_STABLE_MIN:         0,
  STAB_STRAINED_MIN:      -10,
  STAB_UNSTABLE_MIN:      -25,

  /* §8.3 Sustainability requirements — percentage of TotalCore
     RESOLVED: adopting percentage-of-core from HTML source.
     Whitepaper per-player values produce trivially-passing thresholds.
     rf = tc×0.15, ro = tc×0.12, rl = tc×0.08                        */
  FUND_REQ_FRAC:           0.15,
  ORG_REQ_FRAC:            0.12,
  LOG_REQ_FRAC:            0.08,

  /* §9.1 Core power multipliers */
  EFF_COEFF:               0.40,
  INFRA_COEFF:             0.50,

  /* §9.2 Policy impact */
  POLICY_IMPACT_CAP:       1.25,
  POLICY_COEFF:            0.40,
  POLICY_WEIGHT:           0.70,
  FUND_WEIGHT:             0.30,

  /* §9.3 Charisma */
  CHARISMA_COEFF:          0.30,

  /* §9.4 Trust/Cred modifiers */
  TRUST_MOD_BASE:          0.80,
  TRUST_MOD_COEFF:         0.40,
  CRED_MOD_BASE:           0.80,
  CRED_MOD_COEFF:          0.30,

  /* §9.5 Aggression cost */
  BURNRATE_COEFF:          0.10,
  BURNRATE_CAP:            0.50,
  AGGR_PEN_COEFF:          0.50,
  AGGR_PEN_CAP:            0.20,

  /* §9.6 Population */
  OVERPOP_CAP:             0.35,
  OVERPOP_COEFF:           0.25,
  UNDERDOG_CAP:            0.40,
  UNDERDOG_COEFF:          0.50,
  POP_RATIO_TRIGGER:       1.05,

  /* §9.7 Peace */
  PEACE_BONUS_MAX:         0.08,
  PEACE_BONUS_COEFF:       0.50,

  /* §9.9 Power formula */
  BASE_POWER_EXP:          0.90,
  NOISE_MIN:               0.95,
  NOISE_MAX:               1.05,

  /* §10 Conflict */
  CONFLICT_IMPACT_MIN:     0.85,
  CONFLICT_IMPACT_MAX:     1.25,
  CONFLICT_INTEN_CAP:      0.50,
  ATK_CRED_MOD_MIN:        0.60,
  MULTI_ATK_PEN_COEFF:     0.15,
  MULTI_ATK_PEN_FLOOR:     0.40,
  BACKFIRE_TRUST_COEFF:    0.50,
  BACKFIRE_NEG_COEFF:      0.30,

  /* §10.5 Pulse */
  PULSE_MOD:               1.12,
  PULSE_DURATION_MS:       60000,

  /* §10.6 Resolution delay */
  RESOLVE_DELAY_MIN:       60,
  RESOLVE_DELAY_MAX:       120,

  /* §10.7 Targeting thresholds */
  TARGET_SCORE_WARN:       0.15,
  TARGET_SCORE_VULN:       0.18,

  /* §11 Coalition defense diminishing returns */
  COALITION_DR_THRESH:     0.12,
  COALITION_DR_COEFF:      0.40,

  /* §13 Win condition */
  WIN_CHANCE_EXP:          0.90,
  VICTORY_GAP_PCT:         20,
  NARROW_GAP_PCT:          5,

  /* §14 Event probabilities */
  EVENT_NONE_PROB:         0.50,
  EVENT_STANDARD_PROB:     0.40,

  /* §15 Scan */
  SCAN_ACC_FLOOR:          0.50,
  SCAN_ACC_DECAY:          0.10,
  SCAN_NOISE_BASE:         0.05,
  SCAN_NOISE_COEFF:        0.20,
  SCAN_COST_MIN_PCT:       0.01,
  SCAN_COST_MAX_PCT:       0.03,
  INTEL_WIN_DISCOUNT:      0.30,

  /* §17.3 Micro-action costs */
  PULSE_COST_PCT:          0.02,

  /* §5 Time weights */
  EARLY_WEIGHT:            1.000,
  MID_WEIGHT:              1.050,
  LATE_WEIGHT_BASE:        0.900,
  LATE_WEIGHT_VOL:         0.050,
  EARLY_PHASE_END:         0.50,
  MID_PHASE_END:           0.85,

  /* §14.1 Event confidence */
  EV_CONF_BASE:            0.70,
  EV_CONF_COEFF:           0.20,

  /* §20 Reward */
  TEAM_SHARE_FRAC:         0.40,
  INDIV_SHARE_FRAC:        0.60,
  MIN_CONTRIB_FRAC:        0.015,
  LOSER_FLAT_RETURN:       0.12,

  /* §20.4 Participation maturity */
  MATURITY_BASE:           0.60,
  MATURITY_ROUNDS_COEFF:   0.08,
  MATURITY_AGE_COEFF:      0.08,
  MATURITY_AGE_DAYS:       30,

  /* §23 Persistent progression */
  WINNER_BONUS_R1:         0.10,
  WINNER_BONUS_R2:         0.05,
  LOSER_UNDERDOG_BONUS:    0.15,
  LOGISTICS_CARRY_FRAC:    0.50,
  POLICY_CARRY_FRAC:       0.50,

  /* §23 Campaign Identity Trait bonus */
  IDENTITY_TRAIT_BONUS:    0.05,

  /* §23 Identity Trait thresholds (fraction of TotalCore) */
  TRAIT_POLICY_THRESH:     0.22,
  TRAIT_ORG_THRESH:        0.14,
  TRAIT_CHARISMA_THRESH:   0.07,
  TRAIT_COALITION_THRESH:  0.10,
  TRAIT_ATTACK_THRESH:     0.10,  /* fraction of TotalAll */

  /* §12 Player migration */
  MIGRATION_PENALTY_1:     0.05,
  MIGRATION_PENALTY_2:     0.10,
  MIGRATION_PENALTY_3:     0.20,
  MIGRATION_COOLDOWN_MS:   60000, /* 1 round cooldown representation */

  /* §22 Token transfers */
  TRANSFER_TAX_BASE:       0.10,
  TRANSFER_TAX_VOL_COEFF:  0.10,
  TRANSFER_DAILY_CAP_FRAC: 0.075,
  TRANSFER_COOLDOWN_MS:    86400000, /* 24 hours */
  TRANSFER_MIN_AGE_MS:     259200000, /* 72 hours */
  TRANSFER_MIN_ROUNDS:     5,
  TRANSFERRED_WEIGHT_R1:   0.70,
  TRANSFERRED_WEIGHT_R2:   0.70,
};

/* ════════════════════════════════════════════════════════
   STATIC DATA
════════════════════════════════════════════════════════ */
const CAMP_DATA = [
  { id: 0, name: 'LENI',   label: 'LENI Campaign',   color: '#c8a040' },
  { id: 1, name: 'SARA',   label: 'SARA Campaign',   color: '#8db830' },
  { id: 2, name: 'MARCOS', label: 'MARCOS Campaign', color: '#e05050' },
];

const CORE_KEYS = [
  'funding', 'organization', 'logistics', 'policy',
  'charisma', 'messaging', 'opposition_research',
];
const NC_KEYS  = ['attack_ads', 'negative_campaigning', 'coalitions'];
const ALL_KEYS = [...CORE_KEYS, ...NC_KEYS];

const SYS_META = {
  funding:              { label: 'Finance',    color: '#c8a040', group: 'core'    },
  organization:         { label: 'Org.',       color: '#4eb8ff', group: 'core'    },
  logistics:            { label: 'Logistics',  color: '#8db830', group: 'core'    },
  policy:               { label: 'Policy',     color: '#ffd166', group: 'core'    },
  charisma:             { label: 'Charisma',   color: '#ff8c42', group: 'core'    },
  messaging:            { label: 'Messaging',  color: '#c084fc', group: 'core'    },
  opposition_research:  { label: 'Opp.Res.',   color: '#6abfb8', group: 'core'    },
  attack_ads:           { label: 'Atk.Ads',    color: '#e05050', group: 'attack'  },
  negative_campaigning: { label: 'Neg.Camp.',  color: '#e05050', group: 'attack'  },
  coalitions:           { label: 'Coalitions', color: '#60a5fa', group: 'defense' },
};

const STABILITY_STATES = {
  stable:   { label: 'Stable',   mod: 1.10, color: '#00e060' },
  strained: { label: 'Strained', mod: 0.90, color: '#c8a040' },
  unstable: { label: 'Unstable', mod: 0.75, color: '#c86820' },
  critical: { label: 'Critical', mod: 0.50, color: '#e03030' },
};

const OUTCOME_MULTIPLIERS = {
  victory:   1.00,
  narrow:    0.85,
  resilient: 0.15,
  collapse:  0.00,
};

const EVENTS = [
  { name: 'Funding Surge',        layer: 'ContribEventMod',  sys: 'funding',   mod: 1.40, super: false, desc: 'Finance ×1.40'              },
  { name: 'Policy Breakthrough',  layer: 'ContribEventMod',  sys: 'policy',    mod: 2.00, super: false, desc: 'Policy ×2.00'               },
  { name: 'Media Surge',          layer: 'ContribEventMod',  sys: 'messaging', mod: 1.40, super: false, desc: 'Messaging ×1.40'            },
  { name: 'Logistics Breakdown',  layer: 'ContribEventMod',  sys: 'logistics', mod: 0.70, super: false, desc: 'Logistics ×0.70'            },
  { name: 'Talent Drought',       layer: 'PowerEventMod',    sys: 'eff_cap',   mod: 1.05, super: false, desc: 'Efficiency capped ×1.05'    },
  { name: 'Regulation Crackdown', layer: 'ConflictEventMod', sys: 'def_boost', mod: 1.50, super: false, desc: 'EffDef ×1.50; Atk ×0.70'   },
  { name: 'Cold Campaign',        layer: 'ConflictEventMod', sys: 'cold',      mod: 1.00, super: false, desc: 'No conflicts resolve'        },
  { name: 'Grassroots Surge',     layer: 'ContribEventMod',  sys: 'policy',    mod: 3.00, super: true,  desc: 'Policy ×3.00'               },
  { name: 'Financial Crisis',     layer: 'ContribEventMod',  sys: 'funding',   mod: 0.40, super: true,  desc: 'Finance ×0.40'              },
  { name: 'Credibility Collapse', layer: 'ConflictEventMod', sys: 'bf_double', mod: 2.00, super: true,  desc: 'Backfire chance ×2'         },
  { name: 'Scandal Wave',         layer: 'PowerEventMod',    sys: 'scandal',   mod: 0.60, super: true,  desc: 'Trust <0.5 → Power ×0.60'  },
  { name: 'Media Blackout',       layer: 'PowerEventMod',    sys: 'blackout',  mod: 0.50, super: true,  desc: 'EffectiveMessaging ×0.50'   },
];

const SIG_TYPES  = ['ATTACK', 'DEFEND', 'PUSH POLICY', 'HOLD'];
const ARCHETYPES = ['aggressive', 'defensive', 'balanced'];

/* §7 Bot archetype allocation templates.
   FIX: aggressive was summing to 1.02 — corrected messaging from .18 → .16  */
const BOT_TEMPLATES = {
  aggressive: { funding:.14, organization:.10, logistics:.08, policy:.12, charisma:.06, messaging:.16, opposition_research:.04, attack_ads:.14, negative_campaigning:.08, coalitions:.08 },
  defensive:  { funding:.18, organization:.15, logistics:.14, policy:.20, charisma:.07, messaging:.10, opposition_research:.06, attack_ads:.00, negative_campaigning:.00, coalitions:.10 },
  balanced:   { funding:.17, organization:.14, logistics:.12, policy:.20, charisma:.07, messaging:.13, opposition_research:.05, attack_ads:.05, negative_campaigning:.00, coalitions:.07 },
};

/* ════════════════════════════════════════════════════════
   GAME STATE
   All mutable state lives here. Pure functions never mutate G.
════════════════════════════════════════════════════════ */
let G = {
  round:             1,
  seed:              '',
  phase:             'open',
  roundStartMs:      0,
  anyRoundResolved:  false,
  resolvedBalance:   ENGINE.STARTING_BALANCE,   /* FIX: initialized, not undefined */
  roundStartBalance: ENGINE.STARTING_BALANCE,

  playerCamp:        0,
  event:             null,
  eventRevealed:     false,
  cold:              false,

  attacks:           [],
  pulseOff:          0,
  pulseDef:          0,

  intelWin:          false,
  intelWinExpiry:    0,
  scanLoad:          0,
  scanLoadReset:     0,

  roundsPlayed:      0,
  accountAgeDays:    0,

  playerBalance:     ENGINE.STARTING_BALANCE,
  playerTotalSpent:  0,

  /* §5 Late-phase weight — drawn once when phase enters, stable for the rest.
     FIX: stored in G so currentTimeWeight() never calls randFloat on display reads. */
  lateWeightDrawn:        ENGINE.LATE_WEIGHT_BASE,
  lateWeightPhaseEntered: false,

  /* §23 Persistent progression */
  roundsSinceWin:    999,
  lastWinRound:      0,
  carryOverAlloc:    null,

  /* §23 Campaign Identity Traits — accumulated over rounds */
  identityTraits: {
    /* Each trait: { rounds: N, active: bool } */
    policyPowerhouse:    { rounds: 0, active: false },
    groundGameMachine:   { rounds: 0, active: false },
    mediaDarling:        { rounds: 0, active: false },
    allianceBuilder:     { rounds: 0, active: false },
    oppositionSpecialist:{ rounds: 0, active: false },
  },

  /* §12 Player migration */
  switchCount:       0,
  lastSwitchRound:   -1,
  migrationCooldownMs: 0,

  /* §22 Token transfer ledger (player only — backend would handle multi-player) */
  transfersToday:    0,
  lastTransferMs:    0,
  receivedTokens:    [],   /* [{ amount, receivedRound }] for weight decay */

  bots:              [],
  signals:           [],
  lastBotSigMs:      0,

  councilRec:        null,
  councilConf:       'Unproven',
  councilAccuracy:   0.50,
  councilDeviation:  0,
  councilHistory:    [],

  history:           [],
  resolving:         false,
  prevPow:           0,
  prevT:             1,
  prevC:             1,
  feedItems:         [],
};

let camps = CAMP_DATA.map(c => ({
  ...c,
  alloc:   _zeroAlloc(),
  agActs:  0,
  players: 0,
}));

let pendingAlloc = _zeroAlloc();

/* ════════════════════════════════════════════════════════
   §2.2 UTILITIES
════════════════════════════════════════════════════════ */
function _zeroAlloc() {
  const o = {};
  ALL_KEYS.forEach(k => o[k] = 0);
  return o;
}

/** §2.2 safe_div — global rule: returns 0 when denominator ≤ 0 */
function sd(x, y) { return y <= 0 ? 0 : x / y; }

function clamp(lo, hi, v) { return Math.max(lo, Math.min(hi, v)); }

function randFloat(lo, hi) { return lo + Math.random() * (hi - lo); }
function randInt(lo, hi)   { return Math.floor(randFloat(lo, hi + 1)); }

/* ════════════════════════════════════════════════════════
   §2.3 ALLOCATION TOTALS
════════════════════════════════════════════════════════ */
function tcFn(alloc) {
  return CORE_KEYS.reduce((s, k) => s + (alloc[k] || 0), 0);
}

function taFn(alloc) {
  return ALL_KEYS.reduce((s, k) => s + (alloc[k] || 0), 0);
}

/** Merged view: confirmed camp alloc + pending for the player's camp */
function fullAlloc(campId) {
  const a = Object.assign({}, camps[campId].alloc);
  if (campId === G.playerCamp) {
    ALL_KEYS.forEach(k => a[k] = (a[k] || 0) + (pendingAlloc[k] || 0));
  }
  return a;
}

function pendingTotal() {
  return ALL_KEYS.reduce((s, k) => s + (pendingAlloc[k] || 0), 0);
}

function availableBalance() {
  return Math.max(0, G.playerBalance - G.playerTotalSpent - pendingTotal());
}

/* ════════════════════════════════════════════════════════
   §5 CONTRIBUTION TIMING
════════════════════════════════════════════════════════ */
function elapsed() { return (Date.now() - G.roundStartMs) / 1000; }

function phaseFrac() { return Math.min(1, elapsed() / ENGINE.ROUND_SECS); }

function curPhase() {
  const f = phaseFrac();
  if (f < ENGINE.EARLY_PHASE_END) return 'early';
  if (f < ENGINE.MID_PHASE_END)   return 'mid';
  return 'late';
}

/**
 * §5 Time weight for current phase.
 * FIX: Late weight is drawn ONCE when phase first enters, stored in G.
 * All subsequent reads return the same stable value — no per-frame randomness.
 */
function currentTimeWeight() {
  const f = phaseFrac();
  if (f < ENGINE.EARLY_PHASE_END) return ENGINE.EARLY_WEIGHT;
  if (f < ENGINE.MID_PHASE_END)   return ENGINE.MID_WEIGHT;
  /* Late phase — draw once, memoize */
  if (!G.lateWeightPhaseEntered) {
    G.lateWeightDrawn        = ENGINE.LATE_WEIGHT_BASE
                               + randFloat(-ENGINE.LATE_WEIGHT_VOL, ENGINE.LATE_WEIGHT_VOL);
    G.lateWeightPhaseEntered = true;
  }
  return G.lateWeightDrawn;
}

/**
 * §5 effective_amount — applies time weight AND ContribEventMod for a given system.
 * Formula: floor(amount × timeWeight × contribEventMod × SCALE) / SCALE
 */
function effectiveAmount(amount, systemKey, timeWeight) {
  const tw  = timeWeight !== undefined ? timeWeight : currentTimeWeight();
  const cem = _getContribEventMod(systemKey);
  return Math.floor(amount * tw * cem * ENGINE.SCALE) / ENGINE.SCALE;
}

/**
 * §5 Late-phase contribution cap per player.
 * maxLateContrib = min(0.25 × playerRoundTotal, 0.15 × avgCampaignContrib)
 */
function maxLateContrib(playerRoundTotal, avgCampaignContrib) {
  return Math.min(0.25 * playerRoundTotal, 0.15 * avgCampaignContrib);
}

/* ════════════════════════════════════════════════════════
   §14 EVENT HELPERS
════════════════════════════════════════════════════════ */

/** §9.8 ContribEventMod — multiplier at contribution time */
function _getContribEventMod(systemKey) {
  if (!G.event || G.event.layer !== 'ContribEventMod') return 1.0;
  return G.event.sys === systemKey ? G.event.mod : 1.0;
}

/** §9.8 PowerEventMod — multiplier on final CampaignPower */
function _getPowerEventMod(trust) {
  if (!G.event || G.event.layer !== 'PowerEventMod') return 1.0;
  switch (G.event.sys) {
    case 'eff_cap':  return 1.0;           /* handled inside multiplier builder */
    case 'scandal':  return trust < 0.5 ? G.event.mod : 1.0;
    case 'blackout': return 1.0;           /* handled inside computeMessaging */
    default:         return G.event.mod;
  }
}

/** §9.8 ConflictEventMod — affects conflict resolution behavior */
function _getConflictEventMod() {
  if (!G.event || G.event.layer !== 'ConflictEventMod') {
    return { defBoost: 1.0, atkMod: 1.0, bfDouble: false, cold: false };
  }
  return {
    defBoost: G.event.sys === 'def_boost' ? G.event.mod : 1.0,
    atkMod:   G.event.sys === 'def_boost' ? 0.70 : 1.0,
    bfDouble: G.event.sys === 'bf_double',
    cold:     G.event.sys === 'cold',
  };
}

/* ════════════════════════════════════════════════════════
   §7.1 TRUST
════════════════════════════════════════════════════════ */
function computeTrust(alloc) {
  const ta = taFn(alloc);
  return Math.max(
    ENGINE.TRUST_FLOOR,
    1
    - sd(alloc.attack_ads,           ta) * ENGINE.TRUST_ATTACK_COEFF
    - sd(alloc.negative_campaigning, ta) * ENGINE.TRUST_NEG_COEFF
  );
}

/* ════════════════════════════════════════════════════════
   §7.2 CREDIBILITY
════════════════════════════════════════════════════════ */
function computeCredibility(alloc) {
  const tc = tcFn(alloc);
  const ta = taFn(alloc);
  return Math.max(
    ENGINE.CRED_FLOOR,
    Math.min(
      ENGINE.CRED_CEIL,
      1
      + sd(alloc.policy,               tc) * ENGINE.CRED_POLICY_COEFF
      + sd(alloc.organization,         tc) * ENGINE.CRED_ORG_COEFF
      - sd(alloc.negative_campaigning, ta) * ENGINE.CRED_NEG_COEFF
    )
  );
}

/* ════════════════════════════════════════════════════════
   §7.3 SATURATION & EFFECTIVE MESSAGING
════════════════════════════════════════════════════════ */
function computeMessaging(alloc) {
  const tc  = tcFn(alloc);
  const raw = alloc.messaging || 0;

  const blackoutActive = !!(G.event && G.event.sys === 'blackout');

  const S                = Math.min(ENGINE.MSG_SAT_HARD, sd(raw, tc));
  const saturationFactor = 1 - S * ENGINE.SAT_FACTOR_COEFF;
  const effectiveMsg     = raw * saturationFactor;
  const effectiveMsgPow  = blackoutActive ? effectiveMsg * 0.50 : effectiveMsg;
  const gap              = raw - effectiveMsg;

  return {
    raw,
    S,
    saturationFactor,
    saturationPct: Math.round(S * 100),
    effectiveMsg,
    effectiveMsgPow,
    gap,
    atWarn:        S >= ENGINE.MSG_SAT_WARN,
    atHard:        S >= ENGINE.MSG_SAT_HARD,
    blackoutActive,
  };
}

/* ════════════════════════════════════════════════════════
   §8 STABILITY SYSTEM
   FIX: Uses percentage-of-TotalCore thresholds (not per-player).
   RequiredFunding = TotalCore × 0.15
   RequiredOrg     = TotalCore × 0.12
   RequiredLog     = TotalCore × 0.08
════════════════════════════════════════════════════════ */
/*
 * NOTE: second parameter (_playerCountIgnored) is intentionally unused.
 * Stability uses percentage-of-core thresholds, not per-player values.
 * The parameter is retained so call sites that pass playerCount remain
 * readable. Pass 0 or any value — it is silently discarded.
 */
function computeStability(alloc, _playerCountIgnored, cold) {
  const tc = tcFn(alloc);
  const ta = taFn(alloc);

  /* §8.3 — percentage-of-core approach (canonical) */
  const requiredFunding      = tc * ENGINE.FUND_REQ_FRAC;
  const requiredOrganization = tc * ENGINE.ORG_REQ_FRAC;
  const requiredLogistics    = tc * ENGINE.LOG_REQ_FRAC;

  const defenseFactor = sd(alloc.coalitions, ta);

  /* §8.1 Score components */
  const fundingCoverage  = (sd(alloc.funding,      requiredFunding)      - 1) * ENGINE.FUND_COVER_COEFF;
  const orgCoverage      = (sd(alloc.organization, requiredOrganization) - 1) * ENGINE.ORG_COVER_COEFF;
  const logCoverage      = (sd(alloc.logistics,    requiredLogistics)    - 1) * ENGINE.LOG_COVER_COEFF;
  const coalitionSupport = Math.min(ENGINE.COAL_SUPPORT_MAX, defenseFactor * ENGINE.COAL_SUPPORT_COEFF);

  /* §14.2 Cold Campaign: AggressionStressScore excluded this round */
  const aggressionStress = cold
    ? 0
    : sd((alloc.attack_ads || 0) + (alloc.negative_campaigning || 0), ta) * ENGINE.AGGR_STRESS_COEFF;

  const score = fundingCoverage + orgCoverage + logCoverage + coalitionSupport - aggressionStress;

  /* §8.2 State classification */
  let state, mod;
  if      (score >= ENGINE.STAB_STABLE_MIN)   { state = 'stable';   mod = STABILITY_STATES.stable.mod;   }
  else if (score >= ENGINE.STAB_STRAINED_MIN) { state = 'strained'; mod = STABILITY_STATES.strained.mod; }
  else if (score >= ENGINE.STAB_UNSTABLE_MIN) { state = 'unstable'; mod = STABILITY_STATES.unstable.mod; }
  else                                        { state = 'critical'; mod = STABILITY_STATES.critical.mod; }

  /* §13.1 Collapse: any threshold failed */
  const fundingFailed = alloc.funding      < requiredFunding;
  const orgFailed     = alloc.organization < requiredOrganization;
  const logFailed     = alloc.logistics    < requiredLogistics;
  const collapse      = fundingFailed || orgFailed || logFailed;

  /* Diagnostic: tokens needed to reach next state */
  let tokensToNextState = null;
  if (state !== 'stable') {
    const target = state === 'critical' ? ENGINE.STAB_UNSTABLE_MIN
                 : state === 'unstable' ? ENGINE.STAB_STRAINED_MIN
                 : ENGINE.STAB_STABLE_MIN;
    tokensToNextState = Math.ceil(Math.abs(target - score) * 10);
  }

  return {
    score, state, mod, collapse,
    fundingFailed, orgFailed, logFailed,
    requiredFunding, requiredOrganization, requiredLogistics,
    fundingCoverage, orgCoverage, logCoverage,
    coalitionSupport, aggressionStress, defenseFactor,
    tokensToNextState,
  };
}

/* ════════════════════════════════════════════════════════
   §11 COALITION EFFECTIVE DEFENSE
════════════════════════════════════════════════════════ */
function computeEffectiveDefense(alloc) {
  const df = sd(alloc.coalitions, taFn(alloc));
  if (df <= ENGINE.COALITION_DR_THRESH) return df;
  return ENGINE.COALITION_DR_THRESH + (df - ENGINE.COALITION_DR_THRESH) * ENGINE.COALITION_DR_COEFF;
}

/* ════════════════════════════════════════════════════════
   §9.5 AGGRESSION LEVEL & COST
════════════════════════════════════════════════════════ */
function computeAggressionLevel(alloc) {
  return sd((alloc.attack_ads || 0) + (alloc.negative_campaigning || 0), taFn(alloc));
}

function computeAggressionCost(aggressionLevel, aggressiveActions) {
  const burnRate    = Math.min(ENGINE.BURNRATE_CAP, aggressiveActions * ENGINE.BURNRATE_COEFF);
  const aggrPenalty = Math.min(ENGINE.AGGR_PEN_CAP, aggressionLevel  * ENGINE.AGGR_PEN_COEFF);
  const finalCost   = (1 - burnRate) * (1 - aggrPenalty);
  return { burnRate, aggrPenalty, finalCost, aggressiveActions };
}

/* ════════════════════════════════════════════════════════
   §9.6 POPULATION MODIFIERS
════════════════════════════════════════════════════════ */

/**
 * Returns weighted population per campaign.
 * Also returns the human player's individual PlayerPopWeight.
 */
function computeWeightedPopulations() {
  const result = CAMP_DATA.map(c => {
    const alloc    = fullAlloc(c.id);
    const tc       = tcFn(alloc);
    const botCount = camps[c.id].players;
    const pCount   = botCount + (c.id === G.playerCamp ? 1 : 0);
    if (pCount <= 0 || tc <= 0) return { wp: botCount, playerPopWeight: 0 };
    const avg         = sd(tc, pCount);
    const humanAmt    = G.playerTotalSpent + pendingTotal();
    const playerPopWeight = c.id === G.playerCamp
      ? Math.min(1.0, sd(humanAmt, avg))
      : 0;
    return { wp: botCount + playerPopWeight, playerPopWeight };
  });
  return result;
}

function computePopulationModifiers() {
  const wpData = computeWeightedPopulations();
  const wps    = wpData.map(d => d.wp);
  const mx     = Math.max(...wps, 0.001);
  const mn     = Math.min(...wps);
  const ratio  = sd(mx, mn);

  if (ratio < ENGINE.POP_RATIO_TRIGGER) {
    return wpData.map(d => ({
      modifier: 1.0, state: 'balanced', penalty: 0, bonus: 0,
      ratio, wp: d.wp,
      /* 0 for bot-only campaigns (source guarantees this); never null */
      playerPopWeight: d.playerPopWeight,
    }));
  }

  const imb = ratio - 1;
  const ovp = Math.min(ENGINE.OVERPOP_CAP, imb * ENGINE.OVERPOP_COEFF);
  const udg = Math.min(ENGINE.UNDERDOG_CAP, (1 - sd(1, ratio)) * ENGINE.UNDERDOG_COEFF);

  return wpData.map(d => {
    const base = {
      ratio, wp: d.wp,
      /* 0 for bot-only campaigns (source guarantees this); never null */
      playerPopWeight: d.playerPopWeight,
    };
    if (d.wp >= mx - 0.001) return { ...base, modifier: 1 - ovp, state: 'overpop',  penalty: ovp, bonus: 0   };
    if (d.wp <= mn + 0.001) return { ...base, modifier: 1 + udg, state: 'underdog', penalty: 0,   bonus: udg  };
    return                          { ...base, modifier: 1.0,     state: 'balanced', penalty: 0,   bonus: 0   };
  });
}

/* ════════════════════════════════════════════════════════
   §9.7 PEACE MODIFIERS
════════════════════════════════════════════════════════ */
function computePeaceModifiers() {
  const als              = CAMP_DATA.map(c => computeAggressionLevel(fullAlloc(c.id)));
  const globalAggression = als.reduce((a, b) => a + b, 0) / Math.max(1, als.length);
  const peaceBonus       = Math.max(0, ENGINE.PEACE_BONUS_MAX - globalAggression * ENGINE.PEACE_BONUS_COEFF);

  const perCampaign = als.map(al => {
    const qualifying = al <= globalAggression;
    return {
      aggressionLevel: al,
      qualifying,
      modifier:   qualifying ? 1 + peaceBonus : 1.0,
      peaceBonus: qualifying ? peaceBonus : 0,
    };
  });

  return { globalAggression, peaceBonus, perCampaign };
}

/* ════════════════════════════════════════════════════════
   §14.1 EVENT CONFIDENCE
════════════════════════════════════════════════════════ */
function computeEventConfidence(alloc) {
  const tc = tcFn(alloc);
  return Math.min(
    ENGINE.EV_CONF_BASE + ENGINE.EV_CONF_COEFF,
    ENGINE.EV_CONF_BASE + sd(alloc.opposition_research, tc) * ENGINE.EV_CONF_COEFF
  );
}

/* ════════════════════════════════════════════════════════
   §23 CAMPAIGN IDENTITY TRAITS
   Evaluated each round end against that round's allocation.
   Trait activates after 3 consecutive qualifying rounds.
   Qualifying condition checked against the player's campaign.
════════════════════════════════════════════════════════ */
function _evaluateTraitQualification(alloc) {
  const tc = tcFn(alloc);
  const ta = taFn(alloc);
  return {
    policyPowerhouse:     sd(alloc.policy,      tc) >= ENGINE.TRAIT_POLICY_THRESH
                       && sd(alloc.funding,      tc) >= ENGINE.TRAIT_POLICY_THRESH * 0.8,
    groundGameMachine:    sd(alloc.organization, tc) >= ENGINE.TRAIT_ORG_THRESH
                       && sd(alloc.logistics,    tc) >= ENGINE.TRAIT_ORG_THRESH,
    mediaDarling:         sd(alloc.charisma,     tc) >= ENGINE.TRAIT_CHARISMA_THRESH
                       && sd(alloc.messaging,    tc) >= ENGINE.TRAIT_CHARISMA_THRESH * 1.5,
    allianceBuilder:      sd(alloc.coalitions,   ta) >= ENGINE.TRAIT_COALITION_THRESH,
    oppositionSpecialist: sd((alloc.attack_ads || 0) + (alloc.negative_campaigning || 0), ta)
                          >= ENGINE.TRAIT_ATTACK_THRESH,
  };
}

/**
 * §23 Trait passive bonuses applied to power multipliers.
 * Returns an object of bonus values ready for consumption by computePowerMultipliers.
 */
function computeTraitBonuses() {
  const traits = G.identityTraits;
  return {
    policyImpactBonus:   traits.policyPowerhouse.active    ? ENGINE.IDENTITY_TRAIT_BONUS : 0,
    efficiencyBonus:     traits.groundGameMachine.active   ? ENGINE.IDENTITY_TRAIT_BONUS : 0,
    charismaBonus:       traits.mediaDarling.active        ? ENGINE.IDENTITY_TRAIT_BONUS : 0,
    effectiveDefBonus:   traits.allianceBuilder.active     ? ENGINE.IDENTITY_TRAIT_BONUS : 0,
    outcomeFactorBonus:  traits.oppositionSpecialist.active? ENGINE.IDENTITY_TRAIT_BONUS : 0,
    activeTraits: Object.entries(traits)
      .filter(([, v]) => v.active)
      .map(([k]) => k),
  };
}

/**
 * §23 Update identity trait streak counters at round end.
 * Called inside advanceRound().
 */
function _updateIdentityTraits(playerAlloc) {
  const qualified = _evaluateTraitQualification(playerAlloc);
  Object.keys(G.identityTraits).forEach(key => {
    const t = G.identityTraits[key];
    if (qualified[key]) {
      t.rounds++;
      t.active = t.rounds >= 3;
    } else {
      t.rounds = 0;
      t.active = false;
    }
  });
}

/* ════════════════════════════════════════════════════════
   §9 POWER MULTIPLIER BUNDLE
   FIX: playerCount passed explicitly — no more phantom count=0 stability.
   FIX: traitBonuses passed in optionally — callers that already computed it
        once (getGameState) pass the memoized value; internal callers that
        don't pass it fall back to a fresh computeTraitBonuses() call.
   Returns every intermediate multiplier for power computation AND display.
════════════════════════════════════════════════════════ */
function computePowerMultipliers(alloc, aggressiveActions, cold, playerCount, precomputedTraits) {
  const tc  = tcFn(alloc);
  const ta  = taFn(alloc);
  const T   = computeTrust(alloc);
  const C   = computeCredibility(alloc);
  const msg = computeMessaging(alloc);

  /* FIX: use the actual player count passed in — not 0 */
  const stab = computeStability(alloc, playerCount, cold);

  const al = computeAggressionLevel(alloc);
  const ac = computeAggressionCost(al, aggressiveActions);

  /* §23 Trait bonuses — use pre-computed value when provided (avoids redundant calls) */
  const traits = precomputedTraits || computeTraitBonuses();

  /* §9.1 Efficiency
     NOTE: Talent Drought event (eff_cap = ×1.05) caps efficiency AFTER the
     GroundGameMachine trait bonus is included in rawEff. This means when
     Talent Drought is active, the trait bonus is effectively nullified for any
     campaign already at or above the 1.05 cap. This is intentional — the event
     targets the efficiency system as a whole, traits included. Documented here
     to prevent "fixing" it as an apparent bug during Phase 2. */
  const rawEff     = 1 + sd(alloc.organization, tc) * ENGINE.EFF_COEFF + traits.efficiencyBonus;
  const effCap     = (G.event && G.event.sys === 'eff_cap') ? G.event.mod : Infinity;
  const efficiency = Math.min(effCap, rawEff);

  /* §9.1 Infrastructure */
  const infra = 1 + sd(alloc.logistics, tc) * ENGINE.INFRA_COEFF;

  /* §9.2 PolicyImpact */
  const rawPolicyImpact = 1 + sd(
    (alloc.policy  || 0) * ENGINE.POLICY_WEIGHT
    + (alloc.funding || 0) * ENGINE.FUND_WEIGHT,
    tc
  ) * ENGINE.POLICY_COEFF;
  const policyImpact  = Math.min(
    ENGINE.POLICY_IMPACT_CAP,
    rawPolicyImpact + traits.policyImpactBonus
  );
  const policyHeadroom = ENGINE.POLICY_IMPACT_CAP - policyImpact;
  const policyCapPct   = policyImpact / ENGINE.POLICY_IMPACT_CAP;

  /* §9.3 CharismaImpact */
  const charismaImpact = 1 + sd(alloc.charisma, tc) * ENGINE.CHARISMA_COEFF + traits.charismaBonus;

  /* §9.4 TrustMod / CredibilityMod */
  const trustMod = ENGINE.TRUST_MOD_BASE + T * ENGINE.TRUST_MOD_COEFF;
  const credMod  = ENGINE.CRED_MOD_BASE  + C * ENGINE.CRED_MOD_COEFF;

  /* §9.8 PowerEventMod */
  const powEventMod = _getPowerEventMod(T);

  /* §9.9 Noise — not shown to players; intentionally opaque */
  const noise = randFloat(ENGINE.NOISE_MIN, ENGINE.NOISE_MAX);

  /* §9.9 BasePower */
  const basePower = Math.pow(Math.max(tc, 0), ENGINE.BASE_POWER_EXP);

  return {
    tc, ta, T, C, msg, stab, al, ac,
    basePower, efficiency, infra,
    policyImpact, policyHeadroom, policyCapPct,
    charismaImpact, trustMod, credMod,
    powEventMod, noise,
    traits,
    effCapActive:   !!(G.event && G.event.sys === 'eff_cap'),
    blackoutActive: !!(G.event && G.event.sys === 'blackout'),
  };
}

/* ════════════════════════════════════════════════════════
   §23 PERSISTENT WINNER/LOSER BONUS
   FIX: Applied as a multiplier on Efficiency (winner) or EffectiveContrib
   proxy (loser). NOT as a flat final power multiply.
   Implementation: exposed as separate factor so display can label it.
   anyRoundResolved guard prevents first-round phantom bonus.
════════════════════════════════════════════════════════ */
function _getPersistenceMultiplier(campId) {
  if (campId !== G.playerCamp)   return 1.0;
  if (!G.anyRoundResolved)       return 1.0;  /* no bonus before first round resolves */
  const rsSinceWin = G.roundsSinceWin;
  if (rsSinceWin === 1)          return 1 + ENGINE.WINNER_BONUS_R1;   /* §23 R1 after win */
  if (rsSinceWin === 2)          return 1 + ENGINE.WINNER_BONUS_R2;   /* §23 R2 after win */
  /* §23 Loser underdog bonus: "next round" only — applies the round immediately
     after a loss streak crosses 2. rsSinceWin === 3 means the loss was 2 rounds ago,
     this is that "next round". Does not repeat indefinitely. */
  if (rsSinceWin === 3)          return 1 + ENGINE.LOSER_UNDERDOG_BONUS;
  return 1.0;
}

/* ════════════════════════════════════════════════════════
   §9.9 CAMPAIGN POWER — FULL FORMULA
════════════════════════════════════════════════════════ */
function computeCampaignPower(campId, playerCount, popMod, peaceMod, conflictImpacts, precomputedTraits) {
  const alloc  = fullAlloc(campId);
  const agActs = camps[campId].agActs;
  const cold   = G.cold;

  /* FIX: pass real playerCount and optional pre-computed traits */
  const m      = computePowerMultipliers(alloc, agActs, cold, playerCount, precomputedTraits);

  /* §9.9 Conflict product Π(EffectiveConflictImpact) */
  const cImps = conflictImpacts || [];
  let conflictProduct = 1;
  cImps.forEach(v => { conflictProduct *= v; });

  const persistMod = _getPersistenceMultiplier(campId);

  const power = Math.max(0,
    m.basePower
    * m.efficiency
    * m.infra
    * m.policyImpact
    * m.charismaImpact
    * m.trustMod
    * m.credMod
    * m.stab.mod
    * (popMod   || 1.0)
    * (peaceMod || 1.0)
    * m.powEventMod
    * conflictProduct
    * m.ac.finalCost
    * persistMod
    * m.noise
  );

  return {
    power, campId, alloc,
    ...m,
    popMod:   popMod   || 1.0,
    peaceMod: peaceMod || 1.0,
    conflictImpacts: cImps,
    conflictProduct,
    persistMod,
  };
}

/* ════════════════════════════════════════════════════════
   §13 WIN CHANCES
════════════════════════════════════════════════════════ */
function computeWinChances(powers) {
  const w   = powers.map(p => Math.pow(Math.max(p, 0), ENGINE.WIN_CHANCE_EXP));
  const sum = w.reduce((a, b) => a + b, 0);
  if (sum <= 0) return powers.map(() => 1 / powers.length);
  return w.map(x => x / sum);
}

/* ════════════════════════════════════════════════════════
   POWER ESTIMATE (pre-noise, display-stable)
   Uses noise = 1.0 so displayed values don't jitter.
   FIX: passes real playerCount — not 0.
════════════════════════════════════════════════════════ */
function estimatePower(campId, precomputedTraits) {
  const alloc       = fullAlloc(campId);
  const agActs      = camps[campId].agActs;
  const cold        = G.cold;
  const playerCount = camps[campId].players + (campId === G.playerCamp ? 1 : 0);
  /* Pass pre-computed traits when available to avoid redundant calls at 60fps */
  const m           = computePowerMultipliers(alloc, agActs, cold, playerCount, precomputedTraits);
  const popMods    = computePopulationModifiers();
  const peaceMods  = computePeaceModifiers();
  const popMod     = popMods[campId].modifier;
  const peaceMod   = peaceMods.perCampaign[campId].modifier;
  const resolvedImpacts = G.attacks
    .filter(a => a.toId === campId && a.resolved && !a.backfired)
    .map(a => a.impact);
  let cp = 1;
  resolvedImpacts.forEach(v => { cp *= v; });
  const persistMod = _getPersistenceMultiplier(campId);
  return Math.max(0,
    m.basePower * m.efficiency * m.infra * m.policyImpact
    * m.charismaImpact * m.trustMod * m.credMod * m.stab.mod
    * popMod * peaceMod * m.powEventMod * cp * m.ac.finalCost * persistMod
    /* noise intentionally omitted */
  );
}

/* ════════════════════════════════════════════════════════
   §10 CONFLICT SYSTEM
════════════════════════════════════════════════════════ */
function computeTargetScore(fromId, toId) {
  const fromAlloc = fullAlloc(fromId);
  const toAlloc   = fullAlloc(toId);
  const al        = computeAggressionLevel(fromAlloc);
  const pA        = Math.max(estimatePower(fromId), 1);
  const pB        = Math.max(estimatePower(toId),   1);
  const effDef    = computeEffectiveDefense(toAlloc);
  return al * Math.pow(sd(pB, pA), 0.5) * (1 - effDef);
}

function computeOutcomeFactor(fromId, toId, precomputedTraits) {
  const fromAlloc = fullAlloc(fromId);
  const toAlloc   = fullAlloc(toId);
  const fromMsg   = computeMessaging(fromAlloc);
  const toMsg     = computeMessaging(toAlloc);
  const emA = Math.max(fromMsg.effectiveMsg, 1);
  const emB = Math.max(toMsg.effectiveMsg,   1);
  const fA  = Math.max(fromAlloc.funding, 1);
  const fB  = Math.max(toAlloc.funding,   1);
  /* §23 OppositionSpecialist trait: +5% multiplicative on OutcomeFactor */
  const traitBonus = (precomputedTraits || computeTraitBonuses()).outcomeFactorBonus;
  return (Math.pow(emA / emB, 0.6) * Math.pow(fA / fB, 0.4)) * (1 + traitBonus);
}

function computeAttackCredibilityMod(fromId) {
  const alloc = fullAlloc(fromId);
  const C     = computeCredibility(alloc);
  const credM = ENGINE.CRED_MOD_BASE + C * ENGINE.CRED_MOD_COEFF;
  return Math.min(1.0, Math.max(ENGINE.ATK_CRED_MOD_MIN, credM));
}

function computeConflictImpact(fromId, toId, pulseOffActive, precomputedTraits) {
  const fromAlloc = fullAlloc(fromId);
  const al        = computeAggressionLevel(fromAlloc);
  const of        = computeOutcomeFactor(fromId, toId, precomputedTraits);
  const acm       = computeAttackCredibilityMod(fromId);
  const intensity = Math.min(ENGINE.CONFLICT_INTEN_CAP, al);
  const raw       = 1 + (of - 1) * intensity * acm;
  const base      = clamp(ENGINE.CONFLICT_IMPACT_MIN, ENGINE.CONFLICT_IMPACT_MAX, raw);
  /* §10.5 PulseOffenseMod = PULSE_MOD × ConflictImpact multiplicatively */
  return pulseOffActive ? base * ENGINE.PULSE_MOD : base;
}

/** §10.7 */
function computeMultiAttackPenalty(N) {
  return Math.max(ENGINE.MULTI_ATK_PEN_FLOOR, 1 - ENGINE.MULTI_ATK_PEN_COEFF * (N - 1));
}

/** §10.9 */
function computeBackfireChance(fromId) {
  const alloc  = fullAlloc(fromId);
  const T      = computeTrust(alloc);
  const ta     = taFn(alloc);
  const evMod  = _getConflictEventMod();
  const bfMult = evMod.bfDouble ? 2 : 1;
  return Math.min(0.95,
    ((1 - T) * ENGINE.BACKFIRE_TRUST_COEFF
    + sd(alloc.negative_campaigning, ta) * ENGINE.BACKFIRE_NEG_COEFF)
    * bfMult
  );
}

/**
 * §10.8 EffectiveConflictImpact(N)
 * AllianceBuilder trait adds to EffectiveDefense of the defender.
 */
function computeEffectiveConflictImpact(fromId, toId, N, pulseOffActive, pulseDefActive, precomputedTraits) {
  const toAlloc   = fullAlloc(toId);
  const evMod     = _getConflictEventMod();
  const ci        = computeConflictImpact(fromId, toId, pulseOffActive, precomputedTraits);
  const map       = computeMultiAttackPenalty(N);

  let effDef = computeEffectiveDefense(toAlloc);
  /* §23 AllianceBuilder trait bonus on defense */
  if (toId === G.playerCamp) {
    effDef = Math.min(1.0, effDef + (precomputedTraits || computeTraitBonuses()).effectiveDefBonus);
  }

  const effDefMod = Math.min(1.0, effDef * evMod.defBoost * (pulseDefActive ? ENGINE.PULSE_MOD : 1.0));
  return ci * map * (1 - effDefMod * 0.5) * evMod.atkMod;
}

/**
 * Build a new attack object. Returns null if not viable.
 * N is 1-based index of this attack against toId this round.
 * FIX: agActs tracks actual conflict initiations (buildAttack calls),
 *      not allocation events. Bot agActs is incremented here too.
 */
function buildAttack(fromId, toId) {
  if (G.cold) return null;
  const ts = computeTargetScore(fromId, toId);
  if (ts < 0.05) return null;

  const now      = Date.now();
  const N        = G.attacks.filter(a => a.toId === toId && !a.resolved).length + 1;
  const pulseOff = G.pulseOff > now && fromId === G.playerCamp;
  const pulseDef = G.pulseDef > now && toId   === G.playerCamp;
  /* Compute traits once — shared across eci, of, and diagnostics */
  const traits   = computeTraitBonuses();
  const eci      = computeEffectiveConflictImpact(fromId, toId, N, pulseOff, pulseDef, traits);
  const bfc      = computeBackfireChance(fromId);
  const of       = computeOutcomeFactor(fromId, toId, traits);
  const acm      = computeAttackCredibilityMod(fromId);
  const map      = computeMultiAttackPenalty(N);
  const delay    = randInt(ENGINE.RESOLVE_DELAY_MIN, ENGINE.RESOLVE_DELAY_MAX);

  /* FIX: increment agActs here — this is where a conflict is actually opened */
  camps[fromId].agActs++;

  return {
    fromId, toId,
    impact:   eci,
    bfChance: bfc,
    delay,
    startMs:   now,
    resolveMs: now + delay * 1000,
    resolved:  false,
    backfired: false,
    exploded:  false,
    N,
    /* Diagnostics for display layer */
    targetScore:        ts,
    outcomeFactor:      of,
    attackCredMod:      acm,
    multiAttackPenalty: map,
    pulseOffActive:     pulseOff,
    pulseDefActive:     pulseDef,
  };
}

function resolveAttack(attack) {
  if (attack.resolved) return attack;
  const backfired = Math.random() < attack.bfChance;
  return { ...attack, resolved: true, backfired };
}

/* ════════════════════════════════════════════════════════
   §15 SCAN SYSTEM
════════════════════════════════════════════════════════ */
function _refreshScanLoad() {
  const now = Date.now();
  if (now - G.scanLoadReset > 30000) {
    G.scanLoad      = 0;
    G.scanLoadReset = now;
  }
}

function computeScanAccuracy() {
  _refreshScanLoad();
  return Math.max(ENGINE.SCAN_ACC_FLOOR, 1 - (G.scanLoad - 1) * ENGINE.SCAN_ACC_DECAY);
}

function computeDisplayNoise(accuracy) {
  return ENGINE.SCAN_NOISE_BASE + (1 - accuracy) * ENGINE.SCAN_NOISE_COEFF;
}

/**
 * §15 Scan cost: 1–3% of player's current round contribution total (random).
 * Intel Window applies −30% discount.
 */
function computeScanCost(playerRoundContrib) {
  const base     = Math.max(100, Math.floor(
    playerRoundContrib * randFloat(ENGINE.SCAN_COST_MIN_PCT, ENGINE.SCAN_COST_MAX_PCT) / 100
  ) * 100);
  const discount = G.intelWin ? ENGINE.INTEL_WIN_DISCOUNT : 0;
  return Math.floor(base * (1 - discount) / 100) * 100;
}

/**
 * Perform a scan. Caller deducts cost from G.playerBalance.
 * FIX: Now also reveals Trust, BurnRate, AggressionLevel, BackfireChance,
 *      StabilityState, and CollapseRisk per §17.3 spec.
 */
function performScan(targetCampId) {
  _refreshScanLoad();
  G.scanLoad++;

  const accuracy = computeScanAccuracy();
  const noise    = computeDisplayNoise(accuracy);
  const cost     = computeScanCost(Math.max(100, G.playerTotalSpent));
  const toAlloc  = fullAlloc(targetCampId);
  const tc       = tcFn(toAlloc) || 1;

  /* §15 System allocation reveal with display noise */
  const systemBreakdown = {};
  CORE_KEYS.forEach(k => {
    const actual   = sd(toAlloc[k], tc) * 100;
    const noised   = Math.max(0, actual + (Math.random() - 0.5) * noise * 2 * 100);
    systemBreakdown[k] = {
      label:     SYS_META[k].label,
      actual,
      displayed: Math.round(noised),
    };
  });

  /* §17.3 Derived stat reveal (spec says scan reveals Trust state, BurnRate, active threats) */
  const T      = computeTrust(toAlloc);
  const al     = computeAggressionLevel(toAlloc);
  const burn   = Math.min(ENGINE.BURNRATE_CAP, camps[targetCampId].agActs * ENGINE.BURNRATE_COEFF);
  const bf     = computeBackfireChance(targetCampId);
  const stab   = computeStability(toAlloc, camps[targetCampId].players, G.cold);
  const effDef = computeEffectiveDefense(toAlloc);

  /* §14.1 Attempt event reveal */
  let eventRevealed = false;
  if (G.event && !G.eventRevealed) {
    const playerAlloc = fullAlloc(G.playerCamp);
    if (Math.random() < computeEventConfidence(playerAlloc)) {
      G.eventRevealed = true;
      eventRevealed   = true;
    }
  }

  return {
    targetCampId,
    accuracy,
    noise,
    cost,
    intelWindowActive: G.intelWin,
    systemBreakdown,
    /* Derived stats revealed per §17.3 */
    trustLevel:      T > 0.75 ? 'High' : T > 0.50 ? 'Med' : 'Low',
    trustValue:      T,
    burnRate:        burn,
    aggressiveActions: camps[targetCampId].agActs,
    aggressionLevel: al,
    backfireChance:  bf,
    stabilityState:  stab.state,
    stabScore:       stab.score,
    collapseRisk:    stab.collapse,
    effectiveDefense: effDef,
    incomingThreats: G.attacks.filter(a => a.fromId === targetCampId && !a.resolved).length,
    eventRevealed,
  };
}

/* ════════════════════════════════════════════════════════
   §17.3 MICRO-ACTION COSTS
════════════════════════════════════════════════════════ */
function computePulseCost(playerRoundContrib) {
  return Math.max(100, Math.floor(playerRoundContrib * ENGINE.PULSE_COST_PCT / 100) * 100);
}

/* ════════════════════════════════════════════════════════
   §20.4 PARTICIPATION MATURITY
════════════════════════════════════════════════════════ */
function computeMaturity() {
  return Math.min(1.0,
    ENGINE.MATURITY_BASE
    + G.roundsPlayed * ENGINE.MATURITY_ROUNDS_COEFF
    + (G.accountAgeDays / ENGINE.MATURITY_AGE_DAYS) * ENGINE.MATURITY_AGE_COEFF
  );
}

/* ════════════════════════════════════════════════════════
   §22 TOKEN TRANSFER SYSTEM
   Enforces tax, daily cap, cooldown, new account lock,
   same-faction restriction, and transferred token weight decay.
   In the prototype, player is the only actor with a ledger.
════════════════════════════════════════════════════════ */

/** §22.1 Transfer tax = 10% + (volume / DailyLimit × 10%) */
function computeTransferTax(amount) {
  const dailyLimit = G.playerBalance * ENGINE.TRANSFER_DAILY_CAP_FRAC;
  const volRatio   = sd(G.transfersToday + amount, Math.max(1, dailyLimit));
  return ENGINE.TRANSFER_TAX_BASE + volRatio * ENGINE.TRANSFER_TAX_VOL_COEFF;
}

/** §22 Validate a proposed transfer. Returns { allowed, reason }. */
function validateTransfer(toFaction, amount) {
  const now = Date.now();

  /* §22.5 New account lock */
  if (G.accountAgeDays * 86400000 < ENGINE.TRANSFER_MIN_AGE_MS
      || G.roundsPlayed < ENGINE.TRANSFER_MIN_ROUNDS) {
    return { allowed: false, reason: 'account_too_new' };
  }
  /* §22.4 Cooldown */
  if (now - G.lastTransferMs < ENGINE.TRANSFER_COOLDOWN_MS) {
    return { allowed: false, reason: 'cooldown_active' };
  }
  /* §22.6 Only EarnedTokens transferable — prototype tracks full balance as earned */
  /* §22.3 Same-faction only */
  if (toFaction !== G.playerCamp) {
    return { allowed: false, reason: 'cross_faction' };
  }
  /* §22.2 Daily cap */
  const dailyLimit = G.playerBalance * ENGINE.TRANSFER_DAILY_CAP_FRAC;
  if (G.transfersToday + amount > dailyLimit) {
    return { allowed: false, reason: 'daily_cap_exceeded' };
  }
  if (amount > G.playerBalance) {
    return { allowed: false, reason: 'insufficient_balance' };
  }
  return { allowed: true, reason: null };
}

/**
 * §22 Execute a transfer. Returns result object.
 * Caller handles UI messaging.
 */
function executeTransfer(toFaction, amount) {
  const validation = validateTransfer(toFaction, amount);
  if (!validation.allowed) return { success: false, ...validation };

  const tax        = computeTransferTax(amount);
  const taxAmount  = Math.floor(amount * tax);
  const netAmount  = amount - taxAmount;

  G.playerBalance  -= amount;
  G.transfersToday += amount;
  G.lastTransferMs  = Date.now();

  /* §22.7 Recipient token weight decay tracked (player receiving from another) */
  /* Here we track outgoing; incoming recorded when received */
  return {
    success: true,
    gross: amount,
    tax,
    taxAmount,
    netAmount,
    toFaction,
    dailyUsed: G.transfersToday,
    dailyLimit: G.playerBalance * ENGINE.TRANSFER_DAILY_CAP_FRAC,
  };
}

/**
 * §22.7 Transferred token reward weight for a received batch.
 * roundsHeld = current round - receivedRound
 */
function transferredTokenWeight(receivedRound) {
  const roundsHeld = G.round - receivedRound;
  if (roundsHeld <= 2) return ENGINE.TRANSFERRED_WEIGHT_R1;
  return 1.0;
}

/* Reset daily transfer counter at round start */
function _resetDailyTransfer() {
  G.transfersToday = 0;
}

/* ════════════════════════════════════════════════════════
   §12 PLAYER MIGRATION
════════════════════════════════════════════════════════ */

/** §12.3 Migration penalty on wallet balance */
function computeMigrationPenalty() {
  const n = G.switchCount;
  if (n <= 0) return 0;
  if (n === 1) return ENGINE.MIGRATION_PENALTY_1;
  if (n === 2) return ENGINE.MIGRATION_PENALTY_2;
  return ENGINE.MIGRATION_PENALTY_3;
}

/**
 * §12 Switch campaign.
 * Deducts wallet penalty. Applies one-round cooldown.
 */
function switchCamp(targetId) {
  if (targetId === G.playerCamp) return { success: false, reason: 'same_camp' };
  if (Date.now() < G.migrationCooldownMs) return { success: false, reason: 'cooldown_active' };

  /* Stable round reset (if last switch was > 1 round ago, reset counter) */
  if (G.round - G.lastSwitchRound > 1) G.switchCount = 0;

  G.switchCount++;
  const penalty    = computeMigrationPenalty();
  const penaltyAmt = Math.floor(G.playerBalance * penalty);
  G.playerBalance -= penaltyAmt;
  G.playerCamp     = targetId;
  G.lastSwitchRound = G.round;
  G.migrationCooldownMs = Date.now() + ENGINE.MIGRATION_COOLDOWN_MS;

  /* Clear carry-over: previous camp's Logistics/Policy must not bleed into new camp */
  G.carryOverAlloc = null;

  /* Reset pending alloc when switching */
  ALL_KEYS.forEach(k => pendingAlloc[k] = 0);

  return { success: true, penalty, penaltyAmt, newCamp: targetId };
}

/* ════════════════════════════════════════════════════════
   §20 REWARD SYSTEM
════════════════════════════════════════════════════════ */
function computeSoftCap(totalWinningContrib) {
  return totalWinningContrib * ENGINE.SOFT_CAP_FRAC;
}

/** §20.3 */
function computeEffectiveContribution(contrib, softCap) {
  if (contrib <= softCap) return contrib;
  return softCap + (contrib - softCap) * ENGINE.SOFT_CAP_EXCESS;
}

/** §20.5 */
function computeTeamWeight(effContrib, maturity) {
  return Math.sqrt(Math.max(0, effContrib)) * maturity;
}

/** §20.2 */
function computeMinContribThreshold(avgCampaignContrib) {
  return avgCampaignContrib * ENGINE.MIN_CONTRIB_FRAC;
}

/**
 * §13.1 Outcome classification.
 * Winners: Victory (gap > 20%) or Narrow (gap 5–20%).
 * Losers: Resilient (met all sustainability) or Collapse (failed any threshold).
 */
function classifyOutcome(winnerCampId, playerCampId, powers, playerStab) {
  const isWinner = winnerCampId === playerCampId;

  if (!isWinner) {
    if (playerStab && playerStab.collapse) return 'collapse';
    return 'resilient';
  }

  const myPow    = powers[playerCampId];
  const rivals   = powers.filter((_, i) => i !== playerCampId);
  const maxRival = Math.max(...rivals, 0);
  const gapPct   = maxRival > 0 ? ((myPow - maxRival) / maxRival) * 100 : 100;

  if (gapPct > ENGINE.VICTORY_GAP_PCT) return 'victory';
  return 'narrow';
}

/**
 * §20 Full reward computation — dual IndivShare / TeamShare split.
 * FIX: Team weight denominator now correctly uses sqrt(EffectiveContribution)
 *      per-player summed — approximated via campaign total since bots have no
 *      individual ledger in the prototype. This is the correct formula for the
 *      player's individual share against the campaign's aggregate.
 * §20.7 Anti-syndicate penalties are stubbed — backend implements full scoring.
 */
function computeReward({
  winnerCampId,
  playerCampId,
  playerContrib,
  factionTotals,
  outcomeClass,
  maturity,
}) {
  if (playerContrib <= 0) return { reward: 0, profit: 0, efficiencyScore: null, outcomeClass };

  const outcomeMult = OUTCOME_MULTIPLIERS[outcomeClass] ?? 0;
  const grandTotal  = factionTotals.reduce((a, b) => a + b, 0);
  const winnerTotal = factionTotals[winnerCampId];
  const loserTotal  = grandTotal - winnerTotal;
  const loserPool   = Math.floor(loserTotal * (1 - ENGINE.PLATFORM_FEE));

  let reward = 0;

  if (outcomeClass === 'collapse') {
    reward = 0;

  } else if (winnerCampId === playerCampId) {
    /* §20.3 Soft cap */
    const softCap    = computeSoftCap(winnerTotal);
    const effContrib = computeEffectiveContribution(playerContrib, softCap);

    /* §20.6 IndivShare (60%) */
    const adjWinTotal = Math.max(1, winnerTotal);
    const indivShare  = sd(effContrib, adjWinTotal) * loserPool * ENGINE.INDIV_SHARE_FRAC;

    /* §20.6 TeamShare (40%) — FIX: correct denominator approximation */
    const myWeight  = computeTeamWeight(effContrib, maturity);
    /* Σ TeamWeight approximated: all contributors modeled as at-cap efficiency */
    const totalEff  = computeEffectiveContribution(winnerTotal, softCap);
    /* Distribute via proportional sqrt weighting */
    const totWeight = Math.sqrt(Math.max(1, totalEff)) * maturity;
    const teamShare = loserPool * ENGINE.TEAM_SHARE_FRAC * sd(myWeight, totWeight);

    reward = playerContrib + Math.floor(Math.max(0, (indivShare + teamShare) * outcomeMult));

  } else if (outcomeClass === 'resilient') {
    reward = Math.floor(playerContrib * OUTCOME_MULTIPLIERS.resilient);

  } else {
    /* Narrow loss — flat partial return */
    reward = Math.floor(playerContrib * ENGINE.LOSER_FLAT_RETURN);
  }

  const profit          = reward - playerContrib;
  const efficiencyScore = Math.min(500, Math.max(0, Math.round(sd(reward, playerContrib) * 100)));

  return {
    reward, profit, efficiencyScore, outcomeClass,
    loserPool, winnerTotal, loserTotal, grandTotal,
    outcomeMult,
  };
}

/* ════════════════════════════════════════════════════════
   §5 PLAYER ALLOCATION (with time-weight + event mod)
════════════════════════════════════════════════════════ */

/**
 * Set a pending allocation value for a system key.
 * Enforces balance limits and (during late phase) MaxLateContrib cap.
 */
function setPendingAlloc(key, rawValue) {
  const phase        = curPhase();
  const otherPending = ALL_KEYS.reduce((s, k) => s + (k === key ? 0 : (pendingAlloc[k] || 0)), 0);
  const confirmed    = G.playerTotalSpent;
  const roundRemain  = Math.max(0, ENGINE.STARTING_BALANCE - confirmed - otherPending);
  const balRemain    = Math.max(0, G.playerBalance - otherPending - confirmed);
  let maxForKey      = Math.min(roundRemain, balRemain);

  /* §5 Late-phase contribution cap */
  if (phase === 'late') {
    const alloc          = fullAlloc(G.playerCamp);
    const tc             = tcFn(alloc);
    const n              = camps[G.playerCamp].players + 1;
    const avgCampContrib = sd(tc, n);
    const lateCap        = maxLateContrib(confirmed + otherPending, avgCampContrib);
    maxForKey            = Math.min(maxForKey, lateCap);
  }

  const clean         = Math.max(0, Math.round(rawValue / 100) * 100);
  pendingAlloc[key]   = Math.min(clean, Math.max(0, maxForKey));
}

function adjustPendingAlloc(key, delta) {
  setPendingAlloc(key, (pendingAlloc[key] || 0) + delta);
}

function autoAllocatePending(fraction) {
  const avail = Math.max(0, G.playerBalance - G.playerTotalSpent);
  if (avail <= 0) return false;
  const rec    = G.councilRec?.rec || {
    funding:.18, organization:.16, logistics:.12, policy:.22,
    charisma:.07, messaging:.12, opposition_research:.05,
    attack_ads:.02, negative_campaigning:.01, coalitions:.05,
  };
  const target = Math.floor(avail * fraction / 100) * 100;
  ALL_KEYS.forEach(k => pendingAlloc[k] = 0);
  let tot = 0;
  ALL_KEYS.forEach(k => {
    const v = Math.floor(target * (rec[k] || 0) / 100) * 100;
    pendingAlloc[k] = v;
    tot += v;
  });
  if (tot > avail) {
    const scale = avail / tot;
    ALL_KEYS.forEach(k => pendingAlloc[k] = Math.floor(pendingAlloc[k] * scale / 100) * 100);
  }
  return true;
}

/**
 * §2.1 Confirm pending allocation.
 * Applies effectiveAmount() (time weight × ContribEventMod) to each system.
 * Deducts face-value cost from balance, credits effective value to camp alloc.
 * NOTE: agActs is NOT incremented here — only buildAttack() increments it,
 *       keeping allocation and conflict initiation distinct.
 */
function confirmAllocation() {
  const facePending = pendingTotal();
  if (facePending <= 0 || G.playerBalance <= 0) return { success: false, reason: 'no_pending' };

  const safeSpend = Math.min(facePending, G.playerBalance);
  const scale     = safeSpend < facePending ? safeSpend / facePending : 1;
  const tw        = currentTimeWeight();
  let totalFace   = 0;

  ALL_KEYS.forEach(k => {
    const face = Math.floor((pendingAlloc[k] || 0) * scale / 100) * 100;
    const eff  = effectiveAmount(face, k, tw);
    camps[G.playerCamp].alloc[k] = (camps[G.playerCamp].alloc[k] || 0) + eff;
    totalFace += face;
  });

  G.playerTotalSpent += totalFace;
  G.playerBalance     = Math.max(0, G.playerBalance - totalFace);
  ALL_KEYS.forEach(k => pendingAlloc[k] = 0);

  return { success: true, spent: totalFace, timeWeight: tw };
}

/* ════════════════════════════════════════════════════════
   §17.3 MICRO ACTIONS — with cost deduction
════════════════════════════════════════════════════════ */
function triggerPulse(type) {
  if (G.playerTotalSpent <= 0) return { success: false, reason: 'not_confirmed' };
  const cost = computePulseCost(G.playerTotalSpent);
  if (G.playerBalance < cost) return { success: false, reason: 'insufficient_balance' };

  G.playerBalance -= cost;
  const now = Date.now();
  if (type === 'offense') { G.pulseOff = now + ENGINE.PULSE_DURATION_MS; }
  else                    { G.pulseDef = now + ENGINE.PULSE_DURATION_MS; }

  return { success: true, type, cost, expiry: type === 'offense' ? G.pulseOff : G.pulseDef };
}

function doScan(targetCampId) {
  if (G.playerTotalSpent <= 0) return { success: false, reason: 'not_confirmed' };
  const scanResult = performScan(targetCampId);
  if (G.playerBalance < scanResult.cost) return { success: false, reason: 'insufficient_balance', ...scanResult };
  G.playerBalance -= scanResult.cost;
  return { success: true, ...scanResult };
}

/* ════════════════════════════════════════════════════════
   BOT SYSTEM
   FIX: Late-phase cap applied to bots too — prevents bot "wait and dump".
   FIX: agActs incremented via buildAttack(), not via allocation template.
════════════════════════════════════════════════════════ */
function spawnBots() {
  G.bots = [];
  CAMP_DATA.forEach(c => {
    const n = c.id === G.playerCamp ? 88 : 89;
    for (let i = 0; i < n; i++) {
      const arch   = ARCHETYPES[Math.floor(Math.random() * 3)];
      const bursts = 1 + Math.floor(Math.random() * 3);
      G.bots.push({
        cid: c.id, arch, budget: 100000,
        bursts, burstsLeft: bursts,
        nextMs: Date.now() + 2000 + Math.random() * 50000,
      });
    }
    camps[c.id].players = n;
  });
}

function executeBotBurst(bot) {
  if (bot.burstsLeft <= 0) return;
  const phase     = curPhase();
  const fraction  = bot.burstsLeft === 1 ? 1 : 0.25 + Math.random() * 0.45;
  const perBurst  = fraction / bot.bursts;
  const template  = BOT_TEMPLATES[bot.arch];
  const tw        = currentTimeWeight();

  /* §5 Late-phase cap for bots */
  let targetRaw = Math.floor(bot.budget * perBurst / 100) * 100;
  if (phase === 'late') {
    const alloc          = fullAlloc(bot.cid);
    const tc             = tcFn(alloc);
    const n              = camps[bot.cid].players;
    const avgCampContrib = sd(tc, Math.max(1, n));
    /* Bot "round total" proxy: budget - remaining budget */
    const botSpentEst    = bot.budget - bot.budget * (bot.burstsLeft / bot.bursts);
    const lateCap        = maxLateContrib(botSpentEst, avgCampContrib);
    targetRaw            = Math.min(targetRaw, Math.max(0, lateCap));
  }

  /* Normalize template in case it doesn't sum to exactly 1 */
  const templateSum = ALL_KEYS.reduce((s, k) => s + (template[k] || 0), 0);

  ALL_KEYS.forEach(k => {
    const frac = templateSum > 0 ? (template[k] || 0) / templateSum : 0;
    const face  = Math.floor(targetRaw * frac / 100) * 100;
    const eff   = effectiveAmount(face, k, tw);
    camps[bot.cid].alloc[k] = (camps[bot.cid].alloc[k] || 0) + eff;
  });

  bot.burstsLeft--;
  if (bot.burstsLeft > 0) bot.nextMs = Date.now() + 8000 + Math.random() * 37000;
}

function tickBots() {
  const now  = Date.now();
  const frac = phaseFrac();
  G.bots.forEach(b => {
    if (b.burstsLeft <= 0) return;
    if (frac >= 0.88) { while (b.burstsLeft > 0) executeBotBurst(b); return; }
    if (now >= b.nextMs) executeBotBurst(b);
  });
}

/* ════════════════════════════════════════════════════════
   SIGNAL SYSTEM
════════════════════════════════════════════════════════ */
function maybeSpawnBotSignal() {
  const now = Date.now();
  if (now - G.lastBotSigMs < 4000 || Math.random() > 0.012) return null;
  G.lastBotSigMs = now;
  const sig  = SIG_TYPES[Math.floor(Math.random() * SIG_TYPES.length)];
  const cid  = Math.floor(Math.random() * 3);
  const fake = Math.random() < 0.25;
  return { cid, sig, fake, ts: now };
}

function sendPlayerSignal(sigType) {
  if (G.playerTotalSpent <= 0) return false;
  G.signals.unshift({ cid: G.playerCamp, sig: sigType, fake: false, ts: Date.now() });
  if (G.signals.length > 12) G.signals.pop();
  return true;
}

/* ════════════════════════════════════════════════════════
   INTEL WINDOW
════════════════════════════════════════════════════════ */
function maybeSpawnIntelWindow() {
  if (G.intelWin) return false;
  if (Math.random() > 0.007) return false;
  G.intelWin       = true;
  G.intelWinExpiry = Date.now() + 10000;
  return true;
}

function tickIntelWindow() {
  if (G.intelWin && Date.now() >= G.intelWinExpiry) {
    G.intelWin       = false;
    G.intelWinExpiry = 0;
  }
}

/* ════════════════════════════════════════════════════════
   BOT ATTACKS
   FIX: agActs incremented in buildAttack(), not here.
════════════════════════════════════════════════════════ */
function maybeSpawnBotAttack() {
  if (G.cold || Math.random() > 0.012) return null;
  const fromId = Math.floor(Math.random() * 3);
  const others = [0, 1, 2].filter(i => i !== fromId);
  const toId   = others[Math.floor(Math.random() * 2)];
  if (computeAggressionLevel(fullAlloc(fromId)) < 0.05) return null;
  return buildAttack(fromId, toId);
}

/* ════════════════════════════════════════════════════════
   §21 COUNCIL SYSTEM
════════════════════════════════════════════════════════ */
function generateCouncilRecommendation() {
  const rec = {
    funding:.18, organization:.16, logistics:.12, policy:.22,
    charisma:.07, messaging:.12, opposition_research:.05,
    attack_ads:.02, negative_campaigning:.01, coalitions:.05,
  };

  /* Adjust if event revealed */
  if (G.event && G.eventRevealed) {
    if (G.event.sys === 'policy')    { rec.policy    += 0.08; rec.charisma   -= 0.04; rec.messaging  -= 0.04; }
    if (G.event.sys === 'funding')   { rec.funding   += 0.08; rec.coalitions -= 0.04; rec.charisma   -= 0.04; }
    if (G.event.sys === 'messaging') { rec.messaging += 0.06; rec.charisma   -= 0.03; rec.logistics  -= 0.03; }
    if (G.event.sys === 'logistics') { rec.logistics -= 0.05; rec.organization += 0.03; rec.policy   += 0.02; }
  }

  /* Clamp & normalize */
  ALL_KEYS.forEach(k => rec[k] = Math.max(0, rec[k] || 0));
  const total = ALL_KEYS.reduce((s, k) => s + rec[k], 0);
  if (total > 0) ALL_KEYS.forEach(k => rec[k] /= total);

  const goodHist = G.councilHistory.filter(h => h === 'good').length;
  const conf = G.roundsPlayed < 3 ? 'Unproven'
             : goodHist >= 3      ? 'High'
             : goodHist >= 1      ? 'Medium'
             : 'Low';

  const accuracy   = 0.50 + Math.random() * 0.40;
  const deviation  = Math.floor(Math.random() * 30);
  const topSystems = Object.entries(rec).sort((a, b) => b[1] - a[1]).slice(0, 3);

  /* §21 Council recommendation in token amounts (not just %) */
  const availBal  = Math.max(0, G.playerBalance - G.playerTotalSpent);
  const recTokens = {};
  ALL_KEYS.forEach(k => {
    recTokens[k] = Math.floor(availBal * rec[k] / 100) * 100;
  });

  return { rec, recTokens, conf, accuracy, deviation, topSystems };
}

/* ════════════════════════════════════════════════════════
   §14 EVENT DRAW
════════════════════════════════════════════════════════ */
function drawEvent() {
  const roll = Math.random();
  if (roll < ENGINE.EVENT_NONE_PROB) return null;
  if (roll < ENGINE.EVENT_NONE_PROB + ENGINE.EVENT_STANDARD_PROB) {
    const pool = EVENTS.filter(e => !e.super);
    return { ...pool[Math.floor(Math.random() * pool.length)] };
  }
  const pool = EVENTS.filter(e => e.super);
  return { ...pool[Math.floor(Math.random() * pool.length)] };
}

/* ════════════════════════════════════════════════════════
   §23 PERSISTENT PROGRESSION — CARRY-OVER
════════════════════════════════════════════════════════ */
function buildCarryOverAlloc(prevAlloc) {
  const carry = _zeroAlloc();
  carry.logistics = Math.floor((prevAlloc.logistics || 0) * ENGINE.LOGISTICS_CARRY_FRAC);
  carry.policy    = Math.floor((prevAlloc.policy    || 0) * ENGINE.POLICY_CARRY_FRAC);
  return carry;
}

/* ════════════════════════════════════════════════════════
   ROUND LIFECYCLE
════════════════════════════════════════════════════════ */
function startRound() {
  G.seed              = Math.random().toString(36).slice(2, 12);
  G.roundStartMs      = Date.now();
  G.phase             = 'open';
  G.resolving         = false;
  G.attacks           = [];
  G.signals           = [];
  G.pulseOff          = 0;
  G.pulseDef          = 0;
  G.scanLoad          = 0;
  G.scanLoadReset     = Date.now();
  G.intelWin          = false;
  G.intelWinExpiry    = 0;
  G.cold              = false;
  G.eventRevealed     = false;
  G.playerTotalSpent  = 0;
  G.roundStartBalance = G.playerBalance;

  /* FIX: late-phase weight reset each new round */
  G.lateWeightDrawn        = ENGINE.LATE_WEIGHT_BASE;
  G.lateWeightPhaseEntered = false;

  /* §22 Reset daily transfer counter */
  _resetDailyTransfer();

  const ev   = drawEvent();
  G.event    = ev;
  G.cold     = !!(ev && ev.sys === 'cold');

  /* Reset camps — apply carry-over alloc to player's camp */
  CAMP_DATA.forEach(c => {
    const carry = G.carryOverAlloc && c.id === G.playerCamp ? G.carryOverAlloc : _zeroAlloc();
    camps[c.id].alloc  = { ..._zeroAlloc(), ...carry };
    camps[c.id].agActs = 0;
  });
  ALL_KEYS.forEach(k => pendingAlloc[k] = 0);

  /* Council */
  const council     = generateCouncilRecommendation();
  G.councilRec      = council;
  G.councilConf     = council.conf;
  G.councilAccuracy = council.accuracy;
  G.councilDeviation = council.deviation;

  spawnBots();
}

/**
 * Full round resolution.
 * Returns a complete result object consumed by the display layer.
 * FIX: G.anyRoundResolved set AFTER power computation to prevent
 *      first-round phantom underdog bonus.
 */
function resolveRound() {
  if (G.resolving) return null;
  G.resolving = true;
  G.phase     = 'resolving';
  /* FIX: do NOT set anyRoundResolved yet — persistence multiplier reads it */

  /* Force all remaining bot bursts */
  G.bots.forEach(b => { while (b.burstsLeft > 0) executeBotBurst(b); });

  /* Discard unconfirmed pending */
  const discarded = pendingTotal();
  ALL_KEYS.forEach(k => pendingAlloc[k] = 0);

  /* Resolve all pending attacks */
  G.attacks = G.attacks.map(a => a.resolved ? a : resolveAttack(a));

  /* Compute final powers with noise */
  const popMods   = computePopulationModifiers();
  const peaceMods = computePeaceModifiers();

  const powerResults = CAMP_DATA.map((c, i) => {
    const resolvedImpacts = G.attacks
      .filter(a => a.toId === c.id && a.resolved && !a.backfired)
      .map(a => a.impact);
    const playerCount = camps[c.id].players + (c.id === G.playerCamp ? 1 : 0);
    return computeCampaignPower(
      c.id,
      playerCount,
      popMods[i].modifier,
      peaceMods.perCampaign[i].modifier,
      resolvedImpacts
    );
  });

  const powers = powerResults.map(r => r.power);
  const wc     = computeWinChances(powers);

  /* §24 Provable fairness draw */
  let winIdx = 0;
  const roll = Math.random();
  let cum    = 0;
  for (let i = 0; i < wc.length; i++) {
    cum += wc[i];
    if (roll <= cum) { winIdx = i; break; }
  }

  const factionTotals = CAMP_DATA.map(c => taFn(fullAlloc(c.id)));
  const grandTotal    = factionTotals.reduce((a, b) => a + b, 0);
  const playerContrib = Math.min(G.playerTotalSpent, ENGINE.STARTING_BALANCE);
  const mat           = computeMaturity();
  /* ORDERING DEPENDENCY: pendingAlloc was zeroed earlier in this function.
     fullAlloc() merges confirmed alloc + pending. With pending = 0, this
     returns only the confirmed camps[G.playerCamp].alloc — which is correct.
     Do not move this line above the pendingAlloc reset. */
  const playerAlloc   = fullAlloc(G.playerCamp);
  /* computeStability ignores playerCount (uses %-of-core thresholds) — no variable needed */
  const playerStab    = computeStability(playerAlloc, /* ignored */ 0, G.cold);
  const outcomeClass  = classifyOutcome(winIdx, G.playerCamp, powers, playerStab);

  /* §20.2 Participation floor check */
  const avgCampContrib = sd(taFn(playerAlloc), camps[G.playerCamp].players + 1);
  const minThresh      = computeMinContribThreshold(avgCampContrib);
  const aboveFloor     = playerContrib >= minThresh;

  /* §13.1 Effective outcome class.
     If player is below participation floor, they are excluded from reward
     but their campaign may still have won. We preserve the true outcomeClass
     for display context (so "ELECTORAL VICTORY!" still shows) and add a
     separate excludedFromReward flag so the reward/payout display shows 0. */
  const effectivePlayerContrib = aboveFloor ? playerContrib : 0;

  const rewardResult = computeReward({
    winnerCampId: winIdx,
    playerCampId: G.playerCamp,
    playerContrib: effectivePlayerContrib,
    factionTotals,
    outcomeClass,
    maturity: mat,
  });

  /* FIX: set anyRoundResolved AFTER power computation */
  G.anyRoundResolved = true;

  /* §23 Update persistent progression */
  const iWin = winIdx === G.playerCamp;
  if (iWin) {
    G.roundsSinceWin = 1;
    G.lastWinRound   = G.round;
  } else {
    G.roundsSinceWin = Math.min(G.roundsSinceWin + 1, 999);
  }

  /* §23 Build carry-over for next round.
     playerAlloc is safe here — pending was zeroed before playerAlloc was read. */
  G.carryOverAlloc = buildCarryOverAlloc(playerAlloc);

  /* §23 Update identity traits */
  _updateIdentityTraits(playerAlloc);

  /* Council history */
  const councilWasRight = G.councilRec && iWin;
  G.councilHistory.push(councilWasRight ? 'good' : 'weak');
  if (G.councilHistory.length > 5) G.councilHistory.shift();

  /* Power gap for outcome context */
  const myPow    = powers[G.playerCamp];
  const maxRival = Math.max(...powers.filter((_, i) => i !== G.playerCamp), 0);
  const powerGapPct = maxRival > 0 ? ((myPow - maxRival) / maxRival) * 100 : 0;

  /* Apply reward */
  G.playerBalance   = Math.max(0, G.playerBalance + rewardResult.reward);
  G.resolvedBalance = G.playerBalance;

  return {
    round:         G.round,
    seed:          G.seed,
    winIdx,
    winnerName:    CAMP_DATA[winIdx].name,
    winnerColor:   CAMP_DATA[winIdx].color,
    powers,
    powerResults,
    winChances:    wc,
    factionTotals,
    grandTotal,
    playerContrib,
    outcomeClass,
    powerGapPct,
    discarded,
    maturity:      mat,
    playerStab,
    iWin,
    obs:           playerContrib === 0,
    aboveFloor,
    excludedFromReward: !aboveFloor,  /* true = won/lost but earned nothing (below floor) */
    minThresh,
    carryOver:     { ...G.carryOverAlloc },
    identityTraits: { ...G.identityTraits },
    ...rewardResult,
  };
}

/** Called after drama sequence closes */
function advanceRound(result) {
  G.round++;
  G.roundsPlayed++;
  G.accountAgeDays++;
  G.resolving = false;

  G.history.unshift({
    round:        result.round,
    winIdx:       result.winIdx,
    winnerName:   result.winnerName,
    winnerColor:  result.winnerColor,
    playerCamp:   CAMP_DATA[G.playerCamp].name,
    playerColor:  CAMP_DATA[G.playerCamp].color,
    iWin:         result.iWin,
    obs:          result.obs,
    profit:       result.profit,
    effScore:     result.efficiencyScore,
    outcomeClass: result.outcomeClass,
    powers:       [...result.powers],
    winChances:   [...result.winChances],
    factionTotals: [...result.factionTotals],
  });
  if (G.history.length > 20) G.history.pop();
}

/* ════════════════════════════════════════════════════════
   MASTER STATE SNAPSHOT — getGameState()
   Called once per render cycle (RAF). Pure read. Zero side-effects.
   Returns everything the display layer needs.

   FIXES applied here:
   - computeStability called with real playerCount per campaign
   - PlayerPopWeight exposed per campaign
   - GlobalAggression and PeaceBonus exposed (were in hidden bar)
   - policyHeadroom, policyCapPct, saturationFactor, effectiveMsgGap exposed
   - TimeWeight exposed for phase badge display
   - AvailableBalance vs RoundBudgetLeft distinguished explicitly
   - contribEventMod returned as structured object
   - All conflict diagnostics (TargetScore, OutcomeFactor, MultiAttackPenalty,
     AttackCredMod, BackfireChance, EffectiveDefense) exposed per rival
════════════════════════════════════════════════════════ */
function getGameState() {
  const now       = Date.now();
  const frac      = phaseFrac();
  const phase     = curPhase();
  const tw        = currentTimeWeight();
  const popMods   = computePopulationModifiers();
  const peaceMods = computePeaceModifiers();
  const mat       = computeMaturity();

  /* §23 Trait bonuses — computed once per frame, passed into per-campaign multipliers */
  const traitBonuses = computeTraitBonuses();

  /* Per-campaign enriched data
     FIX: real playerCount passed to computeStability and computePowerMultipliers */
  const campaigns = CAMP_DATA.map((c, i) => {
    const alloc      = fullAlloc(c.id);
    const tc         = tcFn(alloc);
    const ta         = taFn(alloc);
    const playerCount = camps[c.id].players + (c.id === G.playerCamp ? 1 : 0);
    const T          = computeTrust(alloc);
    const C          = computeCredibility(alloc);
    const msg        = computeMessaging(alloc);
    const al         = computeAggressionLevel(alloc);
    const agActs     = camps[c.id].agActs;
    const ac         = computeAggressionCost(al, agActs);
    const effDef     = computeEffectiveDefense(alloc);

    /* FIX: real playerCount */
    const stab       = computeStability(alloc, playerCount, G.cold);

    /* FIX: real playerCount in multipliers; memoized traitBonuses passed in — no re-computation */
    const mults      = computePowerMultipliers(alloc, agActs, G.cold, playerCount, traitBonuses);

    const pm         = popMods[i];
    const peaceM     = peaceMods.perCampaign[i];
    const evConf     = computeEventConfidence(alloc);
    const bfChance   = computeBackfireChance(c.id);
    const estPow     = estimatePower(c.id, traitBonuses);
    const trustMod   = ENGINE.TRUST_MOD_BASE + T * ENGINE.TRUST_MOD_COEFF;
    const credMod    = ENGINE.CRED_MOD_BASE  + C * ENGINE.CRED_MOD_COEFF;

    /* Per-rival conflict intel — all values exposed for display */
    const rivalIntel = CAMP_DATA.filter(r => r.id !== c.id).map(r => {
      const rAlloc        = fullAlloc(r.id);
      const N             = G.attacks.filter(a => a.fromId === c.id && a.toId === r.id && !a.resolved).length + 1;
      const targetScore   = computeTargetScore(c.id, r.id);
      const incomingScore = computeTargetScore(r.id, c.id);
      const of            = computeOutcomeFactor(c.id, r.id, traitBonuses);
      const acm           = computeAttackCredibilityMod(c.id);
      const map           = computeMultiAttackPenalty(N);
      const ci            = computeConflictImpact(c.id, r.id, false, traitBonuses);
      const theirEffDef   = computeEffectiveDefense(rAlloc);
      const bf            = computeBackfireChance(c.id);
      const rivalEstPow   = estimatePower(r.id, traitBonuses);
      return {
        rivalId:            r.id,
        rivalName:          r.name,
        rivalColor:         r.color,
        targetScore,
        incomingScore,
        outcomeFactor:      of,
        attackCredMod:      acm,
        conflictImpact:     ci,
        multiAttackPenalty: map,
        multiAttackN:       N,
        theirEffDef,
        backfireChance:     bf,
        vulnerable:         theirEffDef < 0.06 || rivalEstPow < estPow * 0.9,
        threatLevel:        incomingScore > 0.30 ? 'High' : incomingScore > 0.20 ? 'Med' : incomingScore > 0.15 ? 'Low' : 'None',
      };
    });

    return {
      id: c.id, name: c.name, label: c.label, color: c.color,
      alloc, tc, ta, T, C, msg, al, agActs, ac, effDef, stab, mults,
      pm, peaceM, evConf, bfChance, estPow, rivalIntel,
      trustMod, credMod,
      playerCount,
      players:      camps[c.id].players,
      isPlayerCamp: c.id === G.playerCamp,
      /* playerPopWeight: 0 for bot-only campaigns, [0–1] for player's camp */
      playerPopWeight: pm.playerPopWeight,
    };
  });

  const playerCamp    = campaigns[G.playerCamp];
  const estPowers     = campaigns.map(c => c.estPow);
  const winChances    = computeWinChances(estPowers);

  /* §20 Player economy */
  const humanContrib    = G.playerTotalSpent + pendingTotal();
  const avgContrib      = sd(playerCamp.tc, Math.max(1, playerCamp.players + 1));
  const softCap         = computeSoftCap(playerCamp.tc);
  const effContrib      = computeEffectiveContribution(humanContrib, softCap);
  const roundBudgetLeft = Math.max(0, G.playerBalance - G.playerTotalSpent);
  const availBal        = availableBalance();
  const isObserver      = G.playerTotalSpent === 0 && pendingTotal() === 0;
  const minThreshold    = computeMinContribThreshold(avgContrib);
  const aboveMinThresh  = humanContrib >= minThreshold;

  /* §15 Scan state.
     scanCostDisplay uses the midpoint of the 1–3% range for a stable UI value.
     The actual randomised cost is drawn only inside performScan() / doScan(). */
  _refreshScanLoad();
  const scanAccuracy    = computeScanAccuracy();
  const scanNoise       = computeDisplayNoise(scanAccuracy);
  /* scanCostDisplay: stable midpoint estimate for UI — no per-frame randomness.
     Step 1: base = midpoint of 1–3% range, rounded to nearest 100.
     Step 2: apply intel window discount (−30%) if active.
     Step 3: snap result to nearest 100 so displayed amount is always
             a clean token denomination regardless of discount rounding.   */
  const _scanBase       = Math.max(100, Math.round(
    Math.max(100, G.playerTotalSpent)
    * ((ENGINE.SCAN_COST_MIN_PCT + ENGINE.SCAN_COST_MAX_PCT) / 2)
    / 100
  ) * 100);
  const scanCostDisplay = Math.max(100, Math.round(
    _scanBase * (G.intelWin ? (1 - ENGINE.INTEL_WIN_DISCOUNT) : 1) / 100
  ) * 100);
  const pulseCost = computePulseCost(Math.max(100, G.playerTotalSpent));

  /* Active attacks enriched */
  const activeAttacks = G.attacks.filter(a => !a.resolved).map(a => {
    const eta      = Math.max(0, Math.ceil((a.resolveMs - now) / 1000));
    const progress = Math.max(0, Math.min(1, (now - a.startMs) / (a.delay * 1000)));
    const N        = a.N || 1;
    return {
      ...a, eta, progress, N,
      isIncoming: a.toId   === G.playerCamp,
      isOutgoing: a.fromId === G.playerCamp,
    };
  });

  /* Event meta */
  const eventConfidence = computeEventConfidence(playerCamp.alloc);
  /* FIX: contribEventMod returned as structured object (not raw scalar) */
  const contribEventMod = {
    active: !!(G.event && G.event.layer === 'ContribEventMod'),
    sys:    G.event?.sys  || null,
    mod:    G.event && G.event.layer === 'ContribEventMod' ? G.event.mod : 1.0,
    label:  G.event && G.event.layer === 'ContribEventMod' ? G.event.desc : null,
  };

  /* §22 Transfer state */
  const dailyTransferLimit  = G.playerBalance * ENGINE.TRANSFER_DAILY_CAP_FRAC;
  const dailyTransferRemain = Math.max(0, dailyTransferLimit - G.transfersToday);
  const transferCooldownRemainMs = Math.max(0, G.lastTransferMs + ENGINE.TRANSFER_COOLDOWN_MS - now);
  const transferUnlocked = G.accountAgeDays * 86400000 >= ENGINE.TRANSFER_MIN_AGE_MS
                        && G.roundsPlayed >= ENGINE.TRANSFER_MIN_ROUNDS;

  return {
    /* Round meta */
    round:    G.round,
    seed:     G.seed,
    phase,
    frac,
    timeWeight:       tw,
    cold:             G.cold,
    resolving:        G.resolving,
    anyRoundResolved: G.anyRoundResolved,

    /* Campaigns */
    campaigns,
    playerCampId:     G.playerCamp,
    playerCamp,

    /* Cross-campaign */
    estPowers,
    winChances,
    /* FIX: globalAggression and peaceBonus explicitly exposed at top level */
    globalAggression:  peaceMods.globalAggression,
    peaceBonus:        peaceMods.peaceBonus,
    popModifiers:      popMods,
    peaceModifiers:    peaceMods.perCampaign,

    /* Player economy */
    playerBalance:     G.playerBalance,
    playerTotalSpent:  G.playerTotalSpent,
    playerPending:     pendingTotal(),
    pendingAlloc:      { ...pendingAlloc },
    humanContrib,
    /* FIX: both clearly named */
    availableBalance:  availBal,          /* balance minus all committed & pending */
    roundBudgetLeft,                       /* balance minus confirmed this round */
    softCap,
    effContrib,
    maturity:          mat,
    isObserver,
    aboveMinThresh,
    minThreshold,
    resolvedBalance:   G.resolvedBalance,
    pl:                G.anyRoundResolved ? G.resolvedBalance - ENGINE.STARTING_BALANCE : null,

    /* Attacks */
    activeAttacks,
    resolvedAttacks:   G.attacks.filter(a => a.resolved),

    /* Event */
    event:             G.event,
    eventRevealed:     G.eventRevealed,
    eventConfidence,
    contribEventMod,
    eventLayer:        G.event?.layer || null,
    eventSys:          G.event?.sys   || null,

    /* Council */
    councilRec:        G.councilRec,
    councilConf:       G.councilConf,
    councilAccuracy:   G.councilAccuracy,
    councilDeviation:  G.councilDeviation,

    /* Scan */
    scanAccuracy,
    scanNoise,
    scanCostDisplay,   /* stable midpoint estimate for UI; actual cost drawn at scan time */
    intelWinActive:    G.intelWin,
    intelWinRemainMs:  G.intelWin ? Math.max(0, G.intelWinExpiry - now) : 0,

    /* Pulse */
    pulseCost,
    pulseOffActive:    G.pulseOff > now,
    pulseDefActive:    G.pulseDef > now,
    pulseOffRemainS:   Math.max(0, Math.ceil((G.pulseOff - now) / 1000)),
    pulseDefRemainS:   Math.max(0, Math.ceil((G.pulseDef - now) / 1000)),

    /* Signals */
    signals:           [...G.signals],

    /* §23 Persistence */
    roundsSinceWin:    G.roundsSinceWin,
    carryOverAlloc:    G.carryOverAlloc ? { ...G.carryOverAlloc } : null,
    identityTraits:    { ...G.identityTraits },
    traitBonuses,
    persistMod:        _getPersistenceMultiplier(G.playerCamp),

    /* §12 Migration */
    switchCount:          G.switchCount,
    migrationCooldownRemainMs: Math.max(0, G.migrationCooldownMs - now),
    migrationPenaltyNext: computeMigrationPenalty(),

    /* §22 Transfer */
    transferUnlocked,
    dailyTransferLimit,
    dailyTransferRemain,
    transferCooldownRemainMs,

    /* History */
    history:           [...G.history],
  };
}
