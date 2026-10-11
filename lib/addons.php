<?php
/**
 * ===== افزونه‌ها (Add-ons) =====
 *
 * این فایل دو بخش دارد:
 *   ۱) class Addons   → منطق مشترک: وبهوک، Slack/Discord، تحلیل، SLA، Import/Export
 *      و برنامهٔ همکاری. همهٔ ماژول‌های «افزونه» از همین‌جا صدا زده می‌شوند تا
 *      کد مرده نماند.
 *   ۲) trait AddonsUi → رابط کاربری ربات برای همین افزونه‌ها. به‌صورت یک trait
 *      جدا نگه داشته شده تا بدنهٔ bot.php دست‌نخورده و کم‌تغییر بماند.
 *
 * نکته: هیچ‌کدام از این افزونه‌ها اجباری نیستند؛ اگر جدول‌شان نبود یا خاموش
 * باشند، بی‌صدا رد می‌شوند و هیچ‌وقت راند چک را نمی‌شکنند.
 */

class Addons
{
    /** آیا افزونهٔ مشخصی روشن است؟ (کلید settings — پیش‌فرض روشن) */
    public static function enabled(string $key, bool $default = true): bool
    {
        try { return Db::getBool($key, $default); }
        catch (Throwable $e) { return $default; }
    }

    /** آیا این سایت وبهوک فعال دارد؟ */
    private static function hasWebhooks(int $siteId): bool
    {
        try { return (int)Db::val('SELECT COUNT(*) FROM `webhooks` WHERE `site_id` = ? AND `enabled` = 1', [$siteId]) > 0; }
        catch (Throwable $e) { return false; }
    }

    /** آیا این کاربر Integration فعال دارد؟ */
    private static function hasIntegrations(int $userId): bool
    {
        try { return (int)Db::val('SELECT COUNT(*) FROM `integrations` WHERE `user_id` = ? AND `enabled` = 1', [$userId]) > 0; }
        catch (Throwable $e) { return false; }
    }

    /** نگاشت نوع رویداد داخلی → نوع رویداد Integration (Slack/Discord فقط این سه را می‌فهمند) */
    private static function integrationType(string $type): ?string
    {
        return match ($type) {
            'down' => 'down',
            'up'   => 'up',
            'slow' => 'slow',
            default => null,
        };
    }

    /**
     * یک رویداد سایت (down/up/slow/ssl_warning) → صف وبهوک + Integration.
     * فقط کارهای لازم را enqueue می‌کند (نه برای هر چکِ معمولی).
     */
    public static function siteEvent(array $site, string $type, string $err = '', int $ms = 0): void
    {
        $siteId = (int)($site['id'] ?? 0);
        if ($siteId <= 0) return;
        $uid = (int)($site['user_id'] ?? 0);

        try {
            if (self::enabled('webhook_enabled') && self::hasWebhooks($siteId)) {
                Queue::enqueue('webhook_event', [
                    'site_id' => $siteId,
                    'event'   => ['type' => $type, 'error' => $err, 'ms' => $ms],
                ], 8);
            }
        } catch (Throwable $e) { /* افزونه هرگز نباید راند را بشکند */ }

        $it = self::integrationType($type);
        if ($it === null) return;
        try {
            if ($uid > 0 && self::hasIntegrations($uid)) {
                Queue::enqueue('integration_event', [
                    'user_id'    => $uid,
                    'event_type' => $it,
                    'site'       => [
                        'label'  => (string)($site['label'] ?? ''),
                        'target' => (string)($site['target'] ?? ''),
                    ],
                ], 8);
            }
        } catch (Throwable $e) { /* بی‌اهمیت */ }
    }

    /** هشدار گواهی SSL → وبهوک (نوع ssl_warning) */
    public static function sslEvent(array $site): void
    {
        $siteId = (int)($site['id'] ?? 0);
        if ($siteId <= 0) return;
        try {
            if (self::enabled('webhook_enabled') && self::hasWebhooks($siteId)) {
                Queue::enqueue('webhook_event', [
                    'site_id' => $siteId,
                    'event'   => [
                        'type'  => 'ssl_warning',
                        'error' => 'گواهی SSL نزدیک انقضا/منقضی است',
                        'ms'    => 0,
                    ],
                ], 8);
            }
        } catch (Throwable $e) { /* بی‌اهمیت */ }
    }

    /** یک بار در بازهٔ مشخص اجرا شود (کلید settings) */
    private static function due(string $key, int $seconds): bool
    {
        try {
            $last = (int)Db::get($key, '0');
            if ($last > 0 && (time() - $last) < $seconds) return false;
            Db::set($key, (string)time());
            return true;
        } catch (Throwable $e) { return false; }
    }

    /** گزارش ناهنجاری برای همهٔ کاربران دارای سایت (فقط کاربران اعلان‌دار) */
    public static function sendAnomalyReports(): int
    {
        try {
            $rows = Db::all(
                "SELECT DISTINCT s.`user_id` AS uid
                   FROM `site` s JOIN `user` u ON u.id = s.`user_id`
                  WHERE s.`chat_id` = 0 AND u.`is_blocked` = 0 AND u.`notify` = 1
                  LIMIT 200"
            );
        } catch (Throwable $e) { return 0; }

        $n = 0;
        foreach ($rows as $r) {
            $uid = (int)($r['uid'] ?? 0);
            if ($uid <= 0) continue;
            try { if (AnomalyDetector::reportToUser($uid)) $n++; }
            catch (Throwable $e) { /* بی‌اهمیت */ }
        }
        return $n;
    }

