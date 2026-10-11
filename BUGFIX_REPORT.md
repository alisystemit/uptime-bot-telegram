# 🐛 گزارش رفع اشکال — خطای ۵۰۰

**تاریخ:** ۲۰ مهر ۱۴۰۵
**وضعیت:** ✅ همهٔ اشکالات برطرف شد

---

## 🎯 ریشهٔ اصلی خطای ۵۰۰

سه مشکل مستقل که با هم باعث ۵۰۰ می‌شدند:

### ۱. خطای Parse در `lib/status_page.php` (عامل اصلی)

فایل جدیدی که به `bootstrap.php` اضافه شده بود **سینتکس نامعتبر** داشت:

```php
// ❌ نامعتبر — PHP داخل heredoc این را نمی‌پذیرد
{$site['label'] ?: $site['target']}
{round($uptime30['pct'], 1)}
{date('H:i')}
{count($sites)}
```

از آن‌جا که `bootstrap.php` این فایل را `require` می‌کرد، **کل ربات** با
`Parse error` از کار می‌افتاد ⇒ تلگرام پاسخ ۵۰۰ می‌گرفت.

**رفع:** مقادیر قبل از heredoc محاسبه و با متغیر ساده جای‌گذاری شدند.

---

### ۲. `config.php` قالب بود

فایل کانفیگ روی سرور هنوز `{BOT_TOKEN}` و `{DB_HOST}` داشت:

```
SQLSTATE[HY000] [2002] getaddrinfo for {DB_HOST} failed: No such host is known
```

**رفع:** مقادیر واقعی جای‌گزین شدند.

---

### ۳. تگ تکراری `<?php` در `tools/health.php`

```php
<?php
<?php   ← خطای parse
```

**رفع:** تگ تکراری حذف شد.

---

## 🐛 اشکالات دیگری که پیدا و رفع شدند

### ۴. ستون‌های اشتباه دیتابیس (۵ ماژول)

کد جدید از نام ستون‌هایی استفاده می‌کرد که **در اسکیمای واقعی وجود ندارند**:

| کد اشتباه | ستون واقعی | فایل‌ها |
|---|---|---|
| `check_log.checked_at` | `check_log.ts` | reports, anomaly, analytics, queue |
| `check_log.is_up` | `check_log.ok` | anomaly |
| `check_log.response_ms` | `check_log.ms` | reports, anomaly, analytics |
| `user.share_token` | ✅ وجود دارد | status_page |

اگر این اصلاح نمی‌شد، هر بار که کاربر گزارش یا آمار می‌خواست
`SQLSTATE[42S22]: Unknown column` می‌گرفت.

---

### ۵. `PDOStatement::lastInsertId()` ← متد وجود ندارد

```php
// ❌ Fatal: Call to undefined method PDOStatement::lastInsertId()
$id = Db::q('INSERT ...')->lastInsertId();

// ✅ درست
Db::q('INSERT ...');
$id = (int)Db::pdo()->lastInsertId();
```

موارد: `lib/queue.php`، `lib/reports.php`، `lib/affiliate.php`

---

### ۶. تقسیم بر صفر در صفحهٔ وضعیت

کاربری که هنوز سایتی ثبت نکرده بود ⇒
`count($sites) == 0` ⇒ `Division by zero` ⇒ **۵۰۰**

---

### ۷. فرمول اشتباه `Ranking::pointsToNext`

```php
// ❌ سطح ۱ با ۰ امتیاز ⇒ ۲۰۰ امتیاز تا سطح بعد (غلط)
return max(0, ($nextLevel * self::POINTS_PER_LEVEL) - $points);

// ✅ سطح ۱ با ۰ امتیاز ⇒ ۱۰۰ امتیاز تا سطح بعد
return max(0, ($level * self::POINTS_PER_LEVEL) - $points);
```

تست رنکینگ این باگ را از قبل پیدا کرده بود و پاس نمی‌شد.

---

### ۸. `tools/health.php` کلاس `Db` را پیدا نمی‌کرد

