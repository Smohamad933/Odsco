<?php
/**
 * ============================================================================
 *  Odsco — پنل کارفرما: اعلان‌ها
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

c_check_login();

$me = c_current_user();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'read') {
        Notifications::markRead((string)($_POST['uid'] ?? ''), $me['uid']);
    } elseif ($act === 'read_all') {
        Notifications::markAllRead($me['uid']);
    } elseif ($act === 'delete') {
        Notifications::delete((string)($_POST['uid'] ?? ''), $me['uid']);
        $msg = '🗑 اعلان حذف شد';
    }
    redirect('notifications.php');
}

$notices = Notifications::forUser($me['uid'], false, 200);
$unread  = Notifications::unreadCount($me['uid']);

c_head('اعلان‌ها', 'notices');
?>
<div class="cl-page-head">
    <div>
        <h1>🔔 اعلان‌ها</h1>
        <p><?php echo fa_number($unread); ?> خوانده‌نشده از <?php echo fa_number(count($notices)); ?></p>
    </div>
    <?php if ($unread > 0): ?>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="act" value="read_all">
        <button type="submit" class="cl-btn ghost">خواندن همه</button>
    </form>
    <?php endif; ?>
</div>

<?php if ($msg): ?><div class="cl-alert ok"><?php echo e($msg); ?></div><?php endif; ?>

<?php if (!$notices): ?>
<div class="cl-card">
    <div class="cl-empty">
        <div class="big">🔕</div>
        <h2>اعلانی ندارید</h2>
        <p>هشدارها و گزارش‌های پیشرفت پروژه‌ها اینجا نمایش داده می‌شوند.</p>
    </div>
</div>
<?php else: ?>
<div class="cl-card">
    <?php foreach ($notices as $n): ?>
    <div class="cl-notice<?php echo $n['is_read'] ? '' : ' unread'; ?>">
        <span class="ico"><?php echo e($n['icon'] ?: '🔔'); ?></span>
        <div style="flex:1;min-width:0">
            <div class="n-title"><?php echo e($n['title']); ?></div>
            <?php if (!empty($n['body'])): ?><div class="n-body"><?php echo nl2br(e($n['body'])); ?></div><?php endif; ?>
            <div class="n-time"><?php echo e(jalali_datetime((string)$n['created_at'])); ?> · <?php echo e(time_ago_fa((string)$n['created_at'])); ?></div>
            <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap">
                <?php if (!empty($n['link'])): ?>
                    <a href="<?php echo e($n['link']); ?>" class="cl-btn sm">مشاهده</a>
                <?php endif; ?>
                <?php if (!$n['is_read']): ?>
                <form method="post" style="display:inline">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="act" value="read">
                    <input type="hidden" name="uid" value="<?php echo e($n['uid']); ?>">
                    <button type="submit" class="cl-btn ghost sm">خوانده‌شده</button>
                </form>
                <?php endif; ?>
                <form method="post" style="display:inline" onsubmit="return confirm('این اعلان حذف شود؟')">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="uid" value="<?php echo e($n['uid']); ?>">
                    <button type="submit" class="cl-btn ghost sm" style="color:var(--cl-danger)">حذف</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php c_foot(); ?>
