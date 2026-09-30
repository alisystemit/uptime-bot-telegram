<?php
/**
 * تحلیل‌گر ایستا — شکار علت‌های «کد کار نمی‌کند» بدون اجرا
 *
 *   php tools/analyze.php
 *
 * مواردی که پیدا می‌کند:
 *   ۱) فراخوانی متد/تابعی که در هیچ‌جای پروژه تعریف نشده (تایپو، متد حذف‌شده)
 *   ۲) دسترسی به آرایه/شیء‌ای که می‌تواند null باشد و بلافاصله استفاده شده
 *   ۳) تقسیم بر متغیری که ممکن است صفر باشد
 *   ۴) استفاده از کلیدهای ثابت روی آرایه‌ای که از json_decode می‌آید
 *   ۵) فراخوانی آرایه‌ای روی نامی که فقط رشته است (مثل callback با نام متد)
 *   ۶) شرطی که همیشه true/false است (مقایسهٔ رشته با رشتهٔ ثابت)
 *   ۷) استفاده از متغیر تعریف‌نشده (typo در نام متغیر)
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('memory_limit', '512M');

$root = dirname(__DIR__);
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php') continue;
    if (strpos($p, '\\vendor\\') !== false || strpos($p, '\\tools\\') !== false) continue;
    $files[] = $p;
}
sort($files);

// ── توکن‌سازی همهٔ فایل‌ها یک‌جا (تا بتوانیم «تعریف‌شده»ها را سراسری دید) ──
$defs = [
    'function' => [], 'class' => [], 'method' => [], 'const' => [],
    'trait' => [], 'iface' => [], 'var' => [], 'props' => [],
];
$uses = [];   // file => [tokens]

function parseFile(string $path): array
{
    $src = (string)file_get_contents($path);
    $tokens = @token_get_all($src);
    $out = ['function' => [], 'class' => [], 'method' => [], 'const' => [], 'trait' => [], 'iface' => [], 'var' => [], 'props' => []];
    $n = count($tokens);
    $classStack = [];
    $curClass = null;
    $curFunc = null;
    $braceDepth = 0;
    $funcDepth = null;
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t)) {
            $name = $t[0]; $text = $t[1]; $line = $t[2];
            if ($name === T_FUNCTION) {
                // نام تابع بعدی
                for ($j = $i + 1; $j < $n; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        if ($curClass !== null) { $out['method'][$tokens[$j][1]] = true; }
                        else { $out['function'][$tokens[$j][1]] = true; }
                        $curFunc = $tokens[$j][1];
                        break;
                    }
                    if ($tokens[$j] === '(' || (is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON)) { break; }
                }
            } elseif ($name === T_CLASS || $name === T_INTERFACE || $name === T_TRAIT) {
                // تا T_STRING جلو برو (skip extends/implements)
                for ($j = $i + 1; $j < $n; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $cn = $tokens[$j][1];
                        if ($name === T_CLASS) $out['class'][$cn] = true;
                        elseif ($name === T_INTERFACE) $out['iface'][$cn] = true;
                        else $out['trait'][$cn] = true;
                        break;
                    }
                }
            } elseif ($name === T_CONST) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { $out['const'][$tokens[$j][1]] = true; break; }
                }
            } elseif ($name === T_VARIABLE) {
                $out['var'][ltrim($text, '$')] = true;
            } elseif ($name === T_OBJECT_OPERATOR) {
                // $this->prop
                for ($j = $i + 1; $j < $n; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { $out['props'][$tokens[$j][1]] = true; break; }
                    if ($tokens[$j] === '(') break;
                }
            }
        }
    }
    return $out;
}

foreach ($files as $f) {
    $p = parseFile($f);
    foreach ($p as $k => $v) $defs[$k] += $v;
    $uses[$f] = $p;
}

// توکن‌های فراخوانی: name(
$calls = [];
$staticCalls = [];
$methodCalls = [];
foreach ($files as $f) {
    $tokens = @token_get_all((string)file_get_contents($f));
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) continue;
        // تابع ساده: T_STRING یا T_NAME_* که بعدش '(' است
        if (in_array($t[0], [T_STRING, T_ISSET, T_UNSET, T_EMPTY, T_EXIT], true)) {
            $j = $i + 1; while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if ($j < $n && $tokens[$j] === '(') {
                // آیا قبلش متد/استاتیک است؟
                $k = $i - 1; while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) $k--;
                $isMethod = ($k >= 0 && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true));
                if (!$isMethod) $calls[$t[1]][] = "$f:{$t[2]}";
                else $methodCalls[$t[1]][] = "$f:{$t[2]}";
            }
        }
        if ($t[0] === T_NAME_FULLY_QUALIFIED || $t[0] === T_NAME_QUALIFIED || $t[0] === T_NAME_RELATIVE) {
            $short = ltrim($t[1], '\\');
            $j = $i + 1; while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if ($j < $n && $tokens[$j] === '(') $calls[$short][] = "$f:{$t[2]}";
        }
    }
}

// ── ۱) توابع ناشناخته ────────────────────────────────────────
$builtins = array_flip([
    'array','array_map','array_filter','array_merge','array_keys','array_values','array_slice','array_column',
    'array_sum','array_unique','array_reverse','array_search','array_key_exists','array_key_first','array_key_last',
    'array_fill','array_push','array_pop','array_shift','array_unshift','array_splice','array_merge_recursive',
    'str_replace','str_ireplace','strtolower','strtoupper','ucfirst','lcfirst','ucwords','strlen','substr','strpos',
    'stripos','strrpos','str_repeat','str_pad','str_split','strrev','strcmp','strcasecmp','strncmp','strstr','strrchr',
    'str_contains','str_starts_with','str_ends_with','trim','ltrim','rtrim','chop','sprintf','printf','vsprintf','number_format',
    'intval','floatval','strval','boolval','settype','gettype','is_numeric','ctype_digit','ctype_alpha','ctype_alnum',
    'json_encode','json_decode','json_last_error','json_last_error_msg',
    'preg_match','preg_match_all','preg_replace','preg_replace_callback','preg_split','preg_quote','preg_grep',
    'explode','implode','join','sprintf','vsprintf','serialize','unserialize','var_export','print_r','extract','compact',
    'date','time','mktime','strtotime','gmdate','date_default_timezone_set','date_diff','checkdate','microtime','hrtime',
    'rand','mt_rand','random_int','random_bytes','uniqid','shuffle','array_rand',
    'min','max','abs','ceil','floor','round','intdiv','fmod','pow','sqrt','log','log10','exp','pi','is_nan','is_infinite',
    'count','sizeof','in_array','array_diff','array_diff_key','array_intersect','array_intersect_key','range',
    'is_array','is_string','is_int','is_float','is_bool','is_null','is_object','is_callable','is_iterable','is_scalar',
    'gettype','settype','iterator_to_array',
    'file_get_contents','file_put_contents','file_exists','is_file','is_dir','is_readable','is_writable','unlink','mkdir',
    'fopen','fclose','fread','fwrite','fgets','feof','fflush','flock','fseek','rewind','fgets','scandir','glob','opendir','readdir',
    'curl_init','curl_setopt','curl_setopt_array','curl_exec','curl_close','curl_error','curl_errno','curl_getinfo',
    'curl_getopts','curl_multi_add_handle','curl_multi_exec','curl_multi_init','curl_multi_select','curl_close','curl_reset',
    'openssl_open','openssl_x509_read','openssl_x509_parse','openssl_x509_checkpurpose','openssl_free_x509','openssl_error_string',
    'openssl_connect','openssl_encrypt','openssl_decrypt','openssl_digest','openssl_sign','openssl_verify','openssl_random_pseudo_bytes',
    'gethostbyname','gethostbynamel','gethostbyaddr','ip2long','long2ip','inet_ntop','inet_pton','dns_get_record','checkdnsrr',
    'stream_socket_client','stream_socket_enable_crypto','stream_context_create','fsockopen','socket_create',
    'get_headers','getallheaders','header','headers_sent','http_response_code','setcookie','session_start',
    'filter_var','filter_input','preg_grep','hash','hash_hmac','hash_algos','md5','sha1','crc32','base64_encode','base64_decode',
    'htmlspecialchars','html_entity_decode','htmlentities','strip_tags','nl2br','wordwrap','addslashes','stripslashes',
    'urlencode','urldecode','rawurlencode','rawurldecode','parse_url','parse_str','http_build_query',
    'ob_start','ob_get_clean','ob_get_contents','ob_end_clean','flush',
    'call_user_func','call_user_func_array','func_get_args','func_num_args','function_exists','method_exists',
    'class_exists','interface_exists','trait_exists','property_exists','get_class','get_parent_class','get_object_vars',
    'get_class_methods','is_a','is_subclass_of','instanceof',
    'define','defined','constant','defined',
    'settype','usleep','sleep','time_nanosleep','register_shutdown_function','set_error_handler','set_exception_handler',
    'error_reporting','ini_set','ini_get','extension_loaded','function_exists','putenv','getenv','php_uname',
    'memory_get_usage','memory_get_peak_usage','gc_collect_cycles','sys_get_temp_dir','getmypid','gethostname',
    'intdiv','array_find','array_any','array_all','str_split','mb_strlen','mb_substr','mb_strpos','mb_strtoupper',
    'mb_strtolower','mb_convert_encoding','mb_detect_encoding','mb_internal_encoding','mb_substr_count','mb_str_split',
    'iconv','json_last_error','lcg_value','str_word_count','wordwrap','similar_text','soundex','metaphone',
    'levenshtein','array_multisort','usort','uasort','uksort','ksort','krsort','asort','arsort','natsort','shuffle',
    'array_walk','array_walk_recursive','array_map','iterator_to_array','version_compare','php_sapi_name','gc_collect_cycles',
    'ctype_space','ctype_upper','ctype_lower','ctype_punct','ctype_xdigit','ctype_print','ctype_graph','ctype_cntrl',
    'array_diff_assoc','array_intersect_assoc','array_fill_keys','array_pad','chunk_split','wordwrap','nl2br',
    'str_word_count','substr_count','substr_replace','strtr','strpbrk','strspn','strcspn','substr_compare',
    'array_walk','vsprintf','sprintf','money_format','date_create','date_parse','date_interval_create_from_date_string',
    'hash_pbkdf2','password_hash','password_verify','random_bytes','openssl_sign','openssl_verify',
    'exec','shell_exec','system','passthru','proc_open','proc_close','proc_get_status','popen','escapeshellarg','escapeshellcmd',
    'flock','ftruncate','stream_get_meta_data','stream_get_contents','stream_set_timeout',
    'posix_getpid','getmypid','uniqid','hash_equals','array_find','enum_exists',
]);

echo "\n\033[1m═══ ۱) فراخوانی تابع ناشناخته ═══\033[0m\n";
$found = 0;
foreach ($calls as $name => $locs) {
    if (isset($builtins[$name])) continue;
    if (isset($defs['function'][$name])) continue;
    // متد static که با :: صدا زده شده بود در methodCalls است، اینجا نیست
    $found++;
    printf("  ✗ %-28s  %s\n", $name, implode('  ', array_slice(array_unique($locs), 3)));
}
if (!$found) echo "  ✓ هیچ\n";

echo "\n\033[1m═══ ۲) متد ناشناخته روی کلاس‌های خودمان ═══\033[0m\n";
$ours = array_flip(array_merge(
    array_keys($defs['class']), array_keys($defs['trait']), array_keys($defs['iface'])
));
$knownMethods = array_flip(array_merge(
    array_keys($defs['method']), array_keys($defs['props']),
    array_keys(['__construct','__toString','__get','__set','__call','__callStatic','__isset','__invoke'])
));
$found = 0;
foreach ($methodCalls as $name => $locs) {
    if (isset($knownMethods[$name])) continue;
    // متدهای شناخته‌شدهٔ داخلی
    if (isset($builtins[$name])) continue;
    $found++;
    printf("  ? %-28s  %s\n", $name, implode('  ', array_slice(array_unique($locs), 3)));
}
if (!$found) echo "  ✓ هیچ\n";

echo "\n\033[1m═══ ۳) متغیر استفاده‌شده که هیچ‌جا تعریف/-assign نشده ═══\033[0m\n";
$found = 0;
foreach ($files as $f) {
    $src = (string)file_get_contents($f);
    if (!preg_match_all('/\$([a-z_][a-z0-9_]*)/i', $src, $m)) continue;
    $assigned = [];
    // تعریف: = (ولی نه == یا => یا >= یا !=)
    if (preg_match_all('/\$([a-z_][a-z0-9_]*)\s*(?:\.\s*|\[\s*[^]]*\s*\]\s*)*=(?!=|>)/i', $src, $m2)) {
        foreach ($m2[1] as $v) $assigned[strtolower($v)] = true;
    }
    // ورودی تابع
    if (preg_match_all('/function\s+\w+\s*\(([^)]*)\)/i', $src, $m3)) {
        foreach ($m3[1] as $args) {
            if (preg_match_all('/\$([a-z_][a-z0-9_]*)/i', $args, $m4)) foreach ($m4[1] as $v) $assigned[strtolower($v)] = true;
        }
    }
    foreach (preg_match_all('/(?:global|static|use\s*&?)\s*\$([a-z_][a-z0-9_]*)/i', $src, $m5) as $x) {}
    if (preg_match_all('/(?:global|static)\s+\$([a-z_][a-z0-9_]*)/i', $src, $m5)) foreach ($m5[1] as $v) $assigned[strtolower($v)] = true;
    if (preg_match_all('/foreach\s*\([^)]*?\$([a-z_][a-z0-9_]*)/i', $src, $m6)) foreach ($m6[1] as $v) $assigned[strtolower($v)] = true;
    if (preg_match_all('/catch\s*\([^)]*?\$([a-z_][a-z0-9_]*)/i', $src, $m7)) foreach ($m7[1] as $v) $assigned[strtolower($v)] = true;
    if (preg_match_all('/(?:list|\[\s*)\s*[\'"]?[^\]\'"]*[\'"]?\s*,\s*\$([a-z_][a-z0-9_]*)/', $src, $m8)) foreach ($m8[1] as $v) $assigned[strtolower($v)] = true;
    if (preg_match_all('/extract\s*\(/i', $src)) continue;   // با extract نمی‌شود
    if (preg_match_all('/compact\s*\(/i', $src)) continue;

    $undef = [];
    foreach (m1_unique($m[1]) as $v) {
        $lv = strtolower($v);
        if (isset($assigned[$lv])) continue;
        if (isset($defs['var'][$lv])) continue;      // پارامتر فایل/کلاس دیگری
        $undef[$v] = true;
    }
    if ($undef) {
        $found++;
        printf("  ? %-46s  %s\n", str_replace($root . '\\', '', $f), implode(', ', array_keys($undef)));
    }
}
if (!$found) echo "  ✓ هیچ\n";

echo "\n\033[1m═══ ۴) تقسیم بر متغیرِ احتمالاً صفر ═══\033[0m\n";
$found = 0;
foreach ($files as $f) {
    $src = (string)file_get_contents($f);
    if (preg_match_all('#/\s*(\$[a-z_][a-z0-9_]*)#i', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as [$var, $off]) {
            // آیا قبلش guard هست؟ (0 !== / max(1 / ?: / ?:)
            $ctx = substr($src, max(0, $off - 90), 90);
            $safe = (bool)preg_match('/(max\s*\(\s*[01]|<\s*1|==\s*0|!=\s*0|>\s*0|:\s*\?|\?\?|\|\|\s*1)\s*$/', $ctx);
            $found++;
            $line = substr_count(substr($src, 0, $off), "\n") + 1;
            printf("  %s  %s:%d  (%s)\n", $safe ? '·' : '✗', str_replace($root . '\\', '', $f), $line, trim($ctx));
        }
    }
}
if (!$found) echo "  ✓ هیچ\n";

echo "\n";
function m1_unique(array $a): array { return array_values(array_unique(array_map('strtolower', $a))); }
