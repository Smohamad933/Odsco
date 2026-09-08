<?php
/**
 * ============================================================================
 *  Odsco — بوت‌استرپ مرکزی
 * ----------------------------------------------------------------------------
 *  ۱) نشست (session) و مسیرها
 *  ۲) خواندن پیکربندی دیتابیس از includes/config.local.php
 *  ۳) اتصال به MySQL
 *  ۴) بارگذاری توابع کمکی، شمسی، و لایه داده
 *  ۵) لایه سازگاری read_json()/write_json() تا کد قبلی سایت بدون تغییر کار کند
 * ============================================================================
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// ۱) نشست و مسیرها
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('ODSCO_SESS');
    session_start();
}

if (!defined('BASE_PATH'))   define('BASE_PATH',   dirname(__DIR__));
if (!defined('DATA_PATH'))   define('DATA_PATH',   BASE_PATH . '/data');
if (!defined('UPLOAD_PATH')) define('UPLOAD_PATH', BASE_PATH . '/uploads');
if (!defined('ODSCO_VERSION')) define('ODSCO_VERSION', '2.0.0');

// ---------------------------------------------------------------------------
// ۲) پیکربندی دیتابیس
// ---------------------------------------------------------------------------
$ODSCO_DB = null;
if (is_file(__DIR__ . '/config.local.php')) {
    /** @var array $ODSCO_DB */
    $ODSCO_DB = require __DIR__ . '/config.local.php';
}

// ---------------------------------------------------------------------------
// ۳) اگر نصب انجام نشده، به نصب‌کننده بفرست
// ---------------------------------------------------------------------------
$inInstaller = str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/install/');
if (!is_array($ODSCO_DB) && !$inInstaller && PHP_SAPI !== 'cli') {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    // عمق پوشه فعلی نسبت به ریشه پروژه
    $depth = substr_count(trim(dirname($script), '/'), '/');
    $prefix = str_repeat('../', $depth);
    header('Location: ' . $prefix . 'install/');
    exit;
}

// ---------------------------------------------------------------------------
// ۴) اتصال به دیتابیس + بارگذاری کتابخانه‌ها
// ---------------------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/jalali.php';
require_once __DIR__ . '/repo.php';

if (is_array($ODSCO_DB)) {
    Db::boot($ODSCO_DB);
}

// ---------------------------------------------------------------------------
// ۵) لایه سازگاری با فایل‌های JSON قدیمی
// ---------------------------------------------------------------------------

/**
 * خواندن داده — شکل خروجی دقیقاً مثل قبل است، ولی منبع آن MySQL است.
 * برای فایل‌های ناشناخته به همان فایل JSON روی دیسک برمی‌گردد.
 */
