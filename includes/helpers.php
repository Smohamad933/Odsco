<?php
/**
 * ============================================================================
 *  Odsco — توابع کمکی عمومی
 * ============================================================================
 */

declare(strict_types=1);

/** شناسه یکتا به سبک داده‌های قبلی پروژه: prj_6a898c4db8b7e */
function odsco_uid(string $prefix = 'id'): string
{
    return $prefix . '_' . bin2hex(random_bytes(6)) . substr((string)microtime(true), -4);
}

/** پاکسازی ورودی متنی (بدون تغییر در HTML عمدی) */
function sanitize(mixed $input): mixed
{
    if ($input === null) return '';
    if (is_array($input)) return array_map('sanitize', $input);
    if (is_bool($input)) return $input;
    if (is_int($input) || is_float($input)) return $input;
    return htmlspecialchars(strip_tags(trim((string)$input)), ENT_QUOTES, 'UTF-8');
}

/** escape برای خروجی HTML */
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** ساخت slug (فارسی/لاتین) */
function create_slug(mixed $string): string
{
    $string = (string)$string;
    $string = trim($string);
    $string = str_replace(['ی', 'ک', 'ي', 'ك'], ['ی', 'ک', 'ی', 'ک'], $string);
    $string = preg_replace('/[^\p{Arabic}\p{L}\p{N}\s-]/u', '', $string) ?? '';
    $string = preg_replace('/[\s-]+/u', '-', trim($string)) ?? '';
    return $string !== '' ? $string : substr(bin2hex(random_bytes(4)), 0, 8);
}

/** خروجی JSON و پایان */
function json_out(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** آدرس IP واقعی کلاینت */
function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', (string)$_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_valid(?string $token = null): bool
{
    $token ??= (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return $token !== '' && hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token);
}

/** اگر توکن CSRF معتبر نبود، درخواست را رد می‌کند */
function csrf_guard(bool $ajax = false): void
{
    if (csrf_valid()) return;

    if ($ajax) {
        json_out(['success' => false, 'message' => 'نشست امنیتی منقضی شده، صفحه را تازه کنید'], 419);
    }
    http_response_code(419);
    exit('❌ نشست امنیتی منقضی شده است. لطفاً صفحه را تازه کنید.');
}

// ---------------------------------------------------------------------------
// آپلود فایل
// ---------------------------------------------------------------------------

/**
 * آپلود امن فایل.
 *
 * @param array                 $file     یکی از عناصر $_FILES
 * @param string                $subdir   زیرپوشه داخل uploads/
 * @param array<int,string>|null $allowed  پسوندهای مجاز (null = همه پسوندهای بی‌خطر)
 * @return array{success: bool, path?: string, name?: string, size?: int, mime?: string, message?: string}
 */
function handle_upload(array $file, string $subdir, ?array $allowed = null, int $maxBytes = 52428800): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'حجم فایل بیشتر از حد مجاز سرور است',
            UPLOAD_ERR_FORM_SIZE  => 'حجم فایل بیشتر از حد مجاز فرم است',
            UPLOAD_ERR_PARTIAL    => 'فایل ناقص آپلود شد',
            UPLOAD_ERR_NO_FILE    => 'فایلی انتخاب نشده است',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت سرور موجود نیست',
            UPLOAD_ERR_CANT_WRITE => 'امکان نوشتن روی دیسک نیست',
        ];
        return ['success' => false, 'message' => $messages[$file['error']] ?? 'خطای نامشخص در آپلود'];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'message' => 'آپلود نامعتبر است'];
    }

    if ($file['size'] > $maxBytes) {
        return ['success' => false, 'message' => 'حجم فایل نباید بیشتر از ' . format_bytes($maxBytes) . ' باشد'];
    }

    $defaultAllowed = [
        'jpg','jpeg','png','gif','webp','bmp','svg','heic','avif',
        'pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip','rar','7z',
        'mp4','webm','mov','avi','mkv','mp3','wav','ogg','m4a',
    ];
    $allowed = array_map('strtolower', $allowed ?? $defaultAllowed);

    $original = (string)($file['name'] ?? 'file');
    $ext = strtolower((string)pathinfo($original, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';

    if ($ext === '' || !in_array($ext, $allowed, true)) {
        return ['success' => false, 'message' => 'نوع فایل مجاز نیست (.' . e($ext) . ')'];
    }

    $dir = odsco_upload_path($subdir);
    $safeBase = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', pathinfo($original, PATHINFO_FILENAME)) ?? 'file';
    $safeBase = mb_substr($safeBase, 0, 60) ?: 'file';
    $name = time() . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '_' . $safeBase . '.' . $ext;
    $target = $dir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['success' => false, 'message' => 'ذخیره فایل ناموفق بود'];
    }

    @chmod($target, 0644);

    return [
        'success' => true,
        'path'    => 'uploads/' . trim($subdir, '/') . '/' . $name,
        'name'    => $original,
        'size'    => (int)$file['size'],
        'mime'    => (string)($file['type'] ?: 'application/octet-stream'),
    ];
}

