<?php
/**
 * ============================================================================
 *  Odsco — موتور پیام‌رسان
 * ----------------------------------------------------------------------------
 *  مدل ذخیره‌سازی (هیبریدی، طبق انتخاب کارفرما):
 *    ۱) پیام‌ها در MySQL نوشته می‌شوند تا به طرف مقابل «تحویل» شوند.
 *    ۲) هر دستگاه یک نسخه کامل در IndexedDB خودش نگه می‌دارد (تاریخچه + مدیا).
 *    ۳) با تنظیم msg_retention_days یا msg_purge_on_read، نسخه سرور به‌صورت
 *       خودکار پاک می‌شود و تاریخچه فقط روی گوشی/کامپیوتر کاربر می‌ماند.
 *
 *  شناسه چت (chat_key):
 *    p:<conversation_uid>   گفتگوی خصوصی
 *    g:<group_uid>          گروه
 *    s:<user_uid>           پیام‌های ذخیره‌شده (Saved Messages)
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

final class Messenger
{
    private const ONLINE_WINDOW = 90;   // ثانیه

    // =====================================================================
    // حضور و دستگاه‌ها
    // =====================================================================

    public static function touchPresence(string $uid): void
    {
        Db::i()->upsert(Db::i()->t('messenger_presence'), [
            'user_uid' => $uid, 'last_seen' => time(), 'last_seen_at' => date('Y-m-d H:i:s'),
        ], ['user_uid']);
    }

    public static function isOnline(string $uid): bool
    {
        $ts = Db::i()->val('SELECT last_seen FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_presence')) . ' WHERE user_uid = ?', [$uid]);
        return $ts !== null && (time() - (int)$ts) < self::ONLINE_WINDOW;
    }

    public static function lastSeen(string $uid): ?int
    {
        $ts = Db::i()->val('SELECT last_seen FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_presence')) . ' WHERE user_uid = ?', [$uid]);
        return $ts === null ? null : (int)$ts;
    }

    /** @return array<int, string> کاربران آنلاین */
    public static function onlineUsers(): array
    {
        return array_map('strval', array_column(
            Db::i()->all('SELECT user_uid FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_presence')) . ' WHERE last_seen > ?', [time() - self::ONLINE_WINDOW]),
            'user_uid'
        ));
    }

    public static function registerDevice(string $uid, array $device): void
    {
        $name = (string)($device['name'] ?? 'دستگاه');
        $existing = Db::i()->val('SELECT uid FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_devices'))
            . ' WHERE user_uid = ? AND device_name = ?', [$uid, $name]);

        if ($existing) {
            Db::i()->update(Db::i()->t('messenger_devices'), ['last_active' => date('Y-m-d H:i:s'), 'ip' => client_ip()], 'uid = ?', [$existing]);
            return;
        }
        Db::i()->insert(Db::i()->t('messenger_devices'), [
            'uid' => odsco_uid('dev'), 'user_uid' => $uid, 'device_name' => $name,
            'platform' => $device['platform'] ?? '', 'browser' => $device['browser'] ?? '',
            'ip' => client_ip(), 'last_active' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function devices(string $uid): array
    {
        return Db::i()->all('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_devices'))
            . ' WHERE user_uid = ? ORDER BY last_active DESC', [$uid]);
    }

    public static function revokeDevice(string $uid, string $deviceUid): void
    {
        Db::i()->delete(Db::i()->t('messenger_devices'), 'uid = ? AND user_uid = ?', [$deviceUid, $uid]);
    }

    // =====================================================================
    // گفتگوهای خصوصی
    // =====================================================================

    public static function conversationFor(string $a, string $b): string
    {
        $db = Db::i();
        $row = $db->one('SELECT uid FROM ' . $db->quoteIdent($db->t('messenger_conversations'))
            . ' WHERE (user_1 = ? AND user_2 = ?) OR (user_1 = ? AND user_2 = ?) LIMIT 1', [$a, $b, $b, $a]);
        if ($row) return (string)$row['uid'];

        $uid = odsco_uid('conv');
        $db->insert($db->t('messenger_conversations'), [
            'uid' => $uid, 'user_1' => $a, 'user_2' => $b, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uid;
    }

    public static function conversationInfo(string $convUid, string $myUid): ?array
    {
        $row = Db::i()->one('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_conversations')) . ' WHERE uid = ?', [$convUid]);
        if (!$row) return null;

        $otherUid = $row['user_1'] === $myUid ? $row['user_2'] : $row['user_1'];
        $other = Users::find($otherUid);
        if (!$other) return null;

        return [
            'uid' => $row['uid'],
            'other_uid' => $otherUid,
            'name' => $other['full_name'],
            'photo' => $other['photo'],
            'role' => $other['role'],
            'job_title' => $other['job_title'],
            'online' => self::isOnline($otherUid),
            'last_seen' => self::lastSeen($otherUid),
            'chat_key' => 'p:' . $row['uid'],
            'created_at' => $row['created_at'],
        ];
    }

    public static function otherUid(string $convUid, string $myUid): ?string
    {
        return self::conversationInfo($convUid, $myUid)['other_uid'] ?? null;
    }

    // =====================================================================
    // گروه‌ها
    // =====================================================================

    public static function createGroup(string $name, array $members, string $creator, array $opts = []): string
    {
        $db = Db::i();
        $uid = odsco_uid('grp');
        $now = date('Y-m-d H:i:s');

        $db->insert($db->t('messenger_groups'), [
            'uid' => $uid, 'name' => $name, 'avatar' => $opts['avatar'] ?? '',
            'about' => $opts['about'] ?? '', 'type' => $opts['type'] ?? 'private',
            'project_uid' => $opts['project_uid'] ?? '', 'creator_uid' => $creator,
            'only_admins_post' => !empty($opts['only_admins_post']) ? 1 : 0, 'created_at' => $now,
        ]);

        $members = array_values(array_unique(array_merge([$creator], $members)));
        foreach ($members as $m) {
            $db->insert($db->t('messenger_group_members'), [
                'group_uid' => $uid, 'user_uid' => $m,
                'role' => $m === $creator ? 'owner' : 'member', 'joined_at' => $now,
            ]);
        }

        return $uid;
    }

    public static function groupInfo(string $groupUid): ?array
    {
        $row = Db::i()->one('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_groups')) . ' WHERE uid = ?', [$groupUid]);
        if (!$row) return null;

        $members = Db::i()->all('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_group_members')) . ' WHERE group_uid = ?', [$groupUid]);

        return [
            'uid' => $row['uid'], 'name' => $row['name'], 'avatar' => $row['avatar'],
            'about' => $row['about'] ?? '', 'type' => $row['type'], 'project_uid' => $row['project_uid'] ?? '',
            'creator_uid' => $row['creator_uid'], 'only_admins_post' => (bool)$row['only_admins_post'],
            'created_at' => $row['created_at'],
            'member_count' => count($members),
            'members' => array_map(function (array $m): array {
                $u = Users::find($m['user_uid']);
                return [
                    'uid' => $m['user_uid'], 'name' => $u['full_name'] ?? 'کاربر حذف‌شده',
                    'photo' => $u['photo'] ?? '', 'role' => $m['role'],
                    'online' => self::isOnline($m['user_uid']), 'joined_at' => $m['joined_at'],
                ];
            }, $members),
            'chat_key' => 'g:' . $row['uid'],
        ];
    }

    public static function myGroups(string $myUid): array
    {
        return array_map('strval', array_column(
            Db::i()->all('SELECT group_uid FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_group_members')) . ' WHERE user_uid = ?', [$myUid]),
            'group_uid'
        ));
    }

    public static function isGroupMember(string $groupUid, string $uid): bool
    {
        if (Users::level(Users::role($uid)) >= Users::level('admin')) return true;
        return (int)Db::i()->val('SELECT COUNT(*) FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_group_members'))
            . ' WHERE group_uid = ? AND user_uid = ?', [$groupUid, $uid]) > 0;
    }

    public static function groupRole(string $groupUid, string $uid): string
    {
        $role = Db::i()->val('SELECT role FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_group_members'))
            . ' WHERE group_uid = ? AND user_uid = ?', [$groupUid, $uid]);
        if ($role) return (string)$role;
        return Users::level(Users::role($uid)) >= Users::level('admin') ? 'admin' : '';
    }

    public static function addGroupMembers(string $groupUid, array $uids): int
    {
        $n = 0;
        foreach (array_unique($uids) as $u) {
            if ($u === '' || self::isGroupMemberStrict($groupUid, $u)) continue;
            Db::i()->insert(Db::i()->t('messenger_group_members'), [
                'group_uid' => $groupUid, 'user_uid' => $u, 'role' => 'member', 'joined_at' => date('Y-m-d H:i:s'),
            ]);
            $n++;
        }
        return $n;
    }

    private static function isGroupMemberStrict(string $groupUid, string $uid): bool
    {
        return (int)Db::i()->val('SELECT COUNT(*) FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_group_members'))
            . ' WHERE group_uid = ? AND user_uid = ?', [$groupUid, $uid]) > 0;
    }

    public static function removeGroupMember(string $groupUid, string $uid): void
    {
        Db::i()->delete(Db::i()->t('messenger_group_members'), 'group_uid = ? AND user_uid = ?', [$groupUid, $uid]);
    }

    public static function leaveGroup(string $groupUid, string $uid): void
    {
        self::removeGroupMember($groupUid, $uid);
    }

    public static function setGroupMemberRole(string $groupUid, string $uid, string $role): void
    {
        Db::i()->update(Db::i()->t('messenger_group_members'), ['role' => $role], 'group_uid = ? AND user_uid = ?', [$groupUid, $uid]);
    }

    public static function updateGroup(string $groupUid, array $data): void
    {
        $clean = [];
        foreach (['name','avatar','about','type'] as $k) if (array_key_exists($k, $data)) $clean[$k] = $data[$k];
        if (array_key_exists('only_admins_post', $data)) $clean['only_admins_post'] = $data['only_admins_post'] ? 1 : 0;
        if ($clean) Db::i()->update(Db::i()->t('messenger_groups'), $clean, 'uid = ?', [$groupUid]);
    }

    public static function deleteGroup(string $groupUid): void
    {
        $db = Db::i();
        $db->delete($db->t('messenger_messages'), "chat_type = 'group' AND group_uid = ?", [$groupUid]);
        $db->delete($db->t('messenger_group_members'), 'group_uid = ?', [$groupUid]);
        $db->delete($db->t('messenger_groups'), 'uid = ?', [$groupUid]);
    }

    // =====================================================================
    // لیست چت‌ها
    // =====================================================================

    /**
     * لیست کامل چت‌ها (خصوصی + گروه + پیام‌های ذخیره‌شده) با تعداد خوانده‌نشده.
     *
     * @return array{chats: array<int, array<string, mixed>>, total_unread: int}
    */
    public static function chatList(string $myUid): array
    {
        $db = Db::i();
        $chats = [];
        $totalUnread = 0;

        // --- پیام‌های ذخیره‌شده ---
        $savedKey = 's:' . $myUid;
        $saved = self::lastMessage('saved', '', $myUid);
        if ($saved) {
            $unread = self::unreadCount($savedKey, $myUid);
            $totalUnread += $unread;
            $chats[] = self::chatRow([
                'chat_key' => $savedKey, 'type' => 'saved', 'uid' => $myUid,
                'name' => 'پیام‌های ذخیره‌شده', 'photo' => '', 'is_verified' => false,
                'online' => false, 'last_seen' => null,
                'url' => 'chat.php?saved=1', 'unread' => $unread, 'last' => $saved,
                'pinned' => self::chatState($savedKey, $myUid)['is_pinned'] ?? false,
                'muted' => self::chatState($savedKey, $myUid)['is_muted'] ?? false,
            ]);
        }

        // --- گفتگوهای خصوصی ---
        $convs = $db->all('SELECT * FROM ' . $db->quoteIdent($db->t('messenger_conversations'))
            . ' WHERE user_1 = ? OR user_2 = ?', [$myUid, $myUid]);

        foreach ($convs as $conv) {
            $otherUid = $conv['user_1'] === $myUid ? $conv['user_2'] : $conv['user_1'];
            $other = Users::find($otherUid);
            if (!$other) continue;

            $key = 'p:' . $conv['uid'];
            $state = self::chatState($key, $myUid);
            $last = self::lastMessage('private', $conv['uid'], $myUid);
            if (!$last) continue;   // گفتگوی بدون پیام نمایش داده نمی‌شود

            $unread = self::unreadCount($key, $myUid);
            $totalUnread += $unread;

            $chats[] = self::chatRow([
                'chat_key' => $key, 'type' => 'private', 'uid' => $conv['uid'],
                'other_uid' => $otherUid,
                'name' => $other['full_name'], 'photo' => $other['photo'],
                'online' => self::isOnline($otherUid), 'last_seen' => self::lastSeen($otherUid),
                'url' => 'chat.php?conversation=' . $conv['uid'], 'unread' => $unread, 'last' => $last,
                'pinned' => (bool)($state['is_pinned'] ?? false), 'muted' => (bool)($state['is_muted'] ?? false),
            ]);
        }

        // --- گروه‌ها ---
        foreach (self::myGroups($myUid) as $groupUid) {
            $group = self::groupInfo($groupUid);
            if (!$group) continue;

            $key = 'g:' . $groupUid;
            $state = self::chatState($key, $myUid);
            $last = self::lastMessage('group', $groupUid, $myUid);
            $unread = self::unreadCount($key, $myUid);
            $totalUnread += $unread;

            $chats[] = self::chatRow([
                'chat_key' => $key, 'type' => 'group', 'uid' => $groupUid,
                'name' => $group['name'], 'photo' => $group['avatar'],
                'online' => false, 'last_seen' => null,
                'member_count' => $group['member_count'],
                'url' => 'group-chat.php?group=' . $groupUid, 'unread' => $unread, 'last' => $last,
                'pinned' => (bool)($state['is_pinned'] ?? false), 'muted' => (bool)($state['is_muted'] ?? false),
            ]);
        }

        // مرتب‌سازی: پین‌شده‌ها اول، سپس بر اساس زمان آخرین پیام
        usort($chats, function (array $a, array $b): int {
            if ($a['pinned'] !== $b['pinned']) return $a['pinned'] ? -1 : 1;
            return strcmp((string)$b['last_time'], (string)$a['last_time']);
        });

        return ['chats' => $chats, 'total_unread' => $totalUnread];
    }

    private static function chatRow(array $c): array
    {
        $last = $c['last'];
        return [
            'chat_key'     => $c['chat_key'],
            'type'         => $c['type'],
            'uid'          => $c['uid'],
            'other_uid'    => $c['other_uid'] ?? '',
            'name'         => $c['name'],
            'photo'        => $c['photo'],
            'online'       => $c['online'],
            'last_seen'    => $c['last_seen'] ?? null,
            'member_count' => $c['member_count'] ?? 0,
            'url'          => $c['url'],
            'unread_count' => $c['unread'],
            'pinned'       => $c['pinned'],
            'muted'        => $c['muted'],
            'last_message' => $last ? self::preview($last) : '',
            'last_time'    => $last['created_at'] ?? '',
            'last_is_mine' => (bool)($last['is_mine'] ?? false),
            'last_uid'     => $last['uid'] ?? '',
        ];
    }

    private static function preview(array $msg): string
    {
        return match ($msg['type']) {
            'image'  => '📷 تصویر',
            'file'   => '📎 ' . ($msg['file_name'] ?: 'فایل'),
            'video'  => '🎬 ویدیو',
            'voice'  => '🎤 پیام صوتی',
            'system' => 'ℹ️ ' . ($msg['content'] ?: 'رویداد'),
            default  => (string)($msg['content'] ?: ''),
        };
    }

    public static function unreadTotal(string $myUid): int
    {
        return self::chatList($myUid)['total_unread'];
    }

    // =====================================================================
    // وضعیت چت (خوانده‌شده / پین / بی‌صدا)
    // =====================================================================

    public static function chatState(string $chatKey, string $myUid): array
    {
        $row = Db::i()->one('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_chat_state'))
            . ' WHERE chat_key = ? AND user_uid = ?', [$chatKey, $myUid]);
        return $row ?: ['last_read_id' => 0, 'is_pinned' => 0, 'is_muted' => 0, 'draft' => ''];
    }

    public static function setChatState(string $chatKey, string $myUid, array $patch): void
    {
        $db = Db::i();
        $cols = ['updated_at' => date('Y-m-d H:i:s')];
        foreach (['last_read_id'] as $k) if (array_key_exists($k, $patch)) $cols[$k] = (int)$patch[$k];
        foreach (['is_pinned','is_muted'] as $k) if (array_key_exists($k, $patch)) $cols[$k] = $patch[$k] ? 1 : 0;
        if (array_key_exists('draft', $patch)) $cols['draft'] = (string)$patch['draft'];

        $exists = $db->val('SELECT id FROM ' . $db->quoteIdent($db->t('messenger_chat_state'))
            . ' WHERE chat_key = ? AND user_uid = ?', [$chatKey, $myUid]);

        if ($exists) {
            $db->update($db->t('messenger_chat_state'), $cols, 'chat_key = ? AND user_uid = ?', [$chatKey, $myUid]);
        } else {
            $db->insert($db->t('messenger_chat_state'), $cols + ['chat_key' => $chatKey, 'user_uid' => $myUid]);
        }
    }

    public static function togglePin(string $chatKey, string $myUid): bool
    {
        $state = self::chatState($chatKey, $myUid);
        $next = !$state['is_pinned'];
        self::setChatState($chatKey, $myUid, ['is_pinned' => $next]);
        return $next;
    }

    public static function toggleMute(string $chatKey, string $myUid): bool
    {
        $state = self::chatState($chatKey, $myUid);
        $next = !$state['is_muted'];
        self::setChatState($chatKey, $myUid, ['is_muted' => $next]);
        return $next;
    }

    // =====================================================================
    // پیام‌ها
    // =====================================================================

    /** آخرین پیام یک چت */
    public static function lastMessage(string $chatType, string $chatUid, string $myUid): ?array
    {
        $db = Db::i();
        [$where, $params] = self::chatWhere($chatType, $chatUid, $myUid);
        $row = $db->one('SELECT * FROM ' . $db->quoteIdent($db->t('messenger_messages'))
            . " WHERE $where AND deleted_for_all = 0 ORDER BY id DESC LIMIT 1", $params);
        return $row ? self::shape($row, $myUid) : null;
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function chatWhere(string $chatType, string $chatUid, string $myUid): array
    {
        return match ($chatType) {
            'group'  => ['group_uid = ?', [$chatUid]],
            'saved'  => ["chat_type = 'saved' AND sender_uid = ?", [$myUid]],
            default  => ['conversation_uid = ?', [$chatUid]],
        };
    }

    /**
     * دریافت پیام‌های یک چت (صفحه‌بندی‌شده).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function messages(string $chatType, string $chatUid, string $myUid, int $limit = 50, int $beforeId = 0, int $afterId = 0): array
    {
        $db = Db::i();
        [$where, $params] = self::chatWhere($chatType, $chatUid, $myUid);

        $sql = 'SELECT * FROM ' . $db->quoteIdent($db->t('messenger_messages')) . " WHERE $where AND deleted_for_all = 0";
        if ($beforeId > 0) { $sql .= ' AND id < ?'; $params[] = $beforeId; }
        if ($afterId > 0)  { $sql .= ' AND id > ?';  $params[] = $afterId; }
        $sql .= ' ORDER BY id ' . ($afterId > 0 ? 'ASC' : 'DESC') . ' LIMIT ' . max(1, min(200, $limit));

        $rows = $db->all($sql, $params);
        $rows = array_reverse($rows);   // همیشه صعودی برگردانده می‌شود

        // حذف‌شده‌ها فقط برای من
        $deletedForMe = array_map('strval', array_column(
            $db->all('SELECT message_uid FROM ' . $db->quoteIdent($db->t('messenger_message_deletes')) . ' WHERE user_uid = ?', [$myUid]),
            'message_uid'
        ));
        $deletedSet = array_flip($deletedForMe);

        $out = [];
        foreach ($rows as $row) {
            if (isset($deletedSet[$row['uid']])) continue;
            $out[] = self::shape($row, $myUid);
        }
        return $out;
    }

    public static function findMessage(string $uid): ?array
    {
        $row = Db::i()->one('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_messages')) . ' WHERE uid = ?', [$uid]);
        return $row ? self::shape($row, '') : null;
    }

    /**
     * ارسال پیام.
     *
     * @param array{
     *   chat_type: string, conversation_uid?: string, group_uid?: string,
     *   sender_uid: string, type?: string, content?: string,
     *   file_path?: string, file_name?: string, file_size?: float|int,
     *   mime?: string, meta?: array, reply_to?: string, forward_uid?: string
     * } $data
     */
    public static function send(array $data): array
    {
        $db = Db::i();
        $type = $data['chat_type'] ?? 'private';
        $sender = (string)$data['sender_uid'];
        $uid = odsco_uid($type === 'group' ? 'gmsg' : 'msg');

        $retention = (int)Settings::get('msg_retention_days', 0);
        $purgeAt = $retention > 0 ? date('Y-m-d H:i:s', strtotime("+$retention days")) : null;

        $row = [
            'uid' => $uid,
            'chat_type' => $type,
            'conversation_uid' => (string)($data['conversation_uid'] ?? ''),
            'group_uid' => (string)($data['group_uid'] ?? ''),
            'sender_uid' => $sender,
            'type' => (string)($data['type'] ?? 'text'),
            'content' => (string)($data['content'] ?? ''),
            'file_path' => (string)($data['file_path'] ?? ''),
            'file_name' => (string)($data['file_name'] ?? ''),
            'file_size' => (float)($data['file_size'] ?? 0),
            'mime' => (string)($data['mime'] ?? ''),
            'meta' => json_encode_safe($data['meta'] ?? []),
            'reply_to' => (string)($data['reply_to'] ?? ''),
            'forward_uid' => (string)($data['forward_uid'] ?? ''),
            'is_pinned' => 0,
            'is_system' => !empty($data['is_system']) ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
            'deleted_for_all' => 0,
            'purge_at' => $purgeAt,
        ];

        $id = $db->insert($db->t('messenger_messages'), $row);

        // فرستنده پیام خودش را خوانده است
        $chatKey = self::chatKeyOf($type, $row['conversation_uid'], $row['group_uid'], $sender);
        self::setChatState($chatKey, $sender, ['last_read_id' => $id]);

        // اعلان به گیرنده‌ها
        self::notifyRecipients($type, $row, $id, $sender);

        return self::shape($db->one('SELECT * FROM ' . $db->quoteIdent($db->t('messenger_messages')) . ' WHERE id = ?', [$id]) ?? $row, $sender);
    }

    private static function chatKeyOf(string $type, string $convUid, string $groupUid, string $myUid): string
    {
        return match ($type) {
            'group' => 'g:' . $groupUid,
            'saved' => 's:' . $myUid,
            default => 'p:' . $convUid,
        };
    }

    /** اعلان داخل برنامه‌ای به گیرنده‌ها (بدون اسپم برای پیام‌های متوالی) */
    private static function notifyRecipients(string $type, array $row, int $id, string $sender): void
    {
        $recipients = [];
        $title = '';
        $link = '';

        if ($type === 'group') {
            $group = self::groupInfo((string)$row['group_uid']);
            if (!$group) return;
            foreach ($group['members'] as $m) if ($m['uid'] !== $sender) $recipients[] = $m['uid'];
            $title = '💬 پیام جدید در گروه «' . $group['name'] . '»';
            $link = '../messenger/group-chat.php?group=' . $group['uid'];
        } elseif ($type === 'private') {
            $info = self::conversationInfo((string)$row['conversation_uid'], $sender);
            if (!$info) return;
            $recipients[] = $info['other_uid'];
            $title = '💬 پیام جدید از ' . Users::name($sender);
            $link = '../messenger/chat.php?conversation=' . $row['conversation_uid'];
        } else {
            return;   // پیام ذخیره‌شده اعلان ندارد
        }

        $body = self::preview(self::shape($row, $sender));

        foreach (array_unique($recipients) as $u) {
            if (self::isOnline($u)) continue;   // آنلاین است، اعلان لازم نیست
            Notifications::push($u, $title, $body, ['type' => 'message', 'icon' => '💬', 'link' => $link, 'source_uid' => $sender]);
        }
    }

    /** علامت‌گذاری همه پیام‌های چت به عنوان خوانده‌شده */
    public static function markRead(string $chatType, string $chatUid, string $myUid): void
    {
        $db = Db::i();
        [$where, $params] = self::chatWhere($chatType, $chatUid, $myUid);
        $maxId = (int)$db->val('SELECT COALESCE(MAX(id),0) FROM ' . $db->quoteIdent($db->t('messenger_messages')) . " WHERE $where", $params);
        if ($maxId > 0) {
            self::setChatState(self::chatKeyOf($chatType, $chatUid, $chatUid, $myUid), $myUid, ['last_read_id' => $maxId]);
        }
    }

    public static function unreadCount(string $chatKey, string $myUid): int
    {
        $db = Db::i();
        $state = self::chatState($chatKey, $myUid);
        $lastRead = (int)($state['last_read_id'] ?? 0);

        [$type, $uid] = self::splitChatKey($chatKey);
        [$where, $params] = self::chatWhere($type, $uid, $myUid);
        $params[] = $lastRead;
        $params[] = $myUid;

        return (int)$db->val('SELECT COUNT(*) FROM ' . $db->quoteIdent($db->t('messenger_messages'))
            . " WHERE $where AND deleted_for_all = 0 AND id > ? AND sender_uid <> ?", $params);
    }

    /** @return array{0: string, 1: string} */
    public static function splitChatKey(string $chatKey): array
    {
        $prefix = substr($chatKey, 0, 1);
        $uid = substr($chatKey, 2);
        return [match ($prefix) { 'g' => 'group', 's' => 'saved', default => 'private' }, $uid];
    }

    // ---------------------------------------------------------------------
    // عملیات روی پیام
    // ---------------------------------------------------------------------

    public static function editMessage(string $msgUid, string $myUid, string $content): bool
    {
        $msg = self::rawMessage($msgUid);
        if (!$msg || $msg['sender_uid'] !== $myUid) return false;
        if ($msg['type'] !== 'text' && $msg['type'] !== 'system') return false;

        Db::i()->update(Db::i()->t('messenger_messages'), [
            'content' => $content, 'edited_at' => date('Y-m-d H:i:s'),
        ], 'uid = ?', [$msgUid]);
        return true;
    }

    /**
     * حذف پیام.
     *
     * @param bool $forEveryone فقط فرستنده (یا ادمین) می‌تواند برای همه حذف کند
     */
    public static function deleteMessage(string $msgUid, string $myUid, bool $forEveryone = false): bool
    {
        $db = Db::i();
        $msg = self::rawMessage($msgUid);
        if (!$msg) return false;

        $isAdmin = Users::level(Users::role($myUid)) >= Users::level('admin');
        $isSender = $msg['sender_uid'] === $myUid;

        if ($forEveryone) {
            if (!$isSender && !$isAdmin) return false;
            $db->update($db->t('messenger_messages'), ['deleted_for_all' => 1], 'uid = ?', [$msgUid]);
            return true;
        }

        // حذف فقط برای من: هر عضو همان چت می‌تواند پیام را از دید خودش بردارد
        if (!self::canAccessMessage($msg, $myUid)) return false;
        $db->upsert($db->t('messenger_message_deletes'), [
            'message_uid' => $msgUid, 'user_uid' => $myUid, 'created_at' => date('Y-m-d H:i:s'),
        ], ['message_uid', 'user_uid']);
        return true;
    }

    public static function togglePinMessage(string $msgUid, string $myUid): bool
    {
        $msg = self::rawMessage($msgUid);
        if (!$msg) return false;
        $next = !$msg['is_pinned'];
        Db::i()->update(Db::i()->t('messenger_messages'), ['is_pinned' => $next ? 1 : 0], 'uid = ?', [$msgUid]);
        return $next;
    }

    public static function pinnedMessages(string $chatType, string $chatUid, string $myUid): array
    {
        [$where, $params] = self::chatWhere($chatType, $chatUid, $myUid);
        return array_map(fn($r) => self::shape($r, $myUid), Db::i()->all(
            'SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_messages'))
            . " WHERE $where AND is_pinned = 1 AND deleted_for_all = 0 ORDER BY id DESC", $params
        ));
    }

    public static function react(string $msgUid, string $myUid, string $emoji): bool
    {
        $db = Db::i();
        if ($emoji === '') {
            $db->delete($db->t('messenger_reactions'), 'message_uid = ? AND user_uid = ?', [$msgUid, $myUid]);
            return true;
        }
        $db->upsert($db->t('messenger_reactions'), [
            'message_uid' => $msgUid, 'user_uid' => $myUid, 'emoji' => $emoji, 'created_at' => date('Y-m-d H:i:s'),
        ], ['message_uid', 'user_uid']);
        return true;
    }

    /** @return array<string, array{count: int, mine: bool}> */
    public static function reactions(string $msgUid, string $myUid): array
    {
        $rows = Db::i()->all('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_reactions')) . ' WHERE message_uid = ?', [$msgUid]);
        $out = [];
        foreach ($rows as $r) {
            $e = (string)$r['emoji'];
            if ($e === '') continue;
            if (!isset($out[$e])) $out[$e] = ['count' => 0, 'mine' => false];
            $out[$e]['count']++;
            if ($r['user_uid'] === $myUid) $out[$e]['mine'] = true;
        }
        return $out;
    }

    /** پیام‌های پین‌شده + واکنش‌ها برای یک دسته از پیام‌ها */
    public static function reactionsFor(array $msgUids): array
    {
        if (!$msgUids) return [];
        $ph = implode(',', array_fill(0, count($msgUids), '?'));
        $rows = Db::i()->all('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_reactions')) . " WHERE message_uid IN ($ph)", array_values($msgUids));
        $out = [];
        foreach ($rows as $r) {
            $out[$r['message_uid']][] = ['emoji' => $r['emoji'], 'user_uid' => $r['user_uid']];
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // تایپ کردن
    // ---------------------------------------------------------------------

    public static function setTyping(string $chatKey, string $myUid): void
    {
        Db::i()->upsert(Db::i()->t('messenger_typing'), [
            'chat_key' => $chatKey, 'user_uid' => $myUid, 'expires_at' => time() + 6,
        ], ['chat_key', 'user_uid']);
    }

    /** @return array<int, string> */
    public static function typingUsers(string $chatKey, string $myUid): array
    {
        $rows = Db::i()->all('SELECT user_uid FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_typing'))
            . ' WHERE chat_key = ? AND user_uid <> ? AND expires_at > ?', [$chatKey, $myUid, time()]);
        return array_map('strval', array_column($rows, 'user_uid'));
    }

    // ---------------------------------------------------------------------
    // جستجو
    // ---------------------------------------------------------------------

    public static function search(string $myUid, string $query, int $limit = 30): array
    {
        $query = trim($query);
        if ($query === '') return [];

        $db = Db::i();
        $like = '%' . $query . '%';

        $rows = $db->all(
            'SELECT * FROM ' . $db->quoteIdent($db->t('messenger_messages'))
            . " WHERE deleted_for_all = 0 AND content LIKE ? AND ("
            . "   (chat_type = 'saved' AND sender_uid = ?)"
            . "   OR (chat_type = 'private' AND conversation_uid IN ("
            . "        SELECT uid FROM " . $db->quoteIdent($db->t('messenger_conversations')) . " WHERE user_1 = ? OR user_2 = ?))"
            . "   OR (chat_type = 'group' AND group_uid IN ("
            . "        SELECT group_uid FROM " . $db->quoteIdent($db->t('messenger_group_members')) . " WHERE user_uid = ?))"
            . " ) ORDER BY id DESC LIMIT " . max(1, min(100, $limit)),
            [$like, $myUid, $myUid, $myUid, $myUid]
        );

        return array_map(fn($r) => self::shape($r, $myUid), $rows);
    }

    /** کاربران قابل گفتگو */
    public static function contacts(string $myUid): array
    {
        return array_values(array_filter(Users::list(['active' => true, 'exclude_uid' => $myUid]), function (array $u): bool {
            return !empty($u['messenger_enabled']) || in_array($u['role'], ['admin','manager'], true);
        }));
    }

    // ---------------------------------------------------------------------
    // پاکسازی سرور (تاریخچه روی دستگاه کاربر می‌ماند)
    // ---------------------------------------------------------------------

    /** حذف پیام‌هایی که مهلت نگهداری‌شان روی سرور تمام شده */
    public static function purgeExpired(): int
    {
        $db = Db::i();
        $rows = $db->all('SELECT uid, file_path FROM ' . $db->quoteIdent($db->t('messenger_messages'))
            . ' WHERE purge_at IS NOT NULL AND purge_at < ?', [date('Y-m-d H:i:s')]);

        $n = 0;
        foreach ($rows as $r) {
            if ($r['file_path'] !== '') {
                $abs = dirname(__DIR__) . '/' . ltrim((string)$r['file_path'], '/');
                if (is_file($abs)) @unlink($abs);
            }
            $n++;
        }
        if ($rows) $db->delete($db->t('messenger_messages'), 'purge_at IS NOT NULL AND purge_at < ?', [date('Y-m-d H:i:s')]);
        return $n;
    }

    /** حذف پیام‌های خوانده‌شده توسط هر دو طرف (حداکثر حریم خصوصی) */
    public static function purgeReadPrivate(): int
    {
        if (!Settings::get('msg_purge_on_read')) return 0;

        $db = Db::i();
        $rows = $db->all(
            "SELECT m.uid, m.file_path FROM " . $db->quoteIdent($db->t('messenger_messages')) . ' m'
            . ' JOIN ' . $db->quoteIdent($db->t('messenger_conversations')) . ' c ON c.uid = m.conversation_uid'
            . ' JOIN ' . $db->quoteIdent($db->t('messenger_chat_state')) . ' s1 ON s1.chat_key = ' . ($db->isMysql() ? "CONCAT('p:', c.uid)" : "('p:' || c.uid)") . ' AND s1.user_uid = c.user_1'
            . ' JOIN ' . $db->quoteIdent($db->t('messenger_chat_state')) . ' s2 ON s2.chat_key = ' . ($db->isMysql() ? "CONCAT('p:', c.uid)" : "('p:' || c.uid)") . ' AND s2.user_uid = c.user_2'
            . " WHERE m.chat_type = 'private' AND m.deleted_for_all = 0 AND m.id <= s1.last_read_id AND m.id <= s2.last_read_id"
        );

        $n = 0;
        $uids = [];
        foreach ($rows as $r) {
            if ($r['file_path'] !== '') {
                $abs = dirname(__DIR__) . '/' . ltrim((string)$r['file_path'], '/');
                if (is_file($abs)) @unlink($abs);
            }
            $uids[] = $r['uid'];
            $n++;
        }
        foreach (array_chunk($uids, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $db->delete($db->t('messenger_messages'), "uid IN ($ph)", $chunk);
        }
        return $n;
    }

    /** پاک کردن کامل تاریخچه یک چت برای یک کاربر (فقط سمت او) */
    public static function clearHistory(string $chatKey, string $myUid): int
    {
        [$type, $uid] = self::splitChatKey($chatKey);
        [$where, $params] = self::chatWhere($type, $uid, $myUid);

        $rows = Db::i()->all('SELECT uid FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_messages')) . " WHERE $where", $params);
        $n = 0;
        foreach ($rows as $r) {
            Db::i()->upsert(Db::i()->t('messenger_message_deletes'), [
                'message_uid' => $r['uid'], 'user_uid' => $myUid, 'created_at' => date('Y-m-d H:i:s'),
            ], ['message_uid', 'user_uid']);
            $n++;
        }
        return $n;
    }

    // =====================================================================
    // آپلود فایل پیوست
    // =====================================================================

    /** سقف حجم آپلود (بایت) از تنظیمات */
    public static function maxUploadBytes(): int
    {
        return max(1, (int)Settings::get('msg_max_file_mb', 32)) * 1048576;
    }

    /**
     * ذخیره فایل پیوست پیام‌رسان و ثبت آن در کتابخانه رسانه.
     *
     * @return array{success: bool, message?: string, path?: string, name?: string, size?: int, mime?: string}
     */
    public static function uploadFile(array $file, string $byUid = ''): array
    {
        $subdir = 'messenger/' . date('Y/m');
        $up = handle_upload($file, $subdir, null, self::maxUploadBytes());
        if (empty($up['success'])) {
            return ['success' => false, 'message' => (string)($up['message'] ?? 'آپلود ناموفق بود')];
        }
        Media::register(
            (string)$up['path'], (string)$up['name'], 'messenger',
            $byUid, (int)$up['size'], (string)$up['mime']
        );
        return $up;
    }

    // ---------------------------------------------------------------------
    // کمکی
    // ---------------------------------------------------------------------

    /** آیا این کاربر عضو چتی است که پیام در آن قرار دارد؟ */
    private static function canAccessMessage(array $msg, string $myUid): bool
    {
        if (Users::level(Users::role($myUid)) >= Users::level('admin')) return true;
        if (($msg['sender_uid'] ?? '') === $myUid) return true;

        return match ($msg['chat_type'] ?? 'private') {
            'group'  => self::isGroupMember((string)($msg['group_uid'] ?? ''), $myUid),
            'saved'  => ($msg['sender_uid'] ?? '') === $myUid,
            default  => (self::otherUid((string)($msg['conversation_uid'] ?? ''), $myUid) !== null),
        };
    }

    private static function rawMessage(string $uid): ?array
    {
        return Db::i()->one('SELECT * FROM ' . Db::i()->quoteIdent(Db::i()->t('messenger_messages')) . ' WHERE uid = ?', [$uid]);
    }

    /** تبدیل ردیف دیتابیس به شکل JSON API */
    public static function shape(array $r, string $myUid): array
    {
        $sender = $r['sender_uid'] ?? '';
        $meta = json_decode_safe($r['meta'] ?? '{}');

        return [
            'uid' => $r['uid'],
            'id' => $r['uid'],
            'server_id' => (int)($r['id'] ?? 0),
            'chat_type' => $r['chat_type'] ?? 'private',
            'conversation_uid' => $r['conversation_uid'] ?? '',
            'group_uid' => $r['group_uid'] ?? '',
            'sender_uid' => $sender,
            'sender_name' => Users::name($sender),
            'sender_photo' => Users::photo($sender),
            'type' => $r['type'] ?? 'text',
            'content' => $r['content'] ?? '',
            'file_path' => $r['file_path'] ?? '',
            'file_name' => $r['file_name'] ?? '',
            'file_size' => (float)($r['file_size'] ?? 0),
            'mime' => $r['mime'] ?? '',
            'meta' => $meta,
            'reply_to' => $r['reply_to'] ?? '',
            'forward_uid' => $r['forward_uid'] ?? '',
            'is_pinned' => (bool)($r['is_pinned'] ?? 0),
            'is_system' => (bool)($r['is_system'] ?? 0),
            'edited_at' => $r['edited_at'] ?? null,
            'timestamp' => $r['created_at'],
            'created_at' => $r['created_at'],
            'is_mine' => $myUid !== '' && $sender === $myUid,
        ];
    }
}
