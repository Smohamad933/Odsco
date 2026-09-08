<?php
/**
 * ============================================================================
 *  Odsco Messenger — API
 * ----------------------------------------------------------------------------
 *  همه درخواست‌ها: messenger/api.php?a=<action>
 *  پاسخ: JSON
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!is_logged_messenger()) {
    json_out(['success' => false, 'message' => 'نشست شما منقضی شده است', 'auth' => false], 401);
}

$me = m_current_user();
$myUid = $me['uid'];

$action = (string)($_REQUEST['a'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// محافظت CSRF برای درخواست‌های تغییردهنده
if ($isPost && $action !== '' && !in_array($action, ['ping', 'typing', 'mark_read'], true)) {
    csrf_guard(true);
}

Messenger::touchPresence($myUid);

try {
    switch ($action) {

        // =================================================================
        //心跳 / حضور
        // =================================================================
        case 'ping':
            $result = [
                'online' => Messenger::onlineUsers(),
                'unread' => Messenger::unreadTotal($myUid),
                'notices' => Notifications::unreadCount($myUid),
                'server_time' => date('Y-m-d H:i:s'),
            ];
            break;

        // =================================================================
        // لیست چت‌ها
        // =================================================================
        case 'chats':
            $list = Messenger::chatList($myUid);
            foreach ($list['chats'] as &$c) {
                $c['last_time_fa'] = chat_time_fa($c['last_time'] ?: null);
            }
            unset($c);
            $result = $list + ['contacts_count' => count(Messenger::contacts($myUid))];
            break;

        case 'contacts':
            $contacts = Messenger::contacts($myUid);
            $onlineMap = array_flip(Messenger::onlineUsers());
            $result = ['contacts' => array_map(function (array $u) use ($onlineMap): array {
                return [
                    'uid' => $u['uid'], 'name' => $u['full_name'], 'photo' => $u['photo'],
                    'role' => Users::roleLabel($u['role']), 'job_title' => $u['job_title'],
                    'online' => isset($onlineMap[$u['uid']]),
                    'last_seen' => Messenger::lastSeen($u['uid']),
                ];
            }, $contacts)];
            break;

        // =================================================================
        // پیام‌ها
        // =================================================================
        case 'messages':
            $type = (string)($_GET['chat_type'] ?? 'private');
            $uid = (string)($_GET['chat_uid'] ?? '');
            $limit = (int)($_GET['limit'] ?? 50);
            $before = (int)($_GET['before'] ?? 0);
            $after = (int)($_GET['after'] ?? 0);

            if (!in_array($type, ['private', 'group', 'saved'], true)) {
                json_out(['success' => false, 'message' => 'نوع چت نامعتبر است'], 400);
            }
            if ($type === 'group' && !Messenger::isGroupMember($uid, $myUid)) {
                json_out(['success' => false, 'message' => 'شما عضو این گروه نیستید'], 403);
            }
            if ($type === 'private') {
                $other = Messenger::otherUid($uid, $myUid);
                if (!$other) json_out(['success' => false, 'message' => 'گفتگو پیدا نشد'], 404);
            }
            if ($type === 'saved') $uid = $myUid;

            $msgs = Messenger::messages($type, $uid, $myUid, $limit, $before, $after);

            // واکنش‌ها و پیام مورد پاسخ
            $uids = array_column($msgs, 'uid');
            $reactions = Messenger::reactionsFor($uids);
            foreach ($msgs as &$m) {
                $m['reactions'] = $reactions[$m['uid']] ?? [];
                $m['reply_message'] = $m['reply_to'] ? Messenger::findMessage($m['reply_to']) : null;
            }
            unset($m);

            $chatKey = $type === 'group' ? 'g:' . $uid : ($type === 'saved' ? 's:' . $myUid : 'p:' . $uid);
            $state = Messenger::chatState($chatKey, $myUid);

            $result = [
                'messages' => $msgs,
                'chat_key' => $chatKey,
                'pinned' => Messenger::pinnedMessages($type, $uid, $myUid),
                'state' => [
                    'pinned' => (bool)$state['is_pinned'],
                    'muted' => (bool)$state['is_muted'],
                    'draft' => (string)($state['draft'] ?? ''),
                    'last_read_id' => (int)$state['last_read_id'],
                ],
                'typing' => Messenger::typingUsers($chatKey, $myUid),
                'has_more' => count($msgs) >= $limit,
                'purge_days' => (int)Settings::get('msg_retention_days', 0),
            ];
            break;

        case 'send':
            $type = (string)($_POST['chat_type'] ?? 'private');
            $uid = (string)($_POST['chat_uid'] ?? '');
            $content = (string)($_POST['content'] ?? '');
            $replyTo = (string)($_POST['reply_to'] ?? '');
            $forward = (string)($_POST['forward_uid'] ?? '');

            if ($type === 'group' && !Messenger::isGroupMember($uid, $myUid)) {
                json_out(['success' => false, 'message' => 'شما عضو این گروه نیستید'], 403);
            }
            if ($type === 'private' && !Messenger::otherUid($uid, $myUid)) {
                json_out(['success' => false, 'message' => 'گفتگو پیدا نشد'], 404);
            }
            if (trim($content) === '' && $forward === '') {
                json_out(['success' => false, 'message' => 'متن پیام خالی است'], 400);
            }
            if (mb_strlen($content) > 8000) {
                json_out(['success' => false, 'message' => 'پیام خیلی طولانی است (حداکثر ۸۰۰۰ کاراکتر)'], 400);
            }
            if ($type === 'saved') $uid = $myUid;

            $msg = Messenger::send([
                'chat_type' => $type,
                'conversation_uid' => $type === 'group' ? '' : $uid,
                'group_uid' => $type === 'group' ? $uid : '',
                'sender_uid' => $myUid,
                'type' => 'text',
                'content' => $content,
                'reply_to' => $replyTo,
                'forward_uid' => $forward,
            ]);

            $result = ['message' => $msg + ['reactions' => [], 'reply_message' => $replyTo ? Messenger::findMessage($replyTo) : null]];
            break;

        case 'upload':
            if (empty($_FILES['file'])) json_out(['success' => false, 'message' => 'فایلی ارسال نشد'], 400);

            $type = (string)($_POST['chat_type'] ?? 'private');
            $uid = (string)($_POST['chat_uid'] ?? '');
            $caption = (string)($_POST['content'] ?? '');
            $replyTo = (string)($_POST['reply_to'] ?? '');

            if ($type === 'group' && !Messenger::isGroupMember($uid, $myUid)) {
                json_out(['success' => false, 'message' => 'شما عضو این گروه نیستید'], 403);
            }
            if ($type === 'saved') $uid = $myUid;

            $up = Messenger::uploadFile($_FILES['file']);
            if (!$up['success']) json_out(['success' => false, 'message' => $up['message']], 400);

            $ext = strtolower((string)pathinfo((string)$up['path'], PATHINFO_EXTENSION));
            $kind = match (true) {
                in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','avif','heic'], true) => 'image',
                in_array($ext, ['mp4','webm','mov','mkv','avi'], true) => 'video',
                in_array($ext, ['mp3','wav','ogg','m4a'], true) => 'voice',
                default => 'file',
            };

            $msg = Messenger::send([
                'chat_type' => $type,
                'conversation_uid' => $type === 'group' ? '' : $uid,
                'group_uid' => $type === 'group' ? $uid : '',
                'sender_uid' => $myUid,
                'type' => $kind,
                'content' => $caption,
                'file_path' => $up['path'],
                'file_name' => $up['name'],
                'file_size' => $up['size'],
                'mime' => $up['mime'],
                'reply_to' => $replyTo,
                'meta' => ['kind' => $kind, 'icon' => file_icon((string)$up['name'])],
            ]);

            $result = ['message' => $msg + ['reactions' => [], 'reply_message' => null], 'max_mb' => Messenger::maxUploadBytes() / 1048576];
            break;

        case 'edit':
            $uid = (string)($_POST['uid'] ?? '');
            $content = (string)($_POST['content'] ?? '');
            if (!Messenger::editMessage($uid, $myUid, $content)) {
                json_out(['success' => false, 'message' => 'فقط پیام متنی خودتان قابل ویرایش است'], 403);
            }
            $result = ['message' => Messenger::findMessage($uid)];
            break;

        case 'delete':
            $uid = (string)($_POST['uid'] ?? '');
            $forAll = ($_POST['for_everyone'] ?? '') === '1';
            if (!Messenger::deleteMessage($uid, $myUid, $forAll)) {
                json_out(['success' => false, 'message' => 'امکان حذف این پیام را ندارید'], 403);
            }
            $result = ['uid' => $uid, 'for_everyone' => $forAll];
            break;

        case 'pin_message':
            $uid = (string)($_POST['uid'] ?? '');
            $on = Messenger::togglePinMessage($uid, $myUid);
            $result = ['uid' => $uid, 'pinned' => $on];
            break;

        case 'react':
            $uid = (string)($_POST['uid'] ?? '');
            $emoji = (string)($_POST['emoji'] ?? '');
            Messenger::react($uid, $myUid, $emoji);
            $result = ['uid' => $uid, 'reactions' => Messenger::reactions($uid, $myUid)];
            break;

        case 'mark_read':
            $type = (string)($_POST['chat_type'] ?? 'private');
            $uid = (string)($_POST['chat_uid'] ?? '');
            if ($type === 'saved') $uid = $myUid;
            Messenger::markRead($type, $uid, $myUid);
            $result = ['unread' => Messenger::unreadTotal($myUid)];
            break;

        case 'typing':
            $chatKey = (string)($_POST['chat_key'] ?? '');
            if ($chatKey !== '') Messenger::setTyping($chatKey, $myUid);
            $result = ['typing' => Messenger::typingUsers($chatKey, $myUid)];
            break;

        case 'chat_state':
            $chatKey = (string)($_POST['chat_key'] ?? '');
            $patch = [];
            if (isset($_POST['pinned'])) $patch['is_pinned'] = $_POST['pinned'] === '1';
            if (isset($_POST['muted']))  $patch['is_muted']  = $_POST['muted'] === '1';
            if (isset($_POST['draft']))  $patch['draft']     = (string)$_POST['draft'];
            Messenger::setChatState($chatKey, $myUid, $patch);
            $result = Messenger::chatState($chatKey, $myUid);
            break;

        case 'clear_history':
            $chatKey = (string)($_POST['chat_key'] ?? '');
            $n = Messenger::clearHistory($chatKey, $myUid);
            $result = ['cleared' => $n];
            break;

        case 'search':
            $q = (string)($_GET['q'] ?? '');
            $result = ['results' => Messenger::search($myUid, $q, 40)];
            break;

        // =================================================================
        // گفتگوی جدید / گروه
        // =================================================================
        case 'conversation':
            $other = (string)($_POST['user_uid'] ?? '');
            if ($other === '' || $other === $myUid) json_out(['success' => false, 'message' => 'کاربر نامعتبر'], 400);
            if (!Users::find($other)) json_out(['success' => false, 'message' => 'کاربر پیدا نشد'], 404);
            $conv = Messenger::conversationFor($myUid, $other);
            $result = ['conversation' => $conv, 'redirect' => 'chat.php?conversation=' . $conv];
            break;

        case 'group_create':
            $name = trim((string)($_POST['name'] ?? ''));
            $members = (array)($_POST['members'] ?? []);
            $about = (string)($_POST['about'] ?? '');
            if ($name === '') json_out(['success' => false, 'message' => 'نام گروه را وارد کنید'], 400);
            $members = array_values(array_filter(array_map('strval', $members)));

            $groupUid = Messenger::createGroup($name, $members, $myUid, ['about' => $about]);
            Messenger::send([
                'chat_type' => 'group', 'group_uid' => $groupUid, 'sender_uid' => $myUid,
                'type' => 'system', 'is_system' => true,
                'content' => '👥 گروه «' . $name . '» توسط ' . $me['name'] . ' ساخته شد',
            ]);
            ActivityLog::add('messenger_group_create', 'گروه «' . $name . '» ساخته شد');
            $result = ['group' => $groupUid, 'redirect' => 'group-chat.php?group=' . $groupUid];
            break;

        case 'group_info':
            $uid = (string)($_GET['group'] ?? '');
            $g = Messenger::groupInfo($uid);
            if (!$g) json_out(['success' => false, 'message' => 'گروه پیدا نشد'], 404);
            $result = ['group' => $g];
            break;

        case 'group_update':
            $uid = (string)($_POST['group'] ?? '');
            $g = Messenger::groupInfo($uid);
            if (!$g) json_out(['success' => false, 'message' => 'گروه پیدا نشد'], 404);
            if (Messenger::groupRole($uid, $myUid) === '' && !m_is_admin()) {
                json_out(['success' => false, 'message' => 'فقط مدیر گروه می‌تواند تغییر دهد'], 403);
            }
            Messenger::updateGroup($uid, [
                'name' => trim((string)($_POST['name'] ?? $g['name'])),
                'about' => (string)($_POST['about'] ?? $g['about']),
            ]);
            $result = ['group' => Messenger::groupInfo($uid)];
            break;

        case 'group_members_add':
            $uid = (string)($_POST['group'] ?? '');
            $members = array_values(array_filter(array_map('strval', (array)($_POST['members'] ?? []))));
            if (Messenger::groupRole($uid, $myUid) === '' && !m_is_admin()) {
                json_out(['success' => false, 'message' => 'فقط مدیر گروه می‌تواند عضو اضافه کند'], 403);
            }
            $n = Messenger::addGroupMembers($uid, $members);
            if ($n > 0) {
                Messenger::send([
                    'chat_type' => 'group', 'group_uid' => $uid, 'sender_uid' => $myUid,
                    'type' => 'system', 'is_system' => true,
                    'content' => '➕ ' . fa_number($n) . ' عضو جدید به گروه اضافه شد',
                ]);
            }
            $result = ['added' => $n, 'group' => Messenger::groupInfo($uid)];
            break;

        case 'group_member_remove':
            $uid = (string)($_POST['group'] ?? '');
            $target = (string)($_POST['user_uid'] ?? '');
            if (Messenger::groupRole($uid, $myUid) === '' && !m_is_admin()) {
                json_out(['success' => false, 'message' => 'دسترسی ندارید'], 403);
            }
            Messenger::removeGroupMember($uid, $target);
            $result = ['group' => Messenger::groupInfo($uid)];
            break;

        case 'group_leave':
            $uid = (string)($_POST['group'] ?? '');
            Messenger::leaveGroup($uid, $myUid);
            $result = ['redirect' => 'index.php'];
            break;

        case 'group_delete':
            $uid = (string)($_POST['group'] ?? '');
            $g = Messenger::groupInfo($uid);
            if (!$g) json_out(['success' => false, 'message' => 'گروه پیدا نشد'], 404);
            if ($g['creator_uid'] !== $myUid && !m_is_admin()) {
                json_out(['success' => false, 'message' => 'فقط سازنده گروه می‌تواند حذف کند'], 403);
            }
            Messenger::deleteGroup($uid);
            ActivityLog::add('messenger_group_delete', 'گروه «' . $g['name'] . '» حذف شد');
            $result = ['redirect' => 'index.php'];
            break;

        case 'group_role':
            $uid = (string)($_POST['group'] ?? '');
            $target = (string)($_POST['user_uid'] ?? '');
            $role = (string)($_POST['role'] ?? 'member');
            $g = Messenger::groupInfo($uid);
            if (!$g || ($g['creator_uid'] !== $myUid && !m_is_admin())) {
                json_out(['success' => false, 'message' => 'دسترسی ندارید'], 403);
            }
            if ($g['creator_uid'] === $target) json_out(['success' => false, 'message' => 'سازنده گروه همیشه مدیر است'], 400);
            Messenger::setGroupMemberRole($uid, $target, in_array($role, ['member','admin'], true) ? $role : 'member');
            $result = ['group' => Messenger::groupInfo($uid)];
            break;

        // =================================================================
        // اعلان‌ها
        // =================================================================
        case 'notices':
            $result = [
                'unread' => Notifications::unreadCount($myUid),
                'items' => array_map(function (array $n): array {
                    return $n + ['time_fa' => time_ago_fa($n['created_at']), 'date_fa' => jalali_datetime($n['created_at'])];
                }, Notifications::forUser($myUid, false, 40)),
            ];
            break;

        case 'notice_read':
            $uid = (string)($_POST['uid'] ?? '');
            if ($uid === 'all') Notifications::markAllRead($myUid);
            else Notifications::markRead($uid, $myUid);
            $result = ['unread' => Notifications::unreadCount($myUid)];
            break;

        // =================================================================
        // دستگاه‌ها و حریم خصوصی
        // =================================================================
        case 'devices':
            $result = ['devices' => Messenger::devices($myUid)];
            break;

        case 'device_revoke':
            $uid = (string)($_POST['device_uid'] ?? '');
            Messenger::revokeDevice($myUid, $uid);
            $result = ['devices' => Messenger::devices($myUid)];
            break;

        case 'privacy':
            // ذخیره گزارش وضعیت حافظه دستگاه کاربر (برای آمار و اطمینان از کارکرد)
            $result = [
                'retention_days' => (int)Settings::get('msg_retention_days', 0),
                'purge_on_read' => (bool)Settings::get('msg_purge_on_read'),
                'max_upload_mb' => (int)Settings::get('msg_max_file_mb', 32),
            ];
            break;

        // =================================================================
        // پروفایل
        // =================================================================
        case 'profile':
            $result = ['me' => $me + [
                'email' => Users::find($myUid)['email'] ?? '',
                'phone' => Users::find($myUid)['phone'] ?? '',
                'bio' => Users::find($myUid)['bio'] ?? '',
                'last_login' => Users::find($myUid)['last_login'] ?? null,
            ]];
            break;

        case 'profile_update':
            $patch = [];
            if (isset($_POST['full_name'])) $patch['full_name'] = sanitize((string)$_POST['full_name']);
            if (isset($_POST['phone']))     $patch['phone']     = sanitize((string)$_POST['phone']);
            if (isset($_POST['bio']))       $patch['bio']       = sanitize((string)$_POST['bio']);
            if ($patch) Users::update($myUid, $patch);
            $result = ['me' => m_current_user()];
            break;

        case 'password':
            $old = (string)($_POST['old_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            if (!Users::verify($myUid, $old)) json_out(['success' => false, 'message' => 'رمز عبور فعلی اشتباه است'], 400);
            if (strlen($new) < 8) json_out(['success' => false, 'message' => 'رمز جدید حداقل ۸ کاراکتر'], 400);
            Users::setPassword($myUid, $new);
            ActivityLog::add('password_change', 'رمز عبور تغییر کرد');
            $result = ['message' => '✅ رمز عبور تغییر کرد'];
            break;

        default:
            json_out(['success' => false, 'message' => 'عملیات نامعتبر: ' . $action], 400);
    }
} catch (Throwable $e) {
    error_log('[Odsco Messenger API] ' . $action . ': ' . $e->getMessage());
    json_out(['success' => false, 'message' => 'خطای سرور: ' . $e->getMessage()], 500);
}

json_out(['success' => true] + (isset($result) && is_array($result) ? $result : ['data' => $result ?? null]));
