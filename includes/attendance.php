<?php
/**
 * ============================================================================
 *  Odsco — سیستم حضور و غیاب
 * ----------------------------------------------------------------------------
 *  دو مسیر ثبت (طبق انتخاب شما):
 *    ۱) اسکن QR کد در محل کار  → attendance_qr + attendance_scans
 *    ۲) درخواست دستی کارمند    → attendance_requests (تایید/رد توسط مدیر)
 *  به‌علاوه ثبت مستقیم توسط مدیر در جدول attendance.
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

final class Attendance
{
    public const STATUSES = [
        'present' => ['✅', 'حاضر'],
        'late'    => ['⏰', 'تاخیر'],
        'absent'  => ['❌', 'غایب'],
        'leave'   => ['🏖️', 'مرخصی'],
        'mission' => ['🚗', 'مأموریت'],
        'holiday' => ['🎉', 'تعطیل'],
    ];

    public const SOURCES = ['qr' => 'QR کد', 'manual' => 'درخواست کارمند', 'admin' => 'ثبت مدیر', 'auto' => 'خودکار'];

    // =====================================================================
    // تنظیمات
    // =====================================================================

    public static function workStart(): string { return (string)Settings::get('att_work_start', '08:00'); }
    public static function workEnd(): string   { return (string)Settings::get('att_work_end', '16:00'); }
    public static function lateMinutes(): int  { return (int)Settings::get('att_late_minutes', 15); }

    /** @return array<int, int> روزهای تعطیل هفته (شنبه=۰ … جمعه=۶) */
    public static function weekendDays(): array
    {
        $raw = (string)Settings::get('att_weekends', '6');
        return array_values(array_filter(array_map('intval', explode(',', $raw)), fn($d) => $d >= 0 && $d <= 6));
    }

    public static function isWorkday(string $date): bool
    {
        if (self::holiday($date)) return false;
        return !in_array(jalali_weekday_index($date), self::weekendDays(), true);
    }

    // =====================================================================
    // تعطیلات
    // =====================================================================

    /** @return array<int, array{day: string, title: string}> */
    public static function holidays(?string $month = null): array
    {
        $db = Db::i();
        $sql = 'SELECT day, title FROM ' . $db->quoteIdent($db->t('work_holidays'));
        $params = [];
        if ($month) { $sql .= " WHERE " . ($db->isMysql() ? "DATE_FORMAT(day, '%Y-%m')" : "strftime('%Y-%m', day)") . ' = ?'; $params[] = $month; }
        $sql .= ' ORDER BY day ASC';
        return $db->all($sql, $params);
    }

    public static function holiday(string $date): ?string
    {
        $row = Db::i()->one('SELECT title FROM ' . Db::i()->quoteIdent(Db::i()->t('work_holidays')) . ' WHERE day = ?', [$date]);
        return $row ? (string)$row['title'] : null;
    }

    public static function addHoliday(string $date, string $title): void
    {
        Db::i()->upsert(Db::i()->t('work_holidays'), [
            'day' => $date, 'title' => $title, 'created_at' => date('Y-m-d H:i:s'),
        ], ['day']);
    }

    public static function removeHoliday(string $date): void
    {
        Db::i()->delete(Db::i()->t('work_holidays'), 'day = ?', [$date]);
    }

    // =====================================================================
    // ثبت حضور
    // =====================================================================

    /** وضعیت امروز یک کاربر */
    public static function today(string $userUid): ?array
    {
        return self::record($userUid, date('Y-m-d'));
    }

    public static function record(string $userUid, string $date): ?array
    {
        $row = Db::i()->one('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('attendance')) . ' WHERE user_uid = ? AND day = ?', [$userUid, $date]);
        return $row ? self::shape($row) : null;
    }

    /**
     * ثبت ورود. اگر رکوردی وجود ندارد ساخته می‌شود.
     *
     * @return array{ok: bool, message: string, status?: string, time?: string}
     */
    public static function checkIn(string $userUid, string $source = 'qr', string $note = ''): array
    {
        $date = date('Y-m-d');
        $now  = date('H:i:s');
        $db   = Db::i();

        $existing = self::record($userUid, $date);

        if ($existing && $existing['check_in']) {
            // ورود دوم = خروج و ورود مجدد؛ خروج را ثبت می‌کنیم
            return self::checkOut($userUid, $source, $note);
        }

        $lateLimit = strtotime(self::workStart() . ':00') + self::lateMinutes() * 60;
        $status = (strtotime($now) > $lateLimit) ? 'late' : 'present';

        $cols = [
            'check_in' => $now, 'status' => $status, 'source' => $source,
            'note' => $note, 'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $db->update($db->t('attendance'), $cols, 'uid = ?', [$existing['uid']]);
        } else {
            $db->insert($db->t('attendance'), $cols + [
                'uid' => odsco_uid('att'), 'user_uid' => $userUid, 'day' => $date,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return ['ok' => true, 'message' => $status === 'late' ? '⏰ ورود ثبت شد (با تاخیر)' : '✅ ورود ثبت شد',
                'status' => $status, 'time' => $now];
    }

    /**
     * ثبت خروج.
     *
     * @return array{ok: bool, message: string, hours?: float}
     */
    public static function checkOut(string $userUid, string $source = 'qr', string $note = ''): array
    {
        $date = date('Y-m-d');
        $now  = date('H:i:s');
        $db   = Db::i();

        $existing = self::record($userUid, $date);
        if (!$existing || !$existing['check_in']) {
            // ورود ثبت نشده — هر دو را با هم ثبت می‌کنیم
            $db->insert($db->t('attendance'), [
                'uid' => odsco_uid('att'), 'user_uid' => $userUid, 'day' => $date,
                'check_in' => $now, 'check_out' => null, 'status' => 'present',
                'source' => $source, 'note' => $note ?: 'خروج بدون ورود ثبت‌شده',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => true, 'message' => '✅ ورود ثبت شد (خروجی ثبت نشده بود)', 'hours' => 0.0];
        }

        $db->update($db->t('attendance'), ['check_out' => $now, 'updated_at' => date('Y-m-d H:i:s')], 'uid = ?', [$existing['uid']]);

        $hours = (strtotime($now) - strtotime($existing['check_in'])) / 3600;
        return ['ok' => true, 'message' => '🚪 خروج ثبت شد — ' . number_format(max(0, $hours), 2) . ' ساعت کارکرد', 'hours' => max(0, $hours)];
    }

    /** ثبت/اصلاح دستی توسط مدیر */
    public static function adminSet(string $userUid, string $date, array $data, string $adminUid): array
    {
        $db = Db::i();
        $existing = self::record($userUid, $date);

        $cols = [
            'check_in'  => ($data['check_in'] ?? '') ?: null,
            'check_out' => ($data['check_out'] ?? '') ?: null,
            'status'    => (string)($data['status'] ?? 'present'),
            'note'      => (string)($data['note'] ?? ''),
            'source'    => 'admin',
            'approved_by' => $adminUid,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $db->update($db->t('attendance'), $cols, 'uid = ?', [$existing['uid']]);
        } else {
            $db->insert($db->t('attendance'), $cols + [
                'uid' => odsco_uid('att'), 'user_uid' => $userUid, 'day' => $date,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return ['ok' => true, 'message' => '✅ ثبت شد'];
    }

    public static function deleteRecord(string $uid): void
    {
        Db::i()->delete(Db::i()->t('attendance'), 'uid = ?', [$uid]);
    }

    // =====================================================================
    // درخواست دستی کارمند
    // =====================================================================

    public static function submitRequest(string $userUid, string $date, ?string $checkIn, ?string $checkOut, string $reason): array
    {
        $db = Db::i();

        if (!self::isWorkday($date)) {
            return ['ok' => false, 'message' => 'این روز تعطیل است'];
        }
        if (strtotime($date) > time()) {
            return ['ok' => false, 'message' => 'نمی‌توان برای روز آینده درخواست ثبت کرد'];
        }

        $dup = $db->val('SELECT COUNT(*) FROM ' . $db->quoteIdent($db->t('attendance_requests'))
            . " WHERE user_uid = ? AND day = ? AND status = 'pending'", [$userUid, $date]);
        if ($dup) return ['ok' => false, 'message' => 'درخواست تاییدنشده برای این روز وجود دارد'];

        $uid = odsco_uid('req');
        $db->insert($db->t('attendance_requests'), [
            'uid' => $uid, 'user_uid' => $userUid, 'day' => $date,
            'check_in' => $checkIn ?: null, 'check_out' => $checkOut ?: null,
            'reason' => $reason, 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        // اعلان به مدیران
        foreach (Users::list(['active' => true]) as $u) {
            if (Users::level($u['role']) >= Users::level('manager')) {
                Notifications::push($u['uid'], '🕐 درخواست حضور و غیاب جدید',
                    Users::name($userUid) . ' برای ' . jalali_date_long($date) . ' درخواست ثبت دارد.',
                    ['type' => 'attendance', 'icon' => '🕐', 'link' => '../admin/attendance.php?tab=requests']);
            }
        }

        return ['ok' => true, 'message' => '✅ درخواست ارسال شد و در انتظار تایید مدیر است'];
    }

    /** @return array<int, array<string, mixed>> */
    public static function requests(string $status = 'pending', ?string $userUid = null): array
    {
        $db = Db::i();
        $where = [];
        $params = [];
        if ($status !== 'all') { $where[] = 'status = ?'; $params[] = $status; }
        if ($userUid)          { $where[] = 'user_uid = ?'; $params[] = $userUid; }

        $sql = 'SELECT * FROM ' . $db->quoteIdent($db->t('attendance_requests'));
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY created_at DESC LIMIT 300';

        return array_map(function (array $r): array {
            return $r + ['user_name' => Users::name($r['user_uid']), 'user_photo' => Users::photo($r['user_uid'])];
        }, $db->all($sql, $params));
    }

    public static function reviewRequest(string $uid, string $adminUid, bool $approve, string $note = ''): array
    {
        $db = Db::i();
        $req = $db->one('SELECT * FROM ' . $db->quoteIdent($db->t('attendance_requests')) . ' WHERE uid = ?', [$uid]);
        if (!$req) return ['ok' => false, 'message' => 'درخواست پیدا نشد'];
        if ($req['status'] !== 'pending') return ['ok' => false, 'message' => 'این درخواست قبلاً بررسی شده است'];

        $db->update($db->t('attendance_requests'), [
            'status' => $approve ? 'approved' : 'rejected',
            'reviewed_by' => $adminUid, 'reviewed_at' => date('Y-m-d H:i:s'), 'review_note' => $note,
        ], 'uid = ?', [$uid]);

        if ($approve) {
            self::adminSet($req['user_uid'], $req['day'], [
                'check_in' => $req['check_in'], 'check_out' => $req['check_out'],
                'status' => 'present', 'note' => 'تایید درخواست دستی' . ($note ? ' — ' . $note : ''),
            ], $adminUid);
        }

        Notifications::push($req['user_uid'],
            $approve ? '✅ درخواست حضور و غیاب تایید شد' : '⛔ درخواست حضور و غیاب رد شد',
            jalali_date_long($req['day']) . ($note ? ' — ' . $note : ''),
            ['type' => 'attendance', 'icon' => $approve ? '✅' : '⛔', 'link' => '../messenger/index.php']
        );

        return ['ok' => true, 'message' => $approve ? '✅ تایید و ثبت شد' : '⛔ رد شد'];
    }

    // =====================================================================
    // QR کد
    // =====================================================================

    /** ساخت یک QR معتبر */
    public static function makeQr(string $adminUid, int $minutes = 0, string $ipLock = ''): array
    {
        $minutes = $minutes > 0 ? $minutes : (int)Settings::get('att_qr_minutes', 30);
        $code = strtoupper(bin2hex(random_bytes(8)));
        $from = date('Y-m-d H:i:s');
        $until = date('Y-m-d H:i:s', time() + $minutes * 60);

        Db::i()->insert(Db::i()->t('attendance_qr'), [
            'code' => $code, 'generated_by' => $adminUid, 'valid_from' => $from,
            'valid_until' => $until, 'ip_lock' => $ipLock, 'scans' => 0,
            'is_active' => 1, 'created_at' => $from,
        ]);

        return ['code' => $code, 'valid_until' => $until, 'minutes' => $minutes];
    }

    /** @return array<int, array<string, mixed>> */
    public static function activeQrs(): array
    {
        return Db::i()->all('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('attendance_qr'))
            . ' WHERE is_active = 1 ORDER BY created_at DESC LIMIT 20');
    }

    public static function deactivateQr(string $code): void
    {
        Db::i()->update(Db::i()->t('attendance_qr'), ['is_active' => 0], 'code = ?', [$code]);
    }

    /**
     * اعتبارسنجی و ثبت اسکن.
     *
     * @return array{ok: bool, message: string, kind?: string, time?: string}
     */
    public static function scan(string $code, string $userUid): array
    {
        $db = Db::i();
        $qr = $db->one('SELECT * FROM ' . $db->quoteIdent($db->t('attendance_qr')) . ' WHERE code = ?', [strtoupper(trim($code))]);

        if (!$qr || !$qr['is_active']) return ['ok' => false, 'message' => '⛔ این QR کد معتبر نیست'];
        if (strtotime((string)$qr['valid_until']) < time()) return ['ok' => false, 'message' => '⏳ اعتبار این QR کد تمام شده است'];
        if ($qr['ip_lock'] !== '' && $qr['ip_lock'] !== client_ip()) return ['ok' => false, 'message' => '⛔ این QR فقط از شبکه شرکت قابل استفاده است'];

        $today = date('Y-m-d');
        $rec = self::record($userUid, $today);
        $kind = ($rec && $rec['check_in'] && !$rec['check_out']) ? 'out' : 'in';

        $result = $kind === 'in'
            ? self::checkIn($userUid, 'qr')
            : self::checkOut($userUid, 'qr');

        $db->insert($db->t('attendance_scans'), [
            'qr_id' => (int)$qr['id'], 'user_uid' => $userUid, 'day' => $today,
            'kind' => $kind, 'scanned_at' => date('Y-m-d H:i:s'), 'ip' => client_ip(),
        ]);
        $db->run('UPDATE ' . $db->quoteIdent($db->t('attendance_qr')) . ' SET scans = scans + 1 WHERE code = ?', [$qr['code']]);

        return $result + ['kind' => $kind];
    }

    // =====================================================================
    // گزارش‌ها
    // =====================================================================

    /** برگه حضور یک روز برای همه کارمندان */
    public static function sheet(string $date): array
    {
        $db = Db::i();
        $rows = $db->all('SELECT * FROM ' . Db::i()->quoteIdent($db->t('attendance')) . ' WHERE day = ?', [$date]);
        $map = [];
        foreach ($rows as $r) $map[$r['user_uid']] = self::shape($r);

        $out = [];
        foreach (Users::staff() as $u) {
            $rec = $map[$u['uid']] ?? null;
            $out[] = [
                'user' => $u,
                'record' => $rec,
                'check_in' => $rec['check_in'] ?? null,
                'check_out' => $rec['check_out'] ?? null,
                'status' => $rec['status'] ?? (self::isWorkday($date) ? (strtotime($date) < strtotime(date('Y-m-d')) ? 'absent' : 'unknown') : 'holiday'),
                'hours' => $rec ? self::workedHours($rec) : 0.0,
            ];
        }
        return $out;
    }

    /** گزارش ماهانه یک کاربر */
    public static function monthReport(string $userUid, string $month): array
    {
        $db = Db::i();
        $fmt = $db->isMysql() ? "DATE_FORMAT(day, '%Y-%m')" : "strftime('%Y-%m', day)";
        $rows = $db->all('SELECT * FROM ' . $db->quoteIdent($db->t('attendance')) . " WHERE user_uid = ? AND $fmt = ? ORDER BY day ASC", [$userUid, $month]);

        $records = array_map([self::class, 'shape'], $rows);
        $stats = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'mission' => 0, 'holiday' => 0, 'unknown' => 0];
        $totalHours = 0.0;

        foreach ($records as $r) {
            $stats[$r['status']] = ($stats[$r['status']] ?? 0) + 1;
            $totalHours += self::workedHours($r);
        }

        // روزهای کاری ماه که رکورد ندارند = غیبت
        [$y, $m] = array_map('intval', explode('-', $month));
        $daysInMonth = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        $indexed = array_column($records, null, 'day');
        $workdays = 0;

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = sprintf('%04d-%02d-%02d', $y, $m, $d);
            if (strtotime($date) > time()) break;
            if (!self::isWorkday($date)) continue;
            $workdays++;
            if (!isset($indexed[$date])) { $stats['absent']++; }
        }

        return [
            'records' => $records,
            'stats' => $stats,
            'total_hours' => round($totalHours, 2),
            'workdays' => $workdays,
            'attendance_rate' => $workdays > 0 ? round((($stats['present'] + $stats['late']) / $workdays) * 100) : 0,
        ];
    }

    /** خلاصه امروز برای داشبورد */
    public static function todaySummary(): array
    {
        $today = date('Y-m-d');
        $db = Db::i();
        $staff = count(Users::staff());

        $rows = $db->all('SELECT status, COUNT(*) AS c FROM ' . $db->quoteIdent($db->t('attendance')) . ' WHERE day = ? GROUP BY status', [$today]);
        $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'mission' => 0];
        foreach ($rows as $r) $counts[$r['status']] = (int)$r['c'];

        $here = $counts['present'] + $counts['late'];
        $counts['absent'] = max(0, $staff - $here - $counts['leave'] - $counts['mission']);

        return ['staff' => $staff, 'present' => $here, 'counts' => $counts,
                'pending_requests' => (int)$db->val('SELECT COUNT(*) FROM ' . $db->quoteIdent($db->t('attendance_requests')) . " WHERE status = 'pending'")];
    }

    /** غایبان امروز */
    public static function absentToday(): array
    {
        $today = date('Y-m-d');
        $marked = array_map('strval', array_column(
            Db::i()->all('SELECT user_uid FROM ' . Db::i()->quoteIdent(Db::i()->t('attendance')) . ' WHERE day = ?', [$today]),
            'user_uid'
        ));
        $markedSet = array_flip($marked);

        return array_values(array_filter(Users::staff(), fn($u) => !isset($markedSet[$u['uid']])));
    }

    /** علامت‌گذاری خودکار غیبت (برای cron) */
    public static function autoMarkAbsent(?string $date = null): int
    {
        $date ??= date('Y-m-d');
        if (!self::isWorkday($date)) return 0;
        // فقط برای روزهای گذشته اجرا می‌شود
        if ($date >= date('Y-m-d')) return 0;

        $db = Db::i();
        $n = 0;
        foreach (Users::staff() as $u) {
            if (self::record($u['uid'], $date)) continue;
            $db->insert($db->t('attendance'), [
                'uid' => odsco_uid('att'), 'user_uid' => $u['uid'], 'day' => $date,
                'status' => 'absent', 'source' => 'auto', 'note' => 'غیبت خودکار (بدون ثبت ورود)',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $n++;
        }
        return $n;
    }

    // =====================================================================
    // لایه راحت‌تر برای صفحات (اسم‌های خوانا روی همان منطق)
    // =====================================================================

    /** ساخت درخواست اصلاح حضور (فرم پیام‌رسان) */
    public static function createRequest(array $d): array
    {
        return self::submitRequest(
            (string)($d['user_uid'] ?? ''),
            (string)($d['day'] ?? date('Y-m-d')),
            isset($d['check_in']) && $d['check_in'] !== '' ? (string)$d['check_in'] : null,
            isset($d['check_out']) && $d['check_out'] !== '' ? (string)$d['check_out'] : null,
            (string)($d['reason'] ?? '')
        );
    }

    /** ثبت/ویرایش دستی یک رکورد توسط مدیر */
    public static function set(
        string $userUid,
        string $date,
        string $checkIn,
        string $checkOut,
        string $status,
        string $note,
        string $source = 'admin',
        string $adminUid = ''
    ): array {
        return self::adminSet($userUid, $date, [
            'check_in'  => $checkIn !== '' ? $checkIn : null,
            'check_out' => $checkOut !== '' ? $checkOut : null,
            'status'    => $status !== '' ? $status : 'present',
            'note'      => $note,
            'source'    => $source,
        ], $adminUid);
    }

    /** غایب علامت‌زدن کسانی که در این روز هیچ رکوردی ندارند */
    public static function markAbsent(string $date): int
    {
        if (!self::isWorkday($date)) return 0;
        $db = Db::i();
        $n = 0;
        foreach (Users::staff() as $u) {
            if (self::record($u['uid'], $date)) continue;
            $db->insert($db->t('attendance'), [
                'uid' => odsco_uid('att'), 'user_uid' => $u['uid'], 'day' => $date,
                'status' => 'absent', 'source' => 'admin', 'note' => 'غیبت توسط مدیر ثبت شد',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $n++;
        }
        return $n;
    }

    /** ساخت QR جدید و برگرداندن کد آن */
    public static function newQr(int $minutes = 0, string $label = ''): string
    {
        $qr = self::makeQr('', $minutes, '');
        if ($label !== '' && ($qr['code'] ?? '') !== '') {
            Db::i()->update(Db::i()->t('attendance_qr'), ['ip_lock' => ''], 'code = ?', [$qr['code']]);
        }
        return (string)($qr['code'] ?? '');
    }

    /**
     * برگه حضور یک ماه کامل برای همه کارمندان (گزارش ماهانه پنل مدیریت).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function monthSheet(string $month): array
    {
        $db = Db::i();
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) return [];

        $fmt = $db->isMysql() ? "DATE_FORMAT(day, '%Y-%m')" : "strftime('%Y-%m', day)";
        $rows = $db->all('SELECT * FROM ' . $db->quoteIdent($db->t('attendance'))
            . " WHERE $fmt = ? ORDER BY day DESC, user_uid ASC", [$month]);

        return array_map([self::class, 'shape'], $rows);
    }

    /** اعلان یک رویداد حضور و غیاب به همه مدیران */
    public static function notifyManagers(string $userUid, string $title, string $body): int
    {
        $targets = array_values(array_map(
            static fn(array $u): string => (string)$u['uid'],
            array_filter(Users::list(['active' => true]), static fn(array $u): bool => Users::isManager((string)$u['role']))
        ));
        $targets = array_values(array_filter($targets, static fn(string $u): bool => $u !== $userUid));
        if (!$targets) return 0;

        return Notifications::broadcast($targets, '🕐 ' . $title, $body, [
            'type' => 'attendance', 'icon' => '🕐', 'level' => 'info',
            'link' => '../admin/attendance.php',
        ]);
    }

    // =====================================================================
    // کمکی
    // =====================================================================

    public static function workedHours(array $record): float
    {
        if (empty($record['check_in'])) return 0.0;
        $out = !empty($record['check_out']) ? strtotime((string)$record['check_out']) : time();
        return max(0, round(($out - strtotime((string)$record['check_in'])) / 3600, 2));
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status][1] ?? 'نامشخص';
    }

    public static function statusIcon(string $status): string
    {
        return self::STATUSES[$status][0] ?? '❔';
    }

    public static function shape(array $r): array
    {
        return [
            'uid' => $r['uid'], 'user_uid' => $r['user_uid'],
            'user_name' => Users::name($r['user_uid']), 'user_photo' => Users::photo($r['user_uid']),
            'day' => $r['day'], 'check_in' => $r['check_in'], 'check_out' => $r['check_out'],
            'status' => $r['status'], 'source' => $r['source'], 'note' => $r['note'] ?? '',
            'approved_by' => $r['approved_by'] ?? '', 'created_at' => $r['created_at'],
        ];
    }
}
