<?php
/**
 * ============================================================================
 *  Odsco — اجرای یک صفحه به‌صورت CGI برای تست واقعی
 * ----------------------------------------------------------------------------
 *  درخواست JSON را از stdin می‌خواند، superglobalها و نشست واقعی را می‌سازد،
 *  صفحه را اجرا می‌کند و نتیجه را در فایل JSON می‌نویسد:
 *      {status, body, headers, session_id, set_cookie, errors, fatal}
 *
 *  این اسکریپت توسط tools/serve.js صدا زده می‌شود تا بتوان سایت را
 *  با مرورگر واقعی تست کرد. بخشی از برنامه نیست.
 * ============================================================================
 */

declare(strict_types=1);

$__raw  = (string)stream_get_contents(STDIN);
$__req  = json_decode($__raw, true);
$__out  = (string)(getenv('ODSCO_CGI_OUT') ?: '/tmp/odsco_cgi_out.json');
$__root = dirname(__DIR__);

if (!is_array($__req) || empty($__req['script'])) {
    file_put_contents($__out, json_encode(['status' => 400, 'body' => 'درخواست نامعتبر', 'errors' => [], 'fatal' => ''], JSON_UNESCAPED_UNICODE));
    exit(1);
}

$__script = (string)$__req['script'];
if (!str_starts_with($__script, '/')) $__script = $__root . '/' . ltrim($__script, '/');
if (!is_file($__script)) {
    file_put_contents($__out, json_encode(['status' => 404, 'body' => 'اسکریپت پیدا نشد: ' . $__script, 'errors' => [], 'fatal' => ''], JSON_UNESCAPED_UNICODE));
    exit(1);
}

// ---------------------------------------------------------------------------
// superglobalها
// ---------------------------------------------------------------------------
$__uri   = (string)($__req['uri'] ?? '/');
$__parts = explode('?', $__uri, 2);
$__path  = $__parts[0];

$_GET     = (array)($__req['get'] ?? []);
$_POST    = (array)($__req['post'] ?? []);
$_COOKIE  = (array)($__req['cookies'] ?? []);
$_FILES   = (array)($__req['files'] ?? []);
$_REQUEST = array_merge($_GET, $_POST);

$__https = !empty($__req['https']);
$_SERVER = [
    'REQUEST_METHOD'   => strtoupper((string)($__req['method'] ?? 'GET')),
    'REQUEST_URI'      => $__uri,
    'SCRIPT_NAME'      => $__path,
    'PHP_SELF'         => $__path,
    'SCRIPT_FILENAME'  => $__script,
    'DOCUMENT_ROOT'    => $__root,
    'HTTP_HOST'        => (string)($__req['host'] ?? 'localhost'),
    'REMOTE_ADDR'      => (string)($__req['ip'] ?? '127.0.0.1'),
    'SERVER_ADDR'      => '127.0.0.1',
    'SERVER_PORT'      => $__https ? '443' : '80',
    'SERVER_PROTOCOL'  => 'HTTP/1.1',
    'SERVER_SOFTWARE'  => 'odsco-dev-server',
    'HTTPS'            => $__https ? 'on' : '',
    'HTTP_USER_AGENT'  => (string)($__req['user_agent'] ?? 'odsco-browser-test'),
    'HTTP_ACCEPT'      => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'HTTP_ACCEPT_LANGUAGE' => 'fa-IR,fa;q=0.9,en;q=0.8',
    'HTTP_REFERER'     => (string)($__req['referer'] ?? ''),
    'CONTENT_TYPE'     => (string)($__req['content_type'] ?? 'application/x-www-form-urlencoded'),
    'QUERY_STRING'     => (string)($__parts[1] ?? ''),
];
foreach ((array)($__req['headers'] ?? []) as $__k => $__v) {
    $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', (string)$__k))] = (string)$__v;
}

// ---------------------------------------------------------------------------
// نشست واقعی (فایل در tools/.sessions)
// ---------------------------------------------------------------------------
$__sessDir = __DIR__ . '/.sessions';
if (!is_dir($__sessDir)) @mkdir($__sessDir, 0777, true);
ini_set('session.save_path', $__sessDir);
session_name('ODSCO_SESS');

$__sid = (string)($_COOKIE['ODSCO_SESS'] ?? '');
$__sid = preg_replace('/[^a-zA-Z0-9,\-]/', '', $__sid) ?? '';
if ($__sid !== '') session_id($__sid);

$__newSession = $__sid === '';
@session_start();
$__sid = session_id();

// ---------------------------------------------------------------------------
// رهگیری خطاها و هدرها
// ---------------------------------------------------------------------------
$GLOBALS['__odsco_headers'] = [];
$__errors = [];

set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0) use (&$__errors): bool {
    $__errors[] = odsco_err_name($no) . ': ' . $str . ' @ ' . rel_path($file) . ':' . $line;
    return true;
});

function odsco_err_name(int $no): string
{
    return [E_WARNING => 'Warning', E_NOTICE => 'Notice', E_DEPRECATED => 'Deprecated',
            E_USER_WARNING => 'Warning', E_USER_NOTICE => 'Notice', E_USER_DEPRECATED => 'Deprecated',
            E_STRICT => 'Strict'][$no] ?? 'Error(' . $no . ')';
}

function rel_path(string $f): string
{
    $root = dirname(__DIR__);
    return str_starts_with($f, $root) ? substr($f, strlen($root) + 1) : $f;
}

$__emitted = false;
$__emit = static function () use ($__out, &$__errors, $__newSession, &$__emitted): void {
    if ($__emitted) return;
    $__emitted = true;

    $__fatal = '';
    $__e = error_get_last();
    if ($__e && in_array($__e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $__fatal = $__e['message'] . ' @ ' . rel_path((string)$__e['file']) . ':' . $__e['line'];
    }

    $__body = '';
    while (ob_get_level() > 0) { $__body = (string)ob_get_clean() . $__body; }

    $__headers = $GLOBALS['__odsco_headers'] ?? [];
    $__status  = (int)(http_response_code() ?: 200);

    // کوکی نشست فقط وقتی تازه ساخته شده
    $__setCookie = '';
    if ($__newSession && session_status() === PHP_SESSION_ACTIVE) {
        $__setCookie = 'ODSCO_SESS=' . session_id() . '; Path=/; HttpOnly; SameSite=Lax';
    } elseif (session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
        $__setCookie = 'ODSCO_SESS=' . session_id() . '; Path=/; HttpOnly; SameSite=Lax';
    }

    @file_put_contents($__out, json_encode([
        'status'     => $__status,
        'body'       => $__body,
        'headers'    => $__headers,
        'set_cookie' => $__setCookie,
        'session_id' => session_status() === PHP_SESSION_ACTIVE ? session_id() : '',
        'errors'     => $__errors,
        'fatal'      => $__fatal,
        'script'     => rel_path((string)($GLOBALS['__odsco_script'] ?? '')),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
};
register_shutdown_function($__emit);

// ---------------------------------------------------------------------------
// اجرای صفحه
// ---------------------------------------------------------------------------
$GLOBALS['__odsco_script'] = $__script;
ob_start();
chdir(dirname($__script));
include $__script;
