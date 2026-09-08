<?php
/**
 * ============================================================================
 *  Odsco — سایدبار پنل مدیریت (ریسپانسیو)
 * ============================================================================
 */

declare(strict_types=1);

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);

$sidebar_unread_messages = 0;
if (function_exists('get_messages')) {
    $sidebar_unread_messages = count(get_messages(true));
}
$sidebar_unread_requests = 0;
$sidebar_alerts = 0;
if (Db::ready()) {
    $sidebar_unread_requests = count(Attendance::requests('pending'));
    $sidebar_alerts = count(array_filter(Automation::rules(), fn($r) => !empty($r["is_active"])));
}

$_adm_link = function (string $href, string $icon, string $label, int $badge = 0, string $cls = '') use ($current_page): string {
    $on = $current_page === basename($href);
    $b  = $badge > 0 ? '<span class="nav-badge">' . fa_number($badge) . '</span>' : '';
    return '<a href="' . e($href) . '" class="' . ($on ? 'active ' : '') . $cls . '">'
        . '<span class="nav-icon">' . $icon . '</span>'
        . '<span class="nav-text">' . e($label) . '</span>' . $b . '</a>';
};
?>
<button class="sidebar-toggle" id="sidebarToggle" aria-label="منو">☰</button>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<aside class="sidebar" id="adminSidebar">
    <div class="sidebar-header">
        <h2>🏗️ پنل مدیریت</h2>
        <p><?php echo e((string)Settings::get('site_name', 'پنل مدیریت')); ?></p>
        <button class="sidebar-close" id="sidebarClose" aria-label="بستن">✕</button>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-title">منوی اصلی</div>

        <?php echo $_adm_link('dashboard.php', '📊', 'داشبورد'); ?>

        <div class="nav-section-title">مدیریت پروژه</div>
        <?php echo $_adm_link('manage-projects.php', '🏗️', 'پروژه‌ها'); ?>
        <?php echo $_adm_link('workspace.php', '📋', 'میز کار پروژه‌ها'); ?>
        <?php echo $_adm_link('manage-categories.php', '🏷️', 'دسته‌بندی‌ها'); ?>
        <?php echo $_adm_link('manage-clients.php', '🏢', 'کارفرمایان'); ?>

        <div class="nav-section-title">نیروی انسانی</div>
        <?php echo $_adm_link('attendance.php', '🕐', 'حضور و غیاب', $sidebar_unread_requests); ?>
        <?php echo $_adm_link('manage-team.php', '👥', 'اعضای تیم'); ?>

        <div class="nav-section-title">ارتباطات</div>
        <?php echo $_adm_link('automation.php', '🤖', 'خودکارسازی', $sidebar_alerts); ?>
        <?php echo $_adm_link('broadcast.php', '📣', 'ارسال اطلاعیه'); ?>
        <?php echo $_adm_link('manage-messages.php', '📨', 'پیام‌های تماس', $sidebar_unread_messages); ?>
        <?php echo $_adm_link('manage-blog.php', '📝', 'مقالات'); ?>
        <?php echo $_adm_link('media-library.php', '🖼️', 'کتابخانه رسانه'); ?>

        <?php if (has_permission('admin')): ?>
        <div class="nav-section-title">مدیریت سیستم</div>
        <?php echo $_adm_link('manage-users.php', '👤', 'کاربران'); ?>
        <?php echo $_adm_link('logs.php', '📋', 'لاگ سیستم'); ?>
        <?php echo $_adm_link('settings.php', '⚙️', 'تنظیمات'); ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <a href="../messenger/index.php" class="btn-view-site">💬 پیام‌رسان</a>
        <a href="../client/login.php" class="btn-view-site" style="margin-top:6px">🏢 پنل کارفرما</a>
        <a href="../index.php" target="_blank" class="btn-view-site" style="margin-top:6px">🌐 مشاهده سایت</a>
        <a href="logout.php" class="btn-logout">🚪 خروج از حساب</a>
    </div>
</aside>

<script>
(function () {
    var sb = document.getElementById('adminSidebar');
    var bd = document.getElementById('sidebarBackdrop');
    function close() { sb.classList.remove('open'); bd.classList.remove('open'); }
    document.getElementById('sidebarToggle').addEventListener('click', function () {
        sb.classList.toggle('open'); bd.classList.toggle('open', sb.classList.contains('open'));
    });
    document.getElementById('sidebarClose').addEventListener('click', close);
    bd.addEventListener('click', close);
})();
</script>
