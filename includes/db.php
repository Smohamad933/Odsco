<?php
/**
 * ============================================================================
 *  Odsco — لایه اتصال به دیتابیس
 * ----------------------------------------------------------------------------
 *  درایور اصلی: MySQL (pdo_mysql)
 *  درایور جایگزین (فقط توسعه/تست محلی): SQLite (pdo_sqlite)
 *
 *  تمام کوئری‌های پروژه از این کلاس عبور می‌کنند تا SQL Injection بسته باشد
 *  و تفاوت‌های جزئی بین MySQL و SQLite در یک نقطه مدیریت شود.
 * ============================================================================
 */

declare(strict_types=1);

final class Db
{
    private static ?self $instance = null;

    private PDO $pdo;
    private string $driver = 'mysql';
    private string $prefix = '';
    private bool $debug = false;
    private array $log = [];

    private function __construct() {}

    /**
     * راه‌اندازی اتصال از روی آرایه پیکربندی.
     *
     * @param array{
     *   driver?: string, host?: string, port?: int|string, name?: string,
     *   user?: string, pass?: string, charset?: string, socket?: string,
     *   prefix?: string, sqlite_path?: string, debug?: bool
     * } $cfg
     */
    public static function boot(array $cfg): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        $cfg    = self::normalizeConfig($cfg);
        $driver = strtolower((string)($cfg['driver'] ?? 'mysql'));
        $self   = new self();
        $self->driver = $driver === 'sqlite' ? 'sqlite' : 'mysql';
        $self->prefix = (string)($cfg['prefix'] ?? '');
        $self->debug  = (bool)($cfg['debug'] ?? false);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            if ($self->driver === 'sqlite') {
                $path = (string)($cfg['sqlite_path'] ?? sys_get_temp_dir() . '/odsco.sqlite');
                $dir  = dirname($path);
                if ($path !== ':memory:' && !is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $self->pdo = new PDO('sqlite:' . $path, null, null, $options);
                $self->pdo->exec('PRAGMA foreign_keys = ON');
                $self->pdo->exec('PRAGMA journal_mode = WAL');
                $self->pdo->exec('PRAGMA busy_timeout = 5000');
            } else {
                $charset = (string)($cfg['charset'] ?? 'utf8mb4');
                if (!empty($cfg['socket'])) {
                    $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $cfg['socket'], $cfg['name'] ?? '', $charset);
                } else {
                    $dsn = sprintf(
                        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                        $cfg['host'] ?? '127.0.0.1',
                        (string)($cfg['port'] ?? 3306),
                        $cfg['name'] ?? '',
                        $charset
                    );
                }
                $self->pdo = new PDO($dsn, (string)($cfg['user'] ?? ''), (string)($cfg['pass'] ?? ''), $options);
                $self->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
            }
        } catch (PDOException $e) {
            self::fail($e);
        }

        self::$instance = $self;
        return $self;
    }

    /**
     * یکسان‌سازی نام کلیدهای پیکربندی.
     * بعضی‌ها به‌جای name/user/pass از database/username/password استفاده می‌کنند؛
     * هر دو شکل پذیرفته می‌شود.
     *
     * @param  array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    public static function normalizeConfig(array $cfg): array
    {
        foreach (['name' => ['database', 'dbname'], 'user' => ['username'], 'pass' => ['password']] as $key => $alts) {
            if (empty($cfg[$key])) {
                foreach ($alts as $alt) {
                    if (!empty($cfg[$alt])) { $cfg[$key] = $cfg[$alt]; break; }
                }
            }
        }
        return $cfg;
    }

    /** خطای اتصال به دیتابیس — پیام فارسی و قابل فهم برای کاربر */
    private static function fail(PDOException $e): never
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "❌ خطای اتصال به دیتابیس: " . $e->getMessage() . PHP_EOL);
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }

        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>خطای دیتابیس</title>'
            . '<style>body{font-family:Tahoma,sans-serif;background:#0e1621;color:#fff;display:flex;'
            . 'align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}'
            . '.b{background:#17212b;border-radius:16px;padding:32px;max-width:560px;width:100%}'
            . 'h1{font-size:18px;margin:0 0 12px}p{font-size:13px;line-height:2;color:#9fb0c3;margin:0 0 8px}'
            . 'code{background:#0e1621;padding:2px 6px;border-radius:5px;color:#ff8a8a;font-size:12px;'
            . 'direction:ltr;display:inline-block}a{color:#5288c1}</style></head><body><div class="b">'
            . '<h1>🗄️ اتصال به دیتابیس برقرار نشد</h1>'
            . '<p>تنظیمات دیتابیس در فایل <code>includes/config.local.php</code> را بررسی کنید.</p>'
            . '<p><a href="' . htmlspecialchars(self::base() . '/install/', ENT_QUOTES) . '">رفتن به نصب‌کننده</a></p>'
            . '<p style="direction:ltr;text-align:left;font-size:11px;color:#66788c">'
            . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>'
            . '</div></body></html>';
        exit;
    }

    private static function base(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        return rtrim(str_replace('\\', '/', dirname($script)), '/');
    }

    public static function i(): self
    {
        if (!self::$instance instanceof self) {
            throw new RuntimeException('دیتابیس هنوز راه‌اندازی نشده است (Db::boot)');
        }
        return self::$instance;
    }

    public static function ready(): bool
    {
        return self::$instance instanceof self;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function pdo(): PDO      { return $this->pdo; }
    public function driver(): string { return $this->driver; }
    public function isMysql(): bool  { return $this->driver === 'mysql'; }

    /** نام جدول با پیشوند */
    public function t(string $table): string
    {
        return $this->prefix . $table;
    }

    /** نام ستون/جدول امن برای هر دو درایور */
    public function quoteIdent(string $ident): string
    {
        return $this->driver === 'sqlite'
            ? '"' . str_replace('"', '""', $ident) . '"'
            : '`' . str_replace('`', '', $ident) . '`';
    }

    /** تبدیل رشته DDL دارای placeholder به SQL مخصوص درایور جاری */
    public function dialect(string $sql): string
    {
        $map = $this->driver === 'sqlite'
            ? [
                '{AI}'      => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                '{PKAI}'    => '',
                '{JSON}'    => 'TEXT',
                '{LONGTEXT}'=> 'TEXT',
                '{MEDIUMTEXT}'=>'TEXT',
                '{BOOL}'    => 'INTEGER',
                '{DATETIME}'=> 'TEXT',
                '{DATE}'    => 'TEXT',
                '{TIME}'    => 'TEXT',
                '{ENGINE}'  => '',
                '{TINYTEXT}'=> 'TEXT',
                '{NOW}'     => "strftime('%Y-%m-%d %H:%M:%S','now','localtime')",
                '{CURDATE}' => "strftime('%Y-%m-%d','now','localtime')",
            ]
            : [
                '{AI}'      => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                '{PKAI}'    => 'PRIMARY KEY',
                '{JSON}'    => 'JSON',
                '{LONGTEXT}'=> 'LONGTEXT',
                '{MEDIUMTEXT}'=>'MEDIUMTEXT',
                '{BOOL}'    => 'TINYINT(1)',
                '{DATETIME}'=> 'DATETIME',
                '{DATE}'    => 'DATE',
                '{TIME}'    => 'TIME',
                '{ENGINE}'  => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                '{TINYTEXT}'=> 'TINYTEXT',
                '{NOW}'     => 'NOW()',
                '{CURDATE}' => 'CURDATE()',
            ];

        // در SQLite عبارت «ADD PRIMARY KEY» جدا معنا ندارد
        $sql = strtr($sql, $map);
        return preg_replace('/\s+/', ' ', trim($sql));
    }

    // -----------------------------------------------------------------
    // کوئری خام
    // -----------------------------------------------------------------

    public function exec(string $sql, array $params = []): PDOStatement
    {
        $sql = $this->dialect($sql);
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $k = is_int($key) ? $key + 1 : $key;
            $stmt->bindValue($k, $value, self::pdoType($value));
        }

        $stmt->execute();

        if ($this->debug) {
            $this->log[] = ['sql' => $sql, 'params' => $params];
        }

        return $stmt;
    }

    private static function pdoType(mixed $v): int
    {
        if (is_int($v))  return PDO::PARAM_INT;
        if (is_bool($v)) return PDO::PARAM_INT;
        if (is_null($v)) return PDO::PARAM_NULL;
        return PDO::PARAM_STR;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->exec($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->exec($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function val(string $sql, array $params = []): mixed
    {
        $v = $this->exec($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function run(string $sql, array $params = []): int
    {
        return $this->exec($sql, $params)->rowCount();
    }

    public function lastId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // -----------------------------------------------------------------
    // helper های جدول‌محور
    // -----------------------------------------------------------------

    /**
     * درج یک ردیف و برگرداندن id عددی.
     * مقادیر bool به 0/1 و null به NULL تبدیل می‌شوند.
     */
    public function insert(string $table, array $data): int
    {
        if ($data === []) {
            $this->exec('INSERT INTO ' . $this->quoteIdent($this->t($table)) . ' () VALUES ()');
            return $this->lastId();
        }

        $cols = array_keys($data);
        $colSql = implode(', ', array_map(fn($c) => $this->quoteIdent((string)$c), $cols));
        $ph     = implode(', ', array_fill(0, count($cols), '?'));

        $this->exec(
            'INSERT INTO ' . $this->quoteIdent($this->t($table)) . " ($colSql) VALUES ($ph)",
            array_values(array_map([$this, 'norm'], $data))
        );

        return $this->lastId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if ($data === []) return 0;

        $set = implode(', ', array_map(
            fn($c) => $this->quoteIdent((string)$c) . ' = ?',
            array_keys($data)
        ));

        return $this->run(
            'UPDATE ' . $this->quoteIdent($this->t($table)) . " SET $set WHERE $where",
            array_merge(array_values(array_map([$this, 'norm'], $data)), array_values($whereParams))
        );
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->run(
            'DELETE FROM ' . $this->quoteIdent($this->t($table)) . " WHERE $where",
            array_values($params)
        );
    }

    public function count(string $table, string $where = '1=1', array $params = []): int
    {
        return (int)$this->val(
            'SELECT COUNT(*) FROM ' . $this->quoteIdent($this->t($table)) . " WHERE $where",
            array_values($params)
        );
    }

    public function truncate(string $table): void
    {
        $this->run('DELETE FROM ' . $this->quoteIdent($this->t($table)));
        if ($this->driver === 'sqlite') {
            // ریست AUTOINCREMENT
            try { $this->run('DELETE FROM sqlite_sequence WHERE name = ?', [$this->t($table)]); }
            catch (Throwable) { /* جدول در sqlite_sequence نیست */ }
        }
    }

    /** درج یا به‌روزرسانی بر اساس ستون‌های یکتا */
    public function upsert(string $table, array $data, array $uniqueCols): void
    {
        $uq = implode(', ', array_map(fn($c) => $this->quoteIdent((string)$c), $uniqueCols));
        $cols = array_keys($data);
        $colSql = implode(', ', array_map(fn($c) => $this->quoteIdent((string)$c), $cols));
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $vals = array_values(array_map([$this, 'norm'], $data));

        if ($this->driver === 'sqlite') {
            $updates = [];
            foreach ($cols as $c) {
                if (in_array($c, $uniqueCols, true)) continue;
                $updates[] = $this->quoteIdent((string)$c) . ' = excluded.' . $this->quoteIdent((string)$c);
            }
            $sql = 'INSERT INTO ' . $this->quoteIdent($this->t($table)) . " ($colSql) VALUES ($ph)";
            if ($updates) {
                $sql .= ' ON CONFLICT (' . $uq . ') DO UPDATE SET ' . implode(', ', $updates);
            } else {
                $sql .= ' ON CONFLICT (' . $uq . ') DO NOTHING';
            }
            $this->exec($sql, $vals);
            return;
        }

        $updates = [];
        foreach ($cols as $c) {
            if (in_array($c, $uniqueCols, true)) continue;
            $q = $this->quoteIdent((string)$c);
            $updates[] = "$q = VALUES($q)";
        }
        $sql = 'INSERT INTO ' . $this->quoteIdent($this->t($table)) . " ($colSql) VALUES ($ph)";
        $sql .= $updates ? ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates) : ' ON DUPLICATE KEY UPDATE ' . $this->quoteIdent((string)$uniqueCols[0]) . ' = ' . $this->quoteIdent((string)$uniqueCols[0]);
        $this->exec($sql, $vals);
    }

    public function tableExists(string $table): bool
    {
        $name = $this->t($table);
        try {
            if ($this->driver === 'sqlite') {
                return (bool)$this->val(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
                    [$name]
                );
            }
            return (bool)$this->val('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$name]);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<int, string> */
    public function tables(): array
    {
        if ($this->driver === 'sqlite') {
            return array_map(
                'strval',
                array_column($this->all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"), 'name')
            );
        }
        return array_map(
            'strval',
            array_column($this->all('SHOW TABLES'), array_key_first((array)$this->one('SHOW TABLES')))
        );
    }

    private function norm(mixed $v): mixed
    {
        if (is_bool($v)) return $v ? 1 : 0;
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $v;
    }

    /** @return array<int, array{sql: string, params: array}> */
    public function queryLog(): array
    {
        return $this->log;
    }
}
