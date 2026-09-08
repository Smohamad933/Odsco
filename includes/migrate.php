<?php
/**
 * ============================================================================
 *  Odsco — مهاجرت داده‌های قدیمی (فایل‌های JSON) به دیتابیس
 * ----------------------------------------------------------------------------
 *  رمزهای عبور بدون تغییر منتقل می‌شوند (همان bcrypt hash قبلی).
 *  اجرای این اسکریپت چندبار بی‌خطر است (idempotent).
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

final class Migrator
{
    /** @var array<string, int> */
    private array $counts = [];
    private array $errors = [];

    /** @return array{counts: array<string,int>, errors: array<int,string>} */
    public static function run(): array
    {
        $m = new self();

        $m->settings();
        $m->users();
        $m->clients();
        $m->categories();
        $m->projects();
        $m->blog();
        $m->team();
        $m->contactMessages();
        $m->media();
        $m->views();
        $m->logs();
        $m->conversations();
        $m->groups();
        $m->privateMessages();
        $m->groupMessages();
        $m->reactions();
        $m->presence();

        return ['counts' => $m->counts, 'errors' => $m->errors];
    }

    // ------------------------------------------------------------------

    private function json(string $file): array
    {
        $path = DATA_PATH . '/' . $file;
        if (!is_file($path)) return [];
        $d = json_decode((string)file_get_contents($path), true);
        return is_array($d) ? $d : [];
    }

    private function bump(string $key, int $n = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $n;
    }

    private function safe(string $what, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->errors[] = $what . ': ' . $e->getMessage();
        }
    }

    // ------------------------------------------------------------------

    private function settings(): void
    {
        $this->safe('settings', function (): void {
            foreach ($this->json('settings.json') as $k => $v) {
                if (!is_scalar($v)) continue;
                Settings::set((string)$k, (string)$v);
                $this->bump('تنظیمات');
            }
        });
    }

    private function users(): void
    {
        $this->safe('users', function (): void {
            $db = Db::i();
            $users = $this->json('users.json')['users'] ?? [];

            foreach ($users as $u) {
                $uid = (string)($u['id'] ?? '');
                $username = (string)($u['username'] ?? '');
                if ($uid === '' || $username === '') continue;

                if ($db->val('SELECT uid FROM ' . $db->quoteIdent($db->t('users')) . ' WHERE uid = ?', [$uid])) continue;

                $db->insert($db->t('users'), [
                    'uid' => $uid,
                    'username' => $username,
                    'password' => (string)($u['password'] ?? password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)),
                    'role' => (string)($u['role'] ?? 'viewer'),
                    'full_name' => (string)($u['full_name'] ?? $username),
                    'email' => (string)($u['email'] ?? ''),
                    'phone' => (string)($u['phone'] ?? ''),
                    'photo' => str_replace('\\', '/', (string)($u['photo'] ?? '')),
                    'bio' => (string)($u['bio'] ?? ''),
                    'messenger_enabled' => !empty($u['messenger_enabled']) ? 1 : 0,
                    'attendance_enabled' => in_array($u['role'] ?? '', ['admin','manager','editor','employee','article_writer','project_writer'], true) ? 1 : 0,
                    'is_active' => isset($u['is_active']) ? ($u['is_active'] ? 1 : 0) : 1,
                    'last_login' => ($u['last_login'] ?? '') ?: null,
                    'created_at' => (string)($u['created_at'] ?? date('Y-m-d H:i:s')),
                    'updated_at' => ($u['updated_at'] ?? '') ?: null,
                ]);
                $this->bump('کاربران');
            }
        });
    }

    private function clients(): void
    {
        $this->safe('clients', function (): void {
            $db = Db::i();
            foreach ($this->json('clients.json') as $c) {
                $uid = (string)($c['id'] ?? '');
                if ($uid === '') continue;
                if ($db->val('SELECT uid FROM ' . $db->quoteIdent($db->t('clients')) . ' WHERE uid = ?', [$uid])) continue;

                $db->insert($db->t('clients'), [
                    'uid' => $uid, 'name' => (string)($c['name'] ?? ''),
                    'logo' => str_replace('\\', '/', (string)($c['logo'] ?? '')),
                    'website' => (string)($c['website'] ?? ''),
                    'contact_name' => (string)($c['contact_name'] ?? ''),
                    'contact_phone' => (string)($c['contact_phone'] ?? ''),
                    'contact_email' => (string)($c['contact_email'] ?? ''),
                    'address' => (string)($c['address'] ?? ''),
                    'sort_order' => (int)($c['order'] ?? 0), 'is_active' => 1,
                    'created_at' => (string)($c['created_at'] ?? date('Y-m-d H:i:s')),
                ]);
                $this->bump('کارفرمایان');
            }
        });
    }

    private function categories(): void
    {
        $this->safe('categories', function (): void {
            $db = Db::i();
            foreach ($this->json('categories.json') as $i => $c) {
                $uid = (string)($c['id'] ?? '');
                if ($uid === '') continue;
                if ($db->val('SELECT uid FROM ' . $db->quoteIdent($db->t('categories')) . ' WHERE uid = ?', [$uid])) continue;

                $db->insert($db->t('categories'), [
                    'uid' => $uid, 'name' => (string)($c['name'] ?? ''),
                    'slug' => (string)($c['slug'] ?? ''), 'description' => (string)($c['description'] ?? ''),
                    'icon' => (string)($c['icon'] ?? ''), 'color' => (string)($c['color'] ?? ''),
                    'sort_order' => (int)($c['order'] ?? $i), 'created_at' => date('Y-m-d H:i:s'),
                ]);
                $this->bump('دسته‌بندی‌ها');
            }
        });
    }

    private function projects(): void
    {
        $this->safe('projects', function (): void {
            $db = Db::i();
            foreach ($this->json('projects.json') as $p) {
                $uid = (string)($p['id'] ?? '');
                if ($uid === '') continue;
                if ($db->val('SELECT uid FROM ' . $db->quoteIdent($db->t('projects')) . ' WHERE uid = ?', [$uid])) continue;

                $images = array_map(fn($i) => str_replace('\\', '/', (string)$i), (array)($p['images'] ?? []));
                $db->insert($db->t('projects'), [
                    'uid' => $uid, 'title' => (string)($p['title'] ?? ''),
                    'slug' => (string)($p['slug'] ?? ''), 'category' => (string)($p['category'] ?? ''),
                    'description' => (string)($p['description'] ?? ''),
                    'images' => json_encode_safe($images),
                    'cover_image' => str_replace('\\', '/', (string)($p['cover_image'] ?? ($images[0] ?? ''))),
                    'location' => (string)($p['location'] ?? ''),
                    'client_name' => (string)($p['client'] ?? ''),
                    'project_year' => (string)($p['year'] ?? ''),
                    'area' => (string)($p['area'] ?? ''),
                    'specs' => json_encode_safe($p['specs'] ?? []),
                    'progress' => 0,
                    'status' => (string)($p['status'] ?? 'active'),
                    'show_on_home' => !empty($p['show_on_home']) ? 1 : 0,
                    'client_visible' => 1,
                    'views' => (int)($p['views'] ?? 0),
                    'created_at' => (string)($p['created_at'] ?? date('Y-m-d H:i:s')),
                    'updated_at' => ($p['updated_at'] ?? '') ?: null,
                ]);
                $this->bump('پروژه‌ها');
            }
        });
    }

    private function blog(): void
    {
        $this->safe('blog', function (): void {
            $db = Db::i();
            foreach ($this->json('blog_posts.json') as $p) {
                $uid = (string)($p['id'] ?? '');
                if ($uid === '') continue;
                if ($db->val('SELECT uid FROM ' . $db->quoteIdent($db->t('blog_posts')) . ' WHERE uid = ?', [$uid])) continue;

                $date = (string)($p['date'] ?? date('Y-m-d H:i:s'));
                if (strlen($date) === 10) $date .= ' 12:00:00';

                $db->insert($db->t('blog_posts'), [
                    'uid' => $uid, 'title' => (string)($p['title'] ?? ''),
                    'slug' => (string)($p['slug'] ?? ''), 'excerpt' => (string)($p['excerpt'] ?? ''),
                    'content' => (string)($p['content'] ?? ''),
                    'image' => str_replace('\\', '/', (string)($p['image'] ?? '')),
                    'category' => (string)($p['category'] ?? ''), 'author' => (string)($p['author'] ?? ''),
                    'tags' => json_encode_safe($p['tags'] ?? []),
                    'status' => (string)($p['status'] ?? 'published'),
                    'views' => (int)($p['views'] ?? 0),
                    'post_date' => $date, 'created_at' => date('Y-m-d H:i:s'),
                ]);
                $this->bump('مقالات');
            }
        });
    }

    private function team(): void
    {
        $this->safe('team', function (): void {
            $db = Db::i();
            foreach ($this->json('team.json') as $i => $m) {
                $uid = (string)($m['id'] ?? '');
                if ($uid === '') continue;
                if ($db->val('SELECT uid FROM ' . $db->quoteIdent($db->t('team_members')) . ' WHERE uid = ?', [$uid])) continue;

                $db->insert($db->t('team_members'), [
                    'uid' => $uid, 'name' => (string)($m['name'] ?? ''), 'role' => (string)($m['role'] ?? ''),
                    'bio' => (string)($m['bio'] ?? ''), 'photo' => str_replace('\\', '/', (string)($m['photo'] ?? '')),
                    'email' => (string)($m['email'] ?? ''), 'phone' => (string)($m['phone'] ?? ''),
                    'linkedin' => (string)($m['linkedin'] ?? ''), 'instagram' => (string)($m['instagram'] ?? ''),
                    'sort_order' => (int)($m['order'] ?? $i), 'is_active' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $this->bump('اعضای تیم');
            }
        });
    }

    private function contactMessages(): void
    {
        $this->safe('contact_messages', function (): void {
            $db = Db::i();
            foreach ([['messages.json', 0], ['trash_messages.json', 1]] as [$file, $trashed]) {
                foreach ($this->json($file) as $m) {
                    $uid = (string)($m['id'] ?? odsco_uid('msg'));
                    if ($db->val('SELECT uid FROM ' . $db->quoteIdent($db->t('contact_messages')) . ' WHERE uid = ?', [$uid])) continue;

                    $date = (string)($m['date'] ?? date('Y-m-d H:i:s'));
                    $db->insert($db->t('contact_messages'), [
                        'uid' => $uid, 'name' => (string)($m['name'] ?? ''), 'email' => (string)($m['email'] ?? ''),
                        'phone' => (string)($m['phone'] ?? ''), 'subject' => (string)($m['subject'] ?? ''),
                        'body' => (string)($m['message'] ?? ''),
                        'attachment' => json_encode_safe($m['attachment'] ?? null),
                        'is_read' => !empty($m['is_read']) ? 1 : 0, 'is_trashed' => $trashed,
                        'created_at' => strlen($date) === 10 ? $date . ' 12:00:00' : $date,
                    ]);
                    $this->bump('پیام‌های تماس');
                }
            }
        });
    }

    private function media(): void
    {
        $this->safe('media', function (): void {
            $db = Db::i();
            $root = UPLOAD_PATH;
            if (!is_dir($root)) return;

            $folders = ['blog','clients','projects','team','users','media','messenger','attachments'];
            $trashedUids = array_column($this->json('trash_media.json'), 'id');
            $trashedSet = array_flip(array_map('strval', $trashedUids));

            foreach ($folders as $folder) {
                $dir = $root . '/' . $folder;
                if (!is_dir($dir)) continue;
                foreach (scandir($dir) ?: [] as $f) {
                    if ($f === '.' || $f === '..' || is_dir($dir . '/' . $f)) continue;
                    $rel = 'uploads/' . $folder . '/' . $f;
                    if ($db->val('SELECT uid FROM ' . $db->quoteIdent($db->t('media_files')) . ' WHERE path = ?', [$rel])) continue;

                    $db->insert($db->t('media_files'), [
                        'uid' => odsco_uid('med'), 'path' => $rel, 'name' => $f,
                        'mime' => (string)@mime_content_type($dir . '/' . $f),
                        'size_bytes' => (int)@filesize($dir . '/' . $f),
                        'folder' => $folder, 'uploaded_by' => 'migration',
                        'is_trashed' => 0, 'created_at' => date('Y-m-d H:i:s', (int)@filemtime($dir . '/' . $f)),
                    ]);
                    $this->bump('فایل‌های رسانه');
                }
            }
            unset($trashedSet);
        });
    }

    private function views(): void
    {
        $this->safe('views', function (): void {
            $db = Db::i();
            foreach ($this->json('views.json') as $v) {
                if (empty($v['date'])) continue;
                if ($db->val('SELECT id FROM ' . $db->quoteIdent($db->t('site_views')) . ' WHERE day = ?', [$v['date']])) continue;
                $db->insert($db->t('site_views'), ['day' => (string)$v['date'], 'hits' => (int)($v['count'] ?? 0)]);
                $this->bump('آمار بازدید');
            }
        });
    }

    private function logs(): void
    {
        $this->safe('logs', function (): void {
            $db = Db::i();
            if ((int)$db->val('SELECT COUNT(*) FROM ' . $db->quoteIdent($db->t('activity_logs'))) > 0) return;
            foreach ($this->json('logs.json') as $l) {
                $db->insert($db->t('activity_logs'), [
                    'uid' => odsco_uid('log'), 'action' => (string)($l['action'] ?? ''),
                    'details' => (string)($l['details'] ?? ''), 'actor' => (string)($l['user'] ?? 'guest'),
                    'ip' => '', 'created_at' => (string)($l['timestamp'] ?? date('Y-m-d H:i:s')),
                ]);
                $this->bump('لاگ‌ها');
            }
        });
    }

    // ---------------- پیام‌رسان ----------------------------------------

    private function conversations(): void
    {
        $this->safe('conversations', function (): void {
            $db = Db::i();
            foreach ($this->json('messenger_conversations.json') as $c) {
                $uid = (string)($c['id'] ?? '');
                if ($uid === '') continue;
                if ($db->val('SELECT uid FROM ' . $db->quoteIdent($db->t('messenger_conversations')) . ' WHERE uid = ?', [$uid])) continue;

                $db->insert($db->t('messenger_conversations'), [
                    'uid' => $uid, 'user_1' => (string)($c['user_1'] ?? ''), 'user_2' => (string)($c['user_2'] ?? ''),
                    'created_at' => (string)($c['created_at'] ?? date('Y-m-d H:i:s')),
                ]);
                $this->bump('گفتگوهای خصوصی');
            }
        });
    }

    private function groups(): void
    {
        $this->safe('groups', function (): void {
            $db = Db::i();
            foreach ($this->json('messenger_groups.json') as $g) {
                $uid = (string)($g['id'] ?? '');
                if ($uid === '') continue;
                if (!$db->val('SELECT uid FROM ' . $db->quoteIdent($db->t('messenger_groups')) . ' WHERE uid = ?', [$uid])) {
                    $db->insert($db->t('messenger_groups'), [
                        'uid' => $uid, 'name' => (string)($g['name'] ?? 'گروه'),
                        'type' => (string)($g['type'] ?? 'private'), 'creator_uid' => (string)($g['creator_id'] ?? ''),
                        'created_at' => (string)($g['created_at'] ?? date('Y-m-d H:i:s')),
                    ]);
                    $this->bump('گروه‌ها');
                }
                foreach ((array)($g['members'] ?? []) as $m) {
                    $exists = $db->val('SELECT id FROM ' . $db->quoteIdent($db->t('messenger_group_members'))
                        . ' WHERE group_uid = ? AND user_uid = ?', [$uid, $m]);
                    if ($exists) continue;
                    $db->insert($db->t('messenger_group_members'), [
                        'group_uid' => $uid, 'user_uid' => (string)$m,
                        'role' => $m === ($g['creator_id'] ?? '') ? 'owner' : 'member',
                        'joined_at' => (string)($g['created_at'] ?? date('Y-m-d H:i:s')),
                    ]);
                }
            }
        });
    }

    private function privateMessages(): void
    {
        $this->safe('private_messages', function (): void {
            $db = Db::i();
            foreach ($this->json('messenger_messages.json') as $m) {
                $uid = (string)($m['id'] ?? '');
                if ($uid === '') continue;
                if ($db->val('SELECT uid FROM ' . $db->quoteIdent($db->t('messenger_messages')) . ' WHERE uid = ?', [$uid])) continue;

                $id = $this->insertMessage([
                    'uid' => $uid, 'chat_type' => 'private',
                    'conversation_uid' => (string)($m['conversation_id'] ?? ''),
                    'sender_uid' => (string)($m['sender_id'] ?? ''),
                    'type' => (string)($m['type'] ?? 'text'),
                    'content' => (string)($m['content'] ?? ''),
                    'file_path' => str_replace('\\', '/', (string)($m['file_path'] ?? '')),
                    'file_name' => (string)($m['file_name'] ?? ''),
                    'file_size' => (float)($m['file_size'] ?? 0),
                    'created_at' => (string)($m['timestamp'] ?? date('Y-m-d H:i:s')),
                ]);
                if ($id) {
                    $this->applyReadState('p:' . ($m['conversation_id'] ?? ''), (array)($m['read_by'] ?? []), $id);
                    $this->bump('پیام‌های خصوصی');
                }
            }
        });
    }

    private function groupMessages(): void
    {
        $this->safe('group_messages', function (): void {
            $db = Db::i();
            foreach ($this->json('messenger_group_messages.json') as $m) {
                $uid = (string)($m['id'] ?? '');
                if ($uid === '') continue;
                if ($db->val('SELECT uid FROM ' . $db->quoteIdent($db->t('messenger_messages')) . ' WHERE uid = ?', [$uid])) continue;

                $id = $this->insertMessage([
                    'uid' => $uid, 'chat_type' => 'group',
                    'group_uid' => (string)($m['group_id'] ?? ''),
                    'sender_uid' => (string)($m['sender_id'] ?? ''),
                    'type' => (string)($m['type'] ?? 'text'),
                    'content' => (string)($m['content'] ?? ''),
                    'file_path' => str_replace('\\', '/', (string)($m['file_path'] ?? '')),
                    'file_name' => (string)($m['file_name'] ?? ''),
                    'file_size' => (float)($m['file_size'] ?? 0),
                    'created_at' => (string)($m['timestamp'] ?? date('Y-m-d H:i:s')),
                ]);
                if ($id) {
                    $this->applyReadState('g:' . ($m['group_id'] ?? ''), (array)($m['read_by'] ?? []), $id);
                    $this->bump('پیام‌های گروه');
                }
            }
        });
    }

    private function insertMessage(array $row): int
    {
        $db = Db::i();
        $isImage = in_array(strtolower((string)pathinfo($row['file_path'], PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp','bmp'], true);

        return $db->insert($db->t('messenger_messages'), [
            'uid' => $row['uid'], 'chat_type' => $row['chat_type'],
            'conversation_uid' => $row['conversation_uid'] ?? '', 'group_uid' => $row['group_uid'] ?? '',
            'sender_uid' => $row['sender_uid'],
            'type' => $row['file_path'] !== '' ? ($isImage ? 'image' : 'file') : $row['type'],
            'content' => $row['content'], 'file_path' => $row['file_path'],
            'file_name' => $row['file_name'] ?: basename($row['file_path']),
            'file_size' => $row['file_size'], 'meta' => '{}',
            'is_pinned' => 0, 'is_system' => 0,
            'created_at' => $row['created_at'], 'deleted_for_all' => 0,
        ]);
    }

    /** تبدیل آرایه read_by قدیمی به نشانگر «آخرین پیام خوانده‌شده» */
    private function applyReadState(string $chatKey, array $readBy, int $messageId): void
    {
        $db = Db::i();
        foreach ($readBy as $uid) {
            if (!is_string($uid) || $uid === '') continue;
            $current = (int)$db->val('SELECT last_read_id FROM ' . $db->quoteIdent($db->t('messenger_chat_state'))
                . ' WHERE chat_key = ? AND user_uid = ?', [$chatKey, $uid]);
            if ($messageId > $current) {
                if ($current > 0) {
                    $db->update($db->t('messenger_chat_state'), ['last_read_id' => $messageId], 'chat_key = ? AND user_uid = ?', [$chatKey, $uid]);
                } else {
                    $db->insert($db->t('messenger_chat_state'), [
                        'chat_key' => $chatKey, 'user_uid' => $uid, 'last_read_id' => $messageId,
                        'last_read_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }
    }

    private function reactions(): void
    {
        $this->safe('reactions', function (): void {
            $db = Db::i();
            foreach ($this->json('messenger_reactions.json') as $r) {
                $msg = (string)($r['message_id'] ?? '');
                $user = (string)($r['user_id'] ?? '');
                $emoji = (string)($r['emoji'] ?? '');
                if ($msg === '' || $user === '' || $emoji === '') continue;

                if ($db->val('SELECT id FROM ' . $db->quoteIdent($db->t('messenger_reactions'))
                    . ' WHERE message_uid = ? AND user_uid = ?', [$msg, $user])) continue;

                $db->insert($db->t('messenger_reactions'), [
                    'message_uid' => $msg, 'user_uid' => $user, 'emoji' => $emoji,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $this->bump('واکنش‌ها');
            }
        });
    }

    private function presence(): void
    {
        $this->safe('presence', function (): void {
            $db = Db::i();
            foreach ($this->json('messenger_online.json') as $uid => $ts) {
                if (!is_numeric($ts)) continue;
                $db->upsert($db->t('messenger_presence'), [
                    'user_uid' => (string)$uid, 'last_seen' => (int)$ts,
                    'last_seen_at' => date('Y-m-d H:i:s', (int)$ts),
                ], ['user_uid']);
            }
        });
    }
}
