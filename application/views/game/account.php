<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$ok  = $this->session->flashdata('ok');
$err = $this->session->flashdata('err');   // resend reports failures here
$cid = $me->campaign_id === null ? null : (int)$me->campaign_id;
?>
<div style="padding:14px 0 40px">

  <?php if ($ok): ?>
    <div class="alert ok"><i class="ph-fill ph-check-circle"></i><div><?= html_escape($ok) ?></div></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert bad"><i class="ph-fill ph-warning-octagon"></i><div style="word-break:break-word"><?= html_escape($err) ?></div></div>
  <?php endif; ?>

  <!-- identity -->
  <div class="hud mb">
    <div class="hud-hdr"><i class="ph-fill ph-user-circle"></i> <?= html_escape($me->username) ?>
      <span class="right"><?= $me->email_verified ? 'verified' : 'unverified' ?></span></div>
    <div class="hud-body">
      <div class="hud-line"><span class="k">Wallet</span>
        <span class="v teal"><?= number_format($me->balance) ?> tokens</span></div>
      <div class="hud-line"><span class="k">Campaign</span>
        <span class="v" style="<?= $cid !== null ? 'color:'.GameEngine::$CAMPAIGNS[$cid]['color'] : '' ?>">
          <?= $cid !== null ? GameEngine::campaign_name($cid) : 'none yet' ?></span></div>
      <div class="hud-line"><span class="k">Rounds played</span>
        <span class="v"><?= (int)$me->rounds_played ?></span></div>
      <div class="hud-line"><span class="k">Rounds won</span>
        <span class="v teal"><?= (int)$me->rounds_won ?></span></div>
      <div class="hud-line"><span class="k">Account age</span>
        <span class="v"><?= (int)$age_days ?> day<?= $age_days == 1 ? '' : 's' ?></span></div>
    </div>
  </div>

  <?php /* Says what is true — the address is unverified — rather than asserting
           a link was sent. When mail is not configured that claim was the whole
           reason this looked like a silent failure instead of a missing setting. */ ?>
  <?php if (!$me->email_verified): ?>
    <div class="alert warn">
      <i class="ph-fill ph-envelope-simple"></i>
      <div><b>Email not verified.</b> <?= html_escape($me->email) ?> —
        <a href="<?= base_url('resend') ?>">send a verification link</a>.</div>
    </div>
  <?php endif; ?>

  <!-- maturity -->
  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-chart-line-up"></i> Participation maturity</h3>
    <div class="row" style="justify-content:space-between">
      <span class="tiny muted">Scales your share of the team pool</span>
      <span class="num" style="color:var(--teal);font-size:17px"><?= number_format($maturity, 3) ?></span>
    </div>
    <div class="budget-bar"><div class="budget-fill" style="width:<?= min(100, $maturity * 100) ?>%"></div></div>
    <p class="tiny muted mt">Grows with rounds played and account age, capped at 1.000.
      It exists so a brand-new account cannot out-earn someone who has been showing up.</p>
  </div>

  <!-- streaks -->
  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-flow-arrow"></i> Form</h3>
    <div class="kv"><span class="k">Rounds since last win</span>
      <span class="v"><?= (int)$me->rounds_since_win >= 999 ? 'never won' : (int)$me->rounds_since_win ?></span></div>
    <?php
      $pm = $this->gameengine->persistence_multiplier((int)$me->rounds_since_win, (int)$me->rounds_played > 0);
      $note = '';
      if ($pm > 1.0) {
        if ((int)$me->rounds_since_win === 1) $note = 'Momentum from your last win.';
        elseif ((int)$me->rounds_since_win === 2) $note = 'Momentum fading.';
        else $note = 'Underdog bonus — one round only.';
      }
    ?>
    <div class="kv"><span class="k">Persistence multiplier</span>
      <span class="v <?= $pm > 1 ? 'teal' : '' ?>">×<?= number_format($pm, 3) ?></span></div>
    <?php if ($note): ?><p class="tiny muted mt"><?= $note ?></p><?php endif; ?>
    <div class="kv"><span class="k">Campaign switches</span>
      <span class="v <?= (int)$me->switch_count > 1 ? 'warn' : '' ?>"><?= (int)$me->switch_count ?></span></div>
  </div>

  <!-- identity traits -->
  <div class="card mb">
    <h3 class="sub-sec"><i class="ph-fill ph-medal"></i> Identity traits</h3>
    <p class="tiny muted mb">Hold the same allocation shape for three rounds running and it
      becomes a permanent passive. Switching campaigns resets every streak.</p>
    <?php foreach (GameEngine::$TRAIT_META as $key => $tm):
        $t = isset($traits[$key]) ? $traits[$key] : ['streak_rounds' => 0, 'active' => false]; ?>
      <div class="alloc-row">
        <div class="alloc-ic" style="<?= $t['active'] ? 'background:rgba(0,229,204,.16)' : 'opacity:.4' ?>">
          <i class="ph<?= $t['active'] ? '-fill' : '' ?> ph-<?= $tm['icon'] ?>"></i>
        </div>
        <div class="alloc-meta">
          <div class="alloc-name" style="<?= $t['active'] ? 'color:var(--teal)' : '' ?>"><?= $tm['label'] ?></div>
          <div class="alloc-hint"><?= $tm['effect'] ?></div>
        </div>
        <div class="alloc-ctl">
          <?php if ($t['active']): ?>
            <span class="pill up"><i class="ph-fill ph-check"></i> Active</span>
          <?php else: ?>
            <span class="pill flat"><?= (int)$t['streak_rounds'] ?>/<?= GameEngine::TRAIT_STREAK_REQUIRED ?></span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- recent results -->
  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-clock"></i> Your last rounds</h3>
    <?php if (!$history): ?>
      <div class="empty"><i class="ph ph-hourglass-medium"></i>No settled rounds yet.</div>
    <?php else: foreach ($history as $h):
        $won = ((int)$h->winner_campaign === (int)$h->campaign_id);
        $prof = (int)$h->profit; ?>
      <div class="kv">
        <span class="k">Round <?= (int)$h->round_no ?> ·
          <span style="color:<?= GameEngine::$CAMPAIGNS[(int)$h->campaign_id]['color'] ?>">
            <?= GameEngine::campaign_name((int)$h->campaign_id) ?></span>
          <span class="tiny muted">· <?= html_escape($h->outcome ?: 'pending') ?></span>
        </span>
        <span class="v <?= $prof > 0 ? 'teal' : ($prof < 0 ? 'loss' : '') ?>">
          <?= $prof > 0 ? '+' : '' ?><?= number_format($prof) ?>
        </span>
      </div>
    <?php endforeach; endif; ?>
    <a class="btn btn-ghost btn-sm btn-block mt" href="<?= base_url('history') ?>">
      <i class="ph ph-list"></i> Full history</a>
  </div>

  <?php if ($cid !== null): ?>
  <a class="btn btn-ghost btn-block mb" href="<?= base_url('choose') ?>">
    <i class="ph ph-swap"></i> Switch campaign</a>
  <?php endif; ?>

  <a class="btn btn-danger btn-block" href="<?= base_url('logout') ?>">
    <i class="ph ph-sign-out"></i> Log out</a>
</div>
