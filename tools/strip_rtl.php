<?php
/**
 * ===== پاک‌سازی نشانه‌های RTLِ زائد از سورس =====
 *
 * کاراکترهای U+200E / U+200F / U+FEFF نامرئی‌اند و در کامنت/متن فارسی هیچ
 * نقشی در کد ندارند؛ فقط متن را کثیف می‌کنند و ابزارهای اسکن را گیج.
 * (نیم‌فاصلهٔ U+200C عمداً دست نمی‌خورد چون در فارسی درست است.)
 *
 * کاربرد: php tools/strip_rtl.php [--dry]
 */

$root = dirname(__DIR__);
$dry = in_array('--dry', $argv ?? [], true);

$targets = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if (!$f->isFile()) continue;
    if (!in_array(strtolower($f->getExtension()), ['php', 'md'], true)) continue;
    $rel = ltrim(str_replace($root, '', $f->getPathname()), '/\\');
    if (preg_match('#[\\\\/](logs|tools|vendor|\.git)[\\\\/]#', $rel)) continue;
    $targets[$f->getPathname()] = $rel;
}

$marks = ["\xE2\x80\x8E", "\xE2\x80\x8F", "\xEF\xBB\xBF"]; // LRM, RLM, BOM
$changed = 0;

foreach ($targets as $path => $rel) {
    $s = @file_get_contents($path);
    if ($s === false) continue;
    $n = str_replace($marks, '', $s);
    if ($n === $s) continue;
    $changed++;
    echo ($dry ? 'would clean: ' : 'cleaned: ') . $rel . "\n";
    if (!$dry) @file_put_contents($path, $n);
}

echo $changed === 0
    ? "OK: نشانهٔ RTLِ زائدی پیدا نشد.\n"
    : ($dry ? "تعداد: {$changed} (dry-run)\n" : "تعداد پاک‌شده: {$changed}\n");
