<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$byRound = [];
foreach ($mine as $m2) $byRound[(int)$m2->round_no] = $m2;
?>
<div style="padding:14px 0 40px">

  <?php
    $played = count($mine);
    $won = 0; $net = 0;
    foreach ($mine as $m2) {
      if ((int)$m2->winner_campaign === (int)$m2->campaign_id) $won++;
      $net += (int)$m2->profit;
    }
  ?>
  <div class="stat-grid" style="border:1px solid var(--line);border-radius:var(--r);overflow:hidden;margin-bottom:14px">
    <div class="stat"><div class="stat-label">Rounds</div>
      <div class="stat-value"><?= $played ?></div></div>
    <div class="stat"><div class="stat-label">Won</div>
      <div class="stat-value teal"><?= $won ?></div>
      <div class="stat-delta"><?= $played ? number_format($won / $played * 100, 0) : 0 ?>%</div></div>
    <div class="stat"><div class="stat-label">Net tokens</div>
      <div class="stat-value <?= $net > 0 ? 'teal' : ($net < 0 ? 'loss' : '') ?>">
        <?= ($net > 0 ? '+' : '').number_format($net) ?></div></div>
  </div>

  <div class="card">
    <h3 class="sub-sec"><i class="ph ph-list"></i> Every settled round</h3>
    <?php if (!$rounds): ?>
      <div class="empty"><i class="ph ph-hourglass-medium"></i>No rounds have settled yet.</div>
    <?php else: foreach ($rounds as $r):
        $mineRow = isset($byRound[(int)$r->round_no]) ? $byRound[(int)$r->round_no] : null;
        $wc = $r->winner_campaign === null ? null : (int)$r->winner_campaign;
    ?>
      <div style="border-bottom:1px solid rgba(27,33,45,.7);padding:9px 0">
        <div class="row" style="justify-content:space-between">
          <span class="num" style="color:var(--ice)">Round <?= (int)$r->round_no ?></span>
          <span class="pill" style="border-color:<?= $wc !== null ? GameEngine::$CAMPAIGNS[$wc]['color'] : 'var(--line-2)' ?>;
                       color:<?= $wc !== null ? GameEngine::$CAMPAIGNS[$wc]['color'] : 'var(--slate)' ?>">
            <i class="ph-fill ph-trophy"></i>
            <?= $wc !== null ? GameEngine::campaign_name($wc) : 'no result' ?>
          </span>
        </div>
        <div class="tiny muted" style="margin-top:3px">
          <?= date('D j M', strtotime($r->opens_at)) ?>
          · pot <?= number_format((int)$r->grand_total) ?>
          <?= $r->active_event ? ' · '.html_escape($r->active_event) : ' · no event' ?>
        </div>
        <?php if ($mineRow): ?>
          <div class="row" style="justify-content:space-between;margin-top:5px">
            <span class="tiny" style="color:<?= GameEngine::$CAMPAIGNS[(int)$mineRow->campaign_id]['color'] ?>">
              You backed <?= GameEngine::campaign_name((int)$mineRow->campaign_id) ?>
              · <?= html_escape($mineRow->outcome ?: '—') ?>
              <?= (int)$mineRow->excluded_reward ? ' · below participation floor' : '' ?>
            </span>
            <span class="num" style="color:<?= (int)$mineRow->profit > 0 ? 'var(--teal)'
                : ((int)$mineRow->profit < 0 ? 'var(--loss)' : 'var(--slate)') ?>">
              <?= (int)$mineRow->profit > 0 ? '+' : '' ?><?= number_format((int)$mineRow->profit) ?>
            </span>
          </div>
        <?php else: ?>
          <div class="tiny muted" style="margin-top:5px">You sat this one out.</div>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
