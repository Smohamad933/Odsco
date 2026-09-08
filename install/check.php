<?php
/**
 * ============================================================================
 *  Odsco — بررسی سرور
 * ----------------------------------------------------------------------------
 *  قبل از نصب، این صفحه را باز کنید:   yoursite.com/install/check.php
 *
 *  فقط می‌خواند و گزارش می‌دهد؛ هیچ فایلی نمی‌سازد و هیچ چیزی را تغییر نمی‌دهد.
 *  برای IIS طراحی شده: نسخه PHP، افزونه pdo_mysql، دسترسی نوشتن پوشه‌ها
 *  و در نهایت اتصال واقعی به MySQL را بررسی می‌کند.
 * ============================================================================
 */

declare(strict_types=1);

define('ODSCO_ROOT', dirname(__DIR__));

$CONFIG_FILE = ODSCO_ROOT . '/includes/config.local.php';
$LOCK_FILE   = ODSCO_ROOT . '/install/installed.lock';

/** @var array<int, array{group: string, label: string, state: 'ok'|'warn'|'err', detail: string, hint: string}> */
$rows  = [];
$block = 0;   // تعداد موارد بازدارنده
$warn  = 0;

function add(string $group, string $label, string $state, string $detail = '', string $hint = ''): void
{
    global $rows, $block, $warn;
    $rows[] = ['group' => $group, 'label' => $label, 'state' => $state, 'detail' => $detail, 'hint' => $hint];
    if ($state === 'err')  $block++;
    if ($state === 'warn') $warn++;
}

/*
 * نام این تابع عمداً chk_e است: includes/helpers.php هم تابع e() دارد و اگر
 * روزی این صفحه همراه آن بارگذاری شود، تعریف دوباره خطا می‌دهد.
 */
function chk_e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// ۱) نسخه و نوع PHP
// ---------------------------------------------------------------------------
$G = '۱) PHP';

$verOk = PHP_VERSION_ID >= 80100;
add($G, 'نسخه PHP', $verOk ? 'ok' : 'err', PHP_VERSION,
    $verOk ? 'حداقل لازم ۸.۱ است.' : 'این پروژه به PHP ۸.۱ یا بالاتر نیاز دارد. در IIS Manager → Handler Mappings نسخه PHP سایت را عوض کنید.');

$isIis = str_contains(strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? '')), 'iis');
add($G, 'نوع اجرا', $isIis ? 'ok' : 'warn',
    (string)($_SERVER['SERVER_SOFTWARE'] ?? 'نامشخص') . ' / ' . PHP_SAPI,
    $isIis ? '' : 'این صفحه برای IIS بهینه شده ولی روی سایر وب‌سرورها هم کار می‌کند.');

add($G, 'باینری PHP در حال اجرا', 'warn', (string)(PHP_BINARY ?: 'نامشخص'),
    'در web.config پروژه مسیر C:\\php\\php-cgi.exe نوشته شده. اگر PHP شما جای دیگری نصب است، همان مسیر را در web.config بگذارید.');

$ini = (string)(php_ini_loaded_file() ?: 'php.ini بارگذاری نشده');
add($G, 'فایل php.ini فعال', 'warn', $ini,
    'افزونه‌ها و سقف آپلود را باید در همین فایل تنظیم کنید.');

$up = min(
    (int)ini_get('upload_max_filesize'),
    (int)ini_get('post_max_size')
);
add($G, 'سقف آپلود PHP', $up >= 32 ? 'ok' : 'warn',
    'upload_max_filesize=' . (string)ini_get('upload_max_filesize') . ' / post_max_size=' . (string)ini_get('post_max_size'),
    $up >= 32 ? '' : 'برای فایل‌های پیام‌رسان هر دو را روی 64M یا بیشتر بگذارید.');

// ---------------------------------------------------------------------------
// ۲) افزونه‌ها
// ---------------------------------------------------------------------------
$G = '۲) افزونه‌های PHP';

$drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
$hasMysql = in_array('mysql', $drivers, true);
add($G, 'pdo_mysql (اتصال به MySQL)', $hasMysql ? 'ok' : 'err',
    $drivers ? 'درایورهای PDO: ' . implode('، ', $drivers) : 'PDO هیچ درایوری ندارد',
    $hasMysql ? '' : 'در php.ini خط ;extension=pdo_mysql را از حالت کامنت خارج کنید و Application Pool را در IIS ری‌استارت کنید.');

foreach ([
    'mbstring' => ['ضروری — رشته‌های فارسی', true],
    'json'     => ['ضروری — API پیام‌رسان', true],
    'fileinfo' => ['ضروری — تشخیص نوع فایل آپلودی', true],
    'openssl'  => ['توصیه‌شده', false],
    'curl'     => ['توصیه‌شده', false],
    'gd'       => ['برای بهینه‌سازی تصاویر در کتابخانه رسانه', false],
    'zip'      => ['برای خروجی گرفتن', false],
] as $ext => [$label, $required]) {
    $on = extension_loaded((string)$ext);
    add($G, "$ext — $label", $on ? 'ok' : ($required ? 'err' : 'warn'),
        $on ? 'فعال' : 'غیرفعال',
        $on || !$required ? '' : "در php.ini خط ;extension=$ext را فعال کنید.");
}

// ---------------------------------------------------------------------------
// ۳) دسترسی نوشتن
// ---------------------------------------------------------------------------
$G = '۳) دسترسی نوشتن';

/** نوشتن یک فایل آزمایشی و پاک‌کردنش */
function writable(string $dir, bool $mustExist = true): array
{
    if (!is_dir($dir)) {
        return [$mustExist ? 'err' : 'warn', 'پوشه وجود ندارد', 'پوشه را بسازید یا از حالت فشرده کامل خارج کنید.'];
    }
    $probe = $dir . DIRECTORY_SEPARATOR . '.odsco_write_' . bin2hex(random_bytes(4));
    if (@file_put_contents($probe, 'x') === false) {
        return ['err', 'نوشتن ممکن نیست',
            'در IIS به IIS_IUSRS و identity مربوط به Application Pool دسترسی Modify بدهید.'];
    }
    @unlink($probe);
    return ['ok', 'قابل نوشتن', ''];
}

[$st, $dt, $hn] = writable(ODSCO_ROOT . '/includes');
add($G, 'includes/ (محل config.local.php)', $st, $dt, $hn);

[$st, $dt, $hn] = writable(ODSCO_ROOT . '/uploads');
add($G, 'uploads/ (فایل‌های پیام‌رسان و رسانه)', $st, $dt, $hn);

[$st, $dt, $hn] = writable(ODSCO_ROOT . '/data', false);
add($G, 'data/ (فایل‌های JSON قدیمی)', $st, $dt, $hn);

[$st, $dt, $hn] = writable(ODSCO_ROOT . '/install');
add($G, 'install/ (فایل قفل نصب)', $st, $dt, $hn);

$tmp = sys_get_temp_dir();
[$st, $dt, $hn] = writable($tmp);
add($G, 'مسیر موقت PHP', $st, $tmp . ' — ' . $dt, $hn);

// ---------------------------------------------------------------------------
// ۴) فایل پیکربندی
// ---------------------------------------------------------------------------
$G = '۴) پیکربندی دیتابیس';

$cfg = null;
if (is_file($CONFIG_FILE)) {
    $loaded = @include $CONFIG_FILE;
    if (is_array($loaded)) {
        $cfg = $loaded;
        add($G, 'includes/config.local.php', 'ok', 'موجود و قابل خواندن', '');
    } else {
        add($G, 'includes/config.local.php', 'err', 'موجود است ولی آرایه برنمی‌گرداند',
            'فایل را پاک کنید و نصب‌کننده را دوباره اجرا کنید.');
    }
} else {
    add($G, 'includes/config.local.php', 'warn', 'هنوز ساخته نشده',
        'طبیعی است — نصب‌کننده در پایان این فایل را می‌سازد. آدرس: install/');
}

add($G, 'install/installed.lock', is_file($LOCK_FILE) ? 'ok' : 'warn',
    is_file($LOCK_FILE) ? 'نصب قبلاً انجام شده' : 'نصب هنوز انجام نشده', '');

