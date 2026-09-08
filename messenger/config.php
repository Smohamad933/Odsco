<?php
/**
 * ============================================================================
 *  Odsco Messenger — بوت‌استرپ صفحات پیام‌رسان
 * ============================================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/messenger.php';
require_once dirname(__DIR__) . '/includes/automation.php';

// ---------------------------------------------------------------------------
// ورود / کاربر فعلی
// ---------------------------------------------------------------------------

function m_check_login(): void
{
    check_messenger_login();
}

/** @return array{uid: string, name: string, photo: string, role: string, client_uid: string} */
function m_current_user(): array
{
    $uid = current_messenger_uid();
    $u = Users::find($uid);
    return [
        'uid'        => $uid,
        'name'       => $u['full_name'] ?? (string)($_SESSION['messenger_user_name'] ?? 'کاربر'),
        'photo'      => $u['photo'] ?? (string)($_SESSION['messenger_user_photo'] ?? ''),
        'role'       => $u['role'] ?? (string)($_SESSION['messenger_user_role'] ?? 'viewer'),
        'job_title'  => $u['job_title'] ?? '',
        'client_uid' => $u['client_uid'] ?? '',
    ];
}

/** آیا کاربر دسترسی مدیریتی دارد؟ */
function m_is_admin(): bool
{
    return Users::level(m_current_user()['role']) >= Users::level('admin');
}

function m_is_manager(): bool
{
    return Users::level(m_current_user()['role']) >= Users::level('manager');
}

// ---------------------------------------------------------------------------
// مسیرها
// ---------------------------------------------------------------------------

/** مسیر فایل آپلود نسبت به پوشه messenger/ */
function m_asset(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '') return '';
    if (preg_match('#^(https?:)?//#', $path)) return $path;
    return '../' . ltrim($path, '/');
}

function m_url(string $page = ''): string
{
    return $page;
}

/** مسیر پنل مدیریت / سایت از داخل پوشه messenger */
function m_admin_url(string $page = ''): string
{
    return '../admin/' . ltrim($page, '/');
}

// ---------------------------------------------------------------------------
// اجزای مشترک رابط
// ---------------------------------------------------------------------------

/** آواتار کاربر یا گروه */
function m_avatar(string $name, string $photo = '', string $extraClass = '', bool $online = false): string
{
    $size = str_contains($extraClass, 'sm') ? 'sm' : '';
    if ($photo !== '') {
        $html = '<img src="' . e(m_asset($photo)) . '" class="tg-av ' . $size . ' ' . e($extraClass)
              . '" alt="' . e($name) . '" onerror="this.outerHTML=\'<div class=&quot;tg-av ' . $size . ' ' . e($extraClass) . '&quot;>'
              . e(mb_substr($name, 0, 1)) . '</div>\'">';
    } else {
        $html = '<div class="tg-av ' . $size . ' ' . e($extraClass) . '">' . e(mb_substr($name, 0, 1)) . '</div>';
    }
    if ($online) {
        $html = '<span class="tg-av-wrap">' . $html . '<span class="tg-online-dot"></span></span>';
        return $html;
    }
    return $html;
}

/** رنگ آواتار بر اساس نام (مثل تلگرام) */
function m_avatar_color(string $seed): string
{
    $colors = ['#e17076','#eda86c','#a695e7','#7bc862','#6ec9cb','#65aadd','#ee7aae'];
    return $colors[abs(crc32($seed)) % count($colors)];
}

/** وضعیت «آخرین بازدید» */
function m_presence_text(string $uid): string
{
    if (Messenger::isOnline($uid)) return 'آنلاین';
    $ts = Messenger::lastSeen($uid);
    if (!$ts) return 'آخرین بازدید مدت‌ها پیش';
    return last_seen_fa($ts);
}

/** هدر مشترک HTML */
function m_head(string $title, string $extraHead = ''): void
{
    $settings = Settings::all();
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, viewport-fit=cover">
<meta name="theme-color" content="#17212b">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?php echo e($title); ?> · <?php echo e($settings['site_name'] ?? 'پیام‌رسان'); ?></title>
<?php if (!empty($settings['favicon'])): ?>
<link rel="icon" href="<?php echo e(m_asset((string)$settings['favicon'])); ?>">
<?php endif; ?>
<link rel="stylesheet" href="assets/messenger.css">
<?php echo $extraHead; ?>
</head>
<?php
}

function m_foot(string $extraScripts = ''): void
{
    ?>
<div class="tg-toast" id="tgToast"></div>
<div class="tg-lightbox" id="tgLightbox" onclick="this.classList.remove('on')">
    <button class="close" type="button" aria-label="بستن">✕</button>
    <img id="tgLightboxImg" src="" alt="">
</div>
<script src="assets/local-store.js"></script>
<script src="assets/messenger.js"></script>
<?php echo $extraScripts; ?>
</body>
</html>
<?php
}

/** toast سمت سرور (پیام‌های GET) */
function m_flash(): void
{
    if (!empty($_SESSION['m_flash'])) {
        $f = (string)$_SESSION['m_flash'];
        unset($_SESSION['m_flash']);
        echo '<script>window.addEventListener("DOMContentLoaded",function(){TG.toast(' . json_encode($f, JSON_UNESCAPED_UNICODE) . ');});</script>';
    }
}

function m_set_flash(string $message): void
{
    $_SESSION['m_flash'] = $message;
}
