# 🧩 افزونه‌های ربات مانیتورینگ

**وضعیت:** ✅ کاملاً وصل‌شده به ربات، کرون و پنل مدیریت (نه کد مرده)

این سند دقیقاً همان چیزی است که در کد پیاده شده است؛ نه فهرست آرزوها.
همهٔ افزونه‌ها در فایل‌های زیر متمرکز شده‌اند:

| فایل | نقش |
|------|-----|
| `lib/addons.php` | کلاس `Addons` (منطق مشترک) + trait `AddonsUi` (رابط کاربری ربات) |
| `lib/webhooks.php` | وبهوک‌های شخصی هر سایت (`WebhookManager`) |
| `lib/integrations.php` | اتصال Slack / Discord (`SlackIntegration`, `DiscordIntegration`, `IntegrationManager`) |
| `lib/queue.php` | صف کارها (`Queue`) |
| `lib/analytics.php` | تحلیل پیشرفته (`Analytics`) |
| `lib/anomaly.php` | تشخیص ناهنجاری (`AnomalyDetector`) |
| `lib/reports.php` | گزارش SLA (`SLAReport`) |
| `lib/import_export.php` | صادرات/واردات سایت‌ها (`ImportExport`) |
| `lib/affiliate.php` | برنامهٔ همکاری (`AffiliateProgram`) |

---

## 🚀 نصب

```bash
# ساخت/به‌روزرسانی جدول‌ها (idempotent)
php cron/install_features.php

# یا خودکار با اجرای مایگریشن
php table.php
```

تنظیمات اختیاری (در `settings`؛ پیش‌فرض همه روشن):

```
webhook_enabled     = 1
sla_reports_enabled = 1
analytics_enabled   = 1
affiliate_enabled   = 1
```

اگر کلیدی نبود، `Addons::enabled()` پیش‌فرض `true` می‌گیرد؛ پس افزونه بدون
تنظیم اضافه هم کار می‌کند و با گذاشتن `0` خاموش می‌شود.

---

## 🔗 نقطه‌های اتصال به ربات

- **منوی کاربر:** «⚙️ تنظیمات» → «🧩 افزونه‌ها» (`callback_data = feat`)
- **منوی هر سایت:** «🔔 وبهوک» (`whadd:<siteId>`)
- **پنل مدیر:** «🧩 وضعیت افزونه‌ها» (`sysstat`) و «💰 پرداخت همکاران» (`apayouts`)
- **دستورها:** `/export` `/import` `/analyze` `/affiliate` `/webhooks` `/integrations`
- **دکمه‌های inline جدید:** `feat, exp, expcsv, expjson, imp, wh, whadd:N, whtest:N, whdel:N,
  intg, intgset:type, intgtest:type, intgoff:type, anal, anals:N, sla, aff, affreg,
  affpayout, sysstat, qproc, qclean, apayouts, apaydone:N, report_html:N, report_csv:N`
- **مرحله‌های جدید ورودی:** `await_webhook`, `await_integration`, `await_import`
  (همه با «❌ انصراف» بسته می‌شوند)

---

## ۱️⃣ وبهوک شخصی‌سازی

هر کاربر می‌تواند برای هر سایت یک یا چند وبهوک بسازد و رویداد قطعی/برقراری/
کندی/گواهی را به Discord، Slack یا هر سرویس دیگر بفرستد.

```php
// ثبت از UI: منوی سایت → «🔔 وبهوک» → آدرس را بفرستید
// ارسال (به‌صورت غیرهمزمان از صف):
WebhookManager::send($siteId, ['type' => 'down', 'error' => '…', 'ms' => 0]);
WebhookManager::test($webhookId);      // ارسال آزمایشی از UI («🧪 تست»)
WebhookManager::cleanupFailed();       // پاک‌سازی خودکار روزانه در cron
```

رویدادها: `down`, `up`, `slow`, `ssl_warning` — فرمت‌ها: Discord embeds،
Slack blocks، Teams adaptive cards، JSON خام.

---

## ۲️⃣ اتصال Slack / Discord

اتصال مستقیم برای هر کاربر (جدا از وبهوک‌های سایت).

```php
IntegrationManager::register($userId, 'slack',   'https://hooks.slack.com/services/…');
IntegrationManager::register($userId, 'discord', 'https://discord.com/api/webhooks/…');
IntegrationManager::test($userId, 'slack');
IntegrationManager::disable($userId, 'slack');
```

از UI: «🧩 افزونه‌ها» → «🔗 Slack / Discord».
ارسال رویدادها از طریق کار `integration_event` در صف انجام می‌شود.

---

## ۳️⃣ صف کارها (Queue)

`cron/checker.php` بعد از راندهای چک، `Addons::cron()` را صدا می‌زند که شامل
`Queue::process(50, 20)` است. انواع کار:

`check_site`, `export_report`, `send_alert`, `webhook_event`, `integration_event`, `cleanup`

```php
Queue::enqueue('webhook_event', ['site_id' => 5, 'event' => ['type' => 'down']]);
$stats = Queue::process(50, 20);   // {'processed':…, 'failed':…, 'skipped':…}
Queue::status();                   // {'pending':…, 'processing':…, 'failed':…, 'total':…}
Queue::cleanup();                  // حذف کارهای تمام‌شده/ناموفق قدیمی
```

پنل مدیر → «🧩 وضعیت افزونه‌ها» → «⚙️ پردازش صف» / «🧹 پاک‌سازی».

---

## ۴️⃣ تحلیل پیشرفته (Analytics)

از UI: «🧩 افزونه‌ها» → «🧠 تحلیل هوشمند» (`/analyze`) و انتخاب سایت.

