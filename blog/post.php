<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

$settings = get_settings();
$slug = $_GET['slug'] ?? '';
$post = get_blog_post_by_slug($slug);

if (!$post) {
    header('Location: index.php');
    exit;
}

// ============ سیستم بازدید ============
increment_post_view($slug);
track_daily_view();
// =====================================
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $post['title']; ?> | <?php echo $settings['site_name']; ?></title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .single-post { padding: 60px 20px; max-width: 800px; margin: 0 auto; }
        .single-post h1 { font-size: 28px; margin-bottom: 15px; }
        .single-post .meta { color: #666; font-size: 13px; margin-bottom: 20px; display: flex; gap: 15px; flex-wrap: wrap; }
        .single-post .content { font-size: 15px; line-height: 2; }
        .single-post img { max-width: 100%; border-radius: 12px; margin: 20px 0; }
    </style>
</head>
<body class="page">

<div id="header-placeholder"></div>

<main class="main">
    <section class="about-hero">
        <div class="container">
            <h1 class="about-hero__title"><?php echo $post['title']; ?></h1>
        </div>
    </section>

    <section class="single-post">
        <div class="meta">
            <span>📅 <?php echo format_date($post['date']); ?></span>
            <span>👤 <?php echo $post['author'] ?? 'مدیر'; ?></span>
            <span>👁️ <?php echo $post['views'] ?? 0; ?> بازدید</span>
            <?php if (!empty($post['category'])): ?>
                <span>🏷️ <?php echo $post['category']; ?></span>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($post['image'])): ?>
            <img src="../<?php echo $post['image']; ?>" alt="<?php echo $post['title']; ?>">
        <?php endif; ?>
        
        <div class="content">
            <?php echo $post['content']; ?>
        </div>
    </section>
</main>

<div id="footer-placeholder"></div>

<script src="../main.js"></script>
<script>
    loadComponent('header-placeholder', '../header.html');
    loadComponent('footer-placeholder', '../footer.html', updateWorkHours);
</script>
</body>
</html>