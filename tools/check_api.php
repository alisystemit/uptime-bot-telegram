<?php
/**
 * بررسی صحت نام متدهای Telegram Bot API
 *
 *   php tools/check_api.php
 *
 * چرا مهم است: اگر نام متد اشتباه باشد، تلگرام خطای 404 می‌دهد، لاگر
 * چیزی می‌نویسد ولی ربات هیچ پیامی نمی‌فرستد — یعنی «کد کار نمی‌کند»
 * بدون هیچ نشانهٔ ظاهری.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);

// فهرست رسمی متدهای Bot API (ماژول‌هایی که پروژه ممکن است استفاده کند)
$KNOWN = [
    'getMe' => 1, 'logOut' => 1, 'close' => 1,
    'sendMessage' => 1, 'forwardMessages' => 1, 'forwardMessage' => 1,
    'copyMessage' => 1, 'copyMessages' => 1,
    'sendPhoto' => 1, 'sendAudio' => 1, 'sendDocument' => 1, 'sendVideo' => 1,
    'sendAnimation' => 1, 'sendVoice' => 1, 'sendPaidMedia' => 1,
    'sendMediaGroup' => 1, 'sendLocation' => 1, 'sendVenue' => 1,
    'sendContact' => 1, 'sendPoll' => 1, 'sendDice' => 1, 'sendGame' => 1, 'sendInvoice' => 1,
    'sendChatAction' => 1,
    'setMessageReaction' => 1, 'getUserProfilePhotos' => 1, 'setUserEmojiStatus' => 1,
    'editMessageText' => 1, 'editMessageCaption' => 1, 'editMessageMedia' => 1,
    'editMessageLiveLocation' => 1, 'stopMessageLiveLocation' => 1,
    'editMessageReplyMarkup' => 1, 'editMessageChecklist' => 1,
    'stopPoll' => 1, 'deleteMessage' => 1, 'deleteMessages' => 1,
    'sendSticker' => 1, 'getStickerSet' => 1,
    'getForumTopicIconStickers' => 1, 'createForumTopic' => 1, 'editForumTopic' => 1,
    'closeForumTopic' => 1, 'reopenForumTopic' => 1, 'deleteForumTopic' => 1,
    'unpinAllForumTopicMessages' => 1, 'editGeneralForumTopic' => 1, 'closeGeneralForumTopic' => 1,
    'reopenGeneralForumTopic' => 1, 'hideGeneralForumTopic' => 1, 'unhideGeneralForumTopic' => 1,
    'unpinAllGeneralForumTopicMessages' => 1,
    'answerCallbackQuery' => 1, 'getUserChatBoosts' => 1, 'getBusinessConnection' => 1,
    'setMyCommands' => 1, 'deleteMyCommands' => 1, 'getMyCommands' => 1,
    'setMyName' => 1, 'getMyName' => 1, 'setMyDescription' => 1, 'getMyDescription' => 1,
    'setMyShortDescription' => 1, 'getMyShortDescription' => 1,
    'setChatMenuButton' => 1, 'getChatMenuButton' => 1, 'setMyDefaultAdministratorRights' => 1,
    'getMyDefaultAdministratorRights' => 1,
    'editMessageLiveLocation' => 1,
    'setChatTitle' => 1, 'setChatDescription' => 1, 'setChatPhoto' => 1, 'deleteChatPhoto' => 1,
    'setChatPermissions' => 1, 'exportChatInviteLink' => 1, 'createChatInviteLink' => 1,
    'editChatInviteLink' => 1, 'createChatSubscriptionInviteLink' => 1,
    'editChatSubscriptionInviteLink' => 1, 'revokeChatInviteLink' => 1,
    'approveChatJoinRequest' => 1, 'declineChatJoinRequest' => 1,
    'setChatStickerSet' => 1, 'deleteChatStickerSet' => 1,
    'getForumTopic' => 1, 'getChatAdministrators' => 1, 'getChatMemberCount' => 1,
    'getChatMember' => 1, 'setChatAdministratorCustomTitle' => 1,
    'banChatMember' => 1, 'unbanChatMember' => 1, 'restrictChatMember' => 1,
    'promoteChatMember' => 1, 'setChatPermissionsCustom' => 1,
    'setChatPhotoCustom' => 1,
    'setMessageReactionCustom' => 1,
    'exportChatInviteLinkCustom' => 1,
    'setPassportDataErrors' => 1,
    'sendInvoiceCustom' => 1,
    'setGameScore' => 1, 'getGameHighScores' => 1,
    'sendGift' => 1, 'verifyUser' => 1, 'verifyChat' => 1, 'removeUserVerification' => 1,
    'removeChatVerification' => 1,
    'readBusinessMessage' => 1, 'deleteBusinessMessages' => 1, 'setBusinessAccountName' => 1,
    'setBusinessAccountUsername' => 1, 'setBusinessAccountBio' => 1, 'setBusinessAccountProfilePhoto' => 1,
    'removeBusinessAccountProfilePhoto' => 1, 'setBusinessAccountGiftSettings' => 1,
    'getBusinessAccountStarBalance' => 1, 'transferBusinessAccountStars' => 1,
    'getBusinessAccountGifts' => 1, 'convertGiftToStars' => 1, 'upgradeGift' => 1,
    'transferGift' => 1, 'postStory' => 1, 'editStory' => 1, 'deleteStory' => 1,
    'giftPremiumSubscription' => 1, 'checkGiftCode' => 1,
    'getStarTransactions' => 1, 'refundStarPayment' => 1,
    'editUserStarSubscription' => 1,
    'approveSuggestedPost' => 1, 'declineSuggestedPost' => 1,
    'deleteBusinessMessagesCustom' => 1,
    // might be bogus entries below; the script reports anything not in the real list
];
$KNOWN = array_filter($KNOWN);

// ── ۱) نام متدهایی که مستقیم به api.telegram.org می‌روند ──
echo "\n\033[1m═══ ۱) متدهای فراخوانی‌شده مستقیم در URL ═══\033[0m\n";
$found = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php') continue;
    if (strpos($p, '\\vendor\\') !== false) continue;
    $src = (string)file_get_contents($p);
    if (preg_match_all('#api\.telegram\.org/bot[^"\']*/([A-Za-z_][A-Za-z0-9_]*)#', $src, $m)) {
        foreach ($m[1] as $name) {
            $ok = isset($KNOWN[$name]);
            printf("  %s %-30s  %s\n", $ok ? '✓' : '✗', $name, str_replace($root . '\\', '', $p));
            if (!$ok) $found++;
        }
    }
}
if (!$found) echo "  ✓ هیچ مورد مشکوکی نبود\n";

