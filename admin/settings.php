<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/logger.php';

check_login();

$is_viewer = ($_SESSION['admin_role'] ?? '') === 'viewer';
$settings = get_settings();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($is_viewer) {
        $error = '⛔ شما فقط بیننده هستید و دسترسی به تغییرات ندارید!';
    } else {
        // آپلود favicon
        if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
            
            $file_ext = pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION);
            $allowed = ['png', 'jpg', 'jpeg', 'ico', 'svg', 'webp'];
            
            if (in_array(strtolower($file_ext), $allowed)) {
                $file_name = 'favicon_' . time() . '.' . $file_ext;
                if (move_uploaded_file($_FILES['favicon']['tmp_name'], $upload_dir . $file_name)) {
                    $settings['favicon'] = 'uploads/' . $file_name;
                }
            } else {
                $error = '❌ فرمت فایل مجاز نیست';
            }
        }
        
        // آپلود لوگو
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
            
            $file_ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $allowed = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
            
            if (in_array(strtolower($file_ext), $allowed)) {
                $file_name = 'logo_' . time() . '.' . $file_ext;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $file_name)) {
                    $settings['logo'] = 'uploads/' . $file_name;
                }
            }
        }
        
        // ذخیره تنظیمات
        $settings['site_name'] = sanitize($_POST['site_name']);
        $settings['site_description'] = sanitize($_POST['site_description']);
        $settings['phone_1'] = sanitize($_POST['phone_1']);
        $settings['phone_2'] = sanitize($_POST['phone_2']);
        $settings['email'] = sanitize($_POST['email']);
        $settings['working_hours'] = sanitize($_POST['working_hours']);
        
        // آدرس ۱
        $settings['address_1'] = sanitize($_POST['address_1']);
        $settings['address_1_title'] = sanitize($_POST['address_1_title'] ?? 'شعبه اصلی');
        $settings['address_1_map'] = sanitize($_POST['address_1_map'] ?? '');
        
        // آدرس ۲
        $settings['address_2'] = sanitize($_POST['address_2']);
        $settings['address_2_title'] = sanitize($_POST['address_2_title'] ?? 'شعبه دوم');
        $settings['address_2_map'] = sanitize($_POST['address_2_map'] ?? '');
        
        // شبکه‌های اجتماعی
        $settings['instagram'] = sanitize($_POST['instagram'] ?? '');
        $settings['telegram'] = sanitize($_POST['telegram'] ?? '');
        $settings['whatsapp'] = sanitize($_POST['whatsapp'] ?? '');
        $settings['linkedin'] = sanitize($_POST['linkedin'] ?? '');
        
        // سئو
        $settings['meta_keywords'] = sanitize($_POST['meta_keywords'] ?? '');
        $settings['meta_description'] = sanitize($_POST['meta_description'] ?? '');

        // پیام‌رسان / حضور و غیاب / زمان‌بندی
        if (isset($_POST['section']) && $_POST['section'] === 'ops') {
            $settings['msg_retention_days'] = (int)($_POST['msg_retention_days'] ?? 0);
            $settings['msg_max_file_mb']    = (int)($_POST['msg_max_file_mb'] ?? 32);
            $settings['msg_allow_client']   = isset($_POST['msg_allow_client']) ? 1 : 0;
            $settings['msg_purge_on_read']  = isset($_POST['msg_purge_on_read']) ? 1 : 0;
            $settings['messenger_enabled']  = isset($_POST['messenger_enabled']) ? 1 : 0;

            $settings['att_work_start']   = sanitize($_POST['att_work_start'] ?? '08:00');
            $settings['att_work_end']     = sanitize($_POST['att_work_end'] ?? '16:00');
            $settings['att_late_minutes'] = (int)($_POST['att_late_minutes'] ?? 15);
            $settings['att_qr_minutes']   = (int)($_POST['att_qr_minutes'] ?? 30);
            $settings['att_weekends']     = sanitize($_POST['att_weekends'] ?? '6');

            if (($settings['cron_key'] ?? '') === '' || !empty($_POST['rotate_cron_key'])) {
                $settings['cron_key'] = bin2hex(random_bytes(16));
            }
        }

        write_json('settings.json', $settings);
        add_log('update_settings', 'تنظیمات سایت بروزرسانی شد');
        $message = '✅ تنظیمات ذخیره شد';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تنظیمات سایت | پنل مدیریت</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .viewer-banner {
            background: #fff8e1; border: 1px solid #ffa502; color: #e65100;
            padding: 15px 20px; border-radius: 12px; margin-bottom: 20px;
            font-size: 13px; font-weight: 700;
        }
        
        .settings-tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .tab-btn {
            padding: 10px 20px;
            border: none;
            background: #fff;
            border-radius: 10px;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.3s;
            border: 1px solid #e9ecef;
        }
        
        .tab-btn.active {
            background: #1a1a1a;
            color: #fff;
            border-color: #1a1a1a;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 15px;
        }
        
        .form-group.full {
            grid-column: 1 / -1;
        }
        
        .form-group label {
            font-size: 12px;
            font-weight: 700;
            color: #555;
        }
        
        .form-group input,
        .form-group textarea {
            padding: 10px 14px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            font-family: inherit;
            font-size: 13px;
            background: #fafafa;
            transition: all 0.3s;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #1a1a1a;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(0,0,0,0.05);
        }
        
        .form-group input:disabled,
        .form-group textarea:disabled {
            background: #f5f5f5;
            color: #999;
            cursor: not-allowed;
        }
        
        .upload-favicon-area {
            border: 2px dashed #ddd;
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #fafafa;
        }
        
        .upload-favicon-area:hover {
            border-color: #1a1a1a;
            background: #f5f5f5;
        }
        
        .favicon-preview {
            width: 64px;
            height: 64px;
            object-fit: cover;
            border-radius: 12px;
            margin: 15px auto 0;
            border: 2px solid #e0e0e0;
            display: none;
        }
        
        .btn-save {
            padding: 12px 30px;
            background: #1a1a1a;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-save:hover {
            background: #333;
            transform: translateY(-2px);
        }
        
        .section-title {
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        @media (max-width: 768px) {
            .form-grid-2 {
                grid-template-columns: 1fr;
            }
            .form-group.full {
                grid-column: auto;
            }
            .settings-tabs {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php include 'sidebar.php'; ?>
        
        <main class="main-content">
            <header class="top-bar">
                <h1>⚙️ تنظیمات سایت</h1>
                <?php if ($is_viewer): ?><span class="badge" style="background:#fff8e1;color:#e65100;">👁️ حالت مشاهده</span><?php endif; ?>
            </header>
            
            <?php if ($message): ?><div class="alert alert-success"><?php echo $message; ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
            <?php if ($is_viewer): ?><div class="viewer-banner">⛔ شما فقط بیننده هستید!</div><?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="section" value="ops">
                <!-- تب‌ها -->
                <div class="settings-tabs">
                    <button type="button" class="tab-btn active" onclick="showTab('general')">🏢 اطلاعات کلی</button>
                    <button type="button" class="tab-btn" onclick="showTab('address')">📍 آدرس‌ها</button>
                    <button type="button" class="tab-btn" onclick="showTab('social')">📱 شبکه‌های اجتماعی</button>
                    <button type="button" class="tab-btn" onclick="showTab('seo')">🔍 سئو</button>
                    <button type="button" class="tab-btn" onclick="showTab('appearance')">🎨 ظاهر سایت</button>
                </div>
                
                <!-- تب اطلاعات کلی -->
                <div class="tab-content active" id="tab-general">
                    <div class="card">
                        <div class="section-title">🏢 اطلاعات کلی</div>
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label>نام سایت *</label>
                                <input type="text" name="site_name" value="<?php echo $settings['site_name']; ?>" <?php echo $is_viewer ? 'disabled' : 'required'; ?>>
                            </div>
                            <div class="form-group">
                                <label>توضیحات سایت</label>
                                <input type="text" name="site_description" value="<?php echo $settings['site_description'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>تلفن ۱</label>
                                <input type="text" name="phone_1" value="<?php echo $settings['phone_1']; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>تلفن ۲</label>
                                <input type="text" name="phone_2" value="<?php echo $settings['phone_2']; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>ایمیل</label>
                                <input type="email" name="email" value="<?php echo $settings['email']; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>ساعات کاری</label>
                                <input type="text" name="working_hours" value="<?php echo $settings['working_hours'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- تب آدرس‌ها -->
                <div class="tab-content" id="tab-address">
                    <div class="card">
                        <div class="section-title">📍 شعبه اول</div>
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label>عنوان شعبه</label>
                                <input type="text" name="address_1_title" value="<?php echo $settings['address_1_title'] ?? 'شعبه اصلی'; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>آدرس</label>
                                <input type="text" name="address_1" value="<?php echo $settings['address_1'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group full">
                                <label>لینک نقشه (گوگل مپ)</label>
                                <input type="text" name="address_1_map" value="<?php echo $settings['address_1_map'] ?? ''; ?>" placeholder="https://maps.app.goo.gl/..." <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="section-title">📍 شعبه دوم</div>
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label>عنوان شعبه</label>
                                <input type="text" name="address_2_title" value="<?php echo $settings['address_2_title'] ?? 'شعبه دوم'; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>آدرس</label>
                                <input type="text" name="address_2" value="<?php echo $settings['address_2'] ?? ''; ?>" <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group full">
                                <label>لینک نقشه (گوگل مپ)</label>
                                <input type="text" name="address_2_map" value="<?php echo $settings['address_2_map'] ?? ''; ?>" placeholder="https://maps.app.goo.gl/..." <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- تب شبکه‌های اجتماعی -->
                <div class="tab-content" id="tab-social">
                    <div class="card">
                        <div class="section-title">📱 شبکه‌های اجتماعی</div>
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label>اینستاگرام</label>
                                <input type="text" name="instagram" value="<?php echo $settings['instagram'] ?? ''; ?>" placeholder="https://instagram.com/..." <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>تلگرام</label>
                                <input type="text" name="telegram" value="<?php echo $settings['telegram'] ?? ''; ?>" placeholder="https://t.me/..." <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>واتساپ</label>
                                <input type="text" name="whatsapp" value="<?php echo $settings['whatsapp'] ?? ''; ?>" placeholder="https://wa.me/..." <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                            <div class="form-group">
                                <label>لینکدین</label>
                                <input type="text" name="linkedin" value="<?php echo $settings['linkedin'] ?? ''; ?>" placeholder="https://linkedin.com/..." <?php echo $is_viewer ? 'disabled' : ''; ?>>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- تب سئو -->
                <div class="tab-content" id="tab-seo">
                    <div class="card">
                        <div class="section-title">🔍 تنظیمات سئو</div>
                        <div class="form-grid-2">
                            <div class="form-group full">
                                <label>کلمات کلیدی (با کاما جدا کنید)</label>
                                <textarea name="meta_keywords" rows="3" <?php echo $is_viewer ? 'disabled' : ''; ?>><?php echo $settings['meta_keywords'] ?? ''; ?></textarea>
                            </div>
                            <div class="form-group full">
                                <label>توضیحات متا</label>
                                <textarea name="meta_description" rows="3" <?php echo $is_viewer ? 'disabled' : ''; ?>><?php echo $settings['meta_description'] ?? ''; ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- تب ظاهر -->
                <div class="tab-content" id="tab-appearance">
                    <div class="card">
                        <div class="section-title">🎨 ظاهر سایت</div>
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label>عکس تب مرورگر (Favicon):</label>
                                <?php if (!$is_viewer): ?>
                                <div class="upload-favicon-area" onclick="document.getElementById('faviconInput').click()">
                                    <span style="font-size:30px;">🖼️</span>
                                    <p style="font-size:12px;">کلیک کنید و عکس را انتخاب کنید</p>
                                    <p style="font-size:10px;color:#999;">PNG, JPG, ICO - حداکثر ۵۱۲×۵۱۲</p>
                                    <?php if (!empty($settings['favicon'])): ?>
                                        <img src="../<?php echo $settings['favicon']; ?>" class="favicon-preview" id="faviconPreview" style="display:block;">
                                    <?php else: ?>
                                        <img src="" class="favicon-preview" id="faviconPreview">
                                    <?php endif; ?>
                                </div>
                                <input type="file" id="faviconInput" name="favicon" accept=".png,.jpg,.jpeg,.ico,.svg,.webp" style="display:none;" onchange="previewFavicon(this)">
                                <?php else: ?>
                                    <?php if (!empty($settings['favicon'])): ?>
                                        <img src="../<?php echo $settings['favicon']; ?>" style="width:64px;height:64px;object-fit:cover;border-radius:12px;">
                                    <?php else: ?>
                                        <p>عکسی تنظیم نشده</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-group">
                                <label>لوگوی سایت:</label>
                                <?php if (!$is_viewer): ?>
                                <div class="upload-favicon-area" onclick="document.getElementById('logoInput').click()">
                                    <span style="font-size:30px;">🏢</span>
                                    <p style="font-size:12px;">کلیک کنید و لوگو را انتخاب کنید</p>
                                    <?php if (!empty($settings['logo'])): ?>
                                        <img src="../<?php echo $settings['logo']; ?>" class="favicon-preview" id="logoPreview" style="display:block;">
                                    <?php else: ?>
                                        <img src="" class="favicon-preview" id="logoPreview">
                                    <?php endif; ?>
                                </div>
                                <input type="file" id="logoInput" name="logo" accept=".png,.jpg,.jpeg,.svg,.webp" style="display:none;" onchange="previewLogo(this)">
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (!$is_viewer): ?>
                <!-- ============ پیام‌رسان ============ -->
                <div class="form-section" style="margin-top:28px">
                    <div class="form-section__title">💬 پیام‌رسان داخلی</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>نگهداری تاریخچه روی سرور (روز) — ۰ یعنی نامحدود</label>
                            <input type="number" name="msg_retention_days" min="0" max="3650"
                                   value="<?php echo (int)($settings['msg_retention_days'] ?? 0); ?>">
                            <small style="font-size:11px;color:#888">پیام‌ها روی گوشی/کامپیوتر کاربر می‌مانند؛ این فقط پاک‌سازی سمت سرور است.</small>
                        </div>
                        <div class="form-group">
                            <label>حداکثر حجم فایل پیوست (مگابایت)</label>
                            <input type="number" name="msg_max_file_mb" min="1" max="512"
                                   value="<?php echo (int)($settings['msg_max_file_mb'] ?? 32); ?>">
                        </div>
                        <div class="form-group full">
                            <label style="display:flex;gap:8px;align-items:center;font-weight:400">
                                <input type="checkbox" name="messenger_enabled" value="1"
                                       <?php echo !isset($settings['messenger_enabled']) || $settings['messenger_enabled'] ? 'checked' : ''; ?>>
                                پیام‌رسان فعال باشد
                            </label>
                            <label style="display:flex;gap:8px;align-items:center;font-weight:400">
                                <input type="checkbox" name="msg_allow_client" value="1"
                                       <?php echo !empty($settings['msg_allow_client']) ? 'checked' : ''; ?>>
                                کاربران نقش «کارفرما» هم بتوانند وارد پیام‌رسان شوند
                            </label>
                            <label style="display:flex;gap:8px;align-items:center;font-weight:400">
                                <input type="checkbox" name="msg_purge_on_read" value="1"
                                       <?php echo !empty($settings['msg_purge_on_read']) ? 'checked' : ''; ?>>
                                پیام‌های خصوصی خوانده‌شده زودتر از سرور پاک شوند
                            </label>
                        </div>
                    </div>
                </div>

                <!-- ============ حضور و غیاب ============ -->
                <div class="form-section" style="margin-top:28px">
                    <div class="form-section__title">🕐 حضور و غیاب</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>ساعت شروع کار</label>
                            <input type="time" name="att_work_start"
                                   value="<?php echo e((string)($settings['att_work_start'] ?? '08:00')); ?>">
                        </div>
                        <div class="form-group">
                            <label>ساعت پایان کار</label>
                            <input type="time" name="att_work_end"
                                   value="<?php echo e((string)($settings['att_work_end'] ?? '16:00')); ?>">
                        </div>
                        <div class="form-group">
                            <label>آستانه تاخیر (دقیقه)</label>
                            <input type="number" name="att_late_minutes" min="0" max="240"
                                   value="<?php echo (int)($settings['att_late_minutes'] ?? 15); ?>">
                        </div>
                        <div class="form-group">
                            <label>اعتبار QR (دقیقه)</label>
                            <input type="number" name="att_qr_minutes" min="1" max="720"
                                   value="<?php echo (int)($settings['att_qr_minutes'] ?? 30); ?>">
                        </div>
                        <div class="form-group">
                            <label>روزهای تعطیل هفتگی (۰=یکشنبه … ۶=جمعه، با کاما)</label>
                            <input type="text" name="att_weekends"
                                   value="<?php echo e((string)($settings['att_weekends'] ?? '6')); ?>"
                                   placeholder="6 یا 5,6">
                        </div>
                    </div>
                </div>

                <!-- ============ زمان‌بندی ============ -->
                <div class="form-section" style="margin-top:28px">
                    <div class="form-section__title">⏱ زمان‌بندی (Cron)</div>
                    <div class="form-grid">
                        <div class="form-group full">
                            <label>کلید امن اجرای زمان‌بندی</label>
                            <input type="text" readonly value="<?php echo e((string)($settings['cron_key'] ?? '')); ?>"
                                   style="direction:ltr;text-align:left">
                            <small style="font-size:11px;color:#888">
                                آدرس اجرای خودکار (روزی یک‌بار در کنترل‌پنل هاست تنظیم کنید):<br>
                                <code style="direction:ltr;display:inline-block;font-size:10.5px;word-break:break-all">
                                    <?php
                                    $host = isset($_SERVER['HTTP_HOST']) ? (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : '';
                                    $base = $host . rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/settings.php'), 2)), '/');
                                    echo e($base . '/tools/cron.php?key=' . (string)($settings['cron_key'] ?? ''));
                                    ?>
                                </code>
                            </small>
                        </div>
                        <div class="form-group full">
                            <label style="display:flex;gap:8px;align-items:center;font-weight:400">
                                <input type="checkbox" name="rotate_cron_key" value="1">
                                ساخت کلید جدید (کلید فعلی باطل می‌شود)
                            </label>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-save">💾 ذخیره همه تنظیمات</button>
                <?php endif; ?>
            </form>
        </main>
    </div>
    
    <script>
    function showTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('tab-' + tabId).classList.add('active');
        event.target.classList.add('active');
    }
    
    function previewFavicon(input) {
        const preview = document.getElementById('faviconPreview');
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) { preview.src = e.target.result; preview.style.display = 'block'; };
            reader.readAsDataURL(input.files[0]);
        }
    }
    
    function previewLogo(input) {
        const preview = document.getElementById('logoPreview');
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) { preview.src = e.target.result; preview.style.display = 'block'; };
            reader.readAsDataURL(input.files[0]);
        }
    }
    </script>
</body>
</html>