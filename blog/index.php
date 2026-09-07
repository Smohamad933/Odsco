<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
$settings = get_settings();
$posts = get_blog_posts();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بلاگ | <?php echo $settings['site_name']; ?></title>
    <link rel="stylesheet" href="../style.css">
</head>
<body class="page">

<div id="header-placeholder"></div>

<main class="main">
    <section class="about-hero">
        <div class="container">
            <h1 class="about-hero__title">بلاگ و مقالات</h1>
            <p class="about-hero__subtitle">آخرین اخبار و مقالات تخصصی</p>
        </div>
    </section>

    <section class="blog">
        <div class="container">
            <?php if (!empty($posts)): ?>
                <div class="blog__grid">
                    <?php foreach ($posts as $post): ?>
                    <a href="post.php?slug=<?php echo $post['slug']; ?>" class="blog-card">
                        <div class="blog-img">
                            <?php if (!empty($post['image'])): ?>
                                <img src="../<?php echo $post['image']; ?>" alt="<?php echo $post['title']; ?>" style="width:100%;height:100%;object-fit:cover;">
                            <?php endif; ?>
                        </div>
                        <div class="blog-content">
                            <span class="blog-date"><?php echo format_date($post['date']); ?></span>
                            <h3 class="blog-title"><?php echo $post['title']; ?></h3>
                            <p class="blog-excerpt"><?php echo $post['excerpt'] ?? ''; ?></p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="text-align:center;padding:40px;">مقاله‌ای یافت نشد</p>
            <?php endif; ?>
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