<?php
/**
 * ============================================================================
 *  Odsco — صفحه حضور و غیاب کارکنان
 * ----------------------------------------------------------------------------
 *  • ثبت ورود/خروج (دستی یا با کد QR)
 *  • درخواست اصلاح دستی با تایید مدیر
 *  • گزارش ماهانه
 *  • مدیران: وضعیت امروز تیم + ساخت QR
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

at_check_login();

$me    = at_me();
$myUid = $me['uid'];
$today = date('Y-m-d');
$tab   = (string)($_GET['tab'] ?? 'today');
$msg   = '';
$err   = '';

// ---------------------------------------------------------------------------
// پردازش فرم‌ها
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'check_in') {
        $r = Attendance::checkIn($myUid, 'manual', (string)($_POST['note'] ?? ''));
        $r['ok'] ? $msg = $r['message'] : $err = $r['message'];

    } elseif ($action === 'check_out') {
        $r = Attendance::checkOut($myUid, 'manual', (string)($_POST['note'] ?? ''));
        $r['ok'] ? $msg = $r['message'] : $err = $r['message'];

    } elseif ($action === 'scan') {
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        if ($code === '') {
            $err = 'کد QR را وارد کنید.';
        } else {
            $r = Attendance::scan($code, $myUid);
            $r['ok'] ? $msg = $r['message'] : $err = $r['message'];
        }

    } elseif ($action === 'request') {
        $r = Attendance::createRequest([
            'user_uid'   => $myUid,
            'day'        => (string)($_POST['day'] ?? $today),
            'check_in'   => (string)($_POST['check_in'] ?? ''),
            'check_out'  => (string)($_POST['check_out'] ?? ''),
            'reason'     => (string)($_POST['reason'] ?? ''),
        ]);
        $r['ok'] ? $msg = '✅ درخواست شما برای تایید مدیر ارسال شد.' : $err = $r['message'];

    } elseif ($action === 'make_qr' && at_is_manager()) {
        $qr  = Attendance::makeQr($myUid, (int)($_POST['minutes'] ?? 0));
        $msg = '✅ کد QR ساخته شد: ' . $qr['code'];
    }
}

// کد QR از راه لینک (اسکن با گوشی) — به‌صورت خودکار ثبت می‌شود
$autoCode = strtoupper(trim((string)($_GET['code'] ?? '')));
if ($autoCode !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $r = Attendance::scan($autoCode, $myUid);
    $r['ok'] ? $msg = $r['message'] : $err = $r['message'];
}

// ---------------------------------------------------------------------------
// داده‌ها
// ---------------------------------------------------------------------------
$record    = Attendance::record($myUid, $today);
$hours     = $record ? Attendance::workedHours($record) : 0.0;
$isWorkday = Attendance::isWorkday($today);
$holiday   = Attendance::holiday($today);
$myPending = Attendance::requests('pending', $myUid);

$month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$report = Attendance::monthReport($myUid, $month);

$summary = at_is_manager() ? Attendance::todaySummary() : null;
$activeQrs = at_is_manager() ? Attendance::activeQrs() : [];
$qrLinkBase = rtrim((string)Settings::get('site_url', ''), '/');
?>
<?php at_head('حضور و غیاب', $tab === 'requests' || $tab === 'month' || $tab === 'team' ? $tab : 'today'); ?>

<?php if ($msg !== ''): ?><div class="at-alert ok"><?php echo e($msg); ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="at-alert err"><?php echo e($err); ?></div><?php endif; ?>

<?php if ($tab === 'requests'): ?>
    <!-- ================================================== درخواست‌های من -->
    <section class="at-card">
        <h2>📝 درخواست‌های ثبت دستی من</h2>
        <p class="at-hint">اگر فراموش کردید ورود یا خروج را ثبت کنید، اینجا درخواست بدهید تا مدیر تایید کند.</p>

        <form method="post" class="at-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="request">
            <div class="at-grid">
                <div>
                    <label>تاریخ</label>
                    <input type="date" name="day" value="<?php echo e($today); ?>" required>
                </div>
                <div>
                    <label>ساعت ورود</label>
                    <input type="time" name="check_in" step="60">
                </div>
                <div>
                    <label>ساعت خروج</label>
                    <input type="time" name="check_out" step="60">
                </div>
            </div>
            <label>دلیل</label>
            <textarea name="reason" rows="2" placeholder="مثلاً فراموشی اسکن QR" required></textarea>
            <button type="submit" class="at-btn">ارسال درخواست</button>
        </form>

        <h3>درخواست‌های در انتظار تایید</h3>
        <?php if (!$myPending): ?>
            <p class="at-empty">درخواست تاییدنشده‌ای ندارید.</p>
        <?php else: ?>
            <div class="at-list">
                <?php foreach ($myPending as $rq): ?>
                    <div class="at-row">
                        <div>
                            <b><?php echo e(jalali_date_long((string)$rq['day'])); ?></b>
                            <span class="at-mut"><?php echo at_time((string)($rq['check_in'] ?? '')); ?> → <?php echo at_time((string)($rq['check_out'] ?? '')); ?></span>
                        </div>
                        <span class="at-chip warn">در انتظار</span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'month'): ?>
    <!-- ================================================== گزارش ماه -->
    <section class="at-card">
        <h2>📅 گزارش <?php echo e(jalali_month_label($month)); ?></h2>

        <form method="get" class="at-inline">
            <input type="hidden" name="tab" value="month">
            <input type="month" name="month" value="<?php echo e($month); ?>">
            <button type="submit" class="at-btn small">نمایش</button>
        </form>

        <div class="at-stats">
            <div class="at-stat"><b><?php echo fa_number($report['stats']['present'] ?? 0); ?></b><span>حضور</span></div>
            <div class="at-stat"><b><?php echo fa_number($report['stats']['late'] ?? 0); ?></b><span>تاخیر</span></div>
            <div class="at-stat"><b><?php echo fa_number($report['stats']['absent'] ?? 0); ?></b><span>غیبت</span></div>
            <div class="at-stat"><b><?php echo fa_number($report['stats']['leave'] ?? 0); ?></b><span>مرخصی</span></div>
            <div class="at-stat"><b><?php echo fa_number($report['total_hours']); ?></b><span>ساعت کارکرد</span></div>
            <div class="at-stat"><b><?php echo fa_number($report['attendance_rate']); ?>٪</b><span>نرخ حضور</span></div>
        </div>

        <?php if (!$report['records']): ?>
            <p class="at-empty">رکوردی برای این ماه وجود ندارد.</p>
        <?php else: ?>
            <table class="at-table">
                <thead><tr><th>تاریخ</th><th>ورود</th><th>خروج</th><th>ساعت</th><th>وضعیت</th></tr></thead>
                <tbody>
                <?php foreach ($report['records'] as $r): ?>
                    <tr>
                        <td data-l="تاریخ"><?php echo e(jalali_date_long((string)$r['day'])); ?></td>
                        <td data-l="ورود"><?php echo at_time((string)$r['check_in']); ?></td>
                        <td data-l="خروج"><?php echo at_time((string)$r['check_out']); ?></td>
                        <td data-l="ساعت"><?php echo fa_number(round(Attendance::workedHours($r), 1)); ?></td>
                        <td data-l="وضعیت"><span class="at-chip <?php echo in_array($r['status'], ['present', 'late'], true) ? 'on' : 'off'; ?>">
                            <?php echo e(Attendance::statusIcon($r['status']) . ' ' . Attendance::statusLabel($r['status'])); ?>
                        </span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

<?php elseif ($tab === 'team' && at_is_manager()): ?>
    <!-- ================================================== وضعیت تیم -->
    <section class="at-card">
        <h2>👥 وضعیت امروز تیم</h2>
        <p class="at-hint"><?php echo e(jalali_date_long($today)); ?> — ساعت <?php echo fa_number(date('H:i')); ?></p>

        <div class="at-stats">
            <div class="at-stat"><b><?php echo fa_number($summary['present'] ?? 0); ?></b><span>حاضر</span></div>
            <div class="at-stat"><b><?php echo fa_number($summary['late'] ?? 0); ?></b><span>با تاخیر</span></div>
            <div class="at-stat"><b><?php echo fa_number($summary['absent'] ?? 0); ?></b><span>غایب</span></div>
            <div class="at-stat"><b><?php echo fa_number($summary['total'] ?? 0); ?></b><span>کل</span></div>
        </div>

        <form method="post" class="at-inline">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="make_qr">
            <label>اعتبار QR (دقیقه)</label>
            <input type="number" name="minutes" value="30" min="1" max="480" style="width:110px">
            <button type="submit" class="at-btn small">🔳 ساخت کد QR</button>
        </form>

        <?php if ($activeQrs): ?>
            <h3>کدهای فعال</h3>
            <div class="at-list">
                <?php foreach ($activeQrs as $q): ?>
                    <div class="at-row">
                        <div>
                            <b class="at-code"><?php echo e((string)$q['code']); ?></b>
                            <span class="at-mut">تا <?php echo e(fa_number(date('H:i', strtotime((string)$q['valid_until'])))); ?>
                                — <?php echo fa_number((int)$q['scans']); ?> اسکن</span>
                        </div>
                        <a class="at-chip on" href="index.php?code=<?php echo e(urlencode((string)$q['code'])); ?>">لینک اسکن</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h3>لیست امروز</h3>
        <?php $rows = Attendance::sheet($today); ?>
        <?php if (!$rows): ?>
            <p class="at-empty">رکوردی ثبت نشده است.</p>
        <?php else: ?>
            <table class="at-table">
                <thead><tr><th>کاربر</th><th>ورود</th><th>خروج</th><th>ساعت</th><th>وضعیت</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td data-l="کاربر"><?php echo e((string)($r['user_name'] ?? '')); ?></td>
                        <td data-l="ورود"><?php echo at_time((string)($r['check_in'] ?? '')); ?></td>
                        <td data-l="خروج"><?php echo at_time((string)($r['check_out'] ?? '')); ?></td>
                        <td data-l="ساعت"><?php echo fa_number(round($r['hours'] ?? 0, 1)); ?></td>
                        <td data-l="وضعیت"><span class="at-chip <?php echo in_array($r['status'], ['present', 'late'], true) ? 'on' : 'off'; ?>">
                            <?php echo e(Attendance::statusIcon($r['status']) . ' ' . Attendance::statusLabel($r['status'])); ?>
                        </span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <p class="at-hint">مدیریت کامل در <a href="../admin/attendance.php">پنل مدیریت → حضور و غیاب</a>.</p>
    </section>

<?php else: ?>
    <!-- ================================================== امروز -->
    <section class="at-card at-today">
        <div class="at-today-head">
            <div>
                <h2><?php echo e(jalali_date_long($today)); ?></h2>
                <p class="at-hint">
                    ساعت کاری: <?php echo fa_number(Attendance::workStart()); ?> تا <?php echo fa_number(Attendance::workEnd()); ?>
                    <?php if (!$isWorkday): ?> — <b>امروز روز کاری نیست<?php echo $holiday ? ' (' . e($holiday) . ')' : ''; ?></b><?php endif; ?>
                </p>
            </div>
            <div class="at-clock" id="atClock"><?php echo fa_number(date('H:i:s')); ?></div>
        </div>

        <div class="at-big">
            <div class="at-big-item">
                <span>ورود</span>
                <b><?php echo at_time($record['check_in'] ?? null); ?></b>
            </div>
            <div class="at-big-item">
                <span>خروج</span>
                <b><?php echo at_time($record['check_out'] ?? null); ?></b>
            </div>
            <div class="at-big-item">
                <span>کارکرد</span>
                <b><?php echo fa_number(round($hours, 1)); ?> ساعت</b>
            </div>
            <div class="at-big-item">
                <span>وضعیت</span>
                <b><?php echo $record
                        ? e(Attendance::statusIcon($record['status']) . ' ' . Attendance::statusLabel($record['status']))
                        : '—'; ?></b>
            </div>
        </div>

        <div class="at-actions">
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="check_in">
                <button type="submit" class="at-btn in" <?php echo $isWorkday ? '' : 'disabled'; ?>>⬇️ ثبت ورود</button>
            </form>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="check_out">
                <button type="submit" class="at-btn out" <?php echo ($record && $record['check_in']) ? '' : 'disabled'; ?>>⬆️ ثبت خروج</button>
            </form>
        </div>
        <?php if (!$isWorkday): ?><p class="at-hint">در روزهای غیرکاری ثبت ورود/خروج فعال نیست؛ از بخش «درخواست‌های من» اقدام کنید.</p><?php endif; ?>
    </section>

    <section class="at-card">
        <h2>🔳 ثبت با کد QR</h2>
        <p class="at-hint">کد روی QR شرکت را وارد کنید، یا با گوشی آن را اسکن کنید تا همین صفحه با کد باز شود.</p>
        <form method="post" class="at-inline">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="scan">
            <input type="text" name="code" placeholder="مثلاً 8F3A1B2C4D5E6F70" required
                   style="text-transform:uppercase;letter-spacing:2px;min-width:230px">
            <button type="submit" class="at-btn">ثبت</button>
        </form>
    </section>

    <section class="at-card">
        <h2>📝 درخواست اصلاح دستی</h2>
        <p class="at-hint">فراموش کردید ثبت کنید؟ درخواست بدهید تا مدیر تایید کند.</p>
        <form method="post" class="at-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="request">
            <div class="at-grid">
                <div>
                    <label>تاریخ</label>
                    <input type="date" name="day" value="<?php echo e($today); ?>" required>
                </div>
                <div>
                    <label>ساعت ورود</label>
                    <input type="time" name="check_in" step="60">
                </div>
                <div>
                    <label>ساعت خروج</label>
                    <input type="time" name="check_out" step="60">
                </div>
            </div>
            <label>دلیل</label>
            <textarea name="reason" rows="2" placeholder="مثلاً فراموشی اسکن QR" required></textarea>
            <button type="submit" class="at-btn">ارسال درخواست</button>
        </form>
        <?php if ($myPending): ?>
            <p class="at-hint">📌 <?php echo fa_number(count($myPending)); ?> درخواست در انتظار تایید دارید.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<script>
(function () {
    var el = document.getElementById('atClock');
    if (!el) return;
    var fa = '۰۱۲۳۴۵۶۷۸۹';
    setInterval(function () {
        var d = new Date();
        var t = [d.getHours(), d.getMinutes(), d.getSeconds()].map(function (n) {
            return (n < 10 ? '0' : '') + n;
        }).join(':');
        el.textContent = t.replace(/[0-9]/g, function (c) { return fa[+c]; });
    }, 1000);
})();
</script>
<?php at_foot(); ?>
