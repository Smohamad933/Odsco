<?php
/**
 * خروج یکپارچه — همه نشست‌ها پاک می‌شود
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/logger.php';

$uid = current_admin_uid() ?: current_messenger_uid() ?: current_client_uid() ?: current_attendance_uid();
if ($uid !== '') {
    try { ActivityLog::add('unified_logout', 'خروج یکپارچه از همه پنل‌ها'); } catch (Throwable $e) {}
    if (class_exists('Messenger') && $uid) {
        try {
            $devices = Messenger::devices($uid);
            if ($devices) Messenger::revokeDevice($uid, (string)($devices[0]['uid']));
        } catch (Throwable $e) {}
    }
}

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

header('Location: login.php');
exit;
