'use strict';
// Shared helpers for the dashboard and back-office pages (Flex-C, powered by Adnys).
const APP_VERSION = '1.12.8';   // bump with each release; the Console compares this to the published version
const API = '../api/index.php';
const $ = (s, r = document) => r.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const money = (n) => new Intl.NumberFormat('en-AE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(+n || 0);
const cls = (n) => (+n < 0 ? 'neg' : '');
const today = () => new Date().toLocaleDateString('sv-SE', { timeZone: 'Asia/Dubai' });
const nowStamp = () => new Date().toLocaleString('sv-SE', { timeZone: 'Asia/Dubai' });
const monthStart = () => today().slice(0, 8) + '01';

const S = {
  tok: sessionStorage.getItem('fc_tok') || '',
  org: JSON.parse(sessionStorage.getItem('fc_org') || 'null'),
  user: JSON.parse(sessionStorage.getItem('fc_user') || 'null'),
  settings: JSON.parse(sessionStorage.getItem('fc_settings') || 'null'),
};

async function api(route, o = {}) {
  const u = new URL(API, location.href);
  u.searchParams.set('r', route);
  for (const [k, v] of Object.entries(o.params || {})) if (v !== '' && v != null) u.searchParams.set(k, v);
  const res = await fetch(u, {
    method: o.body ? 'POST' : 'GET',
    headers: { 'Content-Type': 'application/json', 'X-Auth-Token': S.tok },
    body: o.body ? JSON.stringify(o.body) : undefined,
  });
  let j = {};
  try { j = await res.json(); } catch (e) { /* non-JSON error page */ }
  if (res.status === 401 && route !== 'login') { sessionStorage.clear(); location.reload(); throw new Error('session_expired'); }
  if (!res.ok) throw new Error(j.error || 'http_' + res.status);
  return j;
}

const ERR = {
  expense_not_found: 'That expense no longer exists.',
  only_primary_nominates: 'Only the primary owner can nominate a sub-owner or change an owner account.', only_owner_resets: 'Only the owner or a sub-owner can reset or set passwords.', reset_self: 'To change your own password use Edit. If you forgot it, use Forgot password on the sign-in page.', forgot_sent: 'If that is an owner account, Adnys has been notified and will contact you. Other users: ask your owner to reset your password.',
  bad_file_type: 'Use an Excel .xlsx file or a .csv file.', empty_file: 'The file has no rows.', import_no_code_column: 'The file needs a Code (or Barcode) column in the first row.', import_no_qty_column: 'The file needs a Qty column in the first row.', import_errors: 'The file has errors. Fix them and upload again.', too_many_rows: 'Too many rows (limit 20,000).', no_file: 'Choose a file first.',
  invalid_login: 'Wrong username/email or password.', too_many_attempts: 'Too many attempts. Wait 15 minutes.', forbidden: 'Your role cannot do this.',
  duplicate: 'That value already exists (username, email, SKU, barcode or bill number).', insufficient_stock: 'Not enough stock in the source shop.',
  missing_field: 'Please fill the required fields.', bad_sku: 'SKU may only use letters, digits and . _ - /', bad_barcode: 'Barcode may only use letters, digits and . _ -',
  bad_email: 'Enter a valid email address.', bad_role: 'Choose a valid role.', password_too_short: 'Password must be at least 8 characters.',
  cashier_needs_shop: 'A cashier must be tied to a shop.', invalid_authoriser: 'Wrong manager username or password.',
  not_authorised_role: 'That person is not a manager, accountant or owner.', return_needs_authorisation: 'This return needs manager approval.',
  logo_too_big: 'That logo is too large. Use a smaller image.', bad_logo: 'That file is not a supported image.',
  notification_not_found: 'That alert no longer exists.', qty_zero: 'Enter a quantity other than zero.',
  register_not_found: 'Register not found.', shop_company_mismatch: 'The shop and company do not match.',
  paid_more_than_total: 'Paid amount is more than the bill total.', bad_date: 'Enter a valid date.', bad_number: 'Enter a valid number.',
  number_out_of_range: 'That number is out of range.', bad_action: 'Unknown action.', text_too_long: 'That text is too long.',
  user_not_found: 'That user no longer exists.', company_not_found: 'Company not found.', shop_not_found: 'Shop not found.',
  item_not_found: 'Item not found.', supplier_not_found: 'Supplier not found.', purchase_not_found: 'Purchase not found.',
  vat_more_than_amount: 'VAT cannot be more than the amount.', bad_lines: 'Add at least one line.', unknown_item: 'One of the items is not recognised.',
  bad_transfer: 'Choose two different shops of the same company.', bad_uuid: 'The sale id is invalid.',
};
Object.assign(ERR, {
  take_not_found: 'That stock take no longer exists.', take_already_open: 'This shop already has a stock take running. Apply or cancel it first.',
  take_not_open: 'This stock take is closed for scanning.', take_close_first: 'Close scanning first, then apply.', take_empty: 'Nothing has been counted yet.',
  below_zero: 'That would make the count less than 0.', line_not_found: 'That line no longer exists.', stocker_needs_shop: 'A stocker must be tied to a shop.',
  bad_trn: 'A UAE TRN is 15 digits starting with 100 (or leave it empty).', bad_file_type: 'Use an Excel file (.xlsx or .xls) or a .csv file.',
  undo_not_allowed: 'That scan cannot be undone here.', already_undone: 'That scan was already undone.',
  item_in_stock_take: 'This item is in a stock take that is still running. Apply or cancel that count first.',
  too_many_barcodes: 'An item can have up to 10 barcodes.', bad_lines: 'Nothing to save, or an item is listed twice.',
  stocker_no_pos: 'Stocker logins use the Stock take app (stock/).', bad_purpose: 'Unknown approval type.',
});
ERR.duplicate_bill = 'This supplier bill number is already entered for that supplier.';
ERR.bad_image = 'Use a JPG or PNG photo (it is reduced automatically).'; ERR.too_many_photos = 'Up to 5 photos per purchase.';
const niceErr = (e) => {
  const [c, x] = String(e.message).split(':');
  if (c === 'bill_total_mismatch') return `The lines add up to ${x}, but the supplier bill total you typed is different. Check quantities and costs against the paper bill.`;
  if (c === 'supplier_exists') return `A supplier with this name or TRN already exists: "${String(e.message).slice(16)}". Edit that one instead.`;
  if (c === 'barcode_taken') return `This barcode already belongs to "${String(e.message).slice(14)}". One barcode can belong to one item only.`;
  if (c === 'duplicate_name') return `"${String(e.message).slice(15)}" already has a stocker login in this shop (or is typed twice). Use a different name, e.g. add an initial.`;
  if (c === 'bill_qty_mismatch') return `The lines add up to ${x} units, but the units you typed from the bill are different. Check the quantities.`;
  return ERR[c] || e.message;
};

/* ---------- login overlay shared by every back-office page ---------- */
function forbiddenPage(msg) {
  const o = document.createElement('div');
  o.className = 'overlay';
  o.innerHTML = `<div class="box"><h1>Flex-C</h1><div class="by">Sign in to continue</div><div class="err">${esc(msg || 'Your role cannot open this page.')}</div>
    <button class="btn" id="fb_out" style="width:100%;margin-top:10px">Sign out</button></div>`;
  document.body.appendChild(o);
  $('#fb_out', o).onclick = signOut;
}
function startPage(start, roles) {
  const allowed = roles || ['owner', 'manager', 'accountant'];
  if (S.tok && S.user) {
    if (!allowed.includes(S.user.role)) { forbiddenPage('Your role (' + S.user.role + ') cannot open this page.'); return; }
    fillTop(); start(); runVersionCheck(); return;
  }
  const o = document.createElement('div');
  o.className = 'overlay';
  o.innerHTML = `<form class="box"><h1>Flex-C</h1><div class="by">Sign in to continue</div>
    <label>Username or email</label><input id="lg_em" type="text" autocomplete="username" required>
    <label>Password</label><input id="lg_pw" type="password" autocomplete="current-password" required>
    <div class="err" id="lg_err"></div><button class="btn" style="width:100%;margin-top:8px">Sign in</button><div style="text-align:center;margin-top:8px"><a href="#" id="lg_forgot" style="font-size:13px">Forgot password?</a></div><div class="pw">Powered by <a href="https://adnys.com" target="_blank" rel="noopener">Adnys</a></div></form>`;
  document.body.appendChild(o);
  $('#lg_forgot', o).onclick = async (ev) => {
    ev.preventDefault();
    const who = $('#lg_em', o).value.trim() || prompt('Enter your username or email');
    if (!who) return;
    try { await api('forgot', { body: { login: who } }); } catch (err) { if (err.message !== 'too_many_attempts') { $('#lg_err', o).textContent = niceErr(err); return; } }
    $('#lg_err', o).textContent = ERR.forgot_sent;
  };
  $('form', o).addEventListener('submit', async (e) => {
    e.preventDefault();
    $('#lg_err', o).textContent = '';
    try {
      const r = await api('login', { body: { email: $('#lg_em', o).value.trim(), password: $('#lg_pw', o).value } });
      if (!allowed.includes(r.user.role)) throw new Error('forbidden');
      S.tok = r.token; S.org = r.org; S.user = r.user; S.settings = r.settings || {};
      sessionStorage.setItem('fc_tok', r.token); sessionStorage.setItem('fc_org', JSON.stringify(r.org));
      sessionStorage.setItem('fc_user', JSON.stringify(r.user)); sessionStorage.setItem('fc_settings', JSON.stringify(S.settings));
      o.remove(); fillTop(); start(); runVersionCheck();
    } catch (err) { $('#lg_err', o).textContent = niceErr(err); }
  });
}
function fillTop() {
  const el = $('#who'); if (el && S.user) el.textContent = S.user.name + ' (' + S.user.role + ')';
}
async function signOut() { try { await api('logout', { body: {} }); } catch (e) { /* ignore */ } sessionStorage.clear(); location.reload(); }

/* ---------- CSV / printing / logos / credentials / sharing ---------- */
function csvText(rows) {
  return '\ufeff' + rows.map((r) => r.map((c) => {
    const s = String(c ?? '');
    return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  }).join(',')).join('\r\n');
}
function downloadCsv(name, rows) {
  const blob = new Blob([Array.isArray(rows) ? csvText(rows) : String(rows)], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1500);
}
function printHtml(html) {
  let p = document.getElementById('printArea');
  if (!p) { p = document.createElement('div'); p.id = 'printArea'; document.body.appendChild(p); }
  p.innerHTML = html;
  document.body.classList.add('printing');
  setTimeout(() => window.print(), 140);
}
addEventListener('afterprint', () => document.body.classList.remove('printing'));

function fileToLogo(file, max) {
  max = max || 340;
  return new Promise((res, rej) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      try {
        let w = img.width, h = img.height;
        const sc = Math.min(1, max / Math.max(w, h));
        w = Math.max(1, Math.round(w * sc)); h = Math.max(1, Math.round(h * sc));
        const c = document.createElement('canvas'); c.width = w; c.height = h;
        const g = c.getContext('2d');
        g.fillStyle = '#fff'; g.fillRect(0, 0, w, h);
        g.drawImage(img, 0, 0, w, h);
        let out = c.toDataURL('image/png');
        if (out.length > 350000) out = c.toDataURL('image/jpeg', 0.82);
        URL.revokeObjectURL(url);
        res(out);
      } catch (e) { URL.revokeObjectURL(url); rej(new Error('bad_logo')); }
    };
    img.onerror = () => { URL.revokeObjectURL(url); rej(new Error('bad_logo')); };
    img.src = url;
  });
}

