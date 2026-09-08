<?php
/**
 * ============================================================================
 *  Odsco — ورود به پنل مدیریت
 * ----------------------------------------------------------------------------
 *  نکته مهم: login_user() آرایه برمی‌گرداند نه bool؛ باید ['success'] بررسی شود.
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (is_logged_admin()) {
    redirect('dashboard.php');
}

$error    = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (login_blocked()) {
        $error = '⏳ تلاش‌های بیش از حد. لطفاً ' . fa_number((int)ceil(login_retry_seconds() / 60)) . ' دقیقه دیگر تلاش کنید.';
    } else {
        $res = authenticate($username, $password, 'admin');
        if (!empty($res['success'])) {
            login_reset_throttle();
            ActivityLog::add('login', 'ورود به پنل مدیریت', $username);
            redirect('dashboard.php');
        }
        $error = (string)($res['message'] ?? 'نام کاربری یا رمز عبور اشتباه است');
    }
}

$settings = Settings::all();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title>ورود به پنل مدیریت | <?php echo e($settings['site_name'] ?? ''); ?></title>
    <link rel="stylesheet" href="admin-style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box">
            <div class="login-header">
                <div class="login-logo">🔐</div>
                <h1>ورود به پنل مدیریت</h1>
                <p class="login-sub"><?php echo e($settings['site_name'] ?? 'افق دانش ثریا'); ?></p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="on">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="username">نام کاربری</label>
                    <input type="text" id="username" name="username" value="<?php echo e($username); ?>"
                           autocomplete="username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">رمز عبور</label>
                    <input type="password" id="password" name="password"
                           autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">ورود</button>
            </form>

            <p class="login-back"><a href="../">↩️ بازگشت به سایت</a></p>
        </div>
    </div>
</body>
</html>
