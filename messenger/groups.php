<?php
/**
 * ============================================================================
 *  Odsco Messenger — مدیریت گروه‌ها
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

m_check_login();

$me    = m_current_user();
$myUid = $me['uid'];
$activeChatKey = '';

Messenger::touchPresence($myUid);

$myGroups = [];
foreach (Messenger::myGroups($myUid) as $gUid) {
    $g = Messenger::groupInfo($gUid);
    if ($g) {
        $g['last'] = Messenger::lastMessage('group', $gUid, $myUid);
        $g['unread'] = Messenger::unreadCount('g:' . $gUid, $myUid);
        $g['is_owner'] = $g['creator_uid'] === $myUid;
        $myGroups[] = $g;
    }
}

$contacts = Messenger::contacts($myUid);

include __DIR__ . '/sidebar.php';
m_head('گروه‌ها · پیام‌رسان');
?>
<body>
<div class="tg-app" id="tgRoot" data-csrf="<?php echo e(csrf_token()); ?>" data-user="<?php echo e($myUid); ?>">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="tg-main">
        <div class="tg-store-bar" id="tgStoreBar"><span class="dot"></span> در حال آماده‌سازی حافظه دستگاه…</div>

        <header class="tg-head">
            <a href="index.php" class="icon-btn" title="بازگشت">→</a>
            <div class="tg-head-info">
                <div class="tg-head-name">👥 گروه‌های من</div>
                <div class="tg-head-sub"><?php echo fa_number(count($myGroups)); ?> گروه</div>
            </div>
            <div class="tg-head-actions">
                <button type="button" class="tg-btn" id="btnNewGroup" style="padding:8px 16px">+ گروه جدید</button>
            </div>
        </header>

        <div class="tg-msgs" style="padding:16px;gap:10px">
            <?php if (!$myGroups): ?>
                <div class="tg-placeholder" style="padding:60px 20px">
                    <div class="ico">👥</div>
                    <h2>هنوز گروهی ندارید</h2>
                    <p>یک گروه بسازید تا با هم‌تیمی‌هایتان هماهنگ شوید.</p>
                </div>
            <?php else: ?>
                <?php foreach ($myGroups as $g): ?>
                <div style="display:flex;align-items:center;gap:12px;background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:14px;padding:12px">
                    <a href="group-chat.php?group=<?php echo e($g['uid']); ?>" style="display:flex;align-items:center;gap:12px;flex:1;min-width:0">
                        <?php echo $g['avatar'] !== ''
                            ? '<img src="' . e(m_asset($g['avatar'])) . '" class="tg-av group" alt="">'
                            : '<div class="tg-av group">👥</div>'; ?>
                        <span style="flex:1;min-width:0">
                            <span style="display:block;font-size:14.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo e($g['name']); ?></span>
                            <span style="display:block;font-size:12px;color:var(--tg-muted)">
                                <?php echo fa_number($g['member_count']); ?> عضو
                                <?php if ($g['is_owner']): ?> · <span style="color:var(--tg-accent)">سازنده</span><?php endif; ?>
                                <?php if ($g['about'] !== ''): ?> · <?php echo e(mb_substr($g['about'], 0, 40)); ?><?php endif; ?>
                            </span>
                            <?php if ($g['last']): ?>
                                <span style="display:block;font-size:11.5px;color:var(--tg-muted);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                    <?php echo e(chat_time_fa($g['last']['created_at'])); ?> — <?php echo e(mb_substr((string)$g['last']['content'], 0, 50) ?: 'رسانه'); ?>
                                </span>
                            <?php endif; ?>
                        </span>
                        <?php if ($g['unread'] > 0): ?><span class="tg-badge"><?php echo fa_number($g['unread']); ?></span><?php endif; ?>
                    </a>
                    <a href="group-info.php?group=<?php echo e($g['uid']); ?>" class="icon-btn" title="اطلاعات گروه">ℹ️</a>
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

    const contacts = <?php echo json_encode(array_map(function (array $u): array {
        return ['uid' => $u['uid'], 'name' => $u['full_name'], 'role' => Users::roleLabel($u['role']), 'job' => $u['job_title']];
    }, $contacts), JSON_UNESCAPED_UNICODE); ?>;

    document.getElementById('btnNewGroup').addEventListener('click', function () {
        let html = '<div class="tg-field"><label>نام گروه</label><input type="text" id="gName" placeholder="مثلاً تیم معماری"></div>';
        html += '<div class="tg-field"><label>توضیحات (اختیاری)</label><textarea id="gAbout" rows="2"></textarea></div>';
        html += '<div class="tg-field"><label>اعضا</label><input type="text" id="gSearch" placeholder="جستجوی کاربر…"></div>';
        html += '<div class="tg-pick" id="gMembers">';
        contacts.forEach(function (u) {
            html += '<label class="tg-pick-item" data-n="' + TG.esc((u.name + ' ' + u.role + ' ' + u.job).toLowerCase()) + '">'
                + '<input type="checkbox" value="' + TG.esc(u.uid) + '">'
                + '<span class="nm"><span class="n">' + TG.esc(u.name) + '</span>'
                + '<span class="s">' + TG.esc(u.role) + (u.job ? ' · ' + TG.esc(u.job) : '') + '</span></span></label>';
        });
        html += '</div>';
        if (!contacts.length) html += '<p style="color:#8698ab;font-size:13px;text-align:center">کاربر دیگری وجود ندارد</p>';

        const box = TG.modal('گروه جدید', html,
            '<button type="button" class="tg-btn ghost" data-close>انصراف</button>'
            + '<button type="button" class="tg-btn" id="gCreate">ساخت گروه</button>', true);

        const search = box.querySelector('#gSearch');
        search.addEventListener('input', function () {
            const q = search.value.trim().toLowerCase();
            box.querySelectorAll('#gMembers .tg-pick-item').forEach(function (el) {
                el.style.display = (el.dataset.n || '').indexOf(q) >= 0 ? '' : 'none';
            });
        });

        box.querySelector('#gCreate').addEventListener('click', function () {
            const name = box.querySelector('#gName').value.trim();
            if (!name) { TG.toast('⚠️ نام گروه را وارد کنید'); return; }
            const members = Array.prototype.slice.call(box.querySelectorAll('#gMembers input:checked')).map(function (i) { return i.value; });

            TG.api('group_create', { name: name, about: box.querySelector('#gAbout').value.trim(), members: members })
                .then(function (res) {
                    TG.toast('✅ گروه ساخته شد');
                    setTimeout(function () { location.href = res.redirect; }, 700);
                })
                .catch(function (e) { TG.toast('⚠️ ' + e.message); });
        });

        setTimeout(function () { box.querySelector('#gName').focus(); }, 60);
    });

    if (location.search.indexOf('new=1') >= 0) {
        setTimeout(function () { document.getElementById('btnNewGroup').click(); }, 300);
    }
})();
</script>
</body>
</html>
