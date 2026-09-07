<?php
require_once 'config.php';
require_once 'logger.php';

// ==============================================
// سلسله مراتب نقش‌ها
// ==============================================
$GLOBALS['role_hierarchy'] = [
    'viewer' => 0,
    'article_writer' => 1,
    'project_writer' => 1,
    'editor' => 2,
    'manager' => 3,
    'admin' => 4
];

// ==============================================
// ورود به پنل مدیریت
// ==============================================
function login_user($username, $password) {
    $data = read_json('users.json');
    $users = $data['users'] ?? [];
    
    foreach ($users as $user) {
        if ($user['username'] === $username && ($user['is_active'] ?? true)) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_user'] = $user['username'];
                $_SESSION['admin_role'] = $user['role'] ?? 'viewer';
                $_SESSION['admin_name'] = $user['full_name'] ?? $username;
                $_SESSION['admin_photo'] = $user['photo'] ?? '';
                
                update_last_login($user['id']);
                add_log('login', "کاربر {$username} وارد پنل شد");
                
                return ['success' => true, 'user' => $user];
            }
        }
    }
    return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است'];
}

// ==============================================
// ورود به پیام‌رسان
// ==============================================
function login_messenger($username, $password) {
    $data = read_json('users.json');
    $users = $data['users'] ?? [];
    
    foreach ($users as $user) {
        if ($user['username'] === $username && ($user['is_active'] ?? true)) {
            // بررسی فعال بودن پیام‌رسان
            $role = $user['role'] ?? 'viewer';
            if (empty($user['messenger_enabled']) && $role !== 'admin' && $role !== 'manager') {
                return ['success' => false, 'message' => '⛔ حساب پیام‌رسان شما فعال نیست. با مدیر تماس بگیرید.'];
            }
            
            if (password_verify($password, $user['password'])) {
                $_SESSION['messenger_user_id'] = $user['id'];
                $_SESSION['messenger_user_name'] = $user['full_name'] ?? $username;
                $_SESSION['messenger_user_role'] = $role;
                $_SESSION['messenger_user_photo'] = $user['photo'] ?? '';
                
                add_log('messenger_login', "کاربر {$username} وارد پیام‌رسان شد");
                
                return ['success' => true, 'user' => $user];
            }
        }
    }
    return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است'];
}

// ==============================================
// بررسی لاگین پنل
// ==============================================
function check_login() {
    if (!isset($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

// ==============================================
// بررسی لاگین پیام‌رسان
// ==============================================
function check_messenger_login() {
    if (!isset($_SESSION['messenger_user_id'])) {
        header('Location: login.php');
        exit;
    }
}

// ==============================================
// بررسی سطح دسترسی
// ==============================================
function check_permission($required_role = 'admin') {
    check_login();
    
    $hierarchy = $GLOBALS['role_hierarchy'];
    $user_role = $_SESSION['admin_role'] ?? 'viewer';
    
    $user_level = $hierarchy[$user_role] ?? 0;
    $required_level = $hierarchy[$required_role] ?? 0;
    
    if ($user_level < $required_level) {
        header('Location: dashboard.php?error=permission');
        exit;
    }
}

function has_permission($required_role) {
    if (!isset($_SESSION['admin_role'])) return false;
    
    $hierarchy = $GLOBALS['role_hierarchy'];
    $user_role = $_SESSION['admin_role'];
    
    $user_level = $hierarchy[$user_role] ?? 0;
    $required_level = $hierarchy[$required_role] ?? 0;
    
    return $user_level >= $required_level;
}

// ==============================================
// به‌روزرسانی آخرین ورود
// ==============================================
function update_last_login($user_id) {
    $data = read_json('users.json');
    $users = $data['users'] ?? [];
    
    foreach ($users as &$user) {
        if ($user['id'] === $user_id) {
            $user['last_login'] = date('Y-m-d H:i:s');
            break;
        }
    }
    
    $data['users'] = $users;
    write_json('users.json', $data);
}

// ==============================================
// مدیریت کاربران
// ==============================================
function create_user($username, $password, $role, $full_name, $email = '', $phone = '', $photo = '', $bio = '', $messenger_enabled = false) {
    $data = read_json('users.json');
    $users = $data['users'] ?? [];
    
    foreach ($users as $user) {
        if ($user['username'] === $username) {
            return ['success' => false, 'message' => 'این نام کاربری وجود دارد'];
        }
    }
    
    $new_user = [
        'id' => 'usr_' . uniqid(),
        'username' => sanitize($username),
        'password' => password_hash($password, PASSWORD_BCRYPT),
        'role' => $role,
        'full_name' => sanitize($full_name),
        'email' => sanitize($email),
        'phone' => sanitize($phone),
        'photo' => $photo,
        'bio' => sanitize($bio),
        'messenger_enabled' => $messenger_enabled,
        'created_at' => date('Y-m-d H:i:s'),
        'last_login' => null,
        'is_active' => true
    ];
    
    $users[] = $new_user;
    $data['users'] = $users;
    write_json('users.json', $data);
    
    add_log('create_user', "کاربر {$username} با نقش {$role} ایجاد شد");
    return ['success' => true];
}

function delete_user($user_id) {
    $data = read_json('users.json');
    $users = $data['users'] ?? [];
    
    foreach ($users as $key => $user) {
        if ($user['id'] === $user_id) {
            unset($users[$key]);
            $data['users'] = array_values($users);
            write_json('users.json', $data);
            add_log('delete_user', "کاربر {$user['username']} حذف شد");
            return true;
        }
    }
    return false;
}

function toggle_user_status($user_id) {
    $data = read_json('users.json');
    $users = $data['users'] ?? [];
    
    foreach ($users as &$user) {
        if ($user['id'] === $user_id) {
            $user['is_active'] = !($user['is_active'] ?? true);
            break;
        }
    }
    
    $data['users'] = $users;
    write_json('users.json', $data);
}

function get_users() {
    $data = read_json('users.json');
    return $data['users'] ?? [];
}

function get_user_by_id($user_id) {
    $users = get_users();
    foreach ($users as $user) {
        if ($user['id'] === $user_id) return $user;
    }
    return null;
}

// ==============================================
// نقش‌ها
// ==============================================
function get_roles() {
    return [
        'admin' => '👑 ادمین کل',
        'manager' => '🛡️ مدیر',
        'editor' => '📝 ویراستار',
        'article_writer' => '✍️ نویسنده مقاله',
        'project_writer' => '🏗️ نویسنده پروژه',
        'viewer' => '👁️ فقط مشاهده'
    ];
}

function get_role_name_fa($role) {
    $roles = get_roles();
    return $roles[$role] ?? $role;
}
?>