<?php
/**
 * ورود یکپارچه Odsco — Single Sign-On
 * یک نام کاربری/رمز برای همه پنل‌ها:
 *  - ادمین/کارمند → admin/dashboard.php
 *  - پیام‌رسان → messenger/ (اگر فعال باشد)
 *  - کارفرما → client/ (اگر نقش client یا client_uid داشته باشد)
 *  - حضور و غیاب → attendance/ (اگر فعال باشد)
 *
 * بعد از ورود، نشست‌ها برای همه نواحی مجاز ساخته می‌شود.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/logger.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// اگر قبلاً لاگین بود، بر اساس نقش ریدایرکت کن
if (is_logged_admin() || is_logged_messenger() || is_logged_client() || is_logged_attendance()) {
    $role = (string)($_SESSION['admin_role'] ?? $_SESSION['messenger_user_role'] ?? 'viewer');
    if (Users::level($role) >= Users::level('viewer') && isset($_SESSION['admin_id'])) {
        redirect('admin/dashboard.php');
    } elseif (isset($_SESSION['client_user_id'])) {
        redirect('client/index.php');
    } elseif (isset($_SESSION['messenger_user_id'])) {
        redirect('messenger/index.php');
    } elseif (isset($_SESSION['attendance_user_id'])) {
        redirect('attendance/index.php');
    }
}

$error = '';
$username = '';
$redirect = (string)($_GET['redirect'] ?? $_POST['redirect'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (login_blocked()) {
        $error = '⏳ تلاش‌های بیش از حد. لطفاً ' . fa_number((int)ceil(login_retry_seconds()/60)) . ' دقیقه دیگر تلاش کنید.';
    } else {
        $user = Users::findByUsername($username);
        if (!$user || !password_verify($password, (string)$user['password'])) {
            odsco_throttle_login();
            ActivityLog::add('login_failed', "تلاش ناموفق ورود یکپارچه با نام کاربری {$username}", $username);
            $error = 'نام کاربری یا رمز عبور اشتباه است';
        } elseif (empty($user['is_active'])) {
            $error = '⛔ حساب شما غیرفعال است. با مدیر تماس بگیرید.';
        } else {
            // مهاجرت هش
            if (password_needs_rehash((string)$user['password'], PASSWORD_DEFAULT)) {
                Users::setPassword($user['uid'], $password);
            }
            if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
            Users::touchLogin($user['uid']);
            login_reset_throttle();

            $device = function_exists('detect_device') ? detect_device() : 'web';

            // نشست یکپارچه — برای هر ناحیه که مجاز است
            $isInternal = Users::level((string)$user['role']) >= Users::level('viewer') || $user['role'] !== 'client';
            $isClient = $user['role'] === 'client' || !empty($user['client_uid']);

            // ادمین پنل (کارمندان و مدیران)
            if ($isInternal) {
                $_SESSION['admin_id'] = $user['uid'];
                $_SESSION['admin_user'] = $user['username'];
                $_SESSION['admin_role'] = $user['role'];
                $_SESSION['admin_name'] = $user['full_name'];
                $_SESSION['admin_photo'] = $user['photo'];
                $_SESSION['admin_client'] = $user['client_uid'];
            }

            // پیام‌رسان
            if (!empty($user['messenger_enabled'])) {
                $_SESSION['messenger_user_id'] = $user['uid'];
                $_SESSION['messenger_user_name'] = $user['full_name'];
                $_SESSION['messenger_user_role'] = $user['role'];
                $_SESSION['messenger_user_photo'] = $user['photo'];
                $_SESSION['messenger_user_client'] = $user['client_uid'];
                if (class_exists('Messenger')) {
                    try { Messenger::registerDevice($user['uid'], $device); Messenger::touchPresence($user['uid']); } catch (Throwable $e) {}
                }
            }

            // کارفرما
            if ($isClient) {
                $_SESSION['client_user_id'] = $user['uid'];
                $_SESSION['client_user_name'] = $user['full_name'];
                $_SESSION['client_uid'] = $user['client_uid'];
                $_SESSION['client_user_photo'] = $user['photo'];
            }

            // حضور و غیاب
            if (!empty($user['attendance_enabled']) || $isInternal) {
                $_SESSION['attendance_user_id'] = $user['uid'];
                $_SESSION['attendance_user_name'] = $user['full_name'];
                $_SESSION['attendance_user_role'] = $user['role'];
                $_SESSION['attendance_user_photo'] = $user['photo'];
            }

            ActivityLog::add('unified_login', "ورود یکپارچه کاربر {$username}", $username);

            // ریدایرکت هوشمند
            if ($redirect !== '' && str_starts_with($redirect, '/') === false && str_contains($redirect, '://') === false) {
                redirect($redirect);
            }

            if ($isInternal && Users::level((string)$user['role']) >= Users::level('viewer')) {
                redirect('admin/dashboard.php');
            } elseif ($isClient) {
                redirect('client/index.php');
            } elseif (!empty($user['messenger_enabled'])) {
                redirect('messenger/index.php');
            } elseif (!empty($user['attendance_enabled'])) {
                redirect('attendance/index.php');
            } else {
                redirect('admin/dashboard.php');
            }
        }
    }
}

$settings = Settings::all();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ورود یکپارچه | <?php echo e($settings['site_name'] ?? 'Odsco'); ?></title>
<link rel="stylesheet" href="admin/admin-style.css">
<style>
.login-page{min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0f0f0f,#2a2a2a);padding:20px}
.login-container{width:100%;max-width:440px}
.login-box{background:#fff;border-radius:22px;padding:32px 28px;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.login-header{text-align:center;margin-bottom:22px}
.login-logo{width:64px;height:64px;border-radius:18px;background:#1a1a1a;color:#fff;display:flex;align-items:center;justify-content:center;font-size:30px;margin:0 auto 12px}
.login-header h1{font-size:20px;font-weight:900;margin:0 0 6px}
.login-sub{font-size:12px;color:#888}
.form-group{margin-bottom:14px;display:flex;flex-direction:column;gap:6px}
.form-group label{font-size:12px;font-weight:800;color:#444}
.form-group input{padding:12px 14px;border:1px solid #e0e0e0;border-radius:12px;font-family:inherit;font-size:14px;background:#fafafa;transition:.2s}
.form-group input:focus{outline:none;border-color:#1a1a1a;background:#fff;box-shadow:0 0 0 3px rgba(0,0,0,.06)}
.btn{padding:12px 18px;border-radius:12px;border:none;font-family:inherit;font-size:14px;font-weight:900;cursor:pointer;transition:.2s;width:100%}
.btn-primary{background:#1a1a1a;color:#fff}
.btn-primary:hover{background:#000}
.alert{padding:12px 14px;border-radius:12px;font-size:13px;font-weight:700;margin-bottom:14px}
.alert-error{background:#ffebee;border:1px solid #ffcdd2;color:#c62828}
.panels{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:18px}
.panel-link{padding:12px;border:1px solid #eee;border-radius:12px;text-align:center;text-decoration:none;color:#333;font-size:12px;font-weight:800;transition:.2s;background:#fafafa}
.panel-link:hover{background:#1a1a1a;color:#fff;border-color:#1a1a1a}
.unified-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:20px;background:#e8f5e9;border:1px solid #c8e6c9;color:#2e7d32;font-size:11px;font-weight:800;margin-bottom:12px}
</style>
</head>
<body class="login-page">
<div class="login-container">
<div class="login-box">
<div class="login-header">
<div class="login-logo">🔐</div>
<h1>ورود یکپارچه Odsco</h1>
<p class="login-sub"><?php echo e($settings['site_name'] ?? 'افق دانش ثریا'); ?> — یک حساب برای همه پنل‌ها</p>
<div class="unified-badge">✅ SSO — پیام‌رسان + حضور و غیاب + کارفرما + مدیریت</div>
</div>

<?php if($error!==''): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>

<form method="post" autocomplete="on">
<?php echo csrf_field(); ?>
<input type="hidden" name="redirect" value="<?php echo e($redirect); ?>">
<div class="form-group"><label>نام کاربری</label><input type="text" name="username" value="<?php echo e($username); ?>" autocomplete="username" required autofocus></div>
<div class="form-group"><label>رمز عبور</label><input type="password" name="password" autocomplete="current-password" required></div>
<button type="submit" class="btn btn-primary">ورود به همه پنل‌ها</button>
</form>

<div class="panels">
<a href="admin/login.php" class="panel-link">🏗️ مدیریت</a>
<a href="messenger/login.php" class="panel-link">💬 پیام‌رسان</a>
<a href="client/login.php" class="panel-link">🏢 کارفرما</a>
<a href="attendance/" class="panel-link">🕐 حضور و غیاب</a>
</div>

<p style="text-align:center;margin-top:16px;font-size:11px;color:#999">با یک بار ورود، به همه پنل‌های مجاز دسترسی دارید — نشست‌ها به صورت خودکار ساخته می‌شود.</p>
<p style="text-align:center;margin-top:10px"><a href="index.php" style="font-size:12px;color:#666;text-decoration:none">↩️ بازگشت به سایت</a></p>
</div>
</div>
</body>
</html>
