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

## 🔎 نکتهٔ مهم: فیلد `signature` و اعتبارسنجی

تلگرام از نسخهٔ 7.2/8.0 فیلد `signature` را به `initData` اضافه کرده
(امضای Ed25519 برای اعتبارسنجی شخص‌ثالث).

**این فیلد برای دو مسیر اعتبارسنجی، قاعدهٔ متفاوتی دارد:**

| مسیر | چه کسی | حذف از data_check_string |
|---|---|---|
| HMAC-SHA256 با توکن ربات | مالک ربات (ما) | فقط `hash` — **`signature` می‌ماند** |
| Ed25519 با کلید عمومی | شخص ثالث (بدون توکن) | هم `hash` و هم `signature` |

طبق [مستندات رسمی](https://docs.telegram-mini-apps.com/platform/init-data):
> «Key `hash` should be excluded» — فقط `hash`.

پس **حذف `signature` از رشتهٔ هش برای اعتبارسنجی HMAC اشتباه است** و باعث
رد شدن همهٔ کاربران (پیام «احراز هویت انجام نشد») می‌شود.

### چرا کد ما هر دو حالت را می‌آزماید

`lib/miniapp.php` به‌جای انتخاب یک قاعده، **هر دو حالت را امتحان می‌کند**:

```php
foreach ([true, false] as $dropSignature) {
    // حالت ۱: signature داخل رشته (قاعدهٔ رسمی HMAC)
    // حالت ۲: signature حذف می‌شود (سازگاری با نسخه‌های قدیمی)
    ...
    if (hash_equals($calc, $hash)) { $verified = true; break; }
}
```

**این کار امنیت را ضعیف نمی‌کند:** هر دو حالت با HMAC و توکن ربات
راستی‌آزمایی می‌شوند. کسی بدون توکن ربات نمی‌تواند هیچ‌کدام را جعل کند.

### تجزیهٔ initData بدون `parse_str`

```php
// ❌ قبلی — مشکل‌ساز
parse_str($initData, $fields);
//   • کلید «user.id» را به «user_id» تبدیل می‌کند
//   • «+» در رشتهٔ query را به فاصله تبدیل می‌کند
//     (امضای base64 تلگرام می‌تواند «+» داشته باشد ⇒ هش ناهماهنگ)

// ✅ فعلی — بایت‌های اصلی حفظ می‌شوند
foreach (explode('&', $initData) as $chunk) {
    if ($chunk === '') continue;
    $eq = strpos($chunk, '=');
    if ($eq === false) continue;
    $fields[rawurldecode(substr($chunk, 0, $eq))] = rawurldecode(substr($chunk, $eq + 1));
}
```

---

## 🔧 ابزار تشخیص

اگر کاربر دوباره پیام «احراز هویت انجام نشد» دید، به‌جای حدس‌زدن:

```bash
# initData را در فایل بگذارید و اجرا کنید
php tools/make_initdata.php --signature > initdata.txt
php tools/initdata_debug.php -f initdata.txt

# یا از وب (فقط با secret)
https://domain/.../tools/initdata_debug.php?secret=<SECRET>&initData=<...>
```

خروجی دقیقاً می‌گوید **کدام مرحله** رد کرده:

```
── 1. parsed keys ──
   auth_date        len=10
   hash             len=64     03eac71398bb2fa997…
   signature        len=88     PYIUUce6001TO1c+/rS0ck…
   user             len=97

── 2. common problems ──
   ✓ hash looks well-formed (64 hex chars)
   ✓ auth_date is 0 min old
   ✓ user.id = 777000001

── 3. hash verification ──
   ✓ MATCH  signature INCLUDED (correct per official docs for HMAC)

── 4. MiniApp::validateInitData ──
   ✓ VALID — user id 777000001 (ali_test)
```

ابزار این موارد را هم تشخیص می‌دهد:
- **BOM (`U+FEFF`) و فاصلهٔ نامرئی** — گاهی هنگام کپی یا از هدر وارد می‌شود
  و کلید `auth_date` را به `﻿auth_date` تبدیل می‌کند
- نبود `hash` (اپ بیرون از تلگرام باز شده)
- `hash` ناقص یا غیر hex
- `auth_date` منقضی یا در آینده (اختلاف ساعت سرور)
- `user` نامعتبر یا بدون `id`
- تفاوت بین قاعدهٔ HMAC و Ed25519

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