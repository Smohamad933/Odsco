<?php
/**
 * ============================================================================
 *  Odsco — مدیریت دسته‌بندی‌ها (MySQL)
 * ============================================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/logger.php';

check_login();

$message = '';
$error = '';
$editing_category = null;
$show_form = isset($_GET['add']) || isset($_GET['edit']);
$is_viewer = is_viewer();

if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if (isset($_GET['edit'])) {
    $uid = (string)$_GET['edit'];
    $all = Categories::list();
    foreach ($all as $cat) {
        if (($cat['id'] ?? $cat['uid']) === $uid) {
            $editing_category = $cat;
            break;
        }
    }
    // fallback direct DB
    if (!$editing_category && Db::ready()) {
        $row = Db::i()->one('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('categories')) . ' WHERE uid = ?', [$uid]);
        if ($row) {
            $editing_category = [
                'id' => $row['uid'], 'uid' => $row['uid'],
                'name' => $row['name'], 'slug' => $row['slug'],
                'description' => $row['description'] ?? '',
                'icon' => $row['icon'] ?? '🏷️',
                'color' => $row['color'] ?? '#1a1a1a',
            ];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_guard();
    if ($is_viewer) {
        $error = '⛔ شما فقط بیننده هستید و دسترسی به تغییرات ندارید!';
    } else {
        $act = (string)$_POST['action'];
        if ($act === 'add_category') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                $error = 'نام دسته‌بندی الزامی است';
            } else {
                $exists = false;
                foreach (Categories::list() as $cat) {
                    if (mb_strtolower($cat['name']) === mb_strtolower($name)) { $exists = true; break; }
                }
                if ($exists) {
                    $error = '❌ این دسته‌بندی وجود دارد';
                } else {
                    Categories::create([
                        'name' => $name,
                        'description' => (string)($_POST['description'] ?? ''),
                        'icon' => (string)($_POST['icon'] ?? '🏷️'),
                        'color' => (string)($_POST['color'] ?? '#1a1a1a'),
                    ]);
                    add_log('add_category', "دسته‌بندی {$name} اضافه شد");
                    $message = '✅ دسته‌بندی اضافه شد';
                    $show_form = false;
                }
            }
        } elseif ($act === 'edit_category') {
            $category_id = (string)($_POST['category_id'] ?? '');
            if ($category_id === '') {
                $error = 'شناسه نامعتبر';
            } else {
                Categories::update($category_id, [
                    'name' => (string)($_POST['name'] ?? ''),
                    'description' => (string)($_POST['description'] ?? ''),
                    'icon' => (string)($_POST['icon'] ?? '🏷️'),
                    'color' => (string)($_POST['color'] ?? '#1a1a1a'),
                ]);
                add_log('edit_category', "دسته‌بندی {$category_id} ویرایش شد");
                $message = '✅ دسته‌بندی ویرایش شد';
                $show_form = false;
            }
        } elseif ($act === 'delete_category') {
            $category_id = (string)($_POST['category_id'] ?? '');
            if ($category_id !== '') {
                Categories::delete($category_id);
                add_log('delete_category', "دسته‌بندی {$category_id} حذف شد");
                $message = '✅ دسته‌بندی حذف شد';
            }
        }
    }
}

$categories = Categories::list();
if (empty($categories)) {
    // پیش‌فرض‌ها را بساز
    $defaults = [
        ['name' => 'معماری و سازه', 'slug' => 'architecture', 'icon' => '🏗️', 'color' => '#3742fa'],
        ['name' => 'تأسیسات برقی و مکانیکی', 'slug' => 'mechanic', 'icon' => '⚡', 'color' => '#ffa502'],
        ['name' => 'صنعتی و هیدرولیکی', 'slug' => 'industrial', 'icon' => '🏭', 'color' => '#2ed573'],
        ['name' => 'عمرانی و ژئوتکنیک', 'slug' => 'civil', 'icon' => '📐', 'color' => '#ff4757'],
    ];
    foreach ($defaults as $d) {
        Categories::create($d);
    }
    $categories = Categories::list();
}

$icons_list = ['🏗️', '⚡', '🏭', '📐', '🔧', '🚗', '🏠', '🏢', '🌉', '🛣️', '💡', '🔥', '💧', '🌊', '📋', '🎨'];
$colors_list = ['#3742fa', '#ff4757', '#ffa502', '#2ed573', '#a55eea', '#1e90ff', '#ff6348', '#26de81', '#fd9644', '#778ca3'];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت دسته‌بندی‌ها | پنل مدیریت</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .categories-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .stat-pill { padding: 8px 18px; border-radius: 25px; font-size: 13px; font-weight: 700; background: #eee; color: #666; }
        .categories-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .category-card { background: #fff; border-radius: 16px; padding: 25px; border: 1px solid #e9ecef; transition: all 0.3s; position: relative; overflow: hidden; }
        .category-card::before { content: ''; position: absolute; top: 0; right: 0; width: 100%; height: 5px; background: var(--cat-color, #1a1a1a); }
        .category-card:hover { box-shadow: 0 10px 30px rgba(0,0,0,0.08); transform: translateY(-4px); }
        .category-icon { width: 60px; height: 60px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 28px; margin-bottom: 15px; background: #f5f5f5; }
        .category-name { font-size: 16px; font-weight: 800; margin-bottom: 5px; }
        .category-slug { font-size: 11px; color: #999; margin-bottom: 10px; direction: ltr; text-align: right; }
        .category-actions { display: flex; gap: 6px; margin-top: 15px; padding-top: 15px; border-top: 1px solid #f0f0f0; }
        .btn-icon { width: 35px; height: 35px; border-radius: 10px; border: 1px solid #ddd; background: #fff; cursor: pointer; font-size: 15px; display: flex; align-items: center; justify-content: center; transition: all 0.3s; text-decoration: none; }
        .btn-icon:hover { background: #f5f5f5; }
        .viewer-banner { background: #fff8e1; border: 1px solid #ffa502; color: #e65100; padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; font-size: 13px; font-weight: 700; }
        .form-modern { background: #fff; border-radius: 20px; box-shadow: 0 5px 30px rgba(0,0,0,0.05); overflow: hidden; }
        .form-modern__header { padding: 25px 30px; background: linear-gradient(135deg, #1a1a1a, #333); color: #fff; display: flex; justify-content: space-between; align-items: center; }
        .form-modern__header h2 { font-size: 20px; font-weight: 900; margin: 0; }
        .btn-back { background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 10px; text-decoration: none; font-size: 12px; }
        .form-modern__body { padding: 30px; }
        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-size: 12px; font-weight: 700; color: #555; }
        .form-group input, .form-group textarea { padding: 10px 14px; border: 1px solid #e0e0e0; border-radius: 10px; font-family: inherit; font-size: 13px; background: #fafafa; }
        .form-group input:disabled, .form-group textarea:disabled { background: #f5f5f5; color: #999; }
        .icons-grid { display: flex; flex-wrap: wrap; gap: 8px; }
        .icon-option { width: 45px; height: 45px; border-radius: 12px; border: 2px solid #e0e0e0; background: #fff; cursor: pointer; font-size: 20px; display: flex; align-items: center; justify-content: center; transition: all 0.3s; }
        .icon-option.selected { border-color: #2ed573; background: #e8f5e9; }
        .icon-option.disabled { cursor: not-allowed; opacity: 0.5; }
        .colors-grid { display: flex; flex-wrap: wrap; gap: 8px; }
        .color-option { width: 40px; height: 40px; border-radius: 50%; border: 3px solid transparent; cursor: pointer; transition: all 0.3s; }
        .color-option.selected { border-color: #1a1a1a; }
        .color-option.disabled { cursor: not-allowed; opacity: 0.5; }
        .form-modern__footer { padding: 20px 30px; border-top: 1px solid #f0f0f0; display: flex; gap: 10px; }
        .btn { padding: 11px 25px; border: none; border-radius: 10px; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.3s; text-decoration: none; }
        .btn-primary { background: #1a1a1a; color: #fff; }
        .btn-secondary { background: #f5f5f5; color: #666; }
        @media (max-width: 768px) {
            .form-grid-2 { grid-template-columns: 1fr; }
            .form-group.full { grid-column: auto; }
            .categories-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="top-bar">
                <h1>🏷️ مدیریت دسته‌بندی‌ها</h1>
                <?php if ($is_viewer): ?><span class="badge" style="background:#fff8e1;color:#e65100;">👁️ حالت مشاهده</span><?php endif; ?>
            </header>
            
            <?php if ($message): ?><div class="alert alert-success"><?php echo e($message); ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($is_viewer): ?><div class="viewer-banner">⛔ شما فقط بیننده هستید!</div><?php endif; ?>
            
            <?php if ($show_form): ?>
            <div class="form-modern">
                <div class="form-modern__header">
                    <h2><?php echo $editing_category ? '✏️ ویرایش دسته‌بندی' : '➕ افزودن دسته‌بندی'; ?></h2>
                    <a href="manage-categories.php" class="btn-back">← بازگشت</a>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="<?php echo $editing_category ? 'edit_category' : 'add_category'; ?>">
                    <?php if ($editing_category): ?><input type="hidden" name="category_id" value="<?php echo e($editing_category['id'] ?? $editing_category['uid']); ?>"><?php endif; ?>
                    
                    <div class="form-modern__body">
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label>نام *</label>
                                <input type="text" name="name" value="<?php echo e($editing_category['name'] ?? ''); ?>" <?php echo $is_viewer ? 'disabled' : 'required'; ?>>
                            </div>
                            <div class="form-group">
                                <label>توضیحات</label>
                                <input type="text" name="description" value="<?php echo e($editing_category['description'] ?? ''); ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group full">
                                <label>آیکون:</label>
                                <div class="icons-grid">
                                    <?php foreach ($icons_list as $icon): 
                                        $selected = ($editing_category && ($editing_category['icon'] ?? '') === $icon) ? 'selected' : '';
                                    ?>
                                    <button type="button" class="icon-option <?php echo $selected; ?> <?php echo $is_viewer ? 'disabled' : ''; ?>" onclick="<?php echo $is_viewer ? '' : "selectIcon(this, '$icon')"; ?>"><?php echo $icon; ?></button>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="icon" id="iconInput" value="<?php echo e($editing_category['icon'] ?? '🏷️'); ?>">
                            </div>
                            <div class="form-group full">
                                <label>رنگ:</label>
                                <div class="colors-grid">
                                    <?php foreach ($colors_list as $color): 
                                        $selected = ($editing_category && ($editing_category['color'] ?? '') === $color) ? 'selected' : '';
                                    ?>
                                    <button type="button" class="color-option <?php echo $selected; ?> <?php echo $is_viewer ? 'disabled' : ''; ?>" style="background:<?php echo $color; ?>" onclick="<?php echo $is_viewer ? '' : "selectColor(this, '$color')"; ?>"></button>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="color" id="colorInput" value="<?php echo e($editing_category['color'] ?? '#1a1a1a'); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!$is_viewer): ?>
                    <div class="form-modern__footer">
                        <button type="submit" class="btn btn-primary"><?php echo $editing_category ? '💾 ذخیره' : '➕ افزودن'; ?></button>
                        <a href="manage-categories.php" class="btn btn-secondary">انصراف</a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            <?php else: ?>
            
            <div class="categories-header">
                <span class="stat-pill">📊 <?php echo fa_number(count($categories)); ?> دسته‌بندی (MySQL)</span>
                <?php if (!$is_viewer): ?><a href="manage-categories.php?add=1" class="btn btn-primary">➕ افزودن</a><?php endif; ?>
            </div>
            
            <div class="categories-grid">
                <?php foreach ($categories as $cat): ?>
                <div class="category-card" style="--cat-color: <?php echo e($cat['color'] ?? '#1a1a1a'); ?>;">
                    <div class="category-icon"><?php echo e($cat['icon'] ?? '🏷️'); ?></div>
                    <h3 class="category-name"><?php echo e($cat['name']); ?></h3>
                    <div class="category-slug">/<?php echo e($cat['slug'] ?? ''); ?></div>
                    <div class="category-actions">
                        <a href="manage-categories.php?edit=<?php echo e($cat['id'] ?? $cat['uid']); ?>" class="btn-icon" title="ویرایش"><?php echo $is_viewer ? '👁️' : '✏️'; ?></a>
                        <?php if (!$is_viewer): ?>
                        <form method="POST" onsubmit="return confirm('حذف شود؟');" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="category_id" value="<?php echo e($cat['id'] ?? $cat['uid']); ?>">
                            <button type="submit" class="btn-icon">🗑️</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>
    
    <script>
    function selectIcon(btn, icon) {
        document.querySelectorAll('.icon-option').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        document.getElementById('iconInput').value = icon;
    }
    function selectColor(btn, color) {
        document.querySelectorAll('.color-option').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        document.getElementById('colorInput').value = color;
    }
    </script>
</body>
</html>
