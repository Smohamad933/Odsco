<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
$settings = get_settings();
$all = Projects::list();
$projects = array_values(array_filter($all, fn($p)=> ($p['status'] ?? 'completed') === 'completed' || (int)($p['progress'] ?? 0) >= 100));
usort($projects, fn($a,$b)=> strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
$categories = Categories::list();
if (empty($categories)) $categories = [['name'=>'معماری و سازه'],['name'=>'تأسیسات'],['name'=>'صنعتی'],['name'=>'عمرانی']];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>پروژه‌ها | <?php echo e($settings['site_name'] ?? ''); ?></title>
<link rel="stylesheet" href="../style.css">
</head>
<body class="page">
<div id="header-placeholder"></div>
<main class="main">
<section class="about-hero"><div class="container"><h1 class="about-hero__title">پروژه‌های انجام شده</h1><p class="about-hero__subtitle">نمونه‌ای از افتخارات و پروژه‌های مهندسی ما — آرشیو MySQL</p></div></section>
<section class="projects"><div class="container">
<div class="projects-filter-bar">
<div class="filter-buttons">
<button class="filter-btn active" data-filter="all">همه</button>
<?php foreach ($categories as $cat): ?><button class="filter-btn" data-filter="<?php echo e($cat['name']); ?>"><?php echo e($cat['name']); ?></button><?php endforeach; ?>
</div>
<div class="search-box"><input type="text" id="projectSearch" class="search-input" placeholder="جستجو..."></div>
</div>
<div class="projects__grid" id="projectsGrid">
<?php if (!empty($projects)): ?>
<?php foreach ($projects as $project): $pid = $project['uid'] ?? $project['id'] ?? ''; ?>
<a href="detail.php?id=<?php echo e($pid); ?>" class="project-card" data-category="<?php echo e($project['category']); ?>" data-title="<?php echo e($project['title']); ?>">
<div class="project-img-box"><?php $cover = $project['cover_image'] ?? ($project['images'][0] ?? 'assets/default-project.jpg'); ?><img src="../<?php echo e($cover); ?>" alt="<?php echo e($project['title']); ?>"></div>
<div class="project-info"><span class="project-tag"><?php echo e($project['category']); ?></span><h3 class="project-title"><?php echo e($project['title']); ?></h3><?php if (isset($project['images']) && count($project['images'])>1): ?><small><?php echo fa_number(count($project['images'])); ?> عکس</small><?php endif; ?></div>
</a>
<?php endforeach; ?>
<?php else: ?><p style="text-align:center;padding:40px;">پروژه‌ای یافت نشد</p><?php endif; ?>
</div>
</div></section>
</main>
<div id="footer-placeholder"></div>
<script src="../main.js"></script>
<script>
loadComponent('header-placeholder','../header.html');
loadComponent('footer-placeholder','../footer.html',updateWorkHours);
document.addEventListener('DOMContentLoaded', function(){
 const filterBtns=document.querySelectorAll('.filter-btn');
 const cards=document.querySelectorAll('.project-card');
 const searchInput=document.getElementById('projectSearch');
 filterBtns.forEach(btn=>{
   btn.addEventListener('click', function(){
     filterBtns.forEach(b=>b.classList.remove('active')); this.classList.add('active');
     const filter=this.dataset.filter;
     cards.forEach(card=>{ card.style.display=(filter==='all'||card.dataset.category===filter)?'block':'none'; });
   });
 });
 if(searchInput){
   searchInput.addEventListener('input', function(){
     const term=this.value.toLowerCase();
     cards.forEach(card=>{ const t=card.dataset.title.toLowerCase(); card.style.display=t.includes(term)?'block':'none'; });
   });
 }
});
</script>
</body>
</html>
