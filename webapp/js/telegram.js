/* ============================================================
   پوستهٔ Telegram.WebApp — بومی‌سازی، تم، BackButton، Haptic
   این لایه تمام دسترسی‌ها به Telegram SDK را ایزوله می‌کند تا
   بقیهٔ اپ نیازی به دانستن جزئیات SDK نداشته باشد.
   ============================================================ */
(function (NS) {
  'use strict';

  var tg = null;
  try { if (window.Telegram && window.Telegram.WebApp) tg = window.Telegram.WebApp; } catch (e) { tg = null; }

  var initData = (tg && tg.initData) || '';
  var tgUser = null;
  try { tgUser = (tg && tg.initDataUnsafe && tg.initDataUnsafe.user) || null; } catch (e) { /* noop */ }

  /* ---------- دسترسی امن ---------- */
  function safe(fn) {
    try { return fn(); } catch (e) { return null; }
  }
  function call(name) {
    return function () {
      if (!tg || typeof tg[name] !== 'function') return null;
      try { return tg[name].apply(tg, arguments); } catch (e) { return null; }
    };
  }
  /** آیا ویژگی وجود دارد؟ (شیء یا تابع — BackButton/MainButton شیء هستند) */
  function has(name) { return !!(tg && tg[name] != null); }

  /* ============================================================
     تم — نگاشت themeParams تلگرام به متغیرهای CSS
     ============================================================ */
  var themeApplied = false;

  function themeVars() {
    var p = (tg && tg.themeParams) || {};
    return {
      bg:        p.bg_color || '#070b14',
      bg_2:      p.secondary_bg_color || '#0e1626',
      text:      p.text_color || '#e7eefc',
      hint:      p.hint_color || '#8ea1c4',
      link:      p.link_color || '#38bdf8',
      btn:       p.button_color || '#1d4ed8',
      btn_text:  p.button_text_color || '#ffffff',
      isDark:    p.bg_color ? isDarkColor(p.bg_color) : true
    };
  }

  function isDarkColor(hex) {
    var c = NS.fmt.hex2rgb(hex);
    var lum = (0.299 * c[0] + 0.587 * c[1] + 0.114 * c[2]) / 255;
    return lum < 0.55;
  }

  /** اعمال تم تلگرام روی ریشهٔ سند */
  function applyTheme() {
    if (!tg) return false;
    var t = themeVars();
    var r = document.documentElement.style;
    r.setProperty('--tg-bg', t.bg);
    r.setProperty('--tg-bg2', t.bg_2);
    r.setProperty('--tg-text', t.text);
    r.setProperty('--tg-hint', t.hint);
    r.setProperty('--tg-link', t.link);
    r.setProperty('--tg-btn', t.btn);
    r.setProperty('--tg-btn-text', t.btn_text);
    document.documentElement.setAttribute('data-theme', t.isDark ? 'dark' : 'light');
    themeApplied = true;
    safe(function () { tg.setHeaderColor(t.bg); tg.setBackgroundColor(t.bg); });
    return true;
  }

  /* ============================================================
     آماده‌سازی اپ داخل تلگرام
     ============================================================ */
  function init() {
    if (!tg) return false;
    safe(function () { tg.ready(); });
    safe(function () { tg.expand(); });
    safe(function () {
      if (has('disableVerticalSwipes')) tg.disableVerticalSwipes();
      if (has('enableClosingConfirmation')) tg.enableClosingConfirmation();
    });
    applyTheme();
    if (has('onEvent')) {
      safe(function () {
        tg.onEvent('themeChanged', function () { applyTheme(); });
        tg.onEvent('viewportChanged', function () { safe(function () { tg.expand(); }); });
        tg.onEvent('fullscreenChanged', function () { safe(function () { tg.expand(); }); });
      });
    }
    // به‌روزرسانی ارتفاع والد برای قفل اسکرول
    if (has('viewport')) {
      safe(function () {
        tg.viewport().then(function (v) {
          if (v && v.height) document.documentElement.style.setProperty('--tg-vh', v.height + 'px');
        }).catch(function () {});
      });
    }
    return true;
  }

  /* ============================================================
     Haptic
     ============================================================ */
  var H = {
    light: function () { safe(function () { tg.HapticFeedback.impactOccurred('light'); }); },
    medium: function () { safe(function () { tg.HapticFeedback.impactOccurred('medium'); }); },
    heavy: function () { safe(function () { tg.HapticFeedback.impactOccurred('heavy'); }); },
    rigid: function () { safe(function () { tg.HapticFeedback.impactOccurred('rigid'); }); },
    soft: function () { safe(function () { tg.HapticFeedback.impactOccurred('soft'); }); },
    success: function () { safe(function () { tg.HapticFeedback.notificationOccurred('success'); }); },
    error: function () { safe(function () { tg.HapticFeedback.notificationOccurred('error'); }); },
    warning: function () { safe(function () { tg.HapticFeedback.notificationOccurred('warning'); }); },
    /** انتخاب (تیک) */
    select: function () { H.light(); },
    /** لغو (تیک سبک) */
    cancel: function () { H.light(); },
    /** حذف/خطر */
    alert: function () { H.heavy(); },
    /** موفقیت */
    done: function () { H.success(); }
  };

  /**
   * نگاشت نام سادهٔ haptic به رفتار صحیح SDK
   * 'success' → notificationOccurred('success')
   * 'light'   → impactOccurred('light')
   */
  function haptic(t) {
    if (!tg || !tg.HapticFeedback) return;
    if (typeof H[t] === 'function') { H[t](); return; }
    if (t === 'success' || t === 'error' || t === 'warning') {
      safe(function () { tg.HapticFeedback.notificationOccurred(t); });
    } else {
      safe(function () { tg.HapticFeedback.impactOccurred(['light','medium','heavy','rigid','soft'].indexOf(t) >= 0 ? t : 'light'); });
    }
  }

  function hapticImpact(style) {
    if (!tg || !tg.HapticFeedback) return;
    safe(function () { tg.HapticFeedback.impactOccurred(style || 'light'); });
  }

  /* ============================================================
     BackButton بومی تلگرام
     ============================================================ */
  var backHandler = null;

  function showBack(handler) {
    backHandler = handler;
    if (!tg || !has('BackButton')) return;
    safe(function () { tg.BackButton.show(); });
  }
  function hideBack() {
    backHandler = null;
    if (!tg || !has('BackButton')) return;
    safe(function () { tg.BackButton.hide(); });
  }
  function bindBack() {
    if (!tg || !has('BackButton')) return;
    safe(function () {
      tg.BackButton.onClick(function () { if (backHandler) backHandler(); });
    });
  }

  /* ============================================================
     MainButton بومی — برای اکشن اصلی هر صفحه
     ============================================================ */
  var mainHandler = null;

  function showMain(text, handler) {
    mainHandler = handler;
    if (!tg || !has('MainButton')) return;
    safe(function () { tg.MainButton.setParams({ text: text, color: themeVars().btn, text_color: themeVars().btn_text, is_active: true, is_visible: true }); tg.MainButton.show(); });
  }
  function hideMain() {
    mainHandler = null;
    if (!tg || !has('MainButton')) return;
    safe(function () { tg.MainButton.hide(); });
  }
  function setMainProgress(on) {
    if (!tg || !has('MainButton')) return;
    safe(function () { if (on) tg.MainButton.showProgress(false); else tg.MainButton.hideProgress(); });
  }
  function bindMain() {
    if (!tg || !has('MainButton')) return;
    safe(function () { tg.MainButton.onClick(function () { if (mainHandler) mainHandler(); }); });
  }

  /* ============================================================
     باز کردن لینک / اشتراک / پرداخت
     ============================================================ */
  function openLink(url) {
    if (tg) { safe(function () { tg.openLink(url); return true; }); }
    try { window.open(url, '_blank', 'noopener'); } catch (e) {}
  }
  function openTelegramLink(url) {
    if (tg) { safe(function () { tg.openTelegramLink(url); return true; }); }
    try { window.open(url, '_blank', 'noopener'); } catch (e) {}
  }
  function close() { safe(function () { tg.close(); }); }
  function share(url, text) {
    if (tg && has('openTelegramLink')) {
      var t = 'https://t.me/share/url?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(text || '');
      safe(function () { tg.openTelegramLink(t); return true; });
      return true;
    }
    if (navigator.share) { try { navigator.share({ url: url, text: text }); return true; } catch (e) {} }
    return false;
  }

  /* ---------- CloudStorage (کش سبک داخل تلگرام) ---------- */
  function cloudGet(key) {
    if (!tg || !has('CloudStorage')) return null;
    var v = safe(function () { return tg.CloudStorage.getItem(key); });
    return (v && typeof v.then === 'function') ? v : null;
  }
  function cloudSet(key, value) {
    if (!tg || !has('CloudStorage')) return;
    safe(function () { tg.CloudStorage.setItem(key, value, function () {}); });
  }

  /* ---------- راه‌اندازی همهٔ شنونده‌ها ---------- */
  function bindAll() {
    bindBack();
    bindMain();
  }

  NS.tg = {
    api: tg,
    hasTelegram: !!tg,
    initData: initData,
    user: tgUser,
    version: (tg && tg.version) || '',
    platform: (tg && tg.platform) || '',
    colorScheme: (tg && tg.colorScheme) || '',

    init: init,
    applyTheme: applyTheme,
    themeVars: themeVars,
    themeApplied: function () { return themeApplied; },

    haptic: haptic,
    hapticImpact: hapticImpact,
    H: H,

    showBack: showBack,
    hideBack: hideBack,
    bindAll: bindAll,

    showMain: showMain,
    hideMain: hideMain,
    setMainProgress: setMainProgress,

    openLink: openLink,
    openTelegramLink: openTelegramLink,
    share: share,
    close: close,

    cloudGet: cloudGet,
    cloudSet: cloudSet,

    has: has,
    safe: safe
  };
})(window.NS = window.NS || {});