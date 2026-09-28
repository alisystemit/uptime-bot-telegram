<?php
/**
 * ===== پایش گواهی SSL و انقضای دامنه =====
 *
 * Ssl::check()  → خواندن گواهی HTTPS یک میزبان (تاریخ انقضا، صادرکننده، تطابق نام)
 * Domain::whois() → تاریخ انقضای ثبت دامنه از WHOIS
 *
 * هر دو تابع «best-effort» هستند: اگر هاست اجازهٔ اتصال SSL/پورت ۴۳ را ندهد،
 * به‌جای خطای دلخواه یک پیام قابل‌فهم برمی‌گردانند و صفحهٔ وضعیت آن را
 * با حالت «نامشخص» نشان می‌دهد.
 */
class Ssl
{
    /**
     * @return array{ok:bool, days:int, expires:?string, issuer:string, subject:string, match:?int, error:string}
     */
    public static function check(string $host, int $port = 443, float $timeout = 6.0): array
    {
        $out = ['ok' => false, 'days' => -1, 'expires' => null, 'issuer' => '', 'subject' => '', 'match' => null, 'error' => ''];
        $host = trim($host);
        if ($host === '') { $out['error'] = 'میزبان خالی است'; return $out; }
        if (!function_exists('openssl_x509_parse')) { $out['error'] = 'افزونهٔ openssl روی هاست فعال نیست'; return $out; }
        if (filter_var($host, FILTER_VALIDATE_IP) === false && $host[0] === '[') $host = trim($host, '[]');

        $ctx = stream_context_create(['ssl' => [
            // اعتبارسنجی عمداً خاموش است: گواهی self-signed هم باید خوانده شود
            'capture_peer_cert'   => true,
            'verify_peer'         => false,
            'verify_peer_name'    => false,
            'allow_self_signed'   => true,
            'SNI_enabled'         => true,
            'peer_name'           => $host,
            'disable_compression' => true,
        ]]);

        $addr = strpos($host, ':') !== false && filter_var($host, FILTER_VALIDATE_IP) === false ? '[' . $host . ']' : $host;
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client('ssl://' . $addr . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!is_resource($fp)) {
            $out['error'] = 'اتصال SSL ناموفق' . ($errstr !== '' ? ': ' . $errstr : '');
            return $out;
        }
        $params = @stream_context_get_params($fp);
        @fclose($fp);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (empty($cert)) { $out['error'] = 'گواهی از سرور دریافت نشد'; return $out; }
        $p = @openssl_x509_parse($cert);
        if (!is_array($p)) { $out['error'] = 'گواهی قابل خواندن نبود'; return $out; }

        $to = (int)($p['validTo_time_t'] ?? 0);
        $from = (int)($p['validFrom_time_t'] ?? 0);
        $out['issuer'] = self::name($p['issuer'] ?? null);
        $out['subject'] = self::name($p['subject'] ?? null);
        $out['expires'] = $to > 0 ? date('Y-m-d H:i:s', $to) : null;
        $out['days'] = $to > 0 ? (int)floor(($to - time()) / 86400) : -1;
        $out['match'] = self::hostInCert($host, $p);

        if ($to > 0 && $to < time()) { $out['error'] = 'گواهی منقضی شده است'; return $out; }
        if ($from > 0 && $from > time()) { $out['error'] = 'گواهی هنوز فعال نشده است'; return $out; }
        if ($out['match'] === 0) { $out['error'] = 'گواهی برای این دامنه صادر نشده است'; return $out; }
        $out['ok'] = true;
        return $out;
    }

    /** نام خوانا از ساختار issuer/subject افزونهٔ openssl */
    private static function name($node): string
    {
        if (!is_array($node)) return '';
        foreach (['CN', 'commonName'] as $k) {
            if (!empty($node[$k])) return (string)$node[$k];
        }
        $parts = [];
        foreach (['O', 'organizationName'] as $k) {
            if (!empty($node[$k])) { $parts[] = (string)$node[$k]; break; }
        }
        return $parts ? implode(' — ', $parts) : '';
    }

    /**
     * آیا نام میزبان داخل CN یا SAN گواهی هست؟
     * @return int|null 1=بله، 0=خیر، null=نامشخص (SAN و CN خوانده نشد)
     */
    private static function hostInCert(string $host, array $p): ?int
    {
        $host = strtolower(trim($host, '[]'));
        $names = [];
        $san = $p['extensions']['subjectAltName'] ?? '';
        if (is_string($san) && $san !== '') {
            foreach (explode(',', $san) as $item) {
                $item = trim($item);
                if (stripos($item, 'DNS:') === 0) $names[] = strtolower(substr($item, 4));
            }
        }
        $cn = '';
        foreach (['CN', 'commonName'] as $k) {
            if (!empty($p['subject'][$k])) { $cn = strtolower((string)$p['subject'][$k]); break; }
        }
        if ($cn !== '') $names[] = $cn;
        if (!$names) return null;
        foreach ($names as $n) {
            if ($n === $host) return 1;
            if (strncmp($n, '*.', 2) === 0 && strlen($host) > 2) {
                $tail = substr($n, 1); // «.example.com»
                if (substr($host, -strlen($tail)) === $tail && strpos($host, '.') < strlen($host) - strlen($tail)) return 1;
            }
        }
        return 0;
    }

