<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
$settings = get_settings();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>خدمات ما | <?php echo $settings['site_name']; ?></title>
    <link rel="stylesheet" href="../style.css">
    <style>
        /* ============ بنر خدمات ============ */
        .services-hero {
            background: linear-gradient(rgba(252, 252, 252, 0.95), rgba(252, 252, 252, 0.95)), url('../assets/image-bg1.png') center / cover no-repeat;
            padding: 140px 20px 60px 20px;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
        }
        
        .services-hero__badge {
            display: inline-block;
            background: var(--accent-color);
            color: #fff;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 15px;
        }
        
        .services-hero__title {
            font-size: 36px;
            font-weight: 900;
            margin-bottom: 12px;
        }
        
        .services-hero__subtitle {
            font-size: 15px;
            color: var(--text-muted);
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.8;
        }
        
        /* ============ بخش خدمات اصلی ============ */
        .services-main {
            padding: 70px 20px;
            background: var(--bg-color);
        }
        
        .services-grid-detailed {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
        }
        
        .service-card-detailed {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 35px 25px;
            border: 1px solid var(--border-color);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }
        
        .service-card-detailed::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100%;
            height: 4px;
            background: var(--accent-color);
            transform: scaleX(0);
            transform-origin: right;
            transition: transform 0.4s ease;
        }
        
        .service-card-detailed:hover::before {
            transform: scaleX(1);
        }
        
        .service-card-detailed:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.08);
        }
        
        .service-icon {
            width: 70px;
            height: 70px;
            background: var(--section-alt-bg);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            font-size: 32px;
            transition: all 0.4s ease;
        }
        
        .service-card-detailed:hover .service-icon {
            background: var(--accent-color);
            transform: scale(1.1) rotate(5deg);
        }
        
        .service-card-detailed h3 {
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 12px;
        }
        
        .service-card-detailed p {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.8;
            margin-bottom: 15px;
        }
        
        .service-features {
            list-style: none;
            padding: 0;
            margin-bottom: 20px;
        }
        
        .service-features li {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .service-features li::before {
            content: '✓';
            color: #4CAF50;
            font-weight: 900;
        }
        
        .service-link {
            display: inline-block;
            font-size: 13px;
            font-weight: 700;
            color: var(--accent-color);
            text-decoration: none;
            position: relative;
        }
        
        .service-link::after {
            content: '';
            position: absolute;
            bottom: -3px;
            right: 0;
            width: 0;
            height: 2px;
            background: var(--accent-color);
            transition: width 0.3s ease;
        }
        
        .service-link:hover::after {
            width: 100%;
        }
        
        /* ============ بخش فرآیند ============ */
        .process-section {
            padding: 70px 20px;
            background: var(--section-alt-bg);
            background-image: var(--sketch-pattern);
            background-size: 24px 24px;
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
        }
        
        .process-title {
            text-align: center;
            font-size: 26px;
            font-weight: 900;
            margin-bottom: 40px;
        }
        
        .process-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
        }
        
        .process-step {
            text-align: center;
            position: relative;
        }
        
        .process-step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 30px;
            left: -15px;
            width: 30px;
            height: 2px;
            background: var(--border-color);
        }
        
        .process-number {
            width: 60px;
            height: 60px;
            background: var(--accent-color);
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 900;
            margin: 0 auto 15px;
        }
        
        .process-step h4 {
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 8px;
        }
        
        .process-step p {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        /* ============ بخش آمار ============ */
        .services-stats {
            padding: 60px 20px;
            background: var(--bg-color);
        }
        
        .services-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }
        
        .services-stat {
            text-align: center;
            padding: 25px;
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border-color);
        }
        
        .services-stat__number {
            font-size: 32px;
            font-weight: 900;
            color: var(--accent-color);
            display: block;
            margin-bottom: 5px;
        }
        
        .services-stat__label {
            font-size: 13px;
            color: var(--text-muted);
        }
        
        /* ============ ریسپانسیو ============ */
        @media screen and (max-width: 992px) {
            .services-grid-detailed {
                grid-template-columns: repeat(2, 1fr);
            }
            .process-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 30px;
            }
            .process-step:not(:last-child)::after {
                display: none;
            }
            .services-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media screen and (max-width: 768px) {
            .services-hero__title {
                font-size: 24px;
            }
            .services-grid-detailed {
                grid-template-columns: 1fr;
            }
            .process-grid {
                grid-template-columns: 1fr;
            }
            .services-stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="page">

<div id="header-placeholder"></div>

<main class="main">
    <!-- ============ بنر خدمات ============ -->
    <section class="services-hero">
        <div class="container">
            <span class="services-hero__badge">خدمات تخصصی ما</span>
            <h1 class="services-hero__title">خدمات مهندسی و معماری</h1>
            <p class="services-hero__subtitle">
                ارائه خدمات جامع مهندسی با بالاترین استانداردهای کیفی، از طراحی تا اجرا و نظارت
            </p>
        </div>
    </section>

    <!-- ============ خدمات اصلی ============ -->
    <section class="services-main">
        <div class="container">
            <h2 class="services__section-title" style="text-align:center;margin-bottom:40px;">خدمات ما</h2>
            
            <div class="services-grid-detailed">
                <!-- خدمت ۱ -->
                <div class="service-card-detailed">
                    <div class="service-icon">🏗️</div>
                    <h3>طراحی معماری</h3>
                    <p>طراحی خلاقانه و اصولی فضاهای مسکونی، تجاری و اداری با رعایت استانداردهای روز دنیا</p>
                    <ul class="service-features">
                        <li>طراحی نما و پلان</li>
                        <li>طراحی داخلی</li>
                        <li>طراحی منظر و محوطه</li>
                    </ul>
                    <a href="../contact" class="service-link">درخواست مشاوره ←</a>
                </div>
                
                <!-- خدمت ۲ -->
                <div class="service-card-detailed">
                    <div class="service-icon">📐</div>
                    <h3>مهندسی عمران</h3>
                    <p>محاسبات سازه و طراحی سازه‌های بتنی و فولادی با دقت و ایمنی بالا</p>
                    <ul class="service-features">
                        <li>محاسبات سازه</li>
                        <li>طراحی فونداسیون</li>
                        <li>نظارت بر اجرا</li>
                    </ul>
                    <a href="../contact" class="service-link">درخواست مشاوره ←</a>
                </div>
                
                <!-- خدمت ۳ -->
                <div class="service-card-detailed">
                    <div class="service-icon">⚡</div>
                    <h3>تأسیسات برقی</h3>
                    <p>طراحی سیستم‌های برقی ساختمان با رویکرد بهینه‌سازی مصرف انرژی</p>
                    <ul class="service-features">
                        <li>سیستم روشنایی</li>
                        <li>سیستم اعلام حریق</li>
                        <li>سیستم هوشمند ساختمان</li>
                    </ul>
                    <a href="../contact" class="service-link">درخواست مشاوره ←</a>
                </div>
                
                <!-- خدمت ۴ -->
                <div class="service-card-detailed">
                    <div class="service-icon">🔧</div>
                    <h3>تأسیسات مکانیکی</h3>
                    <p>طراحی و اجرای سیستم‌های تهویه مطبوع، گرمایش و سرمایش ساختمان</p>
                    <ul class="service-features">
                        <li>سیستم تهویه مطبوع</li>
                        <li>سیستم گرمایش و سرمایش</li>
                        <li>سیستم آبرسانی</li>
                    </ul>
                    <a href="../contact" class="service-link">درخواست مشاوره ←</a>
                </div>
                
                <!-- خدمت ۵ -->
                <div class="service-card-detailed">
                    <div class="service-icon">🚗</div>
                    <h3>مهندسی ترافیک</h3>
                    <p>طراحی و برنامه‌ریزی سیستم‌های حمل و نقل و مدیریت ترافیک شهری</p>
                    <ul class="service-features">
                        <li>مطالعات ترافیکی</li>
                        <li>طراحی تقاطع‌ها</li>
                        <li>برنامه‌ریزی حمل و نقل</li>
                    </ul>
                    <a href="../contact" class="service-link">درخواست مشاوره ←</a>
                </div>
                
                <!-- خدمت ۶ -->
                <div class="service-card-detailed">
                    <div class="service-icon">📋</div>
                    <h3>نظارت و مدیریت پروژه</h3>
                    <p>نظارت کامل بر اجرای پروژه‌ها از شروع تا تحویل با بالاترین کیفیت</p>
                    <ul class="service-features">
                        <li>نظارت عالیه</li>
                        <li>مدیریت پیمان</li>
                        <li>کنترل کیفیت</li>
                    </ul>
                    <a href="../contact" class="service-link">درخواست مشاوره ←</a>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ فرآیند کار ============ -->
    <section class="process-section">
        <div class="container">
            <h2 class="process-title">فرآیند کار ما</h2>
            
            <div class="process-grid">
                <div class="process-step">
                    <div class="process-number">۱</div>
                    <h4>مشاوره اولیه</h4>
                    <p>بررسی نیازها و خواسته‌های شما</p>
                </div>
                
                <div class="process-step">
                    <div class="process-number">۲</div>
                    <h4>طراحی و برنامه‌ریزی</h4>
                    <p>ارائه طرح‌های اولیه و نقشه‌ها</p>
                </div>
                
                <div class="process-step">
                    <div class="process-number">۳</div>
                    <h4>اجرا و نظارت</h4>
                    <p>اجرای دقیق پروژه با نظارت کامل</p>
                </div>
                
                <div class="process-step">
                    <div class="process-number">۴</div>
                    <h4>تحویل و پشتیبانی</h4>
                    <p>تحویل پروژه و پشتیبانی پس از آن</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ آمار ============ -->
    <section class="services-stats">
        <div class="container">
            <div class="services-stats-grid">
                <div class="services-stat">
                    <span class="services-stat__number">+۲۵۰</span>
                    <span class="services-stat__label">پروژه موفق</span>
                </div>
                <div class="services-stat">
                    <span class="services-stat__number">+۱۱</span>
                    <span class="services-stat__label">سال تجربه</span>
                </div>
                <div class="services-stat">
                    <span class="services-stat__number">+۵۰</span>
                    <span class="services-stat__label">مشتری راضی</span>
                </div>
                <div class="services-stat">
                    <span class="services-stat__number">٪۹۸</span>
                    <span class="services-stat__label">رضایت مشتریان</span>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ CTA ============ -->
    <section class="cta">
        <div class="cta__content">
            <h2 class="cta__title">آماده شروع پروژه شما هستیم</h2>
            <h3 class="cta__subtitle">برای دریافت مشاوره رایگان با ما تماس بگیرید</h3>
        </div>
        <a href="../contact" class="cta__action">
            <span class="cta__btn-text">درخواست مشاوره</span>
        </a>
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