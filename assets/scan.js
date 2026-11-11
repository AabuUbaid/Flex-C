/* Flex-C camera barcode scanner for back office. FlexScan.open(onCode) keeps scanning until the user presses Done.
   onCode(code, say) is called once per barcode held in view; say(html) updates the message line.
   opts.gap (ms): the same code counts again once it has been out of view this long (default 2500).
   Stock take uses a short gap so the same product can be scanned again and again: move away, scan again. */
(function (g) {
  'use strict';
  let dlg, vid, msg, stream, timer, reader, cb;
  const load = (src) => new Promise((res, rej) => { const s = document.createElement('script'); s.src = src; s.onload = res; s.onerror = () => rej(new Error('load')); document.head.appendChild(s); });
  const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  function build() {
    dlg = document.createElement('dialog');
    dlg.innerHTML = '<h3 style="margin:0 0 8px">Scan barcode</h3><video playsinline muted style="width:100%;border-radius:10px;background:#000;max-height:50vh;display:block"></video><div class="m" style="margin:8px 0;min-height:20px;font-size:14px;color:#64748b"></div><button type="button" class="btn sec" style="width:100%">Done</button>';
    document.body.appendChild(dlg);
    vid = dlg.querySelector('video'); msg = dlg.querySelector('.m');
    dlg.querySelector('button').onclick = stop; dlg.addEventListener('close', stop);
  }
  function stop() {
    clearInterval(timer); timer = null;
    try { reader && reader.reset(); } catch (e) { /* ignore */ } reader = null;
    if (stream) stream.getTracks().forEach((t) => t.stop()); stream = null;
    if (dlg && dlg.open) dlg.close();
  }
  function beep() { try { const a = new (g.AudioContext || g.webkitAudioContext)(), o = a.createOscillator(); o.frequency.value = 1000; o.connect(a.destination); o.start(); setTimeout(() => { o.stop(); a.close(); }, 110); } catch (e) { /* ignore */ } }
  async function open(onCode, opts) {
    const gap = (opts && opts.gap) || 2500;
    if (!dlg) build();
    cb = onCode; msg.textContent = 'Starting camera...'; dlg.showModal();
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { msg.textContent = 'Camera is not available here (needs https and a camera).'; return; }
    try { stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false }); }
    catch (e) { msg.textContent = 'Camera blocked. Allow camera access for this site and try again.'; return; }
    vid.srcObject = stream; try { await vid.play(); } catch (e) { /* ignore */ }
    msg.textContent = 'Point the camera at a barcode.';
    let last = '', lastAt = 0;
    const say = (h) => { msg.innerHTML = h; };
    const hit = (code) => { const now = Date.now(), same = code === last && now - lastAt < gap; last = code; lastAt = now; if (same) return; beep(); try { navigator.vibrate && navigator.vibrate(40); } catch (e) { /* ignore */ } cb && cb(String(code).trim(), say); };
    try {
      if ('BarcodeDetector' in g) {
        const det = new g.BarcodeDetector();
        timer = setInterval(async () => { try { const r = await det.detect(vid); if (r[0]) hit(r[0].rawValue); } catch (e) { /* ignore */ } }, 250);
      } else {
        if (!g.ZXing) await load('../assets/zxing.min.js');
        reader = new g.ZXing.BrowserMultiFormatReader();
        reader.decodeFromStream(stream, vid, (res) => { if (res) hit(res.getText()); }).catch(() => {});
      }
    } catch (e) { msg.textContent = 'Scanner could not start. Check your connection once, then try again.'; }
  }
  g.FlexScan = { open, close: stop, esc, isOpen: () => !!(dlg && dlg.open), say: (h) => { if (msg) msg.innerHTML = h; } };
})(window);
