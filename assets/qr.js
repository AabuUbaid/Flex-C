/* Flex-C QR code generator (byte mode, error correction M, versions 1-40). No dependencies, works offline.
   FlexQR.matrix(text) -> array of rows of booleans.  FlexQR.svg(text, px) -> SVG string.  FlexQR.tlv(...) -> base64 TLV for invoices. */
(function (g) {
  'use strict';
  const ECC = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28];
  const BLK = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49];
  const raw = (v) => { let r = (16 * v + 128) * v + 64; if (v >= 2) { const n = Math.floor(v / 7) + 2; r -= (25 * n - 10) * n - 55; if (v >= 7) r -= 36; } return r; };
  const dataCw = (v) => Math.floor(raw(v) / 8) - ECC[v] * BLK[v];
  const utf8 = (s) => Array.from(new TextEncoder().encode(s));
  // Reed-Solomon over GF(256), polynomial 0x11D
  const mul = (x, y) => { let z = 0; for (let i = 7; i >= 0; i--) { z = (z << 1) ^ ((z >>> 7) * 0x11D); z ^= ((y >>> i) & 1) * x; } return z & 255; };
  const rsDiv = (n) => { const r = new Array(n).fill(0); r[n - 1] = 1; let root = 1; for (let i = 0; i < n; i++) { for (let j = 0; j < n; j++) { r[j] = mul(r[j], root); if (j + 1 < n) r[j] ^= r[j + 1]; } root = mul(root, 2); } return r; };
  const rsRem = (data, div) => { const r = new Array(div.length).fill(0); for (const b of data) { const f = b ^ r.shift(); r.push(0); div.forEach((c, i) => { r[i] ^= mul(c, f); }); } return r; };
  const align = (v) => { if (v === 1) return []; const n = Math.floor(v / 7) + 2, step = v === 32 ? 26 : Math.ceil((v * 4 + 4) / (n * 2 - 2)) * 2; const a = [6]; for (let p = v * 4 + 10; a.length < n; p -= step) a.splice(1, 0, p); return a; };

  function matrix(text) {
    const bytes = utf8(String(text));
    let ver = 1;
    for (;; ver++) {
      if (ver > 40) throw new Error('qr_too_long');
      const cap = dataCw(ver) * 8, ccBits = ver < 10 ? 8 : 16;
      if (4 + ccBits + bytes.length * 8 <= cap) break;
    }
    const ccBits = ver < 10 ? 8 : 16, bits = [];
    const put = (val, len) => { for (let i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1); };
    put(4, 4); put(bytes.length, ccBits); bytes.forEach((b) => put(b, 8));
    const cap = dataCw(ver) * 8;
    put(0, Math.min(4, cap - bits.length));
    while (bits.length % 8) bits.push(0);
    for (let pad = 0xEC; bits.length < cap; pad ^= 0xEC ^ 0x11) put(pad, 8);
    const data = []; for (let i = 0; i < bits.length; i += 8) data.push(parseInt(bits.slice(i, i + 8).join(''), 2));
    // split into blocks, add ECC, interleave
    const nb = BLK[ver], eb = ECC[ver], rawCw = Math.floor(raw(ver) / 8), short = nb - rawCw % nb, shortLen = Math.floor(rawCw / nb);
    const div = rsDiv(eb), blocks = []; let k = 0;
    for (let i = 0; i < nb; i++) {
      const d = data.slice(k, k + shortLen - eb + (i < short ? 0 : 1)); k += d.length;
      const e = rsRem(d, div); if (i < short) d.push(0); blocks.push(d.concat(e));
    }
    const all = [];
    for (let i = 0; i < blocks[0].length; i++) blocks.forEach((b, j) => { if (i !== shortLen - eb || j >= short) all.push(b[i]); });
    const size = ver * 4 + 17;
    const mod = Array.from({ length: size }, () => new Array(size).fill(false)), fn = Array.from({ length: size }, () => new Array(size).fill(false));
    const setF = (x, y, d) => { if (x >= 0 && y >= 0 && x < size && y < size) { mod[y][x] = d; fn[y][x] = true; } };
    for (let i = 0; i < size; i++) { setF(6, i, i % 2 === 0); setF(i, 6, i % 2 === 0); }
    const finder = (cx, cy) => { for (let dy = -4; dy <= 4; dy++) for (let dx = -4; dx <= 4; dx++) { const d = Math.max(Math.abs(dx), Math.abs(dy)); setF(cx + dx, cy + dy, d !== 2 && d !== 4); } };
    finder(3, 3); finder(size - 4, 3); finder(3, size - 4);
    const al = align(ver), na = al.length;
    for (let i = 0; i < na; i++) for (let j = 0; j < na; j++) {
      if ((i === 0 && j === 0) || (i === 0 && j === na - 1) || (i === na - 1 && j === 0)) continue;
      for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) setF(al[i] + dx, al[j] + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
    }
    const drawFormat = (mask) => {
      const d = (0 << 3 | mask) /* ECC M = 00 */; let r = d; for (let i = 0; i < 10; i++) r = (r << 1) ^ ((r >>> 9) * 0x537);
      const f = ((d << 10) | r) ^ 0x5412, bit = (i) => ((f >>> i) & 1) !== 0;
      for (let i = 0; i <= 5; i++) setF(8, i, bit(i)); setF(8, 7, bit(6)); setF(8, 8, bit(7)); setF(7, 8, bit(8));
      for (let i = 9; i < 15; i++) setF(14 - i, 8, bit(i));
      for (let i = 0; i < 8; i++) setF(size - 1 - i, 8, bit(i));
      for (let i = 8; i < 15; i++) setF(8, size - 15 + i, bit(i));
      setF(8, size - 8, true);
    };
    drawFormat(0);
    if (ver >= 7) {
      let r = ver; for (let i = 0; i < 12; i++) r = (r << 1) ^ ((r >>> 11) * 0x1F25);
      const bitsV = (ver << 12) | r;
      for (let i = 0; i < 18; i++) { const b = ((bitsV >>> i) & 1) !== 0, a = size - 11 + i % 3, c = Math.floor(i / 3); setF(a, c, b); setF(c, a, b); }
    }
    // place data
    let idx = 0;
    for (let right = size - 1; right >= 1; right -= 2) {
      if (right === 6) right = 5;
      for (let vert = 0; vert < size; vert++) for (let j = 0; j < 2; j++) {
        const x = right - j, up = ((right + 1) & 2) === 0, y = up ? size - 1 - vert : vert;
        if (!fn[y][x] && idx < all.length * 8) { mod[y][x] = ((all[idx >>> 3] >>> (7 - (idx & 7))) & 1) !== 0; idx++; }
      }
    }
    const MASKS = [(x, y) => (x + y) % 2 === 0, (x, y) => y % 2 === 0, (x, y) => x % 3 === 0, (x, y) => (x + y) % 3 === 0,
      (x, y) => (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0, (x, y) => x * y % 2 + x * y % 3 === 0,
      (x, y) => (x * y % 2 + x * y % 3) % 2 === 0, (x, y) => ((x + y) % 2 + x * y % 3) % 2 === 0];
    const apply = (m) => { for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) if (!fn[y][x] && MASKS[m](x, y)) mod[y][x] = !mod[y][x]; };
    const penalty = () => {
      let p = 0;
      const line = (get) => { for (let a = 0; a < size; a++) { let run = 1; for (let b = 1; b < size; b++) { if (get(a, b) === get(a, b - 1)) { run++; if (run === 5) p += 3; else if (run > 5) p++; } else run = 1; }
        for (let b = 0; b + 11 <= size; b++) { const w = []; for (let t = 0; t < 11; t++) w.push(get(a, b + t) ? 1 : 0); const s = w.join(''); if (s === '10111010000' || s === '00001011101') p += 40; } } };
      line((a, b) => mod[a][b]); line((a, b) => mod[b][a]);
      for (let y = 0; y < size - 1; y++) for (let x = 0; x < size - 1; x++) { const c = mod[y][x]; if (c === mod[y][x + 1] && c === mod[y + 1][x] && c === mod[y + 1][x + 1]) p += 3; }
      let dark = 0; mod.forEach((r) => r.forEach((c) => { if (c) dark++; }));
      p += Math.floor(Math.abs(dark * 20 - size * size * 10) / (size * size)) * 10;
      return p;
    };
    let best = 0, bp = Infinity;
    for (let m = 0; m < 8; m++) { apply(m); drawFormat(m); const p = penalty(); if (p < bp) { bp = p; best = m; } apply(m); }
    apply(best); drawFormat(best);
    return mod;
  }

  function svg(text, px) {
    const m = matrix(text), n = m.length, q = 2; let d = '';
    m.forEach((row, y) => { let x = 0; while (x < n) { if (row[x]) { let e = x; while (e < n && row[e]) e++; d += `M${x + q} ${y + q}h${e - x}v1h-${e - x}z`; x = e; } else x++; } });
    const s = n + q * 2;
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${s} ${s}" width="${px || 120}" height="${px || 120}" shape-rendering="crispEdges"><rect width="${s}" height="${s}" fill="#fff"/><path d="${d}" fill="#000"/></svg>`;
  }

  // Invoice QR payload: seller name, TRN, date-time, total, VAT as TLV, base64 (the common e-invoice QR layout).
  function tlv(seller, trn, iso, total, vat) {
    const out = [];
    [seller, trn, iso, total, vat].forEach((v, i) => { const b = utf8(String(v ?? '')).slice(0, 255); out.push(i + 1, b.length, ...b); });
    let s = ''; out.forEach((c) => { s += String.fromCharCode(c); });
    return btoa(s);
  }
  g.FlexQR = { matrix, svg, tlv };
})(typeof window !== 'undefined' ? window : globalThis);
