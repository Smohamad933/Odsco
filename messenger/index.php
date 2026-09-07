<?php
require_once 'config.php';

m_check_login();

$me = m_current_user();
$my_id = $me['id'];

include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پیام‌رسان</title>
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
            --tg-online: #4dcd5e;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Abar', sans-serif; background: var(--tg-bg); color: var(--tg-text); overflow: hidden; height: 100vh; }
        
        .app { display: flex; height: 100vh; }
        
        /* سایدبار */
        .sidebar { width: 400px; background: var(--tg-sidebar); border-left: 1px solid #0b1219; display: flex; flex-direction: column; flex-shrink: 0; position: relative; }
        
        .sidebar-header { padding: 10px 15px; display: flex; align-items: center; gap: 10px; }
        .menu-btn { width: 40px; height: 40px; border-radius: 50%; background: transparent; border: none; color: var(--tg-muted); font-size: 18px; cursor: pointer; }
        .menu-btn:hover { background: var(--tg-hover); }
        .search-input { flex: 1; padding: 10px 15px; background: var(--tg-bg); border: none; border-radius: 20px; font-family: inherit; font-size: 13px; color: var(--tg-text); outline: none; }
        .search-input::placeholder { color: var(--tg-muted); }
        
        .chat-list { flex: 1; overflow-y: auto; }
        .chat-list::-webkit-scrollbar { width: 4px; }
        .chat-list::-webkit-scrollbar-thumb { background: var(--tg-hover); }
        
        .chat-item {
            display: flex;
            align-items: center;
            padding: 10px 12px;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            transition: all 0.15s;
            position: relative;
        }
        .chat-item:hover { background: var(--tg-hover); }
        .chat-item.active { background: var(--tg-active); }
        
        .avatar-wrap { position: relative; flex-shrink: 0; margin-left: 10px; }
        .avatar {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--tg-accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #fff;
        }
        .online-dot {
            position: absolute;
            bottom: 2px;
            left: 2px;
            width: 12px;
            height: 12px;
            background: var(--tg-online);
            border-radius: 50%;
            border: 2px solid var(--tg-sidebar);
        }
        
        .chat-info { flex: 1; min-width: 0; }
        .chat-top { display: flex; justify-content: space-between; align-items: center; }
        .chat-name {
            font-size: 15px;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .chat-time { font-size: 11px; color: var(--tg-muted); flex-shrink: 0; }
        
        .chat-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 3px; }
        .chat-preview {
            font-size: 13px;
            color: var(--tg-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex: 1;
        }
        .unread-badge {
            background: var(--tg-accent);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 12px;
            min-width: 20px;
            text-align: center;
            flex-shrink: 0;
        }
        .check-mark { color: var(--tg-muted); font-size: 12px; }
        .check-mark.read { color: var(--tg-online); }
        
        /* دکمه شناور */
        .fab {
            position: absolute;
            bottom: 20px;
            left: 20px;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--tg-accent);
            border: none;
            cursor: pointer;
            color: #fff;
            font-size: 24px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .fab:hover { background: #6a9fd0; }
        
        /* بخش اصلی */
        .main-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: var(--tg-bg);
        }
        .empty-icon {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: var(--tg-sidebar);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            margin-bottom: 20px;
        }
        .empty-title { font-size: 20px; font-weight: 700; margin-bottom: 5px; }
        .empty-subtitle { font-size: 14px; color: var(--tg-muted); }
        
        /* مودال */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: var(--tg-sidebar);
            border-radius: 16px;
            padding: 25px;
            max-width: 400px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-box h2 { font-size: 18px; font-weight: 700; text-align: center; margin-bottom: 20px; }
        
        .user-list { max-height: 300px; overflow-y: auto; }
        .user-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            cursor: pointer;
            border-radius: 10px;
            text-decoration: none;
            color: inherit;
        }
        .user-option:hover { background: var(--tg-hover); }
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--tg-accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: #fff;
        }
        .user-name { font-size: 14px; font-weight: 700; }
        .user-status { font-size: 11px; color: var(--tg-muted); }
        
        @media (max-width: 768px) {
            .sidebar { width: 100%; }
            .main-area { display: none; }
        }
    </style>
</head>
<body>
    <div class="app">
        <!-- سایدبار -->
        <div class="sidebar">
            <div class="sidebar-header">
                <button class="menu-btn">☰</button>
                <input type="text" class="search-input" placeholder="جستجو..." onkeyup="searchChats(this.value)">
            </div>
            
            <div class="chat-list" id="chatList">
                <?php if (empty($sidebar_chats)): ?>
                    <div style="text-align:center;padding:50px;color:var(--tg-muted);">
                        <p style="font-size:40px;margin-bottom:10px;">💬</p>
                        <p style="font-size:14px;">گفتگویی وجود ندارد</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($sidebar_chats as $chat): ?>
                    <a href="<?php echo $chat['url']; ?>" class="chat-item" data-name="<?php echo $chat['name']; ?>">
                        <div class="avatar-wrap">
                            <?php if ($chat['type'] === 'group'): ?>
                                <div class="avatar">👥</div>
                            <?php elseif (!empty($chat['photo'])): ?>
                                <img src="../<?php echo $chat['photo']; ?>" class="avatar">
                            <?php else: ?>
                                <div class="avatar"><?php echo isset($chat['name'][0]) ? $chat['name'][0] : '؟'; ?></div>
                            <?php endif; ?>
                            <span class="online-dot"></span>
                        </div>
                        
                        <div class="chat-info">
                            <div class="chat-top">
                                <span class="chat-name"><?php echo $chat['name']; ?></span>
                                <?php if (!empty($chat['last_time'])): ?>
                                    <span class="chat-time"><?php echo date('H:i', strtotime($chat['last_time'])); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="chat-bottom">
                                <span class="chat-preview"><?php echo $chat['last_message'] ?: '...'; ?></span>
                                <?php if ($chat['unread_count'] > 0): ?>
                                    <span class="unread-badge"><?php echo $chat['unread_count']; ?></span>
                                <?php else: ?>
                                    <span class="check-mark read">✓✓</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <button class="fab" onclick="document.getElementById('newChatModal').classList.add('active')">✏️</button>
        </div>
        
        <!-- بخش اصلی -->
        <div class="main-area">
            <div class="empty-icon">💬</div>
            <div class="empty-title">پیام‌رسان افق دانش ثریا</div>
            <div class="empty-subtitle">یک گفتگو را انتخاب کنید</div>
        </div>
    </div>
    
    <!-- مودال چت جدید -->
    <div class="modal-overlay" id="newChatModal">
        <div class="modal-box">
            <h2>پیام جدید</h2>
            <div class="user-list">
                <?php foreach ($sidebar_users as $user): ?>
                <a href="chat.php?user=<?php echo $user['id']; ?>" class="user-option">
                    <?php if (!empty($user['photo'])): ?>
                        <img src="../<?php echo $user['photo']; ?>" class="user-avatar">
                    <?php else: ?>
                        <div class="user-avatar"><?php echo isset($user['full_name'][0]) ? $user['full_name'][0] : '؟'; ?></div>
                    <?php endif; ?>
                    <div>
                        <div class="user-name"><?php echo $user['full_name']; ?></div>
                        <div class="user-status"><?php echo $user['role'] ?? ''; ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <script>
    function searchChats(query) {
        query = query.toLowerCase();
        document.querySelectorAll('.chat-item').forEach(item => {
            item.style.display = item.dataset.name.toLowerCase().includes(query) ? 'flex' : 'none';
        });
    }
    </script>
</body>
</html>