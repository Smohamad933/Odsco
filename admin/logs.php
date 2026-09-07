<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/logger.php';

check_login();

$is_viewer = ($_SESSION['admin_role'] ?? '') === 'viewer';

// دریافت همه لاگ‌ها
$all_logs = get_logs(1000);

// دریافت کاربران برای فیلتر
$users = get_users();

// دسته‌بندی لاگ‌ها
$log_categories = [
    'all' => '📋 همه',
    'login' => '🔐 ورود/خروج',
    'add_project' => '➕ افزودن پروژه',
    'edit_project' => '✏️ ویرایش پروژه',
    'delete_project' => '🗑️ حذف پروژه',
    'add_post' => '➕ افزودن مقاله',
    'edit_post' => '✏️ ویرایش مقاله',
    'delete_post' => '🗑️ حذف مقاله',
    'add_team_member' => '👥 افزودن عضو تیم',
    'edit_team_member' => '✏️ ویرایش عضو تیم',
    'delete_team_member' => '🗑️ حذف عضو تیم',
    'add_category' => '🏷️ افزودن دسته‌بندی',
    'edit_category' => '✏️ ویرایش دسته‌بندی',
    'delete_category' => '🗑️ حذف دسته‌بندی',
    'add_user' => '👤 افزودن کاربر',
    'edit_user' => '✏️ ویرایش کاربر',
    'delete_user' => '🗑️ حذف کاربر',
    'new_message' => '📨 پیام جدید',
    'delete_message' => '🗑️ حذف پیام',
    'update_settings' => '⚙️ تغییر تنظیمات',
    'logout' => '🚪 خروج'
];

// فیلترها
$filter_category = $_GET['category'] ?? 'all';
$filter_user = $_GET['user'] ?? 'all';
$filter_date = $_GET['date'] ?? 'all';
$search_term = $_GET['search'] ?? '';

// اعمال فیلترها
$filtered_logs = $all_logs;

if ($filter_category !== 'all') {
    $filtered_logs = array_filter($filtered_logs, function($log) use ($filter_category) {
        return ($log['action'] ?? '') === $filter_category;
    });
}

if ($filter_user !== 'all') {
    $filtered_logs = array_filter($filtered_logs, function($log) use ($filter_user) {
        return ($log['user'] ?? '') === $filter_user;
    });
}

if ($filter_date === 'today') {
    $filtered_logs = array_filter($filtered_logs, function($log) {
        return date('Y-m-d', strtotime($log['timestamp'])) === date('Y-m-d');
    });
} elseif ($filter_date === 'week') {
    $filtered_logs = array_filter($filtered_logs, function($log) {
        return strtotime($log['timestamp']) > strtotime('-7 days');
    });
} elseif ($filter_date === 'month') {
    $filtered_logs = array_filter($filtered_logs, function($log) {
        return strtotime($log['timestamp']) > strtotime('-30 days');
    });
}

if (!empty($search_term)) {
    $filtered_logs = array_filter($filtered_logs, function($log) use ($search_term) {
        $text = ($log['details'] ?? '') . ' ' . ($log['user'] ?? '') . ' ' . ($log['action'] ?? '');
        return strpos($text, $search_term) !== false;
    });
}

$filtered_logs = array_values($filtered_logs);

