/* Flex-C Code 128 barcode (subset B, any printable ASCII). FlexBar.svg(text, {h, mod}) returns an SVG string. */
(function (g) {
  'use strict';
  const P = ('212222 222122 222221 121223 121322 131222 122213 122312 132212 221213 221312 231212 112232 122132 122231 113222 123122 123221 223211 221132 221231 213212 223112 312131 311222 321122 321221 312212 322112 322211 212123 212321 232121 111323 131123 131321 112313 132113 132311 211313 231113 231311 112133 112331 132131 113123 113321 133121 313121 211331 231131 213113 213311 213131 311123 311321 331121 312113 312311 332111 314111 221411 431111 111224 111422 121124 121421 141122 141221 112214 112412 122114 122411 142112 142211 241211 221114 413111 241112 134111 111242 121142 121241 114212 124112 124211 411212 421112 421211 212141 214121 412121 111143 111341 131141 114113 114311 411113 411311 113141 114131 311141 411131 211412 211214 211232 2331112').split(' ');
  function codes(text) {
    const out = [104];                                   // start B
    let sum = 104;
    [...text].forEach((ch, i) => { let v = ch.charCodeAt(0) - 32; if (v < 0 || v > 94) v = 31; out.push(v); sum += v * (i + 1); });
    out.push(sum % 103, 106);                            // check, stop
    return out;
  }
  function svg(text, o) {
    o = o || {}; const h = o.h || 40, mod = o.mod || 1.6, quiet = 10 * mod;
    let x = quiet, bars = '';
    codes(String(text)).forEach((c) => {
      const w = P[c]; let bar = true;
      for (const d of w) { const wd = +d * mod; if (bar) bars += `M${x.toFixed(2)} 0h${wd.toFixed(2)}v${h}h-${wd.toFixed(2)}z`; x += wd; bar = !bar; }
    });
    const W = x + quiet;
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W.toFixed(2)} ${h}" width="${W.toFixed(2)}" height="${h}" preserveAspectRatio="none" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path d="${bars}" fill="#000"/></svg>`;
  }
  g.FlexBar = { svg, codes };
})(window);
