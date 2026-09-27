# Deploying PolitiClash to cPanel

Written against a standard cPanel account with MySQL, MultiPHP Manager, Cron Jobs and
AutoSSL. Roughly 30 minutes end to end.

Two values you will need, generated fresh for this server — never reuse them from
another install:

```bash
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"   # encryption_key (Step 5)
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # cron_token (Step 10)
```

---

## Step 1 — Build the upload bundle

On your machine, zip the project **excluding** the folders below. They are either
design sources, one-off tooling, or local noise, and two of them must never be public.

| Exclude | Why |
|---|---|
| `references/` | 636 KB of design source — the prototype and engine spec. Not needed at runtime, and you do not want it readable. |
| `tools/` | The icon generator. Already run; the PNGs in `icons/` are the output. |
| `db/` | Import `schema.sql` through phpMyAdmin instead (Step 3). |
| `application/logs/*.php` | Local development logs. Keep the empty folder. |
| `application/config/secrets.php` | Your local credentials, if you have one. The server gets its own in Step 5. |
| `pages/` | Empty and unused. |

Everything else goes up: `application/`, `system/`, `assets/`, `icons/`, `index.php`,
`.htaccess`, `manifest.json`, `sw.js`, `README.md`.

That is about **3 MB**. Zip it as `politiclash.zip`.

> On Windows, select all, right-click → Send to → Compressed folder, then delete the
> four excluded folders from inside the zip.

---

## Step 2 — Create the database

cPanel → **MySQL® Databases**

1. **New Database:** `politiclash` → Create.
   cPanel prefixes it, so the real name becomes something like `myacct_politiclash`.
   **Write down the full prefixed name.**
2. **New User:** username `pcuser`, and use the password generator.
   Real name becomes `myacct_pcuser`. **Write down the full name and the password.**
3. **Add User To Database:** pick both, click Add, tick **ALL PRIVILEGES**, Make Changes.

You now have three values for Step 5:

```
database : myacct_politiclash
username : myacct_pcuser
password : (whatever you generated)
```

---

## Step 3 — Import the schema

cPanel → **phpMyAdmin** → select `myacct_politiclash` in the left sidebar → **Import**
tab → Choose File → `db/schema.sql` → Go.

You should see 15 tables plus one view (`pc_v_leaderboard`).

If the import errors on `SET time_zone`, your host disallows it — delete that one line
from the top of the file and re-import. The timezone is pinned in PHP anyway.

---

## Step 4 — Upload the files

cPanel → **File Manager**

**For a main domain** (`https://yourdomain.com`) → upload into `public_html/`
**For a subdomain** — create it first in cPanel → Domains → Subdomains, then upload
into that subdomain's document root
**For a subfolder** (`https://yourdomain.com/politiclash`) → create the folder inside
`public_html/` and upload there

Then: Upload `politiclash.zip` → back in File Manager, right-click it → **Extract** →
delete the zip.

Make sure `index.php` and `.htaccess` sit at the **top level** of that folder, not
inside a nested `politiclash/` subfolder. If the extract nested them, select all and
Move them up one level.

> `.htaccess` is a hidden file. In File Manager click **Settings** (top right) and tick
> *Show Hidden Files* or you will not see it.

---

## Step 5 — Point the app at your database

In `application/config/`, copy `secrets.example.php` to `secrets.php` (File Manager →
right-click → Copy), then edit `secrets.php`:

```php
'db_hostname'    => 'localhost',
'db_username'    => 'myacct_pcuser',
'db_password'    => 'your-generated-password',
'db_database'    => 'myacct_politiclash',
'encryption_key' => 'the 32 hex characters from the top of this guide',
```

`localhost` is correct on virtually every cPanel host — do not change it.
`database.php` and `config.php` read this file; it never goes into version control.

You do **not** need to set `base_url`. It auto-detects from the request, so the same
files work on a domain, a subdomain or a subfolder.

---

## Step 6 — Production hardening

These matter. The project inherits a config from an older platform and the defaults are
development defaults.

### 6a. Switch to production mode

Edit `index.php`, line ~57:

```php
define('ENVIRONMENT', isset($_SERVER['CI_ENV']) ? $_SERVER['CI_ENV'] : 'production');
```

Changing `'development'` → `'production'` hides PHP errors from visitors. Do this only
**after** Step 12 confirms the site works, or you will debug a blank page with no
message.

### 6b. The encryption key

It lives in `application/config/secrets.php` (Step 5), which `config.php` reads, so
there is nothing to change in `config.php`. Use a fresh key for every server.

### 6c. Turn down logging

Line 230. It is currently `4`, which logs every debug line of every request and will
fill your disk quota within days:

```php
$config['log_threshold'] = 1;   // errors only
```

### 6d. Give sessions a private directory

Line 387 currently uses `sys_get_temp_dir()` — on shared hosting that is a shared
`/tmp` that other accounts may be able to read, and cPanel's cleanup can wipe it and
log everyone out mid-round.

