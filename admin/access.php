<?php
/**
 * ============================================================================
 *  Odsco — دسترسی کاربران
 * ----------------------------------------------------------------------------
 *  یک صفحه برای اینکه مدیر مشخص کند «چه کسی به کدام بخش وصل شود»:
 *    • پیام‌رسان        (messenger_enabled)
 *    • حضور و غیاب      (attendance_enabled)
 *    • فعال بودن حساب   (is_active)
 *    • نقش کاربر
 *
 *  دسترسی: فقط مدیر (admin)
 * ============================================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/logger.php';

check_login();

$me    = current_admin();
$myUid = (string)($me['uid'] ?? '');

if (!has_permission('admin')) {
    http_response_code(403);
    exit('⛔ فقط مدیر سایت به این بخش دسترسی دارد.');
}

$msg = '';
$err = '';

// ---------------------------------------------------------------------------
// ذخیره تغییرات
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();

    $action = (string)($_POST['action'] ?? 'save');
    $rows   = (array)($_POST['rows'] ?? []);       // uid => 1  (یعنی این سطر در فرم بود)
    $flags  = (array)($_POST['u'] ?? []);         // uid => [msg, att, active, role]

    if (!$rows) {
        $err = 'هیچ کاربری برای ذخیره انتخاب نشده بود.';
    } else {
        // شمارش مدیرهای فعال، تا آخرین مدیر از دست نرود
        $adminsActive = 0;
        foreach (Users::list(['role' => 'admin', 'active' => true]) as $a) { $adminsActive++; }

        $changed = 0;
        $skipped = [];

        foreach (array_keys($rows) as $uid) {
            $uid = (string)$uid;
            $u = Users::find($uid);
            if (!$u) continue;

            $f      = (array)($flags[$uid] ?? []);
            $newMsg    = isset($f['msg']) ? 1 : 0;
            $newAtt    = isset($f['att']) ? 1 : 0;
            $newActive = isset($f['active']) ? 1 : 0;
            $newRole   = isset($f['role']) && array_key_exists((string)$f['role'], Users::HIERARCHY)
                ? (string)$f['role'] : (string)$u['role'];

            // محافظت ۱: nobody can lock himself out
            if ($uid === $myUid) {
                if ($newActive === 0 || $newRole !== 'admin') {
                    $skipped[] = (string)$u['full_name'] . ' (خودتان — برای جلوگیری از قفل‌شدن پنل)';
                    $newActive = 1;
                    $newRole   = 'admin';
                }
            }

            // محافظت ۲: آخرین مدیر فعال نباید از دست برود
            $wasAdmin = ((string)$u['role'] === 'admin' && !empty($u['is_active']));
            $willBeAdmin = ($newRole === 'admin' && $newActive === 1);
            if ($wasAdmin && !$willBeAdmin) {
                if ($adminsActive <= 1) {
                    $skipped[] = (string)$u['full_name'] . ' (آخرین مدیر فعال است)';
                    continue;
                }
                $adminsActive--;
            } elseif (!$wasAdmin && $willBeAdmin) {
                $adminsActive++;
            }

            $diff = [];
            if ((int)($u['messenger_enabled'] ?? 0) !== $newMsg)       $diff[] = 'پیام‌رسان ' . ($newMsg ? '✅' : '⛔');
            if ((int)($u['attendance_enabled'] ?? 0) !== $newAtt)      $diff[] = 'حضور و غیاب ' . ($newAtt ? '✅' : '⛔');
            if ((int)($u['is_active'] ?? 0) !== $newActive)            $diff[] = 'حساب ' . ($newActive ? 'فعال' : 'غیرفعال');
            if ((string)$u['role'] !== $newRole)                       $diff[] = 'نقش → ' . $newRole;
            if (!$diff) continue;

            Users::update($uid, [
                'messenger_enabled'  => $newMsg,
                'attendance_enabled' => $newAtt,
                'is_active'          => $newActive,
                'role'               => $newRole,
            ]);
            $changed++;
            ActivityLog::add('user_access', 'دسترسی «' . $u['full_name'] . '» تغییر کرد: ' . implode('، ', $diff), (string)$u['username']);
        }

        if ($changed > 0) {
            $msg = '✅ دسترسی ' . fa_number($changed) . ' کاربر به‌روزرسانی شد.';
        } else {
            $msg = 'تغییری لازم نبود.';
        }
        if ($skipped) {
            $msg .= ' — بدون تغییر: ' . implode('، ', $skipped);
        }
    }
}

// ---------------------------------------------------------------------------
// داده‌های صفحه
// ---------------------------------------------------------------------------
$q       = trim((string)($_GET['q'] ?? ''));
$filter  = (string)($_GET['filter'] ?? '');

$users = Users::list(['internal_only' => true]);

if ($q !== '') {
    $needle = mb_strtolower($q);
    $users = array_values(array_filter($users, function (array $u) use ($needle): bool {
        return str_contains(mb_strtolower((string)$u['full_name']), $needle)
            || str_contains(mb_strtolower((string)$u['username']), $needle)
            || str_contains(mb_strtolower((string)($u['job_title'] ?? '')), $needle);
    }));
}

switch ($filter) {
    case 'no_msg': $users = array_values(array_filter($users, fn($u) => empty($u['messenger_enabled']))); break;
    case 'no_att': $users = array_values(array_filter($users, fn($u) => empty($u['attendance_enabled']))); break;
    case 'inactive': $users = array_values(array_filter($users, fn($u) => empty($u['is_active']))); break;
    case 'msg': $users = array_values(array_filter($users, fn($u) => !empty($u['messenger_enabled']))); break;
    case 'att': $users = array_values(array_filter($users, fn($u) => !empty($u['attendance_enabled']))); break;
}

$today = date('Y-m-d');
$stats = [
    'total' => count(Users::list(['internal_only' => true])),
    'msg'   => count(array_filter(Users::list(['internal_only' => true]), fn($u) => !empty($u['messenger_enabled']))),
    'att'   => count(array_filter(Users::list(['internal_only' => true]), fn($u) => !empty($u['attendance_enabled']))),
    'off'   => count(array_filter(Users::list(['internal_only' => true]), fn($u) => empty($u['is_active']))),
];

$roleLabels = [
    'admin'          => 'مدیر کل',
    'manager'        => 'مدیر',
    'editor'         => 'ویرایشگر',
    'employee'       => 'کارمند',
    'project_writer' => 'نویسنده پروژه',
    'article_writer' => 'نویسنده مقاله',
    'viewer'         => 'بیننده',
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>دسترسی کاربران | پنل مدیریت</title>
<link rel="stylesheet" href="admin-style.css">
<style>
.acc-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:18px 0}
.acc-stat{background:#fff;border:1px solid var(--admin-border);border-radius:14px;padding:14px 16px}
.acc-stat b{display:block;font-size:24px;font-weight:900}
.acc-stat span{font-size:12px;color:var(--admin-muted)}
.acc-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:14px 0}
.acc-bar input[type=text]{padding:10px 12px;border:1px solid var(--admin-border);border-radius:10px;font-family:inherit;min-width:200px}
.acc-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border-radius:14px;overflow:hidden}
.acc-table th,.acc-table td{padding:11px 10px;border-bottom:1px solid var(--admin-border);text-align:center}
.acc-table th{background:#f7f8fa;font-size:12px;color:var(--admin-muted);white-space:nowrap}
.acc-table td.name{text-align:right}
.acc-table tr:hover{background:#fafbfc}
.acc-table .who{font-weight:700}
.acc-table .sub{font-size:11px;color:var(--admin-muted)}
.acc-table select{padding:6px 8px;border:1px solid var(--admin-border);border-radius:8px;font-family:inherit;font-size:12px}
.sw{position:relative;display:inline-block;width:44px;height:24px}
.sw input{opacity:0;width:0;height:0;position:absolute}
.sw i{position:absolute;inset:0;background:#d7dbe0;border-radius:24px;transition:.2s;cursor:pointer}
.sw i:before{content:'';position:absolute;width:18px;height:18px;border-radius:50%;background:#fff;top:3px;right:3px;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.25)}
.sw input:checked + i{background:var(--admin-success)}
.sw input:checked + i:before{transform:translateX(-20px)}
.sw input:disabled + i{opacity:.5;cursor:not-allowed}
.chip{display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;background:#eef1f4;color:#5a6673}
.chip.on{background:rgba(46,213,115,.16);color:#157f45}
.chip.off{background:rgba(255,71,87,.13);color:#b3212f}
@media (max-width:900px){
    .acc-table thead{display:none}
    .acc-table tr{display:block;border:1px solid var(--admin-border);border-radius:12px;margin-bottom:10px;padding:6px}
    .acc-table td{display:flex;justify-content:space-between;align-items:center;border:0;padding:7px 4px}
    .acc-table td:before{content:attr(data-l);font-size:11px;color:var(--admin-muted)}
}
</style>
</head>
<body>
<div class="admin-layout">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="content-card">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1>🔑 دسترسی کاربران</h1>
                    <p>مشخص کنید هر کاربر به کدام بخش وصل شود: پیام‌رسان، حضور و غیاب، و اینکه حسابش فعال باشد.</p>
                </div>
            </div>

            <?php if ($msg): ?><div class="alert alert-success"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?php echo e($err); ?></div><?php endif; ?>

            <div class="acc-stats">
                <div class="acc-stat"><b><?php echo fa_number($stats['total']); ?></b><span>کل کاربران داخلی</span></div>
                <div class="acc-stat"><b><?php echo fa_number($stats['msg']); ?></b><span>دسترسی پیام‌رسان</span></div>
                <div class="acc-stat"><b><?php echo fa_number($stats['att']); ?></b><span>دسترسی حضور و غیاب</span></div>
                <div class="acc-stat"><b><?php echo fa_number($stats['off']); ?></b><span>حساب غیرفعال</span></div>
            </div>

            <form method="get" class="acc-bar">
                <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="🔍 جستجوی نام یا نام کاربری">
                <select name="filter" onchange="this.form.submit()">
                    <option value="">همه</option>
                    <option value="msg"     <?php echo $filter === 'msg' ? 'selected' : ''; ?>>فقط دارای پیام‌رسان</option>
                    <option value="no_msg"  <?php echo $filter === 'no_msg' ? 'selected' : ''; ?>>بدون پیام‌رسان</option>
                    <option value="att"     <?php echo $filter === 'att' ? 'selected' : ''; ?>>فقط دارای حضور و غیاب</option>
                    <option value="no_att"  <?php echo $filter === 'no_att' ? 'selected' : ''; ?>>بدون حضور و غیاب</option>
                    <option value="inactive"<?php echo $filter === 'inactive' ? 'selected' : ''; ?>>حساب‌های غیرفعال</option>
                </select>
                <button type="submit" class="btn btn-primary">اعمال فیلتر</button>
                <?php if ($q !== '' || $filter !== ''): ?>
                    <a class="btn" href="access.php">پاک کردن</a>
                <?php endif; ?>
            </form>

            <form method="post" id="accForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save">

                <div class="acc-bar">
                    <button type="button" class="btn" onclick="accSet('msg', true)">💬 همه → پیام‌رسان روشن</button>
                    <button type="button" class="btn" onclick="accSet('msg', false)">💬 همه → پیام‌رسان خاموش</button>
                    <button type="button" class="btn" onclick="accSet('att', true)">🕐 همه → حضور و غیاب روشن</button>
                    <button type="submit" class="btn btn-primary">💾 ذخیره دسترسی‌ها</button>
                </div>

                <table class="acc-table">
                    <thead>
                        <tr>
                            <th style="text-align:right">کاربر</th>
                            <th>نقش</th>
                            <th>حساب فعال</th>
                            <th>💬 پیام‌رسان</th>
                            <th>🕐 حضور و غیاب</th>
                            <th>امروز</th>
                            <th>آخرین ورود</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$users): ?>
                        <tr><td colspan="7" style="padding:26px;color:var(--admin-muted)">کاربری با این فیلتر پیدا نشد.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($users as $u):
                        $uid    = (string)$u['uid'];
                        $isSelf = $uid === $myUid;
                        $rec    = Db::ready() ? Attendance::record($uid, $today) : null;
                    ?>
                        <tr>
                            <td class="name" data-l="کاربر">
                                <div class="who"><?php echo e((string)$u['full_name']); ?><?php if ($isSelf): ?> <span class="chip">(شما)</span><?php endif; ?></div>
                                <div class="sub"><?php echo e((string)$u['username']); ?><?php echo ($u['job_title'] ?? '') !== '' ? ' — ' . e((string)$u['job_title']) : ''; ?></div>
                                <input type="hidden" name="rows[<?php echo e($uid); ?>]" value="1">
                            </td>
                            <td data-l="نقش">
                                <select name="u[<?php echo e($uid); ?>][role]" <?php echo $isSelf ? 'disabled' : ''; ?>>
                                    <?php foreach ($roleLabels as $rk => $rl): ?>
                                        <option value="<?php echo e($rk); ?>" <?php echo (string)$u['role'] === $rk ? 'selected' : ''; ?>><?php echo e($rl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($isSelf): ?><input type="hidden" name="u[<?php echo e($uid); ?>][role]" value="admin"><?php endif; ?>
                            </td>
                            <td data-l="حساب فعال">
                                <label class="sw">
                                    <input type="checkbox" name="u[<?php echo e($uid); ?>][active]" value="1"
                                           <?php echo !empty($u['is_active']) ? 'checked' : ''; ?> <?php echo $isSelf ? 'disabled' : ''; ?>>
                                    <i></i>
                                </label>
                                <?php if ($isSelf): ?><input type="hidden" name="u[<?php echo e($uid); ?>][active]" value="1"><?php endif; ?>
                            </td>
                            <td data-l="پیام‌رسان">
                                <label class="sw">
                                    <input type="checkbox" class="sw-msg" name="u[<?php echo e($uid); ?>][msg]" value="1"
                                           <?php echo !empty($u['messenger_enabled']) ? 'checked' : ''; ?>>
                                    <i></i>
                                </label>
                            </td>
                            <td data-l="حضور و غیاب">
                                <label class="sw">
                                    <input type="checkbox" class="sw-att" name="u[<?php echo e($uid); ?>][att]" value="1"
                                           <?php echo !empty($u['attendance_enabled']) ? 'checked' : ''; ?>>
                                    <i></i>
                                </label>
                            </td>
                            <td data-l="امروز">
                                <?php if (!$rec): ?>
                                    <span class="chip">—</span>
                                <?php else: ?>
                                    <span class="chip <?php echo in_array($rec['status'], ['present', 'late'], true) ? 'on' : 'off'; ?>">
                                        <?php echo e(Attendance::statusIcon($rec['status']) . ' ' . Attendance::statusLabel($rec['status'])); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td data-l="آخرین ورود"><span class="sub"><?php echo e($u['last_login'] ? jalali_datetime((string)$u['last_login']) : 'هرگز'); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="acc-bar">
                    <button type="submit" class="btn btn-primary">💾 ذخیره دسترسی‌ها</button>
                    <span class="sub">کلیدهای غیرفعال یعنی «خاموش» — چک‌نکردن یک گزینه، آن دسترسی را می‌بندد.</span>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function accSet(kind, on) {
    document.querySelectorAll('#accForm .sw-' + kind).forEach(function (cb) {
        if (!cb.disabled) cb.checked = on;
    });
}
document.getElementById('accForm').addEventListener('submit', function (ev) {
    if (!confirm('دسترسی کاربران ذخیره شود؟')) ev.preventDefault();
});
</script>
</body>
</html>
