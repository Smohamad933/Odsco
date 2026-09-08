<?php
/**
 * ============================================================================
 *  Odsco Messenger — ورود
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (is_logged_messenger()) {
    redirect('index.php');
}

$error = '';
$ok = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (login_blocked()) {
        $error = '⏳ تلاش‌های بیش از حد. لطفاً ' . fa_number((int)ceil(login_retry_seconds() / 60)) . ' دقیقه دیگر تلاش کنید.';
    } else {
        $res = authenticate($username, $password, 'messenger');
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
<meta name="theme-color" content="#0e1621">
<title>ورود به پیام‌رسان</title>
<link rel="stylesheet" href="assets/messenger.css">
</head>
<body>
<div class="tg-login">
    <div class="tg-login-box">
        <div class="tg-login-logo">💬</div>
        <h1>پیام‌رسان</h1>
        <p class="sub"><?php echo e($settings['site_name'] ?? 'افق دانش ثریا'); ?></p>

        <?php if ($error): ?><div class="tg-alert err"><?php echo e($error); ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="tg-alert ok"><?php echo e($ok); ?></div><?php endif; ?>

        <form method="post">
            <?php echo csrf_field(); ?>
            <div class="tg-field">
                <label>نام کاربری</label>
                <input type="text" name="username" value="<?php echo e($username); ?>" required autocomplete="username" autofocus>
            </div>
            <div class="tg-field">
                <label>رمز عبور</label>
                <input type="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="tg-btn block" style="padding:13px">ورود</button>
        </form>

        <div style="margin-top:22px;font-size:11.5px;color:var(--tg-muted);line-height:2">
            🔒 تاریخچه پیام‌ها و فایل‌ها روی <b>همین دستگاه</b> ذخیره می‌شود.
        </div>

        <div style="display:flex;gap:8px;margin-top:16px;justify-content:center">
            <a href="../index.php" class="tg-btn ghost" style="padding:9px 16px;font-size:12.5px">🌐 سایت</a>
            <a href="../admin/login.php" class="tg-btn ghost" style="padding:9px 16px;font-size:12.5px">🏗️ پنل مدیریت</a>
            <a href="../client/login.php" class="tg-btn ghost" style="padding:9px 16px;font-size:12.5px">🏢 پنل کارفرما</a>
        </div>
    </div>
</div>
</body>
</html>