    /** گزارش SLA ماهانه — فقط یک بار در هر ماه */
    public static function maybeMonthlyReports(): int
    {
        $now = date('Y-m');
        try {
            if ((string)Db::get('sla_last_month', '') === $now) return 0;
            Db::set('sla_last_month', $now);
            $rows = Db::all(
                "SELECT `id` FROM `user`
                  WHERE `is_blocked` = 0 AND `id` IN (SELECT `user_id` FROM `site` WHERE `chat_id` = 0)
                  LIMIT 200"
            );
        } catch (Throwable $e) { return 0; }

        $n = 0;
        foreach ($rows as $r) {
            try { if (SLAReport::sendMonthly((int)$r['id'])) $n++; }
            catch (Throwable $e) { /* بی‌اهمیت */ }
        }
        return $n;
    }

    /**
     * کارهای دوره‌ای افزونه‌ها — از cron/checker.php صدا زده می‌شود.
     * @return array{queue:array,anomaly:int,reports:int}
     */
    public static function cron(): array
    {
        $out = ['queue' => ['processed' => 0, 'failed' => 0, 'skipped' => 0], 'anomaly' => 0, 'reports' => 0];

        // ۱) پردازش صف (وبهوک، Integration، گزارش‌ها…)
        try { $out['queue'] = Queue::process(50, 20); } catch (Throwable $e) { /* بی‌اهمیت */ }

        // ۲) گزارش ناهنجاری هر ۶ ساعت
        try {
            if (self::enabled('analytics_enabled') && self::due('anomaly_last_at', 6 * 3600)) {
                $out['anomaly'] = self::sendAnomalyReports();
            }
        } catch (Throwable $e) { /* بی‌اهمیت */ }

        // ۳) گزارش SLA ماهانه
        try {
            if (self::enabled('sla_reports_enabled')) {
                $out['reports'] = self::maybeMonthlyReports();
            }
        } catch (Throwable $e) { /* بی‌اهمیت */ }

        // ۴) پاک‌سازی روزانهٔ صف و وبهوک‌های خراب
        try {
            if (self::due('addons_cleanup_at', 24 * 3600)) {
                Queue::cleanup();
                WebhookManager::cleanupFailed();
            }
        } catch (Throwable $e) { /* بی‌اهمیت */ }

        return $out;
    }

    /** خلاصهٔ وضعیت افزونه‌ها برای پنل مدیر */
    public static function status(): array
    {
        $q = ['pending' => 0, 'processing' => 0, 'failed' => 0, 'total' => 0];
        try { $q = Queue::status(); } catch (Throwable $e) { /* بی‌اهمیت */ }
        $out = ['queue' => $q, 'webhooks' => 0, 'integrations' => 0, 'affiliates' => 0, 'reports' => 0];
        try { $out['webhooks']     = (int)Db::val('SELECT COUNT(*) FROM `webhooks`'); } catch (Throwable $e) {}
        try { $out['integrations'] = (int)Db::val('SELECT COUNT(*) FROM `integrations`'); } catch (Throwable $e) {}
        try { $out['affiliates']   = (int)Db::val('SELECT COUNT(*) FROM `affiliates`'); } catch (Throwable $e) {}
        try { $out['reports']      = (int)Db::val('SELECT COUNT(*) FROM `sla_reports`'); } catch (Throwable $e) {}
        $out['flags'] = [
            'webhook'    => self::enabled('webhook_enabled'),
            'sla'        => self::enabled('sla_reports_enabled'),
            'analytics'  => self::enabled('analytics_enabled'),
            'affiliate'  => self::enabled('affiliate_enabled'),
        ];
        return $out;
    }
}

// ============================================================================
//  رابط کاربری ربات
// ============================================================================

trait AddonsUi
{
    // ---------------------------------------------------------------- ورودی‌ها

    /** دستورهای متنی افزونه‌ها — true اگر مدیریت شد */
    private function addonCommand(string $cmd, array $p): bool
    {
        switch ($p['cmd']) {
            case 'affiliate':
                $this->edit(0, $this->addonAffiliateText(), $this->addonAffiliateMenu());
                return true;
            case 'analyze':
            case 'analyse':
                $this->edit(0, $this->addonAnalysisText(), $this->addonAnalysisMenu());
                return true;
            case 'export':
                $this->edit(0, $this->addonExportText(), $this->addonExportMenu());
                return true;
            case 'import':
                $this->addonImportPrompt(0);
                return true;
            case 'webhooks':
                $this->edit(0, $this->addonWebhooksText(), $this->addonWebhooksMenu());
                return true;
            case 'integrations':
                $this->edit(0, $this->addonIntegrationsText(), $this->addonIntegrationsMenu());
                return true;
        }
        return false;
    }

