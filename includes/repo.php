<?php
/**
 * ============================================================================
 *  Odsco — لایه دسترسی به داده (Repository)
 * ----------------------------------------------------------------------------
 *  تمام خواندن/نوشتن‌ها از اینجا عبور می‌کنند. شکل خروجی با ساختار قبلیِ
 *  فایل‌های JSON سازگار است تا کل سایت بدون تغییر کار کند، ولی ذخیره‌سازی
 *  روی MySQL انجام می‌شود.
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

// =============================================================================
// تنظیمات
// =============================================================================
final class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;

        $out = [];
        if (Db::ready() && Db::i()->tableExists('settings')) {
            foreach (Db::i()->all('SELECT setting_key, setting_value FROM ' . Db::i()->quoteIdent(Db::i()->t('settings'))) as $row) {
                $out[$row['setting_key']] = $row['setting_value'];
            }
        }
        self::$cache = array_merge(self::defaults(), $out);
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        $v = $all[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function set(string $key, mixed $value): void
    {
        $db = Db::i();
        $db->upsert($db->t('settings'), [
            'setting_key'   => $key,
            'setting_value' => is_array($value) ? json_encode_safe($value) : (string)$value,
            'updated_at'    => date('Y-m-d H:i:s'),
        ], ['setting_key']);
        self::$cache = null;
    }

    public static function setMany(array $pairs): void
    {
        foreach ($pairs as $k => $v) self::set((string)$k, $v);
    }

    public static function flush(): void { self::$cache = null; }

    public static function defaults(): array
    {
        return [
            'site_name'            => 'مشاوران افق دانش ثریا',
            'site_description'     => '',
            'phone_1'              => '',
            'phone_2'              => '',
            'email'                => '',
            'address'              => '',
            'working_hours'        => '',
            'meta_keywords'        => '',
            'meta_description'     => '',
            'favicon'              => '',
            // پیام‌رسان
            'msg_retention_days'   => '0',       // 0 = بدون حذف خودکار
            'msg_max_file_mb'      => '32',
            'msg_allow_client'     => '1',
            'msg_purge_on_read'    => '0',
            // حضور و غیاب
            'att_work_start'       => '08:00',
            'att_work_end'         => '16:00',
            'att_late_minutes'     => '15',
            'att_qr_minutes'       => '30',
            'att_weekends'         => '6',       // 6 = جمعه
        ];
    }
}

// =============================================================================
// کاربران
// =============================================================================
final class Users
{
    private static array $byUid = [];

    /** سلسله‌مراتب نقش‌ها */
    public const HIERARCHY = [
        'client'         => 0,
        'viewer'         => 1,
        'article_writer' => 2,
        'project_writer' => 2,
        'employee'       => 2,
        'editor'         => 3,
        'manager'        => 4,
        'admin'          => 5,
    ];

    public const ROLES = [
        'admin'          => ['👑', 'مدیرعامل / ادمین کل'],
        'manager'        => ['🛡️', 'مدیر'],
        'editor'         => ['📝', 'ویراستار'],
        'article_writer' => ['✍️', 'نویسنده مقاله'],
        'project_writer' => ['🏗️', 'نویسنده پروژه'],
        'employee'       => ['🧑‍💼', 'کارمند'],
        'client'         => ['🏢', 'کارفرما'],
        'viewer'         => ['👁️', 'فقط مشاهده'],
    ];

    public static function level(string $role): int
    {
        return self::HIERARCHY[$role] ?? 0;
    }

    public static function roleLabel(string $role): string
    {
        return self::ROLES[$role][1] ?? $role;
    }

    public static function roleIcon(string $role): string
    {
        return self::ROLES[$role][0] ?? '👤';
    }

    /** @return array<int, array<string, mixed>> */
    public static function list(array $filters = []): array
    {
        $db = Db::i();
        $where = [];
        $params = [];

        if (!empty($filters['role']))          { $where[] = 'role = ?'; $params[] = $filters['role']; }
        if (isset($filters['active']))         { $where[] = 'is_active = ?'; $params[] = $filters['active'] ? 1 : 0; }
        if (!empty($filters['messenger']))     { $where[] = 'messenger_enabled = 1'; }
        if (!empty($filters['attendance']))    { $where[] = 'attendance_enabled = 1'; }
        if (!empty($filters['client_uid']))    { $where[] = 'client_uid = ?'; $params[] = $filters['client_uid']; }
        if (!empty($filters['search'])) {
            $where[] = '(full_name LIKE ? OR username LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($filters['exclude_uid']))   { $where[] = 'uid <> ?'; $params[] = $filters['exclude_uid']; }
        if (!empty($filters['internal_only'])) { $where[] = "role <> 'client'"; }

        $sql = 'SELECT * FROM ' . $db->quoteIdent($db->t('users'));
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY CASE role ' . implode(' ', array_map(
            fn($r, $i) => "WHEN '$r' THEN $i",
            array_keys(self::HIERARCHY),
            array_values(self::HIERARCHY)
        )) . ' END, full_name ASC';

        return array_map([self::class, 'shape'], $db->all($sql, $params));
    }

    public static function find(string $uid): ?array
    {
        if (isset(self::$byUid[$uid])) return self::$byUid[$uid];
        $db = Db::i();
        $row = $db->one('SELECT * FROM ' . $db->quoteIdent($db->t('users')) . ' WHERE uid = ?', [$uid]);
        if (!$row) return null;
        return self::$byUid[$uid] = self::shape($row);
    }

    public static function findByUsername(string $username): ?array
    {
        $db = Db::i();
        $row = $db->one('SELECT * FROM ' . $db->quoteIdent($db->t('users')) . ' WHERE username = ?', [$username]);
        return $row ? self::shape($row) : null;
    }

    public static function name(string $uid): string
    {
        return self::find($uid)['full_name'] ?? 'کاربر حذف‌شده';
    }

    public static function photo(string $uid): string
    {
        return self::find($uid)['photo'] ?? '';
    }

    public static function role(string $uid): string
    {
        return self::find($uid)['role'] ?? 'viewer';
    }

    public static function exists(string $username, ?string $exceptUid = null): bool
    {
        $db = Db::i();
        $sql = 'SELECT COUNT(*) FROM ' . $db->quoteIdent($db->t('users')) . ' WHERE username = ?';
        $params = [$username];
        if ($exceptUid) { $sql .= ' AND uid <> ?'; $params[] = $exceptUid; }
        return (int)$db->val($sql, $params) > 0;
    }

    public static function create(array $data): array
    {
        $db = Db::i();
        $uid = $data['uid'] ?? ('usr_' . bin2hex(random_bytes(6)));
        $now = date('Y-m-d H:i:s');

        if (self::exists((string)($data['username'] ?? ''))) {
            throw new RuntimeException('این نام کاربری وجود دارد');
        }

        $db->insert($db->t('users'), [
            'uid'                => $uid,
            'username'           => $data['username'],
            'password'           => isset($data['password_hash']) ? $data['password_hash'] : password_hash((string)$data['password'], PASSWORD_DEFAULT),
            'role'               => $data['role'] ?? 'viewer',
            'full_name'          => $data['full_name'] ?? '',
            'email'              => $data['email'] ?? '',
            'phone'              => $data['phone'] ?? '',
            'photo'              => $data['photo'] ?? '',
            'bio'                => $data['bio'] ?? '',
            'job_title'          => $data['job_title'] ?? '',
            'department'         => $data['department'] ?? '',
            'national_code'      => $data['national_code'] ?? '',
            'hire_date'          => ($data['hire_date'] ?? '') ?: null,
            'monthly_salary'     => isset($data['monthly_salary']) && $data['monthly_salary'] !== '' ? $data['monthly_salary'] : null,
            'work_start'         => ($data['work_start'] ?? '') ?: null,
            'work_end'           => ($data['work_end'] ?? '') ?: null,
            'attendance_enabled' => !empty($data['attendance_enabled']) ? 1 : 0,
            'messenger_enabled'  => !empty($data['messenger_enabled']) ? 1 : 0,
            'client_uid'         => $data['client_uid'] ?? '',
            'is_active'          => isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1,
            'created_at'         => $data['created_at'] ?? $now,
            'updated_at'         => $now,
        ]);

        self::flush();
        return self::find($uid) ?? ['uid' => $uid];
    }

    public static function update(string $uid, array $data): bool
    {
        $clean = array_intersect_key($data, array_flip([
            'username','role','full_name','email','phone','photo','bio','job_title','department',
            'national_code','hire_date','monthly_salary','work_start','work_end',
            'attendance_enabled','messenger_enabled','client_uid','is_active','last_login','updated_at',
        ]));
        if (!$clean) return false;

        foreach (['attendance_enabled','messenger_enabled','is_active'] as $b) {
            if (array_key_exists($b, $clean)) $clean[$b] = $clean[$b] ? 1 : 0;
        }
        foreach (['hire_date','work_start','work_end'] as $d) {
            if (array_key_exists($d, $clean) && $clean[$d] === '') $clean[$d] = null;
        }
        if (array_key_exists('monthly_salary', $clean) && $clean['monthly_salary'] === '') $clean['monthly_salary'] = null;

        $clean['updated_at'] = date('Y-m-d H:i:s');
        $db = Db::i();
        $ok = $db->update($db->t('users'), $clean, 'uid = ?', [$uid]) >= 0;
        self::flush();
        return $ok;
    }

    public static function setPassword(string $uid, string $plain): void
    {
        Db::i()->update(Db::i()->t('users'), [
            'password'   => password_hash($plain, PASSWORD_DEFAULT),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'uid = ?', [$uid]);
        self::flush();
    }

    public static function verify(string $uid, string $plain): bool
    {
        $db = Db::i();
        $hash = $db->val('SELECT password FROM ' . $db->quoteIdent($db->t('users')) . ' WHERE uid = ?', [$uid]);
        return is_string($hash) && password_verify($plain, $hash);
    }

    public static function touchLogin(string $uid): void
    {
        Db::i()->update(Db::i()->t('users'), ['last_login' => date('Y-m-d H:i:s')], 'uid = ?', [$uid]);
        self::flush();
    }

    public static function delete(string $uid): bool
    {
        $db = Db::i();
        $n = $db->delete($db->t('users'), 'uid = ?', [$uid]);
        self::flush();
        return $n > 0;
    }

    public static function countByRole(?string $role = null): int
    {
        $db = Db::i();
        return $role
            ? (int)$db->val('SELECT COUNT(*) FROM ' . $db->quoteIdent($db->t('users')) . ' WHERE role = ?', [$role])
            : (int)$db->val('SELECT COUNT(*) FROM ' . $db->quoteIdent($db->t('users')));
    }

    /** کارمندان فعال (برای حضور و غیاب) */
    /** آیا این نقش در سطح مدیر یا بالاتر است؟ */
    public static function isManager(string $role): bool
    {
        return self::level($role) >= self::level('manager');
    }

    public static function staff(): array
    {
        return array_values(array_filter(self::list(['active' => true]), function (array $u): bool {
            return !empty($u['attendance_enabled']) && in_array($u['role'], ['admin','manager','editor','article_writer','project_writer','employee','viewer'], true);
        }));
    }

    public static function flush(): void { self::$byUid = []; }

    /** تبدیل ردیف دیتابیس به ساختار سازگار با کد قبلی */
    public static function shape(array $row): array
    {
        return [
            'id'                 => $row['uid'],
            'uid'                => $row['uid'],
            'username'           => $row['username'],
            'password'           => $row['password'] ?? '',
            'role'               => $row['role'],
            'full_name'          => $row['full_name'],
            'email'              => $row['email'],
            'phone'              => $row['phone'],
            'photo'              => $row['photo'],
            'bio'                => $row['bio'] ?? '',
            'job_title'          => $row['job_title'] ?? '',
            'department'         => $row['department'] ?? '',
            'national_code'      => $row['national_code'] ?? '',
            'hire_date'          => $row['hire_date'] ?? null,
            'monthly_salary'     => $row['monthly_salary'] ?? null,
            'work_start'         => $row['work_start'] ?? null,
            'work_end'           => $row['work_end'] ?? null,
            'attendance_enabled' => (bool)($row['attendance_enabled'] ?? 0),
            'messenger_enabled'  => (bool)($row['messenger_enabled'] ?? 0),
            'client_uid'         => $row['client_uid'] ?? '',
            'is_active'          => (bool)($row['is_active'] ?? 1),
            'last_login'         => $row['last_login'] ?? null,
            'created_at'         => $row['created_at'],
            'updated_at'         => $row['updated_at'] ?? null,
        ];
    }
}

