<?php
/**
 * ===== برنامهٔ همکاری و کسب‌درآمد =====
 *
 * مدل:
 *   • کد دعوت منحصر
 *   • ۲۰% کمیسیون از هر خرید
 *   • پرداخت ماهانه
 *   • Dashboard برای پیگیری
 */

class AffiliateProgram
{
    /**
     * ثبت کاربر به برنامهٔ همکاری
     */
    public static function register(int $userId): array
    {
        try {
            // بررسی عضویت قبلی
            $existing = Db::one(
                'SELECT `id` FROM `affiliates` WHERE `user_id` = ?',
                [$userId]
            );

            if ($existing) {
                return [
                    'ok' => true,
                    'message' => 'شما قبلاً در برنامه ثبت‌شده‌اید',
                    'code' => Db::val('SELECT `code` FROM `affiliates` WHERE `user_id` = ?', [$userId]),
                ];
            }

            // ایجاد کد منحصر
            $code = self::generateCode($userId);

            // درج در دیتابیس
            Db::q(
                'INSERT INTO `affiliates` 
                 (`user_id`, `code`, `commission_rate`, `status`)
                 VALUES (?, ?, ?, ?)',
                [$userId, $code, 20, 'active']  // ۲۰% کمیسیون
            );

            Db::logEvent($userId, 'affiliate_register', 'کد: ' . $code);

            return [
                'ok' => true,
                'message' => 'شما به برنامهٔ همکاری اضافه شدید',
                'code' => $code,
                'referral_link' => 'https://' . (appConfig()['domain'] ?? 'example.com') . '/t.me/bot?start=aff_' . $code,
            ];
        } catch (Throwable $e) {
            uptimeLog('error', 'affiliate_register: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * پیگیری signup از طریق کد affilia
     */
    public static function trackSignup(string $code): void
    {
        try {
            $affiliate = Db::one('SELECT `id` FROM `affiliates` WHERE `code` = ?', [$code]);
            if (!$affiliate) return;

            Db::q(
                'UPDATE `affiliates` SET `signup_count` = `signup_count` + 1, `last_signup` = NOW() 
                 WHERE `id` = ?',
                [$affiliate['id']]
            );
        } catch (Throwable $e) {
            uptimeLog('error', 'trackSignup: ' . $e->getMessage());
        }
    }

    /**
     * پیگیری پرداخت و محاسبهٔ کمیسیون
     */
    public static function trackPayment(string $code, int $amountToman): void
    {
        try {
            $affiliate = Db::one('SELECT * FROM `affiliates` WHERE `code` = ?', [$code]);
            if (!$affiliate) return;

            $commission = (int)($amountToman * ($affiliate['commission_rate'] / 100));

            Db::q(
                'INSERT INTO `affiliate_earnings` 
                 (`affiliate_id`, `amount`, `commission`, `type`, `payment_id`)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $affiliate['id'],
                    $amountToman,
                    $commission,
                    'referral_payment',
                    0,
                ]
            );

            // به‌روزرسانی مجموع کمیسیون
            Db::q(
                'UPDATE `affiliates` SET `total_commission` = `total_commission` + ? WHERE `id` = ?',
                [$commission, $affiliate['id']]
            );

            // درخواست خودکار برای پرداخت اگر به ۱۰۰۰۰۰ تومان رسید
            if (($affiliate['total_commission'] + $commission) >= 100000) {
                self::createPayoutRequest($affiliate['id']);
            }
        } catch (Throwable $e) {
            uptimeLog('error', 'trackPayment: ' . $e->getMessage());
        }
    }

    /**
     * داشبورد affiliate
     */
    public static function getDashboard(int $affiliateId): array
    {
        $affiliate = Db::one(
            'SELECT * FROM `affiliates` WHERE `user_id` = ?',
            [$affiliateId]
        );

        if (!$affiliate) {
            return ['ok' => false, 'error' => 'شما در برنامه ثبت‌نشده‌اید'];
        }

        // درآمدهای قابل پرداخت (هنوز پرداخت‌نشده)
        $unpaid = (int)Db::val(
            'SELECT SUM(`commission`) FROM `affiliate_earnings` 
             WHERE `affiliate_id` = ? AND `paid_at` IS NULL',
            [$affiliate['id']]
        );

        // کل درآمدهای پرداخت‌شده
        $paid = (int)Db::val(
            'SELECT SUM(`commission`) FROM `affiliate_earnings` 
             WHERE `affiliate_id` = ? AND `paid_at` IS NOT NULL',
            [$affiliate['id']]
        );

        // آخرین ۱۰ درآمد
        $earnings = Db::all(
            'SELECT * FROM `affiliate_earnings` 
             WHERE `affiliate_id` = ? 
             ORDER BY `created_at` DESC LIMIT 10',
            [$affiliate['id']]
        );

        return [
            'ok' => true,
            'code' => $affiliate['code'],
            'referral_link' => 'https://' . (appConfig()['domain'] ?? 'example.com') . '/t.me/bot?start=aff_' . $affiliate['code'],
            'stats' => [
                'signup_count' => (int)$affiliate['signup_count'],
                'total_commission' => (int)$affiliate['total_commission'],
                'unpaid_commission' => $unpaid,
                'paid_commission' => $paid,
                'commission_rate' => (int)$affiliate['commission_rate'],
            ],
            'earnings' => $earnings,
            'payout_status' => $unpaid >= 100000 ? 'ready' : 'waiting',
            'next_payout' => $unpaid >= 100000 ? 'قابل درخواست' : 'تا ' . (100000 - $unpaid) . ' تومان دیگر',
        ];
    }

    /**
     * درخواست پرداخت
     */
    public static function requestPayout(int $affiliateId, string $method = 'card'): array
    {
        try {
            $affiliate = Db::one(
                'SELECT * FROM `affiliates` WHERE `user_id` = ?',
                [$affiliateId]
            );

            if (!$affiliate) {
                return ['ok' => false, 'error' => 'همکار یافت نشد'];
            }

            $unpaid = (int)Db::val(
                'SELECT SUM(`commission`) FROM `affiliate_earnings` 
                 WHERE `affiliate_id` = ? AND `paid_at` IS NULL',
                [$affiliate['id']]
            );

            if ($unpaid < 100000) {
                return ['ok' => false, 'error' => 'حداقل مبلغ: ۱۰۰۰۰۰ تومان'];
            }

            // ایجاد درخواست
            $requestId = Db::q(
                'INSERT INTO `affiliate_payouts` 
                 (`affiliate_id`, `amount`, `method`, `status`)
                 VALUES (?, ?, ?, ?)',
                [$affiliate['id'], $unpaid, $method, 'pending']
            )->lastInsertId();

            // علامت‌زدن درآمدها به‌عنوان در‌حال‌پرداخت
            Db::q(
                'UPDATE `affiliate_earnings` SET `payout_id` = ? 
                 WHERE `affiliate_id` = ? AND `paid_at` IS NULL',
                [$requestId, $affiliate['id']]
            );

            return [
                'ok' => true,
                'message' => 'درخواست پرداخت ارسال شد',
                'request_id' => $requestId,
                'amount' => $unpaid,
            ];
        } catch (Throwable $e) {
            uptimeLog('error', 'requestPayout: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * تکمیل پرداخت توسط ادمین
     */
    public static function completePayout(int $payoutId): bool
    {
        try {
            $payout = Db::one(
                'SELECT * FROM `affiliate_payouts` WHERE `id` = ?',
                [$payoutId]
            );

            if (!$payout) return false;

            // به‌روزرسانی درآمدها
            Db::q(
                'UPDATE `affiliate_earnings` SET `paid_at` = NOW() 
                 WHERE `payout_id` = ?',
                [$payoutId]
            );

            // به‌روزرسانی درخواست
            Db::q(
                'UPDATE `affiliate_payouts` SET `status` = ?, `completed_at` = NOW() 
                 WHERE `id` = ?',
                ['completed', $payoutId]
            );

            return true;
        } catch (Throwable $e) {
            uptimeLog('error', 'completePayout: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Leaderboard affiliate
     */
    public static function getLeaderboard(int $limit = 20): array
    {
        return Db::all(
            'SELECT a.*, u.name FROM `affiliates` a 
             JOIN `user` u ON u.id = a.user_id
             WHERE a.total_commission > 0
             ORDER BY a.total_commission DESC
             LIMIT ?',
            [$limit]
        );
    }

    /**
     * تولید کد منحصر
     */
    private static function generateCode(int $userId): string
    {
        for ($i = 0; $i < 100; $i++) {
            $code = strtoupper(substr(
                md5($userId . time() . rand(0, 9999)),
                0,
                6
            ));

            $exists = Db::val(
                'SELECT COUNT(*) FROM `affiliates` WHERE `code` = ?',
                [$code]
            );

            if ($exists == 0) {
                return $code;
            }
        }

        return 'AFF' . substr(md5($userId . time()), 0, 9);
    }

    private static function createPayoutRequest(int $affiliateId): void
    {
        // ارسال اطلاع به affiliate
        $affiliate = Db::one(
            'SELECT `user_id` FROM `affiliates` WHERE `id` = ?',
            [$affiliateId]
        );

        if ($affiliate) {
            tgSend(
                $affiliate['user_id'],
                "💰 <b>کمیسیون شما برای پرداخت آماده است</b>\n\n"
                . "مبلغ: " . toLocale((int)Db::val(
                    'SELECT SUM(`commission`) FROM `affiliate_earnings` 
                     WHERE `affiliate_id` = ? AND `paid_at` IS NULL',
                    [$affiliateId]
                )) . " تومان\n\n"
                . "💳 برای درخواست پرداخت به بخش الحاقات مراجعه کنید"
            );
        }
    }
}
