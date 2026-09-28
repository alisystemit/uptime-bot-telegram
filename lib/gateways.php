<?php
/**
 * ===== درایورهای درگاه پرداخت =====
 *
 * هر درایور دقیقاً بر اساس مستندات رسمی همان سرویس نوشته شده است:
 *
 *  | code      | سرویس     | مستندات                    | واحد مبلغ | مسیر ایجاد |
 *  |-----------|-----------|----------------------------|-----------|------------|
 *  | zarinpal  | زرین‌پال  | api.zarinpal.com/pg/v4      | ریال      | payment/request.json |
 *  | variza    | واریزا     | variza.ir/developers        | تومان     | /api/v1/pay          |
 *  | cubepy    | کیوب‌پی    | cubepy/cubepay-doc          | ریال      | /smspay/api/create-payment.php |
 *  | tetrapay  | تتراپی    | tetra98.ir/docs             | ریال      | /api/create_order    |
 *  | aban      | آبان‌گیت‌وی| abangateway.ir/docs/api     | ریال      | /api/v1/invoices     |
 *  | generic   | دلخواه     | —                          | ریال      | از پنل تنظیم می‌شود  |
 *
 * قرارداد همهٔ درایورها یکی است:
 *   create()  → ['ok'=>bool,'pay_url'=>string,'ref'=>string,'card'=>array,'error'=>string]
 *   verify()  → ['ok'=>bool,'ref_id'=>string,'amount'=>int,'error'=>string]
 *   callback()→ ['ref'=>string,'paid'=>bool,'amount'=>int]
 *   signed()  → true اگر وب‌هوک امضا دارد
 *
 * نکتهٔ امنیتی: هیچ‌کدام از callbackها را «مبنای نهایی» نمی‌گیریم؛
 * بعد از هر callback، verify فراخوانی می‌شود و فقط پاسخ آن تصمیم می‌گیرد
 * (این دقیقاً همان چیزی است که مستندات CubePay هم الزامی کرده).
 */

interface PayDriver
{
    public static function code(): string;
    public static function title(): string;
    /** card = کارت‌به‌کارت خودکار | crypto = ارز دیجیتال | bank = درگاه بانکی */
    public static function kind(): string;
    /** rial | toman — واحدی که API سرویس می‌خواهد */
    public static function currency(): string;
    /** @return array{ok:bool,pay_url:string,ref:string,card:array,error:string} */
    public static function create(array $gw, array $a): array;
    /** @return array{ok:bool,ref_id:string,amount:int,error:string} */
    public static function verify(array $gw, array $order): array;
    /** @return array{ref:string,paid:bool,amount:int} */
    public static function callback(array $gw, array $in, string $raw): array;
    /** آیا وب‌هوک امضا دارد؟ اگر بله، سیگنال باید بررسی شود */
    public static function signed(array $gw): bool;
    /** آیا endpoint جداگانه برای تأیید نهایی دارد؟ */
    public static function hasVerify(array $gw): bool;
}

// ══════════════════════════════════════════════════════════════
//  ابزار مشترک HTTP
// ══════════════════════════════════════════════════════════════

class PayHttp
{
    /**
     * درخواست JSON/فرم به درگاه.
     * @return array{ok:bool, status:int, body:string, json:array, error:string}
     */
    public static function call(string $url, array $params, array $headers = [], string $method = 'POST', int $timeout = 20): array
    {
        $out = ['ok' => false, 'status' => 0, 'body' => '', 'json' => [], 'error' => ''];
        $isJson = in_array('application/json', $headers, true) || $headers === [];
        $body = $isJson
            ? json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : http_build_query($params);

        $ch = curl_init($url);
        $h = $headers;
        if ($isJson) $h[] = 'Content-Type: application/json';
        $h[] = 'Accept: application/json';
        $h[] = 'User-Agent: UptimeBot/1.0';

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $h,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($method === 'GET') {
            if ($params) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $res = curl_exec($ch);
        $out['status'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string)curl_error($ch);
        curl_close($ch);

        $out['body'] = (string)$res;
        $out['json'] = json_decode($out['body'], true);
        $out['ok'] = $res !== false && $out['status'] >= 200 && $out['status'] < 300;
        if ($res === false) $out['error'] = $err !== '' ? $err : 'ارتباط با درگاه برقرار نشد';
        elseif (!$out['ok']) $out['error'] = self::errMsg($out['json'], $out['status']);
        return $out;
    }

    private static function errMsg($json, int $status): string
    {
        if (is_array($json)) {
            foreach (['message', 'error_description', 'description', 'errors', 'error'] as $k) {
                if (!empty($json[$k])) {
                    $v = $json[$k];
                    if (is_array($v)) {
                        if (isset($v['message'])) return (string)$v['message'];
                        if (isset($v['code'])) return (string)$v['code'];
                        return (string)json_encode($v, JSON_UNESCAPED_UNICODE);
                    }
                    return (string)$v;
                }
            }
        }
        return 'خطای درگاه (کد ' . $status . ')';
    }

    /** استخراج یک کلید از پاسخ با مسیر نقطه‌ای: a.b.c */
    public static function dig($json, string $path, $default = null)
    {
        $cur = $json;
        foreach (explode('.', $path) as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) return $default;
            $cur = $cur[$k];
        }
        return $cur;
    }
}

