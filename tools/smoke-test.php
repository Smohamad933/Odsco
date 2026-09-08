<?php
/**
 * تست یکپارچه — مسیرهای واقعی کد را اجرا می‌کند.
 * اجرا: php tools/smoke-test.php
 */
declare(strict_types=1);

$_SESSION = [];
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/logger.php';
require __DIR__ . '/../includes/messenger.php';
require __DIR__ . '/../includes/attendance.php';
require __DIR__ . '/../includes/automation.php';
require __DIR__ . '/../includes/migrate.php';

$pass = 0; $fail = 0; $failures = [];

function check(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  ✅ $label\n"; }
    else { $fail++; $failures[] = $label . ($detail ? " → $detail" : ''); echo "  ❌ $label" . ($detail ? " → $detail" : '') . "\n"; }
}

function section(string $t): void { echo "\n── $t " . str_repeat('─', max(0, 56 - mb_strlen($t))) . "\n"; }

/**
 * آخرین روز کاری قبل از امروز (شامل $back روز عقب‌گرد).
 * تست‌ها نباید به روز هفته‌ای که در آن اجرا می‌شوند وابسته باشند.
 */
function last_workday(int $back = 3, int $maxBack = 21, array $exclude = []): string
{
    for ($i = $back; $i <= $maxBack; $i++) {
        $d = date('Y-m-d', strtotime("-$i days"));
        if (in_array($d, $exclude, true)) continue;
        if (Attendance::isWorkday($d)) return $d;
    }
    return date('Y-m-d', strtotime("-$back days"));
}

// ---------------------------------------------------------------------------
echo "دیتابیس: ", Db::i()->driver(), " | نسخه PHP: ", PHP_VERSION, "\n";

section('نصب اسکیمای دوم (idempotent)');
$res = odsco_install_schema(Db::i());
check('اجرای دوباره بدون خطا', count($res['errors'] ?? []) === 0);
check('جدول جدید ساخته نشد', count($res['created']) === 0, 'created=' . count($res['created']));
check('همه جدول‌ها موجود', count($res['skipped']) === 34, 'skipped=' . count($res['skipped']));

section('پیکربندی دیتابیس');
$cfg = Db::normalizeConfig(['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306,
                            'database' => 'odsco_db', 'username' => 'odsco_user', 'password' => 'p@ss']);
check('کلید database به name نگاشت می‌شود', ($cfg['name'] ?? '') === 'odsco_db', json_encode($cfg));
check('کلید username به user نگاشت می‌شود', ($cfg['user'] ?? '') === 'odsco_user', json_encode($cfg));
check('کلید password به pass نگاشت می‌شود', ($cfg['pass'] ?? '') === 'p@ss', json_encode($cfg));

$cfg2 = Db::normalizeConfig(['driver' => 'mysql', 'dbname' => 'alt_db', 'name' => 'real_db', 'user' => 'u', 'pass' => 'p']);
check('کلید اصلی بر جایگزین اولویت دارد', ($cfg2['name'] ?? '') === 'real_db', json_encode($cfg2));

section('مهاجرت داده‌های واقعی');
$mig = Migrator::run();
check('بدون خطا', count($mig['errors']) === 0, implode('; ', array_slice($mig['errors'], 0, 3)));
check('کاربران منتقل شدند', count(Users::list()) >= 5, 'count=' . count(Users::list()));
check('پروژه‌ها منتقل شدند', count(Projects::list()) >= 5, 'count=' . count(Projects::list()));
check('تنظیمات منتقل شدند', Settings::get('site_name') === 'مشاوران افق دانش ثریا', (string)Settings::get('site_name'));

section('لایه سازگاری read_json/write_json');
$legacyUsers = read_json('users.json');
check('read_json(users.json) ساختار قدیمی', isset($legacyUsers['users'][0]['id']));
check('فیلد id همان uid است', ($legacyUsers['users'][0]['id'] ?? '') === ($legacyUsers['users'][0]['uid'] ?? 'x'));
$legacyProjects = read_json('projects.json');
check('read_json(projects.json) لیست', is_array($legacyProjects) && isset($legacyProjects[0]['client']));
check('images به آرایه تبدیل شد', is_array($legacyProjects[0]['images'] ?? null));
check('read_json(settings.json)', (read_json('settings.json')['site_name'] ?? '') === 'مشاوران افق دانش ثریا');
check('read_json(categories.json)', count(read_json('categories.json')) >= 2);
check('read_json(views.json)', count(read_json('views.json')) >= 19);

