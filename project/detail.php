<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/logger.php';

$settings = get_settings();
$project_id = $_GET['id'] ?? '';
$project = get_project_by_id($project_id);

if (!$project) {
    header('Location: index.php');
    exit;
}

// ============ سیستم بازدید ============
increment_project_view($project_id);
track_daily_view();
$views = get_project_views($project_id);
// =====================================

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$currentUrl = $protocol . $host . '/project/detail.php?id=' . $project_id;

$images = [];
if (isset($project['images']) && is_array($project['images']) && !empty($project['images'])) {
    $images = $project['images'];
} elseif (!empty($project['cover_image'])) {
    $images = [$project['cover_image']];
} elseif (!empty($project['image'])) {
    $images = [$project['image']];
} else {
    $images = ['assets/default-project.jpg'];
}

$cover = $project['cover_image'] ?? $images[0];

$projectTitle = $project['title'];
$client = $project['client'] ?? '';
$location = $project['location'] ?? '';
$area = $project['area'] ?? '';
$overview = strip_tags($project['description'] ?? '');
$specs = $project['specs'] ?? [];
$projectImages = $images;
$safeProjectImages = $images;
$instagramHashtags = "#معماری #طراحی_داخلی #" . str_replace(' ', '_', $projectTitle) . " #عمران #ساختمان_سازی #مهندسی";
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $project['title']; ?> | <?php echo $settings['site_name']; ?></title>
    <link rel="stylesheet" href="../style.css">
    <!-- کتابخانه‌ها به‌صورت محلی هستند تا سایت بدون اینترنت/CDN هم کار کند -->
    <script src="../assets/vendor/jszip.min.js"></script>
    <script src="../assets/vendor/FileSaver.min.js"></script>
    <script src="../assets/vendor/qrcode.js"></script>
    <style>
        /* ============ فونت اجباری برای محتوای ادیتور ============ */
        .project-description,
        .project-description * {
            font-family: 'abar', 'Vazirmatn', sans-serif !important;
            font-size: 14px !important;
            line-height: 2 !important;
            color: #333 !important;
            border: none !important;
            outline: none !important;
            -webkit-font-smoothing: antialiased !important;
            background: transparent !important;
            max-width: 100% !important;
            word-wrap: break-word !important;
        }
        
        .project-description h1,
        .project-description h2 {
            font-size: 18px !important;
            font-weight: 900 !important;
            margin: 20px 0 10px !important;
            color: #1a1a1a !important;
        }
        
        .project-description h3 {
            font-size: 16px !important;
            font-weight: 800 !important;
            margin: 15px 0 8px !important;
            color: #1a1a1a !important;
        }
        
        .project-description h4,
        .project-description h5,
        .project-description h6 {
            font-size: 14px !important;
            font-weight: 700 !important;
            margin: 12px 0 6px !important;
            color: #333 !important;
        }
        
        .project-description p {
            margin-bottom: 10px !important;
            padding: 0 !important;
        }
        
        .project-description ul,
        .project-description ol {
            padding-right: 25px !important;
            margin: 10px 0 !important;
        }
        
        .project-description li {
            margin-bottom: 5px !important;
        }
        
        .project-description b,
        .project-description strong {
            font-weight: 900 !important;
            color: #1a1a1a !important;
        }
        
        .project-description a {
            color: #3742fa !important;
            text-decoration: underline !important;
        }
        
        .project-description img {
            max-width: 100% !important;
            height: auto !important;
            border-radius: 10px !important;
            margin: 15px 0 !important;
        }
        
        .project-description table {
            width: 100% !important;
            border-collapse: collapse !important;
            margin: 15px 0 !important;
            border: 1px solid #e0e0e0 !important;
        }
        
        .project-description th {
            background: #f5f5f5 !important;
            font-weight: 700 !important;
            padding: 10px 12px !important;
            border: 1px solid #e0e0e0 !important;
        }
        
        .project-description td {
            padding: 8px 12px !important;
            border: 1px solid #e0e0e0 !important;
        }
        
        .project-description blockquote {
            border-right: 4px solid #3742fa !important;
            padding: 10px 15px !important;
            margin: 15px 0 !important;
            background: #f8f9fa !important;
        }
        
        .project-description code {
            background: #f5f5f5 !important;
            padding: 2px 6px !important;
            border-radius: 4px !important;
            font-size: 13px !important;
            direction: ltr !important;
        }
        
        .project-description hr {
            border: none !important;
            border-top: 1px solid #e0e0e0 !important;
            margin: 20px 0 !important;
        }
        
        .project-description div,
        .project-description span {
            background: transparent !important;
            border: none !important;
            outline: none !important;
            font-family: inherit !important;
            font-size: inherit !important;
            color: inherit !important;
            line-height: inherit !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        
        /* ============ استایل صفحه ============ */
        .project-detail-section { padding: 40px 20px; }
        .project-detail-container { max-width: 1000px; margin: 0 auto; }
        .back-link { display: inline-block; margin-bottom: 20px; color: #666; font-size: 14px; text-decoration: none; }
        .project-cover { width: 100%; max-height: 500px; object-fit: cover; border-radius: 16px; margin-bottom: 20px; }
        .project-title { font-size: 28px; font-weight: 900; margin-bottom: 15px; }
        
        .project-stats-bar {
            display: flex; gap: 15px; flex-wrap: wrap;
            padding: 15px; background: #fff;
            border-radius: 12px; border: 1px solid #eee;
            margin-bottom: 20px; align-items: center;
        }
        .share-btn, .story-btn, .post-btn {
            padding: 8px 15px; border: 1px solid #ddd;
            border-radius: 8px; background: #fff; cursor: pointer;
            font-family: inherit; font-size: 13px;
        }
        .share-btn:hover, .story-btn:hover, .post-btn:hover { background: #1a1a1a; color: #fff; }
        
        .project-meta { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 20px; font-size: 13px; color: #666; }
        .project-description { font-size: 15px; line-height: 2; margin-bottom: 30px; }
        
        /* ============ گالری ============ */
        .gallery-section { margin-top: 30px; margin-bottom: 30px; }
        .gallery-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .gallery-title { font-size: 20px; font-weight: 900; color: #1a1a1a; margin: 0; }
        .gallery-count {
            font-size: 12px; font-weight: 700; color: #666;
            background: #f0f0f0; padding: 5px 12px; border-radius: 20px;
        }
        
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 15px;
        }
        .gallery-item {
            position: relative;
            border-radius: 12px;
            overflow: hidden;
            cursor: pointer;
            aspect-ratio: 4/3;
            transition: all 0.3s;
        }
        .gallery-item:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        .gallery-item img {
            width: 100%; height: 100%;
            object-fit: cover;
            transition: transform 0.4s;
        }
        .gallery-item:hover img { transform: scale(1.05); }
        
        .gallery-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.35);
            display: flex; align-items: center; justify-content: center;
            opacity: 0; transition: all 0.3s ease;
        }
        .gallery-item:hover .gallery-overlay { opacity: 1; }
        
        .gallery-zoom {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            color: #fff;
            transition: all 0.3s ease;
            backdrop-filter: blur(3px);
        }
        .gallery-item:hover .gallery-zoom {
            background: rgba(255,255,255,0.2);
            transform: scale(1.1);
        }
        
        .gallery-number {
            position: absolute;
            bottom: 8px;
            left: 8px;
            background: rgba(0,0,0,0.5);
            color: #fff;
            font-size: 9px;
            padding: 2px 8px;
            border-radius: 10px;
        }
        
        /* ============ لایت باکس ============ */
        .lightbox-overlay {
            display: none;
            position: fixed; top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 9999;
            justify-content: center; align-items: center;
        }
        .lightbox-overlay.active { display: flex; }
        .lightbox-image {
            max-width: 85%; max-height: 80vh;
            object-fit: contain; border-radius: 12px;
        }
        .lightbox-close {
            position: absolute; top: 20px; left: 20px;
            background: rgba(255,255,255,0.1); border: none;
            color: #fff; font-size: 22px;
            width: 42px; height: 42px; border-radius: 50%;
            cursor: pointer;
        }
        .lightbox-prev, .lightbox-next {
            position: absolute; top: 50%; transform: translateY(-50%);
            background: rgba(255,255,255,0.1); border: none;
            color: #fff; font-size: 22px;
            width: 42px; height: 42px; border-radius: 50%;
            cursor: pointer;
        }
        .lightbox-prev { right: 20px; }
        .lightbox-next { left: 20px; }
        .lightbox-counter {
            position: absolute; bottom: 20px;
            color: #fff; font-size: 13px;
        }
        
        /* ============ اینستاگرام ============ */
        .instagram-box { display: none; background: #fff; border: 1px solid #eee; border-radius: 16px; padding: 25px; margin-top: 20px; }
        .instagram-box.active { display: block; }
        .image-thumbnails-grid { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
        .thumb-item { width: 70px; height: 70px; cursor: pointer; border: 2px solid transparent; border-radius: 8px; overflow: hidden; }
        .thumb-item.selected { border-color: #4CAF50; }
        .thumb-item img { width: 100%; height: 100%; object-fit: cover; }
        .story-controls { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 15px 0; }
        .control-group { display: flex; flex-direction: column; gap: 5px; }
        .control-group label { font-size: 12px; font-weight: 700; }
        .story-preview-container, .post-preview-container { margin: 20px auto; max-width: 350px; }
        .story-preview-container canvas, .post-preview-container canvas { width: 100%; border-radius: 12px; }
        .story-actions-flex { display: flex; gap: 10px; margin: 15px 0; flex-wrap: wrap; }
        .action-btn { padding: 10px 20px; background: #1a1a1a; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-family: inherit; font-size: 13px; }
        .ig-caption-area { width: 100%; min-height: 100px; padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-family: inherit; font-size: 13px; }
        .slide-config-bar { display: flex; gap: 15px; align-items: center; margin: 10px 0; }
        
        @media (max-width: 768px) {
            .story-controls { grid-template-columns: 1fr; }
            .project-stats-bar { flex-direction: column; }
            .gallery-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }
            .lightbox-image { max-width: 95%; }
        }
    </style>
</head>
<body class="page">

<div id="header-placeholder"></div>

<main class="main">
    <section class="about-hero">
        <div class="container">
            <h1 class="about-hero__title"><?php echo $project['title']; ?></h1>
        </div>
    </section>

    <section class="project-detail-section">
        <div class="project-detail-container">
            <a href="index.php" class="back-link">← بازگشت به پروژه‌ها</a>
            
            <img src="../<?php echo $cover; ?>" alt="<?php echo $project['title']; ?>" class="project-cover">
            
            <h2 class="project-title"><?php echo $project['title']; ?></h2>
            
            <div class="project-stats-bar">
                <span>👁️ <?php echo $views; ?> بازدید</span>
                <button class="share-btn" onclick="shareProject()">🔗 اشتراک‌گذاری</button>
                <button class="story-btn" onclick="toggleBox('storyBox')">📱 ساخت استوری</button>
                <button class="post-btn" onclick="toggleBox('postBox')">📷 ساخت پست</button>
            </div>
            
            <div class="project-meta">
                <span>🏷️ <?php echo $project['category'] ?? ''; ?></span>
                <?php if (!empty($project['location'])): ?><span>📍 <?php echo $project['location']; ?></span><?php endif; ?>
                <?php if (!empty($project['year'])): ?><span>📅 <?php echo $project['year']; ?></span><?php endif; ?>
                <?php if (!empty($project['client'])): ?><span>👤 <?php echo $project['client']; ?></span><?php endif; ?>
            </div>
            
            <?php if (!empty($project['specs'])): ?>
                <div style="margin-bottom:20px;padding:15px;background:#fff;border-radius:12px;border:1px solid #eee;">
                    <strong>مشخصات فنی:</strong>
                    <ul style="margin-top:10px;padding-right:20px;">
                        <?php foreach ($project['specs'] as $spec): ?>
                            <li style="margin-bottom:5px;"><?php echo htmlspecialchars($spec); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($project['description'])): ?>
                <div class="project-description"><?php echo $project['description']; ?></div>
            <?php endif; ?>
            
            <!-- ============ گالری ============ -->
            <?php if (count($images) > 1): ?>
            <div class="gallery-section">
                <div class="gallery-header">
                    <h3 class="gallery-title">گالری تصاویر</h3>
                    <span class="gallery-count"><?php echo count($images); ?> تصویر</span>
                </div>
                
                <div class="gallery-grid">
                    <?php foreach ($images as $index => $img): ?>
                    <div class="gallery-item" onclick="openLightbox(<?php echo $index; ?>)">
                        <img src="../<?php echo $img; ?>" alt="تصویر <?php echo $index + 1; ?>" loading="lazy">
                        <div class="gallery-overlay">
                            <span class="gallery-zoom">↗</span>
                            <span class="gallery-number"><?php echo $index + 1; ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- لایت باکس -->
            <div class="lightbox-overlay" id="lightbox" onclick="closeLightbox()">
                <button class="lightbox-close" onclick="closeLightbox()">✕</button>
                <button class="lightbox-prev" onclick="event.stopPropagation(); changeSlide(-1)">‹</button>
                <img src="" alt="" id="lightboxImage" class="lightbox-image">
                <button class="lightbox-next" onclick="event.stopPropagation(); changeSlide(1)">›</button>
                <div class="lightbox-counter" id="lightboxCounter">1 / <?php echo count($images); ?></div>
            </div>
            <?php endif; ?>
            
            <?php include 'instagram-maker.php'; ?>
        </div>
    </section>
</main>

<div id="footer-placeholder"></div>

<script>
// ============ گالری ============
const galleryImages = <?php echo json_encode($images, JSON_UNESCAPED_UNICODE); ?>;
let currentSlide = 0;

function openLightbox(index) {
    currentSlide = index;
    updateLightbox();
    document.getElementById('lightbox').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    document.getElementById('lightbox').classList.remove('active');
    document.body.style.overflow = 'auto';
}

function changeSlide(direction) {
    currentSlide += direction;
    if (currentSlide < 0) currentSlide = galleryImages.length - 1;
    if (currentSlide >= galleryImages.length) currentSlide = 0;
    updateLightbox();
}

function updateLightbox() {
    const img = document.getElementById('lightboxImage');
    img.src = '../' + galleryImages[currentSlide];
    document.getElementById('lightboxCounter').textContent = (currentSlide + 1) + ' / ' + galleryImages.length;
}

document.addEventListener('keydown', function(e) {
    if (!document.getElementById('lightbox').classList.contains('active')) return;
    if (e.key === 'Escape') closeLightbox();
    if (e.key === 'ArrowRight') changeSlide(1);
    if (e.key === 'ArrowLeft') changeSlide(-1);
});

// ============ اشتراک‌گذاری ============
function shareProject() {
    const url = "<?php echo $currentUrl; ?>";
    if (navigator.share) {
        navigator.share({ title: "<?php echo addslashes($projectTitle); ?>", url: url }).catch(() => {});
    } else if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(() => alert('لینک کپی شد!'));
    } else {
        prompt('لینک پروژه:', url);
    }
}
</script>

<script src="../main.js"></script>
<script>
    loadComponent('header-placeholder', '../header.html');
    loadComponent('footer-placeholder', '../footer.html', updateWorkHours);
</script>
</body>
</html>