// ---------------------------------------------------------------------------
// ۵) اتصال واقعی به MySQL
// ---------------------------------------------------------------------------
$G = '۵) اتصال به MySQL';

if (is_array($cfg)) {
    $driver = strtolower((string)($cfg['driver'] ?? 'mysql'));
    $shown  = sprintf('%s://%s@%s:%s/%s',
        $driver,
        (string)($cfg['user'] ?? ''),
        (string)($cfg['host'] ?? '127.0.0.1'),
        (string)($cfg['port'] ?? ($driver === 'mysql' ? 3306 : '-')),
        (string)($cfg['name'] ?? '')
    );
    add($G, 'محتوای پیکربندی (بدون نمایش رمز)', 'ok', $shown, 'رمز عبور در این صفحه نمایش داده نمی‌شود.');

    require_once ODSCO_ROOT . '/includes/db.php';
    try {
        Db::reset();
        Db::boot($cfg);
        $db = Db::i();

        $version = $db->isMysql()
            ? 'MySQL ' . (string)$db->val('SELECT VERSION()')
            : 'SQLite ' . (string)$db->val('SELECT sqlite_version()');
        add($G, 'اتصال برقرار شد', 'ok', $version, '');

        $tables = $db->tables();
        add($G, 'تعداد جدول‌های موجود', count($tables) >= 34 ? 'ok' : 'warn',
            count($tables) . ' جدول',
            count($tables) >= 34 ? '' : 'اسکیمای کامل ۳۴ جدول است. مرحله ۲ نصب‌کننده را اجرا کنید.');

        $utf8 = $db->isMysql() ? (string)$db->val("SELECT @@character_set_database") : 'n/a';
        add($G, 'charset دیتابیس', ($utf8 === 'utf8mb4' || !$db->isMysql()) ? 'ok' : 'warn', $utf8,
            $utf8 === 'utf8mb4' || !$db->isMysql() ? '' : 'دیتابیس را با utf8mb4 بسازید وگرنه ایموجی‌ها خراب ذخیره می‌شوند.');
    } catch (Throwable $ex) {
        add($G, 'اتصال برقرار نشد', 'err', $ex->getMessage(),
            'اگر پیغام «could not find driver» دیدید یعنی pdo_mysql فعال نیست. '
            . 'اگر «Access denied» دیدید یعنی نام کاربری/رمز اشتباه است. '
            . 'اگر «Connection refused» دیدید یعنی سرویس MySQL در services.msc اجرا نیست یا پورت اشتباه است.');
    }
} else {
    add($G, 'اتصال به MySQL', 'warn', 'چون فایل پیکربندی نیست، آزموده نشد',
        'ابتدا نصب‌کننده (install/) را اجرا کنید یا دستی includes/config.local.php را بسازید.');
}

// ---------------------------------------------------------------------------
// ۶) داده‌های قدیمی برای مهاجرت
// ---------------------------------------------------------------------------
$G = '۶) داده‌های قدیمی (data/)';

$found = 0;
foreach (glob(ODSCO_ROOT . '/data/*.json') ?: [] as $f) { $found++; }
add($G, 'فایل‌های JSON', $found > 0 ? 'ok' : 'warn',
    $found . ' فایل در data/',
    $found > 0 ? 'مرحله ۳ نصب‌کننده این‌ها را به MySQL منتقل می‌کند.' : 'اگر سایت تازه است، طبیعی است.');

