<?php
/**
 * ============================================================================
 *  Odsco — سیستم خودکارسازی
 * ----------------------------------------------------------------------------
 *  قانون = رویداد + شرط‌ها + کارها
 *
 *  نکته مهم: این صفحه باید دقیقاً با مدل داده includes/automation.php کار کند.
 *  قبلاً کلیدهای اشتباهی (name/enabled/runs) می‌فرستاد و ذخیره قاعده با خطای
 *  «NOT NULL constraint failed: automation_rules.title» شکست می‌خورد.
 * ============================================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/logger.php';
require_once dirname(__DIR__) . '/includes/automation.php';

check_login();

$user  = current_admin();
$myUid = (string)($user['uid'] ?? '');
$msg   = '';
$err   = '';
$tab   = (string)($_GET['tab'] ?? 'rules');

$EVENTS = Automation::EVENTS;

/** متغیرهای در دسترس هر رویداد — برای شرط‌ها و برای {جایگزینی} در متن */
$EVENT_FIELDS = [
    'project.update.created' => ['project', 'title', 'level', 'progress', 'author', 'project_uid'],
    'project.deadline_soon'  => ['project', 'due_date', 'project_uid'],
    'task.overdue'           => ['task', 'assignee', 'due_date', 'assignee_uid', 'project_uid'],
    'task.due_soon'          => ['task', 'assignee', 'due_date', 'assignee_uid', 'project_uid'],
    'attendance.absent'      => ['user', 'user_uid'],
    'attendance.late'        => ['user', 'time', 'user_uid'],
    'client.message'         => ['name', 'email', 'subject'],
    'cron.daily'             => ['absent', 'purged', 'overdue', 'due_soon', 'deadline'],
];

$OPS = [
    'eq'        => 'مساوی',
    'neq'       => 'نامساوی',
    'gt'        => 'بزرگ‌تر از',
    'lt'        => 'کوچک‌تر از',
    'contains'  => 'شامل',
    'empty'     => 'خالی است',
    'not_empty' => 'خالی نیست',
];

$TARGETS = [
    'managers' => '👔 مدیران',
    'staff'    => '👥 همه کارکنان',
    'members'  => '🏗 اعضای پروژه',
    'clients'  => '🏢 کارفرمای پروژه',
    'assignee' => '🎯 مسئول تسک',
    'user'     => '👤 خود کاربر',
    'admin'    => '🛡 مدیران کل',
];

$LEVELS = [
    'info'    => 'ℹ️ اطلاع',
    'success' => '✅ موفق',
    'warning' => '⚠️ هشدار',
    'danger'  => '⛔ خطر',
];

