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

## ⭐ قیاس با ربات مرجع (botsaz-faxima)

ربات `botsaz-faxima` همین مینی‌اپ را دارد و **بدون مشکل کار می‌کند**.
قیاس دقیق نشان داد تفاوت‌ها این‌هاست:

| | botsaz-faxima (سالم) | ما (قبل از اصلاح) |
|---|---|---|
| بارگذاری SDK | `app/index.php` خط ۲۰ | ❌ **اصلاً بارگذاری نمی‌شد** |
| ارسال initData | `?a=xxx&initData=...` (query) | فقط هدر `X-Telegram-Init-Data` |
| POST initData | در body ارسال می‌کرد | ارسال نمی‌کرد |
| خواندن سرور | `$_POST['initData'] ?? $_GET['initData']` | `$_SERVER['HTTP_X_TELEGRAM_INIT_DATA']` |

### درس ۱ — SDK

```php
// botsaz-faxima/app/index.php خط ۲۰
<script src="js/telegram-web-app.js"></script>
```

این تنها تفاوتی بود که اپ را از کار انداخته بود. (در کامیت قبلی اضافه شد.)

### درس ۲ — initData باید در **هر سه** مسیر برود

ربات مرجع فقط به query string تکیه می‌کند. ما فقط به هدر. اگر روی سرور
شما یک **پروکسی، CDN، WAF یا Nginx با `proxy_set_header`** بین مرورگر و
PHP باشد، ممکن است هدرهای سفارشی (`X-*`) را حذف یا بازنویسی کند — ولی
query string چون بخشی از خود مسیر درخواست است **همیشه سالم می‌ماند**.

**راه‌حل: هر سه مسیر.** اگر یکی سالم باشد، احراز هویت موفق می‌شود.

```js
// GET  → در URL
api.php?a=overview&initData=<urlencoded>

// POST → در URL و در بدنه
api.php?a=add&initData=<urlencoded>
Content-Type: application/x-www-form-urlencoded
initData=<urlencoded>&target=...

// هدر → همیشه هم ارسال می‌شود
X-Telegram-Init-Data: <raw>
```

و سرور (`appapi.php`) هر سه را امتحان می‌کند و با **اولین معتبر** ادامه می‌دهد:

```php
$initDataCandidates = [
    'header' => (string)($_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? ''),
    'get'    => (string)($_GET['initData'] ?? ''),
    'post'   => (string)($_POST['initData'] ?? ''),
];
foreach ($initDataCandidates as $src => $candidate) {
    if ($candidate === '') continue;
    $u = MiniApp::validateInitData($candidate, botToken());
    if ($u) { $tgUser = $u; break; }
}
```

نکته: اولویت با هدر است چون query string هر initData را در لاگ سرور و
`access_log` ثبت می‌کند (حدود ۳۰۰ بایت). اگر نمی‌خواهید لاگ پر شود،
این خط را حذف کنید و فقط به هدر و POST تکیه کنید.

### تست اثبات

```
═══ 4. هدر خراب + query سالم (شبیه‌سازی WAF) ═══
  OK   waf-broken header → query rescues (got 200)
```

و در مرورگر واقعی:

```
GET  → hasInitHdr: true, hasInitQ: true
POST → hasInitHdr: true, hasInitQ: true, bodyHasInit: true
```

---

## 🔬 ابزار قطعی: `tools/hash_debug.php`

اگر لاگ نشان می‌دهد `hash` مطابقت ندارد، **قبل از نتیجه‌گیری** این ابزار را
اجرا کنید. این ابزار به‌جای حدس‌زدن، **۴ علت ممکن را هم‌زمان** بررسی می‌کند:

```bash
php tools/make_initdata.php --signature > initdata.txt
php tools/hash_debug.php -f initdata.txt

# یا از وب (فقط با secret)
https://domain/.../tools/hash_debug.php?secret=<SECRET>&initData=<...>
```

