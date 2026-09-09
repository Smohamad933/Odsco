<?php
/**
 * ============================================================================
 *  Odsco — کتابخانه رسانه
 * ----------------------------------------------------------------------------
 *  منبع داده: جدول media_files (از طریق Media) + اسکن پوشه uploads/
 *  فایل‌های جدید روی دیسک به‌صورت خودکار در دیتابیس ثبت می‌شوند.
 * ============================================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/logger.php';

check_login();

$message = '';
$error = '';
$is_viewer = is_viewer();
$myUid = current_admin_uid();

// ---------------------------------------------------------------------------
// بهینه‌سازی تصویر (GD)
// ---------------------------------------------------------------------------
function gd_available(): bool
{
    return function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng');
}

function optimize_image(string $source_path, string $target_path, int $max_width = 1200, int $quality = 75): bool
{
    if (!gd_available()) { copy($source_path, $target_path); return true; }

    $ext = strtolower((string)pathinfo($source_path, PATHINFO_EXTENSION));
    $info = @getimagesize($source_path);
    if (!$info) { copy($source_path, $target_path); return true; }

    [$src_width, $src_height] = [$info[0], $info[1]];
    if ($src_width <= $max_width) { copy($source_path, $target_path); return true; }

    $ratio = $max_width / $src_width;
    $new_width = $max_width;
    $new_height = (int)round($src_height * $ratio);

    $src = match ($ext) {
        'jpg', 'jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source_path) : null,
        'png'         => function_exists('imagecreatefrompng') ? @imagecreatefrompng($source_path) : null,
        'gif'         => function_exists('imagecreatefromgif') ? @imagecreatefromgif($source_path) : null,
        'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source_path) : null,
        default       => null,
    };
    if (!$src) { copy($source_path, $target_path); return true; }

    $dst = imagecreatetruecolor($new_width, $new_height);
    if ($ext === 'png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefilledrectangle($dst, 0, 0, $new_width, $new_height, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_width, $new_height, $src_width, $src_height);

    match ($ext) {
        'jpg', 'jpeg' => imagejpeg($dst, $target_path, $quality),
        'png'         => imagepng($dst, $target_path, 8),
        'gif'         => imagegif($dst, $target_path),
        'webp'        => imagewebp($dst, $target_path, $quality),
        default       => null,
    };

    imagedestroy($src);
    imagedestroy($dst);
    return true;
}

// ---------------------------------------------------------------------------
// پاکسازی خودکار زباله‌دان بعد از ۳۰ روز
// ---------------------------------------------------------------------------
if (Db::ready()) {
    $old = Db::i()->all('SELECT path FROM ' . Db::i()->quoteIdent(Db::i()->t('media_files'))
        . ' WHERE is_trashed = 1 AND trashed_at IS NOT NULL AND trashed_at < ?',
        [date('Y-m-d H:i:s', strtotime('-30 days'))]);
    foreach ($old as $row) {
        $abs = dirname(__DIR__) . '/' . ltrim((string)$row['path'], '/');
        if (is_file($abs)) @unlink($abs);
    }
    if ($old) {
        Db::i()->delete(Db::i()->t('media_files'), 'is_trashed = 1 AND trashed_at IS NOT NULL AND trashed_at < ?',
            [date('Y-m-d H:i:s', strtotime('-30 days'))]);
    }
}

// ---------------------------------------------------------------------------
// عملیات‌ها
// ---------------------------------------------------------------------------
/** ثبت فایل‌های روی دیسک که هنوز در دیتابیس نیستند */
function media_sync_disk(array $directories): void
{
    if (!Db::ready()) return;

    $known = array_flip(array_map(
        'strval',
        array_column(Db::i()->all('SELECT path FROM ' . Db::i()->quoteIdent(Db::i()->t('media_files'))), 'path')
    ));

    foreach (array_keys($directories) as $dir) {
        $abs = dirname(__DIR__) . '/' . $dir;
        if (!is_dir($abs)) continue;
        foreach ((array)glob($abs . '*') as $file) {
            if (!is_file($file)) continue;
            $rel = $dir . basename($file);
            if (isset($known[$rel])) continue;
            Media::register($rel, basename($file), rtrim($dir, '/'), '', (int)filesize($file));
        }
    }
}

