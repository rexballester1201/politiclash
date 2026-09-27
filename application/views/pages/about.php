<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div style="max-width:640px;margin:0 auto;padding:16px 0 40px">

  <p class="eyebrow">What this is</p>
  <h1 class="display" style="font-size:2.1rem">Running a campaign is<br><i>a resource problem.</i></h1>
  <p class="lede">PolitiClash is a three-way political simulation. Each day you split a budget
    across ten campaign systems, watch two rivals do the same, and find out at 21:00 whose
    machine held together.</p>

  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-clock"></i> One round a day</h3>
    <p class="tiny">A round opens at <b>midnight</b> and closes at <b>21:00</b>, Philippine time.
    Inside that window you place tokens whenever you like — but <em>when</em> matters as much
    as how much:</p>
    <div class="kv"><span class="k">00:00 – 10:30 · early</span><span class="v">×1.000</span></div>
    <div class="kv"><span class="k">10:30 – 17:51 · mid</span><span class="v teal">×1.050</span></div>
    <div class="kv"><span class="k">17:51 – 21:00 · late</span><span class="v warn">×0.85–0.95</span></div>
    <p class="tiny muted mt">The late multiplier is drawn fresh at every round open and a per-player
    cap applies. Sitting out the day and dumping at the buzzer is a losing move by design.</p>
  </div>

  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-squares-four"></i> The ten systems</h3>
    <p class="tiny mb">Seven build your core. Two attack. One defends.</p>
    <?php foreach (GameEngine::$SYS_META as $k => $m):
      $notes = [
        'funding' => 'Feeds policy impact and wins conflicts. Also a hard sustainability gate.',
        'organization' => 'Multiplies everything else you spend. Rarely the wrong answer.',
        'logistics' => 'Infrastructure multiplier. Half of it carries into tomorrow.',
        'policy' => 'Credibility and appeal, capped at ×1.25. Half carries over.',
        'charisma' => 'Straight power multiplier with no cap and no side effects.',
        'messaging' => 'Reach — but it saturates past half your core and the excess is burned.',
        'opposition_research' => 'Buys the chance to identify the hidden national event.',
        'attack_ads' => 'Damages rivals. Costs you trust, which costs you power.',
        'negative_campaigning' => 'Hits harder, costs more trust, and drives your backfire risk.',
        'coalitions' => 'The only thing that blunts an incoming attack. Diminishing past 12%.',
      ]; ?>
      <div class="alloc-row <?= $m['group'] === 'attack' ? 'atk' : ($m['group'] === 'defense' ? 'def' : '') ?>">
        <div class="alloc-ic"><i class="ph-fill ph-<?= $m['icon'] ?>"></i></div>
        <div class="alloc-meta">
          <div class="alloc-name"><?= $m['label'] ?></div>
          <div class="alloc-hint" style="white-space:normal"><?= $notes[$k] ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-shield-check"></i> The sustainability gate</h3>
    <p class="tiny">A campaign must keep at least <b>15%</b> of its core in Finance,
    <b>12%</b> in Organisation and <b>8%</b> in Logistics. Miss any one and it is in
    <b style="color:var(--loss)">collapse</b>.</p>
    <div class="kv"><span class="k">Win</span><span class="v teal">Share of the losers' pool</span></div>
    <div class="kv"><span class="k">Lose, but sustainable</span><span class="v">15% of your stake back</span></div>
    <div class="kv"><span class="k">Lose, in collapse</span><span class="v loss">Nothing</span></div>
    <p class="tiny muted mt">Discipline pays even in defeat. That is the whole lesson of the
    system, and it is not optional.</p>
  </div>

  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-scales"></i> Why greed is self-limiting</h3>
    <p class="tiny">Almost every constant in the engine exists to punish an obvious exploit.
    You do not need to memorise them — you will feel them.</p>
    <div class="kv"><span class="k">Go all-in on attacks</span><span class="v">Trust falls, power falls, attacks backfire</span></div>
    <div class="kv"><span class="k">Spam messaging</span><span class="v">Saturates; the excess is burned</span></div>
    <div class="kv"><span class="k">Bandwagon onto the leader</span><span class="v">Up to −35% size penalty</span></div>
    <div class="kv"><span class="k">Back the underdog</span><span class="v teal">Up to +40%</span></div>
    <div class="kv"><span class="k">Everyone stays calm</span><span class="v teal">Up to +8% for all of you</span></div>
    <div class="kv"><span class="k">Outspend everyone</span><span class="v">Above 25% of the pot counts at half</span></div>
    <div class="kv"><span class="k">Dogpile one rival</span><span class="v">Fourth wave lands at 55%</span></div>
  </div>

  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-medal"></i> Playing a long game</h3>
    <p class="tiny">Hold the same allocation shape for three rounds running and it becomes a
    permanent passive — Policy Powerhouse, Ground Game Machine, Media Darling, Alliance
    Builder, Opposition Specialist. Half your Logistics and Policy carries into tomorrow.
    Win and you carry momentum; lose three in a row and you get an underdog bonus, once.</p>
    <p class="tiny muted mt">Switching campaigns costs a slice of your wallet, voids the
    carry-over, and resets every streak to zero. Loyalty is a mechanic, not a mood.</p>
  </div>

  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-dice-five"></i> Power buys odds, not outcomes</h3>
    <p class="tiny">At 21:00 each campaign's power is computed and the winner is <em>drawn</em>,
    weighted by power. A campaign with twice the power does not win twice as often —
    it wins about 1.87× as often, and it can still lose. A hidden ±5% variance is applied
    and never shown. Two identical positions will not always produce identical results,
    which is true of elections too.</p>
  </div>

  <div class="card mb">
    <h3 class="sub-sec"><i class="ph ph-coins"></i> Tokens</h3>
    <p class="tiny">Tokens are game currency and nothing else. There is no deposit, no
    withdrawal, no purchase and no cash value. New accounts start with
    <?= number_format($this->m_user->starting_balance()) ?>, and if your balance falls too
    low you are topped back up at the next round open — a bad run should cost you a round,
    not the game.</p>
  </div>

  <div class="card">
    <h3 class="sub-sec"><i class="ph ph-info"></i> On the names</h3>
    <p class="tiny">The three campaigns are named for figures from a recent Philippine
    presidential race. They are labels for three otherwise identical sides — the rules
    do not differ between them, and nothing here models any real person's actual platform,
    conduct or record. It is a strategy game about resource allocation that happens to wear
    a political costume. It is not affiliated with, sponsored by or endorsed by any
    politician or party.</p>
  </div>

  <div class="row wrap mt-lg" style="justify-content:center">
    <a class="btn btn-primary" href="<?= base_url() ?>"><i class="ph ph-crosshair"></i> Open the war room</a>
    <a class="btn btn-ghost" href="<?= base_url('faq') ?>"><i class="ph ph-question"></i> FAQ</a>
  </div>
</div>
