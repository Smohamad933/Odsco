<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$settings = get_settings();

// ============ ثبت بازدید ============
track_daily_view();
// ===================================

// دریافت پروژه‌های نمایش در خانه
$all_projects = get_projects();
$home_projects = array_filter($all_projects, function($p) {
    return !empty($p['show_on_home']);
});
usort($home_projects, function($a, $b) {
    return strtotime($b['created_at'] ?? 'now') - strtotime($a['created_at'] ?? 'now');
});
$home_projects = array_slice($home_projects, 0, 8);

// دریافت مقالات
$posts = get_blog_posts(4);

// ============ دریافت کارفرمایان ============
$clients = read_json('clients.json');
if (!is_array($clients)) $clients = [];
// =========================================
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $settings['site_name']; ?> | <?php echo $settings['site_description'] ?? ''; ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="page">

<div id="header-placeholder"></div>

<main class="main">
    <!-- Hero Section -->
    <section class="hero">
        <div class="hero__content">
            <h1 class="hero__title hero__title--highlight">مشاوران افق دانش ثریا</h1>
            <h2 class="hero__title">تلفیق دانش مهندسی و هنر معماری</h2>
            <p class="hero__description">
                مجری تخصصی پروژه‌های مسکونی، تجاری و اداری با پروانه رسمی از وزارت راه و شهرسازی
            </p>
        </div>
        <a href="contact" class="hero__action">
            <span>مشاوره رایگان</span>
            <span>←</span>
        </a>
    </section>

    <!-- Story Section -->
    <section class="story">
        <div class="container story__container">
            <h2 class="story__title">داستان ما</h2>
            <p class="story__text">
                شرکت مشاوران افق دانش ثریا از سال ۱۳۹۴ با گرد هم آمدن جمعی از متخصصان جوان، پرانرژی و باتجربه صنعت ساختمان فعالیت خود را آغاز کرد. ما با اخذ پروانه اشتغال به کار رسمی از وزارت راه و شهرسازی، پوشش کاملی از تمامی رشته‌های کلیدی ساختمانی شامل معماری، عمران، تأسیسات برقی و مکانیکی و مهندسی ترافیک را فراهم آورده‌ایم.
            </p>
        </div>
    </section>

    <!-- Services Section -->
    <section class="services">
        <div class="container">
            <h2 class="services__section-title">خدمات ما</h2>
            <div class="services__grid">
                <div class="service-card">
                    <span class="service-card__number">۰۱</span>
                    <div class="service-card__img-box">
                        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-4h6v4"/>
                        </svg>
                    </div>
                    <h3 class="service-card__title">طراحی معماری</h3>
                </div>

                <div class="service-card">
                    <span class="service-card__number">۰۲</span>
                    <div class="service-card__img-box">
                        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                        </svg>
                    </div>
                    <h3 class="service-card__title">مهندسی عمران</h3>
                </div>

                <div class="service-card">
                    <span class="service-card__number">۰۳</span>
                    <div class="service-card__img-box">
                        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
                        </svg>
                    </div>
                    <h3 class="service-card__title">تأسیسات برقی</h3>
                </div>

                <div class="service-card">
                    <span class="service-card__number">۰۴</span>
                    <div class="service-card__img-box">
                        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
                        </svg>
                    </div>
                    <h3 class="service-card__title">تأسیسات مکانیکی</h3>
                </div>

                <div class="service-card">
                    <span class="service-card__number">۰۵</span>
                    <div class="service-card__img-box">
                        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M2 22h20M4 22V12l8-6 8 6v10M8 22v-6h8v6"/>
                        </svg>
                    </div>
                    <h3 class="service-card__title">مهندسی ترافیک</h3>
                </div>
            </div>
        </div>
    </section>

    <!-- Projects Section -->
    <section class="projects">
        <div class="container">
            <h2 class="projects__section-title">پروژه‌های اخیر</h2>
            
            <?php if (!empty($home_projects)): ?>
            <div class="projects__grid">
                <?php foreach ($home_projects as $project): ?>
                <a href="project/detail.php?id=<?php echo $project['id']; ?>" class="project-card">
                    <div class="project-img-box">
                        <?php 
                        $cover = $project['cover_image'] ?? ($project['images'][0] ?? ($project['image'] ?? 'assets/default-project.jpg'));
                        ?>
                        <img src="<?php echo $cover; ?>" alt="<?php echo $project['title']; ?>" onerror="this.src='assets/default-project.jpg'">
                    </div>
                    <div class="project-info">
                        <span class="project-tag"><?php echo $project['category'] ?? 'عمومی'; ?></span>
                        <h3 class="project-title"><?php echo $project['title']; ?></h3>
                    </div>
                </a>
                <?php endforeach; ?>
                
                <a href="project" class="card-more">
                    <h3 class="card-more__title">مشاهده همه پروژه‌ها</h3>
                    <p class="card-more__text">بیشتر ببینید</p>
                </a>
            </div>
            <?php else: ?>
                <p style="text-align:center;padding:40px;color:#666;">پروژه‌ای برای نمایش نیست</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- ============ Clients Section ============ -->
    <?php if (!empty($clients)): ?>
    <section class="clients">
        <h2 class="clients__title">کارفرمایان ما</h2>
        <div class="clients__slider">
            <div class="clients__track">
                <?php foreach ($clients as $client): ?>
                <div class="clients__item">
                    <img src="<?php echo $client['logo'] ?? ''; ?>" alt="<?php echo $client['name'] ?? 'کارفرما'; ?>" onerror="this.style.display='none'">
                </div>
                <?php endforeach; ?>
                <!-- تکرار برای اسلاید بی‌نهایت -->
                <?php foreach ($clients as $client): ?>
                <div class="clients__item">
                    <img src="<?php echo $client['logo'] ?? ''; ?>" alt="<?php echo $client['name'] ?? 'کارفرما'; ?>" onerror="this.style.display='none'">
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>
    <!-- ======================================== -->

    <!-- Blog Section -->
    <section class="blog">
        <div class="container">
            <h2 class="blog__section-title">آخرین مقالات</h2>
            
            <?php if (!empty($posts)): ?>
            <div class="blog__grid">
                <?php foreach ($posts as $post): ?>
                <a href="blog/post.php?slug=<?php echo $post['slug']; ?>" class="blog-card">
                    <div class="blog-img">
                        <?php if (!empty($post['image'])): ?>
                            <img src="<?php echo $post['image']; ?>" alt="<?php echo $post['title']; ?>" style="width:100%;height:100%;object-fit:cover;">
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
                <p style="text-align:center;padding:40px;color:#666;">مقاله‌ای یافت نشد</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta">
        <div class="cta__content">
            <h2 class="cta__title">مشتاق گفت‌وگو درباره پروژه شما هستیم</h2>
            <h3 class="cta__subtitle">جهت دریافت مشاوره تخصصی با مهندسان ما در ارتباط باشید</h3>
        </div>
        <a href="contact" class="cta__action">
            <span class="cta__btn-text">تماس با کارشناسان</span>
        </a>
    </section>
</main>

<div id="footer-placeholder"></div>

<script src="main.js"></script>
<script>
    loadComponent('header-placeholder', 'header.html');
    loadComponent('footer-placeholder', 'footer.html', updateWorkHours);
</script>
</body>
</html>