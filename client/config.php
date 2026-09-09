<?php
/**
 * ============================================================================
 *  Odsco — پنل کارفرما: بوت‌استرپ و هلپرهای نما
 * ============================================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/logger.php';
require_once dirname(__DIR__) . '/includes/automation.php';

/** محافظت از صفحات پنل کارفرما */
function c_check_login(): void
{
    if (!is_logged_client()) redirect('login.php');
    if (login_blocked()) {
        http_response_code(429);
        exit('⏳ تلاش‌های بیش از حد. لطفاً چند دقیقه بعد دوباره تلاش کنید.');
    }
}

/** کاربر کارفرمای فعلی */
function c_current_user(): array
{
    $uid = current_client_uid();
    $u = $uid !== '' ? Users::find($uid) : null;
    if (!$u) redirect('login.php');
    return $u;
}

/** شناسه شرکت کارفرمای فعلی (برای مدیران خالی است — یعنی «همه») */
function c_client_uid(): string
{
    return (string)(c_current_user()['client_uid'] ?? '');
}

/**
 * آیا کاربرِ فعلی مدیر/ادمین است (نه خودِ کارفرما)؟
 * مدیران هم به پورتال کارفرما دسترسی دارند تا بتوانند همان نمای
 * گزارش‌دهی را که کارفرما می‌بیند بررسی کنند — ولی برای همهٔ شرکت‌ها.
 */
function c_is_manager(): bool
{
    return Users::level((string)(c_current_user()['role'] ?? '')) >= Users::level('manager');
}

/**
 * پروژه‌هایی که این کاربر در پورتال کارفرما می‌بیند.
 *  • کارفرما → فقط پروژه‌های همان شرکت
 *  • مدیر    → همهٔ پروژه‌های قابل‌نمایش برای کارفرما
 */
function c_projects(): array
{
    if (c_is_manager()) {
        return Projects::list(['client_visible' => true]);
    }
    $uid = c_client_uid();
    return $uid !== '' ? Projects::list(['client_uid' => $uid, 'client_visible' => true]) : [];
}

/** آیا این کاربر اجازهٔ دیدن این پروژه را دارد؟ */
function c_can_view_project(?array $p): bool
{
    if (!$p || empty($p['client_visible'])) return false;
    if (c_is_manager()) return true;
    return (string)$p['client_uid'] === c_client_uid() && c_client_uid() !== '';
}

/** سرصفحه مشترک */
function c_head(string $title, string $active = ''): void
{
    $me = c_current_user();
    $client = c_client_uid() !== '' ? Clients::find(c_client_uid()) : null;
    $unread = Notifications::unreadCount($me['uid']);
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1a56b0">
<title><?php echo e($title); ?> | پنل کارفرما</title>
<link rel="stylesheet" href="assets/client.css">
<?php if (!empty($settings['favicon'])): ?>
<link rel="icon" href="../<?php echo e(ltrim((string)$settings['favicon'], '/')); ?>">
<?php endif; ?>
</head>
<body>
<header class="cl-topbar">
    <a href="index.php" class="brand"><span class="ico">🏢</span><span>پنل کارفرما</span></a>
    <nav>
        <a href="index.php" class="<?php echo $active === 'home' ? 'on' : ''; ?>">🏠 داشبورد</a>
        <a href="projects.php" class="<?php echo $active === 'projects' ? 'on' : ''; ?>">🏗️ پروژه‌ها</a>
        <a href="reports.php" class="<?php echo $active === 'reports' ? 'on' : ''; ?>">📄 گزارش‌ها</a>
        <a href="notifications.php" class="<?php echo $active === 'notices' ? 'on' : ''; ?>">
            🔔 اعلان‌ها<?php if ($unread > 0): ?> <span class="pill danger"><?php echo fa_number($unread); ?></span><?php endif; ?>
        </a>
    </nav>
    <div class="cl-user">
        <?php if (c_is_manager()): ?>
            <span class="pill info" title="شما مدیر هستید و پروژهٔ همهٔ کارفرماها را می‌بینید">👔 نمای مدیر — همهٔ کارفرماها</span>
        <?php endif; ?>
        <span class="nm"><?php echo e($client['name'] ?? $me['full_name']); ?></span>
        <span class="av"><?php echo e(mb_substr($me['full_name'], 0, 1)); ?></span>
        <a href="logout.php" class="cl-btn ghost sm" title="خروج">خروج</a>
    </div>
</header>
<main class="cl-wrap">
<?php
}

/** پانوشت مشترک */
function c_foot(): void
{
    ?>
</main>
<footer style="text-align:center;padding:20px;font-size:11.5px;color:#98a2b3">
    © <?php echo fa_number((int)date('Y')); ?> <?php echo e((string)Settings::get('site_name', '')); ?> — پنل کارفرما
</footer>
</body>
</html>
<?php
}

/** نوار وضعیت پروژه */
function c_progress_bar(int $percent, string $extraClass = ''): string
{
    $p = max(0, min(100, $percent));
    $cls = $p >= 100 ? 'cl-bar ok ' . $extraClass : 'cl-bar ' . $extraClass;
    return '<div class="' . trim($cls) . '"><span style="width:' . $p . '%"></span></div>';
}

/** برچسب وضعیت پروژه */
function c_status_pill(string $status): string
{
    return match ($status) {
        'done'      => '<span class="pill ok">✅ تکمیل‌شده</span>',
        'paused'    => '<span class="pill warn">⏸ متوقف</span>',
        'cancelled' => '<span class="pill danger">⛔ لغوشده</span>',
        default     => '<span class="pill info">🔨 در حال اجرا</span>',
    };
}
