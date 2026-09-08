<?php
/**
 * ============================================================================
 *  Odsco Messenger — صفحه اصلی (لیست گفتگوها)
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

m_check_login();

$me = m_current_user();
$activeChatKey = '';

m_head('پیام‌رسان');
?>
<body class="messenger-list">
<div class="tg-app" id="tgRoot"
     data-csrf="<?php echo e(csrf_token()); ?>"
     data-user="<?php echo e($me['uid']); ?>"
     data-me='<?php echo json_encode($me, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'
     data-max-mb="<?php echo e((string)Settings::get('msg_max_file_mb', 32)); ?>">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="tg-main">
        <div class="tg-store-bar" id="tgStoreBar"><span class="dot"></span> در حال آماده‌سازی حافظه دستگاه…</div>

        <div class="tg-placeholder">
            <div class="ico">💬</div>
            <h2>پیام‌رسان <?php echo e((string)(Settings::get('site_name') ?: '')); ?></h2>
            <p>یک گفتگو را از فهرست انتخاب کنید، یا با دکمه ✏️ گفتگوی جدید بسازید.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:10px">
                <button type="button" class="tg-btn" id="phNew">✏️ گفتگوی جدید</button>
                <button type="button" class="tg-btn ghost" id="phGroup">👥 گروه جدید</button>
                <a class="tg-btn ghost" href="chat.php?saved=1">🔖 پیام‌های ذخیره‌شده</a>
            </div>
            <div style="margin-top:26px;font-size:12px;color:var(--tg-muted);max-width:420px;line-height:2">
                🔒 <b>ذخیره‌سازی روی دستگاه شما:</b>
                تاریخچه و فایل‌ها در حافظه همین گوشی/کامپیوتر نگه داشته می‌شوند و آفلاین هم قابل مشاهده‌اند.
            </div>
        </div>
    </main>
</div>

<?php m_foot(); ?>

<script>
(function () {
    'use strict';

    TG.boot({}).then(function () {
        const root = document.getElementById('tgRoot');
        try { TG.me = JSON.parse(root.dataset.me || 'null'); } catch (e) {}

        // ---- جستجو در لیست ----
        const search = document.getElementById('sbSearch');
        if (search) {
            search.addEventListener('input', function () {
                const q = search.value.trim().toLowerCase();
                document.querySelectorAll('#sbChats .tg-chat').forEach(function (el) {
                    el.style.display = (el.dataset.name || '').toLowerCase().indexOf(q) >= 0 ? '' : 'none';
                });
            });
        }

        // ---- تب‌ها ----
        document.querySelectorAll('.tg-tabs [data-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('.tg-tabs [data-tab]').forEach(function (b) { b.classList.remove('on'); });
                btn.classList.add('on');
                const t = btn.dataset.tab;
                document.querySelectorAll('#sbChats .tg-chat').forEach(function (el) {
                    el.style.display = (t === 'all' || el.dataset.type === t) ? '' : 'none';
                });
            });
        });

        // ---- منو ----
        const menuBtn = document.querySelector('[data-menu]');
        if (menuBtn) {
            menuBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                document.getElementById(menuBtn.dataset.menu).classList.toggle('on');
            });
        }
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.tg-menu') && !e.target.closest('[data-menu]')) {
                document.querySelectorAll('.tg-menu.on').forEach(function (m) { m.classList.remove('on'); });
            }
        });

        // ---- گفتگوی جدید ----
        function newChatModal() {
            TG.get('contacts').then(function (res) {
                const list = res.contacts || [];
                let html = '<div class="tg-field"><label>جستجوی کاربر</label><input type="text" id="ncSearch" placeholder="نام یا سمت…"></div>';
                html += '<div class="tg-pick" id="ncList">';
                list.forEach(function (u) {
                    html += '<a class="tg-pick-item" href="chat.php?user=' + encodeURIComponent(u.uid) + '" data-n="' + TG.esc(u.name + ' ' + (u.job_title || '')) + '">'
                        + '<span class="tg-av sm">' + TG.esc((u.name || '؟').slice(0, 1)) + '</span>'
                        + '<span class="nm"><span class="n">' + TG.esc(u.name) + (u.online ? ' <span style="color:#4dcd5e">●</span>' : '') + '</span>'
                        + '<span class="s">' + TG.esc(u.role) + (u.job_title ? ' · ' + TG.esc(u.job_title) : '') + '</span></span>'
                        + '<span class="s">' + (u.online ? 'آنلاین' : 'آفلاین') + '</span></a>';
                });
                html += '</div>';
                if (!list.length) html += '<p style="text-align:center;color:#8698ab;padding:20px">کاربر دیگری برای گفتگو وجود ندارد</p>';

                const box = TG.modal('گفتگوی جدید', html, '<button type="button" class="tg-btn ghost" data-close>بستن</button>', true);
                const s = box.querySelector('#ncSearch');
                if (s) {
                    s.addEventListener('input', function () {
                        const q = s.value.trim().toLowerCase();
                        box.querySelectorAll('#ncList .tg-pick-item').forEach(function (el) {
                            el.style.display = (el.dataset.n || '').toLowerCase().indexOf(q) >= 0 ? '' : 'none';
                        });
                    });
                    setTimeout(function () { s.focus(); }, 60);
                }
            }).catch(function (e) { TG.toast('⚠️ ' + e.message); });
        }

        const fab = document.getElementById('sbNew');
        if (fab) fab.addEventListener('click', newChatModal);
        const phNew = document.getElementById('phNew');
        if (phNew) phNew.addEventListener('click', newChatModal);
        const phGroup = document.getElementById('phGroup');
        if (phGroup) phGroup.addEventListener('click', function () { location.href = 'groups.php?new=1'; });

        // ---- همگام‌سازی دوره‌ای لیست ----
        setInterval(function () {
            if (document.hidden) return;
            TG.get('chats').then(function (res) {
                (res.chats || []).forEach(function (c) {
                    const el = document.querySelector('.tg-chat[data-key="' + c.chat_key + '"]');
                    if (!el) return;
                    const badge = el.querySelector('.tg-badge');
                    const tick = el.querySelector('.tg-tick');
                    if (c.unread_count > 0) {
                        if (badge) { badge.textContent = TG.faDigits(c.unread_count); badge.style.display = ''; }
                        else if (tick) { tick.outerHTML = '<span class="tg-badge">' + TG.faDigits(c.unread_count) + '</span>'; }
                    } else if (badge) {
                        badge.outerHTML = c.last_is_mine ? '<span class="tg-tick read">✓✓</span>' : '<span></span>';
                    }
                    const prev = el.querySelector('.tg-chat-prev');
                    if (prev && c.last_message) prev.textContent = (c.last_is_mine ? 'شما: ' : '') + c.last_message;
                    const time = el.querySelector('.tg-chat-time');
                    if (time && c.last_time_fa) time.textContent = c.last_time_fa;
                });
            }).catch(function () { /* ignore */ });
        }, 5000);
    });
})();
</script>
</body>
</html>
