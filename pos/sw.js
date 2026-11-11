// Flex-C POS service worker: keeps the app shell available offline.
// Bump V whenever index.html changes so registers pick up the new version.
const V = 'flexc-pos-v1.9.4';
// FP is a fingerprint of everything the POS loads; tools/fingerprint.php rewrites it on every build.
const FP = '2d1f78560d';
const CACHE = V + '-' + FP;
const SHELL = ['./', './index.html', './manifest.webmanifest', '../assets/icons.js', '../assets/qr.js', '../assets/pwa.js', '../assets/zxing.min.js', '../assets/icons/pos-192.png', '../assets/icons/pos-apple.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL.map((u) => new Request(u, { cache: 'reload' })))));
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('flexc-pos-') && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const r = e.request;
  const u = new URL(r.url);
  // Never touch API calls or cross-origin requests.
  if (r.method !== 'GET' || u.origin !== location.origin || u.pathname.includes('/api/')) return;
  // Network first, so a new version always shows on the next visit; if the line is slow (3 s) or down, the saved copy.
  const fresh = r.mode === 'navigate' ? fetch(r.url, { cache: 'no-cache', credentials: 'same-origin' }) : fetch(r);
  const net = fresh.then((res) => {
    if (res.ok) {
      const copy = res.clone();
      caches.open(CACHE).then((c) => c.put(r, copy));
    }
    return res;
  });
  e.respondWith(new Promise((resolve) => {
    let done = false;
    const fallback = () => caches.match(r).then((hit) => hit || caches.match(r, { ignoreSearch: true })).then((hit) => hit || (r.mode === 'navigate' ? caches.match('./index.html') : null));
    const t = setTimeout(() => { fallback().then((hit) => { if (hit && !done) { done = true; resolve(hit); } }); }, 3000);
    net.then((res) => { if (!done) { done = true; clearTimeout(t); resolve(res); } })
      .catch(() => fallback().then((hit) => { if (!done) { done = true; clearTimeout(t); resolve(hit || Response.error()); } }));
  }));
});
