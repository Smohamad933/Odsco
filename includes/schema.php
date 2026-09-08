<?php
/**
 * ============================================================================
 *  Odsco — اسکیمای دیتابیس (منبع واحد حقیقت)
 * ----------------------------------------------------------------------------
 *  هر جدول یک‌بار اینجا تعریف می‌شود و همان تعریف هم برای MySQL (پروداکشن)
 *  و هم برای SQLite (تست محلی) ترجمه می‌شود.
 *
 *  Placeholder ها: {AI} {PKAI} {BOOL} {DATETIME} {DATE} {TIME} {JSON}
 *                  {LONGTEXT} {MEDIUMTEXT} {TINYTEXT} {ENGINE}
 * ============================================================================
 */

declare(strict_types=1);

/**
 * @return array<string, string> نام جدول => بدنه CREATE TABLE
 */
function odsco_tables(): array
{
    return [
        // =====================================================================
        // کاربران (ادمین / مدیر / کارمند / کارفرما)
        // =====================================================================
        'users' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40)  NOT NULL,
            username      VARCHAR(64)  NOT NULL,
            password      VARCHAR(255) NOT NULL,
            role          VARCHAR(32)  NOT NULL DEFAULT 'viewer',
            full_name     VARCHAR(160) NOT NULL DEFAULT '',
            email         VARCHAR(160) NOT NULL DEFAULT '',
            phone         VARCHAR(32)  NOT NULL DEFAULT '',
            photo         VARCHAR(255) NOT NULL DEFAULT '',
            bio           TEXT,
            job_title     VARCHAR(160) NOT NULL DEFAULT '',
            department    VARCHAR(160) NOT NULL DEFAULT '',
            national_code VARCHAR(32)  NOT NULL DEFAULT '',
            hire_date     {DATE} NULL,
            monthly_salary DECIMAL(14,2) NULL,
            work_start    {TIME} NULL,
            work_end      {TIME} NULL,
            attendance_enabled {BOOL} NOT NULL DEFAULT 0,
            messenger_enabled  {BOOL} NOT NULL DEFAULT 0,
            client_uid    VARCHAR(40) NOT NULL DEFAULT '',
            is_active     {BOOL} NOT NULL DEFAULT 1,
            last_login    {DATETIME} NULL,
            created_at    {DATETIME} NOT NULL,
            updated_at    {DATETIME} NULL,
            UNIQUE (uid),
            UNIQUE (username)
        ",

        // =====================================================================
        // تنظیمات کلید/مقدار
        // =====================================================================
        'settings' => "
            setting_key   VARCHAR(80) NOT NULL,
            setting_value {LONGTEXT},
            updated_at    {DATETIME} NULL,
            PRIMARY KEY (setting_key)
        ",

        // =====================================================================
        // کارفرمایان
        // =====================================================================
        'clients' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            name          VARCHAR(200) NOT NULL,
            logo          VARCHAR(255) NOT NULL DEFAULT '',
            website       VARCHAR(255) NOT NULL DEFAULT '',
            contact_name  VARCHAR(160) NOT NULL DEFAULT '',
            contact_phone VARCHAR(32)  NOT NULL DEFAULT '',
            contact_email VARCHAR(160) NOT NULL DEFAULT '',
            address       VARCHAR(255) NOT NULL DEFAULT '',
            notes         TEXT,
            sort_order    INT NOT NULL DEFAULT 0,
            is_active     {BOOL} NOT NULL DEFAULT 1,
            created_at    {DATETIME} NOT NULL,
            updated_at    {DATETIME} NULL,
            UNIQUE (uid)
        ",

        // =====================================================================
        // دسته‌بندی پروژه‌ها
        // =====================================================================
        'categories' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            name          VARCHAR(120) NOT NULL,
            slug          VARCHAR(200) NOT NULL DEFAULT '',
            description   TEXT,
            icon          VARCHAR(16) NOT NULL DEFAULT '',
            color         VARCHAR(16) NOT NULL DEFAULT '',
            sort_order    INT NOT NULL DEFAULT 0,
            created_at    {DATETIME} NOT NULL,
            updated_at    {DATETIME} NULL,
            UNIQUE (uid)
        ",

        // =====================================================================
        // پروژه‌ها (نمونه‌کار عمومی + مدیریت داخلی)
        // =====================================================================
        'projects' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            title         VARCHAR(255) NOT NULL,
            slug          VARCHAR(255) NOT NULL DEFAULT '',
            category      VARCHAR(120) NOT NULL DEFAULT '',
            description   {LONGTEXT},
            images        {JSON},
            cover_image   VARCHAR(255) NOT NULL DEFAULT '',
            location      VARCHAR(255) NOT NULL DEFAULT '',
            client_name   VARCHAR(200) NOT NULL DEFAULT '',
            client_uid    VARCHAR(40)  NOT NULL DEFAULT '',
            manager_uid   VARCHAR(40)  NOT NULL DEFAULT '',
            project_year  VARCHAR(16)  NOT NULL DEFAULT '',
            area          VARCHAR(80)  NOT NULL DEFAULT '',
            budget        VARCHAR(80)  NOT NULL DEFAULT '',
            specs         {JSON},
            progress      INT NOT NULL DEFAULT 0,
            status        VARCHAR(24) NOT NULL DEFAULT 'active',
            start_date    {DATE} NULL,
            end_date      {DATE} NULL,
            show_on_home  {BOOL} NOT NULL DEFAULT 0,
            client_visible {BOOL} NOT NULL DEFAULT 0,
            views         INT NOT NULL DEFAULT 0,
            created_at    {DATETIME} NOT NULL,
            updated_at    {DATETIME} NULL,
            UNIQUE (uid)
        ",

        // اعضا و نقش هر عضو در پروژه
        'project_members' => "
            id            {AI} {PKAI},
            project_uid   VARCHAR(40) NOT NULL,
            user_uid      VARCHAR(40) NOT NULL,
            role_in_project VARCHAR(80) NOT NULL DEFAULT 'عضو تیم',
            percent       INT NOT NULL DEFAULT 0,
            added_by      VARCHAR(40) NOT NULL DEFAULT '',
            added_at      {DATETIME} NOT NULL,
            UNIQUE (project_uid, user_uid)
        ",

        // تسک‌ها
        'project_tasks' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            project_uid   VARCHAR(40) NOT NULL,
            title         VARCHAR(255) NOT NULL,
            description   {LONGTEXT},
            assignee_uid  VARCHAR(40) NOT NULL DEFAULT '',
            status        VARCHAR(24) NOT NULL DEFAULT 'todo',
            priority      VARCHAR(16) NOT NULL DEFAULT 'normal',
            progress      INT NOT NULL DEFAULT 0,
            due_date      {DATE} NULL,
            created_by    VARCHAR(40) NOT NULL DEFAULT '',
            created_at    {DATETIME} NOT NULL,
            updated_at    {DATETIME} NULL,
            completed_at  {DATETIME} NULL,
            UNIQUE (uid)
        ",

        'project_task_comments' => "
            id            {AI} {PKAI},
            task_uid      VARCHAR(40) NOT NULL,
            user_uid      VARCHAR(40) NOT NULL,
            body          TEXT,
            created_at    {DATETIME} NOT NULL
        ",

        // =====================================================================
        // هشدار / گزارش پیشرفت پروژه (برای کارفرما و تیم)
        // =====================================================================
        'project_updates' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            project_uid   VARCHAR(40) NOT NULL,
            author_uid    VARCHAR(40) NOT NULL DEFAULT '',
            title         VARCHAR(255) NOT NULL,
            body          {LONGTEXT},
            progress      INT NULL,
            level         VARCHAR(16) NOT NULL DEFAULT 'info',
            visibility    VARCHAR(16) NOT NULL DEFAULT 'both',
            attachment    VARCHAR(255) NOT NULL DEFAULT '',
            created_at    {DATETIME} NOT NULL,
            UNIQUE (uid)
        ",

        // =====================================================================
        // اعلان‌ها (Notification Center)
        // =====================================================================
        'notifications' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            user_uid      VARCHAR(40) NOT NULL,
            title         VARCHAR(255) NOT NULL,
            body          TEXT,
            type          VARCHAR(40) NOT NULL DEFAULT 'general',
            level         VARCHAR(16) NOT NULL DEFAULT 'info',
            link          VARCHAR(255) NOT NULL DEFAULT '',
            icon          VARCHAR(16)  NOT NULL DEFAULT '',
            source_uid    VARCHAR(40) NOT NULL DEFAULT '',
            is_read       {BOOL} NOT NULL DEFAULT 0,
            read_at       {DATETIME} NULL,
            created_at    {DATETIME} NOT NULL,
            UNIQUE (uid)
        ",

        // =====================================================================
        // محتوا
        // =====================================================================
        'blog_posts' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            title         VARCHAR(255) NOT NULL,
            slug          VARCHAR(255) NOT NULL DEFAULT '',
            excerpt       TEXT,
            content       {LONGTEXT},
            image         VARCHAR(255) NOT NULL DEFAULT '',
            category      VARCHAR(120) NOT NULL DEFAULT '',
            author        VARCHAR(120) NOT NULL DEFAULT '',
            tags          {JSON},
            status        VARCHAR(24) NOT NULL DEFAULT 'published',
            views         INT NOT NULL DEFAULT 0,
            post_date     {DATETIME} NOT NULL,
            created_at    {DATETIME} NOT NULL,
            updated_at    {DATETIME} NULL,
            UNIQUE (uid)
        ",

        'team_members' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            name          VARCHAR(160) NOT NULL,
            role          VARCHAR(160) NOT NULL DEFAULT '',
            bio           TEXT,
            photo         VARCHAR(255) NOT NULL DEFAULT '',
            email         VARCHAR(160) NOT NULL DEFAULT '',
            phone         VARCHAR(32)  NOT NULL DEFAULT '',
            linkedin      VARCHAR(255) NOT NULL DEFAULT '',
            instagram     VARCHAR(255) NOT NULL DEFAULT '',
            sort_order    INT NOT NULL DEFAULT 0,
            is_active     {BOOL} NOT NULL DEFAULT 1,
            created_at    {DATETIME} NOT NULL,
            updated_at    {DATETIME} NULL,
            UNIQUE (uid)
        ",

        'contact_messages' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            name          VARCHAR(160) NOT NULL DEFAULT '',
            email         VARCHAR(160) NOT NULL DEFAULT '',
            phone         VARCHAR(32)  NOT NULL DEFAULT '',
            subject       VARCHAR(255) NOT NULL DEFAULT '',
            body          TEXT,
            attachment    {JSON},
            is_read       {BOOL} NOT NULL DEFAULT 0,
            is_starred    {BOOL} NOT NULL DEFAULT 0,
            is_trashed    {BOOL} NOT NULL DEFAULT 0,
            created_at    {DATETIME} NOT NULL,
            UNIQUE (uid)
        ",

        'media_files' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            path          VARCHAR(500) NOT NULL,
            name          VARCHAR(255) NOT NULL DEFAULT '',
            mime          VARCHAR(120) NOT NULL DEFAULT '',
            size_bytes    BIGINT NOT NULL DEFAULT 0,
            folder        VARCHAR(80) NOT NULL DEFAULT '',
            uploaded_by   VARCHAR(64) NOT NULL DEFAULT '',
            is_trashed    {BOOL} NOT NULL DEFAULT 0,
            trashed_at    {DATETIME} NULL,
            created_at    {DATETIME} NOT NULL,
            UNIQUE (uid)
        ",

        'site_views' => "
            id            {AI} {PKAI},
            day           {DATE} NOT NULL,
            hits          INT NOT NULL DEFAULT 0,
            UNIQUE (day)
        ",

        'activity_logs' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            action        VARCHAR(64) NOT NULL DEFAULT '',
            details       TEXT,
            actor         VARCHAR(64) NOT NULL DEFAULT 'guest',
            ip            VARCHAR(45) NOT NULL DEFAULT '',
            created_at    {DATETIME} NOT NULL,
            UNIQUE (uid)
        ",

        // =====================================================================
        // پیام‌رسان
        // =====================================================================
        'messenger_conversations' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            user_1        VARCHAR(40) NOT NULL,
            user_2        VARCHAR(40) NOT NULL,
            created_at    {DATETIME} NOT NULL,
            UNIQUE (uid)
        ",

        'messenger_groups' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            name          VARCHAR(160) NOT NULL,
            avatar        VARCHAR(255) NOT NULL DEFAULT '',
            about         TEXT,
            type          VARCHAR(24) NOT NULL DEFAULT 'private',
            project_uid   VARCHAR(40) NOT NULL DEFAULT '',
            creator_uid   VARCHAR(40) NOT NULL DEFAULT '',
            only_admins_post {BOOL} NOT NULL DEFAULT 0,
            created_at    {DATETIME} NOT NULL,
            UNIQUE (uid)
        ",

        'messenger_group_members' => "
            id            {AI} {PKAI},
            group_uid     VARCHAR(40) NOT NULL,
            user_uid      VARCHAR(40) NOT NULL,
            role          VARCHAR(16) NOT NULL DEFAULT 'member',
            muted_until   {DATETIME} NULL,
            joined_at     {DATETIME} NOT NULL,
            UNIQUE (group_uid, user_uid)
        ",

        'messenger_messages' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            chat_type     VARCHAR(12) NOT NULL DEFAULT 'private',
            conversation_uid VARCHAR(40) NOT NULL DEFAULT '',
            group_uid     VARCHAR(40) NOT NULL DEFAULT '',
            sender_uid    VARCHAR(40) NOT NULL,
            type          VARCHAR(20) NOT NULL DEFAULT 'text',
            content       {LONGTEXT},
            file_path     VARCHAR(500) NOT NULL DEFAULT '',
            file_name     VARCHAR(255) NOT NULL DEFAULT '',
            file_size     DECIMAL(12,2) NOT NULL DEFAULT 0,
            mime          VARCHAR(120) NOT NULL DEFAULT '',
            meta          {JSON},
            reply_to      VARCHAR(40) NOT NULL DEFAULT '',
            forward_uid   VARCHAR(40) NOT NULL DEFAULT '',
            is_pinned     {BOOL} NOT NULL DEFAULT 0,
            is_system     {BOOL} NOT NULL DEFAULT 0,
            edited_at     {DATETIME} NULL,
            created_at    {DATETIME} NOT NULL,
            deleted_for_all {BOOL} NOT NULL DEFAULT 0,
            purge_at      {DATETIME} NULL,
            UNIQUE (uid)
        ",

        'messenger_message_reads' => "
            id            {AI} {PKAI},
            message_uid   VARCHAR(40) NOT NULL,
            user_uid      VARCHAR(40) NOT NULL,
            delivered_at  {DATETIME} NOT NULL,
            read_at       {DATETIME} NULL,
            UNIQUE (message_uid, user_uid)
        ",

        'messenger_message_deletes' => "
            id            {AI} {PKAI},
            message_uid   VARCHAR(40) NOT NULL,
            user_uid      VARCHAR(40) NOT NULL,
            created_at    {DATETIME} NOT NULL,
            UNIQUE (message_uid, user_uid)
        ",

        'messenger_reactions' => "
            id            {AI} {PKAI},
            message_uid   VARCHAR(40) NOT NULL,
            user_uid      VARCHAR(40) NOT NULL,
            emoji         VARCHAR(16) NOT NULL DEFAULT '',
            created_at    {DATETIME} NOT NULL,
            UNIQUE (message_uid, user_uid)
        ",

        // وضعیت هر کاربر در هر چت: آخرین پیام خوانده‌شده، پین، بی‌صدا، پیش‌نویس
        'messenger_chat_state' => "
            id            {AI} {PKAI},
            chat_key      VARCHAR(120) NOT NULL,
            user_uid      VARCHAR(40) NOT NULL,
            last_read_id  BIGINT NOT NULL DEFAULT 0,
            last_read_at  {DATETIME} NULL,
            is_pinned     {BOOL} NOT NULL DEFAULT 0,
            is_muted      {BOOL} NOT NULL DEFAULT 0,
            draft         TEXT,
            updated_at    {DATETIME} NULL,
            UNIQUE (chat_key, user_uid)
        ",

        'messenger_presence' => "
            user_uid      VARCHAR(40) NOT NULL,
            last_seen     BIGINT NOT NULL DEFAULT 0,
            last_seen_at  {DATETIME} NULL,
            PRIMARY KEY (user_uid)
        ",

        'messenger_typing' => "
            chat_key      VARCHAR(120) NOT NULL,
            user_uid      VARCHAR(40) NOT NULL,
            expires_at    BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (chat_key, user_uid)
        ",

        'messenger_devices' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            user_uid      VARCHAR(40) NOT NULL,
            device_name   VARCHAR(160) NOT NULL DEFAULT '',
            platform      VARCHAR(40)  NOT NULL DEFAULT '',
            browser       VARCHAR(80)  NOT NULL DEFAULT '',
            ip            VARCHAR(45)  NOT NULL DEFAULT '',
            last_active   {DATETIME} NOT NULL,
            created_at    {DATETIME} NOT NULL,
            UNIQUE (uid)
        ",

        // =====================================================================
        // حضور و غیاب
        // =====================================================================
        'attendance' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            user_uid      VARCHAR(40) NOT NULL,
            day           {DATE} NOT NULL,
            check_in      {TIME} NULL,
            check_out     {TIME} NULL,
            status        VARCHAR(16) NOT NULL DEFAULT 'present',
            source        VARCHAR(16) NOT NULL DEFAULT 'admin',
            note          TEXT,
            approved_by   VARCHAR(40) NOT NULL DEFAULT '',
            created_at    {DATETIME} NOT NULL,
            updated_at    {DATETIME} NULL,
            UNIQUE (uid),
            UNIQUE (user_uid, day)
        ",

        'attendance_requests' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            user_uid      VARCHAR(40) NOT NULL,
            day           {DATE} NOT NULL,
            check_in      {TIME} NULL,
            check_out     {TIME} NULL,
            reason        TEXT,
            status        VARCHAR(16) NOT NULL DEFAULT 'pending',
            reviewed_by   VARCHAR(40) NOT NULL DEFAULT '',
            reviewed_at   {DATETIME} NULL,
            review_note   VARCHAR(255) NOT NULL DEFAULT '',
            created_at    {DATETIME} NOT NULL,
            UNIQUE (uid)
        ",

        'attendance_qr' => "
            id            {AI} {PKAI},
            code          VARCHAR(64) NOT NULL,
            generated_by  VARCHAR(40) NOT NULL DEFAULT '',
            valid_from    {DATETIME} NOT NULL,
            valid_until   {DATETIME} NOT NULL,
            ip_lock       VARCHAR(45) NOT NULL DEFAULT '',
            scans         INT NOT NULL DEFAULT 0,
            is_active     {BOOL} NOT NULL DEFAULT 1,
            created_at    {DATETIME} NOT NULL,
            UNIQUE (code)
        ",

        'attendance_scans' => "
            id            {AI} {PKAI},
            qr_id         INT NOT NULL DEFAULT 0,
            user_uid      VARCHAR(40) NOT NULL,
            day           {DATE} NOT NULL,
            kind          VARCHAR(8) NOT NULL DEFAULT 'in',
            scanned_at    {DATETIME} NOT NULL,
            ip            VARCHAR(45) NOT NULL DEFAULT ''
        ",

        'work_holidays' => "
            id            {AI} {PKAI},
            day           {DATE} NOT NULL,
            title         VARCHAR(160) NOT NULL DEFAULT '',
            created_at    {DATETIME} NOT NULL,
            UNIQUE (day)
        ",

        // =====================================================================
        // اتوماسیون
        // =====================================================================
        'automation_rules' => "
            id            {AI} {PKAI},
            uid           VARCHAR(40) NOT NULL,
            title         VARCHAR(200) NOT NULL,
            event         VARCHAR(64) NOT NULL,
            conditions    {JSON},
            actions       {JSON},
            is_active     {BOOL} NOT NULL DEFAULT 1,
            run_count     INT NOT NULL DEFAULT 0,
            last_run      {DATETIME} NULL,
            created_by    VARCHAR(40) NOT NULL DEFAULT '',
            created_at    {DATETIME} NOT NULL,
            UNIQUE (uid)
        ",

        'automation_logs' => "
            id            {AI} {PKAI},
            rule_uid      VARCHAR(40) NOT NULL DEFAULT '',
            event         VARCHAR(64) NOT NULL DEFAULT '',
            message       VARCHAR(500) NOT NULL DEFAULT '',
            status        VARCHAR(16) NOT NULL DEFAULT 'ok',
            created_at    {DATETIME} NOT NULL
        ",
    ];
}

