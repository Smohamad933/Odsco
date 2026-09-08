<?php
/**
 * ============================================================================
 *  Odsco — نصب‌کننده
 * ----------------------------------------------------------------------------
 *  مرحله ۱: اطلاعات MySQL   →  مرحله ۲: ساخت جدول‌ها  →
 *  مرحله ۳: مهاجرت داده‌های قبلی  →  مرحله ۴: حساب مدیر  →  پایان
 *
 *  بعد از نصب، فایل includes/config.local.php ساخته می‌شود و این صفحه قفل می‌شود.
 * ============================================================================
 */

declare(strict_types=1);

if (!defined('ODSCO_ROOT')) define('ODSCO_ROOT', dirname(__DIR__));
if (!defined('BASE_PATH'))   define('BASE_PATH', ODSCO_ROOT);
if (!defined('DATA_PATH'))   define('DATA_PATH', ODSCO_ROOT . '/data');
if (!defined('UPLOAD_PATH')) define('UPLOAD_PATH', ODSCO_ROOT . '/uploads');

require_once ODSCO_ROOT . '/includes/db.php';
require_once ODSCO_ROOT . '/includes/schema.php';
require_once ODSCO_ROOT . '/includes/helpers.php';
require_once ODSCO_ROOT . '/includes/jalali.php';

session_start();

$CONFIG_FILE = ODSCO_ROOT . '/includes/config.local.php';
$LOCK_FILE   = ODSCO_ROOT . '/install/installed.lock';
$step        = (int)($_GET['step'] ?? $_POST['step'] ?? 1);
$errors      = [];
$ok          = [];

// اگر قبلاً نصب شده
if (is_file($CONFIG_FILE) && !isset($_GET['force'])) {
    $locked = true;
} else {
    $locked = false;
}

