<?php
require_once '../includes/auth.php';
if (isset($_SESSION['admin_id'])) { header('Location: dashboard.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (login_user($_POST['username'], $_POST['password'])) {
        header('Location: dashboard.php'); exit;
    } else { $error = 'نام کاربری یا رمز عبور اشتباه است'; }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به پنل مدیریت</title>
    <link rel="stylesheet" href="admin-style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box">
            <h1 style="color: white; margin-bottom : 40px;">🔐 ورود به پنل مدیریت</h1>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label style="color: white;">نام کاربری</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label style="color: white;" >رمز عبور</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">ورود</button>
            </form>
        </div>
    </div>
</body>
</html>