// ══════════════════════════════════════════════════════════════
//  زرین‌پال — api.zarinpal.com/pg/v4/payment/{request,verify}.json
// ══════════════════════════════════════════════════════════════

class PayZarinpal implements PayDriver
{
    public static function code(): string { return 'zarinpal'; }
    public static function title(): string { return '💳 زرین‌پال'; }
    public static function kind(): string { return 'bank'; }
    public static function currency(): string { return 'rial'; }
    public static function signed(array $gw): bool { return false; }
    public static function hasVerify(array $gw): bool { return true; }

    private static function base(array $gw, string $path): string
    {
        $b = rtrim(trim((string)($gw['base_url'] ?? '')), '/');
        if ($b === '') {
            // اگر کلید با sandbox شروع شود، محیط آزمایشی انتخاب می‌شود
            $b = strpos((string)($gw['api_key'] ?? ''), 'sandbox') === 0
                ? 'https://sandbox.zarinpal.com/pg/v4' : 'https://api.zarinpal.com/pg/v4';
        } elseif (!preg_match('#/v\d+$#', $b)) {
            // مدیر فقط دامنه/پراکسی را داده؛ بخش نسخهٔ API را خودمان اضافه می‌کنیم
            $b .= '/pg/v4';
        }
        return $b . $path;
    }

    public static function create(array $gw, array $a): array
    {
        $r = ['ok' => false, 'pay_url' => '', 'ref' => '', 'card' => [], 'error' => ''];
        $mid = trim((string)($gw['merchant_id'] ?? $gw['api_key'] ?? ''));
        if ($mid === '') { $r['error'] = 'کد Merchant زرین‌پال تنظیم نشده است'; return $r; }

        $resp = PayHttp::call(self::base($gw, '/payment/request.json'), [
            'merchant_id'  => $mid,
            'amount'       => (int)$a['gateway_amount'],
            'description'  => (string)$a['desc'],
            'callback_url' => (string)$a['callback_url'],
            'metadata'     => array_filter(['mobile' => $a['mobile'] ?? '', 'email' => $a['email'] ?? '']),
        ]);
        $code = (int)PayHttp::dig($resp['json'], 'data.code', 0);
        $auth = (string)PayHttp::dig($resp['json'], 'data.authority', '');
        if ($code !== 100 || $auth === '') {
            $r['error'] = $resp['error'] !== '' ? $resp['error'] : 'زرین‌پال کد ' . $code . ' برگرداند';
            return $r;
        }
        $start = self::base($gw, '/payment/startpay/' . $auth);
        return ['ok' => true, 'pay_url' => $start, 'ref' => $auth, 'card' => [], 'error' => ''];
    }

