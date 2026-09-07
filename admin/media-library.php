<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/logger.php';

check_login();

$message = '';
$error = '';
$is_viewer = ($_SESSION['admin_role'] ?? '') === 'viewer';

function gd_available() {
    return function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng');
}

function optimize_image($source_path, $target_path, $max_width = 1200, $quality = 75) {
    if (!gd_available()) {
        copy($source_path, $target_path);
        return true;
    }
    
    $ext = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));
    $info = @getimagesize($source_path);
    if (!$info) {
        copy($source_path, $target_path);
        return true;
    }
    
    $src_width = $info[0];
    $src_height = $info[1];
    
    if ($src_width <= $max_width) {
        copy($source_path, $target_path);
        return true;
    }
    
    $ratio = $max_width / $src_width;
    $new_width = $max_width;
    $new_height = round($src_height * $ratio);
    
    $src = null;
    switch ($ext) {
        case 'jpg': case 'jpeg': if (function_exists('imagecreatefromjpeg')) $src = @imagecreatefromjpeg($source_path); break;
        case 'png': if (function_exists('imagecreatefrompng')) $src = @imagecreatefrompng($source_path); break;
        case 'gif': if (function_exists('imagecreatefromgif')) $src = @imagecreatefromgif($source_path); break;
        case 'webp': if (function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($source_path); break;
    }
    
    if (!$src) { copy($source_path, $target_path); return true; }
    
    $dst = imagecreatetruecolor($new_width, $new_height);
    if ($ext === 'png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $new_width, $new_height, $transparent);
    }
    
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_width, $new_height, $src_width, $src_height);
    
    switch ($ext) {
        case 'jpg': case 'jpeg': imagejpeg($dst, $target_path, $quality); break;
        case 'png': imagepng($dst, $target_path, 8); break;
        case 'gif': imagegif($dst, $target_path); break;
        case 'webp': imagewebp($dst, $target_path, $quality); break;
    }
    
    imagedestroy($src);
    imagedestroy($dst);
    return true;
}

cleanup_media_trash();

function cleanup_media_trash() {
    $trash = read_json('trash_media.json');
    if (!is_array($trash)) $trash = [];
    $changed = false;
    foreach ($trash as $key => $item) {
        if (!is_array($item)) continue;
        if (isset($item['deleted_at']) && (time() - strtotime($item['deleted_at'])) > (30 * 24 * 60 * 60)) {
            $full_path = '../' . ($item['path'] ?? '');
            if (file_exists($full_path)) @unlink($full_path);
            unset($trash[$key]);
            $changed = true;
        }
    }
    if ($changed) write_json('trash_media.json', array_values($trash));
}

function clean_image_path($path) {
    $clean_path = trim($path);
    $clean_path = preg_replace('/^(\.\.\/)+/', '', $clean_path);
    $clean_path = preg_replace('/^(\.\/)+/', '', $clean_path);
    $clean_path = str_replace('\\', '/', $clean_path);
    $clean_path = ltrim($clean_path, '/');
    if (!empty($clean_path) && strlen($clean_path) < 200 && strpos($clean_path, '..') === false) return $clean_path;
    return false;
}

function extract_images_from_array($array) {
    $images = [];
    if (!is_array($array)) return $images;
    foreach ($array as $value) {
        if (is_array($value)) $images = array_merge($images, extract_images_from_array($value));
        elseif (is_string($value) && preg_match('/\.(?:jpg|jpeg|png|gif|webp|svg)$/i', $value)) $images[] = $value;
    }
    return $images;
}

function scan_site_for_images() {
    $used_images = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('../', RecursiveDirectoryIterator::SKIP_DOTS));
    
    foreach ($files as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['php', 'html', 'css', 'js', 'json'])) continue;
        $content = @file_get_contents($file->getPathname());
        if (!$content) continue;
        
        $patterns = [
            '/(?:uploads|assets)\/[a-zA-Z0-9\/_\-\.]+\.(?:jpg|jpeg|png|gif|webp|svg)/i',
            '/src=["\']([^"\']*\.(?:jpg|jpeg|png|gif|webp|svg))["\']/i',
            "/['\"]([^'\"]*\.(?:jpg|jpeg|png|gif|webp|svg))['\"]/i"
        ];
        
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            if (isset($matches[1]) && is_array($matches[1])) {
                foreach ($matches[1] as $match) {
                    $clean = clean_image_path($match);
                    if ($clean) $used_images[] = $clean;
                }
            }
        }
        
        if ($ext === 'json') {
            $json_data = json_decode($content, true);
            if ($json_data) {
                foreach (extract_images_from_array($json_data) as $img) {
                    $clean = clean_image_path($img);
                    if ($clean) $used_images[] = $clean;
                }
            }
        }
    }
    return array_values(array_unique($used_images));
}

