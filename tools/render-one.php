<?php
/**
 * ============================================================================
 *  Odsco — رندر یک صفحه در فرآیند جدا
 * ----------------------------------------------------------------------------
 *  هر صفحه در زیرفرآیند خودش اجرا می‌شود تا exit/redirect یک صفحه
 *  بقیه تست را نکشد.
 *
 *  استفاده:  php tools/render-one.php <path/to/page.php> [queryString] [area]
 *  خروجی:    یک خط JSON  {ok, bytes, fatal, errors}
 * ============================================================================
 */

declare(strict_types=1);

$__page  = (string)($argv[1] ?? '');
$__query = (string)($argv[2] ?? '');
$__area  = (string)($argv[3] ?? 'admin');

$__rep = ['ok' => false, 'page' => $__page, 'bytes' => 0, 'fatal' => '', 'errors' => []];

$__emit = static function (array $r): never {
    if (empty($GLOBALS['__odsco_emitted'])) {
        $GLOBALS['__odsco_emitted'] = true;
        echo "\n__RENDER__" . json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    exit($r['ok'] ? 0 : 1);
};
$GLOBALS['__odsco_emitted'] = false;

// همه خطاها را بگیر (حتی E_WARNING / E_NOTICE)
set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0) use (&$__rep): bool {
    if (!(error_reporting() & $no)) return false;
    $__rep['errors'][] = preg_replace('~^.*Odsco/~', '', $file) . ":$line — $str";
    return true;
});

// خطای مهلک و exit زودهنگام (مثل redirect) را در shutdown بگیر
$GLOBALS['__odsco_emitted'] = false;
register_shutdown_function(static function () use (&$__rep, $__emit): void {
    if (!empty($GLOBALS['__odsco_emitted'])) return;

    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $__rep['fatal'] = $e['message'] . ' @ ' . preg_replace('~^.*Odsco/~', '', $e['file']) . ':' . $e['line'];
        $__rep['ok'] = false;
        $__emit($__rep);
    }

    // صفحه بدون رندر کامل خارج شد → احتمالاً redirect
    $buf = (string)ob_get_clean();
    $__rep['bytes'] = strlen($buf);
    if ($__rep['bytes'] === 0) {
        $__rep['fatal'] = 'صفحه بدون خروجی خارج شد (redirect یا exit زودهنگام)';
        $__rep['ok'] = false;
    } else {
        $__rep['ok'] = $__rep['errors'] === [];
    }
    $__emit($__rep);
});

if (!is_file($__page)) { $__rep['fatal'] = 'فایل پیدا نشد'; $__emit($__rep); }

// ---- محیط درخواست -----------------------------------------------------------
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['SCRIPT_NAME']    = '/' . basename(dirname($__page)) . '/' . basename($__page);
$_SERVER['REQUEST_URI']    = $_SERVER['SCRIPT_NAME'] . ($__query !== '' ? '?' . $__query : '');
$_GET = $__query === '' ? [] : (function (string $q): array {
    parse_str($q, $out);
    return $out;
})($__query);
$_POST = [];
$_FILES = [];

// header() در CLI کاری نمی‌کند؛ برای دیدن redirect آن را رهگیری می‌کنیم
$GLOBALS['__odsco_headers'] = [];

require_once dirname(__DIR__) . '/includes/config.php';

if (!Db::ready()) { $__rep['fatal'] = 'دیتابیس تست آماده نیست'; $__emit($__rep); }

/*
 * عمداً فقط auth.php بارگذاری می‌شود (همان کاری که یک صفحه واقعی می‌کند).
 * بقیه لایه‌ها باید خودشان از includes/config.php بیایند؛ اگر روزی آن
 * بارگذاری خودکار شکست، این تست باید خطا بدهد نه اینکه پنهانش کند.
 */
require_once dirname(__DIR__) . '/includes/auth.php';

// ---- نشست جعلی برای ناحیه مورد نظر ----------------------------------------
$__pick = static function (array $filters): ?string {
    foreach (Users::list($filters) as $__u) {
        return (string)$__u['uid'];
    }
    return null;
};

if ($__area === 'admin') {
    $__uid = $__pick(['role' => 'admin', 'active' => true]) ?? $__pick(['active' => true]);
    if (!$__uid) { $__rep['fatal'] = 'کاربر ادمین برای تست پیدا نشد'; $__emit($__rep); }
    $__u = Users::find($__uid);
    $_SESSION['admin_id']    = $__uid;
    $_SESSION['admin_role']  = (string)$__u['role'];
    $_SESSION['admin_name']  = (string)$__u['full_name'];
    $_SESSION['admin_photo'] = (string)$__u['photo'];

} elseif ($__area === 'messenger') {
    // کاربری که پیام‌رسان برایش فعال است
    $__uid = null;
    foreach (Users::list(['active' => true]) as $__u) {
        if (!empty($__u['messenger_enabled']) || in_array($__u['role'], ['admin', 'manager'], true)) {
            $__uid = (string)$__u['uid'];
            break;
        }
    }
    if (!$__uid) { $__rep['fatal'] = 'کاربر پیام‌رسان پیدا نشد'; $__emit($__rep); }
    $_SESSION['messenger_user_id'] = $__uid;
    Settings::set('messenger_enabled', 1);

} elseif ($__area === 'client') {
    $__uid = $__pick(['role' => 'client', 'active' => true]);
    if (!$__uid) {
        // یک کارفرمای تست بساز
        $clientUid = Clients::create(['name' => 'شرکت تست رندر']);
        $__u = Users::create([
            'username' => 'qa_render_client', 'password' => 'Pass1234', 'role' => 'client',
            'full_name' => 'کارفرمای تست', 'client_uid' => $clientUid, 'messenger_enabled' => true,
        ]);
        $__uid = (string)$__u['uid'];
    }
    $_SESSION['client_user_id'] = $__uid;

} elseif ($__area === 'attendance') {
    // کاربری که دسترسی حضور و غیاب دارد (و مدیر تا تب «تیم» هم پوشش داده شود)
    $__uid = $__pick(['role' => 'manager', 'active' => true]) ?? $__pick(['active' => true]);
    if (!$__uid) { $__rep['fatal'] = 'کاربر حضور و غیاب برای تست پیدا نشد'; $__emit($__rep); }
    Users::update($__uid, ['attendance_enabled' => 1]);
    $_SESSION['attendance_user_id']   = $__uid;
    $_SESSION['attendance_user_name'] = (string)(Users::find($__uid)['full_name'] ?? '');
    $_SESSION['attendance_user_role'] = (string)(Users::find($__uid)['role'] ?? '');
    $_SESSION['attendance_user_photo'] = '';
}
// ناحیه 'anon' / 'public': بدون نشست (صفحه ورود و صفحات عمومی سایت)

// ---- رندر ------------------------------------------------------------------
$__abs = (string)realpath($__page);
if ($__abs === '') { $__rep['fatal'] = 'مسیر صفحه حل نشد: ' . $__page; $__emit($__rep); }
chdir(dirname($__abs));   // include های نسبی صفحه (مثل sidebar.php) کار کنند

ob_start();
include $__abs;
$__html = (string)ob_get_clean();

$__rep['bytes'] = strlen($__html);
$__rep['ok']    = $__rep['fatal'] === '' && $__rep['errors'] === [] && $__rep['bytes'] > 0;

// نشانه‌های مشکل در خروجی HTML
foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught'] as $needle) {
    if (str_contains($__html, $needle)) {
        $__rep['fatal'] = 'خروجی HTML شامل «' . $needle . '» است';
        $__rep['ok'] = false;
        break;
    }
}

$__emit($__rep);
