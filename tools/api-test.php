<?php
/**
 * ============================================================================
 *  Odsco — تست API پیام‌رسان
 * ----------------------------------------------------------------------------
 *  هر اکشن messenger/api.php را واقعاً صدا می‌زند (با نشست و CSRF واقعی)
 *  و پاسخ JSON را بررسی می‌کند.
 *
 *  اجرا:  php tools/api-test.php
 * ============================================================================
 */

declare(strict_types=1);

$_SESSION = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['SCRIPT_NAME']    = '/messenger/api.php';

require_once __DIR__ . '/../includes/config.php';

if (!Db::ready()) {
    fwrite(STDERR, "❌ دیتابیس تست آماده نیست\n");
    exit(1);
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/messenger.php';
require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/automation.php';
require_once __DIR__ . '/../messenger/config.php';

$pass = 0; $fail = 0; $failures = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  ✅ $label\n"; }
    else { $fail++; $failures[] = $label . ($detail ? " → $detail" : ''); echo "  ❌ $label" . ($detail ? " → $detail" : '') . "\n"; }
}

function section(string $t): void { echo "\n── $t " . str_repeat('─', max(0, 56 - mb_strlen($t))) . "\n"; }

/**
 * یک اکشن API را در زیرفرآیند صدا بزن (چون api.php با json_out → exit تمام می‌کند).
 *
 * @return array{status: int, json: array<string,mixed>, raw: string}
 */
/**
 * مسیر باینری PHP برای زیرفرآیند.
 * برخی بیلدها (مثل php-wasm) یک shim کوچک shell به‌جای باینری واقعی در
 * PHP_BINARY می‌گذارند که آرگومان‌ها را منتقل نمی‌کند؛ آن را تشخیص می‌دهیم.
 */
function php_runner(): string
{
    $env = getenv('ODSCO_PHP');
    if (is_string($env) && $env !== '' && is_executable($env)) return $env;

    $bin = (string)(PHP_BINARY ?: '');
    if ($bin !== '' && is_file($bin) && (int)@filesize($bin) > 4096) return $bin;

    // shim کوچک است → خودِ CLI را پیدا کن
    foreach ([$bin, dirname($bin) . '/php-wasm-cli', $_SERVER['HOME'] . '/tools/node_modules/.bin/php-wasm-cli'] as $cand) {
        if ($cand !== '' && is_file($cand) && is_executable($cand)) {
            $head = (string)@file_get_contents($cand, false, null, 0, 400);
            if (str_contains($head, 'php-wasm-cli')) return $cand;
        }
    }
    return 'php';
}

function db_config(): ?array
{
    $f = __DIR__ . '/../includes/config.local.php';
    if (!is_file($f)) return null;
    $cfg = require $f;
    return is_array($cfg) ? $cfg : null;
}

function api(string $action, array $post = [], array $get = [], string $method = 'POST'): array
{
    static $runner = null;
    $runner ??= php_runner();

    $payload = json_encode([
        'action' => $action, 'post' => $post, 'get' => $get, 'method' => $method,
        'session' => $_SESSION,
    ], JSON_UNESCAPED_UNICODE);

    // زیرفرآیند یک اتصال جدا به همان دیتابیس باز می‌کند. در SQLite دو فرآیند
    // هم‌زمان روی یک فایل قفل می‌خورند، پس اتصال خودمان را موقتاً می‌بندیم.
    $cfg = db_config();
    if ($cfg !== null) { Db::reset(); gc_collect_cycles(); }

    $cmd = escapeshellarg($runner) . ' ' . escapeshellarg(__DIR__ . '/api-call.php');
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        if ($cfg !== null) Db::boot($cfg);
        return ['status' => 0, 'json' => [], 'raw' => 'proc_open failed'];
    }

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);

    if ($cfg !== null) Db::boot($cfg);

    // آخرین خط JSON پاسخ واقعی است
    $lines = array_values(array_filter(explode("\n", trim($out)), fn($l) => $l !== ''));
    $raw = $lines ? end($lines) : '';
    $json = json_decode($raw, true);

    if (!is_array($json)) {
        $detail = trim($out . ($err !== '' ? "\n[stderr] " . $err : ''));
        return ['status' => 500, 'json' => ['success' => false, 'message' => 'پاسخ JSON نبود'],
                'raw' => mb_substr($detail !== '' ? $detail : '(خروجی خالی از زیرفرآیند)', 0, 400)];
    }
    $status = (int)($json['__status'] ?? 200);
    unset($json['__status']);
    return ['status' => $status, 'json' => $json, 'raw' => $raw];
}

