<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$guest = !$me;
$mycid = $me && $me->campaign_id !== null ? (int)$me->campaign_id : null;
?>

<div class="cockpit">

  <?php /* ══════════════════════════════════════════════════ REPORT
           Only between rounds. It leads the page because while the board is
           closed there is nothing to do and only one thing worth knowing: what
           happened. Its own grid row, full width, above all three columns. */ ?>
  <?php if (!empty($report)): ?>
  <div class="ck-report">
    <?php $this->load->view('game/_report', ['report' => $report]); ?>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════ LEFT / TOP -->
  <div class="ck-left">

    <!-- round strip -->
    <div class="hud">
      <div class="hud-hdr">
        <i class="ph-fill ph-broadcast"></i> Live Round
        <span class="right" id="rs-status">connecting</span>
      </div>
      <div class="hud-body">
        <div class="hud-line">
          <span class="k">Round</span><span class="v teal" id="rs-no">—</span>
        </div>
        <div class="hud-line">
          <span class="k">Phase</span><span class="v" id="rs-phase">—</span>
        </div>
        <div class="hud-line">
          <span class="k">Contribution weight</span><span class="v" id="rs-weight">—</span>
        </div>
        <div class="hud-line">
          <span class="k">Closes in</span><span class="v teal" id="rs-remaining">—</span>
        </div>
        <div class="budget-bar" style="margin-top:8px">
          <div class="budget-fill" id="rs-progress" style="width:0%"></div>
        </div>
        <div class="tiny muted" id="rs-window">—</div>
      </div>
    </div>

    <!-- the six headline stats -->
    <div class="card flush">
      <div class="panel-title">
        <i class="ph ph-gauge"></i>
        <span id="stat-owner">Leading campaign</span>
      </div>
      <div class="stat-grid six" id="stat-grid">
        <div class="stat" data-stat="power">
          <div class="stat-label">Power</div>
          <div class="stat-value teal" data-v>—</div>
          <div class="stat-delta" data-d>—</div>
        </div>
        <div class="stat" data-stat="trust">
          <div class="stat-label">Trust</div>
          <div class="stat-value" data-v>—</div>
          <div class="stat-delta" data-d>—</div>
        </div>
        <div class="stat" data-stat="cred">
          <div class="stat-label">Credibility</div>
          <div class="stat-value" data-v>—</div>
          <div class="stat-delta" data-d>—</div>
        </div>
        <div class="stat" data-stat="stab">
          <div class="stat-label">Stability</div>
          <div class="stat-value" data-v>—</div>
          <div class="stat-delta" data-d>—</div>
        </div>
        <div class="stat" data-stat="aggr">
          <div class="stat-label">Aggr. Cost</div>
          <div class="stat-value" data-v>—</div>
          <div class="stat-delta" data-d>—</div>
        </div>
        <div class="stat" data-stat="pop">
          <div class="stat-label">Pop. Mod</div>
          <div class="stat-value" data-v>—</div>
          <div class="stat-delta" data-d>—</div>
        </div>
      </div>
    </div>

    <!-- who is winning -->
    <div class="card">
      <h3 class="sub-sec"><i class="ph-fill ph-trophy"></i> Standings</h3>
      <div id="standings">
        <div class="empty"><i class="ph ph-circle-notch ph-spin"></i>Reading the board…</div>
      </div>
      <div class="tiny muted mt" id="standings-note">
        Win chance is power-weighted. The underdog can still take the round.
      </div>
    </div>

    <?php if ($guest): ?>
    <!-- guest hook -->
    <div class="guest-cta">
      <h2>You are watching, not playing.</h2>
      <p>Every number above is live. Create an account and you get
         <b><?= number_format($this->m_user->starting_balance()) ?></b> campaign tokens
         to put behind a side.</p>
      <div class="row wrap" style="justify-content:center">
        <a class="btn btn-primary" href="<?= base_url('register') ?>">
          <i class="ph-fill ph-user-plus"></i> Create account</a>
        <a class="btn btn-ghost" href="<?= base_url('login') ?>">
          <i class="ph ph-sign-in"></i> Sign in</a>
      </div>
    </div>
    <?php elseif ($mycid === null): ?>
    <div class="guest-cta">
      <h2>Pick a side.</h2>
      <p>You are holding <b id="cta-balance"><?= number_format($me->balance) ?></b> tokens
         and no campaign to spend them on.</p>
      <a class="btn btn-primary" href="<?= base_url('choose') ?>">
        <i class="ph-fill ph-flag"></i> Choose your campaign</a>
    </div>
    <?php endif; ?>

  </div>

  <!-- ══════════════════════════════════════════════════ RADAR
       Its own block rather than living inside .ck-center, so that on a phone
       it can lead the page instead of sitting below the whole left column.
       On desktop the grid puts it straight back above the feed. -->
  <div class="ck-radar">
    <div class="radar-wrap" id="radar-wrap">
      <canvas id="radar"></canvas>

      <div class="radar-tag tl">RND <b id="rd-round">—</b></div>
      <div class="radar-tag tr" id="rd-event">NO EVENT DETECTED</div>
      <?php /* The range is the whole reason the plate is framed the way it is:
               1,000 km from the centre of the archipelago is what it takes to
               close around the Batanes and Tawi-Tawi at the same time. */ ?>
      <div class="radar-tag bl">RANGE <b>1000 KM</b></div>
      <div class="radar-tag br">PHASE <b id="rd-phase">—</b></div>

      <?php /* Wallet and Climate used to float here as glass panels. They now
               live in the statbar under the header — see _head.php. Nothing but
               the four corner tags goes over the plate any more. */ ?>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════ CENTER -->
  <div class="ck-center">

    <!-- the scrolling verbose ticker -->
    <div class="hud">
      <div class="hud-hdr">
        <i class="ph-fill ph-radio"></i> Activity
        <span class="right"><span id="feed-count">0</span> events</span>
      </div>
      <div class="hud-body">
        <div class="feed" id="feed">
          <?php foreach ($feed as $f): ?>
          <div class="feed-row">
            <div class="feed-ic <?= html_escape($f->severity) ?>"><i class="ph ph-dot"></i></div>
            <div class="feed-body">
              <div class="feed-txt"><?= html_escape($f->body) ?></div>
              <div class="feed-time"><?= date('H:i', strtotime($f->created_at)) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (!$feed): ?>
          <div class="empty"><i class="ph ph-radio"></i>Nothing on the wire yet.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>

  <!-- ══════════════════════════════════════════════════ RIGHT / BOTTOM -->
  <div class="ck-right">

    <!-- incoming -->
    <div class="card">
      <h3 class="sub-sec"><i class="ph-fill ph-warning"></i> Incoming</h3>
      <div id="threats">
        <div class="empty"><i class="ph ph-shield-check"></i>No conflicts in flight.</div>
      </div>
    </div>

    <!-- campaign shape -->
    <div class="card">
      <h3 class="sub-sec"><i class="ph ph-chart-bar"></i> Core allocation</h3>
      <div class="row wrap mb" id="shape-tabs"></div>
      <div id="shape"></div>
      <div class="tiny muted mt" id="shape-note"></div>
    </div>

    <!-- signals -->
    <div class="card">
      <h3 class="sub-sec"><i class="ph ph-broadcast"></i> Signals</h3>
      <div id="signals">
        <div class="empty"><i class="ph ph-broadcast"></i>No broadcasts yet.</div>
      </div>
      <div class="tiny muted mt">Roughly a quarter of broadcast intent is misdirection.</div>
    </div>

    <!-- overall record -->
    <div class="card">
      <h3 class="sub-sec"><i class="ph-fill ph-crown"></i> Overall record</h3>
      <div id="champions"><div class="empty"><i class="ph ph-hourglass-medium"></i>No settled rounds yet.</div></div>

      <?php if ($history): ?>
      <h3 class="sub-sec mt-lg"><i class="ph ph-clock"></i> Recent rounds</h3>
      <?php foreach ($history as $h): ?>
        <div class="kv">
          <span class="k">Round <?= (int)$h->round_no ?></span>
          <span class="v" style="color:<?= $h->winner_campaign !== null
              ? GameEngine::$CAMPAIGNS[(int)$h->winner_campaign]['color'] : '#5C6E7C' ?>">
            <?= $h->winner_campaign !== null
                ? html_escape(GameEngine::campaign_name((int)$h->winner_campaign)) : 'pending' ?>
          </span>
        </div>
      <?php endforeach; ?>
      <a class="btn btn-ghost btn-sm btn-block mt" href="<?= base_url($me ? 'history' : 'login') ?>">
        <i class="ph ph-list"></i> Full history</a>
      <?php endif; ?>
    </div>

  </div>
</div>
