<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/logger.php';

$settings = get_settings();
$message_sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name']);
    $email = sanitize($_POST['email']);
    $phone = sanitize($_POST['phone']);
    $subject = sanitize($_POST['subject']);
    $message = sanitize($_POST['message']);
    
    if (empty($name) || empty($email) || empty($message)) {
        $error = 'لطفاً فیلدهای ضروری را پر کنید';
    } else {
        // پردازش فایل ضمیمه
        $attachment = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $max_size = 20 * 1024 * 1024; // 20 مگابایت
            $file_size = $_FILES['attachment']['size'];
            
            if ($file_size > $max_size) {
                $error = 'حجم فایل نباید بیشتر از ۲۰ مگابایت باشد';
            } else {
                $upload_dir = '../uploads/attachments/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_name = time() . '_' . basename($_FILES['attachment']['name']);
                $target_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_path)) {
                    $attachment = [
                        'name' => $_FILES['attachment']['name'],
                        'path' => 'uploads/attachments/' . $file_name,
                        'size' => round($file_size / 1024 / 1024, 2) . ' MB',
                        'type' => $_FILES['attachment']['type']
                    ];
                }
            }
        }
        
        if (empty($error)) {
            $messages = read_json('messages.json');
            
            $new_message = [
                'id' => 'msg_' . uniqid(),
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'subject' => $subject,
                'message' => $message,
                'attachment' => $attachment,
                'date' => date('Y-m-d H:i:s'),
                'is_read' => false,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ];
            
            $messages[] = $new_message;
            write_json('messages.json', $messages);
            add_log('new_message', "پیام جدید از {$name}");
            $message_sent = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تماس با ما | <?php echo $settings['site_name']; ?></title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .contact-section {
            padding: 60px 20px;
            background: var(--bg-color);
        }
        
        .contact-grid {
            display: grid;
            grid-template-columns: 1fr 1.2fr;
            gap: 35px;
            max-width: 1100px;
            margin: 0 auto;
        }
        
        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .contact-info-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
        }
        
        .contact-info-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.06);
        }
        
        .contact-info-icon {
            width: 50px;
            height: 50px;
            background: var(--section-alt-bg);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }
        
        .contact-info-text strong {
            display: block;
            font-size: 14px;
            margin-bottom: 4px;
        }
        
        .contact-info-text span {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .contact-form {
            background: var(--card-bg);
            padding: 30px;
            border-radius: 20px;
            border: 1px solid var(--border-color);
            box-shadow: 0 5px 30px rgba(0,0,0,0.03);
        }
        
        .contact-form h2 {
            font-size: 22px;
            font-weight: 900;
            margin-bottom: 25px;
            text-align: center;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        
        .form-group label .required {
            color: #ff4757;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-family: inherit;
            font-size: 13px;
            transition: all 0.3s ease;
            background: #fafafa;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-color);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(0,0,0,0.05);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }
        
        .file-upload-wrapper {
            position: relative;
        }
        
        .file-upload-area {
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #fafafa;
        }
        
        .file-upload-area:hover {
            border-color: var(--accent-color);
            background: #f5f5f5;
        }
        
        .file-upload-area .file-icon {
            font-size: 30px;
            margin-bottom: 8px;
        }
        
        .file-upload-area p {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .file-upload-area .file-limit {
            font-size: 10px;
            color: #999;
            margin-top: 5px;
        }
        
        .file-name-display {
            display: none;
            margin-top: 10px;
            padding: 10px;
            background: #e8f5e9;
            border-radius: 8px;
            font-size: 12px;
            color: #2e7d32;
        }
        
        .submit-btn {
            width: 100%;
            padding: 13px;
            background: var(--accent-color);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .submit-btn:hover {
            background: var(--accent-hover);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }
        
        .success-message {
            text-align: center;
            padding: 20px;
            background: #e8f5e9;
            border-radius: 12px;
            color: #2e7d32;
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        .error-message {
            text-align: center;
            padding: 15px;
            background: #ffebee;
            border-radius: 12px;
            color: #c62828;
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        @media screen and (max-width: 768px) {
            .contact-grid {
                grid-template-columns: 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
        }
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
                <!-- اطلاعات تماس -->
                <div class="contact-info">
                    <h2 class="services__section-title">اطلاعات تماس</h2>
                    
                    <div class="contact-info-card">
                        <div class="contact-info-icon">📞</div>
                        <div class="contact-info-text">
                            <strong>تلفن‌های تماس</strong>
                            <span><?php echo $settings['phone_1']; ?></span>
                            <span><?php echo $settings['phone_2']; ?></span>
                        </div>
                    </div>
                    
                    <div class="contact-info-card">
                        <div class="contact-info-icon">📧</div>
                        <div class="contact-info-text">
                            <strong>ایمیل</strong>
                            <span><?php echo $settings['email']; ?></span>
                        </div>
                    </div>
                    
                    <div class="contact-info-card">
                        <div class="contact-info-icon">📍</div>
                        <div class="contact-info-text">
                            <strong>آدرس</strong>
                            <span><?php echo $settings['address']; ?></span>
                        </div>
                    </div>
                    
                    <div class="contact-info-card">
                        <div class="contact-info-icon">🕐</div>
                        <div class="contact-info-text">
                            <strong>ساعات کاری</strong>
                            <span><?php echo $settings['working_hours'] ?? 'شنبه تا پنجشنبه ۸ صبح تا ۶ عصر'; ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- فرم تماس -->
                <div class="contact-form">
                    <h2>ارسال پیام</h2>
                    
                    <?php if ($message_sent): ?>
                        <div class="success-message">
                            ✅ پیام شما با موفقیت ارسال شد. به زودی با شما تماس می‌گیریم.
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="error-message">
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-row">
                            <div class="form-group">
                                <label>نام و نام خانوادگی <span class="required">*</span></label>
                                <input type="text" name="name" required>
                            </div>
                            
                            <div class="form-group">
                                <label>ایمیل <span class="required">*</span></label>
                                <input type="email" name="email" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>شماره تماس</label>
                                <input type="text" name="phone">
                            </div>
                            
                            <div class="form-group">
                                <label>موضوع</label>
                                <input type="text" name="subject">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>پیام شما <span class="required">*</span></label>
                            <textarea name="message" required></textarea>
                        </div>
                        
                        <!-- آپلود فایل -->
                        <div class="form-group">
                            <label>فایل ضمیمه (اختیاری)</label>
                            <div class="file-upload-wrapper">
                                <div class="file-upload-area" onclick="document.getElementById('attachment').click()">
                                    <div class="file-icon">📎</div>
                                    <p>برای آپلود فایل کلیک کنید</p>
                                    <p class="file-limit">حداکثر حجم: ۲۰ مگابایت | فرمت‌های مجاز: PDF, JPG, PNG, ZIP</p>
                                </div>
                                <input type="file" id="attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.zip,.rar,.doc,.docx" style="display:none;" onchange="showFileName(this)">
                                <div class="file-name-display" id="fileNameDisplay"></div>
                            </div>
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
function showFileName(input) {
    const display = document.getElementById('fileNameDisplay');
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const sizeMB = (file.size / 1024 / 1024).toFixed(2);
        
        if (file.size > 20 * 1024 * 1024) {
            display.textContent = '❌ حجم فایل بیشتر از ۲۰ مگابایت است!';
            display.style.background = '#ffebee';
            display.style.color = '#c62828';
            display.style.display = 'block';
            input.value = '';
        } else {
            display.textContent = '✅ ' + file.name + ' (' + sizeMB + ' MB)';
            display.style.background = '#e8f5e9';
            display.style.color = '#2e7d32';
            display.style.display = 'block';
        }
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