# PolitiClash

A live three-way political campaign simulation. One round per day, 00:00 → 21:00
(Asia/Manila). Players split a token budget across ten campaign systems, read the
board, and find out at the count whose machine held together.

Built on CodeIgniter 3. Tokens are game currency: there is no purchase path, no
wagering and no cash value.

**A simulation, not an endorsement.** The three campaigns carry the names of real
Philippine politicians — LENI, SARA and MARCOS — as labels for three sides with
identical rules. PolitiClash is not affiliated with, sponsored by or endorsed by any
of them or by any party, and nothing in it models a real person's platform, conduct
or record.

---

## Setup

### 1. Database

```bash
mysql -u root -e "CREATE DATABASE politiclash CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root politiclash < db/schema.sql
```

Then copy `application/config/secrets.example.php` to `secrets.php` and fill in the
database credentials and a fresh encryption key. Without it the app uses a local
XAMPP database: localhost, root, no password, `politiclash`.

### 2. Change the cron token

```sql
UPDATE pc_settings SET sval = 'your-long-random-string' WHERE skey = 'cron_token';
```

### 3. Generate the PWA icons (already done, re-run if you change the mark)

```bash
php tools/make-icons.php
```

Delete `tools/` before going to production.

### 4. Cron

One job, every minute. It reads the clock and does whatever is due — opens the round,
moves the bots, resolves conflicts, settles at close.

```
* * * * *  curl -s https://YOUR-HOST/cron/auto/<token> >/dev/null
```

There are separate `open` / `tick` / `settle` endpoints, but `auto` is a superset of all
three and is the only one that recovers from a missed open — `tick` idles when no round
is open, so a server down at midnight would leave the game dead all day. Keep the
individual endpoints for manual intervention.

First run: hit `/cron/seed/<token>` once. It creates the 100-bot roster and opens
round 1.

---

## How a day works

| Time | Phase | Contribution weight |
|---|---|---|
| 00:00 – 10:30 | early | ×1.000 |
| 10:30 – 17:51 | mid | ×1.050 — the sweet spot |
| 17:51 – 21:00 | late | ×0.85–0.95, drawn fresh at each open, plus a per-player cap |

At 21:00 each campaign's power is computed and the winner is **drawn**, weighted by
power. Double the power is roughly 1.87× the odds, never a guarantee. A hidden ±5%
variance is applied and never shown.

---

## Layout

```
application/
  libraries/GameEngine.php    the ruling implementation of every formula
  libraries/BotBrain.php      the synthetic electorate
  models/M_user.php           accounts, allegiance, progression, the faucet
  models/M_round.php          round lifecycle and settlement
  models/M_play.php           contributions, conflicts, scans, pulses, feed
  controllers/Home.php        cockpit + public pages
  controllers/Auth.php        register, verify, login
  controllers/Game.php        player pages + JSON commands
  controllers/Api.php         /api/state, /api/feed, /api/leaders
  controllers/Cron.php        open, tick, settle, auto, seed
assets/css/theme.css          HOAXSNOWDEN tokens
assets/css/phosphor.css       icon subset (no emoji anywhere)
assets/js/cockpit.js          polling, rendering, radar
db/schema.sql
sw.js, manifest.json, icons/  PWA shell
references/                   design source of record — keep, do not ship
```

The cockpit polls `/api/state` every 4s and `/api/feed` every 3s. Between polls the
displayed numbers ease toward their targets on each animation frame, so the console
reads as continuously alive; the values themselves are always server truth.

---

## Engine authority

`references/prototype.html` and `references/GameEngine-JS.js` are the **design source
of record**. `application/libraries/GameEngine.php` is the **ruling implementation** —
where they disagree, the PHP decides the outcome.

Five deliberate departures from the JS, each documented at its method:

1. **Persistence multiplier is included.** The old PHP port omitted it entirely, so
   anyone on a win or loss streak got different power from the two engines. Generalised
   to a contribution-weighted mean across each campaign's backers, because "the player's
   campaign" does not mean anything with a real roster.
2. **Population weight is computed properly** — the sum of real per-player weights,
   `min(1, contrib_i / avg)`, not a raw head count.
3. **Conflict sign inversion fixed.** The JS computed `1 + (of - 1) * …` and multiplied
   it into the *defender's* power, so a better-resourced attacker made its target
   stronger. The sign is flipped.
4. **Conflict dampeners fixed.** The JS returned `ci * map * (1 - effDef*0.5) * atkMod`,
   scaling the multiplier toward zero — but 1.0 means "no effect", not zero, so the
   multi-attack penalty and a strong defence *compounded* damage instead of reducing it.
   Six ordinary attacks took a campaign to 5% of its power in live testing. Dampeners
   now scale the deviation from 1.0.
5. **Reward accounting fixed.** The JS handed winners the full `loserTotal × 0.95` *and*
   separately refunded sustainable losers 15% — obligations of 110% against 100% of the
   losing side's money, minting ~10% of the pot every decisive round. Refunds now come
   out of the pool before winners divide it. The economy can only deflate.

Also dropped: §22 token transfers (an anti-collusion system with no money to collude
over) and the unreachable `LOSER_FLAT_RETURN` branch.

---

## The bots

100 accounts in `my_users` with `is_bot = 1`, so every gameplay path runs identically
for a bot and a person.

| Personality | Count | Behaviour |
|---|---|---|
| rabid | 50 | Never switch, spend hard, high aggression |
| switcher | 30 | Chase whoever is winning; thin, priced loyalty |
| lurker | 20 | Mostly silent, small stakes, often skip a round |

Each draws its own plan at round open: how much to commit, in how many bursts, and
when the first lands. Wake times are spread across the whole 21-hour window and centred
on its middle, so activity peaks through daylight (median first action ~09:20) rather
than draining overnight. A slice of every personality draws a plan of zero and sits the
round out.
Awake bots can still hesitate and push their next action back. They switch sides, open
conflicts, and broadcast signals — a quarter of which are bluffs.

Tuning lives in `pc_settings`: `bot_count`, `bots_enabled`.

---

## The faucet

Without a money layer the economy is closed and leaks 5% per round, so every balance
would drift to zero and lock the player out permanently. At each round open, anyone
below `stipend_floor` (default 20,000) is topped up to `stipend_target` (default
60,000). Bots get a randomised amount so they do not move in lockstep. A bad run costs
a round, not the game.

---

## Notes

- **Do not serve `references/` or `db/`.** The `.htaccess` blocks both.
- CodeIgniter 3.1.x predates PHP 8.2 and its core creates dynamic properties
  throughout. `index.php` suppresses `E_DEPRECATED` in development so real errors are
  visible; everything else still surfaces.
- Asset cache-busting is automatic. `pc_asset()` (application/helpers/asset_helper.php,
  autoloaded) appends each file's `filemtime` to its URL, so uploading a changed CSS or
  JS file is the whole deploy step — no version constant to bump and no cache to purge.
  `CACHE_VERSION` in `sw.js` only matters when sw.js itself changes.

---

## Licence

Released under the MIT licence — see [LICENSE](LICENSE).
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md) covers CodeIgniter 3.1.9 and the
Phosphor Icons, both MIT-licensed too.
