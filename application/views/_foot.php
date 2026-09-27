<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$o        = $opts;
$is_drill = !empty($o['back']);   // back arrow in the header
$has_nav  = !empty($o['nav']);    // bottom nav bar — independent of the above
$nav      = $o['nav'];
$guest    = !$me;
// Guests can read everything but not act — the nav sends them to sign-in
// rather than pretending the action is available.
$gate = function ($url) use ($guest) { return $guest ? 'login' : $url; };
?>
  </div><!-- /.shell -->
</main>

<?php if ($has_nav): ?>

<?php if (!empty($o['pads'])): ?>
<!-- floating tactical pads -->
<div class="pads" id="pads">
  <button class="pad atk"  id="pad-attack"  data-act="attack"  type="button">
    <i class="ph-fill ph-sword"></i><span>Attack</span><em class="cost" id="cost-attack">—</em></button>
  <button class="pad def"  id="pad-defend"  data-act="defend"  type="button">
    <i class="ph-fill ph-shield"></i><span>Defend</span><em class="cost" id="cost-defend">—</em></button>
  <button class="pad pol"  id="pad-policy"  data-act="policy"  type="button">
    <i class="ph-fill ph-scroll"></i><span>Push Policy</span><em class="cost">signal</em></button>
  <button class="pad hold" id="pad-hold"    data-act="hold"    type="button">
    <i class="ph ph-hand-fist"></i><span>Hold</span><em class="cost">signal</em></button>
  <button class="pad"      id="pad-scan"    data-act="scan"    type="button">
    <i class="ph-fill ph-magnifying-glass"></i><span>Scan Rival</span><em class="cost" id="cost-scan">—</em></button>
  <button class="pad"      id="pad-offense" data-act="offense" type="button">
    <i class="ph-fill ph-lightning"></i><span>Offense +12%</span><em class="cost" id="cost-offense">—</em></button>
  <button class="pad"      id="pad-defense" data-act="defense" type="button">
    <i class="ph-fill ph-shield-check"></i><span>Defense +12%</span><em class="cost" id="cost-defense">—</em></button>
</div>
<?php endif; ?>

<div class="stack-veil" id="stack-veil"></div>

<!-- GAME stack: Advisor + Intel + Threats -->
<div class="stack anchor-game" id="stack-game">
  <div class="stack-inner">
    <a class="stack-item" href="<?= base_url($gate('advisor')) ?>">
      <i class="ph-fill ph-brain"></i> Advisor
      <span class="sub">strategy · council</span>
    </a>
    <a class="stack-item" href="<?= base_url($gate('intel')) ?>">
      <i class="ph-fill ph-magnifying-glass"></i> Intel
      <span class="sub" id="stk-intel-sub">events · scans</span>
    </a>
    <a class="stack-item" href="<?= base_url($gate('threats')) ?>">
      <i class="ph-fill ph-warning"></i> Threats
      <span class="sub" id="stk-threat-sub">incoming</span>
    </a>
    <?php /* The route and the page already existed; nothing linked to them from
             the nav, so the only way in was the report footer on the war room —
             which is itself only there between rounds. */ ?>
    <a class="stack-item" href="<?= base_url($gate('history')) ?>">
      <i class="ph-fill ph-clock"></i> History
      <span class="sub">settled rounds · results</span>
    </a>
  </div>
</div>

<!-- INFO stack: Logout + FAQ + About, stacked above the Info item -->
<div class="stack anchor-info" id="stack-info">
  <div class="stack-inner">
    <?php if ($guest): ?>
      <a class="stack-item" href="<?= base_url('register') ?>"><i class="ph-fill ph-user-plus"></i> Sign up</a>
      <a class="stack-item" href="<?= base_url('login') ?>"><i class="ph-fill ph-sign-in"></i> Log in</a>
    <?php else: ?>
      <a class="stack-item danger" href="<?= base_url('logout') ?>"><i class="ph-fill ph-sign-out"></i> Logout</a>
    <?php endif; ?>
    <a class="stack-item" href="<?= base_url('faq') ?>"><i class="ph-fill ph-question"></i> FAQ</a>
    <a class="stack-item" href="<?= base_url('about') ?>"><i class="ph-fill ph-info"></i> About</a>
  </div>
