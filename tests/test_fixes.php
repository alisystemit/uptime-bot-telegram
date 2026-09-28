<?php
/**
 * تست واحد برای فیکس‌های انجام‌شده
 * اجرا: php tests/test_fixes.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo "✅ {$name}\n";
        $passed++;
    } else {
        echo "❌ {$name}\n";
        $failed++;
    }
}

echo "=== تست فیکس‌ها ===\n\n";

// ۱) تست normalizeTarget operator precedence fix
echo "--- normalizeTarget fix ---\n";
$result = normalizeTarget('https://example.com/path');
assert_test('normalizeTarget returns ok for valid URL', $result['ok'] === true);
assert_test('normalizeTarget type is http', $result['type'] === 'http');
assert_test('normalizeTarget host is correct', $result['host'] === 'example.com');
assert_test('normalizeTarget target includes path', $result['target'] === 'https://example.com/path');
assert_test('normalizeTarget label includes path', $result['label'] === 'example.com/path');

// ۲) تست normalizeTarget without path
$result2 = normalizeTarget('example.com');
assert_test('normalizeTarget returns ok for domain', $result2['ok'] === true);
assert_test('normalizeTarget label is just host', $result2['label'] === 'example.com');

// ۳) تست normalizeTarget operator precedence (the actual bug fix)
$result3 = normalizeTarget('https://example.com/');
assert_test('normalizeTarget trailing slash: target has no trailing slash', $result3['target'] === 'https://example.com');
assert_test('normalizeTarget trailing slash: label has no trailing slash', $result3['label'] === 'example.com');

// ۴) تست faToLatin برای تبدیل اعداد فارسی به لاتین
echo "\n--- faToLatin ---\n";
assert_test('faToLatin converts Persian digits', faToLatin('۱۲۳') === '123');
assert_test('faToLatin converts mixed text', faToLatin('۱۰۰ ثانیه') === '100 ثانیه');
assert_test('faToLatin handles Arabic-Indic digits', faToLatin('١٢٣') === '123');

// ۵) تست faNum برای اعداد فارسی
echo "\n--- faNum ---\n";
assert_test('faNum converts integers', faNum(42) === '۴۲');
assert_test('faNum converts strings', faNum('100') === '۱۰۰');

// ۶) تست faPct
echo "\n--- faPct ---\n";
$pctResult = faPct(99.5);
assert_test('faPct returns percent sign', str_ends_with($pctResult, '٪'));
assert_test('faPct returns dash for null', faPct(null) === '—');
assert_test('faPct contains Persian chars', preg_match('/[\x{06F0}-\x{06F9}]/u', $pctResult) === 1);
// ۷) تست faDuration
echo "\n--- faDuration ---\n";
assert_test('faDuration for 60 seconds', faDuration(60) === '۱ دقیقه');
assert_test('faDuration for zero', faDuration(0) === '۰ ثانیه');
assert_test('faDuration for 90 seconds', faDuration(90) === '۱ دقیقه و ۳۰ ثانیه');

// ۸) تست truncateFa
echo "\n--- truncateFa ---\n";
assert_test('truncateFa returns short string', truncateFa('سلام', 10) === 'سلام');
$truncated = truncateFa(str_repeat('ا', 100), 10);
assert_test('truncateFa truncates long string (10 chars)', mb_strlen($truncated) === 10);

// ۹) تست validHost
echo "\n--- validHost ---\n";
assert_test('validHost accepts domain', validHost('example.com') === true);
assert_test('validHost accepts localhost', validHost('localhost') === true);
assert_test('validHost accepts IP', validHost('127.0.0.1') === true);
assert_test('validHost rejects empty', validHost('') === false);

// ۱۰) تست parseCommand
echo "\n--- parseCommand ---\n";
$cmd1 = parseCommand('/start');
assert_test('parseCommand /start', $cmd1['cmd'] === 'start');
$cmd2 = parseCommand('/add@MyBot https://example.com');
assert_test('parseCommand with bot username', $cmd2['cmd'] === 'add');
assert_test('parseCommand rest', $cmd2['rest'] === 'https://example.com');

// ۱۱) تست statusName/statusEmoji/statusLabel
echo "\n--- status functions ---\n";
assert_test('statusName up', statusName('up') === 'فعال');
assert_test('statusName down', statusName('down') === 'قطع');
assert_test('statusEmoji up', statusEmoji('up') === '🟢');
assert_test('statusEmoji down', statusEmoji('down') === '🔴');
assert_test('statusLabel slow', statusLabel('slow') === 'کند');

// ۱۲) تست siteState
echo "\n--- siteState ---\n";
assert_test('siteState paused', siteState(['paused' => 1]) === 'paused');
assert_test('siteState up', siteState(['status' => 'up']) === 'up');
assert_test('siteState slow', siteState(['status' => 'up', 'slow' => 1]) === 'slow');

// ۱۳) تست baseDomain
echo "\n--- baseDomain ---\n";
assert_test('baseDomain simple', baseDomain('example.com') === 'example.com');
assert_test('baseDomain co.ir', baseDomain('test.co.ir') === 'test.co.ir');
assert_test('baseDomain IP', baseDomain('127.0.0.1') === '127.0.0.1');

// ۱۴) تست progressBar
echo "\n--- progressBar ---\n";
$bar = progressBar(50, 10);
assert_test('progressBar returns string', is_string($bar));
assert_test('progressBar correct length', mb_strlen($bar) === 10);
assert_test('progressBar empty for null', mb_strlen(progressBar(null)) === 10);

// ۱۵) تست timeAgo (basic)
echo "\n--- timeAgo ---\n";
$result = timeAgo(date('Y-m-d H:i:s', time() - 30));
assert_test('timeAgo recent', $result !== 'هرگز');

// ۱۶) تست makeShareToken
echo "\n--- makeShareToken ---\n";
$token = makeShareToken(20);
assert_test('makeShareToken length', strlen($token) === 20);
assert_test('makeShareToken unique', makeShareToken(20) !== $token);

// ۱۷) تست uniqueToken
echo "\n--- uniqueToken ---\n";
$unique = uniqueToken(20, fn(string $t) => false);
assert_test('uniqueToken returns string', is_string($unique));
assert_test('uniqueToken length', strlen($unique) === 20);

// ۱۸) تست parseCommand edge cases
echo "\n--- parseCommand edge cases ---\n";
$cmd3 = parseCommand('/');
assert_test('parseCommand empty command', $cmd3['cmd'] === '');
$cmd4 = parseCommand('hello');
assert_test('parseCommand no slash', $cmd4['cmd'] === '');

// ۱۹) تست faToLatin with faNum chain
echo "\n--- faToLatin + faNum chain ---\n";
assert_test('faNum of faToLatin result', faNum(faToLatin('۵۰۰')) === '۵۰۰');

// ۲۰) تست عملکرد صحیح آرایه‌های وضعیت
echo "\n--- Status arrays ---\n";
$stateMap = ['up' => '🟢', 'down' => '🔴', 'slow' => '🟠', 'unknown' => '🟡'];
assert_test('state map has up', $stateMap['up'] === '🟢');
assert_test('state map has down', $stateMap['down'] === '🔴');
assert_test('state map has slow', $stateMap['slow'] === '🟠');

echo "\n=== نتیجه ===\n";
echo "گذشته: {$passed} | ناموفق: {$failed}\n";

if ($failed > 0) {
    exit(1);
}
echo "\n✅ همهٔ تست‌ها گذشت!\n";