// ---------------------------------------------------------------------------
// عملیات‌ها
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)($_POST['act'] ?? '');

    try {
        if ($act === 'save') {
            check_permission('manager');

            $title = trim((string)($_POST['title'] ?? ''));
            $event = (string)($_POST['event'] ?? '');
            if ($title === '') {
                throw new RuntimeException('عنوان قاعده را وارد کنید');
            }
            if (!array_key_exists($event, $EVENTS)) {
                throw new RuntimeException('رویداد نامعتبر است');
            }

            // شرط‌ها — فقط ردیف‌هایی که فیلد دارند
            $conditions = [];
            foreach ((array)($_POST['c_field'] ?? []) as $i => $field) {
                $field = trim((string)$field);
                if ($field === '') continue;
                $op = (string)($_POST['c_op'][$i] ?? 'eq');
                $conditions[] = [
                    'field' => $field,
                    'op'    => array_key_exists($op, $OPS) ? $op : 'eq',
                    'value' => (string)($_POST['c_value'][$i] ?? ''),
                ];
            }

            // کارها — هر ردیف یک کار مستقل
            $actions = [];
            foreach ((array)($_POST['a_type'] ?? []) as $i => $type) {
                $type = (string)$type;
                if ($type === 'log') {
                    $text = trim((string)($_POST['a_log_text'][$i] ?? ''));
                    if ($text === '') continue;
                    $actions[] = ['type' => 'log', 'text' => $text];
                    continue;
                }
                if ($type !== 'notify') continue;

                $target = (string)($_POST['a_target'][$i] ?? 'managers');
                if ($target === 'uids') {
                    $uids = trim((string)($_POST['a_uids'][$i] ?? ''));
                    if ($uids === '') continue;
                    $target = 'uids:' . $uids;
                } elseif (!array_key_exists($target, $TARGETS)) {
                    continue;
                }

                $level = (string)($_POST['a_level'][$i] ?? 'info');
                $actions[] = [
                    'type'   => 'notify',
                    'target' => $target,
                    'title'  => (string)($_POST['a_title'][$i] ?? 'اعلان خودکار'),
                    'body'   => (string)($_POST['a_body'][$i] ?? ''),
                    'level'  => array_key_exists($level, $LEVELS) ? $level : 'info',
                    'link'   => (string)($_POST['a_link'][$i] ?? ''),
                ];
            }

            if (!$actions) {
                throw new RuntimeException('حداقل یک کار (اعلان یا لاگ) تعریف کنید');
            }

            $payload = [
                'title'      => $title,
                'event'      => $event,
                'conditions' => $conditions,
                'actions'    => $actions,
                'is_active'  => isset($_POST['is_active']),
            ];

            $uid = (string)($_POST['rule_uid'] ?? '');
            if ($uid !== '') {
                Automation::update($uid, $payload);
                ActivityLog::add('automation_update', 'قاعده «' . $title . '» ویرایش شد');
                $msg = '✅ قاعده به‌روزرسانی شد';
            } else {
                Automation::create($payload, $myUid);
                ActivityLog::add('automation_create', 'قاعده «' . $title . '» ساخته شد');
                $msg = '✅ قاعده ساخته شد';
            }
            $tab = 'rules';

        } elseif ($act === 'toggle') {
            check_permission('manager');
            $ruleUid = (string)($_POST['rule_uid'] ?? '');
            $before  = Automation::find($ruleUid);
            if ($before) {
                $now = Automation::toggle($ruleUid);
                $msg = $now ? '▶️ قاعده «' . $before['title'] . '» فعال شد'
                            : '⏸ قاعده «' . $before['title'] . '» غیرفعال شد';
            } else {
                throw new RuntimeException('قاعده پیدا نشد');
            }

        } elseif ($act === 'delete') {
            check_permission('manager');
            $ruleUid = (string)($_POST['rule_uid'] ?? '');
            $before  = Automation::find($ruleUid);
            if (!$before) throw new RuntimeException('قاعده پیدا نشد');
            Automation::delete($ruleUid);
            ActivityLog::add('automation_delete', 'قاعده «' . $before['title'] . '» حذف شد');
            $msg = '🗑 قاعده حذف شد';

        } elseif ($act === 'test') {
            check_permission('manager');
            $event = (string)($_POST['event'] ?? '');
            if (!array_key_exists($event, $EVENTS)) throw new RuntimeException('رویداد نامعتبر است');

            // ساخت نمونه context از فیلدهای همان رویداد
            $context = ['event' => $event];
            foreach ($EVENT_FIELDS[$event] ?? [] as $f) {
                $context[$f] = (string)($_POST['ctx'][$f] ?? '');
            }

            $n = Automation::fire($event, $context);
            $msg = '🧪 رویداد «' . $EVENTS[$event] . '» اجرا شد — ' . fa_number($n) . ' کار انجام شد.';
            $tab = 'test';

        } elseif ($act === 'run_daily') {
            check_permission('manager');
            $report = Automation::runDaily();
            $msg = '🕒 اجرای روزانه انجام شد — غایب: ' . fa_number($report['absent'])
                 . '، تسک عقب‌افتاده: ' . fa_number($report['overdue'])
                 . '، نزدیک سررسید: ' . fa_number($report['due_soon'])
                 . '، پروژه نزدیک مهلت: ' . fa_number($report['deadline']);

        } elseif ($act === 'install') {
            check_permission('manager');
            $n = Automation::installTemplates($myUid);
            $msg = $n > 0
                ? '✅ ' . fa_number($n) . ' قاعده پیش‌فرض نصب شد'
                : 'قاعده‌های پیش‌فرض قبلاً نصب شده‌اند.';

        } else {
            throw new RuntimeException('عملیات نامعتبر');
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// داده‌ها
// ---------------------------------------------------------------------------
$rules   = Automation::rules();
$logs    = Automation::logs(50);
$editing = null;
$isNew   = false;

if (isset($_GET['edit'])) {
    if ($_GET['edit'] === 'new') {
        $isNew   = true;
        $editing = ['uid' => '', 'title' => '', 'event' => 'project.update.created',
                    'conditions' => [], 'actions' => [], 'is_active' => true];
    } else {
        $editing = Automation::find((string)$_GET['edit']);
        if (!$editing) $err = 'قاعده پیدا نشد.';
    }
}

$activeCount = count(array_filter($rules, fn($r) => !empty($r['is_active'])));
$cronKey     = (string)Settings::get('cron_key', '');
$host        = (string)($_SERVER['HTTP_HOST'] ?? 'yoursite.com');
$scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base        = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/'))), '/');
$cronUrl     = $scheme . '://' . $host . ($base !== '' && $base !== '/' ? $base : '') . '/../tools/cron.php?key=' . $cronKey;

/** خلاصه خوانای یک کار */
function act_summary(array $a, array $targets, array $levels): string
{
    if (($a['type'] ?? '') === 'log') {
        return '📋 لاگ: ' . mb_substr((string)($a['text'] ?? ''), 0, 42);
    }
    $t = (string)($a['target'] ?? '');
    $label = str_starts_with($t, 'uids:')
        ? '👤 ' . fa_number(count(explode(',', substr($t, 5)))) . ' کاربر مشخص'
        : ($targets[$t] ?? $t);
    return '📣 به ' . $label . ' — ' . mb_substr((string)($a['title'] ?? 'اعلان'), 0, 38);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>خودکارسازی | پنل مدیریت</title>
<link rel="stylesheet" href="admin-style.css">
<style>
.au-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.au-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:16px 0}
.au-tabs a{padding:9px 16px;border-radius:11px;font-size:13px;background:#f1f3f5;color:#495057;text-decoration:none}
.au-tabs a.active{background:#1a1a1a;color:#fff;font-weight:700}
.au-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin:14px 0 4px}
.au-stat{background:#fff;border:1px solid var(--admin-border);border-radius:14px;padding:13px 15px}
.au-stat b{display:block;font-size:22px;font-weight:900}
.au-stat span{font-size:11.5px;color:var(--admin-muted)}
.au-row-item{border:1px solid var(--admin-border);border-radius:12px;padding:12px;margin-bottom:10px;background:#fafbfc;position:relative}
.au-row-item .rm{position:absolute;top:8px;left:8px;background:#ffe3e6;color:#c92a2a;border:0;border-radius:8px;
  width:26px;height:26px;cursor:pointer;font-size:14px;line-height:1}
.au-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px}
.au-grid label,.au-form label{display:block;font-size:11.5px;color:var(--admin-muted);margin-bottom:5px;font-weight:700}
.au-grid input,.au-grid select,.au-form input[type=text],.au-form select,.au-form textarea{
  width:100%;padding:9px 11px;border:1px solid var(--admin-border);border-radius:9px;font-family:inherit;font-size:13px}
.au-hint{font-size:11.5px;color:var(--admin-muted);line-height:2}
.au-hint code{background:#eef1f4;padding:1px 6px;border-radius:5px;font-size:11px;direction:ltr;display:inline-block}
.au-add{background:#eef4ff;color:#2b5cb8;border:1px dashed #9dbdf0;border-radius:10px;padding:9px 16px;
  font-family:inherit;font-size:12.5px;cursor:pointer}
.au-switch{display:inline-flex;align-items:center;gap:9px;font-size:13px;cursor:pointer}
.au-switch input{width:18px;height:18px}
.pill{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11.5px}
.pill.ok{background:rgba(46,213,115,.16);color:#157f45}
.pill.mute{background:#eef1f4;color:#6c757d}
.pill.info{background:rgba(55,66,250,.1);color:#2b3ad1}
.pill.warn{background:rgba(255,165,2,.16);color:#995c00}
.au-code{direction:ltr;text-align:left;background:#0d1117;color:#8ee6a8;padding:12px 14px;border-radius:11px;
  font-family:Consolas,monospace;font-size:12px;overflow-x:auto;word-break:break-all}
.au-empty{text-align:center;padding:34px 12px;color:var(--admin-muted)}
.au-empty b{display:block;font-size:15px;color:#495057;margin-bottom:6px}
@media (max-width:760px){
  .au-grid{grid-template-columns:1fr}
  .au-tabs a{flex:1;text-align:center;padding:8px 10px;font-size:12px}
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
                    <h1>🤖 خودکارسازی</h1>
                    <p>قاعده بسازید: وقتی رویدادی رخ داد، به چه کسی چه چیزی اطلاع داده شود.</p>
                </div>
                <div class="au-bar">
                    <a href="?edit=new" class="btn btn-primary">+ قاعده جدید</a>
                    <form method="post" style="display:inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="act" value="install">
                        <button type="submit" class="btn">📦 قواعد پیش‌فرض</button>
                    </form>
                </div>
            </div>

            <?php if ($msg): ?><div class="alert alert-success"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?php echo e($err); ?></div><?php endif; ?>

            <div class="au-stats">
                <div class="au-stat"><b><?php echo fa_number(count($rules)); ?></b><span>کل قواعد</span></div>
                <div class="au-stat"><b><?php echo fa_number($activeCount); ?></b><span>فعال</span></div>
                <div class="au-stat"><b><?php echo fa_number(array_sum(array_map(fn($r) => (int)$r['run_count'], $rules))); ?></b><span>مجموع اجرا</span></div>
                <div class="au-stat"><b><?php echo fa_number(count($logs)); ?></b><span>رویداد اخیر</span></div>
            </div>

            <div class="au-tabs">
                <a href="?tab=rules" class="<?php echo $tab === 'rules' && !$editing ? 'active' : ''; ?>">📋 قواعد</a>
                <a href="?tab=logs"  class="<?php echo $tab === 'logs' ? 'active' : ''; ?>">📜 گزارش اجرا</a>
                <a href="?tab=test"  class="<?php echo $tab === 'test' ? 'active' : ''; ?>">🧪 آزمایش</a>
                <a href="?tab=cron"  class="<?php echo $tab === 'cron' ? 'active' : ''; ?>">⏰ زمان‌بندی</a>
            </div>

<?php if ($editing): ?>
            <!-- ================================================== فرم قاعده -->
            <form method="post" class="au-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="act" value="save">
                <input type="hidden" name="rule_uid" value="<?php echo e((string)$editing['uid']); ?>">

                <h3 style="margin:6px 0 14px"><?php echo $isNew ? '➕ قاعده جدید' : '✏️ ویرایش قاعده'; ?></h3>

                <div class="au-grid" style="grid-template-columns:2fr 1fr">
                    <div>
                        <label>عنوان قاعده *</label>
                        <input type="text" name="title" value="<?php echo e((string)$editing['title']); ?>" required
                               placeholder="مثلاً هشدار گزارش پیشرفت به مدیران">
                    </div>
                    <div>
                        <label>رویداد *</label>
                        <select name="event" id="auEvent" required>
                            <?php foreach ($EVENTS as $ek => $el): ?>
                                <option value="<?php echo e($ek); ?>" <?php echo $editing['event'] === $ek ? 'selected' : ''; ?>><?php echo e($el); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <p class="au-hint" id="auFields" style="margin-top:10px"></p>

                <label class="au-switch" style="margin:14px 0 18px">
                    <input type="checkbox" name="is_active" <?php echo !empty($editing['is_active']) ? 'checked' : ''; ?>>
                    <span>این قاعده فعال باشد</span>
                </label>

                <h4 style="margin:18px 0 10px">🔎 شرط‌ها <span class="au-hint">(خالی = همیشه اجرا شود)</span></h4>
                <div id="auConds">
                    <?php $conds = $editing['conditions'] ?: []; ?>
                    <?php if (!$conds): ?>
                        <p class="au-hint">شرطی تعریف نشده — قاعده برای همه رویدادهای این نوع اجرا می‌شود.</p>
                    <?php endif; ?>
                    <?php foreach ($conds as $c): ?>
                        <div class="au-row-item">
                            <button type="button" class="rm" onclick="this.parentElement.remove()" title="حذف">✕</button>
                            <div class="au-grid">
                                <div><label>فیلد</label><input type="text" name="c_field[]" value="<?php echo e((string)($c['field'] ?? '')); ?>" placeholder="progress"></div>
                                <div><label>عملگر</label>
                                    <select name="c_op[]">
                                        <?php foreach ($OPS as $ok => $ol): ?>
                                            <option value="<?php echo e($ok); ?>" <?php echo ($c['op'] ?? '') === $ok ? 'selected' : ''; ?>><?php echo e($ol); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div><label>مقدار</label><input type="text" name="c_value[]" value="<?php echo e((string)($c['value'] ?? '')); ?>"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="au-add" onclick="auAddCond()">+ افزودن شرط</button>

                <h4 style="margin:22px 0 10px">⚡ کارها <span class="au-hint">(حداقل یک کار لازم است)</span></h4>
                <div id="auActs">
                    <?php $acts = $editing['actions'] ?: []; ?>
                    <?php if (!$acts): ?>
                        <p class="au-hint">کاری تعریف نشده — «افزودن اعلان» را بزنید.</p>
                    <?php endif; ?>
                    <?php foreach ($acts as $a): ?>
                        <?php if (($a['type'] ?? '') === 'log'): ?>
                        <div class="au-row-item">
                            <button type="button" class="rm" onclick="this.parentElement.remove()" title="حذف">✕</button>
                            <input type="hidden" name="a_type[]" value="log">
                            <label>📋 نوشتن در لاگ</label>
                            <input type="text" name="a_log_text[]" value="<?php echo e((string)($a['text'] ?? '')); ?>" placeholder="متن لاگ — می‌توانید {project} بگذارید">
                        </div>
                        <?php else: ?>
                        <?php $tg = (string)($a['target'] ?? 'managers'); $isUids = str_starts_with($tg, 'uids:'); ?>
                        <div class="au-row-item">
                            <button type="button" class="rm" onclick="this.parentElement.remove()" title="حذف">✕</button>
                            <input type="hidden" name="a_type[]" value="notify">
                            <div class="au-grid">
                                <div><label>گیرنده</label>
                                    <select name="a_target[]">
                                        <?php foreach ($TARGETS as $tk => $tl): ?>
                                            <option value="<?php echo e($tk); ?>" <?php echo $tg === $tk ? 'selected' : ''; ?>><?php echo e($tl); ?></option>
                                        <?php endforeach; ?>
                                        <option value="uids" <?php echo $isUids ? 'selected' : ''; ?>>🔢 شناسه‌های مشخص</option>
                                    </select>
                                </div>
                                <div><label>شناسه‌ها <span style="font-weight:400">(اگر بالا «مشخص» است)</span></label>
                                    <input type="text" name="a_uids[]" value="<?php echo $isUids ? e(substr($tg, 5)) : ''; ?>" placeholder="usr_x, usr_y" style="direction:ltr">
                                </div>
                                <div><label>سطح</label>
                                    <select name="a_level[]">
                                        <?php foreach ($LEVELS as $lk => $ll): ?>
                                            <option value="<?php echo e($lk); ?>" <?php echo ($a['level'] ?? 'info') === $lk ? 'selected' : ''; ?>><?php echo e($ll); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div style="margin-top:10px">
                                <label>عنوان اعلان</label>
                                <input type="text" name="a_title[]" value="<?php echo e((string)($a['title'] ?? '')); ?>" placeholder="📢 گزارش جدید: {project}">
                            </div>
                            <div style="margin-top:10px">
                                <label>متن</label>
                                <textarea name="a_body[]" rows="2" placeholder="جزئیات…"><?php echo e((string)($a['body'] ?? '')); ?></textarea>
                            </div>
                            <div style="margin-top:10px">
                                <label>لینک (اختیاری)</label>
                                <input type="text" name="a_link[]" value="<?php echo e((string)($a['link'] ?? '')); ?>" placeholder="../project/detail.php?id={project_uid}" style="direction:ltr">
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="au-bar">
                    <button type="button" class="au-add" onclick="auAddAct('notify')">+ افزودن اعلان</button>
                    <button type="button" class="au-add" onclick="auAddAct('log')">+ افزودن لاگ</button>
                </div>

                <div class="au-bar" style="margin-top:22px">
                    <button type="submit" class="btn btn-primary">💾 ذخیره قاعده</button>
                    <a href="?tab=rules" class="btn">انصراف</a>
                </div>
            </form>

<?php elseif ($tab === 'logs'): ?>
            <!-- ================================================== گزارش اجرا -->
            <h3 style="margin-bottom:14px">📜 گزارش اجرای قواعد</h3>
            <?php if (!$logs): ?>
                <div class="au-empty"><b>هنوز رویدادی ثبت نشده</b>از تب «آزمایش» یک رویداد را دستی اجرا کنید.</div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>زمان</th><th>رویداد</th><th>قاعده</th><th>پیام</th><th>نتیجه</th></tr></thead>
                    <tbody>
                    <?php foreach ($logs as $l): ?>
                        <?php $ru = (string)($l['rule_uid'] ?? ''); ?>
                        <tr>
                            <td style="white-space:nowrap"><?php echo e(jalali_datetime((string)$l['created_at'])); ?></td>
                            <td><span class="pill info"><?php echo e($EVENTS[$l['event']] ?? (string)$l['event']); ?></span></td>
                            <td style="font-size:12px"><?php echo $ru !== '' ? e((string)(Automation::find($ru)['title'] ?? 'حذف‌شده')) : '—'; ?></td>
                            <td style="font-size:12px;max-width:340px"><?php echo e((string)$l['message']); ?></td>
                            <td><span class="pill <?php echo $l['status'] === 'ok' ? 'ok' : 'warn'; ?>"><?php echo e((string)$l['status']); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

<?php elseif ($tab === 'test'): ?>
            <!-- ================================================== آزمایش -->
            <h3 style="margin-bottom:6px">🧪 آزمایش رویداد</h3>
            <p class="au-hint" style="margin-bottom:14px">
                یک رویداد را با مقادیر دلخواه اجرا می‌کند تا ببینید کدام قواعد فعال می‌شوند.
                اعلان‌ها واقعاً ارسال می‌شوند.
            </p>
            <form method="post" class="au-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="act" value="test">
                <div class="au-grid" style="grid-template-columns:1fr">
                    <div>
                        <label>رویداد</label>
                        <select name="event" id="auTestEvent" onchange="auTestFields()">
                            <?php foreach ($EVENTS as $ek => $el): ?>
                                <option value="<?php echo e($ek); ?>"><?php echo e($el); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div id="auTestCtx" class="au-grid" style="margin-top:12px"></div>
                <button type="submit" class="btn btn-primary" style="margin-top:16px">▶️ اجرای آزمایشی</button>
            </form>

            <h4 style="margin:24px 0 10px">آخرین رویدادها</h4>
            <?php if (!$logs): ?>
                <p class="au-hint">هنوز چیزی ثبت نشده.</p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>زمان</th><th>رویداد</th><th>پیام</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($logs, 0, 10) as $l): ?>
                        <tr>
                            <td style="white-space:nowrap"><?php echo e(jalali_datetime((string)$l['created_at'])); ?></td>
                            <td><span class="pill info"><?php echo e($EVENTS[$l['event']] ?? (string)$l['event']); ?></span></td>
                            <td style="font-size:12px"><?php echo e((string)$l['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <form method="post" style="margin-top:20px">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="act" value="run_daily">
                <button type="submit" class="btn" onclick="return confirm('اجرای روزانه حالا انجام شود؟ غیبت‌ها ثبت و اعلان‌ها ارسال می‌شوند.')">
                    🕒 اجرای دستی «رویداد روزانه»
                </button>
            </form>

<?php elseif ($tab === 'cron'): ?>
            <!-- ================================================== زمان‌بندی -->
            <h3 style="margin-bottom:6px">⏰ زمان‌بندی روزانه</h3>
            <p class="au-hint" style="margin-bottom:14px">
                رویدادهای <code>task.overdue</code>، <code>task.due_soon</code>، <code>project.deadline_soon</code>،
                <code>attendance.absent</code> و پاک‌سازی پیام‌ها فقط با اجرای روزانه فعال می‌شوند.
            </p>

            <label style="display:block;font-size:12px;color:var(--admin-muted);margin-bottom:6px">آدرس کرون (URL)</label>
            <div class="au-code"><?php echo e($cronUrl); ?></div>

            <p class="au-hint" style="margin-top:14px">
                روی <b>ویندوز/IIS</b> با Task Scheduler:
            </p>
            <div class="au-code">Program:  C:\php\php.exe<br>Argument: <?php echo e(str_replace('\\', '\\\\', dirname(__DIR__))); ?>\tools\cron.php --key=<?php echo e($cronKey !== '' ? $cronKey : 'YOUR_CRON_KEY'); ?><br>Trigger:  Daily 07:00</div>

            <p class="au-hint" style="margin-top:14px">روی <b>لینوکس</b> با crontab:</p>
            <div class="au-code">0 7 * * * php <?php echo e(dirname(__DIR__)); ?>/tools/cron.php --key=<?php echo e($cronKey !== '' ? $cronKey : 'YOUR_CRON_KEY'); ?></div>

            <?php if ($cronKey === ''): ?>
                <div class="alert alert-error" style="margin-top:16px">
                    ⛔ کلید کرون ساخته نشده. به <a href="settings.php?section=ops">تنظیمات → زمان‌بندی</a> بروید و کلید بسازید،
                    وگرنه هر کسی می‌تواند کرون را صدا بزند.
                </div>
            <?php endif; ?>

<?php else: ?>
            <!-- ================================================== فهرست قواعد -->
            <?php if (!$rules): ?>
                <div class="au-empty">
                    <b>قاعده‌ای تعریف نشده</b>
                    «قواعد پیش‌فرض» را بزنید یا یک قاعده تازه بسازید.
                </div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>قاعده</th><th>رویداد</th><th>شرط‌ها</th><th>کارها</th><th>اجرا</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                    <tbody>
                    <?php foreach ($rules as $r): ?>
                        <tr>
                            <td><strong><?php echo e((string)$r['title']); ?></strong></td>
                            <td><span class="pill info"><?php echo e($EVENTS[$r['event']] ?? (string)$r['event']); ?></span></td>
                            <td style="font-size:11.5px;max-width:210px">
                                <?php if (!$r['conditions']): ?>
                                    <span class="pill mute">همیشه</span>
                                <?php else: ?>
                                    <?php foreach ($r['conditions'] as $c): ?>
                                        <span class="pill mute"><?php echo e(($c['field'] ?? '') . ' ' . ($OPS[$c['op'] ?? 'eq'] ?? ($c['op'] ?? '')) . ' ' . ($c['value'] ?? '')); ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:11.5px;max-width:250px">
                                <?php if (!$r['actions']): ?>
                                    <span class="pill warn">بدون کار!</span>
                                <?php else: ?>
                                    <?php foreach ($r['actions'] as $a): ?>
                                        <div><?php echo e(act_summary((array)$a, $TARGETS, $LEVELS)); ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap">
                                <?php echo fa_number((int)$r['run_count']); ?>
                                <?php if (!empty($r['last_run'])): ?>
                                    <div class="au-hint"><?php echo e(jalali_datetime((string)$r['last_run'])); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="pill <?php echo $r['is_active'] ? 'ok' : 'mute'; ?>"><?php echo $r['is_active'] ? 'فعال' : 'غیرفعال'; ?></span></td>
                            <td>
                                <div class="au-bar" style="gap:4px">
                                    <a href="?edit=<?php echo e((string)$r['uid']); ?>" class="btn-sm" title="ویرایش">✏️</a>
                                    <form method="post" style="display:inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="act" value="toggle">
                                        <input type="hidden" name="rule_uid" value="<?php echo e((string)$r['uid']); ?>">
                                        <button type="submit" class="btn-sm" title="فعال/غیرفعال"><?php echo $r['is_active'] ? '⏸' : '▶️'; ?></button>
                                    </form>
                                    <form method="post" style="display:inline">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="act" value="test">
                                        <input type="hidden" name="event" value="<?php echo e((string)$r['event']); ?>">
                                        <button type="submit" class="btn-sm" title="اجرای آزمایشی این رویداد">🧪</button>
                                    </form>
                                    <form method="post" style="display:inline" onsubmit="return confirm('این قاعده حذف شود؟')">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="act" value="delete">
                                        <input type="hidden" name="rule_uid" value="<?php echo e((string)$r['uid']); ?>">
                                        <button type="submit" class="btn-sm" style="background:#eb3349" title="حذف">🗑</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
<?php endif; ?>

        </div>
    </div>
</div>

<script>
var AU_FIELDS = <?php echo json_encode($EVENT_FIELDS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var AU_OPS = <?php echo json_encode($OPS, JSON_UNESCAPED_UNICODE); ?>;
var AU_TARGETS = <?php echo json_encode($TARGETS, JSON_UNESCAPED_UNICODE); ?>;

function auEsc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

/** راهنمای فیلدهای رویداد انتخاب‌شده */
function auShowFields() {
    var sel = document.getElementById('auEvent');
    var box = document.getElementById('auFields');
    if (!sel || !box) return;
    var f = AU_FIELDS[sel.value] || [];
    box.innerHTML = f.length
        ? '🔤 متغیرهای این رویداد برای شرط و متن: ' + f.map(function (x) { return '<code>{' + auEsc(x) + '}</code>'; }).join(' ')
        : 'این رویداد متغیر خاصی ندارد.';
}

function auAddCond() {
    var wrap = document.getElementById('auConds');
    var sel = document.getElementById('auEvent');
    var fields = AU_FIELDS[sel.value] || [];
    var opts = fields.map(function (f) { return '<option value="' + auEsc(f) + '">' + auEsc(f) + '</option>'; }).join('');
    var opOpts = Object.keys(AU_OPS).map(function (k) {
        return '<option value="' + auEsc(k) + '">' + auEsc(AU_OPS[k]) + '</option>';
    }).join('');

    var div = document.createElement('div');
    div.className = 'au-row-item';
    div.innerHTML = '<button type="button" class="rm" onclick="this.parentElement.remove()" title="حذف">✕</button>'
        + '<div class="au-grid">'
        + '<div><label>فیلد</label>'
        + (fields.length
            ? '<select name="c_field[]">' + opts + '</select>'
            : '<input type="text" name="c_field[]" placeholder="نام فیلد">')
        + '</div>'
        + '<div><label>عملگر</label><select name="c_op[]">' + opOpts + '</select></div>'
        + '<div><label>مقدار</label><input type="text" name="c_value[]" value=""></div>'
        + '</div>';
    wrap.appendChild(div);
}

function auAddAct(kind) {
    var wrap = document.getElementById('auActs');
    var div = document.createElement('div');
    div.className = 'au-row-item';

    if (kind === 'log') {
        div.innerHTML = '<button type="button" class="rm" onclick="this.parentElement.remove()" title="حذف">✕</button>'
            + '<input type="hidden" name="a_type[]" value="log">'
            + '<label>📋 نوشتن در لاگ</label>'
            + '<input type="text" name="a_log_text[]" placeholder="متن لاگ — می‌توانید {project} بگذارید">';
    } else {
        var tg = Object.keys(AU_TARGETS).map(function (k) {
            return '<option value="' + auEsc(k) + '">' + auEsc(AU_TARGETS[k]) + '</option>';
        }).join('') + '<option value="uids">🔢 شناسه‌های مشخص</option>';
        var lv = { info: 'ℹ️ اطلاع', success: '✅ موفق', warning: '⚠️ هشدار', danger: '⛔ خطر' };
        var lvo = Object.keys(lv).map(function (k) {
            return '<option value="' + k + '">' + lv[k] + '</option>';
        }).join('');

        div.innerHTML = '<button type="button" class="rm" onclick="this.parentElement.remove()" title="حذف">✕</button>'
            + '<input type="hidden" name="a_type[]" value="notify">'
            + '<div class="au-grid">'
            + '<div><label>گیرنده</label><select name="a_target[]">' + tg + '</select></div>'
            + '<div><label>شناسه‌ها</label><input type="text" name="a_uids[]" placeholder="usr_x, usr_y" style="direction:ltr"></div>'
            + '<div><label>سطح</label><select name="a_level[]">' + lvo + '</select></div>'
            + '</div>'
            + '<div style="margin-top:10px"><label>عنوان اعلان</label>'
            + '<input type="text" name="a_title[]" value="📢 اعلان خودکار" placeholder="📢 گزارش جدید: {project}"></div>'
            + '<div style="margin-top:10px"><label>متن</label>'
            + '<textarea name="a_body[]" rows="2" placeholder="جزئیات…"></textarea></div>'
            + '<div style="margin-top:10px"><label>لینک (اختیاری)</label>'
            + '<input type="text" name="a_link[]" placeholder="" style="direction:ltr"></div>';
    }
    wrap.appendChild(div);
}

/** ساخت فیلدهای نمونه برای تب آزمایش */
function auTestFields() {
    var sel = document.getElementById('auTestEvent');
    var box = document.getElementById('auTestCtx');
    if (!sel || !box) return;
    var f = AU_FIELDS[sel.value] || [];
    if (!f.length) { box.innerHTML = '<p class="au-hint">این رویداد متغیری ندارد؛ خالی اجرا می‌شود.</p>'; return; }
    box.innerHTML = f.map(function (x) {
        return '<div><label>' + auEsc(x) + '</label>'
             + '<input type="text" name="ctx[' + auEsc(x) + ']" value="نمونه"></div>';
    }).join('');
}

document.addEventListener('DOMContentLoaded', function () {
    var ev = document.getElementById('auEvent');
    if (ev) { ev.addEventListener('change', auShowFields); auShowFields(); }
    if (document.getElementById('auTestEvent')) auTestFields();
});
</script>
</body>
</html>