/**
 * ایندکس‌ها (خارج از CREATE TABLE تا در هر دو درایور یکسان باشد)
 *
 * @return array<int, array{0: string, 1: string, 2: array<int, string>}> [indexName, table, columns]
 */
function odsco_indexes(): array
{
    return [
        ['idx_users_role',        'users',          ['role']],
        ['idx_users_client',      'users',          ['client_uid']],
        ['idx_projects_client',   'projects',       ['client_uid']],
        ['idx_projects_manager',  'projects',       ['manager_uid']],
        ['idx_projects_status',   'projects',       ['status']],
        ['idx_pm_project',        'project_members',['project_uid']],
        ['idx_pm_user',           'project_members',['user_uid']],
        ['idx_tasks_project',     'project_tasks',  ['project_uid']],
        ['idx_tasks_assignee',    'project_tasks',  ['assignee_uid']],
        ['idx_tasks_status',      'project_tasks',  ['status']],
        ['idx_taskc_task',        'project_task_comments', ['task_uid']],
        ['idx_updates_project',   'project_updates',['project_uid']],
        ['idx_notif_user',        'notifications',  ['user_uid', 'is_read']],
        ['idx_blog_slug',         'blog_posts',     ['slug']],
        ['idx_blog_status',       'blog_posts',     ['status']],
        ['idx_media_folder',      'media_files',    ['folder']],
        ['idx_logs_actor',        'activity_logs',  ['actor']],
        ['idx_logs_created',      'activity_logs',  ['created_at']],
        ['idx_conv_u1',           'messenger_conversations', ['user_1']],
        ['idx_conv_u2',           'messenger_conversations', ['user_2']],
        ['idx_gm_group',          'messenger_group_members', ['group_uid']],
        ['idx_gm_user',           'messenger_group_members', ['user_uid']],
        ['idx_msg_conv',          'messenger_messages', ['conversation_uid', 'created_at']],
        ['idx_msg_group',         'messenger_messages', ['group_uid', 'created_at']],
        ['idx_msg_sender',        'messenger_messages', ['sender_uid']],
        ['idx_msg_purge',         'messenger_messages', ['purge_at']],
        ['idx_reads_user',        'messenger_message_reads', ['user_uid']],
        ['idx_react_msg',         'messenger_reactions', ['message_uid']],
        ['idx_devices_user',      'messenger_devices', ['user_uid']],
        ['idx_att_user_day',      'attendance',     ['user_uid', 'day']],
        ['idx_att_day',           'attendance',     ['day']],
        ['idx_attreq_user',       'attendance_requests', ['user_uid']],
        ['idx_attreq_status',     'attendance_requests', ['status']],
        ['idx_attscan_user',      'attendance_scans', ['user_uid', 'day']],
        ['idx_auto_event',        'automation_rules', ['event']],
        ['idx_autolog_rule',      'automation_logs', ['rule_uid']],
    ];
}