// ============ پردازش فرم ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($is_viewer) {
        $error = '⛔ شما فقط بیننده هستید!';
    } else {
        // آپلود
        if ($_POST['action'] === 'upload') {
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/media/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
                $file_ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $file_name = 'media_' . time() . '_' . uniqid() . '.' . $file_ext;
                optimize_image($_FILES['image']['tmp_name'], $upload_dir . $file_name, 1200, 75);
                $message = '✅ آپلود شد';
            }
        }
        
        // انتقال به زباله‌دان (تکی یا گروهی)
        if ($_POST['action'] === 'move_to_trash') {
            $paths = $_POST['paths'] ?? [];
            if (!is_array($paths)) $paths = [$paths];
            $trash = read_json('trash_media.json');
            if (!is_array($trash)) $trash = [];
            foreach ($paths as $path) {
                $trash[] = ['path' => $path, 'deleted_at' => date('Y-m-d H:i:s')];
            }
            write_json('trash_media.json', $trash);
            $message = '✅ ' . count($paths) . ' عکس به زباله‌دان رفت';
        }
        
        // حذف کامل (تکی یا گروهی) - حذف فوری بدون زباله‌دان
        if ($_POST['action'] === 'delete_permanent_selected') {
            $paths = $_POST['paths'] ?? [];
            if (!is_array($paths)) $paths = [$paths];
            $deleted = 0;
            foreach ($paths as $path) {
                $full_path = '../' . $path;
                if (file_exists($full_path)) {
                    @unlink($full_path);
                    $deleted++;
                }
            }
            $message = "✅ $deleted عکس برای همیشه حذف شد";
        }
        
        // بهینه‌سازی گروهی
        if ($_POST['action'] === 'optimize_selected') {
            $paths = $_POST['paths'] ?? [];
            if (!is_array($paths)) $paths = [$paths];
            $optimized = 0;
            foreach ($paths as $path) {
                $full_path = '../' . $path;
                if (file_exists($full_path)) {
                    $temp_path = $full_path . '.tmp';
                    if (optimize_image($full_path, $temp_path, 1200, 70)) {
                        @unlink($full_path);
                        rename($temp_path, $full_path);
                        $optimized++;
                    }
                }
            }
            $message = "✅ $optimized عکس بهینه شد";
        }
        
        // بهینه‌سازی تک
        if ($_POST['action'] === 'optimize_single') {
            $full_path = '../' . ($_POST['path'] ?? '');
            if (file_exists($full_path)) {
                $temp_path = $full_path . '.tmp';
                if (optimize_image($full_path, $temp_path, 1200, 70)) {
                    @unlink($full_path);
                    rename($temp_path, $full_path);
                    $message = '✅ بهینه شد';
                }
            }
        }
        
        // بازیابی (تکی)
        if ($_POST['action'] === 'restore_image') {
            $trash = read_json('trash_media.json');
            foreach ($trash as $key => $item) {
                if (($item['path'] ?? '') === ($_POST['path'] ?? '')) { unset($trash[$key]); break; }
            }
            write_json('trash_media.json', array_values($trash));
            $message = '✅ بازیابی شد';
        }
        
        // بازیابی گروهی از زباله‌دان
        if ($_POST['action'] === 'restore_selected') {
            $paths = $_POST['paths'] ?? [];
            if (!is_array($paths)) $paths = [$paths];
            $trash = read_json('trash_media.json');
            if (!is_array($trash)) $trash = [];
            
            foreach ($trash as $key => $item) {
                if (in_array($item['path'] ?? '', $paths)) {
                    unset($trash[$key]);
                }
            }
            write_json('trash_media.json', array_values($trash));
            $message = '✅ ' . count($paths) . ' عکس بازیابی شد';
        }
        
        // حذف کامل از زباله‌دان
        if ($_POST['action'] === 'permanent_delete') {
            $full_path = '../' . ($_POST['path'] ?? '');
            if (file_exists($full_path)) @unlink($full_path);
            $trash = read_json('trash_media.json');
            foreach ($trash as $key => $item) {
                if (($item['path'] ?? '') === ($_POST['path'] ?? '')) { unset($trash[$key]); break; }
            }
            write_json('trash_media.json', array_values($trash));
            $message = '✅ حذف شد';
        }
        
        // حذف گروهی کامل از زباله‌دان
        if ($_POST['action'] === 'permanent_delete_selected') {
            $paths = $_POST['paths'] ?? [];
            if (!is_array($paths)) $paths = [$paths];
            $deleted = 0;
            
            foreach ($paths as $path) {
                $full_path = '../' . $path;
                if (file_exists($full_path)) {
                    @unlink($full_path);
                    $deleted++;
                }
            }
            
            $trash = read_json('trash_media.json');
            $trash = array_filter($trash, function($item) use ($paths) {
                return !in_array($item['path'] ?? '', $paths);
            });
            write_json('trash_media.json', array_values($trash));
            $message = "✅ $deleted عکس برای همیشه حذف شد";
        }
        
        // خالی کردن زباله‌دان
        if ($_POST['action'] === 'empty_trash') {
            $trash = read_json('trash_media.json');
            if (is_array($trash)) {
                foreach ($trash as $item) {
                    $full_path = '../' . ($item['path'] ?? '');
                    if (file_exists($full_path)) @unlink($full_path);
                }
            }
            write_json('trash_media.json', []);
            $message = '✅ زباله‌دان خالی شد';
        }
        
        // جایگزینی
        if ($_POST['action'] === 'replace_image') {
            $old_path = $_POST['old_path'] ?? '';
            $full_old_path = '../' . $old_path;
            if (isset($_FILES['replacement']) && $_FILES['replacement']['error'] === UPLOAD_ERR_OK) {
                if (file_exists($full_old_path)) @unlink($full_old_path);
                optimize_image($_FILES['replacement']['tmp_name'], $full_old_path, 1200, 75);
                $message = '✅ جایگزین شد';
            }
        }
    }
}

