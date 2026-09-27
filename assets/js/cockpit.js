/* ============================================================================
   PolitiClash — war room cockpit

   Two poll loops feed everything:
     state  every 4s   the whole board
     feed   every 3s   incremental ticker rows

   Between polls the displayed numbers ease toward their targets on every
   animation frame, so the console reads as continuously alive rather than
   snapping once per request. The underlying values are always server truth;
   only the travel between them is local.
============================================================================ */
(function (w, d) {
  'use strict';

  var PC = w.PC;
  if (!PC) return;

  var STATE_MS = 4000, FEED_MS = 3000;

  var S = null;            // last state payload
  var feedHead = 0;
  var shapeTab = null;     // which campaign's allocation is on screen
  var prev = {};           // previous displayed values, for delta arrows

  /* Every animated number: key -> {cur, target, dp, fmt} */
  var anim = {};

  function track(key, target, dp, fmt) {
    if (!anim[key]) {
      /* First sighting: cur already equals target, so the easing loop would
         see a zero gap and never paint. Paint it here instead — the value must
         never depend on the animation running. */
      anim[key] = { cur: target, target: target, dp: dp || 0, fmt: fmt };
      paint(key);
    } else {
      anim[key].target = target;
      anim[key].dp = dp || 0;
      anim[key].fmt = fmt;
    }
  }

  function fmtVal(a) {
    if (a.fmt === 'x')   return '×' + a.cur.toFixed(a.dp);
    if (a.fmt === 'pct') return a.cur.toFixed(a.dp) + '%';
    return PC.num(a.cur, a.dp);
  }

  function paint(key) {
    var el = d.querySelector('[data-anim="' + key + '"]');
    if (el) el.textContent = fmtVal(anim[key]);
  }

  /**
   * Snap every tracked number straight to its target, skipping the easing.
   * Used when requestAnimationFrame is not running — a background tab, a
   * reduced-motion preference, or a browser that has throttled compositing.
   * The easing is a flourish; the number being correct is not optional.
   */
  function snapAll() {
    Object.keys(anim).forEach(function (key) {
      anim[key].cur = anim[key].target;
      paint(key);
    });
  }

  /* ── statbar crawl ───────────────────────────────────────────────────────
     Six live values do not fit a phone. A strip you have to swipe is a strip
     most people never reach the end of, so once the cells overflow the bar the
     track crawls on its own instead.

     The crawl is a second copy of the cells appended to the same track, which
     then translates by exactly half its own width: the seam lands precisely
     where copy two begins, so the loop never shows a join. The copies carry no
     ids — an id may appear once in a document, and `#hud-balance` resolving to
     a decorative duplicate would be a silent bug — and they are aria-hidden, so
     the readout is still announced once. Their values are mirrored across on
     every board update.

     Whether to crawl is decided from measured overflow, not a breakpoint: the
     bar has three cells for a guest and six for a player, and those cross the
     threshold at different widths. Measuring is also the only way to be right
     about it, since the cells flex-grow to fill a bar they fit in.
  ------------------------------------------------------------------------- */

  var SB_SPEED = 42;                     // crawl, in CSS px per second

  var SB = { bar: null, track: null, cells: [], pairs: [], t: 0, reduced: false };

  function initStatbar() {
    SB.bar = PC.$('#statbar');
    if (!SB.bar) return;
    SB.track = SB.bar.querySelector('.statbar-track');
    if (!SB.track) return;

    SB.reduced = !!(w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches);
    /* Snapshot: .children is live, and cloning into it while walking it would
       not terminate. */
    SB.cells = [].slice.call(SB.track.children);

    /* Press and hold to read one. Touch has no hover, and a value you want is
       always the one about to leave. */
    ['pointerdown', 'pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) {
      SB.bar.addEventListener(ev, function (e) {
        SB.bar.classList.toggle('sb-hold', e.type === 'pointerdown');
      });
    });

    sizeStatbar();
    w.addEventListener('resize', function () {
      clearTimeout(SB.t);
      SB.t = setTimeout(sizeStatbar, 150);
    });
    /* Cell widths are set by the type in them, so the first measurement is only
       right once the webfont has landed. */
    if (d.fonts && d.fonts.ready) {
      d.fonts.ready.then(sizeStatbar)['catch'](function () {});
    }
  }

  function sizeStatbar() {
    if (!SB.track) return;

    /* Measure the real cells alone, with growth switched off — flex-grown cells
       fill the bar exactly and would report that they fit no matter how wide
       they want to be. */
    dropCopies();
    SB.bar.classList.add('sb-measuring');
    var lap = SB.track.offsetWidth;
    SB.bar.classList.remove('sb-measuring');

    var on = !SB.reduced && lap > SB.bar.clientWidth + 1;
    if (on) {
      addCopies();
      syncStatbar();
      SB.bar.scrollLeft = 0;             // the crawl owns the offset now
      SB.track.style.setProperty('--sb-dur', (lap / SB_SPEED).toFixed(2) + 's');
    }
    SB.bar.classList.toggle('is-marquee', on);
  }

  function dropCopies() {
    SB.pairs.length = 0;
    var copies = SB.track.querySelectorAll('[data-sb-copy]');
    for (var i = 0; i < copies.length; i++) SB.track.removeChild(copies[i]);
  }

  function addCopies() {
    SB.cells.forEach(function (cell) {
      var copy = cell.cloneNode(true);
      copy.setAttribute('data-sb-copy', '');
      copy.setAttribute('aria-hidden', 'true');
      var ided = copy.querySelectorAll('[id]');
      for (var i = 0; i < ided.length; i++) ided[i].removeAttribute('id');
      SB.track.appendChild(copy);

      var a = cell.querySelector('.v'), b = copy.querySelector('.v');
      if (a && b) SB.pairs.push([a, b]);
    });
  }

  /** Push the live values into the crawl's duplicate cells. */
  function syncStatbar() {
    for (var i = 0; i < SB.pairs.length; i++) {
      var p = SB.pairs[i];
      if (p[1].textContent !== p[0].textContent) p[1].textContent = p[0].textContent;
    }
  }

  /* ── radar ───────────────────────────────────────────────────────────────
     The panel is the Philippine relief plate from 3d-map.html with the radar
     laid flat in the same plane, so the sweep takes the map's foreshortening
     instead of sitting flat on the glass. phl-map.js owns the projection and
     rasterises the plate once per size change; everything here is per frame.

     Range is 1,000 km from the centre of the archipelago. The old 600 km circle
     was anchored on Panay and reached neither end of the country; this closes
     around the northern tip of the Batanes at 885 km and Sibutu in Tawi-Tawi at
     951 km, with the other 89 landmasses inside them.
  ------------------------------------------------------------------------- */

  var TAU = Math.PI * 2;
  var SWEEP_SEC = 7.5;

  /* ── the orbit — OFF ─────────────────────────────────────────────────────
     PARKED DELIBERATELY. Do not switch this on without reading why it is off.

     The plate is rasterised, not modelled, so it cannot simply spin: redrawing
     91 coastlines costs ~7 ms, most of a frame. The machinery below handles that
     — phl-map.js re-aims the camera every frame at ~0.05 ms so the sweep, arcs
     and pins stay smooth, and re-rasterises the coastline only as often as the
     hardware can pay for, self-tuning against SPIN_BUDGET. It works, and it is
     still 10% of a core for as long as the cockpit is open, forever, on a phone
     in someone's pocket. That is not a trade worth making for ambience.

     It served its purpose: rotating the view proved the trajectories had been
     correct arcs the whole time and the camera angle was hiding them. The fix
     for that is the fixed bearing in phl-map.js, which costs nothing.

     SPIN_SEC = 150 turns it back on if a desktop-only view ever wants it.
     Reduced-motion parks it regardless. */
  var SPIN_SEC = 0;            // seconds per revolution; 0 = static
  var SPIN_BUDGET = 0.10;      // share of wall time the raster may spend

  /* Each campaign stands on its own bastion. Once there is a real coastline
     under the nodes, evenly spaced compass positions read as a bug — and these
     three provinces are the first thing any Filipino player would look for.
     Keyed by campaign id; anything not listed falls back to even spacing. */
  var BASTION = {
    0: [123.181, 13.624],     // LENI   — Naga, Camarines Sur
    1: [125.613,  7.073],     // SARA   — Davao City
    2: [120.564, 18.055]      // MARCOS — Batac, Ilocos Norte
  };

  /* Phosphor persistence: bands trailing the beam, each spanning a slice of a
     full turn and blitting the lit plate at its own strength. Roughly geometric
     decay — three bands left a visible straight seam where the alpha stepped, so
     the decay is spread over five. That costs nothing extra because each band
     only composites its own bounding box, not the whole panel. */
  var TRAIL = [
    { a: 0.000, b: 0.030, lit: .90, glow: .22 },
    { a: 0.030, b: 0.070, lit: .55, glow: .13 },
    { a: 0.070, b: 0.130, lit: .32, glow: .075 },
    { a: 0.130, b: 0.220, lit: .16, glow: .038 },
    { a: 0.220, b: 0.360, lit: .06, glow: .016 }
  ];

  /* phl-map owns the palette; this only matters if its script failed to load,
     in which case the radar still draws — just without the country. */
  var ph = (w.PCMAP && w.PCMAP.phos) ||
           function (t, a) { return 'rgba(92,255,74,' + a + ')'; };

  var R = {
    cv: null, ctx: null, view: null, flat: true,
    w: 0, h: 0, dpr: 1, u: 1, rim: 1,
    beam: 0, prevBeam: 0, pings: [],
    reduced: false, dirty: true, t0: 0, last: 0, sizeT: 0,
    bearing0: 0, rasterMs: 8
  };

  /* Scratch points. These are written thousands of times a second; allocating
     a fresh object per vertex is the one thing that would make this stutter. */
  var _a = {}, _b = {};

  function initRadar() {
    R.cv = PC.$('#radar');
    if (!R.cv) return;
    R.ctx = R.cv.getContext('2d');
    R.reduced = !!(w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches);
    R.t0 = R.last = (w.performance && performance.now) ? performance.now() : 0;

    sizeRadar();
    w.addEventListener('resize', function () {
      /* Rebuilding the plate is 3,000 points through two rasterisers. Doing
         that on every resize event of a window drag would lock the tab. */
      clearTimeout(R.sizeT);
      R.sizeT = setTimeout(sizeRadar, 120);
    });

    /* The range callouts are baked into the plate, so a plate built before
       Chakra Petch arrives keeps a fallback face forever. Rebuild once. */
    if (d.fonts && d.fonts.ready) {
      d.fonts.ready.then(function () { R.w = 0; sizeRadar(); })['catch'](function () {});
    }

    requestAnimationFrame(frame);
  }

  function sizeRadar() {
    if (!R.cv) return;
    var wrap = PC.$('#radar-wrap');
    if (!wrap) return;
    var cw = Math.round(wrap.clientWidth), ch = Math.round(wrap.clientHeight);
    var dpr = Math.min(2, w.devicePixelRatio || 1);
    if (!cw || !ch) return;
    if (cw === R.w && ch === R.h && dpr === R.dpr && R.view) return;

    R.w = cw; R.h = ch; R.dpr = dpr;
    R.cv.width  = Math.round(cw * dpr);
    R.cv.height = Math.round(ch * dpr);
    R.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    R.flat = !w.PCMAP;
    R.view = R.flat ? flatView(cw, ch) : w.PCMAP.build(cw, ch, dpr);
    R.bearing0 = R.view.bearing || 0;
    /* Marker scale — 1 at the design size, but clamped. Straight proportional
       scaling gave the desktop panel 35 px campaign dots and 17 px labels that
       covered the coastline they are supposed to sit on. */
    R.u    = Math.max(0.85, Math.min(1.3, Math.min(cw, ch) / 380));
    R.rim  = rimRadius(R.view);
    R.dirty = true;

    /* Setting canvas.width wiped the panel. Repaint here rather than leaving it
       blank until the next frame — the font-ready rebuild fires at an arbitrary
       moment, and a blank flash then reads as a load failure. */
    drawRadar(R.last, 0);
  }

  /** Widest screen distance from hub to rim — the radius the beam gradients use. */
  function rimRadius(v) {
    var m = 1;
    for (var i = 0; i < 24; i++) {
      v.at((i / 24) * TAU, v.R, _a);
      var dd = Math.hypot(_a.x - v.cx, _a.y - v.cy);
      if (dd > m) m = dd;
    }
    return m;
  }

  /**
   * No map data — draw the radar on a bare plane instead of a relief plate.
   * Same interface as a real view, so nothing below has to know the difference;
   * the panel degrades to rings and a sweep rather than going blank.
   */
  function flatView(cw, ch) {
    var cx = cw / 2, cy = ch / 2;
    var Ru = 1000 / 10.12, k = (Math.min(cw, ch) / 2 - 14) / Ru;
    function pt(wx, wz, o) { o = o || {}; o.x = cx + wx * k; o.y = cy + wz * k; return o; }
    return {
      w: cw, h: ch, plate: null, hot: null,
      R: Ru, km: 1000, ringKm: 250, kmPerUnit: 10.12,
      cx: cx, cy: cy, pxPerUnit: k,
      pt: pt,
      /* No camera to ask, so altitude just lifts up the screen at roughly the
         rake a 36° view would give it. */
      air: function (wx, alt, wz, o) { o = pt(wx, wz, o); o.y -= alt * k * 0.81; return o; },
      at: function (b, r, o) { return pt(Math.sin(b) * r, -Math.cos(b) * r, o); },
      ll: function (lon, lat, o) { return pt(0, 0, o); }
    };
  }

  /* ── paths, all built in the plate's plane ─────────────────────────────── */

  function ringPath(c, range, steps) {
    var v = R.view;
    v.at(0, range, _a);
    c.moveTo(_a.x, _a.y);
    for (var i = 1; i < steps; i++) { v.at((i / steps) * TAU, range, _a); c.lineTo(_a.x, _a.y); }
    c.closePath();
  }

  /**
   * Wedge from the hub out to `range`, spanning a0 -> a1. Subdivided by arc
   * length rather than a fixed count — a fixed count faceted the long trailing
   * bands into visible chords. Also leaves the wedge's screen bounding box in
   * `_box`, so the caller can composite that rectangle instead of the panel.
   */
  var _box = { x0: 0, y0: 0, x1: 0, y1: 0 };
  function wedgePath(c, a0, a1, range) {
    var v = R.view, i;
    var steps = Math.max(3, Math.min(48, Math.ceil(Math.abs(a1 - a0) / 0.06)));
    var x0 = v.cx, y0 = v.cy, x1 = v.cx, y1 = v.cy;
    c.moveTo(v.cx, v.cy);
    for (i = 0; i <= steps; i++) {
      v.at(a0 + (a1 - a0) * (i / steps), range, _a);
      c.lineTo(_a.x, _a.y);
      if (_a.x < x0) x0 = _a.x;
      if (_a.x > x1) x1 = _a.x;
      if (_a.y < y0) y0 = _a.y;
      if (_a.y > y1) y1 = _a.y;
    }
    c.closePath();
    _box.x0 = x0; _box.y0 = y0; _box.x1 = x1; _box.y1 = y1;
  }

  /** Circle of world radius `rho` around a plane point — a ground return. */
  function groundRing(c, wx, wz, rho, steps) {
    var v = R.view;
    for (var i = 0; i <= steps; i++) {
      var a = (i / steps) * TAU;
      v.pt(wx + Math.sin(a) * rho, wz - Math.cos(a) * rho, _a);
      i ? c.lineTo(_a.x, _a.y) : c.moveTo(_a.x, _a.y);
    }
  }

  /**
   * Local plane -> screen Jacobian at a point: where a step east and a step
   * south land. Feed it to ctx.transform() and a circle drawn at the origin
   * comes out as the ellipse that circle would actually be, lying on the plate
   * — same 30° rake and same 10° yaw as the coastline under it.
   *
   * `sx` is screen pixels per world unit measured eastward, which is the axis
   * the pitch does not compress; sizing markers against it keeps them the width
   * they were before and lets the foreshortening take the height.
   */
  var _bs = { x: 0, y: 0, ax: 1, ay: 0, bx: 0, by: 1, sx: 1, sy: 1 };
  function planeBasis(wx, wz) {
    var v = R.view, e = Math.max(0.25, v.R * 0.01);
    v.pt(wx, wz, _a);
    _bs.x = _a.x; _bs.y = _a.y;
    v.pt(wx + e, wz, _b);
    _bs.ax = (_b.x - _a.x) / e; _bs.ay = (_b.y - _a.y) / e;
    v.pt(wx, wz + e, _b);
    _bs.bx = (_b.x - _a.x) / e; _bs.by = (_b.y - _a.y) / e;
    _bs.sx = Math.hypot(_bs.ax, _bs.ay) || 1;
    _bs.sy = Math.hypot(_bs.ay, _bs.by);      // screen half-height per unit radius
    return _bs;
  }

  /** Did the beam pass bearing `a` while advancing from `prev` to `cur`? */
  function crossed(prev, cur, a) {
    var adv = ((cur - prev) % TAU + TAU) % TAU;
    if (adv <= 0) return false;
    return ((a - prev) % TAU + TAU) % TAU <= adv;
  }

  /** Where a campaign stands, in both screen and plane coordinates. */
  function nodePos(cam, i, n, o) {
    var v = R.view, b = (!R.flat && cam && BASTION[cam.id]) ? BASTION[cam.id] : null;
    if (b) {
      var q = w.PCMAP.xz(b[0], b[1]);
      o = v.pt(q.x, q.z, o);
      o.wx = q.x; o.wz = q.z;
    } else {
      var a = (i / n) * TAU, r = v.R * 0.58;
      o = v.at(a, r, o);
      o.wx = Math.sin(a) * r; o.wz = -Math.cos(a) * r;
    }
    o.a = Math.atan2(o.wx, -o.wz);            // bearing from the hub
    return o;
  }

  /* ── draw ──────────────────────────────────────────────────────────────── */

  function drawRadar(now, dt) {
    var v = R.view, c = R.ctx, i;
    if (!c || !v) return;

    if (R.reduced) {
      /* Motion is off: hold the beam still and only repaint when the board
         actually changed, rather than burning a composite every frame. */
      if (!R.dirty && !R.pings.length) return;
      R.prevBeam = R.beam = 0.9;      // where 3d-map.html parks its static beam
    } else {
      R.prevBeam = R.beam;
      R.beam = (((now - R.t0) / 1000) * (TAU / SWEEP_SEC)) % TAU;
    }
    R.dirty = false;

    /* Re-aim, then re-raster if the plate has fallen far enough behind to show.
       The step is derived from what a raster actually cost last time, so the
       budget holds on hardware I cannot measure from here. */
    if (SPIN_SEC && !R.reduced && v.aim) {
      var degPerSec = 360 / SPIN_SEC;
      v.aim(R.bearing0 + ((now - R.t0) / 1000) * degPerSec);
      R.rim = rimRadius(v);
      var step = Math.max(0.12, degPerSec * R.rasterMs / (SPIN_BUDGET * 1000));
      if (Math.abs(v.bearing - v.builtAt) >= step) {
        var rt = performance.now();
        v.raster();
        /* Smoothed, so one unlucky frame does not swing the throttle. */
        R.rasterMs += (performance.now() - rt - R.rasterMs) * 0.25;
      }
    }

    c.clearRect(0, 0, R.w, R.h);

    /* the sea inside the range circle — gives the disc an edge to sit on */
    c.beginPath(); ringPath(c, v.R, 96);
    var sea = c.createRadialGradient(v.cx, v.cy, 0, v.cx, v.cy, R.rim);
    sea.addColorStop(0, 'rgba(11,32,17,.66)');
    sea.addColorStop(1, 'rgba(3,11,6,.28)');
    c.fillStyle = sea;
    c.fill();

    /* the cold plate */
    if (v.plate) c.drawImage(v.plate, 0, 0, R.w, R.h);

    /* the beam, and the phosphor still glowing behind it */
    var hsx = v.hot ? v.hot.width / R.w : 1, hsy = v.hot ? v.hot.height / R.h : 1;
    for (i = 0; i < TRAIL.length; i++) {
      var t = TRAIL[i];
      c.save();
      c.beginPath();
      wedgePath(c, R.beam - t.b * TAU, R.beam - t.a * TAU, v.R);
      c.clip();

      /* Only the wedge's own rectangle. Compositing the full panel five times a
         frame is what would make this expensive; a band is a fraction of it. */
      var bx = Math.max(0, Math.floor(_box.x0) - 2),
          by = Math.max(0, Math.floor(_box.y0) - 2),
          bw = Math.min(R.w, Math.ceil(_box.x1) + 2) - bx,
          bh = Math.min(R.h, Math.ceil(_box.y1) + 2) - by;

      if (bw > 0 && bh > 0) {
        if (v.hot) {
          c.globalAlpha = t.lit;
          c.drawImage(v.hot, bx * hsx, by * hsy, bw * hsx, bh * hsy, bx, by, bw, bh);
          c.globalAlpha = 1;
        }
        var bg = c.createRadialGradient(v.cx, v.cy, 0, v.cx, v.cy, R.rim);
        bg.addColorStop(0, ph('mid', t.glow * 0.18));
        bg.addColorStop(1, ph('mid', t.glow));
        c.fillStyle = bg;
        c.fillRect(bx, by, bw, bh);
      }
      c.restore();
    }

    /* graticule, over the land the way a real overlay sits */
    c.lineWidth = 1;
    c.strokeStyle = ph('mid', .12);
    for (var km = v.ringKm; km < v.km; km += v.ringKm) {
      c.beginPath(); ringPath(c, (km / v.km) * v.R, 72); c.stroke();
    }

    var inner = v.R * (v.ringKm / v.km);
    c.strokeStyle = ph('mid', .07);
    for (i = 0; i < 12; i++) {
      var sa = (i / 12) * TAU;
      v.at(sa, inner, _a); v.at(sa, v.R, _b);
      c.beginPath(); c.moveTo(_a.x, _a.y); c.lineTo(_b.x, _b.y); c.stroke();
    }

    c.strokeStyle = ph('mid', .34);
    c.lineWidth = 1.3;
    c.beginPath(); ringPath(c, v.R, 96); c.stroke();

    /* cardinal ticks straddling the rim. The plate is yawed 10°, so which way
       is north is a real question — north gets the long one. */
    for (i = 0; i < 4; i++) {
      var ca = (i / 4) * TAU, out = i === 0 ? 0.075 : 0.038;
      v.at(ca, v.R * (1 - out * 0.55), _a);
      v.at(ca, v.R * (1 + out * 0.45), _b);
      c.strokeStyle = ph(i === 0 ? 'hi' : 'mid', i === 0 ? .7 : .34);
      c.lineWidth = i === 0 ? 1.8 : 1.1;
      c.beginPath(); c.moveTo(_a.x, _a.y); c.lineTo(_b.x, _b.y); c.stroke();
    }

    /* leading edge */
    v.at(R.beam, v.R, _b);
    c.strokeStyle = ph('hi', .72);
    c.lineWidth = 1.4;
    c.shadowColor = ph('hi', .85);
    c.shadowBlur = 6;
    c.beginPath(); c.moveTo(v.cx, v.cy); c.lineTo(_b.x, _b.y); c.stroke();
    c.shadowBlur = 0;

    drawNodes(c, dt, now);

    /* hub */
    c.strokeStyle = ph('mid', .5);
    c.lineWidth = 1;
    c.beginPath(); ringPath(c, v.R * 0.03, 20); c.stroke();
    c.beginPath();
    c.moveTo(v.cx - 4, v.cy); c.lineTo(v.cx + 4, v.cy);
    c.moveTo(v.cx, v.cy - 4); c.lineTo(v.cx, v.cy + 4);
    c.stroke();
  }

  function drawNodes(c, dt, now) {
    if (!S || !S.campaigns || !S.campaigns.length) { R.pings.length = 0; return; }
    var n = S.campaigns.length, i;

    /* Node positions first: the attack layer needs both endpoints of every
       trajectory before it can draw any of them. */
    for (i = 0; i < n; i++) NP[i] = nodePos(S.campaigns[i], i, n, NP[i] || {});

    drawAttacks(c, now);
    drawBlasts(c, now);

    /* campaign nodes, sized by win chance */
    var p = {};
    var fs = Math.max(9, 11 * R.u);
    S.campaigns.forEach(function (cam, i) {
      nodePos(cam, i, n, p);
      var wc = Math.max(0, Math.min(100, cam.win_chance || 0)) / 100;
      var rr = (7 + wc * 15) * R.u;              // wanted radius, screen px
      var bs = planeBasis(p.wx, p.wz);
      var rw = rr / bs.sx;                       // the same radius in world units
      var rh = rw * bs.sy;                       // how tall it ends up on screen

      /* One return per revolution. The old test fired whenever the beam was
         within a fixed slice of the bearing, so it double-fired on a fast
         display and missed it outright on a slow one. */
      if (crossed(R.prevBeam, R.beam, p.a)) {
        R.pings.push({
          wx: p.wx, wz: p.wz, r0: rw,
          grow: 34 * R.u / bs.sx,      // world units the return expands by
          age: 0, color: cam.color
        });
      }

      c.save();
      c.translate(bs.x, bs.y);
      c.transform(bs.ax, bs.ay, bs.bx, bs.by, 0, 0);

      var hg = c.createRadialGradient(0, 0, 0, 0, 0, rw * 2.4);
      hg.addColorStop(0, hexA(cam.color, .3));
      hg.addColorStop(1, hexA(cam.color, 0));
      c.fillStyle = hg;
      c.beginPath(); c.arc(0, 0, rw * 2.4, 0, TAU); c.fill();

      c.fillStyle = cam.color;
      c.beginPath(); c.arc(0, 0, rw * 0.42, 0, TAU); c.fill();

      /* Built inside the transform, stroked outside it. Canvas bakes path points
         at the transform in force when they are added, so the shape stays the
         foreshortened ellipse while the stroke keeps one screen width instead of
         thinning to nothing along the compressed axis. */
      c.beginPath(); c.arc(0, 0, rw, 0, TAU);
      c.restore();
      c.strokeStyle = cam.color;
      c.lineWidth = 1.5;
      c.stroke();

      /* Type stays upright — it labels the plate, it does not lie on it. Cleared
         off `rh`, the ellipse's screen half-height, not the marker radius: at a
         30° rake those differ by half and clearing the radius would float the
         name well off its own pin. */
      c.font = '600 ' + fs.toFixed(1) + 'px "Chakra Petch",ui-monospace,monospace';
      c.textAlign = 'center';
      c.textBaseline = 'alphabetic';
      c.shadowColor = 'rgba(3,7,16,.9)';
      c.shadowBlur = 4;
      c.fillStyle = '#E2FFFF';
      c.fillText(cam.name, p.x, p.y + rh + fs + 5);
      c.fillStyle = cam.color;
      c.fillText((cam.win_chance || 0).toFixed(1) + '%', p.x, p.y + rh + fs * 2 + 7);
      c.shadowBlur = 0;
    });

    /* expanding ground returns */
    c.lineWidth = 1;
    for (var j = R.pings.length - 1; j >= 0; j--) {
      var g2 = R.pings[j];
      g2.age += dt * 1.2;
      if (g2.age >= 1) { R.pings.splice(j, 1); continue; }
      c.strokeStyle = hexA(g2.color, (1 - g2.age) * 0.5);
      c.beginPath();
      groundRing(c, g2.wx, g2.wz, g2.r0 + g2.age * g2.grow, 40);
      c.stroke();
    }
  }

  /* ── attacks ─────────────────────────────────────────────────────────────
     Ported from references/prototype.html — drawSingleAttack / drawClusterRibbon
     / drawTail / drawArrow / drawExplosions, which the old build had flattened
     into one dashed straight line between two nodes.

     An attack is a quadratic Bézier from attacker to target with a smoke tail
     and an arrowhead riding it, and the arrowhead's position along the curve IS
     the countdown: it touches the target exactly when the conflict resolves.

     THE TRAJECTORY IS FLOWN, NOT DRAWN. A round is a point in the air — along a
     straight ground track, at some altitude above it — put through the same
     camera as the coastline. Its shape on screen is not chosen anywhere in this
     file; it is what that viewpoint makes of a ballistic arc. See arcGeom().

     What DOES stay in the plane: the campaign pins, the sweep, the ping returns,
     the urgency ring and the blast shockwave. Those are all on the ground.

     Departures from the prototype, all deliberate:

       1. No `speedMult`. The prototype jitters each projectile's speed so that
          simultaneous attacks do not move in lockstep — but that decouples the
          arrowhead from the real ETA. Here every conflict already draws its own
          delay between RESOLVE_DELAY_MIN and _MAX, so they desynchronise on
          their own and the position can stay honest.
       2. Arcs are keyed on the conflict row id, not `from-to-startMs`, so a
          trajectory survives a reload with the same curve and lane.
       3. Blast shockwaves are ground-plane ellipses. A shockwave lies on the
          map; the debris and the core flash do not, and stay in screen space.
       4. The whole arc is drawn, end to end, as a dotted trace. The prototype
          draws only the smoke tail and lets it stand in for the path — which
          works there because its nodes sit evenly on a circle, its routes are
          long, and its lanes usually carry three or four rounds whose tails
          overlap into a continuous dotted curve. Here the bastions are real
          places: Batac to Naga is half a radius, so a single attack's tail is a
          40-pixel smudge and the trajectory is invisible. The trace shows where
          a round is going as well as where it has been.

     Bow is a fraction of the CHORD, not of the radar radius as in the prototype.
     Its nodes are equidistant so the two are the same thing; geographic bastions
     are not, and a radius-proportional bow would throw a short route into a
     hairpin while barely bending a long one.
  ------------------------------------------------------------------------- */

  var ATK = '255,78,100';                // --loss, the site's hostile colour
  var TAIL_STEPS = 18;

  var A = { paths: {}, blasts: [], fired: {} };
  var NP = [];                           // node positions, refreshed each frame

  /** Deterministic 0..1 from a conflict id — same arc every frame and after a
      reload, where Math.random() would reshuffle the board 60 times a second. */
  function hash01(k) {
    var x = Math.sin(k * 12.9898 + 78.233) * 43758.5453;
    return x - Math.floor(x);
  }

  /**
   * The seeded part of an attack's shape, cached against its row id: how far it
   * bows, which way, and which lane it flies. Deliberately NOT the geometry —
   * that is screen space now and has to be rebuilt whenever the panel resizes.
   */
  function attackPath(t, lane, lanes) {
    var key = t.id || (t.from * 31 + t.to + 1);
    var p = A.paths[key];
    if (p) return p;

    var s = hash01(key);
    /* Attacks sharing a lane fan out rather than stacking. The fan is capped at
       eight abreast: 100 bots can put a dozen conflicts on one pair, and an
       uncapped fan would throw the outer ones clear across the panel. */
    var wide = Math.min(lanes, 8);
    p = {
      /* Apex altitude as a fraction of the ground range. A ballistic shot
         launched at 45° peaks at a quarter of its range; this sits either side
         of that so simultaneous rounds do not fly identical arcs. */
      apex: 0.20 + s * 0.10,
      side: s > 0.5 ? 1 : -1,
      lane: ((lane % wide) - (wide - 1) / 2) * 0.06
    };
    A.paths[key] = p;
    return p;
  }

  /**
   * The four points of the trajectory, in SCREEN pixels, rebuilt each frame.
   *
   * CUBIC, and asymmetric on purpose: the shot runs out of the launcher almost
   * flat, holds that line, then tips over and drops into the target. A quadratic
   * cannot do this at any magnitude — it has one control point, so its curve is
   * symmetric about the chord and the only knob is how far it bulges. Turning
   * that knob up gives a fatter bulge, never a terminal dive.
   *
   * A round in flight is at a point in the AIR: somewhere along a straight
   * ground track between the two bastions, and some altitude above it. Feed that
   * point to the same camera that draws the coastline and the screen position
   * falls out. Nothing is bowed, lofted or bent by hand — the shape is whatever
   * the viewpoint makes of a ballistic arc.
   *
   * That is also where every earlier attempt went wrong. Drawing a curve in the
   * plate's plane painted the shot onto the ground. Drawing one in screen space
   * lifted it off, but the bow was then a shape I was choosing rather than one
   * the geometry produced, and no choice of bow direction or magnitude was ever
   * going to be right for all four routes at once.
   *
   * The behaviour asked for falls straight out of the model. Ground track and
   * altitude both move the round on screen, and they fight:
   *
   *   flying TOWARD the camera (southbound), the ground track runs down the
   *   screen while altitude pushes up. Early on they nearly cancel and the round
   *   tracks almost level; at the end both point down and it dives.
   *
   *   flying AWAY (northbound) it reverses — a steep climb out, then levelling
   *   off into the target.
   *
   * Horizontal screen motion is purely the ground track, so there is no sideways
   * bulge to be too much of. Apex altitude is a fraction of the ground range —
   * a 45° ballistic shot peaks at a quarter of its range, and `apex` sits either
   * side of that.
   */
  var _g = { ax: 0, az: 0, bx: 0, bz: 0, h: 0 };
  function arcGeom(from, to, p) {
    var dx = to.wx - from.wx, dz = to.wz - from.wz;
    var len = Math.hypot(dx, dz) || 1;
    /* Lanes are parallel ground tracks, so the offset is on the ground too. */
    var off = p.lane * len, nx = -dz / len, nz = dx / len;
    _g.ax = from.wx + nx * off; _g.az = from.wz + nz * off;
    _g.bx = to.wx + nx * off;   _g.bz = to.wz + nz * off;
    _g.h = len * p.apex;
    return _g;
  }

  /** Where the round is at `t`: down the ground track, up the parabola, through
      the camera. */
  function bezAt(g, t, o) {
    return R.view.air(g.ax + (g.bx - g.ax) * t,
                      g.h * 4 * t * (1 - t),
                      g.az + (g.bz - g.az) * t, o);
  }

  /** Heading on screen. Taken as a finite difference rather than a derivative:
      the altitude curve and the perspective divide do not compose into anything
      worth writing out, and two extra projections a frame is nothing. */
  var _d1 = {}, _d2 = {};
  function bezDir(g, t) {
    var a = Math.max(0, t - 0.02), b = Math.min(1, t + 0.02);
    if (b - a < 1e-4) { a = 0; b = 0.04; }
    bezAt(g, a, _d1);
    bezAt(g, b, _d2);
    return Math.atan2(_d2.y - _d1.y, _d2.x - _d1.x);
  }

  /** Polyline along a slice of the trajectory. */
  function arcStroke(c, g, t0, t1, steps) {
    c.beginPath();
    for (var i = 0; i <= steps; i++) {
      bezAt(g, t0 + (t1 - t0) * (i / steps), _a);
      i ? c.lineTo(_a.x, _a.y) : c.moveTo(_a.x, _a.y);
    }
    c.stroke();
  }

  function drawAttacks(c, now) {
    var ts = S.threats || [];
    if (!ts.length) return;

    /* Group by attacker/target pair: lanes fan out, and the label can say 3×
       instead of printing three of them on top of each other. */
    var lanes = {}, order = [], i, t, k;
    for (i = 0; i < ts.length; i++) {
      t = ts[i];
      k = t.from + '>' + t.to;
      if (!lanes[k]) { lanes[k] = []; order.push(k); }
      lanes[k].push(t);
    }

    /* Per-frame detail budget. A full round costs its dotted trajectory, an
       18-puff contrail and a shadow-blurred arrowhead; all fine for the handful
       of conflicts a normal board has in flight, none of it fine when a hundred
       bots swing at once. Past the budget a round still draws and is still in
       the right place — it just loses its trajectory, contrail and glow.
       Measured at a 26-conflict swarm on a 640px panel: 21 ms median. */
    A.budget = 10;

    for (i = 0; i < order.length; i++) {
      var group = lanes[order[i]];
      var ai = camIndex(group[0].from), bi = camIndex(group[0].to);
      if (ai < 0 || bi < 0) continue;
      drawLane(c, now, group, NP[ai], NP[bi]);
    }
  }

  function drawLane(c, now, group, from, to) {
    var soonest = Infinity, mine = false;

    for (var i = 0; i < group.length; i++) {
      var t = group[i];
      var full = A.budget-- > 0;
      var g = arcGeom(from, to, attackPath(t, i, group.length));
      var eta = Math.max(0, t.eta || 0);
      /* flight defaults to the mid-point of the engine's delay window, for a
         cached script talking to an API that predates the field. */
      var prog = Math.max(0, Math.min(1, 1 - eta / Math.max(1, t.flight || 1200)));

      if (eta < soonest) soonest = eta;
      if (t.mine) mine = true;

      /* The trajectory itself, dotted end to end: dim ahead of the round, warm
         behind it. Batched into two paths rather than 26 separate fills — this
         runs per attack per frame, and the fill call is the expensive part. */
      if (full) {
        /* Dots, but drawn as a near-zero-length dash with round caps rather than
           one arc() per dot. Same picture; two strokes over a short polyline
           instead of ~330 circle fills a frame, which measured 14 ms of the
           budget on its own under a 26-conflict swarm.

           Weight matters more here than it looks like it should: the first cut
           used 1.7 px at .2 alpha and was mathematically present but visually
           absent against the plate. Ahead of the round stays grey and quiet;
           behind it takes the attack colour, so the split reads without a key. */
        c.save();
        c.lineCap = 'round';
        c.lineWidth = 2.6 * R.u;
        c.setLineDash([0.5, 5.4 * R.u]);
        if (prog > 0.02) {
          c.strokeStyle = 'rgba(' + ATK + ',.62)';
          arcStroke(c, g, 0, prog, 20);
        }
        if (prog < 0.98) {
          c.strokeStyle = 'rgba(186,198,210,.34)';
          arcStroke(c, g, prog, 1, 12);
        }
        c.restore();
      }

      /* Smoke tail. */
      if (full) {
        for (var j = TAIL_STEPS; j >= 0; j--) {
          var tp = prog - j * 0.020;
          if (tp <= 0) continue;
          bezAt(g, tp, _a);
          var age = j / TAIL_STEPS;
          var grey = (140 + age * 80) | 0;
          c.fillStyle = 'rgba(' + grey + ',' + grey + ',' + grey + ',' +
                        ((1 - age) * 0.17).toFixed(3) + ')';
          c.beginPath();
          c.arc(_a.x, _a.y, (1.5 + age * 6.5) * R.u, 0, TAU);
          c.fill();
        }
      }

      /* Arrowhead — rotated to the screen tangent, and NOT put through the
         plane basis. A round in the air is not a mark on the ground. */
      bezAt(g, prog, _a);
      var hx = _a.x, hy = _a.y, sz = 8 * R.u;

      c.save();
      c.translate(hx, hy);
      c.rotate(bezDir(g, prog));
      c.beginPath();
      c.moveTo(sz, 0);
      c.lineTo(-sz * 0.65, sz * 0.55);
      c.lineTo(-sz * 0.3, 0);
      c.lineTo(-sz * 0.65, -sz * 0.55);
      c.closePath();
      c.fillStyle = 'rgba(' + ATK + ',1)';
      if (full) {
        c.shadowColor = 'rgba(' + ATK + ',.9)';
        c.shadowBlur = 10 * R.u;
      }
      c.fill();
      c.restore();

      /* One label per lane, on the leading round. */
      if (i === 0) {
        c.font = '600 ' + Math.max(8, 9 * R.u).toFixed(1) +
                 'px "Chakra Petch",ui-monospace,monospace';
        c.textAlign = 'center';
        c.textBaseline = 'bottom';
        c.fillStyle = 'rgba(' + ATK + ',1)';
        c.shadowColor = 'rgba(3,7,16,.95)';
        c.shadowBlur = 4;
        c.fillText((group.length > 1 ? group.length + '×' : '') + eta + 's',
                   hx, hy - 10 * R.u);
        c.shadowBlur = 0;
      }

      /* The impact IS on the ground, so the blast still needs the plane scale. */
      var key = t.id || (t.from * 31 + t.to + 1);
      if (prog >= 1 && !A.fired[key]) {
        A.fired[key] = 1;
        spawnBlast(to.wx, to.wz, planeBasis(to.wx, to.wz).sx, !!t.mine, now);
      }
    }

    /* Incoming and close: ring the target. Two speeds, because the last ten
       seconds of an attack you cannot stop should not look like the first. */
    if (mine && soonest < 30) {
      var urgent = soonest < 10;
      var bs2 = planeBasis(to.wx, to.wz);
      var rr = (24 + 3 * Math.sin(now / 200)) * R.u / bs2.sx;
      c.save();
      c.strokeStyle = 'rgba(' + ATK + ',' +
        (urgent ? (0.55 + 0.35 * Math.sin(now / 150)).toFixed(2) : '0.25') + ')';
      c.lineWidth = urgent ? 2 : 1;
      if (!urgent) c.setLineDash([4, 6]);
      c.beginPath();
      groundRing(c, to.wx, to.wz, rr, 40);
      c.stroke();
      c.restore();
    }
  }

  function spawnBlast(wx, wz, scale, mine, now) {
    var parts = [], n = 15 + ((Math.random() * 10) | 0);
    for (var i = 0; i < n; i++) {
      var hot = Math.random() < 0.5;
      parts.push({
        a: Math.random() * TAU,
        sp: 0.3 + Math.random() * 0.7,
        al: 0.7 + Math.random() * 0.3,
        sz: 2 + Math.random() * 3.5,
        r: hot ? 255 : 220,
        g: hot ? (Math.random() * 140) | 0 : 80
      });
    }
    A.blasts.push({
      wx: wx, wz: wz, scale: scale || 1, t0: now,
      dur: mine ? 1100 : 850, maxR: mine ? 46 : 34, parts: parts
    });
  }

  function drawBlasts(c, now) {
    var v = R.view;
    for (var i = A.blasts.length - 1; i >= 0; i--) {
      var e = A.blasts[i];
      var t = (now - e.t0) / e.dur;
      if (t >= 1) { A.blasts.splice(i, 1); continue; }

      var eased = 1 - Math.pow(1 - t, 2);
      var big = e.maxR * R.u;
      v.pt(e.wx, e.wz, _a);
      var ex = _a.x, ey = _a.y;          // groundRing reuses _a — hold on to it

      /* Shockwave: this one is on the ground, so it takes the rake. */
      c.strokeStyle = 'rgba(255,120,40,' + Math.max(0, (1 - t) * 0.8).toFixed(3) + ')';
      c.lineWidth = 2.5 * R.u * (1 - t);
      c.shadowColor = 'rgba(255,100,30,.6)';
      c.shadowBlur = 12 * R.u;
      c.beginPath();
      groundRing(c, e.wx, e.wz, big * eased / e.scale, 44);
      c.stroke();
      c.shadowBlur = 0;

      if (t > 0.15) {
        var t2 = Math.min(1, (t - 0.15) / 0.85);
        c.strokeStyle = 'rgba(255,60,20,' + Math.max(0, (1 - t2) * 0.45).toFixed(3) + ')';
        c.lineWidth = 1.5 * R.u * (1 - t2);
        c.beginPath();
        groundRing(c, e.wx, e.wz, big * 1.6 * t2 / e.scale, 44);
        c.stroke();
      }

      /* Core flash and debris are not on the ground — screen space. */
      if (t < 0.35) {
        var ft = t / 0.35, fr = Math.max(0.5, big * 0.55 * (1 - ft)), fa = (1 - ft) * 0.9;
        var fg = c.createRadialGradient(ex, ey, 0, ex, ey, fr);
        fg.addColorStop(0, 'rgba(255,255,200,' + fa.toFixed(3) + ')');
        fg.addColorStop(0.4, 'rgba(255,160,40,' + (fa * 0.7).toFixed(3) + ')');
        fg.addColorStop(1, 'rgba(255,60,10,0)');
        c.fillStyle = fg;
        c.shadowColor = 'rgba(255,200,80,.8)';
        c.shadowBlur = 20 * R.u;
        c.beginPath(); c.arc(ex, ey, fr, 0, TAU); c.fill();
        c.shadowBlur = 0;
      }

      for (var j = 0; j < e.parts.length; j++) {
        var q = e.parts[j], fly = q.sp * eased * big * 1.2;
        c.fillStyle = 'rgba(' + q.r + ',' + q.g + ',20,' +
                      Math.max(0, (1 - t) * q.al).toFixed(3) + ')';
        c.beginPath();
        c.arc(ex + Math.cos(q.a) * fly, ey + Math.sin(q.a) * fly,
              q.sz * R.u * (1 - t * 0.5), 0, TAU);
        c.fill();
      }
    }
  }

  /** Forget arcs for conflicts that have resolved — a long round launches a lot
      of them, and every one would otherwise leave an entry behind. */
  function pruneAttacks(threats) {
    var live = {}, i, k;
    for (i = 0; i < threats.length; i++) {
      live[threats[i].id || (threats[i].from * 31 + threats[i].to + 1)] = 1;
    }
    for (k in A.paths) if (!live[k]) { delete A.paths[k]; delete A.fired[k]; }
  }

  function camIndex(id) {
    for (var i = 0; i < S.campaigns.length; i++) if (S.campaigns[i].id === id) return i;
    return -1;
  }

  function hexA(hex, a) {
    var h = (hex || '#00E5CC').replace('#', '');
    return 'rgba(' + parseInt(h.substr(0, 2), 16) + ',' +
                     parseInt(h.substr(2, 2), 16) + ',' +
                     parseInt(h.substr(4, 2), 16) + ',' + a + ')';
  }

  /* ── frame loop: ease every tracked number, redraw the radar ───────────── */

  var lastFrame = 0;

  function frame(now) {
    lastFrame = Date.now();
    if (now === undefined) now = lastFrame;
    var dt = Math.min(0.1, Math.max(0, (now - R.last) / 1000));
    R.last = now;

    Object.keys(anim).forEach(function (key) {
      var a = anim[key];
      var gap = a.target - a.cur;
      if (Math.abs(gap) > 1e-6) {
        a.cur += gap * 0.12;                        // ease
        if (Math.abs(a.target - a.cur) < 1e-4) a.cur = a.target;
        paint(key);
      }
    });
    drawRadar(now, dt);
    requestAnimationFrame(frame);
  }

  /** True when the frame loop has not run recently (throttled or stopped). */
  function frameStalled() {
    return (Date.now() - lastFrame) > 1500;
  }

  /* ── render: state ─────────────────────────────────────────────────────── */

  function renderState(st) {
    S = st;
    R.dirty = true;      // the board moved — matters when the sweep is frozen
    if (!st || !st.ok) return;

    var r = st.round;
    var statusEl = PC.$('#rs-status');

    if (!r) {
      if (statusEl) statusEl.textContent = 'no round';
      return;
    }

    /* header clock hand-off */
    PC.roundEndsAt = Math.floor(Date.now() / 1000) + (r.remaining || 0);
    PC.roundOpen = !!r.is_open;

    setText('#rs-no', '#' + r.no);
    setText('#rd-round', '#' + r.no);
    setText('#rs-phase', r.is_open ? r.phase.toUpperCase() : 'CLOSED');
    setText('#rd-phase', r.is_open ? r.phase.toUpperCase() : 'CLOSED');
    setText('#rs-weight', '×' + Number(r.time_weight).toFixed(3));
    setText('#rs-remaining', r.is_open ? PC.clock(r.remaining) : 'settled');
    setText('#rs-window', r.opens_at.substr(11, 5) + ' → ' + r.closes_at.substr(11, 5) + ' daily');
    if (statusEl) statusEl.textContent = r.is_open ? 'live' : 'closed';

    var bar = PC.$('#rs-progress');
    if (bar) bar.style.width = Math.round((r.frac || 0) * 100) + '%';

    /* event line */
    var evEl = PC.$('#rd-event');
    if (evEl) {
      if (r.event) evEl.innerHTML = 'EVENT <b>' + esc(r.event.name.toUpperCase()) + '</b>';
      else if (r.has_event) evEl.textContent = 'EVENT ACTIVE — UNIDENTIFIED';
      else evEl.textContent = 'NO EVENT DETECTED';
    }

    /* which campaign the headline stats describe */
    var focus = null;
    if (st.me && st.me.campaign_id !== null && st.me.campaign_id !== undefined) {
      focus = st.campaigns.filter(function (c) { return c.id === st.me.campaign_id; })[0];
      setText('#stat-owner', focus ? focus.name + ' — your campaign' : 'Your campaign');
    }
    if (!focus) {
      focus = st.campaigns.slice().sort(function (a, b) { return b.power - a.power; })[0];
      setText('#stat-owner', focus ? focus.name + ' — currently leading' : 'Leading campaign');
    }

    if (focus) {
      statCell('power', focus.power, 0, null, 'teal');
      statCell('trust', focus.trust, 3, null, focus.trust < 0.6 ? 'loss' : (focus.trust < 0.8 ? 'warn' : ''));
      statCell('cred', focus.credibility, 3, null, focus.credibility < 0.9 ? 'warn' : '');
      statCellText('stab', focus.stability.label,
        focus.stability.collapse ? 'loss' : (focus.stability.state === 'stable' ? 'teal' : 'warn'),
        '×' + focus.stability.mod.toFixed(2) +
        (focus.stability.to_next ? ' · ' + PC.num(focus.stability.to_next) + ' to next' : ''));
      statCell('aggr', focus.aggr_cost, 3, 'x', focus.aggr_cost < 0.9 ? 'loss' : '');
      statCell('pop', focus.pop_mod, 3, 'x',
        focus.pop_state === 'overpop' ? 'loss' : (focus.pop_state === 'underdog' ? 'teal' : ''));
      setDelta('aggr', 'Burn ' + focus.burn_rate.toFixed(2) + ' · ' + focus.ag_acts + ' acts');
      setDelta('pop', cap(focus.pop_state));
      setDelta('power', 'Win ' + focus.win_chance.toFixed(1) + '%');
      setDelta('trust', 'Saturation ' + focus.saturation + '%');
      setDelta('cred', 'Policy ×' + focus.policy_impact.toFixed(3));
    }

    renderStandings(st);
    renderThreats(st);
    renderSignals(st);
    renderShape(st);
    renderMe(st);

    setText('#hud-aggr', Number(st.global_aggression).toFixed(4));
    setText('#hud-peace', '+' + (st.peace_bonus * 100).toFixed(2) + '%');
    setText('#hud-conflicts', String((st.threats || []).length));
    syncStatbar();          // the crawl's second copy is not addressable by id
    pruneAttacks(st.threats || []);

    var badge = PC.$('#nav-threat-badge');
    if (badge) {
      var mine = (st.threats || []).filter(function (t) { return t.mine; }).length;
      badge.style.display = mine > 0 ? '' : 'none';
      badge.textContent = String(mine);
    }
    var ts = PC.$('#stk-threat-sub');
    if (ts) ts.textContent = (st.threats || []).length + ' in flight';

    /* If the frame loop is not running there is nothing to ease the numbers
       into place, so write them directly. */
    if (frameStalled()) snapAll();
  }

  function renderStandings(st) {
    var box = PC.$('#standings');
    if (!box) return;

    var mine = st.me && st.me.campaign_id;

    box.innerHTML = st.campaigns.map(function (c) {
      /* The bar IS the number: a 42.3% win chance fills 42.3% of the track.
         It used to be scaled so the leader always filled the whole bar, which
         made a narrow lead look like a rout and a full bar mean nothing. */
      return '<div class="camp-row' + (c.id === mine ? ' is-mine' : '') + '">' +
        '<span class="camp-dot" style="background:' + c.color + ';color:' + c.color + '"></span>' +
        '<span class="camp-name" style="color:' + c.color + '">' + esc(c.name) + '</span>' +
        '<span class="camp-bar" title="' + esc(c.name) + ' win chance ' + c.win_chance.toFixed(1) + '%">' +
          '<span class="camp-fill" style="width:' + barPct(c.win_chance) +
          '%;background:' + c.color + '"></span></span>' +
        '<span class="camp-pct" style="color:' + c.color + '">' + c.win_chance.toFixed(1) + '%</span>' +
      '</div>' +
      '<div class="tiny muted" style="margin:-2px 0 6px 18px">' +
        PC.num(c.face_total) + ' tokens · ' + c.contributors + ' backers · ' +
        cap(c.stability.label) +
        (c.stability.collapse ? ' <span style="color:var(--loss)">· COLLAPSE RISK</span>' : '') +
      '</div>';
    }).join('');
  }

  function renderThreats(st) {
    var box = PC.$('#threats');
    if (!box) return;
    var list = st.threats || [];

    if (!list.length) {
      box.innerHTML = '<div class="empty"><i class="ph ph-shield-check"></i>No conflicts in flight.</div>';
      return;
    }
    box.innerHTML = list.slice(0, 6).map(function (t) {
      return '<div class="ins ' + (t.mine ? 'bad' : 'info') + '">' +
        '<i class="ph-fill ' + (t.mine ? 'ph-warning-octagon' : 'ph-sword') + '"></i>' +
        '<div><b>' + esc(t.from_name) + ' → ' + esc(t.to_name) +
          (t.mine ? ' (you)' : '') + '</b>' +
          'Impact in ' + PC.clock(t.eta) + ' · projected −' + t.severity + '% power' +
          (t.n > 1 ? ' · wave ' + t.n : '') +
        '</div></div>';
    }).join('');
  }

  function renderSignals(st) {
    var box = PC.$('#signals');
    if (!box) return;
    var list = st.signals || [];
    if (!list.length) {
      box.innerHTML = '<div class="empty"><i class="ph ph-broadcast"></i>No broadcasts yet.</div>';
      return;
    }
    box.innerHTML = list.map(function (s) {
      var col = (st.campaigns[s.campaign] || {}).color || '#8FA3B0';
      return '<div class="kv"><span class="k">' +
        '<span style="color:' + col + ';font-weight:600">' + esc(s.name) + '</span> ' +
        esc(s.actor || 'anon') + '</span>' +
        '<span class="v">' + esc(s.type) + '</span></div>';
    }).join('');
  }

  function renderShape(st) {
    var tabs = PC.$('#shape-tabs'), box = PC.$('#shape');
    if (!tabs || !box) return;

    if (shapeTab === null) {
      shapeTab = (st.me && st.me.campaign_id !== null && st.me.campaign_id !== undefined)
               ? st.me.campaign_id : 0;
    }

    tabs.innerHTML = st.campaigns.map(function (c) {
      return '<button class="pill ' + (c.id === shapeTab ? 'up' : 'flat') +
        '" data-shape="' + c.id + '" type="button">' + esc(c.name) + '</button>';
    }).join('');

    var cam = st.campaigns.filter(function (c) { return c.id === shapeTab; })[0];
    if (!cam) { box.innerHTML = ''; return; }

    var ORDER = ['funding', 'organization', 'logistics', 'policy', 'charisma',
                 'messaging', 'opposition_research', 'attack_ads',
                 'negative_campaigning', 'coalitions'];
    var LABEL = {
      funding: 'Finance', organization: 'Organisation', logistics: 'Logistics',
      policy: 'Policy', charisma: 'Charisma', messaging: 'Messaging',
      opposition_research: 'Opp. Research', attack_ads: 'Attack Ads',
      negative_campaigning: 'Neg. Campaign', coalitions: 'Coalitions'
    };
    var TONE = { attack_ads: 'var(--loss)', negative_campaigning: 'var(--loss)', coalitions: 'var(--steel)' };

    /* These bars are scaled against the campaign's OWN biggest system, so the
       panel answers "where is this campaign's money going" at a glance. The
       old code multiplied the raw share by an arbitrary 2.4 — a 21% share drew
       a 51% bar, which meant nothing at all. The caption under the panel says
       which encoding this is, so it cannot be read as an absolute share. */
    var peak = ORDER.reduce(function (m, k) { return Math.max(m, cam.shape[k] || 0); }, 0) || 1;

    box.innerHTML = ORDER.map(function (k) {
      var v = cam.shape[k] || 0;
      var col = TONE[k] || cam.color;
      return '<div class="camp-row" style="padding:4px 0">' +
        '<span class="camp-name" style="width:104px;flex:0 0 104px;color:var(--mist);font-size:12px">' +
          LABEL[k] + '</span>' +
        '<span class="camp-bar" style="height:5px" title="' + LABEL[k] + ': ' + v.toFixed(1) +
          '% of ' + esc(cam.name) + '’s core spend">' +
          '<span class="camp-fill" style="width:' + barPct(v / peak * 100) +
          '%;background:' + col + '"></span></span>' +
        '<span class="camp-pct" style="width:46px;flex:0 0 46px;font-size:12.5px;color:' + col + '">' +
          v.toFixed(1) + '%</span>' +
      '</div>';
    }).join('');

    var note = PC.$('#shape-note');
    if (note) {
      note.textContent = 'Bars are relative to ' + cam.name + '’s largest system ('
        + peak.toFixed(1) + '%). Percentages are share of total spend.';
    }
  }

  function renderMe(st) {
    var me = st.me || {};
    setText('#hud-balance', me.logged_in ? PC.num(me.balance) : 'guest');
    setText('#hud-placed', me.logged_in ? PC.num(me.contributed || 0) : '—');
    setText('#hud-side', me.campaign_name || '—');

    var cta = PC.$('#cta-balance');
    if (cta && me.balance !== undefined) cta.textContent = PC.num(me.balance);

    /* pad costs + availability */
    var canAct = !!me.can_act && st.round && st.round.is_open;
    setText('#cost-scan',    me.scan_cost  ? PC.num(me.scan_cost)  : '—');
    setText('#cost-offense', me.pulse_cost ? PC.num(me.pulse_cost) : '—');
    setText('#cost-defense', me.pulse_cost ? PC.num(me.pulse_cost) : '—');
    setText('#cost-attack',  canAct ? 'ready' : 'locked');
    setText('#cost-defend',  canAct ? 'ready' : 'locked');

    ['pad-attack', 'pad-defend', 'pad-scan', 'pad-offense', 'pad-defense', 'pad-policy', 'pad-hold']
      .forEach(function (id) {
        var el = PC.$('#' + id);
        if (el) el.classList.toggle('is-disabled', !canAct);
      });

    var po = PC.$('#pad-offense'), pd = PC.$('#pad-defense');
    if (po) po.classList.toggle('on', !!me.pulse_off);
    if (pd) pd.classList.toggle('on', !!me.pulse_def);
  }

  /* ── render: feed ──────────────────────────────────────────────────────── */

  var ICONS = {
    contribution: 'ph-coins', attack: 'ph-sword', backfire: 'ph-warning-octagon',
    switch: 'ph-swap', event: 'ph-lightning', signal: 'ph-broadcast',
    join: 'ph-user-plus', round: 'ph-flag', system: 'ph-gear', pulse: 'ph-lightning',
    scan: 'ph-magnifying-glass', coalition: 'ph-handshake', result: 'ph-trophy',
    stipend: 'ph-wallet'
  };

  function renderFeed(items) {
    var box = PC.$('#feed');
    if (!box || !items.length) return;

    /* Newest rows are prepended, so the whole list shifts down by their height.
       If the reader is parked at the top that is invisible and welcome. If they
       have scrolled back to read older events, it drags the line they were on
       out from under them — so measure first and compensate below. */
    var stick  = box.scrollTop <= 12;
    var prevH  = box.scrollHeight;

    items.forEach(function (it) {
      var row = d.createElement('div');
      row.className = 'feed-row';
      row.innerHTML =
        '<div class="feed-ic ' + esc(it.severity) + '"><i class="ph ' +
          (ICONS[it.kind] || 'ph-dot') + '"></i></div>' +
        '<div class="feed-body">' +
          '<div class="feed-txt">' + esc(it.body) + '</div>' +
          '<div class="feed-time">' + PC.ago(it.ago) + '</div>' +
        '</div>';
      box.insertBefore(row, box.firstChild);
    });

    /* Keep the DOM bounded — trim from the bottom, which is the oldest end. */
    while (box.children.length > 60) box.removeChild(box.lastChild);

    if (stick) {
      box.scrollTop = 0;                            // pinned to the newest
    } else {
      box.scrollTop += (box.scrollHeight - prevH);  // hold the reader's place
    }

    var n = PC.$('#feed-count');
    if (n) n.textContent = String(box.children.length);
  }

  /* ── champions strip ───────────────────────────────────────────────────── */

  function loadLeaders() {
    PC.get('api/leaders').then(function (r) {
      if (!r || !r.ok) return;
      var box = PC.$('#champions');
      if (!box) return;
      if (!r.campaign_wins.length) {
        box.innerHTML = '<div class="empty"><i class="ph ph-hourglass-medium"></i>No settled rounds yet.</div>';
        return;
      }
      box.innerHTML = r.campaign_wins.map(function (c, i) {
        return '<div class="kv"><span class="k">' +
          (i === 0 ? '<i class="ph-fill ph-crown" style="color:var(--warn)"></i> ' : '') +
          '<span style="color:' + campColor(c.campaign) + '">' + esc(c.name) + '</span></span>' +
          '<span class="v">' + c.wins + ' round' + (c.wins === 1 ? '' : 's') + '</span></div>';
      }).join('');
    }).catch(function () {});
  }

  /* ── helpers ───────────────────────────────────────────────────────────── */

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /**
   * Campaign colour by id. Never hardcode a hex here — GameEngine::$CAMPAIGNS
   * is the source of truth and the state payload carries it. Falls back to the
   * CSS custom properties for the case where the champions strip renders
   * before the first state poll has landed.
   */
  var CAMP_VARS = ['--leni', '--sara', '--marcos'];
  function campColor(cid) {
    if (S && S.campaigns && S.campaigns[cid] && S.campaigns[cid].color) {
      return S.campaigns[cid].color;
    }
    var v = CAMP_VARS[cid];
    return (v && getComputedStyle(d.documentElement).getPropertyValue(v).trim()) || '#8FA3B0';
  }
  function cap(s) { return String(s || '').charAt(0).toUpperCase() + String(s || '').slice(1); }

  /**
   * Clamp a bar to the track, but keep a non-zero value visible.
   * Without the floor a 1% share draws sub-pixel and reads as "nothing here",
   * which is a different statement from "a little here".
   */
  function barPct(pct) {
    if (!(pct > 0)) return 0;
    return Math.min(100, Math.max(2.5, pct)).toFixed(1);
  }

  function setText(sel, txt) {
    var el = PC.$(sel);
    if (el) el.textContent = txt;
  }

  function statCell(key, value, dp, fmt, tone) {
    var cell = d.querySelector('[data-stat="' + key + '"]');
    if (!cell) return;
    var vEl = cell.querySelector('[data-v]');
    if (!vEl) return;

    vEl.setAttribute('data-anim', key);
    vEl.className = 'stat-value' + (tone ? ' ' + tone : '');

    if (prev[key] !== undefined && Math.abs(prev[key] - value) > 1e-9) {
      var worse = value < prev[key];
      cell.classList.remove('flash', 'flash-bad');
      void cell.offsetWidth;                       // restart the animation
      cell.classList.add(worse ? 'flash-bad' : 'flash');
      setTimeout(function () { cell.classList.remove('flash', 'flash-bad'); }, 700);
    }
    prev[key] = value;
    track(key, value, dp, fmt);
  }

  function statCellText(key, text, tone, delta) {
    var cell = d.querySelector('[data-stat="' + key + '"]');
    if (!cell) return;
    var vEl = cell.querySelector('[data-v]');
    if (vEl) {
      vEl.removeAttribute('data-anim');
      vEl.className = 'stat-value' + (tone ? ' ' + tone : '');
      vEl.style.fontSize = '15px';
      vEl.textContent = text;
    }
    if (delta !== undefined) setDelta(key, delta);
  }

  function setDelta(key, txt) {
    var cell = d.querySelector('[data-stat="' + key + '"]');
    if (!cell) return;
    var el = cell.querySelector('[data-d]');
    if (el) el.textContent = txt;
  }

  /* ── game pads ─────────────────────────────────────────────────────────── */

  function pickTarget(title, cb) {
    if (!S) return;
    var mine = S.me ? S.me.campaign_id : null;
    var opts = S.campaigns.filter(function (c) { return c.id !== mine; });

    PC.modal(
      '<div class="dlg-title">' + esc(title) + '</div>' +
      '<div class="tiny muted mb">Pick a rival campaign.</div>' +
      opts.map(function (c) {
        return '<button class="pick mb" data-target="' + c.id + '" type="button">' +
          '<span class="pick-badge" style="background:' + c.color + '">' + esc(c.name.charAt(0)) + '</span>' +
          '<span class="pick-meta"><span class="pick-name">' + esc(c.name) + '</span>' +
          '<span class="pick-sub">Power ' + PC.num(c.power) + ' · defence ' +
            (c.eff_defense * 100).toFixed(1) + '% · ' + cap(c.stability.label) + '</span></span>' +
          '<i class="ph ph-caret-right" style="color:var(--teal)"></i></button>';
      }).join('') +
      '<button class="btn btn-ghost btn-block mt" data-close-modal type="button">Cancel</button>'
    );

    d.querySelectorAll('[data-target]').forEach(function (b) {
      b.addEventListener('click', function () {
        PC.closeModal();
        cb(parseInt(b.getAttribute('data-target'), 10));
      });
    });
  }

  function showScan(res) {
    var rows = Object.keys(res.payload.system_breakdown).map(function (k) {
      var s = res.payload.system_breakdown[k];
      return '<div class="kv"><span class="k">' + esc(s.label) + '</span>' +
             '<span class="v">' + s.displayed + '%</span></div>';
    }).join('');

    PC.modal(
      '<div class="dlg-title">Intel — ' + esc(res.target_name) + '</div>' +
      '<div class="tiny muted mb">Accuracy ' + (res.accuracy * 100).toFixed(0) +
        '%. Figures are deliberately blurred; repeat scans in the same window read worse.</div>' +
      rows +
      '<h3 class="sub-sec mt-lg"><i class="ph ph-gauge"></i> Posture</h3>' +
      '<div class="kv"><span class="k">Trust</span><span class="v">' + esc(res.payload.trust_level) + '</span></div>' +
      '<div class="kv"><span class="k">Stability</span><span class="v">' + cap(res.payload.stability_state) + '</span></div>' +
      '<div class="kv"><span class="k">Collapse risk</span><span class="v ' +
        (res.payload.collapse_risk ? 'loss">YES' : 'teal">no') + '</span></div>' +
      '<div class="kv"><span class="k">Backfire chance</span><span class="v">' +
        (res.payload.backfire_chance * 100).toFixed(1) + '%</span></div>' +
      '<div class="kv"><span class="k">Effective defence</span><span class="v">' +
        (res.payload.effective_defense * 100).toFixed(1) + '%</span></div>' +
      '<div class="kv"><span class="k">Conflicts opened</span><span class="v">' + res.payload.ag_acts + '</span></div>' +
      (res.event_revealed
        ? '<div class="alert ok mt"><i class="ph-fill ph-lightning"></i><div><b>' +
          esc(res.event.name) + '</b> — ' + esc(res.event.desc) + '</div></div>'
        : '') +
      '<button class="btn btn-primary btn-block mt" data-close-modal type="button">Close</button>'
    );
  }

  /* ── tactical pads: hide when idle, reveal on demand ───────────────────── */

  var PADS_IDLE_MS = 10000;
  var padsTimer = null;

  function padsEl() { return PC.$('#pads'); }

  function padsArm() {
    clearTimeout(padsTimer);
    padsTimer = setTimeout(function () {
      var p = padsEl();
      if (p) p.classList.add('tucked');
    }, PADS_IDLE_MS);
  }

  function padsShow() {
    var p = padsEl();
    if (!p) return;
    p.classList.remove('tucked');
    padsArm();                     // any reveal restarts the idle countdown
  }

  function wirePadsVisibility() {
    if (!padsEl()) return;         // guests and drill-down pages have no pads

    padsArm();                     // visible on load, gone a minute later

    var nav = PC.$('#nav');
    if (nav) {
      nav.addEventListener('mouseenter', padsShow);
      /* Touch has no hover. Without this the pads would be unreachable on a
         phone once they tuck away — which is most of the audience. Passive so
         the tap still lands on whatever nav item was pressed. */
      nav.addEventListener('touchstart', padsShow, { passive: true });

      /* Home, while already on Home, brings the pads up instead of reloading.
         This file only loads on the war room, so reaching this line at all means
         we are on the page that anchor points at — a full round trip to fetch
         the identical page, tearing down the radar and both poll loops, to end
         up where we started.
         It is bound here rather than in app.js on purpose: wirePadsVisibility()
         has already returned for anyone without pads, so guests and drill-down
         pages keep an ordinary link that ordinarily navigates. */
      var home = nav.querySelector('[data-nav="home"]');
      if (home) {
        home.addEventListener('click', function (e) {
          /* Leave modified clicks alone — opening the war room in a new tab is
             a real thing to want, and swallowing it would be baffling. */
          if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button) return;
          e.preventDefault();
          padsShow();
        });
      }
    }

    /* Reaching for the pads must not make them disappear mid-reach. */
    var p = padsEl();
    p.addEventListener('mouseenter', padsArm);
    p.addEventListener('touchstart', padsArm, { passive: true });

    /* Coming back to the tab counts as arriving, same as a refresh. */
    d.addEventListener('visibilitychange', function () {
      if (!d.hidden) padsShow();
    });
  }

  function wirePads() {
    d.addEventListener('click', function (e) {
      var pad = e.target.closest ? e.target.closest('.pad') : null;
      if (!pad || pad.classList.contains('is-disabled')) {
        if (pad) PC.toast('Place tokens this round before using tactical actions.', 'warn');
        return;
      }
      padsArm();                   // used them — restart the countdown
      var act = pad.getAttribute('data-act');

      if (act === 'attack') {
        pickTarget('Open an offensive', function (target) {
          PC.post('game/attack', { target: target }).then(function (r) {
            if (!r.ok) return PC.toast(PC.reason(r.reason), 'bad');
            PC.toast('Offensive launched. Impact in ' + Math.ceil(r.eta / 60) +
                     ' min. Backfire risk ' + (r.backfire_chance * 100).toFixed(0) + '%.', 'ok');
            poll();
          });
        });

      } else if (act === 'scan') {
        pickTarget('Run opposition research', function (target) {
          PC.post('game/scan', { target: target }).then(function (r) {
            if (!r.ok) return PC.toast(PC.reason(r.reason), 'bad');
            showScan(r);
            poll();
          });
        });

      } else if (act === 'offense' || act === 'defense') {
        PC.post('game/pulse', { type: act }).then(function (r) {
          if (!r.ok) return PC.toast(PC.reason(r.reason), 'bad');
          PC.toast(cap(act) + ' surge active for 15 minutes. Cost ' + PC.num(r.cost) + '.', 'ok');
          poll();
        });

      } else if (act === 'defend' || act === 'policy' || act === 'hold') {
        var map = { defend: 'DEFEND', policy: 'PUSH POLICY', hold: 'HOLD' };
        PC.post('game/signal', { type: map[act] }).then(function (r) {
          if (!r.ok) return PC.toast(PC.reason(r.reason), 'bad');
          PC.toast('Signalled ' + map[act] + ' to the field.', 'ok');
          pollFeed();
        });
      }
    });

    /* allocation-shape tabs */
    d.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-shape]') : null;
      if (!t) return;
      shapeTab = parseInt(t.getAttribute('data-shape'), 10);
      if (S) renderShape(S);
    });
  }

  /* ── poll loops ────────────────────────────────────────────────────────── */

  var failures = 0;

  function poll() {
    return PC.get('api/state').then(function (r) {
      failures = 0;
      renderState(r);
    }).catch(function () {
      failures++;
      var el = PC.$('#rs-status');
      if (el) el.textContent = failures > 2 ? 'offline' : 'retrying';
    });
  }

  function pollFeed() {
    return PC.get('api/feed', { since: feedHead }).then(function (r) {
      if (!r || !r.ok) return;
      if (r.items.length) {
        feedHead = r.head;
        renderFeed(r.items);
      }
    }).catch(function () {});
  }

  /* ── boot ──────────────────────────────────────────────────────────────── */

  d.addEventListener('DOMContentLoaded', function () {
    initRadar();
    initStatbar();
    wirePads();
    wirePadsVisibility();

    /* The first batch of feed rows is server-rendered, so seed the counter
       from the DOM rather than waiting for the first incremental poll. */
    var fb = PC.$('#feed'), fc = PC.$('#feed-count');
    if (fb && fc) fc.textContent = String(fb.querySelectorAll('.feed-row').length);

    poll().then(function () {
      if (S && S.feed_head) feedHead = S.feed_head;
    });
    loadLeaders();

    setInterval(poll, STATE_MS);
    setInterval(pollFeed, FEED_MS);
    setInterval(loadLeaders, 60000);

    /* Stop hammering the server while the tab is hidden. */
    d.addEventListener('visibilitychange', function () {
      if (!d.hidden) { poll(); pollFeed(); }
    });
  });

})(window, document);