    public static function verify(array $gw, array $order): array
    {
        $r = ['ok' => false, 'ref_id' => '', 'amount' => 0, 'error' => ''];
        $mid = trim((string)($gw['merchant_id'] ?? $gw['api_key'] ?? ''));
        $resp = PayHttp::call(self::base($gw, '/payment/verify.json'), [
            'merchant_id' => $mid,
            'amount'      => (int)$order['pay_amount'],
            'authority'   => (string)$order['ref_id'],
        ]);
        $code = (int)PayHttp::dig($resp['json'], 'data.code', 0);
        // 100 = موفق تازه، 101 = قبلاً تأیید شده (همان نتیجه برای ما)
        if ($code === 100 || $code === 101) {
            $r['ok'] = true;
            $r['ref_id'] = (string)PayHttp::dig($resp['json'], 'data.ref_id', '');
            $r['amount'] = (int)PayHttp::dig($resp['json'], 'data.amount', 0);
        } else {
            $r['error'] = $code === -50 || $code === -33
                ? 'پرداخت توسط کاربر لغو شد'
                : ($resp['error'] !== '' ? $resp['error'] : 'تأیید ناموفق (کد ' . $code . ')');
        }
        return $r;
    }

    public static function callback(array $gw, array $in, string $raw): array
    {
        $auth = (string)($in['Authority'] ?? $in['authority'] ?? '');
        $st = strtoupper((string)($in['Status'] ?? $in['status'] ?? ''));
        return ['ref' => $auth, 'paid' => ($st === 'OK' && $auth !== ''), 'amount' => 0];
    }
}

// ══════════════════════════════════════════════════════════════
//  واریزا — variza.ir/api/v1/pay  (وب‌هوک امضاشده با HMAC-SHA256)
// ══════════════════════════════════════════════════════════════

class PayVariza implements PayDriver
{
    public static function code(): string { return 'variza'; }
    public static function title(): string { return '🏦 واریزا (کارت‌به‌کارت خودکار)'; }
    public static function kind(): string { return 'card'; }
    public static function currency(): string { return 'toman'; }
    public static function signed(array $gw): bool { return trim((string)($gw['secret'] ?? '')) !== ''; }
    public static function hasVerify(array $gw): bool { return false; }   // تأیید فقط با وب‌هوکِ امضاشده

    public static function create(array $gw, array $a): array
    {
        $r = ['ok' => false, 'pay_url' => '', 'ref' => '', 'card' => [], 'error' => ''];
        $key = trim((string)($gw['api_key'] ?? ''));
        if ($key === '') { $r['error'] = 'کلید API واریزا تنظیم نشده است'; return $r; }

        $payload = [
            'amount'     => (int)$a['gateway_amount'],   // واریزا تومان می‌گیرد
            'return_url' => (string)$a['callback_url'],
            'title'      => (string)$a['desc'],
        ];
        $st = PayGws::setting($gw, 'expires_in', '1h');
        if ($st !== '') $payload['expires_in'] = $st;
        $card = trim((string)PayGws::setting($gw, 'card_last_4', ''));
        if ($card !== '') $payload['card_last_4'] = $card;

        $resp = PayHttp::call(self::base($gw) . '/api/v1/pay', $payload, ['Authorization: Bearer ' . $key]);
        $slug = (string)PayHttp::dig($resp['json'], 'slug', '');
        $url = (string)PayHttp::dig($resp['json'], 'pay_url', '');
        if ($slug === '' || $url === '') {
            $r['error'] = $resp['error'] !== '' ? $resp['error'] : 'پاسخ واریزا قابل خواندن نبود';
            return $r;
        }
        return [
            'ok' => true, 'pay_url' => $url, 'ref' => $slug, 'card' => [],
            'error' => '',
            'expires_at' => (string)PayHttp::dig($resp['json'], 'expires_at', ''),
        ];
    }

    public static function verify(array $gw, array $order): array
    {
        // واریزا endpoint تأیید جدا ندارد؛ اعتبارسنجی از راه خود وب‌هوک
        // (که امضای HMAC آن بررسی می‌شود) انجام می‌شود.
        return ['ok' => false, 'ref_id' => '', 'amount' => 0,
            'error' => 'واریزا endpoint جداگانه‌ای برای تأیید ندارد؛ نتیجه فقط از وب‌هوکِ امضاشده پذیرفته می‌شود'];
    }

    public static function callback(array $gw, array $in, string $raw): array
    {
        $j = json_decode($raw, true);
        $j = is_array($j) ? $j : $in;
        $paid = (string)($j['status'] ?? '') === 'paid' || (string)($j['event'] ?? '') === 'payment.paid';
        return [
            'ref' => (string)($j['slug'] ?? $in['slug'] ?? ''),
            'paid' => $paid,
            'amount' => (int)($j['amount'] ?? 0),   // تومان
        ];
    }

