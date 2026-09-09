<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/logger.php';

check_login();

$message = '';
$error = '';
$editing_project = null;
$show_form = isset($_GET['add']) || isset($_GET['edit']);
$is_viewer = ($_SESSION['admin_role'] ?? '') === 'viewer';

if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if (isset($_GET['edit'])) {
    $projects = read_json('projects.json');
    foreach ($projects as $project) {
        if ($project['id'] === $_GET['edit']) {
            $editing_project = $project;
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($is_viewer) {
        $error = '⛔ شما فقط بیننده هستید و دسترسی به تغییرات ندارید!';
    } else {
        if ($_POST['action'] === 'add_project' || $_POST['action'] === 'edit_project') {
            $projects = read_json('projects.json');
            
            // ============ آپلود چند عکس ============
            $uploaded_images = [];
            $upload_dir = '../uploads/projects/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
            
            if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
                $total_files = count($_FILES['images']['name']);
                for ($i = 0; $i < $total_files; $i++) {
                    if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                        $file_name = time() . '_' . $i . '_' . basename($_FILES['images']['name'][$i]);
                        $target_path = $upload_dir . $file_name;
                        if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $target_path)) {
                            $uploaded_images[] = 'uploads/projects/' . $file_name;
                        }
                    }
                }
            }
            
            $specs_array = [];
            if (isset($_POST['specs_text']) && !empty(trim($_POST['specs_text']))) {
                $lines = explode("\n", $_POST['specs_text']);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!empty($line)) $specs_array[] = sanitize($line);
                }
            }
            
            if ($_POST['action'] === 'add_project') {
                if (empty($uploaded_images)) $uploaded_images = ['assets/default-project.jpg'];
                $cover_index = isset($_POST['cover_image_select']) ? (int)$_POST['cover_image_select'] : 0;
                if (!isset($uploaded_images[$cover_index])) $cover_index = 0;
                
                $new_project = [
                    'id' => 'prj_' . uniqid(),
                    'title' => sanitize($_POST['title']),
                    'slug' => create_slug($_POST['title']),
                    'category' => sanitize($_POST['category']),
                    'description' => $_POST['description'],
                    'images' => $uploaded_images,
                    'cover_image' => $uploaded_images[$cover_index],
                    'location' => sanitize($_POST['location']),
                    'client' => sanitize($_POST['client']),
                    'year' => sanitize($_POST['year']),
                    'area' => sanitize($_POST['area'] ?? ''),
                    'specs' => $specs_array,
                    'show_on_home' => isset($_POST['show_on_home']) ? true : false,
                    'status' => 'active',
                    'views' => 0,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $new_project['manager_uid']    = (string)($_POST['manager_uid'] ?? '');
                $new_project['client_uid']     = (string)($_POST['client_uid'] ?? '');
                $new_project['progress']       = (int)($_POST['progress'] ?? 0);
                $new_project['start_date']     = (string)($_POST['start_date'] ?? '');
                $new_project['end_date']       = (string)($_POST['end_date'] ?? '');
                $new_project['client_visible'] = isset($_POST['client_visible']);
                if (!empty($_POST['client_uid'])) {
                    $cl = Clients::find((string)$_POST['client_uid']);
                    if ($cl) $new_project['client'] = $cl['name'];
                }

                $projects[] = $new_project;
                write_json('projects.json', $projects);

                // اعضای پروژه
                $members = [];
                foreach ((array)($_POST['member_uid'] ?? []) as $i => $muid) {
                    if ((string)$muid === '') continue;
                    $members[] = [
                        'user_uid' => (string)$muid,
                        'role_in_project' => (string)(($_POST['member_role'] ?? [])[$i] ?? 'عضو تیم'),
                    ];
                }
                if (Db::ready()) {
                    ProjectMembers::sync($new_project['id'], $members, current_admin_uid());
                }

                add_log('add_project', "پروژه {$new_project['title']} اضافه شد");
                $message = '✅ پروژه اضافه شد';
                $show_form = false;
            } else {
                $project_id = $_POST['project_id'];
                
                foreach ($projects as &$project) {
                    if ($project['id'] === $project_id) {
                        $existing_images = $project['images'] ?? [];
                        $all_images = array_merge($existing_images, $uploaded_images);
                        
                        if (isset($_POST['remove_images']) && is_array($_POST['remove_images'])) {
                            foreach ($_POST['remove_images'] as $remove_img) {
                                $key = array_search($remove_img, $all_images);
                                if ($key !== false) unset($all_images[$key]);
                            }
                            $all_images = array_values($all_images);
                        }
                        
                        if (empty($all_images)) $all_images = ['assets/default-project.jpg'];
                        
                        $cover_index = isset($_POST['cover_image_select']) ? (int)$_POST['cover_image_select'] : 0;
                        if (!isset($all_images[$cover_index])) {
                            $old_cover = $project['cover_image'] ?? '';
                            $old_key = array_search($old_cover, $all_images);
                            $cover_index = ($old_key !== false) ? $old_key : 0;
                        }
                        
                        $project['title'] = sanitize($_POST['title']);
                        $project['category'] = sanitize($_POST['category']);
                        $project['description'] = $_POST['description'];
                        $project['images'] = $all_images;
                        $project['cover_image'] = $all_images[$cover_index];
                        $project['location'] = sanitize($_POST['location']);
                        $project['client'] = sanitize($_POST['client']);
                        $project['year'] = sanitize($_POST['year']);
                        $project['area'] = sanitize($_POST['area'] ?? '');
                        $project['specs'] = $specs_array;
                        $project['show_on_home'] = isset($_POST['show_on_home']) ? true : false;
                        $project['updated_at'] = date('Y-m-d H:i:s');
                        break;
                    }
                }
                write_json('projects.json', $projects);

                // اعضای پروژه + فیلدهای مدیریت پروژه
                $pid = $project_id;
                $members = [];
                foreach ((array)($_POST['member_uid'] ?? []) as $i => $muid) {
                    if ((string)$muid === '') continue;
                    $members[] = [
                        'user_uid' => (string)$muid,
                        'role_in_project' => (string)(($_POST['member_role'] ?? [])[$i] ?? 'عضو تیم'),
                    ];
                }
                if (Db::ready()) {
                    ProjectMembers::sync($pid, $members, current_admin_uid());
                    Projects::update($pid, [
                        'manager_uid'    => (string)($_POST['manager_uid'] ?? ''),
                        'client_uid'     => (string)($_POST['client_uid'] ?? ''),
                        'progress'       => (int)($_POST['progress'] ?? 0),
                        'start_date'     => (string)($_POST['start_date'] ?? ''),
                        'end_date'       => (string)($_POST['end_date'] ?? ''),
                        'client_visible' => isset($_POST['client_visible']),
                        'show_on_home'   => isset($_POST['show_on_home']),
                    ]);
                }

                add_log('edit_project', "پروژه {$project_id} ویرایش شد");
                $message = '✅ پروژه ویرایش شد';
                $show_form = false;
                $editing_project = null;
            }
        }
        
        if ($_POST['action'] === 'delete_project') {
            $project_id = $_POST['project_id'];
            $projects = read_json('projects.json');
            foreach ($projects as $key => $project) {
                if ($project['id'] === $project_id) {
                    unset($projects[$key]);
                    write_json('projects.json', array_values($projects));
                    add_log('delete_project', "پروژه {$project['title']} حذف شد");
                    $message = '✅ پروژه حذف شد';
                    break;
                }
            }
        }
        
        if ($_POST['action'] === 'toggle_home') {
            $project_id = $_POST['project_id'];
            $projects = read_json('projects.json');
            foreach ($projects as &$project) {
                if ($project['id'] === $project_id) {
                    $project['show_on_home'] = !($project['show_on_home'] ?? false);
                    break;
                }
            }
            write_json('projects.json', $projects);
        }
    }
}

