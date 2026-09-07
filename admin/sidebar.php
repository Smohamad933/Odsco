<?php
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);

// تعداد پیام‌های خوانده نشده
$sidebar_unread_messages = 0;
if (function_exists('get_messages')) {
    $sidebar_unread_messages = count(get_messages(true));
}
?>
<aside class="sidebar">
    <!-- هدر سایدبار -->
    <div class="sidebar-header">
        <h2>🏗️ پنل مدیریت</h2>
        <p>افق دانش ثریا</p>
    </div>
    
    <!-- منوی ناوبری -->
    <nav class="sidebar-nav">
        <div class="nav-section-title">منوی اصلی</div>
        
        <a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
            <span class="nav-icon">📊</span>
            <span class="nav-text">داشبورد</span>
        </a>
        
        <a href="manage-projects.php" class="<?php echo $current_page == 'manage-projects.php' ? 'active' : ''; ?>">
            <span class="nav-icon">🏗️</span>
            <span class="nav-text">پروژه‌ها</span>
        </a>
        
        <a href="manage-categories.php" class="<?php echo $current_page == 'manage-categories.php' ? 'active' : ''; ?>">
            <span class="nav-icon">🏷️</span>
            <span class="nav-text">دسته‌بندی‌ها</span>
        </a>
        <a href="manage-clients.php" class="<?php echo $current_page == 'manage-clients.php' ? 'active' : ''; ?>">
            <span class="nav-icon">🏢</span>
            <span class="nav-text">کارفرمایان</span>
        </a>
        <a href="manage-team.php" class="<?php echo $current_page == 'manage-team.php' ? 'active' : ''; ?>">
            <span class="nav-icon">👥</span>
            <span class="nav-text">اعضای تیم</span>
        </a>
        
        <a href="manage-blog.php" class="<?php echo $current_page == 'manage-blog.php' ? 'active' : ''; ?>">
            <span class="nav-icon">📝</span>
            <span class="nav-text">مقالات</span>
        </a>
        
        <a href="media-library.php" class="<?php echo $current_page == 'media-library.php' ? 'active' : ''; ?>">
            <span class="nav-icon">🖼️</span>
            <span class="nav-text">کتابخانه رسانه</span>
        </a>
        
        <a href="manage-messages.php" class="<?php echo $current_page == 'manage-messages.php' ? 'active' : ''; ?>">
            <span class="nav-icon">📨</span>
            <span class="nav-text">پیام‌ها</span>
            <?php if ($sidebar_unread_messages > 0): ?>
                <span class="nav-badge"><?php echo $sidebar_unread_messages; ?></span>
            <?php endif; ?>
        </a>
        
        <?php if (has_permission('admin')): ?>
            <div class="nav-section-title">مدیریت سیستم</div>
            
            <a href="manage-users.php" class="<?php echo $current_page == 'manage-users.php' ? 'active' : ''; ?>">
                <span class="nav-icon">👤</span>
                <span class="nav-text">کاربران</span>
            </a>
            
            <a href="logs.php" class="<?php echo $current_page == 'logs.php' ? 'active' : ''; ?>">
                <span class="nav-icon">📋</span>
                <span class="nav-text">لاگ سیستم</span>
            </a>
            
            <a href="settings.php" class="<?php echo $current_page == 'settings.php' ? 'active' : ''; ?>">
                <span class="nav-icon">⚙️</span>
                <span class="nav-text">تنظیمات</span>
            </a>
        <?php endif; ?>
    </nav>
    
    <!-- فوتر سایدبار -->
    <div class="sidebar-footer">
        <a href="../index.php" target="_blank" class="btn-view-site">
            🌐 مشاهده سایت
        </a>
        <a href="logout.php" class="btn-logout">
            🚪 خروج از حساب
        </a>
    </div>
</aside>