<?php
/**
 * ===== رابط کاربری درگاه‌های پرداخت داخل ربات =====
 *
 * به‌صورت trait نوشته شده تا کلاس Bot (که بزرگ است) دست‌نخورده بماند و فقط
 * یک `use PayUi;` به آن اضافه شود.
 *
 * مسیرهای کاربر:
 *   🛒 اشتراک ویژه → 💳 پرداخت آنلاین → انتخاب درگاه → دریافت لینک/کارت
 *                                        → 🔄 بررسی پرداخت (دستی)
 * مسیرهای مدیر:
 *   ⚙️ پنل مدیریت → 💳 درگاه‌ها → فعال/غیرفعال، وارد کردن کلید، تنظیمات دلخواه
 */
trait PayUi
{
    // ================================================================ کاربر

    /** متن صفحهٔ «پرداخت آنلاین» (قبل از انتخاب درگاه) */
    private function payBuyText(): string
    {
        $gws = PayGws::all(true);
        $price = Db::getInt('price', 0);
        $days = max(1, Db::getInt('vip_days', 30));
        $txt = "💳 <b>پرداخت آنلاین اشتراک ویژه</b>\n\n"
            . "💰 مبلغ: " . faMoney($price) . "\n"
            . "🎁 مدت اشتراک: " . faNum($days) . " روز\n"
            . "🌐 سقف سایت‌ها: " . faNum(Db::getInt('vip_max_sites', 10)) . " سایت\n"
            . "🏷 درگاه‌های فعال: " . faNum(count($gws)) . "\n\n"
            . "یکی از درگاه‌های زیر را انتخاب کنید؛ فاکتور ساخته می‌شود و\n"
            . "بعد از پرداخت، اشتراک <b>خودکار</b> فعال می‌شود.\n";
        if ($this->isVip()) $txt .= "\n💎 شما الان هم اشتراک فعال دارید؛ پرداخت جدید، مدت را از زمان انقضا ادامه می‌دهد.\n";
        $pending = Pay::userOrders($this->uid, 10);
        $p = array_values(array_filter($pending, static fn($r) => (string)$r['status'] === 'pending'));
        if ($p) $txt .= "\n⚠️ " . faNum(count($p)) . " فاکتور در انتظار دارید. اگر پرداخت کرده‌اید، از «📄 فاکتورهای من» بررسی کنید.\n";
        return $txt;
    }

