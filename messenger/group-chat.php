<?php
require_once 'config.php';

m_check_login();

$me = m_current_user();
$my_id = $me['id'];
$my_role = $me['role'];

$group_id = $_GET['group'] ?? '';

if (empty($group_id)) {
    header('Location: index.php');
    exit;
}

// دریافت گروه
$group = m_get_group($group_id);
if (!$group) {
    header('Location: index.php');
    exit;
}

// بررسی عضویت
if (!m_is_group_member($group_id, $my_id, $my_role)) {
    header('Location: index.php');
    exit;
}

// دریافت پیام‌ها
$chat_messages = m_get_group_messages($group_id, $my_id);

// علامت‌گذاری خوانده شده
m_mark_group_read($group_id, $my_id);

// ثبت آنلاین
m_heartbeat($my_id);

include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $group['name']; ?> | پیام‌رسان</title>
    <style>
        @font-face {
            font-family: 'Abar';
            src: url('../assets/abarfanum-vf.ttf') format('truetype');
            font-weight: 100 900;
            font-display: swap;
        }
        
        :root {
            --tg-bg: #0e1621;
            --tg-sidebar: #17212b;
            --tg-hover: #202b36;
            --tg-active: #2b5278;
            --tg-text: #ffffff;
            --tg-muted: #708499;
            --tg-accent: #5288c1;
            --tg-msg-sent: #2b5278;
            --tg-msg-received: #17212b;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Abar', sans-serif; background: var(--tg-bg); color: var(--tg-text); overflow: hidden; height: 100vh; }
        
        .app { display: flex; height: 100vh; }
        
        /* سایدبار */
        .sidebar { width: 380px; background: var(--tg-sidebar); border-left: 1px solid #0b1219; display: flex; flex-direction: column; flex-shrink: 0; }
        .sidebar-header { padding: 10px 15px; display: flex; align-items: center; gap: 10px; }
        .search-input { flex: 1; padding: 10px 15px; background: var(--tg-bg); border: none; border-radius: 20px; font-family: inherit; font-size: 13px; color: var(--tg-text); outline: none; }
        
        .chat-list { flex: 1; overflow-y: auto; }
        .chat-item { display: flex; align-items: center; padding: 10px 12px; cursor: pointer; text-decoration: none; color: inherit; }
        .chat-item:hover { background: var(--tg-hover); }
        .chat-item.active { background: var(--tg-active); }
        .avatar {
            width: 50px; height: 50px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--tg-accent);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; color: #fff;
            margin-left: 10px; flex-shrink: 0;
        }
        .chat-name { font-size: 14px; font-weight: 700; }
        .chat-preview { font-size: 12px; color: var(--tg-muted); }
        .unread-badge { background: var(--tg-accent); color: #fff; font-size: 10px; padding: 2px 6px; border-radius: 10px; }
        
        /* بخش چت */
        .chat-area { flex: 1; display: flex; flex-direction: column; background: var(--tg-bg); }
        
        .chat-header {
            padding: 10px 15px;
            background: var(--tg-sidebar);
            border-bottom: 1px solid #0b1219;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .back-btn { color: var(--tg-muted); text-decoration: none; font-size: 18px; }
        
        .messages-area {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .message {
            max-width: 55%;
            padding: 8px 12px;
            border-radius: 12px;
            font-size: 13px;
            line-height: 1.5;
        }
        .message.sent {
            align-self: flex-end;
            background: var(--tg-msg-sent);
            border-bottom-left-radius: 4px;
        }
        .message.received {
            align-self: flex-start;
            background: var(--tg-msg-received);
            border-bottom-right-radius: 4px;
        }
        
        .sender-name {
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 3px;
        }
        .message.sent .sender-name { color: rgba(255,255,255,0.6); }
        .message.received .sender-name { color: var(--tg-accent); }
        
        .message-time {
            font-size: 10px;
            opacity: 0.6;
            display: block;
            text-align: left;
            margin-top: 3px;
        }
        
        /* فایل */
        .image-message img {
            max-width: 200px;
            max-height: 200px;
            border-radius: 8px;
            cursor: pointer;
        }
        .file-message { display: flex; align-items: center; gap: 8px; }
        .file-icon { font-size: 25px; }
        .file-name { font-size: 11px; word-break: break-all; }
        .file-size { font-size: 9px; opacity: 0.7; }
        .file-link { color: #fff; text-decoration: none; font-size: 16px; }
        .message.received .file-link { color: var(--tg-accent); }
        
        .input-area {
            padding: 10px 15px;
            background: var(--tg-sidebar);
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .chat-input {
            flex: 1;
            padding: 10px 15px;
            background: var(--tg-bg);
            border: none;
            border-radius: 18px;
            font-family: inherit;
            font-size: 13px;
            color: var(--tg-text);
            outline: none;
        }
        .file-btn {
            width: 38px; height: 38px;
            border-radius: 50%;
            background: transparent;
            border: none;
            cursor: pointer;
            color: var(--tg-muted);
            font-size: 16px;
        }
        .file-btn:hover { background: var(--tg-hover); }
        .send-btn {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: var(--tg-accent);
            border: none;
            cursor: pointer;
            color: #fff;
            font-size: 16px;
        }
        .send-btn:hover { background: #6a9fd0; }
        
        @media (max-width: 768px) {
            .sidebar { display: none; }
        }
    </style>
</head>
<body>
    <div class="app">
        <!-- سایدبار -->
        <div class="sidebar">
            <div class="sidebar-header">
                <input type="text" class="search-input" placeholder="جستجو...">
            </div>
            <div class="chat-list">
                <?php foreach ($sidebar_chats as $chat): ?>
                <a href="<?php echo $chat['url']; ?>" class="chat-item <?php echo ($chat['id'] === $group_id) ? 'active' : ''; ?>">
                    <?php if ($chat['type'] === 'group'): ?>
                        <div class="avatar">👥</div>
                    <?php elseif (!empty($chat['photo'])): ?>
                        <img src="../<?php echo $chat['photo']; ?>" class="avatar">
                    <?php else: ?>
                        <div class="avatar"><?php echo isset($chat['name'][0]) ? $chat['name'][0] : '؟'; ?></div>
                    <?php endif; ?>
                    <div style="flex:1;min-width:0;">
                        <div class="chat-name"><?php echo $chat['name']; ?></div>
                        <div class="chat-preview"><?php echo $chat['last_message'] ?: '...'; ?></div>
                    </div>
                    <?php if ($chat['unread_count'] > 0): ?>
                        <span class="unread-badge"><?php echo $chat['unread_count']; ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- بخش چت گروه -->
        <div class="chat-area">
            <div class="chat-header">
                <a href="index.php" class="back-btn">←</a>
                <div style="width:35px;height:35px;border-radius:50%;background:var(--tg-accent);display:flex;align-items:center;justify-content:center;font-size:14px;">👥</div>
                <div>
                    <div style="font-size:14px;font-weight:700;"><?php echo $group['name']; ?></div>
                    <div style="font-size:10px;color:var(--tg-muted);"><?php echo count($group['members'] ?? []); ?> عضو</div>
                </div>
            </div>
            
            <div class="messages-area" id="messagesArea">
                <?php foreach ($chat_messages as $msg): 
                    $is_sent = $msg['sender_id'] === $my_id;
                ?>
                <div class="message <?php echo $is_sent ? 'sent' : 'received'; ?>" data-id="<?php echo $msg['id']; ?>">
                    <?php if (!$is_sent): ?>
                        <div class="sender-name"><?php echo $msg['sender_name'] ?? 'کاربر'; ?></div>
                    <?php endif; ?>
                    
                    <?php if (($msg['type'] ?? 'text') === 'file'): ?>
                        <?php if (($msg['file_type'] ?? '') === 'image'): ?>
                            <div class="image-message"><img src="../<?php echo $msg['file_path']; ?>"></div>
                        <?php else: ?>
                            <div class="file-message">
                                <span class="file-icon">📁</span>
                                <div style="flex:1;">
                                    <div class="file-name"><?php echo $msg['content']; ?></div>
                                    <div class="file-size"><?php echo $msg['file_size'] ?? ''; ?> MB</div>
                                </div>
                                <a href="../<?php echo $msg['file_path']; ?>" download class="file-link">⬇️</a>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php echo $msg['content']; ?>
                    <?php endif; ?>
                    
                    <span class="message-time"><?php echo date('H:i', strtotime($msg['timestamp'])); ?><?php echo $is_sent ? ' ✓' : ''; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="input-area">
                <input type="file" id="fileInput" style="display:none;" onchange="sendFile()">
                <button class="file-btn" onclick="document.getElementById('fileInput').click()">📎</button>
                <input type="text" id="messageInput" class="chat-input" placeholder="پیام..." autocomplete="off">
                <button class="send-btn" onclick="sendMessage()">➤</button>
            </div>
        </div>
    </div>
    
    <script>
    const groupId = '<?php echo $group_id; ?>';
    const myId = '<?php echo $my_id; ?>';
    
    function scrollToBottom() { const a = document.getElementById('messagesArea'); if (a) a.scrollTop = a.scrollHeight; }
    scrollToBottom();
    
    function escapeHtml(t) { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
    
    function sendMessage() {
        const input = document.getElementById('messageInput');
        const content = input.value.trim();
        if (!content) return;
        
        fetch('api/send.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'type=group&group_id=' + groupId + '&content=' + encodeURIComponent(content)
        })
        .then(r => r.json())
        .then(d => { if (d.success) { input.value = ''; loadMessages(); } });
    }
    
    function sendFile() {
        const input = document.getElementById('fileInput');
        const file = input.files[0];
        if (!file) return;
        
        const fd = new FormData();
        fd.append('type', 'group_file');
        fd.append('group_id', groupId);
        fd.append('file', file);
        
        fetch('api/send.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.success) { loadMessages(); } input.value = ''; });
    }
    
    document.getElementById('messageInput')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') sendMessage();
    });
    
    function loadMessages() {
        fetch('api/get.php?type=group&group_id=' + groupId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const area = document.getElementById('messagesArea');
                data.messages.forEach(msg => {
                    if (!area.querySelector('[data-id="' + msg.id + '"]')) {
                        const isSent = msg.sender_id === myId;
                        const div = document.createElement('div');
                        div.className = 'message ' + (isSent ? 'sent' : 'received');
                        div.dataset.id = msg.id;
                        
                        let senderHtml = '';
                        if (!isSent) {
                            senderHtml = '<div class="sender-name">' + escapeHtml(msg.sender_name || 'کاربر') + '</div>';
                        }
                        
                        let content = '';
                        if (msg.type === 'file' && msg.file_type === 'image') {
                            content = '<div class="image-message"><img src="../' + msg.file_path + '"></div>';
                        } else if (msg.type === 'file') {
                            content = '<div class="file-message"><span class="file-icon">📁</span><div style="flex:1;"><div class="file-name">' + escapeHtml(msg.content) + '</div><div class="file-size">' + (msg.file_size || '') + ' MB</div></div><a href="../' + msg.file_path + '" download class="file-link">⬇️</a></div>';
                        } else {
                            content = escapeHtml(msg.content);
                        }
                        
                        div.innerHTML = senderHtml + content + '<span class="message-time">' + new Date(msg.timestamp).toLocaleTimeString('fa-IR', {hour:'2-digit',minute:'2-digit'}) + '</span>';
                        area.appendChild(div);
                    }
                });
                scrollToBottom();
            }
        });
    }
    
    setInterval(loadMessages, 2000);
    </script>
</body>
</html>