<?php
/**
 * تنظیمات سایت — MySQL (Settings::)
 * دیزاین بازطراحی شده، تب‌ها درست کار می‌کنند
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/logger.php';

check_login();

$is_viewer = is_viewer();
$settings = Settings::all();
$message = '';
$error = '';

if (isset($_SESSION['settings_msg'])) {
    $message = $_SESSION['settings_msg'];
    unset($_SESSION['settings_msg']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    if ($is_viewer) {
        $error = '⛔ شما فقط بیننده هستید!';
    } else {
        $upload_dir = dirname(__DIR__) . '/uploads/';
        if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

        // favicon
        if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION));
            $allowed = ['png','jpg','jpeg','ico','svg','webp'];
            if (in_array($ext, $allowed, true)) {
                $fname = 'favicon_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                if (@move_uploaded_file($_FILES['favicon']['tmp_name'], $upload_dir . $fname)) {
                    Settings::set('favicon', 'uploads/' . $fname);
                }
            } else {
                $error = 'فرمت favicon مجاز نیست';
            }
        }

        // logo
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowed = ['png','jpg','jpeg','svg','webp'];
            if (in_array($ext, $allowed, true)) {
                $fname = 'logo_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                if (@move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $fname)) {
                    Settings::set('logo', 'uploads/' . $fname);
                }
            }
        }

        $fields = [
            'site_name' => trim((string)($_POST['site_name'] ?? '')),
            'site_description' => trim((string)($_POST['site_description'] ?? '')),
            'phone_1' => trim((string)($_POST['phone_1'] ?? '')),
            'phone_2' => trim((string)($_POST['phone_2'] ?? '')),
            'email' => trim((string)($_POST['email'] ?? '')),
            'working_hours' => trim((string)($_POST['working_hours'] ?? '')),
            'address_1' => trim((string)($_POST['address_1'] ?? '')),
            'address_1_title' => trim((string)($_POST['address_1_title'] ?? 'شعبه اصلی')),
            'address_1_map' => trim((string)($_POST['address_1_map'] ?? '')),
            'address_2' => trim((string)($_POST['address_2'] ?? '')),
            'address_2_title' => trim((string)($_POST['address_2_title'] ?? 'شعبه دوم')),
            'address_2_map' => trim((string)($_POST['address_2_map'] ?? '')),
            'instagram' => trim((string)($_POST['instagram'] ?? '')),
            'telegram' => trim((string)($_POST['telegram'] ?? '')),
            'whatsapp' => trim((string)($_POST['whatsapp'] ?? '')),
            'linkedin' => trim((string)($_POST['linkedin'] ?? '')),
            'meta_keywords' => trim((string)($_POST['meta_keywords'] ?? '')),
            'meta_description' => trim((string)($_POST['meta_description'] ?? '')),
            // messenger
            'msg_retention_days' => (string)max(0, (int)($_POST['msg_retention_days'] ?? 0)),
            'msg_max_file_mb' => (string)max(1, (int)($_POST['msg_max_file_mb'] ?? 32)),
            'msg_allow_client' => !empty($_POST['msg_allow_client']) ? '1' : '0',
            'msg_purge_on_read' => !empty($_POST['msg_purge_on_read']) ? '1' : '0',
            'messenger_enabled' => !empty($_POST['messenger_enabled']) ? '1' : '0',
            // attendance
            'att_work_start' => trim((string)($_POST['att_work_start'] ?? '08:00')),
            'att_work_end' => trim((string)($_POST['att_work_end'] ?? '16:00')),
            'att_late_minutes' => (string)max(0, (int)($_POST['att_late_minutes'] ?? 15)),
            'att_qr_minutes' => (string)max(1, (int)($_POST['att_qr_minutes'] ?? 30)),
            'att_weekends' => trim((string)($_POST['att_weekends'] ?? '6')),
        ];

        if ($fields['site_name'] === '') {
            $error = 'نام سایت الزامی است';
        } else {
            Settings::setMany($fields);

            // cron key
            $current_key = (string)(Settings::get('cron_key') ?? '');
            if ($current_key === '' || !empty($_POST['rotate_cron_key'])) {
                Settings::set('cron_key', bin2hex(random_bytes(16)));
            }

            add_log('update_settings', 'تنظیمات سایت بروزرسانی شد (MySQL)');
            $_SESSION['settings_msg'] = '✅ تنظیمات ذخیره شد';
            header('Location: settings.php?saved=1');
            exit;
        }

        $settings = Settings::all();
    }
}

$settings = Settings::all();
$cron_key = (string)($settings['cron_key'] ?? '');

$host = $_SERVER['HTTP_HOST'] ?? 'odsco.ir';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'https://';
$basePath = rtrim(str_replace('\\','/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/settings.php'), 2)), '/');
$cron_url = $scheme . $host . $basePath . '/tools/cron.php?key=' . $cron_key;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تنظیمات | پنل مدیریت</title>
<link rel="stylesheet" href="admin-style.css">
<style>
.viewer-banner{background:#fff8e1;border:1px solid #ffa502;color:#e65100;padding:14px 18px;border-radius:12px;margin-bottom:18px;font-size:13px;font-weight:800}
.settings-wrap{display:flex;gap:20px;align-items:flex-start}
.settings-nav{width:230px;flex-shrink:0;background:#fff;border:1px solid #eee;border-radius:16px;padding:10px;position:sticky;top:20px}
.settings-nav .nav-title{font-size:12px;font-weight:900;color:#999;margin:10px 8px 8px;letter-spacing:0.5px}
.nav-item{display:flex;align-items:center;gap:10px;width:100%;padding:11px 12px;border-radius:10px;border:1px solid transparent;background:transparent;cursor:pointer;font-family:inherit;font-size:13px;font-weight:700;color:#555;transition:all .2s;text-align:right}
.nav-item:hover{background:#f7f7f7;border-color:#eee}
.nav-item.active{background:#1a1a1a;color:#fff;border-color:#1a1a1a;box-shadow:0 4px 14px rgba(0,0,0,.15)}
.nav-item .ico{font-size:16px;width:22px;text-align:center}
.settings-content{flex:1;min-width:0}
.card{background:#fff;border:1px solid #eee;border-radius:18px;padding:22px 22px;box-shadow:0 6px 24px rgba(0,0,0,.04);margin-bottom:18px}
.card h3{font-size:15px;font-weight:900;margin:0 0 16px;padding-bottom:12px;border-bottom:2px solid #f5f5f5;display:flex;align-items:center;gap:8px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-grid .full{grid-column:1 / -1}
.fg{display:flex;flex-direction:column;gap:6px}
.fg label{font-size:12px;font-weight:800;color:#444}
.fg input,.fg textarea,.fg select{padding:11px 13px;border:1px solid #e6e6e6;border-radius:11px;background:#fafafa;font-family:inherit;font-size:13px;transition:.2s}
.fg input:focus,.fg textarea:focus,.fg select:focus{outline:none;border-color:#1a1a1a;background:#fff;box-shadow:0 0 0 3px rgba(0,0,0,.06)}
.fg small{font-size:11px;color:#888;line-height:1.6}
.upload-box{border:2px dashed #ddd;border-radius:14px;padding:22px;text-align:center;background:#fafafa;cursor:pointer;transition:.2s}
.upload-box:hover{border-color:#1a1a1a;background:#f5f5f5}
.upload-box img{width:72px;height:72px;object-fit:cover;border-radius:12px;border:2px solid #eee;margin:12px auto 0;display:block}
.switch{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#fafafa;border:1px solid #eee;border-radius:11px;font-size:13px}
.switch input{width:auto}
.tab-panel{display:none}
.tab-panel.active{display:block;animation:fadeIn .25s}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
.btn-save{padding:13px 28px;background:#1a1a1a;color:#fff;border:none;border-radius:12px;font-family:inherit;font-size:14px;font-weight:900;cursor:pointer;box-shadow:0 8px 20px rgba(0,0,0,.15);transition:.2s}
.btn-save:hover{background:#000;transform:translateY(-1px)}
.cron-code{direction:ltr;text-align:left;background:#0f0f0f;color:#9eff9e;padding:12px 14px;border-radius:10px;font-family:ui-monospace,monospace;font-size:11.5px;word-break:break-all;display:block;margin-top:8px}
@media(max-width:900px){.settings-wrap{flex-direction:column}.settings-nav{width:100%;position:static;display:flex;flex-wrap:wrap;gap:6px}.settings-nav .nav-title{display:none}.nav-item{width:auto;flex:1 1 140px}.form-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="admin-layout">
<?php include 'sidebar.php'; ?>
<main class="main-content">
<header class="top-bar"><h1>⚙️ تنظیمات سایت</h1><?php if($is_viewer): ?><span class="badge" style="background:#fff8e1;color:#e65100">👁️ مشاهده</span><?php endif; ?></header>
<?php if($message): ?><div class="alert alert-success"><?php echo e($message); ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
<?php if($is_viewer): ?><div class="viewer-banner">⛔ شما فقط بیننده هستید</div><?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="settingsForm">
<?php echo csrf_field(); ?>
<div class="settings-wrap">
  <nav class="settings-nav">
    <div class="nav-title">بخش‌ها</div>
    <button type="button" class="nav-item active" data-tab="general"><span class="ico">🏢</span> اطلاعات کلی</button>
    <button type="button" class="nav-item" data-tab="address"><span class="ico">📍</span> آدرس‌ها</button>
    <button type="button" class="nav-item" data-tab="social"><span class="ico">📱</span> شبکه‌ها</button>
    <button type="button" class="nav-item" data-tab="seo"><span class="ico">🔍</span> سئو</button>
    <button type="button" class="nav-item" data-tab="appearance"><span class="ico">🎨</span> ظاهر</button>
    <button type="button" class="nav-item" data-tab="messenger"><span class="ico">💬</span> پیام‌رسان</button>
    <button type="button" class="nav-item" data-tab="attendance"><span class="ico">🕐</span> حضور و غیاب</button>
    <button type="button" class="nav-item" data-tab="cron"><span class="ico">⏱</span> زمان‌بندی</button>
  </nav>

  <div class="settings-content">
    <!-- general -->
    <div class="tab-panel active" id="panel-general">
      <div class="card">
        <h3>🏢 اطلاعات کلی — MySQL</h3>
        <div class="form-grid">
          <div class="fg"><label>نام سایت *</label><input type="text" name="site_name" value="<?php echo e($settings['site_name'] ?? ''); ?>" <?php echo $is_viewer?'disabled':''; ?> required></div>
          <div class="fg"><label>توضیح کوتاه</label><input type="text" name="site_description" value="<?php echo e($settings['site_description'] ?? ''); ?>" <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg"><label>تلفن ۱</label><input type="text" name="phone_1" value="<?php echo e($settings['phone_1'] ?? ''); ?>" dir="ltr" style="text-align:left" <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg"><label>تلفن ۲</label><input type="text" name="phone_2" value="<?php echo e($settings['phone_2'] ?? ''); ?>" dir="ltr" style="text-align:left" <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg"><label>ایمیل</label><input type="email" name="email" value="<?php echo e($settings['email'] ?? ''); ?>" dir="ltr" style="text-align:left" <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg"><label>ساعات کاری</label><input type="text" name="working_hours" value="<?php echo e($settings['working_hours'] ?? ''); ?>" <?php echo $is_viewer?'disabled':''; ?>></div>
        </div>
      </div>
    </div>

    <!-- address -->
    <div class="tab-panel" id="panel-address">
      <div class="card">
        <h3>📍 شعبه اول</h3>
        <div class="form-grid">
          <div class="fg"><label>عنوان شعبه</label><input type="text" name="address_1_title" value="<?php echo e($settings['address_1_title'] ?? 'شعبه اصلی'); ?>" <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg"><label>آدرس</label><input type="text" name="address_1" value="<?php echo e($settings['address_1'] ?? ''); ?>" <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg full"><label>لینک نقشه</label><input type="text" name="address_1_map" value="<?php echo e($settings['address_1_map'] ?? ''); ?>" placeholder="https://maps.app.goo.gl/..." dir="ltr" style="text-align:left" <?php echo $is_viewer?'disabled':''; ?>></div>
        </div>
      </div>
      <div class="card">
        <h3>📍 شعبه دوم</h3>
        <div class="form-grid">
          <div class="fg"><label>عنوان شعبه</label><input type="text" name="address_2_title" value="<?php echo e($settings['address_2_title'] ?? 'شعبه دوم'); ?>" <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg"><label>آدرس</label><input type="text" name="address_2" value="<?php echo e($settings['address_2'] ?? ''); ?>" <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg full"><label>لینک نقشه</label><input type="text" name="address_2_map" value="<?php echo e($settings['address_2_map'] ?? ''); ?>" placeholder="https://maps.app.goo.gl/..." dir="ltr" style="text-align:left" <?php echo $is_viewer?'disabled':''; ?>></div>
        </div>
      </div>
    </div>

    <!-- social -->
    <div class="tab-panel" id="panel-social">
      <div class="card">
        <h3>📱 شبکه‌های اجتماعی</h3>
        <div class="form-grid">
          <div class="fg"><label>اینستاگرام</label><input type="text" name="instagram" value="<?php echo e($settings['instagram'] ?? ''); ?>" dir="ltr" style="text-align:left" placeholder="https://instagram.com/..." <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg"><label>تلگرام</label><input type="text" name="telegram" value="<?php echo e($settings['telegram'] ?? ''); ?>" dir="ltr" style="text-align:left" placeholder="https://t.me/..." <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg"><label>واتساپ</label><input type="text" name="whatsapp" value="<?php echo e($settings['whatsapp'] ?? ''); ?>" dir="ltr" style="text-align:left" placeholder="https://wa.me/..." <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg"><label>لینکدین</label><input type="text" name="linkedin" value="<?php echo e($settings['linkedin'] ?? ''); ?>" dir="ltr" style="text-align:left" placeholder="https://linkedin.com/..." <?php echo $is_viewer?'disabled':''; ?>></div>
        </div>
      </div>
    </div>

    <!-- seo -->
    <div class="tab-panel" id="panel-seo">
      <div class="card">
        <h3>🔍 سئو</h3>
        <div class="form-grid">
          <div class="fg full"><label>کلمات کلیدی (با کاما)</label><textarea name="meta_keywords" rows="3" <?php echo $is_viewer?'disabled':''; ?>><?php echo e($settings['meta_keywords'] ?? ''); ?></textarea></div>
          <div class="fg full"><label>توضیحات متا</label><textarea name="meta_description" rows="4" <?php echo $is_viewer?'disabled':''; ?>><?php echo e($settings['meta_description'] ?? ''); ?></textarea><small>برای نمایش در گوگل، حداکثر ۱۵۵ کاراکتر توصیه می‌شود</small></div>
        </div>
      </div>
    </div>

    <!-- appearance -->
    <div class="tab-panel" id="panel-appearance">
      <div class="card">
        <h3>🎨 ظاهر سایت</h3>
        <div class="form-grid">
          <div class="fg">
            <label>فاوآیکون (تب مرورگر)</label>
            <?php if(!$is_viewer): ?>
            <div class="upload-box" onclick="document.getElementById('faviconInput').click()">
              <div style="font-size:28px">🖼️</div>
              <div style="font-size:12px;margin-top:6px">کلیک برای انتخاب</div>
              <div style="font-size:10px;color:#999">PNG, JPG, ICO, WEBP — تا ۵۱۲×۵۱۲</div>
              <?php if(!empty($settings['favicon'])): ?><img src="../<?php echo e($settings['favicon']); ?>" id="faviconPreview"><?php else: ?><img id="faviconPreview" style="display:none"><?php endif; ?>
            </div>
            <input type="file" id="faviconInput" name="favicon" accept=".png,.jpg,.jpeg,.ico,.svg,.webp" style="display:none" onchange="previewImg(this,'faviconPreview')">
            <?php else: ?>
              <?php if(!empty($settings['favicon'])): ?><img src="../<?php echo e($settings['favicon']); ?>" style="width:72px;height:72px;border-radius:12px"><?php endif; ?>
            <?php endif; ?>
          </div>
          <div class="fg">
            <label>لوگوی سایت</label>
            <?php if(!$is_viewer): ?>
            <div class="upload-box" onclick="document.getElementById('logoInput').click()">
              <div style="font-size:28px">🏢</div>
              <div style="font-size:12px;margin-top:6px">کلیک برای انتخاب لوگو</div>
              <?php if(!empty($settings['logo'])): ?><img src="../<?php echo e($settings['logo']); ?>" id="logoPreview"><?php else: ?><img id="logoPreview" style="display:none"><?php endif; ?>
            </div>
            <input type="file" id="logoInput" name="logo" accept=".png,.jpg,.jpeg,.svg,.webp" style="display:none" onchange="previewImg(this,'logoPreview')">
            <?php else: ?>
              <?php if(!empty($settings['logo'])): ?><img src="../<?php echo e($settings['logo']); ?>" style="width:72px;height:72px;border-radius:12px"><?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- messenger -->
    <div class="tab-panel" id="panel-messenger">
      <div class="card">
        <h3>💬 پیام‌رسان داخلی — ذخیره محلی + تحویل MySQL</h3>
        <div class="form-grid">
          <div class="fg"><label>نگهداری روی سرور (روز) — ۰ نامحدود</label><input type="number" name="msg_retention_days" min="0" max="3650" value="<?php echo (int)($settings['msg_retention_days'] ?? 0); ?>" <?php echo $is_viewer?'disabled':''; ?>><small>پیام‌ها روی دستگاه کاربر می‌مانند؛ این فقط پاک‌سازی سمت سرور است</small></div>
          <div class="fg"><label>حداکثر فایل پیوست (MB)</label><input type="number" name="msg_max_file_mb" min="1" max="512" value="<?php echo (int)($settings['msg_max_file_mb'] ?? 32); ?>" <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg full">
            <label class="switch"><input type="checkbox" name="messenger_enabled" value="1" <?php echo !isset($settings['messenger_enabled']) || $settings['messenger_enabled'] ? 'checked':''; ?> <?php echo $is_viewer?'disabled':''; ?>> پیام‌رسان فعال باشد</label>
          </div>
          <div class="fg full">
            <label class="switch"><input type="checkbox" name="msg_allow_client" value="1" <?php echo !empty($settings['msg_allow_client']) ? 'checked':''; ?> <?php echo $is_viewer?'disabled':''; ?>> کارفرماها هم بتوانند وارد پیام‌رسان شوند</label>
          </div>
          <div class="fg full">
            <label class="switch"><input type="checkbox" name="msg_purge_on_read" value="1" <?php echo !empty($settings['msg_purge_on_read']) ? 'checked':''; ?> <?php echo $is_viewer?'disabled':''; ?>> پیام‌های خصوصی خوانده‌شده زودتر از سرور پاک شوند (حریم خصوصی)</label>
          </div>
        </div>
      </div>
    </div>

    <!-- attendance -->
    <div class="tab-panel" id="panel-attendance">
      <div class="card">
        <h3>🕐 حضور و غیاب — QR + درخواست دستی</h3>
        <div class="form-grid">
          <div class="fg"><label>ساعت شروع</label><input type="time" name="att_work_start" value="<?php echo e($settings['att_work_start'] ?? '08:00'); ?>" <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg"><label>ساعت پایان</label><input type="time" name="att_work_end" value="<?php echo e($settings['att_work_end'] ?? '16:00'); ?>" <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg"><label>آستانه تاخیر (دقیقه)</label><input type="number" name="att_late_minutes" min="0" max="240" value="<?php echo (int)($settings['att_late_minutes'] ?? 15); ?>" <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg"><label>اعتبار QR (دقیقه)</label><input type="number" name="att_qr_minutes" min="1" max="720" value="<?php echo (int)($settings['att_qr_minutes'] ?? 30); ?>" <?php echo $is_viewer?'disabled':''; ?>></div>
          <div class="fg full"><label>روزهای تعطیل هفتگی (۰=یکشنبه … ۶=جمعه، با کاما)</label><input type="text" name="att_weekends" value="<?php echo e($settings['att_weekends'] ?? '6'); ?>" placeholder="6 یا 5,6" <?php echo $is_viewer?'disabled':''; ?>></div>
        </div>
      </div>
    </div>

    <!-- cron -->
    <div class="tab-panel" id="panel-cron">
      <div class="card">
        <h3>⏱ زمان‌بندی (Cron)</h3>
        <div class="form-grid">
          <div class="fg full">
            <label>کلید امن اجرای cron</label>
            <input type="text" readonly value="<?php echo e($cron_key); ?>" dir="ltr" style="text-align:left;background:#0f0f0f;color:#9eff9e;font-family:ui-monospace,monospace">
            <small>این کلید برای اجرای خودکار تسک‌ها استفاده می‌شود</small>
            <code class="cron-code"><?php echo e($cron_url); ?></code>
            <small>این آدرس را روزی یک‌بار در کنترل‌پنل هاست (Cron Jobs) تنظیم کنید</small>
          </div>
          <div class="fg full"><label class="switch"><input type="checkbox" name="rotate_cron_key" value="1" <?php echo $is_viewer?'disabled':''; ?>> ساخت کلید جدید (کلید فعلی باطل می‌شود)</label></div>
        </div>
      </div>
    </div>

    <?php if(!$is_viewer): ?>
    <div style="display:flex;gap:10px;margin-top:8px">
      <button type="submit" class="btn-save">💾 ذخیره همه تنظیمات (MySQL)</button>
      <a href="dashboard.php" class="btn-save" style="background:#f5f5f5;color:#333;box-shadow:none;text-decoration:none;display:inline-flex;align-items:center">↩️ بازگشت</a>
    </div>
    <?php endif; ?>
  </div>
</div>
</form>
</main>
</div>
<script>
document.querySelectorAll('.settings-nav .nav-item').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const tab = btn.dataset.tab;
    document.querySelectorAll('.settings-nav .nav-item').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
    document.getElementById('panel-'+tab)?.classList.add('active');
    history.replaceState(null,'','#'+tab);
  });
});
if(location.hash){
  const h = location.hash.replace('#','');
  const b = document.querySelector(`.nav-item[data-tab="${h}"]`);
  if(b) b.click();
}
function previewImg(input,id){
  const img=document.getElementById(id);
  if(input.files && input.files[0]){
    const r=new FileReader();
    r.onload=e=>{img.src=e.target.result;img.style.display='block';};
    r.readAsDataURL(input.files[0]);
  }
}
</script>
</body>
</html>