```php
$r  = Analytics::getSiteReport($siteId);   // uptime 24h/7d/30d، صدک‌های latency، امتیاز پایداری، پیش‌بینی
$c  = Analytics::compareUserSites($userId);
$b  = Analytics::benchmark($siteId);       // رتبه و مقایسه با میانگین کل
```

---

## ۵️⃣ تشخیص ناهنجاری (Anomaly)

ناهنجاری‌های هر سایت داخل گزارش «🧠 تحلیل هوشمند» نمایش داده می‌شود. علاوه بر
آن، هر ۶ ساعت (اگر `analytics_enabled` روشن باشد) گزارش ناهنجاری به کاربران
اعلان‌دار ارسال می‌شود:

```php
AnomalyDetector::analyze($siteId);       // قطع منظم زمانی، پیک تأخیر ۳σ، ناپایداری شدید
AnomalyDetector::analyzeUser($userId);
AnomalyDetector::reportToUser($userId);
```

---

## ۶️⃣ گزارش SLA

از UI: «🧩 افزونه‌ها» → «📄 گزارش SLA ماهانه».
خروجی HTML (برای چاپ/PDF) و CSV با دکمه‌های `report_html_<id>` / `report_csv_<id>`
به‌صورت «سند» در چت ارسال می‌شود (پوشهٔ `reports/` خودکار ساخته می‌شود).

```php
$report = SLAReport::generate($userId, 'month');   // month | quarter | year
$html   = SLAReport::exportHTML($userId, $report);
$csv    = SLAReport::exportCSV($report);
SLAReport::sendMonthly($userId);                   // ارسال ماهانه — یک‌بار در هر ماه از cron
```

---

## ۷️⃣ صادرات / واردات سایت‌ها

از UI: «🧩 افزونه‌ها» → «📤 خروجی سایت‌ها» / «📥 ورود سایت‌ها» (یا `/export` و `/import`).

```php
$csv  = ImportExport::exportCSV($userId);
$json = ImportExport::exportJSON($userId);
$res  = ImportExport::importCSV($userId, $content);   // {'imported':n, 'skipped':n, 'errors':[…]}
$res  = ImportExport::importJSON($userId, $content);
```

فرمت CSV (سطر اول سرستون):
```
URL,Label,Type,MaxMS,Keyword,Enabled
https://example.com,سایت من,http,1000,success,1
api.example.com:443,API,tcp,500,,1
```

برای ورود، محتوای فایل را داخل چت (مرحلهٔ `await_import`) بفرستید؛ سایت‌های
تکراری ساخته نمی‌شوند. ارسال فایل خروجی با `BotApi::sendDocumentContent()` است.

---

## ۸️⃣ برنامهٔ همکاری (Affiliate)

از UI: «🧩 افزونه‌ها» → «💰 برنامهٔ همکاری» (یا `/affiliate`).

```php
AffiliateProgram::register($userId);
AffiliateProgram::trackSignup($code);
AffiliateProgram::trackPayment($code, $amountToman);

// قلاب خودکار روی هر فعال‌سازی موفق پرداخت:
AffiliateProgram::onPayment($buyerUserId, $amountToman);   // کد را از settings/ref_<uid> می‌خواند

AffiliateProgram::getDashboard($userId);
AffiliateProgram::requestPayout($userId);
AffiliateProgram::completePayout($payoutId);   // از پنل مدیر
```

لینک دعوت: `https://t.me/<bot>?start=aff_<code>`.
کمیسیون: ۲۰٪ از هر خرید؛ حداقل برداشت ۱۰۰٬۰۰۰ تومان.
پنل مدیر: «💰 پرداخت همکاران» → تسویه.

> قلاب کمیسیون در هر دو مسیر فعال‌سازی پرداخت صدا زده می‌شود:
> `Pay::complete()` (تأیید درگاه) و تأیید دستی مدیر (`apayok`).

---

## ⚙️ ادغام خودکار (بدون دخالت کاربر)

هر راند `cron/checker.php` پس از چک‌ها `Addons::cron()` را اجرا می‌کند:

1. پردازش صف (`webhook_event`, `integration_event`, …)
2. گزارش ناهنجاری هر ۶ ساعت (`anomaly_last_at`)
3. گزارش SLA ماهانه (`sla_last_month` — فقط یک‌بار در ماه)
4. پاک‌سازی روزانهٔ صف و وبهوک‌های خراب (`addons_cleanup_at`)

همچنین `Monitor` هنگام تغییر وضعیت سایت، رویداد را در صف می‌گذارد
(`Addons::siteEvent`) و هنگام هشدار گواهی، `Addons::sslEvent`.

---

## 🧹 چیزهایی که حذف شدند

برای پرهیز از کد مرده، این‌ها که استفاده نمی‌شدند پاک شدند:

- `lib/cache.php` — کش Redis اختیاری که هیچ‌جا واقعاً صدا زده نمی‌شد
- `lib/status_page.php` — تکرارِ `status.php`/`Page` (نقش صفحهٔ وضعیت از قبل پوشش داده شده)
- `lib/debug.php` — ابزار توسعه که هرگز بارگذاری نمی‌شد (`uptimeLog` کافی است)

اگر بعداً به کش Redis نیاز شد، می‌توان به‌صورت یک لایهٔ مستقل و سنجیده اضافه‌اش کرد.

---

## 🆘 عیب‌یابی

```sql
-- کارهای ناموفق صف
SELECT * FROM queue WHERE status = 'failed' ORDER BY id DESC LIMIT 20;

-- وبهوک‌های پرخطا
SELECT * FROM webhooks WHERE fail_count > 5;
```

- لاگ خطا: `logs/<date>.log` (نوشته‌شده توسط `uptimeLog` / `BotApi::logFail`)
- وضعیت افزونه‌ها از پنل مدیر → «🧩 وضعیت افزونه‌ها»
