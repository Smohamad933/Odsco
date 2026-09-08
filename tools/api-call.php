<?php
/**
 * ============================================================================
 *  Odsco — اجرای یک فراخوانی API در زیرفرآیند
 * ----------------------------------------------------------------------------
 *  messenger/api.php با json_out() → exit تمام می‌شود، پس نمی‌توان چند اکشن را
 *  در یک فرآیند زد. این اسکریپت یک اکشن را با محیط درخواست واقعی اجرا می‌کند
 *  و پاسخ JSON را همراه کد وضعیت HTTP برمی‌گرداند.
 *
 *  ورودی (stdin): JSON شامل action / post / get / method / session
 *  خروجی (stdout): یک خط JSON پاسخ + کلید __status
 *
 *  فقط ابزار تست است؛ بخشی از برنامه نیست.
 * ============================================================================
 */

declare(strict_types=1);

$input = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'ورودی نامعتبر', '__status' => 400], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}

$_SERVER['REQUEST_METHOD']  = strtoupper((string)($input['method'] ?? 'POST'));
$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['SCRIPT_NAME']     = '/messenger/api.php';
$_SERVER['REQUEST_URI']     = '/messenger/api.php';
$_SERVER['HTTP_USER_AGENT'] = 'odsco-api-test';

// api.php اکشن را از $_REQUEST['a'] می‌خواند
$action   = (string)($input['action'] ?? '');
$_GET     = (array)($input['get'] ?? []);
$_POST    = (array)($input['post'] ?? []);
$_REQUEST = array_merge($_GET, $_POST, ['a' => $action]);
$_FILES   = [];
$_SESSION = (array)($input['session'] ?? []);

// خطاها را جمع کن تا JSON پاسخ خراب نشود
$GLOBALS['__api_errors'] = [];
set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $no)) return false;
    $GLOBALS['__api_errors'][] = basename($file) . ":$line — $str";
    return true;
});

/** خروجی را بگیر و در پایان، کد وضعیت را به آن تزریق کن */
$GLOBALS['__api_done'] = false;
register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['__api_done'])) return;
    $GLOBALS['__api_done'] = true;

    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        echo json_encode([
            'success'  => false,
            'message'  => 'FATAL: ' . $e['message'] . ' @ ' . basename((string)$e['file']) . ':' . $e['line'],
            '__status' => 500,
        ], JSON_UNESCAPED_UNICODE), "\n";
        return;
    }

    $body   = (string)ob_get_clean();
    $status = (int)http_response_code();
    $json   = json_decode(trim($body), true);

    if (!is_array($json)) {
        echo json_encode([
            'success' => false,
            'message' => 'پاسخ JSON نبود: ' . mb_substr($body, 0, 300),
            'errors'  => $GLOBALS['__api_errors'],
            '__status' => $status ?: 500,
        ], JSON_UNESCAPED_UNICODE), "\n";
        return;
    }

    $json['__status'] = $status ?: 200;
    if ($GLOBALS['__api_errors']) $json['__errors'] = $GLOBALS['__api_errors'];
    echo json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
});

ob_start();
chdir(__DIR__ . '/../messenger');
include __DIR__ . '/../messenger/api.php';

// اگر api.php بدون json_out تمام شد
echo json_encode(['success' => false, 'message' => 'api.php بدون پاسخ تمام شد', '__status' => 500],
    JSON_UNESCAPED_UNICODE), "\n";
