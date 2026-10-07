<?php
/**
 * ===== اپلیکیشن تلگرام (Mini App) — مسیردهی =====
 *
 * این فایل صرفاً برای سازگاری با لینک‌های قدیمی نگه داشته شده است.
 * رابط کاربری جدید در پوشهٔ «webapp» قرار دارد (کاملاً جاوااسکریپتی):
 *   webapp/index.html + webapp/css/app.css + webapp/js/*
 *
 * ربات و دکمهٔ «📱 اپلیکیشن» به‌صورت خودکار به آدرس جدید اشاره می‌کنند
 * (تابع miniAppUrl در lib/bootstrap.php).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: deny');
header('Location: webapp/index.html', true, 302);
exit;