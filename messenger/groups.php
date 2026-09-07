<?php
require_once '../includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['messenger_user_id'])) {
    header('Location: login.php');
    exit;
}

$my_id = $_SESSION['messenger_user_id'];
$my_role = $_SESSION['messenger_user_role'] ?? 'viewer';

$message = '';
$error = '';

// دریافت کاربران
$users_data = read_json('users.json');
$all_users = $users_data['users'] ?? [];

// دریافت گروه‌ها
$groups = read_json('messenger_groups.json');
if (!is_array($groups)) $groups = [];

// ============ ساخت گروه ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_group') {
    $group_name = sanitize($_POST['group_name'] ?? '');
    $group_type = sanitize($_POST['group_type'] ?? 'private');
    $members = $_POST['members'] ?? [];
    
    if (empty($group_name)) {
        $error = '❌ نام گروه را وارد کنید';
    } else {
        if (!is_array($members)) $members = [];
        
        if (!in_array($my_id, $members)) {
            $members[] = $my_id;
        }
        
        $new_group = [
            'id' => 'grp_' . uniqid(),
            'name' => $group_name,
            'type' => $group_type,
            'creator_id' => $my_id,
            'members' => array_values($members),
            'created_at' => date('Y-m-d H:i:s'),
            'pinned_messages' => []
        ];
        
        $groups[] = $new_group;
        
        if (write_json('messenger_groups.json', $groups)) {
            header('Location: group-chat.php?group=' . $new_group['id']);
            exit;
        } else {
            $error = '❌ خطا در ذخیره گروه';
        }
    }
}

// ============ حذف گروه ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_group') {
    $group_id = $_POST['group_id'] ?? '';
    
    foreach ($groups as $key => $group) {
        if ($group['id'] === $group_id) {
            if (($group['creator_id'] ?? '') === $my_id || $my_role === 'admin') {
                unset($groups[$key]);
                write_json('messenger_groups.json', array_values($groups));
                $message = '✅ گروه حذف شد';
            } else {
                $error = '⛔ فقط سازنده گروه می‌تواند حذف کند';
            }
            break;
        }
    }
}

// گروه‌های من
$my_groups = array_filter($groups, function($group) use ($my_id, $my_role) {
    if ($my_role === 'admin') return true;
    return in_array($my_id, $group['members'] ?? []);
});