// جمع‌آوری
$used_in_code = scan_site_for_images();
$media_files = [];
$total_size_bytes = 0;
$directories = ['uploads/projects/' => 'پروژه‌ها', 'uploads/blog/' => 'مقالات', 'uploads/team/' => 'اعضای تیم', 'uploads/users/' => 'کاربران', 'uploads/media/' => 'گالری', 'assets/' => 'دارایی‌ها'];

$trash_data = read_json('trash_media.json');
if (!is_array($trash_data)) $trash_data = [];

foreach ($directories as $dir => $label) {
    $full_path = '../' . $dir;
    if (!file_exists($full_path)) continue;
    $files = glob($full_path . '*');
    if (!is_array($files)) continue;
    
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) continue;
        
        $relative_path = $dir . basename($file);
        $in_trash = false;
        foreach ($trash_data as $item) {
            if (($item['path'] ?? '') === $relative_path) { $in_trash = true; break; }
        }
        if ($in_trash) continue;
        
        $size_bytes = filesize($file);
        $total_size_bytes += $size_bytes;
        $size_kb = round($size_bytes / 1024, 1);
        $dimensions = @getimagesize($file);
        
        $is_used = false;
        $usage = [];
        
        foreach ($used_in_code as $used_path) {
            if ($used_path === $relative_path || strpos($relative_path, $used_path) !== false || strpos($used_path, $relative_path) !== false || basename($used_path) === basename($relative_path)) {
                $is_used = true;
                $usage[] = 'کد سایت';
                break;
            }
        }
        
        $projects = read_json('projects.json');
        if (is_array($projects)) {
            foreach ($projects as $project) {
                $project_images = $project['images'] ?? [];
                if (is_array($project_images) && (in_array($relative_path, $project_images) || in_array(basename($relative_path), array_map('basename', $project_images)))) {
                    $is_used = true;
                    $usage[] = 'پروژه: ' . ($project['title'] ?? '');
                }
                if (($project['cover_image'] ?? '') === $relative_path || basename($project['cover_image'] ?? '') === basename($relative_path)) {
                    $is_used = true;
                    $usage[] = 'کاور: ' . ($project['title'] ?? '');
                }
            }
        }
        
        $posts = read_json('blog_posts.json');
        if (is_array($posts)) {
            foreach ($posts as $post) {
                if (($post['image'] ?? '') === $relative_path || basename($post['image'] ?? '') === basename($relative_path)) {
                    $is_used = true;
                    $usage[] = 'مقاله: ' . ($post['title'] ?? '');
                }
            }
        }
        
        $team = read_json('team.json');
        if (is_array($team)) {
            foreach ($team as $member) {
                if (($member['photo'] ?? '') === $relative_path || basename($member['photo'] ?? '') === basename($relative_path)) {
                    $is_used = true;
                    $usage[] = 'تیم: ' . ($member['name'] ?? '');
                }
            }
        }
        
        $users_data = read_json('users.json');
        if (is_array($users_data)) {
            foreach (($users_data['users'] ?? []) as $user) {
                if (($user['photo'] ?? '') === $relative_path || basename($user['photo'] ?? '') === basename($relative_path)) {
                    $is_used = true;
                    $usage[] = 'کاربر: ' . ($user['full_name'] ?? '');
                }
            }
        }
        
        $media_files[] = [
            'path' => $relative_path,
            'name' => basename($file),
            'size' => $size_kb,
            'directory' => $label,
            'width' => $dimensions[0] ?? 0,
            'height' => $dimensions[1] ?? 0,
            'is_used' => $is_used,
            'usage' => array_unique($usage)
        ];
    }
}

