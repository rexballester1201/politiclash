<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$cid   = (int)$me->campaign_id;
$camp  = GameEngine::$CAMPAIGNS[$cid];
$p     = $proj && isset($proj[$cid]) ? $proj[$cid] : null;
$alloc = $p ? $p['alloc'] : GameEngine::zero_alloc();
$mineC = $ur ? (int)$ur->contributed : 0;
$open  = !empty($phase['is_open']);

$groups = ['core' => [], 'attack' => [], 'defense' => []];
foreach (GameEngine::$SYS_META as $k => $mta) $groups[$mta['group']][$k] = $mta;
?>
<div style="padding:14px 0 40px">

  <!-- status strip -->
  <div class="hud mb">
    <div class="hud-hdr">
      <i class="ph-fill ph-squares-four"></i> <?= $camp['name'] ?> — Round
      <?= $round ? '#'.(int)$round->round_no : '—' ?>
      <span class="right" id="al-phase"><?= strtoupper($open ? $phase['phase'] : 'closed') ?></span>
    </div>
    <div class="hud-body">
      <div class="hud-line"><span class="k">Wallet</span>
        <span class="v teal" id="al-balance"><?= number_format($me->balance) ?></span></div>
      <div class="hud-line"><span class="k">Already placed this round</span>
        <span class="v" id="al-placed"><?= number_format($mineC) ?></span></div>
      <div class="hud-line"><span class="k">Contribution weight right now</span>
        <span class="v <?= $phase['time_weight'] >= 1.0 ? 'teal' : 'warn' ?>">
          ×<?= number_format($phase['time_weight'], 3) ?></span></div>
      <div class="hud-line"><span class="k">Round closes</span>
        <span class="v" id="al-remaining"><?= $open ? PC_ROUND_CLOSE : 'closed' ?></span></div>
    </div>
  </div>

  <?php if (!$open): ?>
    <div class="alert warn"><i class="ph-fill ph-hourglass-medium"></i>
      <div><b>The round is closed.</b> Contributions reopen at <?= PC_ROUND_OPEN ?>.
      You can still read the board and plan.</div></div>
  <?php elseif ($phase['phase'] === 'late'): ?>
    <div class="alert warn"><i class="ph-fill ph-timer"></i>
      <div><b>Late phase.</b> Contributions now bank at
      ×<?= number_format($phase['time_weight'], 3) ?> and a per-player cap applies.
      Waiting for the buzzer costs you money.</div></div>
  <?php elseif ($phase['phase'] === 'mid'): ?>
    <div class="alert ok"><i class="ph-fill ph-lightning"></i>
      <div><b>Mid phase — the sweet spot.</b> Contributions bank at ×1.050,
      the best rate of the day.</div></div>
  <?php endif; ?>

  <!-- budget -->
  <div class="card mb">
    <div class="row" style="justify-content:space-between">
      <span class="stat-label">This batch</span>
      <span class="num" style="color:var(--ice)"><span id="bud-used">0</span> / <?= number_format($me->balance) ?></span>
    </div>
    <div class="budget-bar"><div class="budget-fill" id="bud-fill" style="width:0%"></div></div>
    <div class="row wrap" style="gap:6px;margin-top:8px">
      <button class="btn btn-ghost btn-sm" type="button" data-auto="20">Auto 20%</button>
      <button class="btn btn-ghost btn-sm" type="button" data-auto="50">Auto 50%</button>
      <button class="btn btn-ghost btn-sm" type="button" data-auto="100">Auto full</button>
      <button class="btn btn-ghost btn-sm" type="button" data-auto="0">Clear</button>
    </div>
    <div class="tiny muted mt">Auto follows the Council's current recommendation.
      It is advice, not gospel — its track record is on the Advisor page.</div>
  </div>

  <!-- allocation rows -->
  <?php
  $labels = ['core' => ['Core systems', 'ph-gear', 'core'],
             'attack' => ['Attack', 'ph-sword', 'atk'],
             'defense' => ['Defence', 'ph-shield', 'def']];
  foreach ($groups as $g => $items):
      if (!$items) continue; ?>
    <div class="group-hdr <?= $labels[$g][2] ?>">
      <i class="ph-fill <?= $labels[$g][1] ?>"></i> <?= $labels[$g][0] ?>
    </div>
    <div class="card mb" style="padding:4px 14px">
      <?php foreach ($items as $k => $mta):
          $cur = isset($alloc[$k]) ? (float)$alloc[$k] : 0; ?>
        <div class="alloc-row <?= $g === 'attack' ? 'atk' : ($g === 'defense' ? 'def' : '') ?>">
          <div class="alloc-ic"><i class="ph-fill ph-<?= $mta['icon'] ?>"></i></div>
          <div class="alloc-meta">
            <div class="alloc-name"><?= $mta['label'] ?></div>
            <div class="alloc-hint" data-hint="<?= $k ?>">campaign has <?= number_format($cur) ?></div>
          </div>
          <div class="alloc-ctl">
            <button class="alloc-btn" type="button" data-step="<?= $k ?>" data-delta="-1000"
              <?= $open ? '' : 'disabled' ?>><i class="ph-bold ph-minus"></i></button>
            <input class="alloc-in" type="number" min="0" step="100" value="0"
              data-sys="<?= $k ?>" inputmode="numeric" <?= $open ? '' : 'disabled' ?>>
            <button class="alloc-btn" type="button" data-step="<?= $k ?>" data-delta="1000"
              <?= $open ? '' : 'disabled' ?>><i class="ph-bold ph-plus"></i></button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <!-- projection -->
  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-chart-line-up"></i> Projection</h3>
    <div id="preview"><div class="tiny muted">Place tokens to see what this batch does.</div></div>
    <div class="tiny muted mt">A projection of the ratios your batch moves. The final power
      figure is computed on the server at settlement and includes a hidden variance term.</div>
  </div>

  <button class="btn btn-primary btn-block" id="confirm" type="button" <?= $open ? '' : 'disabled' ?>>
    <i class="ph-fill ph-check-circle"></i> Confirm batch
  </button>
  <p class="tiny muted mt" style="text-align:center">
    Confirmed tokens are locked into <?= $camp['name'] ?> for the round.
  </p>