// ---------------------------------------------------------------------------
// رندر
// ---------------------------------------------------------------------------
$icon = ['ok' => '✅', 'warn' => '⚠️', 'err' => '❌'];
$last = '';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>بررسی سرور — Odsco</title>
<style>
@font-face { font-family:'Ab'; src:url('../assets/abarfanum-vf.ttf') format('truetype'); font-weight:100 900; }
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0e1621;--card:#17212b;--line:#0b1219;--tx:#fff;--mut:#8698ab;--ac:#5288c1;--ok:#4dcd5e;--err:#ff5c5c;--warn:#ffb454}
body{font-family:'Ab',Tahoma,sans-serif;background:var(--bg);color:var(--tx);min-height:100vh;padding:20px 14px 60px}
.wrap{max-width:820px;margin:0 auto}
.hero{text-align:center;padding:22px 0 16px}
.hero .logo{width:70px;height:70px;border-radius:22px;background:linear-gradient(135deg,#5288c1,#2b5278);display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 12px}
.hero h1{font-size:21px;font-weight:800}
.hero p{color:var(--mut);font-size:13px;margin-top:6px}
.verdict{border-radius:16px;padding:16px 18px;margin:14px 0 20px;font-size:13.5px;line-height:2;border:1px solid}
.verdict.ok{background:rgba(77,205,94,.1);border-color:rgba(77,205,94,.3);color:#9ee7a8}
.verdict.warn{background:rgba(255,180,84,.09);border-color:rgba(255,180,84,.25);color:#ffd9a0}
.verdict.err{background:rgba(255,92,92,.1);border-color:rgba(255,92,92,.3);color:#ff9d9d}
h2.grp{font-size:13px;color:var(--ac);margin:22px 0 8px;font-weight:800}
table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden}
td{padding:10px 12px;border-bottom:1px solid var(--line);font-size:12.5px;vertical-align:top}
tr:last-child td{border-bottom:0}
td.s{width:30px;text-align:center}
td.l{width:38%;color:var(--tx)}
td.d{color:var(--mut);direction:ltr;text-align:left;font-family:Consolas,monospace;font-size:11.5px;word-break:break-all}
td.h{display:block;color:#ffd9a0;font-size:11.5px;margin-top:5px;direction:rtl;text-align:right;font-family:'Ab',Tahoma,sans-serif}
.acts{margin-top:26px;display:flex;gap:10px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;background:var(--ac);color:#fff;border:0;border-radius:12px;font-family:inherit;font-size:13.5px;font-weight:700;text-decoration:none}
.btn.ghost{background:transparent;border:1px solid var(--line);color:var(--mut)}
code{background:#0b1219;padding:1px 6px;border-radius:5px;direction:ltr;display:inline-block;font-size:11.5px}
</style>
</head>
<body>
<div class="wrap">
  <div class="hero">
    <div class="logo">🩺</div>
    <h1>بررسی سرور</h1>
    <p>این صفحه فقط می‌خواند؛ هیچ فایلی نمی‌سازد و چیزی را تغییر نمی‌دهد.</p>
  </div>

  <?php
  $cls = $block > 0 ? 'err' : ($warn > 0 ? 'warn' : 'ok');
  $msg = $block > 0
      ? "❌ <b>" . chk_e($block) . "</b> مورد بازدارنده پیدا شد. تا رفع آن‌ها نصب کامل نمی‌شود."
      : ($warn > 0
          ? "⚠️ سرور آماده نصب است، ولی <b>" . chk_e($warn) . "</b> هشدار وجود دارد که بهتر است ببینید."
          : "✅ سرور کاملاً آماده است. می‌توانید نصب را شروع کنید.");
  ?>
  <div class="verdict <?php echo $cls; ?>"><?php echo $msg; ?></div>

  <?php foreach ($rows as $__i => $r): ?>
    <?php if ($r['group'] !== $last): $last = $r['group']; ?>
      <h2 class="grp"><?php echo chk_e($last); ?></h2>
      <table>
    <?php endif; ?>
      <tr>
        <td class="s"><?php echo $icon[$r['state']]; ?></td>
        <td class="l"><?php echo chk_e($r['label']); ?>
          <?php if ($r['hint'] !== ''): ?><span class="h">💡 <?php echo chk_e($r['hint']); ?></span><?php endif; ?>
        </td>
        <td class="d"><?php echo chk_e($r['detail']); ?></td>
      </tr>
    <?php
    // بستن جدول وقتی گروه عوض می‌شود یا آخرین ردیف است
    $__next = $rows[$__i + 1] ?? null;
    if ($__next === null || $__next['group'] !== $r['group']) { echo '</table>'; }
    ?>
  <?php endforeach; ?>

  <div class="acts">
    <a class="btn" href="./">🚀 رفتن به نصب‌کننده</a>
    <a class="btn ghost" href="?">🔄 بررسی دوباره</a>
    <a class="btn ghost" href="../">↩️ سایت</a>
  </div>
</div>
</body>
</html>
