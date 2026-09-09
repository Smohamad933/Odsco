<?php
/**
 * ============================================================================
 *  Odsco — پنل کارفرما: گزارش‌های قابل دانلود
 * ----------------------------------------------------------------------------
 *  همه گزارش‌های پیشرفت پروژه‌های این کارفرما + خروجی چاپ/CSV
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

c_check_login();

$me        = c_current_user();
$clientUid = c_client_uid();

$projects = c_projects();

$projectFilter = (string)($_GET['project'] ?? '');

// جمع‌آوری گزارش‌ها
$rows = [];
foreach ($projects as $p) {
    if ($projectFilter !== '' && (string)$p['uid'] !== $projectFilter) continue;
    foreach (ProjectUpdates::list((string)$p['uid']) as $u) {
        if ($u['visibility'] === 'internal') continue;
        $rows[] = $u + ['project' => $p['title'], 'project_progress' => (int)$p['progress']];
    }
}
usort($rows, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));

// ---- خروجی CSV ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="odsco-reports-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");   // BOM برای نمایش درست فارسی در Excel
    fputcsv($out, ['تاریخ', 'پروژه', 'عنوان گزارش', 'سطح', 'پیشرفت گزارش', 'پیشرفت پروژه', 'ثبت‌کننده', 'متن']);
    foreach ($rows as $r) {
        fputcsv($out, [
            jalali_datetime((string)$r['created_at']),
            $r['project'],
            $r['title'],
            ProjectUpdates::LEVELS[$r['level']] ?? $r['level'],
            $r['progress'] !== null ? $r['progress'] . '٪' : '',
            $r['project_progress'] . '٪',
            $r['author_name'],
            (string)$r['body'],
        ]);
    }
    fclose($out);
    exit;
}

c_head('گزارش‌ها', 'reports');
?>
<div class="cl-page-head">
    <div>
        <h1>📄 گزارش‌های پیشرفت</h1>
        <p><?php echo fa_number(count($rows)); ?> گزارش ثبت‌شده</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <form method="get" style="display:flex;gap:6px">
            <select name="project" onchange="this.form.submit()"
                    style="padding:9px 12px;border:1px solid var(--cl-line);border-radius:10px;font-family:inherit;font-size:13px;background:#fff">
                <option value="">همه پروژه‌ها</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?php echo e($p['uid']); ?>" <?php echo $projectFilter === $p['uid'] ? 'selected' : ''; ?>>
                        <?php echo e($p['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <a href="?export=csv<?php echo $projectFilter !== '' ? '&project=' . e($projectFilter) : ''; ?>" class="cl-btn ghost">⬇️ دانلود CSV</a>
        <button type="button" class="cl-btn ghost" onclick="window.print()">🖨️ چاپ</button>
    </div>
</div>

<?php if (!$rows): ?>
<div class="cl-card">
    <div class="cl-empty">
        <div class="big">📭</div>
        <h2>گزارشی وجود ندارد</h2>
        <p>به‌محض ثبت گزارش پیشرفت توسط تیم اجرایی، اینجا نمایش داده می‌شود.</p>
    </div>
</div>
<?php else: ?>
<div class="cl-card">
    <div class="cl-table-wrap">
        <table class="cl-table">
            <thead>
                <tr>
                    <th>تاریخ</th><th>پروژه</th><th>عنوان</th><th>سطح</th>
                    <th>پیشرفت</th><th>ثبت‌کننده</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $pill = ['info' => 'info', 'success' => 'ok', 'warning' => 'warn', 'danger' => 'danger'][$r['level']] ?? 'mute';
            ?>
                <tr>
                    <td style="white-space:nowrap"><?php echo e(jalali_datetime((string)$r['created_at'])); ?></td>
                    <td><?php echo e($r['project']); ?></td>
                    <td>
                        <strong><?php echo e($r['title']); ?></strong>
                        <?php if ($r['body'] !== ''): ?>
                            <div style="font-size:11.5px;color:var(--cl-muted);margin-top:3px;line-height:1.8">
                                <?php echo e(mb_substr($r['body'], 0, 110)); ?><?php echo mb_strlen($r['body']) > 110 ? '…' : ''; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><span class="pill <?php echo e($pill); ?>"><?php echo e(ProjectUpdates::LEVELS[$r['level']] ?? $r['level']); ?></span></td>
                    <td>
                        <?php if ($r['progress'] !== null): ?>
                            <div style="min-width:90px"><?php echo c_progress_bar((int)$r['progress']); ?></div>
                            <small><?php echo fa_number((int)$r['progress']); ?>٪</small>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="white-space:nowrap"><?php echo e($r['author_name']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php c_foot(); ?>