    public static function base(array $gw): string
    {
        $b = trim((string)($gw['base_url'] ?? ''));
        return $b !== '' ? rtrim($b, '/') : 'https://variza.ir';
    }
}

// ══════════════════════════════════════════════════════════════
//  کیوب‌پی — cubevps.ir/smspay/api/{create-payment,verify-payment}.php
// ══════════════════════════════════════════════════════════════

class PayCubepy implements PayDriver
{
    public static function code(): string { return 'cubepy'; }
    public static function title(): string { return '💳 کیوب‌پی (کارت‌به‌کارت + ارز دیجیتال)'; }
    public static function kind(): string { return 'card'; }   // card یا crypto — بسته به تنظیم
    public static function currency(): string { return 'rial'; }
    public static function signed(array $gw): bool { return false; }
    public static function hasVerify(array $gw): bool { return true; }

    public static function create(array $gw, array $a): array
    {
        $r = ['ok' => false, 'pay_url' => '', 'ref' => '', 'card' => [], 'error' => ''];
        $token = trim((string)($gw['api_key'] ?? ''));
        if ($token === '') { $r['error'] = 'توکن API کیوب‌پی تنظیم نشده است'; return $r; }

        $payload = [
            'amount'         => (int)$a['gateway_amount'],
            'order_id'       => (string)$a['order_id'],
            'callback_url'   => (string)$a['callback_url'],
            'description'    => (string)$a['desc'],
            'customer_user_id' => (string)($a['user_id'] ?? ''),
            // برای ربات‌محور بهتر است مرورگر خریدار ریدایرکت نشود
            'redirect_after_payment' => false,
        ];
        $ttl = (int)PayGws::setting($gw, 'ttl_minutes', 0);
        if ($ttl >= 5 && $ttl <= 1440) $payload['ttl_minutes'] = $ttl;

        $resp = PayHttp::call(self::base($gw) . '/smspay/api/create-payment.php', $payload,
            ['Authorization: Bearer ' . $token]);
        if (empty($resp['json']['success'])) {
            $r['error'] = $resp['error'] !== '' ? $resp['error']
                : (string)(PayHttp::dig($resp['json'], 'message', 'ساخت فاکتور کیوب‌پی ناموفق بود'));
            return $r;
        }
        $j = $resp['json'];
        $card = [];
        if (!empty($j['card']['number'])) {
            $card = [
                'number' => (string)$j['card']['number'],
                'holder' => (string)($j['card']['holder'] ?? ''),
                'sheba'  => (string)($j['card']['sheba'] ?? ''),
            ];
        }
        return [
            'ok' => true,
            'pay_url' => (string)($j['payment_link'] ?? ''),
            'ref' => (string)($j['authority'] ?? ''),
            'card' => $card,
            'error' => '',
            // کیوب‌پی چند ریال به مبلغ اضافه می‌کند؛ همین عدد باید واریز شود
            'pay_amount' => (int)($j['pay_amount'] ?? $a['gateway_amount']),
            'expires_at' => (string)($j['expires_at'] ?? ''),
        ];
    }

    public static function verify(array $gw, array $order): array
    {
        $r = ['ok' => false, 'ref_id' => '', 'amount' => 0, 'error' => ''];
        $token = trim((string)($gw['api_key'] ?? ''));
        $resp = PayHttp::call(self::base($gw) . '/smspay/api/verify-payment.php',
            ['authority' => (string)$order['ref_id']], ['Authorization: Bearer ' . $token]);

        $st = (string)PayHttp::dig($resp['json'], 'status', '');
        if (!empty($resp['json']['success']) && $st === 'verified') {
            $r['ok'] = true;
            $r['ref_id'] = (string)PayHttp::dig($resp['json'], 'order_id', '');
            $r['amount'] = (int)PayHttp::dig($resp['json'], 'amount', 0);
            return $r;
        }
        if ((int)$resp['status'] === 409) { $r['error'] = 'این فاکتور قبلاً یک‌بار تأیید شده است'; return $r; }
        if ((int)$resp['status'] === 402) { $r['error'] = 'هنوز واریزی ثبت نشده است'; return $r; }
        if ((int)$resp['status'] === 410) { $r['error'] = 'مهلت پرداخت تمام شده است'; return $r; }
        $r['error'] = $resp['error'] !== '' ? $resp['error'] : (string)(PayHttp::dig($resp['json'], 'message', 'تأیید ناموفق بود'));
        return $r;
    }

