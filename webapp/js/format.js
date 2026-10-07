/* ============================================================
   فرمت‌دهی فارسی + متادیتای وضعیت — بدون وابستگی
   (وب‌اپ: پوشهٔ webapp — فقط جاوااسکریپت، بدون PHP)
   ============================================================ */
(function (NS) {
  'use strict';

  var FA_D = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];

  /** عدد → فارسی با جداکنندهٔ هزارگان */
  function fa(x, group) {
    if (x === null || x === undefined || x === '') return '—';
    var s = String(Math.round(x * 100) / 100);
    if (group !== false) s = s.replace(/(\d)(?=(\d{3})+(\.|$))/g, '$1،');
    return s.replace(/\d/g, function (d) { return FA_D[+d]; });
  }

  function faPct(p) {
    if (p === null || p === undefined || isNaN(p)) return '—';
    return fa(Math.round(p * 100) / 100) + '٪';
  }

  function faMs(ms) { return fa(+ms || 0) + ' ms'; }

  /** پاک‌سازی برای injection-safe بودن خروجی */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
  }

  var TYPE = { http: 'HTTP', tcp: 'TCP', ping: 'پینگ' };

  var STATE = {
    up:      { cls:'up',      label:'فعال',   em:'🟢' },
    down:    { cls:'down',    label:'قطع',    em:'🔴' },
    slow:    { cls:'slow',    label:'کند',    em:'🟠' },
    paused:  { cls:'paused',  label:'متوقف',  em:'⏸' },
    unknown: { cls:'unknown', label:'نامشخص', em:'🟡' }
  };

  function stMeta(s) { return STATE[s] || STATE.unknown; }
  function typeName(t) { return TYPE[t] || t || '—'; }

  /** رنگ/کلاس درصد آپ‌تایم */
  function uptimeCls(p) {
    if (p === null || p === undefined || isNaN(p)) return 'na';
    return p >= 95 ? 'good' : (p >= 80 ? 'warn' : 'bad');
  }

  /* ============================================================
     انیمیشن شمارندهٔ عددی — اعداد داخل <b data-count> پس از رندر
     از مقدار فعلی به مقدار نهایی می‌رسند.
     ============================================================ */
  var reduceMotion = (function () {
    try { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; }
    catch (e) { return false; }
  })();

  function animateCount(el) {
    if (!el) return;
    var target = parseFloat(el.getAttribute('data-count'));
    if (isNaN(target)) return;
    var dp = parseInt(el.getAttribute('data-dp') || '0', 10);
    var suffix = el.getAttribute('data-suffix') || '';
    var fmtVal = function (x) { return fa(dp ? (Math.round(x * 100) / 100) : Math.round(x)) + suffix; };

    var dur = reduceMotion ? 0 : 620;
    // اگر انیمیشن در دسترس نبود، مستقیم مقدار نهایی نوشته شود
    if (dur === 0 || typeof requestAnimationFrame !== 'function') {
      el.textContent = fmtVal(target);
      return;
    }

    var t0 = null;
    function step(ts) {
      if (t0 === null) t0 = ts;
      var k = Math.min(1, (ts - t0) / dur);
      var e = 1 - Math.pow(1 - k, 3);           // easeOutCubic
      el.textContent = fmtVal(target * e);
      if (k < 1) requestAnimationFrame(step);
      else el.textContent = fmtVal(target);
    }
    requestAnimationFrame(step);
  }

  /** پس از رندر، همهٔ شمارنده‌های موجود را فعال کن */
  function animateCounts(root) {
    var nodes = (root || document).querySelectorAll('[data-count]');
    for (var i = 0; i < nodes.length; i++) animateCount(nodes[i]);
  }

  /* ============================================================
     ابزار رنگ — برای نمودارها و سایه‌ها
     ============================================================ */

  /** #rrggbb → [r,g,b] */
  function hex2rgb(h) {
    h = String(h || '').replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    var n = parseInt(h, 16);
    if (isNaN(n)) return [0, 0, 0];
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  }

  function rgba(hex, a) {
    var c = hex2rgb(hex);
    return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')';
  }

  /** روشن/تیره کردن رنگ (amount>0 روشن، <0 تیره) */
  function shade(hex, amount) {
    var c = hex2rgb(hex);
    function f(v) {
      var x = amount > 0 ? v + (255 - v) * amount : v * (1 + amount);
      return Math.max(0, Math.min(255, Math.round(x)));
    }
    return 'rgb(' + f(c[0]) + ',' + f(c[1]) + ',' + f(c[2]) + ')';
  }

  /**
   متن شمارندهٔ عددی.
   از <span> استفاده می‌شود نه <b>، چون قاعده‌های CSS برای <b> معمولاً
   display:block دارند و باعث شکستن خطوط در متن‌های درون‌خطی می‌شوند.
   */
  function countHtml(value, dp, suffix, cls) {
    if (value === null || value === undefined || isNaN(value)) {
      return '<span class="num' + (cls ? ' ' + cls : '') + '">—</span>';
    }
    var v = dp ? (Math.round(value * 100) / 100) : Math.round(value);
    // پسوند داخل متن اولیه هم نوشته می‌شود تا پیش از اجرای انیمیشن کامل باشد
    return '<span class="num' + (cls ? ' ' + cls : '') + '" data-count="' + v +
      '" data-dp="' + (dp || 0) + '"' +
      (suffix ? ' data-suffix="' + esc(suffix) + '"' : '') + '>' + fa(v) + (suffix || '') + '</span>';
  }

  NS.fmt = {
    fa: fa, faPct: faPct, faMs: faMs, esc: esc,
    TYPE: TYPE, STATE: STATE,
    stMeta: stMeta, typeName: typeName, uptimeCls: uptimeCls,
    animateCount: animateCount, animateCounts: animateCounts,
    countHtml: countHtml,
    hex2rgb: hex2rgb, rgba: rgba, shade: shade,
    reduceMotion: reduceMotion
  };
})(window.NS = window.NS || {});