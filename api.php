<?php
/**
 * ===== API عمومی صفحهٔ وضعیت (JSON) =====
 *
 *   GET /api.php?u=<share_token>
 *
 * فقط وضعیت سایت‌های همان کاربر را برمی‌گرداند؛ هیچ اطلاعات خصوصی
 * (ایمیل، شماره، توکن ربات و…) در پاسخ نیست. مصرف این endpoint توسط
 * status.php برای بروزرسانی زندهٔ صفحه انجام می‌شود.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/lib/bootstrap.php';

$out = ['ok' => false, 'error' => null];

$token = trim((string)($_GET['u'] ?? ''));
if ($token === '') {
    http_response_code(400);
    $out['error'] = 'missing_token';
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    appBoot();
} catch (Throwable $e) {
    uptimeLog('error', 'api boot failed: ' . $e->getMessage());
    http_response_code(503);
    $out['error'] = 'database';
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $user = Db::one('SELECT `id`,`name`,`username` FROM `user` WHERE `share_token` = ?', [$token]);
    if (!$user) {
        http_response_code(404);
        $out['error'] = 'not_found';
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tz       = tzOffset();
    $uid      = (int)$user['id'];
    $summary  = Stats::userSummary($uid);
    $engine   = Stats::engine();

    $sites = [];
    foreach ($summary['sites'] as $s) {
        $paused = (int)$s['paused'] === 1;
        $status = $paused ? 'paused' : (string)$s['status'];
        $cls    = ['up' => 'up', 'down' => 'down', 'paused' => 'paused'][$status] ?? 'unknown';
        $label  = ['up' => 'فعال', 'down' => 'قطع', 'paused' => 'متوقف', 'unknown' => 'نامشخص'][$cls] ?? $cls;

        $u1  = Stats::uptime($s, 1);
        $u7  = Stats::uptime($s, 7);
        $u30 = Stats::uptime($s, 30);
        $cnt = Stats::counters($s);

        $mk = static function (array $u): array {
            $p = $u['pct'];
            $cls = $p === null ? 'na' : ($p >= 99.5 ? 'great' : ($p >= 95 ? 'good' : ($p >= 80 ? 'warn' : 'bad')));
            return ['pct' => $p, 'cls' => $cls, 'checks' => (int)$u['checks']];
        };

        $err = trim((string)$s['last_error']);
        if ($status === 'down' && $err !== '') {
            $note = '<div class="errline">⚠️ ' . h($err) . '</div>';
        } elseif ($status === 'up' && (int)$s['last_code'] > 0) {
            $note = '<div class="okline">کد پاسخ: ' . faNum((int)$s['last_code']) . ' • زمان پاسخ: ' . faMs((int)$s['last_ms']) . '</div>';
        } elseif ($status === 'paused') {
            $note = '<div class="pauseline">این سایت موقتاً از چک خارج شده است.</div>';
        } else {
            $note = '';
        }

        $sites[] = [
            'id'              => (int)$s['id'],
            'label'           => (string)($s['label'] !== '' ? $s['label'] : $s['target']),
            'target'          => (string)$s['target'],
            'type'            => (string)$s['type'],
            'cls'             => $cls,
            'status_label'    => $label,
            'status'          => $status,
            'ms'              => (int)$s['last_ms'],
            'code'            => (int)$s['last_code'],
            'error'           => $err,
            'last_check'      => $s['last_check_at'],
            'last_check_ago'  => timeAgo($s['last_check_at'], $tz),
            'total_checks'    => (int)$cnt['checks'],
            'total_fails'     => (int)$cnt['fails'],
            'u1'              => $mk($u1),
            'u7'              => $mk($u7),
            'u30'             => $mk($u30),
            'bar_html'        => Stats::barHtml(Stats::recent($s, 60)),
            'note_html'       => $note,
            'sparkline'       => Stats::sparkline(Stats::recent($s, 60)),
        ];
    }

    $out = [
        'ok'       => true,
        'updated'  => faNum(date('Y/m/d H:i:s', time() + (int)round($tz * 3600))),
        'summary'  => [
            'total' => (int)$summary['total'],
            'up'    => (int)$summary['up'],
            'down'  => (int)$summary['down'],
            'paused'=> (int)$summary['paused'],
            'avg'   => $summary['uptime24'],
        ],
        'engine'   => [
            'interval'     => (int)$engine['interval'],
            'paused'       => (bool)$engine['paused'],
            'cron_healthy' => (bool)$engine['cron_healthy'],
            'last_round'   => $engine['last_round'],
            'stale_sec'    => $engine['stale'],
        ],
        'sites'    => $sites,
    ];
} catch (Throwable $e) {
    uptimeLog('error', 'api failed: ' . $e->getMessage());
    http_response_code(500);
    $out['error'] = 'internal';
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