    public static function callback(array $gw, array $in, string $raw): array
    {
        $j = json_decode($raw, true);
        $j = is_array($j) ? $j : $in;
        return [
            'ref' => (string)($j['authority'] ?? $in['authority'] ?? ''),
            'paid' => !empty($j['success']) || (string)($j['status'] ?? '') === 'paid',
            'amount' => (int)($j['amount'] ?? 0),
        ];
    }

    public static function base(array $gw): string
    {
        $b = trim((string)($gw['base_url'] ?? ''));
        return $b !== '' ? rtrim($b, '/') : 'https://cubevps.ir';
    }
}

// ══════════════════════════════════════════════════════════════
//  تتراپی — tetra98.ir/api/create_order و /api/verify
// ══════════════════════════════════════════════════════════════

class PayTetra implements PayDriver
{
    public static function code(): string { return 'tetrapay'; }
    public static function title(): string { return '🪙 تتراپی (ریال ⇄ تتر)'; }
    public static function kind(): string { return 'crypto'; }
    public static function currency(): string { return 'rial'; }
    public static function signed(array $gw): bool { return false; }
    public static function hasVerify(array $gw): bool { return true; }

    public static function create(array $gw, array $a): array
    {
        $r = ['ok' => false, 'pay_url' => '', 'ref' => '', 'card' => [], 'error' => ''];
        $key = trim((string)($gw['api_key'] ?? ''));
        if ($key === '') { $r['error'] = 'ApiKey تتراپی تنظیم نشده است'; return $r; }

        // مستندات: ApiKey, Hash_id, Amount(ریال), Description, Mobile, CallbackURL
        $resp = PayHttp::call(self::base($gw) . '/api/create_order', [
            'ApiKey'       => $key,
            'Hash_id'      => (string)$a['order_id'],
            'Amount'       => (int)$a['gateway_amount'],
            'Description'  => (string)$a['desc'],
            'Mobile'       => (string)($a['mobile'] ?? ''),
            'CallbackURL'  => (string)$a['callback_url'],
        ], [], 'POST');

        $status = (string)($resp['json']['status'] ?? '');
        $auth = (string)($resp['json']['Authority'] ?? '');
        if ($status !== '100' || $auth === '') {
            $r['error'] = $resp['error'] !== '' ? $resp['error'] : 'تتراپی کد ' . $status . ' برگرداند';
            return $r;
        }
        $mode = PayGws::setting($gw, 'pay_mode', 'bot');
        $url = $mode === 'web'
            ? (string)($resp['json']['payment_url_web'] ?? '')
            : (string)($resp['json']['payment_url_bot'] ?? '');
        if ($url === '') $url = self::base($gw) . '/payment/' . $auth;
        return ['ok' => true, 'pay_url' => $url, 'ref' => $auth, 'card' => [], 'error' => '',
            'tracking_id' => (string)($resp['json']['tracking_id'] ?? '')];
    }

    public static function verify(array $gw, array $order): array
    {
        $r = ['ok' => false, 'ref_id' => '', 'amount' => 0, 'error' => ''];
        $key = trim((string)($gw['api_key'] ?? ''));
        $resp = PayHttp::call(self::base($gw) . '/api/verify', [
            'ApiKey'   => $key,
            'authority' => (string)$order['ref_id'],
            'Hash_id'  => (string)$order['order_id'],
        ], [], 'POST');

        $status = (string)($resp['json']['status'] ?? '');
        if ($status === '100') {
            $r['ok'] = true;
            $r['ref_id'] = (string)($resp['json']['tracking_id'] ?? $resp['json']['Authority'] ?? '');
            $r['amount'] = (int)($resp['json']['Amount'] ?? 0);
            return $r;
        }
        $r['error'] = $resp['error'] !== '' ? $resp['error'] : 'تتراپی کد ' . ($status !== '' ? $status : '؟') . ' برگرداند';
        return $r;
    }