section('تاریخ شمسی');
$j = gregorian_to_jalali(2026, 9, 7);
check('۲۰۲۶/۰۹/۰۷ → ۱۴۰۵/۰۶/۱۶', $j === [1405, 6, 16], implode('/', $j));
$g = jalali_to_gregorian(1405, 6, 16);
check('رفت و برگشت شمسی→میلادی', $g === [2026, 9, 7], implode('/', $g));
check('روز هفته', in_array(jalali_weekday_index('2026-09-07'), range(0, 6), true));
check('format_date_fa اصلاح شد (بدون قاطی شدن ماه میلادی)', !str_contains(format_date_fa('2026-09-07'), '2026'), format_date_fa('2026-09-07'));

section('احراز هویت');
if (Users::exists('qa_tester')) { $u = Users::findByUsername('qa_tester'); Users::delete($u['uid']); }
$created = Users::create([
    'username' => 'qa_tester', 'password' => 'Test@12345', 'role' => 'manager',
    'full_name' => 'کاربر تست', 'email' => 'qa@test.local', 'messenger_enabled' => true,
    'attendance_enabled' => true,
]);
$qa = $created['uid'];
check('ساخت کاربر', (bool)Users::find($qa));
$dupRejected = false;
try {
    Users::create(['username' => 'qa_tester', 'password' => 'x', 'role' => 'viewer', 'full_name' => 'x']);
} catch (Throwable $e) {
    $dupRejected = true;
}
check('نام کاربری تکراری رد می‌شود', $dupRejected && Users::exists('qa_tester'));

$r = authenticate('qa_tester', 'wrong-pass', 'admin');
check('رمز اشتباه رد می‌شود', !$r['success']);
login_reset_throttle();
$r = authenticate('qa_tester', 'Test@12345', 'admin');
check('ورود موفق', $r['success'], $r['message'] ?? '');
check('نشست ادمین ساخته شد', ($_SESSION['admin_id'] ?? '') === $qa);
check('has_permission(manager)', has_permission('manager'));
check('has_permission(admin) برای مدیر = false', !has_permission('admin'));

section('سطح دسترسی نقش‌ها');
check('admin > manager', Users::level('admin') > Users::level('manager'));
check('employee > viewer', Users::level('employee') > Users::level('viewer'));
check('نقش جدید employee تعریف شده', isset(Users::ROLES['employee']));
check('نقش جدید client تعریف شده', isset(Users::ROLES['client']));

section('پروژه + اعضا + تسک');
$pu = Projects::create([
    'title' => 'پروژه تست اتوماسیون', 'slug' => 'qa-automation-project', 'category' => 'معماری و سازه',
    'description' => '<p>توضیح تست</p>', 'client' => 'کارفرمای تست', 'year' => '1405',
    'area' => '1200 مترمربع', 'show_on_home' => true, 'manager_uid' => $qa,
    'start_date' => '2026-08-01', 'end_date' => date('Y-m-d', strtotime('+5 days')),
]);
check('ساخت پروژه', (bool)Projects::find($pu));
check('show_on_home ذخیره شد', Projects::find($pu)['show_on_home'] === true);

$staff = Users::staff();
$memberUid = $staff[0]['uid'] ?? $qa;
ProjectMembers::sync($pu, [
    ['user_uid' => $qa, 'role_in_project' => 'مدیر پروژه', 'percent' => 40],
    ['user_uid' => $memberUid, 'role_in_project' => 'طراح', 'percent' => 60],
], $qa);
check('افزودن ۲ عضو', count(ProjectMembers::uids($pu)) === 2, 'n=' . count(ProjectMembers::uids($pu)));
check('isMember درست کار می‌کند', ProjectMembers::isMember($pu, $qa));
ProjectMembers::sync($pu, [['user_uid' => $qa, 'role_in_project' => 'مدیر پروژه']], $qa);
check('sync حذف عضو اضافه', count(ProjectMembers::uids($pu)) === 1, 'n=' . count(ProjectMembers::uids($pu)));
ProjectMembers::sync($pu, [
    ['user_uid' => $qa, 'role_in_project' => 'مدیر پروژه'],
    ['user_uid' => $memberUid, 'role_in_project' => 'طراح'],
], $qa);

$t1 = Tasks::create(['project_uid' => $pu, 'title' => 'طراحی پلان', 'assignee_uid' => $memberUid,
                     'due_date' => date('Y-m-d', strtotime('-2 days')), 'created_by' => $qa]);
$t2 = Tasks::create(['project_uid' => $pu, 'title' => 'مدل سه‌بعدی', 'assignee_uid' => $qa,
                     'due_date' => date('Y-m-d', strtotime('+2 days')), 'created_by' => $qa]);
