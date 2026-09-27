# PolitiClash — Handover

**Status:** playable and deployed. Core loop complete and verified end to end.
Production hardening is **not** applied yet — see [Outstanding](#outstanding).

A live three-way political campaign simulation. One round per day, 00:00 → 21:00
Asia/Manila. Players split a token budget across ten campaign systems, read the board,
and find out at the count whose machine held together.

Built on CodeIgniter 3.1.9. Tokens are game currency with no purchase path, no
wagering and no cash value.

---

## 1. Where it runs

| | |
|---|---|
| **Live** | `~/politiclash` on a cPanel account; its database credentials are in that server's `application/config/secrets.php` |
| **Local** | `D:\xampp\htdocs\dashboard\politiclash` — DB `politiclash`, user `root` |
| **PHP** | Server is **7.x**; local is 8.2. No PHP 8-only syntax is used, so both work. |
| **Timezone** | Pinned to `Asia/Manila` in `constants.php`. Never trust server config for this. |

`application/config/database.php` in the repo currently holds the **live** credentials.
To run locally you must swap them — and swap them back before uploading. This bit us
repeatedly; see [Gotchas](#8-gotchas-that-cost-real-time).

---

## 2. Code map

~9,300 hand-written lines. Everything under `system/` is stock CodeIgniter — untouched.

```
application/
  libraries/GameEngine.php    1438   every formula. THE authority (see §5)
  libraries/BotBrain.php       543   the synthetic electorate
  models/M_user.php                  accounts, allegiance, progression, the faucet
  models/M_round.php                 round lifecycle + settlement   } 1549 total
  models/M_play.php                  contributions, conflicts, feed }
  core/MY_Controller.php             shared base: session, view(), json()
  controllers/Home.php               cockpit + about + faq          }
  controllers/Auth.php               register, verify, login        }
  controllers/Game.php               player pages + JSON commands   } 1350 total
  controllers/Api.php                /api/state, /api/feed, /api/leaders
  controllers/Cron.php               open, tick, settle, auto, seed
  views/**                    1817   _head/_foot shell + 15 pages
assets/css/theme.css                 HOAXSNOWDEN tokens             }
assets/css/phosphor.css              icon subset — NO EMOJI anywhere} 2197 total
assets/js/app.js                     nav, stacks, a2hs, fetch helpers
assets/js/cockpit.js                 polling, rendering, radar
assets/js/phl-geo.js          49K    91 coastlines — GENERATED, never hand-edit
assets/js/phl-map.js                 projects them into the radar's relief plate
sw.js                                service worker
db/schema.sql                437     15 tables + 1 view
references/                          DESIGN SOURCE OF RECORD — never ship
3d-map.html                          three.js original of the radar plate — never ship
tools/make-icons.php                 PNG generator, already run — never ship
tools/make-phl-geo.js                rebuilds phl-geo.js from 3d-map.html — never ship
```

**23 routes**, **15 tables**. Tuning lives in `pc_settings`, not in code:
`cron_token`, `round_opens`, `round_closes`, `starting_balance`, `stipend_floor`,
`stipend_target`, `bot_count`, `bots_enabled`, and the verification-mail
transport — `mail_smtp_host` / `_user` / `_pass` / `_port` / `_crypto`,
`mail_from`, `mail_from_name`. Empty `mail_smtp_host` means PHP `mail()`.

Re-running `db/schema.sql` on a live database **overwrites every one of these**
back to its seed value — the seed ends `ON DUPLICATE KEY UPDATE`. That takes
`cron_token` back to `CHANGE-ME-…` and blanks any mail credentials with it.

---

## 3. How a day works

| Phase | Window | Contribution weight |
|---|---|---|
| early | 00:00 – 10:30 | ×1.000 |
| mid | 10:30 – 17:51 | ×1.050 — the sweet spot |
| late | 17:51 – 21:00 | ×0.85–0.95, drawn fresh each open, plus a per-player cap |

Phases are **fractions** of the window (0.50 / 0.85), so changing `round_opens` /
`round_closes` moves the boundaries automatically. Nothing hardcodes clock times.

At 21:00 each campaign's power is computed and the winner is **drawn**, weighted by
power. Double the power is roughly 1.87× the odds, never a guarantee. A hidden ±5%
noise term is applied and never shown.

Winners take the losers' pool less a 5% sink: 60% pro-rata by contribution, 40% by a
√-weighted team share scaled by participation maturity. Sustainable losers recover 15%.
Collapsed losers get nothing — that is the single most important rule in the game.

**Cron is one job.** `* * * * * curl -s https://HOST/cron/auto/<token>`. It reads the
clock and does whatever is due. Do **not** schedule `open`/`tick`/`settle` separately —
`auto` is a strict superset and the only one that recovers from a missed open. Full
reasoning in [DEPLOY.md](DEPLOY.md) Step 11.

---

## 4. The bots

100 accounts in `my_users` with `is_bot = 1`, so every gameplay path runs identically
for a bot and a person. There is no separate bot ledger to drift.

| Personality | Count | Behaviour |
|---|---|---|
| rabid | 50 | Never switch, spend hard, high aggression |
| switcher | 30 | Chase whoever is winning; thin, priced loyalty |
| lurker | 20 | Mostly silent, small stakes, often skip a round |

Each draws its own plan at round open — budget, burst count, first wake time — centred
on the middle of the window so activity peaks through daylight (median first action
~09:20) rather than draining overnight. A slice of every personality draws a plan of
zero and sits the round out. Awake bots can hesitate and defer. Tick processes at most
12 due bots per run so one cron pass cannot stall.

---

## 5. Engine authority — read this before touching any formula

`references/prototype.html` and `references/GameEngine-JS.js` are the **design source of
record**. The original whitepaper is lost and obsolete.

`application/libraries/GameEngine.php` is the **ruling implementation**. Where they
disagree, the PHP decides the outcome and the JS is wrong.

Five deliberate departures from the JS, each documented at its method:

1. **Persistence multiplier included.** The old PHP port omitted it, so anyone on a
   streak got different power from the two engines. Generalised to a
   contribution-weighted mean across a campaign's backers.
2. **Population weight computed properly** — the sum of real per-player weights,
   `min(1, contrib_i / avg)`, not a head count.
3. **Conflict sign inversion fixed.** The JS computed `1 + (of - 1) * …` and multiplied
   it into the *defender's* power, so a better-resourced attacker made its target
   **stronger**.
4. **Conflict dampeners fixed.** The JS scaled the multiplier itself toward zero, but
   1.0 means "no effect", not zero — so the multi-attack penalty and a strong defence
   *compounded* damage. Six ordinary attacks took a campaign to 5% of its power in live
   testing. Dampeners now scale the deviation from 1.0.
5. **Reward accounting fixed.** The JS handed winners the full `loserTotal × 0.95` *and*
   refunded sustainable losers 15% — 110% of obligations against 100% of the money,
   minting ~10% of the pot every decisive round. Refunds now come out of the pool first.

6. **Runaway-leader loop broken.** Four constants/functions retuned away from the JS —
   `BASE_POWER_EXP` 0.90 → 0.60, `OUTCOME_MULTIPLIERS['resilient']` 0.15 → 0.35,
   `compute_population_modifiers()` now weighs war chests as well as heads, and
   `BotBrain::seed()` deals campaigns round-robin. See §6 *Balance* for the
   measurements. The JS numbers produce a game that locks to whoever leads first.

Also dropped: §22 token transfers (anti-collusion machinery with no money to collude
over) and the unreachable `LOSER_FLAT_RETURN` branch.

---

## 6. Defects found and fixed

Kept because the same mistakes are easy to reintroduce.

### Economy
- **Double payout under concurrency.** `settle()` read the round status then wrote
  `'resolving'` separately. Two overlapping cron runs could both proceed and pay every
  player twice. Now a compare-and-swap on `WHERE status='open'`, verified with two
  simultaneous requests.
- **Half-paid rounds.** `pay_round()` credited balances one at a time with no
  transaction; a timeout left some paid and some not, and a retry double-paid the first
  group. Settlement is now one transaction — a crash rolls back to `'open'`.
- **Token minting** — see §5 item 5.

### Balance
- **The game locked to whoever led first.** Reported from live play as "very biased
  bots" after one campaign took 25 of 32 rounds. The bots were not the cause.

  `BASE_POWER_EXP` (0.90) compounded with `WIN_CHANCE_EXP` (0.90) made win probability
  scale as `tokens^0.81` — very nearly linear. The only corrective terms in the entire
  chain, `OVERPOP_CAP` (0.35) and `UNDERDOG_CAP` (0.40), saturate at about ±40%, so
  past a ~1.2× lead the correction could never catch the advantage. Every round's
  winnings bought the next round's odds.

  Worse, the corrective term could not see the problem. `compute_population_modifiers()`
  keyed on `$wp`, and `$wp` is a headcount by construction: the per-member term is
  `min(1, c/avg)`, and *without* that cap the sum is exactly `total/avg = n` for any
  distribution — so the cap is the only thing separating `$wp` from `n`, and it only
  ever pushes down. Two campaigns with 30 members each read as "balanced" and neither
  was touched, even when one fielded ten times the war chest.

  Measured over 8 × 300-round simulations against the real engine
  (`compute_*` called directly, bots replicated from `BotBrain`):

  | | shipping | fixed |
  |---|---|---|
  | top campaign's share of wins | 51.9% | 36.8% |
  | longest single-campaign streak | 39.6 rounds | 8.4 rounds |
  | **P(richest campaign wins the round)** | **75.5%** | **39.7%** |
  | leader's share of all tokens | 79.7% | 49.0% |

  Each change alone, for future tuning:

  | change | top share | streak | richest-wins |
  |---|---|---|---|
  | *(shipping)* | 51.9% | 39.6 | 75.5% |
  | round-robin bot seeding | 45.7% | 36.9 | 76.2% |
  | refund 0.15 → 0.35 | 48.2% | 27.1 | 66.7% |
  | population modifier weighs money | 41.3% | 21.4 | 57.4% |
  | `BASE_POWER_EXP` 0.90 → 0.60 | 41.1% | 12.5 | 52.9% |

  Ruled out first: the RNG. 200k `rand_float` draws were uniform to ±0.12%, and
  `draw_winner` returned 33.8/33.2/33.0 on equal chances and exactly 50.0/30.0/20.0 on
  skewed ones — including with campaign 0 placed last in the array, so no ordering bias.

  Money still matters after the fix: allocation shape alone swings power ×1.46 at
  identical spend, so a bad shape needs ×1.88 the tokens to keep up, and an 8× war
  chest still wins. A 1.2–3× lead is now self-correcting rather than compounding.
- **Lopsided bot rosters.** `BotBrain::seed()` rolled `mt_rand(0, 2)` per bot — σ ≈ 4.7
  over 100 draws, so a 42/37/21 opening split is an ordinary outcome. Not cosmetic: the
  50 rabid bots are the heaviest spenders in the game (35–78% of balance per round) and
  nothing ever moves them off their seed campaign, so a lopsided deal is a permanent
  funding advantage handed out before a round is played. Now dealt round-robin, offset
  from the roster already in the table so replenishment keeps the balance.

### Operational
- **Cron responses were cacheable.** Plain GET with a static path; a CDN (this stack
  sits behind Cloudflare) would serve a cached 200 and the game would silently never
  run. Now `no-store`.
- **One unplanned bot re-armed the whole roster.** `tick()` called `plan_round()` on
  spotting any unplanned bot, and that rewrote *every* bot — zeroing `budget_spent` and
  handing all 100 a fresh wallet mid-round.
- **`/cron/open` and `/cron/settle` had no guards.** Opening outside the window created
  a round born already closed; settling had no time awareness at all, so a mis-timezoned
  cron would end every round eight hours early. Both now need `?force=1`.
- **Feed table grew forever** when `auto` was the only job — `prune_feed` was only wired
  into `tick`.

### Front end
- **Inline view scripts ran before `app.js`.** `_foot.php` loaded it at the end of body,
  but views render *before* `_foot` — so `PC.$$` was undefined. Allocate crashed
  outright; register's live username check had been failing silently since it was
  written. `app.js` now loads in `<head>`.
- **Progress bar fills never rendered.** `.camp-fill` is a `<span>` with no `display`
  rule, and inline boxes ignore width and height — every bar was 0×0. Compounded by
  arbitrary scaling (`× 2.4`, `× 320`) that made the numbers meaningless anyway.
- **Feed was oldest-first on first paint** but newest-prepended afterwards, so you
  scrolled down to read and new events appeared off-screen above.
- **Double scroll compensation.** Chrome's native scroll anchoring plus the manual
  correction moved the reader twice as far as the content shifted. Fixed with
  `overflow-anchor:none` — Safari has no scroll anchoring, so deleting the manual
  correction instead would have broken iOS.
- **Session fixation** on login, registration and email verification.
- **Grid row inflation.** `.ck-left` spans both desktop grid rows and is the tallest
  element, so two implicit `auto` rows split its excess — inflating the radar row 94px
  past the radar and leaving a dead band above the feed. Fixed with
  `grid-template-rows:auto 1fr`.

---

## 7. Outstanding

### Must do before real users

`application/config/config.php` is still on **development defaults**. All six are
documented with line numbers in [DEPLOY.md](DEPLOY.md) Step 6:

| Setting | Now | Should be |
|---|---|---|
| `ENVIRONMENT` (index.php) | `development` | `production` |
| `log_threshold` | `4` (logs everything) | `1` — will fill the disk otherwise |
| `encryption_key` | moved to `secrets.php` | a fresh key per server |
| `sess_save_path` | `sys_get_temp_dir()` | a private dir outside `public_html` |
| `cookie_secure` / `cookie_httponly` | `FALSE` | `TRUE` |

Also: `db/schema.sql` still seeds `cron_token` as `CHANGE-ME-…`. The live DB has a real
one; a fresh install must change it.

### Known gaps, not blocking

- **No login rate limiting.** Unlimited password attempts. Needs an attempt store keyed
  on IP + username.
- **Email/username enumeration.** `auth/check_email` and `auth/check_username` are open
  oracles. They exist for the inline signup validation. Low severity with no money in
  the system, but real.
- **CSRF is off.** Every state-changing endpoint requires a session and is called
  same-origin; worst case is being tricked into allocating your own tokens. Turning it
  on means threading the token through the `fetch` calls in `app.js`.
- **`/api/state` costs 10 queries per poll.** At 4s intervals: 10 clients → 25 q/s,
  50 clients → 125 q/s. Fine now, will hurt on shared hosting. Every client gets the
  same board, so a few-second server-side cache on the projection is the fix.
- **`verify()` logs the visitor in from a URL token.** Convenient; a leaked link is an
  account takeover.
- **No admin surface.** Tuning is phpMyAdmin on `pc_settings`.
- **No automated tests.** Everything below was verified by hand.

### Verified vs not

**Verified:** token conservation through settlement (economy can only deflate);
concurrent settlement; nav and layout geometry at 375 / 654 / 926 / 1280px; script load
order; feed ordering and scroll anchoring; cache-busting propagation; the full
register → choose → allocate → scan → pulse → settle loop.

**Not verified:** any real mobile device (viewport emulation only); Safari / iOS
anything; load beyond a handful of concurrent requests; email delivery from the live
host; a real 21:00 settlement fired by cron rather than by hand.

**Partly verified — the balance fix.** The 300-round numbers in §6 come from a
simulation that calls the real `GameEngine` methods but holds `conflict_product` at
1.0, i.e. **no attacks**. It isolates the money/size feedback loop, which is what was
broken, but it does not model attack and defence pressure. Live rounds do resolve
conflicts (10 in the settlement used to smoke-test the change). The direction and
rough magnitude should hold — conflicts are a per-round multiplier bounded to
0.85–1.25, not a compounding term — but the exact percentages will move. Re-measure
against real settled rounds once a week of live play has accumulated; the query to
use is win counts by `winner_campaign` in `pc_rounds` joined against each round's
`total_all` spread in `pc_round_campaign`. The simulation harness is disposable and
was not kept in the repo.

---

## 8. Gotchas that cost real time

**Service worker caching.** Assets are busted by `pc_asset()`, which appends each file's
`filemtime` — so a normal deploy needs **no version bump anywhere**. `CACHE_VERSION` in
`sw.js` only matters when `sw.js` itself changes. I originally built a redundant
`PC_ASSET_VER` constant next to the existing helper; it is gone. If you add a manual
version again, you have reintroduced the bug.

**`database.php` holds live credentials.** Swapping it to run locally and forgetting to
swap back will ship broken config. Verify with `md5sum` before uploading.

**The browser preview pane does not composite.** `requestAnimationFrame` returns zero
frames, CSS transitions freeze at frame 0, and screenshots fail. Computed styles and
`getBoundingClientRect` still work. Several "bugs" here were the pane, not the code —
check whether an animation is simply frozen before chasing it.

Canvas is the exception and it is how the radar was verified: drawing updates the
backing store whether or not the pane paints, so shim `requestAnimationFrame` onto
`setTimeout`, then read the frame out with `canvas.toDataURL()` and POST it somewhere
you can open. Composite it onto an opaque rectangle first — the canvas is transparent
where nothing was drawn, and a PNG of that on a white viewer looks nothing like the
panel on `--abyss`.

**Partial uploads bite.** `_head.php` / `_foot.php` / `constants.php` had a cross-file
dependency that only worked in one upload order. When two files must ship together, say
so and say which goes first.

The radar is now three files with the same problem: **`phl-geo.js`, then `phl-map.js`,
then `cockpit.js`** — geo defines the table, map reads it at parse time, cockpit asks
map for a projection. `_foot.php` emits them in that order as plain sync tags; do not
add `defer` or `async`. Ship all three or none. If `phl-map.js` is missing the panel
degrades to a bare-plane radar rather than going blank, but that is a safety net, not
a deploy state to leave it in.

**XAMPP on this machine is broken independently of the project.** It was moved from `E:`
to `D:` and `my.ini` / `php.ini` still point at the old drive, so MySQL and PHP will not
start from the control panel. Workarounds live in the scratchpad, not in the repo.

---

## 9. Working on it

```bash
php -n -l <file>                    # lint PHP without the broken ini
node --check assets/js/cockpit.js   # lint JS
php tools/make-icons.php            # regenerate PWA icons
```

Local dev needs MySQL started with corrected paths and PHP served with a fixed `php.ini`
(both documented in the scratchpad). Then swap `database.php` to `root` / `politiclash`,
hit `/cron/seed/<token>` once, and `/cron/tick/<token>` a few times to populate a board.

**Do not ship:** `references/` (636K, design source), `db/`, `tools/`. The root
`.htaccess` blocks the first two at document root, but that rule does not match under a
subdirectory — the real protection is not uploading them.

---

## 10. Where to look first

| Question | File |
|---|---|
| Why did a round settle that way? | `GameEngine.php` — every method carries its § marker |
| Why did a bot do that? | `BotBrain.php::tick()` |
| What does the cockpit poll? | `Api.php::state()` → `M_round::project()` |
| How does a round open/close? | `M_round::open_today()` / `settle()` |
| How is the page assembled? | `MY_Controller::view()` → `_head` → view → `_foot` |
| Game rules in plain English | `views/pages/about.php` and `faq.php` — written for players |

The About and FAQ pages are the most readable description of the design that exists.
Start there if you are new to the game itself.
