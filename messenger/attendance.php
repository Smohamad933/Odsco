<?php
/**
 * ============================================================================
 *  Odsco Messenger — حضور و غیاب کارمند (ثبت با QR یا درخواست دستی)
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/includes/attendance.php';

m_check_login();

$me    = m_current_user();
$myUid = $me['uid'];
$activeChatKey = '';

Messenger::touchPresence($myUid);

$user = Users::find($myUid);
if (!$user || empty($user['attendance_enabled'])) {
    $enabled = false;
} else {
    $enabled = true;
}

$today = date('Y-m-d');
$record = $enabled ? Attendance::record($myUid, $today) : null;
$month = $_GET['month'] ?? date('Y-m');
$report = $enabled ? Attendance::monthReport($myUid, $month) : ['records' => [], 'stats' => [], 'total_hours' => 0, 'workdays' => 0, 'attendance_rate' => 0];
$myRequests = $enabled ? Attendance::requests('all', $myUid) : [];
$isWorkday = Attendance::isWorkday($today);
$holidayName = Attendance::holiday($today);

m_head('حضور و غیاب · پیام‌رسان');
?>
<body>
<div class="tg-app" id="tgRoot" data-csrf="<?php echo e(csrf_token()); ?>" data-user="<?php echo e($myUid); ?>">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="tg-main">
        <div class="tg-store-bar" id="tgStoreBar"><span class="dot"></span> در حال آماده‌سازی حافظه دستگاه…</div>

        <header class="tg-head">
            <a href="index.php" class="icon-btn" title="بازگشت">→</a>
            <div class="tg-head-info">
                <div class="tg-head-name">🕐 حضور و غیاب من</div>
                <div class="tg-head-sub"><?php echo e(jalali_date_long($today)); ?> — <?php echo e(ODSO_J_WEEKDAYS[jalali_weekday_index($today)] ?? ''); ?></div>
            </div>
        </header>

        <div class="tg-msgs" style="padding:14px;gap:14px;align-items:stretch">

            <?php if (!$enabled): ?>
                <div class="tg-placeholder" style="padding:60px 20px">
                    <div class="ico">🔒</div>
                    <h2>حضور و غیاب برای شما فعال نیست</h2>
                    <p>برای فعال‌سازی با مدیر خود تماس بگیرید.</p>
                </div>
            <?php else: ?>

            <!-- کارت امروز -->
            <section style="background:linear-gradient(135deg,#1c2733,#17212b);border:1px solid var(--tg-line);border-radius:18px;padding:20px">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
                    <div>
                        <div style="font-size:12.5px;color:var(--tg-muted)">وضعیت امروز</div>
                        <div style="font-size:22px;font-weight:800;margin-top:4px">
                            <?php if ($holidayName): ?>
                                🎉 تعطیل — <?php echo e($holidayName); ?>
                            <?php elseif (!$isWorkday): ?>
                                🎉 تعطیل
                            <?php elseif ($record): ?>
                                <?php echo e(Attendance::statusIcon($record['status']) . ' ' . Attendance::statusLabel($record['status'])); ?>
                            <?php else: ?>
                                ⏳ ثبت نشده
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:18px;text-align:center">
                        <div>
                            <div style="font-size:11px;color:var(--tg-muted)">ورود</div>
                            <div style="font-size:19px;font-weight:700"><?php echo e(!empty($record['check_in']) ? substr((string)$record['check_in'], 0, 5) : '—'); ?></div>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--tg-muted)">خروج</div>
                            <div style="font-size:19px;font-weight:700"><?php echo e(!empty($record['check_out']) ? substr((string)$record['check_out'], 0, 5) : '—'); ?></div>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--tg-muted)">کارکرد</div>
                            <div style="font-size:19px;font-weight:700;color:var(--tg-accent)">
                                <?php echo $record ? fa_number(number_format(Attendance::workedHours($record), 1)) : '۰'; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($isWorkday && !$holidayName): ?>
                <div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap">
                    <form method="post" action="attendance-action.php" style="flex:1;min-width:140px">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="act" value="checkin">
                        <button type="submit" class="tg-btn block" style="padding:13px">🟢 ثبت ورود (<?php echo e(date('H:i')); ?>)</button>
                    </form>
                    <form method="post" action="attendance-action.php" style="flex:1;min-width:140px">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="act" value="checkout">
                        <button type="submit" class="tg-btn ghost block" style="padding:13px">🔴 ثبت خروج</button>
                    </form>
                </div>
                <p style="font-size:11.5px;color:var(--tg-muted);margin-top:10px;line-height:1.9">
                    ساعت کاری: <?php echo e(Attendance::workStart()); ?> تا <?php echo e(Attendance::workEnd()); ?>
                    · ورود بعد از <?php echo fa_number(Attendance::lateMinutes()); ?> دقیقه تاخیر = <b style="color:var(--tg-warn)">تاخیر</b>
                </p>
                <?php endif; ?>
            </section>

            <!-- QR کد -->
            <section style="background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:16px;padding:18px">
                <h3 style="font-size:14.5px;margin-bottom:6px">📷 ثبت با QR کد محل کار</h3>
                <p style="font-size:12.5px;color:var(--tg-muted);line-height:2;margin-bottom:12px">
                    کد QR نصب‌شده در شرکت را اسکن کنید یا کد آن را اینجا وارد کنید.
                </p>
                <form method="post" action="attendance-action.php" style="display:flex;gap:8px;flex-wrap:wrap">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="act" value="scan">
                    <input type="text" name="code" placeholder="کد QR را وارد کنید…" required
                           style="flex:1;min-width:180px;padding:11px 14px;background:var(--tg-bg);border:1px solid var(--tg-line);border-radius:11px;color:#fff;font-size:14px;letter-spacing:2px;text-align:center">
                    <button type="submit" class="tg-btn" style="padding:11px 22px">ثبت</button>
                </form>
            </section>

            <!-- درخواست دستی -->
            <section style="background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:16px;padding:18px">
                <h3 style="font-size:14.5px;margin-bottom:6px">📝 درخواست اصلاح حضور (تایید مدیر)</h3>
                <p style="font-size:12.5px;color:var(--tg-muted);line-height:2;margin-bottom:12px">
                    اگر فراموش کردید ورود/خروج را ثبت کنید، درخواست بدهید تا مدیر تایید کند.
                </p>
                <form method="post" action="attendance-action.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="act" value="request">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px">
                        <div class="tg-field"><label>تاریخ (میلادی)</label>
                            <input type="date" name="day" value="<?php echo e(date('Y-m-d', strtotime('-1 day'))); ?>" required></div>
                        <div class="tg-field"><label>ساعت ورود</label><input type="time" name="check_in" value="08:00"></div>
                        <div class="tg-field"><label>ساعت خروج</label><input type="time" name="check_out" value="16:00"></div>
                    </div>
                    <div class="tg-field"><label>دلیل</label>
                        <textarea name="reason" rows="2" placeholder="مثلاً فراموشی ثبت / مأموریت بیرون از شرکت" required></textarea></div>
                    <button type="submit" class="tg-btn">ارسال درخواست</button>
                </form>

                <?php if ($myRequests): ?>
                <div style="margin-top:16px">
                    <h4 style="font-size:13px;margin-bottom:8px;color:var(--tg-muted)">درخواست‌های من</h4>
                    <?php foreach (array_slice($myRequests, 0, 8) as $r):
                        $badge = ['pending' => ['⏳', 'var(--tg-warn)'], 'approved' => ['✅', 'var(--tg-online)'], 'rejected' => ['⛔', 'var(--tg-danger)']][$r['status']] ?? ['❔', 'var(--tg-muted)'];
                    ?>
                    <div style="display:flex;gap:10px;align-items:center;background:var(--tg-bg);border-radius:11px;padding:10px 12px;margin-bottom:7px;font-size:12.5px;flex-wrap:wrap">
                        <span style="color:<?php echo e($badge[1]); ?>"><?php echo e($badge[0]); ?></span>
                        <span style="flex:1;min-width:140px"><?php echo e(jalali_date_long((string)$r['day'])); ?></span>
                        <span style="color:var(--tg-muted)"><?php echo e(substr((string)$r['check_in'], 0, 5)); ?> → <?php echo e(substr((string)$r['check_out'], 0, 5)); ?></span>
                        <span style="color:var(--tg-muted);font-size:11.5px"><?php echo e(mb_substr((string)$r['reason'], 0, 40)); ?></span>
                        <?php if ($r['review_note'] !== ''): ?><span style="font-size:11px;color:var(--tg-muted)">— <?php echo e($r['review_note']); ?></span><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>

            <!-- آمار ماه -->
            <section style="background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:16px;padding:18px">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px">
                    <h3 style="font-size:14.5px">📊 گزارش <?php echo e($month); ?></h3>
                    <form method="get" style="display:flex;gap:6px">
                        <input type="month" name="month" value="<?php echo e($month); ?>"
                               style="padding:8px 10px;background:var(--tg-bg);border:1px solid var(--tg-line);border-radius:9px;color:#fff">
                        <button type="submit" class="tg-btn ghost" style="padding:8px 14px">نمایش</button>
                    </form>
                </div>

                <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:16px">
                    <?php
                    $cards = [
                        ['✅', 'حاضر', $report['stats']['present'] ?? 0, 'var(--tg-online)'],
                        ['⏰', 'تاخیر', $report['stats']['late'] ?? 0, 'var(--tg-warn)'],
                        ['❌', 'غایب', $report['stats']['absent'] ?? 0, 'var(--tg-danger)'],
                        ['🏖️', 'مرخصی', $report['stats']['leave'] ?? 0, 'var(--tg-accent)'],
                        ['🚗', 'مأموریت', $report['stats']['mission'] ?? 0, 'var(--tg-accent)'],
                    ];
                    foreach ($cards as [$icon, $label, $val, $color]): ?>
                    <div style="flex:1;min-width:90px;background:var(--tg-bg);border-radius:12px;padding:12px;text-align:center">
                        <div style="font-size:18px"><?php echo e($icon); ?></div>
                        <div style="font-size:19px;font-weight:800;color:<?php echo e($color); ?>"><?php echo fa_number($val); ?></div>
                        <div style="font-size:11px;color:var(--tg-muted)"><?php echo e($label); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div style="display:flex;gap:20px;flex-wrap:wrap;background:var(--tg-bg);border-radius:12px;padding:14px;font-size:13px">
                    <div><div style="font-size:11px;color:var(--tg-muted)">روزهای کاری</div><b><?php echo fa_number($report['workdays']); ?></b></div>
                    <div><div style="font-size:11px;color:var(--tg-muted)">مجموع کارکرد</div><b><?php echo fa_number(number_format($report['total_hours'], 1)); ?> ساعت</b></div>
                    <div><div style="font-size:11px;color:var(--tg-muted)">نرخ حضور</div><b style="color:var(--tg-online)"><?php echo fa_number($report['attendance_rate']); ?>٪</b></div>
                </div>

                <?php if ($report['records']): ?>
                <div style="margin-top:16px;max-height:280px;overflow:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:12px">
                        <thead>
                            <tr style="color:var(--tg-muted)">
                                <th style="text-align:right;padding:8px;border-bottom:1px solid var(--tg-line)">تاریخ</th>
                                <th style="text-align:right;padding:8px;border-bottom:1px solid var(--tg-line)">ورود</th>
                                <th style="text-align:right;padding:8px;border-bottom:1px solid var(--tg-line)">خروج</th>
                                <th style="text-align:right;padding:8px;border-bottom:1px solid var(--tg-line)">ساعت</th>
                                <th style="text-align:right;padding:8px;border-bottom:1px solid var(--tg-line)">وضعیت</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($report['records'] as $r): ?>
                            <tr>
                                <td style="padding:8px;border-bottom:1px solid var(--tg-line)"><?php echo e(jalali_date((string)$r['day'])); ?></td>
                                <td style="padding:8px;border-bottom:1px solid var(--tg-line)"><?php echo e($r['check_in'] ? substr((string)$r['check_in'], 0, 5) : '—'); ?></td>
                                <td style="padding:8px;border-bottom:1px solid var(--tg-line)"><?php echo e($r['check_out'] ? substr((string)$r['check_out'], 0, 5) : '—'); ?></td>
                                <td style="padding:8px;border-bottom:1px solid var(--tg-line)"><?php echo fa_number(number_format(Attendance::workedHours($r), 1)); ?></td>
                                <td style="padding:8px;border-bottom:1px solid var(--tg-line)"><?php echo e(Attendance::statusIcon($r['status']) . ' ' . Attendance::statusLabel($r['status'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php m_foot(); ?>
<script>TG.boot({});</script>
</body>
</html>
