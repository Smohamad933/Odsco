<?php
/**
 * سازگاری با آدرس‌های قدیمی — همه گفتگوها (خصوصی/گروه/ذخیره‌شده)
 * در chat.php مدیریت می‌شوند.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
m_check_login();

$g = (string)($_GET['group'] ?? '');
if ($g !== '') redirect('chat.php?group=' . rawurlencode($g));
redirect('index.php');
