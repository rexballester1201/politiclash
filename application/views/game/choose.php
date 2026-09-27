<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$switching = ($me->campaign_id !== null);
$ok  = $this->session->flashdata('ok');
$err = $this->session->flashdata('err');   // registration lands here and can
                                           // arrive carrying a mail failure
?>
<div style="max-width:520px;margin:0 auto;padding:16px 0 40px">

  <?php if ($ok): ?>
    <div class="alert ok"><i class="ph-fill ph-check-circle"></i><div><?= html_escape($ok) ?></div></div>
  <?php endif; ?>
  <?php /* warn, not bad: the account was created and they are logged in. The
           only thing that failed is the mail, so this is a caveat on a success,
           not a failed action. */ ?>
  <?php if ($err): ?>
    <div class="alert warn"><i class="ph-fill ph-envelope-simple"></i><div style="word-break:break-word"><?= html_escape($err) ?></div></div>
  <?php endif; ?>

  <p class="eyebrow"><?= $switching ? 'Change allegiance' : 'Declare' ?></p>
  <h1 class="display" style="font-size:2rem">
    <?= $switching ? 'Switch <i>sides</i>' : 'Choose your <i>campaign</i>' ?>
  </h1>

  <?php if ($switching): ?>
    <div class="alert warn">
      <i class="ph-fill ph-warning"></i>
      <div><b>Switching is not free.</b>
        You forfeit <b><?= round($penalty_next * 100) ?>%</b> of your wallet
        (<?= number_format((int)floor($me->balance * $penalty_next)) ?> tokens), lose your
        carry-over, and every identity trait streak resets to zero. Each further switch
        in the same stretch costs more.</div>
    </div>
  <?php else: ?>
    <p class="lede">Your tokens only work inside one campaign. Pick the one you intend to
      build — traits and carry-over reward staying put.</p>
  <?php endif; ?>

  <div class="stack-v">
  <?php foreach (GameEngine::$CAMPAIGNS as $cid => $c):
      $p  = $proj && isset($proj[$cid]) ? $proj[$cid] : null;
      $st = $p ? $p['state'] : null;
      $mine = ($switching && (int)$me->campaign_id === $cid);
  ?>
    <button class="pick <?= $mine ? 'on' : '' ?>" type="button"
            data-join="<?= $cid ?>" <?= $mine ? 'disabled' : '' ?>>
      <span class="pick-badge" style="background:<?= $c['color'] ?>"><?= substr($c['name'], 0, 1) ?></span>
      <span class="pick-meta">
        <span class="pick-name" style="color:<?= $c['color'] ?>"><?= $c['name'] ?></span>
        <span class="pick-sub">
          <?php if ($p): ?>
            Win chance <?= number_format($p['win_chance'] * 100, 1) ?>% ·
            <?= $st ? number_format((int)$st->contributor_count) : 0 ?> backers ·
            <?= $st ? number_format((float)$st->face_total) : 0 ?> tokens
          <?php else: ?>
            No live round yet
          <?php endif; ?>
        </span>
        <?php if ($p): ?>
        <span class="pick-sub" style="margin-top:3px">
          <?php
            $ps = $p['pop']['state'];
            if ($ps === 'underdog') echo '<span style="color:var(--teal)">Underdog bonus active — the smaller war chest hits harder</span>';
            elseif ($ps === 'overpop') echo '<span style="color:var(--warn)">Front-runner — carries a scale penalty</span>';
            else echo 'Balanced field';
          ?>
        </span>
        <?php endif; ?>
      </span>
      <?php if ($mine): ?>
        <i class="ph-fill ph-check-circle" style="color:var(--teal);font-size:20px"></i>
      <?php else: ?>
        <i class="ph ph-caret-right" style="color:var(--teal);font-size:18px"></i>
      <?php endif; ?>
    </button>
  <?php endforeach; ?>
  </div>

  <div class="card mt-lg">
    <h3 class="sub-sec"><i class="ph ph-scales"></i> How the sides differ</h3>
    <p class="tiny">They do not, mechanically — the three campaigns are identical in rules.
    What differs is who else is standing there. A crowded campaign takes a size penalty of up
    to 35%; the smallest gets an underdog bonus of up to 40%. Joining the side that is losing
    is often the mathematically better play, which is the point.</p>
  </div>
</div>

<script>
document.querySelectorAll('[data-join]').forEach(function (b) {
  b.addEventListener('click', function () {
    var cid = b.getAttribute('data-join');
    b.disabled = true;
    PC.post('game/join', { campaign_id: cid }).then(function (r) {
      if (!r.ok) { b.disabled = false; return PC.toast(PC.reason(r.reason), 'bad'); }
      if (r.penalty_amount > 0) {
        PC.toast('Switched. ' + PC.num(r.penalty_amount) + ' tokens forfeited.', 'warn');
      } else {
        PC.toast('You are in. Head to Allocate to place your first tokens.', 'ok');
      }
      setTimeout(function () { location.href = PC.url('allocate'); }, 900);
    });
  });
});
</script>