    /** منوی انتخاب درگاه پرداخت */
    private function payGatewayMenu(): string
    {
        $gws = PayGws::all(true);
        if (!$gws) {
            return BotApi::ikb([
                [['text' => '🎟 ورود کد فعال‌سازی', 'callback_data' => 'codein']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'menu']],
            ]);
        }
        $rows = [];
        foreach ($gws as $g) {
            $rows[] = [[
                'text' => PayGws::title((string)$g['code']) . ' • ' . faMoney((int)Db::getInt('price', 0)),
                'callback_data' => 'gw:' . (string)$g['code'],
            ]];
        }
        $rows[] = [['text' => '📄 فاکتورهای من', 'callback_data' => 'porder']];
        $rows[] = [['text' => '🎟 ورود کد فعال‌سازی', 'callback_data' => 'codein']];
        $rows[] = [['text' => '🔙 بازگشت', 'callback_data' => 'menu']];
        return BotApi::ikb($rows);
    }

    /** ساخت فاکتور برای یک درگاه */
    private function payGatewayCreate(string $code, ?int $msgId = null): void
    {
        if ($code === '') { $this->edit((int)$msgId, '❌ درگاه نامعتبر است.', $this->payGatewayMenu()); return; }
        $gw = PayGws::get($code);
        if (!$gw) {
            $this->edit((int)$msgId, '❌ چنین درگاهی وجود ندارد.', $this->payGatewayMenu());
            return;
        }
        $cls = PayGws::driver($code);
        if (!$cls || !class_exists($cls)) { $this->edit((int)$msgId, '❌ درایور این درگاه بارگذاری نشده.', $this->payGatewayMenu()); return; }
        $this->edit((int)$msgId, '⏳ در حال ساخت فاکتور…');

        $r = Pay::createOrder($this->uid, $code);
        if (empty($r['ok'])) {
            $this->edit((int)$msgId, "❌ ساخت فاکتور ناموفق بود.\n\n" . tgH((string)$r['error'])
                . "\n\nچند لحظه بعد دوباره تلاش کنید یا به مدیر پیام بدهید.", $this->payGatewayMenu());
            return;
        }

        $days = (int)($r['days'] ?? 30);
        if ($days <= 0) $days = 30;
        $card = (array)($r['card'] ?? []);
        $kindMap = ['card' => 'کارت‌به‌کارت خودکار', 'crypto' => 'ارز دیجیتال', 'bank' => 'درگاه بانکی'];

        $txt = "🧾 <b>فاکتور آمادهٔ پرداخت</b>\n\n"
            . "🏷 درگاه: " . PayGws::title($code) . " (" . ($kindMap[$cls::kind()] ?? '') . ")\n"
            . "💰 مبلغ: " . faMoney((int)$r['amount']) . "\n"
            . "🎁 اشتراک: " . faNum($days) . " روز\n"
            . "🆔 شماره فاکتور: <code>#" . faNum((int)$r['id']) . "</code>\n";

        // مبلغ دقیق قابل واریز (بعضی درگاه‌ها چند ریال اضافه می‌کنند)
        $payAmt = (int)($r['pay_amount'] ?? 0);
        $cur = 'rial';
        try { $cur = $cls::currency(); } catch (Throwable $e) { $cur = 'rial'; }
        $wantAmt = $cur === 'toman' ? (int)$payAmt : (int)floor((int)$payAmt / 10);
        if ($payAmt > 0 && $wantAmt !== (int)$r['amount']) {
            $txt .= "\n⚠️ <b>مبلغ دقیق قابل پرداخت: " . faNum($wantAmt) . " تومان</b>\n"
                . "این سرویس برای تشخیص واریز، چند ریال به مبلغ اضافه می‌کند؛\n"
                . "حتماً همین عدد را واریز کنید.";
        }

        if (!empty($card['number'])) {
            $txt .= "\n\n💳 <b>شمارهٔ کارت</b>\n<code>" . tgH((string)$card['number']) . "</code>\n"
                . (trim((string)($card['holder'] ?? '')) !== '' ? "👤 صاحب کارت: " . tgH((string)$card['holder']) . "\n" : '')
                . (trim((string)($card['sheba'] ?? '')) !== '' ? "🏦 شبا: <code>" . tgH((string)$card['sheba']) . "</code>\n" : '');
        }
        if (!empty($r['expires_at'])) {
            $txt .= "\n⏳ مهلت پرداخت: " . faDateTime(date('Y-m-d H:i:s', (int)$r['expires_at'])) . " (تا " . faLeft((int)$r['expires_at'] - time()) . ")";
        }

        $txt .= "\n\nپس از پرداخت، به‌صورت خودکار فعال می‌شود. اگر پیامی نرسید،\n"
            . "دکمهٔ زیر را بزنید تا دستی بررسی کنیم.";

        $url = (string)$r['pay_url'];
        $kb = [];
        if ($url !== '') $kb[] = [['text' => '🔗 رفتن به صفحهٔ پرداخت', 'url' => $url]];
        $kb[] = [['text' => '🔄 بررسی پرداخت', 'callback_data' => 'pcheck:' . (int)$r['id']]];
        $kb[] = [['text' => '💳 درگاه دیگر', 'callback_data' => 'buy'], ['text' => '🔙 بازگشت', 'callback_data' => 'menu']];

        $this->edit((int)$msgId, $txt, BotApi::ikb($kb));
    }

    /** بررسی دستی وضعیت یک فاکتور */
    private function payCheck(int $paymentId, ?int $msgId = null): void
    {
        $p = Db::one('SELECT * FROM `payments` WHERE `id` = ? AND `user_id` = ?', [$paymentId, $this->uid]);
        if (!$p) {
            $this->edit((int)$msgId, '❌ فاکتور پیدا نشد.', $this->payGatewayMenu());
            return;
        }
        $this->edit((int)$msgId, '🔄 در حال استعلام از درگاه…');
        $v = Pay::verifyOrder($paymentId);
        if (!empty($v['ok'])) {
            $until = (string)Db::val('SELECT `plan_until` FROM `user` WHERE `id` = ?', [$this->uid]);
            $this->edit((int)$msgId, "✅ <b>پرداخت تأیید شد!</b>\n\n"
                . "💎 اشتراک ویژه شما فعال است.\n📅 تا: " . faDateTime($until) . "\n"
                . "🌐 سقف سایت‌ها: " . faNum(Db::getInt('vip_max_sites', 10)),
                $this->mainMenu());
            return;
        }
        $this->edit((int)$msgId, "⏳ هنوز پرداخت تأیید نشده است.\n\n"
            . "📌 " . tgH((string)$v['error']) . "\n\n"
            . "اگر مطمئنید پرداخت کرده‌اید، ۳۰ ثانیه بعد دوباره بزنید؛ "
            . "بعضی درگاه‌ها تأیید را با تأخیر انجام می‌دهند.\n"
            . "اگر پول از حساب کم شده ولی تأیید نمی‌شود، رسید را برای مدیر بفرستید.",
            BotApi::ikb([
                [['text' => '🔄 بررسی دوباره', 'callback_data' => 'pcheck:' . $paymentId]],
                [['text' => '💬 ارسال رسید به مدیر', 'callback_data' => 'buy']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'menu']],
            ]));
    }

    /**
     * بازگشت کاربر از صفحهٔ درگاه: همهٔ فاکتورهای باز او را یکی‌یکی بررسی می‌کند.
     * (لینک عمیق ?start=paycheck که pay.php به کاربر می‌دهد)
     */
    private function payCheckAll(): void
    {
        $open = array_values(array_filter(
            Pay::userOrders($this->uid, 5),
            static fn($r) => (string)$r['status'] === 'pending'
        ));
        if (!$open) {
            $this->send($this->isVip()
                ? "💎 اشتراک ویژهٔ شما فعال است.\n📅 تا: " . faDateTime($this->u['plan_until'])
                : "ℹ️ فاکتورِ بازِ شما پیدا نشد.", $this->mainMenu());
            return;
        }
        $done = 0;
        foreach ($open as $p) {
            if (!empty(Pay::verifyOrder((int)$p['id'])['ok'])) $done++;
        }
        if ($done > 0) {
            $this->u = Db::one('SELECT * FROM `user` WHERE `id` = ?', [$this->uid]);
            $this->send("✅ <b>" . faNum($done) . " پرداخت تأیید شد!</b>\n\n"
                . "💎 اشتراک ویژه فعال است.\n📅 تا: " . faDateTime((string)($this->u['plan_until'] ?? ''))
                . "\n🌐 سقف سایت‌ها: " . faNum(Db::getInt('vip_max_sites', 10)) . " سایت", $this->mainMenu());
            return;
        }
        $this->send("⏳ هنوز هیچ‌کدام از فاکتورهای شما تأیید نشده است.\n\n"
            . "بعضی درگاه‌ها تأیید را با چند ثانیه تأخیر انجام می‌دهند.\n"
            . "اگر مطمئنید پول کم شده، رسید را برای مدیر بفرستید.", $this->payGatewayMenu());
    }

    /** فهرست فاکتورهای کاربر */
    private function payOrdersText(): string    {
        $rows = Pay::userOrders($this->uid, 6);
        if (!$rows) return "📄 <b>فاکتورهای من</b>\n\nهنوز فاکتوری نساخته‌اید.";
        $st = ['pending' => '🟡 در انتظار پرداخت', 'approved' => '✅ تأیید‌شده',
               'rejected' => '❌ رد‌شده', 'expired' => '⌛️ منقضی'];
        $txt = "📄 <b>فاکتورهای من</b>\n\n";
        foreach ($rows as $r) {
            $txt .= ($st[(string)$r['status']] ?? '•') . ' <code>#' . faNum((int)$r['id']) . '</code> — '
                . PayGws::title((string)$r['gateway']) . "\n";
            $txt .= "    " . faMoney((int)$r['amount']) . ' • ' . faDateTime((string)$r['created_at']) . "\n";
            if ((string)$r['status'] === 'pending') {
                $txt .= "    🔄 <b>بررسی</b>";
            }
            $txt .= "\n";
        }
        return $txt;
    }

    // ================================================================ مدیر

    private function adminGatewaysText(): string
    {
        $gws = PayGws::all(false);
        $s = Pay::stats();
        $txt = "🏦 <b>درگاه‌های پرداخت</b>\n\n"
            . "🟢 فعال: " . faNum(count(PayGws::all(true))) . " از " . faNum(count($gws)) . "\n"
            . "📊 فاکتور کل: " . faNum($s['total'])
            . " • تأیید: " . faNum($s['ok'])
            . " • در انتظار: " . faNum($s['pending'])
            . " • ناموفق: " . faNum($s['bad']) . "\n"
            . "💰 فروش تأییدشده: " . faMoney($s['revenue']) . "\n\n"
            . "<b>راهنمای کلید هر درگاه</b>\n";
        foreach ($gws as $g) {
            $on = (int)$g['enabled'] === 1;
            $note = PayGws::note((string)$g['code']);
            $txt .= "\n" . ($on ? '🟢' : '⚪️') . ' <b>' . h((string)$g['title']) . "</b> — " . h($note) . "\n";
        }
        $txt .= "\n\nℹ️ با زدن هر درگاه می‌توانید کلید را وارد، روشن/خاموش و اتصال را تست کنید.\n"
            . "⚠️ کلیدها فقط در جدول <code>pay_gateway</code> ذخیره می‌شوند و هرگز در پیام‌ها نمایش داده نمی‌شوند.";
        return $txt;
    }

    private function adminGatewaysMenu(): string
    {
        $gws = PayGws::all(false);
        $rows = [];
        foreach ($gws as $g) {
            $on = (int)$g['enabled'] === 1;
            $hasKey = trim((string)$g['api_key']) !== '' || trim((string)$g['merchant_id']) !== '';
            $icon = $on ? '🟢' : ($hasKey ? '🟡' : '⚪️');
            $rows[] = [['text' => $icon . ' ' . (string)$g['title'], 'callback_data' => 'pgw:' . (string)$g['code']]];
        }
        $rows[] = [['text' => '🔙 پنل مدیریت', 'callback_data' => 'apanel']];
        return BotApi::ikb($rows);
    }

    private function adminGatewayText(string $code): string
    {
        $g = PayGws::get($code);
        if (!$g) return '❌ درگاه پیدا نشد.';
        $on = (int)$g['enabled'] === 1;
        $hasKey = trim((string)$g['api_key']) !== '' || trim((string)$g['merchant_id']) !== '';
        $kindMap = ['card' => 'کارت‌به‌کارت خودکار', 'crypto' => 'ارز دیجیتال', 'bank' => 'درگاه بانکی'];

        $txt = "🏦 <b>درگاه: " . h((string)$g['title']) . "</b>\n\n"
            . "کد: <code>" . h($code) . "</code>\n"
            . "نوع: " . ($kindMap[(string)$g['kind']] ?? (string)$g['kind']) . "\n"
            . "وضعیت: " . ($on ? '🟢 <b>فعال</b>' : ($hasKey ? '🟡 کلید دارد ولی خاموش است' : '⚪️ خاموش و بدون کلید')) . "\n"
            . "کلید: " . (trim((string)$g['api_key']) !== '' ? '••••••' . mb_substr((string)$g['api_key'], -4) : '—') . "\n"
            . "آدرس پایه: " . (trim((string)$g['base_url']) !== '' ? h((string)$g['base_url']) : 'پیش‌فرض') . "\n"
            . "📊 موفق: " . faNum((int)$g['ok_count']) . " • ناموفق: " . faNum((int)$g['fail_count'])
            . " • آخرین استفاده: " . ($g['last_used'] ? timeAgo($g['last_used'], tzOffset()) : '—') . "\n";
        if (!empty($g['last_error'])) $txt .= "⚠️ آخرین خطا: " . h(truncateFa((string)$g['last_error'], 90)) . "\n";

        $cb = 'https://' . (string)(appConfig()['domain'] ?? '') . '/pay.php?g=' . rawurlencode($code);
        $txt .= "\n🌐 آدرس بازگشت/وب‌هوک (در پنل درگاه ثبت کنید):\n<code>" . h($cb) . "</code>\n";
        if (trim((string)$g['secret']) !== '') {
            $txt .= "🔐 Webhook Secret ثبت شده — وب‌هوک‌های این درگاه امضا می‌شوند.\n";
        } elseif ($code === 'variza') {
            $txt .= "⚠️ <b>واریزا بدون Webhook Secret کار نمی‌کند</b>؛ حتماً آن را وارد کنید.\n";
        }
        $note = PayGws::note($code);
        if ($note !== '') $txt .= "\nℹ️ " . h($note) . "\n";
        return $txt;
    }

    private function adminGatewayMenu(string $code): string
    {
        $g = PayGws::get($code);
        $on = $g && (int)$g['enabled'] === 1;
        $rows = [
            [['text' => $on ? '🔴 غیرفعال کردن' : '🟢 فعال کردن', 'callback_data' => 'pgwt:' . $code]],
            [['text' => '🔑 کلید API / توکن', 'callback_data' => 'pgwk:' . $code],
             ['text' => '🏷 Merchant ID', 'callback_data' => 'pgwm:' . $code]],
            [['text' => '🔐 Webhook Secret', 'callback_data' => 'pgws:' . $code],
             ['text' => '🌐 آدرس پایه', 'callback_data' => 'pgwb:' . $code]],
        ];
        if ($code === 'generic') {
            $rows[] = [['text' => '🧩 تنظیمات دلخواه (JSON)', 'callback_data' => 'pgwg:' . $code]];
        }
        $rows[] = [['text' => '🧪 تست اتصال', 'callback_data' => 'pgwx:' . $code]];
        $rows[] = [['text' => '🔙 فهرست درگاه‌ها', 'callback_data' => 'pgws_list']];
        return BotApi::ikb($rows);
    }

    /** ذخیرهٔ یک فیلد تنظیم درگاه (از طریق مرحلهٔ متنی) */
    private function paySetField(string $code, string $field): void
    {
        $labels = [
            'api_key' => 'کلید API / توکن',
            'merchant_id' => 'کد Merchant',
            'secret' => 'Webhook Secret',
            'base_url' => 'آدرس پایه',
        ];
        $label = $labels[$field] ?? $field;
        $this->setStep('await_paygw', ['code' => $code, 'field' => $field]);
        $g = PayGws::get($code);
        $cur = $g ? (string)($g[$field] ?? '') : '';
        $this->send("🔑 <b>تنظیم {$label}</b> برای " . PayGws::title($code) . "\n\n"
            . ($cur !== '' ? "فعلی: <code>" . tgH(mb_substr($cur, 0, 12)) . "</code>\n\n" : '')
            . "مقدار جدید را بفرستید.\nبرای پاک کردن، کلمهٔ <code>پاک</code> را بفرستید.\n"
            . "برای لغو: ❌ انصراف\n\n"
            . ($field === 'secret' ? "🔐 این مقدار باید دقیقاً همان Webhook Secret باشد که در پنل درگاه می‌بینید.\n" : '')
            . ($field === 'merchant_id' ? "🏷 فقط برای زرین‌پال لازم است (کد ۳۶ کاراکتری).\n" : ''),
            Nav::cancelKb());
    }

    private function stepPayGateway(string $text): void
    {
        $t = $this->temp();
        $code = (string)($t['code'] ?? '');
        $field = (string)($t['field'] ?? '');
        if ($code === '' || $field === '') { $this->clearStep(); $this->unknown(); return; }
        if ($text === '❌ انصراف') { $this->clearStep(); $this->send('انصراف شد.', $this->adminGatewayMenu($code)); return; }

        $val = ($text === 'پاک' || $text === '-') ? '' : trim($text);
        $max = in_array($field, ['api_key', 'secret'], true) ? 190 : 190;
        $val = mb_substr($val, 0, $max);
        Db::q("UPDATE `pay_gateway` SET `{$field}` = ?, `last_error` = '' WHERE `code` = ?", [$val, $code]);
        Db::logEvent($this->uid, 'gateway_set', $code . '.' . $field);
        $this->clearStep();
        $this->send("✅ ذخیره شد.\n\n" . $this->adminGatewayText($code), $this->adminGatewayMenu($code));
    }

    /** ذخیرهٔ تنظیمات JSON درگاه دلخواه */
    private function paySetGenericJson(string $text): void
    {
        $t = $this->temp();
        $code = (string)($t['code'] ?? 'generic');
        $j = json_decode($text, true);
        if (!is_array($j)) {
            $this->send("❌ ورودی JSON معتبر نیست.\n\nنمونهٔ ساختار:\n<code>{\n  \"create_path\": \"/api/pay\",\n  \"verify_path\": \"/api/verify\",\n  \"ref_path\": \"id\",\n  \"url_path\": \"pay_url\",\n  \"status_path\": \"status\",\n  \"ok_values\": [\"paid\",\"ok\"],\n  \"field_map\": {\"amount\":\"amount\",\"order_id\":\"order\",\"callback\":\"callback\"}\n}</code>",
                Nav::cancelKb());
            return;
        }
        Db::q('UPDATE `pay_gateway` SET `settings` = ? WHERE `code` = ?',
            [json_encode($j, JSON_UNESCAPED_UNICODE), $code]);
        $this->clearStep();
        $this->send("✅ تنظیمات ذخیره شد.\n\n" . $this->adminGatewayText($code), $this->adminGatewayMenu($code));
    }

    /** تست اتصال: یک فاکتور ۱۰۰۰ تومانی می‌سازد و بلافاصله لغو می‌کند (برای آبان) */
    private function payGatewayTest(string $code, ?int $msgId = null): void
    {
        $g = PayGws::get($code);
        if (!$g) { $this->edit((int)$msgId, '❌ درگاه پیدا نشد.'); return; }
        $this->edit((int)$msgId, '🧪 در حال تست اتصال به ' . PayGws::title($code) . '…');

        $cls = PayGws::driver($code);
        if (!$cls) { $this->edit((int)$msgId, '❌ درایور این درگاه بارگذاری نشده.'); return; }
        $key = trim((string)$g['api_key']) ?: trim((string)$g['merchant_id']);
        if ($key === '') {
            $this->edit((int)$msgId, "❌ ابتدا کلید API این درگاه را وارد کنید.\n\n" . $this->adminGatewayText($code), $this->adminGatewayMenu($code));
            return;
        }

        // حداقل مبلغ تست را از مستندات همان سرویس می‌گیریم
        $min = ['zarinpal' => 10000, 'cubepy' => 1000, 'tetrapay' => 1000, 'aban' => 1000, 'variza' => 1000, 'generic' => 1000][$code] ?? 1000;
        $gwAmount = $cls::currency() === 'toman' ? $min : $min;
        $gw = $g;
        $res = $cls::create($gw, [
            'user_id' => $this->uid, 'order_id' => 'TEST-' . time(),
            'gateway_amount' => $gwAmount, 'amount_toman' => (int)($gwAmount / 10),
            'desc' => 'تست اتصال', 'callback_url' => Pay::callbackUrl($code), 'mobile' => '', 'email' => '',
        ]);

        if (empty($res['ok'])) {
            Db::q('UPDATE `pay_gateway` SET `last_error` = ? WHERE `code` = ?', [mb_substr((string)$res['error'], 0, 190), $code]);
            $this->edit((int)$msgId, "❌ <b>اتصال ناموفق</b>\n\n" . h((string)$res['error'])
                . "\n\nکلید یا آدرس درگاه را بررسی کنید.", $this->adminGatewayMenu($code));
            return;
        }
        Db::q('UPDATE `pay_gateway` SET `last_error` = \'\', `last_used` = NOW() WHERE `code` = ?', [$code]);
        $this->edit((int)$msgId, "✅ <b>اتصال برقرار است</b>\n\n"
            . "درگاه فاکتور را ساخت و لینک برگرداند.\n"
            . "🆔 شناسه: <code>" . h((string)$res['ref']) . "</code>\n"
            . "🔗 لینک: " . h(truncateFa((string)$res['pay_url'], 60)) . "\n\n"
            . "⚠️ این یک فاکتور آزمایشی است؛ آن را پرداخت نکنید.",
            $this->adminGatewayMenu($code));
    }

    /** آمار کلی درگاه‌ها برای پنل مدیریت */
    private function payStatsLine(): string
    {
        $s = Pay::stats();
        if ($s['total'] === 0) return '';
        return "💳 فاکتور: " . faNum($s['total']) . " • تأیید: " . faNum($s['ok'])
            . " • در انتظار: " . faNum($s['pending'])
            . " • ناموفق: " . faNum($s['bad'])
            . " • فروش: " . faMoney($s['revenue']);
    }
}