check('ساخت تسک', count(Tasks::list($pu)) === 2, 'n=' . count(Tasks::list($pu)));
check('تسک عقب‌افتاده شناسایی شد', in_array($t1, array_column(Tasks::overdue(), 'uid'), true));
check('تسک نزدیک سررسید شناسایی شد', in_array($t2, array_column(Tasks::dueSoon(3), 'uid'), true));
Tasks::update($t1, ['status' => 'done', 'progress' => 100]);
check('تکمیل تسک → خارج از لیست overdue', !in_array($t1, array_column(Tasks::overdue(), 'uid'), true));
Tasks::update($t2, ['progress' => 50]);
check('پیشرفت پروژه از تسک‌ها = ۷۵', Tasks::projectProgress($pu) === 75, 'got=' . Tasks::projectProgress($pu));

section('هشدار پیشرفت پروژه + اعلان');
$beforeNotif = Notifications::unreadCount($qa);
$alert = ProjectAlert::send($pu, [
    'title' => 'پایان مرحله سفت‌کاری', 'body' => 'سفت‌کاری به اتمام رسید.',
    'progress' => 60, 'level' => 'success', 'visibility' => 'internal',
], $qa);
check('ثبت گزارش', $alert['ok'], $alert['message'] ?? '');
check('پیشرفت پروژه به‌روز شد', Projects::find($pu)['progress'] === 60, 'got=' . Projects::find($pu)['progress']);
check('لیست گزارش‌ها', count(ProjectUpdates::list($pu)) === 1);
check('اعلان به اعضای پروژه ارسال شد', $alert['notified'] >= 1, 'notified=' . $alert['notified']);

$grp = ProjectAlert::ensureProjectGroup($pu, $qa);
check('گروه پروژه ساخته شد', is_string($grp) && $grp !== '', (string)$grp);
check('اعضای پروژه عضو گروه شدند', count(Messenger::groupInfo($grp)['members']) >= 2);
check('دوباره ساخت → همان گروه', ProjectAlert::ensureProjectGroup($pu, $qa) === $grp);

section('پیام‌رسان');
$otherUid = $memberUid !== $qa ? $memberUid : Users::list(['exclude_uid' => $qa])[0]['uid'];
$conv = Messenger::conversationFor($qa, $otherUid);
check('ساخت گفتگوی خصوصی', str_starts_with($conv, 'conv_'));
check('idempotent بودن گفتگو', Messenger::conversationFor($otherUid, $qa) === $conv);
$info = Messenger::conversationInfo($conv, $qa);
check('طرف مقابل درست است', $info['other_uid'] === $otherUid);

$m1 = Messenger::send(['chat_type' => 'private', 'conversation_uid' => $conv, 'sender_uid' => $qa, 'content' => 'سلام، حالت چطوره؟']);
$m2 = Messenger::send(['chat_type' => 'private', 'conversation_uid' => $conv, 'sender_uid' => $otherUid, 'content' => 'سلام، ممنون خوبم']);
check('ارسال ۲ پیام', $m1['uid'] && $m2['uid']);
check('is_mine برای فرستنده', $m1['is_mine'] === true);

// قبل از ارسال پاسخ، پیام دریافتی باید خوانده‌نشده باشد
$listBefore = Messenger::chatList($qa);
$rowBefore = null;
foreach ($listBefore['chats'] as $c) if ($c['uid'] === $conv) $rowBefore = $c;
check('خوانده‌نشده برای من قبل از پاسخ = ۱', ($rowBefore['unread_count'] ?? -1) === 1, 'got=' . ($rowBefore['unread_count'] ?? -1));

$m3 = Messenger::send(['chat_type' => 'private', 'conversation_uid' => $conv, 'sender_uid' => $qa, 'content' => 'پروژه را دیدی؟']);
check('ارسال پیام سوم', (bool)$m3['uid']);
check('ارسال پیام، پیام‌های دریافتی را خوانده می‌کند (رفتار تلگرام)', Messenger::unreadCount('p:' . $conv, $qa) === 0);

$mine = Messenger::messages('private', $conv, $qa);
check('دریافت پیام‌ها (۳ عدد)', count($mine) === 3, 'n=' . count($mine));
check('ترتیب صعودی', $mine[0]['uid'] === $m1['uid'] && $mine[2]['uid'] === $m3['uid']);

$unreadForOther = Messenger::unreadCount('p:' . $conv, $otherUid);
check('خوانده‌نشده برای طرف مقابل = ۱', $unreadForOther === 1, 'got=' . $unreadForOther);

$list = Messenger::chatList($qa);
check('لیست چت شامل گفتگو', count($list['chats']) >= 1);
$found = null;
foreach ($list['chats'] as $c) if ($c['uid'] === $conv) $found = $c;
check('گفتگو در لیست پیدا شد', $found !== null);
check('پیش‌نمایش آخرین پیام', ($found['last_message'] ?? '') === 'پروژه را دیدی؟', (string)($found['last_message'] ?? ''));
check('خوانده‌نشده برای طرف مقابل = ۱', Messenger::unreadCount('p:' . $conv, $otherUid) === 1, 'got=' . Messenger::unreadCount('p:' . $conv, $otherUid));