/**
 * ساخت/به‌روزرسانی همه جدول‌ها و ایندکس‌ها.
 *
 * @return array{created: array<int,string>, skipped: array<int,string>, indexes: int}
 */
function odsco_install_schema(Db $db): array
{
    $created = [];
    $skipped = [];

    foreach (odsco_tables() as $table => $body) {
        $exists = $db->tableExists($table);

        $body = preg_replace('/\s+/', ' ', trim($body));
        $body = rtrim(trim($body), ',');

        $sql = 'CREATE TABLE IF NOT EXISTS ' . $db->quoteIdent($db->t($table)) . " ($body) {ENGINE}";

        // SQLite عبارت خالی ENGINE را نمی‌فهمد؛ dialect آن را حذف می‌کند
        $db->exec($sql);

        if ($exists) {
            $skipped[] = $table;
            odsco_sync_columns($db, $table, $body);
        } else {
            $created[] = $table;
        }
    }

    $indexCount = 0;
    foreach (odsco_indexes() as [$name, $table, $cols]) {
        if (!$db->tableExists($table)) continue;
        $colSql = implode(', ', array_map(fn($c) => $db->quoteIdent($c), $cols));
        try {
            $db->exec('CREATE INDEX IF NOT EXISTS ' . $db->quoteIdent($name)
                . ' ON ' . $db->quoteIdent($db->t($table)) . " ($colSql)");
            $indexCount++;
        } catch (Throwable) {
            // ایندکس تکراری — مشکلی نیست
        }
    }

    return ['created' => $created, 'skipped' => $skipped, 'indexes' => $indexCount];
}

