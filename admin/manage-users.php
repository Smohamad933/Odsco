<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/logger.php';

check_login();

$message = '';
$error = '';
$editing_user = null;
$show_form = isset($_GET['add']) || isset($_GET['edit']);
$is_viewer = ($_SESSION['admin_role'] ?? '') === 'viewer';

$main_username = 'mohusyn';
$current_username = $_SESSION['admin_user'] ?? '';

if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if (isset($_GET['edit'])) {
    $users_data = read_json('users.json');
    $users = $users_data['users'] ?? [];
    foreach ($users as $user) {
        if ($user['id'] === $_GET['edit']) {
            $editing_user = $user;
            break;
        }
    }
}

// بررسی دسترسی ویرایش
$can_edit = true;
$lock_reason = '';

if ($editing_user) {
    if ($editing_user['username'] === $main_username && $current_username !== $main_username) {
        $can_edit = false;
        $lock_reason = '⛔ کاربر اصلی سیستم فقط توسط خودش قابل ویرایش است!';
    }
}

$roles = [
    'admin' => '👑 ادمین کل',
    'manager' => '🛡️ مدیر',
    'editor' => '📝 ویراستار',
    'article_writer' => '✍️ نویسنده مقاله',
    'project_writer' => '🏗️ نویسنده پروژه',
    'viewer' => '👁️ فقط مشاهده'
];

