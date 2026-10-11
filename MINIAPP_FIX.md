# 🔧 رفع مشکل مینی‌اپ (Web App)

**تاریخ:** ۲۰ مهر ۱۴۰۵
**وضعیت:** ✅ برطرف شد

---

## 🎯 ریشهٔ مشکل

`webapp/index.html` **اسکریپت `telegram-web-app.js` را بارگذاری نمی‌کرد.**

بدون این اسکریپت:

```
window.Telegram        → undefined
window.Telegram.WebApp → undefined
Telegram.WebApp.initData → ""
```

و در نتیجه:

1. `js/telegram.js` مقدار `tg = null` می‌گرفت
2. `js/api.js` هدر `X-Telegram-Init-Data` را **خالی** می‌فرستاد
3. `appapi.php` پاسخ `401 {"ok":false,"error":"auth"}` می‌داد
4. `js/app.js` صفحهٔ قفل «احراز هویت انجام نشد» را نشان می‌داد

کاربر می‌دید: **«این برنامه فقط داخل تلگرام کار می‌کند»** یا
**«نشانهٔ ورود تلگرام پیدا نشد»** — در حالی که داخل تلگرام بود!

---

## ✅ اصلاحات انجام‌شده

### ۱. افزودن SDK به‌صورت لوکال

```html
<script src="js/telegram-web-app.js"></script>   ← جدید (۱۱۹ کیلوبایت)
<script src="js/format.js"></script>
<script src="js/telegram.js"></script>
```

نسخهٔ محلی از `telegram.org/js/telegram-web-app.js` دانلود و داخل پروژه
ذخیره شد تا:
- به CDN وابسته نباشیم (الزام قبلی پروژه)
- اگر تلگرام/شبکه مشکل داشت، اپ بالا بیاید (فقط پیام خطا نشان می‌دهد)

> اگر خواستید SDK را به‌روز کنید، فقط همین فایل را دوباره جایگزین کنید.

### ۲. انتظار برای SDK در `js/telegram.js`

اگر SDK دیرتر اجرا شود، اپ تا ۳ ثانیه صبر می‌کند:

```js
(function waitForSdk(deadline) {
  if (window.Telegram && window.Telegram.WebApp) { ... return; }
  if (Date.now() < deadline) setTimeout(function () { waitForSdk(deadline); }, 30);
})(Date.now() + 3000);
```

### ۳. انتظار برای initData در `js/api.js`

قبل از هر درخواست، تا آماده‌شدن `initData` صبر می‌کند:

```js
function waitInitData(maxWait) { /* poll تا ۴ ثانیه */ }

// در call():
return waitInitData(4000).then(function (liveInit) {
  return request(url, { method: method, body: body, initData: liveInit }, useCache);
});
```

### ۴. حذف busy-wait در `js/app.js`

حلقهٔ قبلی `while (Date.now() - $s < 150)` **thread مرورگر را قفل می‌کرد**.
جای آن یک poll غیرمسدودکننده با `setTimeout` نشست (تا ۴۰ تلاش × ۶۰ms).

---

## 🧪 تست انجام‌شده

تست کامل داخل مرورگر واقعی با شبیه‌سازی `initData` معتبر:

| سناریو | نتیجه |
|---|---|
| SDK بارگذاری شد | ✅ |
| همهٔ ماژول‌ها اجرا شدند (`fmt, tg, api, charts, icons, views, actions, app`) | ✅ |
| صفحهٔ قفل نمایش داده نشد | ✅ |
| داشبورد رندر شد (نام، آپتایم، سایت‌ها) | ✅ |
| تب «گزارش» | ✅ |
| تب «رخدادها» | ✅ |
| تب «دامنه‌ها» | ✅ |
| تب «رنکینگ» | ✅ |
| شیت تنظیمات | ✅ |
| افزودن سایت جدید + چک فوری | ✅ «سایت ثبت شد و چک اول موفق بود» |

### تست‌های خودکار (بدون نیاز به سرور)

```
tools/test_miniapp.php      → 29 PASS, 0 FAIL
```

پوشش: اعتبارسنجی initData، رد دادهٔ جعلی، TTL، `ensureUser`، همهٔ متدهای
داده، کنترل مالکیت سایت، افزودن/حذف.

### تست HTTP (نیازمند وب‌سرور روی پورت ۸۳۲۳)

```
tools/test_miniapp_http.php → 27 PASS, 0 FAIL
```

پوشش: ۴۰۱ بدون initData، ۴۰۱ با initData جعلی، ۲۰۰ همهٔ GETها،
۴۰۴ سایت ناموجود، ۴۰۵ برای نوشتن با GET، POST check/add/delete.

---

## 📤 چطور روی سرور اعمال کنید

```bash
git pull
```

سپس فقط این فایل‌ها تغییر کرده‌اند:

```
webapp/index.html                    (افزودن <script> SDK)
webapp/js/telegram-web-app.js        (فایل جدید — SDK)
webapp/js/telegram.js                (انتظار برای SDK)
webapp/js/api.js                     (انتظار برای initData)
webapp/js/app.js                     (حذف busy-wait)
```

**هیچ تغییری در PHP لازم نیست.** اگر کش/CDN دارید، پاکش کنید.

---

## ⚠️ نکات مهم

### اگر هنوز کار نمی‌کند

۱. **کش مرورگر تلگرام را پاک کنید** — تلگرام WebView را کش می‌کند.

۲. **آدرس باید https باشد.** تلگرام Mini App را روی `http://` باز نمی‌کند
   و به مرورگر خارجی می‌فرستد (که initData ندارد ⇒ همان پیام خطا).

۳. **دامنه باید در BotFather ثبت شده باشد** (Menu Button → Web App URL).

۴. **از دستور `/app` ربات استفاده کنید** — نه لینک مستقیم و نه «ذخیره‌شده‌ها».
   فقط از مسیر رسمی تلگرام، `initData` تولید می‌شود.

### بررسی اینکه SDK واقعاً بارگذاری شده

در کنسول مرورگر (یا از راه دور):

```js
console.log(typeof window.Telegram);              // باید "object" باشد
console.log(window.Telegram.WebApp.initData.length); // داخل تلگرام > 0
```

اگر اولی `undefined` بود ⇒ فایل `telegram-web-app.js` آپلود نشده.

---

## 📁 فایل SDK

```
webapp/js/telegram-web-app.js
```

- منبع: `https://telegram.org/js/telegram-web-app.js`
- حجم: ~۱۱۹ کیلوبایت
- بدون وابستگی، بدون minify دستکاری‌شده (نسخهٔ رسمی)