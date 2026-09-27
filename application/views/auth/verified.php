<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div style="max-width:420px;margin:0 auto;padding:34px 0 40px;text-align:center">

<?php if ($state === 'ok'): ?>
  <div style="font-size:56px;color:var(--teal);line-height:1"><i class="ph-fill ph-seal-check"></i></div>
  <h1 class="display" style="font-size:1.9rem;margin-top:14px">Verified</h1>
  <p class="lede" style="margin:0 auto 24px">
    Welcome aboard, <b><?= html_escape($u->username) ?></b>. Your account is confirmed
    and your tokens are waiting.
  </p>
  <a class="btn btn-primary btn-block" href="<?= base_url($u->campaign_id === null ? 'choose' : '') ?>">
    <i class="ph-fill ph-flag"></i>
    <?= $u->campaign_id === null ? 'Choose your campaign' : 'Open the war room' ?>
  </a>

<?php elseif ($state === 'expired'): ?>
  <div style="font-size:56px;color:var(--warn);line-height:1"><i class="ph-fill ph-hourglass-medium"></i></div>
  <h1 class="display" style="font-size:1.9rem;margin-top:14px">Link expired</h1>
  <p class="lede" style="margin:0 auto 24px">
    Verification links last 48 hours. Sign in and we will send a fresh one.
  </p>
  <a class="btn btn-primary btn-block" href="<?= base_url('login') ?>">
    <i class="ph ph-sign-in"></i> Sign in</a>

<?php else: ?>
  <div style="font-size:56px;color:var(--loss);line-height:1"><i class="ph-fill ph-x-circle"></i></div>
  <h1 class="display" style="font-size:1.9rem;margin-top:14px">Bad link</h1>
  <p class="lede" style="margin:0 auto 24px">
    That verification link is not one of ours, or it has already been used.
  </p>
  <a class="btn btn-ghost btn-block" href="<?= base_url() ?>">
    <i class="ph ph-crosshair"></i> Back to the war room</a>
<?php endif; ?>

</div>
