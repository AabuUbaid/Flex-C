/* Flex-C PWA helper: install button, service worker registration and automatic updates.
   <script src="../assets/pwa.js" data-sw="sw.js"></script>  (data-guard="fn" = name of a global function that says if reloading is safe) */
(function () {
  'use strict';
  const me = document.currentScript || {};
  const swUrl = (me.dataset && me.dataset.sw) || 'sw.js';
  const guard = (me.dataset && me.dataset.guard) || '';
  const standalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const safe = () => { try { return guard && typeof window[guard] === 'function' ? !!window[guard]() : true; } catch (e) { return true; } };
  const store = { get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* ignore */ } } };

  /* ---- styles ---- */
  const st = document.createElement('style');
  st.textContent = '.pwa-pill{position:fixed;right:14px;bottom:14px;z-index:90;display:none;align-items:center;gap:8px;background:var(--pwa,#0f766e);color:#fff;border:0;border-radius:999px;padding:10px 16px 10px 12px;font:600 14px system-ui,sans-serif;box-shadow:0 6px 20px #0004;cursor:pointer}'
    + '.pwa-pill.show{display:inline-flex}.pwa-pill svg{width:18px;height:18px;stroke:#fff;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}'
    + '.pwa-pill .x{margin-left:4px;opacity:.7;padding:0 4px;font-size:16px;line-height:1}'
    + '.pwa-bar{position:fixed;left:0;right:0;top:0;z-index:95;background:#1e293b;color:#fff;padding:9px 14px;display:none;gap:12px;align-items:center;justify-content:center;flex-wrap:wrap;font:14px system-ui,sans-serif}.pwa-bar.show{display:flex}'
    + '.pwa-bar button{font:inherit;font-weight:600;border:0;border-radius:6px;padding:5px 12px;background:#14b8a6;color:#04201c;cursor:pointer}'
    + '.pwa-help{position:fixed;left:12px;right:12px;bottom:12px;z-index:96;background:#fff;color:#0f172a;border-radius:12px;padding:14px 16px;box-shadow:0 8px 30px #0005;font:14px/1.45 system-ui,sans-serif;max-width:420px;margin:auto}';
  st.textContent += '.navtog{display:none;align-items:center;justify-content:center;width:40px;height:36px;padding:0;background:#ffffff26;color:#fff;border:0;border-radius:8px;cursor:pointer}.navtog svg{width:22px;height:22px;stroke:#fff;fill:none;stroke-width:2.2;stroke-linecap:round}'
    + '@media (max-width:720px){.fc-nav{flex-wrap:wrap}.fc-nav .navtog{display:inline-flex;order:2;margin-left:auto}.fc-nav .sp{display:none}'
    + '.fc-nav>a,.fc-nav>button:not(.navtog),.fc-nav>small{display:none}'
    + '.fc-nav.open>a,.fc-nav.open>button:not(.navtog),.fc-nav.open>small{display:flex;align-items:center;gap:8px;flex:1 1 100%;order:5;text-align:left;padding:12px 14px;font-size:16px}'
    + '.fc-nav.open>small{padding:4px 14px;opacity:.85}}';
  document.head.appendChild(st);
  /* ---- mobile menu: header links collapse behind a menu button on phones ---- */
  function navInit() {
    const h = document.querySelector('header.top, #app > header');
    if (!h || h.querySelector('.navtog') || !h.querySelector('a,button')) return;
    h.classList.add('fc-nav');
    const t = document.createElement('button'); t.type = 'button'; t.className = 'navtog'; t.setAttribute('aria-label', 'Menu'); t.setAttribute('aria-expanded', 'false');
    const bars = '<svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>', cross = '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>';
    t.innerHTML = bars;
    const set = (o) => { h.classList.toggle('open', o); t.innerHTML = o ? cross : bars; t.setAttribute('aria-expanded', String(o)); };
    t.onclick = () => set(!h.classList.contains('open'));
    h.addEventListener('click', (e) => { if (e.target.closest('a,button:not(.navtog)')) setTimeout(() => set(false), 50); });
    const first = h.querySelector('b') || h.firstElementChild; first.insertAdjacentElement('afterend', t);
  }
  document.body ? navInit() : document.addEventListener('DOMContentLoaded', navInit);
  const themeMeta = document.querySelector('meta[name="theme-color"]');
  if (themeMeta) document.documentElement.style.setProperty('--pwa', themeMeta.content);

  /* ---- light / dark mode: follows the device until the user picks one ---- */
  const root = document.documentElement;
  const curTheme = () => root.dataset.theme === 'dark' ? 'dark' : 'light';
  const SUN = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
  const MOON = '<svg viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
  st.textContent += '.themetog{position:fixed;left:12px;bottom:12px;z-index:90;width:38px;height:38px;border-radius:50%;border:1px solid #94a3b855;background:#ffffffe6;color:#0f172a;display:grid;place-items:center;cursor:pointer;box-shadow:0 2px 10px #0003;padding:0}'
    + 'html[data-theme=dark] .themetog{background:#1e293bf0;color:#fde68a;border-color:#475569}.themetog svg{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}@media print{.themetog{display:none}}';
  const tt = document.createElement('button'); tt.type = 'button'; tt.className = 'themetog';
  const paint = () => { const d = curTheme() === 'dark'; tt.innerHTML = d ? SUN : MOON; tt.title = d ? 'Switch to light mode' : 'Switch to dark mode'; tt.setAttribute('aria-label', tt.title);
    const m = document.querySelector('meta[name="theme-color"]'); if (m) { if (!m.dataset.l) m.dataset.l = m.content; m.content = d ? '#0b1220' : m.dataset.l; } };
  tt.onclick = () => { const n = curTheme() === 'dark' ? 'light' : 'dark'; root.dataset.theme = n; store.set('fc_theme', n); paint(); };
  matchMedia('(prefers-color-scheme: dark)').addEventListener && matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => { if (!store.get('fc_theme')) { root.dataset.theme = e.matches ? 'dark' : 'light'; paint(); } });
  const mountTheme = () => { document.body.appendChild(tt); paint(); };
  document.body ? mountTheme() : document.addEventListener('DOMContentLoaded', mountTheme);

  /* ---- install button ---- */
  const DL = '<svg viewBox="0 0 24 24"><path d="M12 3v12m0 0l-4.5-4.5M12 15l4.5-4.5M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>';
  const pill = document.createElement('button');
  pill.className = 'pwa-pill'; pill.type = 'button'; pill.setAttribute('aria-label', 'Install app');
  pill.innerHTML = DL + '<span>Install app</span><span class="x" title="Hide" aria-label="Hide">×</span>';
  let deferred = null;
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const key = 'fc_pwa_hide_' + location.pathname;
  function refresh() {
    const can = !standalone() && (deferred || isIOS);
    const hidden = store.get(key) === '1';
    pill.classList.toggle('show', !!can && !hidden);
  }
  async function install() {
    if (deferred) {
      deferred.prompt();
      try { await deferred.userChoice; } catch (e) { /* ignore */ }
      deferred = null; refresh();
    } else if (isIOS) {
      const h = document.createElement('div'); h.className = 'pwa-help';
      h.innerHTML = '<b>Install on iPhone / iPad</b><br>Tap the <b>Share</b> button in Safari, then <b>Add to Home Screen</b>.<br><button type="button" style="margin-top:8px;font:inherit;padding:5px 12px;border-radius:6px;border:1px solid #cbd5e1;background:#f8fafc">OK</button>';
      h.querySelector('button').onclick = () => h.remove();
      document.body.appendChild(h);
    }
  }
  pill.addEventListener('click', (e) => {
    if (e.target.classList.contains('x')) { store.set(key, '1'); refresh(); return; }
    install();
  });
  window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferred = e; refresh(); });
  window.addEventListener('appinstalled', () => { deferred = null; refresh(); });
  matchMedia('(display-mode: standalone)').addEventListener && matchMedia('(display-mode: standalone)').addEventListener('change', refresh);
  function mount() { document.body.appendChild(pill); refresh(); }
  document.body ? mount() : document.addEventListener('DOMContentLoaded', mount);
  window.FlexPWA = { install, canInstall: () => !!deferred || isIOS, standalone, resetHidden: () => { store.set(key, '0'); refresh(); } };
  if (/[?&]install=1/.test(location.search)) {   // opened from the home page "Install app" button
    store.set(key, '0'); refresh();
    st.textContent += '.pwa-pill.big{font-size:16px;padding:14px 20px 14px 16px;animation:pwaPulse 1.4s ease-in-out 4}@keyframes pwaPulse{50%{transform:scale(1.08)}}';
    pill.classList.add('big');
    try { history.replaceState(null, '', location.pathname + location.hash); } catch (e) { /* ignore */ }
    setTimeout(() => {
      if (standalone()) return;
      if (isIOS) { install(); return; }
      if (deferred) return;   // the Install app button is showing
      const h = document.createElement('div'); h.className = 'pwa-help';
      h.innerHTML = '<b>Install this app</b><br>If no Install button appeared, open the browser menu (<b>&#8942;</b>) and choose <b>Install app</b> or <b>Add to Home screen</b>. If it is already installed, open it from your home screen or app list.<br><button type="button" style="margin-top:8px;font:inherit;padding:5px 12px;border-radius:6px;border:1px solid #cbd5e1;background:#f8fafc">OK</button>';
      h.querySelector('button').onclick = () => h.remove();
      document.body.appendChild(h);
    }, 3000);
  }

  /* ---- service worker + automatic updates ---- */
  if (!('serviceWorker' in navigator)) return;
  const hadController = !!navigator.serviceWorker.controller;
  let pending = false, reloading = false;
  const bar = document.createElement('div'); bar.className = 'pwa-bar';
  bar.innerHTML = '<span>A new version is ready.</span><button type="button">Reload now</button>';
  bar.querySelector('button').onclick = () => doReload(true);
  function mountBar() { document.body.appendChild(bar); }
  document.body ? mountBar() : document.addEventListener('DOMContentLoaded', mountBar);
  function doReload(force) {
    if (reloading) return;
    if (!force && !safe()) { bar.classList.add('show'); return; }
    reloading = true; location.reload();
  }
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (!hadController) return;           // first install, nothing to refresh
    pending = true; doReload(false);
  });
  setInterval(() => { if (pending && safe()) doReload(false); }, 5000);

  navigator.serviceWorker.register(swUrl, { updateViaCache: 'none' }).then((reg) => {
    const check = () => { if (navigator.onLine) reg.update().catch(() => {}); };
    check();
    setInterval(check, 30 * 60 * 1000);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') check(); });
    window.addEventListener('online', check);
  }).catch(() => {});
})();

/* Small, light version label at the bottom of every app screen. */
(function () {
  try {
    const st = document.createElement('style');
    st.textContent = '.fc-ver{position:fixed;left:0;right:0;bottom:2px;text-align:center;font:10px/1 system-ui,sans-serif;color:#94a3b8;opacity:.7;pointer-events:none;z-index:2;letter-spacing:.2px}@media print{.fc-ver{display:none}}';
    document.head.appendChild(st);
    const el = document.createElement('div'); el.className = 'fc-ver';
    const put = (v) => { if (v) { el.textContent = 'Flex-C v' + v; if (!el.parentNode) document.body.appendChild(el); } };
    let v = null; try { v = typeof APP_VERSION !== 'undefined' ? APP_VERSION : null; } catch (e) { /* not defined */ }
    if (v) put(v); else fetch(new URL('../version.json', location.href), { cache: 'no-cache' }).then((r) => r.json()).then((j) => put(j.version)).catch(() => {});
  } catch (e) { /* cosmetic only */ }
})();
