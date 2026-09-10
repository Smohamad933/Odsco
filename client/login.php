<?php
/**
 * پنل کارفرما: ورود — یکپارچه
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
        $error = '⏳ تلاش‌های بیش از حد. لطفاً ' . fa_number((int)ceil(login_retry_seconds()/60)) . ' دقیقه دیگر تلاش کنید.';
    } else {
        $res = odsco_unified_login($username, $password);
        if (!empty($res['success'])) {
            $u = $res['user'];
            $isClient = $u['role']==='client' || !empty($u['client_uid']) || Users::level((string)$u['role'])>=Users::level('manager');
            if (!$isClient) {
                $error = '⛔ این حساب مخصوص کارفرما نیست';
            } else {
                redirect('index.php');
            }
        } else {
            $error = $res['message'] ?? 'ورود ناموفق بود';
        }
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
<div style="display:inline-flex;gap:6px;padding:5px 10px;border-radius:20px;background:#e8f4fd;border:1px solid #c5d9ff;color:#1a56b0;font-size:10px;font-weight:800;margin:6px 0">✅ ورود یکپارچه</div>

<?php if($error): ?><div class="cl-alert err"><?php echo e($error); ?></div><?php endif; ?>

<form method="post">
<?php echo csrf_field(); ?>
<div class="cl-field"><label>نام کاربری</label><input type="text" name="username" value="<?php echo e($username); ?>" required autocomplete="username" autofocus></div>
<div class="cl-field"><label>رمز عبور</label><input type="password" name="password" required autocomplete="current-password"></div>
<button type="submit" class="cl-btn block">ورود یکپارچه</button>
</form>

<p class="cl-hint">با یک حساب به همه پنل‌ها (پیام‌رسان، حضور و غیاب، مدیریت) دسترسی دارید — بر اساس دسترسی‌تان.</p>

<div class="cl-links" style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:14px">
<a href="../login.php" style="padding:8px 14px;background:#5288c1;color:#fff;border-radius:10px;text-decoration:none;font-size:12px;font-weight:800">🔐 ورود یکپارچه اصلی</a>
<a href="../index.php">🌐 سایت</a>
<a href="../messenger/login.php">💬 پیام‌رسان</a>
</div>
</div>
</div>
</body>
</html>
