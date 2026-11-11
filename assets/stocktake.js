'use strict';
/* Flex-C team stock take screens, shared by the Back office (office/) and the Stock app (stock/).
   Uses the page's view(), TAB, CO, shopOpts(), shopsOf() and msg() helpers. */
/* ---------------- Stock take (team) ---------------- */
// Owner / manager: start a count per shop, make stocker logins, watch it live, close and apply.
// Stocker (and owner / manager with "Scan here"): scan with the phone; first scan of a product asks the rate,
// every next scan of the same product adds to its count. Scans that fail on a weak line wait and are re-sent
// with the same id, so the server never counts them twice.
let TAKE_TIMER = null, TAKE_GEN = 0;   // a newer scanner screen retires the older one's send loop
const stopTakeTimer = () => { if (TAKE_TIMER) { clearInterval(TAKE_TIMER); TAKE_TIMER = null; } };
// Sound + vibration: 'ok' one short high beep, 'new' / 'warn' two low beeps (new barcode, already counted, problem).
function takeAlert(kind) {
  try {
    const a = new (window.AudioContext || window.webkitAudioContext)(), tones = kind === 'ok' ? [[1200, 0, 0.09]] : [[440, 0, 0.16], [330, 0.22, 0.2]];
    tones.forEach(([f, at, len]) => { const o = a.createOscillator(), g = a.createGain(); o.frequency.value = f; o.connect(g); g.connect(a.destination); g.gain.value = 0.25; o.start(a.currentTime + at); o.stop(a.currentTime + at + len); });
    setTimeout(() => a.close(), 700);
  } catch (e) { /* no sound */ }
  try { navigator.vibrate && navigator.vibrate(kind === 'ok' ? 40 : [120, 80, 160]); } catch (e) { /* ignore */ }
}
const qtyTxt = (n) => String(Math.round((+n || 0) * 1000) / 1000);
const newUuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-axxx-xxxxxxxxxxxx'.replace(/x/g, () => (Math.random() * 16 | 0).toString(16)));
function askDialog(title, html, onOk, okText) {
  const d = document.createElement('dialog');
  d.innerHTML = `<form method="dialog"><h3 style="margin:0 0 8px">${title}</h3>${html}<div class="err" id="dg_err"></div>
    <div class="row" style="margin-top:10px"><div class="fit"><button class="btn" id="dg_ok" value="ok">${okText || 'Save'}</button></div><div class="fit"><button class="btn sec" type="button" id="dg_no">Cancel</button></div></div></form>`;
  document.body.appendChild(d); d.showModal();
  const close = () => { d.close(); d.remove(); };
  $('#dg_no', d).onclick = close;
  d.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
  $('form', d).onsubmit = async (e) => { e.preventDefault(); $('#dg_ok', d).disabled = true; try { if ((await onOk(d)) !== false) close(); } catch (err) { $('#dg_err', d).textContent = niceErr(err); } $('#dg_ok', d).disabled = false; };
  const f = $('input', d); if (f) { f.focus(); f.select && f.select(); }
  return d;
}