function odsco_upload_path(string $subdir = ''): string
{
    $dir = defined('UPLOAD_PATH') ? UPLOAD_PATH : dirname(__DIR__) . '/uploads';
    if ($subdir !== '') $dir .= '/' . trim($subdir, '/');
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function format_bytes(int|float $bytes, int $precision = 1): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max(0, (float)$bytes);
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $precision) . ' ' . $units[$i];
}

function file_icon(string $name): string
{
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    return match (true) {
        in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','heic','avif'], true) => '🖼️',
        in_array($ext, ['pdf'], true)                                              => '📕',
        in_array($ext, ['doc','docx'], true)                                       => '📘',
        in_array($ext, ['xls','xlsx','csv'], true)                                 => '📗',
        in_array($ext, ['ppt','pptx'], true)                                       => '📙',
        in_array($ext, ['zip','rar','7z'], true)                                   => '🗜️',
        in_array($ext, ['mp4','webm','mov','avi','mkv'], true)                     => '🎬',
        in_array($ext, ['mp3','wav','ogg','m4a'], true)                            => '🎵',
        in_array($ext, ['svg'], true)                                              => '🎨',
        default                                                                    => '📄',
    };
}

// ---------------------------------------------------------------------------
// متفرقه
// ---------------------------------------------------------------------------

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** مقدار یک کلید از آرایه با مقدار پیش‌فرض */
function arr_get(array $array, string $key, mixed $default = null): mixed
{
    return $array[$key] ?? $default;
}

/** تبدیل رشته JSON یا آرایه به آرایه */
function json_decode_safe(mixed $value, mixed $default = []): mixed
{
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return $default;
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : $default;
}

function json_encode_safe(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
}

/** تشخیص دستگاه/پلتفرم از User-Agent */
function detect_device(): array
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $platform = match (true) {
        str_contains($ua, 'Android')                       => 'Android',
        str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'Windows')                       => 'Windows',
        str_contains($ua, 'Mac OS')                        => 'macOS',
        str_contains($ua, 'Linux')                         => 'Linux',
        default                                            => 'نامشخص',
    };
    $browser = match (true) {
        str_contains($ua, 'Edg/')      => 'Edge',
        str_contains($ua, 'OPR/')      => 'Opera',
        str_contains($ua, 'Chrome/')   => 'Chrome',
        str_contains($ua, 'Firefox/')  => 'Firefox',
        str_contains($ua, 'Safari/')   => 'Safari',
        default                        => 'نامشخص',
    };
    $device = match (true) {
        str_contains($ua, 'iPad') || str_contains($ua, 'Tablet') => 'تبلت',
        preg_match('/Mobile|iPhone|Android.*Mobile/', $ua) === 1 => 'موبایل',
        default                                                  => 'کامپیوتر',
    };
    return ['platform' => $platform, 'browser' => $browser, 'device' => $device,
            'name' => trim($device . ' · ' . $platform . ' · ' . $browser)];
}

/** مسیر نسبی سایت (بدون اسلش انتهایی) */
function odsco_base_url(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return rtrim(str_replace('\\', '/', dirname($script)), '/');
}

/** آیا دستگاه موبایل است؟ (برای ریسپانسیو سمت سرور) */
function is_mobile_request(): bool
{
    return preg_match('/Mobile|Android|iPhone|iPad|iPod/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')) === 1;
}

/** درصد پیشرفت امن */
function clamp_int(mixed $v, int $min, int $max, int $default = 0): int
{
    if (!is_numeric($v)) return $default;
    return max($min, min($max, (int)$v));
}
