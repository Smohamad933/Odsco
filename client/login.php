<?php
/**
 * ============================================================================
 *  Odsco — پنل کارفرما: ورود
 * ----------------------------------------------------------------------------
 *  کارفرمایان با حساب کاربری نقش «client» وارد می‌شوند و فقط پروژه‌های
 *  مرتبط با شرکت خودشان را می‌بینند.
 * ============================================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/logger.php';

if (is_logged_client()) {
    redirect('index.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (login_blocked()) {
        $error = '⏳ تلاش‌های بیش از حد. لطفاً ' . fa_number((int)ceil(login_retry_seconds() / 60)) . ' دقیقه دیگر تلاش کنید.';
    } else {
        $res = authenticate($username, $password, 'client');
        if ($res['success']) {
            login_reset_throttle();
            redirect('index.php');
        }
        $error = $res['message'] ?? 'ورود ناموفق بود';
    }
}

$settings = Settings::all();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#111a2e">
<title>ورود کارفرما | <?php echo e($settings['site_name'] ?? ''); ?></title>
<link rel="stylesheet" href="assets/client.css">
</head>
<body class="cl-login-page">
<div class="cl-login">
    <div class="cl-login-box">
        <div class="cl-logo">🏢</div>
        <h1>پنل کارفرما</h1>
        <p class="sub"><?php echo e($settings['site_name'] ?? 'افق دانش ثریا'); ?></p>

        <?php if ($error): ?><div class="cl-alert err"><?php echo e($error); ?></div><?php endif; ?>

        <form method="post">
            <?php echo csrf_field(); ?>
            <div class="cl-field">
                <label>نام کاربری</label>
                <input type="text" name="username" value="<?php echo e($username); ?>" required autocomplete="username" autofocus>
            </div>
            <div class="cl-field">
                <label>رمز عبور</label>
                <input type="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="cl-btn block">ورود به پنل</button>
        </form>

        <p class="cl-hint">
            در این پنل وضعیت پیشرفت پروژه‌ها، گزارش‌ها و هشدارهای تیم اجرایی را مشاهده می‌کنید.
        </p>

        <div class="cl-links">
            <a href="../index.php">🌐 سایت</a>
            <a href="../messenger/login.php">💬 پیام‌رسان</a>
            <a href="../admin/login.php">🏗️ پنل مدیریت</a>
        </div>
    </div>
</div>
</body>
</html>
