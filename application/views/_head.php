<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$o = $opts;
// $has_nav   the bottom nav bar is present
// $show_back the header shows a back arrow instead of the logo — ONLY when
//            there is no nav to navigate with. If the nav is there, the header
//            is identical to the war room's: logo left, clock right.
$has_nav   = !empty($o['nav']);
$is_drill  = !empty($o['back']);
$show_back = $is_drill && !$has_nav;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,maximum-scale=1,user-scalable=no">
<title><?= html_escape($o['title']) ?> · <?= PC_NAME ?></title>
<meta name="description" content="PolitiClash — a live three-way political campaign simulation. Allocate, out-manoeuvre, and survive the round.">

<!-- PWA -->
<link rel="manifest" href="<?= base_url('manifest.json') ?>">
<meta name="theme-color" content="#050914">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="PolitiClash">
<link rel="apple-touch-icon" href="<?= base_url('icons/icon-192.png') ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= base_url('icons/icon-32.png') ?>">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;800;900&family=Chakra+Petch:wght@400;500;600;700&family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<link rel="stylesheet" href="<?= pc_asset('assets/css/phosphor.css') ?>">
<link rel="stylesheet" href="<?= pc_asset('assets/css/theme.css') ?>">

<?php /* MERGE, never replace. app.js also populates window.PC; a bare
         `window.PC = {...}` here would wipe whichever one loaded first. */ ?>
<script>window.PC = Object.assign(window.PC || {}, {
  base: <?= json_encode(base_url()) ?>,
  me: <?= $me ? json_encode([
        'id' => (int)$me->user_id,
        'username' => $me->username,
        'campaign_id' => $me->campaign_id === null ? null : (int)$me->campaign_id,
      ]) : 'null' ?>
});</script>

<?php /* app.js loads HERE, in the head, deliberately not at the end of body.
         Views render between _head and _foot, so any inline <script> in a view
         (allocate, register, choose) executes BEFORE a footer script tag would
         — calling PC.$$ / PC.$ / PC.get on an object that only had `base` and
         `me` on it. Allocate died outright; register's live username check had
         been failing silently since it was written.
         app.js is safe here: every DOM reference it makes is inside an event
         handler or guarded, so nothing needs the body to exist yet. */ ?>
<script src="<?= pc_asset('assets/js/app.js') ?>"></script>
</head>
<body class="<?= html_escape(trim($o['body_class'].(!empty($o['pads']) ? ' has-pads' : ''))) ?>">
<div class="scan" aria-hidden="true"></div>

<header class="hdr">
<?php if ($show_back): ?>
  <?php /* Only pages WITHOUT the bottom nav get a back arrow — sign-up, log in,
           the campaign picker. Everywhere else the nav is the way out, so the
           header carries the logo instead. */ ?>
  <a href="<?= html_escape($o['back']) ?>" class="hdr-btn" aria-label="Back"><i class="ph ph-arrow-left"></i></a>
  <div class="hdr-title"><?= html_escape($o['title']) ?></div>
  <div class="spacer"></div>
  <a href="<?= base_url() ?>" class="hdr-btn" aria-label="War room"><i class="ph ph-crosshair"></i></a>
<?php else: ?>
  <a href="<?= base_url() ?>" class="brand">
    <span class="mark"><i class="ph-fill ph-crosshair"></i></span>
    <span class="wordmark">POLITI<i>CLASH</i></span>
  </a>
  <div class="spacer"></div>
  <div class="hdr-clock" id="hdr-clock">
    <i class="ph ph-clock"></i><span id="hdr-clock-txt">--:--:--</span>
  </div>
<?php endif; ?>
</header>

<main class="page<?= $has_nav ? '' : ' no-nav' ?><?= !empty($o['cockpit']) ? ' has-statbar' : '' ?>">

<?php if (!empty($o['cockpit'])): ?>
<?php /* Live readout strip, flush under the header.

         It lives inside .page but OUTSIDE .shell, and that placement is the
         whole trick: .shell is what caps the width at 1280 and pads the sides,
         so anything inside it can only go edge-to-edge by fighting back out
         with `margin-inline:calc(50% - 50vw)` — and 100vw counts the scrollbar,
         which would have put a permanent horizontal scroll on the cockpit.
         Sitting one level up costs nothing and is exact.

         These six numbers used to float over the radar as two glass panels.
         They covered the north of Luzon and the Visayas — the part of the map
         the campaigns are actually fought over — to show six values that fit in
         a strip. cockpit.js addresses them by id through setText(), so moving
         the ids here needed no JS change at all.

         A guest has no wallet: those three lines read "guest" and two em dashes,
         so they are dropped rather than shown empty. Climate is board state and
         is just as true for a spectator, so it always renders. */ ?>
  <div class="statbar" id="statbar" role="group" tabindex="0"
       aria-label="Live board readout">
    <div class="statbar-track">
      <?php if ($me): ?>
      <div class="sb-cell">
        <i class="ph ph-wallet"></i><span class="k">Balance</span>
        <span class="v teal" id="hud-balance">&mdash;</span>
      </div>
      <div class="sb-cell">
        <i class="ph ph-coins"></i><span class="k">Placed</span>
        <span class="v" id="hud-placed">&mdash;</span>
      </div>
      <div class="sb-cell">
        <i class="ph ph-flag"></i><span class="k">Side</span>
        <span class="v" id="hud-side">&mdash;</span>
      </div>
      <?php endif; ?>
      <div class="sb-cell">
        <i class="ph ph-fire"></i><span class="k">Aggression</span>
        <span class="v" id="hud-aggr">&mdash;</span>
      </div>
      <div class="sb-cell">
        <i class="ph ph-handshake"></i><span class="k">Peace bonus</span>
        <span class="v teal" id="hud-peace">&mdash;</span>
      </div>
      <div class="sb-cell" title="Conflicts in flight">
        <i class="ph ph-sword"></i><span class="k">In flight</span>
        <span class="v" id="hud-conflicts">&mdash;</span>
      </div>
    </div>
  </div>
<?php endif; ?>

  <div class="shell">
