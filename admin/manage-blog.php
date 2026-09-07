<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/logger.php';

check_login();

$message = '';
$error = '';
$editing_post = null;
$show_form = isset($_GET['add']) || isset($_GET['edit']);
$is_viewer = ($_SESSION['admin_role'] ?? '') === 'viewer';

if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if (isset($_GET['edit'])) {
    $posts = read_json('blog_posts.json');
    foreach ($posts as $post) {
        if ($post['id'] === $_GET['edit']) {
            $editing_post = $post;
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($is_viewer) {
        $error = '⛔ شما فقط بیننده هستید و دسترسی به تغییرات ندارید!';
    } else {
        if ($_POST['action'] === 'add_post' || $_POST['action'] === 'edit_post') {
            $posts = read_json('blog_posts.json');
            
            // ============ آپلود عکس ============
            $image = '';
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/blog/';
                if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
                $file_name = time() . '_' . basename($_FILES['image']['name']);
                if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $file_name)) {
                    $image = 'uploads/blog/' . $file_name;
                }
            }
            
            if ($_POST['action'] === 'add_post') {
                if (empty($image)) $image = 'assets/default-post.jpg';
                
                $new_post = [
                    'id' => 'post_' . uniqid(),
                    'title' => sanitize($_POST['title']),
                    'slug' => create_slug($_POST['title']),
                    'excerpt' => sanitize($_POST['excerpt']),
                    'content' => $_POST['content'],
                    'image' => $image,
                    'author' => $_SESSION['admin_name'],
                    'date' => date('Y-m-d H:i:s'),
                    'category' => sanitize($_POST['category']),
                    'tags' => sanitize($_POST['tags'] ?? ''),
                    'status' => sanitize($_POST['status'] ?? 'published'),
                    'views' => 0
                ];
                
                $posts[] = $new_post;
                write_json('blog_posts.json', $posts);
                add_log('add_post', "مقاله {$new_post['title']} اضافه شد");
                $message = '✅ مقاله منتشر شد';
                $show_form = false;
            } else {
                $post_id = $_POST['post_id'];
                foreach ($posts as &$post) {
                    if ($post['id'] === $post_id) {
                        if (!empty($image)) $post['image'] = $image;
                        $post['title'] = sanitize($_POST['title']);
                        $post['excerpt'] = sanitize($_POST['excerpt']);
                        $post['content'] = $_POST['content'];
                        $post['category'] = sanitize($_POST['category']);
                        $post['tags'] = sanitize($_POST['tags'] ?? '');
                        $post['status'] = sanitize($_POST['status'] ?? 'published');
                        break;
                    }
                }
                write_json('blog_posts.json', $posts);
                add_log('edit_post', "مقاله ویرایش شد");
                $message = '✅ مقاله ویرایش شد';
                $show_form = false;
                $editing_post = null;
            }
        }
        
        if ($_POST['action'] === 'delete_post') {
            $post_id = $_POST['post_id'];
            $posts = read_json('blog_posts.json');
            foreach ($posts as $key => $post) {
                if ($post['id'] === $post_id) {
                    unset($posts[$key]);
                    write_json('blog_posts.json', array_values($posts));
                    $message = '✅ مقاله حذف شد';
                    break;
                }
            }
        }
    }
}