$roles_desc = [
    'admin' => 'دسترسی کامل',
    'manager' => 'مدیریت محتوا',
    'editor' => 'ویرایش محتوا',
    'article_writer' => 'فقط مقالات',
    'project_writer' => 'فقط پروژه‌ها',
    'viewer' => 'فقط مشاهده'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($is_viewer) {
        $error = '⛔ شما فقط بیننده هستید!';
    } else {
        // ============ افزودن کاربر ============
        if ($_POST['action'] === 'add_user') {
            $data = read_json('users.json');
            $users = $data['users'] ?? [];
            
            // پردازش عکس
            $photo = '';
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/users/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
                $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $file_name = 'user_' . time() . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $file_name)) {
                    $photo = 'uploads/users/' . $file_name;
                }
            }
            if (empty($photo)) $photo = 'assets/default-user.png';
            
            // دریافت امن مقادیر
            $username = sanitize($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = sanitize($_POST['role'] ?? 'viewer');
            $full_name = sanitize($_POST['full_name'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $phone = sanitize($_POST['phone'] ?? '');
            $bio = sanitize($_POST['bio'] ?? '');
            $messenger_enabled = isset($_POST['messenger_enabled']) ? true : false;
            
            // بررسی تکراری
            $exists = false;
            foreach ($users as $user) {
                if ($user['username'] === $username) { $exists = true; break; }
            }
            
            if (!$exists && !empty($username) && !empty($password) && !empty($full_name)) {
                $new_user = [
                    'id' => 'usr_' . uniqid(),
                    'username' => $username,
                    'password' => password_hash($password, PASSWORD_BCRYPT),
                    'role' => $role,
                    'full_name' => $full_name,
                    'email' => $email,
                    'phone' => $phone,
                    'photo' => $photo,
                    'bio' => $bio,
                    'messenger_enabled' => $messenger_enabled,
                    'created_at' => date('Y-m-d H:i:s'),
                    'last_login' => null,
                    'is_active' => true
                ];
                $users[] = $new_user;
                $data['users'] = $users;
                write_json('users.json', $data);
                add_log('add_user', "کاربر {$username} ایجاد شد");
                $message = '✅ کاربر اضافه شد';
                $show_form = false;
            } else {
                $error = $exists ? '❌ این نام کاربری وجود دارد' : '❌ لطفاً همه فیلدهای ضروری را پر کنید';
            }
        }
        
        // ============ ویرایش کاربر ============
        if ($_POST['action'] === 'edit_user') {
            $user_id = $_POST['user_id'] ?? '';
            $data = read_json('users.json');
            $users = $data['users'] ?? [];
            
            foreach ($users as &$user) {
                if ($user['id'] === $user_id) {
                    // بررسی دسترسی
                    if ($user['username'] === $main_username && $current_username !== $main_username) {
                        $error = '⛔ شما اجازه ویرایش کاربر اصلی را ندارید!';
                        break;
                    }
                    
                    // آپلود عکس
                    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = '../uploads/users/';
                        if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
                        $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                        $file_name = 'user_' . time() . '_' . uniqid() . '.' . $ext;
                        if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $file_name)) {
                            // حذف عکس قبلی
                            if (!empty($user['photo']) && $user['photo'] !== 'assets/default-user.png') {
                                $old_file = '../' . $user['photo'];
                                if (file_exists($old_file)) unlink($old_file);
                            }
                            $user['photo'] = 'uploads/users/' . $file_name;
                        }
                    }
                    
                    $user['full_name'] = sanitize($_POST['full_name'] ?? $user['full_name']);
                    
                    // محافظت نقش کاربر اصلی
                    if ($user['username'] !== $main_username) {
                        $user['role'] = sanitize($_POST['role'] ?? $user['role']);
                    }
                    
                    $user['email'] = sanitize($_POST['email'] ?? '');
                    $user['phone'] = sanitize($_POST['phone'] ?? '');
                    $user['bio'] = sanitize($_POST['bio'] ?? '');
                    $user['messenger_enabled'] = isset($_POST['messenger_enabled']) ? true : false;
                    
                    if (!empty($_POST['password'])) {
                        $user['password'] = password_hash($_POST['password'], PASSWORD_BCRYPT);
                    }
                    break;
                }
            }
            
            if (empty($error)) {
                $data['users'] = $users;
                write_json('users.json', $data);
                $message = '✅ کاربر ویرایش شد';
                $show_form = false;
                $editing_user = null;
            }
        }
        
        // ============ حذف کاربر ============
        if ($_POST['action'] === 'delete_user') {
            $user_id = $_POST['user_id'] ?? '';
            if ($user_id === $_SESSION['admin_id']) {
                $error = '❌ نمی‌توانید خودتان را حذف کنید';
            } else {
                $data = read_json('users.json');
                $users = $data['users'] ?? [];
                $target = null;
                foreach ($users as $user) {
                    if ($user['id'] === $user_id) { $target = $user; break; }
                }
                if ($target && $target['username'] === $main_username) {
                    $error = '⛔ کاربر اصلی قابل حذف نیست!';
                } else {
                    // حذف عکس کاربر
                    if ($target && !empty($target['photo']) && $target['photo'] !== 'assets/default-user.png') {
                        $file = '../' . $target['photo'];
                        if (file_exists($file)) unlink($file);
                    }
                    delete_user($user_id);
                    $message = '✅ کاربر حذف شد';
                }
            }
        }
        
        // ============ تغییر وضعیت ============
        if ($_POST['action'] === 'toggle_user') {
            $user_id = $_POST['user_id'] ?? '';
            $data = read_json('users.json');
            $users = $data['users'] ?? [];
            $target = null;
            foreach ($users as $user) {
                if ($user['id'] === $user_id) { $target = $user; break; }
            }
            if ($target && $target['username'] === $main_username) {
                $error = '⛔ کاربر اصلی قابل غیرفعال کردن نیست!';
            } else {
                toggle_user_status($user_id);
            }
        }
    }
}

