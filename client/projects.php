<?php
/**
 * ============================================================================
 *  Odsco — پنل کارفرما: پروژه‌ها و تایم‌لاین پیشرفت
 * ----------------------------------------------------------------------------
 *  ?project=<uid>   نمای یک پروژه
 *  بدون پارامتر     فهرست پروژه‌ها
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

c_check_login();

$me        = c_current_user();
$clientUid = c_client_uid();

$projects = $clientUid !== ''
    ? Projects::list(['client_uid' => $clientUid, 'client_visible' => true])
    : [];

$uid     = (string)($_GET['project'] ?? '');
$project = null;

if ($uid !== '') {
    $p = Projects::find($uid);
    // فقط پروژه‌های همین شرکت که برای کارفرما قابل نمایش هستند
    if ($p && (string)$p['client_uid'] === $clientUid && $p['client_visible']) {
        $project = $p;
    }
}

c_head($project ? $project['title'] : 'پروژه‌ها', 'projects');

if (!$project):
?>
<div class="cl-page-head">
    <div>
        <h1>🏗️ پروژه‌های شما</h1>
        <p><?php echo fa_number(count($projects)); ?> پروژه</p>
    </div>
</div>

<?php if (!$projects): ?>
<div class="cl-card">
    <div class="cl-empty">
        <div class="big">📁</div>
        <h2>پروژه‌ای برای نمایش نیست</h2>
        <p>پروژه‌هایی که برای شرکت شما ثبت و برای کارفرما قابل نمایش شوند، اینجا لیست می‌شوند.</p>
    </div>
</div>
<?php else: ?>
<div class="cl-projects">
    <?php foreach ($projects as $p):
        $cover = !empty($p['cover_image']) ? $p['cover_image'] : (!empty($p['images'][0]) ? $p['images'][0] : '');
        $updCount = count(ProjectUpdates::list((string)$p['uid'], 50));
    ?>
    <a href="?project=<?php echo e($p['uid']); ?>" class="cl-proj" style="text-decoration:none;color:inherit">
        <div class="cover" <?php echo $cover !== '' ? 'style="background-image:url(\'' . e('../' . ltrim((string)$cover, '/')) . '\')"' : ''; ?>>
            <?php echo c_status_pill((string)$p['status']); ?>
        </div>
        <div class="body">
            <h3><?php echo e($p['title']); ?></h3>
            <div class="meta">
                <?php if (!empty($p['location'])): ?>📍 <?php echo e($p['location']); ?> · <?php endif; ?>
                📄 <?php echo fa_number($updCount); ?> گزارش
            </div>
            <?php echo c_progress_bar((int)$p['progress']); ?>
            <div class="cl-pct">
                <span><?php echo !empty($p['end_date']) ? 'مهلت ' . e(jalali_date((string)$p['end_date'])) : 'بدون مهلت'; ?></span>
                <span><?php echo fa_number((int)$p['progress']); ?>٪</span>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
    c_foot();
    exit;
endif;

// ===========================================================================
// نمای یک پروژه
// ===========================================================================
$updates = array_values(array_filter(
    ProjectUpdates::list((string)$project['uid']),
    fn(array $u): bool => $u['visibility'] !== 'internal'
));

$members = ProjectMembers::list((string)$project['uid']);
$images  = array_values(array_filter((array)$project['images']));
?>
<div class="cl-page-head">
    <div>
        <h1><?php echo e($project['title']); ?></h1>
        <p>
            <?php if (!empty($project['location'])): ?>📍 <?php echo e($project['location']); ?> · <?php endif; ?>
            <?php if (!empty($project['end_date'])): ?>📅 مهلت <?php echo e(jalali_date((string)$project['end_date'])); ?> · <?php endif; ?>
            <?php echo c_status_pill((string)$project['status']); ?>
        </p>
    </div>
    <a href="projects.php" class="cl-btn ghost">← بازگشت به فهرست</a>
</div>

<div class="cl-card">
    <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:10px;margin-bottom:10px">
        <h2 style="margin:0">پیشرفت کلی پروژه</h2>
        <strong style="font-size:24px;color:var(--cl-accent)"><?php echo fa_number((int)$project['progress']); ?>٪</strong>
    </div>
    <?php echo c_progress_bar((int)$project['progress']); ?>

    <?php if ($project['description'] !== ''): ?>
        <p style="font-size:13.5px;line-height:2.1;color:#46506a;margin:16px 0 0"><?php echo nl2br(e($project['description'])); ?></p>
    <?php endif; ?>

    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:18px;font-size:13px">
        <?php if (!empty($project['area'])): ?><div><div style="color:var(--cl-muted);font-size:11.5px">مساحت</div><b><?php echo e($project['area']); ?></b></div><?php endif; ?>
        <?php if (!empty($project['year'])): ?><div><div style="color:var(--cl-muted);font-size:11.5px">سال</div><b><?php echo e($project['year']); ?></b></div><?php endif; ?>
        <?php if (!empty($project['budget'])): ?><div><div style="color:var(--cl-muted);font-size:11.5px">بودجه</div><b><?php echo e($project['budget']); ?></b></div><?php endif; ?>
        <?php if (!empty($project['start_date'])): ?><div><div style="color:var(--cl-muted);font-size:11.5px">شروع</div><b><?php echo e(jalali_date((string)$project['start_date'])); ?></b></div><?php endif; ?>
        <?php if (!empty($project['end_date'])): ?><div><div style="color:var(--cl-muted);font-size:11.5px">پایان</div><b><?php echo e(jalali_date((string)$project['end_date'])); ?></b></div><?php endif; ?>
        <div><div style="color:var(--cl-muted);font-size:11.5px">تیم اجرایی</div><b><?php echo fa_number(count($members)); ?> نفر</b></div>
    </div>
</div>

<?php if ($images): ?>
<div class="cl-card">
    <h2>🖼️ تصاویر پروژه</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px">
        <?php foreach ($images as $img): ?>
            <a href="../<?php echo e(ltrim((string)$img, '/')); ?>" target="_blank">
                <img src="../<?php echo e(ltrim((string)$img, '/')); ?>" alt=""
                     style="width:100%;height:120px;object-fit:cover;border-radius:12px;border:1px solid var(--cl-line)">
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="cl-card">
    <h2>📊 تایم‌لاین گزارش‌های پیشرفت</h2>
    <?php if (!$updates): ?>
        <p style="color:var(--cl-muted);font-size:13px">هنوز گزارشی برای این پروژه ثبت نشده است.</p>
    <?php else: ?>
    <div class="cl-timeline">
        <?php foreach ($updates as $u): ?>
        <div class="cl-tl <?php echo e($u['level']); ?>">
            <div class="tl-head">
                <strong><?php echo e(ProjectUpdates::levelIcon((string)$u['level']) . ' ' . $u['title']); ?></strong>
                <?php if ($u['progress'] !== null): ?><span class="pill mute"><?php echo fa_number((int)$u['progress']); ?>٪</span><?php endif; ?>
                <span class="tl-time"><?php echo e(jalali_datetime((string)$u['created_at'])); ?></span>
            </div>
            <div style="font-size:11.5px;color:var(--cl-muted)"><?php echo e($u['author_name']); ?></div>
            <?php if ($u['body'] !== ''): ?><div class="tl-body"><?php echo nl2br(e($u['body'])); ?></div><?php endif; ?>
            <?php if (!empty($u['attachment'])): ?>
                <div class="tl-attach">
                    <?php if (preg_match('/\.(jpg|jpeg|png|gif|webp|avif)$/i', (string)$u['attachment'])): ?>
                        <img src="../<?php echo e(ltrim((string)$u['attachment'], '/')); ?>" alt="">
                    <?php else: ?>
                        <a href="../<?php echo e(ltrim((string)$u['attachment'], '/')); ?>" target="_blank" class="cl-btn sm">📎 دانلود پیوست</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="cl-card">
    <h2>👷 تیم اجرایی پروژه</h2>
    <?php if (!$members): ?>
        <p style="color:var(--cl-muted);font-size:13px">عضوی ثبت نشده است.</p>
    <?php else: ?>
    <div class="cl-table-wrap">
        <table class="cl-table">
            <thead><tr><th>نام</th><th>سمت</th><th>نقش در پروژه</th></tr></thead>
            <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td><?php echo e($m['full_name']); ?></td>
                    <td><?php echo e($m['job_title'] ?: '—'); ?></td>
                    <td><span class="pill info"><?php echo e($m['role_in_project'] ?: 'عضو تیم'); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php c_foot(); ?>
