<?php
/**
 * پیام‌های تماس — MySQL — دیزاین بازطراحی شده
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/logger.php';

check_login();

$message = '';
$error = '';
$is_viewer = is_viewer();

// پاکسازی زباله‌دان قدیمی
if (Db::ready()) {
    try { Db::i()->delete(Db::i()->t('contact_messages'), 'is_trashed = 1 AND created_at < ?', [date('Y-m-d H:i:s', strtotime('-30 days'))]); } catch (Throwable $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_guard();
    $act = (string)$_POST['action'];
    $uid = (string)($_POST['message_id'] ?? '');
    if ($is_viewer) {
        $error = '⛔ شما فقط بیننده هستید!';
    } else {
        switch ($act) {
            case 'mark_read': ContactMessages::setFlags($uid, ['is_read'=>1]); $message='✅ خوانده شد'; break;
            case 'mark_unread': ContactMessages::setFlags($uid, ['is_read'=>0]); $message='✅ خوانده‌نشده شد'; break;
            case 'star':
                $m=null;
                foreach (ContactMessages::list() as $x) if (($x['uid']??'')===$uid){$m=$x;break;}
                if(!$m) foreach (ContactMessages::trash() as $x) if (($x['uid']??'')===$uid){$m=$x;break;}
                ContactMessages::setFlags($uid, ['is_starred'=> $m && !empty($m['is_starred']) ? 0 : 1]);
                $message='⭐ تغییر نشان';
                break;
            case 'delete_message': ContactMessages::delete($uid,false); add_log('delete_message','پیام به زباله‌دان رفت'); $message='🗑 به زباله‌دان رفت'; break;
            case 'restore_message': ContactMessages::setFlags($uid, ['is_trashed'=>0]); $message='♻️ بازیابی شد'; break;
            case 'permanent_delete': ContactMessages::delete($uid,true); add_log('permanent_delete_message','حذف دائمی'); $message='❌ حذف دائمی شد'; break;
            case 'empty_trash': check_permission('admin'); Db::i()->delete(Db::i()->t('contact_messages'),'is_trashed = 1'); add_log('empty_trash','زباله‌دان خالی'); $message='✅ زباله‌دان خالی شد'; break;
            case 'mark_all_read': Db::i()->update(Db::i()->t('contact_messages'),['is_read'=>1],'is_trashed = 0'); $message='✅ همه خوانده شدند'; break;
            default: $error='عملیات نامعتبر';
        }
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$filters = $search !== '' ? ['search'=>$search] : [];
$all_messages = ContactMessages::list($filters);
usort($all_messages, fn($a,$b)=> strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
$trash_messages = ContactMessages::trash();
$unread = array_values(array_filter($all_messages, fn($m)=> empty($m['is_read'])));
$starred = array_values(array_filter($all_messages, fn($m)=> !empty($m['is_starred'])));

$viewing = null;
if (!empty($_GET['view'])) {
    foreach ($all_messages as $m) if (($m['uid']??'')===(string)$_GET['view']) {$viewing=$m;break;}
    if (!$viewing) foreach ($trash_messages as $m) if (($m['uid']??'')===(string)$_GET['view']) {$viewing=$m;break;}
    if ($viewing && empty($viewing['is_read']) && empty($viewing['is_trashed'])) {
        ContactMessages::setFlags($viewing['uid'], ['is_read'=>1]);
        $viewing['is_read']=1;
    }
}
$tab = $_GET['tab'] ?? 'inbox';
if ($viewing) $tab='view';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>پیام‌ها | پنل مدیریت</title>
<link rel="stylesheet" href="admin-style.css">
<style>
.viewer-banner{background:#fff8e1;border:1px solid #ffa502;color:#e65100;padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:12px;font-weight:800}
.page-head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px}
.page-head h1{font-size:20px;font-weight:900;margin:0}
.stat-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.stat-pill{padding:8px 14px;border-radius:20px;font-size:11px;font-weight:800;background:#fff;border:1px solid #eee}
.stat-pill b{font-size:13px}
.stat-pill.unread{background:#e7f0ff;border-color:#c5d9ff;color:#1a56b0}
.stat-pill.star{background:#fff8e1;border-color:#ffe0b2;color:#e65100}
.stat-pill.trash{background:#ffebee;border-color:#ffcdd2;color:#c62828}
.tabs{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap}
.tab-btn{padding:9px 16px;border-radius:10px;border:1px solid #e5e5e5;background:#fff;font-family:inherit;font-size:12.5px;font-weight:800;cursor:pointer;transition:.2s}
.tab-btn.active{background:#1a1a1a;color:#fff;border-color:#1a1a1a}
.tab-btn:hover{transform:translateY(-1px)}
.search-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.search-row input{padding:10px 13px;border:1px solid #ddd;border-radius:10px;font-family:inherit;font-size:13px;background:#fff;min-width:240px}
.btn{padding:9px 16px;border-radius:10px;border:none;font-family:inherit;font-size:12.5px;font-weight:800;cursor:pointer;transition:.2s;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:#1a1a1a;color:#fff}
.btn-secondary{background:#f5f5f5;color:#555;border:1px solid #e5e5e5}
.btn-danger{background:#ff4757;color:#fff}
.btn-sm{padding:6px 10px;border-radius:8px;border:1px solid #e5e5e5;background:#fff;font-size:12px;cursor:pointer}
.btn-sm.danger{background:#ff4757;color:#fff;border-color:#ff4757}
.msg-grid{display:flex;flex-direction:column;gap:10px}
.msg-card{background:#fff;border:1px solid #eee;border-radius:14px;padding:14px 16px;display:flex;gap:12px;align-items:flex-start;transition:.2s}
.msg-card:hover{box-shadow:0 6px 18px rgba(0,0,0,.06);transform:translateY(-1px)}
.msg-card.unread{border-right:4px solid #667eea;background:#fbfcff}
.msg-card .avatar{width:40px;height:40px;border-radius:12px;background:#f4f2ee;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:14px;flex-shrink:0}
.msg-card .content{flex:1;min-width:0}
.msg-card .top{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px}
.msg-card .who{font-weight:900;font-size:13.5px;color:#1a1a1a}
.msg-card .subj{font-weight:800;font-size:13px;color:#333;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.msg-card .prev{font-size:12px;color:#777;line-height:1.8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.msg-card .meta{display:flex;gap:10px;font-size:11px;color:#999;margin-top:6px;flex-wrap:wrap}
.msg-card .acts{display:flex;gap:5px;flex-shrink:0;flex-wrap:wrap}
.pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:800}
.pill.new{background:#e7f0ff;color:#1a56b0}
.pill.star{background:#fff8e1;color:#e65100}
.detail-wrap{background:#fff;border:1px solid #eee;border-radius:18px;overflow:hidden;box-shadow:0 6px 24px rgba(0,0,0,.04);margin-bottom:18px}
.detail-head{padding:18px 20px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;background:#fafafa}
.detail-head h2{font-size:16px;font-weight:900;margin:0 0 8px}
.detail-kv{display:flex;gap:12px;flex-wrap:wrap;font-size:12px;color:#666}
.detail-body{padding:20px}
.detail-text{background:#fcfcfc;border:1px solid #f0f0f0;border-radius:12px;padding:16px;line-height:2.1;font-size:13.5px;white-space:pre-wrap}
.attach{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.attach a{padding:8px 12px;background:#f5f5f5;border:1px solid #e5e5e5;border-radius:10px;font-size:12px;text-decoration:none;color:#333}
.empty{padding:24px;text-align:center;color:#999;font-size:13px;background:#fff;border:1px dashed #ddd;border-radius:14px}
@media(max-width:700px){.msg-card{flex-direction:column}.msg-card .acts{width:100%;justify-content:flex-start}}
</style>
</head>
<body>
<div class="admin-layout">
<?php include 'sidebar.php'; ?>
<main class="main-content">
<header class="top-bar"><h1>📨 پیام‌های تماس — MySQL</h1><?php if($is_viewer): ?><span class="badge" style="background:#fff8e1;color:#e65100">👁️ مشاهده</span><?php endif; ?></header>

<?php if($message): ?><div class="alert alert-success"><?php echo e($message); ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
<?php if($is_viewer): ?><div class="viewer-banner">⛔ فقط بیننده</div><?php endif; ?>

<div class="page-head">
  <div class="stat-row">
    <span class="stat-pill unread">📬 <b><?php echo fa_number(count($unread)); ?></b> خوانده‌نشده</span>
    <span class="stat-pill">📥 <b><?php echo fa_number(count($all_messages)); ?></b> کل</span>
    <span class="stat-pill star">⭐ <b><?php echo fa_number(count($starred)); ?></b> نشان‌دار</span>
    <span class="stat-pill trash">🗑 <b><?php echo fa_number(count($trash_messages)); ?></b> زباله‌دان</span>
  </div>
  <div class="search-row">
    <form method="get" style="display:flex;gap:6px;flex-wrap:wrap">
      <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="جستجو نام، ایمیل، موضوع…">
      <button class="btn btn-secondary" type="submit">🔍 جستجو</button>
      <?php if($search!==''): ?><a href="manage-messages.php" class="btn btn-secondary">✕ پاک</a><?php endif; ?>
    </form>
    <?php if(!$is_viewer && $unread): ?>
    <form method="post"><input type="hidden" name="action" value="mark_all_read"><?php echo csrf_field(); ?><button class="btn btn-secondary">✅ خواندن همه</button></form>
    <?php endif; ?>
  </div>
</div>

<div class="tabs">
  <button class="tab-btn <?php echo $tab==='inbox'?'active':''; ?>" onclick="location.href='manage-messages.php?tab=inbox<?php echo $search!==''?'&q='.urlencode($search):''; ?>'">📥 صندوق ورودی</button>
  <button class="tab-btn <?php echo $tab==='starred'?'active':''; ?>" onclick="location.href='manage-messages.php?tab=starred'">⭐ نشان‌دارها (<?php echo fa_number(count($starred)); ?>)</button>
  <button class="tab-btn <?php echo $tab==='trash'?'active':''; ?>" onclick="location.href='manage-messages.php?tab=trash'">🗑 زباله‌دان (<?php echo fa_number(count($trash_messages)); ?>)</button>
  <?php if($viewing): ?><button class="tab-btn active">👁️ مشاهده پیام</button><?php endif; ?>
</div>

<?php if($viewing): ?>
<div class="detail-wrap">
  <div class="detail-head">
    <div>
      <h2><?php echo e($viewing['subject'] ?: '(بدون موضوع)'); ?> <?php if(!empty($viewing['is_starred'])): ?><span class="pill star">⭐ نشان‌دار</span><?php endif; ?> <?php if(empty($viewing['is_read'])): ?><span class="pill new">جدید</span><?php endif; ?></h2>
      <div class="detail-kv">
        <span>👤 <?php echo e($viewing['name'] ?: 'ناشناس'); ?></span>
        <?php if(!empty($viewing['email'])): ?><span>✉️ <a href="mailto:<?php echo e($viewing['email']); ?>"><?php echo e($viewing['email']); ?></a></span><?php endif; ?>
        <?php if(!empty($viewing['phone'])): ?><span>📞 <a href="tel:<?php echo e($viewing['phone']); ?>"><?php echo e($viewing['phone']); ?></a></span><?php endif; ?>
        <span>🕐 <?php echo e(jalali_datetime((string)($viewing['created_at'] ?? ''))); ?></span>
        <span>🌐 <?php echo e($viewing['ip'] ?? ''); ?></span>
      </div>
    </div>
    <a href="manage-messages.php?tab=inbox" class="btn btn-secondary">← بازگشت</a>
  </div>
  <div class="detail-body">
    <div class="detail-text"><?php echo e($viewing['message'] ?? $viewing['body'] ?? ''); ?></div>
    <?php
    $atts = $viewing['attachment'] ?? $viewing['attachments'] ?? null;
    if ($atts) {
        if (!is_array($atts) || isset($atts['path'])) $atts = [$atts];
        echo '<div class="attach"><strong style="font-size:12px">📎 پیوست:</strong>';
        foreach ($atts as $a) {
            $path = is_array($a) ? ($a['path'] ?? $a['url'] ?? '') : (string)$a;
            $name = is_array($a) ? ($a['name'] ?? basename($path)) : basename($path);
            if ($path==='') continue;
            echo '<a href="../'.e(ltrim($path,'/')).'" target="_blank">📄 '.e($name).'</a>';
        }
        echo '</div>';
    }
    ?>
    <?php if(!$is_viewer): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px">
      <?php if(!empty($viewing['email'])): ?><a href="mailto:<?php echo e($viewing['email']); ?>?subject=<?php echo rawurlencode('پاسخ: '.($viewing['subject']??'')); ?>" class="btn btn-primary">✉️ پاسخ</a><?php endif; ?>
      <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="star"><input type="hidden" name="message_id" value="<?php echo e($viewing['uid']); ?>"><button class="btn btn-secondary"><?php echo !empty($viewing['is_starred'])?'☆ حذف نشان':'⭐ نشان‌دار'; ?></button></form>
      <?php if(empty($viewing['is_trashed'])): ?>
      <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_message"><input type="hidden" name="message_id" value="<?php echo e($viewing['uid']); ?>"><button class="btn btn-danger">🗑 زباله‌دان</button></form>
      <?php else: ?>
      <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="restore_message"><input type="hidden" name="message_id" value="<?php echo e($viewing['uid']); ?>"><button class="btn btn-secondary">♻️ بازیابی</button></form>
      <form method="post" onsubmit="return confirm('برای همیشه حذف شود؟')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="permanent_delete"><input type="hidden" name="message_id" value="<?php echo e($viewing['uid']); ?>"><button class="btn btn-danger">❌ حذف دائمی</button></form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php
$list_to_show = $all_messages;
if($tab==='starred') $list_to_show = $starred;
if($tab==='trash') $list_to_show = $trash_messages;
?>

<?php if($tab!=='view'): ?>
<?php if(empty($list_to_show)): ?><div class="empty"><?php echo $tab==='trash' ? 'زباله‌دان خالی است' : 'پیامی وجود ندارد'; ?></div>
<?php else: ?>
<?php if($tab==='trash' && !$is_viewer && has_permission('admin')): ?>
<form method="post" style="margin-bottom:12px" onsubmit="return confirm('همه زباله‌دان حذف دائمی شود؟')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="empty_trash"><button class="btn btn-danger">🗑 خالی کردن زباله‌دان</button></form>
<?php endif; ?>
<div class="msg-grid">
<?php foreach($list_to_show as $m): $isTrash = !empty($m['is_trashed']); ?>
<div class="msg-card <?php echo empty($m['is_read'])?'unread':''; ?>" style="<?php echo $isTrash?'opacity:.7':''; ?>">
  <div class="avatar"><?php echo e(mb_substr($m['name'] ?? '?',0,1)); ?></div>
  <div class="content">
    <div class="top">
      <a href="?view=<?php echo e($m['uid']); ?>" class="who" style="text-decoration:none;color:inherit"><?php echo e($m['name'] ?: 'ناشناس'); ?></a>
      <?php if(!empty($m['is_starred'])): ?><span title="نشان‌دار">⭐</span><?php endif; ?>
      <?php if(empty($m['is_read']) && !$isTrash): ?><span class="pill new">جدید</span><?php endif; ?>
      <span style="margin-right:auto;font-size:11px;color:#999"><?php echo e(time_ago_fa((string)($m['created_at'] ?? ''))); ?></span>
    </div>
    <div class="subj"><?php echo e($m['subject'] ?: '(بدون موضوع)'); ?></div>
    <div class="prev"><?php echo e(mb_substr($m['message'] ?? $m['body'] ?? '',0,140)); ?><?php echo mb_strlen($m['message'] ?? $m['body'] ?? '')>140?'…':''; ?></div>
    <div class="meta">
      <?php if(!empty($m['email'])): ?><span>✉️ <?php echo e($m['email']); ?></span><?php endif; ?>
      <?php if(!empty($m['phone'])): ?><span>📞 <?php echo e($m['phone']); ?></span><?php endif; ?>
      <span>🕐 <?php echo e(jalali_datetime((string)($m['created_at'] ?? ''))); ?></span>
      <?php if(!empty($m['attachment']) || !empty($m['attachments'])): ?><span>📎 پیوست</span><?php endif; ?>
    </div>
  </div>
  <?php if(!$is_viewer): ?>
  <div class="acts">
    <a href="?view=<?php echo e($m['uid']); ?>" class="btn-sm" title="مشاهده">👁️</a>
    <?php if(!$isTrash): ?>
    <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="<?php echo !empty($m['is_read'])?'mark_unread':'mark_read'; ?>"><input type="hidden" name="message_id" value="<?php echo e($m['uid']); ?>"><button class="btn-sm" title="<?php echo !empty($m['is_read'])?'خوانده‌نشده':'خوانده‌شده'; ?>"><?php echo !empty($m['is_read'])?'📭':'📬'; ?></button></form>
    <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="star"><input type="hidden" name="message_id" value="<?php echo e($m['uid']); ?>"><button class="btn-sm" title="نشان">⭐</button></form>
    <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_message"><input type="hidden" name="message_id" value="<?php echo e($m['uid']); ?>"><button class="btn-sm danger" title="حذف">🗑</button></form>
    <?php else: ?>
    <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="restore_message"><input type="hidden" name="message_id" value="<?php echo e($m['uid']); ?>"><button class="btn-sm" title="بازیابی">♻️</button></form>
    <form method="post" style="display:inline" onsubmit="return confirm('حذف دائمی؟')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="permanent_delete"><input type="hidden" name="message_id" value="<?php echo e($m['uid']); ?>"><button class="btn-sm danger" title="حذف دائمی">❌</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

</main>
</div>
</body>
</html>