Create a folder **outside** `public_html`, e.g. `/home/myacct/pc_sessions`, then:

```php
$config['sess_save_path'] = '/home/myacct/pc_sessions';
```

Set that folder to permissions **0700**.

### 6e. Secure the cookies (after SSL is live)

Lines 410–411:

```php
$config['cookie_secure']   = TRUE;
$config['cookie_httponly'] = TRUE;
```

### 6f. Optional — SameSite cookies

CodeIgniter 3.1.9 predates the `cookie_samesite` config option. If you want it, add
this one line near the top of `application/config/constants.php`, just under the
`date_default_timezone_set` call:

```php
ini_set('session.cookie_samesite', 'Lax');
```

> **Known item:** `csrf_protection` is `FALSE`. Every state-changing endpoint requires
> a logged-in session and is called same-origin, and there is no money in the system —
> the worst case is being tricked into allocating your own tokens. Turning CSRF on
> requires adding the token to the `fetch` calls in `assets/js/app.js`; ask if you want
> that wired.

---

## Step 7 — Permissions

In File Manager, right-click → Change Permissions:

| Path | Mode |
|---|---|
| `application/logs/` | 0755 (writable by the PHP user) |
| `application/cache/` | 0755 |
| Everything else | 0644 files / 0755 folders |

Never set anything to 777 on shared hosting.

---

## Step 8 — Set the PHP version

cPanel → **MultiPHP Manager** → tick your domain → choose **PHP 8.1** or **8.2** → Apply.

Anything from **7.4 to 8.2** works. The app uses no PHP 8-only syntax, and CodeIgniter
3.1.9 is actually cleaner on 7.4 (on 8.2 its core emits dynamic-property deprecations,
which `index.php` suppresses). It needs `mysqli`, `mbstring` and `json`, all standard.
Do not use PHP 8.3+ — CodeIgniter 3.1.9 has not been validated there.

One difference worth knowing: on PHP 7.x an undefined constant is a **warning** and the
page still renders; on PHP 8.x it is a **fatal error**. So a half-finished upload that
only logs noise on 7.4 will take the site down on 8.x.

---

## Step 9 — Enable HTTPS

cPanel → **SSL/TLS Status** → tick your domain → **Run AutoSSL**.

This is **not optional**. Service workers only register over HTTPS, so without a
certificate the app still runs but the PWA install prompt and offline shell will
silently never appear.

Once the certificate is live, force HTTPS: cPanel → **Domains** → toggle *Force HTTPS
Redirect*.

---

## Step 10 — Change the cron token

phpMyAdmin → your database → `pc_settings` table → **Edit** the `cron_token` row and
paste the 64-character token you generated at the top of this guide.

Anyone who learns this token can trigger settlement early. Treat it like a password.

While you are there, the same table holds the faucet knobs if you want to tune them:
`starting_balance`, `stipend_floor`, `stipend_target`, `bot_count`, `bots_enabled`.

---

## Step 11 — Schedule the cron job

cPanel → **Cron Jobs**. Set your notification email at the top so failures reach you.

**Add one job.** Replace `YOURDOMAIN` and `TOKEN`:

```
* * * * *  curl -s "https://YOURDOMAIN/cron/auto/TOKEN" > /dev/null 2>&1
```

That is the whole setup. `auto` looks at the clock on each run and does whatever is
due — opens the round at the start of the window, moves the bots and resolves conflicts
while it is open, settles once it closes, and does nothing outside those times.

### Why one job and not three

There are separate `/cron/open`, `/cron/tick` and `/cron/settle` endpoints, and you can
schedule them individually if you prefer. You should not, for two reasons.

**`auto` already does all three.** It is a strict superset, not an alternative:

| endpoint | seeds bots | opens round | resolves conflicts | moves bots | settles |
|---|:--:|:--:|:--:|:--:|:--:|
| `/cron/open` | yes | yes | | | |
| `/cron/tick` | | | yes | yes | yes |
| `/cron/settle` | | | | | yes |
| **`/cron/auto`** | **yes** | **yes** | **yes** | **yes** | **yes** |

**The three-job setup cannot recover from a missed open.** `tick` deliberately does
nothing when no round is open, so if the server is down at 00:05 — or the timezone is
off by an hour, or you fat-finger the schedule — the game is dead for the entire day
and the every-minute job just idles:

```
/cron/tick   →  {"ok":true,"idle":"no_open_round"}      rounds: 0
/cron/auto   →  {"ok":true,"opened":{"round_no":1,...}} rounds: 1
```

`auto` opens the round on its next run instead. Same for settlement: if it is late, the
next `auto` closes the round out.

Use the individual endpoints only for manual intervention — for example hitting
`/cron/settle/TOKEN` in a browser to force a round closed for testing.

### If your host blocks per-minute cron

