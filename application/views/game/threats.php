<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$cid = (int)$me->campaign_id;
$p   = $proj && isset($proj[$cid]) ? $proj[$cid] : null;
$inc = array_filter($pending, function ($c) use ($cid) { return (int)$c->to_campaign === $cid; });
$out = array_filter($pending, function ($c) use ($cid) { return (int)$c->from_campaign === $cid; });
$eff_def = $p ? $this->gameengine->compute_effective_defense($p['alloc']) : 0;
?>
<div style="padding:14px 0 40px">

  <!-- posture -->
  <div class="hud mb">
    <div class="hud-hdr"><i class="ph-fill ph-shield"></i> Defensive posture
      <span class="right"><?= count($inc) ?> inbound</span></div>
    <div class="hud-body">
      <div class="hud-line"><span class="k">Effective defence</span>
        <span class="v <?= $eff_def > 0.10 ? 'teal' : ($eff_def < 0.04 ? 'loss' : 'warn') ?>">
          <?= number_format($eff_def * 100, 1) ?>%</span></div>
      <div class="hud-line"><span class="k">Coalition spend</span>
        <span class="v"><?= $p ? number_format($p['alloc']['coalitions']) : 0 ?></span></div>
      <?php if ($p): ?>
      <div class="hud-line"><span class="k">Backfire risk on your attacks</span>
        <span class="v <?= $this->gameengine->compute_backfire_chance($p['alloc']) > 0.3 ? 'loss' : '' ?>">
          <?= number_format($this->gameengine->compute_backfire_chance($p['alloc']) * 100, 1) ?>%</span></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($eff_def < 0.04): ?>
    <div class="alert bad"><i class="ph-fill ph-warning-octagon"></i>
      <div><b>You are effectively undefended.</b> Coalition spending is the only thing that
      blunts an incoming attack. Below about 4% you are the obvious target on the board.</div></div>
  <?php elseif ($eff_def > 0.12): ?>
    <div class="alert info"><i class="ph-fill ph-info"></i>
      <div>Past 12%, coalition spending hits diminishing returns — each extra token buys
      40% of what the previous one did. You are well covered.</div></div>
  <?php endif; ?>

  <!-- incoming -->
  <div class="card mb">
    <h3 class="sub-sec"><i class="ph-fill ph-warning"></i> Incoming</h3>
    <?php if (!$inc): ?>
      <div class="empty"><i class="ph ph-shield-check"></i>Nothing aimed at you right now.</div>
    <?php else: foreach ($inc as $c):
        $eta = max(0, strtotime($c->resolve_at) - time()); ?>
      <div class="ins bad">
        <i class="ph-fill ph-sword"></i>
        <div><b style="color:<?= GameEngine::$CAMPAIGNS[(int)$c->from_campaign]['color'] ?>">
          From <?= GameEngine::campaign_name((int)$c->from_campaign) ?>
          <?= (int)$c->n_index > 1 ? ' · wave '.(int)$c->n_index : '' ?></b>
          Lands in <?= (int)ceil($eta / 60) ?> min ·
          projected <?= number_format((1 - (float)$c->impact) * 100, 1) ?>% power loss<br>
          <span class="tiny muted">Their backfire chance
            <?= number_format((float)$c->backfire_chance * 100, 1) ?>% — it may hit them instead.</span>
        </div>
      </div>
    <?php endforeach; endif; ?>
    <?php if ($inc): ?>
      <a class="btn btn-primary btn-block mt" href="<?= base_url('allocate') ?>">
        <i class="ph-fill ph-handshake"></i> Reinforce coalitions</a>
    <?php endif; ?>
  </div>

  <!-- outgoing -->
  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-sword"></i> Your offensives</h3>
    <?php if (!$out): ?>
      <div class="empty"><i class="ph ph-hand-fist"></i>None in flight.</div>
    <?php else: foreach ($out as $c):
        $eta = max(0, strtotime($c->resolve_at) - time()); ?>
      <div class="ins warn">
        <i class="ph-fill ph-target"></i>
        <div><b>Against <?= GameEngine::campaign_name((int)$c->to_campaign) ?></b>
          Lands in <?= (int)ceil($eta / 60) ?> min ·
          impact ×<?= number_format((float)$c->impact, 3) ?><br>
          <span class="tiny muted">Backfire chance
            <?= number_format((float)$c->backfire_chance * 100, 1) ?>%
            <?= (int)$c->n_index > 1 ? ' · multi-attack penalty ×'.number_format((float)$c->multi_atk_penalty, 2) : '' ?></span>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- log -->
  <div class="card">
    <h3 class="sub-sec"><i class="ph ph-list"></i> Conflict log</h3>
    <?php
    $done = array_filter($resolved, function ($c) { return (int)$c->resolved === 1; });
    if (!$done): ?>
      <div class="empty"><i class="ph ph-clock"></i>Nothing has resolved yet.</div>
    <?php else: foreach (array_slice($done, 0, 12) as $c): ?>
      <div class="kv">
        <span class="k">
          <span style="color:<?= GameEngine::$CAMPAIGNS[(int)$c->from_campaign]['color'] ?>">
            <?= GameEngine::campaign_name((int)$c->from_campaign) ?></span>
          →
          <span style="color:<?= GameEngine::$CAMPAIGNS[(int)$c->to_campaign]['color'] ?>">
            <?= GameEngine::campaign_name((int)$c->to_campaign) ?></span>
        </span>
        <span class="v <?= (int)$c->backfired ? 'warn' : 'loss' ?>">
          <?= (int)$c->backfired ? 'backfired' : '−'.number_format((1 - (float)$c->impact) * 100, 1).'%' ?>
        </span>
      </div>
    <?php endforeach; endif; ?>
    <p class="tiny muted mt">Every attack is capped between ×0.85 and ×1.25 — no single strike
      decides a round. Piling on the same target decays fast: the fourth wave lands at 55%.</p>
  </div>
</div>
