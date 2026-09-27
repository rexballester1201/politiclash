<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$faq = [
  ['Getting started', [
    ['Do I need an account to look around?',
     'No. The war room is fully readable as a guest — live power figures, standings, the activity feed, incoming conflicts, everything. You just cannot place tokens or use the tactical pads until you sign up.'],
    ['What do I need to register?',
     'A username, an email, and a password. Nothing else. Username and email both have to be unused. You get '.number_format($this->m_user->starting_balance()).' tokens immediately and can start playing in the same session — the verification email is for the record, it does not block you.'],
    ['How do I pick a campaign?',
     'After signing up you land on the campaign picker. The three sides are mechanically identical; what differs is how many people are already on each. The crowded one carries a size penalty, the emptiest one gets an underdog bonus, so joining the losing side is often the better play.'],
    ['Can I change sides later?',
     'Yes, and it costs. The first switch takes 5% of your wallet, the second 10%, and 20% after that. It also destroys your carry-over and resets every identity trait streak to zero.'],
  ]],

  ['The round', [
    ['When can I play?',
     'A round opens at midnight and closes at 21:00 Philippine time, every day. Settlement runs right after close and results are on the board within a minute or two.'],
    ['Does it matter when in the day I contribute?',
     'A lot. Early (00:00–10:30) banks at ×1.000, mid (10:30–17:51) at ×1.050, and late (17:51–21:00) at somewhere between ×0.85 and ×0.95 — drawn fresh at each round open so it cannot be predicted. Mid-round is the sweet spot.'],
    ['Can I just wait and dump everything at 20:59?',
     'No, and it would be a bad idea anyway. Late contributions bank at a discount and a per-player cap limits how much you can add in the closing phase.'],
    ['What happens if I miss a day?',
     'Nothing bad, except you earn nothing that round. Your traits do reset if you break a streak, and your maturity grows more slowly.'],
  ]],

  ['Winning and losing', [
    ['How is the winner decided?',
     'Each campaign gets a power figure from the full multiplier chain, then the winner is drawn at random weighted by power. Double the power is roughly 1.87× the odds, not a guarantee. There is also a hidden ±5% variance that is never displayed.'],
    ['Why did the stronger campaign lose?',
     'Because that is the design. If power decided outcomes deterministically the game would be over by mid-morning every day and nobody would back an underdog.'],
    ['What do I actually win?',
     'Winners get their stake back plus a share of the losers\' pool — 60% split pro-rata by contribution, 40% split by a square-root weighting scaled by your participation maturity. The square root is deliberate: it compresses the gap between large and small backers.'],
    ['I lost. Do I get anything?',
     'If your campaign stayed sustainable, you get 15% of your stake back. If it was in collapse — below the Finance, Organisation or Logistics minimums — you get nothing at all. This is the single most important rule in the game.'],
    ['I contributed but earned zero even though we won.',
     'You were probably below the participation floor, which sits at 1.5% of the average contribution in your campaign. It exists so dust cannot farm the team pool.'],
    ['Is there a cap on what I can earn?',
     'A soft one. Contribution above 25% of your campaign\'s total counts at half rate for reward purposes. One very large backer cannot take the whole pot.'],
  ]],

  ['Systems and tactics', [
    ['What is Stability and why does it keep shouting at me?',
     'It is the health of your campaign machine. Keep at least 15% of your core in Finance, 12% in Organisation and 8% in Logistics. Stable gives ×1.10 power; critical gives ×0.50; failing any threshold is collapse, and collapse means losers earn nothing.'],
    ['Should I spend on Attack Ads and Negative Campaigning?',
     'Sometimes, carefully. They damage rivals but drive your Trust down, which directly reduces your own power and increases the chance your attack backfires onto you. A campaign that goes all-in on attacks usually beats itself.'],
    ['What does backfire mean?',
     'When an attack resolves there is a chance it lands on you instead. That chance rises as your Trust falls and as you spend more on Negative Campaigning. Some events double it.'],
    ['Why is my messaging spend doing nothing?',
     'Saturation. Once Messaging passes 50% of your core, effectiveness is scaled down and the excess is simply burned. The Allocate page shows your saturation percentage live.'],
    ['What is Opposition Research for?',
     'It is an information system, not a power one. Every round has a hidden national event, and Opposition Research raises the chance a scan identifies it — 70% base, capped at 90%. You are never certain.'],
    ['What do the tactical pads do?',
     'Attack opens a conflict on a rival that lands after a delay. Scan buys a blurred readout of a rival\'s internals. Offense and Defense are 15-minute ±12% surges. Push Policy and Hold broadcast intent to the field — and about a quarter of everyone\'s broadcasts are bluffs.'],
    ['Why can I not use the pads?',
     'You have to place tokens in the current round first. Observers do not get tactical actions.'],
  ]],

  ['Progression', [
    ['What are identity traits?',
     'Hold the same allocation shape for three consecutive rounds and you earn a permanent +5% passive. There are five: Policy Powerhouse, Ground Game Machine, Media Darling, Alliance Builder and Opposition Specialist. Your Account page shows your streaks.'],
    ['What carries between rounds?',
     'Half of your Logistics and half of your Policy carry into the next round automatically, as long as you stay with the same campaign.'],
    ['What is the persistence multiplier?',
     'Form. The round after a win you get +10%, the round after that +5%. If you have been losing, you get a one-off +15% underdog bonus — once, not forever.'],
  ]],

  ['Practical', [
    ['Can I install this on my phone?',
     'Yes. It is a progressive web app. On Android, Chrome will offer to add it to your home screen, or use the prompt in the app. On iPhone, use Share then Add to Home Screen in Safari. It then runs full-screen with no browser chrome.'],
    ['Does it work offline?',
     'The app shell is cached so it opens instantly, but the board itself needs a connection — a stale war room would be worse than none.'],
    ['Are the other players real?',
     'Some of them are not. The roster includes simulated participants with their own personalities — some fiercely loyal, some who chase whoever is winning, some who mostly lurk. They play by exactly the same rules you do, with the same starting tokens and the same penalties for switching sides.'],
    ['Can I buy tokens? Can I cash out?',
     'No to both. Tokens are game currency with no purchase path and no cash value. If your balance drops too low you are topped back up at the next round open.'],
    ['I ran out of tokens.',
     'You will be topped up automatically the next time a round opens. A bad run costs you a round, not the game.'],
  ]],
];
?>
<div style="max-width:640px;margin:0 auto;padding:16px 0 40px">

  <p class="eyebrow">Help centre</p>
  <h1 class="display" style="font-size:2rem">Questions, <i>answered</i></h1>

  <?php foreach ($faq as $gi => $group): ?>
    <h3 class="sub-sec mt-lg"><i class="ph ph-caret-right"></i> <?= html_escape($group[0]) ?></h3>
    <?php foreach ($group[1] as $qi => $qa): ?>
      <div class="card mb" style="padding:0">
        <button class="row" type="button" data-faq="<?= $gi ?>-<?= $qi ?>"
          style="width:100%;background:none;border:0;padding:13px 14px;text-align:left;cursor:pointer;gap:10px">
          <i class="ph ph-caret-right" style="color:var(--teal);font-size:15px;transition:transform .2s;flex:0 0 auto"></i>
          <span style="flex:1;font-family:var(--f-ui);font-size:13.5px;font-weight:600;color:var(--ice)">
            <?= html_escape($qa[0]) ?></span>
        </button>
        <div id="faq-<?= $gi ?>-<?= $qi ?>" style="max-height:0;overflow:hidden;transition:max-height .25s ease">
          <p class="tiny" style="padding:0 14px 14px;margin:0;color:var(--mist)">
            <?= html_escape($qa[1]) ?></p>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <div class="row wrap mt-lg" style="justify-content:center">
    <a class="btn btn-primary" href="<?= base_url() ?>"><i class="ph ph-crosshair"></i> War room</a>
    <a class="btn btn-ghost" href="<?= base_url('about') ?>"><i class="ph ph-info"></i> About</a>
  </div>
</div>

<script>
document.querySelectorAll('[data-faq]').forEach(function (b) {
  b.addEventListener('click', function () {
    var body = document.getElementById('faq-' + b.getAttribute('data-faq')),
        car  = b.querySelector('i'),
        open = body.style.maxHeight && body.style.maxHeight !== '0px';
    body.style.maxHeight = open ? '0' : body.scrollHeight + 'px';
    car.style.transform = open ? '' : 'rotate(90deg)';
  });
});
</script>
