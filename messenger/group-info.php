<?php
/**
 * ============================================================================
 *  Odsco Messenger — اطلاعات و مدیریت گروه
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

m_check_login();

$me    = m_current_user();
$myUid = $me['uid'];
$activeChatKey = '';

$groupUid = (string)($_GET['group'] ?? '');
$group = $groupUid !== '' ? Messenger::groupInfo($groupUid) : null;

if (!$group) {
    m_set_flash('⚠️ گروه پیدا نشد');
    redirect('groups.php');
}

if (!Messenger::isGroupMember($groupUid, $myUid)) {
    m_set_flash('⛔ شما عضو این گروه نیستید');
    redirect('groups.php');
}

$myRole = Messenger::groupRole($groupUid, $myUid);
$canManage = in_array($myRole, ['owner', 'admin'], true) || m_is_admin();
$isOwner = $group['creator_uid'] === $myUid || m_is_admin();

$contacts = Messenger::contacts($myUid);
$memberUids = array_column($group['members'], 'uid');
$nonMembers = array_values(array_filter($contacts, fn($u) => !in_array($u['uid'], $memberUids, true)));

include __DIR__ . '/sidebar.php';
m_head($group['name'] . ' · گروه');
?>
<body>
<div class="tg-app" id="tgRoot" data-csrf="<?php echo e(csrf_token()); ?>" data-user="<?php echo e($myUid); ?>"
     data-group="<?php echo e($groupUid); ?>">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="tg-main">
        <div class="tg-store-bar" id="tgStoreBar"><span class="dot"></span> در حال آماده‌سازی حافظه دستگاه…</div>

        <header class="tg-head">
            <a href="group-chat.php?group=<?php echo e($groupUid); ?>" class="icon-btn" title="بازگشت به گفتگو">→</a>
            <div class="tg-head-info">
                <div class="tg-head-name">ℹ️ اطلاعات گروه</div>
                <div class="tg-head-sub"><?php echo fa_number($group['member_count']); ?> عضو</div>
            </div>
            <div class="tg-head-actions">
                <a href="group-chat.php?group=<?php echo e($groupUid); ?>" class="tg-btn" style="padding:8px 16px">💬 گفتگو</a>
            </div>
        </header>

        <div class="tg-msgs" style="padding:16px;gap:14px;align-items:stretch">

            <!-- سربرگ گروه -->
            <div style="background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:16px;padding:22px;text-align:center">
                <?php echo $group['avatar'] !== ''
                    ? '<img src="' . e(m_asset($group['avatar'])) . '" class="tg-av lg group" alt="" style="margin:0 auto 12px">'
                    : '<div class="tg-av lg group" style="margin:0 auto 12px">👥</div>'; ?>
                <h2 style="font-size:18px;margin-bottom:4px"><?php echo e($group['name']); ?></h2>
                <p style="font-size:12.5px;color:var(--tg-muted)"><?php echo fa_number($group['member_count']); ?> عضو · ساخته‌شده <?php echo e(jalali_date_long($group['created_at'])); ?></p>
                <?php if ($group['about'] !== ''): ?>
                    <p style="font-size:13px;margin-top:10px;line-height:1.9;color:#c7d5e2"><?php echo nl2br(e($group['about'])); ?></p>
                <?php endif; ?>
                <?php if ($group['project_uid'] !== ''):
                    $proj = Projects::find($group['project_uid']);
                    if ($proj): ?>
                    <p style="margin-top:12px;font-size:12px">
                        <a href="<?php echo e(m_admin_url('workspace.php?project=' . $group['project_uid'])); ?>" target="_blank"
                           style="color:var(--tg-accent)">🏗️ پروژه مرتبط: <?php echo e($proj['title']); ?></a>
                    </p>
                <?php endif; endif; ?>
            </div>

            <?php if ($canManage): ?>
            <!-- ویرایش گروه -->
            <div style="background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:16px;padding:16px">
                <h3 style="font-size:14px;margin-bottom:12px">✏️ ویرایش گروه</h3>
                <div class="tg-field"><label>نام گروه</label><input type="text" id="eName" value="<?php echo e($group['name']); ?>"></div>
                <div class="tg-field"><label>توضیحات</label><textarea id="eAbout" rows="2"><?php echo e($group['about']); ?></textarea></div>
                <button type="button" class="tg-btn" id="btnSaveGroup">ذخیره تغییرات</button>
            </div>
            <?php endif; ?>

            <!-- اعضا -->
            <div style="background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:16px;padding:16px">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                    <h3 style="font-size:14px">👥 اعضا (<?php echo fa_number($group['member_count']); ?>)</h3>
                    <?php if ($canManage && $nonMembers): ?>
                        <button type="button" class="tg-btn" id="btnAddMembers" style="padding:7px 14px;font-size:12.5px">+ افزودن عضو</button>
                    <?php endif; ?>
                </div>

                <?php foreach ($group['members'] as $m): ?>
                <div class="tg-pick-item" style="cursor:default">
                    <span class="tg-av-wrap">
                        <?php echo $m['photo'] !== ''
                            ? '<img src="' . e(m_asset($m['photo'])) . '" class="tg-av sm" alt="">'
                            : '<div class="tg-av sm" style="background:' . e(m_avatar_color($m['name'])) . '">' . e(mb_substr($m['name'], 0, 1)) . '</div>'; ?>
                        <?php if ($m['online']): ?><span class="tg-online-dot"></span><?php endif; ?>
                    </span>
                    <span class="nm">
                        <span class="n"><?php echo e($m['name']); ?>
                            <?php if ($m['uid'] === $group['creator_uid']): ?><span style="color:var(--tg-accent);font-size:11px">· سازنده</span><?php endif; ?>
                            <?php if ($m['role'] === 'admin'): ?><span style="color:var(--tg-accent);font-size:11px">· مدیر</span><?php endif; ?>
                            <?php if ($m['uid'] === $myUid): ?><span style="color:var(--tg-muted);font-size:11px">· شما</span><?php endif; ?>
                        </span>
                        <span class="s"><?php echo $m['online'] ? 'آنلاین' : e(m_presence_text($m['uid'])); ?></span>
                    </span>
                    <?php if ($canManage && $m['uid'] !== $group['creator_uid'] && $m['uid'] !== $myUid): ?>
                        <span style="display:flex;gap:2px">
                            <?php if ($isOwner): ?>
                                <button type="button" class="icon-btn" data-role="<?php echo e($m['uid']); ?>"
                                        data-to="<?php echo $m['role'] === 'admin' ? 'member' : 'admin'; ?>"
                                        title="<?php echo $m['role'] === 'admin' ? 'تنزل به عضو' : 'ارتقا به مدیر'; ?>">
                                    <?php echo $m['role'] === 'admin' ? '⬇' : '⬆'; ?>
                                </button>
                            <?php endif; ?>
                            <button type="button" class="icon-btn" data-remove="<?php echo e($m['uid']); ?>" title="حذف از گروه" style="color:var(--tg-danger)">✕</button>
                        </span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- عملیات -->
            <div style="background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:16px;padding:16px;display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="tg-btn ghost" id="btnLeave">🚪 خروج از گروه</button>
                <?php if ($isOwner): ?>
                    <button type="button" class="tg-btn danger" id="btnDelete">🗑 حذف گروه</button>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php m_foot(); ?>
<script>
(function () {
    'use strict';
    TG.boot({});

    const gid = document.getElementById('tgRoot').dataset.group;
    const nonMembers = <?php echo json_encode(array_map(fn($u) => ['uid' => $u['uid'], 'name' => $u['full_name'], 'role' => Users::roleLabel($u['role'])], $nonMembers), JSON_UNESCAPED_UNICODE); ?>;

    const reload = function () { setTimeout(function () { location.reload(); }, 700); };

    const save = document.getElementById('btnSaveGroup');
    if (save) save.addEventListener('click', function () {
        TG.api('group_update', { group: gid, name: document.getElementById('eName').value.trim(), about: document.getElementById('eAbout').value.trim() })
            .then(function () { TG.toast('✅ ذخیره شد'); reload(); })
            .catch(function (e) { TG.toast('⚠️ ' + e.message); });
    });

    const add = document.getElementById('btnAddMembers');
    if (add) add.addEventListener('click', function () {
        let html = '<div class="tg-field"><label>جستجو</label><input type="text" id="amSearch" placeholder="نام کاربر…"></div><div class="tg-pick">';
        nonMembers.forEach(function (u) {
            html += '<label class="tg-pick-item" data-n="' + TG.esc(u.name.toLowerCase()) + '">'
                + '<input type="checkbox" value="' + TG.esc(u.uid) + '">'
                + '<span class="nm"><span class="n">' + TG.esc(u.name) + '</span><span class="s">' + TG.esc(u.role) + '</span></span></label>';
        });
        html += '</div>';
        const box = TG.modal('افزودن عضو', html,
            '<button type="button" class="tg-btn ghost" data-close>انصراف</button><button type="button" class="tg-btn" id="amOk">افزودن</button>', true);

        const s = box.querySelector('#amSearch');
        s.addEventListener('input', function () {
            const q = s.value.trim().toLowerCase();
            box.querySelectorAll('.tg-pick-item').forEach(function (el) { el.style.display = (el.dataset.n || '').indexOf(q) >= 0 ? '' : 'none'; });
        });

        box.querySelector('#amOk').addEventListener('click', function () {
            const list = Array.prototype.slice.call(box.querySelectorAll('input:checked')).map(function (i) { return i.value; });
            if (!list.length) { TG.toast('⚠️ کسی را انتخاب نکردید'); return; }
            TG.api('group_members_add', { group: gid, members: list })
                .then(function (res) { TG.toast('✅ ' + TG.faDigits(res.added) + ' عضو اضافه شد'); reload(); })
                .catch(function (e) { TG.toast('⚠️ ' + e.message); });
        });
    });

    document.querySelectorAll('[data-remove]').forEach(function (b) {
        b.addEventListener('click', function () {
            TG.confirm('حذف عضو', 'این کاربر از گروه حذف شود؟', function () {
                TG.api('group_member_remove', { group: gid, user_uid: b.dataset.remove })
                    .then(function () { TG.toast('✅ حذف شد'); reload(); })
                    .catch(function (e) { TG.toast('⚠️ ' + e.message); });
            }, 'حذف', true);
        });
    });

    document.querySelectorAll('[data-role]').forEach(function (b) {
        b.addEventListener('click', function () {
            TG.api('group_role', { group: gid, user_uid: b.dataset.role, role: b.dataset.to })
                .then(function () { TG.toast('✅ نقش تغییر کرد'); reload(); })
                .catch(function (e) { TG.toast('⚠️ ' + e.message); });
        });
    });

    const leave = document.getElementById('btnLeave');
    if (leave) leave.addEventListener('click', function () {
        TG.confirm('خروج از گروه', 'از این گروه خارج می‌شوید. برای بازگشت باید توسط مدیر اضافه شوید.', function () {
            TG.api('group_leave', { group: gid }).then(function () { location.href = 'groups.php'; })
                .catch(function (e) { TG.toast('⚠️ ' + e.message); });
        }, 'خروج', true);
    });

    const del = document.getElementById('btnDelete');
    if (del) del.addEventListener('click', function () {
        TG.confirm('حذف گروه', 'گروه و همه پیام‌های آن برای همیشه حذف می‌شود. این کار قابل بازگشت نیست!', function () {
            TG.api('group_delete', { group: gid }).then(function () { location.href = 'groups.php'; })
                .catch(function (e) { TG.toast('⚠️ ' + e.message); });
        }, 'حذف گروه', true);
    });
})();
</script>
</body>
</html>