// =============================================================================
// کارفرمایان
// =============================================================================
final class Clients
{
    public static function list(bool $activeOnly = false): array
    {
        $db = Db::i();
        $sql = 'SELECT * FROM ' . $db->quoteIdent($db->t('clients'))
             . ($activeOnly ? ' WHERE is_active = 1' : '')
             . ' ORDER BY sort_order ASC, name ASC';
        return array_map([self::class, 'shape'], $db->all($sql));
    }

    public static function find(string $uid): ?array
    {
        $row = Db::i()->one('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('clients')) . ' WHERE uid = ?', [$uid]);
        return $row ? self::shape($row) : null;
    }

    public static function create(array $d): string
    {
        $uid = odsco_uid('cl');
        Db::i()->insert(Db::i()->t('clients'), [
            'uid' => $uid, 'name' => $d['name'] ?? '', 'logo' => $d['logo'] ?? '',
            'website' => $d['website'] ?? '', 'contact_name' => $d['contact_name'] ?? '',
            'contact_phone' => $d['contact_phone'] ?? '', 'contact_email' => $d['contact_email'] ?? '',
            'address' => $d['address'] ?? '', 'notes' => $d['notes'] ?? '',
            'sort_order' => (int)($d['order'] ?? 0), 'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uid;
    }

    public static function update(string $uid, array $d): void
    {
        $clean = [];
        foreach (['name','logo','website','contact_name','contact_phone','contact_email','address','notes'] as $k) {
            if (array_key_exists($k, $d)) $clean[$k] = $d[$k];
        }
        if (array_key_exists('order', $d)) $clean['sort_order'] = (int)$d['order'];
        if (array_key_exists('is_active', $d)) $clean['is_active'] = $d['is_active'] ? 1 : 0;
        if ($clean) {
            $clean['updated_at'] = date('Y-m-d H:i:s');
            Db::i()->update(Db::i()->t('clients'), $clean, 'uid = ?', [$uid]);
        }
    }

    public static function delete(string $uid): void
    {
        Db::i()->delete(Db::i()->t('clients'), 'uid = ?', [$uid]);
    }

    public static function shape(array $r): array
    {
        return [
            'id' => $r['uid'], 'uid' => $r['uid'], 'name' => $r['name'],
            'logo' => $r['logo'], 'website' => $r['website'],
            'contact_name' => $r['contact_name'] ?? '', 'contact_phone' => $r['contact_phone'] ?? '',
            'contact_email' => $r['contact_email'] ?? '', 'address' => $r['address'] ?? '',
            'notes' => $r['notes'] ?? '', 'order' => (int)$r['sort_order'],
            'is_active' => (bool)$r['is_active'], 'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'] ?? null,
        ];
    }
}

// =============================================================================
// دسته‌بندی‌ها
// =============================================================================
final class Categories
{
    public static function list(): array
    {
        return array_map(
            fn($r) => ['id' => $r['uid'], 'uid' => $r['uid'], 'name' => $r['name'], 'slug' => $r['slug'],
                       'description' => $r['description'] ?? '', 'icon' => $r['icon'] ?? '', 'color' => $r['color'] ?? ''],
            Db::i()->all('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('categories')) . ' ORDER BY sort_order ASC, name ASC')
        );
    }

    public static function create(array $d): string
    {
        $uid = odsco_uid('cat');
        Db::i()->insert(Db::i()->t('categories'), [
            'uid' => $uid, 'name' => $d['name'] ?? '', 'slug' => $d['slug'] ?? create_slug($d['name'] ?? ''),
            'description' => $d['description'] ?? '', 'icon' => $d['icon'] ?? '', 'color' => $d['color'] ?? '',
            'sort_order' => (int)($d['order'] ?? 0), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uid;
    }

    public static function update(string $uid, array $d): void
    {
        $clean = [];
        foreach (['name','slug','description','icon','color'] as $k) if (array_key_exists($k, $d)) $clean[$k] = $d[$k];
        if (array_key_exists('order', $d)) $clean['sort_order'] = (int)$d['order'];
        if ($clean) { $clean['updated_at'] = date('Y-m-d H:i:s'); Db::i()->update(Db::i()->t('categories'), $clean, 'uid = ?', [$uid]); }
    }

    public static function delete(string $uid): void { Db::i()->delete(Db::i()->t('categories'), 'uid = ?', [$uid]); }
}

// =============================================================================
// پروژه‌ها
// =============================================================================
final class Projects
{
    public static function list(array $filters = []): array
    {
        $db = Db::i();
        $where = [];
        $params = [];

        if (!empty($filters['status']))          { $where[] = 'status = ?'; $params[] = $filters['status']; }
        if (!empty($filters['home_only']))       { $where[] = 'show_on_home = 1'; }
        if (!empty($filters['client_uid']))      { $where[] = 'client_uid = ?'; $params[] = $filters['client_uid']; }
        if (!empty($filters['client_visible']))  { $where[] = 'client_visible = 1'; }
        if (!empty($filters['member_uid'])) {
            $where[] = 'uid IN (SELECT project_uid FROM ' . $db->quoteIdent($db->t('project_members')) . ' WHERE user_uid = ?)';
            $params[] = $filters['member_uid'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(title LIKE ? OR client_name LIKE ? OR location LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like);
        }

        $sql = 'SELECT * FROM ' . $db->quoteIdent($db->t('projects'));
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY created_at DESC';

        if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int)$filters['limit'];

        return array_map([self::class, 'shape'], $db->all($sql, $params));
    }

    public static function find(string $uid): ?array
    {
        $row = Db::i()->one('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('projects')) . ' WHERE uid = ?', [$uid]);
        return $row ? self::shape($row) : null;
    }

    public static function findBySlug(string $slug): ?array
    {
        $row = Db::i()->one('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('projects')) . ' WHERE slug = ? LIMIT 1', [$slug]);
        return $row ? self::shape($row) : null;
    }

    public static function create(array $d): string
    {
        $uid = odsco_uid('prj');
        Db::i()->insert(Db::i()->t('projects'), self::row($uid, $d) + ['uid' => $uid, 'created_at' => date('Y-m-d H:i:s')]);
        return $uid;
    }

    public static function update(string $uid, array $d): void
    {
        $row = self::row($uid, $d, true);
        if (!$row) return;
        $row['updated_at'] = date('Y-m-d H:i:s');
        Db::i()->update(Db::i()->t('projects'), $row, 'uid = ?', [$uid]);
    }

    public static function delete(string $uid): void
    {
        $db = Db::i();
        $db->delete($db->t('projects'), 'uid = ?', [$uid]);
        $db->delete($db->t('project_members'), 'project_uid = ?', [$uid]);
        $db->delete($db->t('project_tasks'), 'project_uid = ?', [$uid]);
        $db->delete($db->t('project_updates'), 'project_uid = ?', [$uid]);
    }

    public static function incrementView(string $uid): void
    {
        $db = Db::i();
        $db->run('UPDATE ' . $db->quoteIdent($db->t('projects')) . ' SET views = views + 1 WHERE uid = ?', [$uid]);
    }

    public static function setProgress(string $uid, int $percent): void
    {
        Db::i()->update(Db::i()->t('projects'), ['progress' => clamp_int($percent, 0, 100), 'updated_at' => date('Y-m-d H:i:s')], 'uid = ?', [$uid]);
    }

    private static function row(string $uid, array $d, bool $partial = false): array
    {
        $map = [
            'title' => 'title', 'slug' => 'slug', 'category' => 'category', 'description' => 'description',
            'cover_image' => 'cover_image', 'location' => 'location', 'area' => 'area', 'budget' => 'budget',
            'manager_uid' => 'manager_uid', 'client_uid' => 'client_uid',
        ];
        $out = [];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $d)) $out[$to] = $d[$from];
        }
        if (array_key_exists('client', $d))      $out['client_name']  = $d['client'];
        if (array_key_exists('year', $d))        $out['project_year'] = $d['year'];
        if (array_key_exists('images', $d))      $out['images']       = json_encode_safe($d['images']);
        if (array_key_exists('specs', $d))       $out['specs']        = json_encode_safe($d['specs']);
        if (array_key_exists('progress', $d))    $out['progress']     = clamp_int($d['progress'], 0, 100);
        if (array_key_exists('status', $d))      $out['status']       = $d['status'];
        if (array_key_exists('start_date', $d))  $out['start_date']   = $d['start_date'] ?: null;
        if (array_key_exists('end_date', $d))    $out['end_date']     = $d['end_date'] ?: null;
        if (array_key_exists('show_on_home', $d))   $out['show_on_home']   = $d['show_on_home'] ? 1 : 0;
        if (array_key_exists('client_visible', $d)) $out['client_visible'] = $d['client_visible'] ? 1 : 0;
        if (array_key_exists('views', $d))       $out['views'] = (int)$d['views'];

        if (!$partial) {
            $out += [
                'title' => '', 'slug' => '', 'category' => '', 'description' => '', 'images' => '[]',
                'cover_image' => '', 'location' => '', 'client_name' => '', 'client_uid' => '',
                'manager_uid' => '', 'project_year' => '', 'area' => '', 'budget' => '', 'specs' => '[]',
                'progress' => 0, 'status' => 'active', 'start_date' => null, 'end_date' => null,
                'show_on_home' => 0, 'client_visible' => 0, 'views' => 0,
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // لایه راحت‌تر: اعضا / تسک‌ها / گزارش‌های یک پروژه
    // ---------------------------------------------------------------------

    /** افزودن عضو به پروژه */
    public static function addMember(string $projectUid, array $d, string $by = ''): void
    {
        ProjectMembers::add(
            $projectUid,
            (string)($d['user_uid'] ?? ''),
            (string)($d['role'] ?? $d['role_in_project'] ?? 'عضو تیم'),
            (int)($d['percent'] ?? 0),
            $by
        );
    }

    /** حذف عضو از پروژه (شناسه کاربر) */
    public static function removeMember(string $projectUid, string $userUid): void
    {
        if ($userUid === '') return;
        ProjectMembers::remove($projectUid, $userUid);
    }

    /** ساخت تسک جدید در پروژه */
    public static function addTask(string $projectUid, array $d, string $by = ''): string
    {
        return Tasks::create([
            'project_uid'  => $projectUid,
            'title'        => (string)($d['title'] ?? ''),
            'description'  => (string)($d['description'] ?? ''),
            'assignee_uid' => (string)($d['assignee_uid'] ?? ''),
            'due_date'     => (string)($d['due_date'] ?? ''),
            'priority'     => (string)($d['priority'] ?? 'normal'),
            'status'       => (string)($d['status'] ?? 'todo'),
            'progress'     => (int)($d['progress'] ?? 0),
            'created_by'   => $by,
        ]);
    }

    /** تغییر وضعیت یک تسک */
    public static function taskStatus(string $projectUid, string $taskUid, string $status): void
    {
        if (!array_key_exists($status, Tasks::STATUSES)) {
            throw new RuntimeException('وضعیت نامعتبر است');
        }
        Tasks::update($taskUid, ['status' => $status, 'project_uid' => $projectUid]);
    }

    /** ذخیره درصد پیشرفت یک تسک */
    public static function taskProgress(string $projectUid, string $taskUid, int $percent): void
    {
        Tasks::update($taskUid, ['progress' => $percent, 'project_uid' => $projectUid]);
    }

    /** ثبت نظر / گزارش کار روی یک تسک */
    public static function addTaskComment(string $projectUid, string $taskUid, string $userUid, string $body): string
    {
        return Tasks::addComment($taskUid, $userUid, $body);
    }

    /** @return array<int, array<string, mixed>> */
    public static function taskComments(string $projectUid, string $taskUid): array
    {
        return Tasks::comments($taskUid);
    }

    /** @return array<int, array<string, mixed>> گزارش‌های پیشرفت یک پروژه */
    public static function updates(string $projectUid, int $limit = 0): array
    {
        return ProjectUpdates::list($projectUid, $limit);
    }

    /** بازمحاسبه پیشرفت پروژه از میانگین تسک‌ها */
    public static function recalcProgress(string $projectUid): array
    {
        $percent = Tasks::projectProgress($projectUid);
        self::setProgress($projectUid, $percent);
        return self::find($projectUid) ?? ['progress' => $percent];
    }

    public static function shape(array $r): array
    {
        return [
            'id' => $r['uid'], 'uid' => $r['uid'], 'title' => $r['title'], 'slug' => $r['slug'],
            'category' => $r['category'], 'description' => $r['description'] ?? '',
            'images' => json_decode_safe($r['images'] ?? '[]'),
            'cover_image' => $r['cover_image'], 'location' => $r['location'],
            'client' => $r['client_name'], 'client_uid' => $r['client_uid'],
            'manager_uid' => $r['manager_uid'], 'year' => $r['project_year'],
            'area' => $r['area'], 'budget' => $r['budget'],
            'specs' => json_decode_safe($r['specs'] ?? '[]'),
            'progress' => (int)$r['progress'], 'status' => $r['status'],
            'start_date' => $r['start_date'] ?? null, 'end_date' => $r['end_date'] ?? null,
            'show_on_home' => (bool)$r['show_on_home'], 'client_visible' => (bool)$r['client_visible'],
            'views' => (int)$r['views'], 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'] ?? null,
        ];
    }
}

// =============================================================================
// اعضای پروژه
// =============================================================================
final class ProjectMembers
{
    public static function list(string $projectUid): array
    {
        $rows = Db::i()->all(
            'SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('project_members')) . ' WHERE project_uid = ? ORDER BY id ASC',
            [$projectUid]
        );
        return array_map(function (array $r): array {
            $u = Users::find($r['user_uid']);
            return [
                'user_uid' => $r['user_uid'],
                'full_name' => $u['full_name'] ?? 'کاربر حذف‌شده',
                'photo' => $u['photo'] ?? '',
                'job_title' => $u['job_title'] ?? '',
                'role_in_project' => $r['role_in_project'],
                'percent' => (int)$r['percent'],
                'added_at' => $r['added_at'],
            ];
        }, $rows);
    }

    /** @return array<int, string> */
    public static function uids(string $projectUid): array
    {
        return array_map('strval', array_column(
            Db::i()->all('SELECT user_uid FROM ' . Db::i()->quoteIdent(Db::i()->t('project_members')) . ' WHERE project_uid = ?', [$projectUid]),
            'user_uid'
        ));
    }

    public static function isMember(string $projectUid, string $userUid): bool
    {
        return (int)Db::i()->val(
            'SELECT COUNT(*) FROM ' . Db::i()->quoteIdent(Db::i()->t('project_members')) . ' WHERE project_uid = ? AND user_uid = ?',
            [$projectUid, $userUid]
        ) > 0;
    }

    public static function projectsOf(string $userUid): array
    {
        return Projects::list(['member_uid' => $userUid]);
    }

    public static function add(string $projectUid, string $userUid, string $role = 'عضو تیم', int $percent = 0, string $by = ''): void
    {
        Db::i()->upsert(Db::i()->t('project_members'), [
            'project_uid' => $projectUid, 'user_uid' => $userUid,
            'role_in_project' => $role, 'percent' => clamp_int($percent, 0, 100),
            'added_by' => $by, 'added_at' => date('Y-m-d H:i:s'),
        ], ['project_uid', 'user_uid']);
    }

    public static function remove(string $projectUid, string $userUid): void
    {
        Db::i()->delete(Db::i()->t('project_members'), 'project_uid = ? AND user_uid = ?', [$projectUid, $userUid]);
    }

    /** جایگزینی کامل لیست اعضا */
    public static function sync(string $projectUid, array $members, string $by = ''): void
    {
        $db = Db::i();
        $keep = [];
        foreach ($members as $m) {
            $uid = is_array($m) ? (string)($m['user_uid'] ?? $m['uid'] ?? '') : (string)$m;
            if ($uid === '') continue;
            self::add(
                $projectUid,
                $uid,
                is_array($m) ? (string)($m['role_in_project'] ?? 'عضو تیم') : 'عضو تیم',
                is_array($m) ? (int)($m['percent'] ?? 0) : 0,
                $by
            );
            $keep[] = $uid;
        }
        if ($keep) {
            $ph = implode(',', array_fill(0, count($keep), '?'));
            $db->delete($db->t('project_members'), "project_uid = ? AND user_uid NOT IN ($ph)", array_merge([$projectUid], $keep));
        } else {
            $db->delete($db->t('project_members'), 'project_uid = ?', [$projectUid]);
        }
    }
}

// =============================================================================
// تسک‌های پروژه
// =============================================================================
final class Tasks
{
    public const STATUSES = ['todo' => 'در انتظار', 'doing' => 'در حال انجام', 'review' => 'بررسی', 'done' => 'انجام شده'];
    public const PRIORITIES = ['low' => 'کم', 'normal' => 'معمولی', 'high' => 'زیاد', 'urgent' => 'فوری'];

    public static function list(string $projectUid, array $filters = []): array
    {
        $db = Db::i();
        $where = ['project_uid = ?'];
        $params = [$projectUid];
        if (!empty($filters['status']))   { $where[] = 'status = ?'; $params[] = $filters['status']; }
        if (!empty($filters['assignee'])) { $where[] = 'assignee_uid = ?'; $params[] = $filters['assignee']; }
        $sql = 'SELECT * FROM ' . $db->quoteIdent($db->t('project_tasks')) . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC';
        return array_map([self::class, 'shape'], $db->all($sql, $params));
    }

    public static function find(string $uid): ?array
    {
        $row = Db::i()->one('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('project_tasks')) . ' WHERE uid = ?', [$uid]);
        return $row ? self::shape($row) : null;
    }

    public static function overdue(): array
    {
        return array_map([self::class, 'shape'], Db::i()->all(
            'SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('project_tasks'))
            . " WHERE due_date IS NOT NULL AND due_date < " . (Db::i()->isMysql() ? 'CURDATE()' : "date('now','localtime')")
            . " AND status <> 'done' ORDER BY due_date ASC"
        ));
    }

    public static function dueSoon(int $days = 3): array
    {
        $from = Db::i()->isMysql() ? 'CURDATE()' : "date('now','localtime')";
        $to   = Db::i()->isMysql() ? "DATE_ADD(CURDATE(), INTERVAL $days DAY)" : "date('now','localtime','+$days day')";
        return array_map([self::class, 'shape'], Db::i()->all(
            "SELECT * FROM " . Db::i()->quoteIdent(Db::i()->t('project_tasks'))
            . " WHERE due_date IS NOT NULL AND due_date BETWEEN $from AND $to AND status <> 'done' ORDER BY due_date ASC"
        ));
    }

    public static function create(array $d): string
    {
        $uid = odsco_uid('tsk');
        Db::i()->insert(Db::i()->t('project_tasks'), [
            'uid' => $uid, 'project_uid' => $d['project_uid'], 'title' => $d['title'],
            'description' => $d['description'] ?? '', 'assignee_uid' => $d['assignee_uid'] ?? '',
            'status' => $d['status'] ?? 'todo', 'priority' => $d['priority'] ?? 'normal',
            'progress' => clamp_int($d['progress'] ?? 0, 0, 100), 'due_date' => $d['due_date'] ?: null,
            'created_by' => $d['created_by'] ?? '', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uid;
    }

    public static function update(string $uid, array $d): void
    {
        $clean = [];
        foreach (['title','description','assignee_uid','status','priority','project_uid'] as $k) {
            if (array_key_exists($k, $d)) $clean[$k] = $d[$k];
        }
        if (array_key_exists('progress', $d)) $clean['progress'] = clamp_int($d['progress'], 0, 100);
        if (array_key_exists('due_date', $d)) $clean['due_date'] = $d['due_date'] ?: null;
        if (($clean['status'] ?? '') === 'done') $clean['completed_at'] = date('Y-m-d H:i:s');
        if (!empty($clean['status']) && $clean['status'] !== 'done') $clean['completed_at'] = null;
        if ($clean) { $clean['updated_at'] = date('Y-m-d H:i:s'); Db::i()->update(Db::i()->t('project_tasks'), $clean, 'uid = ?', [$uid]); }
    }

    public static function delete(string $uid): void
    {
        Db::i()->delete(Db::i()->t('project_tasks'), 'uid = ?', [$uid]);
        Db::i()->delete(Db::i()->t('project_task_comments'), 'task_uid = ?', [$uid]);
    }

    /** ثبت نظر / گزارش کار روی یک تسک */
    public static function addComment(string $taskUid, string $userUid, string $body): string
    {
        $body = trim($body);
        if ($body === '') throw new RuntimeException('متن نظر خالی است');
        $id = Db::i()->insert(Db::i()->t('project_task_comments'), [
            'task_uid' => $taskUid, 'user_uid' => $userUid, 'body' => $body,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return (string)$id;
    }

    /** @return array<int, array<string, mixed>> */
    public static function comments(string $taskUid): array
    {
        return array_map(fn(array $r): array => [
            'uid' => (string)$r['id'], 'task_uid' => $r['task_uid'],
            'user_uid' => $r['user_uid'], 'author_name' => Users::name($r['user_uid']),
            'author_photo' => Users::photo($r['user_uid']),
            'body' => (string)$r['body'], 'created_at' => $r['created_at'],
        ], Db::i()->all('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('project_task_comments'))
            . ' WHERE task_uid = ? ORDER BY id ASC', [$taskUid]));
    }

    /** تعداد تسک‌های یک عضو در یک پروژه */
    public static function countForUser(string $projectUid, string $userUid): int
    {
        return (int)Db::i()->val('SELECT COUNT(*) FROM ' . Db::i()->quoteIdent(Db::i()->t('project_tasks'))
            . ' WHERE project_uid = ? AND assignee_uid = ?', [$projectUid, $userUid]);
    }

    /** پیشرفت کلی پروژه از روی تسک‌ها */
    public static function projectProgress(string $projectUid): int
    {
        $avg = Db::i()->val('SELECT AVG(progress) FROM ' . Db::i()->quoteIdent(Db::i()->t('project_tasks')) . ' WHERE project_uid = ?', [$projectUid]);
        return $avg === null ? 0 : (int)round((float)$avg);
    }

    public static function shape(array $r): array
    {
        return [
            'id' => $r['uid'], 'uid' => $r['uid'], 'project_uid' => $r['project_uid'],
            'title' => $r['title'], 'description' => $r['description'] ?? '',
            'assignee_uid' => $r['assignee_uid'], 'assignee_name' => Users::name($r['assignee_uid']),
            'status' => $r['status'], 'priority' => $r['priority'], 'progress' => (int)$r['progress'],
            'due_date' => $r['due_date'] ?? null, 'created_by' => $r['created_by'],
            'created_at' => $r['created_at'], 'completed_at' => $r['completed_at'] ?? null,
        ];
    }
}

// =============================================================================
// گزارش / هشدار پیشرفت پروژه
// =============================================================================
final class ProjectUpdates
{
    public const LEVELS = ['info' => 'ℹ️ اطلاع‌رسانی', 'success' => '✅ پیشرفت', 'warning' => '⚠️ هشدار', 'danger' => '🚨 بحرانی'];

    public static function list(string $projectUid, int $limit = 0): array
    {
        $sql = 'SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('project_updates')) . ' WHERE project_uid = ? ORDER BY created_at DESC';
        if ($limit > 0) $sql .= ' LIMIT ' . $limit;
        return array_map([self::class, 'shape'], Db::i()->all($sql, [$projectUid]));
    }

    public static function recent(int $limit = 10, ?string $userUid = null): array
    {
        $sql = 'SELECT u.*, p.title AS project_title FROM ' . Db::i()->quoteIdent(Db::i()->t('project_updates')) . ' u'
             . ' LEFT JOIN ' . Db::i()->quoteIdent(Db::i()->t('projects')) . ' p ON p.uid = u.project_uid';
        $params = [];
        if ($userUid) {
            $sql .= ' WHERE u.visibility IN (\'internal\',\'both\') AND (p.uid IN (SELECT project_uid FROM '
                  . Db::i()->quoteIdent(Db::i()->t('project_members')) . ' WHERE user_uid = ?) OR p.manager_uid = ?)';
            $params = [$userUid, $userUid];
        }
        $sql .= ' ORDER BY u.created_at DESC LIMIT ' . $limit;
        return array_map([self::class, 'shape'], Db::i()->all($sql, $params));
    }

    public static function create(array $d): string
    {
        $uid = odsco_uid('upd');
        Db::i()->insert(Db::i()->t('project_updates'), [
            'uid' => $uid, 'project_uid' => $d['project_uid'], 'author_uid' => $d['author_uid'] ?? '',
            'title' => $d['title'], 'body' => $d['body'] ?? '',
            'progress' => isset($d['progress']) && $d['progress'] !== '' ? clamp_int($d['progress'], 0, 100) : null,
            'level' => $d['level'] ?? 'info', 'visibility' => $d['visibility'] ?? 'both',
            'attachment' => $d['attachment'] ?? '', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uid;
    }

    /** آیکون یک سطح هشدار */
    public static function levelIcon(string $level): string
    {
        return match ($level) {
            'success' => '✅', 'warning' => '⚠️', 'danger' => '🚨', default => 'ℹ️',
        };
    }

    public static function delete(string $uid): void { Db::i()->delete(Db::i()->t('project_updates'), 'uid = ?', [$uid]); }

    public static function shape(array $r): array
    {
        return [
            'id' => $r['uid'], 'uid' => $r['uid'], 'project_uid' => $r['project_uid'],
            'project_title' => $r['project_title'] ?? (Projects::find($r['project_uid'])['title'] ?? ''),
            'author_uid' => $r['author_uid'], 'author_name' => Users::name($r['author_uid']),
            'title' => $r['title'], 'body' => $r['body'] ?? '',
            'progress' => $r['progress'] === null ? null : (int)$r['progress'],
            'level' => $r['level'], 'visibility' => $r['visibility'],
            'attachment' => $r['attachment'] ?? '', 'created_at' => $r['created_at'],
        ];
    }
}

// =============================================================================
// اعلان‌ها
// =============================================================================
final class Notifications
{
    public static function forUser(string $userUid, bool $unreadOnly = false, int $limit = 50): array
    {
        $sql = 'SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('notifications')) . ' WHERE user_uid = ?'
             . ($unreadOnly ? ' AND is_read = 0' : '') . ' ORDER BY created_at DESC LIMIT ' . $limit;
        return Db::i()->all($sql, [$userUid]);
    }

    public static function unreadCount(string $userUid): int
    {
        return (int)Db::i()->val('SELECT COUNT(*) FROM ' . Db::i()->quoteIdent(Db::i()->t('notifications')) . ' WHERE user_uid = ? AND is_read = 0', [$userUid]);
    }

    public static function push(string $userUid, string $title, string $body = '', array $opts = []): string
    {
        $uid = odsco_uid('ntf');
        Db::i()->insert(Db::i()->t('notifications'), [
            'uid' => $uid, 'user_uid' => $userUid, 'title' => $title, 'body' => $body,
            'type' => $opts['type'] ?? 'general', 'level' => $opts['level'] ?? 'info',
            'link' => $opts['link'] ?? '', 'icon' => $opts['icon'] ?? '🔔',
            'source_uid' => $opts['source_uid'] ?? '', 'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uid;
    }

    /** ارسال یک اعلان به چند کاربر */
    public static function broadcast(array $userUids, string $title, string $body = '', array $opts = []): int
    {
        $n = 0;
        foreach (array_unique($userUids) as $u) { self::push($u, $title, $body, $opts); $n++; }
        return $n;
    }

    /** آخرین اعلان‌های کل سیستم (برای صفحه ارسال اطلاعیه) */
    public static function all(int $limit = 50): array
    {
        return array_map(fn(array $r): array => $r + [
            'user_name' => Users::name((string)$r['user_uid']),
            'is_read'   => (bool)$r['is_read'],
        ], Db::i()->all('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('notifications'))
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit)));
    }

    public static function markRead(string $uid, string $userUid): void
    {
        Db::i()->update(Db::i()->t('notifications'), ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')], 'uid = ? AND user_uid = ?', [$uid, $userUid]);
    }

    public static function markAllRead(string $userUid): void
    {
        Db::i()->update(Db::i()->t('notifications'), ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')], 'user_uid = ? AND is_read = 0', [$userUid]);
    }

    public static function delete(string $uid, string $userUid): void
    {
        Db::i()->delete(Db::i()->t('notifications'), 'uid = ? AND user_uid = ?', [$uid, $userUid]);
    }
}

// =============================================================================
// بلاگ
// =============================================================================
final class Blog
{
    public static function list(array $filters = []): array
    {
        $db = Db::i();
        $where = [];
        $params = [];
        if (!empty($filters['status']))   { $where[] = 'status = ?'; $params[] = $filters['status']; }
        if (!empty($filters['category'])) { $where[] = 'category = ?'; $params[] = $filters['category']; }
        if (!empty($filters['search']))   { $where[] = '(title LIKE ? OR excerpt LIKE ?)'; $l = '%'.$filters['search'].'%'; array_push($params, $l, $l); }

        $sql = 'SELECT * FROM ' . $db->quoteIdent($db->t('blog_posts'));
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY post_date DESC';
        if (!empty($filters['limit'])) $sql .= ' LIMIT ' . (int)$filters['limit'];

        return array_map([self::class, 'shape'], $db->all($sql, $params));
    }

    public static function find(string $uid): ?array
    {
        $row = Db::i()->one('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('blog_posts')) . ' WHERE uid = ?', [$uid]);
        return $row ? self::shape($row) : null;
    }

    public static function findBySlug(string $slug): ?array
    {
        $row = Db::i()->one('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('blog_posts')) . ' WHERE slug = ? LIMIT 1', [$slug]);
        return $row ? self::shape($row) : null;
    }

    public static function create(array $d): string
    {
        $uid = odsco_uid('pst');
        $date = $d['date'] ?? date('Y-m-d H:i:s');
        Db::i()->insert(Db::i()->t('blog_posts'), [
            'uid' => $uid, 'title' => $d['title'], 'slug' => $d['slug'] ?? create_slug($d['title']),
            'excerpt' => $d['excerpt'] ?? '', 'content' => $d['content'] ?? '', 'image' => $d['image'] ?? '',
            'category' => $d['category'] ?? '', 'author' => $d['author'] ?? '',
            'tags' => json_encode_safe($d['tags'] ?? []), 'status' => $d['status'] ?? 'published',
            'views' => (int)($d['views'] ?? 0), 'post_date' => $date, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uid;
    }

    public static function update(string $uid, array $d): void
    {
        $clean = [];
        foreach (['title','slug','excerpt','content','image','category','author','status'] as $k) {
            if (array_key_exists($k, $d)) $clean[$k] = $d[$k];
        }
        if (array_key_exists('tags', $d)) $clean['tags'] = json_encode_safe($d['tags']);
        if (!empty($d['date'])) $clean['post_date'] = $d['date'];
        if ($clean) { $clean['updated_at'] = date('Y-m-d H:i:s'); Db::i()->update(Db::i()->t('blog_posts'), $clean, 'uid = ?', [$uid]); }
    }

    public static function delete(string $uid): void { Db::i()->delete(Db::i()->t('blog_posts'), 'uid = ?', [$uid]); }

    public static function incrementView(string $uid): void
    {
        Db::i()->run('UPDATE ' . Db::i()->quoteIdent(Db::i()->t('blog_posts')) . ' SET views = views + 1 WHERE uid = ?', [$uid]);
    }

    public static function shape(array $r): array
    {
        return [
            'id' => $r['uid'], 'uid' => $r['uid'], 'title' => $r['title'], 'slug' => $r['slug'],
            'excerpt' => $r['excerpt'] ?? '', 'content' => $r['content'] ?? '', 'image' => $r['image'],
            'author' => $r['author'], 'date' => $r['post_date'], 'category' => $r['category'],
            'tags' => json_decode_safe($r['tags'] ?? '[]'), 'status' => $r['status'],
            'views' => (int)$r['views'], 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'] ?? null,
        ];
    }
}

// =============================================================================
// تیم
// =============================================================================
final class Team
{
    public static function list(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('team_members'))
             . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order ASC, name ASC';
        return array_map(fn($r) => [
            'id' => $r['uid'], 'uid' => $r['uid'], 'name' => $r['name'], 'role' => $r['role'],
            'bio' => $r['bio'] ?? '', 'photo' => $r['photo'], 'email' => $r['email'] ?? '',
            'phone' => $r['phone'] ?? '', 'linkedin' => $r['linkedin'] ?? '', 'instagram' => $r['instagram'] ?? '',
            'order' => (int)$r['sort_order'], 'is_active' => (bool)$r['is_active'],
            'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'] ?? null,
        ], Db::i()->all($sql));
    }

    public static function find(string $uid): ?array
    {
        $row = Db::i()->one('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('team_members')) . ' WHERE uid = ?', [$uid]);
        if (!$row) return null;
        return [
            'id' => $row['uid'], 'uid' => $row['uid'], 'name' => $row['name'], 'role' => $row['role'],
            'bio' => $row['bio'] ?? '', 'photo' => $row['photo'], 'email' => $row['email'] ?? '',
            'phone' => $row['phone'] ?? '', 'linkedin' => $row['linkedin'] ?? '', 'instagram' => $row['instagram'] ?? '',
            'order' => (int)$row['sort_order'], 'is_active' => (bool)$row['is_active'],
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    public static function create(array $d): string
    {
        $uid = odsco_uid('team');
        Db::i()->insert(Db::i()->t('team_members'), [
            'uid' => $uid, 'name' => $d['name'], 'role' => $d['role'] ?? '', 'bio' => $d['bio'] ?? '',
            'photo' => $d['photo'] ?? '', 'email' => $d['email'] ?? '', 'phone' => $d['phone'] ?? '',
            'linkedin' => $d['linkedin'] ?? '', 'instagram' => $d['instagram'] ?? '',
            'sort_order' => (int)($d['order'] ?? 0), 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uid;
    }

    public static function update(string $uid, array $d): void
    {
        $clean = [];
        foreach (['name','role','bio','photo','email','phone','linkedin','instagram'] as $k) {
            if (array_key_exists($k, $d)) $clean[$k] = $d[$k];
        }
        if (array_key_exists('order', $d)) $clean['sort_order'] = (int)$d['order'];
        if ($clean) { $clean['updated_at'] = date('Y-m-d H:i:s'); Db::i()->update(Db::i()->t('team_members'), $clean, 'uid = ?', [$uid]); }
    }

    public static function delete(string $uid): void { Db::i()->delete(Db::i()->t('team_members'), 'uid = ?', [$uid]); }
}

// =============================================================================
// پیام‌های فرم تماس
// =============================================================================
final class ContactMessages
{
    public static function list(array $filters = []): array
    {
        $where = ['is_trashed = 0'];
        $params = [];
        if (!empty($filters['unread'])) { $where[] = 'is_read = 0'; }
        if (!empty($filters['starred'])) { $where[] = 'is_starred = 1'; }
        $sql = 'SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('contact_messages'))
             . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC';
        return array_map([self::class, 'shape'], Db::i()->all($sql, $params));
    }

    /** پیام‌های داخل زباله‌دان */
    public static function trash(): array
    {
        return array_map([self::class, 'shape'], Db::i()->all(
            'SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('contact_messages'))
            . ' WHERE is_trashed = 1 ORDER BY created_at DESC'
        ));
    }

    public static function create(array $d): string
    {
        $uid = odsco_uid('msg');
        Db::i()->insert(Db::i()->t('contact_messages'), [
            'uid' => $uid, 'name' => $d['name'] ?? '', 'email' => $d['email'] ?? '', 'phone' => $d['phone'] ?? '',
            'subject' => $d['subject'] ?? '', 'body' => $d['message'] ?? '',
            'attachment' => json_encode_safe($d['attachment'] ?? null), 'is_read' => 0,
            'created_at' => $d['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
        return $uid;
    }

    public static function setFlags(string $uid, array $flags): void
    {
        $clean = [];
        foreach (['is_read','is_starred','is_trashed'] as $f) {
            if (array_key_exists($f, $flags)) $clean[$f] = $flags[$f] ? 1 : 0;
        }
        if ($clean) Db::i()->update(Db::i()->t('contact_messages'), $clean, 'uid = ?', [$uid]);
    }

    public static function delete(string $uid, bool $hard = false): void
    {
        $hard
            ? Db::i()->delete(Db::i()->t('contact_messages'), 'uid = ?', [$uid])
            : self::setFlags($uid, ['is_trashed' => true]);
    }

    public static function shape(array $r): array
    {
        return [
            'id' => $r['uid'], 'uid' => $r['uid'], 'name' => $r['name'], 'email' => $r['email'],
            'phone' => $r['phone'], 'subject' => $r['subject'], 'message' => $r['body'] ?? '',
            'attachment' => json_decode_safe($r['attachment'] ?? 'null', null),
            'is_read' => (bool)$r['is_read'], 'is_starred' => (bool)$r['is_starred'],
            'date' => $r['created_at'], 'created_at' => $r['created_at'],
        ];
    }
}

// =============================================================================
// رسانه
// =============================================================================
final class Media
{
    public static function list(array $filters = []): array
    {
        $where = ['is_trashed = 0'];
        $params = [];
        if (!empty($filters['folder'])) { $where[] = 'folder = ?'; $params[] = $filters['folder']; }
        $sql = 'SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('media_files'))
             . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC';
        return array_map(fn($r) => [
            'id' => $r['uid'], 'uid' => $r['uid'], 'path' => $r['path'], 'name' => $r['name'],
            'mime' => $r['mime'], 'size' => (int)$r['size_bytes'], 'folder' => $r['folder'],
            'uploaded_by' => $r['uploaded_by'], 'created_at' => $r['created_at'],
        ], Db::i()->all($sql, $params));
    }

    public static function trash(): array
    {
        return array_map(fn($r) => [
            'id' => $r['uid'], 'uid' => $r['uid'], 'path' => $r['path'], 'name' => $r['name'],
            'mime' => $r['mime'], 'size' => (int)$r['size_bytes'], 'folder' => $r['folder'],
            'trashed_at' => $r['trashed_at'] ?? null, 'created_at' => $r['created_at'],
        ], Db::i()->all('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('media_files')) . ' WHERE is_trashed = 1 ORDER BY trashed_at DESC'));
    }

    public static function register(string $path, string $name, string $folder = '', string $by = '', ?int $size = null, string $mime = ''): string
    {
        $uid = odsco_uid('med');
        Db::i()->insert(Db::i()->t('media_files'), [
            'uid' => $uid, 'path' => $path, 'name' => $name,
            'mime' => $mime ?: (string)(mime_content_type(dirname(__DIR__) . '/' . $path) ?: ''),
            'size_bytes' => $size ?? (int)@filesize(dirname(__DIR__) . '/' . $path),
            'folder' => $folder, 'uploaded_by' => $by, 'is_trashed' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uid;
    }

    public static function moveToTrash(string $uid): void
    {
        Db::i()->update(Db::i()->t('media_files'), ['is_trashed' => 1, 'trashed_at' => date('Y-m-d H:i:s')], 'uid = ?', [$uid]);
    }

    public static function restore(string $uid): void
    {
        Db::i()->update(Db::i()->t('media_files'), ['is_trashed' => 0, 'trashed_at' => null], 'uid = ?', [$uid]);
    }

    public static function purge(string $uid): ?string
    {
        $row = Db::i()->one('SELECT path FROM ' . Db::i()->quoteIdent(Db::i()->t('media_files')) . ' WHERE uid = ?', [$uid]);
        Db::i()->delete(Db::i()->t('media_files'), 'uid = ?', [$uid]);
        return $row['path'] ?? null;
    }
}

// =============================================================================
// آمار بازدید
// =============================================================================
final class Views
{
    public static function track(): void
    {
        $db = Db::i();
        $today = date('Y-m-d');
        $table = $db->quoteIdent($db->t('site_views'));

        $updated = $db->run("UPDATE $table SET hits = hits + 1 WHERE day = ?", [$today]);
        if ($updated === 0) {
            try {
                $db->insert($db->t('site_views'), ['day' => $today, 'hits' => 1]);
            } catch (Throwable) {
                // رقابت همزمان روی درج — تلاش دوباره برای افزایش
                $db->run("UPDATE $table SET hits = hits + 1 WHERE day = ?", [$today]);
            }
        }
    }

    public static function today(): int
    {
        return (int)Db::i()->val('SELECT hits FROM ' . Db::i()->quoteIdent(Db::i()->t('site_views')) . ' WHERE day = ?', [date('Y-m-d')]);
    }

    /** @return array<int, array{date: string, count: int}> */
    public static function series(int $days = 30): array
    {
        $rows = Db::i()->all('SELECT day, hits FROM ' . Db::i()->quoteIdent(Db::i()->t('site_views')) . ' ORDER BY day DESC LIMIT ' . $days);
        $rows = array_reverse($rows);
        return array_map(fn($r) => ['date' => $r['day'], 'count' => (int)$r['hits']], $rows);
    }

    public static function total(): int
    {
        $site = (int)Db::i()->val('SELECT COALESCE(SUM(hits),0) FROM ' . Db::i()->quoteIdent(Db::i()->t('site_views')));
        $projects = (int)Db::i()->val('SELECT COALESCE(SUM(views),0) FROM ' . Db::i()->quoteIdent(Db::i()->t('projects')));
        $posts = (int)Db::i()->val('SELECT COALESCE(SUM(views),0) FROM ' . Db::i()->quoteIdent(Db::i()->t('blog_posts')));
        return $site + $projects + $posts;
    }
}

// =============================================================================
// لاگ فعالیت
// =============================================================================
final class ActivityLog
{
    public static function add(string $action, string $details = '', ?string $actor = null): void
    {
        Db::i()->insert(Db::i()->t('activity_logs'), [
            'uid' => odsco_uid('log'),
            'action' => $action,
            'details' => $details,
            'actor' => $actor ?? (string)($_SESSION['admin_user'] ?? $_SESSION['messenger_user_name'] ?? 'guest'),
            'ip' => client_ip(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        ActivityLog::prune();
    }

    public static function list(int $limit = 100, ?string $action = null): array
    {
        $sql = 'SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('activity_logs'));
        $params = [];
        if ($action) { $sql .= ' WHERE action = ?'; $params[] = $action; }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
        return array_map(fn($r) => [
            'id' => $r['uid'], 'action' => $r['action'], 'details' => $r['details'] ?? '',
            'user' => $r['actor'], 'ip' => $r['ip'], 'timestamp' => $r['created_at'],
        ], Db::i()->all($sql, $params));
    }

    public static function clear(): void { Db::i()->truncate('activity_logs'); }

    private static function prune(int $keep = 2000): void
    {
        $db = Db::i();
        $count = (int)$db->val('SELECT COUNT(*) FROM ' . $db->quoteIdent($db->t('activity_logs')));
        if ($count <= $keep) return;
        $cut = $db->val('SELECT created_at FROM ' . $db->quoteIdent($db->t('activity_logs')) . ' ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET ' . $keep);
        if ($cut) $db->delete($db->t('activity_logs'), 'created_at < ?', [$cut]);
    }
}
