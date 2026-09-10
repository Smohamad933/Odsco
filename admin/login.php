<?php
/**
 * ورود به پنل مدیریت — یکپارچه (SSO)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (is_logged_admin()) {
    redirect('dashboard.php');
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
<div style="display:inline-flex;gap:6px;padding:6px 12px;border-radius:20px;background:#e8f5e9;border:1px solid #c8e6c9;color:#2e7d32;font-size:11px;font-weight:800;margin-top:8px">✅ ورود یکپارچه — یک حساب برای همه</div>
</div>

<?php if($error!==''): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>

<form method="post" autocomplete="on">
<?php echo csrf_field(); ?>
<div class="form-group"><label>نام کاربری</label><input type="text" name="username" value="<?php echo e($username); ?>" autocomplete="username" required autofocus></div>
<div class="form-group"><label>رمز عبور</label><input type="password" name="password" autocomplete="current-password" required></div>
<button type="submit" class="btn btn-primary btn-block">ورود یکپارچه</button>
</form>

<div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;justify-content:center">
<a href="../login.php" style="padding:8px 14px;background:#f5f5f5;border:1px solid #e5e5e5;border-radius:10px;text-decoration:none;font-size:12px;font-weight:800;color:#333">🔐 ورود یکپارچه اصلی</a>
<a href="../messenger/login.php" style="padding:8px 14px;background:#f5f5f5;border:1px solid #e5e5e5;border-radius:10px;text-decoration:none;font-size:12px;color:#555">💬 پیام‌رسان</a>
<a href="../client/login.php" style="padding:8px 14px;background:#f5f5f5;border:1px solid #e5e5e5;border-radius:10px;text-decoration:none;font-size:12px;color:#555">🏢 کارفرما</a>
</div>

<p class="login-back"><a href="../">↩️ بازگشت به سایت</a></p>
</div>
</div>
</body>
</html>