    public static function callback(array $gw, array $in, string $raw): array
    {
        $j = json_decode($raw, true);
        $j = is_array($j) ? $j : $in;
        return [
            'ref' => (string)($j['hashid'] ?? $j['Hash_id'] ?? $in['hashid'] ?? ''),
            'paid' => (string)($j['status'] ?? '') === '100',
            'amount' => (int)($j['amount'] ?? $j['Amount'] ?? 0),
        ];
    }

    public static function base(array $gw): string
    {
        $b = trim((string)($gw['base_url'] ?? ''));
        return $b !== '' ? rtrim($b, '/') : 'https://tetra98.ir';
    }
}

// ══════════════════════════════════════════════════════════════
//  آبان گیت وی — abangateway.ir/api/v1/invoices
// ══════════════════════════════════════════════════════════════

class PayAban implements PayDriver
{
    public static function code(): string { return 'aban'; }
    public static function title(): string { return '🏦 آبان گیت وی (کارت‌به‌کارت خودکار)'; }
    public static function kind(): string { return 'card'; }
    public static function currency(): string { return 'rial'; }
    public static function signed(array $gw): bool { return false; }
    public static function hasVerify(array $gw): bool { return true; }

    public static function create(array $gw, array $a): array
    {
        $r = ['ok' => false, 'pay_url' => '', 'ref' => '', 'card' => [], 'error' => ''];
        $token = trim((string)($gw['api_key'] ?? ''));
        if ($token === '') { $r['error'] = 'توکن آبان گیت وی تنظیم نشده است'; return $r; }

        $payload = [
            'amount_rial'  => (int)$a['gateway_amount'],
            'order_id'     => (string)$a['order_id'],
            'callback_url' => (string)$a['callback_url'],
            'description'  => (string)$a['desc'],
            'metadata'     => ['telegram_user_id' => (string)($a['user_id'] ?? '')],
        ];
        $exp = (int)PayGws::setting($gw, 'expiry_minutes', 0);
        if ($exp >= 1 && $exp <= 1440) $payload['expiry_minutes'] = $exp;

        $resp = PayHttp::call(self::base($gw) . '/api/v1/invoices', $payload, ['Authorization: Bearer ' . $token]);
        $inv = (string)PayHttp::dig($resp['json'], 'invoice_id', '');
        if ($inv === '') {
            $r['error'] = $resp['error'] !== '' ? $resp['error'] : 'پاسخ آبان گیت وی قابل خواندن نبود';
            return $r;
        }
        $card = [];
        if (!empty($resp['json']['card_number'])) {
            $card = [
                'number' => (string)$resp['json']['card_number'],
                'holder' => (string)($resp['json']['card_holder'] ?? ''),
                'sheba'  => (string)($resp['json']['iban'] ?? ''),
            ];
        }
        return [
            'ok' => true,
            'pay_url' => (string)($resp['json']['payment_url'] ?? ''),
            'ref' => $inv,
            'card' => $card,
            'error' => '',
            // payable_rial همان چیزی است که خریدار باید بی‌دقت واریز کند
            'pay_amount' => (int)($resp['json']['payable_rial'] ?? $a['gateway_amount']),
            'expires_at' => (string)($resp['json']['expires_at'] ?? ''),
        ];
    }

    public static function verify(array $gw, array $order): array
    {
        $r = ['ok' => false, 'ref_id' => '', 'amount' => 0, 'error' => ''];
        $token = trim((string)($gw['api_key'] ?? ''));
        $resp = PayHttp::call(self::base($gw) . '/api/v1/invoices/' . rawurlencode((string)$order['ref_id']) . '/verify',
            [], ['Authorization: Bearer ' . $token]);

        if (!empty($resp['json']['verified'])) {
            $r['ok'] = true;
            $r['ref_id'] = (string)PayHttp::dig($resp['json'], 'invoice_id', '');
            $r['amount'] = (int)PayHttp::dig($resp['json'], 'amount_rial', 0);
            return $r;
        }
        $code = (string)PayHttp::dig($resp['json'], 'error.code', '');
        $r['error'] = match ($code) {
            'already_verified' => 'این فاکتور قبلاً یک‌بار تأیید شده است',
            'not_yet_paid'     => 'هنوز واریزی ثبت نشده است',
            'invoice_expired'  => 'مهلت پرداخت تمام شده است',
            default            => ($resp['error'] !== '' ? $resp['error'] : 'تأیید ناموفق بود'),
        };
        return $r;
    }

