<?php
/**
 * ============================================================================
 *  Odsco — ارسال اطلاعیه (مدیر عامل → تیم / کارفرمایان)
 * ----------------------------------------------------------------------------
 *  یک اطلاعیه می‌تواند همزمان:
 *    • در پیام‌رسان به اعضا ارسال شود
 *    • در پنل کارفرما نمایش داده شود
 *    • به‌صورت گزارش پیشرفت روی پروژه ثبت شود
 * ============================================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/logger.php';
require_once dirname(__DIR__) . '/includes/automation.php';

check_login();

$user  = current_admin();
$myUid = $user['uid'];
$msg   = '';
$err   = '';

$projects = Projects::list();
$clients  = Clients::list();
$allUsers = Users::list();
$employees = array_values(array_filter($allUsers, fn($u) => !empty($u['attendance_enabled']) || in_array($u['role'], ['admin', 'manager', 'employee', 'editor'], true)));

$sent = Notifications::all(30);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    check_permission('manager');

    $title = trim((string)($_POST['title'] ?? ''));
    $body  = trim((string)($_POST['body'] ?? ''));
    $level = (string)($_POST['level'] ?? 'info');
    $aud   = (string)($_POST['audience'] ?? 'employees');
    $vis   = (string)($_POST['visibility'] ?? 'both');

    if ($title === '' || $body === '') {
        $err = 'عنوان و متن اطلاعیه الزامی است';
    } else {
        try {
            $recipients = [];
            $projectUid = (string)($_POST['project_uid'] ?? '');

            if ($aud === 'project_members' && $projectUid !== '') {
                $p = Projects::find($projectUid);
                $recipients = array_column((array)($p['members'] ?? []), 'user_uid');
            } elseif ($aud === 'managers') {
                $recipients = array_values(array_map(fn($u) => $u['uid'], array_filter($allUsers, fn($u) => Users::isManager($u['role']))));
            } elseif ($aud === 'admins') {
                $recipients = array_values(array_map(fn($u) => $u['uid'], array_filter($allUsers, fn($u) => $u['role'] === 'admin')));
            } elseif ($aud === 'employees') {
                $recipients = array_values(array_map(fn($u) => $u['uid'], $employees));
            } elseif ($aud === 'custom') {
                $recipients = array_values(array_filter((array)($_POST['users'] ?? [])));
            } elseif ($aud === 'client') {
                // فقط کارفرما → از طریق گزارش پروژه
                $recipients = [];
            }

            $recipients = array_values(array_unique(array_filter($recipients)));

            $counts = ['notify' => 0, 'message' => 0, 'project' => 0];

            // ۱) اعلان درون‌سیستمی (پنل کارفرما + زنگ پیام‌رسان)
            if (!empty($_POST['do_notify'])) {
                $targets = $aud === 'client' ? array_values(array_filter([(string)($_POST['client_uid'] ?? '')])) : $recipients;
                $counts['notify'] = Notifications::broadcast($targets, $title, $body, [
                    'type'  => 'broadcast',
                    'level' => $level,
                    'icon'  => ['info' => 'ℹ️', 'success' => '✅', 'warning' => '⚠️', 'danger' => '🚨'][$level] ?? '📢',
                    'link'  => $projectUid !== '' ? '../client/projects.php?project=' . $projectUid : '../messenger/notices.php',
                ]);
            }

            // ۲) ارسال به گفتگوی پیام‌رسان هر گیرنده
            if (!empty($_POST['do_messenger'])) {
                $fromUid = $myUid;
                foreach ($recipients as $rUid) {
                    if ($rUid === $fromUid) continue;
                    Messenger::send([
                        'chat_type'        => 'private',
                        'conversation_uid' => Messenger::conversationFor($fromUid, $rUid),
                        'sender_uid'       => $fromUid,
                        'type'             => 'text',
                        'content'          => $body,
                        'meta'             => ['title' => $title, 'broadcast' => true],
                    ]);
                    $counts['message']++;
                }
            }

            // ۳) ثبت به‌عنوان گزارش پیشرفت پروژه
            if (!empty($_POST['do_project']) && $projectUid !== '') {
                ProjectAlert::create($projectUid, $myUid, [
                    'title'      => $title,
                    'body'       => $body,
                    'level'      => $level,
                    'visibility' => $vis,
                    'progress'   => $_POST['progress'] !== '' ? (int)$_POST['progress'] : null,
                    'notify'     => false, // اعلان جداگانه ارسال شد
                ]);
                $counts['project'] = 1;
            }

            $parts = [];
            if ($counts['notify'])  $parts[] = fa_number($counts['notify']) . ' اعلان';
            if ($counts['message']) $parts[] = fa_number($counts['message']) . ' پیام در پیام‌رسان';
            if ($counts['project']) $parts[] = '۱ گزارش پروژه';
            $msg = '✅ اطلاعیه ارسال شد' . ($parts ? ' (' . implode('، ', $parts) . ')' : '');

            add_log('broadcast', 'ارسال اطلاعیه: ' . $title . ' — ' . implode('، ', $parts));
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ارسال اطلاعیه | پنل مدیریت</title>
<link rel="stylesheet" href="admin-style.css">
</head>
<body>
<div class="admin-layout">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div class="content-card">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1>📣 ارسال اطلاعیه و هشدار</h1>
                    <p>یک پیام بنویسید و همزمان به پیام‌رسان، پنل کارفرما و گزارش پروژه بفرستید.</p>
                </div>
            </div>

            <?php if ($msg): ?><div class="alert alert-success"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?php echo e($err); ?></div><?php endif; ?>

            <form method="post">
                <?php echo csrf_field(); ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label>عنوان *</label>
                        <input type="text" name="title" required placeholder="مثلاً توقف عملیات به دلیل بارندگی">
                    </div>
                    <div class="form-group">
                        <label>سطح اهمیت</label>
                        <select name="level">
                            <option value="info">ℹ️ اطلاع</option>
                            <option value="success">✅ موفقیت</option>
                            <option value="warning" selected>⚠️ هشدار</option>
                            <option value="danger">⛔ بحرانی</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>مخاطبان</label>
                        <select name="audience" id="aud" onchange="audChanged()">
                            <option value="employees">👷 همه کارکنان (<?php echo fa_number(count($employees)); ?>)</option>
                            <option value="managers">🧑‍💼 مدیران</option>
                            <option value="admins">👑 مدیران ارشد</option>
                            <option value="project_members">🏗️ اعضای یک پروژه</option>
                            <option value="client">🏢 کارفرمای یک شرکت</option>
                            <option value="custom">🎯 انتخاب دستی</option>
                        </select>
                    </div>
                    <div class="form-group" id="projWrap">
                        <label>پروژه</label>
                        <select name="project_uid" id="projSel">
                            <option value="">— انتخاب پروژه —</option>
                            <?php foreach ($projects as $p): ?>
                                <option value="<?php echo e($p['uid']); ?>"><?php echo e($p['title']); ?> (<?php echo fa_number((int)$p['progress']); ?>٪)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="clientWrap" style="display:none">
                        <label>کارفرما</label>
                        <select name="client_uid" id="clientSel">
                            <option value="">— انتخاب کارفرما —</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?php echo e($c['uid']); ?>"><?php echo e($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="progressWrap" style="display:none">
                        <label>پیشرفت پروژه (٪) — خالی = بدون تغییر</label>
                        <input type="number" name="progress" min="0" max="100">
                    </div>
                </div>

                <div class="form-group full-width" id="customWrap" style="display:none;margin-bottom:16px">
                    <label>انتخاب کاربران</label>
                    <div style="max-height:180px;overflow:auto;border:1px solid #e6ebf2;border-radius:12px;padding:12px;display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:6px">
                        <?php foreach ($allUsers as $u): ?>
                        <label style="display:flex;gap:8px;align-items:center;font-size:12.5px">
                            <input type="checkbox" name="users[]" value="<?php echo e($u['uid']); ?>">
                            <?php echo e($u['full_name']); ?>
                            <small style="color:#9aa7bb">(<?php echo e(Users::roleLabel((string)$u['role'])); ?>)</small>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group full-width">
                    <label>متن اطلاعیه *</label>
                    <textarea name="body" rows="5" required placeholder="توضیح کامل وضعیت، اقدام لازم و مهلت…"></textarea>
                </div>

                <div style="background:#f7f9fc;border:1px solid #e6ebf2;border-radius:14px;padding:16px;margin-bottom:20px">
                    <h4 style="margin-bottom:12px">📤 کانال‌های ارسال</h4>
                    <label class="switch" style="display:flex;margin-bottom:10px">
                        <input type="checkbox" name="do_notify" value="1" checked>
                        <span class="track"></span>
                        <span>🔔 اعلان درون‌سیستمی (زنگ پیام‌رسان + پنل کارفرما)</span>
                    </label>
                    <label class="switch" style="display:flex;margin-bottom:10px">
                        <input type="checkbox" name="do_messenger" value="1" checked>
                        <span class="track"></span>
                        <span>💬 ارسال به گفتگوی خصوصی هر گیرنده در پیام‌رسان</span>
                    </label>
                    <label class="switch" style="display:flex">
                        <input type="checkbox" name="do_project" value="1">
                        <span class="track"></span>
                        <span>🏗️ ثبت به‌عنوان گزارش پیشرفت پروژه (در تایم‌لاین پروژه نمایش داده می‌شود)</span>
                    </label>
                </div>

                <button type="submit" class="btn">📣 ارسال اطلاعیه</button>
            </form>
        </div>

        <div class="content-card" style="margin-top:20px">
            <h2 style="margin-bottom:16px">🕐 آخرین اعلان‌های ارسال‌شده</h2>
            <?php if (!$sent): ?><p style="color:#7c8aa0">اعلانی ارسال نشده است.</p><?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>عنوان</th><th>نوع</th><th>گیرنده</th><th>وضعیت</th><th>زمان</th></tr></thead>
                    <tbody>
                    <?php foreach ($sent as $n): ?>
                        <tr>
                            <td><?php echo e($n['title']); ?></td>
                            <td><span class="pill mute"><?php echo e($n['type']); ?></span></td>
                            <td><?php echo e($n['user_name'] ?: '—'); ?></td>
                            <td>
                                <?php if ($n['is_read']): ?><span class="pill ok">خوانده شد</span>
                                <?php else: ?><span class="pill warn">خوانده‌نشده</span><?php endif; ?>
                            </td>
                            <td><?php echo e(jalali_datetime((string)$n['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function audChanged() {
    var a = document.getElementById('aud').value;
    document.getElementById('projWrap').style.display = (a === 'project_members') ? '' : 'none';
    document.getElementById('clientWrap').style.display = (a === 'client') ? '' : 'none';
    document.getElementById('customWrap').style.display = (a === 'custom') ? '' : 'none';
    document.getElementById('progressWrap').style.display = (a === 'project_members') ? '' : 'none';
}
</script>
</body>
</html>
