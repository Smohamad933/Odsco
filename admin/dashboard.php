<?php
/**
 * داشبورد — MySQL
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/logger.php';
require_once dirname(__DIR__) . '/includes/attendance.php';
require_once dirname(__DIR__) . '/includes/automation.php';

check_login();

$user_name = $_SESSION['admin_name'] ?? 'کاربر';
$user_role = $_SESSION['admin_role'] ?? 'viewer';
$user_photo = $_SESSION['admin_photo'] ?? '';

$hour = (int)date('G');
if ($hour >= 5 && $hour < 12) $greeting = 'صبح بخیر';
elseif ($hour < 17) $greeting = 'ظهر بخیر';
elseif ($hour < 21) $greeting = 'عصر بخیر';
else $greeting = 'شب بخیر';

// آمار بازدید (هنوز از JSON لاگ)
$total_views = get_total_views();
$today_views = get_today_views();

// آمار MySQL
$all_projects_list = Projects::list();
$all_posts = Blog::list();
$all_team = Team::list();
$all_cats = Categories::list();
$all_users_list = Users::list();
$all_contact = ContactMessages::list();

$total_projects = count($all_projects_list);
$total_posts = count($all_posts);
$total_team = count($all_team);
$total_categories = count($all_cats);
$total_users = count($all_users_list);
$total_messages = count($all_contact);
$unread_messages = count(array_filter($all_contact, fn($m)=> empty($m['is_read'])));

// پربازدیدترین پست
$most_viewed_post = null;
$max_views = 0;
foreach ($all_posts as $post) {
    $v = (int)($post['views'] ?? 0);
    if ($v > $max_views) { $max_views = $v; $most_viewed_post = $post; }
}
$most_viewed_project = null;
$max_pv = 0;
foreach ($all_projects_list as $project) {
    $v = (int)($project['views'] ?? 0);
    if ($v > $max_pv) { $max_pv = $v; $most_viewed_project = $project; }
}

$role_names = ['admin'=>'ادمین کل','manager'=>'مدیر','editor'=>'ویراستار','article_writer'=>'نویسنده مقاله','project_writer'=>'نویسنده پروژه','viewer'=>'بیننده'];
$role_name_fa = $role_names[$user_role] ?? 'کاربر';
$role_icons = ['admin'=>'👑','manager'=>'🛡️','editor'=>'📝','article_writer'=>'✍️','project_writer'=>'🏗️','viewer'=>'👁️'];
$role_icon = $role_icons[$user_role] ?? '👤';

$recent_logs = get_logs(8);

$ops = Db::ready();
$att_summary = $ops ? Attendance::todaySummary() : null;
$att_pending = $ops ? count(Attendance::requests('pending')) : 0;
$rules_active = $ops ? count(Automation::rules()) : 0;
$overdue_tasks = $ops ? Tasks::overdue() : [];
$due_soon = $ops ? Tasks::dueSoon(3) : [];
$avg_progress = $all_projects_list ? (int)round(array_sum(array_map(fn($p)=>(int)($p['progress'] ?? 0), $all_projects_list)) / count($all_projects_list)) : 0;
$recent_updates = $ops ? ProjectUpdates::recent(6) : [];
$today_fa = jalali_date_long();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>داشبورد | پنل مدیریت</title>
<link rel="stylesheet" href="admin-style.css">
<style>
.top-bar{margin-bottom:20px}
.top-bar h1{font-size:22px;font-weight:900}
.welcome-section{background:linear-gradient(135deg,#1a1a1a 0%,#333 50%,#1a1a1a 100%);border-radius:20px;padding:28px;margin-bottom:20px;color:#fff;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:18px;position:relative;overflow:hidden}
.welcome-section::before{content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(circle,rgba(255,255,255,.05) 1px,transparent 1px);background-size:30px 30px;animation:patternMove 20s linear infinite}
@keyframes patternMove{0%{transform:translate(0,0)}100%{transform:translate(30px,30px)}}
.welcome-info{position:relative;z-index:1}
.welcome-greeting{font-size:13px;color:#aaa;margin-bottom:4px}
.welcome-name{font-size:26px;font-weight:900;margin-bottom:6px}
.welcome-role{display:inline-block;padding:5px 14px;border-radius:20px;font-size:11px;font-weight:800;background:rgba(255,255,255,.1)}
.welcome-date{font-size:11px;color:#888;margin-top:8px}
.welcome-photo{position:relative;z-index:1;width:72px;height:72px;border-radius:50%;overflow:hidden;border:3px solid rgba(255,255,255,.3);flex-shrink:0}
.welcome-photo img{width:100%;height:100%;object-fit:cover}
.welcome-photo-fallback{width:100%;height:100%;background:#3742fa;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:900}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
.stat-box{background:#fff;padding:20px;border-radius:16px;border:1px solid #eee;transition:.3s;position:relative;overflow:hidden}
.stat-box::before{content:'';position:absolute;top:0;right:0;width:100%;height:4px;transform:scaleX(0);transform-origin:right;transition:transform .4s}
.stat-box:hover::before{transform:scaleX(1)}
.stat-box:hover{transform:translateY(-4px);box-shadow:0 10px 28px rgba(0,0,0,.07)}
.stat-box.blue::before{background:#3742fa}.stat-box.green::before{background:#2ed573}.stat-box.orange::before{background:#ffa502}.stat-box.red::before{background:#ff4757}
.stat-icon{width:48px;height:48px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:12px}
.stat-icon.blue{background:#e8f4fd}.stat-icon.green{background:#e8f5e9}.stat-icon.orange{background:#fff8e1}.stat-icon.red{background:#ffebee}
.stat-number{display:block;font-size:26px;font-weight:900;margin-bottom:2px}
.stat-label{font-size:11px;color:#999}
.dashboard-bottom{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.card{background:#fff;border-radius:16px;padding:22px;border:1px solid #eee}
.card h2{font-size:14px;font-weight:900;margin-bottom:16px;padding-bottom:10px;border-bottom:2px solid #f5f5f5;display:flex;align-items:center;gap:8px}
.top-post-card{display:flex;align-items:center;gap:12px;padding:12px;background:#fafafa;border-radius:12px;margin-bottom:12px;transition:.2s}
.top-post-card:hover{background:#f0f0f0;transform:translateX(-3px)}
.top-post-img{width:54px;height:54px;border-radius:10px;overflow:hidden;flex-shrink:0}
.top-post-img img{width:100%;height:100%;object-fit:cover}
.top-post-info{flex:1;min-width:0}
.top-post-title{font-size:12.5px;font-weight:800;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.top-post-meta{font-size:10.5px;color:#999}
.top-post-views{font-size:18px;font-weight:900;color:#3742fa;white-space:nowrap}
.log-item{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid #f5f5f5;font-size:12px}
.log-item:last-child{border-bottom:none}
.log-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.log-dot.add{background:#2ed573}.log-dot.edit{background:#ffa502}.log-dot.delete{background:#ff4757}.log-dot.login{background:#3742fa}.log-dot.other{background:#999}
.log-text{flex:1;color:#666}
.log-time{font-size:10px;color:#999;white-space:nowrap}
@media(max-width:900px){.stats-grid{grid-template-columns:repeat(2,1fr)}.dashboard-bottom{grid-template-columns:1fr}}
@media(max-width:480px){.stats-grid{grid-template-columns:1fr}.welcome-section{padding:18px}.welcome-name{font-size:20px}}
</style>
</head>
<body>
<div class="admin-layout">
<?php include 'sidebar.php'; ?>
<main class="main-content">
<header class="top-bar"><h1>📊 داشبورد — MySQL</h1></header>

<div class="welcome-section">
  <div class="welcome-info">
    <div class="welcome-greeting"><?php echo e($greeting); ?> 👋</div>
    <div class="welcome-name"><?php echo e($user_name); ?></div>
    <span class="welcome-role"><?php echo e($role_icon . ' ' . $role_name_fa); ?></span>
    <div class="welcome-date">📅 امروز: <?php echo e($today_fa); ?> — MySQL فعال ✅</div>
  </div>
  <div class="welcome-photo">
    <?php if(!empty($user_photo)): ?><img src="../<?php echo e($user_photo); ?>" alt="" onerror="this.style.display='none'"><div class="welcome-photo-fallback" style="display:none"><?php echo e(mb_substr($user_name,0,1)); ?></div><?php else: ?><div class="welcome-photo-fallback"><?php echo e(mb_substr($user_name,0,1)); ?></div><?php endif; ?>
  </div>
</div>

<div class="stats-grid">
  <div class="stat-box blue"><div class="stat-icon blue">👁️</div><span class="stat-number"><?php echo fa_number($total_views); ?></span><span class="stat-label">کل بازدید</span></div>
  <div class="stat-box green"><div class="stat-icon green">📅</div><span class="stat-number"><?php echo fa_number($today_views); ?></span><span class="stat-label">بازدید امروز</span></div>
  <div class="stat-box orange"><div class="stat-icon orange">🏗️</div><span class="stat-number"><?php echo fa_number($total_projects); ?></span><span class="stat-label">پروژه‌ها (MySQL)</span></div>
  <div class="stat-box red"><div class="stat-icon red">📨</div><span class="stat-number"><?php echo fa_number($unread_messages); ?></span><span class="stat-label">پیام جدید</span></div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
  <div class="stat-box"><div class="stat-icon blue">📝</div><span class="stat-number"><?php echo fa_number($total_posts); ?></span><span class="stat-label">مقالات</span></div>
  <div class="stat-box"><div class="stat-icon green">👥</div><span class="stat-number"><?php echo fa_number($total_team); ?></span><span class="stat-label">اعضای تیم</span></div>
  <div class="stat-box"><div class="stat-icon orange">🏷️</div><span class="stat-number"><?php echo fa_number($total_categories); ?></span><span class="stat-label">دسته‌بندی</span></div>
  <div class="stat-box"><div class="stat-icon red">👤</div><span class="stat-number"><?php echo fa_number($total_users); ?></span><span class="stat-label">کاربران</span></div>
</div>

<?php if($ops): ?>
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
  <div class="stat-box green"><div class="stat-icon green">🟢</div><span class="stat-number"><?php echo fa_number((int)($att_summary['present'] ?? 0)); ?></span><span class="stat-label">حاضر امروز از <?php echo fa_number((int)($att_summary['staff'] ?? 0)); ?></span></div>
  <div class="stat-box orange"><div class="stat-icon orange">🕐</div><span class="stat-number"><?php echo fa_number($att_pending); ?></span><span class="stat-label">درخواست حضور</span></div>
  <div class="stat-box red"><div class="stat-icon red">⏰</div><span class="stat-number"><?php echo fa_number(count($overdue_tasks)); ?></span><span class="stat-label">تسک عقب‌افتاده</span></div>
  <div class="stat-box blue"><div class="stat-icon blue">📈</div><span class="stat-number"><?php echo fa_number($avg_progress); ?>٪</span><span class="stat-label">میانگین پیشرفت</span></div>
</div>

<div class="dashboard-bottom" style="margin-bottom:20px">
  <div class="card">
    <h2>📊 آخرین گزارش‌ها</h2>
    <?php if(!$recent_updates): ?><p style="text-align:center;color:#999;padding:16px;font-size:12px">گزارشی ثبت نشده</p>
    <?php else: foreach($recent_updates as $u): ?>
      <div class="log-item"><span class="log-dot <?php echo $u['level']==='danger'?'delete':($u['level']==='success'?'add':'edit'); ?>"></span><span class="log-text"><a href="workspace.php?project=<?php echo e($u['project_uid']); ?>" style="text-decoration:none;color:inherit"><?php echo e(ProjectUpdates::levelIcon((string)$u['level']).' '.$u['title']); ?> <small style="color:#999">— <?php echo e($u['project_title']); ?></small></a></span><span class="log-time"><?php echo e(time_ago_fa((string)$u['created_at'])); ?></span></div>
    <?php endforeach; endif; ?>
  </div>
  <div class="card">
    <h2>⚠️ نیاز به اقدام</h2>
    <?php
    $alerts=[];
    if($att_pending>0) $alerts[]=['🕐',fa_number($att_pending).' درخواست حضور','attendance.php?tab=requests','orange'];
    if(count($overdue_tasks)) $alerts[]=['⏰',fa_number(count($overdue_tasks)).' تسک عقب‌افتاده','workspace.php','red'];
    if(count($due_soon)) $alerts[]=['📆',fa_number(count($due_soon)).' تسک تا ۳ روز','workspace.php','orange'];
    foreach($all_projects_list as $p){ if(!empty($p['end_date']) && strtotime((string)$p['end_date'])<time() && (int)($p['progress']??0)<100){ $alerts[]=['🚨','پروژه «'.$p['title'].'» از مهلت گذشته','workspace.php?project='.$p['uid'],'red']; } }
    if(!$rules_active) $alerts[]=['⚙️','قانون اتوماسیون فعال نیست','automation.php','orange'];
    ?>
    <?php if(!$alerts): ?><p style="text-align:center;color:#2ed573;padding:16px;font-size:12px">✅ همه‌چیز تحت کنترل</p>
    <?php else: foreach(array_slice($alerts,0,8) as [$ico,$txt,$href,$tone]): ?>
      <div class="log-item"><span class="log-dot <?php echo $tone==='red'?'delete':'edit'; ?>"></span><span class="log-text"><a href="<?php echo e($href); ?>" style="text-decoration:none;color:inherit"><?php echo e($ico.' '.$txt); ?></a></span></div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px">
  <a href="workspace.php" class="btn" style="background:#667eea;color:#fff;text-decoration:none;padding:10px 18px;border-radius:11px;font-size:12px;font-weight:800">🏗️ میز کار</a>
  <a href="attendance.php" class="btn" style="background:#11998e;color:#fff;text-decoration:none;padding:10px 18px;border-radius:11px;font-size:12px;font-weight:800">🕐 حضور و غیاب</a>
  <a href="automation.php" class="btn" style="background:#f7971e;color:#fff;text-decoration:none;padding:10px 18px;border-radius:11px;font-size:12px;font-weight:800">⚙️ اتوماسیون</a>
  <a href="broadcast.php" class="btn" style="background:#eb3349;color:#fff;text-decoration:none;padding:10px 18px;border-radius:11px;font-size:12px;font-weight:800">📣 اطلاعیه</a>
  <a href="../messenger/" target="_blank" class="btn" style="background:#3742fa;color:#fff;text-decoration:none;padding:10px 18px;border-radius:11px;font-size:12px;font-weight:800">💬 پیام‌رسان</a>
  <a href="../client/" target="_blank" class="btn" style="background:#555;color:#fff;text-decoration:none;padding:10px 18px;border-radius:11px;font-size:12px;font-weight:800">🏢 پنل کارفرما</a>
</div>
<?php endif; ?>

<div class="dashboard-bottom">
  <div class="card">
    <h2>🔥 پربازدیدترین‌ها</h2>
    <?php if($most_viewed_post): ?>
      <div class="top-post-card"><div class="top-post-img"><img src="../<?php echo e($most_viewed_post['image'] ?? 'assets/default-post.jpg'); ?>" onerror="this.src='../assets/default-post.jpg'"></div><div class="top-post-info"><div class="top-post-title">📝 <?php echo e($most_viewed_post['title']); ?></div><div class="top-post-meta">👤 <?php echo e($most_viewed_post['author'] ?? 'مدیر'); ?> | <?php echo e(format_date($most_viewed_post['date'] ?? '')); ?></div></div><span class="top-post-views"><?php echo fa_number($max_views); ?> 👁️</span></div>
    <?php endif; ?>
    <?php if($most_viewed_project): $cover=$most_viewed_project['cover_image']??($most_viewed_project['images'][0]??'assets/default-project.jpg'); ?>
      <div class="top-post-card"><div class="top-post-img"><img src="../<?php echo e($cover); ?>" onerror="this.src='../assets/default-project.jpg'"></div><div class="top-post-info"><div class="top-post-title">🏗️ <?php echo e($most_viewed_project['title']); ?></div><div class="top-post-meta"><?php echo e($most_viewed_project['category'] ?? ''); ?></div></div><span class="top-post-views"><?php echo fa_number($max_pv); ?> 👁️</span></div>
    <?php endif; ?>
    <?php if(!$most_viewed_post && !$most_viewed_project): ?><p style="text-align:center;color:#999;padding:16px;font-size:12px">بازدیدی ثبت نشده</p><?php endif; ?>
  </div>
  <div class="card">
    <h2>📋 فعالیت‌های اخیر</h2>
    <?php if(!empty($recent_logs)): foreach($recent_logs as $log): $action=$log['action']??''; $dot='other'; if(str_contains($action,'add')) $dot='add'; elseif(str_contains($action,'edit')) $dot='edit'; elseif(str_contains($action,'delete')) $dot='delete'; elseif(str_contains($action,'login')) $dot='login'; ?>
      <div class="log-item"><span class="log-dot <?php echo $dot; ?>"></span><span class="log-text"><?php echo e($log['details'] ?? $log['action']); ?></span><span class="log-time"><?php echo e(time_ago_fa((string)($log['timestamp'] ?? $log['created_at'] ?? ''))); ?></span></div>
    <?php endforeach; else: ?><p style="text-align:center;color:#999;padding:16px;font-size:12px">فعالیتی ثبت نشده</p><?php endif; ?>
  </div>
</div>

</main>
</div>
</body>
</html>