    public static function callback(array $gw, array $in, string $raw): array
    {
        $j = json_decode($raw, true);
        $j = is_array($j) ? $j : $in;
        $st = (string)($j['status'] ?? $in['status'] ?? '');
        $inv = (string)($j['invoice_id'] ?? $j['order_id'] ?? $in['invoice_id'] ?? '');
        return [
            'ref' => $inv,
            'paid' => in_array($st, ['paid', 'verified', 'partially_paid'], true),
            'amount' => (int)($j['amount_rial'] ?? $j['payable_rial'] ?? 0),
        ];
    }

    public static function base(array $gw): string
    {
        $b = trim((string)($gw['base_url'] ?? ''));
        return $b !== '' ? rtrim($b, '/') : 'https://abangateway.ir';
    }
}

// ══════════════════════════════════════════════════════════════
//  درگاه دلخواه — مسیرها و نام فیلدها از پنل مدیریت خوانده می‌شود
//  (برای سرویس‌هایی مثل تون‌پی/اطلس‌پی/ترونادو/بلوپال که
//   مستندات عمومی API ندارند و باید خود مدیر پر کند)
// ══════════════════════════════════════════════════════════════

class PayGeneric implements PayDriver
{
    public static function code(): string { return 'generic'; }
    public static function title(): string { return '🔧 درگاه دلخواه'; }
    public static function kind(): string { return 'card'; }
    public static function currency(): string { return 'rial'; }
    public static function signed(array $gw): bool
    {
        return PayGws::setting($gw, 'sign_algo', '') !== '';
    }

    public static function hasVerify(array $gw): bool
    {
        return trim((string)PayGws::setting($gw, 'verify_path', '')) !== '';
    }

    public static function create(array $gw, array $a): array
    {
        $r = ['ok' => false, 'pay_url' => '', 'ref' => '', 'card' => [], 'error' => ''];
        $base = rtrim(trim((string)($gw['base_url'] ?? '')), '/');
        $path = trim((string)PayGws::setting($gw, 'create_path', ''));
        if ($base === '' || $path === '') {
            $r['error'] = 'آدرس پایه یا مسیر ساخت فاکتور برای درگاه دلخواه تنظیم نشده است';
            return $r;
        }

        // نگاشت نام فیلدها: amount → amount | price | value …
        $map = PayGws::setting($gw, 'field_map', []);
        if (!is_array($map)) $map = [];
        $payload = [];
        foreach ($map as $remote => $local) {
            $val = match ((string)$local) {
                'amount'     => (int)$a['gateway_amount'],
                'order'      => (string)$a['order_id'],
                'callback'   => (string)$a['callback_url'],
                'desc'       => (string)$a['desc'],
                'user'       => (string)($a['user_id'] ?? ''),
                'email'      => (string)($a['email'] ?? ''),
                default      => (string)$local,
            };
            $payload[(string)$remote] = $val;
        }
        if (!$payload) $payload = ['amount' => (int)$a['gateway_amount'], 'order_id' => (string)$a['order_id']];

        $headers = [];
        $authStyle = (string)PayGws::setting($gw, 'auth_style', 'bearer');
        $key = trim((string)($gw['api_key'] ?? ''));
        if ($key !== '') {
            $headers[] = match ($authStyle) {
                'header'  => trim((string)PayGws::setting($gw, 'auth_header', 'X-API-Key')) . ': ' . $key,
                'query'   => '',
                'body'    => '',
                default   => 'Authorization: Bearer ' . $key,
            };
        }
        $ct = (string)PayGws::setting($gw, 'content_type', 'json');
        if ($ct === 'form') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'X-Pay-Form: 1';
        }
        if ($authStyle === 'query' && $key !== '') {
            $base .= (strpos($base, '?') === false ? '?' : '&') . rawurlencode((string)PayGws::setting($gw, 'auth_query', 'api_key')) . '=' . rawurlencode($key);
        }