/**
 * افزودن ستون‌های جدید به جدول‌های از قبل موجود (مهاجرت بی‌خطر).
 */
function odsco_sync_columns(Db $db, string $table, string $body): void
{
    $existing = [];
    if ($db->isMysql()) {
        foreach ($db->all('SHOW COLUMNS FROM ' . $db->quoteIdent($db->t($table))) as $row) {
            $existing[strtolower((string)$row['Field'])] = true;
        }
    } else {
        foreach ($db->all('PRAGMA table_info(' . $db->quoteIdent($db->t($table)) . ')') as $row) {
            $existing[strtolower((string)$row['name'])] = true;
        }
    }

    $parts = explode(',', $body);
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || preg_match('/^(UNIQUE|PRIMARY|KEY|INDEX|CONSTRAINT)\b/i', $part)) continue;
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s+/', $part, $m)) continue;

        $col = $m[1];
        if (isset($existing[strtolower($col)])) continue;

        try {
            $db->exec('ALTER TABLE ' . $db->quoteIdent($db->t($table)) . ' ADD COLUMN ' . $part);
        } catch (Throwable) {
            // ستون خاص (مثل NOT NULL بدون مقدار پیش‌فرض) — نادیده گرفته می‌شود
        }
    }
}

/**
 * خروجی SQL خام مخصوص MySQL (برای phpMyAdmin).
 */
