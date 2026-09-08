<?php
/**
 * ============================================================================
 *  Odsco — بررسی سازگاری فراخوانی‌ها با API واقعی
 * ----------------------------------------------------------------------------
 *  هر فایل PHP را توکنایز می‌کند و همه فراخوانی‌های `Class::method(` و
 *  `function(` را با تعریف واقعی مقایسه می‌کند تا خطاهای «متد وجود ندارد»
 *  پیش از اجرا کشف شوند.
 *
 *  اجرا:  php-wasm-cli tools/check-api.php [path ...]
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/messenger.php';
require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/automation.php';
require_once __DIR__ . '/../includes/migrate.php';
require_once __DIR__ . '/../messenger/config.php';

$targets = array_slice($argv, 1);
if (!$targets) {
    $targets = array_merge(
        array_map(fn($f) => BASE_PATH . '/messenger/' . $f, ['index.php', 'chat.php', 'groups.php', 'group-info.php', 'sidebar.php', 'settings.php', 'notices.php', 'attendance.php', 'attendance-action.php', 'profile.php', 'api.php', 'login.php', 'logout.php', 'config.php']),
        array_map(fn($f) => BASE_PATH . '/admin/' . $f, [
            'sidebar.php', 'workspace.php', 'attendance.php', 'automation.php', 'broadcast.php',
            'manage-messages.php', 'media-library.php', 'manage-projects.php', 'manage-users.php',
            'dashboard.php', 'settings.php', 'logs.php',
        ]),
        array_map(fn($f) => BASE_PATH . '/client/' . $f, [
            'login.php', 'config.php', 'index.php', 'projects.php', 'reports.php', 'notifications.php', 'logout.php',
        ]),
        [BASE_PATH . '/install/index.php', BASE_PATH . '/tools/cron.php']
    );
}

$problems = [];
$checked  = 0;

foreach ($targets as $file) {
    if (!is_file($file)) { $problems[] = "فایل وجود ندارد: $file"; continue; }

    $code   = file_get_contents($file);
    $tokens = token_get_all($code);
    $n      = count($tokens);

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];

        // ---- Class::method( ----
        if (is_array($t) && $t[0] === T_STRING) {
            $prev = null;
            for ($k = $i - 1; $k >= 0; $k--) {
                if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
                $prev = $tokens[$k]; break;
            }
            $next = null;
            for ($k = $i + 1; $k < $n; $k++) {
                if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
                $next = $tokens[$k]; break;
            }

            if (is_array($prev) && $prev[0] === T_DOUBLE_COLON
                && $next === '('
                && isset($tokens[$i - 2])
                && is_array($tokens[$i - 2]) && $tokens[$i - 2][0] === T_STRING) {

                $class  = $tokens[$i - 2][1];
                $method = $t[1];

                if (in_array($class, ['self', 'static', 'parent'], true)) continue;
                if (!class_exists($class)) { $problems[] = basename($file) . ': کلاس ناشناخته ' . $class . '::' . $method . '()'; continue; }
                if (!method_exists($class, $method)) {
                    $problems[] = basename($file) . ': متد وجود ندارد → ' . $class . '::' . $method . '()';
                    continue;
                }

                // تعداد آرگومان‌ها (شمارش کاماهای سطح صفر)
                $depth = 0; $args = 1; $empty = true;
                for ($k = $i + 1; $k < $n; $k++) {
                    $tk = $tokens[$k];
                    $s  = is_array($tk) ? $tk[1] : $tk;
                    if ($s === '(' || $s === '[' || $s === '{') $depth++;
                    elseif ($s === ')' || $s === ']' || $s === '}') { $depth--; if ($depth === 0 && ($s === ')')) break; }
                    elseif ($s === ',' && $depth === 1) $args++;
                    if ($s !== '(' && trim($s) !== '') $empty = false;
                }
                if ($empty) $args = 0;

                try {
                    $rm = new ReflectionMethod($class, $method);
                } catch (Throwable $e) { continue; }
                $req = $rm->getNumberOfRequiredParameters();
                $max = $rm->isVariadic() ? PHP_INT_MAX : $rm->getNumberOfParameters();

                if ($args < $req) {
                    $problems[] = basename($file) . ': ' . $class . '::' . $method . '() به ' . $req . ' آرگومان نیاز دارد، ' . $args . ' داده شد';
                } elseif (!$rm->isVariadic() && $args > $max) {
                    $problems[] = basename($file) . ': ' . $class . '::' . $method . '() حداکثر ' . $max . ' آرگومان می‌گیرد، ' . $args . ' داده شد';
                }
                $checked++;
            }
        }

        // ---- function( ----
        if (is_array($t) && $t[0] === T_STRING && $next === '(') {
            $name = $t[1];
            $skip = ['if', 'elseif', 'while', 'for', 'foreach', 'switch', 'catch', 'match', 'fn', 'function',
                     'array', 'list', 'isset', 'unset', 'empty', 'echo', 'print', 'return', 'new', 'clone',
                     'instanceof', 'and', 'or', 'xor', 'exit', 'die', 'include', 'include_once', 'require', 'require_once'];
            $prevToken = null;
            for ($k = $i - 1; $k >= 0; $k--) {
                if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
                $prevToken = $tokens[$k]; break;
            }
            $isCall = !in_array(strtolower($name), $skip, true)
                && !(is_array($prevToken) && in_array($prevToken[0], [T_FUNCTION, T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_NEW], true))
                && $prevToken !== '::';

            if ($isCall && function_exists($name)) {
                $rf  = new ReflectionFunction($name);
                $req = $rf->getNumberOfRequiredParameters();
                $max = $rf->isVariadic() ? PHP_INT_MAX : $rf->getNumberOfParameters();

                $depth = 0; $args = 1; $empty = true;
                for ($k = $i + 1; $k < $n; $k++) {
                    $tk = $tokens[$k];
                    $s  = is_array($tk) ? $tk[1] : $tk;
                    if ($s === '(' || $s === '[' || $s === '{') $depth++;
                    elseif ($s === ')' || $s === ']' || $s === '}') { $depth--; if ($depth === 0 && $s === ')') break; }
                    elseif ($s === ',' && $depth === 1) $args++;
                    if ($s !== '(' && trim($s) !== '') $empty = false;
                }
                if ($empty) $args = 0;

                if ($args < $req) {
                    $problems[] = basename($file) . ': ' . $name . '() به ' . $req . ' آرگومان نیاز دارد، ' . $args . ' داده شد';
                } elseif (!$rf->isVariadic() && $args > $max) {
                    $problems[] = basename($file) . ': ' . $name . '() حداکثر ' . $max . ' آرگومان می‌گیرد، ' . $args . ' داده شد';
                }
                $checked++;
            } elseif ($isCall && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)
                && !in_array(strtolower($name), ['true', 'false', 'null', 'void', 'never', 'mixed', 'int', 'string', 'bool', 'float', 'array', 'object', 'iterable', 'self', 'static', 'parent', 'callable'], true)) {

                if (!isset($repoFunctions, $phpBuiltins)) {
                    // همه توابع تعریف‌شده در کل مخزن (نه فقط includes/)
                    $repoFunctions = [];
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH, RecursiveDirectoryIterator::SKIP_DOTS));
                    foreach ($it as $f) {
                        if ($f->getExtension() !== 'php') continue;
                        $path = str_replace('\\', '/', $f->getPathname());
                        if (preg_match('~/(\.git|node_modules|vendor)/~', $path)) continue;
                        if (preg_match_all('/^\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', (string)file_get_contents($path), $mm)) {
                            foreach ($mm[1] as $fn) $repoFunctions[strtolower($fn)] = true;
                        }
                    }
                    $phpBuiltins = array_flip(array_map('strtolower', get_defined_functions()['internal']));
                }

                // فقط توابعی که هیچ‌جای مخزن تعریف نشده‌اند خطای واقعی‌اند.
                // «تعریف‌شده ولی require نشده» را تست رندر صفحه (tools/render-test.php) می‌گیرد،
                // چون زنجیره require هر صفحه متفاوت است و اینجا قابل تشخیص نیست.
                $key = strtolower($name);
                if (!function_exists($name) && !isset($phpBuiltins[$key]) && !isset($repoFunctions[$key])) {
                    $problems[] = basename($file) . ': تابع وجود ندارد → ' . $name . '()';
                }
            }
        }
    }
}

if (!$problems) {
    echo "✅ همه فراخوانی‌ها با API واقعی سازگارند (" . fa_number($checked) . " فراخوانی بررسی شد)\n";
    exit(0);
}

echo "❌ " . count($problems) . " ناسازگاری پیدا شد:\n";
foreach (array_unique($problems) as $p) echo "   • " . $p . "\n";
exit(1);
