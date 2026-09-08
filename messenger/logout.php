<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$uid = current_messenger_uid();
if ($uid !== '') {
    // خروج از دستگاه فعلی از لیست نشست‌ها
    $devices = Messenger::devices($uid);
    if ($devices) Messenger::revokeDevice($uid, (string)$devices[0]['uid']);
    ActivityLog::add('messenger_logout', 'خروج از پیام‌رسان');
}

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
redirect('login.php');
