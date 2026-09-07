<?php
require_once '../config.php';

header('Content-Type: application/json');

m_check_login();

$me = m_current_user();
$my_id = $me['id'];

$type = $_GET['type'] ?? '';

// ==============================================
// دریافت پیام‌های خصوصی
// ==============================================
if ($type === 'private') {
    $conversation_id = sanitize($_GET['conversation_id'] ?? '');
    
    if (empty($conversation_id)) {
        echo json_encode(['success' => false]);
        exit;
    }
    
    $messages = m_get_private_messages($conversation_id, $my_id);
    
    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}

// ==============================================
// دریافت پیام‌های گروه
// ==============================================
if ($type === 'group') {
    $group_id = sanitize($_GET['group_id'] ?? '');
    
    if (empty($group_id)) {
        echo json_encode(['success' => false]);
        exit;
    }
    
    $messages = m_get_group_messages($group_id, $my_id);
    
    echo json_encode(['success' => true, 'messages' => $messages]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'عملیات نامعتبر']);
?>