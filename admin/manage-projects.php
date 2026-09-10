<?php
/**
 * مدیریت پروژه‌های تمام‌شده (آرشیو سایت) — MySQL
 * پروژه‌های در حال اجرا در workspace.php / پنل مدیریت پروژه هستند
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
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_guard();
    if ($is_viewer) {
        $error = '⛔ شما فقط بیننده هستید!';
    } else {
        $act = (string)$_POST['action'];
        if ($act === 'add_project' || $act === 'edit_project') {
            // آپلود چند عکس
            $uploaded_images = [];
            $upload_dir = dirname(__DIR__) . '/uploads/projects/';
            if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

            if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
                $total_files = count($_FILES['images']['name']);
                for ($i = 0; $i < $total_files; $i++) {
                    if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                        $file_name = time() . '_' . $i . '_' . bin2hex(random_bytes(3)) . '_' . basename($_FILES['images']['name'][$i]);
                        $target_path = $upload_dir . $file_name;
                        if (@move_uploaded_file($_FILES['images']['tmp_name'][$i], $target_path)) {
                            $uploaded_images[] = 'uploads/projects/' . $file_name;
                        }
                    }
                }
            }

            $specs_array = [];
            if (!empty(trim((string)($_POST['specs_text'] ?? '')))) {
                $lines = explode("\n", (string)$_POST['specs_text']);
                foreach ($lines as $line) {
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
                'manager_uid' => (string)($_POST['manager_uid'] ?? ''),
                'year' => trim((string)($_POST['year'] ?? '')),
                'area' => trim((string)($_POST['area'] ?? '')),
                'budget' => trim((string)($_POST['budget'] ?? '')),
                'specs' => $specs_array,
                'progress' => (int)($_POST['progress'] ?? 100),
                'status' => (string)($_POST['status'] ?? 'completed'),
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
                    foreach ((array)($_POST['member_uid'] ?? []) as $i => $muid) {
                        if ((string)$muid === '') continue;
                        $members[] = [
                            'user_uid' => (string)$muid,
                            'role_in_project' => (string)(($_POST['member_role'] ?? [])[$i] ?? 'عضو تیم'),
                        ];
                    }
                    if ($members) ProjectMembers::sync($newId, $members, current_admin_uid());

                    add_log('add_project', "پروژه {$baseData['title']} اضافه شد");
                    $message = '✅ پروژه اضافه شد';
                    $show_form = false;
                } else {
                    $project_id = (string)($_POST['project_id'] ?? '');
                    $existing = Projects::find($project_id);
                    if (!$existing) {
                        $error = 'پروژه یافت نشد';
                    } else {
                        $existing_images = $existing['images'] ?? [];
                        $all_images = array_merge($existing_images, $uploaded_images);

                        if (isset($_POST['remove_images']) && is_array($_POST['remove_images'])) {
                            foreach ($_POST['remove_images'] as $remove_img) {
                                $key = array_search($remove_img, $all_images, true);
                                if ($key !== false) unset($all_images[$key]);
                            }
                            $all_images = array_values($all_images);
                        }

                        if (empty($all_images)) $all_images = ['assets/default-project.jpg'];

                        $cover_index = isset($_POST['cover_image_select']) ? (int)$_POST['cover_image_select'] : 0;
                        if (!isset($all_images[$cover_index])) {
                            $old_cover = $existing['cover_image'] ?? '';
                            $old_key = array_search($old_cover, $all_images, true);
                            $cover_index = ($old_key !== false) ? $old_key : 0;
                        }

                        $baseData['images'] = $all_images;
                        $baseData['cover_image'] = $all_images[$cover_index];

                        Projects::update($project_id, $baseData);

                        $members = [];
                        foreach ((array)($_POST['member_uid'] ?? []) as $i => $muid) {
                            if ((string)$muid === '') continue;
                            $members[] = [
                                'user_uid' => (string)$muid,
                                'role_in_project' => (string)(($_POST['member_role'] ?? [])[$i] ?? 'عضو تیم'),
                            ];
                        }
                        ProjectMembers::sync($project_id, $members, current_admin_uid());

                        add_log('edit_project', "پروژه {$project_id} ویرایش شد");
                        $message = '✅ پروژه ویرایش شد';
                        $show_form = false;
                        $editing_project = null;
                    }
                }
            }
        } elseif ($act === 'delete_project') {
            $project_id = (string)($_POST['project_id'] ?? '');
            if ($project_id !== '') {
                Projects::delete($project_id);
                add_log('delete_project', "پروژه {$project_id} حذف شد");
                $message = '✅ پروژه حذف شد';
            }
        } elseif ($act === 'toggle_home') {
            $project_id = (string)($_POST['project_id'] ?? '');
            $p = Projects::find($project_id);
            if ($p) {
                Projects::update($project_id, ['show_on_home' => !($p['show_on_home'] ?? false)]);
            }
        }
    }
}

// فقط پروژه‌های تمام‌شده برای آرشیو سایت
$all_projects = Projects::list();
$finished = array_values(array_filter($all_projects, fn($p) => ($p['status'] ?? '') === 'completed' || (int)($p['progress'] ?? 0) >= 100));
$ongoingCount = count($all_projects) - count($finished);

// برای فرم
$all_users = Users::list(['active' => true, 'internal_only' => true]);
$all_clients = Clients::list();
$categories = Categories::list();
if (empty($categories)) {
    $categories = [
        ['id' => 'cat_1', 'name' => 'معماری و سازه', 'slug' => 'architecture'],
        ['id' => 'cat_2', 'name' => 'تأسیسات برقی و مکانیکی', 'slug' => 'mechanic'],
        ['id' => 'cat_3', 'name' => 'صنعتی و هیدرولیکی', 'slug' => 'industrial'],
        ['id' => 'cat_4', 'name' => 'عمرانی و ژئوتکنیک', 'slug' => 'civil']
    ];
}

$edit_members = [];
if ($editing_project) {
    foreach (ProjectMembers::list((string)($editing_project['uid'] ?? $editing_project['id'] ?? '')) as $mm) {
        $edit_members[$mm['user_uid']] = $mm['role_in_project'];
    }
}

// اگر در حالت لیست، فقط تمام‌شده‌ها را نشان بده
$projects = $finished;
usort($projects, fn($a,$b)=> strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت پروژه‌های تمام‌شده | پنل مدیریت</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .projects-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .stat-pill { padding: 8px 18px; border-radius: 25px; font-size: 13px; font-weight: 700; background: #eee; color: #666; }
        .projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
        .project-card { background: #fff; border-radius: 16px; overflow: hidden; border: 1px solid #e9ecef; transition: all 0.3s; }
        .project-card:hover { box-shadow: 0 10px 30px rgba(0,0,0,0.08); transform: translateY(-4px); }
        .project-card__image { position: relative; height: 200px; overflow: hidden; }
        .project-card__image img { width: 100%; height: 100%; object-fit: cover; }
        .project-card__badge { position: absolute; top: 12px; right: 12px; padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; background: rgba(0,0,0,0.7); color: #fff; }
        .project-card__badge.home-active { background: rgba(46, 213, 115, 0.9); }
        .project-card__images-count { position: absolute; bottom: 12px; left: 12px; padding: 4px 10px; border-radius: 15px; font-size: 11px; background: rgba(0,0,0,0.6); color: #fff; }
        .project-card__body { padding: 18px 20px; }
        .project-card__title { font-size: 16px; font-weight: 800; margin-bottom: 10px; }
        .project-card__meta { display: flex; flex-wrap: wrap; gap: 12px; font-size: 11px; color: #999; }
        .project-card__footer { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-top: 1px solid #f0f0f0; flex-wrap: wrap; gap: 10px; }
        .project-actions { display: flex; gap: 6px; }
        .btn-icon { width: 35px; height: 35px; border-radius: 10px; border: 1px solid #ddd; background: #fff; cursor: pointer; font-size: 15px; display: flex; align-items: center; justify-content: center; transition: all 0.3s; text-decoration: none; }
        .btn-icon:hover { background: #f5f5f5; }
        .viewer-banner { background: #fff8e1; border: 1px solid #ffa502; color: #e65100; padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; font-size: 13px; font-weight: 700; }
        .form-modern { background: #fff; border-radius: 20px; box-shadow: 0 5px 30px rgba(0,0,0,0.05); overflow: hidden; }
        .form-modern__header { padding: 25px 30px; background: linear-gradient(135deg, #1a1a1a, #333); color: #fff; display: flex; justify-content: space-between; align-items: center; }
        .form-modern__header h2 { font-size: 20px; font-weight: 900; margin: 0; }
        .btn-back { background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 10px; text-decoration: none; font-size: 12px; }
        .form-modern__body { padding: 30px; }
        .form-section { margin-bottom: 30px; }
        .form-section__title { font-size: 15px; font-weight: 800; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0; }
        .form-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; }
        .form-grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-size: 12px; font-weight: 700; color: #555; }
        .form-group input, .form-group select, .form-group textarea { padding: 10px 14px; border: 1px solid #e0e0e0; border-radius: 10px; font-family: inherit; font-size: 13px; background: #fafafa; }
        .form-group input:disabled, .form-group select:disabled, .form-group textarea:disabled { background: #f5f5f5; color: #999; cursor: not-allowed; }
        .upload-area { border: 2px dashed #ddd; border-radius: 15px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.3s; background: #fafafa; }
        .upload-area:hover { border-color: #1a1a1a; }
        .upload-area.disabled { cursor: not-allowed; opacity: 0.5; }
        .upload-area .upload-icon { font-size: 40px; margin-bottom: 10px; }
        .image-preview-grid { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; }
        .image-preview-item { width: 100px; height: 75px; border-radius: 10px; overflow: hidden; border: 2px solid #e0e0e0; }
        .image-preview-item img { width: 100%; height: 100%; object-fit: cover; }
        .cover-select-grid { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 10px; }
        .cover-select-item { cursor: pointer; text-align: center; position: relative; }
        .cover-select-item img { width: 90px; height: 70px; object-fit: cover; border-radius: 10px; border: 2px solid #e0e0e0; }
        .cover-select-item input:checked + img { border-color: #2ed573; box-shadow: 0 0 10px rgba(46,213,115,0.4); }
        .cover-select-item .cover-label { position: absolute; top: 5px; right: 5px; background: #2ed573; color: #fff; font-size: 9px; padding: 2px 6px; border-radius: 10px; }
        .mini-toolbar { display: flex; flex-wrap: wrap; gap: 3px; padding: 8px; background: #f8f9fa; border: 1px solid #e0e0e0; border-bottom: none; border-radius: 10px 10px 0 0; }
        .mini-toolbar button { padding: 5px 10px; border: 1px solid #ddd; background: #fff; border-radius: 5px; cursor: pointer; font-size: 11px; font-family: inherit; }
        .mini-toolbar button:disabled { opacity: 0.3; cursor: not-allowed; }
        .mini-editor { min-height: 200px; padding: 15px; border: 1px solid #e0e0e0; border-radius: 0 0 10px 10px; background: #fff; outline: none; direction: rtl; text-align: right; font-family: inherit; font-size: 13px; line-height: 1.8; }
        .mini-editor[contenteditable="false"] { background: #f5f5f5; color: #999; }
        .form-modern__footer { padding: 20px 30px; border-top: 1px solid #f0f0f0; display: flex; gap: 10px; }
        .btn { padding: 11px 25px; border: none; border-radius: 10px; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.3s; text-decoration: none; }
        .btn-primary { background: #1a1a1a; color: #fff; }
        .btn-secondary { background: #f5f5f5; color: #666; }
        @media (max-width: 768px) {
            .form-grid-3, .form-grid-2 { grid-template-columns: 1fr; }
            .form-group.full { grid-column: auto; }
            .projects-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar.php'; ?>
        <main class="main-content">
            <header class="top-bar">
                <h1>🏗️ آرشیو پروژه‌های تمام‌شده</h1>
                <?php if ($is_viewer): ?><span class="badge" style="background:#fff8e1;color:#e65100;">👁️ حالت مشاهده</span><?php endif; ?>
            </header>
            <?php if ($message): ?><div class="alert alert-success"><?php echo e($message); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($is_viewer): ?><div class="viewer-banner">⛔ شما فقط بیننده هستید!</div><?php endif; ?>
            <div style="background:#e8f4fd;border:1px solid #c5d9e8;border-radius:12px;padding:12px 16px;margin-bottom:20px;font-size:13px">
                ℹ️ این بخش فقط برای <b>پروژه‌های تمام‌شده</b> است که در سایت نمایش داده می‌شوند.
                پروژه‌های در حال اجرا را از <a href="workspace.php" style="font-weight:800">میز کار پروژه‌ها</a> یا <a href="../pm/" style="font-weight:800">پنل مدیریت پروژه</a> مدیریت کنید.
                (<?php echo fa_number($ongoingCount); ?> پروژه در حال اجرا)
            </div>
            <?php if ($show_form): ?>
            <div class="form-modern">
                <div class="form-modern__header">
                    <h2><?php echo $editing_project ? ($is_viewer ? '👁️ مشاهده پروژه' : '✏️ ویرایش پروژه') : '➕ افزودن پروژه تمام‌شده'; ?></h2>
                    <a href="manage-projects.php" class="btn-back">← بازگشت</a>
                </div>
                <form method="POST" enctype="multipart/form-data" id="projectForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="<?php echo $editing_project ? 'edit_project' : 'add_project'; ?>">
                    <?php if ($editing_project): ?><input type="hidden" name="project_id" value="<?php echo e($editing_project['uid'] ?? $editing_project['id']); ?>"><?php endif; ?>
                    <div class="form-modern__body">
                        <div class="form-section">
                            <div class="form-section__title">📋 اطلاعات اصلی</div>
                            <div class="form-grid-2">
                                <div class="form-group"><label>عنوان *</label><input type="text" name="title" value="<?php echo e($editing_project['title'] ?? ''); ?>" <?php echo $is_viewer ? 'disabled' : 'required'; ?>></div>
                                <div class="form-group"><label>دسته‌بندی *</label><select name="category" <?php echo $is_viewer ? 'disabled' : 'required'; ?>><option value="">-- انتخاب --</option><?php foreach ($categories as $cat): ?><option value="<?php echo e($cat['name']); ?>" <?php echo ($editing_project && ($editing_project['category'] ?? '') === $cat['name']) ? 'selected' : ''; ?>><?php echo e($cat['name']); ?></option><?php endforeach; ?></select></div>
                            </div>
                        </div>
                        <div class="form-section">
                            <div class="form-section__title">📍 اطلاعات تکمیلی</div>
                            <div class="form-grid-3">
                                <div class="form-group"><label>موقعیت</label><input type="text" name="location" value="<?php echo e($editing_project['location'] ?? ''); ?>"></div>
                                <div class="form-group"><label>کارفرما</label><input type="text" name="client" value="<?php echo e($editing_project['client'] ?? ''); ?>"></div>
                                <div class="form-group"><label>سال</label><input type="text" name="year" value="<?php echo e($editing_project['year'] ?? ''); ?>"></div>
                                <div class="form-group"><label>مساحت</label><input type="text" name="area" value="<?php echo e($editing_project['area'] ?? ''); ?>"></div>
                                <div class="form-group"><label>بودجه</label><input type="text" name="budget" value="<?php echo e($editing_project['budget'] ?? ''); ?>"></div>
                                <div class="form-group"><label>وضعیت</label><select name="status"><option value="completed" <?php echo (($editing_project['status'] ?? '') === 'completed') ? 'selected' : ''; ?>>تمام‌شده</option><option value="active" <?php echo (($editing_project['status'] ?? '') === 'active') ? 'selected' : ''; ?>>در حال اجرا</option></select></div>
                            </div>
                        </div>
                        <div class="form-section">
                            <div class="form-section__title">🖼️ تصاویر پروژه</div>
                            <?php if ($editing_project && !empty($editing_project['images'])): ?>
                            <div class="form-group full" style="margin-bottom:15px;">
                                <label>عکس شاخص:</label>
                                <div class="cover-select-grid">
                                    <?php foreach (($editing_project['images'] ?? []) as $index => $img): ?>
                                    <label class="cover-select-item">
                                        <input type="radio" name="cover_image_select" value="<?php echo $index; ?>" <?php echo (($editing_project['cover_image'] ?? '') === $img) ? 'checked' : ''; ?> style="display:none;">
                                        <img src="../<?php echo e($img); ?>">
                                        <?php if (($editing_project['cover_image'] ?? '') === $img): ?><span class="cover-label">⭐</span><?php endif; ?>
                                        <br><small style="font-size:9px;"><?php echo $index + 1; ?></small>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php if (!$is_viewer): ?>
                            <div class="form-group full" style="margin-bottom:15px;">
                                <label>حذف عکس‌ها:</label>
                                <div style="display:flex;flex-wrap:wrap;gap:10px;">
                                    <?php foreach (($editing_project['images'] ?? []) as $img): ?>
                                    <label style="cursor:pointer;text-align:center;">
                                        <input type="checkbox" name="remove_images[]" value="<?php echo e($img); ?>">
                                        <img src="../<?php echo e($img); ?>" style="width:60px;height:45px;object-fit:cover;border-radius:8px;border:1px solid #ddd;display:block;">
                                        <small style="font-size:9px;color:#ff4757;">حذف</small>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!$is_viewer): ?>
                            <div class="form-group full">
                                <label>آپلود عکس‌های جدید:</label>
                                <div class="upload-area" onclick="document.getElementById('fileInput').click()"><div class="upload-icon">📸</div><p>کلیک کنید و عکس‌ها را انتخاب کنید</p></div>
                                <input type="file" id="fileInput" name="images[]" accept="image/*" multiple style="display:none;" onchange="previewNewImages(this)">
                                <div class="image-preview-grid" id="newImagesPreview"></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="form-section">
                            <div class="form-section__title">📈 مدیریت پروژه</div>
                            <div class="form-grid-3">
                                <div class="form-group">
                                    <label>کارفرما (شرکت)</label>
                                    <select name="client_uid">
                                        <option value="">— بدون کارفرما —</option>
                                        <?php foreach ($all_clients as $c): ?>
                                            <option value="<?php echo e($c['uid']); ?>" <?php echo (($editing_project['client_uid'] ?? '') === $c['uid']) ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>مدیر پروژه</label>
                                    <select name="manager_uid">
                                        <option value="">— تعیین نشده —</option>
                                        <?php foreach ($all_users as $u): ?>
                                            <option value="<?php echo e($u['uid']); ?>" <?php echo (($editing_project['manager_uid'] ?? '') === $u['uid']) ? 'selected' : ''; ?>><?php echo e($u['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group"><label>پیشرفت (٪)</label><input type="number" name="progress" min="0" max="100" value="<?php echo (int)($editing_project['progress'] ?? 100); ?>"></div>
                                <div class="form-group"><label>تاریخ شروع</label><input type="date" name="start_date" value="<?php echo e((string)($editing_project['start_date'] ?? '')); ?>"></div>
                                <div class="form-group"><label>تاریخ پایان</label><input type="date" name="end_date" value="<?php echo e((string)($editing_project['end_date'] ?? '')); ?>"></div>
                                <div class="form-group"><label>نمایش در پنل کارفرما</label><label style="display:flex;align-items:center;gap:8px;font-size:12px;margin-top:8px"><input type="checkbox" name="client_visible" value="1" <?php echo !empty($editing_project['client_visible']) ? 'checked' : ''; ?>> کارفرما ببیند</label></div>
                                <div class="form-group"><label>نمایش در خانه</label><label style="display:flex;align-items:center;gap:8px;font-size:12px;margin-top:8px"><input type="checkbox" name="show_on_home" value="1" <?php echo !empty($editing_project['show_on_home']) ? 'checked' : ''; ?>> نمایش در صفحه اصلی</label></div>
                            </div>
                        </div>
                        <div class="form-section">
                            <div class="form-section__title">👥 اعضای پروژه</div>
                            <div style="border:1px solid #e5e5e5;border-radius:12px;padding:14px;max-height:300px;overflow:auto">
                                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px">
                                    <?php foreach ($all_users as $u): $on = isset($edit_members[$u['uid']]); ?>
                                    <label style="display:flex;align-items:center;gap:8px;background:#fafafa;border:1px solid #eee;border-radius:10px;padding:8px 10px;font-size:12px">
                                        <input type="checkbox" name="member_uid[]" value="<?php echo e($u['uid']); ?>" <?php echo $on ? 'checked' : ''; ?> style="width:auto">
                                        <span style="flex:1"><?php echo e($u['full_name']); ?> <small style="color:#999">(<?php echo e(Users::roleLabel((string)$u['role'])); ?>)</small></span>
                                        <input type="text" name="member_role[]" value="<?php echo e((string)($edit_members[$u['uid']] ?? 'عضو تیم')); ?>" style="width:88px;padding:5px 8px;font-size:11px">
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="form-section">
                            <div class="form-section__title">🔧 مشخصات فنی</div>
                            <div class="form-group full"><textarea name="specs_text" rows="5"><?php if ($editing_project && !empty($editing_project['specs'])) echo e(implode("\n", (array)$editing_project['specs'])); ?></textarea></div>
                        </div>
                        <div class="form-section">
                            <div class="form-section__title">📝 توضیحات</div>
                            <div class="form-group full">
                                <div class="mini-toolbar">
                                    <button type="button" onclick="doCmd('bold')"><b>B</b></button>
                                    <button type="button" onclick="doCmd('italic')"><i>I</i></button>
                                    <button type="button" onclick="doCmd('underline')"><u>U</u></button>
                                    <button type="button" onclick="doCmd('insertUnorderedList')">• لیست</button>
                                    <button type="button" onclick="doCmd('insertOrderedList')">۱. لیست</button>
                                    <button type="button" onclick="doCmd('formatBlock', 'h2')">H2</button>
                                    <button type="button" onclick="doCmd('formatBlock', 'h3')">H3</button>
                                </div>
                                <div class="mini-editor" id="descriptionMiniEditor" contenteditable="<?php echo $is_viewer ? 'false' : 'true'; ?>"><?php echo $editing_project['description'] ?? ''; ?></div>
                                <textarea name="description" id="descriptionEditor" style="display:none;"><?php echo e($editing_project['description'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    <?php if (!$is_viewer): ?>
                    <div class="form-modern__footer">
                        <button type="submit" class="btn btn-primary"><?php echo $editing_project ? '💾 ذخیره' : '➕ افزودن'; ?></button>
                        <a href="manage-projects.php" class="btn btn-secondary">انصراف</a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            <?php else: ?>
            <div class="projects-header">
                <span class="stat-pill">📊 <?php echo fa_number(count($projects)); ?> پروژه تمام‌شده (MySQL) — <?php echo fa_number($ongoingCount); ?> در حال اجرا</span>
                <?php if (!$is_viewer): ?><a href="manage-projects.php?add=1" class="btn btn-primary">➕ افزودن پروژه تمام‌شده</a><?php endif; ?>
            </div>
            <div class="projects-grid">
                <?php foreach ($projects as $project):
                    $cover = $project['cover_image'] ?? ($project['images'][0] ?? 'assets/default-project.jpg');
                    $images_count = isset($project['images']) ? count($project['images']) : 1;
                ?>
                <div class="project-card">
                    <div class="project-card__image">
                        <img src="../<?php echo e($cover); ?>" onerror="this.src='../assets/default-project.jpg'">
                        <span class="project-card__badge <?php echo !empty($project['show_on_home']) ? 'home-active' : ''; ?>"><?php echo !empty($project['show_on_home']) ? '🏠 خانه' : e($project['category']); ?></span>
                        <span class="project-card__images-count">📷 <?php echo fa_number($images_count); ?></span>
                    </div>
                    <div class="project-card__body">
                        <h3 class="project-card__title"><?php echo e($project['title']); ?></h3>
                        <div class="project-card__meta"><span>👁️ <?php echo fa_number((int)($project['views'] ?? 0)); ?></span><?php if (!empty($project['year'])): ?><span>📅 <?php echo e($project['year']); ?></span><?php endif; ?></div>
                    </div>
                    <div class="project-card__footer">
                        <span style="font-size:11px;color:#999;"><?php echo e($project['category']); ?></span>
                        <div class="project-actions">
                            <a href="../project/detail.php?id=<?php echo e($project['uid'] ?? $project['id']); ?>" target="_blank" class="btn-icon">👁️</a>
                            <a href="manage-projects.php?edit=<?php echo e($project['uid'] ?? $project['id']); ?>" class="btn-icon"><?php echo $is_viewer ? '👁️' : '✏️'; ?></a>
                            <?php if (!$is_viewer): ?>
                            <form method="POST" style="display:inline;"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle_home"><input type="hidden" name="project_id" value="<?php echo e($project['uid'] ?? $project['id']); ?>"><button type="submit" class="btn-icon">🏠</button></form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('حذف شود؟');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?php echo e($project['uid'] ?? $project['id']); ?>"><button type="submit" class="btn-icon">🗑️</button></form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($projects)): ?><p style="text-align:center;color:#999;padding:30px">پروژه تمام‌شده‌ای وجود ندارد — پروژه‌های در حال اجرا در میزکار هستند</p><?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
    <script>
    function doCmd(cmd, val = null) { document.execCommand(cmd, false, val); document.getElementById('descriptionMiniEditor').focus(); syncEditor(); }
    function syncEditor() { document.getElementById('descriptionEditor').value = document.getElementById('descriptionMiniEditor').innerHTML; }
    document.getElementById('descriptionMiniEditor')?.addEventListener('input', syncEditor);
    function previewNewImages(input) {
        const container = document.getElementById('newImagesPreview');
        container.innerHTML = '';
        if (input.files) {
            Array.from(input.files).slice(0, 15).forEach((file) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'image-preview-item';
                    div.innerHTML = `<img src=\"${e.target.result}\">`;
                    container.appendChild(div);
                };
                reader.readAsDataURL(file);
            });
        }
    }
    document.getElementById('projectForm')?.addEventListener('submit', function() { syncEditor(); });
    </script>
</body>
</html>
