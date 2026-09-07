<?php
// شروع session اگر شروع نشده
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// تعریف مسیرها
define('BASE_PATH', dirname(__DIR__));
define('DATA_PATH', BASE_PATH . '/data');
define('UPLOAD_PATH', BASE_PATH . '/uploads');

// ==============================================
// خواندن فایل JSON
// ==============================================
function read_json($file) {
    $path = DATA_PATH . '/' . $file;
    
    if (!file_exists($path)) {
        // ساخت پوشه اگر وجود ندارد
        if (!file_exists(DATA_PATH)) {
            mkdir(DATA_PATH, 0755, true);
        }
        file_put_contents($path, json_encode([], JSON_UNESCAPED_UNICODE));
        return [];
    }
    
    $content = file_get_contents($path);
    $decoded = json_decode($content, true);
    
    return is_array($decoded) ? $decoded : [];
}

// ==============================================
// نوشتن فایل JSON
// ==============================================
function write_json($file, $data) {
    $path = DATA_PATH . '/' . $file;
    
    if (!file_exists(DATA_PATH)) {
        mkdir(DATA_PATH, 0755, true);
    }
    
    return file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ==============================================
// پاکسازی ورودی
// ==============================================
function sanitize($input) {
    if ($input === null) return '';
    if (is_array($input)) return array_map('sanitize', $input);
    return htmlspecialchars(strip_tags(trim((string)$input)), ENT_QUOTES, 'UTF-8');
}

// ==============================================
// ساخت slug
// ==============================================
function create_slug($string) {
    $string = (string)$string;
    $string = trim($string);
    $string = str_replace(['ی', 'ک'], ['y', 'k'], $string);
    $string = preg_replace('/[^a-zA-Z0-9\p{Arabic}\s-]/u', '', $string);
    $string = preg_replace('/[\s-]+/', '-', $string);
    return $string;
}

// ==============================================
// فرمت تاریخ شمسی ساده
// ==============================================
function format_date_fa($date) {
    $timestamp = strtotime($date);
    if (!$timestamp) return $date;
    
    $months = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    ];
    
    $day = date('d', $timestamp);
    $month = $months[(int)date('n', $timestamp)];
    $year = date('Y', $timestamp);
    
    return $day . ' ' . $month . ' ' . $year;
}

// ==============================================
// فرمت زمان
// ==============================================
function format_time_fa($date) {
    $timestamp = strtotime($date);
    if (!$timestamp) return '';
    return date('H:i', $timestamp);
}

// ==============================================
// بررسی وجود فایل
// ==============================================
function data_file_exists($file) {
    return file_exists(DATA_PATH . '/' . $file);
}

// ==============================================
// ساخت پوشه آپلود
// ==============================================
function ensure_upload_dir($subfolder = '') {
    $dir = UPLOAD_PATH . ($subfolder ? '/' . $subfolder : '');
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}
?>