<?php
/**
 * سرور قلابی درگاه‌های پرداخت برای تست (حالت‌دار — مثل یک درگاه واقعی)
 * اجرا:  php -S 127.0.0.1:8321 tests/mock_gw.php
 *
 * فایل وضعیت: tests/.mock_state.json  (در هر فراخوانی بازنویسی می‌شود)
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$raw = (string)file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) $in = $_REQUEST;
$stateFile = __DIR__ . '/.mock_state.json';

$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$save = static function () use ($stateFile, &$state): void {
    @file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
};

function out($code, $json, array $headers = [])
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    foreach ($headers as $k => $v) header($k . ': ' . $v);
    echo json_encode($json, JSON_UNESCAPED_UNICODE);
    exit;
}

if (strpos($path, '/mock/') !== 0) out(404, ['error' => 'not found']);
$parts = explode('/', trim($path, '/'));
$gw = $parts[1] ?? '';
$act = implode('/', array_slice($parts, 2));

// درگاه خراب برای تست مسیر خطا
if ($gw === 'broken') out(422, ['error' => ['code' => 'invalid_request', 'message' => 'مبلغ نامعتبر است']]);

$seq = (int)($state['seq'] ?? 0) + 1;
$state['seq'] = $seq;

// ---------------------------------------------------------------- زرین‌پال
if ($gw === 'zarinpal' && preg_match('#^(?:pg/v4/|api/v4/)?payment/(request|verify)\.json$#', $act, $m)) {
    if ($m[1] === 'request') {
        if ((int)($in['amount'] ?? 0) < 10000) out(400, ['errors' => ['مبلغ کمتر از حد مجاز'], 'data' => ['code' => -9]]);
        $auth = 'A' . str_pad((string)$seq, 8, '0', STR_PAD_LEFT);
        $state['zarinpal'][$auth] = ['amount' => (int)$in['amount'], 'order' => $in['callback_url'] ?? ''];
        $save();
        out(200, ['data' => ['code' => 100, 'authority' => $auth]]);
    }
    $a = (string)($in['authority'] ?? '');
    $row = $state['zarinpal'][$a] ?? null;
    if (str_starts_with($a, 'FAIL')) out(200, ['data' => ['code' => -50, 'message' => 'پرداخت لغو شد']]);
    if (!$row) out(200, ['data' => ['code' => -50, 'message' => 'authority نامعتبر']]);
    $save();
    out(200, ['data' => ['code' => 100, 'ref_id' => 100000 + $seq, 'card_pan' => '621986******1234', 'amount' => $row['amount']]]);
}

// ---------------------------------------------------------------- واریزا
if ($gw === 'variza' && $act === 'api/v1/pay') {
    $slug = 'slg' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
    $state['variza'][$slug] = ['amount' => (int)($in['amount'] ?? 0)];
    $save();
    out(201, ['slug' => $slug, 'pay_url' => 'https://mock.local/pay/' . $slug,
        'amount' => (int)($in['amount'] ?? 0), 'expires_at' => date('Y-m-d\TH:i:sP', time() + 86400)]);
}

// ---------------------------------------------------------------- کیوب‌پی
if ($gw === 'cubepy' && $act === 'smspay/api/create-payment.php') {
    $auth = 'au' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
    $pay = ((int)($in['amount'] ?? 0)) + 72;      // چند ریال برای تشخیص واریز
    $state['cubepy'][$auth] = ['amount' => $pay];
    $save();
    out(200, ['success' => true, 'authority' => $auth,
        'payment_link' => 'https://mock.local/pay.php?authority=' . $auth,
        'pay_amount' => $pay, 'pay_amount_toman' => intdiv($pay, 10), 'is_test' => true,
        'card' => ['number' => '6219861900412221', 'holder' => 'تست فارسی', 'sheba' => 'IR000000000000000000000001'],
        'expires_at' => date('Y-m-d\TH:i:sP', time() + 1800), 'expires_in_minutes' => 30, 'show_card_in_bot' => true]);
}
if ($gw === 'cubepy' && $act === 'smspay/api/verify-payment.php') {
    $a = (string)($in['authority'] ?? '');
    $row = $state['cubepy'][$a] ?? null;
    if (!$row) out(404, ['success' => false, 'message' => 'authority نامعتبر']);
    if (!$state['cubepy_paid'][$a]) out(402, ['success' => false, 'status' => 'pending', 'message' => 'هنوز پرداختی ثبت نشده']);
    $save();
    out(200, ['success' => true, 'message' => 'پرداخت تایید شد.', 'order_id' => 'ORD' . $seq,
        'amount' => $row['amount'], 'status' => 'verified', 'match_confidence' => 100, 'match_flags' => []]);
}

// ---------------------------------------------------------------- تتراپی
if ($gw === 'tetra' && $act === 'api/create_order') {
    $auth = 'HS' . str_pad((string)$seq, 9, '0', STR_PAD_LEFT);
    $state['tetra'][$auth] = ['amount' => (int)($in['Amount'] ?? 0), 'hash' => (string)($in['Hash_id'] ?? '')];
    $save();
    out(200, ['status' => '100', 'Authority' => $auth,
        'payment_url_bot' => 'https://t.me/mock_bot?start=pay_' . $auth,
        'payment_url_web' => 'https://mock.local/payment/' . $auth, 'tracking_id' => 'GPN' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT)]);
}
if ($gw === 'tetra' && $act === 'api/verify') {
    $a = (string)($in['authority'] ?? '');
    $row = $state['tetra'][$a] ?? null;
    if (!$row) out(200, ['status' => '404', 'Message' => 'نامعتبر']);
    $save();
    out(200, ['status' => '100', 'Authority' => $a, 'Amount' => $row['amount'], 'tracking_id' => 'GPN' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT)]);
}

// ---------------------------------------------------------------- آبان
if ($gw === 'aban' && $act === 'api/v1/invoices') {
    $inv = 'inv_' . $seq;
    $payable = ((int)($in['amount_rial'] ?? 0)) + 10;
    $state['aban'][$inv] = ['amount' => $payable, 'order' => (string)($in['order_id'] ?? ''), 'verified' => false];
    $save();
    out(201, ['invoice_id' => $inv, 'status' => 'pending',
        'amount_rial' => (int)($in['amount_rial'] ?? 0), 'payable_rial' => $payable,
        'payable_toman' => intdiv($payable, 10), 'fee_rial' => 40000, 'order_id' => (string)($in['order_id'] ?? ''),
        'card_number' => '6037991234567890', 'card_holder' => 'تست', 'card_last4' => '7890',
        'payment_url' => 'https://mock.local/pay/' . $inv, 'expires_at' => date('Y-m-d\TH:i:s\Z', time() + 86400),
        'paid_at' => null, 'is_test' => true]);
}
if ($gw === 'aban' && preg_match('#^api/v1/invoices/([^/]+)/(verify|cancel)$#', $act, $m)) {
    $inv = $m[1];
    $row = $state['aban'][$inv] ?? null;
    if (!$row) out(404, ['error' => ['code' => 'invoice_not_found', 'message' => 'یافت نشد']]);
    if ($m[2] === 'verify' && $row['verified']) out(409, ['error' => ['code' => 'already_verified', 'message' => 'قبلاً تأیید شده']]);
    if ($m[2] === 'verify') $state['aban'][$inv]['verified'] = true;
    $save();
    out(200, ['verified' => true, 'invoice_id' => $inv, 'order_id' => $row['order'],
        'amount_rial' => $row['amount'], 'paid_at' => gmdate('Y-m-d\TH:i:s\Z')]);
}

// ---------------------------------------------------------------- درگاه دلخواه
if ($gw === 'generic' && $act === 'pay') {
    $id = 'g-' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
    $state['generic'][$id] = ['amount' => (int)($in['amount'] ?? 0)];
    $save();
    out(200, ['id' => $id, 'pay_url' => 'https://mock.local/g/' . $id, 'status' => 'pending']);
}
if ($gw === 'generic' && $act === 'verify') {
    $id = (string)($in['id'] ?? '');
    $row = $state['generic'][$id] ?? null;
    if (!$row) out(404, ['status' => 'not_found']);
    $save();
    out(200, ['status' => 'paid', 'ref_id' => 'RG-' . $seq, 'amount' => $row['amount']]);
}

// ---------------------------------------------------------------- ابزار تست
// علامت‌گذاری یک فاکتور کیوب‌پی به‌عنوان «پرداخت‌شده» (شبیه‌سازی پیامک بانک)
if ($gw === '_pay' && $act === 'cubepy') {
    $state['cubepy_paid'][(string)($in['authority'] ?? '')] = true;
    $save();
    out(200, ['ok' => true]);
}
if ($gw === '_pay' && $act === 'unpay-cubepy') {
    unset($state['cubepy_paid'][(string)($in['authority'] ?? '')]);
    $save();
    out(200, ['ok' => true]);
}

out(404, ['error' => ['code' => 'unknown_route', 'message' => 'مسیر شبیه‌سازی‌نشده: ' . $gw . ':' . $act]]);