    /** کالبک‌های افزونه‌ها — true اگر مدیریت شد */
    private function addonCallback(string $act, string $arg, array $parts, int $msgId): bool
    {
        try {
            // دکمه‌های دانلود گزارش SLA که sendMonthly می‌سازد
            if (preg_match('/^report_(html|csv)_(\d+)$/', $act, $m)) {
                $this->addonSendReport((int)$m[2], $m[1]);
                return true;
            }

            switch ($act) {
                case 'feat':
                    $this->edit($msgId, $this->addonSummaryText(), $this->addonMenu());
                    return true;

                // ---------- Import/Export ----------
                case 'exp':
                    $this->edit($msgId, $this->addonExportText(), $this->addonExportMenu());
                    return true;
                case 'expcsv':
                    $this->addonExportFile('csv');
                    return true;
                case 'expjson':
                    $this->addonExportFile('json');
                    return true;
                case 'imp':
                    $this->addonImportPrompt($msgId);
                    return true;

                // ---------- وبهوک ----------
                case 'wh':
                    $this->edit($msgId, $this->addonWebhooksText(), $this->addonWebhooksMenu());
                    return true;
                case 'whadd': {
                    $site = $this->ownSite((int)$arg);
                    if (!$site) { $this->edit($msgId, '❌ سایت پیدا نشد.', $this->addonWebhooksMenu()); return true; }
                    $this->setStep('await_webhook', ['site' => (int)$site['id']]);
                    $this->edit($msgId, "🔔 <b>افزودن وبهوک</b>\n\n"
                        . "آدرس وبهوک را بفرستید (یک URL با <code>https://</code>):\n"
                        . "▪️ Discord: <code>https://discord.com/api/webhooks/...</code>\n"
                        . "▪️ Slack: <code>https://hooks.slack.com/services/...</code>\n"
                        . "▪️ هر سرویس دیگر (JSON خام ارسال می‌شود)\n\n"
                        . "رویدادها: قطعی، برقراری، کندی.\nانصراف: ❌", Nav::cancelKb());
                    return true;
                }
                case 'whtest': {
                    $hook = $this->addonOwnWebhook((int)$arg);
                    if (!$hook) { $this->edit($msgId, '❌ وبهوک پیدا نشد.', $this->addonWebhooksMenu()); return true; }
                    $ok = WebhookManager::test((int)$hook['id']);
                    $this->edit($msgId, ($ok ? '✅ ارسال آزمایشی موفق بود.' : '❌ ارسال آزمایشی ناموفق بود (آدرس/شبکه را بررسی کنید).')
                        . "\n\n" . $this->addonWebhooksText(), $this->addonWebhooksMenu());
                    return true;
                }
                case 'whdel': {
                    Db::q('DELETE FROM `webhooks` WHERE `id` = ? AND `user_id` = ?', [(int)$arg, $this->uid]);
                    Db::logEvent($this->uid, 'webhook_delete', (string)$arg);
                    $this->edit($msgId, "🗑 وبهوک حذف شد.\n\n" . $this->addonWebhooksText(), $this->addonWebhooksMenu());
                    return true;
                }

                // ---------- Slack / Discord ----------
                case 'intg':
                    $this->edit($msgId, $this->addonIntegrationsText(), $this->addonIntegrationsMenu());
                    return true;
                case 'intgset': {
                    $type = $arg === 'discord' ? 'discord' : 'slack';
                    $this->setStep('await_integration', ['type' => $type]);
                    $this->edit($msgId, "🔗 <b>اتصال " . ($type === 'discord' ? 'Discord' : 'Slack') . "</b>\n\n"
                        . "آدرس Incoming Webhook را بفرستید:\n"
                        . ($type === 'discord'
                            ? "<code>https://discord.com/api/webhooks/...</code>"
                            : "<code>https://hooks.slack.com/services/...</code>")
                        . "\n\nانصراف: ❌", Nav::cancelKb());
                    return true;
                }
                case 'intgtest': {
                    $type = $arg === 'discord' ? 'discord' : 'slack';
                    $ok = IntegrationManager::test($this->uid, $type);
                    $this->edit($msgId, ($ok ? '✅ ارسال آزمایشی موفق بود.' : '❌ ارسال ناموفق بود.')
                        . "\n\n" . $this->addonIntegrationsText(), $this->addonIntegrationsMenu());
                    return true;
                }
                case 'intgoff':
                    IntegrationManager::disable($this->uid, $arg === 'discord' ? 'discord' : 'slack');
                    $this->edit($msgId, "🔕 اتصال غیرفعال شد.\n\n" . $this->addonIntegrationsText(), $this->addonIntegrationsMenu());
                    return true;

                // ---------- تحلیل ----------
                case 'anal':
                    $this->edit($msgId, $this->addonAnalysisText(), $this->addonAnalysisMenu());
                    return true;
                case 'anals': {
                    $site = $this->ownSite((int)$arg);
                    if (!$site) { $this->edit($msgId, '❌ سایت پیدا نشد.', $this->addonAnalysisMenu()); return true; }
                    $this->edit($msgId, $this->addonSiteAnalysisText($site), BotApi::ikb([
                        [['text' => '🔙 تحلیل', 'callback_data' => 'anal']],
                        [['text' => '🏠 منو', 'callback_data' => 'menu']],
                    ]));
                    return true;
                }

                // ---------- SLA ----------
                case 'sla':
                    $this->addonGenerateMonthly($msgId);
                    return true;

                // ---------- همکاری ----------
                case 'aff':
                    $this->edit($msgId, $this->addonAffiliateText(), $this->addonAffiliateMenu());
                    return true;
                case 'affreg': {
                    $r = AffiliateProgram::register($this->uid);
                    $this->edit($msgId, (!empty($r['ok']) ? "✅ " . tgH((string)($r['message'] ?? '')) : "❌ خطا: " . tgH((string)($r['error'] ?? '')))
                        . "\n\n" . $this->addonAffiliateText(), $this->addonAffiliateMenu());
                    return true;
                }
                case 'affpayout': {
                    $r = AffiliateProgram::requestPayout($this->uid);
                    $this->edit($msgId, (!empty($r['ok'])
                        ? "✅ درخواست پرداخت ثبت شد.\n💰 مبلغ: " . faNum((int)($r['amount'] ?? 0)) . " تومان"
                        : "⚠️ " . tgH((string)($r['error'] ?? 'خطا')))
                        . "\n\n" . $this->addonAffiliateText(), $this->addonAffiliateMenu());
                    return true;
                }

                // ---------- ادمین: وضعیت سیستم و پرداخت همکاران ----------
                case 'sysstat':
                    if (!$this->requireAdmin($msgId)) return true;
                    $this->edit($msgId, $this->addonSysText(), $this->addonSysMenu());
                    return true;
                case 'qproc':
                    if (!$this->requireAdmin($msgId)) return true;
                    $s = Queue::process(30, 15);
                    $this->edit($msgId, "⚙️ صف پردازش شد: ✅ " . faNum((int)($s['processed'] ?? 0))
                        . " • ❌ " . faNum((int)($s['failed'] ?? 0)) . "\n\n" . $this->addonSysText(), $this->addonSysMenu());
                    return true;
                case 'qclean':
                    if (!$this->requireAdmin($msgId)) return true;
                    Queue::cleanup();
                    WebhookManager::cleanupFailed();
                    $this->edit($msgId, "🧹 پاک‌سازی انجام شد.\n\n" . $this->addonSysText(), $this->addonSysMenu());
                    return true;
                case 'apayouts':
                    if (!$this->requireAdmin($msgId)) return true;
                    $this->edit($msgId, $this->addonPayoutsText(), $this->addonPayoutsMenu());
                    return true;
                case 'apaydone':
                    if (!$this->requireAdmin($msgId)) return true;
                    AffiliateProgram::completePayout((int)$arg);
                    $this->edit($msgId, "✅ پرداخت #" . faNum((int)$arg) . " تکمیل شد.\n\n" . $this->addonPayoutsText(), $this->addonPayoutsMenu());
                    return true;
            }
        } catch (Throwable $e) {
            uptimeLog('error', 'addon callback: ' . $e->getMessage());
            return false;
        }
        return false;
    }

