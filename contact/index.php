<?php
/**
 * فرم تماس با ما — MySQL (ContactMessages::create)
 * دیگر JSON نمی‌نویسد
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/logger.php';
require_once dirname(__DIR__) . '/includes/automation.php';

$settings = get_settings();
$message_sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $msg_body = trim((string)($_POST['message'] ?? ''));

    if ($name === '' || $email === '' || $msg_body === '') {
        $error = 'لطفاً فیلدهای ضروری را پر کنید';
    } else {
        // فایل ضمیمه
        $attachment = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $max_size = 20 * 1024 * 1024;
            if ($_FILES['attachment']['size'] > $max_size) {
                $error = 'حجم فایل نباید بیشتر از ۲۰ مگابایت باشد';
            } else {
                $upload_dir = dirname(__DIR__) . '/uploads/attachments/';
                if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
                $safe_name = time() . '_' . bin2hex(random_bytes(4)) . '_' . basename($_FILES['attachment']['name']);
                $target = $upload_dir . $safe_name;
                if (@move_uploaded_file($_FILES['attachment']['tmp_name'], $target)) {
                    $attachment = [
                        'name' => $_FILES['attachment']['name'],
                        'path' => 'uploads/attachments/' . $safe_name,
                        'size' => round($_FILES['attachment']['size'] / 1024 / 1024, 2) . ' MB',
                        'type' => $_FILES['attachment']['type'] ?? 'application/octet-stream',
                    ];
                }
            }
        }

        if ($error === '') {
            try {
                ContactMessages::create([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'subject' => $subject,
                    'body' => $msg_body,
                    'attachment' => $attachment,
                ]);
                add_log('new_message', "پیام جدید از {$name} (MySQL)");
                try {
                    Automation::fire('client.message', [
                        'event' => 'client.message',
                        'name' => $name,
                        'email' => $email,
                        'subject' => $subject,
                    ]);
                } catch (Throwable $e) {}

                $message_sent = true;
            } catch (Throwable $e) {
                error_log('Contact create failed: ' . $e->getMessage());
                $error = 'خطا در ذخیره پیام، لطفاً بعداً تلاش کنید';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تماس با ما | <?php echo e($settings['site_name'] ?? 'اودسکو'); ?></title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .contact-section{padding:60px 20px;background:var(--bg-color)}
        .contact-grid{display:grid;grid-template-columns:1fr 1.2fr;gap:35px;max-width:1100px;margin:0 auto}
        .contact-info{display:flex;flex-direction:column;gap:16px}
        .contact-info-card{background:var(--card-bg);padding:18px 20px;border-radius:16px;border:1px solid var(--border-color);display:flex;align-items:center;gap:14px;transition:.25s}
        .contact-info-card:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(0,0,0,.06)}
        .contact-info-icon{width:48px;height:48px;background:var(--section-alt-bg);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
        .contact-info-text strong{display:block;font-size:13px;margin-bottom:3px}
        .contact-info-text span{font-size:12px;color:var(--text-muted);display:block;line-height:1.7}
        .contact-form{background:var(--card-bg);padding:28px;border-radius:20px;border:1px solid var(--border-color);box-shadow:0 5px 30px rgba(0,0,0,.04)}
        .contact-form h2{font-size:20px;font-weight:900;margin-bottom:20px;text-align:center}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .form-group{margin-bottom:14px}
        .form-group label{display:block;font-size:12px;font-weight:800;margin-bottom:6px}
        .form-group label .required{color:#ff4757}
        .form-group input,.form-group textarea{width:100%;padding:11px 13px;border:1px solid var(--border-color);border-radius:10px;font-family:inherit;font-size:13px;transition:.2s;background:#fafafa}
        .form-group input:focus,.form-group textarea:focus{outline:none;border-color:var(--accent-color);background:#fff;box-shadow:0 0 0 3px rgba(0,0,0,.05)}
        .form-group textarea{resize:vertical;min-height:120px}
        .file-upload-area{border:2px dashed var(--border-color);border-radius:12px;padding:18px;text-align:center;cursor:pointer;transition:.2s;background:#fafafa}
        .file-upload-area:hover{border-color:var(--accent-color);background:#f5f5f5}
        .file-name-display{display:none;margin-top:10px;padding:10px;border-radius:8px;font-size:12px}
        .submit-btn{width:100%;padding:12px;background:var(--accent-color);color:#fff;border:none;border-radius:10px;font-family:inherit;font-size:14px;font-weight:800;cursor:pointer;transition:.2s}
        .submit-btn:hover{background:var(--accent-hover);transform:translateY(-1px)}
        .success-message{text-align:center;padding:16px;background:#e8f5e9;border-radius:12px;color:#2e7d32;font-weight:800;margin-bottom:16px;font-size:13px}
        .error-message{text-align:center;padding:14px;background:#ffebee;border-radius:12px;color:#c62828;font-weight:800;margin-bottom:16px;font-size:13px}
        @media(max-width:768px){.contact-grid{grid-template-columns:1fr}.form-row{grid-template-columns:1fr}}
    </style>
</head>
<body class="page">
<div id="header-placeholder"></div>
<main class="main">
    <section class="about-hero">
        <div class="container">
            <h1 class="about-hero__title">تماس با ما</h1>
            <p class="about-hero__subtitle">برای دریافت مشاوره با ما در ارتباط باشید</p>
        </div>
    </section>
    <section class="contact-section">
        <div class="container">
            <div class="contact-grid">
                <div class="contact-info">
                    <h2 class="services__section-title">اطلاعات تماس</h2>
                    <div class="contact-info-card"><div class="contact-info-icon">📞</div><div class="contact-info-text"><strong>تلفن</strong><span><?php echo e($settings['phone_1'] ?? ''); ?></span><span><?php echo e($settings['phone_2'] ?? ''); ?></span></div></div>
                    <div class="contact-info-card"><div class="contact-info-icon">📧</div><div class="contact-info-text"><strong>ایمیل</strong><span dir="ltr" style="text-align:left"><?php echo e($settings['email'] ?? ''); ?></span></div></div>
                    <div class="contact-info-card"><div class="contact-info-icon">📍</div><div class="contact-info-text"><strong><?php echo e($settings['address_1_title'] ?? 'آدرس'); ?></strong><span><?php echo e($settings['address_1'] ?? $settings['address'] ?? ''); ?></span></div></div>
                    <?php if(!empty($settings['address_2'])): ?><div class="contact-info-card"><div class="contact-info-icon">📍</div><div class="contact-info-text"><strong><?php echo e($settings['address_2_title'] ?? 'شعبه دوم'); ?></strong><span><?php echo e($settings['address_2']); ?></span></div></div><?php endif; ?>
                    <div class="contact-info-card"><div class="contact-info-icon">🕐</div><div class="contact-info-text"><strong>ساعات کاری</strong><span><?php echo e($settings['working_hours'] ?? 'شنبه تا پنجشنبه ۸ تا ۱۸'); ?></span></div></div>
                </div>
                <div class="contact-form">
                    <h2>ارسال پیام — MySQL</h2>
                    <?php if($message_sent): ?><div class="success-message">✅ پیام شما با موفقیت ثبت شد. به زودی تماس می‌گیریم.</div><?php endif; ?>
                    <?php if($error !== ''): ?><div class="error-message"><?php echo e($error); ?></div><?php endif; ?>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-row">
                            <div class="form-group"><label>نام و نام خانوادگی <span class="required">*</span></label><input type="text" name="name" required></div>
                            <div class="form-group"><label>ایمیل <span class="required">*</span></label><input type="email" name="email" required dir="ltr" style="text-align:left"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>شماره تماس</label><input type="text" name="phone" dir="ltr" style="text-align:left"></div>
                            <div class="form-group"><label>موضوع</label><input type="text" name="subject"></div>
                        </div>
                        <div class="form-group"><label>پیام شما <span class="required">*</span></label><textarea name="message" required></textarea></div>
                        <div class="form-group">
                            <label>فایل ضمیمه (اختیاری)</label>
                            <div class="file-upload-area" onclick="document.getElementById('attachment').click()"><div style="font-size:28px">📎</div><p style="font-size:12px">کلیک برای آپلود</p><p style="font-size:10px;color:#999">حداکثر ۲۰ مگ — PDF, JPG, PNG, ZIP</p></div>
                            <input type="file" id="attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.zip,.rar,.doc,.docx" style="display:none" onchange="showFileName(this)">
                            <div class="file-name-display" id="fileNameDisplay"></div>
                        </div>
                        <button type="submit" class="submit-btn">ارسال پیام</button>
                    </form>
                </div>
            </div>
        </div>
    </section>
</main>
<div id="footer-placeholder"></div>
<script>
function showFileName(input){
  const d=document.getElementById('fileNameDisplay');
  if(input.files && input.files[0]){
    const f=input.files[0];
    const mb=(f.size/1024/1024).toFixed(2);
    if(f.size>20*1024*1024){d.textContent='❌ حجم بیشتر از ۲۰ مگ';d.style.background='#ffebee';d.style.color='#c62828';d.style.display='block';input.value='';}
    else{d.textContent='✅ '+f.name+' ('+mb+' MB)';d.style.background='#e8f5e9';d.style.color='#2e7d32';d.style.display='block';}
  }
}
</script>
<script src="../main.js"></script>
<script>loadComponent('header-placeholder','../header.html');loadComponent('footer-placeholder','../footer.html',updateWorkHours);</script>
</body>
</html>
