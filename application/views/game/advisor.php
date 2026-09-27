<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$cid = (int)$me->campaign_id;
$p   = $proj && isset($proj[$cid]) ? $proj[$cid] : null;
$m   = $p ? $p['mults'] : null;
$TONE = ['bad' => 'bad', 'warn' => 'warn', 'ok' => 'ok', 'info' => 'info'];
?>
<div style="padding:14px 0 40px">

  <!-- strategist -->
  <div class="hud mb">
    <div class="hud-hdr"><i class="ph-fill ph-brain"></i> Strategist
      <span class="right"><?= count($coach) ?> note<?= count($coach) === 1 ? '' : 's' ?></span></div>
    <div class="hud-body">
      <?php foreach ($coach as $c): ?>
        <div class="ins <?= $TONE[$c['tone']] ?>">
          <i class="ph-fill ph-<?= $c['icon'] ?>"></i>
          <div><b><?= html_escape($c['title']) ?></b><?= html_escape($c['body']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- council -->
  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-users-three"></i> Council recommendation</h3>
    <div class="row mb" style="justify-content:space-between">
      <span class="tiny muted">Track record</span>
      <?php
        $conf = $council['confidence'];
        $cls  = $conf === 'High' ? 'up' : ($conf === 'Low' ? 'down' : ($conf === 'Medium' ? 'wait' : 'flat'));
      ?>
      <span class="pill <?= $cls ?>"><?= $conf ?></span>
    </div>

    <?php
    // Scale against the largest recommended share so the shape of the advice
    // is readable. The old `* 320` was an arbitrary stretch with no meaning.
    $peak = max(array_merge([0.0001], array_values($council['rec'])));
    foreach ($council['rec'] as $k => $v):
        if ($v < 0.005) continue;
        $mta = GameEngine::$SYS_META[$k];
        $w   = min(100, max(2.5, $v / $peak * 100)); ?>
      <div class="camp-row" style="padding:4px 0">
        <span class="camp-name" style="width:104px;flex:0 0 104px;font-size:12px;color:var(--mist)">
          <?= $mta['label'] ?></span>
        <span class="camp-bar" style="height:5px"
              title="<?= $mta['label'] ?>: <?= number_format($v * 100, 1) ?>% of the recommended budget">
          <span class="camp-fill"
          style="width:<?= number_format($w, 1) ?>%;background:var(--teal)"></span></span>
        <span class="camp-pct" style="width:44px;flex:0 0 44px;font-size:12.5px;color:var(--teal)">
          <?= number_format($v * 100, 1) ?>%</span>
      </div>
    <?php endforeach; ?>
    <div class="tiny muted mt">Bars are relative to the largest recommendation
      (<?= number_format($peak * 100, 1) ?>%). Percentages are share of budget.</div>

    <p class="tiny muted mt">The Council is fallible on purpose. Its confidence rating is
      earned from its own past calls — treat "Unproven" as exactly that. The Allocate page's
      auto buttons follow this split.</p>

    <a class="btn btn-primary btn-block mt" href="<?= base_url('allocate') ?>">
      <i class="ph ph-squares-four"></i> Apply on the Allocate page</a>
  </div>

  <!-- multiplier breakdown -->
  <?php if ($m): ?>
  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-gauge"></i> Where your power comes from</h3>
    <?php
    $rows = [
      ['Base power (core^0.9)', number_format($m['base_power'], 1), ''],
      ['Efficiency',      '×'.number_format($m['efficiency'], 3),      $m['efficiency'] > 1.05 ? 'teal' : ''],
      ['Infrastructure',  '×'.number_format($m['infra'], 3),           $m['infra'] > 1.05 ? 'teal' : ''],
      ['Policy impact',   '×'.number_format($m['policy_impact'], 3),   $m['policy_impact'] >= 1.24 ? 'warn' : ''],
      ['Charisma impact', '×'.number_format($m['charisma_impact'], 3), ''],
      ['Trust modifier',  '×'.number_format($m['trust_mod'], 3),       $m['T'] < 0.7 ? 'loss' : ''],
      ['Credibility modifier', '×'.number_format($m['cred_mod'], 3),   ''],
      ['Stability',       '×'.number_format($m['stab']['mod'], 2),     $m['stab']['state'] === 'stable' ? 'teal' : 'warn'],
      ['Campaign scale',  '×'.number_format($p['pop']['modifier'], 3), $p['pop']['state'] === 'underdog' ? 'teal' : ($p['pop']['state'] === 'overpop' ? 'loss' : '')],
      ['Peace bonus',     '×'.number_format($p['peace']['modifier'], 3), $p['peace']['modifier'] > 1 ? 'teal' : ''],
      ['Conflict damage', '×'.number_format($p['conflict_product'], 3), $p['conflict_product'] < 1 ? 'loss' : ''],
      ['Aggression cost', '×'.number_format($m['ac']['final_cost'], 3), $m['ac']['final_cost'] < 0.95 ? 'loss' : ''],
      ['Persistence',     '×'.number_format($p['persist'], 3),         $p['persist'] > 1 ? 'teal' : ''],
    ];
    foreach ($rows as $r): ?>
      <div class="kv"><span class="k"><?= $r[0] ?></span>
        <span class="v <?= $r[2] ?>"><?= $r[1] ?></span></div>
    <?php endforeach; ?>
    <div class="kv" style="border-top:1px solid var(--line-2);margin-top:6px;padding-top:9px">
      <span class="k" style="color:var(--ice)">Projected power</span>
      <span class="v teal" style="font-size:15px"><?= number_format($p['power'], 1) ?></span></div>
    <p class="tiny muted mt">A hidden variance of ±5% is applied at settlement and is never shown.
      Two identical positions do not always produce identical results.</p>
  </div>
  <?php endif; ?>

  <a class="btn btn-ghost btn-block" href="<?= base_url('intel') ?>">
    <i class="ph ph-magnifying-glass"></i> Intel and opportunities</a>
</div>