    /** مرحله‌های افزونه‌ها — true اگر مدیریت شد */
    private function addonStep(string $text, array $msg, string $step): bool
    {
        switch ($step) {
            case 'await_webhook': {
                $t = $this->temp();
                $site = $this->ownSite((int)($t['site'] ?? 0));
                if (!$site) { $this->clearStep(); $this->send('❌ سایت پیدا نشد.', $this->addonWebhooksMenu()); return true; }
                $url = trim($text);
                if (!preg_match('#^https?://#i', $url)) {
                    $this->send("❌ آدرس نامعتبر است. یک URL با <code>https://</code> بفرستید.", Nav::cancelKb());
                    return true;
                }
                $type = str_contains($url, 'discord.com/api/webhooks') ? 'discord'
                    : (str_contains($url, 'hooks.slack.com') ? 'slack' : 'custom');
                try {
                    Db::q(
                        'INSERT INTO `webhooks` (`user_id`,`site_id`,`type`,`url`,`events`,`enabled`)
                         VALUES (?,?,?,?,?,1)',
                        [$this->uid, (int)$site['id'], $type, mb_substr($url, 0, 1000), 'down,up,slow,ssl_warning']
                    );
                    Db::logEvent($this->uid, 'webhook_add', $type);
                } catch (Throwable $e) {
                    $this->clearStep();
                    $this->send('❌ ثبت وبهوک ناموفق بود.', $this->addonWebhooksMenu());
                    return true;
                }
                $this->clearStep();
                $this->send("✅ وبهوک ثبت شد.\n\n" . $this->addonWebhooksText(), $this->addonWebhooksMenu());
                return true;
            }

            case 'await_integration': {
                $t = $this->temp();
                $type = ($t['type'] ?? '') === 'discord' ? 'discord' : 'slack';
                $url = trim($text);
                if (!preg_match('#^https?://#i', $url)) {
                    $this->send("❌ آدرس نامعتبر است.", Nav::cancelKb());
                    return true;
                }
                $ok = IntegrationManager::register($this->uid, $type, mb_substr($url, 0, 1000));
                $this->clearStep();
                $this->send(($ok ? "✅ اتصال ثبت شد." : "❌ ثبت ناموفق بود.") . "\n\n" . $this->addonIntegrationsText(), $this->addonIntegrationsMenu());
                return true;
            }

            case 'await_import': {
                $this->clearStep();
                $body = trim($text);
                if ($body === '') { $this->send('❌ محتوایی دریافت نشد.', $this->addonMenu()); return true; }
                $isJson = $body[0] === '{' || $body[0] === '[';
                $res = $isJson
                    ? ImportExport::importJSON($this->uid, $body)
                    : ImportExport::importCSV($this->uid, $body);
                $txt = "📥 <b>نتیجهٔ ورود</b>\n\n"
                    . "▫️ وارد شد: " . faNum((int)($res['imported'] ?? 0)) . "\n";
                if (isset($res['skipped'])) $txt .= "▫️ تکراری/رد‌شده: " . faNum((int)$res['skipped']) . "\n";
                $errs = (array)($res['errors'] ?? []);
                if ($errs) {
                    $txt .= "\n⚠️ خطاها:\n";
                    foreach (array_slice($errs, 0, 5) as $e) $txt .= "• " . tgH(truncateFa((string)$e, 80)) . "\n";
                    if (count($errs) > 5) $txt .= "…\n";
                }
                $this->send($txt, $this->addonMenu());
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------- منوها

    /** دکمهٔ «افزونه‌ها» در منوی تنظیمات */
    private function addonEntryRow(): array
    {
        return [['text' => '🧩 افزونه‌ها', 'callback_data' => 'feat']];
    }

    private function addonMenu(): string
    {
        return BotApi::ikb([
            [['text' => '📤 خروجی سایت‌ها', 'callback_data' => 'exp'], ['text' => '📥 ورود سایت‌ها', 'callback_data' => 'imp']],
            [['text' => '🔔 وبهوک‌ها', 'callback_data' => 'wh'], ['text' => '🔗 Slack / Discord', 'callback_data' => 'intg']],
            [['text' => '🧠 تحلیل هوشمند', 'callback_data' => 'anal'], ['text' => '📄 گزارش SLA ماهانه', 'callback_data' => 'sla']],
            [['text' => '💰 برنامهٔ همکاری', 'callback_data' => 'aff']],
            [['text' => '🔙 بازگشت', 'callback_data' => 'menu']],
        ]);
    }

    private function addonSummaryText(): string
    {
        $n = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ? AND `chat_id` = 0', [$this->uid]);
        $wh = (int)Db::val('SELECT COUNT(*) FROM `webhooks` WHERE `user_id` = ?', [$this->uid]);
        return "🧩 <b>افزونه‌ها</b>\n\n"
            . "ابزارهای پیشرفته برای مانیتورینگ حرفه‌ای:\n\n"
            . "▫️ <b>خروجی/ورود سایت‌ها</b> — پشتیبان‌گیری CSV/JSON و انتقال به‌صورت دستهٔ‌ای\n"
            . "▫️ <b>وبهوک</b> — ارسال رویداد قطعی/برقراری به Discord، Slack یا هر سرویس دیگر\n"
            . "▫️ <b>Slack / Discord</b> — اتصال مستقیم برای هر کاربر\n"
            . "▫️ <b>تحلیل هوشمند</b> — صدک تأخیر، امتیاز پایداری، پیش‌بینی و مقایسه با سایر سایت‌ها\n"
            . "▫️ <b>گزارش SLA</b> — گزارش ماهانه/فصلی/سالانه با خروجی HTML و CSV\n"
            . "▫️ <b>برنامهٔ همکاری</b> — کسب درآمد از معرفی\n\n"
            . "📊 سایت‌های شما: " . faNum($n) . " • وبهوک‌ها: " . faNum($wh);
    }

    // ---------------------------------------------------------------- Import/Export

    private function addonExportText(): string
    {
        return "📤 <b>خروجی سایت‌ها</b>\n\n"
            . "همهٔ مانیتورهای شخصی شما (بدون مانیتورهای گروهی) به‌صورت یک فایل.\n\n"
            . "▪️ CSV — مناسب اکسل و ویرایش دستی\n"
            . "▪️ JSON — مناسب پشتیبان‌گیری کامل و انتقال\n\n"
            . "فایل همین‌جا در چت برای شما ارسال می‌شود.";
    }

    private function addonExportMenu(): string
    {
        return BotApi::ikb([
            [['text' => '📄 خروجی CSV', 'callback_data' => 'expcsv'], ['text' => '🧾 خروجی JSON', 'callback_data' => 'expjson']],
            [['text' => '🔙 افزونه‌ها', 'callback_data' => 'feat']],
        ]);
    }

    private function addonExportFile(string $format): void
    {
        try {
            $content = $format === 'json'
                ? ImportExport::exportJSON($this->uid)
                : ImportExport::exportCSV($this->uid);
        } catch (Throwable $e) {
            $this->send('❌ ساخت خروجی ناموفق بود.', $this->addonExportMenu());
            return;
        }
        $name = 'uptime-sites-' . date('Ymd-His') . '.' . ($format === 'json' ? 'json' : 'csv');
        $res = BotApi::sendDocumentContent($this->token, $this->chatId, $name, $content, '📤 خروجی سایت‌های شما');
        if (empty($res['ok'])) $this->send('❌ ارسال فایل ناموفق بود. دوباره تلاش کنید.', $this->addonExportMenu());
        Db::logEvent($this->uid, 'export_sites', $format);
    }

    private function addonImportPrompt(int $msgId): void
    {
        $this->setStep('await_import');
        $this->edit($msgId,
            "📥 <b>ورود سایت‌ها</b>\n\n"
            . "محتوای فایل <b>CSV</b> یا <b>JSON</b> را همین‌جا بفرستید (کپی/پیست).\n\n"
            . "▪️ CSV: سطر اول سرستون‌ها باشد — <code>URL,Label,Type,MaxMS,Keyword,Enabled</code>\n"
            . "▪️ JSON: خروجی همین بخش «خروجی JSON»\n\n"
            . "سایت‌های موجود دوباره ساخته نمی‌شوند.\nانصراف: ❌",
            Nav::cancelKb());
    }

    // ---------------------------------------------------------------- وبهوک

    private function addonWebhooksText(): string
    {
        $rows = Db::all(
            'SELECT w.*, s.`label`, s.`target` FROM `webhooks` w
               JOIN `site` s ON s.id = w.`site_id`
              WHERE w.`user_id` = ? ORDER BY w.`id` DESC LIMIT 20',
            [$this->uid]
        );
        $txt = "🔔 <b>وبهوک‌ها</b>\n\n"
            . "هر رویداد قطعی/برقراری/کندی به آدرس‌های زیر ارسال می‌شود.\n\n";
        if (!$rows) {
            $txt .= "<i>هنوز وبهوکی ثبت نشده است.</i>\n";
        } else {
            foreach ($rows as $h) {
                $icon = (int)$h['enabled'] === 1 ? '🟢' : '🔴';
                $type = ['discord' => 'Discord', 'slack' => 'Slack', 'custom' => 'سفارشی', 'teams' => 'Teams'][$h['type']] ?? $h['type'];
                $txt .= $icon . " #" . faNum((int)$h['id']) . " — " . $type . " • <code>"
                    . tgH(truncateFa((string)($h['label'] ?: $h['target']), 30)) . "</code>";
                if ((int)$h['fail_count'] > 0) $txt .= " • ⚠️ " . faNum((int)$h['fail_count']) . " خطا";
                $txt .= "\n";
            }
        }
        return $txt;
    }

    private function addonWebhooksMenu(): string
    {
        $rows = [];
        $sites = Db::all('SELECT `id`,`label`,`target` FROM `site` WHERE `user_id` = ? AND `chat_id` = 0 ORDER BY `id` LIMIT 8', [$this->uid]);
        foreach ($sites as $s) {
            $rows[] = [['text' => '➕ ' . mb_substr($s['label'] ?: $s['target'], 0, 26), 'callback_data' => 'whadd:' . $s['id']]];
        }
        $hooks = Db::all('SELECT `id` FROM `webhooks` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 3', [$this->uid]);
        foreach ($hooks as $h) {
            $rows[] = [
                ['text' => '🧪 تست #' . $h['id'], 'callback_data' => 'whtest:' . $h['id']],
                ['text' => '🗑 حذف #' . $h['id'], 'callback_data' => 'whdel:' . $h['id']],
            ];
        }
        $rows[] = [['text' => '🔙 افزونه‌ها', 'callback_data' => 'feat']];
        return BotApi::ikb($rows);
    }

    private function addonOwnWebhook(int $id): ?array
    {
        if ($id <= 0) return null;
        return Db::one('SELECT * FROM `webhooks` WHERE `id` = ? AND `user_id` = ?', [$id, $this->uid]);
    }

    // ---------------------------------------------------------------- Slack/Discord

    private function addonIntegrationsText(): string
    {
        $txt = "🔗 <b>Slack / Discord</b>\n\n"
            . "با اتصال، رویداد قطعی/برقراری/کندی سایت‌هایتان مستقیم به کانال شما می‌رود.\n\n";
        foreach (['slack' => 'Slack', 'discord' => 'Discord'] as $type => $name) {
            $row = Db::one('SELECT * FROM `integrations` WHERE `user_id` = ? AND `type` = ?', [$this->uid, $type]);
            $st = !$row ? '⚪️ متصل نیست' : ((int)$row['enabled'] === 1 ? '🟢 فعال' : '🔴 غیرفعال');
            $txt .= "▫️ <b>{$name}</b>: {$st}\n";
            if ($row) $txt .= "    <code>" . tgH(truncateFa((string)$row['webhook_url'], 48)) . "</code>\n";
        }
        return $txt;
    }

    private function addonIntegrationsMenu(): string
    {
        $rows = [];
        foreach (['slack' => 'Slack', 'discord' => 'Discord'] as $type => $name) {
            $row = Db::one('SELECT * FROM `integrations` WHERE `user_id` = ? AND `type` = ?', [$this->uid, $type]);
            if (!$row) {
                $rows[] = [['text' => '➕ اتصال ' . $name, 'callback_data' => 'intgset:' . $type]];
            } else {
                $rows[] = [
                    ['text' => '🧪 تست ' . $name, 'callback_data' => 'intgtest:' . $type],
                    ['text' => ((int)$row['enabled'] === 1 ? '🔕 غیرفعال' : '➕ تنظیم مجدد'), 'callback_data' => ((int)$row['enabled'] === 1 ? 'intgoff:' : 'intgset:') . $type],
                ];
            }
        }
        $rows[] = [['text' => '🔙 افزونه‌ها', 'callback_data' => 'feat']];
        return BotApi::ikb($rows);
    }

    // ---------------------------------------------------------------- تحلیل

    private function addonAnalysisText(): string
    {
        if (!Addons::enabled('analytics_enabled')) {
            return "🧠 تحلیل هوشمند\n\n⚠️ این بخش توسط مدیر غیرفعال شده است.";
        }
        $sites = Db::all('SELECT * FROM `site` WHERE `user_id` = ? AND `chat_id` = 0 ORDER BY `label`', [$this->uid]);
        if (!$sites) return "🧠 <b>تحلیل هوشمند</b>\n\nهنوز سایتی ثبت نکرده‌اید.";

        $cmp = Analytics::compareUserSites($this->uid);
        $txt = "🧠 <b>تحلیل هوشمند</b>\n\n"
            . "«📊 تحلیل» را از دکمه‌ها انتخاب کنید تا گزارش کامل یک سایت را ببینید.\n"
            . "در گزارش هر سایت: صدک‌های تأخیر، امتیاز پایداری، روند و پیش‌بینی نمایش داده می‌شود.\n\n"
            . "<b>مقایسهٔ سایت‌های شما (۳۰ روز):</b>\n";
        foreach ($cmp as $c) {
            $txt .= statusEmoji('up') . " <code>" . tgH(truncateFa((string)$c['label'], 28)) . "</code> — "
                . faPct($c['uptime_30d'] !== null ? $c['uptime_30d'] : null)
                . " • 📜 " . faNum((int)$c['incidents_30d'])
                . " • ⚡️ " . faMs((int)$c['avg_latency']) . "\n";
        }
        return $txt;
    }

    private function addonAnalysisMenu(): string
    {
        $rows = [];
        foreach (Db::all('SELECT `id`,`label`,`target` FROM `site` WHERE `user_id` = ? AND `chat_id` = 0 ORDER BY `id` LIMIT 8', [$this->uid]) as $s) {
            $rows[] = [['text' => '📊 ' . mb_substr($s['label'] ?: $s['target'], 0, 28), 'callback_data' => 'anals:' . $s['id']]];
        }
        $rows[] = [['text' => '🔙 افزونه‌ها', 'callback_data' => 'feat']];
        return BotApi::ikb($rows);
    }

    private function addonSiteAnalysisText(array $site): string
    {
        if (!Addons::enabled('analytics_enabled')) return "🧠 تحلیل غیرفعال است.";
        $r = Analytics::getSiteReport((int)$site['id']);
        if (empty($r['ok'])) return "🧠 " . tgH((string)($r['error'] ?? 'خطا'));

        $t = $r['trending'];
        $txt = "🧠 <b>تحلیل سایت</b>\n\n"
            . "🔗 <code>" . tgH((string)$r['site']['target']) . "</code>\n\n"
            . "<b>⏱ آپتایم</b>\n"
            . "▫️ ۲۴س: " . faPct($r['uptime']['24h']) . " • ۷روز: " . faPct($r['uptime']['7d']) . " • ۳۰روز: " . faPct($r['uptime']['30d']) . "\n\n"
            . "<b>⚡️ تأخیر</b>\n"
            . "▫️ میانگین: " . faMs($r['latency']['avg']) . " • بیشینه: " . faMs($r['latency']['max']) . " • صدک۹۵: " . faMs($r['latency']['p95']) . "\n"
            . "▫️ صدک‌های ۲۴س — P50: " . faMs($r['latency']['p50_24h']) . " • P95: " . faMs($r['latency']['p95_24h'])
            . " • P99: " . faMs($r['latency']['p99_24h']) . "\n\n"
            . "<b>📈 روند و پایداری</b>\n"
            . "▫️ تأخیر: " . tgH((string)$t['latency']) . " • پایداری: " . tgH((string)$t['reliability'])
            . " • امتیاز پایداری: " . faNum((int)$t['reliability_score']) . "/۱۰۰\n\n"
            . "<b>📜 رخدادها</b>\n"
            . "▫️ ۲۴س: " . faNum((int)$r['incidents']['count_24h'])
            . " • ۷روز: " . faNum((int)$r['incidents']['count_7d'])
            . " • ۳۰روز: " . faNum((int)$r['incidents']['count_30d']) . "\n"
            . "▫️ مجموع قطعی: " . faDuration((int)$r['incidents']['total_downtime_sec'])
            . " • میانگین هر قطعی: " . faDuration((int)$r['incidents']['avg_duration_sec']) . "\n\n"
            . "<b>🔮 پیش‌بینی</b>\n"
            . "▫️ احتمال قطعی بعدی (۷ روز): " . faNum($r['predictions']['next_incident_probability']) . "٪\n"
            . "▫️ آپتایم پیش‌بینی ۳۰ روز آینده: " . faPct($r['predictions']['forecast_uptime_30d']);

        $b = Analytics::benchmark((int)$site['id']);
        if (!empty($b['ok']) && $b['avg_uptime'] !== null) {
            $txt .= "\n\n<b>🏁 مقایسه با سایر سایت‌ها</b>\n"
                . "▫️ میانگین کل: " . faPct($b['avg_uptime']) . " • رتبهٔ شما: " . faNum((int)$b['rank']) . " از " . faNum((int)$b['total_sites']) . "\n"
                . "▫️ " . tgH((string)$b['verdict']);
        }

        $an = AnomalyDetector::analyze((int)$site['id']);
        if (!empty($an['anomalies'])) {
            $txt .= "\n\n<b>⚠️ ناهنجاری‌های اخیر</b>\n";
            foreach (array_slice($an['anomalies'], 0, 4) as $a) {
                $icon = ['critical' => '🔴', 'warning' => '🟠', 'info' => '🔵'][$a['severity'] ?? ''] ?? '⚪';
                $line = match ($a['type']) {
                    'scheduled_downtime' => "قطع منظم حدود ساعت " . ($a['hour'] ?? '?'),
                    'latency_spike'      => "پیک تأخیر " . faMs((int)($a['latency_ms'] ?? 0)),
                    'frequent_failures'  => "ناپایداری شدید (" . ($a['percentage'] ?? '?') . "٪ چک‌های اخیر)",
                    default              => (string)($a['type'] ?? ''),
                };
                $txt .= $icon . " " . tgH($line) . "\n";
            }
        }
        return $txt;
    }

    // ---------------------------------------------------------------- SLA

    private function addonGenerateMonthly(int $msgId): void
    {
        if (!Addons::enabled('sla_reports_enabled')) {
            $this->edit($msgId, "📄 گزارش SLA\n\n⚠️ این بخش توسط مدیر غیرفعال شده است.", $this->addonMenu());
            return;
        }
        $this->edit($msgId, '⏳ در حال ساخت گزارش SLA…');
        $rep = SLAReport::generate($this->uid, 'month');
        if (empty($rep['ok'])) {
            $this->edit($msgId, "❌ " . tgH((string)($rep['error'] ?? 'خطا')), $this->addonMenu());
            return;
        }
        $id = (int)$rep['report_id'];
        $txt = "📄 <b>گزارش SLA (۳۰ روز)</b>\n\n"
            . "▫️ آپتایم متوسط: " . faPct($rep['totals']['uptime_pct']) . "\n"
            . "▫️ تعداد قطعی: " . faNum((int)$rep['totals']['incidents']) . "\n"
            . "▫️ مدت قطع کل: " . faDuration((int)$rep['totals']['downtime_sec']) . "\n"
            . "▫️ میانگین تأخیر: " . faMs((int)$rep['totals']['avg_latency']) . "\n\n"
            . "خروجی کامل (HTML برای چاپ، CSV برای اکسل):";
        $this->edit($msgId, $txt, BotApi::ikb([
            [['text' => '📥 دانلود HTML', 'callback_data' => 'report_html_' . $id], ['text' => '📊 دانلود CSV', 'callback_data' => 'report_csv_' . $id]],
            [['text' => '🔙 افزونه‌ها', 'callback_data' => 'feat']],
        ]));
    }

    /** سرو دکمه‌های report_html_/report_csv_ (قبلاً بی‌مصرف بودند) */
    private function addonSendReport(int $reportId, string $format): void
    {
        $row = Db::one('SELECT * FROM `sla_reports` WHERE `id` = ? AND `user_id` = ?', [$reportId, $this->uid]);
        if (!$row) { $this->send('❌ گزارش پیدا نشد.'); return; }
        $data = json_decode((string)$row['data_json'], true);
        if (!is_array($data)) { $this->send('❌ دادهٔ گزارش خراب است.'); return; }
        if ($format === 'csv') {
            $content = SLAReport::exportCSV($data);
            $name = 'sla-' . $reportId . '.csv';
        } else {
            $content = SLAReport::exportHTML($this->uid, $data);
            $name = 'sla-' . $reportId . '.html';
        }
        $res = BotApi::sendDocumentContent($this->token, $this->chatId, $name, $content, '📄 گزارش SLA');
        if (empty($res['ok'])) $this->send('❌ ارسال فایل ناموفق بود.');
    }

    // ---------------------------------------------------------------- همکاری

    private function addonAffiliateText(): string
    {
        if (!Addons::enabled('affiliate_enabled')) {
            return "💰 <b>برنامهٔ همکاری</b>\n\n⚠️ این بخش توسط مدیر غیرفعال شده است.";
        }
        $d = AffiliateProgram::getDashboard($this->uid);
        if (empty($d['ok'])) {
            return "💰 <b>برنامهٔ همکاری</b>\n\n"
                . "شما هنوز عضو نیستید.\n\n"
                . "با معرفی دوستان، از هر خرید آن‌ها کمیسیون می‌گیرید:\n"
                . "▫️ کمیسیون: ۲۰٪ از هر خرید\n"
                . "▫️ حداقل برداشت: " . faNum(100000) . " تومان\n"
                . "▫️ پرداخت ماهانه\n\n"
                . "برای عضویت «➕ عضویت» را بزنید.";
        }
        $s = $d['stats'];
        return "💰 <b>داشبورد همکاری</b>\n\n"
            . "🎟 کد شما: <code>" . tgH((string)$d['code']) . "</code>\n"
            . "🔗 لینک دعوت:\n<code>" . tgH((string)$d['referral_link']) . "</code>\n\n"
            . "▫️ ثبت‌نام با کد شما: " . faNum((int)$s['signup_count']) . "\n"
            . "▫️ کمیسیون کل: " . faNum((int)$s['total_commission']) . " تومان\n"
            . "▫️ قابل برداشت: " . faNum((int)$s['unpaid_commission']) . " تومان\n"
            . "▫️ پرداخت‌شده: " . faNum((int)$s['paid_commission']) . " تومان\n"
            . "▫️ نرخ کمیسیون: " . faNum((int)$s['commission_rate']) . "٪\n\n"
            . "💡 وضعیت برداشت: " . tgH((string)$d['next_payout']);
    }

    private function addonAffiliateMenu(): string
    {
        if (!Addons::enabled('affiliate_enabled')) return $this->addonMenu();
        $d = AffiliateProgram::getDashboard($this->uid);
        $rows = [];
        if (empty($d['ok'])) {
            $rows[] = [['text' => '➕ عضویت در برنامهٔ همکاری', 'callback_data' => 'affreg']];
        } elseif (($d['payout_status'] ?? '') === 'ready') {
            $rows[] = [['text' => '💳 درخواست پرداخت', 'callback_data' => 'affpayout']];
        }
        $rows[] = [['text' => '🔙 افزونه‌ها', 'callback_data' => 'feat']];
        return BotApi::ikb($rows);
    }

    /** کاربر با لینک /start aff_<code> آمده → ثبت معرفی */
    private function addonAffiliateReferral(string $code): void
    {
        $code = strtoupper(trim($code));
        if ($code === '') { $this->clearStep(); $this->send($this->welcome(), $this->mainMenu()); return; }
        try {
            $exists = (int)Db::val('SELECT COUNT(*) FROM `affiliates` WHERE `code` = ?', [$code]);
            if ($exists > 0) {
                Db::set('ref_' . $this->uid, $code);
                AffiliateProgram::trackSignup($code);
            }
        } catch (Throwable $e) { /* بی‌اهمیت */ }
        $this->clearStep();
        $this->send("🎉 خوش آمدید!\n\n" . $this->welcome(), $this->mainMenu());
    }

    // ---------------------------------------------------------------- ادمین: وضعیت

    private function addonSysText(): string
    {
        $s = Addons::status();
        $q = $s['queue'];
        $f = $s['flags'];
        $sw = static fn($b) => $b ? '🟢' : '🔴';
        return "🧩 <b>وضعیت افزونه‌ها</b>\n\n"
            . "<b>⚙️ صف کارها</b>\n"
            . "▫️ در انتظار: " . faNum((int)$q['pending']) . " • در حال پردازش: " . faNum((int)$q['processing'])
            . " • ناموفق: " . faNum((int)$q['failed']) . "\n\n"
            . "<b>🔌 اتصال‌ها</b>\n"
            . "▫️ وبهوک: " . faNum((int)$s['webhooks']) . " • Slack/Discord: " . faNum((int)$s['integrations']) . "\n"
            . "▫️ گزارش SLA: " . faNum((int)$s['reports']) . " • همکاران: " . faNum((int)$s['affiliates']) . "\n\n"
            . "<b>🎛 کلید افزونه‌ها</b>\n"
            . "▫️ وبهوک: {$sw($f['webhook'])} • SLA: {$sw($f['sla'])}\n"
            . "▫️ تحلیل: {$sw($f['analytics'])} • همکاری: {$sw($f['affiliate'])}";
    }

    private function addonSysMenu(): string
    {
        return BotApi::ikb([
            [['text' => '⚙️ پردازش صف', 'callback_data' => 'qproc'], ['text' => '🧹 پاک‌سازی', 'callback_data' => 'qclean']],
            [['text' => '💰 پرداخت همکاران', 'callback_data' => 'apayouts']],
            [['text' => '🔙 پنل مدیریت', 'callback_data' => 'apanel']],
        ]);
    }

    private function addonPayoutsText(): string
    {
        $rows = Db::all("SELECT * FROM `affiliate_payouts` WHERE `status` = 'pending' ORDER BY `id` DESC LIMIT 15");
        $txt = "💰 <b>درخواست‌های پرداخت همکاران</b>\n\n";
        if (!$rows) { $txt .= "<i>درخواست در انتظاری وجود ندارد.</i>"; return $txt; }
        foreach ($rows as $r) {
            $u = (int)Db::val('SELECT `user_id` FROM `affiliates` WHERE `id` = ?', [(int)$r['affiliate_id']]);
            $name = (string)Db::val('SELECT `name` FROM `user` WHERE `id` = ?', [$u]);
            $txt .= "▫️ #" . faNum((int)$r['id']) . " — " . tgH(truncateFa($name, 24)) . " (<code>{$u}</code>) — "
                . faNum((int)$r['amount']) . " تومان\n";
        }
        return $txt;
    }

    private function addonPayoutsMenu(): string
    {
        $rows = [];
        foreach (Db::all("SELECT * FROM `affiliate_payouts` WHERE `status` = 'pending' ORDER BY `id` DESC LIMIT 12") as $r) {
            $rows[] = [['text' => '✅ تسویه #' . $r['id'], 'callback_data' => 'apaydone:' . $r['id']]];
        }
        $rows[] = [['text' => '🔙 وضعیت افزونه‌ها', 'callback_data' => 'sysstat']];
        return BotApi::ikb($rows);
    }
}