$directories = [
    'uploads/projects/' => 'پروژه‌ها',
    'uploads/blog/'     => 'مقالات',
    'uploads/team/'     => 'اعضای تیم',
    'uploads/users/'    => 'کاربران',
    'uploads/media/'    => 'گالری',
    'uploads/messenger/' => 'پیام‌رسان',
    'assets/'           => 'دارایی‌ها',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_guard();
    $act = (string)$_POST['action'];

    if ($is_viewer) {
        $error = '⛔ شما فقط بیننده هستید!';
    } else {
        $paths = $_POST['paths'] ?? [];
        if (!is_array($paths)) $paths = [$paths];
        $paths = array_values(array_filter(array_map('strval', $paths)));

        switch ($act) {
            case 'upload':
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower((string)pathinfo((string)$_FILES['image']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
                        $error = '❌ فقط فایل تصویری مجاز است';
                        break;
                    }
                    $dir = 'uploads/media/';
                    odsco_upload_path('media');
                    $name = 'media_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
                    optimize_image((string)$_FILES['image']['tmp_name'], dirname(__DIR__) . '/' . $dir . $name, 1200, 75);
                    Media::register($dir . $name, (string)$_FILES['image']['name'], 'uploads/media', $myUid);
                    add_log('media_upload', 'آپلود عکس: ' . $name);
                    $message = '✅ آپلود شد';
                }
                break;

            case 'move_to_trash':
                foreach ($paths as $path) {
                    if (Db::ready()) {
                        $row = Db::i()->one('SELECT uid FROM ' . Db::i()->quoteIdent(Db::i()->t('media_files')) . ' WHERE path = ?', [$path]);
                        if ($row) Media::moveToTrash((string)$row['uid']);
                    }
                }
                $message = '✅ ' . fa_number(count($paths)) . ' مورد به زباله‌دان رفت';
                break;

            case 'delete_permanent_selected':
            case 'permanent_delete_selected':
                $deleted = 0;
                foreach ($paths as $path) {
                    if (Db::ready()) {
                        $row = Db::i()->one('SELECT uid FROM ' . Db::i()->quoteIdent(Db::i()->t('media_files')) . ' WHERE path = ?', [$path]);
                        if ($row) {
                            $p = Media::purge((string)$row['uid']);
                            if ($p !== null) {
                                $abs = dirname(__DIR__) . '/' . ltrim($p, '/');
                                if (is_file($abs)) { @unlink($abs); $deleted++; }
                            }
                        }
                    } else {
                        $abs = dirname(__DIR__) . '/' . ltrim($path, '/');
                        if (is_file($abs)) { @unlink($abs); $deleted++; }
                    }
                }
                add_log('media_purge', fa_number($deleted) . ' فایل برای همیشه حذف شد');
                $message = '✅ ' . fa_number($deleted) . ' فایل برای همیشه حذف شد';
                break;

            case 'optimize_selected':
                $optimized = 0;
                foreach ($paths as $path) {
                    $abs = dirname(__DIR__) . '/' . ltrim($path, '/');
                    if (!is_file($abs)) continue;
                    $tmp = $abs . '.tmp';
                    if (optimize_image($abs, $tmp, 1200, 70)) {
                        @unlink($abs);
                        rename($tmp, $abs);
                        $optimized++;
                        if (Db::ready()) {
                            Db::i()->update(Db::i()->t('media_files'), ['size_bytes' => (int)filesize($abs)], 'path = ?', [$path]);
                        }
                    }
                }
                $message = '✅ ' . fa_number($optimized) . ' عکس بهینه شد';
                break;

            case 'optimize_single':
                $abs = dirname(__DIR__) . '/' . ltrim((string)($_POST['path'] ?? ''), '/');
                if (is_file($abs)) {
                    $tmp = $abs . '.tmp';
                    if (optimize_image($abs, $tmp, 1200, 70)) {
                        @unlink($abs);
                        rename($tmp, $abs);
                        if (Db::ready()) {
                            Db::i()->update(Db::i()->t('media_files'), ['size_bytes' => (int)filesize($abs)], 'path = ?', [(string)$_POST['path']]);
                        }
                        $message = '✅ بهینه شد';
                    }
                }
                break;

            case 'restore_image':
            case 'restore_selected':
                $targets = $act === 'restore_image' ? [(string)($_POST['path'] ?? '')] : $paths;
                $n = 0;
                foreach ($targets as $path) {
                    if ($path === '' || !Db::ready()) continue;
                    $row = Db::i()->one('SELECT uid FROM ' . Db::i()->quoteIdent(Db::i()->t('media_files')) . ' WHERE path = ?', [$path]);
                    if ($row) { Media::restore((string)$row['uid']); $n++; }
                }
                $message = '✅ ' . fa_number($n) . ' مورد بازیابی شد';
                break;

            case 'permanent_delete':
                $path = (string)($_POST['path'] ?? '');
                if (Db::ready()) {
                    $row = Db::i()->one('SELECT uid FROM ' . Db::i()->quoteIdent(Db::i()->t('media_files')) . ' WHERE path = ?', [$path]);
                    if ($row) Media::purge((string)$row['uid']);
                }
                $abs = dirname(__DIR__) . '/' . ltrim($path, '/');
                if (is_file($abs)) @unlink($abs);
                $message = '✅ حذف شد';
                break;

            case 'empty_trash':
                check_permission('admin');
                foreach (Media::trash() as $t) {
                    $abs = dirname(__DIR__) . '/' . ltrim((string)$t['path'], '/');
                    if (is_file($abs)) @unlink($abs);
                    Media::purge((string)$t['uid']);
                }
                add_log('media_empty_trash', 'زباله‌دان رسانه خالی شد');
                $message = '✅ زباله‌دان خالی شد';
                break;

            case 'replace_image':
                $old_path = (string)($_POST['old_path'] ?? '');
                $abs = dirname(__DIR__) . '/' . ltrim($old_path, '/');
                if (isset($_FILES['replacement']) && $_FILES['replacement']['error'] === UPLOAD_ERR_OK) {
                    if (is_file($abs)) @unlink($abs);
                    optimize_image((string)$_FILES['replacement']['tmp_name'], $abs, 1200, 75);
                    if (Db::ready()) {
                        Db::i()->update(Db::i()->t('media_files'),
                            ['size_bytes' => is_file($abs) ? (int)filesize($abs) : 0], 'path = ?', [$old_path]);
                    }
                    $message = '✅ جایگزین شد';
                }
                break;

            default:
                $error = 'عملیات نامعتبر';
        }
    }
}