function read_json(string $file): array
{
    if (!Db::ready()) return odsco_file_read($file);

    switch ($file) {
        case 'users.json':
            return ['users' => Users::list()];

        case 'settings.json':
            return Settings::all();

        case 'projects.json':
            return Projects::list();

        case 'blog_posts.json':
            return Blog::list();

        case 'categories.json':
            return Categories::list();

        case 'clients.json':
            return Clients::list();

        case 'team.json':
            return Team::list();

        case 'messages.json':
            return ContactMessages::list();

        case 'trash_messages.json':
            return array_map(
                fn($r) => ContactMessages::shape($r),
                Db::i()->all('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('contact_messages')) . ' WHERE is_trashed = 1 ORDER BY created_at DESC')
            );

        case 'logs.json':
            return ActivityLog::list(1000);

        case 'views.json':
            return Views::series(30);

        case 'trash_media.json':
            return Media::trash();
    }

    return odsco_file_read($file);
}

/**
 * نوشتن داده — کل مجموعه جایگزین می‌شود (همان رفتار قبلی)،
 * ولی روی MySQL و به‌صورت تفاضلی (درج/به‌روزرسانی/حذف).
 */
function write_json(string $file, array $data)
{
    if (!Db::ready()) return odsco_file_write($file, $data);

    switch ($file) {
        case 'users.json':
            return odsco_sync_users($data['users'] ?? []);

        case 'settings.json':
            foreach ($data as $k => $v) Settings::set((string)$k, is_array($v) ? json_encode_safe($v) : (string)$v);
            return 1;

        case 'projects.json':
            return odsco_sync_projects($data);

        case 'blog_posts.json':
            return odsco_sync_blog($data);

        case 'categories.json':
            return odsco_sync_categories($data);

        case 'clients.json':
            return odsco_sync_clients($data);

        case 'team.json':
            return odsco_sync_team($data);

        case 'messages.json':
            return odsco_sync_messages($data);

        case 'logs.json':
            return 1;   // لاگ‌ها فقط افزودنی هستند

        case 'views.json':
            foreach ($data as $row) {
                if (empty($row['date'])) continue;
                Db::i()->upsert(Db::i()->t('site_views'), ['day' => $row['date'], 'hits' => (int)($row['count'] ?? 0)], ['day']);
            }
            return 1;
    }

    return odsco_file_write($file, $data);
}

// ---- خواندن/نوشتن واقعی فایل (fallback) -----------------------------------

function odsco_file_read(string $file): array
{
    $path = DATA_PATH . '/' . $file;
    if (!is_file($path)) return [];
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function odsco_file_write(string $file, array $data): int|false
{
    if (!is_dir(DATA_PATH)) @mkdir(DATA_PATH, 0775, true);
    return file_put_contents(DATA_PATH . '/' . $file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ---- همگام‌سازی مجموعه‌ها --------------------------------------------------

function odsco_sync_users(array $rows): int
{
    $db = Db::i();
    $existing = [];
    foreach ($db->all('SELECT uid, username FROM ' . $db->quoteIdent($db->t('users'))) as $r) {
        $existing[$r['uid']] = $r['username'];
    }

    $keep = [];
    foreach ($rows as $u) {
        $uid = (string)($u['id'] ?? $u['uid'] ?? '');
        if ($uid === '') continue;
        $keep[] = $uid;

        $cols = [
            'username'  => (string)($u['username'] ?? ''),
            'role'      => (string)($u['role'] ?? 'viewer'),
            'full_name' => (string)($u['full_name'] ?? ''),
            'email'     => (string)($u['email'] ?? ''),
            'phone'     => (string)($u['phone'] ?? ''),
            'photo'     => (string)($u['photo'] ?? ''),
            'bio'       => (string)($u['bio'] ?? ''),
            'messenger_enabled' => !empty($u['messenger_enabled']) ? 1 : 0,
            'is_active' => isset($u['is_active']) ? ($u['is_active'] ? 1 : 0) : 1,
        ];
        if (!empty($u['password']) && str_starts_with((string)$u['password'], '$2')) {
            $cols['password'] = (string)$u['password'];
        }

        if (isset($existing[$uid])) {
            $cols['updated_at'] = date('Y-m-d H:i:s');
            $db->update($db->t('users'), $cols, 'uid = ?', [$uid]);
        } else {
            $db->insert($db->t('users'), $cols + [
                'uid' => $uid,
                'password' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
                'created_at' => (string)($u['created_at'] ?? date('Y-m-d H:i:s')),
            ]);
        }
    }

    if ($existing) {
        $ph = implode(',', array_fill(0, count($keep) ?: 1, '?'));
        $keep = $keep ?: ['__none__'];
        $db->delete($db->t('users'), "uid NOT IN ($ph)", $keep);
    }
    Users::flush();
    return count($rows);
}

function odsco_sync_projects(array $rows): int
{
    $db = Db::i();
    $existing = array_map('strval', array_column($db->all('SELECT uid FROM ' . $db->quoteIdent($db->t('projects'))), 'uid'));
    $keep = [];

    foreach ($rows as $p) {
        $uid = (string)($p['id'] ?? $p['uid'] ?? '');
        if ($uid === '') continue;
        $keep[] = $uid;

        $cols = [
            'title' => (string)($p['title'] ?? ''), 'slug' => (string)($p['slug'] ?? ''),
            'category' => (string)($p['category'] ?? ''), 'description' => (string)($p['description'] ?? ''),
            'images' => json_encode_safe($p['images'] ?? []), 'cover_image' => (string)($p['cover_image'] ?? ''),
            'location' => (string)($p['location'] ?? ''), 'client_name' => (string)($p['client'] ?? ''),
            'project_year' => (string)($p['year'] ?? ''), 'area' => (string)($p['area'] ?? ''),
            'budget' => (string)($p['budget'] ?? ''), 'specs' => json_encode_safe($p['specs'] ?? []),
            'progress' => clamp_int($p['progress'] ?? 0, 0, 100),
            'status' => (string)($p['status'] ?? 'active'),
            'show_on_home' => !empty($p['show_on_home']) ? 1 : 0,
            'client_visible' => !empty($p['client_visible']) ? 1 : 0,
            'views' => (int)($p['views'] ?? 0),
            'client_uid' => (string)($p['client_uid'] ?? ''),
            'manager_uid' => (string)($p['manager_uid'] ?? ''),
            'start_date' => ($p['start_date'] ?? '') ?: null,
            'end_date' => ($p['end_date'] ?? '') ?: null,
        ];

        if (in_array($uid, $existing, true)) {
            $cols['updated_at'] = date('Y-m-d H:i:s');
            $db->update($db->t('projects'), $cols, 'uid = ?', [$uid]);
        } else {
            $db->insert($db->t('projects'), $cols + ['uid' => $uid, 'created_at' => (string)($p['created_at'] ?? date('Y-m-d H:i:s'))]);
        }
    }

    if ($existing) {
        $keep = $keep ?: ['__none__'];
        $ph = implode(',', array_fill(0, count($keep), '?'));
        $db->delete($db->t('projects'), "uid NOT IN ($ph)", $keep);
    }
    return count($rows);
}

function odsco_sync_blog(array $rows): int
{
    $db = Db::i();
    $existing = array_map('strval', array_column($db->all('SELECT uid FROM ' . $db->quoteIdent($db->t('blog_posts'))), 'uid'));
    $keep = [];

    foreach ($rows as $p) {
        $uid = (string)($p['id'] ?? $p['uid'] ?? '');
        if ($uid === '') continue;
        $keep[] = $uid;

        $cols = [
            'title' => (string)($p['title'] ?? ''), 'slug' => (string)($p['slug'] ?? ''),
            'excerpt' => (string)($p['excerpt'] ?? ''), 'content' => (string)($p['content'] ?? ''),
            'image' => (string)($p['image'] ?? ''), 'category' => (string)($p['category'] ?? ''),
            'author' => (string)($p['author'] ?? ''), 'tags' => json_encode_safe($p['tags'] ?? []),
            'status' => (string)($p['status'] ?? 'published'), 'views' => (int)($p['views'] ?? 0),
            'post_date' => (string)($p['date'] ?? date('Y-m-d H:i:s')),
        ];

        if (in_array($uid, $existing, true)) {
            $cols['updated_at'] = date('Y-m-d H:i:s');
            $db->update($db->t('blog_posts'), $cols, 'uid = ?', [$uid]);
        } else {
            $db->insert($db->t('blog_posts'), $cols + ['uid' => $uid, 'created_at' => date('Y-m-d H:i:s')]);
        }
    }

    if ($existing) {
        $keep = $keep ?: ['__none__'];
        $ph = implode(',', array_fill(0, count($keep), '?'));
        $db->delete($db->t('blog_posts'), "uid NOT IN ($ph)", $keep);
    }
    return count($rows);
}

function odsco_sync_categories(array $rows): int
{
    $db = Db::i();
    $existing = array_map('strval', array_column($db->all('SELECT uid FROM ' . $db->quoteIdent($db->t('categories'))), 'uid'));
    $keep = [];
    foreach ($rows as $i => $c) {
        $uid = (string)($c['id'] ?? $c['uid'] ?? '');
        if ($uid === '') continue;
        $keep[] = $uid;
        $cols = [
            'name' => (string)($c['name'] ?? ''), 'slug' => (string)($c['slug'] ?? ''),
            'description' => (string)($c['description'] ?? ''), 'icon' => (string)($c['icon'] ?? ''),
            'color' => (string)($c['color'] ?? ''), 'sort_order' => (int)($c['order'] ?? $i),
        ];
        if (in_array($uid, $existing, true)) {
            $cols['updated_at'] = date('Y-m-d H:i:s');
            $db->update($db->t('categories'), $cols, 'uid = ?', [$uid]);
        } else {
            $db->insert($db->t('categories'), $cols + ['uid' => $uid, 'created_at' => date('Y-m-d H:i:s')]);
        }
    }
    if ($existing) {
        $keep = $keep ?: ['__none__'];
        $ph = implode(',', array_fill(0, count($keep), '?'));
        $db->delete($db->t('categories'), "uid NOT IN ($ph)", $keep);
    }
    return count($rows);
}

function odsco_sync_clients(array $rows): int
{
    $db = Db::i();
    $existing = array_map('strval', array_column($db->all('SELECT uid FROM ' . $db->quoteIdent($db->t('clients'))), 'uid'));
    $keep = [];
    foreach ($rows as $i => $c) {
        $uid = (string)($c['id'] ?? $c['uid'] ?? '');
        if ($uid === '') continue;
        $keep[] = $uid;
        $cols = [
            'name' => (string)($c['name'] ?? ''), 'logo' => (string)($c['logo'] ?? ''),
            'website' => (string)($c['website'] ?? ''), 'contact_name' => (string)($c['contact_name'] ?? ''),
            'contact_phone' => (string)($c['contact_phone'] ?? ''), 'contact_email' => (string)($c['contact_email'] ?? ''),
            'address' => (string)($c['address'] ?? ''), 'notes' => (string)($c['notes'] ?? ''),
            'sort_order' => (int)($c['order'] ?? $i), 'is_active' => 1,
        ];
        if (in_array($uid, $existing, true)) {
            $cols['updated_at'] = date('Y-m-d H:i:s');
            $db->update($db->t('clients'), $cols, 'uid = ?', [$uid]);
        } else {
            $db->insert($db->t('clients'), $cols + ['uid' => $uid, 'created_at' => (string)($c['created_at'] ?? date('Y-m-d H:i:s'))]);
        }
    }
    if ($existing) {
        $keep = $keep ?: ['__none__'];
        $ph = implode(',', array_fill(0, count($keep), '?'));
        $db->delete($db->t('clients'), "uid NOT IN ($ph)", $keep);
    }
    return count($rows);
}

function odsco_sync_team(array $rows): int
{
    $db = Db::i();
    $existing = array_map('strval', array_column($db->all('SELECT uid FROM ' . $db->quoteIdent($db->t('team_members'))), 'uid'));
    $keep = [];
    foreach ($rows as $i => $m) {
        $uid = (string)($m['id'] ?? $m['uid'] ?? '');
        if ($uid === '') continue;
        $keep[] = $uid;
        $cols = [
            'name' => (string)($m['name'] ?? ''), 'role' => (string)($m['role'] ?? ''),
            'bio' => (string)($m['bio'] ?? ''), 'photo' => (string)($m['photo'] ?? ''),
            'email' => (string)($m['email'] ?? ''), 'phone' => (string)($m['phone'] ?? ''),
            'linkedin' => (string)($m['linkedin'] ?? ''), 'instagram' => (string)($m['instagram'] ?? ''),
            'sort_order' => (int)($m['order'] ?? $i), 'is_active' => 1,
        ];
        if (in_array($uid, $existing, true)) {
            $cols['updated_at'] = date('Y-m-d H:i:s');
            $db->update($db->t('team_members'), $cols, 'uid = ?', [$uid]);
        } else {
            $db->insert($db->t('team_members'), $cols + ['uid' => $uid, 'created_at' => date('Y-m-d H:i:s')]);
        }
    }
    if ($existing) {
        $keep = $keep ?: ['__none__'];
        $ph = implode(',', array_fill(0, count($keep), '?'));
        $db->delete($db->t('team_members'), "uid NOT IN ($ph)", $keep);
    }
    return count($rows);
}

function odsco_sync_messages(array $rows): int
{
    $db = Db::i();
    $existing = array_map('strval', array_column($db->all('SELECT uid FROM ' . $db->quoteIdent($db->t('contact_messages'))), 'uid'));
    $keep = [];
    foreach ($rows as $m) {
        $uid = (string)($m['id'] ?? $m['uid'] ?? '');
        if ($uid === '') continue;
        $keep[] = $uid;
        $cols = [
            'name' => (string)($m['name'] ?? ''), 'email' => (string)($m['email'] ?? ''),
            'phone' => (string)($m['phone'] ?? ''), 'subject' => (string)($m['subject'] ?? ''),
            'body' => (string)($m['message'] ?? ''), 'attachment' => json_encode_safe($m['attachment'] ?? null),
            'is_read' => !empty($m['is_read']) ? 1 : 0,
            'is_starred' => !empty($m['is_starred']) ? 1 : 0,
        ];
        if (in_array($uid, $existing, true)) {
            $db->update($db->t('contact_messages'), $cols, 'uid = ?', [$uid]);
        } else {
            $db->insert($db->t('contact_messages'), $cols + [
                'uid' => $uid, 'is_trashed' => 0,
                'created_at' => (string)($m['date'] ?? date('Y-m-d H:i:s')),
            ]);
        }
    }
    return count($rows);
}

// ---------------------------------------------------------------------------
// توابع قدیمی که هنوز در کد استفاده می‌شوند
// ---------------------------------------------------------------------------

/** @deprecated از jalali_date_long() استفاده کنید */
function format_date_fa(?string $date): string
{
    return jalali_date_long($date);
}

/** مسیر پوشه آپلود را می‌سازد */
function ensure_upload_dir(string $subfolder = ''): string
{
    return odsco_upload_path($subfolder);
}

function data_file_exists(string $file): bool
{
    return is_file(DATA_PATH . '/' . $file);
}
