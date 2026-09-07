<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
$settings = get_settings();
$projects = get_projects();
$categories = read_json('categories.json');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پروژه‌ها | <?php echo $settings['site_name']; ?></title>
    <link rel="stylesheet" href="../style.css">
</head>
<body class="page">

<div id="header-placeholder"></div>

<main class="main">
    <section class="about-hero">
        <div class="container">
            <h1 class="about-hero__title">پروژه‌های انجام شده</h1>
            <p class="about-hero__subtitle">نمونه‌ای از افتخارات و پروژه‌های مهندسی ما</p>
        </div>
    </section>

    <section class="projects">
        <div class="container">
            <div class="projects-filter-bar">
                <div class="filter-buttons">
                    <button class="filter-btn active" data-filter="all">همه</button>
                    <?php foreach ($categories as $cat): ?>
                        <button class="filter-btn" data-filter="<?php echo $cat['name']; ?>"><?php echo $cat['name']; ?></button>
                    <?php endforeach; ?>
                </div>
                
                <div class="search-box">
                    <input type="text" id="projectSearch" class="search-input" placeholder="جستجو...">
                </div>
            </div>

            <div class="projects__grid" id="projectsGrid">
                <?php if (!empty($projects)): ?>
                    <?php foreach ($projects as $project): ?>
                    <a href="detail.php?id=<?php echo $project['id']; ?>" 
                       class="project-card" 
                       data-category="<?php echo $project['category']; ?>"
                       data-title="<?php echo $project['title']; ?>">
                        <div class="project-img-box">
                            <?php $cover = $project['cover_image'] ?? ($project['images'][0] ?? 'assets/default-project.jpg'); ?>
                            <img src="../<?php echo $cover; ?>" alt="<?php echo $project['title']; ?>">
                        </div>
                        <div class="project-info">
                            <span class="project-tag"><?php echo $project['category']; ?></span>
                            <h3 class="project-title"><?php echo $project['title']; ?></h3>
                            <?php if (isset($project['images']) && count($project['images']) > 1): ?>
                                <small><?php echo count($project['images']); ?> عکس</small>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="text-align:center;padding:40px;">پروژه‌ای یافت نشد</p>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<div id="footer-placeholder"></div>

<script src="../main.js"></script>
<script>
    loadComponent('header-placeholder', '../header.html');
    loadComponent('footer-placeholder', '../footer.html', updateWorkHours);
    
    document.addEventListener('DOMContentLoaded', function() {
        const filterBtns = document.querySelectorAll('.filter-btn');
        const cards = document.querySelectorAll('.project-card');
        const searchInput = document.getElementById('projectSearch');
        
        filterBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                filterBtns.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const filter = this.dataset.filter;
                cards.forEach(card => {
                    if (filter === 'all' || card.dataset.category === filter) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        });
        
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const term = this.value.toLowerCase();
                cards.forEach(card => {
                    const title = card.dataset.title.toLowerCase();
                    if (title.includes(term)) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        }
    });
</script>
</body>
</html>