$posts = get_blog_posts();
$blog_categories = ['عمومی', 'معماری', 'عمران', 'تأسیسات', 'اخبار', 'مهندسی', 'طراحی'];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت مقالات | پنل مدیریت</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .blog-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .stat-pill { padding: 8px 18px; border-radius: 25px; font-size: 13px; font-weight: 700; background: #eee; color: #666; }
        .blog-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
        .post-card { background: #fff; border-radius: 16px; overflow: hidden; border: 1px solid #e9ecef; transition: all 0.3s; }
        .post-card:hover { box-shadow: 0 10px 30px rgba(0,0,0,0.08); transform: translateY(-4px); }
        .post-image { position: relative; height: 180px; overflow: hidden; background: #f5f5f5; }
        .post-image img { width: 100%; height: 100%; object-fit: cover; }
        .post-status { position: absolute; top: 12px; right: 12px; padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .post-status.published { background: rgba(46,213,115,0.9); color: #fff; }
        .post-status.draft { background: rgba(255,165,2,0.9); color: #fff; }
        .post-body { padding: 18px 20px; }
        .post-title { font-size: 15px; font-weight: 800; margin-bottom: 8px; }
        .post-excerpt { font-size: 12px; color: #666; line-height: 1.6; margin-bottom: 10px; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
        .post-category { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; background: #f5f5f5; color: #666; }
        .post-meta { display: flex; gap: 12px; font-size: 11px; color: #999; flex-wrap: wrap; margin-top: 8px; }
        .post-footer { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-top: 1px solid #f0f0f0; flex-wrap: wrap; gap: 10px; }
        .post-actions { display: flex; gap: 6px; }
        .btn-icon { width: 35px; height: 35px; border-radius: 10px; border: 1px solid #ddd; background: #fff; cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; transition: all 0.3s; text-decoration: none; }
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
        .form-group input, .form-group select, .form-group textarea { padding: 10px 14px; border: 1px solid #e0e0e0; border-radius: 10px; font-family: inherit; font-size: 13px; background: #fafafa; }
        .form-group input:disabled, .form-group select:disabled, .form-group textarea:disabled { background: #f5f5f5; color: #999; }
        .upload-area { border: 2px dashed #ddd; border-radius: 15px; padding: 25px; text-align: center; cursor: pointer; transition: all 0.3s; background: #fafafa; }
        .upload-area:hover { border-color: #1a1a1a; }
        .upload-area .icon { font-size: 35px; margin-bottom: 8px; }
        .image-preview { width: 200px; height: 130px; object-fit: cover; border-radius: 10px; margin: 15px auto 0; border: 2px solid #e0e0e0; display: none; }
        .mini-toolbar { display: flex; flex-wrap: wrap; gap: 3px; padding: 8px; background: #f8f9fa; border: 1px solid #e0e0e0; border-bottom: none; border-radius: 10px 10px 0 0; }
        .mini-toolbar button { padding: 5px 10px; border: 1px solid #ddd; background: #fff; border-radius: 5px; cursor: pointer; font-size: 11px; font-family: inherit; }
        .mini-toolbar button:disabled { opacity: 0.3; cursor: not-allowed; }
        .mini-editor { min-height: 250px; padding: 15px; border: 1px solid #e0e0e0; border-radius: 0 0 10px 10px; background: #fff; outline: none; direction: rtl; text-align: right; font-family: inherit; font-size: 13px; line-height: 1.8; }
        .mini-editor[contenteditable="false"] { background: #f5f5f5; color: #999; }
        .form-modern__footer { padding: 20px 30px; border-top: 1px solid #f0f0f0; display: flex; gap: 10px; }
        .btn { padding: 11px 25px; border: none; border-radius: 10px; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer; transition: all 0.3s; text-decoration: none; }
        .btn-primary { background: #1a1a1a; color: #fff; }
        .btn-secondary { background: #f5f5f5; color: #666; }
        @media (max-width: 768px) {
            .form-grid-2 { grid-template-columns: 1fr; }
            .form-group.full { grid-column: auto; }
            .blog-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="top-bar">
                <h1>📝 مدیریت مقالات</h1>
                <?php if ($is_viewer): ?><span class="badge" style="background:#fff8e1;color:#e65100;">👁️ حالت مشاهده</span><?php endif; ?>
            </header>
            
            <?php if ($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
            <?php if ($is_viewer): ?><div class="viewer-banner">⛔ شما فقط بیننده هستید!</div><?php endif; ?>
            
            <?php if ($show_form): ?>
            <div class="form-modern">
                <div class="form-modern__header">
                    <h2><?php echo $editing_post ? ($is_viewer ? '👁️ مشاهده مقاله' : '✏️ ویرایش مقاله') : '➕ افزودن مقاله'; ?></h2>
                    <a href="manage-blog.php" class="btn-back">← بازگشت</a>
                </div>
                
                <form method="POST" enctype="multipart/form-data" id="blogForm">
                    <input type="hidden" name="action" value="<?php echo $editing_post ? 'edit_post' : 'add_post'; ?>">
                    <?php if ($editing_post): ?><input type="hidden" name="post_id" value="<?php echo $editing_post['id']; ?>"><?php endif; ?>
                    
                    <div class="form-modern__body">
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label>عنوان *</label>
                                <input type="text" name="title" value="<?php echo $editing_post['title'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : 'required'; ?>>
                            </div>
                            <div class="form-group">
                                <label>دسته‌بندی</label>
                                <select name="category" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                                    <?php foreach ($blog_categories as $cat): ?>
                                        <option value="<?php echo $cat; ?>" <?php echo ($editing_post && ($editing_post['category'] ?? '') === $cat) ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>وضعیت</label>
                                <select name="status" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                                    <option value="published" <?php echo ($editing_post && ($editing_post['status'] ?? '') === 'published') ? 'selected' : ''; ?>>منتشر شده</option>
                                    <option value="draft" <?php echo ($editing_post && ($editing_post['status'] ?? '') === 'draft') ? 'selected' : ''; ?>>پیش‌نویس</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>برچسب‌ها</label>
                                <input type="text" name="tags" value="<?php echo $editing_post['tags'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group full">
                                <label>خلاصه *</label>
                                <textarea name="excerpt" rows="3" <?php echo $is_viewer ? 'disabled' : 'required'; ?>><?php echo $editing_post['excerpt'] ?? ''; ?></textarea>
                            </div>
                            
                            <?php if (!$is_viewer): ?>
                            <div class="form-group full">
                                <label>عکس مقاله:</label>
                                <div class="upload-area" onclick="document.getElementById('imageInput').click()">
                                    <div class="icon">🖼️</div>
                                    <p>کلیک کنید و عکس را انتخاب کنید</p>
                                    <?php if ($editing_post && !empty($editing_post['image'])): ?>
                                        <img src="../<?php echo $editing_post['image']; ?>" class="image-preview" id="imagePreview" style="display:block;">
                                    <?php else: ?>
                                        <img src="" class="image-preview" id="imagePreview">
                                    <?php endif; ?>
                                </div>
                                <input type="file" id="imageInput" name="image" accept="image/*" style="display:none;" onchange="previewImage(this)">
                            </div>
                            <?php endif; ?>
                            
                            <div class="form-group full">
                                <label>متن کامل:</label>
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
                                <div class="mini-editor" id="contentMiniEditor" contenteditable="<?php echo $is_viewer ? 'false' : 'true'; ?>"><?php echo $editing_post['content'] ?? ''; ?></div>
                                <textarea name="content" id="contentEditor" style="display:none;"><?php echo $editing_post['content'] ?? ''; ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!$is_viewer): ?>
                    <div class="form-modern__footer">
                        <button type="submit" class="btn btn-primary"><?php echo $editing_post ? '💾 ذخیره' : '📤 انتشار'; ?></button>
                        <a href="manage-blog.php" class="btn btn-secondary">انصراف</a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            <?php else: ?>
            
            <div class="blog-header">
                <span class="stat-pill">📝 <?php echo count($posts); ?> مقاله</span>
                <?php if (!$is_viewer): ?><a href="manage-blog.php?add=1" class="btn btn-primary">➕ افزودن مقاله</a><?php endif; ?>
            </div>
            
            <div class="blog-grid">
                <?php foreach ($posts as $post): ?>
                <div class="post-card">
                    <div class="post-image">
                        <img src="../<?php echo $post['image'] ?? 'assets/default-post.jpg'; ?>" onerror="this.src='../assets/default-post.jpg'">
                        <span class="post-status <?php echo ($post['status'] ?? 'published') === 'published' ? 'published' : 'draft'; ?>"><?php echo ($post['status'] ?? 'published') === 'published' ? '✓ منتشر' : '📝 پیش‌نویس'; ?></span>
                    </div>
                    <div class="post-body">
                        <h3 class="post-title"><?php echo $post['title']; ?></h3>
                        <?php if (!empty($post['excerpt'])): ?><p class="post-excerpt"><?php echo $post['excerpt']; ?></p><?php endif; ?>
                        <span class="post-category">🏷️ <?php echo $post['category'] ?? 'عمومی'; ?></span>
                        <div class="post-meta">
                            <span>👤 <?php echo $post['author'] ?? 'مدیر'; ?></span>
                            <span>📅 <?php echo format_date($post['date']); ?></span>
                            <span>👁️ <?php echo $post['views'] ?? 0; ?></span>
                        </div>
                    </div>
                    <div class="post-footer">
                        <span style="font-size:10px;color:#999;"><?php echo $post['tags'] ?? ''; ?></span>
                        <div class="post-actions">
                            <a href="../blog/post.php?slug=<?php echo $post['slug']; ?>" target="_blank" class="btn-icon">👁️</a>
                            <a href="manage-blog.php?edit=<?php echo $post['id']; ?>" class="btn-icon"><?php echo $is_viewer ? '👁️' : '✏️'; ?></a>
                            <?php if (!$is_viewer): ?>
                            <form method="POST" onsubmit="return confirm('حذف شود؟');" style="display:inline;">
                                <input type="hidden" name="action" value="delete_post">
                                <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                <button type="submit" class="btn-icon">🗑️</button>
                            </form>
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
    function doCmd(cmd, val = null) { if (<?php echo $is_viewer ? 'true' : 'false'; ?>) return; document.execCommand(cmd, false, val); document.getElementById('contentMiniEditor').focus(); syncEditor(); }
    function syncEditor() { document.getElementById('contentEditor').value = document.getElementById('contentMiniEditor').innerHTML; }
    document.getElementById('contentMiniEditor')?.addEventListener('input', syncEditor);
    function previewImage(input) {
        const preview = document.getElementById('imagePreview');
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) { preview.src = e.target.result; preview.style.display = 'block'; };
            reader.readAsDataURL(input.files[0]);
        }
    }
    document.getElementById('blogForm')?.addEventListener('submit', function() { syncEditor(); });
    </script>
</body>
</html>