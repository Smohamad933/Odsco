<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

$settings = get_settings();
$team_members = get_team_members();

/* مرتب‌سازی اعضای تیم بر اساس ترتیب */
usort($team_members, function($a, $b) {
    return ($a['order'] ?? 0) - ($b['order'] ?? 0);
});
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>درباره ما | <?php echo htmlspecialchars($settings['site_name'] ?? 'مشاوران افق دانش ثریا'); ?></title>

    <link rel="stylesheet" href="../style.css">

    <style>
        /* =========================================================
           TEAM SECTION
        ========================================================= */

        .team-section {
            padding: 70px 20px;
            background: var(--section-alt-bg);
        }

        .team-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .team-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            background: rgba(0, 0, 0, 0.05);
            margin-bottom: 10px;
        }

        .team-title {
            font-size: 26px;
            font-weight: 900;
            margin-bottom: 8px;
        }

        .team-subtitle {
            font-size: 13px;
            color: var(--text-muted);
        }

        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* =========================================================
           TEAM CARD
        ========================================================= */

        .team-card {
            background: #fff;
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            text-align: center;
            display: flex;
            flex-direction: column;
        }

        .team-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
        }

        /* =========================================================
           TEAM PHOTO - 1:1
        ========================================================= */

        .team-photo {
            position: relative;
            width: 100%;
            aspect-ratio: 1 / 1;
            overflow: hidden;
            background: #f0f0f0;
        }

        .team-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
            transition: transform 0.4s;
        }

        .team-card:hover .team-photo img {
            transform: scale(1.05);
        }

        .team-photo-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            background: linear-gradient(135deg, #e0e0e0, #f5f5f5);
        }

        /* =========================================================
           TEAM INFO
        ========================================================= */

        .team-info {
            padding: 20px 15px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .team-name {
            font-size: 16px;
            font-weight: 900;
            margin-bottom: 5px;
        }

        .team-role {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            background: #1a1a1a;
            color: #fff;
            margin: 0 auto 10px;
            width: fit-content;
        }

        .team-bio {
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.8;
            margin-bottom: 12px;

            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* =========================================================
           MORE INFO BUTTON
        ========================================================= */

        .btn-more-info {
            margin-top: auto;
            padding: 8px 16px;
            background: transparent;
            border: 1px solid #1a1a1a;
            border-radius: 10px;
            cursor: pointer;

            font-family: inherit;
            font-size: 11px;
            font-weight: 700;
            color: #1a1a1a;

            transition: all 0.3s;

            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;

            width: 100%;
        }

        .btn-more-info:hover {
            background: #1a1a1a;
            color: #fff;
        }

        .btn-more-info .arrow {
            transition: transform 0.3s;
            font-size: 9px;
        }

        .btn-more-info.active .arrow {
            transform: rotate(180deg);
        }

        /* =========================================================
           CONTACT INFO
        ========================================================= */

        .team-contact {
            max-height: 0;
            overflow: hidden;

            transition:
                max-height 0.5s cubic-bezier(0.16, 1, 0.3, 1),
                padding 0.3s ease,
                margin 0.3s ease;
        }

        .team-contact.open {
            max-height: 300px;
            border-top: 1px solid #f0f0f0;
            padding-top: 12px;
            margin-top: 10px;
        }

        .contact-row {
            display: flex;
            align-items: center;
            justify-content: center;

            gap: 5px;

            font-size: 11px;
            color: #666;
            text-decoration: none;

            padding: 4px 0;

            transition: color 0.3s;
        }

        .contact-row:hover {
            color: #1a1a1a;
        }

        .contact-row .icon {
            font-size: 13px;
        }

        .contact-row .label {
            font-weight: 700;
            color: #999;
        }

        .contact-row .value {
            direction: ltr;
            font-size: 10px;
            word-break: break-word;
        }

        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (max-width: 992px) {

            .team-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }

        }

        @media (max-width: 768px) {

            .team-section {
                padding: 40px 10px;
            }

            .team-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .team-name {
                font-size: 13px;
            }

            .team-role {
                font-size: 10px;
                padding: 3px 10px;
            }

            .team-bio {
                font-size: 11px;
                -webkit-line-clamp: 2;
            }

            .btn-more-info {
                font-size: 10px;
                padding: 7px 12px;
            }

            .contact-row {
                font-size: 10px;
            }

            .contact-row .value {
                font-size: 9px;
            }

        }

        @media (max-width: 400px) {

            .team-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }

            .team-info {
                padding: 12px 10px;
            }

            .team-name {
                font-size: 12px;
            }

        }
    </style>
