<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/logger.php';

check_login();

$user_name = $_SESSION['admin_name'] ?? 'کاربر';
$user_role = $_SESSION['admin_role'] ?? 'viewer';
$user_photo = $_SESSION['admin_photo'] ?? '';

// تعیین سلام بر اساس ساعت
$hour = date('G');
if ($hour >= 5 && $hour < 12) {
    $greeting = 'صبح بخیر';
} elseif ($hour >= 12 && $hour < 17) {
    $greeting = 'ظهر بخیر';
} elseif ($hour >= 17 && $hour < 21) {
    $greeting = 'عصر بخیر';
} else {
    $greeting = 'شب بخیر';
}

// ============ آمار بازدید ============
$total_views = get_total_views();
$today_views = get_today_views();
// =====================================

// آمار کلی
$total_projects = count(read_json('projects.json'));
$total_posts = count(read_json('blog_posts.json'));
$total_messages = count(read_json('messages.json'));
$unread_messages = count(get_messages(true));
$total_team = count(read_json('team.json'));
$total_categories = count(read_json('categories.json'));
$total_users = count(get_users());

// پست پربازدید
$most_viewed_post = null;
$max_views = 0;
foreach (get_blog_posts() as $post) {
    if (($post['views'] ?? 0) > $max_views) {
        $max_views = $post['views'] ?? 0;
        $most_viewed_post = $post;
    }
}

// پروژه پربازدید
$most_viewed_project = null;
$max_pv = 0;
foreach (get_projects() as $project) {
    if (($project['views'] ?? 0) > $max_pv) {
        $max_pv = $project['views'] ?? 0;
        $most_viewed_project = $project;
    }
}

// نام فارسی نقش
$role_names = [
    'admin' => 'ادمین کل',
    'manager' => 'مدیر',
    'editor' => 'ویراستار',
    'article_writer' => 'نویسنده مقاله',
    'project_writer' => 'نویسنده پروژه',
    'viewer' => 'بیننده'
];
$role_name_fa = $role_names[$user_role] ?? 'کاربر';

// آیکون نقش
$role_icons = [
    'admin' => '👑',
    'manager' => '🛡️',
    'editor' => '📝',
    'article_writer' => '✍️',
    'project_writer' => '🏗️',
    'viewer' => '👁️'
];
$role_icon = $role_icons[$user_role] ?? '👤';

// لاگ‌های اخیر
$recent_logs = get_logs(8);

