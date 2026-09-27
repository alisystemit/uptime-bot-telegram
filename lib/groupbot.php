<?php
/**
 * ===== ربات در گروه و کانال =====
 *
 * در گروه/کانال، ربات با «دستور» کار می‌کند (چون دکمهٔ شیشه‌ای در پیام کانال کار
 * نمی‌کند و در گروه هم شلوغ می‌شود):
 *
 *   /add [آدرس] [نام]   افزودن مانیتور (فقط ادمین)
 *   /list               فهرست مانیتورها
 *   /status [#id]       جزئیات یک مانیتور
 *   /check [all|#id]    بررسی فوری
 *   /pause [all|#id]    توقف موقت
 *   /resume [all|#id]   ادامه
 *   /remove #id         حذف (با تأیید)
 *   /incidents [all]    تاریخچهٔ رخدادها
 *   /page               لینک صفحهٔ وضعیت عمومی گروه
 *   /notify             روشن/خاموش کردن اعلان گروه
 *   /domain <دامنه>     پایش انقضای دامنه
 *   /help , /start
 *
 * سقف مانیتورهای گروه از سقف شخصی جداست (setting: group_max_sites) تا اعضای
 * گروه بتوانند بدون مصرف سهمیهٔ شخصی خود چیزی اضافه کنند.
 */