function showCreds(title, r) {
  const d = document.createElement('dialog');
  d.innerHTML = `<h3 style="margin:0 0 8px">${esc(title)}</h3>
    ${r.username ? `<label>Username</label><div class="cred">${esc(r.username)}</div>` : ''}
    ${r.email ? `<label>Email</label><div class="cred">${esc(r.email)}</div>` : ''}
    <label>Password (shown once)</label><div class="cred">${esc(r.password || '')}</div>
    <div class="row" style="margin-top:12px"><div class="fit"><button class="btn sec" id="cr_cp" type="button">Copy</button></div><div class="fit"><button class="btn" id="cr_ok" type="button">Done</button></div></div>`;
  document.body.appendChild(d);
  d.showModal();
  const close = () => { d.close(); d.remove(); };
  $('#cr_ok', d).onclick = close;
  d.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
  $('#cr_cp', d).onclick = () => {
    const text = [r.username ? 'Username: ' + r.username : '', r.email ? 'Email: ' + r.email : '', 'Password: ' + (r.password || '')].filter(Boolean).join('\n');
    if (navigator.clipboard) navigator.clipboard.writeText(text);
  };
}
async function shareText(title, text) {
  if (navigator.share) {
    try { await navigator.share({ title, text }); return true; } catch (e) { if (e && e.name === 'AbortError') return false; }
  }
  if (navigator.clipboard) { await navigator.clipboard.writeText(text); alert('Sharing is not available in this browser. The summary was copied to the clipboard.'); return false; }
  alert(text);
  return false;
}

