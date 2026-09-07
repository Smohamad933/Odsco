<?php
require_once '../config.php';

header('Content-Type: application/json');

m_check_login();

$me = m_current_user();
$my_id = $me['id'];
$my_role = $me['role'];

$type = $_POST['type'] ?? '';

// ==============================================
// ارسال پیام متنی خصوصی
// ==============================================
if ($type === 'private') {
    $conversation_id = sanitize($_POST['conversation_id'] ?? '');
    $content = sanitize($_POST['content'] ?? '');
    
    if (empty($conversation_id) || empty($content)) {
        echo json_encode(['success' => false, 'message' => 'داده ناقص']);
        exit;
    }
    
    // بررسی عضویت
    $other_id = m_other_user_id($conversation_id, $my_id);
    if (!$other_id) {
        echo json_encode(['success' => false, 'message' => 'چت پیدا نشد']);
        exit;
    }
    
    $messages = read_json('messenger_messages.json');
    if (!is_array($messages)) $messages = [];
    
    $new_msg = [
        'id' => 'msg_' . uniqid(),
        'conversation_id' => $conversation_id,
        'sender_id' => $my_id,
        'content' => $content,
        'type' => 'text',
        'timestamp' => date('Y-m-d H:i:s'),
        'read_by' => [$my_id],
        'deleted_for_everyone' => false,
        'deleted_for' => []
    ];
    
    $messages[] = $new_msg;
    write_json('messenger_messages.json', $messages);
    
    echo json_encode(['success' => true, 'message' => $new_msg]);
    exit;
}

// ==============================================
// ارسال فایل خصوصی
// ==============================================
if ($type === 'private_file') {
    $conversation_id = sanitize($_POST['conversation_id'] ?? '');
    
    if (empty($conversation_id) || !isset($_FILES['file'])) {
        echo json_encode(['success' => false, 'message' => 'داده ناقص']);
        exit;
    }
    
    $other_id = m_other_user_id($conversation_id, $my_id);
    if (!$other_id) {
        echo json_encode(['success' => false]);
        exit;
    }
    
    $file = $_FILES['file'];
    
    // مسیر درست
    $upload_dir = dirname(__DIR__) . '/uploads/messenger/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $new_name = 'msg_' . time() . '_' . uniqid() . '.' . $ext;
    $target = $upload_dir . $new_name;
    
    if (move_uploaded_file($file['tmp_name'], $target)) {
        $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        $is_image = in_array($ext, $image_exts);
        
        $messages = read_json('messenger_messages.json');
        if (!is_array($messages)) $messages = [];
        
        $new_msg = [
            'id' => 'msg_' . uniqid(),
            'conversation_id' => $conversation_id,
            'sender_id' => $my_id,
            'content' => $file['name'],
            'type' => 'file',
            'file_type' => $is_image ? 'image' : 'document',
            'file_path' => 'uploads/messenger/' . $new_name,
            'file_size' => round($file['size'] / 1024 / 1024, 2),
            'timestamp' => date('Y-m-d H:i:s'),
            'read_by' => [$my_id],
            'deleted_for_everyone' => false,
            'deleted_for' => []
        ];
        
        $messages[] = $new_msg;
        write_json('messenger_messages.json', $messages);
        
        echo json_encode(['success' => true, 'message' => $new_msg]);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'خطا در ذخیره']);
    exit;
}

// ==============================================
// ارسال پیام متنی گروه
// ==============================================
if ($type === 'group') {
    $group_id = sanitize($_POST['group_id'] ?? '');
    $content = sanitize($_POST['content'] ?? '');
    
    if (empty($group_id) || empty($content)) {
        echo json_encode(['success' => false]);
        exit;
    }
    
    if (!m_is_group_member($group_id, $my_id, $my_role)) {
        echo json_encode(['success' => false, 'message' => 'عضو نیستید']);
        exit;
    }
    
    $messages = read_json('messenger_group_messages.json');
    if (!is_array($messages)) $messages = [];
    
    $new_msg = [
        'id' => 'gmsg_' . uniqid(),
        'group_id' => $group_id,
        'sender_id' => $my_id,
        'content' => $content,
        'type' => 'text',
        'timestamp' => date('Y-m-d H:i:s'),
        'read_by' => [$my_id],
        'deleted_for_everyone' => false,
        'deleted_for' => []
    ];
    
    $messages[] = $new_msg;
    write_json('messenger_group_messages.json', $messages);
    
    echo json_encode(['success' => true, 'message' => $new_msg]);
    exit;
}

// ==============================================
// ارسال فایل گروه
// ==============================================
if ($type === 'group_file') {
    $group_id = sanitize($_POST['group_id'] ?? '');
    
    if (empty($group_id) || !isset($_FILES['file'])) {
        echo json_encode(['success' => false]);
        exit;
    }
    
    if (!m_is_group_member($group_id, $my_id, $my_role)) {
        echo json_encode(['success' => false]);
        exit;
    }
    
    $file = $_FILES['file'];
    
    $upload_dir = dirname(__DIR__) . '/uploads/messenger/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $new_name = 'gmsg_' . time() . '_' . uniqid() . '.' . $ext;
    $target = $upload_dir . $new_name;
    
    if (move_uploaded_file($file['tmp_name'], $target)) {
        $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        $is_image = in_array($ext, $image_exts);
        
        $messages = read_json('messenger_group_messages.json');
        if (!is_array($messages)) $messages = [];
        
        $new_msg = [
            'id' => 'gmsg_' . uniqid(),
            'group_id' => $group_id,
            'sender_id' => $my_id,
            'content' => $file['name'],
            'type' => 'file',
            'file_type' => $is_image ? 'image' : 'document',
            'file_path' => 'uploads/messenger/' . $new_name,
            'file_size' => round($file['size'] / 1024 / 1024, 2),
            'timestamp' => date('Y-m-d H:i:s'),
            'read_by' => [$my_id],
            'deleted_for_everyone' => false,
            'deleted_for' => []
        ];
        
        $messages[] = $new_msg;
        write_json('messenger_group_messages.json', $messages);
        
        echo json_encode(['success' => true, 'message' => $new_msg]);
        exit;
    }
    
    echo json_encode(['success' => false]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'عملیات نامعتبر']);
?>