include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>گروه‌ها | پیام‌رسان</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;700;900&display=swap');
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Vazirmatn', sans-serif; background: #f0f2f5; }
        
        .page { padding: 20px; max-width: 900px; margin: 0 auto; }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        .page-header h1 { font-size: 22px; font-weight: 900; color: #333; }
        
        .btn-create {
            padding: 12px 25px;
            background: #3742fa;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-create:hover { background: #2835d8; transform: translateY(-2px); box-shadow: 0 5px 20px rgba(55,66,250,0.3); }
        
        .back-link {
            display: inline-block;
            margin-bottom: 15px;
            color: #3742fa;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
        }
        
        .alert { padding: 12px 15px; border-radius: 10px; margin-bottom: 15px; font-size: 12px; font-weight: 700; }
        .alert-success { background: #e8f5e9; color: #2e7d32; }
        .alert-error { background: #ffebee; color: #c62828; }
        
        .groups-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        
        .group-card {
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #e9ecef;
            transition: all 0.3s;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .group-card:hover { box-shadow: 0 10px 30px rgba(0,0,0,0.08); transform: translateY(-4px); }
        
        .group-header {
            padding: 20px;
            background: linear-gradient(135deg, #1a1a1a, #333);
            color: #fff;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .group-avatar {
            width: 50px;
            height: 50px;
            border-radius: 15px;
            background: #2c5364;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }
        .group-name { font-size: 14px; font-weight: 800; }
        .group-type { font-size: 10px; color: #aaa; }
        
        .group-body { padding: 15px 20px; }
        .group-members { font-size: 11px; color: #999; }
        
        .type-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 700;
            margin-top: 8px;
        }
        .type-badge.private { background: #e8f4fd; color: #3742fa; }
        .type-badge.project { background: #fff8e1; color: #ffa502; }
        
        /* مودال */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        
        .modal-box {
            background: #fff;
            border-radius: 20px;
            padding: 25px;
            max-width: 450px;
            width: 100%;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-box h2 { text-align: center; margin-bottom: 20px; font-size: 16px; font-weight: 900; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 11px; font-weight: 700; margin-bottom: 5px; color: #555; }
        .form-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            font-family: inherit;
            font-size: 12px;
        }
        .form-input:focus { outline: none; border-color: #3742fa; }
        
        .members-list {
            max-height: 180px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 8px;
        }
        .member-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .member-option:hover { background: #f5f5f5; }
        .member-option input { width: 16px; height: 16px; cursor: pointer; }
        .member-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            object-fit: cover;
            background: #3742fa;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #fff;
            font-size: 10px;
        }
        .member-name { font-size: 11px; font-weight: 700; }
        
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: #3742fa;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            margin-bottom: 8px;
        }
        .btn-cancel {
            width: 100%;
            padding: 10px;
            background: #f5f5f5;
            color: #666;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="page">
        <a href="index.php" class="back-link">← بازگشت به چت‌ها</a>
        
        <div class="page-header">
            <h1>👥 گروه‌ها</h1>
            <button class="btn-create" onclick="document.getElementById('createModal').classList.add('active')">➕ ساخت گروه</button>
        </div>
        
        <?php if ($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
        
        <div class="groups-grid">
            <?php foreach ($my_groups as $group): 
                $member_count = count($group['members'] ?? []);
                $is_creator = ($group['creator_id'] ?? '') === $my_id;
            ?>
            <a href="group-chat.php?group=<?php echo $group['id']; ?>" class="group-card">
                <div class="group-header">
                    <div class="group-avatar">👥</div>
                    <div>
                        <div class="group-name"><?php echo $group['name']; ?></div>
                        <div class="group-type"><?php echo $member_count; ?> عضو</div>
                    </div>
                </div>
                <div class="group-body">
                    <span class="type-badge <?php echo ($group['type'] ?? 'private') === 'project' ? 'project' : 'private'; ?>">
                        <?php echo ($group['type'] ?? 'private') === 'project' ? '🏗️ پروژه' : '👥 خصوصی'; ?>
                    </span>
                    <?php if ($is_creator): ?>
                        <span class="type-badge" style="background:#ffebee;color:#ff4757;">⭐ سازنده</span>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
            
            <?php if (empty($my_groups)): ?>
                <div style="text-align:center;grid-column:1/-1;padding:60px;color:#999;">
                    <p style="font-size:50px;">👥</p>
                    <p style="font-size:13px;">گروهی وجود ندارد</p>
                    <button class="btn-create" style="margin-top:15px;" onclick="document.getElementById('createModal').classList.add('active')">ساخت اولین گروه</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- مودال ساخت گروه -->
    <div class="modal-overlay" id="createModal">
        <div class="modal-box">
            <h2>➕ ساخت گروه جدید</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_group">
                
                <div class="form-group">
                    <label>نام گروه *</label>
                    <input type="text" name="group_name" class="form-input" placeholder="مثلاً: تیم معماری" required>
                </div>
                
                <div class="form-group">
                    <label>نوع گروه</label>
                    <select name="group_type" class="form-input">
                        <option value="private">👥 گروه خصوصی</option>
                        <option value="project">🏗️ گروه پروژه</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>انتخاب اعضا:</label>
                    <div class="members-list">
                        <?php foreach ($sidebar_users as $user): ?>
                        <label class="member-option">
                            <input type="checkbox" name="members[]" value="<?php echo $user['id']; ?>">
                            <?php if (!empty($user['photo'])): ?>
                                <img src="../<?php echo $user['photo']; ?>" class="member-avatar">
                            <?php else: ?>
                                <div class="member-avatar"><?php echo isset($user['full_name'][0]) ? $user['full_name'][0] : '؟'; ?></div>
                            <?php endif; ?>
                            <span class="member-name"><?php echo $user['full_name']; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <button type="submit" class="btn-submit">✅ ساخت گروه</button>
                <button type="button" class="btn-cancel" onclick="document.getElementById('createModal').classList.remove('active')">انصراف</button>
            </form>
        </div>
    </div>
</body>
</html>