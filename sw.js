// Flex-C home page service worker: network first, last copy when offline. Only handles the home page itself.
const V = 'flexc-home-v1';
self.addEventListener('install', (e) => { e.waitUntil(caches.open(V).then((c) => c.addAll(['./', 'index.html', 'assets/landing.css', 'manifest.webmanifest'])).catch(() => {})); self.skipWaiting(); });
self.addEventListener('activate', (e) => { e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k.startsWith('flexc-home-') && k !== V).map((k) => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', (e) => {
  const r = e.request, u = new URL(r.url);
  if (r.method !== 'GET' || u.origin !== location.origin) return;
  const p = u.pathname.replace(/[^/]*$/, '');
  const root = new URL('./', self.registration.scope).pathname;
  if (p !== root && !p.startsWith(root + 'assets/')) return;   // sub-apps (pos/, office/ ...) use their own workers
  e.respondWith(fetch(r).then((res) => { if (res.ok) { const c = res.clone(); caches.open(V).then((x) => x.put(r, c)); } return res; }).catch(() => caches.match(r).then((m) => m || caches.match('./'))));
});
