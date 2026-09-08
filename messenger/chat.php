<?php
/**
 * ============================================================================
 *  Odsco Messenger — صفحه گفتگو (خصوصی / گروه / پیام‌های ذخیره‌شده)
 * ----------------------------------------------------------------------------
 *  ?conversation=<uid>   گفتگوی خصوصی
 *  ?user=<uid>           ساخت/بازکردن گفتگو با یک کاربر
 *  ?group=<uid>          گروه
 *  ?saved=1              پیام‌های ذخیره‌شده
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

m_check_login();

$me    = m_current_user();
$myUid = $me['uid'];

$type = 'private';
$uid  = '';
$headName = '';
$headSub  = '';
$headPhoto = '';
$headOnline = false;
$group = null;
$error = '';

// ---------------------------------------------------------------------------
// تشخیص نوع چت
// ---------------------------------------------------------------------------
if (isset($_GET['saved'])) {
    $type = 'saved';
    $uid  = $myUid;
    $headName = 'پیام‌های ذخیره‌شده';
    $headSub  = 'فقط خودتان می‌بینید · روی این دستگاه ذخیره می‌شود';

} elseif (!empty($_GET['group'])) {
    $type = 'group';
    $uid  = (string)$_GET['group'];
    $group = Messenger::groupInfo($uid);
    if (!$group) {
        $error = 'گروه پیدا نشد';
    } elseif (!Messenger::isGroupMember($uid, $myUid)) {
        $error = 'شما عضو این گروه نیستید';
    } else {
        $headName = $group['name'];
        $headSub = fa_number($group['member_count']) . ' عضو';
        $headPhoto = $group['avatar'];
    }

} elseif (!empty($_GET['user'])) {
    $target = (string)$_GET['user'];
    if ($target === $myUid) {
        redirect('chat.php?saved=1');
    }
    $other = Users::find($target);
    if (!$other) {
        $error = 'کاربر پیدا نشد';
    } else {
        $uid = Messenger::conversationFor($myUid, $target);
        redirect('chat.php?conversation=' . $uid);
    }

} elseif (!empty($_GET['conversation'])) {
    $uid = (string)$_GET['conversation'];
    $info = Messenger::conversationInfo($uid, $myUid);
    if (!$info) {
        $error = 'گفتگو پیدا نشد';
    } else {
        $headName = $info['name'];
        $headPhoto = $info['photo'];
        $headOnline = $info['online'];
        $headSub = $info['online'] ? 'آنلاین' : m_presence_text($info['other_uid']);
    }
}

if ($error !== '') {
    m_set_flash('⚠️ ' . $error);
    redirect('index.php');
}

$chatKey = match ($type) { 'group' => 'g:' . $uid, 'saved' => 's:' . $uid, default => 'p:' . $uid };
$activeChatKey = $chatKey;

// علامت‌گذاری خوانده‌شده + ثبت حضور
Messenger::markRead($type, $uid, $myUid);
Messenger::touchPresence($myUid);

$canPost = true;
if ($type === 'group' && $group && $group['only_admins_post']) {
    $canPost = in_array(Messenger::groupRole($uid, $myUid), ['owner', 'admin'], true) || m_is_admin();
}

$otherUid = $type === 'private' ? (string)(Messenger::otherUid($uid, $myUid) ?? '') : '';

m_head(($headName ?: 'گفتگو') . ' · پیام‌رسان');
?>
<body class="chat-open">
<div class="tg-app" id="tgRoot"
     data-csrf="<?php echo e(csrf_token()); ?>"
     data-user="<?php echo e($myUid); ?>"
     data-chat-type="<?php echo e($type); ?>"
     data-chat-uid="<?php echo e($uid); ?>"
     data-chat-key="<?php echo e($chatKey); ?>"
     data-other="<?php echo e($otherUid); ?>"
     data-max-mb="<?php echo e((string)Settings::get('msg_max_file_mb', 32)); ?>"
     data-me='<?php echo json_encode($me, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'>

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="tg-main">
        <div class="tg-store-bar" id="tgStoreBar"><span class="dot"></span> در حال آماده‌سازی حافظه دستگاه…</div>

        <!-- هدر -->
        <header class="tg-head">
            <a href="index.php" class="icon-btn" title="بازگشت" aria-label="بازگشت">→</a>

            <?php if ($type === 'saved'): ?>
                <div class="tg-av sm saved">🔖</div>
            <?php elseif ($type === 'group'): ?>
                <a href="group-info.php?group=<?php echo e($uid); ?>" style="display:flex;align-items:center;gap:10px;flex:1;min-width:0">
                    <?php echo $headPhoto !== ''
                        ? '<img src="' . e(m_asset($headPhoto)) . '" class="tg-av sm group" alt="">'
                        : '<div class="tg-av sm group">👥</div>'; ?>
                    <span class="tg-head-info">
                        <span class="tg-head-name"><?php echo e($headName); ?></span>
                        <span class="tg-head-sub" id="tgHeadSub" data-default="<?php echo e($headSub); ?>"><?php echo e($headSub); ?></span>
                    </span>
                </a>
            <?php else: ?>
                <a href="profile.php?user=<?php echo e($otherUid); ?>" style="display:flex;align-items:center;gap:10px;flex:1;min-width:0">
                    <span class="tg-av-wrap">
                        <?php echo $headPhoto !== ''
                            ? '<img src="' . e(m_asset($headPhoto)) . '" class="tg-av sm" alt="">'
                            : '<div class="tg-av sm" style="background:' . e(m_avatar_color($headName)) . '">' . e(mb_substr($headName, 0, 1)) . '</div>'; ?>
                        <?php if ($headOnline): ?><span class="tg-online-dot"></span><?php endif; ?>
                    </span>
                    <span class="tg-head-info">
                        <span class="tg-head-name"><?php echo e($headName); ?></span>
                        <span class="tg-head-sub<?php echo $headOnline ? ' on' : ''; ?>" id="tgHeadSub" data-default="<?php echo e($headSub); ?>"><?php echo e($headSub); ?></span>
                    </span>
                </a>
            <?php endif; ?>

            <div class="tg-head-actions">
                <button type="button" class="icon-btn opt" id="tgSearchBtn" title="جستجو در گفتگو">🔍</button>
                <button type="button" class="icon-btn" data-menu="tgChatMenu" title="گزینه‌ها">⋮</button>
            </div>

            <div class="tg-menu" id="tgChatMenu">
                <?php if ($type !== 'saved'): ?>
                    <button type="button" data-cmd="pin_chat">📌 <span id="pinChatLabel">سنجاق کردن گفتگو</span></button>
                    <button type="button" data-cmd="mute_chat">🔕 <span id="muteChatLabel">بی‌صدا کردن</span></button>
                    <div class="sep"></div>
                <?php endif; ?>
                <button type="button" data-cmd="search">🔍 <span>جستجو در گفتگو</span></button>
                <button type="button" data-cmd="export">📤 <span>خروجی گرفتن (ذخیره روی دستگاه)</span></button>
                <div class="sep"></div>
                <button type="button" data-cmd="clear" class="danger">🧹 <span>پاک کردن تاریخچه از دید من</span></button>
                <?php if ($type === 'group'): ?>
                    <div class="sep"></div>
                    <a href="group-info.php?group=<?php echo e($uid); ?>">ℹ️ <span>اطلاعات گروه</span></a>
                <?php endif; ?>
            </div>
        </header>

        <!-- نوار پیام سنجاق‌شده -->
        <div class="tg-pinbar" id="tgPinBar" style="display:none"></div>

        <!-- پیام‌ها -->
        <div class="tg-msgs" id="tgMessages">
            <div class="tg-loading" id="tgLoading"><div class="tg-spinner"></div>در حال بارگذاری گفتگو…</div>
        </div>

        <button type="button" class="tg-jump" id="tgJump" title="رفتن به پایین">↓</button>

        <!-- ورودی -->
        <div class="tg-composer">
            <div class="tg-reply-preview" id="tgReplyPreview">
                <span style="color:var(--tg-accent);font-size:16px">↩</span>
                <span class="body"><span class="n"></span><span class="t"></span></span>
                <button type="button" class="icon-btn" id="tgReplyCancel" aria-label="لغو">✕</button>
            </div>

            <div class="tg-attach-preview" id="tgAttachPreview">
                <span class="thumb"></span>
                <span class="body" style="flex:1;min-width:0">
                    <span class="name" style="display:block;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
                    <span class="size" style="color:var(--tg-muted)"></span>
                </span>
                <button type="button" class="icon-btn" id="tgAttachCancel" aria-label="حذف ضمیمه">✕</button>
            </div>

            <div id="tgUploadBar" style="display:none;margin-bottom:8px">
                <div style="height:5px;background:var(--tg-bg);border-radius:3px;overflow:hidden">
                    <div class="bar" style="height:100%;width:0;background:var(--tg-accent);transition:width .2s"></div>
                </div>
            </div>

            <?php if ($canPost): ?>
            <div class="tg-input-row">
                <input type="file" id="tgFile" style="display:none">
                <button type="button" class="icon-btn" id="tgAttach" title="پیوست فایل" aria-label="پیوست فایل">📎</button>
                <textarea id="tgInput" class="tg-input" rows="1" placeholder="پیام خود را بنویسید…" autocomplete="off"></textarea>
                <button type="button" class="tg-send" id="tgSend" title="ارسال" aria-label="ارسال">➤</button>
            </div>
            <?php else: ?>
            <div class="tg-composer-hint" style="display:block">
                🔒 فقط مدیران این گروه می‌توانند پیام ارسال کنند.
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php m_foot(); ?>
<script src="assets/chat.js"></script>
<script>
(function () {
    'use strict';

    const root = document.getElementById('tgRoot');

    TG.boot({}).then(function () {
        try { TG.me = JSON.parse(root.dataset.me || 'null'); } catch (e) {}

        // نام کاربران برای نمایش «در حال نوشتن»
        if (window.__NAMES && root.dataset.other) {
            // نام طرف مقابل از هدر
            const nm = document.querySelector('.tg-head-name');
            if (nm) window.__NAMES[root.dataset.other] = nm.textContent.trim();
            window.__names = window.__NAMES;
        }

        return Chat.init({
            type: root.dataset.chatType,
            uid: root.dataset.chatUid,
            chat_key: root.dataset.chatKey,
            other_uid: root.dataset.other
        });
    }).then(function () {
        // وضعیت اولیه سنجاق / بی‌صدا
        const st = Chat.state || {};
        const pinLabel = document.getElementById('pinChatLabel');
        const muteLabel = document.getElementById('muteChatLabel');
        if (pinLabel) pinLabel.textContent = st.pinned ? 'برداشتن سنجاق گفتگو' : 'سنجاق کردن گفتگو';
        if (muteLabel) muteLabel.textContent = st.muted ? 'باصدا کردن' : 'بی‌صدا کردن';

        // به‌روزرسانی وضعیت آنلاین طرف مقابل
        if (root.dataset.other) {
            setInterval(function () {
                if (document.hidden) return;
                TG.get('ping').then(function (res) {
                    const on = (res.online || []).indexOf(root.dataset.other) >= 0;
                    const sub = document.getElementById('tgHeadSub');
                    if (!sub || (document.querySelector('.tg-head-sub.on') && sub.classList.contains('on') && sub.textContent.indexOf('نوشتن') >= 0)) return;
                    sub.textContent = on ? 'آنلاین' : (sub.dataset.default || '');
                    sub.classList.toggle('on', on);
                    const dot = document.querySelector('.tg-head .tg-online-dot');
                    if (on && !dot) {
                        const wrap = document.querySelector('.tg-head .tg-av-wrap');
                        if (wrap) wrap.insertAdjacentHTML('beforeend', '<span class="tg-online-dot"></span>');
                    } else if (!on && dot) {
                        dot.remove();
                    }
                }).catch(function () {});
            }, 10000);
        }
    }).catch(function (e) {
        TG.toast('⚠️ ' + e.message);
    });

    // ---- منوی گفتگو ----
    const menuBtn = document.querySelector('[data-menu="tgChatMenu"]');
    if (menuBtn) {
        menuBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            document.getElementById('tgChatMenu').classList.toggle('on');
        });
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.tg-menu') && !e.target.closest('[data-menu]')) {
            document.querySelectorAll('.tg-menu.on').forEach(function (m) { m.classList.remove('on'); });
        }
    });

    document.querySelectorAll('[data-cmd]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tg-menu.on').forEach(function (m) { m.classList.remove('on'); });
            const cmd = btn.dataset.cmd;

            if (cmd === 'search') { Chat.openSearch(); return; }

            if (cmd === 'pin_chat') {
                const next = !(Chat.state && Chat.state.pinned);
                TG.api('chat_state', { chat_key: root.dataset.chatKey, pinned: next ? '1' : '0' }).then(function () {
                    Chat.state.pinned = next;
                    document.getElementById('pinChatLabel').textContent = next ? 'برداشتن سنجاق گفتگو' : 'سنجاق کردن گفتگو';
                    TG.toast(next ? '📌 گفتگو سنجاق شد' : 'سنجاق برداشته شد');
                }).catch(function (e) { TG.toast('⚠️ ' + e.message); });
                return;
            }

            if (cmd === 'mute_chat') {
                const next = !(Chat.state && Chat.state.muted);
                TG.api('chat_state', { chat_key: root.dataset.chatKey, muted: next ? '1' : '0' }).then(function () {
                    Chat.state.muted = next;
                    document.getElementById('muteChatLabel').textContent = next ? 'باصدا کردن' : 'بی‌صدا کردن';
                    TG.toast(next ? '🔕 گفتگو بی‌صدا شد' : '🔊 گفتگو باصدا شد');
                }).catch(function (e) { TG.toast('⚠️ ' + e.message); });
                return;
            }

            if (cmd === 'clear') {
                TG.confirm('پاک کردن تاریخچه',
                    'تاریخچه این گفتگو فقط از دید شما و از حافظه این دستگاه پاک می‌شود. ادامه می‌دهید؟',
                    function () {
                        LocalStore.clearChat(root.dataset.chatKey).then(function () {
                            return TG.api('clear_history', { chat_key: root.dataset.chatKey });
                        }).then(function () {
                            TG.toast('🧹 تاریخچه پاک شد');
                            setTimeout(function () { location.reload(); }, 900);
                        }).catch(function (e) { TG.toast('⚠️ ' + e.message); });
                    }, 'پاک کن', true);
                return;
            }

            if (cmd === 'export') {
                exportChat();
                return;
            }
        });
    });

    /** خروجی JSON/متن از تاریخچه — مستقیم روی دستگاه کاربر ذخیره می‌شود */
    function exportChat() {
        LocalStore.loadMessages(root.dataset.chatKey, 100000).then(function (list) {
            if (!list.length) { TG.toast('چیزی برای خروجی گرفتن نیست'); return; }
            const name = (document.querySelector('.tg-head-name') || {}).textContent || 'chat';
            let text = 'گفتگو: ' + name + '\n';
            text += 'خروجی گرفته‌شده در: ' + new Date().toLocaleString('fa-IR') + '\n';
            text += 'تعداد پیام: ' + list.length + '\n' + '─'.repeat(50) + '\n\n';
            list.forEach(function (m) {
                if (m.type === 'system') { text += '— ' + (m.content || '') + '\n'; return; }
                text += '[' + (m.created_at || '') + '] ' + (m.sender_name || '') + ': '
                      + (m.content || (m.type === 'image' ? '📷 تصویر' : m.type === 'file' ? '📎 ' + (m.file_name || 'فایل') : m.type))
                      + '\n';
            });

            const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'odsco-chat-' + name.replace(/[^\p{L}\p{N}]+/gu, '-') + '.txt';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function () { URL.revokeObjectURL(url); }, 3000);
            TG.toast('✅ خروجی روی دستگاه شما ذخیره شد');
        });
    }
})();
</script>
</body>
</html>
