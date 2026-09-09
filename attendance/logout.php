<?php
/**
 * Odsco — خروج از بخش حضور و غیاب
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
redirect('login.php');