$filter = $_GET['filter'] ?? 'all';
$active_tab = $_GET['tab'] ?? 'all';

if ($filter === 'unused') $media_files = array_filter($media_files, fn($m) => !$m['is_used']);
elseif ($filter === 'used') $media_files = array_filter($media_files, fn($m) => $m['is_used']);
elseif ($filter === 'small') $media_files = array_filter($media_files, fn($m) => $m['size'] <= 200);
elseif ($filter === 'large') $media_files = array_filter($media_files, fn($m) => $m['size'] > 500);

$media_files = array_values($media_files);
$total_size_mb = round($total_size_bytes / 1024 / 1024, 1);

$trash_files = [];
foreach ($trash_data as $item) {
    $full_path = '../' . ($item['path'] ?? '');
    if (file_exists($full_path)) {
        $trash_files[] = [
            'path' => $item['path'],
            'name' => basename($item['path']),
            'size' => round(filesize($full_path) / 1024, 1),
            'days_left' => max(0, 30 - floor((time() - strtotime($item['deleted_at'] ?? 'now')) / (24 * 60 * 60)))
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>کتابخانه رسانه | پنل مدیریت</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .media-tabs { display: flex; gap: 5px; margin-bottom: 15px; background: #fff; padding: 8px; border-radius: 14px; border: 1px solid #e9ecef; flex-wrap: wrap; }
        .tab-btn { padding: 9px 16px; border: none; background: transparent; border-radius: 10px; cursor: pointer; font-family: inherit; font-size: 12px; font-weight: 700; transition: all 0.3s; display: flex; align-items: center; gap: 6px; text-decoration: none; color: #666; }
        .tab-btn:hover { background: #f5f5f5; }
        .tab-btn.active { background: #1a1a1a; color: #fff; }
        .tab-count { background: rgba(0,0,0,0.1); padding: 1px 8px; border-radius: 10px; font-size: 10px; }
        .tab-btn.active .tab-count { background: rgba(255,255,255,0.2); }
        
        .stats-row { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
        .stat-box { background: #fff; padding: 15px 20px; border-radius: 12px; border: 1px solid #e9ecef; text-align: center; min-width: 100px; }
        .stat-box .num { font-size: 20px; font-weight: 900; display: block; }
        .stat-box .lbl { font-size: 10px; color: #999; }
        
        .filter-bar { display: flex; gap: 5px; margin-bottom: 15px; flex-wrap: wrap; }
        .filter-btn { padding: 7px 14px; border: 1px solid #e0e0e0; border-radius: 20px; cursor: pointer; font-family: inherit; font-size: 11px; font-weight: 700; transition: all 0.3s; text-decoration: none; color: #666; background: #fff; }
        .filter-btn.active { background: #1a1a1a; color: #fff; border-color: #1a1a1a; }
        
        .upload-zone { border: 3px dashed #c0c0c0; border-radius: 20px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.4s; background: linear-gradient(135deg, #fafafa, #f0f0f0); margin-bottom: 20px; }
        .upload-zone:hover { border-color: #1a1a1a; transform: scale(1.01); }
        .upload-zone .upload-icon { font-size: 40px; display: block; margin-bottom: 10px; }
        .upload-zone .upload-title { font-size: 14px; font-weight: 900; }
        .upload-zone .upload-btn { display: inline-block; padding: 10px 25px; background: #1a1a1a; color: #fff; border-radius: 12px; font-size: 12px; font-weight: 700; margin-top: 12px; cursor: pointer; border: none; font-family: inherit; }
        
        .bulk-bar {
            display: none; background: #1a1a1a; color: #fff;
            padding: 12px 20px; border-radius: 12px;
            margin-bottom: 15px; align-items: center; gap: 8px; flex-wrap: wrap;
            position: sticky; top: 10px; z-index: 100;
        }
        .bulk-bar.show { display: flex; }
        .bulk-btn { padding: 8px 14px; border: none; border-radius: 8px; font-family: inherit; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.3s; }
        .bulk-btn.trash { background: #ff4757; color: #fff; }
        .bulk-btn.trash:hover { background: #d63031; }
        .bulk-btn.opt { background: #ffa502; color: #fff; }
        .bulk-btn.opt:hover { background: #e69500; }
        .bulk-btn.restore { background: #2ed573; color: #fff; }
        .bulk-btn.restore:hover { background: #26b958; }
        .bulk-btn.cancel { background: #555; color: #fff; }
        .bulk-btn.cancel:hover { background: #444; }
        
        .media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 15px; }
        .media-card { background: #fff; border-radius: 14px; overflow: hidden; border: 1px solid #e9ecef; transition: all 0.3s; position: relative; }
        .media-card:hover { box-shadow: 0 8px 25px rgba(0,0,0,0.1); transform: translateY(-4px); }
        .media-card.selected { border: 2px solid #3742fa; box-shadow: 0 0 20px rgba(55,66,250,0.2); }
        .media-checkbox { position: absolute; top: 10px; left: 10px; z-index: 5; width: 24px; height: 24px; cursor: pointer; accent-color: #3742fa; }
        .media-image { width: 100%; aspect-ratio: 1; overflow: hidden; background: #f5f5f5; }
        .media-image img { width: 100%; height: 100%; object-fit: cover; }
        .size-badge { position: absolute; top: 10px; right: 10px; padding: 4px 10px; border-radius: 15px; font-size: 10px; font-weight: 700; background: rgba(0,0,0,0.7); color: #fff; }
        .media-info { padding: 12px; }
        .media-name { font-size: 10px; font-weight: 700; direction: ltr; text-align: right; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .media-meta { font-size: 9px; color: #999; margin-top: 4px; display: flex; gap: 6px; flex-wrap: wrap; }
        .used-badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 9px; font-weight: 700; margin-top: 6px; }
        .used-badge.used { background: #e8f5e9; color: #2e7d32; }
        .used-badge.unused { background: #fff8e1; color: #e65100; }
        .usage-detail { font-size: 8px; color: #999; margin-top: 3px; }
        
        .media-actions { display: flex; gap: 5px; padding: 10px; border-top: 1px solid #f0f0f0; justify-content: center; flex-wrap: wrap; }
        .action-btn { width: 35px; height: 35px; border-radius: 10px; border: 1px solid #e0e0e0; cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; transition: all 0.3s; text-decoration: none; background: #fff; font-family: inherit; }
        .action-btn:hover { transform: translateY(-3px); }
        .action-btn.view { color: #ffa502; }
        .action-btn.view:hover { background: #ffa502; color: #fff; border-color: #ffa502; }
        .action-btn.copy { color: #3742fa; }
        .action-btn.copy:hover { background: #3742fa; color: #fff; border-color: #3742fa; }
        .action-btn.opt { color: #ffa502; }
        .action-btn.opt:hover { background: #ffa502; color: #fff; border-color: #ffa502; }
        .action-btn.replace { color: #9c27b0; }
        .action-btn.replace:hover { background: #9c27b0; color: #fff; border-color: #9c27b0; }
        .action-btn.trash { color: #ff4757; }
        .action-btn.trash:hover { background: #ff4757; color: #fff; border-color: #ff4757; }
        
        .replace-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; justify-content: center; align-items: center; }
        .replace-modal.active { display: flex; }
        .replace-box { background: #fff; border-radius: 20px; padding: 30px; max-width: 400px; width: 90%; text-align: center; }
        
        @media (max-width: 768px) { .media-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; } }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="top-bar"><h1>🖼️ کتابخانه رسانه</h1></header>
            
            <?php if ($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
            
            <div class="media-tabs">
                <a href="?tab=all" class="tab-btn <?php echo $active_tab === 'all' ? 'active' : ''; ?>">📸 عکس‌ها <span class="tab-count"><?php echo count($media_files); ?></span></a>
                <a href="?tab=trash" class="tab-btn <?php echo $active_tab === 'trash' ? 'active' : ''; ?>">🗑️ زباله‌دان <span class="tab-count"><?php echo count($trash_files); ?></span></a>
            </div>
            
            <?php if ($active_tab === 'all'): ?>
                <div class="stats-row">
                    <div class="stat-box"><span class="num"><?php echo count($media_files); ?></span><span class="lbl">عکس</span></div>
                    <div class="stat-box"><span class="num"><?php echo $total_size_mb; ?> MB</span><span class="lbl">حجم کل</span></div>
                    <div class="stat-box"><span class="num"><?php echo count(array_filter($media_files, fn($m) => $m['is_used'])); ?></span><span class="lbl">استفاده شده</span></div>
                    <div class="stat-box"><span class="num" style="color:#e65100;"><?php echo count(array_filter($media_files, fn($m) => !$m['is_used'])); ?></span><span class="lbl">استفاده نشده</span></div>
                </div>
                
                <div class="filter-bar">
                    <a href="?tab=all&filter=all" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">همه</a>
                    <a href="?tab=all&filter=used" class="filter-btn <?php echo $filter === 'used' ? 'active' : ''; ?>">✅ استفاده شده</a>
                    <a href="?tab=all&filter=unused" class="filter-btn <?php echo $filter === 'unused' ? 'active' : ''; ?>">⚠️ استفاده نشده</a>
                    <a href="?tab=all&filter=small" class="filter-btn <?php echo $filter === 'small' ? 'active' : ''; ?>">🟢 کم حجم</a>
                    <a href="?tab=all&filter=large" class="filter-btn <?php echo $filter === 'large' ? 'active' : ''; ?>">🔴 پر حجم</a>
                </div>
                
                <?php if (!$is_viewer): ?>
                <div class="upload-zone" onclick="document.getElementById('mediaInput').click()">
                    <span class="upload-icon">📤</span>
                    <span class="upload-title">آپلود عکس جدید</span>
                    <p style="font-size:11px;color:#999;">عکس‌ها خودکار بهینه می‌شوند</p>
                    <button type="button" class="upload-btn">انتخاب عکس</button>
                </div>
                <input type="file" id="mediaInput" accept="image/*" style="display:none;" onchange="uploadMedia(this)">
                <form method="POST" enctype="multipart/form-data" id="uploadForm" style="display:none;">
                    <input type="hidden" name="action" value="upload">
                    <input type="file" name="image" id="hiddenFileInput">
                </form>
                <?php endif; ?>
                
                <!-- نوار انتخاب گروهی -->
                <div class="bulk-bar" id="bulkBar">
                    <span id="selectedCount" style="font-size:12px;">۰ انتخاب</span>
                    
                    <form method="POST" id="trashForm" onsubmit="return confirm('انتقال به زباله‌دان؟');" style="display:inline;">
                        <input type="hidden" name="action" value="move_to_trash">
                        <div id="trashPaths"></div>
                        <button type="submit" class="bulk-btn trash">🗑️ زباله‌دان</button>
                    </form>
                    
                    <form method="POST" id="deleteForm" onsubmit="return confirm('برای همیشه حذف شود؟');" style="display:inline;">
                        <input type="hidden" name="action" value="delete_permanent_selected">
                        <div id="deletePaths"></div>
                        <button type="submit" class="bulk-btn trash" style="background:#b71c1c;">❌ حذف کامل</button>
                    </form>
                    
                    <form method="POST" id="optForm" onsubmit="return confirm('بهینه‌سازی شود؟');" style="display:inline;">
                        <input type="hidden" name="action" value="optimize_selected">
                        <div id="optPaths"></div>
                        <button type="submit" class="bulk-btn opt">⚡ بهینه‌سازی</button>
                    </form>
                    
                    <button class="bulk-btn cancel" onclick="clearSelection()">لغو</button>
                </div>
                
                <div class="media-grid">
                    <?php foreach ($media_files as $media): ?>
                    <div class="media-card" id="card-<?php echo md5($media['path']); ?>">
                        <?php if (!$is_viewer): ?>
                        <input type="checkbox" class="media-checkbox" onchange="toggleSelect(this, '<?php echo $media['path']; ?>')">
                        <?php endif; ?>
                        
                        <div class="media-image">
                            <img src="../<?php echo $media['path']; ?>" loading="lazy">
                            <span class="size-badge"><?php echo $media['size']; ?> KB</span>
                        </div>
                        
                        <div class="media-info">
                            <div class="media-name"><?php echo $media['name']; ?></div>
                            <div class="media-meta"><span><?php echo $media['width']; ?>×<?php echo $media['height']; ?></span><span><?php echo $media['directory']; ?></span></div>
                            <?php if ($media['is_used']): ?>
                                <span class="used-badge used">✅ استفاده شده</span>
                                <div class="usage-detail"><?php echo implode('، ', $media['usage']); ?></div>
                            <?php else: ?>
                                <span class="used-badge unused">⚠️ استفاده نشده</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="media-actions">
                            <a href="../<?php echo $media['path']; ?>" target="_blank" class="action-btn view">👁️</a>
                            <button class="action-btn copy" onclick="navigator.clipboard.writeText('<?php echo 'https://' . $_SERVER['HTTP_HOST'] . '/' . $media['path']; ?>').then(()=>alert('کپی شد!'))">📋</button>
                            <?php if (!$is_viewer): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="optimize_single">
                                <input type="hidden" name="path" value="<?php echo $media['path']; ?>">
                                <button type="submit" class="action-btn opt">⚡</button>
                            </form>
                            <button class="action-btn replace" onclick="openReplace('<?php echo $media['path']; ?>')">🔄</button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('انتقال به زباله‌دان؟');">
                                <input type="hidden" name="action" value="move_to_trash">
                                <input type="hidden" name="paths[]" value="<?php echo $media['path']; ?>">
                                <button type="submit" class="action-btn trash">🗑️</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <!-- زباله‌دان -->
                <div style="background:#fff8e1;padding:12px;border-radius:10px;margin-bottom:15px;font-size:11px;color:#e65100;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                    <span>⏰ حذف خودکار بعد از ۳۰ روز</span>
                    <?php if (!$is_viewer): ?>
                    <form method="POST" onsubmit="return confirm('همه برای همیشه حذف شوند؟');" style="display:inline;">
                        <input type="hidden" name="action" value="empty_trash">
                        <button type="submit" class="bulk-btn trash">🗑️ خالی کردن زباله‌دان</button>
                    </form>
                    <?php endif; ?>
                </div>
                
                <!-- نوار انتخاب گروهی زباله‌دان -->
                <div class="bulk-bar" id="trashBulkBar">
                    <span id="trashSelectedCount" style="font-size:12px;">۰ انتخاب</span>
                    
                    <form method="POST" id="restoreForm" style="display:inline;">
                        <input type="hidden" name="action" value="restore_selected">
                        <div id="restorePaths"></div>
                        <button type="submit" class="bulk-btn restore">↩️ بازگردانی</button>
                    </form>
                    
                    <form method="POST" id="trashDeleteForm" onsubmit="return confirm('برای همیشه حذف شود؟');" style="display:inline;">
                        <input type="hidden" name="action" value="permanent_delete_selected">
                        <div id="trashDeletePaths"></div>
                        <button type="submit" class="bulk-btn trash" style="background:#b71c1c;">❌ حذف کامل</button>
                    </form>
                    
                    <button class="bulk-btn cancel" onclick="clearTrashSelection()">لغو</button>
                </div>
                
                <div class="media-grid">
                    <?php foreach ($trash_files as $item): ?>
                    <div class="media-card">
                        <?php if (!$is_viewer): ?>
                        <input type="checkbox" class="media-checkbox trash-checkbox" onchange="toggleTrashSelect(this, '<?php echo $item['path']; ?>')">
                        <?php endif; ?>
                        <div class="media-image"><img src="../<?php echo $item['path']; ?>"><span class="size-badge"><?php echo $item['size']; ?> KB</span></div>
                        <div class="media-info"><div class="media-name"><?php echo $item['name']; ?></div><div class="media-meta"><span style="color:#ff4757;"><?php echo $item['days_left']; ?> روز مانده</span></div></div>
                        <div class="media-actions">
                            <form method="POST"><input type="hidden" name="action" value="restore_image"><input type="hidden" name="path" value="<?php echo $item['path']; ?>"><button type="submit" class="action-btn view" title="بازیابی">↩️</button></form>
                            <form method="POST" onsubmit="return confirm('حذف کامل؟');"><input type="hidden" name="action" value="permanent_delete"><input type="hidden" name="path" value="<?php echo $item['path']; ?>"><button type="submit" class="action-btn trash" title="حذف کامل">❌</button></form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
    
    <div class="replace-modal" id="replaceModal">
        <div class="replace-box">
            <h3>🔄 جایگزینی عکس</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="replace_image">
                <input type="hidden" name="old_path" id="replacePath">
                <input type="file" name="replacement" accept="image/*" required style="margin:15px 0;">
                <br>
                <button type="submit" class="bulk-btn opt" style="padding:10px 25px;">جایگزین کن</button>
                <button type="button" class="bulk-btn cancel" onclick="document.getElementById('replaceModal').classList.remove('active')">انصراف</button>
            </form>
        </div>
    </div>
    
    <script>
    let selectedPaths = [];
    let selectedTrashPaths = [];
    
    function toggleSelect(checkbox, path) {
        const card = checkbox.closest('.media-card');
        if (checkbox.checked) { card.classList.add('selected'); selectedPaths.push(path); }
        else { card.classList.remove('selected'); selectedPaths = selectedPaths.filter(p => p !== path); }
        updateBulkBar();
    }
    
    function updateBulkBar() {
        const bar = document.getElementById('bulkBar');
        const count = document.getElementById('selectedCount');
        
        if (selectedPaths.length > 0) {
            bar.classList.add('show');
            count.textContent = selectedPaths.length + ' عکس';
            
            ['trashPaths', 'deletePaths', 'optPaths'].forEach(id => {
                const container = document.getElementById(id);
                container.innerHTML = '';
                selectedPaths.forEach(path => {
                    const input = document.createElement('input');
                    input.type = 'hidden'; input.name = 'paths[]'; input.value = path;
                    container.appendChild(input);
                });
            });
        } else {
            bar.classList.remove('show');
        }
    }
    
    function clearSelection() {
        selectedPaths = [];
        document.querySelectorAll('.media-card').forEach(c => c.classList.remove('selected'));
        document.querySelectorAll('.media-checkbox:not(.trash-checkbox)').forEach(cb => cb.checked = false);
        updateBulkBar();
    }
    
    function toggleTrashSelect(checkbox, path) {
        const card = checkbox.closest('.media-card');
        if (checkbox.checked) { card.classList.add('selected'); selectedTrashPaths.push(path); }
        else { card.classList.remove('selected'); selectedTrashPaths = selectedTrashPaths.filter(p => p !== path); }
        updateTrashBulkBar();
    }
    
    function updateTrashBulkBar() {
        const bar = document.getElementById('trashBulkBar');
        const count = document.getElementById('trashSelectedCount');
        
        if (selectedTrashPaths.length > 0) {
            bar.classList.add('show');
            count.textContent = selectedTrashPaths.length + ' عکس';
            
            ['restorePaths', 'trashDeletePaths'].forEach(id => {
                const container = document.getElementById(id);
                container.innerHTML = '';
                selectedTrashPaths.forEach(path => {
                    const input = document.createElement('input');
                    input.type = 'hidden'; input.name = 'paths[]'; input.value = path;
                    container.appendChild(input);
                });
            });
        } else {
            bar.classList.remove('show');
        }
    }
    
    function clearTrashSelection() {
        selectedTrashPaths = [];
        document.querySelectorAll('.media-card').forEach(c => c.classList.remove('selected'));
        document.querySelectorAll('.trash-checkbox').forEach(cb => cb.checked = false);
        updateTrashBulkBar();
    }
    
    function openReplace(path) {
        document.getElementById('replacePath').value = path;
        document.getElementById('replaceModal').classList.add('active');
    }
    
    function uploadMedia(input) {
        if (input.files && input.files[0]) {
            const hiddenInput = document.getElementById('hiddenFileInput');
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(input.files[0]);
            hiddenInput.files = dataTransfer.files;
            document.getElementById('uploadForm').submit();
        }
    }
    </script>
</body>
</html>