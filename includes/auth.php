<?php
/**
 * ============================================================================
 *  Odsco — احراز هویت و سطح دسترسی
 * ----------------------------------------------------------------------------
 *  سه ناحیه ورود:
 *    • پنل مدیریت   → $_SESSION['admin_*']
 *    • پیام‌رسان     → $_SESSION['messenger_*']
 *    • پنل کارفرما   → $_SESSION['client_*']
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// ورود
// ---------------------------------------------------------------------------

/**
 * @return array{success: bool, message?: string, user?: array}
 */
function authenticate(string $username, string $password, string $area = 'admin'): array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['success' => false, 'message' => 'نام کاربری و رمز عبور را وارد کنید'];
    }

    $user = Users::findByUsername($username);

    if (!$user || !password_verify($password, (string)$user['password'])) {
        // مقاومت در برابر Brute-force (۵ تلاش در ۱۰ دقیقه برای هر IP)
        odsco_throttle_login();
        ActivityLog::add('login_failed', "تلاش ناموفق ورود با نام کاربری {$username}", $username);
        return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است'];
    }

    if (!$user['is_active']) {
        return ['success' => false, 'message' => '⛔ حساب کاربری شما غیرفعال است. با مدیر تماس بگیرید.'];
    }

    if ($area === 'messenger' && !$user['messenger_enabled']) {
        return ['success' => false, 'message' => '⛔ دسترسی پیام‌رسان برای حساب شما فعال نیست. با مدیر تماس بگیرید.'];
    }

    if ($area === 'client' && $user['role'] !== 'client') {
        return ['success' => false, 'message' => '⛔ این حساب کاربری مخصوص پنل کارفرما نیست.'];
    }

    // مهاجرت خودکار hash قدیمی به الگوریتم جدید
    if (password_needs_rehash((string)$user['password'], PASSWORD_DEFAULT)) {
        Users::setPassword($user['uid'], $password);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    Users::touchLogin($user['uid']);

    $device = detect_device();

    switch ($area) {
        case 'messenger':
            $_SESSION['messenger_user_id']    = $user['uid'];
            $_SESSION['messenger_user_name']  = $user['full_name'];
            $_SESSION['messenger_user_role']  = $user['role'];
            $_SESSION['messenger_user_photo'] = $user['photo'];
            $_SESSION['messenger_user_client']= $user['client_uid'];
            Messenger::registerDevice($user['uid'], $device);
            Messenger::touchPresence($user['uid']);
            ActivityLog::add('messenger_login', "کاربر {$username} وارد پیام‌رسان شد", $username);
            break;

        case 'client':
            $_SESSION['client_user_id']    = $user['uid'];
            $_SESSION['client_user_name']  = $user['full_name'];
            $_SESSION['client_uid']        = $user['client_uid'];
            $_SESSION['client_user_photo'] = $user['photo'];
            ActivityLog::add('client_login', "کاربر {$username} وارد پنل کارفرما شد", $username);
            break;

        default:
            $_SESSION['admin_id']    = $user['uid'];
            $_SESSION['admin_user']  = $user['username'];
            $_SESSION['admin_role']  = $user['role'];
            $_SESSION['admin_name']  = $user['full_name'];
            $_SESSION['admin_photo'] = $user['photo'];
            $_SESSION['admin_client']= $user['client_uid'];
            ActivityLog::add('login', "کاربر {$username} وارد پنل شد", $username);
    }

    return ['success' => true, 'user' => $user];
}

/** شمارش تلاش‌های ناموفق ورود */
function odsco_throttle_login(): void
{
    $ip = client_ip();
    $bucket = 'login_fail_' . md5($ip);
    $now = time();
    $data = $_SESSION[$bucket] ?? ['count' => 0, 'first' => $now];
    if ($now - $data['first'] > 600) $data = ['count' => 0, 'first' => $now];
    $data['count']++;
    $_SESSION[$bucket] = $data;
}

function login_blocked(): bool
{
    $bucket = 'login_fail_' . md5(client_ip());
    $data = $_SESSION[$bucket] ?? null;
    return $data && $data['count'] >= 5 && (time() - $data['first']) < 600;
}

function login_retry_seconds(): int
{
    $bucket = 'login_fail_' . md5(client_ip());
    $data = $_SESSION[$bucket] ?? null;
    return $data ? max(0, 600 - (time() - $data['first'])) : 0;
}

