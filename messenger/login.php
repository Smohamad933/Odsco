<?php
/**
 * ورود پیام‌رسان — یکپارچه
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (is_logged_messenger()) {
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
            if (empty($u['messenger_enabled']) && Users::level((string)$u['role']) < Users::level('manager')) {
                $error = '⛔ دسترسی پیام‌رسان برای شما فعال نیست';
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
<div style="display:inline-flex;gap:6px;padding:5px 10px;border-radius:20px;background:rgba(77,205,94,.14);border:1px solid rgba(77,205,94,.3);color:#4dcd5e;font-size:10px;font-weight:800;margin:8px 0">✅ ورود یکپارچه</div>

<?php if($error): ?><div class="tg-alert err"><?php echo e($error); ?></div><?php endif; ?>

<form method="post">
<?php echo csrf_field(); ?>
<div class="tg-field"><label>نام کاربری</label><input type="text" name="username" value="<?php echo e($username); ?>" required autocomplete="username" autofocus></div>
<div class="tg-field"><label>رمز عبور</label><input type="password" name="password" required autocomplete="current-password"></div>
<button type="submit" class="tg-btn block" style="padding:13px">ورود یکپارچه</button>
</form>

<div style="margin-top:18px;font-size:11.5px;color:var(--tg-muted);line-height:2">🔒 تاریخچه روی <b>همین دستگاه</b> ذخیره می‌شود. با یک بار ورود به همه پنل‌ها دسترسی دارید.</div>

<div style="display:flex;gap:8px;margin-top:16px;justify-content:center;flex-wrap:wrap">
<a href="../login.php" class="tg-btn" style="padding:9px 16px;font-size:12.5px;background:#5288c1">🔐 ورود یکپارچه اصلی</a>
<a href="../index.php" class="tg-btn ghost" style="padding:9px 16px;font-size:12.5px">🌐 سایت</a>
<a href="../admin/login.php" class="tg-btn ghost" style="padding:9px 16px;font-size:12.5px">🏗️ مدیریت</a>
</div>
</div>
</div>
</body>
</html>