media_sync_disk($directories);

// ---------------------------------------------------------------------------
// جمع‌آوری داده‌ها
// ---------------------------------------------------------------------------
function clean_image_path(string $path): string|false
{
    $clean = trim($path);
    $clean = (string)preg_replace('/^(\.\.\/)+/', '', $clean);
    $clean = (string)preg_replace('/^(\.\/)+/', '', $clean);
    $clean = str_replace('\\', '/', $clean);
    $clean = ltrim($clean, '/');
    if ($clean !== '' && strlen($clean) < 200 && !str_contains($clean, '..')) return $clean;
    return false;
}

/** اسکن کد سایت برای یافتن عکس‌های در حال استفاده */
function scan_site_for_images(): array
{
    $used = [];
    $root = dirname(__DIR__);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['php', 'html', 'css', 'js', 'json'], true)) continue;
        // پوشه‌های سنگین را رد کن
        if (preg_match('~/(vendor|node_modules|\.git|uploads)/~', str_replace('\\', '/', $file->getPathname()))) continue;

        $content = @file_get_contents($file->getPathname());
        if (!$content) continue;

        $patterns = [
            '/(?:uploads|assets)\/[a-zA-Z0-9\/_\-.]+\.(?:jpg|jpeg|png|gif|webp|svg)/i',
            '/src=["\']([^"\']*\.(?:jpg|jpeg|png|gif|webp|svg))["\']/i',
            "/['\"]([^'\"]*\.(?:jpg|jpeg|png|gif|webp|svg))['\"]/i",
        ];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            foreach ((array)($matches[1] ?? []) as $match) {
                $c = clean_image_path((string)$match);
                if ($c) $used[] = $c;
            }
        }
    }
    return array_values(array_unique($used));
}