```php
// ❌ این define باعث می‌شد bootstrap.php همهٔ require ها را رد کند
define('UPTIME_ROOT', dirname(__DIR__));
require_once UPTIME_ROOT . '/lib/bootstrap.php';
```

نتیجه: `Class "Db" not found` و ۲ تست fail.

---

### ۹. متغیر `$full` خارج از scope در `health.php`

```php
function check(...) {
    global $results;          // ❌ $full یادآوری نشده بود
    if ($full && $details) ...
}
```

---

### ۱۰. لینک دعوت affiliate بی‌معنی بود

```php
// ❌ ساختن لینک t.me از دامنهٔ وب — هیچ ربطی ندارد
'https://' . appConfig()['domain'] . '/t.me/bot?start=aff_' . $code

// ✅
'https://t.me/' . botUsername() . '?start=aff_' . $code
```

---

### ۱۱. رقابت در `Queue::process`

دو کرون هم‌زمان می‌توانستند یک کار را بردارند و دوبار اجرا کنند.
**رفع:** قفل اتمیک با `UPDATE ... WHERE status='pending'` و بررسی `rowCount()`.

همچنین هنگام خطا، `status` به `pending` برمی‌گشت (قبلاً در `processing` گیر می‌کرد
و کار هرگز دوباره اجرا نمی‌شد).

---

### ۱۲. `Monitor::checkSite` کلید `ok` برنمی‌گرداند

```php
// ❌ همیشه false ⇒ هر کارِ صف ۳ بار retry و سپس failed می‌شد
return $result['ok'] !== null;

// ✅ متد کلید 'result' برمی‌گرداند
return isset($result['result']) && empty($result['result']['error']);
```

---

### ۱۳. `Analytics` داده‌ها را در حافظه لود می‌کرد

`percentile()` همهٔ رکوردهای ۳۰ روز را می‌خواند. ضمن اینکه `check_log` فقط
۲۴ ساعت نگهداری می‌شود، پس آمار ۳۰ روزه **همیشه خالی** بود.

**رفع:** استفاده از `uptime_hour` برای بازه‌های بلند + `LIMIT` برای دقیق‌ترین
محاسبه روی ۲۴ ساعت اخیر.

---

### ۱۴. `curl_close()` در PHP 8.5 deprecated

بی‌اثر از PHP 8.0 است (GC خودکار). با `unset($ch)` جایگزین شد تا لاگ پر از
هشدار نشود.

---

## ✅ نتیجهٔ تست‌ها

```
tests/test_fixes.php      → گذشته: 54 | ناموفق: 0
tests/ranking_test.php    → 61 موفق، 0 ناموفق
tests/nav_test.php        → 84 موفق، 0 ناموفق
tools/test_load.php       → 98 PASS, 0 FAIL
tools/test_new_modules.php→ 60 PASS, 0 FAIL
tools/health.php          → 31/31 OK
php -l (همهٔ فایل‌ها)      → clean (PHP 8.1 تا 8.5)
```

---

## 🚀 چطور ربات را بالا بیاوریم

```bash
# ۱. کانفیگ را پر کنید (اگر نکرده‌اید)
#    config.php نباید {BOT_TOKEN} یا {DB_HOST} داشته باشد

# ۲. جدول‌ها را بسازید
php table.php
# یا فقط ویژگی‌های جدید:
php cron/install_features.php

# ۳. وب‌هوک تلگرام را تنظیم کنید
#    https://api.telegram.org/bot<TOKEN>/setWebhook?url=<BASE_URL>/index.php

# ۴. کرون را فعال کنید
php cron/checker.php --daemon
# یا crontab:  * * * * * php /path/cron/checker.php
```

---

## 🔍 بررسی وضعیت

```bash
# CLI
php tools/health.php --full

# وب (فقط با secret)
https://domain/.../tools/health.php?secret=<cron secret>
```

---

## ⚠️ نکتهٔ امنیتی

`config.php` شامل توکن واقعی تلگرام است و در git track شده. اگر این ریپو
عمومی است، **توکن را عوض کنید** (`@BotFather` → `/revoke`) و `config.php` را
از نسخهٔ کنترل خارج کنید.