function odsco_schema_mysql_sql(string $prefix = ''): string
{
    $out = "-- Odsco — اسکیمای MySQL\n";
    $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach (odsco_tables() as $table => $body) {
        $body = preg_replace('/\s+/', ' ', trim($body));
        $body = rtrim(trim($body), ',');
        $map = [
            '{AI}' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT', '{PKAI}' => 'PRIMARY KEY',
            '{JSON}' => 'JSON', '{LONGTEXT}' => 'LONGTEXT', '{MEDIUMTEXT}' => 'MEDIUMTEXT',
            '{TINYTEXT}' => 'TINYTEXT', '{BOOL}' => 'TINYINT(1)', '{DATETIME}' => 'DATETIME',
            '{DATE}' => 'DATE', '{TIME}' => 'TIME',
            '{ENGINE}' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
        $body = strtr($body, $map);
        $q = fn(string $i) => '`' . str_replace('`', '', $i) . '`';
        $out .= 'DROP TABLE IF EXISTS ' . $q($prefix . $table) . ";\n";
        $out .= 'CREATE TABLE ' . $q($prefix . $table) . " (\n  " . str_replace(', ', ",\n  ", $body) . "\n) " . $map['{ENGINE}'] . ";\n\n";
    }

    foreach (odsco_indexes() as [$name, $table, $cols]) {
        $colSql = implode(', ', array_map(fn($c) => '`' . $c . '`', $cols));
        $out .= 'CREATE INDEX `' . $name . '` ON `' . $prefix . $table . "` ($colSql);\n";
    }

    $out .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";
    return $out;
}