        $isJson = $ct !== 'form';
        $resp = PayHttp::call($base . $path, $payload, $headers, 'POST');
        $j = $resp['json'];

        $refPath = (string)PayGws::setting($gw, 'ref_path', 'id');
        $urlPath = (string)PayGws::setting($gw, 'url_path', 'pay_url');
        $ref = (string)PayHttp::dig($j, $refPath, '');
        $url = (string)PayHttp::dig($j, $urlPath, '');
        if ($url === '' && $ref !== '') $url = $base . PayGws::setting($gw, 'url_suffix', '/pay/') . $ref;
        if ($ref === '' || $url === '') {
            $r['error'] = $resp['error'] !== '' ? $resp['error']
                : 'فیلد «' . $refPath . '» یا «' . $urlPath . '» در پاسخ درگاه پیدا نشد (پیکربندی دلخواه را بررسی کنید)';
            return $r;
        }
        $card = [];
        $cn = (string)PayGws::setting($gw, 'card_path', '');
        if ($cn !== '') {
            $card = [
                'number' => (string)PayHttp::dig($j, $cn, ''),
                'holder' => (string)PayHttp::dig($j, PayGws::setting($gw, 'card_holder_path', $cn . '.holder'), ''),
            ];
        }
        return ['ok' => true, 'pay_url' => $url, 'ref' => $ref, 'card' => $card, 'error' => ''];
    }

    public static function verify(array $gw, array $order): array
    {
        $r = ['ok' => false, 'ref_id' => '', 'amount' => 0, 'error' => ''];
        $base = rtrim(trim((string)($gw['base_url'] ?? '')), '/');
        $path = trim((string)PayGws::setting($gw, 'verify_path', ''));
        if ($path === '') { $r['error'] = 'مسیر تأیید برای درگاه دلخواه تنظیم نشده است'; return $r; }

        $headers = [];
        $key = trim((string)($gw['api_key'] ?? ''));
        if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
        $payload = [(string)PayGws::setting($gw, 'ref_field', 'id') => (string)$order['ref_id']];

        $resp = PayHttp::call($base . $path, $payload, $headers, 'POST');
        $j = $resp['json'];
        $okVal = PayGws::setting($gw, 'ok_values', ['paid', 'verified', 'success', 'ok']);
        $okVal = is_array($okVal) ? array_map('strval', $okVal) : ['paid', 'verified', 'success'];
        $st = strtolower((string)PayHttp::dig($j, (string)PayGws::setting($gw, 'status_path', 'status'), ''));
        if ($st !== '' && in_array($st, $okVal, true)) {
            $r['ok'] = true;
            $r['ref_id'] = (string)PayHttp::dig($j, (string)PayGws::setting($gw, 'ref_id_path', 'ref_id'), '');
            $r['amount'] = (int)PayHttp::dig($j, (string)PayGws::setting($gw, 'amount_path', 'amount'), 0);
        } else {
            $r['error'] = $resp['error'] !== '' ? $resp['error']
                : ($st !== '' ? 'وضعیت درگاه: ' . $st : 'تأیید ناموفق بود');
        }
        return $r;
    }

    public static function callback(array $gw, array $in, string $raw): array
    {
        $j = json_decode($raw, true);
        $j = is_array($j) ? $j : $in;
        $okVal = PayGws::setting($gw, 'ok_values', ['paid', 'verified', 'success', 'ok']);
        $okVal = is_array($okVal) ? array_map('strval', $okVal) : ['paid', 'verified', 'success'];
        $st = strtolower((string)PayHttp::dig($j, (string)PayGws::setting($gw, 'status_path', 'status'), ''));
        $map = PayGws::setting($gw, 'field_map', []);
        $refField = 'order';
        if (is_array($map)) foreach ($map as $remote => $local) if ((string)$local === 'order') { $refField = (string)$remote; break; }
        return [
            'ref' => (string)($j[$refField] ?? $in[$refField] ?? $in['ref'] ?? ''),
            'paid' => in_array($st, $okVal, true),
            'amount' => (int)PayHttp::dig($j, (string)PayGws::setting($gw, 'amount_path', 'amount'), 0),
        ];
    }
}