</div>

<nav class="nav" id="nav">
  <a class="nav-item <?= $nav === 'account' ? 'on' : '' ?>" href="<?= base_url($gate('account')) ?>">
    <i class="ph<?= $nav === 'account' ? '-fill' : '' ?> ph-user-circle"></i><span>Account</span></a>

  <a class="nav-item <?= $nav === 'allocate' ? 'on' : '' ?>" href="<?= base_url($gate('allocate')) ?>">
    <i class="ph<?= $nav === 'allocate' ? '-fill' : '' ?> ph-squares-four"></i><span>Allocate</span></a>

  <?php /* data-nav is the hook cockpit.js binds to so that tapping Home while
           already on Home reveals the pads instead of reloading. A real href
           stays, because that is what makes it work from every other page, in a
           new tab, and with JS off. */ ?>
  <a class="nav-item <?= $nav === 'home' ? 'on' : '' ?>" href="<?= base_url() ?>" data-nav="home">
    <i class="ph<?= $nav === 'home' ? '-fill' : '' ?> ph-house"></i><span>Home</span></a>

  <?php /* data-current marks the item the CURRENT PAGE belongs to. The stack
           buttons also take .on transiently while their pull-up is open, and
           closing it clears .on from every stack button — this attribute is
           what lets app.js put the page's own highlight back afterwards. */ ?>
  <button class="nav-item <?= $nav === 'game' ? 'on' : '' ?>" type="button" data-stack="game"
          <?= $nav === 'game' ? 'data-current' : '' ?>>
    <i class="ph<?= $nav === 'game' ? '-fill' : '' ?> ph-crosshair"></i><span>Game</span>
    <em class="badge" id="nav-threat-badge" style="display:none">0</em></button>

  <button class="nav-item <?= $nav === 'info' ? 'on' : '' ?>" type="button" data-stack="info"
          <?= $nav === 'info' ? 'data-current' : '' ?>>
    <i class="ph<?= $nav === 'info' ? '-fill' : '' ?> ph-dots-three"></i><span>Info</span></button>
</nav>

<?php if (!$is_drill): ?>
<!-- add to home screen — only on the war room, never over a drill-down page -->
<div class="a2hs" id="a2hs">
  <i class="ph-fill ph-device-mobile lead"></i>
  <div class="txt"><b>Install PolitiClash</b>Add it to your home screen and play full-screen.</div>
  <button class="btn btn-primary btn-sm" id="a2hs-go" type="button">Add</button>
  <button class="hdr-btn" id="a2hs-no" type="button" aria-label="Dismiss"><i class="ph ph-x"></i></button>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="overlay" id="overlay"><div class="dlg" id="overlay-dlg"></div></div>

<?php /* app.js is loaded in <head> — see the note there. Only the cockpit's own
         scripts stay at the end of the body: they boot off DOMContentLoaded and
         nothing else depends on them.

         ORDER IS LOAD-BEARING and these three ship together. phl-geo.js is the
         coastline table, phl-map.js reads it at parse time, and cockpit.js asks
         phl-map for a projection the moment it sizes the radar. Uploading
         cockpit.js alone gets you a radar with no country in it; uploading
         phl-map.js alone gets you nothing at all. Plain sync tags, so document
         order is the guarantee — do not add defer/async to any of them. */ ?>
<?php if (!empty($o['cockpit'])): ?>
<script src="<?= pc_asset('assets/js/phl-geo.js') ?>"></script>
<script src="<?= pc_asset('assets/js/phl-map.js') ?>"></script>
<script src="<?= pc_asset('assets/js/cockpit.js') ?>"></script>
<?php endif; ?>
</body>
</html>
