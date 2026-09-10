<?php
/**
 * مدیریت پروژه‌های در حال انجام — پنل جدید
 * این پنل برای پروژه‌های active/in_progress است
 * آرشیو تمام‌شده‌ها در manage-projects.php می‌ماند
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/logger.php';

check_login();

$message = '';
$error = '';
$editing_project = null;
$show_form = isset($_GET['add']) || isset($_GET['edit']);
$is_viewer = is_viewer();

if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if (isset($_GET['edit'])) {
    $uid = (string)$_GET['edit'];
    $editing_project = Projects::find($uid);
    // اگر تمام‌شده بود، هشدار بده ولی اجازه ویرایش بده
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_guard();
    if ($is_viewer) {
        $error = '⛔ شما فقط بیننده هستید!';
    } else {
        $act = (string)$_POST['action'];
        if ($act === 'add_project' || $act === 'edit_project') {
            $upload_dir = dirname(__DIR__) . '/uploads/projects/';
            if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

            $uploaded_images = [];
            if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
                $total = count($_FILES['images']['name']);
                for ($i=0;$i<$total;$i++) {
                    if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                        $fname = time().'_'.$i.'_'.bin2hex(random_bytes(3)).'_'.basename($_FILES['images']['name'][$i]);
                        $target = $upload_dir.$fname;
                        if (@move_uploaded_file($_FILES['images']['tmp_name'][$i], $target)) {
                            $uploaded_images[] = 'uploads/projects/'.$fname;
                        }
                    }
                }
            }

            $specs_array = [];
            if (!empty(trim((string)($_POST['specs_text'] ?? '')))) {
                foreach (explode("\n", (string)$_POST['specs_text']) as $line) {
                    $line = trim($line);
                    if ($line !== '') $specs_array[] = $line;
                }
            }

            $baseData = [
                'title' => trim((string)($_POST['title'] ?? '')),
                'category' => trim((string)($_POST['category'] ?? '')),
                'description' => (string)($_POST['description'] ?? ''),
                'location' => trim((string)($_POST['location'] ?? '')),
                'client' => trim((string)($_POST['client'] ?? '')),
                'client_uid' => (string)($_POST['client_uid'] ?? ''),
                'manager_uid' => (string)($_POST['manager_uid'] ?? current_admin_uid()),
                'year' => trim((string)($_POST['year'] ?? '')),
                'area' => trim((string)($_POST['area'] ?? '')),
                'budget' => trim((string)($_POST['budget'] ?? '')),
                'specs' => $specs_array,
                'progress' => max(0,min(100,(int)($_POST['progress'] ?? 0))),
                'status' => (string)($_POST['status'] ?? 'active'),
                'start_date' => (string)($_POST['start_date'] ?? ''),
                'end_date' => (string)($_POST['end_date'] ?? ''),
                'show_on_home' => !empty($_POST['show_on_home']),
                'client_visible' => !empty($_POST['client_visible']),
            ];

            if ($baseData['title'] === '') {
                $error = 'عنوان الزامی است';
            } else {
                if ($act === 'add_project') {
                    if (empty($uploaded_images)) $uploaded_images = ['assets/default-project.jpg'];
                    $cover_index = isset($_POST['cover_image_select']) ? (int)$_POST['cover_image_select'] : 0;
                    if (!isset($uploaded_images[$cover_index])) $cover_index = 0;
                    $baseData['images'] = $uploaded_images;
                    $baseData['cover_image'] = $uploaded_images[$cover_index];
                    $baseData['slug'] = create_slug($baseData['title']);

                    if (!empty($baseData['client_uid'])) {
                        $cl = Clients::find($baseData['client_uid']);
                        if ($cl) $baseData['client'] = $cl['name'];
                    }

                    $newId = Projects::create($baseData);

                    $members = [];
                    foreach ((array)($_POST['member_uid'] ?? []) as $i=>$muid) {
                        if ((string)$muid==='') continue;
                        $members[] = ['user_uid'=>(string)$muid,'role_in_project'=>(string)(($_POST['member_role'] ?? [])[$i] ?? 'عضو تیم')];
                    }
                    if ($members) ProjectMembers::sync($newId, $members, current_admin_uid());

                    add_log('add_ongoing_project', "پروژه در حال انجام {$baseData['title']} اضافه شد");
                    $message = '✅ پروژه در حال انجام اضافه شد';
                    $show_form = false;
                } else {
                    $pid = (string)($_POST['project_id'] ?? '');
                    $existing = Projects::find($pid);
                    if (!$existing) {
                        $error = 'پروژه یافت نشد';
                    } else {
                        $existing_images = $existing['images'] ?? [];
                        $all_images = array_merge($existing_images, $uploaded_images);
                        if (isset($_POST['remove_images']) && is_array($_POST['remove_images'])) {
                            foreach ($_POST['remove_images'] as $rm) {
                                $k = array_search($rm, $all_images, true);
                                if ($k!==false) unset($all_images[$k]);
                            }
                            $all_images = array_values($all_images);
                        }
                        if (empty($all_images)) $all_images = ['assets/default-project.jpg'];
                        $cover_index = isset($_POST['cover_image_select']) ? (int)$_POST['cover_image_select'] : 0;
                        if (!isset($all_images[$cover_index])) {
                            $old_cover = $existing['cover_image'] ?? '';
                            $old_key = array_search($old_cover, $all_images, true);
                            $cover_index = ($old_key!==false)?$old_key:0;
                        }
                        $baseData['images'] = $all_images;
                        $baseData['cover_image'] = $all_images[$cover_index];

                        Projects::update($pid, $baseData);

                        $members = [];
                        foreach ((array)($_POST['member_uid'] ?? []) as $i=>$muid) {
                            if ((string)$muid==='') continue;
                            $members[] = ['user_uid'=>(string)$muid,'role_in_project'=>(string)(($_POST['member_role'] ?? [])[$i] ?? 'عضو تیم')];
                        }
                        ProjectMembers::sync($pid, $members, current_admin_uid());

                        add_log('edit_ongoing_project', "پروژه در حال انجام {$pid} ویرایش شد");
                        $message = '✅ پروژه ویرایش شد';
                        $show_form = false;
                        $editing_project = null;
                    }
                }
            }
        } elseif ($act === 'delete_project') {
            $pid = (string)($_POST['project_id'] ?? '');
            if ($pid!=='') {
                Projects::delete($pid);
                add_log('delete_ongoing_project', "پروژه {$pid} حذف شد");
                $message = '✅ پروژه حذف شد';
            }
        } elseif ($act === 'toggle_status') {
            $pid = (string)($_POST['project_id'] ?? '');
            $p = Projects::find($pid);
            if ($p) {
                $newStatus = ($p['status'] ?? 'active') === 'completed' ? 'active' : 'completed';
                $newProgress = $newStatus==='completed' ? 100 : (int)($p['progress'] ?? 50);
                Projects::update($pid, ['status'=>$newStatus,'progress'=>$newProgress]);
                $message = $newStatus==='completed' ? '✅ به آرشیو تمام‌شده منتقل شد' : '✅ به در حال انجام برگشت';
            }
        } elseif ($act === 'update_progress') {
            $pid = (string)($_POST['project_id'] ?? '');
            $prog = max(0,min(100,(int)($_POST['progress'] ?? 0)));
            Projects::update($pid, ['progress'=>$prog,'status'=>$prog>=100?'completed':'active']);
            $message = '✅ پیشرفت بروز شد';
        }
    }
}

// فقط پروژه‌های در حال انجام
$all = Projects::list();
$ongoing = array_values(array_filter($all, fn($p)=> ($p['status'] ?? 'active') !== 'completed' && (int)($p['progress'] ?? 0) < 100));
usort($ongoing, fn($a,$b)=> strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

$finishedCount = count($all) - count($ongoing);

$all_users = Users::list(['active'=>true,'internal_only'=>true]);
$all_clients = Clients::list();
$categories = Categories::list();
if (empty($categories)) {
    $categories = [
        ['id'=>'cat_1','name'=>'معماری و سازه','slug'=>'architecture'],
        ['id'=>'cat_2','name'=>'تأسیسات برقی و مکانیکی','slug'=>'mechanic'],
        ['id'=>'cat_3','name'=>'صنعتی و هیدرولیکی','slug'=>'industrial'],
        ['id'=>'cat_4','name'=>'عمرانی و ژئوتکنیک','slug'=>'civil']
    ];
}

$edit_members = [];
if ($editing_project) {
    foreach (ProjectMembers::list((string)($editing_project['uid'] ?? $editing_project['id'] ?? '')) as $mm) {
        $edit_members[$mm['user_uid']] = $mm['role_in_project'];
    }
}

$projects = $ongoing;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>پروژه‌های در حال انجام | پنل مدیریت</title>
<link rel="stylesheet" href="admin-style.css">
<style>
.projects-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.stat-pill{padding:8px 16px;border-radius:20px;font-size:12px;font-weight:800;background:#e8f4fd;border:1px solid #c5d9ff;color:#1a56b0}
.projects-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:18px}
.project-card{background:#fff;border-radius:16px;overflow:hidden;border:1px solid #eee;transition:.3s;position:relative}
.project-card:hover{box-shadow:0 10px 30px rgba(0,0,0,.08);transform:translateY(-3px)}
.project-card__image{position:relative;height:190px;overflow:hidden}
.project-card__image img{width:100%;height:100%;object-fit:cover}
.project-card__badge{position:absolute;top:10px;right:10px;padding:5px 10px;border-radius:20px;font-size:10px;font-weight:800;background:rgba(0,0,0,.7);color:#fff}
.project-card__badge.active{background:rgba(46,213,115,.9)}
.project-card__progress{position:absolute;bottom:0;left:0;right:0;height:5px;background:rgba(0,0,0,.2)}
.project-card__progress-bar{height:100%;background:#2ed573;transition:width .3s}
.project-card__body{padding:16px 18px}
.project-card__title{font-size:15px;font-weight:900;margin-bottom:8px}
.project-card__meta{display:flex;flex-wrap:wrap;gap:10px;font-size:11px;color:#888}
.project-card__footer{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-top:1px solid #f5f5f5;flex-wrap:wrap;gap:8px}
.project-actions{display:flex;gap:5px}
.btn-icon{width:34px;height:34px;border-radius:9px;border:1px solid #e5e5e5;background:#fff;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:.2s}
.btn-icon:hover{background:#f5f5f5}
.progress-input{width:70px;padding:6px 8px;border:1px solid #ddd;border-radius:8px;font-size:12px}
.viewer-banner{background:#fff8e1;border:1px solid #ffa502;color:#e65100;padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:12px;font-weight:800}
.form-modern{background:#fff;border-radius:18px;box-shadow:0 6px 24px rgba(0,0,0,.05);overflow:hidden}
.form-modern__header{padding:22px 26px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;display:flex;justify-content:space-between;align-items:center}
.form-modern__header h2{font-size:18px;font-weight:900;margin:0}
.btn-back{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);padding:7px 14px;border-radius:10px;text-decoration:none;font-size:12px}
.form-modern__body{padding:26px}
.form-section{margin-bottom:26px}
.form-section__title{font-size:14px;font-weight:900;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid #f5f5f5}
.form-grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.form-grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.form-group{display:flex;flex-direction:column;gap:5px}
.form-group.full{grid-column:1 / -1}
.form-group label{font-size:11px;font-weight:800;color:#555}
.form-group input,.form-group select,.form-group textarea{padding:10px 12px;border:1px solid #e0e0e0;border-radius:10px;font-family:inherit;font-size:13px;background:#fafafa}
.upload-area{border:2px dashed #ddd;border-radius:12px;padding:22px;text-align:center;cursor:pointer;background:#fafafa;transition:.2s}
.upload-area:hover{border-color:#667eea}
.image-preview-grid{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.image-preview-item{width:90px;height:70px;border-radius:8px;overflow:hidden;border:2px solid #eee}
.image-preview-item img{width:100%;height:100%;object-fit:cover}
.cover-select-grid{display:flex;flex-wrap:wrap;gap:10px;margin-top:8px}
.cover-select-item{cursor:pointer;text-align:center;position:relative}
.cover-select-item img{width:80px;height:60px;object-fit:cover;border-radius:8px;border:2px solid #e0e0e0}
.cover-select-item input:checked + img{border-color:#667eea;box-shadow:0 0 10px rgba(102,126,234,.3)}
.mini-toolbar{display:flex;flex-wrap:wrap;gap:3px;padding:7px;background:#f8f9fa;border:1px solid #e0e0e0;border-bottom:none;border-radius:10px 10px 0 0}
.mini-toolbar button{padding:5px 9px;border:1px solid #ddd;background:#fff;border-radius:5px;cursor:pointer;font-size:11px;font-family:inherit}
.mini-editor{min-height:180px;padding:14px;border:1px solid #e0e0e0;border-radius:0 0 10px 10px;background:#fff;outline:none;direction:rtl;text-align:right;font-family:inherit;font-size:13px;line-height:1.8}
.form-modern__footer{padding:18px 26px;border-top:1px solid #f0f0f0;display:flex;gap:10px}
.btn{padding:10px 22px;border:none;border-radius:10px;font-family:inherit;font-size:13px;font-weight:800;cursor:pointer;text-decoration:none;transition:.2s}
.btn-primary{background:#667eea;color:#fff}
.btn-secondary{background:#f5f5f5;color:#666}
@media(max-width:768px){.form-grid-3,.form-grid-2{grid-template-columns:1fr}.projects-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="admin-layout">
<?php include 'sidebar.php'; ?>
<main class="main-content">
<header class="top-bar"><h1>🚧 پروژه‌های در حال انجام</h1><?php if($is_viewer): ?><span class="badge" style="background:#fff8e1;color:#e65100">👁️ مشاهده</span><?php endif; ?></header>
<?php if($message): ?><div class="alert alert-success"><?php echo e($message); ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
<?php if($is_viewer): ?><div class="viewer-banner">⛔ شما فقط بیننده هستید</div><?php endif; ?>

<div style="background:#f0f4ff;border:1px solid #c5d9ff;border-radius:12px;padding:12px 16px;margin-bottom:18px;font-size:13px;line-height:2">
ℹ️ این پنل مخصوص <b>پروژه‌های در حال اجرا</b> است — می‌تونید پروژه جدید اضافه کنید، پیشرفت رو بروز کنید، اعضا رو مدیریت کنید و از طریق <a href="workspace.php" style="font-weight:900">میز کار</a> تسک‌ها و گزارش‌ها رو ببینید.<br>
پروژه‌های تمام‌شده در <a href="manage-projects.php" style="font-weight:900">آرشیو سایت (تمام‌شده)</a> نمایش داده می‌شن (<?php echo fa_number($finishedCount); ?> پروژه).
</div>

<?php if($show_form): ?>
<div class="form-modern">
<div class="form-modern__header"><h2><?php echo $editing_project ? ($is_viewer ? '👁️ مشاهده' : '✏️ ویرایش پروژه در حال انجام') : '➕ افزودن پروژه در حال انجام'; ?></h2><a href="manage-ongoing-projects.php" class="btn-back">← بازگشت</a></div>
<form method="POST" enctype="multipart/form-data" id="projectForm">
<?php echo csrf_field(); ?>
<input type="hidden" name="action" value="<?php echo $editing_project ? 'edit_project' : 'add_project'; ?>">
<?php if($editing_project): ?><input type="hidden" name="project_id" value="<?php echo e($editing_project['uid'] ?? $editing_project['id']); ?>"><?php endif; ?>
<div class="form-modern__body">
<div class="form-section"><div class="form-section__title">📋 اطلاعات اصلی</div>
<div class="form-grid-2">
<div class="form-group"><label>عنوان *</label><input type="text" name="title" value="<?php echo e($editing_project['title'] ?? ''); ?>" <?php echo $is_viewer?'disabled':''; ?> required></div>
<div class="form-group"><label>دسته‌بندی *</label><select name="category" <?php echo $is_viewer?'disabled':''; ?> required><option value="">-- انتخاب --</option><?php foreach($categories as $cat): ?><option value="<?php echo e($cat['name']); ?>" <?php echo ($editing_project && ($editing_project['category'] ?? '')===$cat['name'])?'selected':''; ?>><?php echo e($cat['name']); ?></option><?php endforeach; ?></select></div>
</div></div>

<div class="form-section"><div class="form-section__title">📍 تکمیلی + پیشرفت</div>
<div class="form-grid-3">
<div class="form-group"><label>موقعیت</label><input type="text" name="location" value="<?php echo e($editing_project['location'] ?? ''); ?>"></div>
<div class="form-group"><label>کارفرما (متن)</label><input type="text" name="client" value="<?php echo e($editing_project['client'] ?? ''); ?>"></div>
<div class="form-group"><label>سال</label><input type="text" name="year" value="<?php echo e($editing_project['year'] ?? date('Y')); ?>"></div>
<div class="form-group"><label>مساحت</label><input type="text" name="area" value="<?php echo e($editing_project['area'] ?? ''); ?>"></div>
<div class="form-group"><label>بودجه</label><input type="text" name="budget" value="<?php echo e($editing_project['budget'] ?? ''); ?>"></div>
<div class="form-group"><label>وضعیت</label><select name="status"><option value="active" <?php echo (($editing_project['status'] ?? 'active')==='active')?'selected':''; ?>>در حال انجام</option><option value="on_hold" <?php echo (($editing_project['status'] ?? '')==='on_hold')?'selected':''; ?>>متوقف</option><option value="completed" <?php echo (($editing_project['status'] ?? '')==='completed')?'selected':''; ?>>تمام‌شده (انتقال به آرشیو)</option></select></div>
<div class="form-group"><label>پیشرفت ٪</label><input type="number" name="progress" min="0" max="100" value="<?php echo (int)($editing_project['progress'] ?? 0); ?>"></div>
<div class="form-group"><label>شروع</label><input type="date" name="start_date" value="<?php echo e((string)($editing_project['start_date'] ?? '')); ?>"></div>
<div class="form-group"><label>پایان پیش‌بینی</label><input type="date" name="end_date" value="<?php echo e((string)($editing_project['end_date'] ?? '')); ?>"></div>
</div></div>

<div class="form-section"><div class="form-section__title">🖼️ تصاویر</div>
<?php if($editing_project && !empty($editing_project['images'])): ?>
<div class="form-group full" style="margin-bottom:12px"><label>عکس شاخص:</label><div class="cover-select-grid"><?php foreach(($editing_project['images'] ?? []) as $idx=>$img): ?><label class="cover-select-item"><input type="radio" name="cover_image_select" value="<?php echo $idx; ?>" <?php echo (($editing_project['cover_image'] ?? '')===$img)?'checked':''; ?> style="display:none"><img src="../<?php echo e($img); ?>"><br><small style="font-size:9px"><?php echo $idx+1; ?></small></label><?php endforeach; ?></div></div>
<?php if(!$is_viewer): ?><div class="form-group full" style="margin-bottom:12px"><label>حذف عکس‌ها:</label><div style="display:flex;flex-wrap:wrap;gap:8px"><?php foreach(($editing_project['images'] ?? []) as $img): ?><label style="cursor:pointer;text-align:center"><input type="checkbox" name="remove_images[]" value="<?php echo e($img); ?>"><img src="../<?php echo e($img); ?>" style="width:60px;height:45px;object-fit:cover;border-radius:8px;border:1px solid #ddd;display:block"><small style="font-size:9px;color:#ff4757">حذف</small></label><?php endforeach; ?></div></div><?php endif; ?>
<?php endif; ?>
<?php if(!$is_viewer): ?><div class="form-group full"><label>آپلود جدید:</label><div class="upload-area" onclick="document.getElementById('fileInput').click()"><div style="font-size:32px">📸</div><p style="font-size:12px">کلیک و انتخاب عکس‌ها</p></div><input type="file" id="fileInput" name="images[]" accept="image/*" multiple style="display:none" onchange="previewNewImages(this)"><div class="image-preview-grid" id="newImagesPreview"></div></div><?php endif; ?>
</div>

<div class="form-section"><div class="form-section__title">🏢 کارفرما و مدیر</div>
<div class="form-grid-3">
<div class="form-group"><label>کارفرما (شرکت ثبت‌شده)</label><select name="client_uid"><option value="">— بدون کارفرما —</option><?php foreach($all_clients as $c): ?><option value="<?php echo e($c['uid']); ?>" <?php echo (($editing_project['client_uid'] ?? '')===$c['uid'])?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>مدیر پروژه</label><select name="manager_uid"><option value="">— تعیین نشده —</option><?php foreach($all_users as $u): ?><option value="<?php echo e($u['uid']); ?>" <?php echo (($editing_project['manager_uid'] ?? '')===$u['uid'])?'selected':''; ?>><?php echo e($u['full_name']); ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>نمایش در پنل کارفرما</label><label style="display:flex;align-items:center;gap:6px;margin-top:8px;font-size:12px"><input type="checkbox" name="client_visible" value="1" <?php echo !empty($editing_project['client_visible'])?'checked':''; ?>> کارفرما ببیند</label></div>
</div></div>

<div class="form-section"><div class="form-section__title">👥 اعضای پروژه</div>
<div style="border:1px solid #eee;border-radius:12px;padding:12px;max-height:300px;overflow:auto"><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px"><?php foreach($all_users as $u): $on=isset($edit_members[$u['uid']]); ?><label style="display:flex;align-items:center;gap:8px;background:#fafafa;border:1px solid #eee;border-radius:10px;padding:8px 10px;font-size:12px"><input type="checkbox" name="member_uid[]" value="<?php echo e($u['uid']); ?>" <?php echo $on?'checked':''; ?> style="width:auto"><span style="flex:1"><?php echo e($u['full_name']); ?> <small style="color:#999">(<?php echo e(Users::roleLabel((string)$u['role'])); ?>)</small></span><input type="text" name="member_role[]" value="<?php echo e((string)($edit_members[$u['uid']] ?? 'عضو تیم')); ?>" style="width:80px;padding:5px 8px;font-size:11px"></label><?php endforeach; ?></div></div>
</div>

<div class="form-section"><div class="form-section__title">🔧 مشخصات فنی</div><div class="form-group full"><textarea name="specs_text" rows="4" placeholder="هر خط یک مورد"><?php if($editing_project && !empty($editing_project['specs'])) echo e(implode("\n",(array)$editing_project['specs'])); ?></textarea></div></div>

<div class="form-section"><div class="form-section__title">📝 توضیحات</div><div class="form-group full"><div class="mini-toolbar"><button type="button" onclick="doCmd('bold')"><b>B</b></button><button type="button" onclick="doCmd('italic')"><i>I</i></button><button type="button" onclick="doCmd('underline')"><u>U</u></button><button type="button" onclick="doCmd('insertUnorderedList')">• لیست</button><button type="button" onclick="doCmd('formatBlock','h2')">H2</button></div><div class="mini-editor" id="descriptionMiniEditor" contenteditable="<?php echo $is_viewer?'false':'true'; ?>"><?php echo $editing_project['description'] ?? ''; ?></div><textarea name="description" id="descriptionEditor" style="display:none"><?php echo e($editing_project['description'] ?? ''); ?></textarea></div></div>

</div>
<?php if(!$is_viewer): ?><div class="form-modern__footer"><button type="submit" class="btn btn-primary"><?php echo $editing_project?'💾 ذخیره':'➕ افزودن'; ?></button><a href="manage-ongoing-projects.php" class="btn btn-secondary">انصراف</a></div><?php endif; ?>
</form>
</div>
<?php else: ?>
<div class="projects-header">
<span class="stat-pill">🚧 <?php echo fa_number(count($projects)); ?> پروژه در حال انجام (MySQL) — <?php echo fa_number($finishedCount); ?> تمام‌شده در آرشیو</span>
<?php if(!$is_viewer): ?><a href="manage-ongoing-projects.php?add=1" class="btn btn-primary">➕ افزودن پروژه در حال انجام</a><?php endif; ?>
</div>

<div class="projects-grid">
<?php foreach($projects as $project):
$cover=$project['cover_image']??($project['images'][0]??'assets/default-project.jpg');
$prog=(int)($project['progress'] ?? 0);
$status=$project['status'] ?? 'active';
?>
<div class="project-card">
<div class="project-card__image"><img src="../<?php echo e($cover); ?>" onerror="this.src='../assets/default-project.jpg'"><span class="project-card__badge <?php echo $status==='active'?'active':''; ?>"><?php echo $status==='active'?'🚧 در حال انجام':e($status); ?> — <?php echo fa_number($prog); ?>٪</span><div class="project-card__progress"><div class="project-card__progress-bar" style="width:<?php echo $prog; ?>%"></div></div></div>
<div class="project-card__body"><h3 class="project-card__title"><?php echo e($project['title']); ?></h3><div class="project-card__meta"><span>📍 <?php echo e($project['location'] ?: '—'); ?></span><span>👤 <?php echo e($project['client'] ?: '—'); ?></span><?php if(!empty($project['end_date'])): ?><span>📅 <?php echo e($project['end_date']); ?></span><?php endif; ?></div></div>
<div class="project-card__footer">
<span style="font-size:11px;color:#999"><?php echo e($project['category']); ?></span>
<div class="project-actions">
<a href="workspace.php?project=<?php echo e($project['uid'] ?? $project['id']); ?>" class="btn-icon" title="میز کار">📋</a>
<a href="manage-ongoing-projects.php?edit=<?php echo e($project['uid'] ?? $project['id']); ?>" class="btn-icon" title="ویرایش"><?php echo $is_viewer?'👁️':'✏️'; ?></a>
<?php if(!$is_viewer): ?>
<form method="POST" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="project_id" value="<?php echo e($project['uid'] ?? $project['id']); ?>"><button class="btn-icon" title="تغییر وضعیت">🔄</button></form>
<form method="POST" style="display:inline" onsubmit="return confirm('حذف شود؟')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?php echo e($project['uid'] ?? $project['id']); ?>"><button class="btn-icon" title="حذف">🗑️</button></form>
<?php endif; ?>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php if(empty($projects)): ?><p style="text-align:center;color:#999;padding:30px">پروژه در حال انجامی وجود ندارد — از دکمه بالا پروژه جدید بسازید</p><?php endif; ?>
<?php endif; ?>
</main>
</div>
<script>
function doCmd(c,v=null){document.execCommand(c,false,v);document.getElementById('descriptionMiniEditor').focus();syncEditor();}
function syncEditor(){document.getElementById('descriptionEditor').value=document.getElementById('descriptionMiniEditor').innerHTML;}
document.getElementById('descriptionMiniEditor')?.addEventListener('input',syncEditor);
function previewNewImages(input){const cont=document.getElementById('newImagesPreview');cont.innerHTML='';if(input.files){Array.from(input.files).slice(0,12).forEach(f=>{const r=new FileReader();r.onload=e=>{const d=document.createElement('div');d.className='image-preview-item';d.innerHTML=`<img src="${e.target.result}">`;cont.appendChild(d);};r.readAsDataURL(f);});}}
document.getElementById('projectForm')?.addEventListener('submit',()=>{syncEditor();});
</script>
</body>
</html>
