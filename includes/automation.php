<?php
/**
 * ============================================================================
 *  Odsco — موتور اتوماسیون + ارسال هشدار پروژه
 * ----------------------------------------------------------------------------
 *  هر قانون = رویداد (event) + شرط‌ها (conditions) + کارها (actions)
 *
 *  رویدادهای پشتیبانی‌شده:
 *    project.update.created   گزارش/هشدار پیشرفت پروژه ثبت شد
 *    project.deadline_soon    نزدیک شدن به پایان پروژه
 *    task.overdue             تسک عقب افتاده
 *    task.due_soon            تسک در حال نزدیک شدن به سررسید
 *    attendance.absent        غیبت کارمند
 *    attendance.late          تاخیر ورود
 *    client.message           پیام جدید از فرم تماس سایت
 *    cron.daily               اجرای روزانه (برای cron)
 *
 *  کارهای پشتیبانی‌شده:
 *    notify  → اعلان داخل برنامه‌ای  (target: managers|members|staff|clients|user)
 *    log     → نوشتن در لاگ اتوماسیون
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

final class Automation
{
    public const EVENTS = [
        'project.update.created' => '📢 گزارش پیشرفت پروژه ثبت شد',
        'project.deadline_soon'  => '⏳ نزدیک شدن به پایان پروژه',
        'task.overdue'           => '🚨 تسک عقب افتاده',
        'task.due_soon'          => '⏰ نزدیک شدن سررسید تسک',
        'attendance.absent'      => '❌ غیبت کارمند',
        'attendance.late'        => '⏰ تاخیر ورود کارمند',
        'client.message'         => '📨 پیام جدید از فرم تماس',
        'cron.daily'             => '🕒 اجرای زمان‌بندی روزانه',
    ];

    /** قالب‌های آماده */
    public static function templates(): array
    {
        return [
            [
                'title' => 'هشدار گزارش پیشرفت به مدیران و اعضای پروژه',
                'event' => 'project.update.created',
                'conditions' => [],
                'actions' => [
                    ['type' => 'notify', 'target' => 'managers', 'title' => '📢 گزارش جدید پروژه: {project}', 'body' => '{title}'],
                    ['type' => 'notify', 'target' => 'members',  'title' => '📢 گزارش جدید پروژه: {project}', 'body' => '{title}'],
                ],
            ],
            [
                'title' => 'هشدار تسک عقب‌افتاده به مسئول تسک و مدیران',
                'event' => 'task.overdue',
                'conditions' => [],
                'actions' => [
                    ['type' => 'notify', 'target' => 'assignee', 'title' => '🚨 تسک عقب افتاده: {task}', 'body' => 'سررسید این تسک گذشته است. لطفاً وضعیت را به‌روز کنید.'],
                    ['type' => 'notify', 'target' => 'managers', 'title' => '🚨 تسک عقب افتاده: {task}', 'body' => 'مسئول: {assignee}'],
                ],
            ],
            [
                'title' => 'یادآوری سررسید تسک (۲ روز قبل)',
                'event' => 'task.due_soon',
                'conditions' => [],
                'actions' => [
                    ['type' => 'notify', 'target' => 'assignee', 'title' => '⏰ سررسید تسک نزدیک است: {task}', 'body' => 'سررسید: {due_date}'],
                ],
            ],
            [
                'title' => 'اطلاع غیبت کارمند به مدیران',
                'event' => 'attendance.absent',
                'conditions' => [],
                'actions' => [
                    ['type' => 'notify', 'target' => 'managers', 'title' => '❌ غیبت: {user}', 'body' => '{user} امروز حضور ثبت نکرده است.'],
                ],
            ],
            [
                'title' => 'اطلاع تاخیر ورود به مدیر مستقیم',
                'event' => 'attendance.late',
                'conditions' => [],
                'actions' => [
                    ['type' => 'notify', 'target' => 'managers', 'title' => '⏰ تاخیر ورود: {user}', 'body' => 'ساعت ورود: {time}'],
                ],
            ],
            [
                'title' => 'اعلان پیام جدید سایت به مدیران',
                'event' => 'client.message',
                'conditions' => [],
                'actions' => [
                    ['type' => 'notify', 'target' => 'managers', 'title' => '📨 پیام جدید از سایت', 'body' => '{subject} — از طرف {name}'],
                ],
            ],
        ];
    }

    // =====================================================================
    // مدیریت قوانین
    // =====================================================================

    public static function rules(?string $event = null): array
    {
        $db = Db::i();
        $sql = 'SELECT * FROM ' . $db->quoteIdent($db->t('automation_rules'));
        $params = [];
        if ($event) { $sql .= ' WHERE event = ?'; $params[] = $event; }
        $sql .= ' ORDER BY is_active DESC, created_at DESC';

        return array_map(fn($r) => array_merge($r, [
            'conditions' => json_decode_safe($r['conditions'], []),
            'actions'    => json_decode_safe($r['actions'], []),
            'is_active'  => (bool)$r['is_active'],
        ]), $db->all($sql, $params));
    }

    public static function find(string $uid): ?array
    {
        $row = Db::i()->one('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('automation_rules')) . ' WHERE uid = ?', [$uid]);
        return $row ? array_merge($row, [
            'conditions' => json_decode_safe($row['conditions'], []),
            'actions'    => json_decode_safe($row['actions'], []),
            'is_active'  => (bool)$row['is_active'],
        ]) : null;
    }

    public static function create(array $d, string $byUid = ''): string
    {
        $uid = odsco_uid('rule');
        Db::i()->insert(Db::i()->t('automation_rules'), [
            'uid' => $uid, 'title' => $d['title'], 'event' => $d['event'],
            'conditions' => json_encode_safe($d['conditions'] ?? []),
            'actions' => json_encode_safe($d['actions'] ?? []),
            'is_active' => isset($d['is_active']) ? ($d['is_active'] ? 1 : 0) : 1,
            'created_by' => $byUid, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uid;
    }

    public static function update(string $uid, array $d): void
    {
        $clean = [];
        foreach (['title','event'] as $k) if (array_key_exists($k, $d)) $clean[$k] = $d[$k];
        if (array_key_exists('conditions', $d)) $clean['conditions'] = json_encode_safe($d['conditions']);
        if (array_key_exists('actions', $d))    $clean['actions']    = json_encode_safe($d['actions']);
        if (array_key_exists('is_active', $d))  $clean['is_active']  = $d['is_active'] ? 1 : 0;
        if ($clean) Db::i()->update(Db::i()->t('automation_rules'), $clean, 'uid = ?', [$uid]);
    }

    public static function toggle(string $uid): bool
    {
        $rule = self::find($uid);
        if (!$rule) return false;
        self::update($uid, ['is_active' => !$rule['is_active']]);
        return !$rule['is_active'];
    }

    public static function delete(string $uid): void
    {
        Db::i()->delete(Db::i()->t('automation_rules'), 'uid = ?', [$uid]);
    }

    /** نصب قالب‌های آماده (فقط اگر قانونی با همان عنوان وجود نداشته باشد) */
    public static function installTemplates(string $byUid = ''): int
    {
        $existing = array_column(self::rules(), 'title');
        $n = 0;
        foreach (self::templates() as $t) {
            if (in_array($t['title'], $existing, true)) continue;
            self::create($t, $byUid);
            $n++;
        }
        return $n;
    }

    // =====================================================================
    // اجرای رویداد
    // =====================================================================

    /**
     * اجرای همه قوانین مربوط به یک رویداد.
     *
     * @param array<string, mixed> $context
     * @return int تعداد کارهای اجراشده
     */
    public static function fire(string $event, array $context = []): int
    {
        $executed = 0;

        foreach (self::rules($event) as $rule) {
            if (!$rule['is_active']) continue;
            if (!self::matchConditions($rule['conditions'] ?: [], $context)) continue;

            foreach ($rule['actions'] as $action) {
                if (self::runAction($action, $context)) $executed++;
            }

            Db::i()->update(Db::i()->t('automation_rules'), [
                'run_count' => (int)$rule['run_count'] + 1, 'last_run' => date('Y-m-d H:i:s'),
            ], 'uid = ?', [$rule['uid']]);

            self::log($rule['uid'], $event, 'اجرا شد — ' . count($rule['actions']) . ' کار', 'ok');
        }

        return $executed;
    }

    private static function matchConditions(array $conditions, array $context): bool
    {
        foreach ($conditions as $c) {
            $field = (string)($c['field'] ?? '');
            $op    = (string)($c['op'] ?? 'eq');
            $value = $c['value'] ?? null;
            $actual = $context[$field] ?? null;

            $ok = match ($op) {
                'eq'        => (string)$actual === (string)$value,
                'neq'       => (string)$actual !== (string)$value,
                'gt'        => is_numeric($actual) && is_numeric($value) && $actual > $value,
                'lt'        => is_numeric($actual) && is_numeric($value) && $actual < $value,
                'contains'  => str_contains((string)$actual, (string)$value),
                'empty'     => $actual === null || $actual === '',
                'not_empty' => $actual !== null && $actual !== '',
                default     => true,
            };
            if (!$ok) return false;
        }
        return true;
    }

    /** @return bool آیا کاری انجام شد؟ */
    private static function runAction(array $action, array $context): bool
    {
        $type = (string)($action['type'] ?? '');

        if ($type === 'log') {
            self::log('', (string)($context['event'] ?? 'manual'), self::fill((string)($action['text'] ?? ''), $context), 'ok');
            return true;
        }

        if ($type !== 'notify') return false;

        $targets = self::resolveTargets((string)($action['target'] ?? 'managers'), $context);
        if (!$targets) return false;

        $title = self::fill((string)($action['title'] ?? 'اعلان'), $context);
        $body  = self::fill((string)($action['body'] ?? ''), $context);
        $level = (string)($action['level'] ?? 'info');
        $link  = self::fill((string)($action['link'] ?? ''), $context);

        Notifications::broadcast($targets, $title, $body, [
            'type' => 'automation', 'level' => $level, 'icon' => '⚙️', 'link' => $link,
        ]);
        return true;
    }

    /** @return array<int, string> */
    private static function resolveTargets(string $target, array $context): array
    {
        switch ($target) {
            case 'managers':
                return array_column(array_filter(
                    Users::list(['active' => true]),
                    fn($u) => Users::level($u['role']) >= Users::level('manager')
                ), 'uid');

            case 'staff':
                return array_column(Users::staff(), 'uid');

            case 'members':
                $projectUid = (string)($context['project_uid'] ?? '');
                if ($projectUid === '') return [];
                $members = ProjectMembers::uids($projectUid);
                $project = Projects::find($projectUid);
                if ($project && $project['manager_uid']) $members[] = $project['manager_uid'];
                return array_values(array_unique($members));

            case 'clients':
                $projectUid = (string)($context['project_uid'] ?? '');
                if ($projectUid === '') return [];
                $project = Projects::find($projectUid);
                if (!$project || $project['client_uid'] === '') return [];
                return array_column(Users::list(['client_uid' => $project['client_uid'], 'active' => true]), 'uid');

            case 'assignee':
                $u = (string)($context['assignee_uid'] ?? '');
                return $u ? [$u] : [];

            case 'user':
                $u = (string)($context['user_uid'] ?? '');
                return $u ? [$u] : [];

            case 'admin':
                return array_column(array_filter(Users::list(['active' => true]), fn($u) => $u['role'] === 'admin'), 'uid');

            default:
                // لیست صریح شناسه‌ها
                if (str_starts_with($target, 'uids:')) {
                    return array_values(array_filter(explode(',', substr($target, 5))));
                }
                return [];
        }
    }

    /** جایگزینی {متغیر} در متن */
    private static function fill(string $template, array $context): string
    {
        return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function (array $m) use ($context): string {
            $v = $context[$m[1]] ?? '';
            return is_scalar($v) ? (string)$v : '';
        }, $template) ?? $template;
    }

    public static function log(string $ruleUid, string $event, string $message, string $status = 'ok'): void
    {
        Db::i()->insert(Db::i()->t('automation_logs'), [
            'rule_uid' => $ruleUid, 'event' => $event, 'message' => $message,
            'status' => $status, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function logs(int $limit = 100): array
    {
        return Db::i()->all('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('automation_logs'))
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit);
    }

    // =====================================================================
    // زمان‌بندی روزانه (cron)
    // =====================================================================

    /**
     * کارهای روزانه:
     *  • علامت‌گذاری غیبت روز قبل
     *  • پاک‌سازی پیام‌های منقضی‌شده پیام‌رسان
     *  • هشدار تسک‌های عقب‌افتاده و نزدیک به سررسید
     *  • هشدار پروژه‌های نزدیک به پایان
     */
    public static function runDaily(): array
    {
        $report = ['absent' => 0, 'purged' => 0, 'overdue' => 0, 'due_soon' => 0, 'deadline' => 0];

        $report['absent'] = Attendance::autoMarkAbsent(date('Y-m-d', strtotime('-1 day')));
        foreach (Attendance::absentToday() as $u) {
            self::fire('attendance.absent', ['event' => 'attendance.absent', 'user_uid' => $u['uid'], 'user' => $u['full_name']]);
        }

        $report['purged'] = Messenger::purgeExpired() + Messenger::purgeReadPrivate();

        foreach (Tasks::overdue() as $t) {
            self::fire('task.overdue', [
                'event' => 'task.overdue', 'project_uid' => $t['project_uid'], 'task' => $t['title'],
                'assignee_uid' => $t['assignee_uid'], 'assignee' => $t['assignee_name'],
                'due_date' => jalali_date_long($t['due_date']),
            ]);
            $report['overdue']++;
        }

        foreach (Tasks::dueSoon(2) as $t) {
            self::fire('task.due_soon', [
                'event' => 'task.due_soon', 'project_uid' => $t['project_uid'], 'task' => $t['title'],
                'assignee_uid' => $t['assignee_uid'], 'assignee' => $t['assignee_name'],
                'due_date' => jalali_date_long($t['due_date']),
            ]);
            $report['due_soon']++;
        }

        $soon = date('Y-m-d', strtotime('+7 days'));
        foreach (Projects::list() as $p) {
            if (empty($p['end_date']) || $p['status'] === 'done') continue;
            if ($p['end_date'] > $soon || $p['end_date'] < date('Y-m-d')) continue;
            self::fire('project.deadline_soon', [
                'event' => 'project.deadline_soon', 'project_uid' => $p['uid'], 'project' => $p['title'],
                'due_date' => jalali_date_long($p['end_date']),
            ]);
            $report['deadline']++;
        }

        self::log('', 'cron.daily', 'اجرای روزانه: ' . json_encode($report, JSON_UNESCAPED_UNICODE), 'ok');
        self::fire('cron.daily', ['event' => 'cron.daily'] + $report);

        return $report;
    }
}

// =============================================================================
// ارسال هشدار / گزارش پیشرفت پروژه
// =============================================================================

final class ProjectAlert
{
    /**
     * ثبت گزارش پیشرفت پروژه و اطلاع‌رسانی همزمان به:
     *   • اعضای پروژه (اعلان داخل برنامه‌ای)
     *   • کاربران کارفرما (اعلان + نمایش در پنل کارفرما)
     *   • گروه پیام‌رسان مرتبط با پروژه (پیام سیستمی)
     *
     * @return array{ok: bool, update_uid?: string, notified?: int, message: string}
     */
    public static function send(string $projectUid, array $data, string $authorUid): array
    {
        $project = Projects::find($projectUid);
        if (!$project) return ['ok' => false, 'message' => 'پروژه پیدا نشد'];

        // ۱) ثبت گزارش
        $updateUid = ProjectUpdates::create([
            'project_uid' => $projectUid,
            'author_uid'  => $authorUid,
            'title'       => $data['title'] ?? '',
            'body'        => $data['body'] ?? '',
            'progress'    => $data['progress'] ?? null,
            'level'       => $data['level'] ?? 'info',
            'visibility'  => $data['visibility'] ?? 'both',
            'attachment'  => $data['attachment'] ?? '',
        ]);

        // ۲) به‌روزرسانی درصد پیشرفت پروژه
        if (isset($data['progress']) && $data['progress'] !== '') {
            Projects::setProgress($projectUid, (int)$data['progress']);
        }

        // ۳) مشخص کردن گیرندگان
        $visibility = $data['visibility'] ?? 'both';
        $recipients = [];

        if ($visibility !== 'client') {
            $recipients = array_merge($recipients, ProjectMembers::uids($projectUid));
            if ($project['manager_uid']) $recipients[] = $project['manager_uid'];
        }
        if ($visibility !== 'internal' && $project['client_uid']) {
            foreach (Users::list(['client_uid' => $project['client_uid'], 'active' => true]) as $cu) {
                $recipients[] = $cu['uid'];
            }
        }
        $recipients = array_values(array_unique(array_filter($recipients, fn($u) => $u !== $authorUid)));

        // ۴) اعلان داخل برنامه‌ای
        $level = $data['level'] ?? 'info';
        $icons = ['info' => 'ℹ️', 'success' => '✅', 'warning' => '⚠️', 'danger' => '🚨'];
        $notified = Notifications::broadcast(
            $recipients,
            ($icons[$level] ?? '📢') . ' ' . ($data['title'] ?? 'گزارش پیشرفت پروژه'),
            ($project['title'] ?? '') . (isset($data['progress']) && $data['progress'] !== '' ? ' — ' . (int)$data['progress'] . '٪' : ''),
            [
                'type' => 'project_update', 'level' => $level, 'icon' => $icons[$level] ?? '📢',
                'link' => '../admin/workspace.php?project=' . $projectUid, 'source_uid' => $updateUid,
            ]
        );

        // ۵) پیام سیستمی در گروه مرتبط با پروژه
        $groupUid = self::projectGroup($projectUid);
        if ($groupUid && $visibility !== 'client') {
            Messenger::send([
                'chat_type' => 'group', 'group_uid' => $groupUid, 'sender_uid' => $authorUid,
                'type' => 'system', 'is_system' => true,
                'content' => ($icons[$level] ?? '📢') . ' ' . ($data['title'] ?? '')
                    . (isset($data['progress']) && $data['progress'] !== '' ? ' — پیشرفت: ' . (int)$data['progress'] . '٪' : ''),
            ]);
        }

        // ۶) اتوماسیون
        Automation::fire('project.update.created', [
            'event' => 'project.update.created',
            'project_uid' => $projectUid,
            'project' => $project['title'],
            'title' => $data['title'] ?? '',
            'level' => $level,
            'progress' => (string)($data['progress'] ?? ''),
            'author' => Users::name($authorUid),
        ]);

        ActivityLog::add('project_update', 'گزارش «' . ($data['title'] ?? '') . '» برای پروژه ' . $project['title'] . ' ثبت شد');

        return ['ok' => true, 'update_uid' => $updateUid, 'notified' => $notified,
                'message' => '✅ گزارش ثبت و به ' . fa_number($notified) . ' نفر اطلاع داده شد'];
    }

    /** آیکون یک سطح هشدار */
    public static function levelIcon(string $level): string
    {
        return match ($level) {
            'success' => '✅', 'warning' => '⚠️', 'danger' => '🚨', default => 'ℹ️',
        };
    }

    /**
     * ثبت گزارش پیشرفت (امضای کوتاه‌تر برای صفحات).
     *
     * @param array{title?: string, body?: string, level?: string, visibility?: string,
     *              progress?: int|null, notify?: bool} $data
     * @return array{ok: bool, update_uid?: string, notified?: int, message: string}
     */
    public static function create(string $projectUid, string $authorUid, array $data): array
    {
        if (array_key_exists('notify', $data) && !$data['notify']) {
            // بدون اعلان: فقط ثبت گزارش و به‌روزرسانی درصد
            $project = Projects::find($projectUid);
            if (!$project) return ['ok' => false, 'message' => 'پروژه پیدا نشد'];

            $updateUid = ProjectUpdates::create([
                'project_uid' => $projectUid,
                'author_uid'  => $authorUid,
                'title'       => (string)($data['title'] ?? ''),
                'body'        => (string)($data['body'] ?? ''),
                'progress'    => $data['progress'] ?? null,
                'level'       => (string)($data['level'] ?? 'info'),
                'visibility'  => (string)($data['visibility'] ?? 'both'),
                'attachment'  => (string)($data['attachment'] ?? ''),
            ]);
            if (isset($data['progress']) && $data['progress'] !== '' && $data['progress'] !== null) {
                Projects::setProgress($projectUid, (int)$data['progress']);
            }
            ActivityLog::add('project_update', 'گزارش «' . ($data['title'] ?? '') . '» برای پروژه ' . $project['title'] . ' ثبت شد (بدون اعلان)');
            return ['ok' => true, 'update_uid' => $updateUid, 'notified' => 0, 'message' => '✅ گزارش ثبت شد'];
        }

        unset($data['notify']);
        return self::send($projectUid, $data, $authorUid);
    }

    /** گروه پیام‌رسان مرتبط با یک پروژه */
    public static function projectGroup(string $projectUid): ?string
    {
        $row = Db::i()->one('SELECT uid FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_groups')) . ' WHERE project_uid = ? LIMIT 1', [$projectUid]);
        return $row ? (string)$row['uid'] : null;
    }

    /** ساخت گروه پیام‌رسان مخصوص پروژه با اعضای همان پروژه */
    public static function ensureProjectGroup(string $projectUid, string $creatorUid): ?string
    {
        $existing = self::projectGroup($projectUid);
        if ($existing) return $existing;

        $project = Projects::find($projectUid);
        if (!$project) return null;

        $members = ProjectMembers::uids($projectUid);
        if ($project['manager_uid']) $members[] = $project['manager_uid'];
        $members = array_values(array_unique($members));

        $groupUid = Messenger::createGroup('🏗️ ' . $project['title'], array_values(array_diff($members, [$creatorUid])), $creatorUid, [
            'project_uid' => $projectUid, 'about' => 'گروه هماهنگی پروژه ' . $project['title'],
        ]);

        Messenger::send([
            'chat_type' => 'group', 'group_uid' => $groupUid, 'sender_uid' => $creatorUid,
            'type' => 'system', 'is_system' => true,
            'content' => '👥 گروه پروژه «' . $project['title'] . '» ساخته شد — ' . count($members) . ' عضو',
        ]);

        return $groupUid;
    }
}