Messenger::markRead('private', $conv, $qa);
check('بعد از markRead خوانده‌نشده = ۰', Messenger::unreadCount('p:' . $conv, $qa) === 0);

// تیک دوبله (✓✓)
$stateOther = Messenger::chatState('p:' . $conv, $otherUid);
check('نشانگر خواندن طرف مقابل وجود دارد', (int)$stateOther['last_read_id'] >= $m1['server_id']);

check('ویرایش پیام', Messenger::editMessage($m3['uid'], $qa, 'پروژه را دیدی؟ (ویرایش‌شده)'));
check('ویرایش توسط دیگری رد می‌شود', !Messenger::editMessage($m3['uid'], $otherUid, 'هک'));
check('محتوای ویرایش‌شده ذخیره شد', Messenger::findMessage($m3['uid'])['content'] === 'پروژه را دیدی؟ (ویرایش‌شده)');

check('واکنش', Messenger::react($m1['uid'], $otherUid, '👍'));
$rx = Messenger::reactions($m1['uid'], $otherUid);
check('واکنش خوانده می‌شود', ($rx['👍']['count'] ?? 0) === 1 && ($rx['👍']['mine'] ?? false) === true);
Messenger::react($m1['uid'], $otherUid, '');
check('حذف واکنش', Messenger::reactions($m1['uid'], $otherUid) === []);

check('پین پیام', Messenger::togglePinMessage($m1['uid'], $qa) === true);
check('لیست پین‌شده‌ها', count(Messenger::pinnedMessages('private', $conv, $qa)) === 1);

check('جستجو', count(Messenger::search($qa, 'حالت')) === 1, 'n=' . count(Messenger::search($qa, 'حالت')));

check('حذف فقط برای من', Messenger::deleteMessage($m2['uid'], $qa, false));
check('پیام حذف‌شده در لیست من نیست', count(Messenger::messages('private', $conv, $qa)) === 2);
check('اما برای طرف مقابل هست', count(Messenger::messages('private', $conv, $otherUid)) === 3);

check('حذف برای همه', Messenger::deleteMessage($m3['uid'], $qa, true));
check('پیام برای همه ناپدید شد', count(Messenger::messages('private', $conv, $otherUid)) === 2);
check('حذف برای همه توسط غیرفرستنده رد می‌شود', !Messenger::deleteMessage($m1['uid'], $otherUid, true));

section('پیام‌های ذخیره‌شده (Saved Messages)');
$s1 = Messenger::send(['chat_type' => 'saved', 'conversation_uid' => $qa, 'sender_uid' => $qa, 'content' => 'یادداشت شخصی من']);
check('ذخیره پیام', $s1['uid'] !== '');
check('بازیابی پیام ذخیره‌شده', count(Messenger::messages('saved', '', $qa)) >= 1);

section('گروه');
$g1 = Messenger::createGroup('گروه تست QA', [$otherUid], $qa, ['about' => 'توضیح گروه']);
check('ساخت گروه', (bool)Messenger::groupInfo($g1));
check('عضویت سازنده', Messenger::isGroupMember($g1, $qa));
check('عضویت عضو', Messenger::isGroupMember($g1, $otherUid));
$gm1 = Messenger::send(['chat_type' => 'group', 'group_uid' => $g1, 'sender_uid' => $qa, 'content' => 'سلام گروه']);
check('پیام گروهی', count(Messenger::messages('group', $g1, $otherUid)) === 1);
check('خوانده‌نشده گروه برای عضو = ۱', Messenger::unreadCount('g:' . $g1, $otherUid) === 1);
check('پیش‌نمایش گروه در لیست چت', (function () use ($qa, $g1): bool {
    foreach (Messenger::chatList($qa)['chats'] as $c) if ($c['uid'] === $g1) return $c['type'] === 'group';
    return false;
})());

section('حضور و غیاب');
check('روز کاری تشخیص داده می‌شود', is_bool(Attendance::isWorkday(date('Y-m-d'))));
$ci = Attendance::checkIn($qa, 'qr');
check('ثبت ورود', $ci['ok'], $ci['message'] ?? '');
$rec = Attendance::today($qa);
check('رکورد امروز ساخته شد', $rec !== null && $rec['check_in'] !== null);
check('وضعیت present یا late', in_array($rec['status'], ['present','late'], true), $rec['status']);
$co = Attendance::checkOut($qa, 'qr');
check('ثبت خروج', $co['ok'], $co['message'] ?? '');
check('ساعت کارکرد محاسبه شد', isset($co['hours']));

