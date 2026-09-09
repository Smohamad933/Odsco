<?php
/**
 * ============================================================================
 *  Odsco — حضور و غیاب
 * ----------------------------------------------------------------------------
 *  امروز / ثبت دستی / درخواست‌ها / QR / گزارش ماهانه
 * ============================================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/logger.php';
require_once dirname(__DIR__) . '/includes/attendance.php';

check_login();

$user = current_admin();
$tab  = (string)($_GET['tab'] ?? 'today');
$msg  = '';
$err  = '';

$today = date('Y-m-d');
$month = (string)($_GET['month'] ?? date('Y-m'));

// ---------------------------------------------------------------------------
// عملیات‌ها
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $act = (string)($_POST['act'] ?? '');
    try {
        if ($act === 'set') {
            check_permission('admin');
            Attendance::set(
                (string)($_POST['user_uid'] ?? ''),
                (string)($_POST['day'] ?? $today),
                (string)($_POST['check_in'] ?? ''),
                (string)($_POST['check_out'] ?? ''),
                (string)($_POST['status'] ?? 'present'),
                (string)($_POST['note'] ?? ''),
                'admin',
                $user['uid']
            );
            $msg = '✅ رکورد حضور ثبت شد';

        } elseif ($act === 'absent') {
            check_permission('admin');
            $n = Attendance::markAbsent((string)($_POST['day'] ?? $today));
            $msg = '✅ ' . fa_number($n) . ' کاربر غایب علامت خورد';

        } elseif ($act === 'request') {
            check_permission('admin');
            Attendance::reviewRequest(
                (string)($_POST['request_uid'] ?? ''),
                (string)($_POST['decision'] ?? 'approved'),
                (string)($_POST['review_note'] ?? ''),
                $user['uid']
            );
            $msg = '✅ درخواست بررسی شد';

        } elseif ($act === 'qr_new') {
            check_permission('admin');
            $code = Attendance::newQr((int)($_POST['minutes'] ?? 30));
            $msg = '✅ کد جدید ساخته شد: ' . $code;
            $tab = 'qr';

        } elseif ($act === 'qr_scan') {
            $r = Attendance::scan(trim((string)($_POST['code'] ?? '')), (string)($_POST['user_uid'] ?? $user['uid']));
            $msg = '✅ با QR ثبت شد (' . date('H:i') . ')';
            $tab = 'qr';

        } else {
            throw new RuntimeException('عملیات نامعتبر');
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// داده‌ها
// ---------------------------------------------------------------------------
$employees = array_values(array_filter(Users::list(), fn($u) => !empty($u['attendance_enabled'])));
$summary   = Attendance::todaySummary();
$pending   = Attendance::requests('pending');
$allReqs   = Attendance::requests('all');
$sheet     = Attendance::monthSheet($month);
$qrCodes   = Db::ready()
    ? Db::i()->all('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('attendance_qr'))
        . ' ORDER BY created_at DESC, id DESC LIMIT 20')
    : [];

$stats = [
    ['👥', 'کارمندان', count($employees), 'linear-gradient(135deg,#667eea,#764ba2)'],
    ['🟢', 'حاضر امروز', $summary['present'], 'linear-gradient(135deg,#11998e,#38ef7d)'],
    ['⏰', 'تاخیر', $summary['counts']['late'] ?? 0, 'linear-gradient(135deg,#f7971e,#ffd200)'],
    ['❌', 'غایب', $summary['counts']['absent'] ?? 0, 'linear-gradient(135deg,#eb3349,#f45c43)'],
    ['🏖️', 'مرخصی', $summary['counts']['leave'] ?? 0, 'linear-gradient(135deg,#396afc,#2948ff)'],
    ['📝', 'درخواست باز', count($pending), 'linear-gradient(135deg,#fc4a1a,#f7b733)'],
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>حضور و غیاب | پنل مدیریت</title>
<link rel="stylesheet" href="admin-style.css">
</head>
<body>
<div class="admin-layout">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="content-card">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1>🕐 حضور و غیاب</h1>
                    <p><?php echo e(jalali_date_long($today)); ?> — ساعت کاری <?php echo e(Attendance::workStart()); ?> تا <?php echo e(Attendance::workEnd()); ?></p>
                </div>
                <button class="btn-secondary" onclick="window.print()">🖨️ چاپ گزارش</button>
            </div>

            <?php if ($msg): ?><div class="alert alert-success"><?php echo e($msg); ?></div><?php endif; ?>
            <?php if ($err): ?><div class="alert alert-error"><?php echo e($err); ?></div><?php endif; ?>

            <div class="stats-grid" style="margin-bottom:22px">
                <?php foreach ($stats as [$icon, $label, $val, $grad]): ?>
                <div class="stat-card" style="background:<?php echo e($grad); ?>">
                    <div class="stat-icon"><?php echo e($icon); ?></div>
                    <div class="stat-info">
                        <h3><?php echo fa_number((int)$val); ?></h3>
                        <p><?php echo e($label); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="tabs-bar">
                <a href="?tab=today" class="<?php echo $tab === 'today' ? 'active' : ''; ?>">امروز</a>
                <a href="?tab=requests" class="<?php echo $tab === 'requests' ? 'active' : ''; ?>">درخواست‌ها <?php if ($pending): ?><span class="pill danger"><?php echo fa_number(count($pending)); ?></span><?php endif; ?></a>
                <a href="?tab=qr" class="<?php echo $tab === 'qr' ? 'active' : ''; ?>">QR کد</a>
                <a href="?tab=month" class="<?php echo $tab === 'month' ? 'active' : ''; ?>">گزارش ماهانه</a>
                <a href="?tab=settings" class="<?php echo $tab === 'settings' ? 'active' : ''; ?>">تنظیمات</a>
            </div>

            <?php if ($tab === 'today'): ?>
            <!-- ================ امروز ================ -->
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
                <form method="post" style="display:inline">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="act" value="absent">
                    <input type="hidden" name="day" value="<?php echo e($today); ?>">
                    <button type="submit" class="btn-danger" onclick="return confirm('کارکنانی که امروز ثبتی ندارند غایب علامت بخورند؟')">
                        ⚡ ثبت خودکار غایبان امروز
                    </button>
                </form>
            </div>

            <div class="att-grid">
                <?php foreach ($employees as $emp):
                    $rec  = Attendance::record((string)$emp['uid'], $today);
                    $st   = (string)($rec['status'] ?? '');
                    $pill = ['present' => 'ok', 'late' => 'warn', 'absent' => 'danger', 'leave' => 'info', 'mission' => 'mute'][$st] ?? 'mute';
                ?>
                <div class="att-card">
                    <div class="av"><?php echo e(mb_substr($emp['full_name'], 0, 1)); ?></div>
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:700;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo e($emp['full_name']); ?></div>
                        <div style="font-size:11.5px;color:#7c8aa0;margin-top:2px">
                            <?php if ($rec && ($rec['check_in'] || $rec['check_out'])): ?>
                                <?php echo e($rec['check_in'] ? substr((string)$rec['check_in'], 0, 5) : '—'); ?>
                                → <?php echo e($rec['check_out'] ? substr((string)$rec['check_out'], 0, 5) : '—'); ?>
                                · <?php echo fa_number(number_format(Attendance::workedHours($rec), 1)); ?> ساعت
                            <?php else: ?>
                                ثبتی ندارد
                            <?php endif; ?>
                        </div>
                        <div style="margin-top:6px"><span class="pill <?php echo e($pill); ?>"><?php echo $st !== '' ? e(Attendance::statusIcon($st) . ' ' . Attendance::statusLabel($st)) : 'ثبتی ندارد'; ?></span></div>
                    </div>
                    <a href="?tab=settings&edit=<?php echo e($emp['uid']); ?>#manual" class="btn-sm" title="ویرایش">✏️</a>
                </div>
                <?php endforeach; ?>
                <?php if (!$employees): ?><p style="color:#7c8aa0">هیچ کارمندی برای حضور و غیاب فعال نشده است. از بخش <a href="manage-users.php">کاربران</a> گزینه «حضور و غیاب» را فعال کنید.</p><?php endif; ?>
            </div>

            <?php elseif ($tab === 'requests'): ?>
            <!-- ================ درخواست‌ها ================ -->
            <h3 style="margin-bottom:14px">📝 درخواست‌های اصلاح حضور</h3>
            <?php if (!$allReqs): ?>
                <p style="color:#7c8aa0">درخواستی ثبت نشده است.</p>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>کارمند</th><th>تاریخ</th><th>ورود</th><th>خروج</th><th>دلیل</th><th>وضعیت</th><th>عملیات</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allReqs as $r):
                        $pill = ['pending' => 'warn', 'approved' => 'ok', 'rejected' => 'danger'][$r['status']] ?? 'mute';
                    ?>
                        <tr>
                            <td><?php echo e($r['user_name']); ?></td>
                            <td><?php echo e(jalali_date((string)$r['day'])); ?></td>
                            <td><?php echo e(substr((string)$r['check_in'], 0, 5)); ?></td>
                            <td><?php echo e(substr((string)$r['check_out'], 0, 5)); ?></td>
                            <td style="max-width:220px"><?php echo e($r['reason']); ?></td>
                            <td><span class="pill <?php echo e($pill); ?>"><?php echo e(['pending' => 'در انتظار', 'approved' => 'تایید', 'rejected' => 'رد'][$r['status']] ?? $r['status']); ?></span></td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="act" value="request">
                                    <input type="hidden" name="request_uid" value="<?php echo e($r['uid']); ?>">
                                    <input type="text" name="review_note" placeholder="یادداشت (اختیاری)" style="width:130px;padding:6px 8px;font-size:12px;border:1px solid #d8dfeb;border-radius:8px">
                                    <button type="submit" name="decision" value="approved" class="btn-sm">✅</button>
                                    <button type="submit" name="decision" value="rejected" class="btn-sm" style="background:#eb3349">⛔</button>
                                </form>
                                <?php else: ?>
                                    <span style="font-size:11.5px;color:#7c8aa0"><?php echo e($r['review_note'] ?: '—'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php elseif ($tab === 'qr'): ?>
            <!-- ================ QR ================ -->
            <h3 style="margin-bottom:14px">📷 کدهای QR</h3>
            <p style="font-size:13px;color:#5a6b80">کد را در محل کار نصب کنید؛ کارمندان با اسکن یا وارد کردن کد، ورود/خروج خود را ثبت می‌کنند.</p>

            <div style="display:flex;gap:18px;flex-wrap:wrap;margin:18px 0">
                <div style="flex:1;min-width:260px;background:#f7f9fc;border:1px solid #e6ebf2;border-radius:14px;padding:16px">
                    <h4 style="margin-bottom:10px">ساخت کد جدید</h4>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="act" value="qr_new">
                        <div class="form-group">
                            <label>اعتبار (دقیقه)</label>
                            <input type="number" name="minutes" value="<?php echo fa_number((int)Settings::get('att_qr_minutes', 30)); ?>" min="1" max="1440">
                        </div>
                        <button type="submit" class="btn">ساخت کد</button>
                    </form>
                </div>

                <div style="flex:1;min-width:260px;background:#f7f9fc;border:1px solid #e6ebf2;border-radius:14px;padding:16px">
                    <h4 style="margin-bottom:10px">تست اسکن</h4>
                    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="act" value="qr_scan">
                        <input type="text" name="code" placeholder="کد QR" required style="flex:1;min-width:150px;letter-spacing:2px">
                        <button type="submit" class="btn">ثبت</button>
                    </form>
                </div>
            </div>

            <?php if ($qrCodes): ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>کد</th><th>ساخته‌شده</th><th>انقضا</th><th>اسکن‌ها</th><th>وضعیت</th></tr></thead>
                    <tbody>
                    <?php foreach ($qrCodes as $q):
                        $live = !empty($q['is_active']) && strtotime((string)$q['valid_until']) > time();
                    ?>
                        <tr>
                            <td><code style="font-size:15px;letter-spacing:2px;font-weight:800"><?php echo e((string)$q['code']); ?></code></td>
                            <td><?php echo e(jalali_datetime((string)$q['created_at'])); ?></td>
                            <td><?php echo e(jalali_datetime((string)$q['valid_until'])); ?></td>
                            <td><?php echo fa_number((int)$q['scans']); ?></td>
                            <td><span class="pill <?php echo $live ? 'ok' : 'mute'; ?>"><?php echo $live ? 'فعال' : 'منقضی'; ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php elseif ($tab === 'month'): ?>
            <!-- ================ گزارش ماهانه ================ -->
            <form method="get" class="att-filters" style="display:flex;gap:10px;align-items:end;margin-bottom:16px;flex-wrap:wrap">
                <input type="hidden" name="tab" value="month">
                <div class="form-group" style="margin:0">
                    <label>ماه</label>
                    <input type="month" name="month" value="<?php echo e($month); ?>">
                </div>
                <button type="submit" class="btn">نمایش گزارش</button>
            </form>

            <?php if (!$sheet): ?>
                <p style="color:#7c8aa0">رکوردی برای این ماه وجود ندارد.</p>
            <?php else:
                $tot = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'mission' => 0];
                $totHours = 0.0;
                foreach ($sheet as $row) {
                    $tot[$row['status']] = ($tot[$row['status']] ?? 0) + 1;
                    $totHours += Attendance::workedHours($row);
                }
            ?>
            <div class="table-wrap att-sheet">
                <table class="table">
                    <thead>
                        <tr>
                            <th>کارمند</th><th>تاریخ</th><th>ورود</th><th>خروج</th>
                            <th>کارکرد</th><th>وضعیت</th><th>منبع</th><th>یادداشت</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sheet as $row):
                        $pill = ['present' => 'ok', 'late' => 'warn', 'absent' => 'danger', 'leave' => 'info', 'mission' => 'mute'][$row['status']] ?? 'mute';
                    ?>
                        <tr>
                            <td><?php echo e($row['user_name']); ?></td>
                            <td><?php echo e(jalali_date((string)$row['day'])); ?></td>
                            <td><?php echo e($row['check_in'] ? substr((string)$row['check_in'], 0, 5) : '—'); ?></td>
                            <td><?php echo e($row['check_out'] ? substr((string)$row['check_out'], 0, 5) : '—'); ?></td>
                            <td><?php echo fa_number(number_format(Attendance::workedHours($row), 1)); ?></td>
                            <td><span class="pill <?php echo e($pill); ?>"><?php echo e(Attendance::statusLabel($row['status'])); ?></span></td>
                            <td><span class="pill mute"><?php echo e(['qr' => 'QR', 'manual' => 'دستی', 'admin' => 'مدیر', 'auto' => 'خودکار'][$row['source']] ?? $row['source']); ?></span></td>
                            <td style="max-width:200px;font-size:12px"><?php echo e($row['note']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:800;background:#f7f9fc">
                            <td colspan="4">جمع کل</td>
                            <td><?php echo fa_number(number_format($totHours, 1)); ?></td>
                            <td colspan="3" style="font-size:12px">
                                ✅ <?php echo fa_number($tot['present']); ?> ·
                                ⏰ <?php echo fa_number($tot['late']); ?> ·
                                ❌ <?php echo fa_number($tot['absent']); ?> ·
                                🏖️ <?php echo fa_number($tot['leave']); ?> ·
                                🚗 <?php echo fa_number($tot['mission']); ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- ================ تنظیمات / ثبت دستی ================ -->
            <a id="manual"></a>
            <h3 style="margin-bottom:14px">✏️ ثبت / ویرایش دستی حضور</h3>
            <?php
            $editUid = (string)($_GET['edit'] ?? '');
            $editRec = null;
            if ($editUid !== '') {
                $day = (string)($_GET['day'] ?? $today);
                $editRec = Attendance::record($editUid, $day);
            }
            ?>
            <form method="post" class="form-grid">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="act" value="set">
                <div class="form-group">
                    <label>کارمند *</label>
                    <select name="user_uid" required>
                        <?php foreach (Users::list() as $u): ?>
                            <option value="<?php echo e($u['uid']); ?>" <?php echo $u['uid'] === $editUid ? 'selected' : ''; ?>>
                                <?php echo e($u['full_name']); ?> (<?php echo e(Users::roleLabel((string)$u['role'])); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>تاریخ (میلادی) *</label>
                    <input type="date" name="day" value="<?php echo e($editRec ? (string)$editRec['day'] : $today); ?>" required>
                </div>
                <div class="form-group">
                    <label>ساعت ورود</label>
                    <input type="time" name="check_in" value="<?php echo e($editRec ? substr((string)$editRec['check_in'], 0, 5) : Attendance::workStart()); ?>">
                </div>
                <div class="form-group">
                    <label>ساعت خروج</label>
                    <input type="time" name="check_out" value="<?php echo e($editRec ? substr((string)$editRec['check_out'], 0, 5) : Attendance::workEnd()); ?>">
                </div>
                <div class="form-group">
                    <label>وضعیت</label>
                    <select name="status">
                        <option value="present" <?php echo $editRec && $editRec['status'] === 'present' ? 'selected' : ''; ?>>حاضر</option>
                        <option value="late" <?php echo $editRec && $editRec['status'] === 'late' ? 'selected' : ''; ?>>تاخیر</option>
                        <option value="absent" <?php echo $editRec && $editRec['status'] === 'absent' ? 'selected' : ''; ?>>غایب</option>
                        <option value="leave" <?php echo $editRec && $editRec['status'] === 'leave' ? 'selected' : ''; ?>>مرخصی</option>
                        <option value="mission" <?php echo $editRec && $editRec['status'] === 'mission' ? 'selected' : ''; ?>>مأموریت</option>
                        <option value="holiday" <?php echo $editRec && $editRec['status'] === 'holiday' ? 'selected' : ''; ?>>تعطیل</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>یادداشت</label>
                    <input type="text" name="note" value="<?php echo e($editRec ? (string)$editRec['note'] : ''); ?>">
                </div>
                <div class="form-group full-width">
                    <button type="submit" class="btn">💾 ذخیره رکورد</button>
                </div>
            </form>

            <h3 style="margin:28px 0 14px">⚙️ تنظیمات حضور و غیاب</h3>
            <form method="post" action="settings.php" class="form-grid">
                <?php echo csrf_field(); ?>
                <div class="form-group"><label>شروع ساعت کاری</label>
                    <input type="time" name="att_work_start" value="<?php echo e(Attendance::workStart()); ?>"></div>
                <div class="form-group"><label>پایان ساعت کاری</label>
                    <input type="time" name="att_work_end" value="<?php echo e(Attendance::workEnd()); ?>"></div>
                <div class="form-group"><label>آستانه تاخیر (دقیقه)</label>
                    <input type="number" name="att_late_minutes" value="<?php echo fa_number(Attendance::lateMinutes()); ?>" min="0"></div>
                <div class="form-group"><label>اعتبار QR (دقیقه)</label>
                    <input type="number" name="att_qr_minutes" value="<?php echo fa_number((int)Settings::get('att_qr_minutes', 30)); ?>" min="1"></div>
                <div class="form-group full-width">
                    <button type="submit" class="btn">💾 ذخیره تنظیمات</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