    /** متن فارسی کوتاه برای تلگرام */
    public static function line(array $info, float $tz = 3.5): string
    {
        if (!empty($info['error'])) return '❌ ' . $info['error'];
        $d = (int)($info['days'] ?? -1);
        $icon = $d <= 7 ? '🔴' : ($d <= 14 ? '🟠' : '🟢');
        return $icon . ' انقضای گواهی: ' . ($d < 0 ? '—' : faLeft($d * 86400))
            . ' • ' . faDay((string)($info['expires'] ?? null), $tz)
            . (!empty($info['issuer']) ? "\n🏛 صادرکننده: " . $info['issuer'] : '');
    }

    /** پشتیبانی از TLS روی هاست؟ (برای صفحهٔ پنل) */
    public static function supported(): bool
    {
        return function_exists('openssl_x509_parse') && function_exists('stream_socket_client') && in_array('ssl', stream_get_transports(), true);
    }
}

class Domain
{
    /** نگاشت پرکاربرد TLD به سرور WHOIS (بقیه از مرجع IANA گرفته می‌شوند) */
    private const SERVERS = [
        'ir'     => 'whois.nic.ir',
        'com'    => 'whois.verisign-grs.com',
        'net'    => 'whois.verisign-grs.com',
        'org'    => 'whois.publicinterestregistry.org',
        'info'   => 'whois.afilias.net',
        'io'     => 'whois.nic.io',
        'co'     => 'whois.nic.co',
        'me'     => 'whois.nic.me',
        'dev'    => 'whois.nic.google',
        'app'    => 'whois.nic.google',
        'xyz'    => 'whois.nic.xyz',
        'online' => 'whois.nic.online',
        'site'   => 'whois.nic.site',
        'shop'   => 'whois.nic.shop',
    ];

    /**
     * تاریخ انقضای دامنه از WHOIS.
     * @return array{ok:bool, expires:?string, registrar:string, error:string}
     */
    public static function whois(string $domain, float $timeout = 8.0): array
    {
        $out = ['ok' => false, 'expires' => null, 'registrar' => '', 'error' => ''];
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^https?:\/\//', '', $domain) ?? $domain;
        $domain = trim(explode('/', $domain)[0]);
        $domain = preg_replace('/:\d+$/', '', $domain) ?? $domain;
        if (!validHost($domain) || filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            $out['error'] = 'دامنهٔ نامعتبر';
            return $out;
        }
        if (!function_exists('fsockopen')) { $out['error'] = 'اتصال شبکه در دسترس نیست'; return $out; }

        $deadline = microtime(true) + $timeout;
        $server = self::SERVERS[self::tld($domain)] ?? 'whois.iana.org';
        $text = self::query($server, $domain, $deadline);
        if ($text === '' && $server !== 'whois.iana.org') {
            $text = self::query('whois.iana.org', $domain, $deadline);
        }
        if ($text === '') {
            $out['error'] = 'WHOIS در دسترس نیست (پورت ۴۳ هاست بسته است)';
            return $out;
        }
        // مرجع IANA معمولاً خودش دامنه را به سرور مرتبط هدایت می‌کند
        if (preg_match('/^\s*whois:\s*(\S+)/mi', $text, $m)) {
            $ref = strtolower(trim($m[1]));
            if ($ref !== '' && $ref !== $server) {
                $t2 = self::query($ref, $domain, $deadline);
                if ($t2 !== '') $text = $t2;
            }
        }

        $ts = self::parseExpiry($text);
        $out['registrar'] = self::parseRegistrar($text);
        if ($ts === null) {
            $out['error'] = 'تاریخ انقضا در پاسخ WHOIS پیدا نشد';
            return $out;
        }
        // رجیسترهای «نازک» (مثل verisign) نام ثبت‌کننده را نمی‌دهند؛
        // در آن حالت یک‌بار از مرجع IANA می‌پرسیم.
        if ($out['registrar'] === '' && $server !== 'whois.iana.org') {
            $ref = self::query('whois.iana.org', $domain, $deadline);
            if ($ref !== '') $out['registrar'] = self::parseRegistrar($ref);
        }
        $out['expires'] = date('Y-m-d H:i:s', $ts);
        $out['ok'] = true;
        return $out;
    }

