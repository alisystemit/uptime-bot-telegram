<?php
/**
 * ===== ناوبری: دکمه‌های «انصراف» و «بازگشت» =====
 *
 * چرا یک فایل جدا: قبلاً هر مرحلهٔ ورودی خودش متن و دکمهٔ انصراف را می‌ساخت و
 * هیچ مرحله‌ای همهٔ کلمه‌های فرار را نمی‌شناخت. نتیجه: در چند نقطه (مثلاً
 * «دعوت به مانیتور» یا «تنظیم کلید درگاه») کاربر می‌توانست گیر بیفتد و راه
 * برگشت نداشته باشد.
 *
 * این ماژول سه چیز را یک‌جا متمرکز می‌کند:
 *   ۱) Nav::isEscape()      — آیا این پیام یعنی «انصراف/بازگشت»؟
 *   ۲) Nav::cancelKb()      — کیبورد استاندارد مرحلهٔ ورودی (انصراف + راهنما)
 *   ۳) Nav::backRow()       — سطر «بازگشت» برای منوهای شاخه‌ای
 *
 * قانون طلایی: هیچ حالتی نباید کاربر را بدون راه برگشت رها کند. اگر مرحله‌ای
 * اضافه شد، فقط کافی است setStep() صدا بزند — escape به‌صورت خودکار کار می‌کند.
 */
final class Nav
{
    /** کلمه‌هایی که همیشه یعنی «بیا بیرون از این مرحله» */
    public const ESCAPE = [
        '❌ انصراف', '❌ انصراف از عملیات', '❌ بستن', '❌ لغو',
        '/cancel', '/start', '/menu',
        '🔙 بازگشت', '↩️ بازگشت', '🔙 برگشت', '↩️ برگشت',
        '🏠 منو', '🏠 منوی اصلی', '⬅️ بازگشت', '⬅️ برگشت',
        '🔙 منو', 'بازگشت به منو', 'انصراف', 'لغو',
    ];

    /** پسوند @BotName روی دستورها (/cancel@MyBot) */
    private const CMDS = ['cancel', 'start', 'menu', 'close'];

