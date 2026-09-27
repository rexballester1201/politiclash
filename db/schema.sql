-- ============================================================================
-- PolitiClash — database schema
-- MySQL 5.7+ / MariaDB 10.2+ · utf8mb4
--
-- Design notes
--   · Bots live in my_users with is_bot=1 so every gameplay path is uniform.
--     Their personality lives in pc_bot_profiles.
--   · Money / wagering / on-chain layers are intentionally absent.
--     Tokens are game currency only, faucet-fed (see pc_settings.stipend_*).
--   · face_amount is what the player paid.  effective_amount is what the
--     campaign actually banked after time weight x event modifier.
--     They differ, and the difference is the whole point of round timing.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';   -- Asia/Manila


-- ============================================================================
-- USERS
-- ============================================================================
CREATE TABLE IF NOT EXISTS my_users (
  user_id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  username          VARCHAR(32)     NOT NULL,
  email             VARCHAR(190)    NOT NULL,
  password_hash     VARCHAR(255)    NOT NULL,

  email_verified    TINYINT(1)      NOT NULL DEFAULT 0,
  verify_token      CHAR(64)                 DEFAULT NULL,
  verify_expires    DATETIME                 DEFAULT NULL,

  -- economy
  balance           BIGINT          NOT NULL DEFAULT 100000,
  lifetime_earned   BIGINT          NOT NULL DEFAULT 0,
  lifetime_spent    BIGINT          NOT NULL DEFAULT 0,

  -- allegiance
  campaign_id       TINYINT                  DEFAULT NULL,   -- 0=LENI 1=SARA 2=MARCOS
  joined_campaign_at DATETIME                DEFAULT NULL,

  -- progression (engine SS23)
  rounds_played     INT             NOT NULL DEFAULT 0,
  rounds_won        INT             NOT NULL DEFAULT 0,
  rounds_since_win  INT             NOT NULL DEFAULT 999,
  last_win_round    INT             NOT NULL DEFAULT 0,
  switch_count      INT             NOT NULL DEFAULT 0,
  last_switch_round INT             NOT NULL DEFAULT -1,

  -- bots
  is_bot            TINYINT(1)      NOT NULL DEFAULT 0,

  status            ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  created_at        DATETIME        NOT NULL,
  last_login_at     DATETIME                 DEFAULT NULL,
  last_seen_at      DATETIME                 DEFAULT NULL,

  PRIMARY KEY (user_id),
  UNIQUE KEY uq_username (username),
  UNIQUE KEY uq_email (email),
  KEY ix_campaign (campaign_id),
  KEY ix_bot (is_bot, status),
  KEY ix_verify (verify_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- BOT PERSONALITIES
--   loyalty   0..1  resistance to switching campaigns
--   greed     0..1  pull toward whichever campaign is currently winning
--   integrity 0..1  inverse propensity to fund attack / negative systems
--   aggression0..1  appetite for opening conflicts
--   activity  0..1  chance of acting on any given tick
-- ============================================================================
CREATE TABLE IF NOT EXISTS pc_bot_profiles (
  user_id           INT UNSIGNED    NOT NULL,
  personality       ENUM('rabid','switcher','lurker') NOT NULL,
  archetype         ENUM('aggressive','defensive','balanced') NOT NULL,
  home_campaign     TINYINT         NOT NULL,

  loyalty           DECIMAL(4,3)    NOT NULL DEFAULT 0.500,
  greed             DECIMAL(4,3)    NOT NULL DEFAULT 0.500,
  integrity         DECIMAL(4,3)    NOT NULL DEFAULT 0.500,
  aggression        DECIMAL(4,3)    NOT NULL DEFAULT 0.500,
  activity          DECIMAL(4,3)    NOT NULL DEFAULT 0.500,

  -- per-round pacing
  budget_plan       BIGINT          NOT NULL DEFAULT 0,   -- intended spend this round
  budget_spent      BIGINT          NOT NULL DEFAULT 0,
  bursts_total      TINYINT         NOT NULL DEFAULT 3,
  bursts_left       TINYINT         NOT NULL DEFAULT 3,
  next_action_at    DATETIME                 DEFAULT NULL,
  plan_round_id     INT UNSIGNED             DEFAULT NULL,

  last_action_at    DATETIME                 DEFAULT NULL,

  PRIMARY KEY (user_id),
  KEY ix_next (next_action_at),
  KEY ix_personality (personality),
  CONSTRAINT fk_bot_user FOREIGN KEY (user_id)
    REFERENCES my_users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- ROUNDS  — one per day, opens 00:00 closes 21:00 (Asia/Manila)
-- ============================================================================
CREATE TABLE IF NOT EXISTS pc_rounds (
  round_id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  round_no          INT             NOT NULL,
  status            ENUM('scheduled','open','resolving','settled') NOT NULL DEFAULT 'scheduled',

  opens_at          DATETIME        NOT NULL,
  closes_at         DATETIME        NOT NULL,
  settled_at        DATETIME                 DEFAULT NULL,

  -- SS14 event, drawn at open, hidden until revealed per-player
  active_event      VARCHAR(48)              DEFAULT NULL,
  event_layer       ENUM('ContribEventMod','PowerEventMod','ConflictEventMod') DEFAULT NULL,
  event_sys         VARCHAR(24)              DEFAULT NULL,
  event_mod         DECIMAL(6,3)             DEFAULT NULL,
  event_is_super    TINYINT(1)      NOT NULL DEFAULT 0,

  -- SS5 late-phase weight, drawn once at open so it cannot be farmed
  late_weight_x1000 SMALLINT        NOT NULL DEFAULT 900,

  -- settlement output
  winner_campaign   TINYINT                  DEFAULT NULL,
  global_aggression DECIMAL(9,6)             DEFAULT NULL,
  peace_bonus       DECIMAL(9,6)             DEFAULT NULL,
  grand_total       BIGINT          NOT NULL DEFAULT 0,
  loser_pool        BIGINT          NOT NULL DEFAULT 0,

  seed              CHAR(16)        NOT NULL DEFAULT '',
  created_at        DATETIME        NOT NULL,

  PRIMARY KEY (round_id),
  UNIQUE KEY uq_round_no (round_no),
  KEY ix_status (status),
  KEY ix_window (opens_at, closes_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- PER-CAMPAIGN ROUND STATE
--   Running totals maintained on every contribution so the live cockpit can
--   read one row per campaign instead of aggregating the whole ledger.
--   *_eff columns hold effective (time-weighted) values — these feed the engine.
-- ============================================================================
CREATE TABLE IF NOT EXISTS pc_round_campaign (
  id                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  round_id          INT UNSIGNED    NOT NULL,
  campaign_id       TINYINT         NOT NULL,

  -- effective totals per system (SS2.3)
  funding             DECIMAL(20,3) NOT NULL DEFAULT 0,
  organization        DECIMAL(20,3) NOT NULL DEFAULT 0,
  logistics           DECIMAL(20,3) NOT NULL DEFAULT 0,
  policy              DECIMAL(20,3) NOT NULL DEFAULT 0,
  charisma            DECIMAL(20,3) NOT NULL DEFAULT 0,
  messaging           DECIMAL(20,3) NOT NULL DEFAULT 0,
  opposition_research DECIMAL(20,3) NOT NULL DEFAULT 0,
  attack_ads          DECIMAL(20,3) NOT NULL DEFAULT 0,
  negative_campaigning DECIMAL(20,3) NOT NULL DEFAULT 0,
  coalitions          DECIMAL(20,3) NOT NULL DEFAULT 0,

  total_core        DECIMAL(20,3)   NOT NULL DEFAULT 0,
  total_all         DECIMAL(20,3)   NOT NULL DEFAULT 0,
  face_total        BIGINT          NOT NULL DEFAULT 0,   -- unweighted, for prize pool

  ag_acts           INT             NOT NULL DEFAULT 0,   -- conflicts opened (SS9.5 burn rate)
  player_count      INT             NOT NULL DEFAULT 0,
  contributor_count INT             NOT NULL DEFAULT 0,

  -- settlement snapshot
  power             DECIMAL(24,6)            DEFAULT NULL,
  win_chance        DECIMAL(9,6)             DEFAULT NULL,
  trust             DECIMAL(9,6)             DEFAULT NULL,
  credibility       DECIMAL(9,6)             DEFAULT NULL,
  stability_score   DECIMAL(12,4)            DEFAULT NULL,
  stability_state   ENUM('stable','strained','unstable','critical') DEFAULT NULL,
  collapsed         TINYINT(1)      NOT NULL DEFAULT 0,
  pop_mod           DECIMAL(9,6)             DEFAULT NULL,
  peace_mod         DECIMAL(9,6)             DEFAULT NULL,
  conflict_product  DECIMAL(9,6)             DEFAULT NULL,
  noise_x1000       SMALLINT                 DEFAULT NULL,
  mults_json        TEXT                     DEFAULT NULL, -- full multiplier bundle for the breakdown screen

  updated_at        DATETIME        NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_round_campaign (round_id, campaign_id),
  CONSTRAINT fk_rc_round FOREIGN KEY (round_id)
    REFERENCES pc_rounds (round_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- CONTRIBUTION LEDGER  — append only, one row per system per confirmed batch
-- ============================================================================
CREATE TABLE IF NOT EXISTS pc_contributions (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  round_id          INT UNSIGNED    NOT NULL,
  user_id           INT UNSIGNED    NOT NULL,
  campaign_id       TINYINT         NOT NULL,
  system_key        VARCHAR(24)     NOT NULL,

  face_amount       BIGINT          NOT NULL,
  effective_amount  DECIMAL(20,3)   NOT NULL,
  time_weight_x1000 SMALLINT        NOT NULL,
  event_mod_x1000   SMALLINT        NOT NULL DEFAULT 1000,
  phase             ENUM('early','mid','late') NOT NULL,

  created_at        DATETIME        NOT NULL,

  PRIMARY KEY (id),
  KEY ix_round_user (round_id, user_id),
  KEY ix_round_campaign (round_id, campaign_id),
  KEY ix_user (user_id),
  CONSTRAINT fk_contrib_round FOREIGN KEY (round_id)
    REFERENCES pc_rounds (round_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- PER-USER PER-ROUND aggregate + settlement result
-- ============================================================================
CREATE TABLE IF NOT EXISTS pc_user_round (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  round_id          INT UNSIGNED    NOT NULL,
  user_id           INT UNSIGNED    NOT NULL,
  campaign_id       TINYINT         NOT NULL,

  contributed       BIGINT          NOT NULL DEFAULT 0,   -- face value
  eff_contributed   DECIMAL(20,3)   NOT NULL DEFAULT 0,
  action_spend      BIGINT          NOT NULL DEFAULT 0,   -- scans + pulses

  reward            BIGINT                   DEFAULT NULL,
  profit            BIGINT                   DEFAULT NULL,
  outcome           ENUM('victory','narrow','resilient','collapse','observer') DEFAULT NULL,
  efficiency_score  SMALLINT                 DEFAULT NULL,
  excluded_reward   TINYINT(1)      NOT NULL DEFAULT 0,
  maturity          DECIMAL(6,4)             DEFAULT NULL,
  settled_at        DATETIME                 DEFAULT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_round_user (round_id, user_id),
  KEY ix_user_round (user_id, round_id),
  CONSTRAINT fk_ur_round FOREIGN KEY (round_id)
    REFERENCES pc_rounds (round_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- CONFLICTS (SS10)  — attacks resolve on a delay, so they are rows not events
-- ============================================================================
CREATE TABLE IF NOT EXISTS pc_conflicts (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  round_id          INT UNSIGNED    NOT NULL,
  from_campaign     TINYINT         NOT NULL,
  to_campaign       TINYINT         NOT NULL,
  initiator_user_id INT UNSIGNED             DEFAULT NULL,

  impact            DECIMAL(9,6)    NOT NULL,   -- EffectiveConflictImpact
  backfire_chance   DECIMAL(9,6)    NOT NULL,
  n_index           SMALLINT        NOT NULL DEFAULT 1,

  target_score      DECIMAL(9,6)    NOT NULL DEFAULT 0,
  outcome_factor    DECIMAL(9,6)    NOT NULL DEFAULT 1,
  attack_cred_mod   DECIMAL(9,6)    NOT NULL DEFAULT 1,
  multi_atk_penalty DECIMAL(9,6)    NOT NULL DEFAULT 1,
  pulse_off         TINYINT(1)      NOT NULL DEFAULT 0,
  pulse_def         TINYINT(1)      NOT NULL DEFAULT 0,

  resolve_at        DATETIME        NOT NULL,
  resolved          TINYINT(1)      NOT NULL DEFAULT 0,
  backfired         TINYINT(1)      NOT NULL DEFAULT 0,
  created_at        DATETIME        NOT NULL,

  PRIMARY KEY (id),
  KEY ix_round (round_id, resolved),
  KEY ix_target (round_id, to_campaign, resolved),
  KEY ix_resolve (resolved, resolve_at),
  CONSTRAINT fk_conflict_round FOREIGN KEY (round_id)
    REFERENCES pc_rounds (round_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- IDENTITY TRAITS (SS23) — 3 consecutive qualifying rounds activates a trait
-- ============================================================================
CREATE TABLE IF NOT EXISTS pc_user_traits (
  user_id           INT UNSIGNED    NOT NULL,
  trait_key         VARCHAR(32)     NOT NULL,
  streak_rounds     SMALLINT        NOT NULL DEFAULT 0,
  active            TINYINT(1)      NOT NULL DEFAULT 0,
  activated_round   INT             NOT NULL DEFAULT 0,
  updated_at        DATETIME        NOT NULL,

  PRIMARY KEY (user_id, trait_key),
  CONSTRAINT fk_trait_user FOREIGN KEY (user_id)
    REFERENCES my_users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- CARRY-OVER (SS23) — 50% of Logistics and Policy survives into the next round
-- ============================================================================
CREATE TABLE IF NOT EXISTS pc_user_carryover (
  user_id           INT UNSIGNED    NOT NULL,
  campaign_id       TINYINT         NOT NULL,
  from_round_id     INT UNSIGNED    NOT NULL,
  logistics         DECIMAL(20,3)   NOT NULL DEFAULT 0,
  policy            DECIMAL(20,3)   NOT NULL DEFAULT 0,
  consumed          TINYINT(1)      NOT NULL DEFAULT 0,
  created_at        DATETIME        NOT NULL,

  PRIMARY KEY (user_id),
  CONSTRAINT fk_carry_user FOREIGN KEY (user_id)
    REFERENCES my_users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- MICRO ACTIONS — pulses (SS10.5) and scans (SS15)
-- ============================================================================
CREATE TABLE IF NOT EXISTS pc_pulses (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  round_id          INT UNSIGNED    NOT NULL,
  user_id           INT UNSIGNED    NOT NULL,
  campaign_id       TINYINT         NOT NULL,
  pulse_type        ENUM('offense','defense') NOT NULL,
  cost              BIGINT          NOT NULL,
  expires_at        DATETIME        NOT NULL,
  created_at        DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_active (round_id, campaign_id, pulse_type, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pc_scans (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  round_id          INT UNSIGNED    NOT NULL,
  user_id           INT UNSIGNED    NOT NULL,
  target_campaign   TINYINT         NOT NULL,
  accuracy          DECIMAL(6,4)    NOT NULL,
  cost              BIGINT          NOT NULL,
  payload_json      TEXT            NOT NULL,
  event_revealed    TINYINT(1)      NOT NULL DEFAULT 0,
  created_at        DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_round_user (round_id, user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user event reveal state (SS14.1 — Opposition Research buys a reveal roll)
CREATE TABLE IF NOT EXISTS pc_event_reveals (
  round_id          INT UNSIGNED    NOT NULL,
  user_id           INT UNSIGNED    NOT NULL,
  revealed_at       DATETIME        NOT NULL,
  PRIMARY KEY (round_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- SIGNALS — public intent broadcasts, 25% of bot signals are bluffs
-- ============================================================================
CREATE TABLE IF NOT EXISTS pc_signals (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  round_id          INT UNSIGNED    NOT NULL,
  user_id           INT UNSIGNED    NOT NULL,
  campaign_id       TINYINT         NOT NULL,
  sig_type          ENUM('ATTACK','DEFEND','PUSH POLICY','HOLD') NOT NULL,
  is_fake           TINYINT(1)      NOT NULL DEFAULT 0,
  created_at        DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_round (round_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- LIVE FEED — the scrolling HUD ticker on the cockpit
-- ============================================================================
CREATE TABLE IF NOT EXISTS pc_feed (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  round_id          INT UNSIGNED             DEFAULT NULL,
  kind              ENUM('contribution','attack','backfire','switch','event',
                         'signal','join','round','system','pulse','scan',
                         'coalition','result','stipend') NOT NULL,
  campaign_id       TINYINT                  DEFAULT NULL,
  actor             VARCHAR(32)              DEFAULT NULL,
  body              VARCHAR(255)    NOT NULL,
  severity          ENUM('info','good','warn','bad') NOT NULL DEFAULT 'info',
  created_at        DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY ix_round_id (round_id, id),
  KEY ix_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- SETTINGS — cron tokens, faucet knobs, feature flags
-- ============================================================================
CREATE TABLE IF NOT EXISTS pc_settings (
  skey              VARCHAR(48)     NOT NULL,
  sval              VARCHAR(255)    NOT NULL,
  updated_at        DATETIME        NOT NULL,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO pc_settings (skey, sval, updated_at) VALUES
  ('cron_token',        'CHANGE-ME-b7f2a91c4e', NOW()),
  ('round_opens',       '00:00',                NOW()),
  ('round_closes',      '21:00',                NOW()),
  ('starting_balance',  '100000',               NOW()),
  ('stipend_floor',     '20000',                NOW()),  -- below this, top up
  ('stipend_target',    '60000',                NOW()),  -- topped up to this
  ('bot_count',         '100',                  NOW()),
  ('bots_enabled',      '1',                    NOW()),

  -- Verification mail. Leave mail_smtp_host EMPTY to use PHP mail(), which is
  -- what most shared hosts want; fill it in to send over SMTP, which is the
  -- only thing that works on XAMPP. Settings live here rather than in a config
  -- file so local and live differ by database, with no file to swap before an
  -- upload.
  --
  -- SET mail_from ON A SUBDOMAIN INSTALL. Blank falls back to no-reply@<site
  -- host>, and a cPanel account serves mail for the registrable domain, not for
  -- app.example.com. Exim refuses the -f sender for a domain it does not serve,
  -- mail() returns false, and nothing sends even though the parent domain mails
  -- fine. It also has to be a domain whose SPF authorises this host, or the mail
  -- sends and still never arrives.
  ('mail_smtp_host',    '',                     NOW()),
  ('mail_smtp_user',    '',                     NOW()),
  ('mail_smtp_pass',    '',                     NOW()),
  ('mail_smtp_port',    '587',                  NOW()),
  ('mail_smtp_crypto',  'tls',                  NOW()),
  ('mail_from',         '',                     NOW()),  -- blank: no-reply@<host>
  ('mail_from_name',    '',                     NOW()),  -- blank: PolitiClash
  ('mail_test_to',      '',                     NOW())   -- /cron/mailtest/<token> sends here
ON DUPLICATE KEY UPDATE sval = VALUES(sval);


-- ============================================================================
-- LEADERBOARD VIEW — cheap read for the cockpit "overall winner" strip
-- ============================================================================
CREATE OR REPLACE VIEW pc_v_leaderboard AS
SELECT
  u.user_id,
  u.username,
  u.campaign_id,
  u.is_bot,
  u.balance,
  u.rounds_played,
  u.rounds_won,
  CASE WHEN u.rounds_played > 0
       THEN ROUND(u.rounds_won / u.rounds_played * 100, 1)
       ELSE 0 END AS win_pct,
  (u.balance - 100000) AS net_tokens
FROM my_users u
WHERE u.status = 'active';