$users_data = read_json('users.json');
$users = $users_data['users'] ?? [];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت کاربران | پنل مدیریت</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .users-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .stat-pill { padding: 8px 18px; border-radius: 25px; font-size: 13px; font-weight: 700; background: #eee; color: #666; }
        .users-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; }
        .user-card { background: #fff; border-radius: 16px; overflow: hidden; border: 1px solid #e9ecef; transition: all 0.3s; text-align: center; position: relative; }
        .user-card:hover { box-shadow: 0 10px 30px rgba(0,0,0,0.08); transform: translateY(-4px); }
        .user-card.main-user { border: 2px solid #ffa502; }
        .main-badge { position: absolute; top: 10px; right: 10px; background: #ffa502; color: #fff; padding: 3px 10px; border-radius: 15px; font-size: 9px; font-weight: 700; z-index: 2; }
        .messenger-badge { position: absolute; top: 10px; left: 10px; background: #2ed573; color: #fff; padding: 3px 10px; border-radius: 15px; font-size: 9px; font-weight: 700; z-index: 2; }
        .user-photo { height: 120px; background: linear-gradient(135deg, #1a1a1a, #333); display: flex; align-items: center; justify-content: center; position: relative; }
        .user-avatar { width: 70px; height: 70px; border-radius: 50%; border: 3px solid #fff; object-fit: cover; }
        .user-status { position: absolute; bottom: 10px; left: 10px; width: 12px; height: 12px; border-radius: 50%; border: 2px solid #fff; }
        .user-status.active { background: #2ed573; }
        .user-status.inactive { background: #ff4757; }
        .user-body { padding: 15px; }
        .user-name { font-size: 14px; font-weight: 800; }
        .user-username { font-size: 11px; color: #999; direction: ltr; }
        .user-role-badge { display: inline-block; padding: 4px 12px; border-radius: 15px; font-size: 10px; font-weight: 700; margin: 8px 0; }
        .user-role-badge.admin { background: #ffebee; color: #ff4757; }
        .user-role-badge.manager { background: #e8f4fd; color: #3742fa; }
        .user-role-badge.editor { background: #fff8e1; color: #ffa502; }
        .user-role-badge.article_writer { background: #e8f5e9; color: #2e7d32; }
        .user-role-badge.project_writer { background: #f3e5f5; color: #9c27b0; }
        .user-role-badge.viewer { background: #f5f5f5; color: #666; }
        .user-footer { display: flex; justify-content: center; gap: 6px; padding: 12px; border-top: 1px solid #f0f0f0; }
        .btn-icon { width: 35px; height: 35px; border-radius: 10px; border: 1px solid #ddd; background: #fff; cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; transition: all 0.3s; text-decoration: none; }
        .btn-icon:hover { background: #f5f5f5; }
        .btn-icon.disabled { opacity: 0.3; cursor: not-allowed; pointer-events: none; }
        .lock-banner { background: #ffebee; border: 1px solid #ff4757; color: #c62828; padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; font-size: 13px; font-weight: 700; text-align: center; }
        .form-modern { background: #fff; border-radius: 20px; box-shadow: 0 5px 30px rgba(0,0,0,0.05); overflow: hidden; }
        .form-modern__header { padding: 25px 30px; background: linear-gradient(135deg, #1a1a1a, #333); color: #fff; display: flex; justify-content: space-between; align-items: center; }
        .form-modern__header h2 { font-size: 20px; font-weight: 900; margin: 0; }
        .btn-back { background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 10px; text-decoration: none; font-size: 12px; }
        .form-modern__body { padding: 30px; }
        .form-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-size: 12px; font-weight: 700; color: #555; }
        .form-group input, .form-group select, .form-group textarea { padding: 10px 14px; border: 1px solid #e0e0e0; border-radius: 10px; font-family: inherit; font-size: 13px; background: #fafafa; }
        .form-group input:disabled, .form-group select:disabled, .form-group textarea:disabled { background: #f5f5f5; color: #999; }
        .form-group input[type="file"] { padding: 8px; background: #fff; }
        .role-selector { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .role-option { border: 2px solid #e0e0e0; border-radius: 12px; padding: 12px; cursor: pointer; transition: all 0.3s; text-align: center; }
        .role-option:hover { border-color: #1a1a1a; }
        .role-option.selected { border-color: #2ed573; background: #e8f5e9; }
        .role-option.locked { cursor: not-allowed; opacity: 0.6; }
        .role-option .role-icon { font-size: 22px; display: block; margin-bottom: 5px; }
        .role-option .role-name { font-size: 12px; font-weight: 700; }
        .role-option .role-desc { font-size: 10px; color: #999; margin-top: 3px; }
        .role-option input { display: none; }
        .form-modern__footer { padding: 20px 30px; border-top: 1px solid #f0f0f0; display: flex; gap: 10px; }
        .btn { padding: 11px 25px; border: none; border-radius: 10px; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.3s; text-decoration: none; }
        .btn-primary { background: #1a1a1a; color: #fff; }
        .btn-primary:hover { background: #333; }
        .btn-secondary { background: #f5f5f5; color: #666; }
        .btn-secondary:hover { background: #e0e0e0; }
        .messenger-toggle { display: flex; align-items: center; gap: 10px; padding: 12px 15px; background: #e8f4fd; border-radius: 12px; cursor: pointer; border: 2px solid #c5d9e8; transition: all 0.3s; }
        .messenger-toggle:hover { border-color: #3742fa; }
        .messenger-toggle input { width: 18px; height: 18px; cursor: pointer; accent-color: #3742fa; }
        .photo-preview { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid #e0e0e0; display: block; margin: 5px 0; }
        @media (max-width: 768px) {
            .form-grid-3 { grid-template-columns: 1fr; }
            .form-group.full { grid-column: auto; }
            .role-selector { grid-template-columns: 1fr; }
            .users-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="top-bar">
                <h1>👥 مدیریت کاربران</h1>
                <?php if ($is_viewer): ?><span class="badge" style="background:#fff8e1;color:#e65100;">👁️ حالت مشاهده</span><?php endif; ?>
            </header>
            
            <?php if ($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
            <?php if ($is_viewer): ?><div class="viewer-banner">⛔ شما فقط بیننده هستید!</div><?php endif; ?>
            
            <?php if ($show_form && $editing_user && !$can_edit): ?>
                <div class="lock-banner"><?php echo $lock_reason; ?></div>
                <a href="manage-users.php" class="btn btn-secondary">← بازگشت</a>
            <?php elseif ($show_form): ?>
            
            <div class="form-modern">
                <div class="form-modern__header">
                    <h2><?php echo $editing_user ? '✏️ ویرایش کاربر' : '➕ افزودن کاربر'; ?></h2>
                    <a href="manage-users.php" class="btn-back">← بازگشت</a>
                </div>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="<?php echo $editing_user ? 'edit_user' : 'add_user'; ?>">
                    <?php if ($editing_user): ?><input type="hidden" name="user_id" value="<?php echo $editing_user['id']; ?>"><?php endif; ?>
                    
                    <div class="form-modern__body">
                        <div class="form-grid-3">
                            <div class="form-group">
                                <label>نام کامل *</label>
                                <input type="text" name="full_name" value="<?php echo $editing_user['full_name'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : 'required'; ?>>
                            </div>
                            <div class="form-group">
                                <label>نام کاربری *</label>
                                <input type="text" name="username" value="<?php echo $editing_user['username'] ?? ''; ?>" <?php echo $is_viewer || $editing_user ? 'disabled' : 'required'; ?>>
                            </div>
                            <div class="form-group">
                                <label>رمز عبور <?php echo $editing_user ? '(خالی = بدون تغییر)' : '*'; ?></label>
                                <input type="password" name="password" <?php echo $is_viewer ? 'disabled' : ($editing_user ? '' : 'required'); ?>>
                            </div>
                            <div class="form-group">
                                <label>ایمیل</label>
                                <input type="email" name="email" value="<?php echo $editing_user['email'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>تلفن</label>
                                <input type="text" name="phone" value="<?php echo $editing_user['phone'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            
                            <div class="form-group">
                                <label>عکس پروفایل</label>
                                <?php if ($editing_user && !empty($editing_user['photo'])): ?>
                                    <img src="../<?php echo $editing_user['photo']; ?>" class="photo-preview">
                                <?php endif; ?>
                                <input type="file" name="photo" accept="image/*" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                                <small style="color:#999;font-size:10px;">فرمت‌های مجاز: jpg, png, gif - حداکثر ۲ مگابایت</small>
                            </div>
                            
                            <div class="form-group full">
                                <label>نقش کاربری:</label>
                                <?php $is_main_user = $editing_user && $editing_user['username'] === $main_username; ?>
                                <?php if ($is_main_user): ?>
                                    <div style="padding:10px;background:#fff8e1;border-radius:10px;font-size:12px;color:#e65100;">⛔ نقش کاربر اصلی قابل تغییر نیست</div>
                                <?php endif; ?>
                                <div class="role-selector">
                                    <?php foreach ($roles as $role_key => $role_name): 
                                        $selected = ($editing_user && ($editing_user['role'] ?? '') === $role_key) ? 'selected' : '';
                                        $role_icon = explode(' ', $role_name)[0];
                                        $role_title = str_replace($role_icon . ' ', '', $role_name);
                                        $locked = $is_main_user ? 'locked' : '';
                                    ?>
                                    <label class="role-option <?php echo $selected; ?> <?php echo $locked; ?>" onclick="<?php echo $locked ? '' : "selectRole('$role_key', this)"; ?>">
                                        <input type="radio" name="role" value="<?php echo $role_key; ?>" <?php echo $selected ? 'checked' : ''; ?> <?php echo ($is_viewer || $locked) ? 'disabled' : ''; ?>>
                                        <span class="role-icon"><?php echo $role_icon; ?></span>
                                        <span class="role-name"><?php echo $role_title; ?></span>
                                        <span class="role-desc"><?php echo $roles_desc[$role_key] ?? ''; ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <!-- فعال‌سازی پیام‌رسان -->
                            <div class="form-group full">
                                <label>💬 پیام‌رسان:</label>
                                <label class="messenger-toggle">
                                    <input type="checkbox" name="messenger_enabled" 
                                           <?php echo (!empty($editing_user['messenger_enabled'])) ? 'checked' : ''; ?> 
                                           <?php echo $is_viewer ? 'disabled' : ''; ?>>
                                    <span style="font-size:13px;font-weight:700;">فعال‌سازی حساب پیام‌رسان</span>
                                </label>
                                <small style="color:#999;font-size:10px;">کاربر می‌تواند با همین نام کاربری و رمز وارد پیام‌رسان شود</small>
                            </div>
                            
                            <div class="form-group full">
                                <label>توضیحات</label>
                                <textarea name="bio" rows="3" <?php echo $is_viewer ? 'disabled' : ''; ?>><?php echo $editing_user['bio'] ?? ''; ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!$is_viewer): ?>
                    <div class="form-modern__footer">
                        <button type="submit" class="btn btn-primary"><?php echo $editing_user ? '💾 ذخیره' : '➕ افزودن'; ?></button>
                        <a href="manage-users.php" class="btn btn-secondary">انصراف</a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            <?php else: ?>
            
            <div class="users-header">
                <span class="stat-pill">👥 <?php echo count($users); ?> کاربر</span>
                <?php if (!$is_viewer): ?><a href="manage-users.php?add=1" class="btn btn-primary">➕ افزودن کاربر</a><?php endif; ?>
            </div>
            
            <div class="users-grid">
                <?php foreach ($users as $user): 
                    $role_key = $user['role'] ?? 'viewer';
                    $is_main = $user['username'] === $main_username;
                    $is_current = $user['id'] === $_SESSION['admin_id'];
                    $has_messenger = !empty($user['messenger_enabled']) || $role_key === 'admin';
                ?>
                <div class="user-card <?php echo $is_main ? 'main-user' : ''; ?>">
                    <?php if ($is_main): ?><span class="main-badge">⭐ اصلی</span><?php endif; ?>
                    <?php if ($has_messenger): ?><span class="messenger-badge">💬</span><?php endif; ?>
                    
                    <div class="user-photo">
                        <img src="../<?php echo $user['photo'] ?? 'assets/default-user.png'; ?>" class="user-avatar" onerror="this.src='../assets/default-user.png'">
                        <span class="user-status <?php echo ($user['is_active'] ?? true) ? 'active' : 'inactive'; ?>"></span>
                    </div>
                    <div class="user-body">
                        <h3 class="user-name"><?php echo $user['full_name']; ?></h3>
                        <div class="user-username">@<?php echo $user['username']; ?></div>
                        <span class="user-role-badge <?php echo $role_key; ?>"><?php echo $roles[$role_key] ?? $role_key; ?></span>
                    </div>
                    <div class="user-footer">
                        <?php if ($is_main && !$is_current): ?>
                            <button type="button" class="btn-icon disabled">🔒</button>
                        <?php else: ?>
                            <a href="manage-users.php?edit=<?php echo $user['id']; ?>" class="btn-icon"><?php echo $is_viewer ? '👁️' : '✏️'; ?></a>
                        <?php endif; ?>
                        
                        <?php if (!$is_viewer): ?>
                            <?php if ($is_main): ?>
                                <button type="button" class="btn-icon disabled">🔒</button>
                                <button type="button" class="btn-icon disabled">🔒</button>
                            <?php elseif ($is_current): ?>
                                <button type="button" class="btn-icon disabled">🔒</button>
                            <?php else: ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_user">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn-icon"><?php echo ($user['is_active'] ?? true) ? '🟢' : '🔴'; ?></button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('حذف شود؟');">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn-icon">🗑️</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>
    
    <script>
    function selectRole(role, element) {
        if (<?php echo $is_viewer ? 'true' : 'false'; ?>) return;
        document.querySelectorAll('.role-option').forEach(r => r.classList.remove('selected'));
        element.classList.add('selected');
        const radio = element.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
    }
    </script>
</body>
</html>