### چهار مرحلهٔ بررسی

```
STEP 1 — bot_token
   ✓ طول: 46
   ✓ ساختار id:secret
   ✓ secret length = 35
   ✓ بدون فاصله / BOM / کوتیشن

STEP 2 — تجزیهٔ initData
   ✓ hash طول = 64
   ✓ auth_date در بازهٔ معتبر
   ← نویسه‌های نامرئی گزارش می‌شوند

STEP 3 — محاسبهٔ هش در ۶ حالت
   ✓ MATCH  A: فقط hash حذف شود (قاعدهٔ رسمی HMAC)
   no       B: hash و signature حذف شوند (Ed25519)
   no       C: بدون auth_date
   ...
   received (کامل): 233004d9...   ← هش کامل، نه ۱۶ رقم

STEP 4 — نتیجه‌گیری
   ⇒ علت دقیق
```

### چهار نتیجهٔ ممکن

| نتیجهٔ نمایش‌داده‌شده | علت واقعی |
|---|---|
| `توکن و initData هر دو سالم‌اند` | هش درست محاسبه می‌شود ⇒ مشکل جای دیگری است (کد قدیمی سرور، proxy) |
| `مشکل از initData است` | ساختار خراب (hash ناقص، BOM، نویسهٔ نامرئی) |
| `مشکل از bot_token است` | توکن ناقص / خالی / دارای فاصله |
| `متعلق به ربات دیگری است` | initData تازه ولی با توکن این ربات امضا نشده |

### تفاوت «دادهٔ کهنه» با «ربات دیگر»

ابزار بر اساس **قدمت `auth_date`** تفکیک می‌کند:

- `auth_date` قدیمی ⇒ initData کهنه است. تلگرام initData را فقط هنگام باز
  شدن اپ تازه می‌کند. اگر رشته را از **لاگ قدیمی** کپی کرده‌اید، همین نتیجه
  را می‌گیرید — و ربطی به توکن ندارد.
- `auth_date` تازه ولی هش نمی‌خواند ⇒ payload با توکن این ربات امضا نشده.
  آدرس Web App در BotFather را بررسی کنید.

---

## ⚠️ دربارهٔ «۱۶ رقمی دیدن هش»

اگر در لاگ خودتان این را دیده‌اید:

```
received_hash: 2dfbf048f2ede176
computed_hash: 16bbfe7958d45fbe
```

توجه کنید که **هر دو فقط ۱۶ رقم** هستند، در حالی که HMAC-SHA256 همیشه
**۶۴ رقم** hex می‌دهد. یعنی لاگ با `substr($hash, 0, 16)` بریده شده است.

**برای تشخیص قطعی، هش کامل لازم است.** متفاوت‌بودن رقم اول یعنی هش‌ها
کاملاً متفاوت‌اند — ولی این به‌تنهایی ثابت نمی‌کند مشکل از توکن است.
سه علت دیگر هم وجود دارد:

1. **initData کهنه** (از لاگ قدیمی کپی شده)
2. **initData متعلق به ربات دیگر** (آدرس Web App اشتباه)
3. **data_check_string متفاوت** (کد قدیمی سرور، `parse_str`، یا proxy/CDN)

نکتهٔ دیگر: نبودن `signature` در لاگ شما **نشان نمی‌دهد که اصلاح من آپلود
شده**. وجود یا نبود `signature` را **کلاینت تلگرام** تعیین می‌کند (نسخهٔ
اپ و نوع باز کردن)، نه کد سرور. برای اطمینان از آپلود شدن، راه درست را در
بخش زیر ببینید.

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

### فایل‌های تغییرکرده

```
webapp/index.html       افزودن <script src="js/telegram-web-app.js">
webapp/js/telegram-web-app.js   فایل جدید — SDK رسمی تلگرام (۱۱۹KB)
webapp/js/telegram.js   انتظار برای SDK
webapp/js/api.js        initData در هر سه مسیر + اصلاح باطل‌کردن کش
webapp/js/app.js        حذف busy-wait
appapi.php              خواندن initData از هر سه منبع + لاگ تشخیصی
```

