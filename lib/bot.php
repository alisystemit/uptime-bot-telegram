<?php
/**
 * ===== منطق ربات تلگرامی مانیتورینگ سرور =====
 * شامل: منوها، وضعیت‌ها (States)، مدیریت سایت‌ها، آمار، پنل ادمین،
 * سقف کاربران، اشتراک ویژه (کد فعال‌سازی / پرداخت)، پیام همگانی، توقف موقت چک.
 */
class Bot
{
    private array $cfg;
    private string $token;
    private ?array $u = null;
    private int $uid = 0;
    /** @var mixed */
    private $chatId = 0;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $this->token = (string)($cfg['bot_token'] ?? '');
    }

    // ================================================================ ورودی

    public function handle(array $update): void
    {
        if (!empty($update['callback_query'])) {
            $this->onCallback($update['callback_query']);
            return;
        }
        $msg = $update['message'] ?? null;
        if (!$msg) return;
        if (($msg['chat']['type'] ?? 'private') !== 'private') return;
        $from = $msg['from'] ?? null;
        if (!$from) return;
        $this->onMessage($msg, $from);
    }

    // ================================================================ کاربر

    private function ensureUser(array $from): ?array
    {
        $uid = (int)$from['id'];
        $this->uid = $uid;
        $name = (string)($from['first_name'] ?? '') . ' ' . (string)($from['last_name'] ?? '');
        $username = (string)($from['username'] ?? '');

        $row = Db::one('SELECT * FROM `user` WHERE `id` = ?', [$uid]);
        if ($row) {
            Db::q('UPDATE `user` SET `name` = ?, `username` = ?, `last_seen` = NOW() WHERE `id` = ?', [trim($name), $username, $uid]);
            $row['name'] = trim($name);
            $row['username'] = $username;
            $this->u = $row;
            return $row;
        }

        // ---- کاربر تازه ----
        $isAdmin = isBotAdmin($uid);
        $maxUsers = Db::getInt('max_users', 0);
        if (!$isAdmin && $maxUsers > 0) {
            $total = (int)Db::val('SELECT COUNT(*) FROM `user` WHERE `is_admin` = 0');
            if ($total >= $maxUsers) {
                $this->u = null;
                return null; // ظرفیت تکمیل
            }
        }
        $mode = Db::get('access_mode', 'open');
        $access = ($isAdmin || $mode === 'open') ? 1 : 0;
        $token = self::uniqueShareToken();

        Db::q(
            'INSERT INTO `user` (`id`,`name`,`username`,`is_admin`,`access`,`share_token`,`step`,`temp`,`created_at`,`last_seen`)
             VALUES (?,?,?,?,?,?,\'idle\',NULL,NOW(),NOW())',
            [$uid, trim($name), $username, $isAdmin ? 1 : 0, $access, $token]
        );
        $row = Db::one('SELECT * FROM `user` WHERE `id` = ?', [$uid]);
        $this->u = $row;
        if ($row) Db::logEvent($uid, 'register', $isAdmin ? 'admin' : 'user');
        return $row;
    }

    private static function uniqueShareToken(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $t = makeShareToken(20);
            $exists = Db::val('SELECT COUNT(*) FROM `user` WHERE `share_token` = ?', [$t]);
            if ((int)$exists === 0) return $t;
        }
        return makeShareToken(28);
    }

    /** آیا کاربر دسترسی کامل دارد؟ */
    private function allowed(): bool
    {
        if (!$this->u) return false;
        if ((int)$this->u['is_blocked'] === 1) return false;
        if (isBotAdmin($this->uid)) return true;
        return (int)$this->u['access'] === 1;
    }

    /** انقضای اشتراک ویژه */
    private function syncPlan(): void
    {
        if (!$this->u) return;
        $u = $this->u;
        if ($u['plan'] === 'vip' && !empty($u['plan_until']) && strtotime($u['plan_until']) < time()) {
            Db::q("UPDATE `user` SET `plan` = 'free' WHERE `id` = ?", [$this->uid]);
            $this->u['plan'] = 'free';
            Db::logEvent($this->uid, 'plan_expired', '');
            if (!isBotAdmin($this->uid) && Db::get('access_mode', 'open') !== 'open') {
                Db::q('UPDATE `user` SET `access` = 0 WHERE `id` = ?', [$this->uid]);
                $this->u['access'] = 0;
            }
            tgSend($this->uid, "⌛️ اشتراک ویژهٔ شما منقضی شد.\nبرای ادامهٔ استفاده، اشتراک را تمدید کنید.", ['reply_markup' => $this->subMenu()]);
        }
    }

    private function isVip(): bool
    {
        if (!$this->u) return false;
        return $this->u['plan'] === 'vip' && (empty($this->u['plan_until']) || strtotime($this->u['plan_until']) >= time());
    }

    private function maxSites(): int
    {
        return $this->isVip() ? Db::getInt('vip_max_sites', 10) : Db::getInt('max_sites', 5);
    }

    private function setStep(string $step, array $temp = []): void
    {
        Db::q('UPDATE `user` SET `step` = ?, `temp` = ? WHERE `id` = ?', [$step, $temp ? json_encode($temp, JSON_UNESCAPED_UNICODE) : null, $this->uid]);
        if ($this->u) { $this->u['step'] = $step; $this->u['temp'] = $temp ? json_encode($temp, JSON_UNESCAPED_UNICODE) : null; }
    }

    private function clearStep(): void
    {
        $this->setStep('idle', []);
    }

    private function temp(): array
    {
        if (!$this->u) return [];
        $t = json_decode((string)($this->u['temp'] ?? ''), true);
        return is_array($t) ? $t : [];
    }

    // ================================================================ پیام

    private function onMessage(array $msg, array $from): void
    {
        $text = trim((string)($msg['text'] ?? ''));
        $this->chatId = $msg['chat']['id'];

        $user = $this->ensureUser($from);
        if ($user === null) {
            tgSend($this->chatId, "⛔️ ظرفیت ربات تکمیل است.\n\nتعداد کاربران به سقف تعیین‌شده رسیده است؛ لاحقاً دوباره تلاش کنید یا با مدیر تماس بگیرید.");
            return;
        }
        if ((int)$user['is_blocked'] === 1) {
            tgSend($this->chatId, "⛔️ دسترسی شما توسط مدیر مسدود شده است.");
            return;
        }
        $this->syncPlan();

        // بدون متن (عکس/فایل/استیکر) — فقط در صورت نیاز به پرداخت پذیرفته می‌شود
        if ($text === '' && !isset($msg['text'])) {
            if ($user['step'] === 'await_payment') {
                $this->onPaymentProof($msg);
                return;
            }
            return;
        }

        $step = $user['step'] ?? 'idle';
        if ($step !== 'idle' && $this->handleStep($text, $msg, $step)) return;

        $this->handleCommand($text, $msg);
    }

    private function handleCommand(string $text, array $msg): void
    {
        $cmd = str_replace(botUsername(), '', $text);
        $cmd = trim($cmd);

        switch ($cmd) {
            case '/start':
            case '🏠 منو':
                $this->clearStep();
                BotApi::setMyCommands($this->token, [
                    ['command' => 'start', 'description' => '🏠 منوی اصلی'],
                    ['command' => 'sites', 'description' => '📋 سایت‌های من'],
                    ['command' => 'report', 'description' => '📈 گزارش آپتایم'],
                    ['command' => 'help', 'description' => 'ℹ️ راهنما'],
                ]);
                if (!$this->allowed()) {
                    $this->send($this->welcome() . "\n\n⏳ <b>حساب شما هنوز فعال نشده است.</b>\nبرای فعال‌سازی، یکی از گزینه‌های زیر را بزنید.", $this->restrictedMenu());
                    return;
                }
                $this->send($this->welcome(), $this->mainMenu());
                return;
            case '/help':
            case 'ℹ️ راهنما':
                $this->clearStep();
                $this->send($this->helpText(), $this->mainMenu());
                return;
            case '/sites':
            case '📋 سایت‌های من':
                $this->clearStep();
                $this->sendSitesList();
                return;
            case '/report':
            case '📈 گزارش من':
                $this->clearStep();
                $this->send($this->reportText(), $this->mainMenu());
                return;
        }

        if (!$this->allowed()) {
            $this->send("⏳ حساب شما هنوز فعال نشده است.\n\n"
                . "برای استفاده از ربات، اشتراک ویژه را فعال کنید:\n"
                . "▫️ «🛒 اشتراک ویژه» → پرداخت یا ورود کد فعال‌سازی\n\n"
                . "پس از فعال‌سازی می‌توانید تا " . faNum(Db::getInt('max_sites', 5)) . " سایت ثبت کنید.",
                $this->restrictedMenu());
            return;
        }

        switch ($text) {
            case '➕ افزودن سایت':
                $this->startAddSite();
                return;
            case '🔗 صفحهٔ وضعیت من':
                $this->send($this->shareText(), $this->shareMenu());
                return;
            case '🛒 اشتراک ویژه':
                $this->send($this->subText(), $this->subMenu());
                return;
            case '⚙️ تنظیمات':
                $this->send($this->settingsText(), $this->settingsMenu());
                return;
            case '⏸ توقف چک‌ها':
                $this->pauseUser();
                return;
            case '▶️ ادامهٔ چک‌ها':
                $this->resumeUser();
                return;
            // ---- ادمین ----
            case '📊 آمار مدیریتی':
                if (!$this->isAdmin()) { $this->unknown(); return; }
                $this->send($this->adminStatsText(), $this->adminMenu());
                return;
            case '📣 همگانی':
                if (!$this->isAdmin()) { $this->unknown(); return; }
                $this->setStep('await_broadcast');
                $this->send("📣 پیام همگانی را بفرستید (متن، عکس، فایل…).\n\nپیام برای همهٔ کاربران ارسال می‌شود.\nانصراف: ❌ انصراف", BotApi::kb([[['text' => '❌ انصراف']]]));
                return;
            case '👥 کاربران':
                if (!$this->isAdmin()) { $this->unknown(); return; }
                $this->sendUsersList(0);
                return;
            case '⚙️ پنل مدیریت':
                if (!$this->isAdmin()) { $this->unknown(); return; }
                $this->send($this->adminPanelText(), $this->adminPanelMenu());
                return;
            case '⏰ کرون چک':
                if (!$this->isAdmin()) { $this->unknown(); return; }
                $this->send($this->cronText(), $this->adminMenu());
                return;
            case '💳 پرداخت‌ها':
                if (!$this->isAdmin()) { $this->unknown(); return; }
                $this->sendPayments();
                return;
            case '❌ انصراف':
                $this->clearStep();
                $this->send("انصراف داده شد.", $this->mainMenu());
                return;
        }
        $this->unknown();
    }

    private function unknown(): void
    {
        $this->send("🤔 دستور نامشخص است.\nاز دکمه‌های منو استفاده کنید یا /help بزنید.", $this->mainMenu());
    }

    private function isAdmin(): bool
    {
        return isBotAdmin($this->uid);
    }

    // ================================================================ مرحله‌ها

    /** @return bool آیا پیام به‌عنوان ورودیِ یک مرحله پردازش شد؟ */
    private function handleStep(string $text, array $msg, string $step): bool
    {
        switch ($step) {
            case 'await_site':
                $this->stepAddSite($text);
                return true;

            case 'await_code':
                $this->stepRedeemCode($text);
                return true;

            case 'await_payment':
                if ($text === '❌ انصراف') { $this->clearStep(); $this->send('انصراف شد.', $this->subMenu()); return true; }
                $this->onPaymentProof($msg);
                return true;

            case 'await_broadcast':
                if ($text === '❌ انصراف') { $this->clearStep(); $this->send('انصراف شد.', $this->mainMenu()); return true; }
                $this->doBroadcast($msg);
                return true;

            case 'await_setting':
                $this->stepSetting($text);
                return true;

            case 'await_codegen':
                $this->stepCodeGen($text);
                return true;

            case 'await_userop':
                $this->stepUserOp($text);
                return true;
        }
        $this->clearStep();
        return false;
    }

    // --------------------------- افزودن سایت

    private function startAddSite(): void
    {
        $count = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ?', [$this->uid]);
        $max = $this->maxSites();
        if ($count >= $max) {
            $this->send("⛔️ سقف سایت‌های شما تکمیل است.\n\nسایت‌های ثبت‌شده: " . faNum($count) . " از " . faNum($max) . "\n"
                . ($this->isVip() ? '' : "با اشتراک ویژه سقف شما به " . faNum(Db::getInt('vip_max_sites', 10)) . " سایت می‌رسد.")
                . "\n\nبرای حذف یک سایت، «📋 سایت‌های من» را بزنید.", $this->mainMenu());
            return;
        }
        $this->setStep('await_site');
        $this->send(
            "🔗 <b>آدرس سایت یا سرور را بفرستید</b>\n\n"
            . "نمونه‌ها:\n"
            . "▪️ <code>https://example.com</code> — بررسی صفحه (HTTP)\n"
            . "▪️ <code>example.com</code> — پینگ (ICMP)\n"
            . "▪️ <code>example.com:8080</code> — اتصال پورت (TCP)\n"
            . "▪️ <code>192.168.1.10</code> — پینگ IP\n"
            . "▪️ <code>1.2.3.4:22</code> — بررسی پورت SSH\n\n"
            . "سایت‌های شما: " . faNum($count) . " از " . faNum($max) . "\n"
            . "انصراف: ❌ انصراف",
            BotApi::kb([[['text' => '❌ انصراف']]])
        );
    }

    private function stepAddSite(string $text): void
    {
        if ($text === '❌ انصراف' || $text === '/start' || $text === '🏠 منو') {
            $this->clearStep();
            $this->send('انصراف شد.', $this->mainMenu());
            return;
        }
        $norm = normalizeTarget($text);
        if (!$norm['ok']) {
            $this->send("❌ " . $norm['error'] . "\n\nدوباره تلاش کنید یا ❌ انصراف بزنید.", BotApi::kb([[['text' => '❌ انصراف']]]));
            return;
        }
        $count = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ?', [$this->uid]);
        if ($count >= $this->maxSites()) {
            $this->clearStep();
            $this->send('⛔️ سقف سایت‌های شما تکمیل است.', $this->mainMenu());
            return;
        }
        $dup = Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ? AND `target` = ?', [$this->uid, $norm['target']]);
        if ((int)$dup > 0) {
            $this->send("⚠️ این سایت قبلاً ثبت شده است.\nآدرس دیگری بفرستید یا ❌ انصراف بزنید.", BotApi::kb([[['text' => '❌ انصراف']]]));
            return;
        }
        try {
            Db::q(
                'INSERT INTO `site` (`user_id`,`target`,`label`,`type`,`host`,`port`,`created_at`) VALUES (?,?,?,?,?,?,NOW())',
                [$this->uid, $norm['target'], mb_substr($norm['label'], 0, 200), $norm['type'], $norm['host'], (int)$norm['port']]
            );
        } catch (Throwable $e) {
            $this->send("❌ خطا در ثبت سایت. دوباره تلاش کنید.", $this->mainMenu());
            return;
        }
        $siteId = (int)Db::val('SELECT `id` FROM `site` WHERE `user_id` = ? AND `target` = ?', [$this->uid, $norm['target']]);
        $this->clearStep();

        // اولین چک همان لحظه تا کاربر نتیجه را ببیند
        $res = Monitor::checkSite($siteId);
        $r = $res['result'] ?? ['ok' => false, 'error' => '—'];
        $site = Db::one('SELECT * FROM `site` WHERE `id` = ?', [$siteId]);

        $txt = "✅ <b>سایت ثبت شد</b>\n\n"
            . "🔗 <code>" . tgH($norm['target']) . "</code>\n"
            . "🧭 نوع: " . typeName($norm['type']) . "\n"
            . "⏱ فاصلهٔ چک: هر " . faNum(max(10, Db::getInt('check_interval', 20))) . " ثانیه\n\n"
            . "نتیجهٔ نخستین بررسی: " . (!empty($r['ok']) ? "🟢 <b>فعال</b>" : "🔴 <b>قطع</b>") . "\n"
            . ($r['ok'] ? "⚡️ زمان پاسخ: " . faMs((int)$r['ms']) : "❌ دلیل: " . tgH($r['error'] ?? '')) . "\n\n"
            . "سایت‌های شما: " . faNum((int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ?', [$this->uid])) . " از " . faNum($this->maxSites());
        $this->send($txt, $site ? BotApi::ikb([[['text' => '📜 جزئیات سایت', 'callback_data' => 'site:' . $siteId]]]) : $this->mainMenu());
    }

    // --------------------------- کد فعال‌سازی

    private function stepRedeemCode(string $text): void
    {
        if ($text === '❌ انصراف') { $this->clearStep(); $this->send('انصراف شد.', $this->mainMenu()); return; }
        $code = strtoupper(trim($text));
        $code = preg_replace('/[^A-Z0-9\-]/', '', $code) ?? $code;
        $row = Db::one('SELECT * FROM `codes` WHERE `code` = ?', [$code]);
        if (!$row || (int)$row['uses'] >= (int)$row['uses_max']) {
            $this->send("❌ کد نامعتبر یا منقضی/مصرف‌شده است.\n\nدوباره تلاش کنید:", BotApi::kb([[['text' => '❌ انصراف']]]));
            return;
        }
        $days = max(1, (int)$row['days']);
        $this->grantVip($days);
        Db::q('UPDATE `codes` SET `uses` = `uses` + 1 WHERE `code` = ?', [$code]);
        Db::logEvent($this->uid, 'redeem', $code);
        $this->clearStep();
        $this->send("🎉 <b>اشتراک ویژه فعال شد</b>\n\n"
            . "📆 مدت: " . faNum($days) . " روز\n"
            . "📅 تا تاریخ: " . faDateTime(date('Y-m-d H:i:s', time() + $days * 86400)) . "\n"
            . "🌐 سقف سایت‌ها: " . faNum(Db::getInt('vip_max_sites', 10)) . " سایت\n\n"
            . "از این پس از همهٔ امکانات استفاده کنید.", $this->mainMenu());
    }

    private function grantVip(int $days): void
    {
        $base = time();
        $cur = Db::val('SELECT `plan_until` FROM `user` WHERE `id` = ?', [$this->uid]);
        if (!empty($cur) && strtotime($cur) > time()) $base = strtotime($cur);
        $until = date('Y-m-d H:i:s', $base + $days * 86400);
        Db::q("UPDATE `user` SET `plan` = 'vip', `plan_until` = ?, `access` = 1 WHERE `id` = ?", [$until, $this->uid]);
        if ($this->u) { $this->u['plan'] = 'vip'; $this->u['plan_until'] = $until; $this->u['access'] = 1; }
    }

    // --------------------------- پرداخت

    private function onPaymentProof(array $msg): void
    {
        $photo = null;
        if (!empty($msg['photo'])) {
            $sizes = $msg['photo'];
            usort($sizes, fn($a, $b) => ($b['width'] ?? 0) <=> ($a['width'] ?? 0));
            $photo = $sizes[0]['file_id'] ?? null;
        } elseif (!empty($msg['document']['file_id'])) {
            $photo = $msg['document']['file_id'];
        } elseif (!empty($msg['text'])) {
            $photo = '';
        }
        if ($photo === null) {
            tgSend($this->chatId, "لطفاً تصویر فیش/رسید پرداخت را بفرستید (یا متن توضیح).", ['reply_markup' => BotApi::kb([[['text' => '❌ انصراف']]])]);
            return;
        }
        $amount = Db::getInt('price', 0);
        Db::q(
            'INSERT INTO `payments` (`user_id`,`amount`,`status`,`note`,`media_file_id`,`created_at`) VALUES (?,?,?,?,?,NOW())',
            [$this->uid, $amount, 'pending', 'درخواست اشتراک از ربات', is_string($photo) ? $photo : '']
        );
        $payId = (int)Db::val('SELECT MAX(`id`) FROM `payments` WHERE `user_id` = ?', [$this->uid]);
        Db::logEvent($this->uid, 'payment_pending', (string)$payId);
        $this->clearStep();

        tgSend($this->chatId, "✅ درخواست شما ثبت شد و برای بررسی به مدیر ارسال شد.\nپس از تأیید، اشتراک ویژهٔ شما فعال می‌شود.", $this->mainMenu());

        $adminTxt = "🧾 <b>درخواست پرداخت جدید</b>\n\n"
            . "👤 کاربر: " . tgH($this->u['name'] ?? '') . " (<code>{$this->uid}</code>)\n"
            . ($this->u['username'] ? "🆔 @" . tgH($this->u['username']) . "\n" : '')
            . "💰 مبلغ: " . faMoney($amount) . "\n"
            . "🗓 زمان: " . faDateTime(date('Y-m-d H:i:s')) . "\n"
            . "💳 شماره کارت: <code>" . tgH(Db::get('card', '—')) . "</code>";
        $kb = BotApi::ikb([
            [['text' => '✅ تأیید و فعال‌سازی', 'callback_data' => 'apayok:' . $payId]],
            [['text' => '❌ رد درخواست', 'callback_data' => 'apayno:' . $payId]],
        ]);
        foreach (botAdminIds() as $aid) {
            if (is_string($photo) && $photo !== '') {
                BotApi::sendPhoto($this->token, $aid, $photo, $adminTxt, $kb);
            } else {
                tgSend($aid, $adminTxt, ['reply_markup' => $kb]);
            }
        }
    }

    // --------------------------- توقف/ادامه

    private function pauseUser(): void
    {
        if ((int)$this->u['paused'] === 1) { $this->send('همین حالا هم متوقف است.', $this->mainMenu()); return; }
        Db::q('UPDATE `user` SET `paused` = 1, `paused_since` = NOW() WHERE `id` = ?', [$this->uid]);
        Db::logEvent($this->uid, 'pause_user', '');
        $this->send("⏸ <b>چک سایت‌های شما متوقف شد</b>\n\n"
            . "تا زمانی که «▶️ ادامهٔ چک‌ها» را نزنید، سایت‌های شما بررسی نمی‌شوند و آپتایم ثبت نخواهد شد.\n"
            . "مدت توقف در آمار شما لحاظ می‌شود.", $this->mainMenu());
    }

    private function resumeUser(): void
    {
        if ((int)$this->u['paused'] === 0) { $this->send('همین حالا فعال است.', $this->mainMenu()); return; }
        $since = $this->u['paused_since'] ? strtotime($this->u['paused_since']) : time();
        $dur = max(0, time() - $since);
        Db::q('UPDATE `user` SET `paused` = 0, `paused_since` = NULL, `paused_total` = `paused_total` + ? WHERE `id` = ?', [$dur, $this->uid]);
        Db::logEvent($this->uid, 'resume_user', (string)$dur);
        $this->send("▶️ <b>چک سایت‌های شما ادامه یافت</b>\n\n⌛️ مدت توقف: " . faDuration($dur), $this->mainMenu());
    }

    // --------------------------- تنظیمات ادمین

    private function stepSetting(string $text): void
    {
        if ($text === '❌ انصراف') { $this->clearStep(); $this->send('انصراف شد.', $this->adminPanelMenu()); return; }
        $t = $this->temp();
        $key = (string)($t['key'] ?? '');
        if ($key === '') { $this->clearStep(); $this->unknown(); return; }

        if ($key === 'card') {
            Db::set('card', mb_substr(trim($text), 0, 19));
            $this->clearStep();
            $this->send("✅ شماره کارت ذخیره شد:\n<code>" . tgH(Db::get('card', '')) . "</code>", $this->adminPanelMenu());
            return;
        }
        $rules = [
            'max_users' => [0, 100000, 'سقف کاربران (۰ = نامحدود)'],
            'max_sites' => [1, 100, 'سقف سایت هر کاربر عادی'],
            'vip_max_sites' => [1, 100, 'سقف سایت هر کاربر ویژه'],
            'check_interval' => [10, 3600, 'فاصلهٔ چک به ثانیه (کمینه ۱۰)'],
            'fail_threshold' => [1, 20, 'آستانهٔ هشدار قطعی (چک ناموفق پیاپی)'],
            'price' => [0, 1000000000, 'قیمت اشتراک به تومان'],
            'vip_days' => [1, 3650, 'مدت اشتراک ویژه به روز'],
        ];
        if (!isset($rules[$key])) { $this->clearStep(); $this->unknown(); return; }
        $num = preg_replace('/[۰-۹]/', fn($m) => (string)(strpos('۰۱۲۳۴۵۶۷۸۹', $m[0]) % 10), trim($text));
        $num = preg_replace('/[^\d]/', '', (string)$num);
        if ($num === '') { $this->send('⛔️ لطفاً یک عدد وارد کنید:', BotApi::kb([[['text' => '❌ انصراف']]])); return; }
        [$min, $max, $label] = $rules[$key];
        $v = (int)$num;
        if ($v < $min || $v > $max) {
            $this->send("⛔️ مقدار باید بین " . faNum($min) . " و " . faNum($max) . " باشد.\n" . $label, BotApi::kb([[['text' => '❌ انصراف']]]));
            return;
        }
        Db::set($key, (string)$v);
        Db::logEvent($this->uid, 'setting', $key . '=' . $v);
        $this->clearStep();
        $this->send("✅ <b>{$label}</b> به " . faNum($v) . " تغییر کرد.", $this->adminPanelMenu());
    }

    private function stepCodeGen(string $text): void
    {
        if ($text === '❌ انصراف') { $this->clearStep(); $this->send('انصراف شد.', $this->adminPanelMenu()); return; }
        $parts = preg_split('/[\s,x×]+/u', trim($text)) ?: [];
        $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
        $days = isset($parts[0]) ? (int)preg_replace('/\D/', '', $parts[0]) : 0;
        $count = isset($parts[1]) ? (int)preg_replace('/\D/', '', $parts[1]) : 0;
        if ($days < 1 || $days > 3650 || $count < 1 || $count > 50) {
            $this->send("⛔️ فرمت درست: <code>30 5</code>\n\nیعنی: کدهای ۳۰ روزه، تعداد ۵\n(حداکثر ۵۰ کد در هر نوبت)", BotApi::kb([[['text' => '❌ انصراف']]]));
            return;
        }
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            do {
                $c = 'UP-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 4)) . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 4));
                $exists = (int)Db::val('SELECT COUNT(*) FROM `codes` WHERE `code` = ?', [$c]);
            } while ($exists > 0);
            Db::q('INSERT INTO `codes` (`code`,`days`,`uses`,`uses_max`,`note`,`created_at`) VALUES (?,?,0,1,?,NOW())', [$c, $days, 'ساخت ادمین']);
            $codes[] = $c;
        }
        Db::logEvent($this->uid, 'gen_codes', $count . 'x' . $days);
        $this->clearStep();
        $list = implode("\n", array_map(fn($c) => '▪️ <code>' . $c . '</code>', $codes));
        $this->send("🎟 <b>" . faNum(count($codes)) . " کد فعال‌سازی ساخته شد</b> (هر کدام " . faNum($days) . " روز):\n\n" . $list, $this->adminPanelMenu());
    }

    private function stepUserOp(string $text): void
    {
        $this->clearStep();
        $this->sendUsersList(0);
    }

    // --------------------------- پیام همگانی

    private function doBroadcast(array $msg): void
    {
        $this->clearStep();
        if (!$this->isAdmin()) return;

        @set_time_limit(0);
        @ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

        $ids = Db::all('SELECT `id` FROM `user` WHERE `is_blocked` = 0');
        $ids = array_map(fn($r) => (int)$r['id'], $ids);
        $ok = 0;
        $total = count($ids);
        foreach (array_chunk($ids, 8) as $chunk) {
            foreach ($chunk as $id) {
                $r = BotApi::call($this->token, 'copyMessage', [
                    'chat_id' => $id,
                    'from_chat_id' => $this->chatId,
                    'message_id' => $msg['message_id'] ?? 0,
                ]);
                if (!empty($r['ok'])) $ok++;
                usleep(60000);
            }
        }
        Db::logEvent($this->uid, 'broadcast', "{$ok}/{$total}");
        // پس از اتمام، ادمین گزارش می‌گیرد (اگر هنوز اتصال باز است)
        try { tgSend($this->chatId, "✅ پیام همگانی ارسال شد: " . faNum($ok) . " از " . faNum($total)); } catch (Throwable $e) { }
    }

    // ================================================================ کالبک

    private function onCallback(array $cb): void
    {
        $data = (string)($cb['data'] ?? '');
        $from = $cb['from'] ?? [];
        $this->chatId = $cb['message']['chat']['id'] ?? ($from['id'] ?? 0);
        $msgId = (int)($cb['message']['message_id'] ?? 0);
        BotApi::answerCb($this->token, (string)$cb['id']);

        $user = $this->ensureUser($from);
        if ($user === null) { $this->send('⛔️ ظرفیت ربات تکمیل است.'); return; }
        if ((int)$user['is_blocked'] === 1) { $this->send('⛔️ دسترسی شما مسدود شده است.'); return; }
        $this->syncPlan();

        $parts = explode(':', $data);
        $act = $parts[0];
        $arg = $parts[1] ?? '';

        // ---- دسترسی ----
        $openForRestricted = ['sub', 'menu', 'codein', 'buy', 'help'];
        if (!$this->allowed() && !in_array($act, $openForRestricted, true)) {
            $this->send("⏳ حساب شما هنوز فعال نشده است.\n\n"
                . "برای استفاده از ربات، اشتراک ویژه را فعال کنید:\n"
                . "▫️ کد فعال‌سازی دارید؟ «🎟 ورود کد فعال‌سازی»\n"
                . "▫️ یا پرداخت را انجام دهید تا مدیر تأیید کند.",
                $this->restrictedMenu());
            return;
        }

        switch ($act) {
            // ---------- عمومی ----------
            case 'menu':
                $this->clearStep();
                $this->edit($msgId, $this->welcome(), $this->mainMenu());
                return;
            case 'help':
                $this->edit($msgId, $this->helpText(), $this->mainMenu());
                return;
            case 'sites':
                $this->edit($msgId, $this->sitesListText(), $this->sitesListMenu());
                return;
            case 'addsite':
                $this->startAddSite();
                return;
            case 'report':
                $this->edit($msgId, $this->reportText(), $this->mainMenu());
                return;
            case 'share':
                $this->edit($msgId, $this->shareText(), $this->shareMenu());
                return;
            case 'regen':
                Db::q('UPDATE `user` SET `share_token` = ? WHERE `id` = ?', [self::uniqueShareToken(), $this->uid]);
                $this->u = Db::one('SELECT * FROM `user` WHERE `id` = ?', [$this->uid]);
                $this->edit($msgId, "🔄 لینک صفحهٔ وضعیت شما عوض شد.\n\n" . $this->shareText(), $this->shareMenu());
                return;
            case 'sub':
                $this->edit($msgId, $this->subText(), $this->subMenu());
                return;
            case 'codein':
                $this->setStep('await_code');
                $this->edit($msgId, "🎟 کد فعال‌سازی را بفرستید:\n\n<code>UP-XXXX-XXXX</code>", BotApi::kb([[['text' => '❌ انصراف']]]));
                return;
            case 'buy':
                $this->setStep('await_payment');
                $this->edit($msgId,
                    "💳 <b>پرداخت اشتراک ویژه</b>\n\n"
                    . "💰 مبلغ: " . faMoney(Db::getInt('price', 0)) . "\n"
                    . "💳 کارت: <code>" . tgH(Db::get('card', '—')) . "</code>\n"
                    . "📆 مدت: " . faNum(Db::getInt('vip_days', 30)) . " روز\n\n"
                    . "پس از انتقال، <b>رسید (عکس یا فایل)</b> را همین‌جا بفرستید تا مدیر بررسی و فعال کند.",
                    BotApi::kb([[['text' => '❌ انصراف']]])
                );
                return;
            case 'tnotify':
                $cur = (int)$this->u['notify'];
                Db::q('UPDATE `user` SET `notify` = ? WHERE `id` = ?', [$cur ? 0 : 1, $this->uid]);
                $this->u['notify'] = $cur ? 0 : 1;
                $this->edit($msgId, $this->settingsText(), $this->settingsMenu());
                return;
            case 'settings':
                $this->edit($msgId, $this->settingsText(), $this->settingsMenu());
                return;

            // ---------- سایت‌ها ----------
            case 'site': {
                $site = $this->ownSite((int)$arg);
                if (!$site) { $this->edit($msgId, '❌ سایت پیدا نشد.', $this->sitesListMenu()); return; }
                $this->edit($msgId, $this->siteDetail($site), $this->siteMenu($site));
                return;
            }
            case 'sc': { // چک الآن
                $site = $this->ownSite((int)$arg);
                if (!$site) { $this->edit($msgId, '❌ سایت پیدا نشد.', $this->sitesListMenu()); return; }
                $res = Monitor::checkSite((int)$site['id']);
                $r = $res['result'] ?? [];
                $site = Db::one('SELECT * FROM `site` WHERE `id` = ?', [$site['id']]);
                $note = "🔁 <b>بررسی فوری انجام شد</b>\n"
                    . (!empty($r['ok']) ? "🟢 پاسخ: فعال — " . faMs((int)$r['ms']) : "🔴 پاسخ: قطع — " . tgH($r['error'] ?? 'بدون پاسخ')) . "\n\n";
                $this->edit($msgId, $note . $this->siteDetail($site), $this->siteMenu($site));
                return;
            }
            case 'sp': { // توقف سایت
                $site = $this->ownSite((int)$arg);
                if (!$site) return;
                Db::q('UPDATE `site` SET `paused` = 1, `paused_since` = NOW() WHERE `id` = ?', [$site['id']]);
                Db::logEvent($this->uid, 'pause_site', $site['target']);
                $site = Db::one('SELECT * FROM `site` WHERE `id` = ?', [$site['id']]);
                $this->edit($msgId, "⏸ چک سایت متوقف شد.\n\n" . $this->siteDetail($site), $this->siteMenu($site));
                return;
            }
            case 'sr': { // ادامه سایت
                $site = $this->ownSite((int)$arg);
                if (!$site) return;
                $since = $site['paused_since'] ? strtotime($site['paused_since']) : time();
                $dur = max(0, time() - $since);
                Db::q('UPDATE `site` SET `paused` = 0, `paused_since` = NULL, `paused_total` = `paused_total` + ? WHERE `id` = ?', [$dur, $site['id']]);
                Db::logEvent($this->uid, 'resume_site', (string)$dur);
                $site = Db::one('SELECT * FROM `site` WHERE `id` = ?', [$site['id']]);
                $this->edit($msgId, "▶️ چک سایت ادامه یافت (توقف قبلی: " . faDuration($dur) . ").\n\n" . $this->siteDetail($site), $this->siteMenu($site));
                return;
            }
            case 'sdel': { // تأیید حذف
                $site = $this->ownSite((int)$arg);
                if (!$site) return;
                $this->edit($msgId, "⚠️ <b>حذف سایت</b>\n\n🔗 <code>" . tgH($site['target']) . "</code>\n\n"
                    . "کل تاریخچهٔ آن (آپتایم، لاگ چک‌ها) هم پاک می‌شود. مطمئنید؟",
                    BotApi::ikb([
                        [['text' => '✅ بله، حذف شود', 'callback_data' => 'sdelx:' . $site['id']]],
                        [['text' => '↩️ بازگشت', 'callback_data' => 'site:' . $site['id']]],
                    ]));
                return;
            }
            case 'sdelx': {
                $site = $this->ownSite((int)$arg);
                if (!$site) return;
                Db::q('DELETE FROM `check_log` WHERE `site_id` = ?', [$site['id']]);
                Db::q('DELETE FROM `uptime_hour` WHERE `site_id` = ?', [$site['id']]);
                Db::q('DELETE FROM `site` WHERE `id` = ? AND `user_id` = ?', [$site['id'], $this->uid]);
                Db::logEvent($this->uid, 'delete_site', $site['target']);
                $this->edit($msgId, "🗑 سایت حذف شد.\n\n" . $this->sitesListText(), $this->sitesListMenu());
                return;
            }

            // ---------- ادمین: آمار/کرون/همگانی ----------
            case 'astats':
                $this->edit($msgId, $this->adminStatsText(), $this->adminMenu());
                return;
            case 'apanel':
                $this->edit($msgId, $this->adminPanelText(), $this->adminPanelMenu());
                return;
            case 'cron':
                $this->edit($msgId, $this->cronText(), $this->adminMenu());
                return;
            case 'abroadcast':
                $this->setStep('await_broadcast');
                $this->edit($msgId, "📣 پیام همگانی را بفرستید (متن/عکس/فایل).\nانصراف: ❌ انصراف", BotApi::kb([[['text' => '❌ انصراف']]]));
                return;

            // ---------- ادمین: توقف سراسری ----------
            case 'pause': {
                if (!$this->isAdmin()) return;
                if (Db::getBool('pause_all', false)) { $this->edit($msgId, $this->adminPanelText(), $this->adminPanelMenu()); return; }
                Db::set('pause_all', '1');
                Db::set('pause_global_since', date('Y-m-d H:i:s'));
                Db::logEvent($this->uid, 'pause_global', '');
                $this->edit($msgId, "⏸ <b>چک سراسری متوقف شد</b>\n\n"
                    . "هیچ سایتی تا ادامهٔ مجدد بررسی نمی‌شود.\n"
                    . "مدت توقف در «آمار» لحاظ می‌شود.\n\n" . $this->adminPanelText(), $this->adminPanelMenu());
                return;
            }
            case 'resume': {
                if (!$this->isAdmin()) return;
                if (!Db::getBool('pause_all', false)) { $this->edit($msgId, $this->adminPanelText(), $this->adminPanelMenu()); return; }
                $since = Db::get('pause_global_since');
                $dur = $since ? max(0, time() - strtotime($since)) : 0;
                $total = Db::getInt('pause_global_total', 0) + $dur;
                Db::set('pause_all', '0');
                Db::set('pause_global_total', (string)$total);
                Db::set('pause_global_since', '');
                Db::logEvent($this->uid, 'resume_global', (string)$dur);
                $this->edit($msgId, "▶️ <b>چک سراسری ادامه یافت</b>\n⌛️ مدت توقف: " . faDuration($dur)
                    . "\n📈 مجموع مدت توقف: " . faDuration($total) . "\n\n" . $this->adminPanelText(), $this->adminPanelMenu());
                return;
            }

            // ---------- ادمین: تنظیمات ----------
            case 'set': {
                if (!$this->isAdmin()) return;
                if ($arg === 'notify') {
                    $cur = Db::getBool('notify', true);
                    Db::set('notify', $cur ? '0' : '1');
                    $this->edit($msgId, $this->adminPanelText(), $this->adminPanelMenu());
                    return;
                }
                if ($arg === 'access_mode') {
                    $order = ['open', 'code', 'paid'];
                    $cur = Db::get('access_mode', 'open');
                    $next = $order[(array_search($cur, $order, true) + 1) % 3];
                    Db::set('access_mode', $next);
                    if ($next === 'open') Db::q('UPDATE `user` SET `access` = 1 WHERE `is_admin` = 0');
                    Db::logEvent($this->uid, 'setting', 'access_mode=' . $next);
                    $this->edit($msgId, $this->adminPanelText(), $this->adminPanelMenu());
                    return;
                }
                $labels = [
                    'max_users' => 'سقف کاربران (۰ = نامحدود)',
                    'max_sites' => 'سقف سایت هر کاربر عادی',
                    'vip_max_sites' => 'سقف سایت هر کاربر ویژه',
                    'check_interval' => 'فاصلهٔ چک (ثانیه، کمینه ۱۰)',
                    'fail_threshold' => 'آستانهٔ هشدار قطعی (چک ناموفق پیاپی)',
                    'price' => 'قیمت اشتراک (تومان)',
                    'card' => 'شماره کارت (حداکثر ۱۹ رقم)',
                    'vip_days' => 'مدت اشتراک ویژه (روز)',
                ];
                if (!isset($labels[$arg])) return;
                $this->setStep('await_setting', ['key' => $arg]);
                $this->edit($msgId, "⚙️ مقدار جدید برای <b>{$labels[$arg]}</b> را بفرستید:\n\nفعلی: <code>" . tgH((string)Db::get($arg, '')) . "</code>",
                    BotApi::kb([[['text' => '❌ انصراف']]]));
                return;
            }
            case 'codes':
                if (!$this->isAdmin()) return;
                $this->edit($msgId, $this->codesText(), BotApi::ikb([
                    [['text' => '➕ ساخت کد جدید', 'callback_data' => 'gen']],
                    [['text' => '↩️ بازگشت', 'callback_data' => 'apanel']],
                ]));
                return;
            case 'gen':
                if (!$this->isAdmin()) return;
                $this->setStep('await_codegen');
                $this->edit($msgId, "🎟 <b>ساخت کد فعال‌سازی</b>\n\nفرمت را بفرستید:\n<code>30 5</code>\n\n"
                    . "یعنی: کدهای <b>۳۰ روزه</b>، تعداد <b>۵</b>\n(حداکثر ۵۰ کد در هر نوبت)",
                    BotApi::kb([[['text' => '❌ انصراف']]]));
                return;

            // ---------- ادمین: کاربران ----------
            case 'ausers':
                if (!$this->isAdmin()) return;
                $this->sendUsersList((int)$arg, $msgId);
                return;
            case 'auser': {
                if (!$this->isAdmin()) return;
                $target = Db::one('SELECT * FROM `user` WHERE `id` = ?', [(int)$arg]);
                if (!$target) { $this->edit($msgId, 'کاربر پیدا نشد.', $this->adminMenu()); return; }
                $this->edit($msgId, $this->userDetail($target), $this->userMenu($target));
                return;
            }
            case 'aub': { // مسدود/رفع مسدود
                if (!$this->isAdmin()) return;
                $target = Db::one('SELECT * FROM `user` WHERE `id` = ?', [(int)$arg]);
                if (!$target) return;
                $new = (int)$target['is_blocked'] === 1 ? 0 : 1;
                Db::q('UPDATE `user` SET `is_blocked` = ? WHERE `id` = ?', [$new, $target['id']]);
                Db::logEvent($this->uid, $new ? 'block_user' : 'unblock_user', (string)$target['id']);
                if ($new) tgSend($target['id'], "⛔️ دسترسی شما توسط مدیر مسدود شد.");
                else tgSend($target['id'], "✅ دسترسی شما دوباره باز شد.");
                $target = Db::one('SELECT * FROM `user` WHERE `id` = ?', [(int)$arg]);
                $this->edit($msgId, $this->userDetail($target), $this->userMenu($target));
                return;
            }
            case 'auv': { // ویژه کردن
                if (!$this->isAdmin()) return;
                $target = Db::one('SELECT * FROM `user` WHERE `id` = ?', [(int)$arg]);
                if (!$target) return;
                $days = Db::getInt('vip_days', 30);
                $base = (empty($target['plan_until']) || strtotime($target['plan_until']) < time()) ? time() : strtotime($target['plan_until']);
                $until = date('Y-m-d H:i:s', $base + $days * 86400);
                Db::q("UPDATE `user` SET `plan` = 'vip', `plan_until` = ?, `access` = 1, `is_blocked` = 0 WHERE `id` = ?", [$until, $target['id']]);
                Db::logEvent($this->uid, 'make_vip', (string)$target['id']);
                tgSend($target['id'], "💎 اشتراک ویژهٔ شما توسط مدیر فعال شد.\n📅 تا: " . faDateTime($until));
                $target = Db::one('SELECT * FROM `user` WHERE `id` = ?', [(int)$arg]);
                $this->edit($msgId, $this->userDetail($target), $this->userMenu($target));
                return;
            }
            case 'aud': { // حذف کاربر
                if (!$this->isAdmin()) return;
                $target = Db::one('SELECT * FROM `user` WHERE `id` = ?', [(int)$arg]);
                if (!$target) return;
                $siteIds = array_map(fn($r) => (int)$r['id'], Db::all('SELECT `id` FROM `site` WHERE `user_id` = ?', [$target['id']]));
                foreach ($siteIds as $sid) {
                    Db::q('DELETE FROM `check_log` WHERE `site_id` = ?', [$sid]);
                    Db::q('DELETE FROM `uptime_hour` WHERE `site_id` = ?', [$sid]);
                }
                Db::q('DELETE FROM `site` WHERE `user_id` = ?', [$target['id']]);
                Db::q('DELETE FROM `user` WHERE `id` = ?', [$target['id']]);
                Db::logEvent($this->uid, 'delete_user', (string)$target['id']);
                $this->edit($msgId, "🗑 کاربر " . tgH((string)$target['name']) . " (<code>{$target['id']}</code>) و " . faNum(count($siteIds)) . " سایتش حذف شد.", $this->adminMenu());
                return;
            }
            case 'apays':
                if (!$this->isAdmin()) return;
                $this->sendPayments($msgId);
                return;
            case 'apayok':
            case 'apayno': {
                if (!$this->isAdmin()) return;
                $pay = Db::one('SELECT * FROM `payments` WHERE `id` = ?', [(int)$arg]);
                if (!$pay) { $this->edit($msgId, 'پرداخت پیدا نشد.'); return; }
                $approve = $act === 'apayok';
                Db::q('UPDATE `payments` SET `status` = ?, `decided_at` = NOW() WHERE `id` = ?', [$approve ? 'approved' : 'rejected', $pay['id']]);
                if ($approve) {
                    $days = Db::getInt('vip_days', 30);
                    $target = Db::one('SELECT * FROM `user` WHERE `id` = ?', [$pay['user_id']]);
                    $base = (!$target || empty($target['plan_until']) || strtotime($target['plan_until']) < time()) ? time() : strtotime($target['plan_until']);
                    $until = date('Y-m-d H:i:s', $base + $days * 86400);
                    Db::q("UPDATE `user` SET `plan` = 'vip', `plan_until` = ?, `access` = 1, `is_blocked` = 0 WHERE `id` = ?", [$until, $pay['user_id']]);
                    Db::logEvent($this->uid, 'payment_ok', (string)$pay['id']);
                    tgSend($pay['user_id'], "✅ پرداخت شما تأیید شد.\n💎 اشتراک ویژه فعال شد — تا " . faDateTime($until)
                        . "\n🌐 سقف سایت‌ها: " . faNum(Db::getInt('vip_max_sites', 10)), $this->mainMenu());
                    $this->edit($msgId, "✅ پرداخت #" . faNum($pay['id']) . " تأیید و اشتراک فعال شد.");
                } else {
                    Db::logEvent($this->uid, 'payment_reject', (string)$pay['id']);
                    tgSend($pay['user_id'], "❌ پرداخت شما تأیید نشد.\nبرای اطلاعات بیشتر با مدیر تماس بگیرید.");
                    $this->edit($msgId, "❌ پرداخت #" . faNum($pay['id']) . " رد شد.");
                }
                $this->sendPayments();
                return;
            }
        }
    }

    private function ownSite(int $id): ?array
    {
        if ($id <= 0) return null;
        return Db::one('SELECT * FROM `site` WHERE `id` = ? AND `user_id` = ?', [$id, $this->uid]);
    }

    // ================================================================ متون

    private function welcome(): string
    {
        $interval = max(10, Db::getInt('check_interval', 20));
        $engine = Stats::engine();
        $name = trim((string)($this->u['name'] ?? ''));
        $vip = $this->isVip() ? "💎 اشتراک ویژه تا " . faDateTime($this->u['plan_until']) . "\n" : '';
        return "👋 سلام" . ($name !== '' ? ' ' . tgH($name) : '') . "!\n\n"
            . "📊 <b>مانیتورینگ سرور و سایت</b>\n\n"
            . "با این ربات می‌توانید تا <b>" . faNum($this->maxSites()) . " سایت/سرور</b> را زیر نظر بگیرید و وضعیت آن‌ها هر <b>"
            . faNum($interval) . " ثانیه</b> بررسی شود.\n\n"
            . "📈 آپتایم دقیق با توضیح کامل، زمان پاسخ، تاریخچهٔ قطعی‌ها، اطلاع‌رسانی لحظه‌ای و یک <b>صفحهٔ وب اشتراکی</b> برای نمایش زنده.\n\n"
            . $vip
            . "🔁 موتور چک: " . ($engine['paused'] ? "⏸ <b>متوقف (توسط مدیر)</b>" : ($engine['cron_healthy'] ? "🟢 فعال" : "🟡 کرون هنوز راه‌اندازی نشده"))
            . ($engine['last_round'] ? "\n🕒 آخرین راند: " . faDateTime($engine['last_round']) : '');
    }

    private function helpText(): string
    {
        return "ℹ️ <b>راهنمای ربات</b>\n\n"
            . "<b>۱) افزودن سایت</b>\n"
            . "«➕ افزودن سایت» و سپس آدرس را بفرستید:\n"
            . "▪️ <code>https://example.com</code> → بررسی صفحه (HTTP/HTTPS)\n"
            . "▪️ <code>example.com</code> → پینگ (ICMP)\n"
            . "▪️ <code>example.com:8080</code> → اتصال پورت (TCP)\n\n"
            . "<b>۲) نمایش وضعیت</b>\n"
            . "«📋 سایت‌های من» → انتخاب سایت → جزئیات کامل: آپتایم ۲۴ ساعت/۷ روز/۳۰ روز، زمان پاسخ، تاریخچهٔ رنگی چک‌ها.\n\n"
            . "<b>۳) توقف موقت</b>\n"
            . "می‌توانید چک یک سایت یا همهٔ سایت‌های خود را موقتاً متوقف و دوباره ادامه دهید. مدت توقف در آمار لحاظ می‌شود.\n\n"
            . "<b>۴) صفحهٔ وضعیت اشتراکی</b>\n"
            . "«🔗 صفحهٔ وضعیت من» → لینکی عمومی برای نمایش زندهٔ سایت‌هایتان (قابل اشتراک در وب/شبکه‌های اجتماعی).\n\n"
            . "<b>۵) اطلاع‌رسانی</b>\n"
            . "با قطع شدن سرویس پیام 🔴 و با برقراری دوباره پیام 🟢 دریافت می‌کنید.\n\n"
            . "⚙️ از «⚙️ تنظیمات» می‌توانید اعلان‌ها را خاموش/روشن کنید.";
    }

    private function sitesListText(): string
    {
        $summary = Stats::userSummary($this->uid);
        if ($summary['total'] === 0) {
            return "📋 <b>سایت‌های شما</b>\n\nهنوز سایتی ثبت نکرده‌اید.\nبا «➕ افزودن سایت» شروع کنید.";
        }
        $txt = "📋 <b>سایت‌های شما</b> (" . faNum($summary['total']) . " از " . faNum($this->maxSites()) . ")\n\n";
        $i = 1;
        foreach ($summary['sites'] as $s) {
            $paused = (int)$s['paused'] === 1;
            $st = $paused ? '🟡' : statusEmoji($s['status']);
            $u = Stats::uptime($s, 1);
            $txt .= faNum($i++) . ". {$st} <code>" . tgH($s['label'] ?: $s['target']) . "</code>\n";
            $txt .= "    " . typeName($s['type']) . " • آپتایم ۲۴س: " . ($u['pct'] !== null ? faPct($u['pct']) : '—');
            if ($paused) $txt .= " • ⏸ متوقف";
            elseif ($s['status'] === 'down') $txt .= " • 🔴 از " . timeAgo($s['last_down_at'], tzOffset());
            $txt .= "\n";
            if (!$paused && $s['last_check_at']) $txt .= "    ⏱ آخرین چک: " . timeAgo($s['last_check_at'], tzOffset()) . " — " . faMs((int)$s['last_ms']) . "\n";
            $txt .= "\n";
        }
        $engine = Stats::engine();
        $txt .= "🔁 موتور چک: " . ($engine['paused'] ? '⏸ متوقف' : ($engine['cron_healthy'] ? '🟢 فعال' : '🟡 بدون کرون'))
            . " • فاصله: " . faNum($engine['interval']) . " ثانیه";
        return $txt;
    }

    private function siteDetail(array $s): string
    {
        $u24 = Stats::uptime($s, 1);
        $u7 = Stats::uptime($s, 7);
        $u30 = Stats::uptime($s, 30);
        $c = Stats::counters($s);
        $recent = Stats::recent($s, 60);
        $paused = (int)$s['paused'] === 1;

        $txt = "📊 <b>جزئیات سایت</b>\n\n"
            . "🔗 <code>" . tgH($s['target']) . "</code>\n"
            . "🧭 نوع: " . typeName($s['type']) . "\n"
            . "🚦 وضعیت: " . ($paused ? "🟡 <b>متوقف موقت</b>" : statusEmoji($s['status']) . " <b>" . statusName($s['status']) . "</b>") . "\n\n"
            . "<b>⏱ آپتایم</b>\n"
            . "▫️ ۲۴ ساعت اخیر: " . faPct($u24['pct']) . " (" . faNum($u24['checks']) . " چک)\n"
            . "▫️ ۷ روز: " . faPct($u7['pct']) . "\n"
            . "▫️ ۳۰ روز: " . faPct($u30['pct']) . "\n\n"
            . "<b>📈 عملکرد</b>\n"
            . "▫️ زمان پاسخ آخرین چک: " . faMs((int)$s['last_ms']) . "\n"
            . "▫️ میانگین ۲۴ ساعت: " . faMs($u24['avg_ms']) . "\n"
            . "▫️ کل چک‌ها: " . faNum($c['checks']) . " • قطعی‌ها: " . faNum($c['fails'])
            . " (" . faPct($c['checks'] > 0 ? round($c['fails'] * 10000 / $c['checks']) / 100 : null) . ")\n"
            . "▫️ آخرین چک: " . timeAgo($s['last_check_at'], tzOffset()) . "\n"
            . "▫️ آخرین برقراری: " . timeAgo($s['last_up_at'], tzOffset()) . "\n"
            . "▫️ آخرین قطعی: " . ($s['last_down_at'] ? timeAgo($s['last_down_at'], tzOffset()) . ((int)$s['last_down_duration'] > 0 ? " (مدت " . faDuration((int)$s['last_down_duration']) . ")" : '') : '—') . "\n"
            . "▫️ ثبت‌شده از: " . timeAgo($s['created_at'], tzOffset()) . "\n";

        if (!empty($s['last_error']) && $s['status'] !== 'up') {
            $txt .= "\n❌ دلیل آخرین خطا: <i>" . tgH($s['last_error']) . "</i>\n";
        }
        $txt .= "\n<b>آخرین " . faNum(count($recent)) . " چک (هر مربع یک چک):</b>\n" . Stats::barEmoji($recent);
        return $txt;
    }

    private function reportText(): string
    {
        $s = Stats::userSummary($this->uid);
        if ($s['total'] === 0) return "📈 هنوز سایتی ثبت نکرده‌اید.\nبا «➕ افزودن سایت» شروع کنید.";
        $u = $this->u;
        $txt = "📈 <b>گزارش آپتایم شما</b>\n\n"
            . "▫️ تعداد سایت‌ها: " . faNum($s['total']) . " از " . faNum($this->maxSites()) . "\n"
            . "▫️ فعال: 🟢 " . faNum($s['up']) . " • قطع: 🔴 " . faNum($s['down']) . " • متوقف: 🟡 " . faNum($s['paused']) . "\n"
            . "▫️ میانگین آپتایم ۲۴ ساعت همهٔ سایت‌ها: " . faPct($s['uptime24']) . "\n\n";
        foreach ($s['sites'] as $site) {
            $u24 = Stats::uptime($site, 1);
            $u30 = Stats::uptime($site, 30);
            $txt .= statusEmoji($site['status']) . " <code>" . tgH($site['label'] ?: $site['target']) . "</code>\n"
                . "    ۲۴س: " . faPct($u24['pct']) . " • ۳۰روز: " . faPct($u30['pct'])
                . " • میانگین پاسخ: " . faMs($u24['avg_ms']) . "\n";
        }
        $txt .= "\n▫️ توقف‌های شما: " . faNum((int)Db::val("SELECT COUNT(*) FROM `events` WHERE `user_id` = ? AND `kind` = 'pause_user'", [$this->uid]))
            . " بار — مجموع " . faDuration((int)$u['paused_total'] + ((int)$u['paused'] === 1 && $u['paused_since'] ? max(0, time() - strtotime($u['paused_since'])) : 0)) . "\n"
            . "▫️ اعلان‌ها: " . ((int)$u['notify'] ? '🔔 روشن' : '🔕 خاموش');
        return $txt;
    }

    private function shareText(): string
    {
        $url = statusUrl($this->cfg, (string)$this->u['share_token']);
        $summary = Stats::userSummary($this->uid);
        return "🔗 <b>صفحهٔ وضعیت شما (اشتراکی)</b>\n\n"
            . "این لینک را می‌توانید در وب‌سایت، شبکه‌های اجتماعی یا برای مشتریانتان بفرستید تا وضعیت زندهٔ سایت‌های شما را ببینند.\n\n"
            . "🌐 <code>" . tgH($url) . "</code>\n\n"
            . "📊 سایت‌های نمایش‌داده‌شده: " . faNum($summary['total']) . "\n"
            . "🔁 به‌روزرسانی خودکار هر " . faNum(max(10, Db::getInt('check_interval', 20))) . " ثانیه\n\n"
            . "⚠️ هرکسی این لینک را داشته باشد می‌تواند سایت‌های شما را ببیند؛ با «🔄 تغییر لینک» می‌توانید لینک قبلی را باطل کنید.";
    }

    private function subText(): string
    {
        $mode = Db::get('access_mode', 'open');
        $price = Db::getInt('price', 0);
        $days = Db::getInt('vip_days', 30);
        $vipTxt = $this->isVip()
            ? "💎 <b>اشتراک ویژه فعال است</b>\n📅 تا: " . faDateTime($this->u['plan_until']) . "\n🌐 سقف سایت‌ها: " . faNum(Db::getInt('vip_max_sites', 10)) . "\n\n"
            : "";
        $base = $vipTxt
            . "<b>امکانات اشتراک ویژه</b>\n"
            . "▫️ سقف بیشتر سایت (" . faNum(Db::getInt('vip_max_sites', 10)) . " به‌جای " . faNum(Db::getInt('max_sites', 5)) . ")\n"
            . "▫️ اولویت در پشتیبانی\n"
            . "▫️ امکان تمدید و انتقال\n\n";
        if ($mode === 'open') {
            return $base . "🔓 حالت دسترسی: <b>باز</b> — نیازی به پرداخت نیست.";
        }
        if ($mode === 'code') {
            return $base . "🔓 برای استفاده به <b>کد فعال‌سازی</b> نیاز دارید.\nاگر کد دارید، دکمهٔ زیر را بزنید.";
        }
        return $base . "🛒 <b>خرید اشتراک</b>\n"
            . "💰 مبلغ: " . faMoney($price) . "\n"
            . "📆 مدت: " . faNum($days) . " روز\n"
            . "💳 کارت: <code>" . tgH(Db::get('card', '—')) . "</code>\n\n"
            . "رسید را بفرستید تا مدیر فعال کند، یا اگر کد دارید از همان استفاده کنید.";
    }

    private function settingsText(): string
    {
        $u = $this->u;
        $mode = ['open' => 'باز (همه می‌توانند استفاده کنند)', 'code' => 'نیازمند کد فعال‌سازی', 'paid' => 'نیازمند پرداخت'][Db::get('access_mode', 'open')] ?? '—';
        return "⚙️ <b>تنظیمات</b>\n\n"
            . "🔔 اعلان قطعی/برقراری: " . ((int)$u['notify'] ? 'روشن' : 'خاموش') . "\n"
            . "🌐 سقف سایت‌های شما: " . faNum($this->maxSites()) . "\n"
            . "⏱ فاصلهٔ چک: " . faNum(max(10, Db::getInt('check_interval', 20))) . " ثانیه\n"
            . "💳 اشتراک: " . ($this->isVip() ? '💎 ویژه تا ' . faDateTime($u['plan_until']) : 'عادی') . "\n"
            . "🔓 حالت دسترسی ربات: " . $mode . "\n"
            . "⏸ وضعیت چک شما: " . ((int)$u['paused'] === 1 ? '🟡 متوقف از ' . timeAgo($u['paused_since'], tzOffset()) : '🟢 در حال چک') . "\n"
            . "🗂 سایت‌ها: " . faNum((int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ?', [$this->uid])) . " از " . faNum($this->maxSites());
    }

    private function adminStatsText(): string
    {
        $o = Stats::adminOverview();
        $p = Stats::pauseStats();
        $e = Stats::engine();
        $settings = Db::allSettings();
        $interval = max(10, (int)($settings['check_interval'] ?? 20));
        $uptimeAll = null;
        $row = Db::one('SELECT COUNT(*) c, COALESCE(SUM(`ok`),0) o FROM `check_log` WHERE `ts` >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
        if ($row && (int)$row['c'] > 0) $uptimeAll = round((int)$row['o'] * 10000 / (int)$row['c']) / 100;

        return "📊 <b>آمار کلی ربات</b>\n\n"
            . "<b>👥 کاربران</b>\n"
            . "▫️ کل: " . faNum($o['users_total']) . " • فعال: " . faNum($o['users_active']) . " • مسدود: " . faNum($o['users_blocked']) . "\n"
            . "▫️ ویژه: 💎 " . faNum($o['users_vip']) . " • متوقفکنندهٔ چک: 🟡 " . faNum($o['users_paused']) . "\n"
            . "▫️ سقف کاربران: " . (Db::getInt('max_users', 0) > 0 ? faNum(Db::getInt('max_users', 0)) : 'نامحدود') . "\n\n"
            . "<b>🌐 سایت‌ها</b>\n"
            . "▫️ کل: " . faNum($o['sites_total']) . " • فعال: 🟢 " . faNum($o['sites_up']) . " • قطع: 🔴 " . faNum($o['sites_down']) . " • متوقف: 🟡 " . faNum($o['sites_paused']) . "\n\n"
            . "<b>🔁 موتور چک</b>\n"
            . "▫️ وضعیت: " . ($e['paused'] ? '⏸ <b>متوقف سراسری</b>' : ($e['cron_healthy'] ? '🟢 سالم' : '🟡 کرون اجرا نمی‌شود')) . "\n"
            . "▫️ فاصله: " . faNum($interval) . " ثانیه • آخرین راند: " . ($e['last_round'] ? timeAgo($e['last_round'], tzOffset()) : 'هرگز') . "\n"
            . "▫️ چک‌های ۲۴ ساعت: " . faNum($o['checks_day']) . " • ناموفق: " . faNum($o['fails_day']) . "\n"
            . "▫️ آپتایم کل ۲۴ ساعت: " . faPct($uptimeAll) . "\n"
            . "▫️ هشدارهای قطعی امروز: " . faNum($o['alerts_day']) . "\n\n"
            . "<b>⏸ آمار خاموش کردن موقت</b>\n"
            . "▫️ توقف سراسری: " . ($p['global_on'] ? '🟡 در حال توقف' : '—') . "\n"
            . "▫️ تعداد توقف سراسری: " . faNum($p['global_count']) . " بار — مجموع " . faDuration($p['global_total']) . "\n"
            . "▫️ توقف کاربران: " . faNum($p['user_count']) . " بار — مجموع " . faDuration($p['user_total']) . " (الان: " . faNum($p['user_now']) . " کاربر)\n"
            . "▫️ توقف سایت‌ها: " . faNum($p['site_count']) . " بار — مجموع " . faDuration($p['site_total']) . " (الان: " . faNum($p['site_now']) . " سایت)\n"
            . "▫️ آخرین ادامه: " . ($p['last_resume'] ? timeAgo($p['last_resume'], tzOffset()) : '—') . "\n\n"
            . "<b>💰 اشتراک</b>\n"
            . "▫️ حالت: " . ['open' => 'باز', 'code' => 'کد فعال‌سازی', 'paid' => 'پرداخت'][Db::get('access_mode', 'open')] . "\n"
            . "▫️ پرداخت‌های در انتظار: " . faNum($o['payments_pending']) . " • کدهای بدون مصرف: " . faNum($o['codes_unused']);
    }

    private function adminPanelText(): string
    {
        $s = Db::allSettings();
        $e = Stats::engine();
        $modeTxt = ['open' => '🔓 باز', 'code' => '🎟 کد فعال‌سازی', 'paid' => '💳 پرداخت'][Db::get('access_mode', 'open')] ?? '—';
        return "🛠 <b>پنل مدیریت</b>\n\n"
            . "▫️ سقف کاربران: " . ((int)($s['max_users'] ?? 0) > 0 ? faNum((int)$s['max_users']) : 'نامحدود') . "\n"
            . "▫️ سقف سایت عادی: " . faNum((int)($s['max_sites'] ?? 5)) . " • ویژه: " . faNum((int)($s['vip_max_sites'] ?? 10)) . "\n"
            . "▫️ فاصلهٔ چک: " . faNum((int)($s['check_interval'] ?? 20)) . " ثانیه\n"
            . "▫️ آستانهٔ هشدار قطعی: " . faNum((int)($s['fail_threshold'] ?? 2)) . " چک ناموفق پیاپی\n"
            . "▫️ حالت دسترسی: " . $modeTxt . "\n"
            . "▫️ قیمت اشتراک: " . ((int)($s['price'] ?? 0) > 0 ? faMoney((int)$s['price']) : '—') . " • مدت: " . faNum((int)($s['vip_days'] ?? 30)) . " روز\n"
            . "▫️ شماره کارت: <code>" . tgH((string)($s['card'] ?? '—')) . "</code>\n"
            . "▫️ اعلان‌های سراسری: " . (Db::getBool('notify', true) ? '🔔 روشن' : '🔕 خاموش') . "\n"
            . "▫️ موتور چک: " . ($e['paused'] ? '⏸ <b>متوقف</b>' : '🟢 فعال')
            . ($e['last_round'] ? " • آخرین راند: " . timeAgo($e['last_round'], tzOffset()) : '');
    }

    private function cronText(): string
    {
        $dir = UPTIME_ROOT;
        $base = rtrim((string)($this->cfg['base_url'] ?? ''), '/');
        $secret = hash('sha256', $this->token . '_uptime_cron_secret');
        return "⏰ <b>راه‌اندازی کرون چک (هر ۲۰ ثانیه)</b>\n\n"
            . "بدون کرون، چک‌ها فقط هنگام بازدید صفحهٔ وضعیت انجام می‌شوند.\n\n"
            . "<b>روش ۱ — کرون سیستمی (پیشنهادی)</b>\n"
            . "<code>* * * * * php {$dir}/cron/checker.php</code>\n"
            . "اسکریپت خودش هر بار ۳ نوبت با فاصلهٔ ۲۰ ثانیه چک می‌کند.\n\n"
            . "<b>روش ۲ — کرون HTTP (هاست اشتراکی)</b>\n"
            . "<code>* * * * * curl -s \"{$base}/cron/checker.php?secret={$secret}\"</code>\n\n"
            . "<b>روش ۳ — دیمون دائمی</b>\n"
            . "<code>php {$dir}/cron/checker.php --daemon</code>\n\n"
            . "🔍 وضعیت فعلی: " . (Stats::engine()['cron_healthy'] ? '🟢 سالم' : '🟡 اجرا نشده');
    }

    private function codesText(): string
    {
        $rows = Db::all('SELECT * FROM `codes` WHERE `uses` < `uses_max` ORDER BY `created_at` DESC LIMIT 30');
        if (!$rows) return "🎟 کد فعال‌سازی بدون مصرفی وجود ندارد.\n\nبا «➕ ساخت کد جدید» بسازید.";
        $txt = "🎟 <b>کدهای بدون مصرف</b> (" . faNum(count($rows)) . " مورد):\n\n";
        foreach ($rows as $r) {
            $txt .= "▪️ <code>" . tgH($r['code']) . "</code> — " . faNum((int)$r['days']) . " روز\n";
        }
        return $txt;
    }

    private function userDetail(array $t): string
    {
        $sites = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ?', [$t['id']]);
        $u24 = 0.0; $n = 0;
        foreach (Db::all('SELECT * FROM `site` WHERE `user_id` = ?', [$t['id']]) as $s) {
            $u = Stats::uptime($s, 1);
            if ($u['pct'] !== null) { $u24 += $u['pct']; $n++; }
        }
        return "👤 <b>کاربر</b>\n\n"
            . "▫️ نام: " . tgH((string)$t['name']) . " (<code>{$t['id']}</code>)\n"
            . "▫️ یوزرنیم: " . ($t['username'] ? '@' . tgH($t['username']) : '—') . "\n"
            . "▫️ نقش: " . ((int)$t['is_admin'] === 1 ? '👑 مدیر' : (((int)$t['access'] === 1 ? '✅ مجاز' : '⏳ در انتظار') . ((int)$t['is_blocked'] === 1 ? ' • 🚫 مسدود' : ''))) . "\n"
            . "▫️ اشتراک: " . ($t['plan'] === 'vip' && (!empty($t['plan_until']) && strtotime($t['plan_until']) > time()) ? '💎 تا ' . faDateTime($t['plan_until']) : 'عادی') . "\n"
            . "▫️ سایت‌ها: " . faNum($sites) . " • میانگین آپتایم ۲۴س: " . ($n ? faPct($u24 / $n) : '—') . "\n"
            . "▫️ اعلان‌ها: " . ((int)$t['notify'] ? '🔔' : '🔕') . " • چک: " . ((int)$t['paused'] === 1 ? '🟡 متوقف' : '🟢 در حال چک') . "\n"
            . "▫️ مجموع توقف: " . faDuration((int)$t['paused_total']) . "\n"
            . "▫️ عضویت: " . timeAgo($t['created_at'], tzOffset()) . " • آخرین حضور: " . timeAgo($t['last_seen'], tzOffset());
    }

    // ================================================================ منوها

    private function mainMenu(): string
    {
        $paused = $this->u && (int)$this->u['paused'] === 1;
        $rows = [
            [['text' => '➕ افزودن سایت'], ['text' => '📋 سایت‌های من']],
            [['text' => '📈 گزارش من'], ['text' => '🔗 صفحهٔ وضعیت من']],
            [['text' => $paused ? '▶️ ادامهٔ چک‌ها' : '⏸ توقف چک‌ها'], ['text' => '⚙️ تنظیمات']],
            [['text' => '🛒 اشتراک ویژه'], ['text' => 'ℹ️ راهنما']],
        ];
        if ($this->isAdmin()) {
            $rows[] = [['text' => '📊 آمار مدیریتی'], ['text' => '📣 همگانی']];
            $rows[] = [['text' => '👥 کاربران'], ['text' => '⚙️ پنل مدیریت']];
        }
        return BotApi::kb($rows);
    }

    private function restrictedMenu(): string
    {
        return BotApi::kb([
            [['text' => '🛒 اشتراک ویژه'], ['text' => 'ℹ️ راهنما']],
            [['text' => '🏠 منو']],
        ]);
    }

    private function adminMenu(): string
    {
        return BotApi::kb([
            [['text' => '📊 آمار مدیریتی'], ['text' => '📣 همگانی']],
            [['text' => '👥 کاربران'], ['text' => '💳 پرداخت‌ها']],
            [['text' => '⚙️ پنل مدیریت'], ['text' => '⏰ کرون چک']],
            [['text' => '🏠 منو']],
        ]);
    }

    private function adminPanelMenu(): string
    {
        return BotApi::ikb([
            [['text' => '🔢 سقف کاربران', 'callback_data' => 'set:max_users'], ['text' => '🌐 سقف سایت', 'callback_data' => 'set:max_sites']],
            [['text' => '💎 سقف سایت ویژه', 'callback_data' => 'set:vip_max_sites'], ['text' => '⏱ فاصلهٔ چک', 'callback_data' => 'set:check_interval']],
            [['text' => '🚨 آستانهٔ هشدار', 'callback_data' => 'set:fail_threshold'], ['text' => '🔓 حالت دسترسی', 'callback_data' => 'set:access_mode']],
            [['text' => '🛒 قیمت اشتراک', 'callback_data' => 'set:price'], ['text' => '💳 شماره کارت', 'callback_data' => 'set:card']],
            [['text' => '📆 مدت اشتراک (روز)', 'callback_data' => 'set:vip_days'], ['text' => Db::getBool('notify', true) ? '🔕 خاموش کردن اعلان‌ها' : '🔔 روشن کردن اعلان‌ها', 'callback_data' => 'set:notify']],
            [['text' => Db::getBool('pause_all', false) ? '▶️ ادامهٔ چک سراسری' : '⏸ توقف سراسری چک', 'callback_data' => Db::getBool('pause_all', false) ? 'resume' : 'pause']],
            [['text' => '🎟 کدهای فعال‌سازی', 'callback_data' => 'codes'], ['text' => '📊 آمار', 'callback_data' => 'astats']],
            [['text' => '📣 همگانی', 'callback_data' => 'abroadcast'], ['text' => '⏰ کرون چک', 'callback_data' => 'cron']],
            [['text' => '🔙 بازگشت', 'callback_data' => 'menu']],
        ]);
    }

    private function sitesListMenu(): string
    {
        $sites = Db::all('SELECT `id`,`label`,`target`,`status`,`paused` FROM `site` WHERE `user_id` = ? ORDER BY `id` ASC', [$this->uid]);
        $rows = [];
        foreach ($sites as $i => $s) {
            $name = mb_substr($s['label'] ?: $s['target'], 0, 30);
            $icon = ((int)$s['paused'] === 1) ? '🟡' : statusEmoji($s['status']);
            $rows[] = [['text' => $icon . ' ' . $name, 'callback_data' => 'site:' . $s['id']]];
        }
        $rows[] = [['text' => '➕ افزودن سایت', 'callback_data' => 'addsite'], ['text' => '📈 گزارش', 'callback_data' => 'report']];
        $rows[] = [['text' => '🔙 منو', 'callback_data' => 'menu']];
        return BotApi::ikb($rows);
    }

    private function siteMenu(array $s): string
    {
        $paused = (int)$s['paused'] === 1;
        return BotApi::ikb([
            [['text' => '🔁 چک الآن', 'callback_data' => 'sc:' . $s['id']],
             ['text' => $paused ? '▶️ ادامه' : '⏸ توقف', 'callback_data' => ($paused ? 'sr:' : 'sp:') . $s['id']]],
            [['text' => '🗑 حذف سایت', 'callback_data' => 'sdel:' . $s['id']],
             ['text' => '🔗 لینک صفحهٔ وضعیت', 'callback_data' => 'share']],
            [['text' => '🔙 سایت‌های من', 'callback_data' => 'sites']],
        ]);
    }

    private function shareMenu(): string
    {
        $url = statusUrl($this->cfg, (string)$this->u['share_token']);
        return BotApi::ikb([
            [['text' => '📤 اشتراک‌گذاری در تلگرام', 'url' => 'https://t.me/share/url?url=' . rawurlencode($url) . '&text=' . rawurlencode('📊 وضعیت زندهٔ سایت‌های من:')]],
            [['text' => '🌐 باز کردن صفحه', 'url' => $url]],
            [['text' => '🔄 تغییر لینک', 'callback_data' => 'regen']],
            [['text' => '🔙 منو', 'callback_data' => 'menu']],
        ]);
    }

    private function subMenu(): string
    {
        $mode = Db::get('access_mode', 'open');
        $rows = [];
        if ($mode === 'paid') $rows[] = [['text' => '💳 پرداخت و فعال‌سازی', 'callback_data' => 'buy']];
        if ($mode !== 'open') $rows[] = [['text' => '🎟 ورود کد فعال‌سازی', 'callback_data' => 'codein']];
        $rows[] = [['text' => '🔙 منو', 'callback_data' => 'menu']];
        return BotApi::ikb($rows);
    }

    private function settingsMenu(): string
    {
        return BotApi::ikb([
            [['text' => (int)$this->u['notify'] ? '🔕 خاموش کردن اعلان‌ها' : '🔔 روشن کردن اعلان‌ها', 'callback_data' => 'tnotify']],
            [['text' => '🔗 صفحهٔ وضعیت من', 'callback_data' => 'share']],
            [['text' => '🛒 اشتراک', 'callback_data' => 'sub']],
            [['text' => '🔙 منو', 'callback_data' => 'menu']],
        ]);
    }

    private function sendUsersList(int $page = 0, ?int $editMsgId = null): void
    {
        [$text, $kb] = $this->usersList($page);
        if ($editMsgId) $this->edit($editMsgId, $text, $kb);
        else $this->send($text, $kb);
    }

    /** @return array{0:string,1:string} */
    private function usersList(int $page): array
    {
        $perPage = 10;
        $total = (int)Db::val('SELECT COUNT(*) FROM `user`');
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(0, min($page, $pages - 1));
        $rows = Db::all('SELECT * FROM `user` ORDER BY `last_seen` DESC LIMIT ' . $perPage . ' OFFSET ' . ($page * $perPage));
        $text = "👥 <b>کاربران</b> (" . faNum($total) . " نفر — صفحهٔ " . faNum($page + 1) . " از " . faNum($pages) . ")\n\n";
        $kb = [];
        foreach ($rows as $r) {
            $sites = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `user_id` = ?', [$r['id']]);
            $icon = (int)$r['is_blocked'] === 1 ? '🚫' : ((int)$r['access'] === 1 ? '🟢' : '⏳');
            $text .= $icon . " " . tgH((string)$r['name']) . " <code>{$r['id']}</code> — " . faNum($sites) . " سایت\n";
            $kb[] = [['text' => $icon . ' ' . mb_substr($r['name'] ?: (string)$r['id'], 0, 24), 'callback_data' => 'auser:' . $r['id']]];
        }
        if ($total === 0) $text .= "هنوز کاربری ثبت نشده است.";
        $nav = [];
        if ($page > 0) $nav[] = ['text' => '⏮ قبلی', 'callback_data' => 'ausers:' . ($page - 1)];
        if ($page < $pages - 1) $nav[] = ['text' => 'بعدی ⏭', 'callback_data' => 'ausers:' . ($page + 1)];
        if ($nav) $kb[] = $nav;
        $kb[] = [['text' => '🔙 منو', 'callback_data' => 'menu']];
        return [$text, BotApi::ikb($kb)];
    }

    private function userMenu(array $t): string
    {
        $blocked = (int)$t['is_blocked'] === 1;
        return BotApi::ikb([
            [['text' => $blocked ? '✅ رفع مسدودی' : '🚫 مسدود کردن', 'callback_data' => 'aub:' . $t['id']],
             ['text' => '💎 ویژه کردن', 'callback_data' => 'auv:' . $t['id']]],
            [['text' => '🗑 حذف کاربر', 'callback_data' => 'aud:' . $t['id']]],
            [['text' => '🔙 لیست کاربران', 'callback_data' => 'ausers:0']],
        ]);
    }

    private function sendPayments(?int $editMsgId = null): void
    {
        $rows = Db::all("SELECT * FROM `payments` WHERE `status` = 'pending' ORDER BY `id` DESC LIMIT 10");
        if (!$rows) {
            $txt = "💳 پرداخت در انتظار بررسی وجود ندارد.";
            $kb = BotApi::ikb([[['text' => '🔙 منو', 'callback_data' => 'menu']]]);
        } else {
            $txt = "💳 <b>پرداخت‌های در انتظار</b>\n\n";
            $kb = [];
            foreach ($rows as $r) {
                $txt .= "▫️ #" . faNum($r['id']) . " — کاربر <code>{$r['user_id']}</code> — " . faMoney((int)$r['amount']) . "\n";
                $kb[] = [['text' => 'بررسی #' . faNum($r['id']), 'callback_data' => 'apayok:' . $r['id']]];
            }
            $kb[] = [['text' => '🔙 منو', 'callback_data' => 'menu']];
            $kb = BotApi::ikb($kb);
        }
        if ($editMsgId) $this->edit($editMsgId, $txt, $kb);
        else $this->send($txt, $kb);
    }

    // ================================================================ ارسال

    private function send(string $text, ?string $kb = null, array $extra = []): void
    {
        if ($kb !== null) $extra['reply_markup'] = $kb;
        tgSend($this->chatId, $text, $extra);
    }

    private function edit(int $msgId, string $text, ?string $kb = null): void
    {
        if ($msgId <= 0) { $this->send($text, $kb); return; }
        $extra = [];
        if ($kb !== null) $extra['reply_markup'] = $kb;
        BotApi::edit($this->token, $this->chatId, $msgId, $text, $extra);
    }
}