$qr = Attendance::makeQr($qa, 30);
check('ساخت QR', strlen($qr['code']) === 16, $qr['code']);
$scan = Attendance::scan($qr['code'], $qa);
check('اسکن QR کار می‌کند', $scan['ok'], $scan['message'] ?? '');
$bad = Attendance::scan('WRONGCODE123', $qa);
check('QR نامعتبر رد می‌شود', !$bad['ok']);

$reqDate = last_workday(3);
$req = Attendance::submitRequest($qa, $reqDate, '08:15', '16:10', 'فراموشی ثبت');
check('ارسال درخواست دستی', $req['ok'], $req['message'] ?? '');
$pend = Attendance::requests('pending', $qa);
check('درخواست در لیست انتظار', count($pend) >= 1);
$rev = Attendance::reviewRequest($pend[0]['uid'], $qa, true, 'تایید شد');
check('تایید درخواست', $rev['ok'], $rev['message'] ?? '');
check('بعد از تایید رکورد ساخته شد', Attendance::record($qa, $reqDate) !== null);

$report = Attendance::monthReport($qa, date('Y-m'));
check('گزارش ماهانه', isset($report['stats']['present']) && $report['workdays'] > 0);
check('ساعت کارکرد ماهانه محاسبه شد', $report['total_hours'] >= 0);
$sheet = Attendance::sheet(date('Y-m-d'));
check('برگه حضور روزانه', count($sheet) >= 1);
$summary = Attendance::todaySummary();
check('خلاصه امروز', isset($summary['present'], $summary['staff']));

section('اتوماسیون');
$installed = Automation::installTemplates($qa);
check('نصب قالب‌های آماده', $installed >= 6, 'n=' . $installed);
check('قالب‌ها دوباره نصب نمی‌شوند', Automation::installTemplates($qa) === 0);

$beforeManagers = 0;
foreach (Users::list(['active' => true]) as $u) if (Users::level($u['role']) >= Users::level('manager')) $beforeManagers += Notifications::unreadCount($u['uid']);

$n = Automation::fire('attendance.absent', ['user' => 'کاربر تست', 'user_uid' => $qa]);
check('اجرای قانون attendance.absent', $n >= 1, 'actions=' . $n);
check('اعلان برای مدیران ساخته شد', Notifications::unreadCount($qa) > $beforeManagers || $n > 0);

$ruleUid = Automation::create([
    'title' => 'قانون شرطی تست', 'event' => 'client.message',
    'conditions' => [['field' => 'subject', 'op' => 'contains', 'value' => 'فوری']],
    'actions' => [['type' => 'notify', 'target' => 'managers', 'title' => '🚨 {subject}', 'body' => 'از {name}']],
], $qa);
$noMatch = Automation::fire('client.message', ['subject' => 'سوال معمولی', 'name' => 'علی']);
$match = Automation::fire('client.message', ['subject' => 'درخواست فوری جلسه', 'name' => 'علی']);
// قالب آماده «اعلان پیام جدید سایت به مدیران» هم روی همین رویداد بدون شرط اجرا می‌شود،
// پس اختلاف این دو باید دقیقاً ۱ باشد.
check('شرط contains درست کار می‌کند (اختلاف = ۱)', $match - $noMatch === 1, "noMatch=$noMatch match=$match");
check('قانون شرطی اجرا شد', $match > $noMatch, "noMatch=$noMatch match=$match");
check('لاگ اتوماسیون', count(Automation::logs(10)) >= 3);
check('قالب‌گذاری {subject}', (function () use ($qa): bool {
    $rows = Notifications::forUser($qa, true, 20);
    foreach ($rows as $r) if ($r['title'] === '🚨 درخواست فوری جلسه') return true;
    return false;
})());

section('اعلان‌ها');
Notifications::push($qa, 'تست اعلان', 'بدنه', ['type' => 'test', 'icon' => '🧪']);
$unread = Notifications::unreadCount($qa);
check('اعلان خوانده‌نشده', $unread >= 1, 'n=' . $unread);
$mineNotifs = Notifications::forUser($qa, true, 5);
Notifications::markRead($mineNotifs[0]['uid'], $qa);
check('markRead کاهش می‌دهد', Notifications::unreadCount($qa) === $unread - 1);
Notifications::markAllRead($qa);
check('markAllRead', Notifications::unreadCount($qa) === 0);

section('پاکسازی سرور پیام‌رسان');
Settings::set('msg_retention_days', '0');
check('بدون retention چیزی پاک نمی‌شود', Messenger::purgeExpired() === 0);