Every 5 minutes still works. Bots move in slightly larger clumps and conflicts land up
to 5 minutes late; neither breaks anything:

```
*/5 * * * *  curl -s "https://YOURDOMAIN/cron/auto/TOKEN" > /dev/null 2>&1
```

Do not stretch it much past that — bots wake on their own schedule and their actions
queue up behind the cron, so a 30-minute interval makes the board lurch rather than
move.

### Timezone

This is the part worth checking. The game pins itself to **Asia/Manila** in PHP, but
cPanel's cron fires on the **server's** timezone. Check cPanel → **Server Information**
→ *Server Time*.

With the single per-minute `auto` job this does not matter — it runs constantly and
reads the game's own clock, so a server on UTC behaves identically to one on Manila
time. That is the third reason to use it.

It only matters if you schedule the individual endpoints, where the times must be
converted (Manila is UTC+8, so 00:05 Manila is 16:05 UTC the previous day).

---
## Step 12 — First run

Visit once in a browser:

```
https://YOURDOMAIN/cron/seed/TOKEN
```

You should get JSON like:

```json
{ "ok": true,
  "bots": { "created": 100, "total": 100 },
  "round": { "ok": true, "round_id": 1, "round_no": 1 } }
```

That creates the 100-bot roster (50 rabid, 30 switchers, 20 lurkers) and opens round 1.
**Run it once only** — it is safe to re-run, but it will not create a second round the
same day.

If you seed outside the 00:00–21:00 window the round still opens; it will simply be in
its closed phase until the next morning.

---

## Step 13 — Verify

Work through this list:

- [ ] `https://YOURDOMAIN/` shows the war room with live numbers
- [ ] `https://YOURDOMAIN/api/state` returns JSON with three campaigns
- [ ] The activity feed fills up over a few minutes as bots act
- [ ] `/about` and `/faq` load
- [ ] Register an account — you get 100,000 tokens and land on the campaign picker
- [ ] Pick a side, allocate some tokens, confirm — the balance drops
- [ ] `https://YOURDOMAIN/references/` returns **404 or 403** (see the note below)
- [ ] Chrome on Android offers "Add to Home screen"
- [ ] Padlock icon in the address bar

Then go back and do **Step 6a** — switch `ENVIRONMENT` to `production`.

> **Subfolder installs:** the `RedirectMatch` rule in `.htaccess` that blocks
> `references/` and `db/` is written for a document-root install and will not match
> under a subfolder path. This is why Step 1 has you leave those folders out of the
> upload entirely — that is the real protection. Verify the checklist item above and,
> if either folder is reachable, delete it from the server.

---

## Updating later

1. Upload the changed files. **Upload views before config.** `_head.php` and
   `_foot.php` call `pc_asset()`; older copies referenced a `PC_ASSET_VER` constant that
   no longer exists. New views + old config works fine (the constant is simply unused);
   old views + new config throws `Use of undefined constant PC_ASSET_VER` on every page.
2. Nothing else. CSS and JS carry their own `filemtime` in the URL, so a changed file
   is a changed URL and every cache in the chain re-fetches it automatically.
3. Only bump `CACHE_VERSION` in `sw.js` if you edited `sw.js` itself.

---

## Troubleshooting

**Blank white page**
`ENVIRONMENT` is `production` and something fatal happened. Set it back to
`development` temporarily, reload, read the error, fix, set it back.

**"Unable to connect to your database server"**
The credentials in `secrets.php` are missing the cPanel prefix. It is
`myacct_politiclash`, not `politiclash`. Also confirm the user was actually added to
the database with ALL PRIVILEGES in Step 2.3.

**Every page 404s except the homepage**
`mod_rewrite` is off, or `.htaccess` did not upload. Confirm the file exists at the
top level (turn on hidden files). If your host disables `.htaccess` overrides, ask
support to enable `AllowOverride All`.

**404s on a subfolder install**
Add this line to `.htaccess` right after `RewriteEngine On`:
```apache
RewriteBase /politiclash/
```

**The round never opens / nothing happens**
The cron is not firing. cPanel → Cron Jobs shows the last run. Test by pasting the
`auto` URL straight into a browser — if that works, the schedule or timezone is wrong,
not the app.

**"Forbidden" from a cron URL**
The token in the URL does not match `pc_settings.cron_token`.

**PWA install prompt never appears**
No valid HTTPS certificate, or you dismissed it once (it remembers). Clear site data
and reload. It also waits 12 seconds by design before asking.

**Bots are not doing anything**
Check `bots_enabled` is `1` in `pc_settings`, and that a round has `status = 'open'` in
`pc_rounds`. The tick cron only acts on an open round.

**Emails not arriving**
The app uses PHP `mail()` via CodeIgniter's email library. Many hosts rate-limit or
block it. Verification does not gate play — a new account can pick a side and start
immediately — so this is cosmetic until you wire SMTP in
`application/config/email.php`.