$projects = get_projects();
usort($projects, function($a, $b) {
    return strtotime($b['created_at'] ?? 'now') - strtotime($a['created_at'] ?? 'now');
});

$all_users   = Users::list(['active' => true, 'internal_only' => true]);
$all_clients = Clients::list();
$edit_members = [];
if ($editing_project) {
    foreach (ProjectMembers::list((string)($editing_project['id'] ?? '')) as $mm) {
        $edit_members[$mm['user_uid']] = $mm['role_in_project'];
    }
}

$categories = read_json('categories.json');
if (empty($categories)) {
    $categories = [
        ['id' => 'cat_1', 'name' => 'معماری و سازه', 'slug' => 'architecture'],
        ['id' => 'cat_2', 'name' => 'تأسیسات برقی و مکانیکی', 'slug' => 'mechanic'],
        ['id' => 'cat_3', 'name' => 'صنعتی و هیدرولیکی', 'slug' => 'industrial'],
        ['id' => 'cat_4', 'name' => 'عمرانی و ژئوتکنیک', 'slug' => 'civil']
    ];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت پروژه‌ها | پنل مدیریت</title>
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
                <h1>🏗️ مدیریت پروژه‌ها</h1>
                <?php if ($is_viewer): ?><span class="badge" style="background:#fff8e1;color:#e65100;">👁️ حالت مشاهده</span><?php endif; ?>
            </header>
            
            <?php if ($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
            <?php if ($is_viewer): ?><div class="viewer-banner">⛔ شما فقط بیننده هستید!</div><?php endif; ?>
            
            <?php if ($show_form): ?>
            <div class="form-modern">
                <div class="form-modern__header">
                    <h2><?php echo $editing_project ? ($is_viewer ? '👁️ مشاهده پروژه' : '✏️ ویرایش پروژه') : '➕ افزودن پروژه'; ?></h2>
                    <a href="manage-projects.php" class="btn-back">← بازگشت</a>
                </div>
                
                <form method="POST" enctype="multipart/form-data" id="projectForm">
                    <input type="hidden" name="action" value="<?php echo $editing_project ? 'edit_project' : 'add_project'; ?>">
                    <?php if ($editing_project): ?><input type="hidden" name="project_id" value="<?php echo $editing_project['id']; ?>"><?php endif; ?>
                    
                    <div class="form-modern__body">
                        <!-- اطلاعات اصلی -->
                        <div class="form-section">
                            <div class="form-section__title">📋 اطلاعات اصلی</div>
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label>عنوان *</label>
                                    <input type="text" name="title" value="<?php echo $editing_project['title'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : 'required'; ?>>
                                </div>
                                <div class="form-group">
                                    <label>دسته‌بندی *</label>
                                    <select name="category" <?php echo $is_viewer ? 'disabled' : 'required'; ?>>
                                        <option value="">-- انتخاب --</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo $cat['name']; ?>" <?php echo ($editing_project && $editing_project['category'] === $cat['name']) ? 'selected' : ''; ?>><?php echo $cat['name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- اطلاعات تکمیلی -->
                        <div class="form-section">
                            <div class="form-section__title">📍 اطلاعات تکمیلی</div>
                            <div class="form-grid-3">
                                <div class="form-group"><label>موقعیت</label><input type="text" name="location" value="<?php echo $editing_project['location'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>></div>
                                <div class="form-group"><label>کارفرما</label><input type="text" name="client" value="<?php echo $editing_project['client'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>></div>
                                <div class="form-group"><label>سال</label><input type="text" name="year" value="<?php echo $editing_project['year'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>></div>
                                <div class="form-group"><label>مساحت</label><input type="text" name="area" value="<?php echo $editing_project['area'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>></div>
                            </div>
                        </div>
                        
                        <!-- تصاویر -->
                        <div class="form-section">
                            <div class="form-section__title">🖼️ تصاویر پروژه</div>
                            
                            <?php if ($editing_project && !empty($editing_project['images'])): ?>
                            <div class="form-group full" style="margin-bottom:15px;">
                                <label>عکس شاخص:</label>
                                <div class="cover-select-grid">
                                    <?php foreach ($editing_project['images'] as $index => $img): ?>
                                    <label class="cover-select-item">
                                        <input type="radio" name="cover_image_select" value="<?php echo $index; ?>" <?php echo ($editing_project['cover_image'] === $img) ? 'checked' : ''; ?> <?php echo $is_viewer ? 'disabled' : ''; ?> style="display:none;">
                                        <img src="../<?php echo $img; ?>">
                                        <?php if ($editing_project['cover_image'] === $img): ?><span class="cover-label">⭐</span><?php endif; ?>
                                        <br><small style="font-size:9px;"><?php echo $index + 1; ?></small>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <?php if (!$is_viewer): ?>
                            <div class="form-group full" style="margin-bottom:15px;">
                                <label>حذف عکس‌ها:</label>
                                <div style="display:flex;flex-wrap:wrap;gap:10px;">
                                    <?php foreach ($editing_project['images'] as $img): ?>
                                    <label style="cursor:pointer;text-align:center;">
                                        <input type="checkbox" name="remove_images[]" value="<?php echo $img; ?>">
                                        <img src="../<?php echo $img; ?>" style="width:60px;height:45px;object-fit:cover;border-radius:8px;border:1px solid #ddd;display:block;">
                                        <small style="font-size:9px;color:#ff4757;">حذف</small>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php if (!$is_viewer): ?>
                            <div class="form-group full">
                                <label>آپلود عکس‌های جدید (حداکثر ۱۵ عکس):</label>
                                <div class="upload-area" onclick="document.getElementById('fileInput').click()">
                                    <div class="upload-icon">📸</div>
                                    <p>کلیک کنید و عکس‌ها را انتخاب کنید</p>
                                </div>
                                <input type="file" id="fileInput" name="images[]" accept="image/*" multiple style="display:none;" onchange="previewNewImages(this)">
                                <div class="image-preview-grid" id="newImagesPreview"></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- مدیریت پروژه -->
                        <div class="form-section">
                            <div class="form-section__title">📈 مدیریت پروژه</div>
                            <div class="form-grid-3">
                                <div class="form-group">
                                    <label>کارفرما (شرکت)</label>
                                    <select name="client_uid" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                                        <option value="">— بدون کارفرما —</option>
                                        <?php foreach ($all_clients as $c): ?>
                                            <option value="<?php echo htmlspecialchars($c['uid'], ENT_QUOTES); ?>"
                                                <?php echo (($editing_project['client_uid'] ?? '') === $c['uid']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>مدیر پروژه</label>
                                    <select name="manager_uid" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                                        <option value="">— تعیین نشده —</option>
                                        <?php foreach ($all_users as $u): ?>
                                            <option value="<?php echo htmlspecialchars($u['uid'], ENT_QUOTES); ?>"
                                                <?php echo (($editing_project['manager_uid'] ?? '') === $u['uid']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($u['full_name'], ENT_QUOTES); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>پیشرفت (٪)</label>
                                    <input type="number" name="progress" min="0" max="100"
                                           value="<?php echo (int)($editing_project['progress'] ?? 0); ?>"
                                           <?php echo $is_viewer ? 'disabled' : ''; ?>>
                                </div>
                                <div class="form-group">
                                    <label>تاریخ شروع</label>
                                    <input type="date" name="start_date"
                                           value="<?php echo htmlspecialchars((string)($editing_project['start_date'] ?? ''), ENT_QUOTES); ?>"
                                           <?php echo $is_viewer ? 'disabled' : ''; ?>>
                                </div>
                                <div class="form-group">
                                    <label>تاریخ پایان (مهلت)</label>
                                    <input type="date" name="end_date"
                                           value="<?php echo htmlspecialchars((string)($editing_project['end_date'] ?? ''), ENT_QUOTES); ?>"
                                           <?php echo $is_viewer ? 'disabled' : ''; ?>>
                                </div>
                                <div class="form-group">
                                    <label>نمایش در پنل کارفرما</label>
                                    <label style="display:flex;align-items:center;gap:8px;font-size:12px;font-weight:400;margin-top:8px">
                                        <input type="checkbox" name="client_visible" value="1"
                                               <?php echo !empty($editing_project['client_visible']) ? 'checked' : ''; ?>
                                               <?php echo $is_viewer ? 'disabled' : ''; ?>>
                                        کارفرما این پروژه و گزارش‌هایش را ببیند
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- اعضای پروژه -->
                        <div class="form-section">
                            <div class="form-section__title">👥 اعضای پروژه</div>
                            <p style="font-size:12px;color:#888;margin:0 0 12px">
                                اعضای انتخاب‌شده به تسک‌ها و گزارش‌های این پروژه دسترسی دارند و اعلان‌ها برایشان ارسال می‌شود.
                            </p>
                            <div style="border:1px solid #e5e5e5;border-radius:12px;padding:14px;max-height:300px;overflow:auto">
                                <?php if (!$all_users): ?>
                                    <p style="color:#999;font-size:12px;margin:0">کاربری برای انتخاب وجود ندارد.</p>
                                <?php else: ?>
                                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px">
                                    <?php foreach ($all_users as $u):
                                        $on = array_key_exists($u['uid'], $edit_members);
                                    ?>
                                    <label style="display:flex;align-items:center;gap:8px;background:#fafafa;border:1px solid #eee;border-radius:10px;padding:8px 10px;font-size:12px">
                                        <input type="checkbox" name="member_uid[]" value="<?php echo htmlspecialchars($u['uid'], ENT_QUOTES); ?>"
                                               <?php echo $on ? 'checked' : ''; ?> <?php echo $is_viewer ? 'disabled' : ''; ?>
                                               style="width:auto">
                                        <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                            <?php echo htmlspecialchars($u['full_name'], ENT_QUOTES); ?>
                                            <small style="color:#999">(<?php echo htmlspecialchars(Users::roleLabel((string)$u['role']), ENT_QUOTES); ?>)</small>
                                        </span>
                                        <input type="text" name="member_role[]"
                                               value="<?php echo htmlspecialchars((string)($edit_members[$u['uid']] ?? 'عضو تیم'), ENT_QUOTES); ?>"
                                               placeholder="نقش" <?php echo $is_viewer ? 'disabled' : ''; ?>
                                               style="width:88px;padding:5px 8px;font-size:11px">
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($editing_project): ?>
                            <p style="font-size:11.5px;color:#888;margin:10px 0 0">
                                💡 برای مدیریت وظایف و گزارش پیشرفت، پس از ذخیره به
                                <a href="workspace.php?project=<?php echo htmlspecialchars((string)($editing_project['id'] ?? ''), ENT_QUOTES); ?>">میز کار پروژه</a> بروید.
                            </p>
                            <?php endif; ?>
                        </div>

                        <!-- مشخصات فنی -->
                        <div class="form-section">
                            <div class="form-section__title">🔧 مشخصات فنی</div>
                            <div class="form-group full">
                                <textarea name="specs_text" rows="5" <?php echo $is_viewer ? 'disabled' : ''; ?>><?php if ($editing_project && !empty($editing_project['specs'])) echo htmlspecialchars(implode("\n", $editing_project['specs'])); ?></textarea>
                            </div>
                        </div>
                        
                        <!-- توضیحات -->
                        <div class="form-section">
                            <div class="form-section__title">📝 توضیحات</div>
                            <div class="form-group full">
                                <div class="mini-toolbar">
                                    <button type="button" onclick="doCmd('bold')" <?php echo $is_viewer ? 'disabled' : ''; ?>><b>B</b></button>
                                    <button type="button" onclick="doCmd('italic')" <?php echo $is_viewer ? 'disabled' : ''; ?>><i>I</i></button>
                                    <button type="button" onclick="doCmd('underline')" <?php echo $is_viewer ? 'disabled' : ''; ?>><u>U</u></button>
                                    <button type="button" onclick="doCmd('insertUnorderedList')" <?php echo $is_viewer ? 'disabled' : ''; ?>>• لیست</button>
                                    <button type="button" onclick="doCmd('insertOrderedList')" <?php echo $is_viewer ? 'disabled' : ''; ?>>۱. لیست</button>
                                    <button type="button" onclick="doCmd('formatBlock', 'h2')" <?php echo $is_viewer ? 'disabled' : ''; ?>>H2</button>
                                    <button type="button" onclick="doCmd('formatBlock', 'h3')" <?php echo $is_viewer ? 'disabled' : ''; ?>>H3</button>
                                    <button type="button" onclick="doCmd('formatBlock', 'p')" <?php echo $is_viewer ? 'disabled' : ''; ?>>P</button>
                                </div>
                                <div class="mini-editor" id="descriptionMiniEditor" contenteditable="<?php echo $is_viewer ? 'false' : 'true'; ?>"><?php echo $editing_project['description'] ?? ''; ?></div>
                                <textarea name="description" id="descriptionEditor" style="display:none;"><?php echo $editing_project['description'] ?? ''; ?></textarea>
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
                <span class="stat-pill">📊 <?php echo count($projects); ?> پروژه</span>
                <?php if (!$is_viewer): ?><a href="manage-projects.php?add=1" class="btn btn-primary">➕ افزودن پروژه</a><?php endif; ?>
            </div>
            
            <div class="projects-grid">
                <?php foreach ($projects as $project): 
                    $cover = $project['cover_image'] ?? ($project['images'][0] ?? 'assets/default-project.jpg');
                    $images_count = isset($project['images']) ? count($project['images']) : 1;
                ?>
                <div class="project-card">
                    <div class="project-card__image">
                        <img src="../<?php echo $cover; ?>">
                        <span class="project-card__badge <?php echo !empty($project['show_on_home']) ? 'home-active' : ''; ?>"><?php echo !empty($project['show_on_home']) ? '🏠 خانه' : $project['category']; ?></span>
                        <span class="project-card__images-count">📷 <?php echo $images_count; ?></span>
                    </div>
                    <div class="project-card__body">
                        <h3 class="project-card__title"><?php echo $project['title']; ?></h3>
                        <div class="project-card__meta">
                            <span>👁️ <?php echo $project['views'] ?? 0; ?></span>
                            <?php if (!empty($project['year'])): ?><span>📅 <?php echo $project['year']; ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="project-card__footer">
                        <span style="font-size:11px;color:#999;"><?php echo $project['category']; ?></span>
                        <div class="project-actions">
                            <a href="../project/detail.php?id=<?php echo $project['id']; ?>" target="_blank" class="btn-icon">👁️</a>
                            <a href="manage-projects.php?edit=<?php echo $project['id']; ?>" class="btn-icon"><?php echo $is_viewer ? '👁️' : '✏️'; ?></a>
                            <?php if (!$is_viewer): ?>
                            <form method="POST" style="display:inline;"><input type="hidden" name="action" value="toggle_home"><input type="hidden" name="project_id" value="<?php echo $project['id']; ?>"><button type="submit" class="btn-icon">🏠</button></form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('حذف شود؟');"><input type="hidden" name="action" value="delete_project"><input type="hidden" name="project_id" value="<?php echo $project['id']; ?>"><button type="submit" class="btn-icon">🗑️</button></form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>
    
    <script>
    function doCmd(cmd, val = null) { if (<?php echo $is_viewer ? 'true' : 'false'; ?>) return; document.execCommand(cmd, false, val); document.getElementById('descriptionMiniEditor').focus(); syncEditor(); }
    function syncEditor() { document.getElementById('descriptionEditor').value = document.getElementById('descriptionMiniEditor').innerHTML; }
    document.getElementById('descriptionMiniEditor')?.addEventListener('input', syncEditor);
    function previewNewImages(input) {
        const container = document.getElementById('newImagesPreview');
        container.innerHTML = '';
        if (input.files) {
            Array.from(input.files).slice(0, 15).forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'image-preview-item';
                    div.innerHTML = `<img src="${e.target.result}">`;
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