<?php
/**
 * ============================================================================
 *  Odsco — بخش حضور و غیاب (صفحه مستقل کارکنان)
 * ----------------------------------------------------------------------------
 *  بوت‌استرپ: نشست، احراز هویت و توابع کمکی این بخش.
 *  این بخش از پیام‌رسان جداست تا کارکنانی که پیام‌رسان ندارند هم بتوانند
 *  ورود/خروج خود را ثبت کنند.
 * ============================================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';

function at_check_login(): void
{
    check_attendance_login();
}

/** کاربر فعلی این بخش */
function at_me(): array
{
    $uid = current_attendance_uid();
    $u = Users::find($uid);
    return [
        'uid'   => $uid,
        'name'  => (string)($u['full_name'] ?? 'کاربر'),
        'role'  => (string)($u['role'] ?? 'viewer'),
        'photo' => (string)($u['photo'] ?? ''),
        'job'   => (string)($u['job_title'] ?? ''),
    ];
}

function at_is_manager(): bool
{
    return Users::level(at_me()['role']) >= Users::level('manager');
}

/** ساعت و دقیقه فارسی */
function at_time(?string $t): string
{
    if (!$t) return '—';
    $t = (string)$t;
    return fa_number(substr($t, 0, 5));
}

function at_head(string $title, string $active = ''): void
{
    $me = at_me();
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0f2027">
<title><?php echo e($title); ?> | حضور و غیاب</title>
<link rel="stylesheet" href="assets/attendance.css">
</head>
<body>
<header class="at-top">
    <div class="at-top-in">
        <div class="at-brand">🕐 <b>حضور و غیاب</b> <span><?php echo e((string)Settings::get('site_name', '')); ?></span></div>
        <div class="at-user">
            <span class="at-who"><?php echo e($me['name']); ?></span>
            <span class="at-role"><?php echo e(Users::roleLabel($me['role'])); ?></span>
            <a class="at-out" href="logout.php">خروج</a>
        </div>
    </div>
    <nav class="at-nav">
        <a href="index.php" class="<?php echo $active === 'today' ? 'on' : ''; ?>">امروز</a>
        <a href="index.php?tab=requests" class="<?php echo $active === 'requests' ? 'on' : ''; ?>">درخواست‌های من</a>
        <a href="index.php?tab=month" class="<?php echo $active === 'month' ? 'on' : ''; ?>">گزارش ماه</a>
        <?php if (at_is_manager()): ?>
        <a href="index.php?tab=team" class="<?php echo $active === 'team' ? 'on' : ''; ?>">وضعیت تیم</a>
        <?php endif; ?>
    </nav>
</header>
<main class="at-main">
<?php
}

function at_foot(): void
{
    ?>
</main>
<footer class="at-foot">
    <span>⏱ ساعت سرور: <?php echo fa_number(date('H:i:s')); ?></span>
    <a href="../index.php">↩️ سایت</a>
    <a href="../admin/login.php">پنل مدیریت</a>
</footer>
</body>
</html>
<?php
}
