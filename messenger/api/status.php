<?php
require_once '../config.php';

header('Content-Type: application/json');

m_check_login();

$me = m_current_user();
$my_id = $me['id'];

$action = $_GET['action'] ?? '';

// ==============================================
// ثبت آنلاین بودن
// ==============================================
if ($action === 'heartbeat') {
    m_heartbeat($my_id);
    echo json_encode(['success' => true]);
    exit;
}

// ==============================================
// بررسی آنلاین بودن یک کاربر
// ==============================================
if ($action === 'get') {
    $user_id = sanitize($_GET['user_id'] ?? '');
    
    if (empty($user_id)) {
        echo json_encode(['success' => false]);
        exit;
    }
    
    $is_online = m_is_online($user_id);
    
    echo json_encode(['success' => true, 'is_online' => $is_online]);
    exit;
}

// ==============================================
// دریافت همه کاربران آنلاین
// ==============================================
if ($action === 'get_all') {
    $online = read_json('messenger_online.json');
    if (!is_array($online)) $online = [];
    
    $online_users = [];
    foreach ($online as $uid => $time) {
        if (time() - $time < 120) {
            $online_users[] = $uid;
        }
    }
    
    echo json_encode(['success' => true, 'online_users' => $online_users]);
    exit;
}

echo json_encode(['success' => false]);
?>