// آمار
$total_logs = count($all_logs);
$today_logs = count(array_filter($all_logs, function($log) {
    return date('Y-m-d', strtotime($log['timestamp'])) === date('Y-m-d');
}));
$login_logs = count(array_filter($all_logs, function($log) {
    return ($log['action'] ?? '') === 'login';
}));
$error_logs = count(array_filter($all_logs, function($log) {
    return strpos($log['action'] ?? '', 'error') !== false;
}));
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لاگ سیستم | پنل مدیریت</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .logs-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .stats-grid-mini {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-mini {
            background: #fff;
            padding: 18px;
            border-radius: 14px;
            border: 1px solid #e9ecef;
            text-align: center;
        }
        
        .stat-mini .num {
            font-size: 24px;
            font-weight: 900;
            display: block;
        }
        
        .stat-mini .lbl {
            font-size: 11px;
            color: #999;
        }
        
        .filters-bar {
            background: #fff;
            padding: 20px;
            border-radius: 14px;
            border: 1px solid #e9ecef;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-width: 150px;
            flex: 1;
        }
        
        .filter-group label {
            font-size: 11px;
            font-weight: 700;
            color: #666;
        }
        
        .filter-group select,
        .filter-group input {
            padding: 9px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 12px;
        }
        
        .filter-btn {
            padding: 9px 20px;
            background: #1a1a1a;
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }
        
        .filter-btn.reset {
            background: #f5f5f5;
            color: #666;
        }
        
        .logs-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .log-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 20px;
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e9ecef;
            transition: all 0.3s;
        }
        
        .log-item:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            transform: translateX(-3px);
        }
        
        .log-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .log-icon.login { background: #e8f4fd; }
        .log-icon.add { background: #e8f5e9; }
        .log-icon.edit { background: #fff8e1; }
        .log-icon.delete { background: #ffebee; }
        .log-icon.message { background: #f3e5f5; }
        .log-icon.settings { background: #f5f5f5; }
        .log-icon.user { background: #e0f2f1; }
        .log-icon.team { background: #fff3e0; }
        .log-icon.category { background: #e8eaf6; }
        .log-icon.logout { background: #fce4ec; }
        
        .log-details {
            flex: 1;
            min-width: 0;
        }
        
        .log-action {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 3px;
        }
        
        .log-description {
            font-size: 12px;
            color: #666;
        }
        
        .log-meta {
            text-align: left;
            flex-shrink: 0;
        }
        
        .log-user {
            font-size: 11px;
            font-weight: 700;
            color: #3742fa;
        }
        
        .log-time {
            font-size: 10px;
            color: #999;
        }
        
        .viewer-banner {
            background: #fff8e1;
            border: 1px solid #ffa502;
            color: #e65100;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 13px;
            font-weight: 700;
        }
        
        @media (max-width: 768px) {
            .stats-grid-mini {
                grid-template-columns: repeat(2, 1fr);
            }
            .filters-bar {
                flex-direction: column;
            }
            .filter-group {
                width: 100%;
            }
            .log-item {
                flex-wrap: wrap;
            }
            .log-meta {
                text-align: right;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="top-bar">
                <h1>📋 لاگ سیستم</h1>
                <button onclick="window.location.reload()" class="btn btn-sm">🔄 بروزرسانی</button>
            </header>
            
            <?php if ($is_viewer): ?>
                <div class="viewer-banner">👁️ حالت مشاهده - فقط خواندنی</div>
            <?php endif; ?>
            
            <!-- آمار -->
            <div class="stats-grid-mini">
                <div class="stat-mini">
                    <span class="num"><?php echo $total_logs; ?></span>
                    <span class="lbl">کل لاگ‌ها</span>
                </div>
                <div class="stat-mini">
                    <span class="num"><?php echo $today_logs; ?></span>
                    <span class="lbl">امروز</span>
                </div>
                <div class="stat-mini">
                    <span class="num"><?php echo $login_logs; ?></span>
                    <span class="lbl">ورودها</span>
                </div>
                <div class="stat-mini">
                    <span class="num"><?php echo count($users); ?></span>
                    <span class="lbl">کاربران</span>
                </div>
            </div>
            
            <!-- فیلترها -->
            <div class="filters-bar">
                <form method="GET" style="display:contents;">
                    <div class="filter-group">
                        <label>نوع عملیات:</label>
                        <select name="category" onchange="this.form.submit()">
                            <?php foreach ($log_categories as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo $filter_category === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>کاربر:</label>
                        <select name="user" onchange="this.form.submit()">
                            <option value="all">همه کاربران</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['username']; ?>" <?php echo $filter_user === $user['username'] ? 'selected' : ''; ?>>
                                    <?php echo $user['full_name'] . ' (@' . $user['username'] . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>بازه زمانی:</label>
                        <select name="date" onchange="this.form.submit()">
                            <option value="all" <?php echo $filter_date === 'all' ? 'selected' : ''; ?>>همه</option>
                            <option value="today" <?php echo $filter_date === 'today' ? 'selected' : ''; ?>>امروز</option>
                            <option value="week" <?php echo $filter_date === 'week' ? 'selected' : ''; ?>>۷ روز اخیر</option>
                            <option value="month" <?php echo $filter_date === 'month' ? 'selected' : ''; ?>>۳۰ روز اخیر</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>جستجو:</label>
                        <input type="text" name="search" value="<?php echo $search_term; ?>" placeholder="جستجو...">
                    </div>
                    
                    <button type="submit" class="filter-btn">🔍 اعمال فیلتر</button>
                    <a href="logs.php" class="filter-btn reset" style="text-decoration:none;">🔄 حذف فیلتر</a>
                </form>
            </div>
            
            <!-- لیست لاگ‌ها -->
            <div class="logs-list">
                <?php if (empty($filtered_logs)): ?>
                    <div class="empty-state">
                        <p>📭</p>
                        <p>لاگی با این فیلترها یافت نشد</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($filtered_logs as $log): 
                        $action = $log['action'] ?? '';
                        $icon_class = 'login';
                        $icon = '🔐';
                        
                        if (strpos($action, 'add') !== false) { $icon_class = 'add'; $icon = '➕'; }
                        elseif (strpos($action, 'edit') !== false || strpos($action, 'update') !== false) { $icon_class = 'edit'; $icon = '✏️'; }
                        elseif (strpos($action, 'delete') !== false) { $icon_class = 'delete'; $icon = '🗑️'; }
                        elseif (strpos($action, 'message') !== false) { $icon_class = 'message'; $icon = '📨'; }
                        elseif (strpos($action, 'settings') !== false) { $icon_class = 'settings'; $icon = '⚙️'; }
                        elseif (strpos($action, 'user') !== false) { $icon_class = 'user'; $icon = '👤'; }
                        elseif (strpos($action, 'team') !== false) { $icon_class = 'team'; $icon = '👥'; }
                        elseif (strpos($action, 'category') !== false) { $icon_class = 'category'; $icon = '🏷️'; }
                        elseif (strpos($action, 'logout') !== false) { $icon_class = 'logout'; $icon = '🚪'; }
                        elseif (strpos($action, 'login') !== false) { $icon_class = 'login'; $icon = '🔐'; }
                        
                        $action_label = $log_categories[$action] ?? $action;
                    ?>
                    <div class="log-item">
                        <div class="log-icon <?php echo $icon_class; ?>"><?php echo $icon; ?></div>
                        
                        <div class="log-details">
                            <div class="log-action"><?php echo $action_label; ?></div>
                            <div class="log-description"><?php echo $log['details'] ?? ''; ?></div>
                        </div>
                        
                        <div class="log-meta">
                            <div class="log-user">👤 <?php echo $log['user'] ?? 'guest'; ?></div>
                            <div class="log-time">🕐 <?php echo $log['timestamp'] ?? ''; ?></div>
                            <?php if (!empty($log['ip'])): ?>
                                <div class="log-time">🌐 <?php echo $log['ip']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>