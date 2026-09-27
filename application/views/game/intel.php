<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$cid = (int)$me->campaign_id;
$p   = $proj && isset($proj[$cid]) ? $proj[$cid] : null;
?>
<div style="padding:14px 0 40px">

  <!-- event -->
  <div class="hud mb">
    <div class="hud-hdr"><i class="ph-fill ph-lightning"></i> National event
      <span class="right"><?= $revealed ? 'identified' : 'unidentified' ?></span></div>
    <div class="hud-body">
      <?php if (!$round || empty($round->active_event)): ?>
        <div class="tiny muted">No event is in play this round. A clean board.</div>
      <?php elseif ($revealed):
          $desc = '';
          foreach (GameEngine::$EVENTS as $e) if ($e['name'] === $round->active_event) $desc = $e['desc']; ?>
        <div class="ins <?= $round->event_is_super ? 'bad' : 'warn' ?>">
          <i class="ph-fill ph-lightning"></i>
          <div><b><?= html_escape($round->active_event) ?><?= $round->event_is_super ? ' (major)' : '' ?></b>
            <?= html_escape($desc) ?></div>
        </div>
      <?php else: ?>
        <div class="ins warn"><i class="ph-fill ph-eye-slash"></i>
          <div><b>Something is moving this round</b>An event is active but has not been
            identified. Fund Opposition Research and run a scan — your reveal chance is
            <?= $p ? number_format($this->gameengine->compute_event_confidence($p['alloc']) * 100, 0) : 70 ?>%
            per scan, capped at 90%.</div></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- rivals -->
  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-crosshair"></i> Rival readout</h3>
    <?php foreach (GameEngine::$CAMPAIGNS as $rid => $c):
        if ($rid === $cid || !$proj) continue;
        $rp  = $proj[$rid];
        $rm  = $rp['mults'];
        $ed  = $this->gameengine->compute_effective_defense($rp['alloc']);
        $ts  = $this->gameengine->compute_target_score(
                 $p['alloc'], max(1.0, $p['power']), $rp['alloc'], max(1.0, $rp['power']));
        $soft = $ed < 0.05;
    ?>
      <div class="ins <?= $soft ? 'ok' : 'info' ?>">
        <i class="ph-fill ph-<?= $soft ? 'target' : 'shield' ?>"></i>
        <div>
          <b style="color:<?= $c['color'] ?>"><?= $c['name'] ?></b>
          Power <?= number_format($rp['power'], 0) ?> ·
          win <?= number_format($rp['win_chance'] * 100, 1) ?>% ·
          defence <?= number_format($ed * 100, 1) ?>% ·
          <?= ucfirst($rm['stab']['state']) ?>
          <?= $rm['stab']['collapse'] ? ' · <span style="color:var(--loss)">collapse risk</span>' : '' ?>
          <br>
          <span class="tiny">Target score <?= number_format($ts, 3) ?>
            <?= $ts < GameEngine::TARGET_SCORE_MIN ? ' — too hard to be worth attacking'
                : ($soft ? ' — an opening' : '') ?></span>
        </div>
      </div>
    <?php endforeach; ?>
    <p class="tiny muted">These are public aggregates. A paid scan reveals the internal
      allocation split — blurred, and blurrier the more you scan.</p>
  </div>

  <!-- scans -->
  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-magnifying-glass"></i> Your scans this round</h3>
    <?php if (!$scans): ?>
      <div class="empty"><i class="ph ph-magnifying-glass"></i>
        No scans yet. Use the Scan Rival pad in the war room.</div>
    <?php else: foreach ($scans as $s):
        $pl = json_decode($s->payload_json, true); ?>
      <div class="ins info">
        <i class="ph-fill ph-magnifying-glass"></i>
        <div><b><?= GameEngine::campaign_name((int)$s->target_campaign) ?>
          · <?= number_format($s->accuracy * 100, 0) ?>% accuracy</b>
          <?php if ($pl && isset($pl['system_breakdown'])):
            $bits = [];
            foreach ($pl['system_breakdown'] as $k => $v) $bits[] = $v['label'].' '.$v['displayed'].'%';
            echo html_escape(implode(' · ', array_slice($bits, 0, 5)));
          endif; ?>
          <br><span class="tiny muted"><?= date('H:i', strtotime($s->created_at)) ?>
            · cost <?= number_format($s->cost) ?></span>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- signals -->
  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-broadcast"></i> Field signals</h3>
    <?php if (!$signals): ?>
      <div class="empty"><i class="ph ph-broadcast"></i>Nothing broadcast yet.</div>
    <?php else: foreach ($signals as $s): ?>
      <div class="kv">
        <span class="k">
          <span style="color:<?= GameEngine::$CAMPAIGNS[(int)$s->campaign_id]['color'] ?>;font-weight:600">
            <?= GameEngine::campaign_name((int)$s->campaign_id) ?></span>
          <?= html_escape($s->username ?: 'anon') ?>
        </span>
        <span class="v"><?= html_escape($s->sig_type) ?></span>
      </div>
    <?php endforeach; endif; ?>
    <p class="tiny muted mt">Signals are declarations of intent, not commitments.
      Roughly a quarter of them are deliberate misdirection.</p>
  </div>

  <!-- past rounds -->
  <div class="card">
    <h3 class="sub-sec"><i class="ph ph-clock"></i> Recent results</h3>
    <?php if (!$history): ?>
      <div class="empty"><i class="ph ph-hourglass-medium"></i>No settled rounds yet.</div>
    <?php else: foreach ($history as $h): ?>
      <div class="kv">
        <span class="k">Round <?= (int)$h->round_no ?>
          <?php if ($h->active_event): ?>
            <span class="tiny muted">· <?= html_escape($h->active_event) ?></span>
          <?php endif; ?>
        </span>
        <span class="v" style="color:<?= $h->winner_campaign !== null
            ? GameEngine::$CAMPAIGNS[(int)$h->winner_campaign]['color'] : '#5C6E7C' ?>">
          <?= $h->winner_campaign !== null ? GameEngine::campaign_name((int)$h->winner_campaign) : '—' ?>
        </span>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
