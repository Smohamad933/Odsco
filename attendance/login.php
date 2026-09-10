<?php
/**
 * ورود به بخش حضور و غیاب — یکپارچه
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (is_logged_attendance()) {
    redirect('index.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (login_blocked()) {
        $error = '⏳ تلاش‌های بیش از حد. لطفاً ' . fa_number((int)ceil(login_retry_seconds()/60)) . ' دقیقه دیگر تلاش کنید.';
    } else {
        $res = odsco_unified_login($username, $password);
        if (!empty($res['success'])) {
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
<div style="display:inline-flex;gap:6px;padding:5px 10px;border-radius:20px;background:#e8f5e9;border:1px solid #c8e6c9;color:#2e7d32;font-size:10px;font-weight:800;margin:6px 0">✅ ورود یکپارچه</div>

<?php if($error!==''): ?><div class="at-alert err"><?php echo e($error); ?></div><?php endif; ?>

<form method="post">
<?php echo csrf_field(); ?>
<label>نام کاربری</label>
<input type="text" name="username" value="<?php echo e($username); ?>" autocomplete="username" required autofocus>
<label>رمز عبور</label>
<input type="password" name="password" autocomplete="current-password" required>
<button type="submit" class="at-btn" style="width:100%;margin-top:14px">ورود یکپارچه</button>
</form>

<div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:16px">
<a href="../login.php" class="at-btn small" style="text-decoration:none;background:#11998e">🔐 ورود اصلی</a>
<a href="../" class="at-btn small ghost" style="text-decoration:none">↩️ سایت</a>
<a href="../messenger/login.php" class="at-btn small ghost" style="text-decoration:none">💬 پیام‌رسان</a>
</div>
</div>
</div>
</body>
</html>