$used_in_code = scan_site_for_images();
$trash_data = Media::trash();
$trash_paths = array_flip(array_map(fn($t) => (string)$t['path'], $trash_data));

$media_files = [];
$total_size_bytes = 0;

$projects = Projects::list();
$posts = Blog::list();
$team = Team::list();
$users = Users::list();

foreach ($directories as $dir => $label) {
    $abs = dirname(__DIR__) . '/' . $dir;
    if (!is_dir($abs)) continue;

    foreach ((array)glob($abs . '*') as $file) {
        if (!is_file($file)) continue;
        $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) continue;

        $rel = $dir . basename($file);
        if (isset($trash_paths[$rel])) continue;

        $size_bytes = (int)filesize($file);
        $total_size_bytes += $size_bytes;
        $dims = @getimagesize($file);

        $usage = [];
        foreach ($used_in_code as $u) {
            if ($u === $rel || str_contains($rel, $u) || str_contains($u, $rel) || basename($u) === basename($rel)) {
                $usage[] = 'کد سایت';
                break;
            }
        }
        foreach ($projects as $p) {
            $imgs = (array)($p['images'] ?? []);
            if (in_array($rel, $imgs, true) || in_array(basename($rel), array_map('basename', $imgs), true)) {
                $usage[] = 'پروژه: ' . $p['title'];
            }
            if (($p['cover_image'] ?? '') === $rel || basename((string)($p['cover_image'] ?? '')) === basename($rel)) {
                $usage[] = 'کاور: ' . $p['title'];
            }
        }
        foreach ($posts as $post) {
            if (($post['image'] ?? '') === $rel || basename((string)($post['image'] ?? '')) === basename($rel)) {
                $usage[] = 'مقاله: ' . $post['title'];
            }
        }
        foreach ($team as $m) {
            if (($m['photo'] ?? '') === $rel || basename((string)($m['photo'] ?? '')) === basename($rel)) {
                $usage[] = 'تیم: ' . $m['name'];
            }
        }
        foreach ($users as $u) {
            if (($u['photo'] ?? '') === $rel || basename((string)($u['photo'] ?? '')) === basename($rel)) {
                $usage[] = 'کاربر: ' . $u['full_name'];
            }
        }

        $media_files[] = [
            'path'      => $rel,
            'name'      => basename($file),
            'size'      => round($size_bytes / 1024, 1),
            'directory' => $label,
            'width'     => $dims[0] ?? 0,
            'height'    => $dims[1] ?? 0,
            'is_used'   => $usage !== [],
            'usage'     => array_values(array_unique($usage)),
        ];
    }
}

$filter = (string)($_GET['filter'] ?? 'all');
$active_tab = (string)($_GET['tab'] ?? 'all');

if ($filter === 'unused')    $media_files = array_values(array_filter($media_files, fn($m) => !$m['is_used']));
elseif ($filter === 'used')  $media_files = array_values(array_filter($media_files, fn($m) => $m['is_used']));
elseif ($filter === 'small') $media_files = array_values(array_filter($media_files, fn($m) => $m['size'] <= 200));
elseif ($filter === 'large') $media_files = array_values(array_filter($media_files, fn($m) => $m['size'] > 500));

$total_size_mb = round($total_size_bytes / 1048576, 1);

