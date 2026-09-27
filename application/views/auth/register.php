<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$old = $this->session->flashdata('old') ?: [];
$err = $this->session->flashdata('err');
?>
<div style="max-width:440px;margin:0 auto;padding:18px 0 40px">

  <p class="eyebrow">Enlist</p>
  <h1 class="display">Pick a side.<br><i>Fund it.</i></h1>
  <p class="lede">Four fields and you are in. You start with
    <b><?= number_format($this->m_user->starting_balance()) ?></b> campaign tokens and
    a choice of three campaigns.</p>

  <?php if ($err): ?>
    <div class="alert bad"><i class="ph-fill ph-warning-octagon"></i><div><?= html_escape($err) ?></div></div>
  <?php endif; ?>

  <form method="post" action="<?= base_url('auth/do_register') ?>" id="reg-form" autocomplete="on">

    <div class="field">
      <label for="username">Username</label>
      <div class="input-wrap">
        <i class="ph ph-identification-badge"></i>
        <input class="input" type="text" id="username" name="username" required
               minlength="3" maxlength="32" pattern="[A-Za-z0-9._\-]{3,32}"
               autocomplete="username" placeholder="how the feed will name you"
               value="<?= html_escape(isset($old['username']) ? $old['username'] : '') ?>">
      </div>
      <div class="hint" id="h-username">3–32 characters. Letters, numbers, dot, dash, underscore.</div>
    </div>

    <div class="field">
      <label for="email">Email</label>
      <div class="input-wrap">
        <i class="ph ph-envelope-simple"></i>
        <input class="input" type="email" id="email" name="email" required maxlength="190"
               autocomplete="email" placeholder="you@example.com"
               value="<?= html_escape(isset($old['email']) ? $old['email'] : '') ?>">
      </div>
      <div class="hint" id="h-email">We send one verification link here. Nothing else.</div>
    </div>

    <div class="field">
      <label for="password">Password</label>
      <div class="input-wrap">
        <i class="ph ph-lock"></i>
        <input class="input" type="password" id="password" name="password" required
               minlength="8" autocomplete="new-password" placeholder="at least 8 characters">
        <button class="input-btn" type="button" data-reveal="password" aria-label="Show password">
          <i class="ph ph-eye"></i></button>
      </div>
    </div>

    <div class="field">
      <label for="password2">Confirm password</label>
      <div class="input-wrap">
        <i class="ph ph-key"></i>
        <input class="input" type="password" id="password2" name="password2" required
               minlength="8" autocomplete="new-password" placeholder="type it again">
        <button class="input-btn" type="button" data-reveal="password2" aria-label="Show password">
          <i class="ph ph-eye"></i></button>
      </div>
      <div class="hint" id="h-match"></div>
    </div>

    <button class="btn btn-primary btn-block" type="submit" id="reg-go">
      <i class="ph-fill ph-user-plus"></i> Create account
    </button>
  </form>

  <p class="tiny muted mt-lg" style="text-align:center">
    Already enlisted? <a href="<?= base_url('login') ?>">Sign in</a>
  </p>

  <div class="card mt-lg">
    <h3 class="sub-sec"><i class="ph ph-info"></i> What you are joining</h3>
    <p class="tiny">One round runs every day, midnight to 21:00. You spread tokens across ten
    campaign systems, watch two rival campaigns do the same, and the count at 21:00 decides
    who takes the pot. Power buys better odds — never certainty.</p>
  </div>
</div>

<script>
(function () {
  var PC = window.PC;
  var u = PC.$('#username'), e = PC.$('#email'),
      p = PC.$('#password'), p2 = PC.$('#password2');

  function mark(el, ok, msg) {
    el.className = 'hint ' + (ok === null ? '' : (ok ? 'ok' : 'bad'));
    el.innerHTML = (ok === null ? '' :
      '<i class="ph ' + (ok ? 'ph-check-circle' : 'ph-x-circle') + '"></i>') + msg;
  }

  var t1;
  u.addEventListener('input', function () {
    clearTimeout(t1);
    var v = u.value.trim();
    if (v.length < 3) return mark(PC.$('#h-username'), null,
      '3–32 characters. Letters, numbers, dot, dash, underscore.');
    t1 = setTimeout(function () {
      PC.get('auth/check_username', { u: v }).then(function (r) {
        mark(PC.$('#h-username'), r.ok,
          r.ok ? 'Available.' : (r.reason === 'format' ? 'Invalid characters.' : 'Already taken.'));
      });
    }, 380);
  });

  var t2;
  e.addEventListener('input', function () {
    clearTimeout(t2);
    var v = e.value.trim();
    if (v.indexOf('@') < 0) return mark(PC.$('#h-email'), null,
      'We send one verification link here. Nothing else.');
    t2 = setTimeout(function () {
      PC.get('auth/check_email', { e: v }).then(function (r) {
        mark(PC.$('#h-email'), r.ok,
          r.ok ? 'Good to go.' : (r.reason === 'format' ? 'That is not a valid address.'
                                                        : 'Already registered — try signing in.'));
      });
    }, 380);
  });

  function checkMatch() {
    if (!p2.value) return mark(PC.$('#h-match'), null, '');
    mark(PC.$('#h-match'), p.value === p2.value,
      p.value === p2.value ? 'Passwords match.' : 'Passwords do not match.');
  }
  p.addEventListener('input', checkMatch);
  p2.addEventListener('input', checkMatch);
})();
</script>