// ---------------------------------------------------------------------------
echo "تست API پیام‌رسان — دیتابیس: ", Db::i()->driver(), "\n";

// دو کاربر تست
$ua = Users::create(['username' => 'api_a_' . bin2hex(random_bytes(3)), 'password' => 'Pass1234',
                     'role' => 'employee', 'full_name' => 'کاربر الف API', 'messenger_enabled' => true]);
$ub = Users::create(['username' => 'api_b_' . bin2hex(random_bytes(3)), 'password' => 'Pass1234',
                     'role' => 'employee', 'full_name' => 'کاربر ب API', 'messenger_enabled' => true]);
$aUid = (string)$ua['uid'];
$bUid = (string)$ub['uid'];
Settings::set('messenger_enabled', 1);

// نشست کاربر الف.
// توکن CSRF ثابت است چون هر فراخوانی در زیرفرآیند با همین نشست اجرا می‌شود.
$_SESSION['messenger_user_id'] = $aUid;
$_SESSION['csrf_token']        = 'odsco_api_test_token';
$token = (string)$_SESSION['csrf_token'];

$conv = Messenger::conversationFor($aUid, $bUid);

section('احراز هویت و CSRF');
$r = api('ping', [], [], 'GET');
check('ping بدون CSRF کار می‌کند', ($r['json']['success'] ?? false) === true, $r['raw']);
check('ping آمار برمی‌گرداند', isset($r['json']['online'], $r['json']['unread']));

$r = api('chats', ['csrf_token' => 'WRONG'], [], 'POST');
check('CSRF اشتباه رد می‌شود', ($r['json']['success'] ?? true) === false, $r['raw']);