$trash_files = [];
foreach ($trash_data as $item) {
    $abs = dirname(__DIR__) . '/' . ltrim((string)$item['path'], '/');
    $trash_files[] = [
        'path'      => $item['path'],
        'name'      => basename((string)$item['path']),
        'size'      => is_file($abs) ? round(filesize($abs) / 1024, 1) : round((int)$item['size'] / 1024, 1),
        'days_left' => max(0, 30 - (int)floor((time() - strtotime((string)($item['trashed_at'] ?? 'now'))) / 86400)),
    ];
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
.tab-btn { padding: 9px 16px; border: none; background: transparent; border-radius: 10px; cursor: pointer; font-family: inherit; font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 6px; text-decoration: none; color: #666; }
.tab-btn:hover { background: #f5f5f5; text-decoration: none; }
.tab-btn.active { background: #1a1a1a; color: #fff; }
.tab-count { background: rgba(0,0,0,.1); padding: 1px 8px; border-radius: 10px; font-size: 10px; }
.tab-btn.active .tab-count { background: rgba(255,255,255,.2); }

.stats-row { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
.stat-box { background: #fff; padding: 15px 20px; border-radius: 12px; border: 1px solid #e9ecef; text-align: center; min-width: 100px; flex: 1; }
.stat-box .num { font-size: 20px; font-weight: 900; display: block; }
.stat-box .lbl { font-size: 10px; color: #999; }

.filter-bar { display: flex; gap: 5px; margin-bottom: 15px; flex-wrap: wrap; }
.filter-btn { padding: 7px 14px; border: 1px solid #e0e0e0; border-radius: 20px; font-family: inherit; font-size: 11px; font-weight: 700; text-decoration: none; color: #666; background: #fff; }
.filter-btn.active { background: #1a1a1a; color: #fff; border-color: #1a1a1a; }

.upload-zone { border: 3px dashed #c0c0c0; border-radius: 20px; padding: 26px; text-align: center; cursor: pointer; background: linear-gradient(135deg,#fafafa,#f0f0f0); margin-bottom: 18px; }
.upload-zone:hover { border-color: #1a1a1a; }
.upload-zone .upload-icon { font-size: 36px; display: block; margin-bottom: 8px; }
.upload-zone .upload-title { font-size: 14px; font-weight: 900; }

.bulk-bar { display: none; background: #1a1a1a; color: #fff; padding: 12px 18px; border-radius: 12px; margin-bottom: 15px; align-items: center; gap: 8px; flex-wrap: wrap; position: sticky; top: 10px; z-index: 100; }
.bulk-bar.show { display: flex; }
.bulk-btn { padding: 8px 14px; border: none; border-radius: 8px; font-family: inherit; font-size: 11px; font-weight: 700; cursor: pointer; }
.bulk-btn.trash { background: #ff4757; color: #fff; }
.bulk-btn.opt { background: #ffa502; color: #fff; }
.bulk-btn.restore { background: #2ed573; color: #fff; }
.bulk-btn.cancel { background: #555; color: #fff; }

.media-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(210px,1fr)); gap: 14px; }
.media-card { background: #fff; border-radius: 14px; overflow: hidden; border: 1px solid #e9ecef; position: relative; }
.media-card:hover { box-shadow: 0 8px 25px rgba(0,0,0,.1); }
.media-card.selected { border: 2px solid #3742fa; }
.media-checkbox { position: absolute; top: 10px; left: 10px; z-index: 5; width: 22px; height: 22px; cursor: pointer; accent-color: #3742fa; }
.media-image { width: 100%; aspect-ratio: 1; overflow: hidden; background: #f5f5f5; }
.media-image img { width: 100%; height: 100%; object-fit: cover; }
.size-badge { position: absolute; top: 10px; right: 10px; padding: 3px 9px; border-radius: 15px; font-size: 10px; font-weight: 700; background: rgba(0,0,0,.7); color: #fff; }
.media-info { padding: 11px; }
.media-name { font-size: 10px; font-weight: 700; direction: ltr; text-align: right; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.media-meta { font-size: 9px; color: #999; margin-top: 4px; display: flex; gap: 6px; flex-wrap: wrap; }
.used-badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 9px; font-weight: 700; margin-top: 6px; }
.used-badge.used { background: #e8f5e9; color: #2e7d32; }
.used-badge.unused { background: #fff8e1; color: #e65100; }
.usage-detail { font-size: 8px; color: #999; margin-top: 3px; line-height: 1.7; }
.media-actions { display: flex; gap: 5px; padding: 9px; border-top: 1px solid #f0f0f0; justify-content: center; flex-wrap: wrap; }
.action-btn { width: 33px; height: 33px; border-radius: 10px; border: 1px solid #e0e0e0; cursor: pointer; font-size: 13px; display: flex; align-items: center; justify-content: center; text-decoration: none; background: #fff; font-family: inherit; }
.action-btn.trash { color: #ff4757; }
.action-btn.trash:hover { background: #ff4757; color: #fff; }

.replace-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.8); z-index: 9999; justify-content: center; align-items: center; }
.replace-modal.active { display: flex; }
.replace-box { background: #fff; border-radius: 20px; padding: 28px; max-width: 400px; width: 90%; text-align: center; }

@media (max-width: 768px) { .media-grid { grid-template-columns: repeat(2,1fr); gap: 8px; } .stats-row { gap: 6px; } .stat-box { padding: 10px; min-width: 70px; } }
</style>
</head>
<body>
<div class="admin-layout">
    <?php include 'sidebar.php'; ?>

    <main class="main-content">
        <header class="top-bar"><h1>🖼️ کتابخانه رسانه</h1></header>

        <?php if ($message): ?><div class="alert alert-success"><?php echo e($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>

        <div class="media-tabs">
            <a href="?tab=all" class="tab-btn <?php echo $active_tab === 'all' ? 'active' : ''; ?>">📸 عکس‌ها <span class="tab-count"><?php echo fa_number(count($media_files)); ?></span></a>
            <a href="?tab=trash" class="tab-btn <?php echo $active_tab === 'trash' ? 'active' : ''; ?>">🗑️ زباله‌دان <span class="tab-count"><?php echo fa_number(count($trash_files)); ?></span></a>
        </div>

        <?php if ($active_tab === 'all'): ?>
        <div class="stats-row">
            <div class="stat-box"><span class="num"><?php echo fa_number(count($media_files)); ?></span><span class="lbl">عکس</span></div>
            <div class="stat-box"><span class="num"><?php echo fa_number($total_size_mb); ?> MB</span><span class="lbl">حجم کل</span></div>
            <div class="stat-box"><span class="num"><?php echo fa_number(count(array_filter($media_files, fn($m) => $m['is_used']))); ?></span><span class="lbl">استفاده‌شده</span></div>
            <div class="stat-box"><span class="num" style="color:#e65100"><?php echo fa_number(count(array_filter($media_files, fn($m) => !$m['is_used']))); ?></span><span class="lbl">استفاده‌نشده</span></div>
        </div>

        <div class="filter-bar">
            <a href="?tab=all&filter=all" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">همه</a>
            <a href="?tab=all&filter=used" class="filter-btn <?php echo $filter === 'used' ? 'active' : ''; ?>">✅ استفاده‌شده</a>
            <a href="?tab=all&filter=unused" class="filter-btn <?php echo $filter === 'unused' ? 'active' : ''; ?>">⚠️ استفاده‌نشده</a>
            <a href="?tab=all&filter=small" class="filter-btn <?php echo $filter === 'small' ? 'active' : ''; ?>">🟢 کم‌حجم</a>
            <a href="?tab=all&filter=large" class="filter-btn <?php echo $filter === 'large' ? 'active' : ''; ?>">🔴 پرحجم</a>
        </div>

        <?php if (!$is_viewer): ?>
        <div class="upload-zone" onclick="document.getElementById('mediaInput').click()">
            <span class="upload-icon">📤</span>
            <span class="upload-title">آپلود عکس جدید</span>
            <p style="font-size:11px;color:#999;margin:6px 0 0">عکس‌ها خودکار بهینه و در دیتابیس ثبت می‌شوند</p>
        </div>
        <input type="file" id="mediaInput" accept="image/*" style="display:none" onchange="uploadMedia(this)">
        <form method="post" enctype="multipart/form-data" id="uploadForm" style="display:none">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="upload">
            <input type="file" name="image" id="hiddenFileInput">
        </form>
        <?php endif; ?>

        <div class="bulk-bar" id="bulkBar">
            <span id="selectedCount" style="font-size:12px">۰ انتخاب</span>
            <form method="post" id="trashForm" onsubmit="return confirm('انتقال به زباله‌دان؟')" style="display:inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="move_to_trash">
                <span id="trashPaths"></span>
                <button type="submit" class="bulk-btn trash">🗑️ زباله‌دان</button>
            </form>
            <form method="post" id="deleteForm" onsubmit="return confirm('برای همیشه حذف شود؟')" style="display:inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="delete_permanent_selected">
                <span id="deletePaths"></span>
                <button type="submit" class="bulk-btn trash" style="background:#b71c1c">❌ حذف کامل</button>
            </form>
            <form method="post" id="optForm" onsubmit="return confirm('بهینه‌سازی شود؟')" style="display:inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="optimize_selected">
                <span id="optPaths"></span>
                <button type="submit" class="bulk-btn opt">⚡ بهینه‌سازی</button>
            </form>
            <button class="bulk-btn cancel" onclick="clearSelection()">لغو</button>
        </div>

        <div class="media-grid">
            <?php foreach ($media_files as $media): ?>
            <div class="media-card">
                <?php if (!$is_viewer): ?>
                <input type="checkbox" class="media-checkbox" onchange="toggleSelect(this, '<?php echo e($media['path']); ?>')">
                <?php endif; ?>
                <div class="media-image">
                    <img src="../<?php echo e($media['path']); ?>" loading="lazy" alt="">
                    <span class="size-badge"><?php echo fa_number($media['size']); ?> KB</span>
                </div>
                <div class="media-info">
                    <div class="media-name"><?php echo e($media['name']); ?></div>
                    <div class="media-meta"><span><?php echo fa_number($media['width']); ?>×<?php echo fa_number($media['height']); ?></span><span><?php echo e($media['directory']); ?></span></div>
                    <?php if ($media['is_used']): ?>
                        <span class="used-badge used">✅ استفاده‌شده</span>
                        <div class="usage-detail"><?php echo e(implode('، ', $media['usage'])); ?></div>
                    <?php else: ?>
                        <span class="used-badge unused">⚠️ استفاده‌نشده</span>
                    <?php endif; ?>
                </div>
                <div class="media-actions">
                    <a href="../<?php echo e($media['path']); ?>" target="_blank" class="action-btn" title="مشاهده">👁️</a>
                    <button type="button" class="action-btn" title="کپی آدرس"
                            onclick="copyUrl('<?php echo e($media['path']); ?>')">📋</button>
                    <?php if (!$is_viewer): ?>
                    <form method="post" style="display:inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="optimize_single">
                        <input type="hidden" name="path" value="<?php echo e($media['path']); ?>">
                        <button type="submit" class="action-btn" title="بهینه‌سازی">⚡</button>
                    </form>
                    <button type="button" class="action-btn" title="جایگزینی" onclick="openReplace('<?php echo e($media['path']); ?>')">🔄</button>
                    <form method="post" style="display:inline" onsubmit="return confirm('انتقال به زباله‌دان؟')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="move_to_trash">
                        <input type="hidden" name="paths[]" value="<?php echo e($media['path']); ?>">
                        <button type="submit" class="action-btn trash" title="زباله‌دان">🗑️</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php else: ?>
        <div style="background:#fff8e1;padding:12px;border-radius:10px;margin-bottom:15px;font-size:11px;color:#e65100;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
            <span>⏰ حذف خودکار بعد از ۳۰ روز</span>
            <?php if (!$is_viewer && has_permission('admin')): ?>
            <form method="post" onsubmit="return confirm('همه برای همیشه حذف شوند؟')" style="display:inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="empty_trash">
                <button type="submit" class="bulk-btn trash">🗑️ خالی کردن زباله‌دان</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="bulk-bar" id="trashBulkBar">
            <span id="trashSelectedCount" style="font-size:12px">۰ انتخاب</span>
            <form method="post" id="restoreForm" style="display:inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="restore_selected">
                <span id="restorePaths"></span>
                <button type="submit" class="bulk-btn restore">↩️ بازگردانی</button>
            </form>
            <form method="post" id="trashDeleteForm" onsubmit="return confirm('برای همیشه حذف شود؟')" style="display:inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="permanent_delete_selected">
                <span id="trashDeletePaths"></span>
                <button type="submit" class="bulk-btn trash" style="background:#b71c1c">❌ حذف کامل</button>
            </form>
            <button class="bulk-btn cancel" onclick="clearTrashSelection()">لغو</button>
        </div>

        <div class="media-grid">
            <?php foreach ($trash_files as $item): ?>
            <div class="media-card">
                <?php if (!$is_viewer): ?>
                <input type="checkbox" class="media-checkbox trash-checkbox" onchange="toggleTrashSelect(this, '<?php echo e($item['path']); ?>')">
                <?php endif; ?>
                <div class="media-image">
                    <img src="../<?php echo e($item['path']); ?>" loading="lazy" alt="">
                    <span class="size-badge"><?php echo fa_number($item['size']); ?> KB</span>
                </div>
                <div class="media-info">
                    <div class="media-name"><?php echo e($item['name']); ?></div>
                    <div class="media-meta"><span style="color:#ff4757"><?php echo fa_number($item['days_left']); ?> روز مانده</span></div>
                </div>
                <div class="media-actions">
                    <form method="post" style="display:inline">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="restore_image">
                        <input type="hidden" name="path" value="<?php echo e($item['path']); ?>">
                        <button type="submit" class="action-btn" title="بازیابی">↩️</button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('حذف کامل؟')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="permanent_delete">
                        <input type="hidden" name="path" value="<?php echo e($item['path']); ?>">
                        <button type="submit" class="action-btn trash" title="حذف کامل">❌</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (!$trash_files): ?>
                <p style="color:#7c8aa0">زباله‌دان خالی است.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>
</div>

<div class="replace-modal" id="replaceModal">
    <div class="replace-box">
        <h3>🔄 جایگزینی عکس</h3>
        <form method="post" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="replace_image">
            <input type="hidden" name="old_path" id="replacePath">
            <input type="file" name="replacement" accept="image/*" required style="margin:15px 0">
            <br>
            <button type="submit" class="bulk-btn opt" style="padding:10px 25px">جایگزین کن</button>
            <button type="button" class="bulk-btn cancel" onclick="document.getElementById('replaceModal').classList.remove('active')">انصراف</button>
        </form>
    </div>
</div>

<script>
var selected = [], trashSelected = [];

function fillForms(list, ids) {
    ids.forEach(function (id) {
        var box = document.getElementById(id);
        if (box) box.innerHTML = list.map(function (p) {
            return '<input type="hidden" name="paths[]" value="' + p.replace(/"/g, '&quot;') + '">';
        }).join('');
    });
}

function toggleSelect(cb, path) {
    var i = selected.indexOf(path);
    if (cb.checked && i < 0) selected.push(path);
    if (!cb.checked && i >= 0) selected.splice(i, 1);
    cb.closest('.media-card').classList.toggle('selected', cb.checked);
    document.getElementById('selectedCount').textContent = selected.length + ' انتخاب';
    document.getElementById('bulkBar').classList.toggle('show', selected.length > 0);
    fillForms(selected, ['trashPaths', 'deletePaths', 'optPaths']);
}

function toggleTrashSelect(cb, path) {
    var i = trashSelected.indexOf(path);
    if (cb.checked && i < 0) trashSelected.push(path);
    if (!cb.checked && i >= 0) trashSelected.splice(i, 1);
    cb.closest('.media-card').classList.toggle('selected', cb.checked);
    document.getElementById('trashSelectedCount').textContent = trashSelected.length + ' انتخاب';
    document.getElementById('trashBulkBar').classList.toggle('show', trashSelected.length > 0);
    fillForms(trashSelected, ['restorePaths', 'trashDeletePaths']);
}

function clearSelection() {
    selected = [];
    document.querySelectorAll('.media-checkbox').forEach(function (c) { c.checked = false; });
    document.querySelectorAll('.media-card').forEach(function (c) { c.classList.remove('selected'); });
    document.getElementById('bulkBar').classList.remove('show');
}

function clearTrashSelection() {
    trashSelected = [];
    document.querySelectorAll('.trash-checkbox').forEach(function (c) { c.checked = false; });
    document.querySelectorAll('.media-card').forEach(function (c) { c.classList.remove('selected'); });
    document.getElementById('trashBulkBar').classList.remove('show');
}

function uploadMedia(input) {
    if (!input.files || !input.files.length) return;
    var dt = new DataTransfer();
    dt.items.add(input.files[0]);
    var hidden = document.getElementById('hiddenFileInput');
    hidden.files = dt.files;
    document.getElementById('uploadForm').submit();
}

function openReplace(path) {
    document.getElementById('replacePath').value = path;
    document.getElementById('replaceModal').classList.add('active');
}

function copyUrl(path) {
    var url = location.origin + '/' + path;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function () { alert('✅ آدرس کپی شد'); });
    } else {
        prompt('آدرس را کپی کنید:', url);
    }
}
</script>
</body>
</html>
