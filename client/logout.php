<?php
/**
 * ============================================================================
 *  Odsco — پنل کارفرما: خروج
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (is_logged_client()) {
    ActivityLog::add('client_logout', 'خروج از پنل کارفرما');
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
