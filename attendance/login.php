<?php
/**
 * ============================================================================
 *  Odsco — ورود به بخش حضور و غیاب
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (is_logged_attendance()) {
    redirect('index.php');
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
        $res = authenticate($username, $password, 'attendance');
        if (!empty($res['success'])) {
            login_reset_throttle();
            redirect('index.php');
        }
        $error = (string)($res['message'] ?? 'ورود ناموفق بود');
    }
}

$settings = Settings::all();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0f2027">
<meta name="robots" content="noindex,nofollow">
<title>ورود | حضور و غیاب</title>
<link rel="stylesheet" href="assets/attendance.css">
</head>
<body class="at-login-page">
<div class="at-login">
    <div class="at-login-box">
        <div class="at-login-logo">🕐</div>
        <h1>حضور و غیاب</h1>
        <p class="at-sub"><?php echo e($settings['site_name'] ?? 'افق دانش ثریا'); ?></p>

        <?php if ($error !== ''): ?><div class="at-alert err"><?php echo e($error); ?></div><?php endif; ?>

        <form method="post">
            <?php echo csrf_field(); ?>
            <label for="username">نام کاربری</label>
            <input type="text" id="username" name="username" value="<?php echo e($username); ?>"
                   autocomplete="username" required autofocus>

            <label for="password">رمز عبور</label>
            <input type="password" id="password" name="password"
                   autocomplete="current-password" required>

            <button type="submit" class="at-btn">ورود</button>
        </form>

        <p class="at-login-foot">
            <a href="../">↩️ سایت</a>
            <a href="../messenger/login.php">💬 پیام‌رسان</a>
        </p>
    </div>
</div>
</body>
</html>