/* ---- version check: Console publishes releases; stale pages are told to reload ---- */
async function checkVersion(getJson) {
  try {
    const v = await getJson();
    if (!v || !v.latest) return;
    const cmp = (a, b) => { const x = a.split('.').map(Number), y = b.split('.').map(Number); for (let i = 0; i < 3; i++) { if ((x[i] || 0) !== (y[i] || 0)) return (x[i] || 0) - (y[i] || 0); } return 0; };
    const old = cmp(APP_VERSION, v.latest) < 0, forced = cmp(APP_VERSION, v.min_required || '0.0.0') < 0;
    if (!old || document.getElementById('fc_upd')) return;
    const bar = document.createElement('div'); bar.id = 'fc_upd';
    bar.style.cssText = forced ? 'position:fixed;inset:0;z-index:99;background:#0f172af0;color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;text-align:center;padding:20px'
      : 'position:fixed;left:0;right:0;bottom:0;z-index:99;background:#1e293b;color:#fff;padding:10px 16px;display:flex;gap:12px;align-items:center;justify-content:center;flex-wrap:wrap';
    bar.innerHTML = `<span>${forced ? '<b>Update required.</b> ' : ''}Version ${v.latest} is available${v.notes ? ': ' + v.notes.replace(/[<>&]/g, '') : '.'} You are on ${APP_VERSION}.</span><button style="font:inherit;border:0;border-radius:8px;padding:7px 14px;background:#fff;cursor:pointer;font-weight:700">Update now</button>`;
    bar.querySelector('button').onclick = async () => {
      try { for (const r of await navigator.serviceWorker.getRegistrations()) await r.unregister(); for (const k of await caches.keys()) await caches.delete(k); } catch (e) { /* ignore */ }
      location.reload();
    };
    document.body.appendChild(bar);
  } catch (e) { /* offline or old server: skip */ }
}
let versionTimer = null;
const runVersionCheck = () => {
  const go = () => checkVersion(() => api('version', { params: { v: APP_VERSION } }));
  go();
  if (!versionTimer) versionTimer = setInterval(go, 30 * 60000);
};

/* ---- footer on every company-side page: Powered by Adnys only (the full brand line lives in the Adnys Console) ---- */
(function brandFooter() {
  const add = () => {
    if (document.getElementById('brandfoot')) return;
    const f = document.createElement('footer');
    f.id = 'brandfoot'; f.className = 'pw';
    f.innerHTML = 'Powered by <a href="https://adnys.com" target="_blank" rel="noopener">Adnys</a>';
    document.body.appendChild(f);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', add); else add();
})();