    /**
     * آیا این پیام یعنی انصراف/بازگشت است؟
     * عمداً سخاوتمندانه است: هر نگارشی از «انصراف» یا «بازگشت» را می‌پذیرد
     * تا کاربر با یک ایموجی اشتباه گیر نکند.
     */
    public static function isEscape(mixed $text): bool
    {
        $t = trim(toStr($text));
        if ($t === '') return false;
        if (in_array($t, self::ESCAPE, true)) return true;

        // /cancel@MyBot و مشابه
        if ($t[0] === '/') {
            $head = strtolower(preg_replace('/@[A-Za-z0-9_]+$/', '', $t) ?? $t);
            if ($head === null || $head === '') return false;
            return in_array(ltrim($head, '/'), self::CMDS, true);
        }

        // نرمال‌سازی: حذف ایموجی/فاصله و بررسی محتوای متن
        $plain = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{20E3}\s]/u', '', $t) ?? $t;
        $plain = trim($plain);
        if ($plain === '') return false;
        if (in_array($plain, self::ESCAPE, true)) return true;
        // «انصراف»، «لغو کن»، «بازگشت به منو»، «برگشت» …
        foreach (['لغوکن', 'لغو کن', 'لغوکنم', 'بیخیال', 'بی خیال'] as $needle) {
            if (mb_strpos($plain, $needle) === 0) return true;
        }
        // کلمه باید ابتدای پیام باشد و بعدش حرف فارسی/عربی نیاید، وگرنه
        // «انصرافی از» و «بازگشتی» هم لغو می‌شدند (آزاردهنده و غلط).
        return (bool)preg_match('/^(انصراف|بازگشت|برگشت|لغو)(?![؀-ۿ])/u', $plain);
    }

    /**
     * کیبورد استانداردِ مرحلهٔ ورودی.
     * همیشه «انصراف» دارد و اگر $back داده شود، «بازگشت» هم اضافه می‌شود.
     */
    public static function cancelKb(?string $back = null): string
    {
        $row = [];
        if ($back !== null && $back !== '') $row[] = ['text' => '🔙 بازگشت'];
        $row[] = ['text' => '❌ انصراف'];
        return BotApi::kb([$row]);
    }

    /**
     * کیبورد ورودی همراه با راهنمای کوتاه.
     * $hint مثلاً «مثال: example.com» — داخل متن پیام نمایش داده می‌شود.
     */
    public static function prompt(string $body, string $hint = '', ?string $back = null): array
    {
        $text = $body . ($hint !== '' ? "\n\n" . $hint : '');
        return [$text, self::cancelKb($back)];
    }

    /**
     * سطر «بازگشت» برای منوهای inline شاخه‌ای.
     * اگر منو از قبل سطر بازگشت دارد، دوباره اضافه نمی‌شود.
     *
     * @param string $target کالبک مقصد (مثلاً 'menu' یا 'apanel')
     * @param string $label  متن دکمه
     */
    public static function backRow(string $target = 'menu', string $label = '🔙 بازگشت'): array
    {
        return [['text' => $label, 'callback_data' => $target]];
    }

    /**
     * تضمین می‌کند هر منوی inline حداقل یک راه برگشت دارد.
     * اگر هیچ سطری با callback_data دارد (یعنی منو تله است)، سطر بازگشت اضافه می‌شود.
     *
     * @param array  $rows سطرهای کیبورد
     * @param string $target مقصد بازگشت
     */
    public static function ensureBack(array $rows, string $target = 'menu', string $label = '🔙 بازگشت'): array
    {
        $rows = array_values($rows);
        if (!$rows) $rows[] = self::backRow($target, $label);
        // آیا سطر آخر یک دکمهٔ ناوبری است؟
        $last = end($rows);
        $hasNav = false;
        foreach ((array)$last as $btn) {
            if (!is_array($btn)) continue;
            $cd = (string)($btn['callback_data'] ?? '');
            if ($cd === $target || $cd === 'menu' || $cd === 'apanel' || $cd === 'close' || $cd === 'cancel') {
                $hasNav = true;
                break;
            }
        }
        if (!$hasNav) $rows[] = self::backRow($target, $label);
        return $rows;
    }

    /**
     * منوی inline با تضمین بازگشت.
     */
    public static function menu(array $rows, string $target = 'menu', string $label = '🔙 بازگشت'): string
    {
        return BotApi::ikb(self::ensureBack($rows, $target, $label));
    }

    /**
     * پیام لغو: متن + منوی مقصد.
     * $target یکی از کالبک‌های شناخته‌شده است.
     */
    public static function cancelled(string $where = '', string $target = 'menu'): array
    {
        $msg = '❌ انصراف داده شد.';
        if ($where !== '') $msg .= "\n" . $where;
        $kb = $target === 'menu'
            ? self::homeKb()
            : BotApi::ikb([self::backRow($target)]);
        return [$msg, $kb];
    }

    /**
     * کیبورد اصلی با سطر همیشه‌حاضر «انصراف».
     * بدون این سطر، کاربر در هر مرحلهٔ ورودی گیر می‌کرد.
     */
    public static function homeKb(): string
    {
        return BotApi::kb([
            [['text' => '➕ افزودن سایت'], ['text' => '📋 سایت‌های من']],
            [['text' => '📊 رنکینگ'], ['text' => '📈 گزارش من']],
            [['text' => '🔗 صفحهٔ وضعیت من'], ['text' => '👥 مانیتورهای مشترک']],
            [['text' => '⏸ توقف چک‌ها'], ['text' => '🌐 دامنه‌های من']],
            [['text' => '⚙️ تنظیمات'], ['text' => '🛒 اشتراک ویژه']],
            [['text' => 'ℹ️ راهنما']],
            [['text' => '❌ انصراف']],
        ]);
    }

    /**
     * پیام «راهنمای فرار»: وقتی کاربر وسط یک عملیات است و کمک می‌خواهد.
     */
    public static function helpLine(): string
    {
        return "برای خروج از این مرحله: <b>❌ انصراف</b> یا <b>🔙 بازگشت</b>";
    }
}
