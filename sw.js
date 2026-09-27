/* ============================================================================
   sw.js — PolitiClash service worker

   Strategy per resource:
     app shell (CSS/JS/fonts/icons)  Cache First, versioned cache
     HTML pages                      Network First, cache fallback, then /offline
     /api/state, /api/feed           Network Only — a stale board is worse than
                                     no board, and both are polled every few
                                     seconds anyway
     POST commands                   Network Only, never intercepted

   CACHE_VERSION only needs bumping when THIS FILE's logic changes. CSS and
   JS bust themselves via pc_asset() mtime versioning, so a normal deploy
   needs no version edit anywhere.
============================================================================ */
'use strict';

const CACHE_VERSION = 'v1.3.2';
const CACHE = {
  shell:  `pc-shell-${CACHE_VERSION}`,
  assets: `pc-assets-${CACHE_VERSION}`,
  pages:  `pc-pages-${CACHE_VERSION}`,
};
const ALL = Object.values(CACHE);

/* Scope-relative so the app works at the document root or in a subdirectory. */
const BASE = new URL(self.registration.scope).pathname.replace(/\/+$/, '');
const P = (p) => `${BASE}/${p.replace(/^\/+/, '')}`;

/**
 * CSS and JS are deliberately NOT precached here.
 *
 * Pages link them through pc_asset(), which appends the file's mtime — so the
 * real request is `theme.css?v=1785432109`, never the bare path. Precaching the
 * bare URL cached a copy nothing ever asks for, and any harness or tool that
 * did request the bare path got served a stale build indefinitely.
 *
 * The runtime cacheFirst handler still caches them, keyed on the versioned URL,
 * so they are offline-available after one normal page view and a new deploy
 * simply produces a new key. Fonts and icons stay here: their URLs are stable
 * and they effectively never change.
 */
const SHELL = [
  P(''),
  P('manifest.json'),
  P('assets/fonts/Phosphor.woff2'),
  P('assets/fonts/Phosphor-Fill.woff2'),
  P('assets/fonts/Phosphor-Bold.woff2'),
  P('icons/icon-192.png'),
  P('icons/icon-512.png'),
  P('offline'),
];

// ── install ──────────────────────────────────────────────────────────────────
self.addEventListener('install', (e) => {
  e.waitUntil((async () => {
    const c = await caches.open(CACHE.shell);
    // Individually, so one 404 does not abort the whole install.
    await Promise.all(SHELL.map((u) => c.add(u).catch(() => {})));
    await self.skipWaiting();
  })());
});

// ── activate ─────────────────────────────────────────────────────────────────
self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((k) => k.startsWith('pc-') && !ALL.includes(k))
                          .map((k) => caches.delete(k)));
    await self.clients.claim();
  })());
});

// ── fetch ────────────────────────────────────────────────────────────────────
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;                       // commands go straight out

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;        // fonts CDN etc: let the network handle it

  // Live game data is never served from cache.
  if (url.pathname.includes('/api/')) return;
  if (url.pathname.includes('/cron/')) return;

  // Versioned static assets: cache first.
  if (/\.(css|js|woff2?|png|svg|jpg|webp)$/i.test(url.pathname)) {
    e.respondWith(cacheFirst(req, CACHE.assets));
    return;
  }

  // Everything else is a page.
  if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
    e.respondWith(networkFirst(req));
    return;
  }

  e.respondWith(cacheFirst(req, CACHE.assets));
});

async function cacheFirst(req, cacheName) {
  const cached = await caches.match(req);
  if (cached) return cached;
  try {
    const res = await fetch(req);
    if (res && res.ok) {
      const c = await caches.open(cacheName);
      await c.put(req, res.clone());
      pruneSuperseded(c, req);          // fire and forget
    }
    return res;
  } catch (err) {
    return cached || Response.error();
  }
}

/**
 * Drop older versions of an asset once a newer one arrives.
 *
 * Assets are versioned by mtime (pc_asset), so every deploy mints a brand new
 * URL — theme.css?v=1785453454 becomes theme.css?v=1785453942. That is exactly
 * what makes a deploy reach returning visitors without touching CACHE_VERSION,
 * but each new URL is a new cache key and the old one is never requested again.
 * Left alone, a device accumulates one dead copy of every CSS and JS file for
 * every deploy, forever.
 *
 * Same path, different query = superseded. Keep one.
 */
async function pruneSuperseded(cache, req) {
  try {
    const url = new URL(req.url);
    if (!url.search) return;            // unversioned (fonts, icons) — nothing to supersede
    const keys = await cache.keys();
    await Promise.all(keys.map((k) => {
      const u = new URL(k.url);
      return (u.pathname === url.pathname && u.search !== url.search)
        ? cache.delete(k)
        : null;
    }));
  } catch (err) { /* housekeeping only — never break the response path */ }
}

async function networkFirst(req) {
  try {
    const res = await fetch(req);
    if (res && res.ok) {
      const c = await caches.open(CACHE.pages);
      c.put(req, res.clone());
    }
    return res;
  } catch (err) {
    const cached = await caches.match(req);
    if (cached) return cached;
    const offline = await caches.match(P('offline'));
    if (offline) return offline;
    return new Response(
      '<h1>Offline</h1><p>PolitiClash needs a connection to read the live board.</p>',
      { headers: { 'Content-Type': 'text/html' }, status: 503 }
    );
  }
}

// Allow the page to force an update after a deploy.
self.addEventListener('message', (e) => {
  if (e.data === 'skipWaiting') self.skipWaiting();
});
