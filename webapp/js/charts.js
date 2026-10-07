/* ============================================================
   چارت‌های SVG دستی — بدون هرگونه وابستگی خارجی
   همهٔ نمودارها با انیمیشن ورود (stroke-dashoffset) و گرادیان
   ============================================================ */
(function (NS) {
  'use strict';

  var fmt = NS.fmt;
  var reduce = fmt.reduceMotion;
  var _svgId = 0;

  function uid(p) { return p + (++_svgId); }

  /* ---------- اسپارکلاین پاسخ‌زمانی با ناحیهٔ گرادیانی ---------- */
  function sparkline(arr, w, h) {
    var gid = uid('sg');
    if (!arr || arr.length < 2) {
      return '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none">' +
        '<line x1="0" y1="' + (h / 2) + '" x2="' + w + '" y2="' + (h / 2) + '" stroke="#1e293b" ' +
        'stroke-width="2" stroke-dasharray="4 4"/></svg>';
    }
    var max = Math.max.apply(null, arr), min = Math.min.apply(null, arr);
    if (max === min) { max = min + 1; min = Math.max(0, min - 1); }
    var pad = 3, n = arr.length;
    var pts = arr.map(function (v, i) {
      var x = pad + (i / (n - 1)) * (w - pad * 2);
      var y = h - pad - ((v - min) / (max - min)) * (h - pad * 2);
      return x.toFixed(1) + ',' + y.toFixed(1);
    }).join(' ');

    var len = Math.round(w * 1.6);
    var draw = reduce ? '' : ' class="draw" stroke-dasharray="' + len + '" stroke-dashoffset="' + len + '"';

    return '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" aria-hidden="true">' +
      '<defs><linearGradient id="' + gid + '" x1="0" y1="0" x2="0" y2="1">' +
      '<stop offset="0" stop-color="#38bdf8" stop-opacity=".45"/>' +
      '<stop offset="1" stop-color="#38bdf8" stop-opacity="0"/>' +
      '</linearGradient></defs>' +
      '<polygon points="' + pts + ' ' + (w - pad) + ',' + h + ' ' + pad + ',' + h + '" fill="url(#' + gid + ')"/>' +
      '<polyline points="' + pts + '" fill="none" stroke="#38bdf8" stroke-width="2" ' +
      'stroke-linejoin="round" stroke-linecap="round"' + draw + '/>' +
      (reduce ? '' : '<animate attributeName="stroke-dashoffset" from="' + len + '" to="0" dur=".8s" fill="freeze" calcMode="spline" keyTimes="0;1" keySplines=".22 1 .36 1"/>') +
      '</svg>';
  }

  /* ---------- نمودار میله‌ای آپ‌تایم روزانه ---------- */
  function dailyChart(daily) {
    if (!daily || !daily.length) return '';
    var w = 600, h = 78, bw = w / daily.length;
    var bars = '';
    var live = daily.length;
    for (var i = 0; i < daily.length; i++) {
      var d = daily[i];
      var p = d.p;
      var has = (p !== null && p !== undefined);
      var cls = !has ? 'na' : (p >= 99.5 ? 'gr' : (p >= 95 ? 'go' : (p >= 80 ? 'wn' : 'bd')));
      var bh = has ? Math.max(4, Math.round((p / 100) * (h - 8))) : 5;
      var x = (i * bw) + bw * 0.14;
      var ww = bw * 0.72;
      var col = { gr: '#34d399', go: '#86efac', wn: '#fbbf24', bd: '#f87171', na: '#1e293b' }[cls];
      var title = 'آپ‌تایم ' + (has ? fmt.faPct(p) : 'بدون داده');
      var delay = reduce ? 0 : ((i / live) * 0.35).toFixed(3);
      var style = reduce ? '' : ' style="animation-delay:' + delay + 's"';
      bars += '<rect class="bpop"' + style + ' x="' + x.toFixed(1) + '" y="' + (h - bh).toFixed(1) +
        '" width="' + ww.toFixed(1) + '" height="' + bh + '" rx="2.5" fill="' + col + '" opacity="' +
        (cls === 'na' ? 1 : 0.92) + '"><title>' + title + '</title></rect>';
    }
    // خط امروز
    bars += '<rect x="' + (w - bw * 0.86).toFixed(1) + '" y="1" width="2" height="' + (h - 1) +
      '" rx="1" fill="#e2e8f0" opacity=".35"><title>امروز</title></rect>';
    return '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" aria-hidden="true">' + bars + '</svg>';
  }

  /* ---------- حلقهٔ درصد با گرادیان و انیمیشن ---------- */
  function ringChart(pct, color, size) {
    var r = 34, c = 2 * Math.PI * r;
    size = size || 96;
    var v = (pct === null || pct === undefined || isNaN(pct)) ? 0 : Math.max(0, Math.min(100, pct));
    var gid = uid('rg');
    var off = c * (1 - v / 100);
    var light = fmt.shade(color, 0.28);
    var c2 = c.toFixed(1);

    var circle =
      '<circle cx="42" cy="42" r="' + r + '" fill="none" stroke="url(#' + gid + ')" ' +
      'stroke-width="8" stroke-linecap="round" stroke-dasharray="' + c2 + '" stroke-dashoffset="' +
      (reduce ? off.toFixed(1) : c2) + '">';
    if (!reduce) {
      circle += '<animate attributeName="stroke-dashoffset" from="' + c2 + '" to="' + off.toFixed(1) +
        '" dur=".9s" fill="freeze" calcMode="spline" keyTimes="0;1" keySplines=".22 1 .36 1"/></circle>';
    } else {
      circle += '</circle>';
    }

    return '<svg viewBox="0 0 84 84" style="width:' + size + 'px;height:' + size + 'px" aria-hidden="true">' +
      '<defs><linearGradient id="' + gid + '" x1="0" y1="0" x2="1" y2="1">' +
      '<stop offset="0" stop-color="' + light + '"/><stop offset="1" stop-color="' + color + '"/>' +
      '</linearGradient></defs>' +
      '<circle cx="42" cy="42" r="' + r + '" fill="none" stroke="#17233c" stroke-width="8"/>' +
      circle +
      '</svg>';
  }

  /* ---------- نوار ۶۰ چک آخر ---------- */
  function barsHtml(bars) {
    var dots = '';
    (bars || []).forEach(function (b) { dots += '<i class="' + (b.ok ? 'g' : 'b') + '"></i>'; });
    return dots;
  }

  /** رنگ متناسب با درصد */
  function pctColor(p) {
    if (p === null || p === undefined || isNaN(p)) return '#64748b';
    return p >= 99.5 ? '#22c55e' : (p >= 95 ? '#86efac' : (p >= 80 ? '#f59e0b' : '#ef4444'));
  }

  /* ---------- میلهٔ افقی کوچک (برای نمایش مقایسه‌ای) ---------- */
  function miniBar(pct, color) {
    var v = (pct === null || pct === undefined || isNaN(pct)) ? 0 : Math.max(0, Math.min(100, pct));
    color = color || pctColor(pct);
    var gid = uid('mb');
    return '<svg viewBox="0 0 100 6" preserveAspectRatio="none" class="mbar" aria-hidden="true">' +
      '<defs><linearGradient id="' + gid + '" x1="0" y1="0" x2="1" y2="0">' +
      '<stop offset="0" stop-color="' + fmt.shade(color, 0.25) + '"/><stop offset="1" stop-color="' + color + '"/>' +
      '</linearGradient></defs>' +
      '<rect x="0" y="0" width="100" height="6" rx="3" fill="#141f36"/>' +
      '<rect x="0" y="0" width="' + v.toFixed(2) + '" height="6" rx="3" fill="url(#' + gid + ')"' +
      (reduce ? '' : ' class="grow"') + '/></svg>';
  }

  NS.charts = {
    sparkline: sparkline,
    dailyChart: dailyChart,
    ringChart: ringChart,
    barsHtml: barsHtml,
    pctColor: pctColor,
    miniBar: miniBar
  };
})(window.NS = window.NS || {});