<?php
/**
 * ===== لایهٔ درگاه‌های پرداخت =====
 *
 *  PayGws → ثبت/تنظیم درگاه‌ها (جدول pay_gateway) و نگاشت کد به کلاس درایور
 *  Pay    → ساخت سفارش، تأیید، وب‌هوک و فعال‌سازی خودکار اشتراک
 *
 *  اصول مهمی که این لایه رعایت می‌کند:
 *   ۱) مبلغ همیشه از دیتابیس خودمان می‌آید، هرگز از callback.
 *   ۲) هیچ callbackای «تأیید نهایی» نیست؛ بعدش verify صدا زده می‌شود.
 *   ۳) فعال‌سازی اشتراک idempotent است (کلید یکتا روی payments)؛
 *      دوبار verify شدن هیچ سودی نمی‌دهد.
 *   ۴) واحد پول تبدیل می‌شود: قیمت ربات «تومان» است، بیشتر APIها «ریال».
 */
class PayGws
{
    /** @return array<string,class-string<PayDriver>> */
    public static function drivers(): array
    {
        return [
            'zarinpal' => 'PayZarinpal',
            'variza'   => 'PayVariza',
            'cubepy'   => 'PayCubepy',
            'tetrapay' => 'PayTetra',
            'aban'     => 'PayAban',
            'generic'  => 'PayGeneric',
        ];
    }

    /** ردیف‌های پیش‌فرض جدول pay_gateway */
    public static function seed(): array
    {
        return [
            ['code' => 'zarinpal', 'title' => 'زرین‌پال', 'kind' => 'bank', 'sort' => 10,
                'note' => 'درگاه بانکی با نماد اعتماد. کد Merchant را از پنل زرین‌پال بگیرید.'],
            ['code' => 'variza', 'title' => 'واریزا', 'kind' => 'card', 'sort' => 20,
                'note' => 'کارت‌به‌کارت با تأیید خودکار از پیامک بانک. حتماً Webhook Secret را هم وارد کنید.'],
            ['code' => 'cubepy', 'title' => 'کیوب‌پی', 'kind' => 'card', 'sort' => 30,
                'note' => 'کارت‌به‌کارت و ارز دیجیتال. توکن از پنل @cubepy_bot ← «🔗 پنل من».'],
            ['code' => 'tetrapay', 'title' => 'تتراپی', 'kind' => 'crypto', 'sort' => 40,
                'note' => 'پرداخت ریالی و دریافت تتر. ApiKey از tetra98.ir ← پنل فروشنده ← اطلاعات API.'],
            ['code' => 'aban', 'title' => 'آبان گیت وی', 'kind' => 'card', 'sort' => 50,
                'note' => 'کارت‌به‌کارت با تأیید خودکار. توکن live_ یا test_ از پنل آبان گیت وی.'],
            ['code' => 'generic', 'title' => 'درگاه دلخواه', 'kind' => 'card', 'sort' => 90,
                'note' => 'برای سرویس‌هایی که مستندات عمومی ندارند (تون‌پی، اطلس‌پی، ترونادو، بلوپال…) — مسیرها و نام فیلدها را دستی وارد کنید.'],
        ];
    }

    /** ساخت ردیف‌های اولیه (idempotent) */
    public static function ensure(): void
    {
        foreach (self::seed() as $g) {
            try {
                Db::q(
                    'INSERT INTO `pay_gateway` (`code`,`title`,`kind`,`enabled`,`sort`,`created_at`)
                     VALUES (?,?,?,0,?,NOW()) ON DUPLICATE KEY UPDATE `title` = VALUES(`title`)',
                    [$g['code'], $g['title'], $g['kind'], $g['sort']]
                );
            } catch (Throwable $e) {
                uptimeLog('error', 'pay_gateway seed failed: ' . $e->getMessage());
            }
        }
    }

