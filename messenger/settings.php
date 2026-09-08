<?php
/**
 * ============================================================================
 *  Odsco Messenger — تنظیمات، حریم خصوصی و ذخیره‌سازی روی دستگاه
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

m_check_login();

$me    = m_current_user();
$myUid = $me['uid'];
$activeChatKey = '';

$user = Users::find($myUid) ?? [];
$devices = Messenger::devices($myUid);

$retentionDays = (int)Settings::get('msg_retention_days', 0);
$purgeOnRead   = (bool)Settings::get('msg_purge_on_read');
$maxUploadMb   = (int)Settings::get('msg_max_file_mb', 32);

include __DIR__ . '/sidebar.php';
m_head('تنظیمات · پیام‌رسان');
?>
<body>
<div class="tg-app" id="tgRoot" data-csrf="<?php echo e(csrf_token()); ?>" data-user="<?php echo e($myUid); ?>"
     data-me='<?php echo json_encode($me, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'>

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="tg-main">
        <div class="tg-store-bar" id="tgStoreBar"><span class="dot"></span> در حال آماده‌سازی حافظه دستگاه…</div>

        <header class="tg-head">
            <a href="index.php" class="icon-btn" title="بازگشت">→</a>
            <div class="tg-head-info">
                <div class="tg-head-name">⚙️ تنظیمات</div>
                <div class="tg-head-sub">پروفایل، حریم خصوصی و ذخیره‌سازی</div>
            </div>
        </header>

        <div class="tg-msgs" style="padding:14px;gap:14px;align-items:stretch">

            <!-- پروفایل -->
            <section style="background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:16px;padding:18px">
                <h3 style="font-size:14.5px;margin-bottom:14px">👤 پروفایل من</h3>
                <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
                    <?php echo m_avatar($me['name'], $me['photo'], 'lg'); ?>
                    <div style="flex:1;min-width:200px">
                        <div class="tg-field"><label>نام و نام خانوادگی</label>
                            <input type="text" id="pName" value="<?php echo e($user['full_name'] ?? ''); ?>"></div>
                        <div class="tg-field"><label>شماره تماس</label>
                            <input type="text" id="pPhone" value="<?php echo e($user['phone'] ?? ''); ?>"></div>
                        <div class="tg-field"><label>درباره من</label>
                            <textarea id="pBio" rows="2"><?php echo e($user['bio'] ?? ''); ?></textarea></div>
                        <button type="button" class="tg-btn" id="btnSaveProfile">ذخیره پروفایل</button>
                    </div>
                </div>
                <div style="margin-top:14px;font-size:12px;color:var(--tg-muted);line-height:2">
                    نام کاربری: <b style="color:#c7d5e2"><?php echo e($user['username'] ?? ''); ?></b>
                    · نقش: <b style="color:#c7d5e2"><?php echo e(Users::roleLabel((string)($user['role'] ?? ''))); ?></b>
                    <?php if (!empty($user['last_login'])): ?>
                        · آخرین ورود: <?php echo e(jalali_datetime((string)$user['last_login'])); ?>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ذخیره‌سازی روی دستگاه -->
            <section style="background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:16px;padding:18px">
                <h3 style="font-size:14.5px;margin-bottom:6px">📱 ذخیره‌سازی روی دستگاه شما</h3>
                <p style="font-size:12.5px;color:var(--tg-muted);line-height:2;margin-bottom:14px">
                    تاریخچه پیام‌ها و فایل‌ها در حافظه <b>همین گوشی/کامپیوتر</b> نگه داشته می‌شود،
                    بنابراین حتی اگر از سرور پاک شوند یا اینترنت قطع باشد، روی دستگاه شما باقی می‌مانند.
                </p>

                <div id="storeStats" style="background:var(--tg-bg);border-radius:12px;padding:14px;margin-bottom:14px">
                    <div class="tg-loading"><div class="tg-spinner"></div>در حال محاسبه…</div>
                </div>

                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button type="button" class="tg-btn" id="btnPersist">🔒 ماندگار کردن حافظه</button>
                    <button type="button" class="tg-btn ghost" id="btnExportAll">📤 خروجی کامل (JSON)</button>
                    <button type="button" class="tg-btn danger" id="btnWipe">🗑 پاک کردن همه داده‌های این دستگاه</button>
                </div>

                <label class="tg-pick-item" style="margin-top:16px;background:var(--tg-bg);border-radius:12px">
                    <input type="checkbox" id="chkStore" checked>
                    <span class="nm">
                        <span class="n">ذخیره‌سازی محلی فعال باشد</span>
                        <span class="s">اگر خاموش کنید، داده‌های ذخیره‌شده روی این دستگاه پاک می‌شود.</span>
                    </span>
                </label>
            </section>

            <!-- حریم خصوصی سرور -->
            <section style="background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:16px;padding:18px">
                <h3 style="font-size:14.5px;margin-bottom:6px">🔐 نگه‌داری روی سرور</h3>
                <p style="font-size:12.5px;color:var(--tg-muted);line-height:2;margin-bottom:12px">
                    سرور فقط برای <b>تحویل</b> پیام به طرف مقابل استفاده می‌شود. سیاست فعلی شرکت:
                </p>
                <ul style="font-size:13px;line-height:2.2;padding-right:18px;color:#c7d5e2">
                    <li>مدت نگه‌داری پیام روی سرور:
                        <b><?php echo $retentionDays > 0 ? fa_number($retentionDays) . ' روز' : 'بدون محدودیت'; ?></b></li>
                    <li>حذف پیام خصوصی بعد از خوانده‌شدن توسط هر دو طرف:
                        <b><?php echo $purgeOnRead ? '✅ فعال' : '❌ غیرفعال'; ?></b></li>
                    <li>حداکثر حجم فایل ارسالی: <b><?php echo fa_number($maxUploadMb); ?> مگابایت</b></li>
                </ul>
                <p style="font-size:11.5px;color:var(--tg-muted);margin-top:10px">
                    🔧 این موارد توسط مدیر در <b>پنل مدیریت ← تنظیمات ← پیام‌رسان</b> تعیین می‌شود.
                </p>
            </section>

            <!-- دستگاه‌ها -->
            <section style="background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:16px;padding:18px">
                <h3 style="font-size:14.5px;margin-bottom:14px">💻 دستگاه‌های فعال</h3>
                <?php if (!$devices): ?>
                    <p style="font-size:13px;color:var(--tg-muted)">دستگاه ثبت‌شده‌ای نیست.</p>
                <?php else: ?>
                    <?php foreach ($devices as $i => $d): ?>
                    <div class="tg-pick-item" style="cursor:default;background:var(--tg-bg);border-radius:12px;margin-bottom:8px">
                        <div class="tg-file-ico"><?php echo e(str_contains((string)$d['platform'], 'Android') || str_contains((string)$d['platform'], 'iOS') ? '📱' : '💻'); ?></div>
                        <span class="nm">
                            <span class="n"><?php echo e($d['device_name'] ?: 'دستگاه نامشخص'); ?><?php echo $i === 0 ? ' <span style="color:var(--tg-online);font-size:11px">· همین دستگاه</span>' : ''; ?></span>
                            <span class="s"><?php echo e($d['platform']); ?> · <?php echo e($d['browser']); ?> · <?php echo e($d['ip']); ?></span>
                            <span class="s">آخرین فعالیت: <?php echo e(jalali_datetime((string)$d['last_active'])); ?></span>
                        </span>
                        <?php if ($i > 0): ?>
                            <button type="button" class="icon-btn" data-revoke="<?php echo e($d['uid']); ?>" title="خروج از این دستگاه" style="color:var(--tg-danger)">✕</button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <!-- رمز عبور -->
            <section style="background:var(--tg-panel);border:1px solid var(--tg-line);border-radius:16px;padding:18px">
                <h3 style="font-size:14.5px;margin-bottom:14px">🔑 تغییر رمز عبور</h3>
                <div class="tg-field"><label>رمز عبور فعلی</label><input type="password" id="pwOld" autocomplete="current-password"></div>
                <div class="tg-field"><label>رمز عبور جدید (حداقل ۸ کاراکتر)</label><input type="password" id="pwNew" autocomplete="new-password"></div>
                <div class="tg-field"><label>تکرار رمز جدید</label><input type="password" id="pwNew2" autocomplete="new-password"></div>
                <button type="button" class="tg-btn" id="btnChangePw">تغییر رمز عبور</button>
            </section>

            <section style="text-align:center;padding:10px 0 20px">
                <a href="logout.php" class="tg-btn danger" style="padding:11px 26px">🚪 خروج از حساب</a>
            </section>
        </div>
    </main>
</div>

<?php m_foot(); ?>
<script>
(function () {
    'use strict';

    TG.boot({}).then(function () {
        try { TG.me = JSON.parse(document.getElementById('tgRoot').dataset.me || 'null'); } catch (e) {}
        refreshStats();
    });

    function refreshStats() {
        const box = document.getElementById('storeStats');
        LocalStore.stats().then(function (s) {
            LocalStore.isPersistent().then(function (persisted) {
                let html = '<div style="display:flex;gap:20px;flex-wrap:wrap;font-size:13px">';
                html += '<div><div style="color:var(--tg-muted);font-size:11px">پیام‌های ذخیره‌شده</div><b style="font-size:17px">' + TG.faDigits(s.messages) + '</b></div>';
                html += '<div><div style="color:var(--tg-muted);font-size:11px">فایل و عکس</div><b style="font-size:17px">' + TG.faDigits(s.media) + '</b></div>';
                if (s.quota) {
                    const pct = Math.round((s.usage / s.quota) * 100);
                    html += '<div style="flex:1;min-width:160px"><div style="color:var(--tg-muted);font-size:11px">فضای اشغال‌شده</div>'
                          + '<b style="font-size:17px">' + TG.fmtBytes(s.usage) + '</b>'
                          + '<div style="height:6px;background:#0b1219;border-radius:4px;margin-top:6px;overflow:hidden">'
                          + '<div style="height:100%;width:' + Math.min(100, pct) + '%;background:' + (pct > 85 ? '#ff5c5c' : '#5288c1') + '"></div></div>'
                          + '<div style="font-size:10.5px;color:var(--tg-muted);margin-top:4px">از ' + TG.fmtBytes(s.quota) + ' فضای مجاز مرورگر</div></div>';
                }
                html += '</div>';
                html += '<div style="font-size:11.5px;color:' + (persisted ? '#9ee7a8' : '#ffd9a0') + ';margin-top:12px">'
                      + (persisted ? '✅ حافظه ماندگار است — مرورگر خودکار پاک نمی‌کند'
                                   : '⚠️ حافظه ماندگار نیست؛ برای اطمینان دکمه «ماندگار کردن حافظه» را بزنید')
                      + '</div>';
                box.innerHTML = html;
            });
        });
    }

    document.getElementById('btnSaveProfile').addEventListener('click', function () {
        TG.api('profile_update', {
            full_name: document.getElementById('pName').value.trim(),
            phone: document.getElementById('pPhone').value.trim(),
            bio: document.getElementById('pBio').value.trim()
        }).then(function () { TG.toast('✅ پروفایل ذخیره شد'); setTimeout(function () { location.reload(); }, 800); })
          .catch(function (e) { TG.toast('⚠️ ' + e.message); });
    });

    document.getElementById('btnChangePw').addEventListener('click', function () {
        const a = document.getElementById('pwOld').value;
        const b = document.getElementById('pwNew').value;
        const c = document.getElementById('pwNew2').value;
        if (b !== c) { TG.toast('⚠️ تکرار رمز مطابقت ندارد'); return; }
        TG.api('password', { old_password: a, new_password: b })
            .then(function () { TG.toast('✅ رمز عبور تغییر کرد'); document.getElementById('pwOld').value = document.getElementById('pwNew').value = document.getElementById('pwNew2').value = ''; })
            .catch(function (e) { TG.toast('⚠️ ' + e.message); });
    });

    document.getElementById('btnPersist').addEventListener('click', function () {
        LocalStore.persist().then(function (ok) {
            TG.toast(ok ? '✅ حافظه ماندگار شد' : '⚠️ مرورگر اجازه ماندگاری نداد');
            refreshStats();
        });
    });

    document.getElementById('chkStore').addEventListener('change', function () {
        const on = this.checked;
        if (!on) {
            TG.confirm('خاموش کردن ذخیره‌سازی محلی',
                'همه پیام‌ها و فایل‌های ذخیره‌شده روی این دستگاه پاک می‌شود. مطمئنید؟',
                function () {
                    LocalStore.setEnabled(false);
                    TG.toast('ذخیره‌سازی محلی خاموش شد');
                    setTimeout(function () { location.reload(); }, 900);
                }, 'خاموش کن', true);
            this.checked = true;
            return;
        }
        LocalStore.setEnabled(true);
        TG.toast('✅ ذخیره‌سازی محلی فعال شد');
        setTimeout(function () { location.reload(); }, 800);
    });

    document.getElementById('btnExportAll').addEventListener('click', function () {
        Promise.all([LocalStore.loadChats(), new Promise(function (res) {
            const all = [];
            const req = LocalStore.db.transaction('messages', 'readonly').objectStore('messages').openCursor();
            req.onsuccess = function (e) { const c = e.target.result; if (c) { all.push(c.value); c.continue(); } else res(all); };
            req.onerror = function () { res([]); };
        })]).then(function (r) {
            const payload = {
                exported_at: new Date().toISOString(),
                user: (TG.me && TG.me.name) || '',
                chats: r[0],
                messages: r[1]
            };
            const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'odsco-messenger-backup-' + new Date().toISOString().slice(0, 10) + '.json';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
            setTimeout(function () { URL.revokeObjectURL(url); }, 3000);
            TG.toast('✅ نسخه پشتیبان روی دستگاه ذخیره شد');
        }).catch(function () { TG.toast('⚠️ خطا در تهیه خروجی'); });
    });

    document.getElementById('btnWipe').addEventListener('click', function () {
        TG.confirm('پاک کردن داده‌های این دستگاه',
            'همه پیام‌ها، عکس‌ها و فایل‌های ذخیره‌شده روی این گوشی/کامپیوتر پاک می‌شود. این کار قابل بازگشت نیست!',
            function () {
                LocalStore.wipeAll().then(function () {
                    TG.toast('🗑 داده‌های این دستگاه پاک شد');
                    setTimeout(function () { location.reload(); }, 900);
                });
            }, 'پاک کن', true);
    });

    document.querySelectorAll('[data-revoke]').forEach(function (b) {
        b.addEventListener('click', function () {
            TG.confirm('خروج دستگاه', 'این دستگاه از حساب شما خارج می‌شود.', function () {
                TG.api('device_revoke', { device_uid: b.dataset.revoke })
                    .then(function () { TG.toast('✅ دستگاه حذف شد'); setTimeout(function () { location.reload(); }, 700); })
                    .catch(function (e) { TG.toast('⚠️ ' + e.message); });
            }, 'خروج', true);
        });
    });
})();
</script>
</body>
</html>
