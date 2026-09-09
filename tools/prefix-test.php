<?php
/**
 * ============================================================================
 *  Odsco — تست پیشوند جدول‌ها
 * ----------------------------------------------------------------------------
 *  Db::insert/update/delete خودشان t() را روی نام جدول صدا می‌زنند و صدها جای
 *  کد هم از قبل t() زده‌اند. اگر t() idempotent نباشد، هر پیشوندِ غیرخالی نام
 *  جدول را دوبرابر می‌کند (odsco_odsco_users) و کل سایت با «no such table»
 *  از کار می‌افتد. این باگ با پیشوند خالی دیده نمی‌شد.
 *
 *  این تست عمداً config.php را بارگذاری نمی‌کند تا بتواند Db را با پیشوند
 *  دلخواه boot کند.
 *
 *  اجرا:  php tools/prefix-test.php
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/repo.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✅ $label\n"; }
    else { $fail++; echo "  ❌ $label" . ($detail !== '' ? " → $detail" : '') . "\n"; }
}

$PREFIX = 'zztest_';
$dbFile = sys_get_temp_dir() . '/odsco_prefix_test.sqlite';
@unlink($dbFile);

echo "🧩 تست پیشوند جدول‌ها (prefix = «{$PREFIX}»)\n";

$db = Db::boot([
    'driver'      => 'sqlite',
    'sqlite_path' => $dbFile,
    'prefix'      => $PREFIX,
]);

check('t() پیشوند را اضافه می‌کند', $db->t('users') === $PREFIX . 'users', $db->t('users'));
check('t() idempotent است (پیشوند دوبرابر نمی‌شود)',
    $db->t($PREFIX . 'users') === $PREFIX . 'users', $db->t($PREFIX . 'users'));
check('t() با نام خالی پیشونددار همان را برمی‌گرداند',
    $db->t($PREFIX . 'projects') === $PREFIX . 'projects', $db->t($PREFIX . 'projects'));

$r = odsco_install_schema($db);
check('اسکیمای ۳۴ جدولی با پیشوند ساخته شد', count($r['created']) === 34, 'created=' . count($r['created']));

$tables = $db->tables();
$bad = array_values(array_filter($tables, fn($t) => str_starts_with($t, $PREFIX . $PREFIX)));
check('هیچ جدولی پیشوند دوبرابر ندارد', $bad === [], implode(', ', $bad));
check('همه جدول‌ها پیشوند دارند',
    count(array_filter($tables, fn($t) => str_starts_with($t, $PREFIX))) === count($tables),
    count($tables) . ' جدول');

// چرخهٔ کامل نوشتن/خواندن — همان مسیری که با باگ می‌شکست
$uid = Users::create([
    'username' => 'prefix_probe', 'password' => 'PrefixPass123',
    'role' => 'admin', 'full_name' => 'کاوشگر پیشوند',
]);
check('Users::create با پیشوند کار می‌کند', is_array($uid) && !empty($uid['uid']),
    is_array($uid) ? ($uid['uid'] ?? 'بدون uid') : gettype($uid));

$found = Users::findByUsername('prefix_probe');
check('Users::findByUsername با پیشوند کار می‌کند', $found !== null && ($found['full_name'] ?? '') === 'کاوشگر پیشوند',
    $found ? 'ok' : 'null');

$puid = Projects::create(['title' => 'پروژه پیشوند', 'status' => 'active', 'progress' => 10]);
check('Projects::create با پیشوند کار می‌کند', is_string($puid) && $puid !== '', var_export($puid, true));
check('Projects::list با پیشوند کار می‌کند', count(Projects::list()) === 1, (string)count(Projects::list()));

@unlink($dbFile);

echo "\n════════════════════════════════════════\n";
echo "نتیجه پیشوند: {$pass} موفق / {$fail} ناموفق\n";
echo "════════════════════════════════════════\n";
exit($fail === 0 ? 0 : 1);
