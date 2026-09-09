<?php
/**
 * ============================================================================
 *  Odsco — پنل کارفرما: داشبورد
 * ----------------------------------------------------------------------------
 *  فقط پروژه‌هایی که client_visible هستند و به همین شرکت تعلق دارند.
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

c_check_login();

$me       = c_current_user();
$clientUid = c_client_uid();
$client   = $clientUid !== '' ? Clients::find($clientUid) : null;

$projects = c_projects();

// آمار
$done = array_values(array_filter($projects, fn($p) => (int)$p['progress'] >= 100));
$running = array_values(array_filter($projects, fn($p) => (int)$p['progress'] < 100 && $p['status'] === 'active'));
$avg = $projects ? (int)round(array_sum(array_map(fn($p) => (int)$p['progress'], $projects)) / count($projects)) : 0;

$overdue = [];
foreach ($projects as $p) {
    if (!empty($p['end_date']) && strtotime((string)$p['end_date']) < time() && (int)$p['progress'] < 100) {
        $overdue[] = $p;
    }
}

// آخرین گزارش‌ها و اعلان‌ها
$updates = [];
foreach ($projects as $p) {
    foreach (ProjectUpdates::list((string)$p['uid'], 5) as $u) {
        if ($u['visibility'] === 'internal') continue;   // گزارش‌های داخلی برای کارفرما نیست
        $updates[] = $u;
    }
}
usort($updates, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
$updates = array_slice($updates, 0, 8);

$notices = Notifications::forUser($me['uid'], false, 8);
?>
<?php c_head('داشبورد', 'home'); ?>

<div class="cl-page-head">
    <div>
        <h1>سلام <?php echo e($me['full_name']); ?> 👋</h1>
        <p><?php echo e($client['name'] ?? 'پنل کارفرما'); ?> — <?php echo e(jalali_date_long()); ?></p>
    </div>
    <a href="projects.php" class="cl-btn ghost">مشاهده همه پروژه‌ها</a>
</div>

<?php if (!$projects): ?>
<div class="cl-card">
    <div class="cl-empty">
        <div class="big">🏗️</div>
        <h2>پروژه‌ای برای نمایش وجود ندارد</h2>
        <p>به‌محض اینکه پروژه‌ای برای شرکت شما ثبت و «قابل نمایش برای کارفرما» شود، اینجا ظاهر می‌شود.</p>
    </div>
</div>
<?php else: ?>

<div class="cl-stats">
    <div class="cl-stat">
        <span class="ico">🏗️</span>
        <span><span class="val"><?php echo fa_number(count($projects)); ?></span><br><span class="lbl">کل پروژه‌ها</span></span>
    </div>
    <div class="cl-stat">
        <span class="ico">🔨</span>
        <span><span class="val"><?php echo fa_number(count($running)); ?></span><br><span class="lbl">در حال اجرا</span></span>
    </div>
    <div class="cl-stat">
        <span class="ico">✅</span>
        <span><span class="val"><?php echo fa_number(count($done)); ?></span><br><span class="lbl">تکمیل‌شده</span></span>
    </div>
    <div class="cl-stat">
        <span class="ico">📈</span>
        <span><span class="val"><?php echo fa_number($avg); ?>٪</span><br><span class="lbl">میانگین پیشرفت</span></span>
    </div>
</div>

<?php if ($overdue): ?>
<div class="cl-card" style="border-right:4px solid var(--cl-danger)">
    <h2>⏰ پروژه‌های عقب‌افتاده از مهلت</h2>
    <div class="cl-table-wrap">
        <table class="cl-table">
            <thead><tr><th>پروژه</th><th>مهلت</th><th>پیشرفت</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($overdue as $p): ?>
                <tr>
                    <td><strong><?php echo e($p['title']); ?></strong></td>
                    <td><span class="pill danger"><?php echo e(jalali_date((string)$p['end_date'])); ?></span></td>
                    <td style="min-width:130px"><?php echo c_progress_bar((int)$p['progress']); ?></td>
                    <td><a href="projects.php?project=<?php echo e($p['uid']); ?>" class="cl-btn sm">جزئیات</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="cl-card">
    <h2>🏗️ پروژه‌های شما</h2>
    <div class="cl-projects">
        <?php foreach ($projects as $p):
            $cover = !empty($p['cover_image']) ? $p['cover_image'] : (!empty($p['images'][0]) ? $p['images'][0] : '');
        ?>
        <a href="projects.php?project=<?php echo e($p['uid']); ?>" class="cl-proj" style="text-decoration:none;color:inherit">
            <div class="cover" <?php echo $cover !== '' ? 'style="background-image:url(\'' . e('../' . ltrim((string)$cover, '/')) . '\')"' : ''; ?>>
                <?php echo c_status_pill((string)$p['status']); ?>
            </div>
            <div class="body">
                <h3><?php echo e($p['title']); ?></h3>
                <div class="meta">
                    <?php if (!empty($p['location'])): ?>📍 <?php echo e($p['location']); ?> · <?php endif; ?>
                    <?php if (!empty($p['end_date'])): ?>📅 مهلت <?php echo e(jalali_date((string)$p['end_date'])); ?><?php else: ?>بدون مهلت تعیین‌شده<?php endif; ?>
                </div>
                <?php echo c_progress_bar((int)$p['progress']); ?>
                <div class="cl-pct"><span>پیشرفت</span><span><?php echo fa_number((int)$p['progress']); ?>٪</span></div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px">
    <div class="cl-card">
        <h2>📊 آخرین گزارش‌های پیشرفت</h2>
        <?php if (!$updates): ?>
            <p style="color:var(--cl-muted);font-size:13px">هنوز گزارشی ثبت نشده است.</p>
        <?php else: ?>
            <div class="cl-timeline">
                <?php foreach ($updates as $u): ?>
                <div class="cl-tl <?php echo e($u['level']); ?>">
                    <div class="tl-head">
                        <strong><?php echo e(ProjectUpdates::levelIcon((string)$u['level']) . ' ' . $u['title']); ?></strong>
                        <?php if ($u['progress'] !== null): ?><span class="pill mute"><?php echo fa_number((int)$u['progress']); ?>٪</span><?php endif; ?>
                        <span class="tl-time"><?php echo e(time_ago_fa((string)$u['created_at'])); ?></span>
                    </div>
                    <div style="font-size:11.5px;color:var(--cl-muted)"><?php echo e($u['project_title']); ?></div>
                    <?php if ($u['body'] !== ''): ?><div class="tl-body"><?php echo e(mb_substr($u['body'], 0, 140)); ?><?php echo mb_strlen($u['body']) > 140 ? '…' : ''; ?></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="cl-card">
        <h2>🔔 اعلان‌های اخیر</h2>
        <?php if (!$notices): ?>
            <p style="color:var(--cl-muted);font-size:13px">اعلانی ندارید.</p>
        <?php else: ?>
            <?php foreach ($notices as $n): ?>
            <div class="cl-notice<?php echo $n['is_read'] ? '' : ' unread'; ?>">
                <span class="ico"><?php echo e($n['icon'] ?: '🔔'); ?></span>
                <div style="flex:1;min-width:0">
                    <div class="n-title"><?php echo e($n['title']); ?></div>
                    <?php if (!empty($n['body'])): ?><div class="n-body"><?php echo e($n['body']); ?></div><?php endif; ?>
                    <div class="n-time"><?php echo e(jalali_datetime((string)$n['created_at'])); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <a href="notifications.php" class="cl-btn ghost sm" style="margin-top:8px">همه اعلان‌ها</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php c_foot(); ?>
