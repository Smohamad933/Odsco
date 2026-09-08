<?php
/**
 * ============================================================================
 *  Odsco — پیام‌های فرم تماس سایت
 * ----------------------------------------------------------------------------
 *  منبع داده: جدول contact_messages (از طریق ContactMessages)
 * ============================================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/logger.php';

check_login();

$message = '';
$error = '';
$is_viewer = is_viewer();

// پاکسازی خودکار زباله‌دان بعد از ۳۰ روز
if (Db::ready()) {
    Db::i()->delete(Db::i()->t('contact_messages'),
        'is_trashed = 1 AND created_at < ?', [date('Y-m-d H:i:s', strtotime('-30 days'))]);
}

// ---------------------------------------------------------------------------
// عملیات‌ها
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_guard();
    $act = (string)$_POST['action'];
    $uid = (string)($_POST['message_id'] ?? '');

    if ($is_viewer) {
        $error = '⛔ شما فقط بیننده هستید!';
    } else {
        switch ($act) {
            case 'mark_read':
                ContactMessages::setFlags($uid, ['is_read' => 1]);
                $message = '✅ خوانده شد';
                break;

            case 'mark_unread':
                ContactMessages::setFlags($uid, ['is_read' => 0]);
                $message = '✅ به خوانده‌نشده تغییر کرد';
                break;

            case 'star':
                $m = null;
                foreach (ContactMessages::list() as $x) if ($x['uid'] === $uid) { $m = $x; break; }
                foreach (ContactMessages::trash() as $x) if ($x['uid'] === $uid) { $m = $x; break; }
                ContactMessages::setFlags($uid, ['is_starred' => $m && $m['is_starred'] ? 0 : 1]);
                $message = '✅ تغییر کرد';
                break;

            case 'delete_message':
                ContactMessages::delete($uid, false);
                add_log('delete_message', 'پیام به زباله‌دان منتقل شد');
                $message = '✅ پیام به زباله‌دان منتقل شد';
                break;

            case 'restore_message':
                ContactMessages::setFlags($uid, ['is_trashed' => 0]);
                $message = '✅ پیام بازیابی شد';
                break;

            case 'permanent_delete':
                ContactMessages::delete($uid, true);
                add_log('permanent_delete_message', 'پیام برای همیشه حذف شد');
                $message = '🗑 برای همیشه حذف شد';
                break;

            case 'empty_trash':
                check_permission('admin');
                if (Db::ready()) Db::i()->delete(Db::i()->t('contact_messages'), 'is_trashed = 1');
                add_log('empty_trash', 'زباله‌دان پیام‌ها خالی شد');
                $message = '✅ زباله‌دان خالی شد';
                break;

            case 'mark_all_read':
                if (Db::ready()) {
                    Db::i()->update(Db::i()->t('contact_messages'), ['is_read' => 1], 'is_trashed = 0');
                }
                $message = '✅ همه خوانده شدند';
                break;

            default:
                $error = 'عملیات نامعتبر';
        }
    }
}

// ---------------------------------------------------------------------------
// داده‌ها
// ---------------------------------------------------------------------------
$search = trim((string)($_GET['q'] ?? ''));
$filters = $search !== '' ? ['search' => $search] : [];

$all_messages = ContactMessages::list($filters);
usort($all_messages, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));

$trash_messages = ContactMessages::trash();
$unread = array_values(array_filter($all_messages, fn($m) => !$m['is_read']));
$starred = array_values(array_filter($all_messages, fn($m) => $m['is_starred']));

$viewing = null;
if (!empty($_GET['view'])) {
    foreach ($all_messages as $m) {
        if ($m['uid'] === (string)$_GET['view']) { $viewing = $m; break; }
    }
    if ($viewing && !$viewing['is_read']) {
        ContactMessages::setFlags($viewing['uid'], ['is_read' => 1]);
        $viewing['is_read'] = true;
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>پیام‌ها | پنل مدیریت</title>
<link rel="stylesheet" href="admin-style.css">
<style>
.msg-list { display: flex; flex-direction: column; gap: 8px; }
.msg-item {
    display: flex; gap: 12px; align-items: flex-start;
    background: #fff; border: 1px solid #e6ebf2; border-radius: 12px;
    padding: 13px 15px; transition: box-shadow .14s;
}
.msg-item:hover { box-shadow: 0 4px 16px rgba(20,30,60,.08); }
.msg-item.unread { border-right: 4px solid #667eea; background: #fbfcff; }
.msg-item .who { font-weight: 700; font-size: 13.5px; }
.msg-item .subj { font-size: 13px; color: #33405a; margin-top: 2px; }
.msg-item .prev { font-size: 12px; color: #7c8aa0; margin-top: 3px; line-height: 1.8; }
.msg-item .when { font-size: 11px; color: #9aa7bb; white-space: nowrap; }
.msg-item .acts { display: flex; gap: 4px; flex-shrink: 0; }
.msg-detail { background: #f7f9fc; border: 1px solid #e6ebf2; border-radius: 14px; padding: 20px; margin-bottom: 18px; }
.msg-detail .kv { display: flex; gap: 18px; flex-wrap: wrap; font-size: 12.5px; color: #5a6b80; margin-bottom: 14px; }
.msg-detail .body { background: #fff; border: 1px solid #e6ebf2; border-radius: 12px; padding: 16px; line-height: 2.1; font-size: 13.5px; white-space: pre-wrap; }
</style>
</head>
<body>
<div class="admin-layout">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="content-card">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1>📨 پیام‌های تماس</h1>
                    <p><?php echo fa_number(count($unread)); ?> خوانده‌نشده از <?php echo fa_number(count($all_messages)); ?> پیام</p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <form method="get" style="display:flex;gap:6px">
                        <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="جستجو…"
                               style="padding:9px 12px;border:1px solid #d8dfeb;border-radius:10px;font-family:inherit;font-size:13px">
                        <button type="submit" class="btn-secondary">🔍</button>
                    </form>
                    <?php if (!$is_viewer && $unread): ?>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="btn-secondary">✅ خواندن همه</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($message): ?><div class="alert alert-success"><?php echo e($message); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>

            <?php if ($viewing): ?>
            <!-- ============ نمای یک پیام ============ -->
            <div class="msg-detail">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
                    <div>
                        <h2 style="margin:0 0 8px;font-size:17px"><?php echo e($viewing['subject'] ?: '(بدون موضوع)'); ?></h2>
                        <div class="kv">
                            <span>👤 <?php echo e($viewing['name'] ?: '—'); ?></span>
                            <?php if ($viewing['email'] !== ''): ?><span>✉️ <a href="mailto:<?php echo e($viewing['email']); ?>"><?php echo e($viewing['email']); ?></a></span><?php endif; ?>
                            <?php if ($viewing['phone'] !== ''): ?><span>📞 <a href="tel:<?php echo e($viewing['phone']); ?>"><?php echo e($viewing['phone']); ?></a></span><?php endif; ?>
                            <span>🕐 <?php echo e(jalali_datetime((string)$viewing['created_at'])); ?></span>
                        </div>
                    </div>
                    <a href="manage-messages.php" class="btn-secondary">← بازگشت</a>
                </div>

                <div class="body"><?php echo e($viewing['message']); ?></div>

                <?php if (!empty($viewing['attachment'])):
                    $att = is_array($viewing['attachment']) ? $viewing['attachment'] : [$viewing['attachment']];
                ?>
                <div style="margin-top:14px">
                    <strong style="font-size:13px">📎 پیوست‌ها</strong>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
                        <?php foreach ($att as $a): ?>
                            <a href="../<?php echo e(ltrim((string)$a, '/')); ?>" target="_blank" class="btn-sm">
                                <?php echo e(file_icon((string)$a) . ' ' . basename((string)$a)); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$is_viewer): ?>
                <div style="display:flex;gap:8px;margin-top:18px;flex-wrap:wrap">
                    <?php if ($viewing['email'] !== ''): ?>
                        <a href="mailto:<?php echo e($viewing['email']); ?>?subject=پاسخ: <?php echo e(rawurlencode($viewing['subject'])); ?>" class="btn">✉️ پاسخ</a>
                    <?php endif; ?>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="star">
                        <input type="hidden" name="message_id" value="<?php echo e($viewing['uid']); ?>">
                        <button type="submit" class="btn-secondary"><?php echo $viewing['is_starred'] ? '⭐ برداشتن نشان' : '☆ نشان‌دار کردن'; ?></button>
                    </form>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_message">
                        <input type="hidden" name="message_id" value="<?php echo e($viewing['uid']); ?>">
                        <button type="submit" class="btn-danger">🗑 انتقال به زباله‌دان</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ============ صندوق ورودی ============ -->
            <h3 style="margin-bottom:12px">📥 صندوق ورودی (<?php echo fa_number(count($all_messages)); ?>)</h3>
            <?php if (!$all_messages): ?>
                <p style="color:#7c8aa0">پیامی وجود ندارد.</p>
            <?php else: ?>
            <div class="msg-list">
                <?php foreach ($all_messages as $m): ?>
                <div class="msg-item<?php echo $m['is_read'] ? '' : ' unread'; ?>">
                    <div style="flex:1;min-width:0">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                            <a href="?view=<?php echo e($m['uid']); ?>" class="who" style="text-decoration:none;color:inherit">
                                <?php echo e($m['name'] ?: 'ناشناس'); ?>
                            </a>
                            <?php if ($m['is_starred']): ?><span title="نشان‌دار">⭐</span><?php endif; ?>
                            <?php if (!$m['is_read']): ?><span class="pill" style="background:#e7f0ff;color:#1a56b0;font-size:10px">جدید</span><?php endif; ?>
                            <span class="when" style="margin-right:auto"><?php echo e(time_ago_fa((string)$m['created_at'])); ?></span>
                        </div>
                        <div class="subj"><strong><?php echo e($m['subject'] ?: '(بدون موضوع)'); ?></strong></div>
                        <div class="prev"><?php echo e(mb_substr($m['message'], 0, 150)); ?><?php echo mb_strlen($m['message']) > 150 ? '…' : ''; ?></div>
                    </div>
                    <?php if (!$is_viewer): ?>
                    <div class="acts">
                        <a href="?view=<?php echo e($m['uid']); ?>" class="btn-sm" title="مشاهده">👁</a>
                        <form method="post" style="display:inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="<?php echo $m['is_read'] ? 'mark_unread' : 'mark_read'; ?>">
                            <input type="hidden" name="message_id" value="<?php echo e($m['uid']); ?>">
                            <button type="submit" class="btn-sm" title="<?php echo $m['is_read'] ? 'خوانده‌نشده' : 'خوانده‌شده'; ?>">
                                <?php echo $m['is_read'] ? '📭' : '📬'; ?>
                            </button>
                        </form>
                        <form method="post" style="display:inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="star">
                            <input type="hidden" name="message_id" value="<?php echo e($m['uid']); ?>">
                            <button type="submit" class="btn-sm" title="نشان">⭐</button>
                        </form>
                        <form method="post" style="display:inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_message">
                            <input type="hidden" name="message_id" value="<?php echo e($m['uid']); ?>">
                            <button type="submit" class="btn-sm" style="background:#eb3349" title="حذف">🗑</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ============ زباله‌دان ============ -->
            <h3 style="margin:30px 0 12px">🗑 زباله‌دان (<?php echo fa_number(count($trash_messages)); ?>)</h3>
            <?php if (!$trash_messages): ?>
                <p style="color:#7c8aa0">زباله‌دان خالی است.</p>
            <?php else: ?>
            <?php if (!$is_viewer && has_permission('admin')): ?>
            <form method="post" style="margin-bottom:12px" onsubmit="return confirm('همه پیام‌های زباله‌دان برای همیشه حذف شوند؟')">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="empty_trash">
                <button type="submit" class="btn-danger">🗑 خالی کردن زباله‌دان</button>
            </form>
            <?php endif; ?>
            <div class="msg-list">
                <?php foreach ($trash_messages as $m): ?>
                <div class="msg-item" style="opacity:.75">
                    <div style="flex:1;min-width:0">
                        <div class="who"><?php echo e($m['name'] ?: 'ناشناس'); ?></div>
                        <div class="subj"><?php echo e($m['subject'] ?: '(بدون موضوع)'); ?></div>
                        <div class="prev"><?php echo e(mb_substr($m['message'], 0, 120)); ?></div>
                        <div class="when" style="margin-top:4px"><?php echo e(jalali_datetime((string)$m['created_at'])); ?></div>
                    </div>
                    <?php if (!$is_viewer): ?>
                    <div class="acts">
                        <form method="post" style="display:inline">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="restore_message">
                            <input type="hidden" name="message_id" value="<?php echo e($m['uid']); ?>">
                            <button type="submit" class="btn-sm" title="بازیابی">♻️</button>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('برای همیشه حذف شود؟')">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="permanent_delete">
                            <input type="hidden" name="message_id" value="<?php echo e($m['uid']); ?>">
                            <button type="submit" class="btn-sm" style="background:#eb3349" title="حذف دائمی">❌</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
