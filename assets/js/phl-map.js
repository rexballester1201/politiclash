/* ============================================================================
   phl-map.js — the Philippine relief plate, ported to 2D canvas

   3d-map.html renders this with three.js: 91 coastlines extruded into a plate
   and a shader radar laid flat on top of it. That view is FIXED — one bearing,
   one pitch, no orbit — so none of WebGL's reason for existing applies. What is
   left is a static projection, which canvas can do in about a hundred lines and
   without a 600 KB CDN dependency this PWA would then have to cache, or a WebGL
   context every phone in the audience has to supply.

   So the plate is projected and rasterised ONCE per size change into two
   offscreen canvases:

     plate   the cold map — how the archipelago sits when nothing is lit
     hot     the same geometry in hot phosphor — what the sweep exposes

   The cockpit blits `plate` every frame, then blits `hot` clipped to the beam
   wedge, which is what makes the coastlines flare as the sweep crosses them.
   Everything else the radar draws — rings, spokes, beam, campaign nodes — goes
   through the projection helpers below, so it takes the same foreshortening as
   the land instead of sitting flat on the glass.

   Depends on phl-geo.js (window.PC_PHL). Exposes window.PCMAP.
============================================================================ */
(function (w, d) {
  'use strict';

  var G = w.PC_PHL;
  if (!G) return;

  /* ── the fixed view ───────────────────────────────────────────────────────
     Bearing is 30° round from 3d-map.html's 350, and that is a gameplay
     decision rather than a stylistic one. The three bastions — Batac, Naga,
     Davao — sit almost on one north-south line, so from 350 they stack nearly
     vertically and every trajectory between them runs straight up and down the
     screen. Turning the plate spreads them across the diagonal, which is what
     lets an attack's arc read as an arc instead of a line. 20 is as far as it
     goes before the archipelago stops looking like itself.

     Pitch sets the framing, not just the look: it decides how much of a square
     panel the range disc can reach. At 3d-map's 30° a 2,000 km disc foreshortens
     to 1.96:1, the width binds, and the disc takes 96% of the panel's width but
     only 49% of its height. 36° brings that to 57% while keeping the rake low
     enough to read as a plate. The spare height still goes above and below the
     map, which is where the floating HUDs and corner tags sit. Horizontal
     padding is trimmed to 2.5% to give the disc all the width it can take. */
  var BEARING  = 18;
  var PITCH    = 30;
  var CAM_MULT = 3.2;        // camera distance, in radar radii. Sets how much
                             // perspective the near edge gets; 3d-map is ~3.

  /* Range. Was 600 km on Panay, which stopped short of both ends of the country.
     1,000 km from the centre of the archipelago's bounding box reaches Sibutu in
     Tawi-Tawi at 951 km and the northern tip of the Batanes at 885 km — the two
     coastlines that define the extremes — with all 89 others inside them.
     Measured, not estimated: tools/make-phl-geo.js ships the same table this
     reads. Rings every 250 km put the rim on a round number. */
  var RADAR_KM = 1000;
  var RING_KM  = 250;

  var TAU = Math.PI * 2, DEG = Math.PI / 180;
  var KM_PER_UNIT = 111.32 / G.scale;

  /* ── phosphor ─────────────────────────────────────────────────────────────
     P1 phosphor is a yellow-green, not the blue-green of the cockpit's teal and
     not SARA's leaf green — which matters, because campaign nodes have to stay
     legible on top of it. Hue sits around 113°, well clear of both. */
  var PHOS = {
    hi:  '150,255,120',      // bloom — leading edge of the beam, lit coastlines
    mid: '92,255,74',        // the working green: rings, land, chrome
    dim: '58,168,64'         // decayed trail
  };
  function phos(tone, a) { return 'rgba(' + PHOS[tone] + ',' + a + ')'; }

  /* Cold plate and lit plate differ only in how hard they are driven. */
  var COLD = {
    capN: phos('mid', .150), capS: phos('mid', .055),
    wall: phos('mid', .085),
    coast: phos('hi', .40), coastW: 1, glow: phos('mid', .5), blur: 2
  };
  var HOT = {
    capN: phos('hi', .62), capS: phos('mid', .30),
    wall: phos('mid', .34),
    coast: 'rgba(226,255,205,.95)', coastW: 1.3, glow: phos('hi', .95), blur: 7
  };

  /* ── build ───────────────────────────────────────────────────────────────
     Returns a view: two rasters plus the projection helpers that put anything
     else in the same plane. Costs one full rasterisation, so call it on size
     changes only — never per frame. */
  function build(cssW, cssH, dpr) {
    cssW = Math.max(80, cssW | 0);
    cssH = Math.max(80, cssH | 0);
    dpr  = Math.min(2, dpr || 1);

    var R = RADAR_KM / KM_PER_UNIT;                  // radar radius, world units

    /* ── camera ───────────────────────────────────────────────────────────
       Everything below is recomputed by aim(), which is the cheap half of this
       module — about 0.05 ms. Rasterising the plate is the expensive half at
       roughly 7 ms, and the two are deliberately separable: a rotating view can
       re-aim every frame so the overlays stay smooth, and re-draw the coastline
       only as often as it can afford to. Measured, so the split is not a guess:
       canvas allocation is 0.33 ms and projecting every coastline point four
       times over is 0.63 ms — the cost is all in fill and stroke. */
    var ex, ey, ez, fx, fy, fz, rx, ry, rz, ux, uy, uz, k, ox, oy;
    var DEPTH = 1, TOP = 1.06, bearing = BEARING;

    var tmp = [0, 0];
    /** Camera-space projection, before the fit. Returns depth. */
    function raw(wx, wy, wz, o) {
      var dx = wx - ex, dy = wy - ey, dz = wz - ez;
      var dep = dx * fx + dy * fy + dz * fz;
      if (dep < 0.01) dep = 0.01;
      o[0] = (dx * rx + dy * ry + dz * rz) / dep;
      o[1] = (dx * ux + dy * uy + dz * uz) / dep;
      return dep;
    }

    /** World point -> CSS pixels. */
    function project(wx, wy, wz, o) {
      var dep = raw(wx, wy, wz, tmp);
      o = o || {};
      o.x = ox + k * tmp[0];
      o.y = oy - k * tmp[1];
      o.d = dep;
      return o;
    }

    /**
     * Point the camera. `deg` is the compass bearing it faces; the archipelago
     * appears to turn the opposite way as this advances. Recomputes the basis,
     * the fit and the plate thickness, then republishes them on the view object
     * — callers hold a reference to that, not to a snapshot.
     */
    function aim(deg) {
      bearing = deg;
      var AZ = -deg * DEG, EL = PITCH * DEG, dist = R * CAM_MULT;
      ex = dist * Math.cos(EL) * Math.sin(AZ);
      ey = dist * Math.sin(EL);
      ez = dist * Math.cos(EL) * Math.cos(AZ);

      fx = -ex / dist; fy = -ey / dist; fz = -ez / dist;        // forward
      var rl = Math.hypot(fz, fx) || 1;
      rx = -fz / rl; ry = 0; rz = fx / rl;                      // right
      ux = ry * fz - rz * fy;                                   // up
      uy = rz * fx - rx * fz;
      uz = rx * fy - ry * fx;

      /* Fit the whole disc, not just the land: the point of a 1,000 km range is
         being able to see that it closes around both ends of the country, and a
         rim running off the panel does not show that. The disc contains every
         coastline, so fitting it fits them too. Refitting on every aim also
         keeps the framing steady through a rotation instead of letting the
         archipelago swing in and out of the frame. */
      var minU = Infinity, maxU = -Infinity, minV = Infinity, maxV = -Infinity, i, a;
      k = 1; ox = 0; oy = 0;
      for (i = 0; i < 96; i++) {
        a = (i / 96) * TAU;
        raw(Math.sin(a) * R, 0, -Math.cos(a) * R, tmp);
        if (tmp[0] < minU) minU = tmp[0];
        if (tmp[0] > maxU) maxU = tmp[0];
        if (tmp[1] < minV) minV = tmp[1];
        if (tmp[1] > maxV) maxV = tmp[1];
      }
      var padX = cssW * 0.025, padY = cssH * 0.035;
      k  = Math.min((cssW - 2 * padX) / (maxU - minU),
                    (cssH - 2 * padY) / (maxV - minV));
      ox = cssW / 2 - k * (minU + maxU) / 2;
      oy = cssH / 2 + k * (minV + maxV) / 2;

      /* Plate thickness. 3d-map extrudes one scene unit — about 5 px full
         screen, and well under one here, which is no relief at all. Solve for
         the height that lands on a legible edge at this size instead. */
      var pa = project(0, 0, 0), pb = project(0, 1, 0);
      var risePerUnit = Math.abs(pb.y - pa.y);
      var wantPx = Math.max(2.5, Math.min(9, cssH * 0.016));
      DEPTH = risePerUnit > 1e-6
        ? Math.max(0.4, Math.min(R * 0.05, wantPx / risePerUnit))
        : 1;
      TOP = DEPTH + 0.06;      // overlays ride just clear of the land

      if (view) {
        var o1 = at(0, 0), o2 = at(Math.PI / 2, 10);
        view.depth = DEPTH;
        view.bearing = deg;
        view.cx = o1.x;
        view.cy = o1.y;
        view.pxPerUnit = Math.hypot(o2.x - o1.x, o2.y - o1.y) / 10;
      }
      return view;
    }

    /* ── helpers the cockpit draws through ────────────────────────────────
       All three land on the overlay height, so anything built from them sits
       in the plate's plane and foreshortens with it. Every one takes an
       optional output object: these run per frame, per vertex. */

    /** Plane coordinates -> CSS pixels. Compose with xz() to place a lon/lat. */
    function pt(wx, wz, o) { return project(wx, TOP, wz, o); }
    /** A point ALOFT — `alt` is height above the plate, in the same world units
        as everything else. This is what puts a projectile in the air instead of
        painting it on the ground: the camera does the rest. */
    function air(wx, alt, wz, o) { return project(wx, TOP + alt, wz, o); }
    /** Polar around the hub — bearing in radians clockwise from north. */
    function at(bearing, range, o) {
      return project(Math.sin(bearing) * range, TOP, -Math.cos(bearing) * range, o);
    }

    /* ── rasterise ────────────────────────────────────────────────────────
       Island bounding-box centres, in plane coordinates. Cheap to hold, and the
       painter's-algorithm sort has to be redone on every raster because which
       island is furthest changes the moment the camera moves. */
    var mid = G.islands.map(function (isl) {
      var x0 = Infinity, x1 = -Infinity, y0 = Infinity, y1 = -Infinity, p = isl.p;
      for (var j = 0; j < p.length; j++) {
        if (p[j][0] < x0) x0 = p[j][0];
        if (p[j][0] > x1) x1 = p[j][0];
        if (p[j][1] < y0) y0 = p[j][1];
        if (p[j][1] > y1) y1 = p[j][1];
      }
      return { x: (x0 + x1) / 2, z: -(y0 + y1) / 2 };
    });
    var order = [];
    function reorder() {
      order.length = 0;
      for (var i = 0; i < mid.length; i++) {
        order.push({ i: i, d: project(mid[i].x, 0, mid[i].z).d });
      }
      order.sort(function (a, b) { return b.d - a.d; });     // far side first
    }

    function surface() {
      var cv = d.createElement('canvas');
      cv.width  = Math.round(cssW * dpr);
      cv.height = Math.round(cssH * dpr);
      var g = cv.getContext('2d');
      g.setTransform(dpr, 0, 0, dpr, 0, 0);
      return { cv: cv, g: g };
    }

    var top = [], bot = [];
    function draw(g, sk) {
      g.lineJoin = g.lineCap = 'round';

      order.forEach(function (o) {
        var p = G.islands[o.i].p, n = p.length, i, j, q;
        if (n < 3) return;

        var sy0 = Infinity, sy1 = -Infinity;
        for (i = 0; i < n; i++) {
          q = project(p[i][0], DEPTH, -p[i][1], top[i] || (top[i] = {}));
          if (q.y < sy0) sy0 = q.y;
          if (q.y > sy1) sy1 = q.y;
          project(p[i][0], 0, -p[i][1], bot[i] || (bot[i] = {}));
        }

        /* Winding on screen, so the outward normal of each edge is known
           without assuming anything about how the source wound its rings. */
        var area = 0;
        for (i = 0; i < n; i++) {
          j = (i + 1) % n;
          area += top[i].x * top[j].y - top[j].x * top[i].y;
        }
        var s = area > 0 ? 1 : -1;   // +1: clockwise on screen (y runs down)

        /* Side walls — only the ones facing the camera. Drawing all of them
           would hang a second silhouette off the far coast of every island. */
        g.beginPath();
        for (i = 0; i < n; i++) {
          j = (i + 1) % n;
          var ex2 = top[j].x - top[i].x, ey2 = top[j].y - top[i].y;
          var nx = s * ey2, ny = -s * ex2;                       // outward
          var vx = bot[i].x - top[i].x, vy = bot[i].y - top[i].y; // the extrusion
          if (nx * vx + ny * vy <= 0) continue;
          g.moveTo(top[i].x, top[i].y);
          g.lineTo(top[j].x, top[j].y);
          g.lineTo(bot[j].x, bot[j].y);
          g.lineTo(bot[i].x, bot[i].y);
          g.closePath();
        }
        g.fillStyle = sk.wall;
        g.fill();

        /* Cap. The gradient runs down the screen so the far coast catches more
           of the light than the near one — the plate's only shading cue. */
        g.beginPath();
        g.moveTo(top[0].x, top[0].y);
        for (i = 1; i < n; i++) g.lineTo(top[i].x, top[i].y);
        g.closePath();

        if (sy1 - sy0 > 0.75) {
          var gd = g.createLinearGradient(0, sy0, 0, sy1);
          gd.addColorStop(0, sk.capN);
          gd.addColorStop(1, sk.capS);
          g.fillStyle = gd;
        } else {
          g.fillStyle = sk.capN;         // an islet a pixel tall has no gradient
        }
        g.fill();

        g.strokeStyle = sk.coast;
        g.lineWidth = sk.coastW;
        g.shadowColor = sk.glow;
        g.shadowBlur = sk.blur;
        g.stroke();
        g.shadowBlur = 0;
      });
    }

    /* The two canvases are allocated once and redrawn in place. Reallocating
       them per raster is only 0.33 ms, but it is 0.33 ms of garbage a second
       under rotation for no reason. */
    var cold = surface(), lit = surface();

    /** Redraw both plates at the current aim. THIS is the expensive call. */
    function raster() {
      cold.g.clearRect(0, 0, cssW, cssH);
      lit.g.clearRect(0, 0, cssW, cssH);
      reorder();
      draw(cold.g, COLD);
      draw(lit.g, HOT);
      view.builtAt = bearing;
      return view;
    }

    var view = {
      w: cssW, h: cssH,
      plate: cold.cv, hot: lit.cv,
      R: R, depth: DEPTH, bearing: BEARING, builtAt: BEARING,
      km: RADAR_KM, ringKm: RING_KM, kmPerUnit: KM_PER_UNIT,
      cx: 0, cy: 0, pxPerUnit: 1,
      pt: pt, air: air, at: at, aim: aim, raster: raster
    };

    aim(BEARING);
    raster();
    return view;
  }

  /** Degrees -> the geo file's plane coordinates. View-independent. */
  function xz(lon, lat) {
    return {
      x:  (lon - G.lon0) * G.kx * G.scale,
      z: -(lat - G.lat0) * G.scale
    };
  }

  w.PCMAP = {
    build: build,
    xz: xz,
    phos: phos,
    RADAR_KM: RADAR_KM,
    RING_KM: RING_KM,
    KM_PER_UNIT: KM_PER_UNIT
  };
})(window, document);
