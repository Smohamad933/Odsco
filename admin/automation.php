<?php
/**
 * ============================================================================
 *  Odsco — سیستم خودکارسازی (قواعد، هشدارهای خودکار، ارسال خودکار)
 * ----------------------------------------------------------------------------
 *  مدیر عامل می‌تواند قواعدی بسازد که به‌صورت خودکار هشدار/اعلان ارسال کنند.
 * ============================================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/logger.php';
require_once dirname(__DIR__) . '/includes/automation.php';

check_login();

$user = current_admin();
$msg = '';
$err = '';
$tab = (string)($_GET['tab'] ?? 'rules');

// ---------------------------------------------------------------------------
// عملیات‌ها
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)($_POST['act'] ?? '');
    try {
        if ($act === 'save') {
            check_permission('manager');
            $conditions = [];
            foreach ((array)($_POST['c_field'] ?? []) as $i => $field) {
                if ((string)$field === '') continue;
                $conditions[] = [
                    'field' => (string)$field,
                    'op'    => (string)($_POST['c_op'][$i] ?? '=='),
                    'value' => (string)($_POST['c_value'][$i] ?? ''),
                ];
            }

            $payload = [
                'name'        => (string)($_POST['name'] ?? ''),
                'event'       => (string)($_POST['event'] ?? 'daily'),
                'enabled'     => isset($_POST['enabled']) ? 1 : 0,
                'priority'    => (int)($_POST['priority'] ?? 50),
                'match_all'   => isset($_POST['match_all']) ? 1 : 0,
                'conditions'  => $conditions,
                'actions'     => [
                    'notify'       => isset($_POST['a_notify']),
                    'notify_title' => (string)($_POST['a_notify_title'] ?? ''),
                    'notify_body'  => (string)($_POST['a_notify_body'] ?? ''),
                    'notify_level' => (string)($_POST['a_notify_level'] ?? 'warning'),
                    'targets'      => (string)($_POST['a_targets'] ?? 'project_members'),
                    'project_uid'  => (string)($_POST['a_project'] ?? ''),
                    'visibility'   => (string)($_POST['a_visibility'] ?? 'both'),
                    'log'          => isset($_POST['a_log']),
                ],
            ];

            $uid = (string)($_POST['rule_uid'] ?? '');
            if ($uid !== '') {
                Automation::update($uid, $payload);
                $msg = '✅ قاعده به‌روزرسانی شد';
            } else {
                $uid = Automation::create($payload);
                $msg = '✅ قاعده ساخته شد';
            }

        } elseif ($act === 'toggle') {
            check_permission('manager');
            $r = Automation::find((string)($_POST['rule_uid'] ?? ''));
            if ($r) {
                Automation::update((string)$r['uid'], ['enabled' => $r['enabled'] ? 0 : 1]);
                $msg = $r['enabled'] ? '⏸ قاعده غیرفعال شد' : '▶️ قاعده فعال شد';
            }

        } elseif ($act === 'delete') {
            check_permission('admin');
            Automation::delete((string)($_POST['rule_uid'] ?? ''));
            $msg = '🗑 قاعده حذف شد';

        } elseif ($act === 'test') {
            check_permission('manager');
            $hits = Automation::fire((string)($_POST['event'] ?? 'daily'));
            $msg = '🧪 قاعده اجرا شد — ' . fa_number($hits) . ' قاعده شرایط را داشت';
            $tab = 'test';

        } elseif ($act === 'install') {
            check_permission('admin');
            $n = Automation::installTemplates();
            $msg = '✅ ' . fa_number($n) . ' قاعده پیش‌فرض نصب شد';

        } else {
            throw new RuntimeException('عملیات نامعتبر');
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$rules = Automation::rules();
$logs  = Automation::logs(40);
$editing = null;
if (isset($_GET['edit'])) {
    $editing = Automation::find((string)$_GET['edit']);
}

$projects = Projects::list();
$events = [
    'daily'              => 'روزانه (کرون روزانه)',
    'project_overdue'    => 'پروژه از مهلت گذشته',
    'project_progress'   => 'ثبت گزارش پیشرفت پروژه',
    'task_overdue'       => 'وظیفه از مهلت گذشته',
    'task_created'       => 'وظیفه جدید ساخته شد',
    'attendance_late'    => 'کارمند تاخیر داشت',
    'attendance_absent'  => 'کارمند غایب بود',
    'message_received'   => 'پیام جدید در پیام‌رسان',
    'client_message'     => 'پیام از فرم تماس سایت',
    'user_created'       => 'کاربر جدید ساخته شد',
];
$fields = [
    'progress'       => 'پیشرفت پروژه (٪)',
    'days_to_deadline' => 'روز مانده به مهلت',
    'days_overdue'   => 'روزهای تاخیر',
    'task_progress'  => 'پیشرفت وظیفه (٪)',
    'task_priority'  => 'اولویت وظیفه',
    'status'         => 'وضعیت',
    'role'           => 'نقش کاربر',
    'count'          => 'تعداد',
];
$ops = ['==' => 'مساوی', '!=' => 'نامساوی', '>' => 'بزرگ‌تر از', '<' => 'کوچک‌تر از', '>=' => 'بزرگ‌تر یا مساوی', '<=' => 'کوچک‌تر یا مساوی', 'contains' => 'شامل', 'empty' => 'خالی است'];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>خودکارسازی | پنل مدیریت</title>
<link rel="stylesheet" href="admin-style.css">
</head>
<body>
<div class="admin-layout">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="content-card">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1>🤖 خودکارسازی</h1>
                    <p>قواعدی بسازید که هشدارها و اعلان‌ها را به‌صورت خودکار برای تیم و کارفرمایان ارسال کنند.</p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <a href="?edit=new" class="btn">+ قاعده جدید</a>
                    <form method="post" style="display:inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="act" value="install">
                        <button type="submit" class="btn-secondary">📦 نصب قواعد پیش‌فرض</button>
                    </form>
                </div>
            </div>

            <?php if ($msg): ?><div class="alert alert-success"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?php echo e($err); ?></div><?php endif; ?>

            <div class="tabs-bar">
                <a href="?tab=rules" class="<?php echo $tab === 'rules' ? 'active' : ''; ?>">قواعد (<?php echo fa_number(count($rules)); ?>)</a>
                <a href="?tab=logs" class="<?php echo $tab === 'logs' ? 'active' : ''; ?>">گزارش اجرا</a>
                <a href="?tab=cron" class="<?php echo $tab === 'cron' ? 'active' : ''; ?>">راه‌اندازی کرون</a>
            </div>

            <?php if ($tab === 'rules'): ?>
                <?php if (!$rules): ?>
                    <p style="color:#7c8aa0">قاعده‌ای تعریف نشده است. برای شروع «نصب قواعد پیش‌فرض» را بزنید.</p>
                <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>نام قاعده</th><th>رویداد</th><th>شرایط</th><th>اقدام</th><th>اجرا</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                        <tbody>
                        <?php foreach ($rules as $r): ?>
                            <tr>
                                <td><strong><?php echo e($r['name']); ?></strong></td>
                                <td><span class="pill info"><?php echo e($events[$r['event']] ?? $r['event']); ?></span></td>
                                <td style="font-size:11.5px;max-width:220px">
                                    <?php if (!$r['conditions']): ?>همیشه<?php else: ?>
                                        <?php foreach ($r['conditions'] as $c): ?>
                                            <span class="pill mute"><?php echo e(($fields[$c['field']] ?? $c['field']) . ' ' . ($ops[$c['op']] ?? $c['op']) . ' ' . $c['value']); ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:11.5px">
                                    <?php $a = $r['actions']; ?>
                                    <?php if (!empty($a['notify'])): ?>📣 <?php echo e($a['notify_title'] ?: 'اعلان'); ?><?php endif; ?>
                                    <?php if (!empty($a['log'])): ?> 📋 لاگ<?php endif; ?>
                                </td>
                                <td><?php echo fa_number((int)$r['runs']); ?></td>
                                <td>
                                    <span class="pill <?php echo $r['enabled'] ? 'ok' : 'mute'; ?>"><?php echo $r['enabled'] ? 'فعال' : 'غیرفعال'; ?></span>
                                </td>
                                <td>
                                    <div style="display:flex;gap:5px;flex-wrap:wrap">
                                        <a href="?edit=<?php echo e($r['uid']); ?>" class="btn-sm">✏️</a>
                                        <form method="post" style="display:inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="act" value="toggle">
                                            <input type="hidden" name="rule_uid" value="<?php echo e($r['uid']); ?>">
                                            <button type="submit" class="btn-sm" title="تغییر وضعیت"><?php echo $r['enabled'] ? '⏸' : '▶️'; ?></button>
                                        </form>
                                        <form method="post" style="display:inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="act" value="test">
                                            <input type="hidden" name="event" value="<?php echo e($r['event']); ?>">
                                            <button type="submit" class="btn-sm" title="اجرای آزمایشی">🧪</button>
                                        </form>
                                        <form method="post" style="display:inline" onsubmit="return confirm('این قاعده حذف شود؟')">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="act" value="delete">
                                            <input type="hidden" name="rule_uid" value="<?php echo e($r['uid']); ?>">
                                            <button type="submit" class="btn-sm" style="background:#eb3349">🗑</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

            <?php elseif ($tab === 'logs'): ?>
                <h3 style="margin-bottom:14px">📋 گزارش اجرای قواعد</h3>
                <?php if (!$logs): ?><p style="color:#7c8aa0">هنوز قاعده‌ای اجرا نشده است.</p><?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>زمان</th><th>رویداد</th><th>قاعده</th><th>هدف</th><th>عنوان</th><th>نتیجه</th></tr></thead>
                        <tbody>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td><?php echo e(jalali_datetime((string)$l['created_at'])); ?></td>
                                <td><span class="pill info"><?php echo e($events[$l['event']] ?? $l['event']); ?></span></td>
                                <td><?php echo e($l['rule_name'] ?: '—'); ?></td>
                                <td><?php echo e($l['target_uid'] ?: '—'); ?></td>
                                <td><?php echo e($l['title']); ?></td>
                                <td>
                                    <?php if ($l['ok']): ?>
                                        <span class="pill ok"><?php echo e(json_decode((string)$l['result'], true)['count'] ?? '۱'); ?> گیرنده</span>
                                    <?php else: ?>
                                        <span class="pill danger">خطا</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <h3 style="margin-bottom:14px">⏱ راه‌اندازی کرون</h3>
                <p style="font-size:13px;line-height:2;color:#5a6b80">
                    برای اجرای خودکار قواعد روزانه (هشدار پروژه‌های عقب‌افتاده، خلاصه حضور و غیاب، پاک‌سازی پیام‌ها)،
                    یکی از دو روش زیر را روی هاست فعال کنید:
                </p>

                <div style="background:#f7f9fc;border:1px solid #e6ebf2;border-radius:14px;padding:16px;margin:16px 0">
                    <h4 style="margin-bottom:10px">۱) کرون خط فرمان (cPanel → Cron Jobs)</h4>
                    <pre style="direction:ltr;text-align:left;background:#1b2430;color:#d7e3f4;padding:12px;border-radius:10px;overflow:auto;font-size:12.5px">0 7 * * * php <?php echo e(BASE_PATH); ?>/tools/cron.php --key=<?php echo e((string)Settings::get('cron_key', 'CHANGE_ME')); ?></pre>
                </div>

                <div style="background:#f7f9fc;border:1px solid #e6ebf2;border-radius:14px;padding:16px;margin-bottom:16px">
                    <h4 style="margin-bottom:10px">۲) فراخوانی با URL (اگر کرون خط فرمان ندارید)</h4>
                    <pre style="direction:ltr;text-align:left;background:#1b2430;color:#d7e3f4;padding:12px;border-radius:10px;overflow:auto;font-size:12px;word-break:break-all">https://yoursite.com/tools/cron.php?key=<?php echo e((string)Settings::get('cron_key', 'CHANGE_ME')); ?></pre>
                    <p style="font-size:12px;color:#7c8aa0;margin-top:10px">
                        🔐 کلید را در <a href="settings.php">تنظیمات</a> عوض کنید (کلید فعلی: <code><?php echo e((string)Settings::get('cron_key', '')); ?></code>)
                    </p>
                    <form method="post" action="settings.php" style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="cron_key" value="<?php echo e(bin2hex(random_bytes(16))); ?>">
                        <button type="submit" class="btn-secondary">🔑 ساخت کلید جدید</button>
                    </form>
                </div>

                <form method="post">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="act" value="test">
                    <input type="hidden" name="event" value="daily">
                    <button type="submit" class="btn">🧪 اجرای دستی رویداد روزانه</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($tab === 'rules' && (isset($_GET['edit']))):
            $e = $editing ?? [];
            $conds = $e['conditions'] ?? [['field' => '', 'op' => '==', 'value' => '']];
            $acts  = $e['actions'] ?? [];
        ?>
        <div class="content-card" id="ruleForm">
            <h2 style="margin-bottom:18px"><?php echo $editing ? '✏️ ویرایش قاعده' : '➕ قاعده جدید'; ?></h2>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="act" value="save">
                <input type="hidden" name="rule_uid" value="<?php echo e($e['uid'] ?? ''); ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label>نام قاعده *</label>
                        <input type="text" name="name" required value="<?php echo e($e['name'] ?? ''); ?>" placeholder="مثلاً هشدار پروژه عقب‌افتاده">
                    </div>
                    <div class="form-group">
                        <label>رویداد *</label>
                        <select name="event" required>
                            <?php foreach ($events as $k => $label): ?>
                                <option value="<?php echo e($k); ?>" <?php echo ($e['event'] ?? 'daily') === $k ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>اولویت</label>
                        <input type="number" name="priority" value="<?php echo fa_number((int)($e['priority'] ?? 50)); ?>" min="0" max="100">
                    </div>
                    <div class="form-group">
                        <label class="switch" style="margin-top:26px">
                            <input type="checkbox" name="match_all" value="1" <?php echo !empty($e['match_all']) ? 'checked' : ''; ?>>
                            <span class="track"></span><span>همه شرایط باید برقرار باشند (وگرنه «یا»)</span>
                        </label>
                    </div>
                    <div class="form-group full-width">
                        <label class="switch">
                            <input type="checkbox" name="enabled" value="1" <?php echo !isset($e['enabled']) || $e['enabled'] ? 'checked' : ''; ?>>
                            <span class="track"></span><span>قاعده فعال باشد</span>
                        </label>
                    </div>
                </div>

                <h3 style="margin:22px 0 12px">🎯 شرایط</h3>
                <div id="condList">
                    <?php foreach ($conds as $c): ?>
                    <div class="cond-row" style="display:grid;grid-template-columns:1fr 130px 1fr auto;gap:8px;margin-bottom:8px;align-items:center">
                        <select name="c_field[]">
                            <option value="">— بدون شرط —</option>
                            <?php foreach ($fields as $fk => $fl): ?>
                                <option value="<?php echo e($fk); ?>" <?php echo ($c['field'] ?? '') === $fk ? 'selected' : ''; ?>><?php echo e($fl); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="c_op[]">
                            <?php foreach ($ops as $ok => $ol): ?>
                                <option value="<?php echo e($ok); ?>" <?php echo ($c['op'] ?? '==') === $ok ? 'selected' : ''; ?>><?php echo e($ol); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="c_value[]" value="<?php echo e($c['value'] ?? ''); ?>" placeholder="مقدار">
                        <button type="button" class="btn-sm" style="background:#eb3349" onclick="this.parentElement.remove()">✕</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn-secondary" onclick="addCond()">+ افزودن شرط</button>

                <h3 style="margin:26px 0 12px">⚡ اقدام</h3>
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="switch">
                            <input type="checkbox" name="a_notify" value="1" <?php echo empty($acts) || !empty($acts['notify']) ? 'checked' : ''; ?>>
                            <span class="track"></span><span>ارسال اعلان</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label>عنوان اعلان</label>
                        <input type="text" name="a_notify_title" value="<?php echo e($acts['notify_title'] ?? 'هشدار پروژه {subject}'); ?>">
                    </div>
                    <div class="form-group">
                        <label>سطح</label>
                        <select name="a_notify_level">
                            <?php foreach (['info' => 'اطلاع', 'success' => 'موفقیت', 'warning' => 'هشدار', 'danger' => 'بحرانی'] as $k => $l): ?>
                                <option value="<?php echo e($k); ?>" <?php echo ($acts['notify_level'] ?? 'warning') === $k ? 'selected' : ''; ?>><?php echo e($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>گیرندگان</label>
                        <select name="a_targets">
                            <?php foreach (['project_members' => 'اعضای پروژه', 'managers' => 'مدیران', 'admins' => 'مدیران ارشد', 'all_employees' => 'همه کارکنان', 'client' => 'کارفرمای پروژه'] as $k => $l): ?>
                                <option value="<?php echo e($k); ?>" <?php echo ($acts['targets'] ?? 'project_members') === $k ? 'selected' : ''; ?>><?php echo e($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>نمایش برای</label>
                        <select name="a_visibility">
                            <?php foreach (['both' => 'تیم و کارفرما', 'client' => 'فقط کارفرما', 'internal' => 'فقط داخلی'] as $k => $l): ?>
                                <option value="<?php echo e($k); ?>" <?php echo ($acts['visibility'] ?? 'both') === $k ? 'selected' : ''; ?>><?php echo e($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label>متن اعلان</label>
                        <textarea name="a_notify_body" rows="3"><?php echo e($acts['notify_body'] ?? 'وضعیت پروژه {subject} نیازمند بررسی است. پیشرفت فعلی: {progress}٪'); ?></textarea>
                        <small style="color:#7c8aa0">متغیرها: <code>{subject}</code> <code>{progress}</code> <code>{deadline}</code> <code>{days_overdue}</code></small>
                    </div>
                    <div class="form-group full-width">
                        <label class="switch">
                            <input type="checkbox" name="a_log" value="1" <?php echo empty($acts) || !empty($acts['log']) ? 'checked' : ''; ?>>
                            <span class="track"></span><span>ثبت در لاگ سیستم</span>
                        </label>
                    </div>
                </div>

                <div style="display:flex;gap:8px;margin-top:22px;flex-wrap:wrap">
                    <button type="submit" class="btn">💾 ذخیره قاعده</button>
                    <a href="automation.php" class="btn-secondary">انصراف</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function addCond() {
    var tpl = '<div class="cond-row" style="display:grid;grid-template-columns:1fr 130px 1fr auto;gap:8px;margin-bottom:8px;align-items:center">'
        + '<select name="c_field[]"><?php foreach ($fields as $fk => $fl): ?><option value="<?php echo e($fk); ?>"><?php echo e($fl); ?></option><?php endforeach; ?></select>'
        + '<select name="c_op[]"><?php foreach ($ops as $ok => $ol): ?><option value="<?php echo e($ok); ?>"><?php echo e($ol); ?></option><?php endforeach; ?></select>'
        + '<input type="text" name="c_value[]" placeholder="مقدار">'
        + '<button type="button" class="btn-sm" style="background:#eb3349" onclick="this.parentElement.remove()">✕</button></div>';
    document.getElementById('condList').insertAdjacentHTML('beforeend', tpl);
}
if (location.hash === '#ruleForm' || location.search.indexOf('edit=') >= 0) {
    var f = document.getElementById('ruleForm');
    if (f) f.scrollIntoView({ behavior: 'smooth' });
}
</script>
</body>
</html>