class GroupBot
{
    private array $cfg;
    private string $token;
    private int $chatId = 0;
    private string $chatType = 'group';
    private string $chatTitle = '';
    private int $uid = 0;
    private int $mid = 0;
    private string $name = '';
    private string $username = '';
    private array $hub = [];

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $this->token = (string)($cfg['bot_token'] ?? '');
    }

    // ---------------------------------------------------------------- ورودی

    /** پیام در گروه یا کانال */
    public function onMessage(array $msg, array $from): void
    {
        $chat = $msg['chat'] ?? [];
        $this->chatId = (int)($chat['id'] ?? 0);
        $this->chatType = (string)($chat['type'] ?? 'group');
        $this->chatTitle = (string)($chat['title'] ?? '');
        $this->mid = (int)($msg['message_id'] ?? 0);
        $this->uid = (int)($from['id'] ?? 0);
        $this->name = trim((string)($from['first_name'] ?? '') . ' ' . (string)($from['last_name'] ?? ''));
        $this->username = (string)($from['username'] ?? '');

        if ($this->chatId === 0) return;
        $this->hub = Group::touch($this->chatId, $this->chatType, $this->chatTitle);

        $text = trim((string)($msg['text'] ?? $msg['caption'] ?? ''));

        // ---- ادامهٔ یک مرحلهٔ نیمه‌کاره ----
        $st = Group::step($this->chatId);
        if ($st['step'] !== 'idle' && $text !== '' && $text[0] !== '/') {
            if ((int)$st['step_user'] === $this->uid) {
                $this->handleStep($text, $st);
                return;
            }
            $this->say('⏳ یکی دیگر در حال ثبت مانیتور است؛ کمی صبر کنید یا دستور <code>/cancel</code> را بفرستید.');
            return;
        }
        if ($text === '/cancel' || $text === '/cancel@' . botUsername() || $text === '❌ انصراف') {
            Group::clearStep($this->chatId);
            $this->say('انصراف داده شد.');
            return;
        }

        if ($text === '' || $text[0] !== '/') return;
        $p = parseCommand($text);
        if ($p['cmd'] === '') return;

        if ($p['cmd'] === 'start' || $p['cmd'] === 'help') {
            $this->help();
            return;
        }
        if ($p['cmd'] === 'add' || $p['cmd'] === 'monitor' || $p['cmd'] === 'new') {
            $this->cmdAdd($p['rest']);
            return;
        }
        if ($p['cmd'] === 'list' || $p['cmd'] === 'monitors' || $p['cmd'] === 'statuses') {
            $this->cmdList();
            return;
        }
        if ($p['cmd'] === 'status' || $p['cmd'] === 'info') {
            $this->cmdStatus($p['rest']);
            return;
        }
        if ($p['cmd'] === 'check' || $p['cmd'] === 'test') {
            $this->cmdCheck($p['rest']);
            return;
        }
        if ($p['cmd'] === 'pause' || $p['cmd'] === 'stop') {
            $this->cmdPause($p['rest'], true);
            return;
        }
        if ($p['cmd'] === 'resume' || $p['cmd'] === 'start2') {
            $this->cmdPause($p['rest'], false);
            return;
        }
        if ($p['cmd'] === 'remove' || $p['cmd'] === 'delete' || $p['cmd'] === 'rm') {
            $this->cmdRemove($p['rest']);
            return;
        }
        if ($p['cmd'] === 'incidents' || $p['cmd'] === 'log' || $p['cmd'] === 'history') {
            $this->cmdIncidents($p['rest']);
            return;
        }
        if ($p['cmd'] === 'page' || $p['cmd'] === 'link' || $p['cmd'] === 'share') {
            $this->cmdPage();
            return;
        }
        if ($p['cmd'] === 'notify') {
            $this->cmdNotify($p['rest']);
            return;
        }
        if ($p['cmd'] === 'domain') {
            $this->cmdDomain($p['rest']);
            return;
        }
        $this->say('❓ دستور <code>/' . h($p['cmd']) . '</code> شناخته نشد.\nبرای راهنما /help را بزنید.');
    }

    /** پیام کانال (کانال‌ها پیام معمولی ندارند) */
    public function onChannelPost(array $post): void
    {
        $chat = $post['chat'] ?? [];
        $this->chatId = (int)($chat['id'] ?? 0);
        $this->chatType = 'channel';
        $this->chatTitle = (string)($chat['title'] ?? '');
        $this->mid = (int)($post['message_id'] ?? 0);
        if ($this->chatId === 0) return;
        $this->hub = Group::touch($this->chatId, 'channel', $this->chatTitle);
        $text = trim((string)($post['text'] ?? ''));
        if ($text === '' || $text[0] !== '/') return;
        $p = parseCommand($text);
        if (in_array($p['cmd'], ['status', 'list', 'incidents', 'page', 'help', 'start'], true)) {
            $this->onMessage(['chat' => $chat, 'text' => $text, 'message_id' => $this->mid], ['id' => 0]);
        }
    }

    // ---------------------------------------------------------------- دستورها

    private function cmdAdd(string $rest): void
    {
        if (!$this->requireAdmin('افزودن مانیتور')) return;
        $parts = preg_split('/\s+/u', trim($rest)) ?: [];
        $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

        if (!$parts) {
            Group::setStep($this->chatId, 'await_site', $this->uid, []);
            $this->say("🔗 <b>آدرس مانیتور را بفرستید</b>\n\n"
                . "مثال: <code>https://example.com</code>\n"
                . "یا بعد از آدرس، یک نام اختیاری بنویسید:\n<code>https://example.com سایت اصلی</code>\n\n"
                . "انصراف: /cancel");
            return;
        }

        $url = array_shift($parts);
        $label = trim(implode(' ', $parts));
        $this->insertSite($url, $label !== '' ? $label : '');
    }

    private function insertSite(string $url, string $label): void
    {
        $norm = normalizeTarget($url);
        if (!$norm['ok']) {
            $this->say("❌ " . h($norm['error']));
            return;
        }
        $max = Group::maxSites($this->hub);
        $count = Group::count($this->chatId);
        if ($count >= $max) {
            $this->say("⛔️ سقف مانیتورهای این گروه تکمیل است (" . faNum($count) . " از " . faNum($max) . ").\n"
                . "برای تغییر سقف با مدیر ربات تماس بگیرید.");
            return;
        }
        $dup = (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `chat_id` = ? AND `target` = ?', [$this->chatId, $norm['target']]);
        if ($dup > 0) {
            $this->say("⚠️ این آدرس از قبل در این گروه ثبت شده است.\n<code>" . h($norm['target']) . "</code>");
            return;
        }

        $userId = $this->ensureUser();
        $token = uniqueToken(20, static fn(string $t): bool => (int)Db::val('SELECT COUNT(*) FROM `site` WHERE `share_token` = ?', [$t]) > 0);
        try {
            Db::q(
                'INSERT INTO `site` (`user_id`,`chat_id`,`chat_title`,`target`,`label`,`type`,`host`,`port`,`share_token`,`created_at`)
                 VALUES (?,?,?,?,?,?,?,?,?,NOW())',
                [
                    $userId, $this->chatId, truncateFa($this->chatTitle, 150),
                    $norm['target'], mb_subtr(($label !== '' ? $label : $norm['label']), 0, 200) ?: $norm['label'],
                    $norm['type'], $norm['host'], (int)$norm['port'], $token,
                ]
            );
        } catch (Throwable $e) {
            $this->say('❌ ثبت مانیتور ناموفق بود. دوباره تلاش کنید.');
            uptimeLog('error', 'group add site failed: ' . $e->getMessage());
            return;
        }
        $siteId = (int)Db::val('SELECT MAX(`id`) FROM `site` WHERE `chat_id` = ? AND `target` = ?', [$this->chatId, $norm['target']]);
        $res = Monitor::checkSite($siteId);
        $r = $res['result'] ?? ['ok' => false, 'error' => '—'];
        $cnt = Group::count($this->chatId);

        $this->say("✅ <b>مانیتور اضافه شد</b>\n\n"
            . "🔗 <code>" . h($norm['target']) . "</code>\n"
            . "🧭 نوع: " . typeName($norm['type']) . "\n"
            . "🆔 شناسه: <code>#" . faNum($siteId) . "</code>\n"
            . "⏱ هر " . faNum(max(10, Db::getInt('check_interval', 20))) . " ثانیه بررسی می‌شود\n\n"
            . "نتیجهٔ اول: " . (!empty($r['ok']) ? "🟢 فعال — " . faMs((int)$r['ms']) : "🔴 قطع — " . h((string)($r['error'] ?? '')))
            . "\n\nمانیتورهای گروه: " . faNum($cnt) . " از " . faNum($max)
            . "\nℹ️ حذف: <code>/remove #" . faNum($siteId) . "</code>");
    }

    private function cmdList(): void
    {
        $sites = Group::sites($this->chatId);
        if (!$sites) {
            $this->say("📋 <b>مانیتورهای این گروه</b>\n\nهنوز مانیتوری ثبت نشده است.\n"
                . ($this->isAdmin() ? "با <code>/add https://example.com</code> اضافه کنید." : 'از مدیر گروه بخواهید اضافه کند.'));
            return;
        }
        $sum = Stats::groupSummary($this->chatId);
        $txt = "📋 <b>مانیتورهای گروه</b> (" . faNum($sum['total']) . " از " . faNum(Group::maxSites($this->hub)) . ")\n\n";
        foreach ($sites as $s) {
            $state = siteState($s);
            $u = Stats::uptime($s, 1);
            $icon = ['up' => '🟢', 'down' => '🔴', 'slow' => '🟠', 'paused' => '⏸', 'unknown' => '🟡'][$state] ?? '⚪️';
            $txt .= $icon . " <code>#" . faNum((int)$s['id']) . " " . tgH($s['label'] ?: $s['target']) . "</code>\n";
            $txt .= "    " . typeName((string)$s['type']) . " • ۲۴س: " . ($u['pct'] !== null ? faPct($u['pct']) : '—');
            if ($state === 'slow') $txt .= " • 🟠 کند";
            if ($state === 'paused') $txt .= " • ⏸ متوقف";
            if ($state === 'down') $txt .= " • 🔴 از " . timeAgo($s['last_down_at'], tzOffset());
            $txt .= "\n";
        }
        $e = Stats::engine();
        $txt .= "\n🟢 فعال: " . faNum($sum['up']) . " • 🔴 قطع: " . faNum($sum['down']) . " • 🟠 کند: " . faNum($sum['slow'])
            . " • ⏸ متوقف: " . faNum($sum['paused']);
        $txt .= "\n📈 میانگین ۲۴س: " . faPct($sum['uptime24']);
        $txt .= "\n🔁 موتور: " . ($e['paused'] ? '⏸ متوقف' : ($e['cron_healthy'] ? '🟢 فعال' : '🟡 بدون کرون'))
            . " • فاصله: " . faNum($e['interval']) . " ثانیه";
        $txt .= "\nℹ️ <code>/page</code> برای لینک صفحهٔ عمومی";
        $this->say($txt);
    }

    private function cmdStatus(string $rest): void
    {
        $site = $this->pickSite($rest);
        if (!$site) return;
        $u24 = Stats::uptime($site, 1);
        $u7 = Stats::uptime($site, 7);
        $u30 = Stats::uptime($site, 30);
        $state = siteState($site);
        $icon = ['up' => '🟢', 'down' => '🔴', 'slow' => '🟠', 'paused' => '⏸', 'unknown' => '🟡'][$state] ?? '⚪️';
        $name = ['up' => 'فعال', 'down' => 'قطع', 'slow' => 'کند', 'paused' => 'متوقف', 'unknown' => 'نامشخص'][$state] ?? $state;

        $txt = "📊 <b>" . tgH($site['label'] ?: $site['target']) . "</b> (#" . faNum((int)$site['id']) . ")\n\n"
            . "🔗 <code>" . tgH((string)$site['target']) . "</code>\n"
            . "🧭 " . typeName((string)$site['type']) . " • وضعیت: {$icon} <b>{$name}</b>\n\n"
            . "📈 آپتایم: ۲۴س " . faPct($u24['pct']) . " • ۷روز " . faPct($u7['pct']) . " • ۳۰روز " . faPct($u30['pct']) . "\n"
            . "⚡️ پاسخ: " . faMs((int)$site['last_ms'])
            . ((int)$site['last_code'] > 0 ? " • کد " . faNum((int)$site['last_code']) : '')
            . "\n⏰ آخرین چک: " . timeAgo($site['last_check_at'], tzOffset());
        if (!empty($site['last_error']) && $state === 'down') {
            $txt .= "\n❌ دلیل: " . tgH((string)$site['last_error']);
        }
        if ((int)$site['max_ms'] > 0) $txt .= "\n🎯 حد کندی: " . faMs((int)$site['max_ms']);
        if (trim((string)$site['keyword']) !== '') $txt .= "\n🔎 کلیدواژه: <code>" . tgH(truncateFa((string)$site['keyword'], 40)) . "</code>";
        if ((int)$site['ssl_days'] >= -1 && (string)$site['ssl_check_at'] !== '') {
            $d = (int)$site['ssl_days'];
            $txt .= "\n🔐 گواهی SSL: " . ($d < 0 ? 'نامشخص' : faLeft($d * 86400) . ' دیگر')
                . (!empty($site['ssl_issuer']) ? ' (' . tgH(truncateFa((string)$site['ssl_issuer'], 30)) . ')' : '');
        }
        $inc = Stats::incidents((int)$site['id'], 3);
        if ($inc) {
            $txt .= "\n\n📜 <b>آخرین رخدادها</b>";
            foreach ($inc as $i) {
                $open = empty($i['end_at']);
                $dur = $open ? max(0, time() - (int)strtotime((string)$i['start_at'])) : (int)$i['duration'];
                $txt .= "\n" . ((string)$i['kind'] === 'down' ? '🔴' : '🟠') . " "
                    . ((string)$i['kind'] === 'down' ? 'قطعی' : 'کندی') . " — "
                    . faDuration($dur) . ($open ? ' (ادامه دارد)' : '')
                    . ' • ' . timeAgo($i['start_at'], tzOffset());
            }
        }
        $txt .= "\n\n" . Stats::barEmoji(Stats::recent($site, 40));
        $this->say($txt);
    }

    private function cmdCheck(string $rest): void
    {
        if (!$this->requireAdmin('بررسی فوری')) return;
        $rest = trim($rest);
        if ($rest === '' || strtolower($rest) === 'all' || $rest === 'همه') {
            $sites = Group::sites($this->chatId);
            if (!$sites) { $this->say('مانیتوری وجود ندارد.'); return; }
            $ok = 0;
            foreach (array_slice($sites, 0, 10) as $s) {
                $r = Monitor::checkSite((int)$s['id']);
                $ok += !empty($r['result']['ok']) ? 1 : 0;
            }
            $this->say("🔁 بررسی فوری انجام شد: 🟢 " . faNum($ok) . " فعال • 🔴 " . faNum(count($sites) - $ok) . " قطع");
            return;
        }
        $site = $this->pickSite($rest);
        if (!$site) return;
        $r = Monitor::checkSite((int)$site['id']);
        $res = $r['result'] ?? [];
        $this->say("🔁 <b>بررسی فوری</b>\n<code>" . tgH((string)$site['target']) . "</code>\n\n"
            . (!empty($res['ok']) ? "🟢 فعال — " . faMs((int)($res['ms'] ?? 0)) : "🔴 قطع — " . tgH((string)($res['error'] ?? 'بدون پاسخ'))));
    }

    private function cmdPause(string $rest, bool $pause): void
    {
        if (!$this->requireAdmin($pause ? 'توقف' : 'ادامه')) return;
        $rest = trim($rest);
        if ($rest === '' || strtolower($rest) === 'all' || $rest === 'همه') {
            if ($pause) {
                $ids = array_map(static fn($r) => (int)$r['id'], Group::sites($this->chatId));
                foreach ($ids as $id) Db::q('UPDATE `site` SET `paused` = 1, `paused_since` = NOW() WHERE `id` = ? AND `paused` = 0', [$id]);
                Db::logEvent(0, 'pause_group', (string)$this->chatId . ':' . count($ids));
                $this->say('⏸ همهٔ مانیتورهای گروه متوقف شد (' . faNum(count($ids)) . ' مورد).');
            } else {
                $ids = array_map(static fn($r) => (int)$r['id'], Db::all('SELECT `id` FROM `site` WHERE `chat_id` = ? AND `paused` = 1', [$this->chatId]));
                foreach ($ids as $id) {
                    $since = Db::val('SELECT paused_since FROM `site` WHERE `id` = ?', [$id]);
                    $dur = $since ? max(0, time() - (int)strtotime((string)$since)) : 0;
                    Db::q('UPDATE `site` SET `paused` = 0, `paused_since` = NULL, `paused_total` = `paused_total` + ? WHERE `id` = ?', [$dur, $id]);
                }
                Db::logEvent(0, 'resume_group', (string)$this->chatId . ':' . count($ids));
                $this->say('▶️ همهٔ مانیتورهای گروه ادامه یافت (' . faNum(count($ids)) . ' مورد).');
            }
            return;
        }
        $site = $this->pickSite($rest);
        if (!$site) return;
        if ($pause) {
            if ((int)$site['paused'] === 1) { $this->say('این مانیتور از قبل متوقف است.'); return; }
            Db::q('UPDATE `site` SET `paused` = 1, `paused_since` = NOW() WHERE `id` = ?', [$site['id']]);
            Db::logEvent(0, 'pause_site', (string)$site['target']);
            $this->say('⏸ مانیتور <code>' . tgH(truncateFa((string)$site['target'], 40)) . '</code> متوقف شد.');
        } else {
            if ((int)$site['paused'] === 0) { $this->say('این مانیتور فعال است.'); return; }
            $since = $site['paused_since'] ? (int)strtotime((string)$site['paused_since']) : time();
            $dur = max(0, time() - $since);
            Db::q('UPDATE `site` SET `paused` = 0, `paused_since` = NULL, `paused_total` = `paused_total` + ? WHERE `id` = ?', [$dur, $site['id']]);
            Db::logEvent(0, 'resume_site', (string)$site['target']);
            $this->say('▶️ ادامه یافت (مدت توقف قبلی: ' . faDuration($dur) . ').');
        }
    }

    private function cmdRemove(string $rest): void
    {
        if (!$this->requireAdmin('حذف مانیتور')) return;
        $rest = trim($rest);
        if ($rest === '') { $this->say("فرمت: <code>/remove #12</code>\nبرای دیدن شناسه‌ها /list را بزنید."); return; }
        $site = $this->pickSite($rest);
        if (!$site) return;
        if ((string)($this->hub['step'] ?? '') === 'await_del' && (int)($this->hub['step_user'] ?? 0) === $this->uid
            && (int)($this->hub['temp']['site'] ?? 0) === (int)$site['id']) {
            Group::clearStep($this->chatId);
            self::purgeSite((int)$site['id']);
            Db::logEvent(0, 'delete_site', (string)$site['target']);
            $this->say("🗑 مانیتور <code>" . tgH(truncateFa((string)$site['target'], 50)) . "</code> و تاریخچهٔ آن حذف شد.");
            return;
        }
        Group::setStep($this->chatId, 'await_del', $this->uid, ['site' => (int)$site['id']]);
        $this->say("⚠️ <b>حذف مانیتور</b>\n<code>" . tgH(truncateFa((string)$site['target'], 60)) . "</code>\n\n"
            . "همهٔ آمار و تاریخچهٔ آن پاک می‌شود.\nبرای تأیید دوباره <code>/remove #" . faNum((int)$site['id']) . "</code> را بفرستید\n(انصراف: /cancel)");
    }

    private function cmdIncidents(string $rest): void
    {
        $rest = trim($rest);
        $siteId = 0;
        if ($rest !== '' && $rest !== 'all' && $rest !== 'همه') {
            $site = $this->pickSite($rest);
            if (!$site) return;
            $siteId = (int)$site['id'];
        }
        $rows = $siteId > 0
            ? Stats::incidents($siteId, 10)
            : Db::all(
                'SELECT i.*, s.`label`, s.`target` FROM `incident` i JOIN `site` s ON s.id = i.site_id
                  WHERE s.chat_id = ? ORDER BY i.start_at DESC, i.id DESC LIMIT 10',
                [$this->chatId]
            );
        if (!$rows) {
            $this->say($siteId > 0
                ? '📜 این مانیتور تا این لحظه هیچ قطعی یا کندی ثبت‌شده‌ای ندارد. 🎉'
                : '📜 در بازهٔ نگهداری‌شده رخدادی ثبت نشده است. 🎉');
            return;
        }
        $txt = "📜 <b>رخدادهای اخیر</b>\n\n";
        foreach ($rows as $i) {
            $open = empty($i['end_at']);
            $dur = $open ? max(0, time() - (int)strtotime((string)$i['start_at'])) : (int)$i['duration'];
            $name = (string)($i['label'] !== '' ? $i['label'] : ($i['target'] ?? ''));
            $txt .= (string)$i['kind'] === 'down' ? '🔴' : '🟠';
            $txt .= " <code>#" . faNum((int)$i['site_id']) . " " . tgH(truncateFa($name, 34)) . "</code>\n";
            $txt .= "    " . ((string)$i['kind'] === 'down' ? 'قطعی' : 'کندی') . ' • ' . faDuration($dur) . ($open ? ' (ادامه دارد)' : '');
            $txt .= "\n    🕒 " . faDateTime((string)$i['start_at']) . ' • ' . timeAgo($i['start_at'], tzOffset());
            if (!empty($i['reason'])) $txt .= "\n    ❌ " . tgH(truncateFa((string)$i['reason'], 60));
            $txt .= "\n";
        }
        $this->say($txt);
    }

    private function cmdPage(): void
    {
        $url = Group::statusUrl($this->cfg, $this->hub);
        $sum = Stats::groupSummary($this->chatId);
        $this->say("🔗 <b>صفحهٔ وضعیت عمومی گروه</b>\n\n"
            . "این لینک را می‌توانید در سایت یا پیام‌رسان بفرستید تا همه وضعیت زندهٔ مانیتورهای گروه را ببینند.\n\n"
            . "🌐 <code>" . h($url) . "</code>\n\n"
            . "📊 مانیتورها: " . faNum($sum['total']) . " • میانگین ۲۴س: " . faPct($sum['uptime24']) . "\n"
            . "🔁 بروزرسانی خودکار هر ۲۵ ثانیه");
    }

    private function cmdNotify(string $rest): void
    {
        if (!$this->requireAdmin('تغییر تنظیمات اعلان')) return;
        $cur = (int)($this->hub['notify'] ?? 1);
        $rest = strtolower(trim($rest));
        $new = in_array($rest, ['on', '1', 'روشن'], true) ? 1 : (in_array($rest, ['off', '0', 'خاموش'], true) ? 0 : ($cur ? 0 : 1));
        Db::q('UPDATE `chat_hub` SET `notify` = ? WHERE `chat_id` = ?', [$new, $this->chatId]);
        $this->say($new
            ? '🔔 اعلان‌های این گروه <b>روشن</b> شد.'
            : '🔕 اعلان‌های این گروه <b>خاموش</b> شد.\n⚠️ قطعی/برقراری دیگر در این گروه اعلام نمی‌شود.');
    }

    private function cmdDomain(string $rest): void
    {
        if (!$this->requireAdmin('پایش دامنه')) return;
        $rest = trim($rest);
        if ($rest === '' || $rest === 'list') {
            $rows = Stats::domains(0, $this->chatId);
            if (!$rows) { $this->say("🌐 دامنه‌ای ثبت نشده است.\nفرمت: <code>/domain example.ir</code>"); return; }
            $txt = "🌐 <b>دامنه‌های پایش‌شده</b>\n\n";
            foreach ($rows as $d) {
                $exp = (string)$d['expires_at'];
                $left = $exp !== '' ? (int)floor((strtotime($exp) - time()) / 86400) : null;
                $icon = $left === null ? '⚪️' : ($left < 0 ? '🔴' : ($left <= (int)$d['warn_days'] ? '🟠' : '🟢'));
                $txt .= $icon . ' <code>' . tgH((string)$d['domain']) . "</code>\n";
                $txt .= '    ' . ($left === null ? 'نامشخص' : ($left < 0 ? 'منقضی شده' : faLeft($left * 86400) . ' دیگر'))
                    . ' • بررسی: ' . timeAgo($d['last_check'], tzOffset()) . "\n";
                if (!empty($d['last_error'])) $txt .= '    ⚠️ ' . tgH(truncateFa((string)$d['last_error'], 60)) . "\n";
            }
            $this->say($txt);
            return;
        }

        $d = trim($rest);
        $norm = normalizeTarget($d);
        if (!$norm['ok'] || $norm['type'] !== 'ping') {
            $this->say("❌ یک دامنهٔ معتبر بفرستید (بدون https و بدون پورت).\nمثال: <code>example.ir</code>");
            return;
        }
        $domain = baseDomain((string)$norm['host']);
        $exists = Db::one('SELECT * FROM `domain_watch` WHERE user_id = 0 AND chat_id = ? AND `domain` = ?', [$this->chatId, $domain]);
        if ($exists) { $this->say('این دامنه از قبل پایش می‌شود.'); return; }

        $info = Domain::whois($domain);
        try {
            Db::q(
                'INSERT INTO `domain_watch` (`user_id`,`chat_id`,`domain`,`warn_days`,`expires_at`,`registrar`,`status`,`last_check`,`last_error`,`created_at`)
                 VALUES (0,?,?,?,?,?,?,NOW(),?,NOW())',
                [
                    $this->chatId, $domain, max(1, Db::getInt('domain_warn_days', 14)),
                    $info['expires'], mb_substr((string)$info['registrar'], 0, 160),
                    !empty($info['ok']) ? 'ok' : 'unknown',
                    mb_substr((string)$info['error'], 0, 190),
                ]
            );
        } catch (Throwable $e) {
            $this->say('❌ ثبت دامنه ناموفق بود (احتمالاً تکراری است).');
            return;
        }
        $txt = "✅ <b>دامنه ثبت شد</b>\n🌐 <code>" . h($domain) . "</code>\n\n";
        if (!empty($info['ok'])) {
            $left = (int)floor((strtotime((string)$info['expires']) - time()) / 86400);
            $txt .= "🗓 انقضا: " . faDay($info['expires']) . ' — ' . ($left < 0 ? 'منقضی شده' : faLeft($left * 86400) . ' دیگر') . "\n";
            if (!empty($info['registrar'])) $txt .= "🏛 ثبت‌کننده: " . tgH(truncateFa((string)$info['registrar'], 40)) . "\n";
            $txt .= "\n🔔 اگر کمتر از " . faNum(max(1, Db::getInt('domain_warn_days', 14))) . " روز به انقضا بماند، همین‌جا هشدار می‌گیرید.";
        } else {
            $txt .= "⚠️ استعلام WHOIS انجام نشد: " . tgH((string)$info['error']) . "\n"
                . "دامنه ذخیره شد و هر " . faNum(6) . " ساعت دوباره بررسی می‌شود.";
        }
        $this->say($txt);
    }

    // ---------------------------------------------------------------- کمکی‌ها

    private function handleStep(string $text, array $st): void
    {
        switch ((string)$st['step']) {
            case 'await_site':
                $parts = preg_split('/\s+/u', trim($text)) ?: [];
                $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
                $url = (string)($parts[0] ?? '');
                $label = trim(implode(' ', array_slice($parts, 1)));
                Group::clearStep($this->chatId);
                if ($url === '') { $this->say('❌ آدرس نامعتبر است.'); return; }
                $this->insertSite($url, $label);
                return;
        }
        Group::clearStep($this->chatId);
    }

    /** انتخاب سایت از روی «#id»، شمارهٔ ترتیبی یا آخرین آدرس ارسال‌شده */
    private function pickSite(string $ref): ?array
    {
        $ref = trim($ref);
        $ref = ltrim($ref, '#');
        $ref = faToLatin($ref);
        $sites = Group::sites($this->chatId);
        if (!$sites) { $this->say('هنوز مانیتوری در این گروه ثبت نشده است.'); return null; }
        if ($ref === '' || ctype_digit($ref)) {
            $n = $ref === '' ? count($sites) : (int)$ref;
            if (ctype_digit($ref) && $ref !== '') {
                foreach ($sites as $s) if ((int)$s['id'] === $n) return $s;
            }
            if ($n >= 1 && $n <= count($sites)) return $sites[$n - 1];
        }
        $this->say('❌ مانیتوری با این شناسه پیدا نشد. با /list فهرست را ببینید.');
        return null;
    }

    private function isAdmin(): bool
    {
        return Group::isAdmin($this->chatId, $this->uid);
    }

    private function requireAdmin(string $what): bool
    {
        if ($this->isAdmin()) return true;
        $hub = Group::get($this->chatId);
        $kind = $hub && (string)$hub['chat_type'] === 'channel' ? 'کانال' : 'گروه';
        $this->say("⛔️ فقط مدیران {$kind} می‌توانند «{$what}» را انجام دهند.");
        return false;
    }

    /** کاربرِ فرستنده (در صورت نبود، ساخته می‌شود تا مالک مانیتور مشخص باشد) */
    private function ensureUser(): int
    {
        $row = Db::one('SELECT `id` FROM `user` WHERE `id` = ?', [$this->uid]);
        if ($row) return (int)$row['id'];
        $isAdmin = isBotAdmin($this->uid);
        $maxUsers = Db::getInt('max_users', 0);
        if (!$isAdmin && $maxUsers > 0) {
            $total = (int)Db::val('SELECT COUNT(*) FROM `user` WHERE `is_admin` = 0');
            if ($total >= $maxUsers) {
                $this->say('⛔️ ظرفیت ربات تکمیل است؛ ابتدا در چت خصوصی /start را بزنید.');
                return 0;
            }
        }
        $mode = Db::get('access_mode', 'open');
        $access = ($isAdmin || $mode === 'open') ? 1 : 0;
        $token = uniqueToken(20, static fn(string $t): bool => (int)Db::val('SELECT COUNT(*) FROM `user` WHERE `share_token` = ?', [$t]) > 0);
        try {
            Db::q(
                'INSERT INTO `user` (`id`,`name`,`username`,`is_admin`,`access`,`share_token`,`step`,`created_at`,`last_seen`)
                 VALUES (?,?,?,?,?,?,\'idle\',NOW(),NOW())',
                [$this->uid, $this->name, $this->username, $isAdmin ? 1 : 0, $access, $token]
            );
        } catch (Throwable $e) {
            return 0;
        }
        Db::logEvent($this->uid, 'register', 'group');
        return $this->uid;
    }

    private function help(): void
    {
        $admin = $this->isAdmin();
        $txt = "📊 <b>ربات مانیتورینگ</b> — {$this->chatTitle}\n\n"
            . "این ربات هر " . faNum(max(10, Db::getInt('check_interval', 20))) . " ثانیه مانیتورها را چک می‌کند و "
            . "قطعی/برقراری را همین‌جا اعلام می‌کند.\n\n"
            . "<b>دستورها</b>\n"
            . "▫️ <code>/list</code> — فهرست مانیتورها با آپتایم\n"
            . "▫️ <code>/status #3</code> — جزئیات کامل یک مانیتور\n"
            . "▫️ <code>/incidents</code> — تاریخچهٔ قطعی‌ها\n"
            . "▫️ <code>/page</code> — لینک صفحهٔ وضعیت عمومی\n";
        if ($admin) {
            $txt .= "\n<b>دستورهای مدیر</b>\n"
                . "▫️ <code>/add https://example.com نام</code> — افزودن مانیتور\n"
                . "▫️ <code>/remove #3</code> — حذف (دو بار برای تأیید)\n"
                . "▫️ <code>/check all</code> — بررسی فوری همه\n"
                . "▫️ <code>/pause all</code> • <code>/resume all</code> — توقف/ادامهٔ موقت\n"
                . "▫️ <code>/notify off</code> — خاموش/روشن کردن اعلان گروه\n"
                . "▫️ <code>/domain example.ir</code> — پایش انقضای دامنه\n";
        }
        $sum = Stats::groupSummary($this->chatId);
        if ($sum['total'] > 0) {
            $txt .= "\n📊 وضعیت فعلی: 🟢 " . faNum($sum['up']) . " فعال • 🔴 " . faNum($sum['down']) . " قطع"
                . ($sum['slow'] > 0 ? " • 🟠 " . faNum($sum['slow']) . " کند" : '')
                . " • میانگین ۲۴س " . faPct($sum['uptime24']);
        }
        $this->say($txt);
    }

    private function say(string $text): void
    {
        tgSend($this->chatId, $text);
    }

    /** حذف کامل یک مانیتور (همهٔ داده‌های وابسته) */
    public static function purgeSite(int $siteId): void
    {
        try {
            Db::exec('DELETE FROM `check_log` WHERE `site_id` = ?', [$siteId]);
            Db::exec('DELETE FROM `uptime_hour` WHERE `site_id` = ?', [$siteId]);
            Db::exec('DELETE FROM `incident` WHERE `site_id` = ?', [$siteId]);
            Db::exec('DELETE FROM `site_share` WHERE `site_id` = ?', [$siteId]);
            Db::exec('DELETE FROM `site` WHERE `id` = ?', [$siteId]);
        } catch (Throwable $e) {
            uptimeLog('error', 'purgeSite failed: ' . $e->getMessage());
        }
    }
}