section('لیست‌ها');
$r = api('chats', ['csrf_token' => $token]);
check('chats موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
check('chats کلید chats دارد', isset($r['json']['chats']));

$r = api('contacts', ['csrf_token' => $token]);
check('contacts موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
check('contacts لیست برمی‌گرداند', isset($r['json']['contacts']) && is_array($r['json']['contacts']));

section('ارسال و دریافت پیام');
$r = api('send', ['csrf_token' => $token, 'chat_type' => 'private', 'chat_uid' => $conv, 'content' => 'سلام از تست API']);
check('send موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
$msgUid = (string)($r['json']['message']['uid'] ?? '');
check('uid پیام برگشت', $msgUid !== '');
check('is_mine درست است', ($r['json']['message']['is_mine'] ?? false) === true);

$r = api('send', ['csrf_token' => $token, 'chat_type' => 'private', 'chat_uid' => $conv, 'content' => '   ']);
check('پیام خالی رد می‌شود', ($r['json']['success'] ?? true) === false, $r['raw']);

$r = api('send', ['csrf_token' => $token, 'chat_type' => 'private', 'chat_uid' => 'conv_nonsense', 'content' => 'x']);
check('گفتگوی ناموجود رد می‌شود', ($r['json']['success'] ?? true) === false, $r['raw']);

$r = api('messages', ['csrf_token' => $token], ['chat_type' => 'private', 'chat_uid' => $conv], 'GET');
check('messages موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
check('پیام ارسالی در لیست است', in_array($msgUid, array_column($r['json']['messages'] ?? [], 'uid'), true));
check('chat_key برگشت', ($r['json']['chat_key'] ?? '') === 'p:' . $conv);

$r = api('messages', ['csrf_token' => $token], ['chat_type' => 'group', 'chat_uid' => 'grp_nonsense'], 'GET');
check('گروه غیرعضو رد می‌شود', ($r['json']['success'] ?? true) === false, $r['raw']);

$r = api('messages', ['csrf_token' => $token], ['chat_type' => 'bad_type', 'chat_uid' => $conv], 'GET');
check('نوع چت نامعتبر رد می‌شود', ($r['json']['success'] ?? true) === false, $r['raw']);

section('ویرایش / واکنش / پین / حذف');
$r = api('edit', ['csrf_token' => $token, 'uid' => $msgUid, 'content' => 'ویرایش‌شده']);
check('edit پیام خودم موفق', ($r['json']['success'] ?? false) === true, $r['raw']);

$r = api('react', ['csrf_token' => $token, 'uid' => $msgUid, 'emoji' => '❤️']);
check('react موفق', ($r['json']['success'] ?? false) === true, $r['raw']);

$r = api('pin_message', ['csrf_token' => $token, 'uid' => $msgUid]);
check('pin_message موفق', ($r['json']['success'] ?? false) === true, $r['raw']);

$r = api('mark_read', ['chat_type' => 'private', 'chat_uid' => $conv]);
check('mark_read موفق', ($r['json']['success'] ?? false) === true, $r['raw']);

$r = api('typing', ['chat_key' => 'p:' . $conv]);
check('typing موفق', ($r['json']['success'] ?? false) === true, $r['raw']);

$r = api('chat_state', ['csrf_token' => $token, 'chat_key' => 'p:' . $conv, 'draft' => 'پیش‌نویس']);
check('chat_state موفق', ($r['json']['success'] ?? false) === true, $r['raw']);

section('گروه‌ها');
$r = api('group_create', ['csrf_token' => $token, 'name' => 'گروه تست API', 'members' => [$bUid]]);
check('group_create موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
$g   = $r['json']['group'] ?? null;
$grp = (string)(is_array($g) ? ($g['uid'] ?? '') : ($g ?? $r['json']['uid'] ?? ''));
check('uid گروه برگشت', $grp !== '', json_encode($r['json'], JSON_UNESCAPED_UNICODE));

if ($grp !== '') {
    $r = api('group_info', ['csrf_token' => $token], ['group' => $grp], 'GET');
    check('group_info موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
    check('group_info نام گروه را دارد', ($r['json']['group']['name'] ?? '') === 'گروه تست API', $r['raw']);

    $r = api('send', ['csrf_token' => $token, 'chat_type' => 'group', 'chat_uid' => $grp, 'content' => 'سلام گروه']);
    check('ارسال به گروه موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
    $grpMsg = (string)($r['json']['message']['uid'] ?? '');

    $r = api('messages', ['csrf_token' => $token], ['chat_type' => 'group', 'chat_uid' => $grp], 'GET');
    check('پیام‌های گروه خوانده شد', ($r['json']['success'] ?? false) === true, $r['raw']);

    $r = api('group_update', ['csrf_token' => $token, 'group' => $grp, 'name' => 'گروه تغییرنام‌یافته']);
    check('group_update موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
    check('نام گروه عوض شد', ($r['json']['group']['name'] ?? '') === 'گروه تغییرنام‌یافته', $r['raw']);

    $r = api('group_role', ['csrf_token' => $token, 'group' => $grp, 'user_uid' => $bUid, 'role' => 'admin']);
    check('group_role موفق', ($r['json']['success'] ?? false) === true, $r['raw']);

    $r = api('group_members_add', ['csrf_token' => $token, 'group' => $grp, 'members' => [$bUid]]);
    check('group_members_add موفق', ($r['json']['success'] ?? false) === true, $r['raw']);

    $r = api('group_member_remove', ['csrf_token' => $token, 'group' => $grp, 'user_uid' => $bUid]);
    check('group_member_remove موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
    $stillMember = false;
    foreach (($r['json']['group']['members'] ?? []) as $m) {
        if (($m['user_uid'] ?? $m['uid'] ?? '') === $bUid) $stillMember = true;
    }
    check('عضو حذف‌شده دیگر در لیست نیست', !$stillMember, $r['raw']);

    $r = api('group_leave', ['csrf_token' => $token, 'group' => $grp]);
    check('group_leave موفق', ($r['json']['success'] ?? false) === true, $r['raw']);

    $r = api('send', ['csrf_token' => $token, 'chat_type' => 'group', 'chat_uid' => $grp, 'content' => 'x']);
    check('بعد از خروج نمی‌توان فرستاد', ($r['json']['success'] ?? true) === false, $r['raw']);

    $r = api('group_delete', ['csrf_token' => $token, 'group' => $grp]);
    check('group_delete موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
    check('گروه واقعاً حذف شد', Messenger::groupInfo($grp) === null, 'groupInfo still returns data');
    $grp = '';
}

section('گفتگوی جدید و حذف پیام');
$r = api('conversation', ['csrf_token' => $token, 'user_uid' => $bUid]);
check('conversation موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
check('conversation همان گفتگوی موجود است', ($r['json']['conversation'] ?? '') === $conv, $r['raw']);

$r = api('delete', ['csrf_token' => $token, 'uid' => $msgUid]);
check('delete پیام خودم موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
check('delete برای خودم بود', ($r['json']['for_everyone'] ?? true) === false, $r['raw']);

$r = api('send', ['csrf_token' => $token, 'chat_type' => 'private', 'chat_uid' => $conv, 'content' => 'پیام موقتی']);
$tmpUid = (string)($r['json']['message']['uid'] ?? '');
$r = api('delete', ['csrf_token' => $token, 'uid' => $tmpUid, 'for_everyone' => '1']);
check('حذف برای همه موفق', ($r['json']['success'] ?? false) === true && ($r['json']['for_everyone'] ?? false) === true, $r['raw']);

section('جستجو و تاریخچه');
$r = api('search', ['csrf_token' => $token], ['q' => 'ویرایش'], 'GET');
check('search موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
check('search کلید results دارد', isset($r['json']['results']), $r['raw']);

$r = api('clear_history', ['csrf_token' => $token, 'chat_key' => 'p:' . $conv]);
check('clear_history موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
check('clear_history تعداد برمی‌گرداند', isset($r['json']['cleared']), $r['raw']);

section('اعلان‌ها و دستگاه‌ها');
$r = api('notices', ['csrf_token' => $token], [], 'GET');
check('notices موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
check('notices کلیدهای unread و items', isset($r['json']['unread'], $r['json']['items']), $r['raw']);

$r = api('notice_read', ['csrf_token' => $token, 'uid' => 'all']);
check('notice_read موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
check('بعد از خواندن همه، unread صفر است', (int)($r['json']['unread'] ?? -1) === 0, $r['raw']);

Messenger::registerDevice($aUid, ['name' => 'دستگاه تست', 'platform' => 'linux', 'browser' => 'cli']);
$r = api('devices', ['csrf_token' => $token], [], 'GET');
check('devices موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
$devUid = (string)($r['json']['devices'][0]['uid'] ?? '');
check('دستگاه ثبت‌شده دیده می‌شود', $devUid !== '', $r['raw']);

$r = api('device_revoke', ['csrf_token' => $token, 'device_uid' => $devUid]);
check('device_revoke موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
check('دستگاه حذف شد', !in_array($devUid, array_column($r['json']['devices'] ?? [], 'uid'), true), $r['raw']);

section('پروفایل و حریم خصوصی');
$r = api('profile', ['csrf_token' => $token], [], 'GET');
check('profile موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
check('profile اطلاعات خودم را می‌دهد', ($r['json']['me']['uid'] ?? '') === $aUid, $r['raw']);

$r = api('profile_update', ['csrf_token' => $token, 'bio' => 'بیوگرافی تست API']);
check('profile_update موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
check('بیو ذخیره شد', (Users::find($aUid)['bio'] ?? '') === 'بیوگرافی تست API');

$r = api('privacy', ['csrf_token' => $token]);
check('privacy موفق', ($r['json']['success'] ?? false) === true, $r['raw']);
check('privacy سقف آپلود را برمی‌گرداند', isset($r['json']['max_upload_mb']), $r['raw']);

$r = api('password', ['csrf_token' => $token, 'old_password' => 'WRONG', 'new_password' => 'NewPass1234']);
check('رمز اشتباه رد می‌شود', ($r['json']['success'] ?? true) === false, $r['raw']);

$r = api('password', ['csrf_token' => $token, 'old_password' => 'Pass1234', 'new_password' => 'short']);
check('رمز کوتاه رد می‌شود', ($r['json']['success'] ?? true) === false, $r['raw']);

section('اکشن نامعتبر');
$r = api('no_such_action', ['csrf_token' => $token]);
check('اکشن نامعتبر رد می‌شود', ($r['json']['success'] ?? true) === false, $r['raw']);

// ---------------------------------------------------------------------------
section('پاک‌سازی');
if ($grp !== '') Messenger::deleteGroup($grp);
Db::i()->delete(Db::i()->t('messenger_group_members'), 'user_uid IN (?, ?)', [$aUid, $bUid]);
Db::i()->delete(Db::i()->t('messenger_reactions'), 'user_uid IN (?, ?)', [$aUid, $bUid]);
Db::i()->delete(Db::i()->t('messenger_messages'), 'sender_uid IN (?, ?)', [$aUid, $bUid]);
Db::i()->delete(Db::i()->t('messenger_conversations'), 'uid = ?', [$conv]);
Db::i()->delete(Db::i()->t('messenger_chat_state'), 'user_uid IN (?, ?)', [$aUid, $bUid]);
Db::i()->delete(Db::i()->t('notifications'), 'user_uid IN (?, ?)', [$aUid, $bUid]);
Db::i()->delete(Db::i()->t('messenger_presence'), 'user_uid IN (?, ?)', [$aUid, $bUid]);
Db::i()->delete(Db::i()->t('messenger_devices'), 'user_uid IN (?, ?)', [$aUid, $bUid]);
Users::delete($aUid);
Users::delete($bUid);
echo "  ✅ پاک شد\n";

echo "\n" . str_repeat('═', 60) . "\n";
echo "نتیجه API: $pass موفق / $fail ناموفق\n";
if ($failures) { echo "\nموارد ناموفق:\n"; foreach ($failures as $f) echo "  • $f\n"; }
echo str_repeat('═', 60) . "\n";
exit($fail === 0 ? 0 : 1);