async function tTake() {
  stopTakeTimer(); if (S.user.role !== 'stocker') TAKE_GEN++;
  if (S.user.role === 'stocker') return takeScanner(null);
  const shops = shopsOf();
  const d = await api('count_list', { params: { company_id: CO } });
  const busyShops = new Set(d.takes.filter((t) => t.status === 'open' || t.status === 'closed').map((t) => t.shop_id));
  const ST = { open: '<span class="tag ok">scanning</span>', closed: '<span class="tag warn">closed - check and apply</span>', applied: '<span class="tag">applied to stock</span>', cancelled: '<span class="tag bad">cancelled</span>' };
  view().innerHTML = `<div class="card"><h3>Team stock take</h3>
    <div class="hint">0. (First time) load your item codes with cost: Stock &gt; Import stock, <b>Items only</b>. 1. Start a count for the shop. 2. Give each stocker a login (below). 3. Stockers open the <b>Stock take</b> app on their phones (home page card, or <b>/stock/</b>; install it once) and scan: the <b>first scan</b> of a product asks its rate, <b>every next scan</b> of the same product adds 1 (by any stocker). Coming back to a product that is already counted (same stocker later, or another stocker) asks <b>more stock or same goods?</b>, so goods are not counted twice; such lines are marked for you to check. 4. Watch it live here, fix any line, then <b>Close</b> and <b>Apply</b>: the shop's stock is set to what was counted. Count when the shop is closed, or the sales made during the count will be overwritten.</div>
    <div class="grid g3" style="margin-top:8px"><div><label>Shop</label><select id="tk_shop">${shopOpts()}</select></div>
      <div><label>Name (optional)</label><input id="tk_name" maxlength="120" placeholder="e.g. Year-end count"></div>
      <div><label>Rate means</label><select id="tk_kind"><option value="price">Selling price (VAT incl.)</option><option value="cost">Cost price (no VAT)</option></select></div></div>
    <div class="row" style="margin-top:10px"><div class="fit"><button class="btn" id="tk_start">Start stock take</button></div></div><div id="tk_msg"></div></div>
    <div class="card"><h3>Stock takes</h3><div class="tbl"><table><tr><th>Name</th><th>Shop</th><th>Status</th><th class="r">Products</th><th class="r">Units</th><th>Started</th><th></th></tr>
      ${d.takes.map((t) => `<tr><td>${esc(t.name)}${t.result ? `<div class="hint">${esc(t.result)}</div>` : ''}</td><td>${esc(t.shop_code)}</td><td>${ST[t.status] || esc(t.status)}</td><td class="r">${t.lines}</td><td class="r">${qtyTxt(t.units)}</td><td>${esc(String(t.created_at).slice(0, 16))}</td>
        <td class="hold-actions"><button class="btn sec" data-tk="${t.id}">Open</button>${t.status === 'open' ? `<button class="btn sec" data-tscan="${t.id}" data-ic="camera">Scan here</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="7">No stock takes yet.</td></tr>'}</table></div></div>
    <div class="card"><h3>Stocker logins</h3>
      <div class="hint">Each stocker gets their own username and password, locked to one shop. They can only scan in that shop's stock take, nothing else. Usernames are never repeated, and two active stockers of a shop cannot have the same name. Reset or disable them in Admin &gt; Users.</div>
      <div class="grid g3" style="margin-top:8px"><div><label>Shop</label><select id="sk_shop">${shopOpts()}</select></div>
        <div style="grid-column:span 2"><label>Names, one per line</label><textarea id="sk_names" rows="3" placeholder="Ahmed&#10;Ravi&#10;Joseph"></textarea></div></div>
      <div class="row" style="margin-top:10px"><div class="fit"><button class="btn" id="sk_make">Create stocker logins</button></div></div><div id="sk_msg"></div>
      <div class="tbl" style="margin-top:8px"><table id="sk_tb"></table></div></div>`;
  const drawStockers = async () => {
    try {
      const u = await api('users_list');
      const sid = +$('#sk_shop').value;
      const rows = u.users.filter((x) => x.role === 'stocker' && (!sid || x.shop_id === sid));
      $('#sk_tb').innerHTML = '<tr><th>Stocker</th><th>Username</th><th>Shop</th><th>Status</th></tr>' + (rows.map((x) => `<tr><td>${esc(x.name)}</td><td><b>${esc(x.username)}</b></td><td>${esc(x.shop_code || '')}</td><td>${+x.active ? '<span class="tag ok">active</span>' : '<span class="tag bad">disabled</span>'}</td></tr>`).join('') || '<tr><td colspan="4">No stockers for this shop yet.</td></tr>');
    } catch (e) { $('#sk_tb').innerHTML = ''; }
  };
  const pick = () => { const sid = +$('#tk_shop').value; $('#tk_start').disabled = busyShops.has(sid); msg($('#tk_msg'), busyShops.has(sid) ? 'This shop already has a stock take running. Open it below, or apply / cancel it first.' : '', true); };
  $('#tk_shop').onchange = pick; pick();
  $('#sk_shop').onchange = drawStockers; drawStockers();
  $('#tk_start').onclick = async () => {
    try { const r = await api('count_start', { body: { shop_id: +$('#tk_shop').value, name: $('#tk_name').value, rate_kind: $('#tk_kind').value } }); takeDetail(r.id); }
    catch (e) { msg($('#tk_msg'), niceErr(e)); }
  };
  view().onclick = (e) => {
    const o = e.target.closest('[data-tk]'), s = e.target.closest('[data-tscan]');
    if (o) takeDetail(+o.dataset.tk);
    if (s) takeScanner(+s.dataset.tscan);
  };
  $('#sk_make').onclick = async () => {
    const names = $('#sk_names').value.split(/\r?\n/).map((x) => x.trim()).filter(Boolean);
    if (!names.length) { msg($('#sk_msg'), 'Type at least one name.'); return; }
    try {
      const r = await api('stockers_create', { body: { shop_id: +$('#sk_shop').value, names } });
      $('#sk_names').value = ''; msg($('#sk_msg'), r.stockers.length + ' login(s) created.', true); drawStockers();
      stockerCreds(r);
    } catch (e) { msg($('#sk_msg'), niceErr(e)); }
  };
}

function stockerCreds(r) {
  const url = new URL('../stock/', location.href).href;   // the Stock take app
  const rows = r.stockers.map((s) => `<tr><td>${esc(s.name)}</td><td><b>${esc(s.username)}</b></td><td><b>${esc(s.password)}</b></td></tr>`).join('');
  const d = document.createElement('dialog'); d.style.width = 'min(620px,96vw)';
  d.innerHTML = `<h3 style="margin:0 0 6px">Stocker logins - ${esc(r.shop)}</h3><div class="hint">Passwords are shown only now. Print or copy them and hand each stocker their own slip. They open <b>${esc(url)}</b> on their phone (or the <b>Stock take</b> card on the home page), tap <b>Install app</b> once, and sign in.</div>
    <div class="tbl"><table><tr><th>Name</th><th>Username</th><th>Password</th></tr>${rows}</table></div>
    <div class="row" style="margin-top:12px"><div class="fit"><button class="btn sec" id="sc_pr" type="button">Print slips</button></div><div class="fit"><button class="btn sec" id="sc_cp" type="button">Copy</button></div><div class="fit"><button class="btn" id="sc_ok" type="button">Done</button></div></div>`;
  document.body.appendChild(d); d.showModal();
  const close = () => { d.close(); d.remove(); };
  $('#sc_ok', d).onclick = close; d.addEventListener('cancel', (e) => { e.preventDefault(); close(); });
  $('#sc_cp', d).onclick = () => navigator.clipboard && navigator.clipboard.writeText(r.stockers.map((s) => `${s.name}\nSign in: ${url}\nUsername: ${s.username}\nPassword: ${s.password}`).join('\n\n'));
  $('#sc_pr', d).onclick = () => { close(); printHtml(`<div style="font-family:Arial,sans-serif">${r.stockers.map((s) => `<div style="border:1px dashed #999;padding:12px 14px;margin:0 0 10px;page-break-inside:avoid"><b style="font-size:16px">Stock take login - ${esc(s.name)}</b><div>${esc(r.shop)}</div><div style="margin-top:6px">Open: ${esc(url)}</div><div>Username: <b style="font-size:16px">${esc(s.username)}</b></div><div>Password: <b style="font-size:16px">${esc(s.password)}</b></div></div>`).join('')}</div>`); };
}

/* ---- owner / manager: one stock take, live ---- */
async function takeDetail(id) {
  stopTakeTimer(); TAKE_GEN++;
  let data = null, q = '', only = 'all', editing = false;
  view().onclick = null;
  view().innerHTML = '<div class="card">Loading...</div>';
  const kindTxt = () => data.take.rate_kind === 'cost' ? 'Cost (no VAT)' : 'Price (VAT incl.)';
  const draw = () => {
    const t = data.take, L = data.lines;
    const units = L.reduce((a, l) => a + l.qty, 0), value = L.reduce((a, l) => a + l.qty * l.rate, 0);
    const diffs = L.filter((l) => Math.abs(l.diff) > 0.0005).length, unknown = L.filter((l) => !l.item_id).length, rateCh = L.filter((l) => l.item_rate != null && Math.abs(l.item_rate - l.rate) > 0.004).length, recnt = L.filter((l) => l.recount || (l.by || []).length > 1).length;
    const live = t.status === 'open' || t.status === 'closed';
    const k = q.trim().toLowerCase();
    const shown = L.filter((l) => (!k || (l.name + ' ' + l.code + ' ' + (l.sku || '')).toLowerCase().includes(k)) &&
      (only === 'all' || (only === 'diff' && Math.abs(l.diff) > 0.0005) || (only === 'new' && !l.item_id) || (only === 'rate' && l.item_rate != null && Math.abs(l.item_rate - l.rate) > 0.004) || (only === 'twice' && (l.recount || (l.by || []).length > 1))));
    view().innerHTML = `<div class="card"><div class="row" style="align-items:center"><div><h3 style="margin:0">${esc(t.name)}</h3><div class="hint">${esc(t.shop_code)} - ${esc(t.shop_name)} · rate = ${kindTxt()} · started ${esc(String(t.created_at).slice(0, 16))}${t.status === 'open' ? ' · <b style="color:var(--ok)">live, refreshes every 5 s</b>' : ''}</div></div>
        <div class="fit"><button class="btn sec" id="td_back">Back</button></div></div>
      ${t.result ? `<div class="okmsg">${esc(t.result)}</div>` : ''}
      <div class="grid g4" style="margin:12px 0">${[['Products', L.length], ['Units counted', qtyTxt(units)], ['Value at rate', money(value)], ['Differ from system', diffs], ['New barcodes', unknown], ['Rate changes', rateCh], ['Check: counted twice?', recnt]].map(([l, v]) => `<div class="kpi"><div class="l">${l}</div><div class="v">${v}</div></div>`).join('')}</div>
      <div class="row">${t.status === 'open' ? '<div class="fit"><button class="btn" id="td_scan" data-ic="camera">Scan here</button></div><div class="fit"><button class="btn sec" id="td_close">Close scanning</button></div>' : ''}
        ${t.status === 'closed' ? '<div class="fit"><button class="btn" id="td_apply">Apply to stock</button></div><div class="fit"><button class="btn sec" id="td_reopen">Reopen scanning</button></div>' : ''}
        ${live ? '<div class="fit"><button class="btn sec" id="td_cancel">Cancel count</button></div>' : ''}
        <div class="fit"><button class="btn sec" id="td_csv">CSV</button></div><div class="fit"><button class="btn sec" id="td_print">Print</button></div></div><div id="td_msg"></div></div>
      ${data.people.length ? `<div class="card"><h3>Stockers</h3><div class="tbl"><table><tr><th>Name</th><th>Username</th><th class="r">Scans</th><th class="r">Units</th><th>Last scan</th></tr>${data.people.map((p) => `<tr><td>${esc(p.name)}</td><td>${esc(p.username || '')}</td><td class="r">${p.scans}</td><td class="r">${qtyTxt(p.units)}</td><td>${esc(String(p.last_at).slice(11, 19))}</td></tr>`).join('')}</table></div></div>` : ''}
      <div class="card"><h3>Counted products</h3>
        <div class="row"><div><input id="td_q" placeholder="Search name, code or SKU" value="${esc(q)}"></div><div class="fit"><select id="td_only"><option value="all">All</option><option value="diff">Differs from system</option><option value="new">New barcodes</option><option value="rate">Rate changed</option><option value="twice">Counted by 2+ people / again</option></select></div></div>
        <div class="tbl" style="margin-top:8px"><table><tr><th>Product</th><th class="r">Rate</th><th class="r">Counted</th><th class="r">System now</th><th class="r">Difference</th><th class="r">Value</th><th>Scanned by</th>${live ? '<th></th>' : ''}</tr>
        ${shown.slice(0, 1500).map((l) => `<tr><td>${esc(l.name)} ${l.item_id ? '' : '<span class="tag warn">new</span>'}<div class="hint">${esc(l.code)}${l.sku && l.sku !== l.code ? ' · ' + esc(l.sku) : ''}</div></td>
          <td class="r">${money(l.rate)}${l.item_rate != null && Math.abs(l.item_rate - l.rate) > 0.004 ? `<div class="hint">was ${money(l.item_rate)}</div>` : ''}</td><td class="r"><b>${qtyTxt(l.qty)}</b></td><td class="r">${l.item_id ? qtyTxt(l.system) : '-'}</td>
          <td class="r ${l.diff < 0 ? 'neg' : ''}">${Math.abs(l.diff) > 0.0005 ? (l.diff > 0 ? '+' : '') + qtyTxt(l.diff) : '0'}</td><td class="r">${money(l.qty * l.rate)}</td><td>${(l.by || []).map((b) => esc(b.name) + ' ' + qtyTxt(b.qty) + (b.recount ? ' <span class="tag warn">again</span>' : '')).join('<br>') || esc(l.first_by || '')}<div class="hint">${esc(String(l.last_at).slice(11, 19))}</div></td>
          ${live ? `<td class="hold-actions"><button class="btn sec" data-le="${l.id}">Edit</button><button class="btn sec" data-ld="${l.id}">Delete</button></td>` : ''}</tr>`).join('') || `<tr><td colspan="8">${L.length ? 'Nothing matches.' : 'Nothing scanned yet.'}</td></tr>`}</table></div>
        ${shown.length > 1500 ? '<div class="hint">Showing the first 1500. Use search or CSV for the rest.</div>' : ''}</div>
      ${live && data.missing.length ? `<div class="card"><h3>In stock but not scanned yet (${data.missing.length})</h3><div class="hint">These products have stock in the system but nobody has scanned them. Check those shelves. When applying you can set them to 0.</div>
        <div class="tbl" style="max-height:320px"><table><tr><th>Product</th><th>SKU</th><th class="r">System</th></tr>${data.missing.slice(0, 1000).map((m) => `<tr><td>${esc(m.name)}</td><td>${esc(m.sku)}</td><td class="r">${qtyTxt(m.qty)}</td></tr>`).join('')}</table></div></div>` : ''}`;
    $('#td_only').value = only;
    hydrateIcons(view());
    $('#td_back').onclick = () => { stopTakeTimer(); tTake(); };
    $('#td_q').oninput = (e) => { q = e.target.value; const p = e.target.selectionStart; draw(); const f = $('#td_q'); f.focus(); f.setSelectionRange(p, p); };
    $('#td_only').onchange = (e) => { only = e.target.value; draw(); };
    const act = async (action, extra, sure) => { if (sure && !confirm(sure)) return; try { const r = await api('count_action', { body: { take_id: id, action, ...(extra || {}) } }); await load(); if (r.result) msg($('#td_msg'), r.result, true); } catch (e) { msg($('#td_msg'), niceErr(e)); } };
    if ($('#td_scan')) $('#td_scan').onclick = () => takeScanner(id);
    if ($('#td_close')) $('#td_close').onclick = () => act('close', null, 'Close scanning? Stockers will not be able to add more. You can reopen it.');
    if ($('#td_reopen')) $('#td_reopen').onclick = () => act('reopen');
    if ($('#td_cancel')) $('#td_cancel').onclick = () => act('cancel', null, 'Cancel this stock take? Nothing changes in stock and the count is kept only as history.');
    if ($('#td_apply')) $('#td_apply').onclick = () => askDialog('Apply to stock',
      `<div class="hint">For each of the <b>${L.length}</b> counted products, the stock of <b>${esc(t.shop_name)}</b> is set to the counted quantity (${diffs} differ from the system now). New barcodes (${unknown}) become new products. Product ${t.rate_kind === 'cost' ? 'cost prices' : 'selling prices'} are updated to the counted rate (${rateCh} change). Every change is written to the Stock record as "Stock take #${t.id}".</div>
       <label style="margin-top:10px"><input type="checkbox" id="ap_zero" style="width:auto"> Also set the ${data.missing.length} products that were not scanned to 0 (full count of the shop)</label>`,
      async (dg) => { const r = await api('count_action', { body: { take_id: id, action: 'apply', zero_unscanned: $('#ap_zero', dg).checked } }); await load(); msg($('#td_msg'), 'Applied: ' + r.result, true); }, 'Apply now');
    $('#td_csv').onclick = () => downloadCsv(`stock-take-${t.id}-${t.shop_code}.csv`, [['Code', 'SKU', 'Product', 'New', 'Rate', 'Counted', 'System now', 'Difference', 'Value', 'First scanned by', 'Last scanned by', 'Last scan']]
      .concat(L.map((l) => [l.code, l.sku || '', l.name, l.item_id ? '' : 'yes', l.rate, l.qty, l.item_id ? l.system : '', l.diff, (l.qty * l.rate).toFixed(2), l.first_by || '', l.last_by || '', l.last_at])));
    $('#td_print').onclick = () => printHtml(`<div style="font-family:Arial,sans-serif;font-size:12px"><h2 style="margin:0">${esc(t.name)}</h2><div>${esc(t.shop_code)} - ${esc(t.shop_name)} · ${esc(t.status)} · printed ${esc(nowStamp())}</div>
      <table style="width:100%;border-collapse:collapse;margin-top:8px"><tr><th align="left">Product</th><th align="left">Code</th><th align="right">Rate</th><th align="right">Counted</th><th align="right">System</th><th align="right">Diff</th><th align="right">Value</th></tr>
      ${L.map((l) => `<tr><td>${esc(l.name)}</td><td>${esc(l.code)}</td><td align="right">${money(l.rate)}</td><td align="right">${qtyTxt(l.qty)}</td><td align="right">${l.item_id ? qtyTxt(l.system) : '-'}</td><td align="right">${qtyTxt(l.diff)}</td><td align="right">${money(l.qty * l.rate)}</td></tr>`).join('')}
      <tr><td colspan="3"><b>Total</b></td><td align="right"><b>${qtyTxt(units)}</b></td><td></td><td></td><td align="right"><b>${money(value)}</b></td></tr></table>
      <div style="margin-top:30px">Counted by: ${data.people.map((p) => esc(p.name)).join(', ') || '-'}</div><div style="margin-top:30px">Checked by: ____________________ &nbsp; Signature: ____________________</div></div>`);
    view().onclick = (e) => {
      const ed = e.target.closest('[data-le]'), dl = e.target.closest('[data-ld]');
      if (ed) {
        const l = L.find((x) => x.id === +ed.dataset.le); editing = true;
        const dg = askDialog('Correct ' + esc(l.name), `${l.item_id ? '' : `<label>Name</label><input id="le_name" maxlength="200" value="${esc(l.name)}">`}
          <div class="grid g3"><div><label>Counted quantity</label><input id="le_qty" type="number" inputmode="decimal" step="0.001" min="0" value="${l.qty}"></div><div><label>Rate</label><input id="le_rate" type="number" inputmode="decimal" step="0.01" min="0" value="${l.rate}"></div></div>
          <div class="hint">The correction is logged with your name.</div>`,
          async (d) => { await api('count_line', { body: { take_id: id, line_id: l.id, qty: $('#le_qty', d).value, rate: $('#le_rate', d).value, name: l.item_id ? '' : $('#le_name', d).value } }); editing = false; await load(); });
        dg.addEventListener('close', () => { editing = false; });
      }
      if (dl) {
        const l = L.find((x) => x.id === +dl.dataset.ld);
        if (!confirm(`Delete the line "${l.name}" (${qtyTxt(l.qty)} counted)? Its scans are removed. This is logged.`)) return;
        api('count_line', { body: { take_id: id, line_id: l.id, delete: true } }).then(load).catch((err) => alert(niceErr(err)));
      }
    };
  };
  const load = async () => { data = await api('count_get', { params: { take_id: id } }); draw(); };
  await load();
  if (data.take.status === 'open' || data.take.status === 'closed') {
    TAKE_TIMER = setInterval(() => {
      if (TAB !== 'take' || !document.contains($('#td_back'))) { stopTakeTimer(); return; }
      if (document.hidden || editing || document.querySelector('dialog[open]') || (document.activeElement && document.activeElement.id === 'td_q' && q)) return;
      load().then(() => { if (!['open', 'closed'].includes(data.take.status)) stopTakeTimer(); }).catch(() => {});
    }, 5000);
  }
}

/* ---- the scanning screen (stockers, or owner / manager "Scan here") ---- */
async function takeScanner(takeId) {
  stopTakeTimer();
  const gen = ++TAKE_GEN;
  view().onclick = null;
  let take = null;
  const admin = S.user.role !== 'stocker' && !window.TAKE_STANDALONE;   // the Stock app (stock/) is scanning only
  const pickTake = async () => {
    const d = await api('count_list', admin ? { params: { company_id: CO } } : {});
    const open = d.takes.filter((t) => t.status === 'open');
    if (takeId) return open.find((t) => t.id === takeId) || null;
    return open[0] || null;
  };
  const LK = 'fc_take_last_' + S.user.id;
  try { take = await pickTake(); try { if (take) localStorage.setItem(LK, JSON.stringify(take)); } catch (e) { /* ignore */ } }
  catch (e) {   // no connection: keep counting in the last stock take this phone used; scans wait on the phone
    try { take = JSON.parse(localStorage.getItem(LK) || 'null'); } catch (x) { take = null; }
    if (!take || (takeId && take.id !== takeId)) throw e;
  }
  if (!take) {
    view().innerHTML = `<div class="card"><h3>Stock take</h3><div class="hint" style="font-size:15px">There is no stock take open for your shop right now. Your manager starts it from the Back office. This page checks again every 15 seconds.</div>
      <div class="row" style="margin-top:10px"><div class="fit"><button class="btn" id="ts_retry">Check now</button></div>${admin ? '<div class="fit"><button class="btn sec" id="ts_back">Back</button></div>' : ''}</div></div>`;
    $('#ts_retry').onclick = () => takeScanner(takeId);
    if ($('#ts_back')) $('#ts_back').onclick = () => tTake();
    TAKE_TIMER = setInterval(async () => { if (TAB !== 'take' || !document.contains($('#ts_retry'))) { stopTakeTimer(); return; } try { if (await pickTake()) takeScanner(takeId); } catch (e) { /* offline */ } }, 15000);
    return;
  }
  const QK = 'fc_take_q_' + S.user.id + '_' + take.id;
  let queue = []; try { queue = JSON.parse(localStorage.getItem(QK) || '[]'); } catch (e) { queue = []; }
  const saveQ = () => { try { localStorage.setItem(QK, JSON.stringify(queue)); } catch (e) { /* private mode: memory only */ } };
  let running = false, waitingRate = false, camOn = false, retryT = null, mine = [], lines = 0, units = 0;
  view().innerHTML = `<div class="card"><div class="row" style="align-items:center"><div><h3 style="margin:0">${esc(take.name)}</h3><div class="hint">${esc(take.shop_code)} - ${esc(take.shop_name)} · signed in as ${esc(S.user.name)}</div></div>${admin ? '<div class="fit"><button class="btn sec" id="ts_back">Back</button></div>' : ''}</div>
      <button class="btn" id="ts_cam" data-ic="camera" style="width:100%;padding:18px;font-size:18px;margin-top:12px">Scan with camera</button>
      <div class="row" style="margin-top:10px"><div style="flex:3"><label>Or type / use a barcode gun, then Enter (12*code adds 12)</label><input id="ts_code" autocomplete="off" autocapitalize="off" spellcheck="false"></div>
        <div style="flex:1;min-width:90px"><label>Qty per scan</label><input id="ts_per" type="number" inputmode="decimal" min="0.001" step="0.001" value="1"></div></div>
      <div id="ts_rate"></div>
      <div class="hint" style="margin-top:8px">Same product again: keep scanning, it adds. If you (or another stocker) come back to a product that is already counted, you are asked whether it is more stock or the same goods, so nothing is counted twice.</div>
      <div id="ts_last" style="margin-top:12px;padding:14px;border-radius:12px;background:var(--soft0,#f8fafc);min-height:56px"><span class="hint">Scan the first product. The first time a product is scanned you are asked its rate; after that each scan adds to its count.</span></div>
      <div id="ts_q" class="hint" style="margin-top:6px"></div></div>
    <div class="card"><h3>My scans <span class="hint" id="ts_tot" style="float:right"></span></h3><div class="tbl"><table id="ts_mine"></table></div></div>`;
  hydrateIcons(view());
  if ($('#ts_back')) $('#ts_back').onclick = () => { stopTakeTimer(); FlexScan.close(); takeDetail(take.id); };
  const say = (html, bad) => { $('#ts_last').innerHTML = `<div style="font-size:${bad ? 15 : 18}px;${bad ? 'color:var(--bad)' : ''}">${html}</div>`; if (FlexScan.isOpen()) FlexScan.say(html); };
  const drawQ = () => { $('#ts_q').innerHTML = queue.length > (running ? 1 : 0) ? `<b style="color:var(--warn)">${queue.length} scan(s) waiting to be sent</b> - they are kept on this phone and sent automatically.` : ''; };
  // What the last column says: Undo only when there is still that much counted to take back.
  const undoCell = (m) => {
    if (+m.qty < 0) return m.undo_of ? '<span class="hint">undo</span>' : '<span class="hint">taken back</span>';
    if (+m.undone) return '<span class="tag">undone</span>';
    if (!m.id) return '';
    if (+m.line_qty + 0.0005 < +m.qty) return '<span class="hint" title="The count of this product is already lower than this scan">already 0</span>';
    return `<button class="btn sec" data-undo="${m.id}">Undo</button>`;
  };
  const drawMine = () => {
    $('#ts_tot').textContent = `Count so far: ${lines} products, ${qtyTxt(units)} units`;
    $('#ts_mine').innerHTML = '<tr><th>Product</th><th class="r">Scan</th><th class="r">Total now</th><th></th></tr>' + (mine.map((m) => `<tr${+m.undone ? ' style="opacity:.55;text-decoration:line-through"' : ''}><td>${esc(m.name)}<div class="hint">${esc(m.code)} · ${esc(String(m.created_at).slice(11, 19))}</div></td><td class="r">${+m.qty > 0 ? '+' : ''}${qtyTxt(m.qty)}</td><td class="r"><b>${qtyTxt(m.line_qty)}</b></td>
      <td>${undoCell(m)}</td></tr>`).join('') || '<tr><td colspan="4">Nothing scanned by you yet.</td></tr>');
  };
  let soonT = null;
  const refreshSoon = () => { clearTimeout(soonT); soonT = setTimeout(() => { if (!queue.length && !waitingRate) refresh(); }, 1500); };   // exact totals from the server after a burst of scans
  const refresh = async () => {
    try {
      const d = await api('count_get', { params: { take_id: take.id } });
      mine = d.mine; lines = d.lines.filter((l) => l.qty > 0.0005).length; units = d.lines.reduce((a, l) => a + l.qty, 0); drawMine();
      if (d.take.status !== 'open') { FlexScan.close(); say('<b>Scanning is closed</b> for this stock take. Thank you! Scans not sent yet stay on this phone.', true); $('#ts_cam').disabled = true; }
      else $('#ts_cam').disabled = false;
    } catch (e) { /* offline: keep what we show */ }
  };
  const cam = () => { camOn = true; FlexScan.open((code) => add(code), { gap: 900 }); const dlg = document.querySelector('dialog[open]'); if (dlg) dlg.addEventListener('close', () => { if (!waitingRate) camOn = false; }, { once: true }); };
  const add = (raw, n) => {
    let code = String(raw || '').trim(), qty = n || +$('#ts_per').value || 1;
    const m = /^(\d+(?:\.\d+)?)\s*[*xX]\s*(.+)$/.exec(code);
    if (m && !n) { qty = +m[1]; code = m[2].trim(); }
    if (!code) return;
    queue.push({ uuid: newUuid(), code, qty }); saveQ(); drawQ(); run();
  };
  const askRate = (job, r) => new Promise((resolve) => {
    waitingRate = true;
    const reopen = camOn && FlexScan.isOpen(); if (reopen) FlexScan.close();
    const it = r.item, kind = r.rate_kind === 'cost' ? 'cost price (no VAT)' : 'selling price (VAT incl.)';
    let linked = null;
    if (!it) takeAlert('new');
    $('#ts_rate').innerHTML = it ? `<div class="card" style="margin-top:12px;border:2px solid var(--brand);box-shadow:none">
      <b style="font-size:17px">${esc(it.name)}</b><div class="hint">${esc(job.code)} · ${esc(it.sku)} · first scan in this count</div>` :
      `<div class="card dupwarn" style="margin-top:12px"><b class="dt" style="font-size:18px">&#9888; New barcode ${esc(job.code)}</b>
      <div class="hint">This barcode is not in your products yet. If it is a product you already have (for example from your old stock list), find it below so the barcode is remembered on it. Otherwise type a name for a new product.</div>
      <label>Find existing product (name or code)</label><input id="tr_find" autocomplete="off" placeholder="e.g. perfume, KU RTA">
      <div id="tr_list" style="margin-top:6px"></div><div id="tr_linked"></div>
      <div id="tr_newbox"><label>Or new product name</label><input id="tr_name" maxlength="200" placeholder="e.g. White Eau de Perfume"></div>`;
    $('#ts_rate').firstElementChild.insertAdjacentHTML('beforeend', `
      <div class="row"><div><label>Rate - ${kind} *</label><input id="tr_rate" type="number" inputmode="decimal" step="0.01" min="0" value="${it && it.rate > 0 ? it.rate : ''}" style="font-size:22px"></div>
        <div style="max-width:150px"><label>Quantity on shelf</label><input id="tr_qty" type="number" inputmode="decimal" step="0.001" min="0.001" value="${job.qty}" style="font-size:22px"></div></div>
      <div class="row" style="margin-top:10px"><div class="fit"><button class="btn" id="tr_ok" style="padding:12px 22px">Save and continue</button></div><div class="fit"><button class="btn sec" id="tr_skip">Skip this scan</button></div></div><div id="tr_err" class="err"></div>`);
    const f = it ? $('#tr_rate') : $('#tr_find'); f.focus(); f.select && f.select();
    if (!it) {
      let timer = null, list = [];
      const pick = (x) => {
        linked = x; $('#tr_list').innerHTML = ''; $('#tr_find').value = '';
        $('#tr_linked').innerHTML = x ? `<div style="margin-top:8px;padding:8px 10px;border:2px solid #15803d;border-radius:10px;background:#f0fdf4;color:#14532d"><b>Barcode will be saved on:</b> ${esc(x.name)} <span class="hint">${esc(x.sku)}</span> <button class="btn sec" id="tr_unlink" type="button" style="padding:4px 10px">Change</button></div>` : '';
        $('#tr_newbox').style.display = x ? 'none' : '';
        if (x) { if (x.rate > 0) $('#tr_rate').value = x.rate; $('#tr_rate').focus(); $('#tr_rate').select(); $('#tr_unlink').onclick = () => pick(null); }
      };
      $('#tr_find').oninput = (e) => {
        clearTimeout(timer); const q = e.target.value.trim();
        if (q.length < 2) { $('#tr_list').innerHTML = ''; return; }
        timer = setTimeout(async () => {
          try { list = (await api('count_item_search', { params: { take_id: take.id, q } })).items; } catch (er) { list = []; }
          $('#tr_list').innerHTML = list.map((x, i) => `<button type="button" class="btn sec" data-pk="${i}" style="display:block;width:100%;text-align:left;margin:4px 0;padding:9px 10px">${esc(x.name)} <span class="hint">${esc(x.sku)} · ${money(x.rate)}</span></button>`).join('') || '<div class="hint">No product found. Type a new product name below.</div>';
        }, 250);
      };
      $('#tr_list').onclick = (e) => { const b = e.target.closest('[data-pk]'); if (b) pick(list[+b.dataset.pk]); };
    }
    const done = (ok) => { $('#ts_rate').innerHTML = ''; waitingRate = false; resolve(ok); if (reopen) setTimeout(cam, 250); };
    $('#tr_ok').onclick = () => {
      const rate = $('#tr_rate').value, q = +$('#tr_qty').value;
      if (rate === '' || +rate < 0) { $('#tr_err').textContent = 'Enter the rate.'; return; }
      if (!(q > 0)) { $('#tr_err').textContent = 'Enter the quantity.'; return; }
      job.rate = rate; job.qty = q;
      if (!it) { if (linked) job.link_item_id = linked.id; else job.name = $('#tr_name').value.trim(); }
      saveQ(); done(true);
    };
    $('#tr_skip').onclick = () => done(false);
    $('#ts_rate').onkeydown = (e) => { if (e.key === 'Enter' && e.target.id !== 'tr_find') { e.preventDefault(); $('#tr_ok').click(); } };
  });
  // Product already counted (by someone else, or by me earlier): is this MORE stock, or the same goods again?
  const askSame = (job, r) => new Promise((resolve) => {
    waitingRate = true;
    const reopen = camOn && FlexScan.isOpen(); if (reopen) FlexScan.close();
    const l = r.line, by = (r.by || []).map((b) => `<b>${esc(b.name)}</b> ${qtyTxt(b.qty)} <span class="hint">(last ${esc(b.at)})</span>`).join('<br>');
    takeAlert('warn');
    $('#ts_rate').innerHTML = `<div class="card dupwarn" style="margin-top:12px">
      <b class="dt" style="font-size:18px">&#9888; Already counted: ${esc(l.name)}</b><div class="hint">${esc(job.code)} · total so far <b>${qtyTxt(l.qty)}</b></div>
      <div style="margin:8px 0">${by || ''}</div>
      <div style="font-size:15px">Is this <b>more stock</b> from another shelf or box? If these are the <b>same goods</b> already counted, press <b>Same goods - skip</b>.</div>
      <div class="row" style="margin-top:10px"><div class="fit"><button class="btn" id="cf_add" style="padding:12px 18px">More stock - add ${qtyTxt(job.qty)}</button></div><div class="fit"><button class="btn sec" id="cf_skip" style="padding:12px 18px">Same goods - skip</button></div></div></div>`;
    const done = (ok) => { $('#ts_rate').innerHTML = ''; waitingRate = false; resolve(ok); if (reopen) setTimeout(cam, 250); };
    $('#cf_add').onclick = () => { job.confirm = 1; saveQ(); done(true); };
    $('#cf_skip').onclick = () => done(false);
    $('#cf_skip').focus();
  });
  const NET = (e) => e instanceof TypeError || /^http_5|^updating$|^server_error$|Failed to fetch|NetworkError/.test(String(e.message));
  const run = async () => {
    if (running || waitingRate || gen !== TAKE_GEN) return;
    running = true; clearTimeout(retryT);
    while (queue.length && !waitingRate) {
      const job = queue[0];
      try {
        const r = await api('count_scan', { body: { take_id: take.id, ...job } });
        if (r.need_rate) {
          running = false; drawQ();
          const ok = await askRate(job, r);
          if (!ok) { queue.shift(); saveQ(); say('Skipped ' + esc(job.code) + '.', true); }
          running = true; continue;
        }
        if (r.need_confirm) {
          running = false; drawQ();
          const ok = await askSame(job, r);
          if (!ok) { queue.shift(); saveQ(); say('Not added: <b>' + esc(r.line.name) + '</b> stays at ' + qtyTxt(r.line.qty) + ' (same goods).'); }
          running = true; continue;
        }
        queue.shift(); saveQ();
        const l = r.line;
        if (job.undo_scan_id) {
          say(`<b>Undone:</b> ${esc(l.name)} ${qtyTxt(job.qty)} &rarr; total <b style="font-size:26px">${qtyTxt(l.qty)}</b>`);
          mine.forEach((m) => { if (+m.id === +job.undo_scan_id) m.undone = 1; });
          mine.unshift({ id: r.scan_id, line_id: l.id, name: l.name, code: job.code, qty: job.qty, line_qty: l.qty, created_at: nowStamp(), undo_of: job.undo_scan_id });
        } else {
          say(`<b>${esc(l.name)}</b> ${job.qty > 0 ? '+' : ''}${qtyTxt(job.qty)} &rarr; total <b style="font-size:26px">${qtyTxt(l.qty)}</b> <span class="hint">@ ${money(l.rate)}</span>${r.first ? ' <span class="tag ok">first scan</span>' : ''}${r.recount ? ' <span class="tag warn">added as more stock</span>' : ''}`);
          mine.unshift({ id: r.scan_id, line_id: l.id, name: l.name, code: job.code, qty: job.qty, line_qty: l.qty, created_at: nowStamp(), undone: 0 });
        }
        mine.forEach((m) => { if (+m.line_id === +l.id) m.line_qty = l.qty; });   // every row of this product shows its total now
        mine = mine.slice(0, 30);
        if (r.first) lines++; units += job.qty; refreshSoon(); drawMine();
      } catch (e) {
        if (NET(e)) { say('No connection. The scan is kept on this phone and will be sent automatically.', true); retryT = setTimeout(run, 4000); break; }
        queue.shift(); saveQ();
        if (job.undo_scan_id && e.message !== 'already_undone') mine.forEach((m) => { if (+m.id === +job.undo_scan_id) m.undone = 0; });
        drawMine();
        say(esc(job.code) + ': ' + esc(job.undo_scan_id && e.message === 'below_zero' ? 'nothing to undo, the count of this product is already 0.' : niceErr(e)), true);
        if (job.undo_scan_id) refresh();
        if (e.message === 'take_not_open') { refresh(); }
      }
      drawQ();
    }
    running = false; drawQ();
  };
  $('#ts_cam').onclick = cam;
  $('#ts_code').onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); add(e.target.value); e.target.value = ''; } };
  // Undo takes back that one scan (exactly its quantity), once. Tap, then tap again within 4 seconds to confirm.
  $('#ts_mine').onclick = (e) => {
    const b = e.target.closest('[data-undo]'); if (!b) return;
    const m = mine.find((x) => +x.id === +b.dataset.undo); if (!m || +m.undone) return;
    if (!b.dataset.arm) { b.dataset.arm = '1'; b.textContent = 'Tap again to undo'; b.style.background = '#dc2626'; b.style.color = '#fff'; setTimeout(() => { if (document.contains(b)) { delete b.dataset.arm; b.textContent = 'Undo'; b.style.background = ''; b.style.color = ''; } }, 4000); return; }
    m.undone = 1; drawMine();
    queue.push({ uuid: newUuid(), code: m.code, qty: -Math.abs(+m.qty), undo_scan_id: +m.id }); saveQ(); drawQ(); run();
  };
  window.__takeRun = run; if (!window.__takeOnline) { window.__takeOnline = true; addEventListener('online', () => window.__takeRun && window.__takeRun()); }
  drawQ(); await refresh(); run();
  TAKE_TIMER = setInterval(() => { if (TAB !== 'take' || !document.contains($('#ts_cam'))) { stopTakeTimer(); return; } if (!document.hidden && !waitingRate) { refresh(); if (queue.length) run(); } }, 10000);
  if (!admin) $('#ts_code').focus();
}

