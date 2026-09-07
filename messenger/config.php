<?php
// messenger/config.php
// تمام توابع مشترک پیام‌رسان

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/includes/config.php';

// ==============================================
// بررسی لاگین پیام‌رسان
// ==============================================
function m_check_login() {
    if (!isset($_SESSION['messenger_user_id'])) {
        header('Location: login.php');
        exit;
    }
}

// ==============================================
// دریافت کاربر فعلی
// ==============================================
function m_current_user() {
    return [
        'id' => $_SESSION['messenger_user_id'] ?? null,
        'name' => $_SESSION['messenger_user_name'] ?? 'کاربر',
        'photo' => $_SESSION['messenger_user_photo'] ?? '',
        'role' => $_SESSION['messenger_user_role'] ?? 'viewer'
    ];
}

// ==============================================
// دریافت مپ کاربران (id => user)
// ==============================================
function m_user_map() {
    $data = read_json('users.json');
    $users = $data['users'] ?? [];
    
    $map = [];
    foreach ($users as $user) {
        $map[$user['id']] = $user;
    }
    return $map;
}

// ==============================================
// دریافت کاربر با ID
// ==============================================
function m_get_user($user_id) {
    $map = m_user_map();
    return $map[$user_id] ?? null;
}

// ==============================================
// دریافت نام کاربر
// ==============================================
function m_user_name($user_id) {
    $user = m_get_user($user_id);
    return $user['full_name'] ?? 'کاربر';
}

// ==============================================
// دریافت عکس کاربر
// ==============================================
function m_user_photo($user_id) {
    $user = m_get_user($user_id);
    return $user['photo'] ?? '';
}

// ==============================================
// دریافت چت‌های خصوصی کاربر
// ==============================================
function m_private_conversations($my_id) {
    $conversations = read_json('messenger_conversations.json');
    if (!is_array($conversations)) return [];
    
    return array_filter($conversations, function($conv) use ($my_id) {
        return ($conv['user_1'] ?? '') === $my_id || ($conv['user_2'] ?? '') === $my_id;
    });
}

// ==============================================
// پیدا کردن یا ساخت چت خصوصی
// ==============================================
function m_find_or_create_conversation($my_id, $other_id) {
    $conversations = read_json('messenger_conversations.json');
    if (!is_array($conversations)) $conversations = [];
    
    foreach ($conversations as $conv) {
        if (($conv['user_1'] === $my_id && $conv['user_2'] === $other_id) ||
            ($conv['user_1'] === $other_id && $conv['user_2'] === $my_id)) {
            return $conv['id'];
        }
    }
    
    $conv_id = 'conv_' . uniqid();
    $conversations[] = [
        'id' => $conv_id,
        'user_1' => $my_id,
        'user_2' => $other_id,
        'created_at' => date('Y-m-d H:i:s')
    ];
    write_json('messenger_conversations.json', $conversations);
    
    return $conv_id;
}

// ==============================================
// دریافت کاربر مقابل در چت
// ==============================================
function m_other_user_id($conversation_id, $my_id) {
    $conversations = read_json('messenger_conversations.json');
    
    foreach ($conversations as $conv) {
        if ($conv['id'] === $conversation_id) {
            return ($conv['user_1'] === $my_id) ? $conv['user_2'] : $conv['user_1'];
        }
    }
    return null;
}

// ==============================================
// دریافت گروه
// ==============================================
function m_get_group($group_id) {
    $groups = read_json('messenger_groups.json');
    if (!is_array($groups)) return null;
    
    foreach ($groups as $group) {
        if ($group['id'] === $group_id) return $group;
    }
    return null;
}

// ==============================================
// بررسی عضویت در گروه
// ==============================================
function m_is_group_member($group_id, $my_id, $my_role = 'viewer') {
    $group = m_get_group($group_id);
    if (!$group) return false;
    
    if ($my_role === 'admin') return true;
    return in_array($my_id, $group['members'] ?? []);
}

// ==============================================
// دریافت پیام‌های چت خصوصی
// ==============================================
function m_get_private_messages($conversation_id, $my_id) {
    $messages = read_json('messenger_messages.json');
    if (!is_array($messages)) return [];
    
    $result = [];
    foreach ($messages as $msg) {
        if (($msg['conversation_id'] ?? '') !== $conversation_id) continue;
        if (!empty($msg['deleted_for_everyone'])) continue;
        if (in_array($my_id, $msg['deleted_for'] ?? [])) continue;
        $result[] = $msg;
    }
    
    usort($result, function($a, $b) {
        return strtotime($a['timestamp'] ?? 'now') - strtotime($b['timestamp'] ?? 'now');
    });
    
    return $result;
}

// ==============================================
// دریافت پیام‌های گروه
// ==============================================
function m_get_group_messages($group_id, $my_id) {
    $messages = read_json('messenger_group_messages.json');
    if (!is_array($messages)) return [];
    
    $result = [];
    foreach ($messages as $msg) {
        if (($msg['group_id'] ?? '') !== $group_id) continue;
        if (!empty($msg['deleted_for_everyone'])) continue;
        if (in_array($my_id, $msg['deleted_for'] ?? [])) continue;
        
        $msg['sender_name'] = m_user_name($msg['sender_id'] ?? '');
        $result[] = $msg;
    }
    
    usort($result, function($a, $b) {
        return strtotime($a['timestamp'] ?? 'now') - strtotime($b['timestamp'] ?? 'now');
    });
    
    return $result;
}

