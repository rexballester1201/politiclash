<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -----------------------------------------------------------------------------
| PolitiClash — routes
| -----------------------------------------------------------------------------
| Four areas: a public cockpit, auth, the game, and cron.
|
| Page conventions
|   · "/"                 cockpit — works in guest mode, no login required
|   · full pages          carry the fixed bottom nav
|   · drill-down pages    carry a back arrow in the header instead of the nav
*/

$route['default_controller'] = 'home';
$route['404_override']       = 'home/notfound';
$route['translate_uri_dashes'] = FALSE;


// ── Public ───────────────────────────────────────────────────────────────────
$route['about']  = 'home/about';        // GET  drill-down, back arrow
$route['faq']    = 'home/faq';          // GET  drill-down, back arrow


// ── Auth (Auth.php) ──────────────────────────────────────────────────────────
$route['register']         = 'auth/register';         // GET  form
$route['login']            = 'auth/login';            // GET  form
$route['logout']           = 'auth/logout';           // GET
$route['verify/(:any)']    = 'auth/verify/$1';        // GET  email link
$route['resend']           = 'auth/resend';           // GET  re-send verification
// POST auth/do_register · auth/do_login · auth/check_username · auth/check_email


// ── Game (Game.php) ──────────────────────────────────────────────────────────
$route['play']      = 'game/index';       // GET  cockpit, authenticated
$route['choose']    = 'game/choose';      // GET  pick a campaign
$route['allocate']  = 'game/allocate';    // GET  drill-down: allocation console
$route['account']   = 'game/account';     // GET  drill-down
$route['advisor']   = 'game/advisor';     // GET  drill-down: strategist + council
$route['intel']     = 'game/intel';       // GET  drill-down: events, scans, opportunities
$route['threats']   = 'game/threats';     // GET  drill-down: incoming + conflict queue
$route['history']   = 'game/history';     // GET  drill-down: round history
// POST game/join · game/confirm · game/scan · game/pulse · game/signal · game/attack


// ── Real-time state API (Api.php) ────────────────────────────────────────────
// The cockpit polls state; everything else is a command.
$route['api/state']     = 'api/state';        // GET  full snapshot (guest-safe)
$route['api/feed']      = 'api/feed';         // GET  ?since=<id> incremental ticker
$route['api/leaders']   = 'api/leaders';      // GET  leaderboard strip


// ── Cron (Cron.php) — token-gated, see pc_settings.cron_token ────────────────
// * * * * *      curl -s https://HOST/cron/tick/<token>      (bots + conflicts)
// 5 6 * * *      curl -s https://HOST/cron/open/<token>      (open the round)
// 1 21 * * *     curl -s https://HOST/cron/settle/<token>    (settle the round)
$route['cron/(:any)/(:any)'] = 'cron/$1/$2';


// ── PWA ──────────────────────────────────────────────────────────────────────
$route['offline']       = 'home/offline';
