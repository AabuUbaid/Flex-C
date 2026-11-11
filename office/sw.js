// Flex-C service worker (network first: always the newest screens when online, last copy when offline).
// FP is a fingerprint of the files this app loads; tools/fingerprint.php rewrites it on every build.
const APP = 'office';
const FP = 'd077a22084';
const CACHE = 'flexc-' + APP + '-' + FP;
const SHELL = ['./', './index.html', './manifest.webmanifest', '../assets/app.css', '../assets/app.js', '../assets/icons.js', '../assets/qr.js', '../assets/pwa.js', '../assets/scan.js', '../assets/stocktake.js', '../assets/barcode.js', '../assets/icons/' + APP + '-192.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL.map((u) => new Request(u, { cache: 'reload' })))).catch(() => {}));
  self.skipWaiting();
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k.startsWith('flexc-' + APP + '-') && k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  const r = e.request, u = new URL(r.url);
  if (r.method !== 'GET' || u.origin !== location.origin || u.pathname.includes('/api/')) return;
  e.respondWith(
    (r.mode === 'navigate' ? fetch(r.url, { cache: 'no-cache', credentials: 'same-origin' }) : fetch(r)).then((res) => {
      if (res.ok) { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(r, copy)); }
      return res;
    }).catch(() => caches.match(r, { ignoreSearch: r.mode !== 'navigate' ? false : true }).then((hit) => hit || caches.match(r, { ignoreSearch: true }) || caches.match('./index.html')))
  );
});
