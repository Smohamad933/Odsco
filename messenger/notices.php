<?php
/**
 * ============================================================================
 *  Odsco Messenger — مرکز اعلان‌ها
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

m_check_login();

$me    = m_current_user();
$myUid = $me['uid'];
$activeChatKey = '';

// خوانده‌نشده‌ها در لیست باقی می‌مانند؛ فقط با کلیک یا دکمه «خوانده‌شده» پاک می‌شوند
$notices = Notifications::forUser($myUid, false, 100);
$unread  = Notifications::unreadCount($myUid);

m_head('اعلان‌ها · پیام‌رسان');
?>
<body>
<div class="tg-app" id="tgRoot" data-csrf="<?php echo e(csrf_token()); ?>" data-user="<?php echo e($myUid); ?>">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="tg-main">
        <div class="tg-store-bar" id="tgStoreBar"><span class="dot"></span> در حال آماده‌سازی حافظه دستگاه…</div>

        <header class="tg-head">
            <a href="index.php" class="icon-btn" title="بازگشت">→</a>
            <div class="tg-head-info">
                <div class="tg-head-name">🔔 اعلان‌ها</div>
                <div class="tg-head-sub"><?php echo fa_number($unread); ?> خوانده‌نشده از <?php echo fa_number(count($notices)); ?></div>
            </div>
            <div class="tg-head-actions">
                <?php if ($unread > 0): ?>
                    <button type="button" class="tg-btn" id="btnReadAll" style="padding:8px 14px;font-size:12.5px">خواندن همه</button>
                <?php endif; ?>
            </div>
        </header>

        <div class="tg-msgs" style="padding:14px;gap:8px;align-items:stretch">
            <?php if (!$notices): ?>
                <div class="tg-placeholder" style="padding:60px 20px">
                    <div class="ico">🔕</div>
                    <h2>اعلانی ندارید</h2>
                    <p>هشدارهای پروژه، پیام‌های جدید و رویدادهای حضور و غیاب اینجا نمایش داده می‌شوند.</p>
                </div>
            <?php else: ?>
                <?php foreach ($notices as $n): ?>
                <div class="notice-item" data-uid="<?php echo e($n['uid']); ?>"
                     style="display:flex;gap:12px;background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:14px;padding:13px;<?php echo $n['is_read'] ? 'opacity:.66' : ''; ?>">
                    <div style="width:40px;height:40px;border-radius:12px;background:var(--tg-bg);display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0">
                        <?php echo e($n['icon'] ?: '🔔'); ?>
                    </div>
                    <div style="flex:1;min-width:0">
                        <div style="display:flex;align-items:center;gap:8px">
                            <strong style="font-size:13.5px;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo e($n['title']); ?></strong>
                            <?php if (!$n['is_read']): ?><span class="tg-badge" style="min-width:8px;height:8px;padding:0"></span><?php endif; ?>
                            <span style="font-size:11px;color:var(--tg-muted);flex-shrink:0"><?php echo e(time_ago_fa($n['created_at'])); ?></span>
                        </div>
                        <?php if ($n['body'] !== '' && $n['body'] !== null): ?>
                            <p style="font-size:12.5px;color:var(--tg-muted);margin-top:4px;line-height:1.85"><?php echo e($n['body']); ?></p>
                        <?php endif; ?>
                        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
                            <?php if ($n['link'] !== ''): ?>
                                <a href="<?php echo e($n['link']); ?>" class="tg-btn" style="padding:6px 13px;font-size:12px">مشاهده</a>
                            <?php endif; ?>
                            <?php if (!$n['is_read']): ?>
                                <button type="button" class="tg-btn ghost" data-read style="padding:6px 13px;font-size:12px">خوانده‌شده</button>
                            <?php endif; ?>
                            <button type="button" class="tg-btn ghost" data-del style="padding:6px 13px;font-size:12px;color:var(--tg-danger)">حذف</button>
                        </div>
                        <div style="font-size:10.5px;color:#5d6f80;margin-top:6px"><?php echo e(jalali_datetime($n['created_at'])); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php m_foot(); ?>
<script>
(function () {
    'use strict';
    TG.boot({});

    document.querySelectorAll('[data-read]').forEach(function (b) {
        b.addEventListener('click', function () {
            const item = b.closest('.notice-item');
            TG.api('notice_read', { uid: item.dataset.uid }).then(function () {
                item.style.opacity = '.66';
                b.remove();
                const dot = item.querySelector('.tg-badge');
                if (dot) dot.remove();
            }).catch(function (e) { TG.toast('⚠️ ' + e.message); });
        });
    });

    document.querySelectorAll('[data-del]').forEach(function (b) {
        b.addEventListener('click', function () {
            const item = b.closest('.notice-item');
            TG.api('notice_read', { uid: item.dataset.uid }).then(function () {
                item.style.transition = 'opacity .25s, transform .25s';
                item.style.opacity = '0';
                item.style.transform = 'translateX(24px)';
                setTimeout(function () { item.remove(); }, 260);
            }).catch(function (e) { TG.toast('⚠️ ' + e.message); });
        });
    });

    const all = document.getElementById('btnReadAll');
    if (all) all.addEventListener('click', function () {
        TG.api('notice_read', { uid: 'all' }).then(function () { location.reload(); })
            .catch(function (e) { TG.toast('⚠️ ' + e.message); });
    });
})();
</script>
</body>
</html>