/** تست اتصال با اطلاعات داده‌شده */
function try_connect(array $cfg): array
{
    try {
        Db::reset();
        Db::boot($cfg);
        $db = Db::i();
        $version = $db->isMysql()
            ? (string)$db->val('SELECT VERSION()')
            : 'SQLite ' . (string)$db->val('SELECT sqlite_version()');
        return ['ok' => true, 'version' => $version, 'tables' => $db->tables()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ===========================================================================
// پردازش فرم‌ها
// ===========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_db') {
        $cfg = [
            'driver'  => 'mysql',
            'host'    => trim((string)($_POST['db_host'] ?? 'localhost')),
            'port'    => (int)($_POST['db_port'] ?? 3306),
            'name'    => trim((string)($_POST['db_name'] ?? '')),
            'user'    => trim((string)($_POST['db_user'] ?? '')),
            'pass'    => (string)($_POST['db_pass'] ?? ''),
            'charset' => 'utf8mb4',
            'prefix'  => trim((string)($_POST['db_prefix'] ?? '')),
            'debug'   => false,
        ];

        if ($cfg['name'] === '') $errors[] = 'نام دیتابیس را وارد کنید';
        if ($cfg['user'] === '') $errors[] = 'نام کاربری دیتابیس را وارد کنید';

        if (!$errors) {
            $res = try_connect($cfg);
            if (!$res['ok']) {
                $errors[] = 'اتصال برقرار نشد: ' . $res['error'];
            } else {
                $_SESSION['install_db'] = $cfg;
                $_SESSION['install_db_version'] = $res['version'];
                header('Location: ?step=2');
                exit;
            }
        }
        $step = 1;
    }

    if ($action === 'create_schema') {
        $cfg = $_SESSION['install_db'] ?? null;
        if (!$cfg) { header('Location: ?step=1'); exit; }

        Db::reset();
        Db::boot($cfg);
        try {
            $res = odsco_install_schema(Db::i());
            $_SESSION['install_schema'] = $res;
            $ok[] = count($res['created']) . ' جدول ساخته شد و ' . $res['indexes'] . ' ایندکس ایجاد شد.';
            header('Location: ?step=3');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'خطا در ساخت جدول‌ها: ' . $e->getMessage();
            $step = 2;
        }
    }

    if ($action === 'migrate') {
        $cfg = $_SESSION['install_db'] ?? null;
        if (!$cfg) { header('Location: ?step=1'); exit; }

        Db::reset();
        Db::boot($cfg);
        require_once ODSCO_ROOT . '/includes/repo.php';
        require_once ODSCO_ROOT . '/includes/migrate.php';

        try {
            $res = Migrator::run();
            $_SESSION['install_migrate'] = $res;
            header('Location: ?step=4');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'خطا در مهاجرت: ' . $e->getMessage();
            $step = 3;
        }
    }

    if ($action === 'finish') {
        $cfg = $_SESSION['install_db'] ?? null;
        if (!$cfg) { header('Location: ?step=1'); exit; }

        Db::reset();
        Db::boot($cfg);
        require_once ODSCO_ROOT . '/includes/repo.php';
        require_once ODSCO_ROOT . '/includes/auth.php';
        require_once ODSCO_ROOT . '/includes/automation.php';
        require_once ODSCO_ROOT . '/includes/messenger.php';
        require_once ODSCO_ROOT . '/includes/attendance.php';

        $username = trim((string)($_POST['admin_username'] ?? ''));
        $password = (string)($_POST['admin_password'] ?? '');
        $fullname = trim((string)($_POST['admin_fullname'] ?? ''));
        $email    = trim((string)($_POST['admin_email'] ?? ''));
        $mode     = (string)($_POST['admin_mode'] ?? 'new');

        if ($mode === 'new') {
            if ($username === '' || strlen($username) < 3) $errors[] = 'نام کاربری حداقل ۳ کاراکتر';
            if (strlen($password) < 8)                    $errors[] = 'رمز عبور حداقل ۸ کاراکتر';
            if ($fullname === '')                          $errors[] = 'نام و نام خانوادگی را وارد کنید';
            if ($username !== '' && Users::exists($username)) $errors[] = 'این نام کاربری وجود دارد';
        }

        if (!$errors) {
            try {
                if ($mode === 'new') {
                    Users::create([
                        'username' => $username, 'password' => $password, 'role' => 'admin',
                        'full_name' => $fullname, 'email' => $email,
                        'messenger_enabled' => true, 'attendance_enabled' => true,
                    ]);
                } else {
                    // ارتقای اولین کاربر موجود به مدیرعامل + فعال‌سازی پیام‌رسان
                    $all = Users::list();
                    if ($all) {
                        Users::update($all[0]['uid'], [
                            'role' => 'admin', 'messenger_enabled' => true,
                            'attendance_enabled' => true, 'is_active' => true,
                        ]);
                        $username = $all[0]['username'];
                    }
                }

                // فعال‌سازی پیام‌رسان و حضور و غیاب برای همه کاربران فعال
                foreach (Users::list(['active' => true]) as $u) {
                    if (!$u['messenger_enabled']) {
                        Users::update($u['uid'], ['messenger_enabled' => true, 'attendance_enabled' => true]);
                    }
                }

                // نصب قالب‌های آماده اتوماسیون
                Automation::installTemplates();

                // مقادیر پیش‌فرض تنظیمات
                foreach (Settings::defaults() as $k => $v) {
                    if (Settings::get($k) === $v) Settings::set($k, $v);
                }

                // نوشتن فایل پیکربندی
                $php = "<?php\n"
                     . "/**\n"
                     . " * پیکربندی دیتابیس — ساخته‌شده توسط نصب‌کننده Odsco\n"
                     . " * این فایل را در Git قرار ندهید.\n"
                     . " */\n"
                     . "return " . var_export($cfg, true) . ";\n";

                if (!@file_put_contents($CONFIG_FILE, $php, LOCK_EX)) {
                    $errors[] = 'امکان نوشتن فایل includes/config.local.php نیست. دسترسی نوشتن به پوشه includes را بررسی کنید.';
                    $step = 4;
                } else {
                    @chmod($CONFIG_FILE, 0640);
                    @file_put_contents($LOCK_FILE, date('Y-m-d H:i:s'));
                    ActivityLog::add('install', 'نصب و راه‌اندازی سیستم انجام شد', $username);
                    header('Location: ?step=5&u=' . urlencode($username));
                    exit;
                }
            } catch (Throwable $e) {
                $errors[] = 'خطا: ' . $e->getMessage();
                $step = 4;
            }
        } else {
            $step = 4;
        }
    }
}

// بارگذاری پیکربندی موقت برای نمایش وضعیت
if (!empty($_SESSION['install_db']) && Db::ready() === false) {
    try { Db::boot($_SESSION['install_db']); } catch (Throwable) { /* ignore */ }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>نصب Odsco</title>
<style>
@font-face { font-family:'Ab'; src:url('../assets/abarfanum-vf.ttf') format('truetype'); font-weight:100 900; }
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0e1621;--card:#17212b;--line:#0b1219;--tx:#fff;--mut:#8698ab;--ac:#5288c1;--ok:#4dcd5e;--err:#ff5c5c;--warn:#ffb454}
body{font-family:'Ab',Tahoma,sans-serif;background:var(--bg);color:var(--tx);min-height:100vh;padding:20px 14px 60px}
.wrap{max-width:760px;margin:0 auto}
.hero{text-align:center;padding:26px 0 18px}
.hero .logo{width:72px;height:72px;border-radius:22px;background:linear-gradient(135deg,#5288c1,#2b5278);display:flex;align-items:center;justify-content:center;font-size:34px;margin:0 auto 14px;box-shadow:0 12px 32px rgba(82,136,193,.32)}
.hero h1{font-size:22px;font-weight:800}
.hero p{color:var(--mut);font-size:13px;margin-top:6px}
.steps{display:flex;gap:6px;margin:18px 0 22px;flex-wrap:wrap}
.steps span{flex:1;min-width:70px;text-align:center;font-size:11px;padding:9px 6px;border-radius:10px;background:var(--card);color:var(--mut);border:1px solid var(--line)}
.steps span.on{background:var(--ac);color:#fff;border-color:var(--ac)}
.steps span.done{background:rgba(77,205,94,.14);color:var(--ok);border-color:rgba(77,205,94,.3)}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:22px}
.card h2{font-size:16px;margin-bottom:6px}
.card .sub{color:var(--mut);font-size:12.5px;line-height:2;margin-bottom:18px}
label{display:block;font-size:12.5px;color:var(--mut);margin:14px 0 6px;font-weight:600}
input[type=text],input[type=password],input[type=email],input[type=number]{
 width:100%;padding:12px 14px;background:var(--bg);border:1px solid var(--line);border-radius:11px;
 color:var(--tx);font-family:inherit;font-size:14px;outline:none;transition:.2s}
input:focus{border-color:var(--ac)}
.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:560px){.row{grid-template-columns:1fr}}
.btn{display:inline-flex;align-items:center;gap:8px;padding:13px 24px;background:var(--ac);color:#fff;border:0;
 border-radius:12px;font-family:inherit;font-size:14px;font-weight:700;cursor:pointer;margin-top:22px;transition:.2s;text-decoration:none}
.btn:hover{background:#6a9fd0}
.btn.ghost{background:transparent;border:1px solid var(--line);color:var(--mut)}
.btn.ghost:hover{color:#fff;border-color:var(--ac)}
.err{background:rgba(255,92,92,.1);border:1px solid rgba(255,92,92,.3);color:#ff9d9d;padding:12px 14px;border-radius:12px;font-size:12.5px;line-height:2;margin-bottom:14px;direction:rtl}
.ok{background:rgba(77,205,94,.1);border:1px solid rgba(77,205,94,.3);color:#9ee7a8;padding:12px 14px;border-radius:12px;font-size:12.5px;line-height:2;margin-bottom:14px}
.note{background:rgba(255,180,84,.09);border:1px solid rgba(255,180,84,.25);color:#ffd9a0;padding:12px 14px;border-radius:12px;font-size:12px;line-height:2;margin-top:18px}
table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:10px}
td,th{padding:9px 10px;border-bottom:1px solid var(--line);text-align:right}
th{color:var(--mut);font-weight:600;font-size:11.5px}
td b{color:var(--ac)}
code{background:var(--bg);padding:2px 7px;border-radius:6px;font-size:11.5px;direction:ltr;display:inline-block;color:#8fc7ff}
.chk{display:flex;gap:10px;align-items:center;padding:11px 13px;background:var(--bg);border-radius:11px;margin-top:10px;font-size:13px}
.chk i{font-style:normal}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:560px){.grid2{grid-template-columns:1fr}}
.big{font-size:44px;text-align:center;margin:12px 0}
.center{text-align:center}
</style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <div class="logo">⚙️</div>
        <h1>نصب‌کننده Odsco</h1>
        <p>نسخه <?php echo defined('ODSCO_VERSION') ? ODSCO_VERSION : '2.0.0'; ?> — سایت + پیام‌رسان + اتوماسیون</p>
    </div>

    <div class="steps">
        <?php
        $titles = [1 => 'دیتابیس', 2 => 'جدول‌ها', 3 => 'مهاجرت', 4 => 'مدیر', 5 => 'پایان'];
        foreach ($titles as $n => $t) {
            $cls = $n < $step ? 'done' : ($n === $step ? 'on' : '');
            echo '<span class="' . $cls . '">' . ($n < $step ? '✓ ' : '') . $t . '</span>';
        }
        ?>
    </div>

    <?php foreach ($errors as $e): ?><div class="err">⛔ <?php echo e($e); ?></div><?php endforeach; ?>
    <?php foreach ($ok as $o): ?><div class="ok">✅ <?php echo e($o); ?></div><?php endforeach; ?>

    <?php if ($locked): ?>
    <div class="card center">
        <div class="big">🔒</div>
        <h2>سیستم قبلاً نصب شده است</h2>
        <p class="sub">فایل <code>includes/config.local.php</code> وجود دارد.</p>
        <a class="btn" href="../admin/login.php">ورود به پنل مدیریت</a>
        <a class="btn ghost" href="?force=1">نصب مجدد</a>
        <div class="note">⚠️ نصب مجدد جدول‌های موجود را تغییر نمی‌دهد، ولی فایل پیکربندی را بازنویسی می‌کند.</div>
    </div>

    <?php elseif ($step === 1): ?>
    <div class="card">
        <h2>۱) اطلاعات MySQL</h2>
        <p class="sub">
            روی هاست اشتراکی (cPanel / DirectAdmin) این اطلاعات را از پنل بردارید.
            روی سرور اختصاصی با <b>IIS</b>، همان کاربری است که MySQL برایش ساخته‌اید
            (معمولاً <code>root</code> یا یک کاربر اختصاصی) و آدرس هم <code>127.0.0.1</code> است.
            دیتابیس باید از قبل ساخته شده باشد.
        </p>
        <p class="sub">🩺 اگر مطمئن نیستید سرور آماده است، اول <a href="check.php" style="color:#5288c1">صفحه بررسی سرور</a> را باز کنید.</p>
        <form method="post">
            <input type="hidden" name="action" value="save_db">
            <input type="hidden" name="step" value="1">
            <div class="row">
                <div>
                    <label>آدرس سرور (Host)</label>
                    <input type="text" name="db_host" value="<?php echo e($_POST['db_host'] ?? 'localhost'); ?>" required>
                </div>
                <div>
                    <label>پورت</label>
                    <input type="number" name="db_port" value="<?php echo e($_POST['db_port'] ?? '3306'); ?>">
                </div>
            </div>
            <label>نام دیتابیس</label>
            <input type="text" name="db_name" value="<?php echo e($_POST['db_name'] ?? ''); ?>" placeholder="username_odsco" required>
            <div class="row">
                <div>
                    <label>نام کاربری</label>
                    <input type="text" name="db_user" value="<?php echo e($_POST['db_user'] ?? ''); ?>" required>
                </div>
                <div>
                    <label>رمز عبور</label>
                    <input type="password" name="db_pass" value="<?php echo e($_POST['db_pass'] ?? ''); ?>">
                </div>
            </div>
            <label>پیشوند جدول‌ها (اختیاری)</label>
            <input type="text" name="db_prefix" value="<?php echo e($_POST['db_prefix'] ?? ''); ?>" placeholder="odsco_">
            <button class="btn" type="submit">تست اتصال و ادامه ←</button>
        </form>
        <div class="note">
            💡 اگر از <b>Socket</b> استفاده می‌کنید، آدرس سرور را <code>localhost</code> بگذارید.<br>
            🔐 رمز عبور فقط در فایل <code>includes/config.local.php</code> روی سرور خودتان ذخیره می‌شود.
        </div>
    </div>

    <?php elseif ($step === 2): ?>
    <div class="card">
        <h2>۲) ساخت جدول‌های دیتابیس</h2>
        <p class="sub">اتصال موفق بود. نسخه MySQL شما: <code><?php echo e($_SESSION['install_db_version'] ?? ''); ?></code></p>
        <div class="chk"><i>✅</i> اتصال به دیتابیس <b><?php echo e($_SESSION['install_db']['name'] ?? ''); ?></b> برقرار شد</div>
        <div class="chk"><i>📋</i> <?php echo count(odsco_tables()); ?> جدول و <?php echo count(odsco_indexes()); ?> ایندکس ساخته می‌شود</div>
        <div class="chk"><i>🔁</i> اجرا چندبار بی‌خطر است (جدول موجود دست‌نخورده می‌ماند)</div>
        <form method="post">
            <input type="hidden" name="action" value="create_schema">
            <button class="btn" type="submit">ساخت جدول‌ها ←</button>
        </form>
    </div>

    <?php elseif ($step === 3):
        $schema = $_SESSION['install_schema'] ?? ['created' => [], 'skipped' => [], 'indexes' => 0];
        $hasJson = is_dir(DATA_PATH) && count(glob(DATA_PATH . '/*.json') ?: []) > 0;
    ?>
    <div class="card">
        <h2>۳) مهاجرت داده‌های قبلی</h2>
        <p class="sub"><?php echo count($schema['created']); ?> جدول جدید ساخته شد، <?php echo count($schema['skipped']); ?> جدول از قبل وجود داشت، <?php echo (int)$schema['indexes']; ?> ایندکس ایجاد شد.</p>

        <?php if ($hasJson): ?>
            <div class="ok">📦 فایل‌های JSON قدیمی در پوشه <code>data/</code> پیدا شد. کاربران (با همان رمزهای عبور)، پروژه‌ها، مقالات، تیم، کارفرمایان، پیام‌های پیام‌رسان و گروه‌ها به MySQL منتقل می‌شوند.</div>
        <?php else: ?>
            <div class="note">ℹ️ فایل JSON قدیمی پیدا نشد — سیستم خالی نصب می‌شود.</div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="action" value="migrate">
            <button class="btn" type="submit">شروع مهاجرت ←</button>
        </form>
    </div>

    <?php elseif ($step === 4):
        $mig = $_SESSION['install_migrate'] ?? ['counts' => [], 'errors' => []];
        require_once ODSCO_ROOT . '/includes/automation.php';
        $automationTemplates = Automation::templates();
    ?>
    <div class="card">
        <h2>۴) حساب مدیرعامل</h2>
        <?php if ($mig['counts']): ?>
        <p class="sub">نتیجه مهاجرت:</p>
        <table>
            <tr><th>بخش</th><th>تعداد</th></tr>
            <?php foreach ($mig['counts'] as $k => $v): ?>
                <tr><td><?php echo e($k); ?></td><td><b><?php echo fa_number($v); ?></b></td></tr>
            <?php endforeach; ?>
        </table>
        <?php if (!empty($mig['errors'])): ?>
            <div class="err" style="margin-top:14px"><?php foreach ($mig['errors'] as $er): ?>• <?php echo e($er); ?><br><?php endforeach; ?></div>
        <?php endif; ?>
        <?php endif; ?>

        <form method="post" style="margin-top:20px">
            <input type="hidden" name="action" value="finish">
            <label>حساب مدیرعامل</label>
            <div class="chk" style="cursor:pointer">
                <input type="radio" name="admin_mode" value="new" id="m1" checked style="width:auto">
                <label for="m1" style="margin:0;cursor:pointer">ساخت حساب جدید</label>
            </div>
            <div class="chk" style="cursor:pointer">
                <input type="radio" name="admin_mode" value="promote" id="m2" style="width:auto">
                <label for="m2" style="margin:0;cursor:pointer">ارتقای اولین کاربر موجود به مدیرعامل (رمز قبلی حفظ می‌شود)</label>
            </div>

            <div id="newAdminFields">
                <div class="row">
                    <div><label>نام کاربری</label><input type="text" name="admin_username" value="admin"></div>
                    <div><label>رمز عبور (حداقل ۸ کاراکتر)</label><input type="password" name="admin_password" placeholder="••••••••"></div>
                </div>
                <div class="row">
                    <div><label>نام و نام خانوادگی</label><input type="text" name="admin_fullname" placeholder="مدیرعامل"></div>
                    <div><label>ایمیل (اختیاری)</label><input type="email" name="admin_email"></div>
                </div>
            </div>

            <button class="btn" type="submit">پایان نصب ✓</button>
        </form>
        <div class="note">
            ⚙️ در این مرحله همچنین انجام می‌شود:<br>
            • فعال‌سازی پیام‌رسان و حضور و غیاب برای همه کاربران فعال<br>
            • نصب <?php echo count($automationTemplates); ?> قالب آماده اتوماسیون<br>
            • نوشتن فایل پیکربندی و قفل‌کردن نصب‌کننده
        </div>
    </div>
    <script>
    document.querySelectorAll('input[name=admin_mode]').forEach(function (r) {
        r.addEventListener('change', function () {
            document.getElementById('newAdminFields').style.display = this.value === 'new' ? '' : 'none';
        });
    });
    </script>

    <?php else: ?>
    <div class="card center">
        <div class="big">🎉</div>
        <h2>نصب با موفقیت انجام شد</h2>
        <p class="sub">نام کاربری مدیر: <code><?php echo e($_GET['u'] ?? 'admin'); ?></code></p>
        <div class="grid2" style="margin-top:22px">
            <a class="btn" href="../admin/login.php">🏗️ پنل مدیریت</a>
            <a class="btn ghost" href="../messenger/login.php">💬 پیام‌رسان</a>
            <a class="btn ghost" href="../client/login.php">🏢 پنل کارفرما</a>
            <a class="btn ghost" href="../index.php">🌐 سایت</a>
        </div>
        <div class="note" style="text-align:right">
            <b>کارهای بعد از نصب:</b><br>
            ۱) پوشه <code>install/</code> را از روی هاست پاک کنید یا تغییر نام دهید.<br>
            ۲) برای اجرای روزانه اتوماسیون این cron را اضافه کنید:<br>
            <code>0 7 * * * php <?php echo e(ODSCO_ROOT); ?>/tools/cron.php</code><br>
            ۳) از طریق <b>پنل مدیریت ← تنظیمات</b> مدت نگه‌داری پیام‌ها روی سرور را تعیین کنید.
        </div>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