    private static function tld(string $domain): string
    {
        $parts = explode('.', $domain);
        return $parts[count($parts) - 1] ?? '';
    }

    private static function query(string $server, string $query, float $deadline): string
    {
        $left = $deadline - microtime(true);
        if ($left <= 0.3) return '';
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($server, 43, $errno, $errstr, min(5.0, $left));
        if (!is_resource($fp)) return '';
        stream_set_timeout($fp, (int)min(5, max(1, $left)));
        @fwrite($fp, $query . "\r\n");
        $out = '';
        while (!feof($fp)) {
            $chunk = @fread($fp, 4096);
            if ($chunk === false || $chunk === '') break;
            $out .= $chunk;
            if (strlen($out) > 60000) break;
            if (microtime(true) >= $deadline) break;
        }
        @fclose($fp);
        return $out;
    }

    /** استخراج timestamp انقضا از ده‌ها قالب مختلف WHOIS */
    private static function parseExpiry(string $text): ?int
    {
        // ۱) تاریخ شمسی (پاسخ whois.nic.ir و برخی رجیسترهای ایرانی)
        if (preg_match('/(?:expire|expiry|paid-?till)[^\n]{0,40}?[:]\s*([۰-۹0-9]{4})[\/\-]([۰-۹0-9]{1,2})[\/\-]([۰-۹0-9]{1,2})/ui', $text, $m)) {
            $ts = self::jalaliToTs(
                faToLatin($m[1]),
                faToLatin($m[2]),
                faToLatin($m[3])
            );
            if ($ts !== null && $ts > 946684800) return $ts;
        }
        // ۲) تاریخ میلادی
        $patterns = [
            '/expir(?:y|ation)\s+date[^\n]*?[:]\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i',
            '/expir(?:y|ation)\s+date[^\n]*?[:]\s*([0-9]{2}\/[0-9]{2}\/[0-9]{4})/i',
            '/paid-?till[^\n]*?[:]\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i',
            '/renewal\s+date[^\n]*?[:]\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i',
            '/domain\s+expiration\s+date[^\n]*?[:]\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i',
            '/expire[^\n]*?[:]\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i',
        ];
        foreach ($patterns as $re) {
            if (!preg_match($re, $text, $m)) continue;
            $raw = trim($m[1]);
            $raw = preg_replace('/\s*(T|Z|UTC|\+[0-9:]+).*$/i', '', $raw) ?? $raw;
            $ts = strtotime(str_replace('/', '-', $raw));
            if ($ts === false) continue;
            if ($ts > 946684800) return $ts; // بعد از ۲۰۰۰ — منطقی است
        }
        return null;
    }

    /**
     * تبدیل تاریخ شمسی به timestamp (روش استاندارد jdf).
     * @return int|null
     */
    public static function jalaliToTs(string $jy, string $jm, string $jd): ?int
    {
        $jy = (int)$jy; $jm = (int)$jm; $jd = (int)$jd;
        if ($jy < 1200 || $jy > 1700 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) return null;
        $jy += 1595;
        $days = -355668 + (365 * $jy) + intdiv($jy, 33) * 8 + intdiv(($jy % 33) + 3, 4) + $jd;
        $days += $jm < 7 ? (($jm - 1) * 31) : ((($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) $days++;
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $leap = ($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0;
        $md = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;
        for ($gm = 0; $gm < 13 && $gd > $md[$gm]; $gm++) { $gd -= $md[$gm]; }
        if ($gm < 1 || $gm > 12) return null;
        return (int)gmmktime(0, 0, 0, $gm, $gd, $gy);
    }

    private static function parseField(string $text, string $re): string
    {
        if (!preg_match($re, $text, $m)) return '';
        return trim((string)$m[1]);
    }

    /** نام ثبت‌کننده — بسته به سرور WHOIS کلیدهای متفاوتی دارد */
    private static function parseRegistrar(string $text): string
    {
        $patterns = [
            '/^(?:Sponsoring\s+)?Registrar:\s*(.+)$/im',   // verisign، afilias، نیک‌ایر
            '/^Registrant\s+(?:Organisation|Organization):\s*(.+)$/im',
            '/^organisation:\s*(.+)$/im',                    // پاسخ IANA
            '/^organization:\s*(.+)$/im',
        ];
        foreach ($patterns as $re) {
            $v = self::parseField($text, $re);
            if ($v !== '') return mb_substr($v, 0, 160);
        }
        return '';
    }

    public static function supported(): bool
    {
        return function_exists('fsockopen');
    }
}
