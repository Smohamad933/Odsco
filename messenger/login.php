<?php
require_once 'config.php';

// اگر قبلاً وارد شده
if (isset($_SESSION['messenger_user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'نام کاربری و رمز عبور را وارد کنید';
    } else {
        $users = read_json('users.json');
        $user_list = $users['users'] ?? [];
        
        $found = false;
        foreach ($user_list as $user) {
            if ($user['username'] === $username) {
                $found = true;
                
                if (!($user['is_active'] ?? true)) {
                    $error = 'حساب شما غیرفعال است';
                    break;
                }
                
                $role = $user['role'] ?? 'viewer';
                if (empty($user['messenger_enabled']) && $role !== 'admin' && $role !== 'manager') {
                    $error = 'حساب پیام‌رسان شما فعال نیست';
                    break;
                }
                
                if (password_verify($password, $user['password'])) {
                    $_SESSION['messenger_user_id'] = $user['id'];
                    $_SESSION['messenger_user_name'] = $user['full_name'] ?? $username;
                    $_SESSION['messenger_user_photo'] = $user['photo'] ?? '';
                    $_SESSION['messenger_user_role'] = $role;
                    
                    header('Location: index.php');
                    exit;
                } else {
                    $error = 'رمز عبور اشتباه است';
                }
                break;
            }
        }
        
        if (!$found) {
            $error = 'کاربری با این نام پیدا نشد';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به پیام‌رسان</title>
    <style>
        @font-face {
            font-family: 'Abar';
            src: url('../assets/abarfanum-vf.ttf') format('truetype');
            font-weight: 100 900;
            font-display: swap;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Abar', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: #0e1621;
        }
        
        .login-box {
            background: #17212b;
            border-radius: 16px;
            padding: 40px 30px;
            max-width: 380px;
            width: 90%;
            text-align: center;
        }
        
        .logo {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: #5288c1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 35px;
            margin: 0 auto 15px;
        }
        
        h1 {
            font-size: 20px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 5px;
        }
        
        .subtitle {
            font-size: 13px;
            color: #708499;
            margin-bottom: 25px;
        }
        
        .error-box {
            background: rgba(255,71,87,0.1);
            color: #ff4757;
            padding: 12px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
            text-align: right;
        }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 5px;
            color: #708499;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            background: #0e1621;
            border: 1px solid #0b1219;
            border-radius: 10px;
            font-family: inherit;
            font-size: 14px;
            color: #fff;
            outline: none;
        }
        .form-group input:focus {
            border-color: #5288c1;
        }
        
        .btn-login {
            width: 100%;
            padding: 13px;
            background: #5288c1;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-login:hover {
            background: #6a9fd0;
        }
        
        .back-link {
            display: block;
            margin-top: 15px;
            font-size: 12px;
            color: #708499;
            text-decoration: none;
        }
        .back-link:hover { color: #fff; }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="logo">💬</div>
        <h1>پیام‌رسان</h1>
        <p class="subtitle">افق دانش ثریا</p>
        
        <?php if ($error): ?>
            <div class="error-box"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>نام کاربری</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>رمز عبور</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn-login">ورود</button>
        </form>
        
        <a href="../index.php" class="back-link">← بازگشت به سایت</a>
    </div>
</body>
</html>