function login_reset_throttle(): void
{
    unset($_SESSION['login_fail_' . md5(client_ip())]);
}

// ---------------------------------------------------------------------------
// وضعیت ورود
// ---------------------------------------------------------------------------

function is_logged_admin(): bool     { return isset($_SESSION['admin_id']); }
function is_logged_messenger(): bool{ return isset($_SESSION['messenger_user_id']); }
function is_logged_client(): bool   { return isset($_SESSION['client_user_id']); }

function current_admin_uid(): string     { return (string)($_SESSION['admin_id'] ?? ''); }
function current_messenger_uid(): string { return (string)($_SESSION['messenger_user_id'] ?? ''); }
function current_client_uid(): string    { return (string)($_SESSION['client_user_id'] ?? ''); }

function current_admin(): ?array
{
    $uid = current_admin_uid();
    return $uid ? Users::find($uid) : null;
}

// ---------------------------------------------------------------------------
// محافظت از صفحات
// ---------------------------------------------------------------------------

function check_login(): void
{
    if (!is_logged_admin()) {
        redirect('login.php');
    }
    if (login_blocked()) {
        http_response_code(429);
        exit('⏳ تلاش‌های بیش از حد. لطفاً چند دقیقه بعد دوباره تلاش کنید.');
    }
}

function check_messenger_login(): void
{
    if (!is_logged_messenger()) redirect('login.php');
}

function check_client_login(): void
{
    if (!is_logged_client()) redirect('login.php');
}

// ---------------------------------------------------------------------------
// سطح دسترسی
// ---------------------------------------------------------------------------

function has_permission(string $required_role): bool
{
    $role = (string)($_SESSION['admin_role'] ?? '');
    return Users::level($role) >= Users::level($required_role);
}

function check_permission(string $required_role = 'admin'): void
{
    check_login();
    if (!has_permission($required_role)) {
        redirect('dashboard.php?error=permission');
    }
}

/** فقط مشاهده‌گر است؟ */
function is_viewer(): bool
{
    return Users::level((string)($_SESSION['admin_role'] ?? '')) <= Users::level('viewer');
}

// ---------------------------------------------------------------------------
// سازگاری با کد قبلی
// ---------------------------------------------------------------------------

/** @deprecated از Users::list() استفاده کنید */
function get_users(): array { return Users::list(); }

/** @deprecated از Users::find() استفاده کنید */
function get_user_by_id(string $user_id): ?array { return Users::find($user_id); }

/** @deprecated از Users::ROLES استفاده کنید */
function get_roles(): array
{
    $out = [];
    foreach (Users::ROLES as $key => [$icon, $label]) $out[$key] = "$icon $label";
    return $out;
}

function get_role_name_fa(string $role): string { return Users::roleLabel($role); }

/** @deprecated */
function update_last_login(string $user_id): void { Users::touchLogin($user_id); }

function login_user(string $username, string $password): array { return authenticate($username, $password, 'admin'); }
function login_messenger(string $username, string $password): array { return authenticate($username, $password, 'messenger'); }

function create_user(string $username, string $password, string $role, string $full_name, string $email = '', string $phone = '', string $photo = '', string $bio = '', bool $messenger_enabled = false): array
{
    if (Users::exists($username)) return ['success' => false, 'message' => 'این نام کاربری وجود دارد'];
    try {
        $user = Users::create([
            'username' => sanitize($username), 'password' => $password, 'role' => $role,
            'full_name' => sanitize($full_name), 'email' => sanitize($email), 'phone' => sanitize($phone),
            'photo' => $photo, 'bio' => sanitize($bio), 'messenger_enabled' => $messenger_enabled,
        ]);
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
    ActivityLog::add('create_user', "کاربر {$username} با نقش {$role} ایجاد شد");
    return ['success' => true, 'user' => $user];
}

function delete_user(string $user_id): bool
{
    $u = Users::find($user_id);
    $ok = Users::delete($user_id);
    if ($ok) ActivityLog::add('delete_user', 'کاربر ' . ($u['username'] ?? $user_id) . ' حذف شد');
    return $ok;
}

function toggle_user_status(string $user_id): void
{
    $u = Users::find($user_id);
    if ($u) Users::update($user_id, ['is_active' => !$u['is_active']]);
}
