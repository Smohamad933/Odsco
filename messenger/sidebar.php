<?php
/**
 * ============================================================================
 *  Odsco Messenger — سایدبار مشترک (لیست چت‌ها)
 * ----------------------------------------------------------------------------
 *  متغیرهای لازم:  $activeChatKey (اختیاری)
 * ============================================================================
 */

declare(strict_types=1);

if (!function_exists('m_current_user')) {
    require_once __DIR__ . '/config.php';
}

m_check_login();

$sbMe      = m_current_user();
$sbList    = Messenger::chatList($sbMe['uid']);
$sbChats   = $sbList['chats'];
$sbUnread  = $sbList['total_unread'];
$sbNotices = Notifications::unreadCount($sbMe['uid']);
$sbActive  = $activeChatKey ?? '';

// دسته‌بندی چت‌ها برای تب‌ها
$sbPrivate = array_values(array_filter($sbChats, fn($c) => $c['type'] === 'private'));
$sbGroups  = array_values(array_filter($sbChats, fn($c) => $c['type'] === 'group'));
$sbSaved   = array_values(array_filter($sbChats, fn($c) => $c['type'] === 'saved'));
?>
<aside class="tg-sidebar">
    <div class="tg-sb-head">
        <button type="button" class="icon-btn" data-menu="tgMenu" aria-label="منو">☰</button>
        <div class="tg-search">
            <span style="color:var(--tg-muted);font-size:14px">🔍</span>
            <input type="search" id="sbSearch" placeholder="جستجو در گفتگوها…" autocomplete="off">
        </div>
        <a href="notices.php" class="icon-btn" title="اعلان‌ها" style="position:relative">
            🔔
            <?php if ($sbNotices > 0): ?>
                <span class="tg-badge" style="position:absolute;top:4px;left:2px;min-width:17px;height:17px;font-size:10px"><?php echo fa_number($sbNotices); ?></span>
            <?php endif; ?>
        </a>
    </div>

    <div class="tg-tabs" role="tablist">
        <button type="button" class="on" data-tab="all">همه <?php if (count($sbChats)): ?><span style="opacity:.65">(<?php echo fa_number(count($sbChats)); ?>)</span><?php endif; ?></button>
        <button type="button" data-tab="private">خصوصی (<?php echo fa_number(count($sbPrivate)); ?>)</button>
        <button type="button" data-tab="group">گروه‌ها (<?php echo fa_number(count($sbGroups)); ?>)</button>
    </div>

    <div class="tg-chats" id="sbChats">
        <?php if (!$sbChats): ?>
            <div class="tg-empty">
                <div class="big">💬</div>
                <p>هنوز گفتگویی ندارید</p>
                <p style="font-size:12px;margin-top:6px">روی دکمه ✏️ بزنید و اولین گفتگو را شروع کنید</p>
            </div>
        <?php else: ?>
            <?php foreach ($sbChats as $c):
                $isOn = ($c['chat_key'] === $sbActive);
                $initial = mb_substr($c['name'], 0, 1);
                $avClass = $c['type'] === 'group' ? 'group' : ($c['type'] === 'saved' ? 'saved' : '');
            ?>
            <a href="<?php echo e($c['url']); ?>"
               class="tg-chat<?php echo $isOn ? ' on' : ''; ?><?php echo !empty($c['pinned']) ? ' pinned' : ''; ?>"
               data-type="<?php echo e($c['type']); ?>"
               data-name="<?php echo e($c['name']); ?>"
               data-key="<?php echo e($c['chat_key']); ?>">
                <span class="tg-av-wrap">
                    <?php if (!empty($c['photo'])): ?>
                        <img src="<?php echo e(m_asset((string)$c['photo'])); ?>" class="tg-av <?php echo e($avClass); ?>" alt=""
                             onerror="this.outerHTML='<div class=&quot;tg-av <?php echo e($avClass); ?>&quot;><?php echo e($initial); ?></div>'">
                    <?php elseif ($c['type'] === 'saved'): ?>
                        <div class="tg-av saved">🔖</div>
                    <?php elseif ($c['type'] === 'group'): ?>
                        <div class="tg-av group">👥</div>
                    <?php else: ?>
                        <div class="tg-av" style="background:<?php echo e(m_avatar_color((string)$c['name'])); ?>"><?php echo e($initial); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($c['online'])): ?><span class="tg-online-dot"></span><?php endif; ?>
                </span>

                <span class="tg-chat-body">
                    <span class="tg-chat-top">
                        <span class="tg-chat-name"><?php echo e($c['name']); ?></span>
                        <?php if (!empty($c['muted'])): ?><span class="tg-pin-ico" title="بی‌صدا">🔕</span><?php endif; ?>
                        <?php if (!empty($c['pinned'])): ?><span class="tg-pin-ico" title="سنجاق‌شده">📌</span><?php endif; ?>
                        <span class="tg-chat-time"><?php echo e(chat_time_fa($c['last_time'] ?: null)); ?></span>
                    </span>
                    <span class="tg-chat-bot">
                        <span class="tg-chat-prev"><?php
                            $prev = (string)$c['last_message'];
                            echo e($prev !== '' ? ($c['last_is_mine'] ? 'شما: ' . $prev : $prev) : 'گفتگو را شروع کنید');
                        ?></span>
                        <?php if ($c['unread_count'] > 0): ?>
                            <span class="tg-badge<?php echo !empty($c['muted']) ? ' muted' : ''; ?>"><?php echo fa_number($c['unread_count']); ?></span>
                        <?php elseif ($c['last_is_mine'] && $c['last_uid'] !== ''): ?>
                            <span class="tg-tick read">✓✓</span>
                        <?php endif; ?>
                    </span>
                </span>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <button type="button" class="tg-fab" id="sbNew" title="گفتگوی جدید">✏️</button>

    <!-- منوی اصلی -->
    <div class="tg-menu" id="tgMenu">
        <div style="display:flex;align-items:center;gap:10px;padding:10px 12px 12px;border-bottom:1px solid var(--tg-line);margin-bottom:6px">
            <?php echo m_avatar($sbMe['name'], $sbMe['photo'], 'sm'); ?>
            <div style="min-width:0">
                <div style="font-size:13.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo e($sbMe['name']); ?></div>
                <div style="font-size:11px;color:var(--tg-muted)"><?php echo e(Users::roleLabel($sbMe['role'])); ?></div>
            </div>
        </div>
        <a href="index.php">💬 <span>همه گفتگوها</span></a>
        <a href="groups.php">👥 <span>گروه‌ها</span></a>
        <a href="chat.php?saved=1">🔖 <span>پیام‌های ذخیره‌شده</span></a>
        <a href="notices.php">🔔 <span>اعلان‌ها</span><?php if ($sbNotices > 0): ?> <span class="tg-badge" style="margin-right:auto"><?php echo fa_number($sbNotices); ?></span><?php endif; ?></a>
        <a href="attendance.php">🕐 <span>حضور و غیاب من</span></a>
        <a href="settings.php">⚙️ <span>تنظیمات و حریم خصوصی</span></a>
        <?php if (m_is_manager()): ?>
            <div class="sep"></div>
            <a href="<?php echo e(m_admin_url('dashboard.php')); ?>" target="_blank">🏗️ <span>پنل مدیریت</span></a>
        <?php endif; ?>
        <div class="sep"></div>
        <a href="../index.php" target="_blank">🌐 <span>مشاهده سایت</span></a>
        <a href="logout.php" class="danger">🚪 <span>خروج</span></a>
    </div>
</aside>

<script>
window.__NAMES = <?php echo json_encode(
    array_reduce(Messenger::contacts($sbMe['uid']), function (array $carry, array $u): array {
        $carry[$u['uid']] = $u['full_name'];
        return $carry;
    }, []),
    JSON_UNESCAPED_UNICODE
); ?>;
window.__names = window.__NAMES;
</script>