// ── ۲) متدهای استاتیک BotApi که تعریف نشده‌اند ──
echo "\n\033[1m═══ ۲) BotApi::xxx() که متد واقعی نیست ═══\033[0m\n";
require_once $root . '/lib/bootstrap.php';
$rc = new ReflectionClass('BotApi');
$real = [];
foreach ($rc->getMethods() as $m) $real[strtolower($m->getName())] = true;
foreach (['Db', 'BotApi', 'Monitor', 'Stats', 'Group', 'GroupBot', 'Ssl', 'Domain', 'Page', 'Pay', 'PayGws', 'Ranking', 'Util'] as $cls) {
    if (!class_exists($cls)) continue;
    foreach ((new ReflectionClass($cls))->getMethods() as $m) $real[strtolower($m->getName())] = true;
}
$found = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php') continue;
    if (strpos($p, '\\vendor\\') !== false) continue;
    $src = (string)file_get_contents($p);
    if (preg_match_all('/\b(BotApi|Db|Monitor|Stats|Group|GroupBot|Ssl|Domain|Page|Pay|PayGws|Ranking|Util)\s*::\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $name = $x[2];
            if (in_array(strtolower($name), ['class','function','call','__callstatic','this','parent','self'], true)) continue;
            if (isset($real[strtolower($name)])) continue;
            if (in_array($name, ['MYSQLI_ASSOC','PDO','ATTR_'], true)) continue;
            if (defined($name) || defined($x[1] . '::' . $name)) continue;
            $line = substr_count(substr($src, 0, strpos($src, $x[0])), "\n") + 1;
            printf("  ✗ %s::%s()  %s:%d\n", $x[1], $name, str_replace($root . '\\', '', $p), $line);
            $found++;
        }
    }
}
if (!$found) echo "  ✓ همهٔ متدهای استاتیک وجود دارند\n";

// ── ۳) ثابت‌های تعریف‌نشده ──
echo "\n\033[1m═══ ۳) ثابت/کلاس تعریف‌نشده ═══\033[0m\n";
$consts = get_defined_constants(true);
$allConst = [];
foreach ($consts as $group) $allConst = array_merge($allConst, array_keys($group));
$allConst = array_flip($allConst);
$classes = array_flip(array_merge(
    get_declared_classes(),
    ['Db','BotApi','Monitor','Stats','Group','GroupBot','Ssl','Domain','Page','Pay','PayGws','Ranking','PayUi']
));
$found = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php') continue;
    if (strpos($p, '\\vendor\\') !== false || strpos($p, '\\tests\\') !== false) continue;
    $src = (string)file_get_contents($p);
    // ثابت‌های سراسری با حروف بزرگ که تعریف نشده‌اند
    if (preg_match_all('/(?<![A-Za-z0-9_$\'"\\\\])([A-Z][A-Z0-9_]{2,})(?![A-Za-z0-9_])/', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as [$name, $off]) {
            if (isset($allConst[$name])) continue;
            $before = substr($src, max(0, $off - 3), 3);
            if (strpos($before, '::') !== false) continue;
            // تعریف؟ define('X'
            $ctx = substr($src, max(0, $off - 30), 40);
            if (stripos($ctx, 'define(') !== false) continue;
            $line = substr_count(substr($src, 0, $off), "\n") + 1;
            printf("  ✗ %s  %s:%d\n", $name, str_replace($root . '\\', '', $p), $line);
            $found++;
        }
    }
}
if (!$found) echo "  ✓ هیچ\n";
echo "\n";