// ==============================================
// علامت‌گذاری خوانده شده (خصوصی)
// ==============================================
function m_mark_private_read($conversation_id, $my_id) {
    $messages = read_json('messenger_messages.json');
    $changed = false;
    
    foreach ($messages as &$msg) {
        if (($msg['conversation_id'] ?? '') === $conversation_id && !in_array($my_id, $msg['read_by'] ?? [])) {
            $msg['read_by'][] = $my_id;
            $changed = true;
        }
    }
    
    if ($changed) write_json('messenger_messages.json', $messages);
}

// ==============================================
// علامت‌گذاری خوانده شده (گروه)
// ==============================================
function m_mark_group_read($group_id, $my_id) {
    $messages = read_json('messenger_group_messages.json');
    $changed = false;
    
    foreach ($messages as &$msg) {
        if (($msg['group_id'] ?? '') === $group_id && !in_array($my_id, $msg['read_by'] ?? [])) {
            $msg['read_by'][] = $my_id;
            $changed = true;
        }
    }
    
    if ($changed) write_json('messenger_group_messages.json', $messages);
}

// ==============================================
// ساخت لیست کامل چت‌ها (خصوصی + گروه) برای سایدبار
// ==============================================
function m_build_chat_list($my_id, $my_role = 'viewer') {
    $chats = [];
    $total_unread = 0;
    
    // چت‌های خصوصی
    $conversations = m_private_conversations($my_id);
    $private_messages = read_json('messenger_messages.json');
    
    foreach ($conversations as $conv) {
        $other_id = m_other_user_id($conv['id'], $my_id);
        $other_user = m_get_user($other_id);
        if (!$other_user) continue;
        
        $last_msg = '';
        $last_time = '';
        $unread = 0;
        
        foreach ($private_messages as $msg) {
            if (($msg['conversation_id'] ?? '') !== $conv['id']) continue;
            if (!empty($msg['deleted_for_everyone'])) continue;
            if (in_array($my_id, $msg['deleted_for'] ?? [])) continue;
            
            $last_msg = $msg['content'] ?? '';
            $last_time = $msg['timestamp'] ?? '';
            
            if (($msg['sender_id'] ?? '') !== $my_id && !in_array($my_id, $msg['read_by'] ?? [])) {
                $unread++;
            }
        }
        
        $total_unread += $unread;
        $chats[] = [
            'id' => $conv['id'],
            'type' => 'private',
            'name' => $other_user['full_name'] ?? 'کاربر',
            'photo' => $other_user['photo'] ?? '',
            'last_message' => $last_msg,
            'last_time' => $last_time,
            'unread_count' => $unread,
            'url' => 'chat.php?conversation=' . $conv['id']
        ];
    }
    
    // گروه‌ها
    $groups = read_json('messenger_groups.json');
    $group_messages = read_json('messenger_group_messages.json');
    
    foreach ($groups as $group) {
        $is_member = in_array($my_id, $group['members'] ?? []);
        if (!$is_member && $my_role !== 'admin') continue;
        
        $last_msg = '';
        $last_time = '';
        $unread = 0;
        
        foreach ($group_messages as $msg) {
            if (($msg['group_id'] ?? '') !== $group['id']) continue;
            if (!empty($msg['deleted_for_everyone'])) continue;
            if (in_array($my_id, $msg['deleted_for'] ?? [])) continue;
            
            $last_msg = $msg['content'] ?? '';
            $last_time = $msg['timestamp'] ?? '';
            
            if (($msg['sender_id'] ?? '') !== $my_id && !in_array($my_id, $msg['read_by'] ?? [])) {
                $unread++;
            }
        }
        
        $total_unread += $unread;
        $chats[] = [
            'id' => $group['id'],
            'type' => 'group',
            'name' => $group['name'] ?? 'گروه',
            'photo' => '',
            'last_message' => $last_msg,
            'last_time' => $last_time,
            'unread_count' => $unread,
            'url' => 'group-chat.php?group=' . $group['id']
        ];
    }
    
    usort($chats, function($a, $b) {
        return strtotime($b['last_time'] ?? '2000-01-01') - strtotime($a['last_time'] ?? '2000-01-01');
    });
    
    return ['chats' => $chats, 'total_unread' => $total_unread];
}

// ==============================================
// دریافت کاربران قابل چت
// ==============================================
function m_available_users($my_id) {
    $all_users = m_user_map();
    $available = [];
    
    foreach ($all_users as $user) {
        if ($user['id'] === $my_id) continue;
        
        $role = $user['role'] ?? 'viewer';
        if ($role === 'admin' || $role === 'manager') {
            $available[] = $user;
        } elseif (!empty($user['messenger_enabled']) && ($user['is_active'] ?? true)) {
            $available[] = $user;
        }
    }
    
    return $available;
}

// ==============================================
// ثبت آنلاین بودن
// ==============================================
function m_heartbeat($my_id) {
    $online = read_json('messenger_online.json');
    if (!is_array($online)) $online = [];
    
    $online[$my_id] = time();
    
    foreach ($online as $uid => $time) {
        if (time() - $time > 120) unset($online[$uid]);
    }
    
    write_json('messenger_online.json', $online);
}

// ==============================================
// بررسی آنلاین بودن
// ==============================================
function m_is_online($user_id) {
    $online = read_json('messenger_online.json');
    if (!is_array($online)) return false;
    
    return isset($online[$user_id]) && (time() - $online[$user_id]) < 120;
}
?>