// تاریخ امروز
$today_date = date('Y/m/d');
$today_fa = format_date(date('Y-m-d'));
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>داشبورد | پنل مدیریت</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        /* ============ داشبورد ============ */
        .top-bar {
            margin-bottom: 25px;
        }
        
        .top-bar h1 {
            font-size: 24px;
            font-weight: 900;
            color: #333;
        }
        
        .welcome-section {
            background: linear-gradient(135deg, #1a1a1a 0%, #333 50%, #1a1a1a 100%);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-section::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.05) 1px, transparent 1px);
            background-size: 30px 30px;
            animation: patternMove 20s linear infinite;
        }
        
        @keyframes patternMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(30px, 30px); }
        }
        
        .welcome-info {
            position: relative;
            z-index: 1;
        }
        
        .welcome-greeting {
            font-size: 14px;
            color: #aaa;
            margin-bottom: 5px;
        }
        
        .welcome-name {
            font-size: 28px;
            font-weight: 900;
            margin-bottom: 8px;
        }
        
        .welcome-role {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            background: rgba(255,255,255,0.1);
        }
        
        .welcome-date {
            font-size: 12px;
            color: #888;
            margin-top: 10px;
        }
        
        .welcome-photo {
            position: relative;
            z-index: 1;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid rgba(255,255,255,0.3);
            flex-shrink: 0;
        }
        
        .welcome-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .welcome-photo-fallback {
            width: 100%;
            height: 100%;
            background: #3742fa;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            font-weight: 900;
        }
        
        /* استات‌ها */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 25px;
        }
        
        .stat-box {
            background: #fff;
            padding: 22px;
            border-radius: 16px;
            border: 1px solid #e9ecef;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .stat-box::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100%;
            height: 4px;
            transform: scaleX(0);
            transform-origin: right;
            transition: transform 0.4s;
        }
        
        .stat-box:hover::before {
            transform: scaleX(1);
        }
        
        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        
        .stat-box.blue::before { background: #3742fa; }
        .stat-box.green::before { background: #2ed573; }
        .stat-box.orange::before { background: #ffa502; }
        .stat-box.red::before { background: #ff4757; }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 15px;
        }
        
        .stat-icon.blue { background: #e8f4fd; }
        .stat-icon.green { background: #e8f5e9; }
        .stat-icon.orange { background: #fff8e1; }
        .stat-icon.red { background: #ffebee; }
        
        .stat-number {
            display: block;
            font-size: 28px;
            font-weight: 900;
            margin-bottom: 3px;
        }
        
        .stat-label {
            font-size: 12px;
            color: #999;
        }
        
        /* بخش‌های پایین */
        .dashboard-bottom {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 25px;
            border: 1px solid #e9ecef;
        }
        
        .card h2 {
            font-size: 16px;
            font-weight: 800;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* پست پربازدید */
        .top-post-card {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #fafafa;
            border-radius: 12px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        
        .top-post-card:hover {
            background: #f0f0f0;
            transform: translateX(-5px);
        }
        
        .top-post-img {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .top-post-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .top-post-info {
            flex: 1;
            min-width: 0;
        }
        
        .top-post-title {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .top-post-meta {
            font-size: 11px;
            color: #999;
        }
        
        .top-post-views {
            font-size: 20px;
            font-weight: 900;
            color: #3742fa;
            white-space: nowrap;
        }
        
        /* لاگ‌ها */
        .log-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid #f5f5f5;
            font-size: 12px;
        }
        
        .log-item:last-child {
            border-bottom: none;
        }
        
        .log-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        
        .log-dot.add { background: #2ed573; }
        .log-dot.edit { background: #ffa502; }
        .log-dot.delete { background: #ff4757; }
        .log-dot.login { background: #3742fa; }
        .log-dot.other { background: #999; }
        
        .log-text {
            flex: 1;
            color: #666;
        }
        
        .log-time {
            font-size: 10px;
            color: #999;
            white-space: nowrap;
        }
        
        /* ریسپانسیو */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .dashboard-bottom {
                grid-template-columns: 1fr;
            }
            .welcome-section {
                flex-direction: column;
                text-align: center;
            }
            .welcome-info {
                text-align: center;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .welcome-section {
                padding: 20px;
            }
            .welcome-name {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="top-bar">
                <h1>📊 داشبورد</h1>
            </header>
            
            <!-- خوش‌آمدگویی -->
            <div class="welcome-section">
                <div class="welcome-info">
                    <div class="welcome-greeting"><?php echo $greeting; ?> 👋</div>
                    <div class="welcome-name"><?php echo $user_name; ?></div>
                    <span class="welcome-role"><?php echo $role_icon . ' ' . $role_name_fa; ?></span>
                    <div class="welcome-date">📅 امروز: <?php echo $today_fa; ?></div>
                </div>
                
                <div class="welcome-photo">
                    <?php if (!empty($user_photo)): ?>
                        <img src="../<?php echo $user_photo; ?>" alt="<?php echo $user_name; ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="welcome-photo-fallback" style="display:none;"><?php echo isset($user_name[0]) ? $user_name[0] : '؟'; ?></div>
                    <?php else: ?>
                        <div class="welcome-photo-fallback"><?php echo isset($user_name[0]) ? $user_name[0] : '؟'; ?></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- استات‌ها -->
            <div class="stats-grid">
                <div class="stat-box blue">
                    <div class="stat-icon blue">👁️</div>
                    <span class="stat-number"><?php echo number_format($total_views); ?></span>
                    <span class="stat-label">کل بازدید سایت</span>
                </div>
                
                <div class="stat-box green">
                    <div class="stat-icon green">📅</div>
                    <span class="stat-number"><?php echo number_format($today_views); ?></span>
                    <span class="stat-label">بازدید امروز</span>
                </div>
                
                <div class="stat-box orange">
                    <div class="stat-icon orange">🏗️</div>
                    <span class="stat-number"><?php echo $total_projects; ?></span>
                    <span class="stat-label">پروژه‌ها</span>
                </div>
                
                <div class="stat-box red">
                    <div class="stat-icon red">📨</div>
                    <span class="stat-number"><?php echo $unread_messages; ?></span>
                    <span class="stat-label">پیام‌های جدید</span>
                </div>
            </div>
            
            <!-- بخش پایین -->
            <div class="dashboard-bottom">
                <!-- پربازدیدترین‌ها -->
                <div class="card">
                    <h2>🔥 پربازدیدترین‌ها</h2>
                    
                    <?php if ($most_viewed_post): ?>
                        <div class="top-post-card">
                            <div class="top-post-img">
                                <img src="../<?php echo $most_viewed_post['image'] ?? 'assets/default-post.jpg'; ?>" onerror="this.src='../assets/default-post.jpg'">
                            </div>
                            <div class="top-post-info">
                                <div class="top-post-title">📝 <?php echo $most_viewed_post['title']; ?></div>
                                <div class="top-post-meta">👤 <?php echo $most_viewed_post['author'] ?? 'مدیر'; ?> | 📅 <?php echo format_date($most_viewed_post['date']); ?></div>
                            </div>
                            <span class="top-post-views"><?php echo $most_viewed_post['views'] ?? 0; ?> 👁️</span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($most_viewed_project): ?>
                        <div class="top-post-card">
                            <div class="top-post-img">
                                <?php $cover = $most_viewed_project['cover_image'] ?? ($most_viewed_project['images'][0] ?? 'assets/default-project.jpg'); ?>
                                <img src="../<?php echo $cover; ?>" onerror="this.src='../assets/default-project.jpg'">
                            </div>
                            <div class="top-post-info">
                                <div class="top-post-title">🏗️ <?php echo $most_viewed_project['title']; ?></div>
                                <div class="top-post-meta">🏷️ <?php echo $most_viewed_project['category'] ?? ''; ?></div>
                            </div>
                            <span class="top-post-views"><?php echo $most_viewed_project['views'] ?? 0; ?> 👁️</span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!$most_viewed_post && !$most_viewed_project): ?>
                        <p style="text-align:center;color:#999;padding:20px;">هنوز بازدیدی ثبت نشده</p>
                    <?php endif; ?>
                </div>
                
                <!-- فعالیت‌های اخیر -->
                <div class="card">
                    <h2>📋 فعالیت‌های اخیر</h2>
                    
                    <?php if (!empty($recent_logs)): ?>
                        <?php foreach ($recent_logs as $log): 
                            $action = $log['action'] ?? '';
                            $dot_class = 'other';
                            if (strpos($action, 'add') !== false) $dot_class = 'add';
                            elseif (strpos($action, 'edit') !== false) $dot_class = 'edit';
                            elseif (strpos($action, 'delete') !== false) $dot_class = 'delete';
                            elseif (strpos($action, 'login') !== false) $dot_class = 'login';
                        ?>
                        <div class="log-item">
                            <span class="log-dot <?php echo $dot_class; ?>"></span>
                            <span class="log-text"><?php echo $log['details'] ?? $log['action']; ?></span>
                            <span class="log-time"><?php echo $log['timestamp']; ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align:center;color:#999;padding:20px;">فعالیتی ثبت نشده</p>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>