</div>

<script>
(function () {
  var CAMP = <?= json_encode($alloc) ?>;
  var REC  = <?= json_encode($council['rec']) ?>;
  var TW   = <?= json_encode((float)$phase['time_weight']) ?>;
  var BAL  = <?= (int)$me->balance ?>;
  var PLACED = <?= (int)$mineC ?>;
  var OPEN = <?= $open ? 'true' : 'false' ?>;

  var CORE = ['funding','organization','logistics','policy','charisma','messaging','opposition_research'];
  var REQ  = { funding: 0.15, organization: 0.12, logistics: 0.08 };
  var LBL  = <?= json_encode(array_map(function ($m) { return $m['label']; }, GameEngine::$SYS_META)) ?>;

  var inputs = PC.$$('[data-sys]');

  function vals() {
    var o = {};
    inputs.forEach(function (i) { o[i.getAttribute('data-sys')] = Math.max(0, parseInt(i.value, 10) || 0); });
    return o;
  }
  function total(v) {
    return Object.keys(v).reduce(function (s, k) { return s + v[k]; }, 0);
  }

  function render() {
    var v = vals(), t = total(v);

    PC.$('#bud-used').textContent = PC.num(t);
    var pct = BAL > 0 ? Math.min(100, t / BAL * 100) : 0;
    var fill = PC.$('#bud-fill');
    fill.style.width = pct + '%';
    fill.classList.toggle('over', t > BAL);

    var btn = PC.$('#confirm');
    btn.disabled = !OPEN || t <= 0 || t > BAL;
    btn.innerHTML = t > BAL
      ? '<i class="ph-fill ph-warning"></i> Over budget by ' + PC.num(t - BAL)
      : '<i class="ph-fill ph-check-circle"></i> Confirm ' + (t > 0 ? PC.num(t) + ' tokens' : 'batch');

    /* per-row hint: what the campaign holds now vs after this batch */
    inputs.forEach(function (i) {
      var k = i.getAttribute('data-sys');
      var h = d('[data-hint="' + k + '"]');
      if (!h) return;
      var after = (CAMP[k] || 0) + v[k] * TW;
      h.textContent = v[k] > 0
        ? PC.num(CAMP[k] || 0) + ' → ' + PC.num(after) + ' banked'
        : 'campaign has ' + PC.num(CAMP[k] || 0);
      h.style.color = v[k] > 0 ? 'var(--teal)' : '';
    });

    renderPreview(v, t);
  }

  function d(sel) { return document.querySelector(sel); }

  function renderPreview(v, t) {
    var box = PC.$('#preview');
    if (t <= 0) {
      box.innerHTML = '<div class="tiny muted">Place tokens to see what this batch does.</div>';
      return;
    }

    /* campaign totals after this batch banks at the current time weight */
    var after = {}, core = 0, all = 0;
    Object.keys(LBL).forEach(function (k) {
      after[k] = (CAMP[k] || 0) + (v[k] || 0) * TW;
      all += after[k];
      if (CORE.indexOf(k) >= 0) core += after[k];
    });

    var html = '';

    /* timing loss — the single most actionable number on this page */
    var banked = t * TW;
    html += '<div class="kv"><span class="k">Face value</span><span class="v">' + PC.num(t) + '</span></div>';
    html += '<div class="kv"><span class="k">Actually banked at ×' + TW.toFixed(3) + '</span><span class="v ' +
            (TW >= 1 ? 'teal' : 'warn') + '">' + PC.num(banked) + '</span></div>';
    if (TW < 1) {
      html += '<div class="kv"><span class="k">Lost to late timing</span><span class="v loss">−' +
              PC.num(t - banked) + '</span></div>';
    }

    /* sustainability gates */
    html += '<h3 class="sub-sec mt-lg"><i class="ph ph-shield-check"></i> Sustainability</h3>';
    ['funding','organization','logistics'].forEach(function (k) {
      var need = core * REQ[k], have = after[k], ok = have >= need;
      html += '<div class="kv"><span class="k">' + LBL[k] + '</span><span class="v ' +
        (ok ? 'teal' : 'loss') + '">' + PC.num(have) + ' / ' + PC.num(need) +
        (ok ? ' ✓' : '') + '</span></div>';
    });
    var fails = ['funding','organization','logistics'].filter(function (k) {
      return after[k] < core * REQ[k];
    });
    if (fails.length) {
      html += '<div class="ins bad mt"><i class="ph-fill ph-warning-octagon"></i><div>' +
        '<b>Campaign would be in collapse</b>Below minimum on ' +
        fails.map(function (k) { return LBL[k]; }).join(', ') +
        '. Lose the round in this state and backers earn nothing at all.</div></div>';
    }

    /* saturation */
    var sat = core > 0 ? Math.min(0.5, after.messaging / core) : 0;
    html += '<h3 class="sub-sec mt-lg"><i class="ph ph-megaphone"></i> Messaging</h3>';
    html += '<div class="kv"><span class="k">Saturation</span><span class="v ' +
      (sat >= 0.5 ? 'loss' : (sat >= 0.28 ? 'warn' : 'teal')) + '">' +
      (sat * 100).toFixed(0) + '%</span></div>';
    if (sat >= 0.28) {
      html += '<div class="tiny muted">Past 50% of core, extra messaging spend is largely wasted.</div>';
    }

    /* trust cost of the attack systems */
    var atk = (after.attack_ads || 0), neg = (after.negative_campaigning || 0);
    if (atk + neg > 0 && all > 0) {
      var trust = Math.max(0.10, 1 - (atk / all) * 0.60 - (neg / all) * 0.80);
      html += '<h3 class="sub-sec mt-lg"><i class="ph ph-heartbeat"></i> Trust cost</h3>';
      html += '<div class="kv"><span class="k">Projected trust</span><span class="v ' +
        (trust < 0.6 ? 'loss' : (trust < 0.8 ? 'warn' : 'teal')) + '">' + trust.toFixed(3) + '</span></div>';
      html += '<div class="tiny muted">Attack spending lowers trust, which lowers power and ' +
        'raises the chance your own attacks backfire.</div>';
    }

    box.innerHTML = html;
  }

  /* steppers */
  PC.$$('[data-step]').forEach(function (b) {
    b.addEventListener('click', function () {
      var k = b.getAttribute('data-step'),
          dv = parseInt(b.getAttribute('data-delta'), 10),
          inp = d('[data-sys="' + k + '"]');
      inp.value = Math.max(0, (parseInt(inp.value, 10) || 0) + dv);
      render();
    });
  });
  inputs.forEach(function (i) { i.addEventListener('input', render); });

  /* auto-allocate from the Council recommendation */
  PC.$$('[data-auto]').forEach(function (b) {
    b.addEventListener('click', function () {
      var pct = parseInt(b.getAttribute('data-auto'), 10);
      var budget = Math.floor(BAL * pct / 100 / 100) * 100;
      inputs.forEach(function (i) {
        var k = i.getAttribute('data-sys');
        i.value = pct === 0 ? 0 : Math.floor(budget * (REC[k] || 0) / 100) * 100;
      });
      render();
    });
  });

  /* confirm */
  PC.$('#confirm').addEventListener('click', function () {
    var v = vals(), t = total(v);
    if (t <= 0) return;

    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="ph ph-circle-notch ph-spin"></i> Locking batch…';

    PC.post('game/confirm', { alloc: v }).then(function (r) {
      if (!r.ok) {
        btn.disabled = false;
        render();
        if (r.reason === 'late_cap') {
          return PC.toast('Late-phase cap: the most you can add right now is ' +
                          PC.num(r.cap) + ' tokens.', 'warn');
        }
        return PC.toast(PC.reason(r.reason), 'bad');
      }

      var lost = Math.max(0, r.face - r.effective);
      PC.toast('Locked ' + PC.num(r.face) + ' tokens. Banked ' + PC.num(Math.round(r.effective)) +
               (lost > 100 ? ' — ' + PC.num(Math.round(lost)) + ' lost to timing.' : '.'), 'ok');

      setTimeout(function () { location.href = PC.url(''); }, 1300);
    });
  });

  render();
})();
</script>
