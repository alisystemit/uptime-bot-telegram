/* ============================================================
   کلاینت API — مسیر appapi.php خودکار نسبت به webapp/
   ویژگی‌ها: تایم‌اوت، لغو درخواست، کش حافظه‌ای، تلاش مجدد
   ============================================================ */
(function (NS) {
  'use strict';

  var API = new URL('../appapi.php', location.href).href;
  var TIMEOUT = 15000;
  var CACHE_TTL = 25000;          // اعتبار کش (میلی‌ثانیه)

  var inflight = {};              // لغو درخواست‌های هم‌نام
  var cache = {};                 // کش پاسخ GET

  /* ---------- ساخت URL ---------- */
  function buildUrl(action, params) {
    var q = '?a=' + encodeURIComponent(action);
    if (params) {
      Object.keys(params).forEach(function (k) {
        if (params[k] === undefined || params[k] === null) return;
        q += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
      });
    }
    return API + q;
  }

  /* ---------- پیام فارسی خطاها ---------- */
  function err(kind) {
    var map = {
      auth: 'احراز هویت انجام نشد. لطفاً اپ را از داخل ربات باز کنید.',
      database: 'اختلال در دیتابیس. کمی بعد دوباره تلاش کنید.',
      blocked: 'حساب شما مسدود شده است.',
      capacity: 'ظرفیت کاربران تکمیل است.',
      access: 'دسترسی شما فعال نیست. از ربات دستور /start بفرستید.',
      method: 'درخواست نامعتبر است.',
      not_found: 'موردی پیدا نشد.',
      json: 'پاسخ سرور قابل خواندن نیست.',
      internal: 'خطای داخلی. دوباره تلاش کنید.',
      timeout: 'پاسخی از سرور نرسید. اینترنت را بررسی کنید.',
      network: 'اتصال برقرار نشد.',
      unknown: 'خطای ناشناخته.'
    };
    return map[kind] || kind || 'خطا در ارتباط با سرور';
  }

  /* ---------- خواندن پاسخ با تایم‌اوت و ابورت ---------- */
  function request(url, opts, useCache) {
    var isGet = (opts.method || 'GET') === 'GET';
    // فقط درخواست‌های GET خوانا ابورت خودکار دارند؛
    // POSTهای عملیاتی (مثل چک همزمانِ چند سایت) نباید یکدیگر را بکشند.
    var ctlKey = isGet ? ('GET ' + url) : null;
    if (ctlKey && inflight[ctlKey]) { try { inflight[ctlKey].abort(); } catch (e) {} }

    var controller = null;
    try { controller = new AbortController(); } catch (e) { controller = null; }

    var timeout = null;
    var options = {
      method: opts.method || 'GET',
      headers: {
        'X-Telegram-Init-Data': NS.tg.initData,
        'Accept': 'application/json'
      },
      cache: 'no-store'
    };
    if (options.method !== 'GET') {
      options.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
      options.body = opts.body || '';
    }
    if (controller) options.signal = controller.signal;

    if (ctlKey) inflight[ctlKey] = controller;

    return new Promise(function (resolve) {
      var settled = false;
      function done(v) {
        if (settled) return;
        settled = true;
        if (timeout) clearTimeout(timeout);
        if (ctlKey && inflight[ctlKey] === controller) delete inflight[ctlKey];
        resolve(v);
      }

      timeout = setTimeout(function () {
        if (controller) { try { controller.abort(); } catch (e) {} }
        done({ status: 0, json: { ok: false, error: 'timeout' }, _offline: true });
      }, TIMEOUT);

      fetch(url, options).then(function (r) {
        return r.text().then(function (txt) {
          var j;
          try { j = JSON.parse(txt); }
          catch (e) { return done({ status: r.status, json: { ok: false, error: 'json' } }); }
          if (j && j.ok) { if (useCache && options.method === 'GET') cache[url] = { t: Date.now(), d: j }; }
          done({ status: r.status, json: j });
        });
      }).catch(function (e) {
        var kind = (e && e.name === 'AbortError') ? 'timeout' : 'network';
        done({ status: 0, json: { ok: false, error: kind }, _offline: true });
      });
    });
  }

  /**
   * درخواست به API
   * @param {string} action
   * @param {object|null} params
   * @param {string} method GET|POST
   * @param {boolean} useCache اجازهٔ استفاده از کش کوتاه‌مدت (GET)
   */
  function call(action, params, method, useCache) {
    method = method || 'GET';
    var url = buildUrl(action, method === 'GET' ? params : null);

    if (method === 'GET' && useCache) {
      var c = cache[url];
      if (c && (Date.now() - c.t) < CACHE_TTL) {
        return Promise.resolve({ status: 200, json: c.d, _cached: true });
      }
    }

    var body = '';
    if (method !== 'GET' && params) {
      body = Object.keys(params).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
      }).join('&');
    }
    return request(url, { method: method, body: body }, useCache);
  }

  /* ---------- باطل کردن کش ---------- */
  function bust(action) {
    Object.keys(cache).forEach(function (k) { if (k.indexOf('a=' + action) >= 0) delete cache[k]; });
  }
  function bustAll() { cache = {}; }

  NS.api = { call: call, err: err, bust: bust, bustAll: bustAll, url: API };
})(window.NS = window.NS || {});