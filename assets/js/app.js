/* ============================================================================
   PolitiClash — shared app shell
   Bottom-nav pull-up stacks, add-to-home-screen, service worker, toasts,
   and the tiny fetch helper every page uses.
   No emoji anywhere; all glyphs come from the Phosphor webfont.
============================================================================ */
(function (w, d) {
  'use strict';

  var PC = w.PC || (w.PC = {});
  PC.base = PC.base || '/';

  /* ── utilities ─────────────────────────────────────────────────────────── */

  PC.$  = function (s, r) { return (r || d).querySelector(s); };
  PC.$$ = function (s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); };

  PC.url = function (p) {
    return PC.base.replace(/\/+$/, '') + '/' + String(p).replace(/^\/+/, '');
  };

  PC.num = function (n, dp) {
    if (n === null || n === undefined || isNaN(n)) return '—';
    return Number(n).toLocaleString('en-US', {
      minimumFractionDigits: dp || 0, maximumFractionDigits: dp || 0
    });
  };

  PC.clock = function (secs) {
    secs = Math.max(0, Math.floor(secs || 0));
    var h = Math.floor(secs / 3600),
        m = Math.floor((secs % 3600) / 60),
        s = secs % 60,
        p = function (x) { return x < 10 ? '0' + x : '' + x; };
    return h > 0 ? p(h) + ':' + p(m) + ':' + p(s) : p(m) + ':' + p(s);
  };

  PC.ago = function (secs) {
    if (secs < 45) return 'just now';
    if (secs < 3600) return Math.floor(secs / 60) + 'm ago';
    if (secs < 86400) return Math.floor(secs / 3600) + 'h ago';
    return Math.floor(secs / 86400) + 'd ago';
  };

  /* ── fetch ─────────────────────────────────────────────────────────────── */

  PC.get = function (path, params) {
    var q = params ? '?' + new URLSearchParams(params).toString() : '';
    return fetch(PC.url(path) + q, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); });
  };

  PC.post = function (path, body) {
    var fd = new FormData();
    (function walk(obj, prefix) {
      Object.keys(obj || {}).forEach(function (k) {
        var v = obj[k], key = prefix ? prefix + '[' + k + ']' : k;
        if (v !== null && typeof v === 'object') walk(v, key);
        else fd.append(key, v);
      });
    })(body || null, '');

    return fetch(PC.url(path), {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json().catch(function () { return { ok: false, reason: 'bad_response' }; }); });
  };

  /* Human-readable text for the reason codes the API returns. */
  var REASONS = {
    round_closed:          'The round is closed. Contributions reopen at midnight.',
    no_campaign:           'Pick a campaign first.',
    not_logged_in:         'Sign in to do that.',
    insufficient_balance:  'Not enough tokens for that.',
    nothing_to_place:      'Nothing entered.',
    must_contribute_first: 'Place tokens before using tactical actions.',
    cannot_attack_self:    'You cannot attack your own campaign.',
    cannot_scan_self:      'You already see your own books.',
    already_active:        'That surge is already running.',
    cold_campaign:         'A Cold Campaign is suppressing all conflict this round.',
    target_too_hard:       'That target is too well defended to be worth attacking.',
    late_cap:              'Late-phase cap reached. Large late contributions are limited.',
    same_campaign:         'You are already with that campaign.',
    bad_target:            'Unknown target.',
    db_error:              'Something went wrong saving that. Try again.'
  };
  PC.reason = function (code) { return REASONS[code] || 'That did not work (' + code + ').'; };

  /* ── toast ─────────────────────────────────────────────────────────────── */

  var toastEl = null, toastT = null;
  PC.toast = function (msg, tone) {
    if (!toastEl) {
      toastEl = d.createElement('div');
      toastEl.style.cssText =
        'position:fixed;left:12px;right:12px;bottom:calc(var(--nav-h) + 74px);z-index:500;' +
        'padding:12px 14px;border-radius:10px;font-size:13px;line-height:1.4;' +
        'backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);' +
        'box-shadow:0 14px 40px -16px #000;transition:opacity .25s,transform .25s;' +
        'display:flex;gap:9px;align-items:flex-start;max-width:520px;margin:0 auto;';
      d.body.appendChild(toastEl);
    }
    var map = {
      ok:   ['rgba(0,229,204,.12)',  'rgba(0,229,204,.4)',  '#00E5CC', 'ph-check-circle'],
      bad:  ['rgba(255,78,100,.12)', 'rgba(255,78,100,.4)', '#FF4E64', 'ph-warning-octagon'],
      warn: ['rgba(255,176,32,.12)', 'rgba(255,176,32,.4)', '#FFB020', 'ph-warning'],
      info: ['rgba(61,141,180,.14)', 'rgba(61,141,180,.4)', '#3D8DB4', 'ph-info']
    };
    var c = map[tone || 'info'];
    toastEl.style.background = c[0];
    toastEl.style.border = '1px solid ' + c[1];
    toastEl.style.color = c[2];
    toastEl.innerHTML = '<i class="ph-fill ' + c[3] + '" style="font-size:17px;flex:0 0 auto"></i><div>' + msg + '</div>';
    toastEl.style.opacity = '1';
    toastEl.style.transform = 'none';

    clearTimeout(toastT);
    toastT = setTimeout(function () {
      toastEl.style.opacity = '0';
      toastEl.style.transform = 'translateY(8px)';
    }, 4200);
  };

  /* ── modal ─────────────────────────────────────────────────────────────── */

  PC.modal = function (html) {
    var ov = PC.$('#overlay'), dlg = PC.$('#overlay-dlg');
    if (!ov || !dlg) return;
    dlg.innerHTML = html;
    ov.classList.add('open');
  };
  PC.closeModal = function () {
    var ov = PC.$('#overlay');
    if (ov) ov.classList.remove('open');
  };
  d.addEventListener('click', function (e) {
    if (e.target && e.target.id === 'overlay') PC.closeModal();
    if (e.target && e.target.closest && e.target.closest('[data-close-modal]')) PC.closeModal();
  });

  /* ── bottom-nav pull-up stacks ─────────────────────────────────────────── */

  var openStack = null;

  function closeStacks() {
    PC.$$('.stack').forEach(function (s) { s.classList.remove('open'); });
    var v = PC.$('#stack-veil');
    if (v) v.classList.remove('open');
    /* Clear the transient open-state highlight, but put back the one marking
       the page you are actually on (Intel and Threats sit under Game; FAQ and
       About sit under Info) — otherwise closing a stack wipes the indicator. */
    PC.$$('.nav-item[data-stack]').forEach(function (b) {
      b.classList.toggle('on', b.hasAttribute('data-current'));
    });
    openStack = null;
  }

  function toggleStack(name) {
    var el = PC.$('#stack-' + name);
    if (!el) return;
    if (openStack === name) { closeStacks(); return; }
    closeStacks();
    el.classList.add('open');
    var v = PC.$('#stack-veil');
    if (v) v.classList.add('open');
    var btn = PC.$('.nav-item[data-stack="' + name + '"]');
    if (btn) btn.classList.add('on');
    openStack = name;
  }

  PC.closeStacks = closeStacks;

  d.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-stack]') : null;
    if (btn) { e.preventDefault(); toggleStack(btn.getAttribute('data-stack')); return; }
    if (openStack && !e.target.closest('.stack')) closeStacks();
  });
  d.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeStacks(); PC.closeModal(); }
  });

  /* ── header clock ──────────────────────────────────────────────────────── */
  /* Ticks locally between polls so the countdown never looks frozen. */

  PC.roundEndsAt = null;
  PC.roundOpen = false;

  function tickClock() {
    var el = PC.$('#hdr-clock-txt'), box = PC.$('#hdr-clock');
    if (!el) return;

    if (PC.roundEndsAt === null) {
      var n = new Date();
      el.textContent = ('0' + n.getHours()).slice(-2) + ':' +
                       ('0' + n.getMinutes()).slice(-2) + ':' +
                       ('0' + n.getSeconds()).slice(-2);
      return;
    }
    var left = Math.max(0, PC.roundEndsAt - Math.floor(Date.now() / 1000));
    el.textContent = PC.roundOpen ? PC.clock(left) : 'CLOSED';
    if (box) box.classList.toggle('closed', !PC.roundOpen || left <= 0);
  }
  setInterval(tickClock, 1000);
  tickClock();

  /* ── add to home screen ────────────────────────────────────────────────── */

  var deferredPrompt = null;
  var A2HS_KEY = 'pc_a2hs_dismissed';

  w.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    if (localStorage.getItem(A2HS_KEY)) return;
    var bar = PC.$('#a2hs');
    // Give the player a moment with the board before asking for anything.
    if (bar) setTimeout(function () { bar.classList.add('show'); }, 12000);
  });

  d.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('#a2hs-go')) {
      var bar = PC.$('#a2hs');
      if (bar) bar.classList.remove('show');
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      deferredPrompt.userChoice.then(function (c) {
        if (c.outcome === 'accepted') PC.toast('Installed. Look for PolitiClash on your home screen.', 'ok');
        deferredPrompt = null;
      });
    }
    if (e.target.closest && e.target.closest('#a2hs-no')) {
      var b = PC.$('#a2hs');
      if (b) b.classList.remove('show');
      try { localStorage.setItem(A2HS_KEY, '1'); } catch (err) {}
    }
  });

  w.addEventListener('appinstalled', function () {
    try { localStorage.setItem(A2HS_KEY, '1'); } catch (err) {}
  });

  /* ── service worker ────────────────────────────────────────────────────── */

  if ('serviceWorker' in navigator) {
    w.addEventListener('load', function () {
      navigator.serviceWorker.register(PC.url('sw.js'), { scope: PC.base })
        .catch(function (err) { console.warn('SW registration failed', err); });
    });
  }

  /* ── password reveal ───────────────────────────────────────────────────── */

  d.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-reveal]') : null;
    if (!b) return;
    var inp = PC.$('#' + b.getAttribute('data-reveal'));
    if (!inp) return;
    var show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    b.innerHTML = '<i class="ph ' + (show ? 'ph-eye-slash' : 'ph-eye') + '"></i>';
  });

})(window, document);