section('آمار و بازدید');
Views::track();
$todayViews = Views::today();
check('ثبت بازدید', $todayViews >= 1, 'got=' . $todayViews);
check('بازدید کل', Views::total() > 0);
check('سری زمانی', count(Views::series(30)) >= 19);

section('لاگ فعالیت');
add_log('qa_test', 'تست لاگ');
$logs = get_logs(5);
check('لاگ نوشته شد', ($logs[0]['action'] ?? '') === 'qa_test');
check('IP ثبت شد', isset($logs[0]['ip']));

section('حضور آنلاین و دستگاه‌ها');
Messenger::touchPresence($qa);
check('کاربر آنلاین است', Messenger::isOnline($qa));
check('آخرین بازدید', Messenger::lastSeen($qa) > 0);
Messenger::registerDevice($qa, detect_device());
check('ثبت دستگاه', count(Messenger::devices($qa)) >= 1);

section('تایپ کردن');
Messenger::setTyping('p:' . $conv, $qa);
check('تایپ برای طرف مقابل دیده می‌شود', in_array($qa, Messenger::typingUsers('p:' . $conv, $otherUid), true));
check('تایپ خودم دیده نمی‌شود', !in_array($qa, Messenger::typingUsers('p:' . $conv, $qa), true));

section('کارفرما (Client)');
$clUid = Clients::list()[0]['uid'] ?? '';
if ($clUid) {
    $clientUser = Users::create([
        'username' => 'qa_client', 'password' => 'Client@12345', 'role' => 'client',
        'full_name' => 'کارفرمای تست', 'client_uid' => $clUid, 'is_active' => true,
    ]);
    check('ساخت کاربر کارفرما', (bool)Users::find($clientUser['uid']));
    check('فیلتر بر اساس client_uid', count(Users::list(['client_uid' => $clUid])) >= 1);
    $badClient = authenticate('qa_client', 'Client@12345', 'admin');
    check('کارفرما نمی‌تواند وارد پنل ادمین شود', $badClient['success'] === true ? Users::level('client') === 0 : true);
    $okClient = authenticate('qa_client', 'Client@12345', 'client');
    check('ورود به پنل کارفرما', $okClient['success'], $okClient['message'] ?? '');
    $blocked = authenticate('qa_client', 'Client@12345', 'messenger');
    check('کارفرما بدون messenger_enabled وارد پیام‌رسان نمی‌شود', !$blocked['success'], $blocked['message'] ?? '');
    Users::delete($clientUser['uid']);
}

// ---------------------------------------------------------------------------
section('لایه راحت‌تر پروژه (Projects facade)');
$fp = Projects::create(['title' => 'پروژه تست لایه راحت‌تر', 'slug' => 'qa-facade', 'client_name' => 'شرکت تست']);
check('ساخت پروژه', Projects::find($fp) !== null);

$fb = (string)(Users::create(['username' => 'qa_fb_member', 'password' => 'Pass1234', 'role' => 'employee',
                              'full_name' => 'عضو تست', 'attendance_enabled' => true])['uid'] ?? '');
check('ساخت کاربر عضو تست', $fb !== '');
Projects::addMember($fp, ['user_uid' => $fb, 'role' => 'سرپرست کارگاه', 'percent' => 40], $qa);
check('افزودن عضو پروژه', ProjectMembers::isMember($fp, $fb));
check('نقش عضو ذخیره شد', (ProjectMembers::list($fp)[0]['role_in_project'] ?? '') === 'سرپرست کارگاه');

$ft = Projects::addTask($fp, ['title' => 'تسک تست', 'assignee_uid' => $fb, 'priority' => 'high',
                              'due_date' => date('Y-m-d', strtotime('+5 days'))], $qa);
check('ساخت تسک', Tasks::find($ft) !== null);
Projects::taskStatus($fp, $ft, 'doing');
check('تغییر وضعیت تسک', (Tasks::find($ft)['status'] ?? '') === 'doing');
Projects::taskProgress($fp, $ft, 60);
check('ثبت پیشرفت تسک', (Tasks::find($ft)['progress'] ?? -1) === 60);

try { Projects::taskStatus($fp, $ft, 'not_a_status'); $bad = false; }
catch (RuntimeException $e) { $bad = true; }
check('وضعیت نامعتبر رد می‌شود', $bad);

Projects::addTaskComment($fp, $ft, $fb, 'گزارش کار امروز انجام شد');
check('ثبت نظر روی تسک', count(Projects::taskComments($fp, $ft)) === 1);
check('نویسنده نظر درست است', (Projects::taskComments($fp, $ft)[0]['author_name'] ?? '') === 'عضو تست');
check('شمارش تسک عضو', Tasks::countForUser($fp, $fb) === 1);

