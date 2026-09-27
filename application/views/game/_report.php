<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Settlement report — shown on the war room while the round is closed.
 *
 * Every number here was written by settle(); nothing is recomputed. Where a
 * "why" is given it is the multiplier that actually moved the power score, not
 * a plausible-sounding reason derived after the fact.
 *
 * $report comes from M_round::report(). $me is the viewer's pc_user_round row,
 * or null for a guest or for somebody who did not play this round.
 */
$rp     = $report;
$winner = $rp['winner'];
$camps  = $rp['camps'];
$me     = $rp['me'];
$w      = ($winner !== null && isset($camps[$winner])) ? $camps[$winner] : null;

/* Losers, worst-beaten last, so the list reads as a ranking. */
$losers = [];
foreach ($camps as $cid => $c) if (!$c['won']) $losers[$cid] = $c;
uasort($losers, function ($a, $b) {
    if ($a['power'] === $b['power']) return 0;
    return ($a['power'] < $b['power']) ? 1 : -1;
});

$mine_cid = $me ? (int)$me->campaign_id : null;
$staked   = $me ? (int)$me->contributed : 0;
$reward   = $me ? (int)$me->reward : 0;
$profit   = $me ? (int)$me->profit : 0;
$played   = ($me && $staked > 0);

$OUTCOME = [
    'victory'   => ['Decisive victory', 'Your campaign won by a clear margin.'],
    'narrow'    => ['Narrow victory',   'Your campaign won, but only just — the reward multiplier is reduced.'],
    'resilient' => ['Beaten but solid', 'Your campaign lost while staying sustainable, so part of your stake came back.'],
    'collapse'  => ['Collapse',         'Your campaign lost and failed its sustainability checks. Nothing is returned.'],
    'observer'  => ['Observer',         'You held your tokens this round.'],
];
?>
<div class="report">

  <!-- ── verdict ──────────────────────────────────────────────────────── -->
  <div class="rp-head">
    <div>
      <div class="rp-eyebrow">Round <?= (int)$rp['round']->round_no ?> · settled</div>
      <?php if ($w): ?>
        <h2 class="rp-winner" style="color:<?= $w['color'] ?>">
          <i class="ph-fill ph-trophy"></i> <?= html_escape($w['name']) ?> wins
        </h2>
        <?php /* The winner is DRAWN from the win chances, so the strongest
                 campaign is the favourite and nothing more. Saying "N% ahead on
                 power" when the winner was behind on power is both wrong and
                 hides the best thing that happens in this game. */ ?>
        <div class="rp-sub">
          <?php if (!empty($rp['upset']) && isset($camps[$rp['leader']])): ?>
            Won from a <b><?= number_format($w['win_chance'], 1) ?>%</b> chance —
            <span style="color:<?= $camps[$rp['leader']]['color'] ?>"><?= html_escape($camps[$rp['leader']]['name']) ?></span>
            finished stronger on power. The winner is drawn from the odds, not
            handed to the strongest.
          <?php elseif ($rp['margin'] !== null): ?>
            <?= number_format($rp['margin'], 1) ?>% ahead of the next campaign on
            power, and won from a <b><?= number_format($w['win_chance'], 1) ?>%</b>
            chance<?= $rp['margin'] < 10 ? ' — a narrow result' : '' ?>.
          <?php endif; ?>
        </div>
      <?php else: ?>
        <h2 class="rp-winner">No winner recorded</h2>
      <?php endif; ?>
    </div>

    <?php /* The viewer's own line comes first on a phone — it is the thing they
             opened the page to find out. */ ?>
    <?php if ($me): ?>
      <?php
        $oc  = $me->outcome ? (string)$me->outcome : 'observer';
        $won = ($winner !== null && $mine_cid === $winner);
        $tone = !$played ? 'flat' : ($profit > 0 ? 'up' : ($profit < 0 ? 'down' : 'flat'));
      ?>
      <div class="rp-you <?= $tone ?>">
        <div class="rp-you-label"><?= html_escape($OUTCOME[$oc][0]) ?></div>
        <?php if ($played): ?>
          <div class="rp-you-num"><?= $profit >= 0 ? '+' : '−' ?><?= number_format(abs($profit)) ?></div>
          <div class="rp-you-foot">
            staked <?= number_format($staked) ?> · returned <?= number_format($reward) ?>
          </div>
        <?php else: ?>
          <div class="rp-you-num">—</div>
          <div class="rp-you-foot">no tokens placed</div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($me): ?>
    <div class="rp-verdict"><?= html_escape($OUTCOME[$me->outcome ? (string)$me->outcome : 'observer'][1]) ?>
      <?php if ($played && (int)$me->excluded_reward === 1): ?>
        <b>Your stake was below the participation floor for your campaign, so it
        did not earn a share.</b>
      <?php elseif ($played && $me->efficiency_score !== null): ?>
        Efficiency <b><?= (int)$me->efficiency_score ?>%</b> — what you got back
        against what you put in.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- ── the board ────────────────────────────────────────────────────── -->
  <div class="rp-grid">
    <?php foreach ($camps as $cid => $c): ?>
      <div class="rp-camp<?= $c['won'] ? ' won' : '' ?><?= $cid === $mine_cid ? ' mine' : '' ?>">
        <div class="rp-camp-head">
          <span class="rp-dot" style="background:<?= $c['color'] ?>"></span>
          <b style="color:<?= $c['color'] ?>"><?= html_escape($c['name']) ?></b>
          <?php if ($c['won']): ?><i class="ph-fill ph-trophy rp-trophy"></i><?php endif; ?>
          <?php if ($cid === $mine_cid): ?><em class="rp-tag">you</em><?php endif; ?>
          <span class="right"><?= number_format($c['power'], 0) ?></span>
        </div>

        <?php /* Power is the headline number, but the odds are what the round
                 was actually decided on, so both belong on the card. */ ?>
        <div class="rp-odds">
          <span class="rp-odds-n" style="color:<?= $c['color'] ?>"><?= number_format($c['win_chance'], 1) ?>%</span>
          <span class="rp-odds-l">chance to win</span>
          <?php if ($rp['leader'] === $cid && !$c['won']): ?>
            <em class="rp-tag lead">strongest</em>
          <?php endif; ?>
        </div>

        <div class="rp-bar"><div style="width:<?= max(2, min(100, $c['share'])) ?>%;background:<?= $c['color'] ?>"></div></div>
        <div class="rp-meta">
          <?= number_format($c['face_total']) ?> tokens
          (<?= number_format($c['share'], 1) ?>%) · <?= (int)$c['backers'] ?> backers
          · <?= html_escape($c['stability']) ?><?= $c['collapsed'] ? ' · collapsed' : '' ?>
        </div>

        <?php /* Ranked by distance from 1.0, so these are the multipliers that
                 actually decided it rather than the ones easiest to describe. */ ?>
        <?php $why = array_slice($c['why'], 0, 4); ?>
        <?php if ($why): ?>
          <ul class="rp-why">
            <?php foreach ($why as $f): $up = $f['pct'] > 0; ?>
              <li class="<?= $up ? 'up' : 'down' ?>">
                <i class="ph-fill ph-<?= $up ? 'trend-up' : 'trend-down' ?>"></i>
                <span class="rp-why-l"><?= html_escape($f['label']) ?></span>
                <span class="rp-why-p"><?= ($up ? '+' : '') . number_format($f['pct'], 1) ?>%</span>
                <span class="rp-why-n"><?= html_escape($f['note']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="rp-why-none">Nothing moved this campaign far from baseline.</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ── where the tokens went ────────────────────────────────────────── -->
  <div class="rp-foot">
    <span><b><?= number_format($rp['grand']) ?></b> tokens contested</span>
    <span><b><?= number_format($rp['pool']) ?></b> paid out to the winning side</span>
    <?php if ($rp['event']): ?>
      <?php /* With what it did, not just its name — "Regulation Crackdown" does
               not explain why nobody's attacks landed; "Defence x1.50, attacks
               x0.70" does, and it is the reason a conflict-damage line reads the
               way it does above. */ ?>
      <?php $ed = GameEngine::event_desc($rp['event']); ?>
      <span>Event <b><?= html_escape($rp['event']) ?></b><?= $ed ? ' — '.html_escape($ed) : '' ?></span>
    <?php endif; ?>
    <a href="<?= base_url('history') ?>">Full history <i class="ph ph-arrow-right"></i></a>
  </div>
</div>