    /** @return array[] همهٔ درگاه‌ها */
    public static function all(bool $onlyEnabled = false): array
    {
        try {
            self::ensure();
            $sql = 'SELECT * FROM `pay_gateway`' . ($onlyEnabled ? ' WHERE `enabled` = 1' : '') . ' ORDER BY `sort` ASC, `id` ASC';
            return Db::all($sql);
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function get(string $code): ?array
    {
        if ($code === '') return null;
        $row = Db::one('SELECT * FROM `pay_gateway` WHERE `code` = ?', [$code]);
        return $row ?: null;
    }

    /** @return class-string<PayDriver>|null */
    public static function driver(string $code): ?string
    {
        return self::drivers()[$code] ?? null;
    }

    /** خواندن یک کلید از تنظیمات JSON درگاه */
    public static function setting(array $gw, string $key, $default = null)
    {
        $raw = (string)($gw['settings'] ?? '');
        if ($raw === '') return $default;
        static $cache = [];
        $ck = (int)($gw['id'] ?? 0) . '|' . md5($raw);
        if (!isset($cache[$ck])) {
            $j = json_decode($raw, true);
            $cache[$ck] = is_array($j) ? $j : [];
        }
        return $cache[$ck][$key] ?? $default;
    }

    /**
     * خواندن یک کلید اسکالر از تنظیمات JSON درگاه.
     * اگر مقدار آرایه/شیء باشد (که مدیر در پنل وارد کرده) مقدار پیش‌فرض
     * برمی‌گردد تا کستِ (string) بعدی warning ندهد.
     */
    public static function text(array $gw, string $key, string $default = ''): string
    {
        return toStr(self::setting($gw, $key, $default), $default);
    }

    public static function setSetting(string $code, string $key, $value): void
    {
        $gw = self::get($code);
        if (!$gw) return;
        $j = json_decode((string)($gw['settings'] ?? ''), true);
        if (!is_array($j)) $j = [];
        $j[$key] = $value;
        Db::q('UPDATE `pay_gateway` SET `settings` = ? WHERE `id` = ?',
            [json_encode($j, JSON_UNESCAPED_UNICODE), (int)$gw['id']]);
    }

    public static function toggle(string $code, bool $on): bool
    {
        $gw = self::get($code);
        if (!$gw) return false;
        if ($on && trim((string)$gw['api_key']) === '' && trim((string)$gw['merchant_id']) === '') {
            return false; // بدون کلید، روشن کردن بی‌فایده است
        }
        Db::q('UPDATE `pay_gateway` SET `enabled` = ? WHERE `id` = ?', [$on ? 1 : 0, (int)$gw['id']]);
        return true;
    }

    public static function note(string $code): string
    {
        foreach (self::seed() as $g) if ($g['code'] === $code) return (string)$g['note'];
        return '';
    }

    /** عنوان نمایشی هر درگاه (برای پیام‌های تلگرام) */
    public static function title(string $code): string
    {
        $row = self::get($code);
        if (!$row) return $code;
        $cls = self::driver($code);
        $base = $cls ? $cls::title() : (string)$row['title'];
        $icon = trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', (string)$row['title']) ?? '');
        return $icon !== '' ? $row['title'] : $base;
    }
}

class Pay
{
    /** حداکثر تعداد سفارش «در انتظار» برای هر کاربر (جلوگیری از انبوه فاکتور) */
    public const MAX_PENDING = 3;

    /** آدرس بازگشت/وب‌هوک برای یک درگاه */
    public static function callbackUrl(string $code): string
    {
        $base = rtrim((string)(appConfig()['base_url'] ?? ''), '/');
        if ($base === '') $base = 'https://' . trim((string)(appConfig()['domain'] ?? ''));
        return $base . '/pay.php?g=' . rawurlencode($code);
    }

    /**
     * ساخت سفارش در درگاه و ثبت آن در جدول payments.
     *
     * @return array{ok:bool, error?:string, id?:int, pay_url?:string, card?:array, amount?:int}
     */
    public static function createOrder(int $userId, string $code, ?int $amountToman = null, ?string $desc = null): array
    {
        $gw = PayGws::get($code);
        if (!$gw) return ['ok' => false, 'error' => 'چنین درگاهی وجود ندارد'];
        if ((int)$gw['enabled'] !== 1) return ['ok' => false, 'error' => 'این درگاه غیرفعال است'];

        $cls = PayGws::driver($code);
        if (!$cls) return ['ok' => false, 'error' => 'درایور این درگاه بارگذاری نشده است'];

        $user = Db::one('SELECT `id`,`name`,`plan`,`plan_until` FROM `user` WHERE `id` = ?', [$userId]);
        if (!$user) return ['ok' => false, 'error' => 'کاربر پیدا نشد'];

        // تمدید اشتراک فعال: از تاریخ انقضا به بعد حساب می‌شود
        $days = max(1, Db::getInt('vip_days', 30));
        $amountToman = $amountToman ?: Db::getInt('price', 0);
        if ($amountToman <= 0) return ['ok' => false, 'error' => 'قیمت اشتراک تنظیم نشده است (پنل مدیریت)'];

        $pending = (int)Db::val("SELECT COUNT(*) FROM `payments` WHERE `user_id` = ? AND `gateway` = ? AND `status` = 'pending'",
            [$userId, $code]);
        if ($pending >= self::MAX_PENDING) {
            return ['ok' => false, 'error' => 'شما ' . faNum(self::MAX_PENDING) . ' فاکتور باز دارید. ابتدا یکی را بررسی کنید یا با پشتیبانی تماس بگیرید.'];
        }

        $orderId = 'UP' . date('ymd') . '-' . $userId . '-' . substr((string)time(), -5);
        $gatewayAmount = $cls::currency() === 'toman' ? $amountToman : $amountToman * 10;

        $args = [
            'user_id'       => $userId,
            'order_id'      => $orderId,
            'amount_toman'  => $amountToman,
            'gateway_amount'=> $gatewayAmount,
            'desc'          => $desc ?: ('اشتراک ویژهٔ ' . faNum($days) . ' روزه'),
            'callback_url'  => self::callbackUrl($code),
            'mobile'        => '',
            'email'         => '',
        ];

        $res = $cls::create($gw, $args);
        if (empty($res['ok'])) {
            self::bumpStat($code, false, (string)($res['error'] ?? ''));
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'ارتباط با درگاه ناموفق بود')];
        }

        $payAmount = (int)($res['pay_amount'] ?? $gatewayAmount);
        if ($payAmount <= 0) $payAmount = $gatewayAmount;
        $card = (array)($res['card'] ?? []);
        $expires = (string)($res['expires_at'] ?? '');
        $expTs = 0;
        if ($expires !== '') { $t = (int)strtotime($expires); if ($t > 0) $expTs = $t; }
        $refStr = (string)($res['ref'] ?? '');
        if ($refStr === '') { self::bumpStat($code, false, 'شناسه درگاه خالی'); return ['ok' => false, 'error' => 'شناسه درگاه از پاسخ خوانده نشد']; }
        $payUrlStr = (string)($res['pay_url'] ?? '');

        try {
            Db::q(
                'INSERT INTO `payments`
                 (`user_id`,`amount`,`status`,`note`,`gateway`,`channel`,`ref_id`,`pay_url`,
                  `card_number`,`card_holder`,`pay_amount`,`expires_at`,`created_at`)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
                [
                    $userId, $amountToman, 'pending',
                    mb_substr((string)($res['tracking_id'] ?? ($orderId . ' | ' . $args['desc'])), 0, 255),
                    $code, $cls::kind(), $refStr, mb_substr($payUrlStr, 0, 500),
                    mb_substr((string)($card['number'] ?? ''), 0, 32), mb_substr((string)($card['holder'] ?? ''), 0, 80),
                    $payAmount, $expTs > 0 ? date('Y-m-d H:i:s', $expTs) : null,
                ]
            );
        } catch (Throwable $e) {
            self::bumpStat($code, false, 'ثبت ناموفق');
            uptimeLog('error', 'payments insert failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'ثبت سفارش در دیتابیس ناموفق بود'];
        }
        $id = (int)Db::val('SELECT MAX(`id`) FROM `payments` WHERE `user_id` = ? AND `ref_id` = ?', [$userId, $refStr]);
        if ($id <= 0) { try { $id = (int)Db::pdo()->lastInsertId(); } catch (Throwable $e) { $id = 0; } }
        if ($id <= 0) { uptimeLog('error', 'payments id missing ref=' . $refStr); return ['ok' => false, 'error' => 'ثبت سفارش ناموفق بود']; }
        self::bumpStat($code, true, '');
        Db::logEvent($userId, 'pay_created', $code . '#' . $id);

        return [
            'ok' => true, 'id' => $id, 'pay_url' => (string)$res['pay_url'],
            'card' => $card, 'amount' => $amountToman, 'pay_amount' => $payAmount,
            'gateway' => $code, 'days' => $days, 'expires_at' => $expTs > 0 ? $expTs : null,
        ];
    }

    /**
     * بررسی وضعیت یک سفارش (این تنها مرجع نهایی پرداخت است).
     * @return array{ok:bool, error:string, id:int, ref_id:string}
     */
    public static function verifyOrder(int $paymentId): array
    {
        $p = Db::one('SELECT * FROM `payments` WHERE `id` = ?', [$paymentId]);
        if (!$p) return ['ok' => false, 'error' => 'سفارش پیدا نشد', 'id' => $paymentId, 'ref_id' => ''];
        if ((string)$p['status'] === 'approved') {
            return ['ok' => true, 'error' => '', 'id' => $paymentId, 'ref_id' => (string)$p['verified_at']];
        }
        if ((string)$p['status'] === 'rejected') {
            return ['ok' => false, 'error' => 'این پرداخت رد شده است', 'id' => $paymentId, 'ref_id' => ''];
        }
        if (!empty($p['expires_at']) && strtotime((string)$p['expires_at']) < time() - 300) {
            self::markStatus($paymentId, 'expired', 'مهلت پرداخت تمام شد');
            return ['ok' => false, 'error' => 'مهلت این فاکتور تمام شده است', 'id' => $paymentId, 'ref_id' => ''];
        }

        $gw = PayGws::get((string)$p['gateway']);
        $cls = $gw ? PayGws::driver((string)$p['gateway']) : null;
        if (!$gw || !$cls) return ['ok' => false, 'error' => 'درگاه این سفارش در دسترس نیست', 'id' => $paymentId, 'ref_id' => ''];

        $v = $cls::verify($gw, $p);
        if (empty($v['ok'])) {
            self::bumpStat((string)$p['gateway'], false, (string)$v['error']);
            return ['ok' => false, 'error' => (string)($v['error'] ?? 'تأیید ناموفق'), 'id' => $paymentId, 'ref_id' => ''];
        }

        // بررسی مبلغ دریافتی (برای درگاه‌هایی که مبلغ را برمی‌گردانند)
        $got = (int)($v['amount'] ?? 0);
        $want = (int)$p['pay_amount'];
        if ($got > 0 && $want > 0 && $got !== $want) {
            $diff = abs($got - $want);
            // اختلاف چند ریالی طبیعی است (کارمزد/مبلغ یکتا)
            $tolerance = $cls::currency() === 'toman' ? 5 : 50;
            if ($diff > $tolerance) {
                self::markStatus($paymentId, 'pending', 'مبلغ نامطابق');
                uptimeLog('warn', 'payment amount mismatch id=' . $paymentId . ' got=' . $got . ' want=' . $want);
                return ['ok' => false, 'error' => 'مبلغ پرداختی با فاکتور مطابقت ندارد', 'id' => $paymentId, 'ref_id' => ''];
            }
        }

        self::complete($paymentId, (string)($v['ref_id'] ?? ''), ['verify' => $v, 'order' => $p]);
        self::bumpStat((string)$p['gateway'], true, '');
        return ['ok' => true, 'error' => '', 'id' => $paymentId, 'ref_id' => (string)($v['ref_id'] ?? '')];
    }

    /**
     * فعال‌سازی نهایی و اشتراک ویژه — کاملاً idempotent.
     * اگر قبلاً تأیید شده باشد، هیچ کاری نمی‌کند و true برمی‌گرداند.
     */
    public static function complete(int $paymentId, string $refId, array $meta = []): bool
    {
        $p = Db::one('SELECT * FROM `payments` WHERE `id` = ?', [$paymentId]);
        if (!$p) return false;
        if ((string)$p['status'] === 'approved') return true;   // قبلاً انجام شده

        // قفل منطقی: فقط از حالت pending به approved می‌رویم
        $n = Db::exec(
            "UPDATE `payments` SET `status` = 'approved', `decided_at` = NOW(), `verified_at` = NOW(),
                    `ref_id` = CASE WHEN `ref_id` = '' THEN ? ELSE `ref_id` END,
                    `raw` = ? WHERE `id` = ? AND `status` = 'pending'",
            [mb_substr($refId, 0, 120), mb_substr((string)json_encode($meta, JSON_UNESCAPED_UNICODE), 0, 2000), $paymentId]
        );

        $fresh = Db::one('SELECT `status` FROM `payments` WHERE `id` = ?', [$paymentId]);
        $wasPending = (string)($p['status'] ?? '') === 'pending';
        if (!$wasPending && (string)($fresh['status'] ?? '') !== 'approved') return false;

        if ($n > 0) {
            $uid = (int)$p['user_id'];
            $days = max(1, Db::getInt('vip_days', 30));
            $base = time();
            $cur = Db::val('SELECT `plan_until` FROM `user` WHERE `id` = ?', [$uid]);
            if (!empty($cur) && strtotime((string)$cur) > time()) $base = (int)strtotime((string)$cur);
            $until = date('Y-m-d H:i:s', $base + $days * 86400);
            Db::q("UPDATE `user` SET `plan` = 'vip', `plan_until` = ?, `access` = 1, `is_blocked` = 0 WHERE `id` = ?", [$until, $uid]);
            Db::logEvent($uid, 'payment_ok', (string)$p['gateway'] . '#' . $paymentId);

            $name = (string)Db::val('SELECT `name` FROM `user` WHERE `id` = ?', [$uid]);
            tgSend($uid, "✅ <b>پرداخت شما تأیید شد</b>\n\n"
                . "🏷 درگاه: " . PayGws::title((string)$p['gateway']) . "\n"
                . "💰 مبلغ: " . faMoney((int)$p['amount']) . "\n"
                . (trim($refId) !== '' ? "🧾 کد پیگیری: <code>" . tgH($refId) . "</code>\n" : '')
                . "💎 اشتراک ویژه فعال شد — تا " . faDateTime($until) . "\n"
                . "🌐 سقف سایت‌ها: " . faNum(Db::getInt('vip_max_sites', 10)) . " سایت",
                ['reply_markup' => null]);

            foreach (botAdminIds() as $aid) {
                tgSend($aid, "💰 <b>پرداخت جدید</b>\n"
                    . "👤 " . tgH(truncateFa($name, 30)) . " (<code>{$uid}</code>)\n"
                    . "🏷 " . PayGws::title((string)$p['gateway']) . "\n"
                    . "💰 " . faMoney((int)$p['amount'])
                    . (trim($refId) !== '' ? "\n🧾 " . tgH($refId) : '')
                    . "\n📅 تا " . faDateTime($until));
            }
        }
        return true;
    }

    /**
     * رسیدن callback یا وب‌هوک یک درگاه.
     * @return array{ok:bool, status:int, message:string, payment_id?:int}
     */
    public static function callback(string $code, array $in, string $raw, array $headers = []): array
    {
        $gw = PayGws::get($code);
        $cls = PayGws::driver($code);
        if (!$gw || !$cls) {
            return ['ok' => false, 'status' => 404, 'message' => 'درگاه نامعتبر'];
        }

        // ---------- اعتبارسنجی امضا (درگاه‌های امضاشده) ----------
        if ($cls::signed($gw)) {
            $secret = (string)$gw['secret'];
            $algo = strtolower((string)PayGws::setting($gw, 'sign_algo', 'sha256'));
            $sig = '';
            foreach ($headers as $k => $v) {
                $k = strtolower(str_replace('_', '-', (string)$k));
                if ($k === 'x-webhook-signature' || $k === 'x-signature') { $sig = (string)$v; break; }
            }
            // بعضی درگاه‌ها «sha256=» را جدا می‌فرستند
            $bare = preg_replace('/^[a-z0-9-]+=/i', '', trim($sig));
            $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
            if ($sig === '' || !(hash_equals($expected, $sig) || hash_equals(hash_hmac('sha256', $raw, $secret), (string)$bare))) {
                // طول‌ها برای عیب‌یابی وب‌هوک لاگ می‌شوند (نه خودِ امضا)
                uptimeLog('warn', 'pay webhook bad signature gateway=' . $code
                    . ' body_len=' . strlen($raw) . ' sig_len=' . strlen($sig)
                    . ' hdrs=' . implode(',', array_keys($headers)));
                return ['ok' => false, 'status' => 400, 'message' => 'امضا نامعتبر'];
            }
            // ضدتکرار: X-Delivery-Id
            $delivery = '';
            foreach ($headers as $k => $v) {
                $k = strtolower(str_replace('_', '-', (string)$k));
                if ($k === 'x-delivery-id' || $k === 'x-request-id') { $delivery = (string)$v; break; }
            }
            if ($delivery !== '') {
                $seen = 'pay_dlv_' . substr(hash('sha256', $code . '|' . $delivery), 0, 40);
                try {
                    if (Db::get($seen) !== null) {
                        return ['ok' => true, 'status' => 200, 'message' => 'قبلاً پردازش شده'];
                    }
                    Db::set($seen, (string)time());
                } catch (Throwable $e) { /* بی‌اهمیت */ }
            }
        }

        $cb = $cls::callback($gw, $in, $raw);
        $ref = trim((string)($cb['ref'] ?? ''));
        if ($ref === '') {
            return ['ok' => false, 'status' => 400, 'message' => 'شناسهٔ تراکنش در پاسخ نیست'];
        }

        $p = Db::one('SELECT `id`,`status`,`user_id` FROM `payments` WHERE `gateway` = ? AND `ref_id` = ? ORDER BY id DESC LIMIT 1',
            [$code, $ref]);
        if (!$p) {
            uptimeLog('warn', 'pay callback unknown ref gateway=' . $code . ' ref=' . $ref);
            // 200 می‌دهیم تا درگاه بیخودی تکرار نکند، ولی چیزی تحویل نمی‌دهیم
            return ['ok' => true, 'status' => 200, 'message' => 'سفارشی با این شناسه پیدا نشد'];
        }
        if ((string)$p['status'] === 'approved') {
            return ['ok' => true, 'status' => 200, 'message' => 'قبلاً تأیید شده', 'payment_id' => (int)$p['id']];
        }
        if (empty($cb['paid'])) {
            self::markStatus((int)$p['id'], 'pending', 'callback: ناموفق/در انتظار');
            return ['ok' => true, 'status' => 200, 'message' => 'پرداخت ناموفق گزارش شد', 'payment_id' => (int)$p['id']];
        }

        // ---------- مرجع نهایی: verify ----------
        $v = self::verifyOrder((int)$p['id']);
        if (empty($v['ok'])) {
            // درگاه‌هایی که endpoint تأیید ندارند (واریزا، یا درگاه دلخواهِ
            // بدون مسیر تأیید) فقط وقتی معتبرند که وب‌هوکشان امضا داشته باشد.
            if (!$cls::hasVerify($gw)) {
                if ($cls::signed($gw)) {
                    self::complete((int)$p['id'], $ref, ['webhook' => $cb]);
                    return ['ok' => true, 'status' => 200, 'message' => 'پرداخت تأیید و فعال شد', 'payment_id' => (int)$p['id']];
                }
                // بدون امضا هیچ اطمینانی نیست ⇒ به مدیر ارجاع می‌دهیم
                self::markStatus((int)$p['id'], 'pending', 'نیازمند تأیید مدیر');
                return ['ok' => true, 'status' => 200, 'message' => 'در انتظار تأیید مدیر', 'payment_id' => (int)$p['id']];
            }
            return ['ok' => true, 'status' => 200, 'message' => (string)$v['error'], 'payment_id' => (int)$p['id']];
        }
        return ['ok' => true, 'status' => 200, 'message' => 'پرداخت تأیید و فعال شد', 'payment_id' => (int)$p['id']];
    }

    public static function markStatus(int $id, string $status, string $note = ''): void
    {
        try {
            $set = '`status` = ?';
            $args = [$status];
            if ($status === 'approved' || $status === 'rejected') {
                $set .= ', `decided_at` = NOW()';
            }
            if ($note !== '') {
                $set .= ', `note` = CONCAT(`note`, " | ", ?)';
                $args[] = mb_substr($note, 0, 120);
            }
            $args[] = $id;
            Db::q("UPDATE `payments` SET {$set} WHERE `id` = ?", $args);
        } catch (Throwable $e) {
            uptimeLog('error', 'markStatus failed: ' . $e->getMessage());
        }
    }

    private static function bumpStat(string $code, bool $ok, string $err = ''): void
    {
        try {
            Db::q('UPDATE `pay_gateway` SET `ok_count` = `ok_count` + ?, `fail_count` = `fail_count` + ?,
                     `last_used` = NOW(), `last_error` = ? WHERE `code` = ?',
                [$ok ? 1 : 0, $ok ? 0 : 1, mb_substr($err, 0, 190), $code]);
        } catch (Throwable $e) {
            // بی‌اهمیت
        }
    }

    /** فاکتورهای منقضی‌شده را علامت می‌زند (هر روز یک‌بار) */
    public static function expireOld(bool $force = false): int
    {
        $last = (int)(Db::get('pay_expire_last', '0') ?: 0);
        if (!$force && $last > 0 && (time() - $last) < 86400) return 0;
        try {
            $n = Db::exec(
                "UPDATE `payments` SET `status` = 'expired'
                  WHERE `status` = 'pending' AND `gateway` <> '' AND `expires_at` IS NOT NULL AND `expires_at` < NOW()"
            );
            Db::set('pay_expire_last', (string)time());
            return $n;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** آمار کلی درگاه‌ها برای پنل مدیریت */
    public static function stats(): array
    {
        try {
            $r = Db::one(
                "SELECT COUNT(*) total,
                        COALESCE(SUM(CASE WHEN `status` = 'approved' THEN 1 ELSE 0 END),0) ok,
                        COALESCE(SUM(CASE WHEN `status` = 'pending'   THEN 1 ELSE 0 END),0) pending,
                        COALESCE(SUM(CASE WHEN `status` IN ('expired','rejected') THEN 1 ELSE 0 END),0) bad,
                        COALESCE(SUM(CASE WHEN `status` = 'approved' THEN `amount` ELSE 0 END),0) revenue
                   FROM `payments` WHERE `gateway` <> ''"
            );
        } catch (Throwable $e) {
            $r = null;
        }
        return [
            'total' => (int)($r['total'] ?? 0),
            'ok' => (int)($r['ok'] ?? 0),
            'pending' => (int)($r['pending'] ?? 0),
            'bad' => (int)($r['bad'] ?? 0),
            'revenue' => (int)($r['revenue'] ?? 0),
        ];
    }

    /** فهرست فاکتورهای یک کاربر (برای منو) */
    public static function userOrders(int $userId, int $limit = 5): array
    {
        try {
            return Db::all(
                'SELECT * FROM `payments` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT ' . (int)$limit,
                [$userId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }
}
