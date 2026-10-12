<?php
/**
 * ===== گزارش خطای داخلی =====
 *
 * چرا این کلاس وجود دارد؟
 *
 * وقتی هندلر ربات با استثنا می‌میرد، دو چیز خراب می‌شد:
 *   ۱) کاربر هیچ پیامی نمی‌گرفت و فقط منتظر می‌ماند (تلگرام هم خطا را
 *      دوباره می‌فرستد و کاربر پیام‌های تکراری می‌بیند).
 *   ۲) وب‌هوک ۵۰۰ برمی‌گرداند ⇒ تلگرام همان آپدیت را بی‌نهایت تکرار می‌کرد
 *      (حلقهٔ مرگ که هم پیام را تکراری می‌کرد هم بار سرور را می‌برد).
 *
 * این کلاس هر دو را حل می‌کند: خطا را دقیق و خوانا به خودِ کاربر می‌گوید
 * (بخش + ورودی + پیام استثنا + فایل:خط) و آپدیت را علامت‌گذاری می‌کند تا
 * دیگر تکرار نشود. جزئیات کامل (stack trace) همیشه در لاگ می‌ماند.
 */

final class ErrorReport
{
    /**
     * گزارش خطا به کاربر + لاگ.
     *
     * @param string $stage   بخشی که خطا داد (مثلاً «message» یا «callback»)
     * @param string $input   ورودیِ کاربر (برای بازتولید خطا)
     * @param string $userRef چه کسی خطا دید (برای لاگ؛ در پیام نمایش داده نمی‌شود)
     */
    public static function handlerError(string $stage, string $input, Throwable $e, ?int $userId = null, ?string $kb = null): void
    {
        $file = basename((string)$e->getFile());
        $line = (int)$e->getLine();
        $raw  = (string)$e->getMessage();
        $safe = sanitizeDbError($raw);

        // لاگ کامل (شامل trace و نوع استثنا) — این‌ها هرگز به کاربر نمی‌رود
        try {
            uptimeLog('error', sprintf(
                '[%s] user=%s input=%s | %s @ %s:%d | %s',
                $stage,
                $userId === null ? '?' : (string)$userId,
                mb_substr(preg_replace('/\s+/u', ' ', $input) ?? $input, 0, 120),
                $safe,
                $file,
                $line,
                get_class($e) . ' :: ' . preg_replace('/\s+/u', ' ', $raw) . "\n" . $e->getTraceAsString()
            ));
        } catch (Throwable $ignored) {
            // لاگر هم خراب باشد، پیامِ کاربر نباید گم شود
        }

        // پیامی که کاربر می‌بیند: دقیق، ولی بدون لو دادن رمز/کوئری
        $text = "⚠️ <b>خطای داخلی ربات — عملیات متوقف شد</b>\n\n"
            . "🧩 بخش: " . tgH($stage) . "\n"
            . "⌨️ ورودی شما: " . tgH(mb_substr($input, 0, 120)) . "\n"
            . "❗️ علت: " . tgH($safe) . "\n"
            . "📍 محل دقیق خطا: " . tgH("{$file}:{$line}") . "\n\n"
            . tgSep() . "\n"
            . "🛡 این پیام علامت‌گذاری شد، پس تکرار بی‌جهت نمی‌شود.\n"
            . "دوباره تلاش کنید یا «🏠 منو» را بزنید.";

        // ارسال خطا هرگز نباید خودش خطا بدهد
        try {
            $extra = ($kb !== null) ? ['reply_markup' => $kb] : [];
            $r = tgSend($userId ?? 0, $text, $extra);
            if (empty($r['ok'])) {
                uptimeLog('error', '[handler] error notice not delivered: ' . (string)($r['description'] ?? '?'));
            }
        } catch (Throwable $ignored) {
            // هیچ کاری نمی‌کنیم؛ کاربر دست‌کم پاسخ ۲۰۰ می‌گیرد تا تکرار نشود
        }
    }
}