<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/logger.php';

check_login();

$message = '';
$error = '';
$is_viewer = ($_SESSION['admin_role'] ?? '') === 'viewer';

// پاکسازی خودکار پیام‌های زباله‌دان بعد از 30 روز
cleanup_trash();

function cleanup_trash() {
    $trash = read_json('trash_messages.json');
    $changed = false;
    
    foreach ($trash as $key => $item) {
        if (isset($item['deleted_at'])) {
            $delete_time = strtotime($item['deleted_at']);
            if ($delete_time && (time() - $delete_time) > (30 * 24 * 60 * 60)) {
                unset($trash[$key]);
                $changed = true;
            }
        }
    }
    
    if ($changed) {
        write_json('trash_messages.json', array_values($trash));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($is_viewer) {
        $error = '⛔ شما فقط بیننده هستید!';
    } else {
        // علامت‌گذاری خوانده شده
        if ($_POST['action'] === 'mark_read') {
            $messages = read_json('messages.json');
            foreach ($messages as &$msg) {
                if ($msg['id'] === $_POST['message_id']) {
                    $msg['is_read'] = true;
                    break;
                }
            }
            write_json('messages.json', $messages);
            $message = '✅ خوانده شد';
        }
        
        // علامت‌گذاری خوانده نشده
        if ($_POST['action'] === 'mark_unread') {
            $messages = read_json('messages.json');
            foreach ($messages as &$msg) {
                if ($msg['id'] === $_POST['message_id']) {
                    $msg['is_read'] = false;
                    break;
                }
            }
            write_json('messages.json', $messages);
            $message = '✅ به خوانده نشده تغییر کرد';
        }
        
        // انتقال به زباله‌دان
        if ($_POST['action'] === 'delete_message') {
            $messages = read_json('messages.json');
            $trash = read_json('trash_messages.json');
            
            foreach ($messages as $key => $msg) {
                if ($msg['id'] === $_POST['message_id']) {
                    $msg['deleted_at'] = date('Y-m-d H:i:s');
                    $trash[] = $msg;
                    unset($messages[$key]);
                    break;
                }
            }
            
            write_json('messages.json', array_values($messages));
            write_json('trash_messages.json', $trash);
            add_log('delete_message', "پیام به زباله‌دان منتقل شد");
            $message = '✅ پیام به زباله‌دان منتقل شد';
        }
        
        // بازیابی از زباله‌دان
        if ($_POST['action'] === 'restore_message') {
            $trash = read_json('trash_messages.json');
            $messages = read_json('messages.json');
            
            foreach ($trash as $key => $msg) {
                if ($msg['id'] === $_POST['message_id']) {
                    unset($msg['deleted_at']);
                    $messages[] = $msg;
                    unset($trash[$key]);
                    break;
                }
            }
            
            write_json('trash_messages.json', array_values($trash));
            write_json('messages.json', $messages);
            $message = '✅ پیام بازیابی شد';
        }
        
        // حذف کامل از زباله‌دان
        if ($_POST['action'] === 'permanent_delete') {
            $trash = read_json('trash_messages.json');
            
            foreach ($trash as $key => $msg) {
                if ($msg['id'] === $_POST['message_id']) {
                    unset($trash[$key]);
                    break;
                }
            }
            
            write_json('trash_messages.json', array_values($trash));
            $message = '✅ برای همیشه حذف شد';
        }
        
        // خالی کردن زباله‌دان
        if ($_POST['action'] === 'empty_trash') {
            write_json('trash_messages.json', []);
            $message = '✅ زباله‌دان خالی شد';
        }
        
        // علامت‌گذاری همه
        if ($_POST['action'] === 'mark_all_read') {
            $messages = read_json('messages.json');
            foreach ($messages as &$msg) { $msg['is_read'] = true; }
            write_json('messages.json', $messages);
            $message = '✅ همه خوانده شدند';
        }
    }
}

// دریافت پیام‌ها
$all_messages = get_messages();
usort($all_messages, function($a, $b) { 
    return strtotime($b['date']) - strtotime($a['date']); 
});

$trash_messages = read_json('trash_messages.json');
usort($trash_messages, function($a, $b) { 
    return strtotime($b['deleted_at']) - strtotime($a['deleted_at']); 
});

$unread_messages = array_filter($all_messages, function($msg) {
    return empty($msg['is_read']);
});
$read_messages = array_filter($all_messages, function($msg) {
    return !empty($msg['is_read']);
});

$active_tab = $_GET['tab'] ?? 'all';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت پیام‌ها | پنل مدیریت</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .messages-tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 25px;
            background: #fff;
            padding: 8px;
            border-radius: 14px;
            border: 1px solid #e9ecef;
            flex-wrap: wrap;
        }
        .tab-btn {
            padding: 10px 20px;
            border: none;
            background: transparent;
            border-radius: 10px;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: #666;
        }
        .tab-btn:hover { background: #f5f5f5; }
        .tab-btn.active { background: #1a1a1a; color: #fff; }
        .tab-count { background: rgba(0,0,0,0.1); padding: 2px 10px; border-radius: 12px; font-size: 11px; }
        .tab-btn.active .tab-count { background: rgba(255,255,255,0.2); }
        .tab-count.unread-count { background: #ff4757; color: #fff; }
        .tab-count.trash-count { background: #ffa502; color: #fff; }
        
        .messages-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        .message-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e9ecef;
            overflow: hidden;
            transition: all 0.3s;
        }
        .message-card:hover { box-shadow: 0 10px 30px rgba(0,0,0,0.08); transform: translateY(-4px); }
        .message-card.unread { border-top: 4px solid #ff4757; }
        .message-card.read { border-top: 4px solid #2ed573; }
        .message-card.trash { border-top: 4px solid #ffa502; opacity: 0.8; }
        
        .message-card__header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 18px 20px;
            background: #fafafa;
            border-bottom: 1px solid #eee;
        }
        .avatar {
            width: 45px; height: 45px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; font-weight: 900; color: #fff; flex-shrink: 0;
        }
        .avatar.c1 { background: #3742fa; } .avatar.c2 { background: #ff4757; }
        .avatar.c3 { background: #ffa502; } .avatar.c4 { background: #2ed573; }
        .avatar.c5 { background: #a55eea; } .avatar.c6 { background: #ff6b81; }
        .sender-info { flex: 1; min-width: 0; }
        .sender-name { font-size: 14px; font-weight: 800; display: flex; align-items: center; gap: 8px; }
        .sender-email { font-size: 12px; color: #999; }
        .unread-dot { width: 8px; height: 8px; background: #ff4757; border-radius: 50%; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%,100% {opacity:1;} 50% {opacity:0.3;} }
        
        .message-card__body { padding: 18px 20px; }
        .message-subject { font-size: 13px; font-weight: 700; padding: 8px 12px; background: #f5f5f5; border-radius: 8px; margin-bottom: 10px; }
        .message-text { font-size: 13px; color: #666; line-height: 1.8; white-space: pre-wrap; }
        
        .message-card__footer {
            display: flex; justify-content: space-between; align-items: center;
            padding: 15px 20px; border-top: 1px solid #eee; flex-wrap: wrap; gap: 10px;
        }
        .message-meta { display: flex; flex-direction: column; gap: 5px; font-size: 11px; color: #999; }
        .attachment-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #e8f4fd; border-radius: 20px; font-size: 11px; }
        .attachment-chip a { color: #3742fa; text-decoration: none; font-weight: 700; }
        .message-actions { display: flex; gap: 6px; }
        .btn-icon {
            width: 35px; height: 35px; border-radius: 10px;
            border: 1px solid #ddd; background: #fff; cursor: pointer;
            font-size: 14px; display: flex; align-items: center; justify-content: center;
            transition: all 0.3s;
        }
        .btn-icon.read:hover { background: #e8f5e9; border-color: #2ed573; }
        .btn-icon.unread:hover { background: #fff8e1; border-color: #ffa502; }
        .btn-icon.delete:hover { background: #ffebee; border-color: #ff4757; }
        .btn-icon.restore:hover { background: #e8f5e9; border-color: #2ed573; }
        
        .trash-banner {
            background: #fff8e1; border: 1px solid #ffa502; color: #e65100;
            padding: 12px 20px; border-radius: 12px; margin-bottom: 20px;
            font-size: 12px; font-weight: 700;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 10px;
        }
        
        .deleted-date { color: #ff4757; font-size: 11px; }
        .viewer-banner { background: #fff8e1; border: 1px solid #ffa502; color: #e65100; padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; font-size: 13px; font-weight: 700; }
        
        @media (max-width: 768px) {
            .messages-grid { grid-template-columns: 1fr; }
            .messages-tabs { flex-direction: column; }
            .tab-btn { justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="top-bar">
                <h1>📨 پیام‌های تماس</h1>
                <?php if ($is_viewer): ?><span class="badge" style="background:#fff8e1;color:#e65100;">👁️ حالت مشاهده</span><?php endif; ?>
            </header>
            
            <?php if ($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
            <?php if ($is_viewer): ?><div class="viewer-banner">⛔ شما فقط بیننده هستید!</div><?php endif; ?>
            
            <!-- تب‌ها -->
            <div class="messages-tabs">
                <a href="manage-messages.php?tab=all" class="tab-btn <?php echo $active_tab === 'all' ? 'active' : ''; ?>">
                    📋 همه <span class="tab-count"><?php echo count($all_messages); ?></span>
                </a>
                <a href="manage-messages.php?tab=unread" class="tab-btn <?php echo $active_tab === 'unread' ? 'active' : ''; ?>">
                    🔴 خوانده نشده <span class="tab-count unread-count"><?php echo count($unread_messages); ?></span>
                </a>
                <a href="manage-messages.php?tab=read" class="tab-btn <?php echo $active_tab === 'read' ? 'active' : ''; ?>">
                    ✅ خوانده شده <span class="tab-count"><?php echo count($read_messages); ?></span>
                </a>
                <a href="manage-messages.php?tab=trash" class="tab-btn <?php echo $active_tab === 'trash' ? 'active' : ''; ?>">
                    🗑️ زباله‌دان <span class="tab-count trash-count"><?php echo count($trash_messages); ?></span>
                </a>
                
                <?php if (!$is_viewer && count($unread_messages) > 0): ?>
                <form method="POST" style="display:inline;margin-right:auto;">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="tab-btn" style="color:#2e7d32;">✓ خواندن همه</button>
                </form>
                <?php endif; ?>
            </div>
            
            <!-- نمایش زباله‌دان -->
            <?php if ($active_tab === 'trash'): ?>
                <?php if (!empty($trash_messages)): ?>
                    <div class="trash-banner">
                        <span>🗑️ پیام‌های زباله‌دان بعد از ۳۰ روز خودکار حذف می‌شوند</span>
                        <?php if (!$is_viewer): ?>
                        <form method="POST" onsubmit="return confirm('همه پیام‌های زباله‌دان حذف شوند؟');">
                            <input type="hidden" name="action" value="empty_trash">
                            <button type="submit" class="btn btn-danger btn-sm">🗑️ خالی کردن زباله‌دان</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    
                    <div class="messages-grid">
                        <?php foreach ($trash_messages as $index => $msg): ?>
                        <div class="message-card trash">
                            <div class="message-card__header">
                                <div class="avatar c<?php echo ($index % 6) + 1; ?>"><?php echo isset($msg['name'][0]) ? $msg['name'][0] : '؟'; ?></div>
                                <div class="sender-info">
                                    <div class="sender-name"><?php echo $msg['name']; ?></div>
                                    <div class="sender-email"><?php echo $msg['email']; ?></div>
                                </div>
                            </div>
                            <div class="message-card__body">
                                <?php if (!empty($msg['subject'])): ?>
                                    <div class="message-subject">📌 <?php echo $msg['subject']; ?></div>
                                <?php endif; ?>
                                <div class="message-text"><?php echo nl2br($msg['message']); ?></div>
                            </div>
                            <div class="message-card__footer">
                                <div class="message-meta">
                                    <span class="deleted-date">🗑️ حذف شده: <?php echo $msg['deleted_at'] ?? ''; ?></span>
                                    <?php if (!empty($msg['deleted_at'])): 
                                        $days_left = 30 - floor((time() - strtotime($msg['deleted_at'])) / (24 * 60 * 60));
                                    ?>
                                        <span><?php echo max(0, $days_left); ?> روز تا حذف کامل</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$is_viewer): ?>
                                <div class="message-actions">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="restore_message">
                                        <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                                        <button type="submit" class="btn-icon restore" title="بازیابی">↩️</button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('برای همیشه حذف شود؟');">
                                        <input type="hidden" name="action" value="permanent_delete">
                                        <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                                        <button type="submit" class="btn-icon delete" title="حذف کامل">🗑️</button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <p>🗑️</p>
                        <p>زباله‌دان خالی است</p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- نمایش پیام‌های عادی -->
                <?php
                if ($active_tab === 'unread') {
                    $display_messages = array_values($unread_messages);
                } elseif ($active_tab === 'read') {
                    $display_messages = array_values($read_messages);
                } else {
                    $display_messages = $all_messages;
                }
                ?>
                
                <?php if (empty($display_messages)): ?>
                    <div class="empty-state">
                        <p>📭</p>
                        <p>
                            <?php
                            if ($active_tab === 'unread') echo 'پیام خوانده نشده‌ای وجود ندارد';
                            elseif ($active_tab === 'read') echo 'پیام خوانده شده‌ای وجود ندارد';
                            else echo 'پیامی وجود ندارد';
                            ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="messages-grid">
                        <?php foreach ($display_messages as $index => $msg): 
                            $is_read = !empty($msg['is_read']);
                        ?>
                        <div class="message-card <?php echo $is_read ? 'read' : 'unread'; ?>">
                            <div class="message-card__header">
                                <div class="avatar c<?php echo ($index % 6) + 1; ?>"><?php echo isset($msg['name'][0]) ? $msg['name'][0] : '؟'; ?></div>
                                <div class="sender-info">
                                    <div class="sender-name">
                                        <?php echo $msg['name']; ?>
                                        <?php if (!$is_read): ?><span class="unread-dot"></span><?php endif; ?>
                                    </div>
                                    <div class="sender-email"><?php echo $msg['email']; ?></div>
                                </div>
                            </div>
                            <div class="message-card__body">
                                <?php if (!empty($msg['subject'])): ?>
                                    <div class="message-subject">📌 <?php echo $msg['subject']; ?></div>
                                <?php endif; ?>
                                <div class="message-text"><?php echo nl2br($msg['message']); ?></div>
                            </div>
                            <div class="message-card__footer">
                                <div class="message-meta">
                                    <span>📅 <?php echo $msg['date'] ?? ''; ?></span>
                                    <?php if (!empty($msg['phone'])): ?><span>📞 <?php echo $msg['phone']; ?></span><?php endif; ?>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:10px;align-items:flex-end;">
                                    <?php if (!empty($msg['attachment'])): ?>
                                        <span class="attachment-chip">📎 <a href="../<?php echo $msg['attachment']['path']; ?>" target="_blank"><?php echo $msg['attachment']['name']; ?></a></span>
                                    <?php endif; ?>
                                    <?php if (!$is_viewer): ?>
                                    <div class="message-actions">
                                        <?php if (!$is_read): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="mark_read">
                                                <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                                                <button type="submit" class="btn-icon read" title="خوانده شده">✓</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="mark_unread">
                                                <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                                                <button type="submit" class="btn-icon unread" title="خوانده نشده">↩️</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('انتقال به زباله‌دان؟');">
                                            <input type="hidden" name="action" value="delete_message">
                                            <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                                            <button type="submit" class="btn-icon delete" title="انتقال به زباله‌دان">🗑️</button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>