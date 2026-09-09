<?php
/**
 * ============================================================================
 *  Odsco — میز کار پروژه‌ها
 * ----------------------------------------------------------------------------
 *  اعضا · وظایف (کانبان) · گزارش پیشرفت · هشدار به کارفرما
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

$projectUid = (string)($_GET['project'] ?? '');
$project = $projectUid !== '' ? Projects::find($projectUid) : null;

// ---------------------------------------------------------------------------
// عملیات‌ها
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)($_POST['act'] ?? '');
    try {
        if ($act === 'add_member') {
            check_permission('manager');
            Projects::addMember($projectUid, [
                'user_uid' => (string)($_POST['user_uid'] ?? ''),
                'role'     => (string)($_POST['role'] ?? 'عضو تیم'),
            ]);
            $msg = '✅ عضو به پروژه اضافه شد';

        } elseif ($act === 'remove_member') {
            check_permission('manager');
            Projects::removeMember($projectUid, (string)($_POST['member_uid'] ?? ''));
            $msg = '✅ عضو حذف شد';

        } elseif ($act === 'add_task') {
            check_permission('manager');
            Projects::addTask($projectUid, [
                'title'          => (string)($_POST['title'] ?? ''),
                'description'    => (string)($_POST['description'] ?? ''),
                'assignee_uid'   => (string)($_POST['assignee_uid'] ?? ''),
                'due_date'       => (string)($_POST['due_date'] ?? ''),
                'priority'       => (string)($_POST['priority'] ?? 'medium'),
            ]);
            $msg = '✅ وظیفه ایجاد شد';

        } elseif ($act === 'task_status') {
            check_permission('viewer');
            Projects::taskStatus($projectUid, (string)($_POST['task_uid'] ?? ''), (string)($_POST['status'] ?? ''));
            $msg = '✅ وضعیت وظیفه تغییر کرد';

        } elseif ($act === 'task_progress') {
            check_permission('viewer');
            Projects::taskProgress($projectUid, (string)($_POST['task_uid'] ?? ''), (int)($_POST['progress'] ?? 0));
            $msg = '✅ پیشرفت وظیفه ذخیره شد';

        } elseif ($act === 'task_comment') {
            check_permission('viewer');
            Projects::addTaskComment($projectUid, (string)($_POST['task_uid'] ?? ''), $user['uid'], (string)($_POST['comment'] ?? ''));
            $msg = '✅ نظر ثبت شد';

        } elseif ($act === 'update') {
            check_permission('manager');
            $level = (string)($_POST['level'] ?? 'info');
            ProjectAlert::create($projectUid, $user['uid'], [
                'title'      => (string)($_POST['title'] ?? ''),
                'body'       => (string)($_POST['body'] ?? ''),
                'level'      => $level,
                'visibility' => (string)($_POST['visibility'] ?? 'both'),
                'progress'   => $_POST['progress'] !== '' && $_POST['progress'] !== null ? (int)$_POST['progress'] : null,
                'notify'     => !empty($_POST['notify']),
            ]);
            $msg = '✅ گزارش پیشرفت ثبت شد' . (!empty($_POST['notify']) ? ' و اعلان ارسال گردید' : '');

        } elseif ($act === 'recalc') {
            check_permission('manager');
            $p = Projects::recalcProgress($projectUid);
            $msg = '✅ پیشرفت پروژه از وظایف محاسبه شد: ' . fa_number((int)$p['progress']) . '٪';

        } else {
            throw new RuntimeException('عملیات نامعتبر');
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }

    // برگشت به همان پروژه
    if ($projectUid !== '') {
        header('Location: workspace.php?project=' . rawurlencode($projectUid) . '&ok=1');
        exit;
    }
}

if (isset($_GET['ok'])) {
    $msg = '✅ تغییرات اعمال شد';
}

// ---------------------------------------------------------------------------
// نمای پروژه
// ---------------------------------------------------------------------------
if (!$project):
    $projects = Projects::list();
    $overdue = 0;
    $atRisk = 0;
    foreach ($projects as $p) {
        if (!empty($p['end_date']) && strtotime((string)$p['end_date']) < time() && $p['progress'] < 100) $overdue++;
        if ($p['progress'] < 50 && !empty($p['end_date']) && strtotime((string)$p['end_date']) < strtotime('+7 days')) $atRisk++;
    }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>میز کار پروژه‌ها | پنل مدیریت</title>
<link rel="stylesheet" href="admin-style.css">
</head>
<body>
<div class="admin-layout">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="content-card">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1>📋 میز کار پروژه‌ها</h1>
                    <p>یک پروژه را باز کنید تا اعضا، وظایف و گزارش پیشرفت را مدیریت کنید.</p>
                </div>
                <a href="manage-projects.php" class="btn">+ پروژه جدید</a>
            </div>

            <div class="stats-grid" style="margin-bottom:22px">
                <div class="stat-card" style="background:linear-gradient(135deg,#667eea,#764ba2)">
                    <div class="stat-icon">🏗️</div>
                    <div class="stat-info"><h3><?php echo fa_number(count($projects)); ?></h3><p>کل پروژه‌ها</p></div>
                </div>
                <div class="stat-card" style="background:linear-gradient(135deg,#eb3349,#f45c43)">
                    <div class="stat-icon">⏰</div>
                    <div class="stat-info"><h3><?php echo fa_number($overdue); ?></h3><p>عقب‌افتاده از مهلت</p></div>
                </div>
                <div class="stat-card" style="background:linear-gradient(135deg,#f7971e,#ffd200)">
                    <div class="stat-icon">⚠️</div>
                    <div class="stat-info"><h3><?php echo fa_number($atRisk); ?></h3><p>در خطر (کمتر از ۷ روز)</p></div>
                </div>
                <div class="stat-card" style="background:linear-gradient(135deg,#11998e,#38ef7d)">
                    <div class="stat-icon">✅</div>
                    <div class="stat-info"><h3><?php echo fa_number(count(array_filter($projects, fn($p) => (int)$p['progress'] >= 100))); ?></h3><p>تکمیل‌شده</p></div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>پروژه</th><th>کارفرما</th><th>اعضا</th><th>وظایف</th><th>پیشرفت</th><th>مهلت</th><th>عملیات</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($projects as $p):
                        $late = !empty($p['end_date']) && strtotime((string)$p['end_date']) < time() && $p['progress'] < 100;
                    ?>
                        <tr>
                            <td><strong><?php echo e($p['title']); ?></strong></td>
                            <td><?php echo e($p['client'] ?: '—'); ?></td>
                            <td><?php echo fa_number(count(ProjectMembers::list((string)$p['uid']))); ?></td>
                            <td><?php echo fa_number(count(Tasks::list((string)$p['uid']))); ?></td>
                            <td style="min-width:120px">
                                <div class="progress-bar"><span style="width:<?php echo (int)$p['progress']; ?>%"></span></div>
                                <small><?php echo fa_number((int)$p['progress']); ?>٪</small>
                            </td>
                            <td>
                                <?php if (!empty($p['end_date'])): ?>
                                    <?php echo e(jalali_date((string)$p['end_date'])); ?>
                                    <?php if ($late): ?><span class="pill danger">عقب‌افتاده</span><?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><a href="?project=<?php echo e($p['uid']); ?>" class="btn-sm">باز کردن</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$projects): ?><tr><td colspan="7" style="text-align:center;color:#7c8aa0">پروژه‌ای ثبت نشده است</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
<?php
    exit;
endif;

// ---------------------------------------------------------------------------
// صفحه یک پروژه
// ---------------------------------------------------------------------------
$members = ProjectMembers::list($projectUid);
$tasks   = Tasks::list($projectUid);
$updates = Projects::updates($projectUid);
$client  = Clients::find($project['client_uid']);
$allUsers = array_values(array_filter(Users::list(), fn($u) => $u['role'] !== 'client'));
$memberUids = array_column($members, 'user_uid');
$nonMembers = array_values(array_filter($allUsers, fn($u) => !in_array($u['uid'], $memberUids, true)));

$board = ['todo' => [], 'doing' => [], 'review' => [], 'done' => []];
foreach ($tasks as $t) {
    $board[$t['status'] ?? 'todo'][] = $t;
}
$colMeta = [
    'todo'       => ['⚪', 'در انتظار', '#eef1f6'],
    'doing'     => ['🔵', 'در حال انجام', '#e7f0ff'],
    'review'     => ['🟠', 'بررسی', '#fff4e0'],
    'done'       => ['✅', 'انجام‌شده', '#e4f8ea'],
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($project['title']); ?> | میز کار</title>
<link rel="stylesheet" href="admin-style.css">
</head>
<body>
<div class="admin-layout">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="content-card">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1>🏗️ <?php echo e($project['title']); ?></h1>
                    <p>
                        <?php if ($client): ?>کارفرما: <strong><?php echo e($client['name']); ?></strong> · <?php endif; ?>
                        مهلت: <?php echo e(!empty($project['end_date']) ? jalali_date((string)$project['end_date']) : 'تعیین نشده'); ?>
                        · پیشرفت <?php echo fa_number((int)$project['progress']); ?>٪
                    </p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <a href="workspace.php" class="btn-secondary">← بازگشت</a>
                    <a href="manage-projects.php?edit=<?php echo e($projectUid); ?>" class="btn-secondary">✏️ ویرایش پروژه</a>
                    <form method="post" style="display:inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="act" value="recalc">
                        <button type="submit" class="btn">🔄 محاسبه پیشرفت از وظایف</button>
                    </form>
                </div>
            </div>

            <?php if ($msg): ?><div class="alert alert-success"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?php echo e($err); ?></div><?php endif; ?>

            <div style="margin-bottom:22px">
                <div class="progress-bar" style="height:14px"><span style="width:<?php echo (int)$project['progress']; ?>%"></span></div>
                <div style="font-size:12px;color:#7c8aa0;margin-top:6px"><?php echo fa_number((int)$project['progress']); ?>٪ پیشرفت کلی</div>
            </div>

            <div class="tabs-bar">
                <a href="#members">👥 اعضا (<?php echo fa_number(count($members)); ?>)</a>
                <a href="#tasks">📌 وظایف (<?php echo fa_number(count($tasks)); ?>)</a>
                <a href="#updates">📊 گزارش پیشرفت (<?php echo fa_number(count($updates)); ?>)</a>
            </div>

            <!-- ============ اعضا ============ -->
            <a id="members"></a>
            <h3 style="margin-bottom:14px">👥 اعضای پروژه</h3>

            <div class="att-grid" style="margin-bottom:20px">
                <?php foreach ($members as $m): ?>
                <div class="att-card">
                    <div class="av"><?php echo e(mb_substr($m['full_name'] ?: '؟', 0, 1)); ?></div>
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:700;font-size:13.5px"><?php echo e($m['full_name'] ?: 'کاربر حذف‌شده'); ?></div>
                        <div style="font-size:11.5px;color:#7c8aa0;margin-top:2px"><?php echo e($m['role_in_project'] ?: 'عضو تیم'); ?></div>
                        <div style="margin-top:5px"><span class="pill mute"><?php echo fa_number(Tasks::countForUser($projectUid, (string)$m['user_uid'])); ?> وظیفه</span></div>
                    </div>
                    <form method="post" onsubmit="return confirm('این عضو از پروژه حذف شود؟')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="act" value="remove_member">
                        <input type="hidden" name="member_uid" value="<?php echo e((string)$m['user_uid']); ?>">
                        <button type="submit" class="btn-sm" style="background:#eb3349" title="حذف">✕</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($nonMembers): ?>
            <form method="post" class="form-grid" style="background:#f7f9fc;border:1px solid #e6ebf2;border-radius:14px;padding:16px;margin-bottom:26px">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="act" value="add_member">
                <div class="form-group">
                    <label>افزودن عضو</label>
                    <select name="user_uid" required>
                        <option value="">انتخاب کاربر…</option>
                        <?php foreach ($nonMembers as $u): ?>
                            <option value="<?php echo e($u['uid']); ?>"><?php echo e($u['full_name']); ?> — <?php echo e(Users::roleLabel((string)$u['role'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>نقش در پروژه</label>
                    <input type="text" name="role" placeholder="مثلاً مسئول اجرا" value="عضو تیم">
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn">+ افزودن</button>
                </div>
            </form>
            <?php endif; ?>

            <!-- ============ وظایف ============ -->
            <a id="tasks"></a>
            <h3 style="margin-bottom:14px">📌 تخته وظایف</h3>

            <div class="kanban" style="margin-bottom:20px">
                <?php foreach ($colMeta as $key => [$icon, $label, $bg]): ?>
                <div class="kanban-col">
                    <h4><?php echo e($icon . ' ' . $label); ?> <span style="color:#9aa7bb">(<?php echo fa_number(count($board[$key])); ?>)</span></h4>
                    <?php foreach ($board[$key] as $t): ?>
                    <div class="kanban-card" style="background:<?php echo e($bg); ?>">
                        <div style="font-weight:700"><?php echo e($t['title']); ?></div>
                        <?php if ($t['description'] !== ''): ?>
                            <div style="color:#5a6b80;font-size:11.5px;margin-top:4px;line-height:1.7"><?php echo e(mb_substr($t['description'], 0, 90)); ?></div>
                        <?php endif; ?>
                        <div class="progress-bar" style="height:6px;margin-top:8px"><span style="width:<?php echo (int)$t['progress']; ?>%"></span></div>
                        <div class="who">
                            👤 <?php echo e($t['assignee_name'] ?: 'تخصیص‌نیافته'); ?>
                            <?php if ($t['due_date']): ?> · 📅 <?php echo e(jalali_date((string)$t['due_date'])); ?><?php endif; ?>
                            · <?php echo e(Tasks::PRIORITIES[$t['priority']] ?? $t['priority']); ?>
                        </div>
                        <div style="display:flex;gap:5px;margin-top:8px;flex-wrap:wrap">
                            <?php foreach ($colMeta as $sk => [$si, $sl, $sb]): if ($sk === $key) continue; ?>
                            <form method="post" style="display:inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="act" value="task_status">
                                <input type="hidden" name="task_uid" value="<?php echo e($t['uid']); ?>">
                                <input type="hidden" name="status" value="<?php echo e($sk); ?>">
                                <button type="submit" class="btn-sm" style="padding:4px 9px;font-size:11px" title="انتقال به <?php echo e($sl); ?>"><?php echo e($si); ?></button>
                            </form>
                            <?php endforeach; ?>
                            <a href="?project=<?php echo e($projectUid); ?>#task-<?php echo e($t['uid']); ?>" class="btn-sm" style="padding:4px 9px;font-size:11px">جزئیات</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$board[$key]): ?><p style="color:#9aa7bb;font-size:12px;text-align:center;padding:12px 0">خالی</p><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <form method="post" class="form-grid" style="background:#f7f9fc;border:1px solid #e6ebf2;border-radius:14px;padding:16px;margin-bottom:26px">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="act" value="add_task">
                <div class="form-group">
                    <label>عنوان وظیفه *</label>
                    <input type="text" name="title" required placeholder="مثلاً اجرای فونداسیون">
                </div>
                <div class="form-group">
                    <label>تخصیص به</label>
                    <select name="assignee_uid">
                        <option value="">— بدون تخصیص —</option>
                        <?php foreach ($members as $m): ?>
                            <option value="<?php echo e((string)$m['user_uid']); ?>"><?php echo e($m['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>مهلت</label><input type="date" name="due_date"></div>
                <div class="form-group">
                    <label>اولویت</label>
                    <select name="priority">
                        <option value="low">کم</option>
                        <option value="medium" selected>متوسط</option>
                        <option value="high">زیاد</option>
                        <option value="critical">بحرانی</option>
                    </select>
                </div>
                <div class="form-group full-width">
                    <label>توضیحات</label>
                    <textarea name="description" rows="2"></textarea>
                </div>
                <div class="form-group full-width"><button type="submit" class="btn">+ افزودن وظیفه</button></div>
            </form>

            <?php foreach ($tasks as $t): ?>
            <a id="task-<?php echo e($t['uid']); ?>"></a>
            <div style="background:#fff;border:1px solid #e6ebf2;border-radius:14px;padding:16px;margin-bottom:12px">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
                    <h4 style="font-size:14.5px">📌 <?php echo e($t['title']); ?></h4>
                    <span class="pill mute"><?php echo e(Tasks::PRIORITIES[$t['priority']] ?? $t['priority']); ?></span>
                </div>
                <?php if ($t['description'] !== ''): ?><p style="font-size:13px;color:#5a6b80;line-height:1.9;margin-top:8px"><?php echo nl2br(e($t['description'])); ?></p><?php endif; ?>
                <div style="font-size:12px;color:#7c8aa0;margin-top:8px">
                    👤 <?php echo e($t['assignee_name'] ?: 'تخصیص‌نیافته'); ?>
                    <?php if ($t['due_date']): ?> · 📅 مهلت <?php echo e(jalali_date((string)$t['due_date'])); ?><?php endif; ?>
                    · <?php echo e(Tasks::STATUSES[$t['status']] ?? $t['status']); ?>
                </div>

                <form method="post" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:12px">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="act" value="task_progress">
                    <input type="hidden" name="task_uid" value="<?php echo e($t['uid']); ?>">
                    <div class="form-group" style="margin:0;flex:1;min-width:160px">
                        <label>پیشرفت: <?php echo fa_number((int)$t['progress']); ?>٪</label>
                        <input type="range" name="progress" min="0" max="100" step="5" value="<?php echo (int)$t['progress']; ?>">
                    </div>
                    <button type="submit" class="btn">ذخیره پیشرفت</button>
                </form>

                <?php $comments = Projects::taskComments($projectUid, (string)$t['uid']); ?>
                <?php if ($comments): ?>
                <div style="margin-top:14px;border-top:1px dashed #e6ebf2;padding-top:12px">
                    <?php foreach ($comments as $c): ?>
                    <div style="font-size:12.5px;margin-bottom:8px">
                        <strong><?php echo e($c['author_name']); ?></strong>
                        <span style="color:#9aa7bb;font-size:11px"><?php echo e(jalali_datetime((string)$c['created_at'])); ?></span>
                        <div style="color:#5a6b80;line-height:1.8;margin-top:2px"><?php echo nl2br(e($c['body'])); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="post" style="display:flex;gap:8px;margin-top:10px">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="act" value="task_comment">
                    <input type="hidden" name="task_uid" value="<?php echo e($t['uid']); ?>">
                    <input type="text" name="comment" placeholder="نظر یا گزارش کار…" required style="flex:1">
                    <button type="submit" class="btn-sm">ارسال</button>
                </form>
            </div>
            <?php endforeach; ?>

            <!-- ============ گزارش پیشرفت / هشدار به کارفرما ============ -->
            <a id="updates"></a>
            <h3 style="margin:26px 0 14px">📊 گزارش پیشرفت و اطلاع‌رسانی به کارفرما</h3>

            <form method="post" class="form-grid" style="background:#f7f9fc;border:1px solid #e6ebf2;border-radius:14px;padding:16px;margin-bottom:20px">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="act" value="update">
                <div class="form-group">
                    <label>عنوان گزارش *</label>
                    <input type="text" name="title" required placeholder="مثلاً اتمام مرحله فونداسیون">
                </div>
                <div class="form-group">
                    <label>سطح</label>
                    <select name="level">
                        <option value="info">ℹ️ اطلاع</option>
                        <option value="success">✅ موفقیت</option>
                        <option value="warning">⚠️ هشدار</option>
                        <option value="danger">⛔ بحرانی</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>نمایش برای</label>
                    <select name="visibility">
                        <option value="both">هم تیم و هم کارفرما</option>
                        <option value="client">فقط کارفرما</option>
                        <option value="internal">فقط داخلی</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>پیشرفت پروژه (٪) — خالی = بدون تغییر</label>
                    <input type="number" name="progress" min="0" max="100" placeholder="<?php echo (int)$project['progress']; ?>">
                </div>
                <div class="form-group full-width">
                    <label>متن گزارش</label>
                    <textarea name="body" rows="3" placeholder="توضیح وضعیت، موانع، برنامه بعدی…"></textarea>
                </div>
                <div class="form-group full-width">
                    <label class="switch">
                        <input type="checkbox" name="notify" value="1" checked>
                        <span class="track"></span>
                        <span>اعلان همزمان برای اعضای پروژه و کارفرما ارسال شود (پیام‌رسان + پنل کارفرما)</span>
                    </label>
                </div>
                <div class="form-group full-width">
                    <button type="submit" class="btn">📣 ثبت و ارسال گزارش</button>
                </div>
            </form>

            <?php if ($updates): ?>
            <div class="timeline">
                <?php foreach ($updates as $u): ?>
                <div class="timeline-item <?php echo e($u['level']); ?>">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <strong style="font-size:13.5px"><?php echo e(ProjectAlert::levelIcon($u['level']) . ' ' . $u['title']); ?></strong>
                        <span class="pill <?php echo e(['info' => 'info', 'success' => 'ok', 'warning' => 'warn', 'danger' => 'danger'][$u['level']] ?? 'mute'); ?>">
                            <?php echo e(['internal' => 'داخلی', 'client' => 'کارفرما', 'both' => 'هر دو'][$u['visibility']] ?? $u['visibility']); ?>
                        </span>
                        <?php if ($u['progress'] !== null): ?><span class="pill mute"><?php echo fa_number((int)$u['progress']); ?>٪</span><?php endif; ?>
                        <span style="font-size:11.5px;color:#9aa7bb;margin-right:auto"><?php echo e(jalali_datetime((string)$u['created_at'])); ?> · <?php echo e($u['author_name']); ?></span>
                    </div>
                    <?php if ($u['body'] !== ''): ?><p style="font-size:12.5px;color:#5a6b80;line-height:1.9;margin-top:6px"><?php echo nl2br(e($u['body'])); ?></p><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
                <p style="color:#7c8aa0">هنوز گزارشی ثبت نشده است.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