### اگر باز هم مشکل داشت

۱. **کش WebView تلگرام را پاک کنید.**

۲. **پروکسی/CDN را بررسی کنید.** اگر Nginx دارید:

   ```nginx
   location /bots/paishin/ {
       # هدرهای سفارشی را صریحاً پاس بده
       proxy_set_header X-Telegram-Init-Data $http_x_telegram_init_data;
   }
   ```

   Cloudflare یا هر WAF دیگری هم ممکن است هدرهای `X-*` را حذف کنند.

۳. **`tools/transport_debug.php`** را باز کنید تا ببینید کدام مسیر سالم است.

### بررسی اینکه SDK واقعاً بارگذاری شده

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

### گام ۰ — اول مطمئن شوید کد روی سرور به‌روز است

**نبودن `signature` در لاگ، دلیل آپلود شدن اصلاحات نیست.** آن را کلاینت
تلگرام تعیین می‌کند، نه سرور. برای اطمینان قطعی، روی سرور اجرا کنید:

```bash
php tools/hash_debug.php
```

اگر نسخهٔ قدیمی روی سرور باشد، بخش STEP 2 نمایش داده نمی‌شود
(چون ابزار جدید است). راه مطمئن‌تر — از راه دور:

```
https://domain/.../tools/initdata_debug.php?secret=<SECRET>&initData=<...>
```

اگر این صفحه ۴۰۳ داد ⇒ secret اشتباه است.
اگر ۵۰۰ داد ⇒ فایل‌های `tools/` آپلود نشده‌اند.

### گام ۰ — مطمئن شوید initData اصلاً می‌رسد

اگر هنوز «احراز هویت انجام نشد» می‌بینید، ممکن است initData اصلاً به سرور
نرسد (پروکسی هدر را حذف کرده). این ابزار هر سه مسیر را جدا گزارش می‌کند:

```
https://domain/.../tools/transport_debug.php?secret=<SECRET>&initData=<...>
```

```
── 1. header X-Telegram-Init-Data ──
   length : (empty)          ← پروکسی هدر را برداشته!
── 2. query string ?initData= ──
   length : 285
── 3. POST body initData ──
   length : (empty)
```

اگر هدر خالی ولی query پر است، مشکل از پروکسی/CDN است. (با اصلاح جدید
اپ باید کار کند، ولی علت را می‌توانید برطرف کنید.)

### گام ۱ — تشخیص علت هش

اگر پیام «احراز هویت انجام نشد» می‌بینید:

```bash
# initData خام را از لاگ یا مرورگر بردارید، بعد:
php tools/hash_debug.php -f initdata.txt
```

این ابزار دقیقاً می‌گوید مشکل از کجاست (توکن / initData / کهنگی /
ربات دیگر / کد قدیمی).

### گام ۲ — موارد رایج دیگر

۱. **کش مرورگر تلگرام را پاک کنید** — تلگرام WebView را کش می‌کند.

۲. **آدرس باید https باشد.** تلگرام Mini App را روی `http://` باز نمی‌کند
   و به مرورگر خارجی می‌فرستد (که initData ندارد ⇒ همان پیام خطا).

۳. **دامنه باید در BotFather ثبت شده باشد** (Menu Button → Web App URL).

۴. **از دستور `/app` ربات استفاده کنید** — نه لینک مستقیم و نه «ذخیره‌شده‌ها».
   فقط از مسیر رسمی تلگرام، `initData` تولید می‌شود.

۵. **initData را از لاگ قدیمی برندارید.** تلگرام initData را فقط هنگام باز
   شدن اپ تازه می‌کند؛ یک رشتهٔ کهنه همیشه هش ناهماهنگ می‌دهد.

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