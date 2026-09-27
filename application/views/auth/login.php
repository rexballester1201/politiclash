<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$old = $this->session->flashdata('old') ?: [];
$err = $this->session->flashdata('err');
$ok  = $this->session->flashdata('ok');
?>
<div style="max-width:420px;margin:0 auto;padding:18px 0 40px">

  <p class="eyebrow">Return to post</p>
  <h1 class="display">Sign <i>in</i></h1>

  <?php if ($err): ?>
    <div class="alert bad"><i class="ph-fill ph-warning-octagon"></i><div><?= html_escape($err) ?></div></div>
  <?php endif; ?>
  <?php if ($ok): ?>
    <div class="alert ok"><i class="ph-fill ph-check-circle"></i><div><?= html_escape($ok) ?></div></div>
  <?php endif; ?>

  <form method="post" action="<?= base_url('auth/do_login') ?>" autocomplete="on">
    <div class="field">
      <label for="login">Username or email</label>
      <div class="input-wrap">
        <i class="ph ph-user-circle"></i>
        <input class="input" type="text" id="login" name="login" required
               autocomplete="username" placeholder="your handle or address"
               value="<?= html_escape(isset($old['login']) ? $old['login'] : '') ?>">
      </div>
    </div>

    <div class="field">
      <label for="password">Password</label>
      <div class="input-wrap">
        <i class="ph ph-lock"></i>
        <input class="input" type="password" id="password" name="password" required
               autocomplete="current-password" placeholder="your password">
        <button class="input-btn" type="button" data-reveal="password" aria-label="Show password">
          <i class="ph ph-eye"></i></button>
      </div>
    </div>

    <button class="btn btn-primary btn-block" type="submit">
      <i class="ph-fill ph-sign-in"></i> Sign in
    </button>
  </form>

  <p class="tiny muted mt-lg" style="text-align:center">
    No account yet? <a href="<?= base_url('register') ?>">Create one</a> and start with
    <?= number_format($this->m_user->starting_balance()) ?> tokens.
  </p>
</div>