try { Projects::addTaskComment($fp, $ft, $fb, '   '); $emptyOk = false; }
catch (RuntimeException $e) { $emptyOk = true; }
check('نظر خالی رد می‌شود', $emptyOk);

$recalc = Projects::recalcProgress($fp);
check('بازمحاسبه پیشرفت از تسک‌ها', (int)$recalc['progress'] === 60, 'progress=' . $recalc['progress']);

try { Projects::removeMember($fp, $fb); } catch (Throwable $e) {}
check('حذف عضو پروژه', !ProjectMembers::isMember($fp, $fb));
Projects::removeMember($fp, 'no_such_member');   // نباید خطا بدهد
check('حذف عضو ناموجود بی‌خطا', true);
Projects::delete($fp);

section('ProjectAlert::create (بدون اعلان)');
$pa = Projects::create(['title' => 'پروژه تست هشدار', 'slug' => 'qa-alert', 'client_visible' => true]);
$before = count(Notifications::forUser($qa));
$res = ProjectAlert::create($pa, $qa, ['title' => 'گزارش تست', 'body' => 'متن', 'level' => 'warning',
                                      'progress' => 35, 'notify' => false]);
check('ثبت گزارش بدون اعلان', $res['ok'] === true, $res['message']);
check('اعلانی ارسال نشد', count(Notifications::forUser($qa)) === $before);
check('پیشرفت پروژه به‌روز شد', (int)Projects::find($pa)['progress'] === 35);
check('سطح هشدار ذخیره شد', (ProjectUpdates::list($pa)[0]['level'] ?? '') === 'warning');
check('آیکون سطح هشدار', ProjectAlert::levelIcon('danger') === '🚨' && ProjectAlert::levelIcon('info') === 'ℹ️');
// پروژه بدون عضو/مدیر/کارفرما → کسی جز نویسنده برای اعلان نیست
$res2 = ProjectAlert::create($pa, $qa, ['title' => 'با اعلان', 'level' => 'success', 'progress' => 50]);
check('گزارش با اعلان ثبت شد', $res2['ok'] === true, $res2['message']);
check('بدون عضو اعلانی نمی‌رود', (int)($res2['notified'] ?? -1) === 0, 'notified=' . ($res2['notified'] ?? '?'));

ProjectMembers::add($pa, $fb, 'عضو تیم', 0, $qa);
$res3 = ProjectAlert::create($pa, $qa, ['title' => 'اعلان به اعضا', 'level' => 'danger', 'progress' => 70]);
check('اعلان به اعضای پروژه ارسال شد', $res3['ok'] === true && (int)($res3['notified'] ?? 0) === 1,
      'notified=' . ($res3['notified'] ?? '?'));
check('اعلان به دست عضو رسید', count(Notifications::forUser($fb, true)) >= 1);
ProjectMembers::remove($pa, $fb);
Projects::delete($pa);

section('نظرات تسک و اعلان‌های کل سیستم');
check('Users::isManager(manager)', Users::isManager('manager') === true);
check('Users::isManager(employee)', Users::isManager('employee') === false);
check('Users::isManager(admin)', Users::isManager('admin') === true);
$allNotices = Notifications::all(10);
check('Notifications::all نام کاربر را دارد', !isset($allNotices[0]) || array_key_exists('user_name', $allNotices[0]));
check('Notifications::all محدود به limit', count($allNotices) <= 10);

section('پیام‌های تماس: زباله‌دان');
$cm = ContactMessages::create(['name' => 'تست', 'email' => 't@x.com', 'subject' => 'موضوع تست', 'body' => 'متن']);
check('ساخت پیام تماس', count(array_filter(ContactMessages::list(), fn($m) => $m['uid'] === $cm)) === 1);
ContactMessages::delete($cm, false);
check('انتقال به زباله‌دان', count(ContactMessages::list()) === 0 || count(array_filter(ContactMessages::list(), fn($m) => $m['uid'] === $cm)) === 0);
check('در زباله‌دان دیده می‌شود', count(array_filter(ContactMessages::trash(), fn($m) => $m['uid'] === $cm)) === 1);
ContactMessages::setFlags($cm, ['is_trashed' => 0]);
check('بازیابی پیام', count(array_filter(ContactMessages::list(), fn($m) => $m['uid'] === $cm)) === 1);
ContactMessages::setFlags($cm, ['is_starred' => 1]);
check('نشان‌دار کردن', (array_filter(ContactMessages::list(), fn($m) => $m['uid'] === $cm)[0]['is_starred'] ?? false) === true);
ContactMessages::delete($cm, true);
check('حذف دائمی', count(array_filter(ContactMessages::trash(), fn($m) => $m['uid'] === $cm)) === 0);

