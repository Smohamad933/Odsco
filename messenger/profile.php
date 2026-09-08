<?php
/**
 * ============================================================================
 *  Odsco Messenger — پروفایل کاربر
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

m_check_login();

$me    = m_current_user();
$myUid = $me['uid'];
$activeChatKey = '';

$uid = (string)($_GET['user'] ?? '');
$user = Users::find($uid);
if (!$user) {
    m_set_flash('⚠️ کاربر پیدا نشد');
    redirect('index.php');
}

$isMe     = $uid === $myUid;
$isOnline = Messenger::isOnline($uid);
if (!$isMe && (bool)Settings::get('msg_privacy_profile', true)) {
    $user['phone'] = '';
}

// پروژه‌هایی که هم من و هم این کاربر عضویش را داریم
$common = [];
foreach (ProjectMembers::projectsOf($myUid) as $p) {
    if (ProjectMembers::isMember((string)$p['uid'], $uid)) {
        $common[] = $p;
    }
}

include __DIR__ . '/sidebar.php';
m_head($user['full_name'] . ' · پروفایل');
?>
<body>
<div class="tg-app" id="tgRoot" data-csrf="<?php echo e(csrf_token()); ?>" data-user="<?php echo e($myUid); ?>">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="tg-main">
        <div class="tg-store-bar" id="tgStoreBar"><span class="dot"></span> در حال آماده‌سازی حافظه دستگاه…</div>

        <header class="tg-head">
            <a href="index.php" class="icon-btn" title="بازگشت">→</a>
            <div class="tg-head-info">
                <div class="tg-head-name">پروفایل</div>
                <div class="tg-head-sub"><?php echo $isOnline ? 'آنلاین' : e(m_presence_text($uid)); ?></div>
            </div>
            <?php if (!$isMe): ?>
            <div class="tg-head-actions">
                <a href="chat.php?user=<?php echo e($uid); ?>" class="tg-btn" style="padding:8px 16px">💬 گفتگو</a>
            </div>
            <?php endif; ?>
        </header>

        <div class="tg-msgs" style="padding:16px;gap:14px;align-items:stretch">
            <section style="background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:18px;padding:26px;text-align:center">
                <?php echo m_avatar($user['full_name'], $user['photo'], 'lg'); ?>
                <h2 style="font-size:19px;margin-top:14px"><?php echo e($user['full_name']); ?></h2>
                <p style="font-size:12.5px;color:var(--tg-muted);margin-top:4px">
                    <?php echo e(Users::roleLabel((string)$user['role'])); ?>
                    <?php if (!empty($user['job_title'])): ?> · <?php echo e($user['job_title']); ?><?php endif; ?>
                </p>
                <?php if (!empty($user['bio'])): ?>
                    <p style="font-size:13px;margin-top:12px;line-height:1.9;color:#c7d5e2"><?php echo nl2br(e($user['bio'])); ?></p>
                <?php endif; ?>
                <div style="margin-top:16px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
                    <?php if ($user['phone'] !== ''): ?><span class="tg-chip">📞 <?php echo e($user['phone']); ?></span><?php endif; ?>
                    <?php if (!empty($user['hire_date'])): ?><span class="tg-chip">📅 عضو از <?php echo e(jalali_date((string)$user['hire_date'])); ?></span><?php endif; ?>
                    <span class="tg-chip"><?php echo $isOnline ? '🟢 آنلاین' : '⚪ ' . e(m_presence_text($uid)); ?></span>
                </div>
            </section>

            <?php if ($common): ?>
            <section style="background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:16px;padding:18px">
                <h3 style="font-size:14.5px;margin-bottom:12px">🏗️ پروژه‌های مشترک</h3>
                <?php foreach ($common as $p): ?>
                <a href="<?php echo e(m_admin_url('workspace.php?project=' . $p['uid'])); ?>" target="_blank"
                   class="tg-pick-item" style="margin-bottom:7px">
                    <div class="tg-file-ico">🏗️</div>
                    <span class="nm">
                        <span class="n"><?php echo e($p['title']); ?></span>
                        <span class="s"><?php echo e(jalali_date((string)$p['date'])); ?> · پیشرفت <?php echo fa_number((int)$p['progress']); ?>٪</span>
                    </span>
                    <span class="s"><?php echo e(jalali_date((string)($p['deadline'] ?? '')) ?: 'بدون مهلت'); ?></span>
                </a>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php m_foot(); ?>
<script>TG.boot({});</script>
</body>
</html>
