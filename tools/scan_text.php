<?php
/**
 * ===== اسکنِ متن‌های مغشوش/خارجی در سورس =====
 *
 * هدف: پیداکردنِ کاراکترهایی که نباید در سورسِ این پروژه باشند:
 *  - حروف لاتینِ اکسنت‌دار (فرانسوی/اسپانیایی/مجاری/ویتنامی)
 *  - حروف چینی/ژاپنی/کره‌ای/تایلندی/سیریلیک
 *  - حروف عربیِ جزء الفبا (برای جلوگیری از قاطی‌شدن با فارسی)
 *
 * کاربرد: php tools/scan_text.php
 * خروجی: فهرست «فایل:خط: کاراکترها | متن» یا پیام پاک‌بودن.
 */

$root = dirname(__DIR__);
$skipDir = ['logs', 'tools', 'vendor', '.git'];

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

/** کاراکترهای نامطلوب (به‌جز حروف فارسی/عربیِ رایج و لاتینِ سالم) */
$bad = [
    // لاتینِ اکسنت‌دار (فرانسوی/اسپانیایی/مجاری/ویتنامی) — شامل Latin-1 و Extended.
    // عمداً × ÷ « » کنار گذاشته شده‌اند چون در متن فارسی درست‌اند.
    '/[\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{00FF}\x{0100}-\x{024F}]/u'
                              => 'لاتین اکسنت‌دار',
    // حروف لاتینِ افزودهٔ ویتنامی/افزوده‌ها
    '/[\x{1E00}-\x{1EFF}]/u'   => 'لاتین ویتنامی',
    // سیریلیک
    '/[\x{0400}-\x{04FF}]/u'   => 'سیریلیک',
    // چینی/ژاپنی/کره‌ای/کانجی
    '/[\x{2E80}-\x{9FFF}]/u'   => 'شرق‌آسیایی',
    // هانگول
    '/[\x{AC00}-\x{D7AF}]/u'   => 'کره‌ای',
    // تایلندی
    '/[\x{0E00}-\x{0E7F}]/u'   => 'تایلندی',
    // دام عربی↔فارسی: ك (ک عربی) و ي (ی عربی) به‌جای حروف فارسی، و کشیده.
    // توجه: ئ/ؤ/آ/ة در «تأیید/مسئول/آپتایم/صفحه» درست‌اند و نباید گزارش شوند.
    '/[\x{0643}\x{064A}\x{0640}]/u'
                              => 'عربی به‌جای فارسی',
    // حروف لاتینِ چسبیده به فارسی: «واریzoa» یا «کColon» — نشانهٔ ترکیبِ خراب.
    // (سمت دیگر یعنی «APIها» عمداً گزارش نمی‌شود چون درست است)
    '/[\x{0600}-\x{06FF}][A-Za-z]{2,}/u' => 'لاتینِ چسبیده به فارسی',
    // فقط نشانه‌های جهت‌دهنده/فاصلهٔ صفرِ مزاحم — نیم‌فاصلهٔ فارسی (U+200C)
    // عمداً در این دسته نیست چون در «داده‌ای» و «کاربران» کاملاً درست است.
    '/[\x{200B}\x{200E}\x{200F}\x{FEFF}]/u' => 'کاراکتر جهت‌دهنده/فاصلهٔ صفر',
];

$found = 0;
foreach ($rii as $file) {
    if (!$file->isFile()) continue;
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['php', 'md', 'txt', 'html', 'css', 'js'], true)) continue;

    $path = $file->getPathname();
    $rel = ltrim(str_replace($root, '', $path), '/\\');
    if (preg_match('#[\\\\/](logs|tools|vendor|\.git)[\\\\/]#', $rel)) continue;
    if (basename($rel) === 'scan_text.php') continue;

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) continue;

    foreach ($lines as $i => $line) {
        foreach ($bad as $re => $label) {
            if (!preg_match($re, $line)) continue;
            $found++;
            preg_match_all($re, $line, $m);
            $chars = implode('', array_unique($m[0]));
            $txt = trim($line);
            if (mb_strlen($txt) > 110) $txt = mb_substr($txt, 0, 110) . '…';
            printf("%s:%d: [%s] %s | %s\n", $rel, $i + 1, $chars, $label, $txt);
            break; // هر خط فقط یک‌بار گزارش شود
        }
    }
}

echo $found === 0
    ? "OK: هیچ کاراکتر نامطلوبی پیدا نشد.\n"
    : "تعداد موارد: {$found}\n";