</head>

<body class="page">

<div id="header-placeholder"></div>

<main class="main">

    <!-- =========================================================
         HERO
    ========================================================== -->

    <section class="about-hero">
        <div class="container">

            <h1 class="about-hero__title">
                درباره مشاوران افق دانش ثریا
            </h1>

            <p class="about-hero__subtitle">
                همراه شما در خلق فضاهای ایمن، مدرن و پایدار با بیش از یک دهه تجربه مهندسی
            </p>

        </div>
    </section>


    <!-- =========================================================
         ABOUT STORY
    ========================================================== -->

    <section class="about-story">

        <div class="container">

            <div class="about-story__grid">

                <div class="about-story__content">

                    <span class="about-story__badge">
                        داستان شکل‌گیری
                    </span>

                    <h2 class="about-story__heading">
                        نگاهی به مسیر و آرمان‌های ما
                    </h2>

                    <p class="about-story__text">
                        شرکت مشاوران افق دانش ثریا از سال ۱۳۹۴ با گرد هم آمدن جمعی از متخصصان جوان، پرانرژی و باتجربه صنعت ساختمان فعالیت خود را آغاز کرد. هدف ما از روز اول، ارتقای کیفیت ساخت‌وساز و ارائه خدمات جامع مهندسی بر پایه دانش روز و استانداردهای ملی و بین‌المللی بوده است.
                    </p>

                    <p class="about-story__text">
                        ما با اخذ پروانه اشتغال به کار رسمی از وزارت راه و شهرسازی، پوشش کاملی از تمامی رشته‌های کلیدی ساختمانی شامل
                        <strong>
                            معماری، عمران، تأسیسات برقی و مکانیکی و مهندسی ترافیک
                        </strong>
                        را فراهم آورده‌ایم تا نیازی به مراجعه مالکان و کارفرمایان به مجموعه‌های متفرق نباشد.
                    </p>

                </div>


                <div class="about-story__image-box">

                    <div class="about-story__img-placeholder">

                        <img
                            src="../assets/team-prf.png"
                            alt="عکس تیمی"
                            class="about-story__img"
                            onerror="this.style.display='none'"
                        >

                    </div>

                </div>

            </div>

        </div>

    </section>


    <!-- =========================================================
         STATS
    ========================================================== -->

    <section class="about-stats">

        <div class="container">

            <div class="about-stats__grid">

                <div class="stat-card">
                    <span class="stat-card__number">+۱۱</span>
                    <span class="stat-card__label">
                        سال سابقه فعالیت
                    </span>
                </div>

                <div class="stat-card">
                    <span class="stat-card__number">+۲۵۰</span>
                    <span class="stat-card__label">
                        پروژه موفق شهری و ویلایی و صنعتی
                    </span>
                </div>

                <div class="stat-card">
                    <span class="stat-card__number">۴</span>
                    <span class="stat-card__label">
                        دیسیپلین کامل مهندسی
                    </span>
                </div>

                <div class="stat-card">
                    <span class="stat-card__number">٪۱۰۰</span>
                    <span class="stat-card__label">
                        تعهد به ضوابط و ایمنی
                    </span>
                </div>

            </div>

        </div>

    </section>


    <!-- =========================================================
         VALUES
    ========================================================== -->

    <section class="about-values">

        <div class="container">

            <h2 class="about-values__title">
                ارزش‌های محوری ما
            </h2>

            <div class="about-values__grid">

                <div class="value-card">

                    <div class="value-card__number">
                        ۰۱
                    </div>

                    <h3 class="value-card__title">
                        نوآوری در طراحی
                    </h3>

                    <p class="value-card__text">
                        تلفیق فرم و عملکرد در معماری مطابق با متدهای روز دنیا و هویت ایرانی.
                    </p>

                </div>


                <div class="value-card">

                    <div class="value-card__number">
                        ۰۲
                    </div>

                    <h3 class="value-card__title">
                        دقت و شفافیت
                    </h3>

                    <p class="value-card__text">
                        پاسخگویی مداوم به کارفرمایان و پایبندی کامل به محاسبات فنی و اقتصادی.
                    </p>

                </div>


                <div class="value-card">

                    <div class="value-card__number">
                        ۰۳
                    </div>

                    <h3 class="value-card__title">
                        بهینه‌سازی انرژی
                    </h3>

                    <p class="value-card__text">
                        طراحی هوشمندانه تأسیسات جهت کاهش مصرف انرژی و حفظ محیط زیست.
                    </p>

                </div>

            </div>

        </div>

    </section>


    <!-- =========================================================
         TEAM
    ========================================================== -->

    <section class="team-section">

        <div class="container">

            <div class="team-header">

                <span class="team-badge">
                    سرمایه‌های انسانی
                </span>

                <h2 class="team-title">
                    تیم مدیریتی و متخصصان ما
                </h2>

                <p class="team-subtitle">
                    جمع متخصصی از مهندسان و مدیران با‌تجربه که پروژه‌های شما را به واقعیت تبدیل می‌کنند.
                </p>

            </div>


            <div class="team-grid">

                <?php if (!empty($team_members)): ?>

                    <?php foreach ($team_members as $index => $member): ?>

                        <?php
                        $has_contact =
                            !empty($member['phone']) ||
                            !empty($member['email']) ||
                            !empty($member['instagram']) ||
                            !empty($member['linkedin']);

                        $member_name = htmlspecialchars(
                            $member['name'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        );

                        $member_role = htmlspecialchars(
                            $member['role'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        );

                        $member_bio = htmlspecialchars(
                            $member['bio'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        );

                        $member_photo = htmlspecialchars(
                            $member['photo'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        );

                        $member_phone = htmlspecialchars(
                            $member['phone'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        );

                        $member_email = htmlspecialchars(
                            $member['email'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        );

                        $member_instagram = htmlspecialchars(
                            $member['instagram'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        );

                        $member_linkedin = htmlspecialchars(
                            $member['linkedin'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        );
                        ?>


                        <div class="team-card">

                            <!-- عکس -->
                            <div class="team-photo">

                                <?php if (!empty($member_photo)): ?>

                                    <img
                                        src="../<?php echo $member_photo; ?>"
                                        alt="<?php echo $member_name; ?>"
                                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                    >

                                    <div
                                        class="team-photo-placeholder"
                                        style="display:none;"
                                    >
                                        👤
                                    </div>

                                <?php else: ?>

                                    <div class="team-photo-placeholder">
                                        👤
                                    </div>

                                <?php endif; ?>

                            </div>


                            <!-- اطلاعات -->
                            <div class="team-info">

                                <h3 class="team-name">
                                    <?php echo $member_name; ?>
                                </h3>


                                <?php if (!empty($member_role)): ?>

                                    <span class="team-role">
                                        <?php echo $member_role; ?>
                                    </span>

                                <?php endif; ?>


                                <?php if (!empty($member_bio)): ?>

                                    <p class="team-bio">
                                        <?php echo $member_bio; ?>
                                    </p>

                                <?php endif; ?>


                                <!-- اطلاعات تماس -->
                                <?php if ($has_contact): ?>

                                    <button
                                        type="button"
                                        class="btn-more-info"
                                        onclick="toggleContact(<?php echo $index; ?>, this)"
                                        aria-expanded="false"
                                        aria-controls="contact-<?php echo $index; ?>"
                                    >
                                        اطلاعات بیشتر
                                        <span class="arrow">▼</span>
                                    </button>


                                    <div
                                        class="team-contact"
                                        id="contact-<?php echo $index; ?>"
                                    >

                                        <?php if (!empty($member_phone)): ?>

                                            <a
                                                href="tel:<?php echo $member_phone; ?>"
                                                class="contact-row"
                                            >
                                                <span class="icon">📞</span>
                                                <span class="label">تلفن:</span>
                                                <span class="value">
                                                    <?php echo $member_phone; ?>
                                                </span>
                                            </a>

                                        <?php endif; ?>


                                        <?php if (!empty($member_email)): ?>

                                            <a
                                                href="mailto:<?php echo $member_email; ?>"
                                                class="contact-row"
                                            >
                                                <span class="icon">📧</span>
                                                <span class="label">ایمیل:</span>
                                                <span class="value">
                                                    <?php echo $member_email; ?>
                                                </span>
                                            </a>

                                        <?php endif; ?>


                                        <?php if (!empty($member_instagram)): ?>

                                            <a
                                                href="<?php echo $member_instagram; ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="contact-row"
                                            >

                                                <span class="icon">📷</span>

                                                <span class="label">
                                                    اینستاگرام:
                                                </span>

                                                <span class="value">

                                                    <?php
                                                    echo htmlspecialchars(
                                                        str_replace(
                                                            [
                                                                'https://',
                                                                'http://',
                                                                'www.',
                                                                'instagram.com/',
                                                                '@'
                                                            ],
                                                            '',
                                                            $member['instagram']
                                                        ),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    );
                                                    ?>

                                                </span>

                                            </a>

                                        <?php endif; ?>


                                        <?php if (!empty($member_linkedin)): ?>

                                            <a
                                                href="<?php echo $member_linkedin; ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="contact-row"
                                            >

                                                <span class="icon">💼</span>

                                                <span class="label">
                                                    لینکدین:
                                                </span>

                                                <span class="value">

                                                    <?php
                                                    echo htmlspecialchars(
                                                        str_replace(
                                                            [
                                                                'https://',
                                                                'http://',
                                                                'www.',
                                                                'linkedin.com/in/'
                                                            ],
                                                            '',
                                                            $member['linkedin']
                                                        ),
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    );
                                                    ?>

                                                </span>

                                            </a>

                                        <?php endif; ?>

                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <p style="text-align:center;grid-column:1/-1;color:#999;">
                        اطلاعات تیم به زودی اضافه می‌شود
                    </p>

                <?php endif; ?>

            </div>

        </div>

    </section>


    <!-- =========================================================
         CTA
    ========================================================== -->

    <section class="cta">

        <div class="cta__content">

            <h2 class="cta__title">
                مشتاق گفت‌وگو درباره پروژه شما هستیم
            </h2>

            <h3 class="cta__subtitle">
                جهت دریافت مشاوره تخصصی با مهندسان ما در ارتباط باشید
            </h3>

        </div>

        <a
            href="../contact"
            class="cta__action"
        >
            <span class="cta__btn-text">
                تماس با کارشناسان
            </span>
        </a>

    </section>

</main>


<div id="footer-placeholder"></div>


<!-- =========================================================
     TEAM CONTACT SCRIPT
========================================================== -->

<script>
function toggleContact(index, btn) {

    const contact = document.getElementById('contact-' + index);

    if (!contact) {
        return;
    }

    const isOpen = contact.classList.contains('open');

    if (isOpen) {

        contact.classList.remove('open');

        btn.classList.remove('active');

        btn.setAttribute('aria-expanded', 'false');

        btn.innerHTML =
            'اطلاعات بیشتر <span class="arrow">▼</span>';

    } else {

        contact.classList.add('open');

        btn.classList.add('active');

        btn.setAttribute('aria-expanded', 'true');

        btn.innerHTML =
            'بستن <span class="arrow">▲</span>';
    }
}
</script>


<!-- =========================================================
     MAIN JS
========================================================== -->

<script src="../main.js"></script>

<script>

    loadComponent(
        'header-placeholder',
        '../header.html'
    );

    loadComponent(
        'footer-placeholder',
        '../footer.html',
        updateWorkHours
    );

</script>

</body>
</html>