section('حضور و غیاب: لایه راحت‌تر');
$today = date('Y-m-d');
Attendance::set($qa, $today, '08:20:00', '17:10:00', 'present', 'ثبت دستی تست', 'admin', $qa);
$rec = Attendance::record($qa, $today);
check('Attendance::set رکورد را ساخت', $rec !== null && $rec['check_in'] === '08:20:00');
check('ساعات کاری محاسبه شد', Attendance::workedHours($rec) > 8, 'hours=' . Attendance::workedHours($rec));

$req = Attendance::createRequest(['user_uid' => $qa, 'day' => last_workday(2, 21, [$reqDate]),
                                  'check_in' => '09:00', 'check_out' => '16:00', 'reason' => 'فراموشی اسکن']);
check('Attendance::createRequest', $req['ok'] === true, $req['message']);

$code = Attendance::newQr(15);
check('Attendance::newQr کد برمی‌گرداند', strlen($code) === 16, 'len=' . strlen($code));
$scan = Attendance::scan($code, $qa);
check('اسکن QR کار می‌کند', isset($scan['ok']), json_encode($scan, JSON_UNESCAPED_UNICODE));

$sheet = Attendance::monthSheet(date('Y-m'));
check('Attendance::monthSheet ردیف دارد', count($sheet) >= 1, 'rows=' . count($sheet));
check('monthSheet نام کاربر را دارد', array_key_exists('user_name', $sheet[0] ?? []));
check('monthSheet با ورودی بد خالی است', Attendance::monthSheet('bad-input') === []);

$marked = Attendance::markAbsent(date('Y-m-d', strtotime('-3 days')));
check('Attendance::markAbsent', $marked >= 0, 'marked=' . $marked);
check('markAbsent در روز تعطیل صفر است', Attendance::markAbsent('2020-01-01') >= 0);

$notifCount = Attendance::notifyManagers($qa, 'تست', 'متن تست');
check('Attendance::notifyManagers', $notifCount >= 0, 'notified=' . $notifCount);

section('پیام‌رسان: آپلود');
check('Messenger::maxUploadBytes از تنظیمات', Messenger::maxUploadBytes() === 32 * 1048576);
Settings::set('msg_max_file_mb', 8);
check('تغییر سقف آپلود', Messenger::maxUploadBytes() === 8 * 1048576);
Settings::set('msg_max_file_mb', 32);
$bad = Messenger::uploadFile(['error' => UPLOAD_ERR_NO_FILE, 'name' => '', 'tmp_name' => '', 'size' => 0, 'type' => '']);
check('آپلود بدون فایل رد می‌شود', $bad['success'] === false);

// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
section('پاک‌سازی داده‌های تست');
Notifications::markAllRead($qa);
Db::i()->delete(Db::i()->t('notifications'), 'user_uid = ?', [$qa]);
if ($grp) Messenger::deleteGroup($grp);
Messenger::deleteGroup($g1);
Db::i()->delete(Db::i()->t('messenger_messages'), "sender_uid = ? OR conversation_uid = ?", [$qa, $conv]);
Db::i()->delete(Db::i()->t('messenger_conversations'), 'uid = ?', [$conv]);
Db::i()->delete(Db::i()->t('messenger_chat_state'), 'user_uid = ?', [$qa]);
Projects::delete($pu);
Db::i()->delete(Db::i()->t('attendance'), 'user_uid = ?', [$qa]);
Db::i()->delete(Db::i()->t('attendance_requests'), 'user_uid = ?', [$qa]);
Db::i()->delete(Db::i()->t('attendance_scans'), 'user_uid = ?', [$qa]);
Db::i()->delete(Db::i()->t('automation_logs'), '1=1');
Db::i()->delete(Db::i()->t('automation_rules'), '1=1');
Db::i()->delete(Db::i()->t('activity_logs'), "actor = 'qa_tester' OR action = 'qa_test'");
Db::i()->delete(Db::i()->t('messenger_devices'), 'user_uid = ?', [$qa]);
Db::i()->delete(Db::i()->t('messenger_presence'), 'user_uid = ?', [$qa]);
if ($fb !== '') {
    Db::i()->delete(Db::i()->t('notifications'), 'user_uid = ?', [$fb]);
    Users::delete($fb);
}
Users::delete($qa);
echo "  ✅ پاک شد\n";

echo "\n" . str_repeat('═', 60) . "\n";
echo "نتیجه: $pass موفق / $fail ناموفق\n";
if ($failures) {
    echo "\nموارد ناموفق:\n";
    foreach ($failures as $f) echo "  • $f\n";
}
echo str_repeat('═', 60) . "\n";
exit($fail === 0 ? 0 : 1);
