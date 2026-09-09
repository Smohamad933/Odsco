/* ==========================================================================
   Odsco Messenger — اپلیکیشن کلاینت
   --------------------------------------------------------------------------
   • همه درخواست‌ها از TG.api() می‌روند (CSRF خودکار)
   • پیام‌ها اول در IndexedDB دستگاه ذخیره و از همان‌جا رندر می‌شوند
   • همگام‌سازی افزایشی با سرور (after=lastServerId)
   ========================================================================== */

(function (global) {
    'use strict';

    const TG = {
        me: null,
        csrf: '',
        apiBase: 'api.php',
        pollTimer: null,
        storeEnabled: true,
        storeUser: ''
    };

    /* -------------------------------------------------------------------- */
    /* ابزارهای عمومی                                                       */
    /* -------------------------------------------------------------------- */

    TG.esc = function (s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    TG.nl2br = function (s) { return TG.esc(s).replace(/\n/g, '<br>'); };

    TG.toast = function (msg, ms) {
        const el = document.getElementById('tgToast');
        if (!el) { return; }
        el.textContent = msg;
        el.classList.add('on');
        clearTimeout(TG._toastT);
        TG._toastT = setTimeout(function () { el.classList.remove('on'); }, ms || 2600);
    };

    TG.lightbox = function (src) {
        const box = document.getElementById('tgLightbox');
        const img = document.getElementById('tgLightboxImg');
        if (!box || !img) { window.open(src, '_blank'); return; }
        img.src = src;
        box.classList.add('on');
    };

    TG.fmtBytes = function (b) {
        b = Number(b) || 0;
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
        if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
        return (b / 1073741824).toFixed(2) + ' GB';
    };

    TG.faDigits = function (v) {
        return String(v).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; });
    };

    /** «امروز / دیروز / ۱۶ شهریور» */
    TG.dayLabel = function (iso) {
        if (!iso) return '';
        const d = new Date(String(iso).replace(' ', 'T'));
        const now = new Date();
        const y = new Date(now.getTime() - 86400000);
        const same = function (a, b) { return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate(); };
        if (same(d, now)) return 'امروز';
        if (same(d, y)) return 'دیروز';
        try { return new Intl.DateTimeFormat('fa-IR', { day: 'numeric', month: 'long', year: 'numeric' }).format(d); }
        catch (e) { return iso.slice(0, 10); }
    };

    TG.timeShort = function (iso) {
        if (!iso) return '';
        const d = new Date(String(iso).replace(' ', 'T'));
        if (isNaN(d)) return '';
        const now = new Date();
        if (d.toDateString() === now.toDateString()) {
            return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        }
        return TG.dayLabel(iso);
    };

    TG.iconBtn = function (title, icon, cls, onclick) {
        return '<button type="button" class="icon-btn ' + (cls || '') + '" title="' + TG.esc(title) + '"'
             + (onclick ? ' onclick="' + onclick + '"' : '') + '>' + icon + '</button>';
    };

    /* -------------------------------------------------------------------- */
    /* ارتباط با سرور                                                       */
    /* -------------------------------------------------------------------- */

    TG.api = function (action, data, opts) {
        opts = opts || {};
        const body = new URLSearchParams();
        body.set('a', action);
        if (!opts.noCsrf) body.set('csrf_token', TG.csrf);
        Object.keys(data || {}).forEach(function (k) {
            const v = data[k];
            if (Array.isArray(v)) v.forEach(function (item) { body.append(k + '[]', item); });
            else if (v !== undefined && v !== null) body.set(k, v);
        });

        return fetch(TG.apiBase, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
        }).then(function (r) {
            return r.json().catch(function () { return { success: false, message: 'پاسخ نامعتبر از سرور' }; });
        }).then(function (json) {
            if (json && json.auth === false) {
                TG.toast('⏳ نشست شما منقضی شده، در حال انتقال به صفحه ورود…');
                setTimeout(function () { location.href = 'login.php'; }, 1400);
                throw new Error('auth');
            }
            if (!json || !json.success) throw new Error((json && json.message) || 'خطای نامشخص');
            return json;
        });
    };

    TG.get = function (action, params) {
        const q = new URLSearchParams(params || {});
        q.set('a', action);
        return fetch(TG.apiBase + '?' + q.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (!json || !json.success) throw new Error((json && json.message) || 'خطا');
                return json;
            });
    };

    TG.upload = function (action, formData, onProgress) {
        formData.set('a', action);
        formData.set('csrf_token', TG.csrf);
        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', TG.apiBase, true);
            xhr.withCredentials = true;
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            if (onProgress) {
                xhr.upload.onprogress = function (e) {
                    if (e.lengthComputable) onProgress(Math.round((e.loaded / e.total) * 100));
                };
            }
            xhr.onload = function () {
                let json;
                try { json = JSON.parse(xhr.responseText); } catch (e) { reject(new Error('پاسخ نامعتبر سرور')); return; }
                if (!json.success) reject(new Error(json.message || 'خطا در آپلود'));
                else resolve(json);
            };
            xhr.onerror = function () { reject(new Error('خطای شبکه در آپلود')); };
            xhr.send(formData);
        });
    };

    /* -------------------------------------------------------------------- */
    /* راه‌اندازی                                                           */
    /* -------------------------------------------------------------------- */

    TG.boot = function (cfg) {
        cfg = cfg || {};
        TG.me = cfg.me || null;
        TG.csrf = cfg.csrf || '';
        TG.storeUser = (TG.me && TG.me.uid) || '';

        const root = document.getElementById('tgRoot');
        if (root) {
            TG.csrf = root.dataset.csrf || TG.csrf;
            try { TG.me = JSON.parse(root.dataset.me || 'null') || TG.me; } catch (e) { /* ignore */ }
            TG.storeUser = root.dataset.user || TG.storeUser;
        }

        // فعال/غیرفعال کردن حافظه محلی طبق انتخاب کاربر
        TG.storeEnabled = LocalStore.isEnabled();

        return LocalStore.init(TG.storeUser).then(function (ok) {
            TG.storeReady = ok;
            TG.updateStoreBar();
            return LocalStore.persist().then(function () { return TG; });
        }).catch(function () { return TG; });
    };

    TG.updateStoreBar = function () {
        const bar = document.getElementById('tgStoreBar');
        if (!bar) return;

        if (!TG.storeEnabled || !TG.storeReady) {
            bar.className = 'tg-store-bar warn';
            bar.innerHTML = '<span class="dot"></span> ذخیره‌سازی روی دستگاه <b>خاموش</b> است — تاریخچه فقط روی سرور می‌ماند.';
            return;
        }

        LocalStore.stats().then(function (s) {
            bar.className = 'tg-store-bar';
            let quota = '';
            if (s.quota) quota = ' · ' + TG.fmtBytes(s.usage) + ' از ' + TG.fmtBytes(s.quota);
            bar.innerHTML = '<span class="dot"></span> 📱 '
                + '<b>' + TG.faDigits(s.messages) + '</b> پیام و <b>' + TG.faDigits(s.media) + '</b> فایل روی این دستگاه ذخیره شده'
                + quota;
        });
    };

    /* -------------------------------------------------------------------- */
    /* رندر پیام                                                            */
    /* -------------------------------------------------------------------- */

    TG.mediaHtml = function (m) {
        const kind = m.type;
        const path = m.file_path ? ('../' + String(m.file_path).replace(/\\/g, '/')) : '';

        if (kind === 'image') {
            return '<div class="tg-media" data-media="1" data-uid="' + TG.esc(m.uid) + '" data-src="' + TG.esc(path) + '">'
                 + '<img src="' + TG.esc(path) + '" alt="" loading="lazy" '
                 + 'onerror="this.parentNode.innerHTML=\'<div class=&quot;tg-file&quot;><div class=&quot;tg-file-ico&quot;>⚠️</div><div class=&quot;tg-file-meta&quot;><div class=&quot;tg-file-name&quot;>فایل روی سرور پاک شده</div><div class=&quot;tg-file-size&quot;>نسخه روی دستگاه شما موجود است</div></div></div>\'"'
                 + '></div>';
        }
        if (kind === 'video') {
            return '<div class="tg-media"><video src="' + TG.esc(path) + '" controls preload="metadata"></video></div>';
        }
        if (kind === 'voice') {
            return '<div class="tg-media"><audio src="' + TG.esc(path) + '" controls preload="metadata" style="width:100%;min-width:210px"></audio></div>';
        }
        const meta = m.meta || {};
        return '<a class="tg-file" href="' + TG.esc(path) + '" download="' + TG.esc(m.file_name || '') + '" '
             + 'data-dl="1" data-uid="' + TG.esc(m.uid) + '" data-src="' + TG.esc(path) + '" data-name="' + TG.esc(m.file_name || '') + '">'
             + '<span class="tg-file-ico">' + TG.esc(meta.icon || '📄') + '</span>'
             + '<span class="tg-file-meta"><span class="tg-file-name">' + TG.esc(m.file_name || 'فایل') + '</span>'
             + '<span class="tg-file-size">' + TG.fmtBytes((m.file_size || 0) * 1048576) + '</span></span>'
             + '<span class="tg-file-dl">⬇</span></a>';
    };

    TG.reactionsHtml = function (m) {
        const list = m.reactions || [];
        if (!list.length) return '';
        const grouped = {};
        list.forEach(function (r) {
            grouped[r.emoji] = grouped[r.emoji] || { count: 0, mine: false };
            grouped[r.emoji].count++;
            if (r.user_uid === (TG.me && TG.me.uid)) grouped[r.emoji].mine = true;
        });
        let html = '<div class="tg-reactions">';
        Object.keys(grouped).forEach(function (emoji) {
            const g = grouped[emoji];
            html += '<button type="button" class="tg-reaction' + (g.mine ? ' mine' : '') + '" data-react="' + TG.esc(m.uid) + '" data-emoji="' + TG.esc(emoji) + '">'
                  + emoji + ' <span>' + TG.faDigits(g.count) + '</span></button>';
        });
        return html + '</div>';
    };

    TG.tickHtml = function (m, lastReadByOther) {
        if (!m.is_mine) return '';
        const read = lastReadByOther && m.server_id <= lastReadByOther;
        return '<span class="ticks' + (read ? ' read' : '') + '">' + (read ? '✓✓' : '✓') + '</span>';
    };

    /**
     * @param {object} m پیام
     * @param {object} ctx {showSender, lastReadByOther}
     */
    TG.renderMessage = function (m, ctx) {
        ctx = ctx || {};

        if (m.is_system) {
            return '<div class="tg-sys" data-uid="' + TG.esc(m.uid) + '">' + TG.esc(m.content) + '</div>';
        }

        const cls = ['tg-msg', m.is_mine ? 'out' : 'in'];
        const hasMedia = ['image', 'video', 'voice', 'file'].indexOf(m.type) >= 0;
        if (hasMedia) cls.push('has-media');

        let html = '';

        if (m.reply_message) {
            const r = m.reply_message;
            html += '<div class="tg-reply-box" data-goto="' + TG.esc(r.uid) + '">'
                  + '<div class="n">' + TG.esc(r.sender_name || '') + '</div>'
                  + '<div class="t">' + TG.esc(String(r.content || (r.type === 'file' ? '📎 فایل' : '📷 رسانه')).slice(0, 70)) + '</div></div>';
        }

        if (hasMedia) html += TG.mediaHtml(m);
        if (m.content) html += '<div class="tg-msg-text">' + TG.linkify(m.content) + '</div>';

        html += '<div class="tg-meta">';
        if (m.edited_at) html += '<span class="edited">ویرایش‌شده</span>';
        html += '<span>' + TG.timeShort(m.created_at) + '</span>';
        html += TG.tickHtml(m, ctx.lastReadByOther);
        html += '</div>';

        html += TG.reactionsHtml(m);

        const actions = '<div class="tg-msg-actions">'
            + '<button type="button" data-act="reply" title="پاسخ">↩</button>'
            + '<button type="button" data-act="react" title="واکنش">😊</button>'
            + (m.is_mine ? '<button type="button" data-act="edit" title="ویرایش">✎</button>' : '')
            + '<button type="button" data-act="pin" title="سنجاق">' + (m.is_pinned ? '📌' : '📍') + '</button>'
            + '<button type="button" data-act="delete" title="حذف">🗑</button>'
            + '</div>';

        const sender = (!m.is_mine && ctx.showSender)
            ? '<div class="tg-msg-sender">' + TG.esc(m.sender_name || '') + '</div>' : '';

        return '<div class="' + cls.join(' ') + '" data-uid="' + TG.esc(m.uid) + '" data-server="' + (m.server_id || 0)
             + '" data-mine="' + (m.is_mine ? 1 : 0) + '" data-type="' + TG.esc(m.type) + '">'
             + sender + html + actions + '</div>';
    };

    TG.linkify = function (text) {
        const safe = TG.esc(text);
        return safe
            .replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>')
            .replace(/\n/g, '<br>');
    };

    /* -------------------------------------------------------------------- */
    /* مودال ساده                                                           */
    /* -------------------------------------------------------------------- */

    TG.modal = function (title, bodyHtml, buttons, wide) {
        TG.closeModal();
        const wrap = document.createElement('div');
        wrap.className = 'tg-modal on';
        wrap.id = 'tgModal';
        wrap.innerHTML = '<div class="tg-modal-box' + (wide ? ' wide' : '') + '">'
            + '<div class="tg-modal-head"><h3>' + TG.esc(title) + '</h3>'
            + '<button type="button" class="icon-btn" data-close>✕</button></div>'
            + '<div class="tg-modal-body">' + bodyHtml + '</div>'
            + (buttons ? '<div class="tg-modal-foot">' + buttons + '</div>' : '')
            + '</div>';
        document.body.appendChild(wrap);

        wrap.addEventListener('click', function (e) {
            if (e.target === wrap || e.target.closest('[data-close]')) TG.closeModal();
        });
        return wrap;
    };

    TG.closeModal = function () {
        const el = document.getElementById('tgModal');
        if (el) el.remove();
    };

    TG.confirm = function (title, text, onYes, yesLabel, danger) {
        const box = TG.modal(title, '<p style="font-size:13.5px;line-height:2;color:#c7d5e2">' + TG.esc(text) + '</p>',
            '<button type="button" class="tg-btn ghost" data-close>انصراف</button>'
            + '<button type="button" class="tg-btn' + (danger ? ' danger' : '') + '" id="tgConfirmYes">' + TG.esc(yesLabel || 'تایید') + '</button>');
        box.querySelector('#tgConfirmYes').addEventListener('click', function () {
            TG.closeModal();
            onYes();
        });
    };

    TG.prompt = function (title, label, initial, onOk) {
        const box = TG.modal(title,
            '<div class="tg-field"><label>' + TG.esc(label) + '</label>'
            + '<input type="text" id="tgPromptInput" value="' + TG.esc(initial || '') + '"></div>',
            '<button type="button" class="tg-btn ghost" data-close>انصراف</button>'
            + '<button type="button" class="tg-btn" id="tgPromptOk">ثبت</button>');
        const input = box.querySelector('#tgPromptInput');
        setTimeout(function () { input.focus(); input.select(); }, 60);
        box.querySelector('#tgPromptOk').addEventListener('click', function () {
            const v = input.value.trim();
            TG.closeModal();
            if (v) onOk(v);
        });
    };

    /* -------------------------------------------------------------------- */
    /* منوی انتخاب ایموجی                                                   */
    /* -------------------------------------------------------------------- */

    TG.EMOJIS = ['👍', '❤️', '🔥', '🎉', '😂', '😮', '😢', '🙏', '👏', '💯', '✅', '⚠️'];

    TG.pickEmoji = function (onPick) {
        let html = '<div style="display:flex;flex-wrap:wrap;gap:6px">';
        TG.EMOJIS.forEach(function (e) {
            html += '<button type="button" class="tg-reaction" data-e="' + e + '" style="font-size:19px;padding:6px 10px">' + e + '</button>';
        });
        html += '</div>';
        const box = TG.modal('واکنش به پیام', html, '<button type="button" class="tg-btn ghost" data-close>بستن</button>');
        box.querySelectorAll('[data-e]').forEach(function (b) {
            b.addEventListener('click', function () {
                const e = b.dataset.e;
                TG.closeModal();
                onPick(e);
            });
        });
    };

    /* -------------------------------------------------------------------- */

    global.TG = TG;

})(typeof window !== 'undefined' ? window : this);
