/* ==========================================================================
   Odsco Messenger — کنترل‌کننده صفحه گفتگو
   --------------------------------------------------------------------------
   جریان کار:
     ۱) پیام‌های اولیه از حافظه دستگاه (IndexedDB) رندر می‌شوند → فوری و آفلاین
     ۲) همگام‌سازی افزایشی با سرور (after = آخرین server_id)
     ۳) پیام جدید/آپلود → سرور → ذخیره در IndexedDB → رندر
     ۴) هر ۳ ثانیه پیام‌های جدید + وضعیت تایپ + آنلاین
   ========================================================================== */

(function (global) {
    'use strict';

    const Chat = {
        type: 'private',
        uid: '',
        chatKey: '',
        messages: [],
        byUid: {},
        lastServerId: 0,
        oldestServerId: 0,
        replyTo: null,
        pendingFile: null,
        state: {},
        otherUid: '',
        hasMore: true,
        loadingOlder: false,
        atBottom: true,
        pollEvery: 3000
    };

    const $ = function (sel, root) { return (root || document).querySelector(sel); };
    const $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    /* -------------------------------------------------------------------- */
    /* راه‌اندازی                                                           */
    /* -------------------------------------------------------------------- */

    Chat.init = function (cfg) {
        this.type = cfg.type;
        this.uid = cfg.uid;
        this.chatKey = cfg.chat_key;
        this.otherUid = cfg.other_uid || '';

        this.bindUI();

        const self = this;
        return LocalStore.loadMessages(this.chatKey, 60).then(function (local) {
            if (local && local.length) {
                self.messages = local;
                self.paint(true);
                self.scrollToBottom(false);
            } else {
                self.showLoading(true);
            }
            return self.sync(true);
        }).then(function () {
            self.showLoading(false);
            self.markRead();
            self.startPolling();
            self.restoreDraft();
        }).catch(function (e) {
            self.showLoading(false);
            TG.toast('⚠️ ' + e.message);
        });
    };

    Chat.showLoading = function (on) {
        const el = $('#tgLoading');
        if (el) el.style.display = on ? '' : 'none';
    };

    /* -------------------------------------------------------------------- */
    /* رویدادهای رابط                                                       */
    /* -------------------------------------------------------------------- */

    Chat.bindUI = function () {
        const self = this;
        const input = $('#tgInput');
        const sendBtn = $('#tgSend');
        const fileInput = $('#tgFile');

        // ارتفاع خودکار textarea
        if (input) {
            input.addEventListener('input', function () {
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 140) + 'px';
                self.saveDraft(input.value);
                self.sendTyping();
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                    e.preventDefault();
                    self.send();
                }
            });
        }

        if (sendBtn) sendBtn.addEventListener('click', function () { self.send(); });

        if (fileInput) {
            fileInput.addEventListener('change', function () {
                if (fileInput.files && fileInput.files[0]) self.attach(fileInput.files[0]);
                fileInput.value = '';
            });
        }

        const attachBtn = $('#tgAttach');
        if (attachBtn) attachBtn.addEventListener('click', function () { fileInput && fileInput.click(); });

        // لغو پاسخ / ضمیمه
        const cancelReply = $('#tgReplyCancel');
        if (cancelReply) cancelReply.addEventListener('click', function () { self.clearReply(); });
        const cancelAttach = $('#tgAttachCancel');
        if (cancelAttach) cancelAttach.addEventListener('click', function () { self.clearAttach(); });

        // رویدادهای ناحیه پیام‌ها (event delegation)
        const area = $('#tgMessages');
        if (area) {
            area.addEventListener('click', function (e) {
                const msgEl = e.target.closest('.tg-msg');

                // واکنش
                const reactBtn = e.target.closest('[data-react]');
                if (reactBtn) {
                    self.react(reactBtn.dataset.react, reactBtn.dataset.emoji);
                    return;
                }

                // رفتن به پیام پاسخ
                const goto = e.target.closest('[data-goto]');
                if (goto) { self.gotoMessage(goto.dataset.goto); return; }

                // بزرگ‌نمایی تصویر
                const media = e.target.closest('.tg-media img');
                if (media) { self.openMedia(media.closest('.tg-media')); return; }

                // دانلود فایل با کش روی دستگاه
                const dl = e.target.closest('[data-dl]');
                if (dl) {
                    e.preventDefault();
                    self.downloadToDevice(dl);
                    return;
                }

                if (!msgEl) return;

                // دکمه‌های عملیات
                const act = e.target.closest('[data-act]');
                if (act) {
                    e.stopPropagation();
                    self.messageAction(msgEl.dataset.uid, act.dataset.act);
                    return;
                }

                // لمس طولانی / راست‌کلیک روی پیام در موبایل
                msgEl.classList.toggle('menu-open');
                setTimeout(function () { msgEl.classList.remove('menu-open'); }, 4000);
            });

            area.addEventListener('contextmenu', function (e) {
                const msgEl = e.target.closest('.tg-msg');
                if (!msgEl) return;
                e.preventDefault();
                self.openMessageMenu(msgEl);
            });

            // اسکرول: بارگذاری پیام‌های قدیمی‌تر + دکمه «برو به پایین»
            area.addEventListener('scroll', function () {
                const nearTop = area.scrollTop < 220;
                const nearBottom = area.scrollHeight - area.scrollTop - area.clientHeight < 120;
                self.atBottom = nearBottom;
                const jump = $('#tgJump');
                if (jump) jump.classList.toggle('on', !nearBottom);
                if (nearTop) self.loadOlder();
            });
        }

        const jump = $('#tgJump');
        if (jump) jump.addEventListener('click', function () { self.scrollToBottom(true); });

        // جستجو در گفتگو
        const searchBtn = $('#tgSearchBtn');
        if (searchBtn) searchBtn.addEventListener('click', function () { self.openSearch(); });

        // بستن منوها با کلیک بیرون
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.tg-menu') && !e.target.closest('[data-menu]')) {
                $$('.tg-menu.on').forEach(function (m) { m.classList.remove('on'); });
            }
            if (!e.target.closest('.tg-msg')) {
                $$('.tg-msg.menu-open').forEach(function (m) { m.classList.remove('menu-open'); });
            }
        });

        // ذخیره پیش‌نویس هنگام خروج
        window.addEventListener('beforeunload', function () {
            if (input) self.saveDraft(input.value, true);
        });

        // وقتی کاربر به تب برمی‌گردد، سریع همگام‌سازی کن
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { self.sync(); self.markRead(); }
        });
    };

    /* -------------------------------------------------------------------- */
    /* همگام‌سازی با سرور                                                    */
    /* -------------------------------------------------------------------- */

    Chat.sync = function (initial) {
        const self = this;
        const params = { chat_type: this.type, chat_uid: this.uid, limit: initial ? 60 : 100 };
        if (!initial && this.lastServerId) params.after = this.lastServerId;

        return TG.get('messages', params).then(function (res) {
            self.state = res.state || self.state;
            self.hasMore = !!res.has_more;
            self.setOtherLastRead(res.state ? res.state.last_read_id : 0);

            const incoming = res.messages || [];
            if (incoming.length) {
                const fresh = incoming.filter(function (m) { return !self.byUid[m.uid]; });
                self.ingest(incoming);
                if (fresh.length) {
                    self.appendBatch(fresh);
                    if (self.atBottom) self.scrollToBottom(true);
                    else self.showNewMessageHint(fresh.length);
                    if (!document.hidden) self.markRead();
                }
            }

            if (initial) {
                self.messages.sort(function (a, b) { return a.server_id - b.server_id; });
                self.paint(true);
                self.scrollToBottom(false);
                self.renderPinned(res.pinned || []);
                self.updateHeader(res);
            }

            self.updateTyping(res.typing || []);
            return res;
        }).catch(function (e) {
            if (e.message !== 'auth') console.warn('sync:', e.message);
        });
    };

    /** افزودن پیام‌ها به حافظه داخلی + ذخیره روی دستگاه */
    Chat.ingest = function (list) {
        const self = this;
        list.forEach(function (m) {
            m.chat_key = self.chatKey;
            self.byUid[m.uid] = m;
            if (m.server_id > self.lastServerId) self.lastServerId = m.server_id;
            if (!self.oldestServerId || m.server_id < self.oldestServerId) self.oldestServerId = m.server_id;
        });
        // ادغام با لیست موجود و مرتب‌سازی
        const merged = {};
        this.messages.concat(list).forEach(function (m) { merged[m.uid] = m; });
        this.messages = Object.keys(merged).map(function (k) { return merged[k]; })
            .sort(function (a, b) { return a.server_id - b.server_id; });

        LocalStore.saveMessages(this.chatKey, list);
        TG.updateStoreBar();
    };

    Chat.loadOlder = function () {
        const self = this;
        if (this.loadingOlder || !this.hasMore || !this.oldestServerId) return;
        this.loadingOlder = true;

        // اول از حافظه دستگاه
        LocalStore.loadOlder(this.chatKey, this.oldestServerId, 40).then(function (local) {
            const fresh = (local || []).filter(function (m) { return !self.byUid[m.uid]; });
            if (fresh.length) {
                const h = $('#tgMessages').scrollHeight;
                self.ingest(fresh);
                self.prependBatch(fresh);
                $('#tgMessages').scrollTop = $('#tgMessages').scrollHeight - h;
                self.loadingOlder = false;
                return null;
            }
            return TG.get('messages', { chat_type: self.type, chat_uid: self.uid, limit: 40, before: self.oldestServerId });
        }).then(function (res) {
            if (!res) return;
            self.hasMore = !!res.has_more;
            const fresh = (res.messages || []).filter(function (m) { return !self.byUid[m.uid]; });
            if (!fresh.length) { self.hasMore = false; return; }
            const area = $('#tgMessages');
            const h = area.scrollHeight;
            self.ingest(fresh);
            self.prependBatch(fresh);
            area.scrollTop = area.scrollHeight - h;
        }).catch(function () { /* ignore */ })
          .then(function () { self.loadingOlder = false; });
    };

    /* -------------------------------------------------------------------- */
    /* رندر                                                                 */
    /* -------------------------------------------------------------------- */

    Chat.paint = function (full) {
        const area = $('#tgMessages');
        if (!area) return;

        const html = [];
        let lastDay = '';
        let lastSender = '';

        this.messages.forEach(function (m) {
            const day = TG.dayLabel(m.created_at);
            if (day !== lastDay) {
                html.push('<div class="tg-day">' + TG.esc(day) + '</div>');
                lastDay = day;
                lastSender = '';
            }
            const showSender = (Chat.type === 'group' && !m.is_mine && m.sender_uid !== lastSender);
            html.push(TG.renderMessage(m, { showSender: showSender, lastReadByOther: Chat.otherLastRead || 0 }));
            lastSender = m.sender_uid;
        });

        area.innerHTML = html.join('') || '<div class="tg-empty"><div class="big">💬</div>هنوز پیامی نیست. اولین پیام را بفرستید!</div>';
        this.attachMediaUrls();
    };

    Chat.appendBatch = function (list) {
        const area = $('#tgMessages');
        if (!area) return;
        const empty = area.querySelector('.tg-empty');
        if (empty) empty.remove();

        const frag = document.createDocumentFragment();
        const tmp = document.createElement('div');
        let lastDay = this.messages.length ? TG.dayLabel(this.messages[this.messages.length - 1 - list.length] ? this.messages[this.messages.length - 1 - list.length].created_at : list[0].created_at) : '';

        list.forEach(function (m) {
            const day = TG.dayLabel(m.created_at);
            if (day !== lastDay) {
                tmp.innerHTML = '<div class="tg-day">' + TG.esc(day) + '</div>';
                frag.appendChild(tmp.firstElementChild);
                lastDay = day;
            }
            tmp.innerHTML = TG.renderMessage(m, { showSender: Chat.type === 'group' && !m.is_mine, lastReadByOther: Chat.otherLastRead || 0 });
            frag.appendChild(tmp.firstElementChild);
        });

        area.appendChild(frag);
        this.attachMediaUrls();
    };

    Chat.prependBatch = function (list) {
        const area = $('#tgMessages');
        if (!area) return;
        const tmp = document.createElement('div');
        const frag = document.createDocumentFragment();
        let lastDay = '';

        list.forEach(function (m) {
            const day = TG.dayLabel(m.created_at);
            if (day !== lastDay) {
                tmp.innerHTML = '<div class="tg-day">' + TG.esc(day) + '</div>';
                frag.appendChild(tmp.firstElementChild);
                lastDay = day;
            }
            tmp.innerHTML = TG.renderMessage(m, { showSender: Chat.type === 'group' && !m.is_mine, lastReadByOther: Chat.otherLastRead || 0 });
            frag.appendChild(tmp.firstElementChild);
        });

        const first = area.querySelector('.tg-day') || area.firstElementChild;
        if (first) area.insertBefore(frag, first);
        else area.appendChild(frag);
        this.attachMediaUrls();
    };

    /** جایگزینی آدرس رسانه با نسخه کش‌شده روی دستگاه (کار آفلاین) */
    Chat.attachMediaUrls = function () {
        const nodes = $$('.tg-media[data-uid]');
        nodes.forEach(function (node) {
            if (node.dataset.localised === '1') return;
            const uid = node.dataset.uid;
            const img = node.querySelector('img');
            if (!img) { node.dataset.localised = '1'; return; }

            LocalStore.getMedia(uid).then(function (hit) {
                if (hit && hit.blob) {
                    img.src = URL.createObjectURL(hit.blob);
                    node.dataset.localised = '1';
                    return;
                }
                // کش کردن در پس‌زمینه برای استفاده آفلاین
                const src = img.getAttribute('src');
                if (!src || src.startsWith('blob:')) return;
                fetch(src, { credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.blob() : null; })
                    .then(function (blob) {
                        if (blob) LocalStore.saveMedia(uid, blob, {});
                        node.dataset.localised = '1';
                    })
                    .catch(function () { node.dataset.localised = '1'; });
            });
        });
    };

    Chat.openMedia = function (node) {
        const uid = node && node.dataset.uid;
        const img = node && node.querySelector('img');
        if (!img) return;
        if (uid) {
            LocalStore.getMedia(uid).then(function (hit) {
                TG.lightbox(hit && hit.blob ? URL.createObjectURL(hit.blob) : img.src);
            });
        } else {
            TG.lightbox(img.src);
        }
    };

    Chat.downloadToDevice = function (el) {
        const uid = el.dataset.uid;
        const src = el.dataset.src;
        const name = el.dataset.name || 'file';
        TG.toast('⬇️ در حال دانلود و ذخیره روی دستگاه…');
        LocalStore.saveToDevice(uid, src, name)
            .then(function () { TG.toast('✅ فایل دانلود و روی دستگاه شما ذخیره شد'); TG.updateStoreBar(); })
            .catch(function () { TG.toast('⚠️ فایل روی سرور موجود نیست (احتمالاً پاک شده)'); });
    };

    Chat.scrollToBottom = function (smooth) {
        const area = $('#tgMessages');
        if (!area) return;
        area.scrollTo ? area.scrollTo({ top: area.scrollHeight, behavior: smooth ? 'smooth' : 'auto' })
                      : (area.scrollTop = area.scrollHeight);
        this.atBottom = true;
        const jump = $('#tgJump');
        if (jump) jump.classList.remove('on');
    };

    Chat.gotoMessage = function (uid) {
        const el = document.querySelector('.tg-msg[data-uid="' + uid + '"]');
        if (!el) return;
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.style.transition = 'box-shadow .3s';
        el.style.boxShadow = '0 0 0 3px rgba(82,136,193,.6)';
        setTimeout(function () { el.style.boxShadow = ''; }, 1400);
    };

    Chat.showNewMessageHint = function (n) {
        const jump = $('#tgJump');
        if (jump) {
            jump.classList.add('on');
            jump.innerHTML = '↓<span style="font-size:10px;margin-right:2px">' + TG.faDigits(n) + '</span>';
        }
    };

    Chat.renderPinned = function (list) {
        const bar = $('#tgPinBar');
        if (!bar) return;
        if (!list || !list.length) { bar.style.display = 'none'; return; }
        const p = list[0];
        bar.style.display = '';
        bar.innerHTML = '<span class="ttl">📌 پیام سنجاق‌شده</span>'
            + '<span class="txt">' + TG.esc(String(p.content || 'رسانه').slice(0, 90)) + '</span>'
            + '<button type="button" class="icon-btn" data-goto="' + TG.esc(p.uid) + '" title="رفتن به پیام">↗</button>';
        const btn = bar.querySelector('[data-goto]');
        if (btn) btn.addEventListener('click', function () { Chat.gotoMessage(p.uid); });
    };

    Chat.updateHeader = function (res) {
        const sub = $('#tgHeadSub');
        if (!sub) return;
        if (this.type === 'group') {
            const g = res.group || null;
            if (g) sub.textContent = TG.faDigits(g.member_count) + ' عضو';
        }
    };

    Chat.updateTyping = function (uids) {
        const sub = $('#tgHeadSub');
        if (!sub) return;
        if (uids && uids.length) {
            const names = uids.map(function (u) { return (window.__names && window.__names[u]) || 'کاربر'; });
            sub.textContent = names.length > 1 ? (TG.faDigits(names.length) + ' نفر در حال نوشتن…') : (names[0] + ' در حال نوشتن…');
            sub.classList.add('on');
            return;
        }
        sub.classList.remove('on');
        if (this.type === 'private') {
            sub.textContent = window.__presenceText || sub.dataset.default || '';
        }
    };

    Chat.setOtherLastRead = function (id) {
        const changed = this.otherLastRead !== id;
        this.otherLastRead = id || 0;
        if (!changed) return;
        // به‌روزرسانی تیک‌ها
        $$('.tg-msg[data-mine="1"]').forEach(function (el) {
            const sid = Number(el.dataset.server || 0);
            const meta = el.querySelector('.tg-meta');
            if (!meta) return;
            let tick = meta.querySelector('.ticks');
            if (!tick) {
                tick = document.createElement('span');
                tick.className = 'ticks';
                meta.appendChild(tick);
            }
            tick.textContent = sid <= id ? '✓✓' : '✓';
            tick.classList.toggle('read', sid <= id);
        });
    };

    Chat.showNewMessageHintCount = 0;

    /* -------------------------------------------------------------------- */
    /* ارسال                                                                */
    /* -------------------------------------------------------------------- */

    Chat.send = function () {
        const input = $('#tgInput');
        const content = input ? input.value.trim() : '';
        if (!content && !this.pendingFile) return;

        const self = this;

        if (this.pendingFile) {
            this.sendFile();
            return;
        }

        const btn = $('#tgSend');
        if (btn) btn.disabled = true;

        TG.api('send', {
            chat_type: this.type,
            chat_uid: this.uid,
            content: content,
            reply_to: this.replyTo ? this.replyTo.uid : ''
        }).then(function (res) {
            if (input) { input.value = ''; input.style.height = 'auto'; }
            self.saveDraft('', true);
            self.clearReply();
            self.ingest([res.message]);
            self.appendBatch([res.message]);
            self.scrollToBottom(true);
            self.markRead();
        }).catch(function (e) {
            TG.toast('⚠️ ' + e.message);
        }).then(function () {
            if (btn) btn.disabled = false;
            if (input) input.focus();
        });
    };

    Chat.attach = function (file) {
        const maxMb = Number($('#tgRoot') ? $('#tgRoot').dataset.maxMb : 32) || 32;
        if (file.size > maxMb * 1048576) {
            TG.toast('⚠️ حجم فایل بیشتر از ' + TG.faDigits(maxMb) + ' مگابایت است');
            return;
        }
        this.pendingFile = file;

        const box = $('#tgAttachPreview');
        if (box) {
            box.classList.add('on');
            const isImg = /^image\//.test(file.type);
            box.querySelector('.thumb').innerHTML = isImg
                ? '<img src="' + URL.createObjectURL(file) + '" alt="">'
                : '<div class="tg-file-ico">📎</div>';
            box.querySelector('.name').textContent = file.name;
            box.querySelector('.size').textContent = TG.fmtBytes(file.size);
        }
    };

    Chat.clearAttach = function () {
        this.pendingFile = null;
        const box = $('#tgAttachPreview');
        if (box) box.classList.remove('on');
    };

    Chat.sendFile = function () {
        const self = this;
        const file = this.pendingFile;
        if (!file) return;

        const fd = new FormData();
        fd.set('chat_type', this.type);
        fd.set('chat_uid', this.uid);
        fd.set('file', file);
        const input = $('#tgInput');
        if (input && input.value.trim()) fd.set('content', input.value.trim());
        if (this.replyTo) fd.set('reply_to', this.replyTo.uid);

        const bar = $('#tgUploadBar');
        TG.toast('⬆️ در حال آپلود…');

        TG.upload('upload', fd, function (p) {
            if (bar) { bar.style.display = ''; bar.querySelector('.bar').style.width = p + '%'; }
        }).then(function (res) {
            if (bar) bar.style.display = 'none';
            if (input) { input.value = ''; input.style.height = 'auto'; }
            self.clearAttach();
            self.clearReply();
            self.ingest([res.message]);
            self.appendBatch([res.message]);
            self.scrollToBottom(true);
            TG.toast('✅ فایل ارسال و روی دستگاه ذخیره شد');
            // کش کردن فایل ارسالی روی دستگاه
            if (res.message.file_path) {
                LocalStore.saveMedia(res.message.uid, file, { name: res.message.file_name });
                TG.updateStoreBar();
            }
        }).catch(function (e) {
            if (bar) bar.style.display = 'none';
            TG.toast('⚠️ ' + e.message);
        });
    };

    /* -------------------------------------------------------------------- */
    /* عملیات روی پیام                                                      */
    /* -------------------------------------------------------------------- */

    Chat.messageAction = function (uid, act) {
        const m = this.byUid[uid];
        if (!m) return;
        const self = this;

        switch (act) {
            case 'reply':
                this.setReply(m);
                break;

            case 'react':
                TG.pickEmoji(function (e) { self.react(uid, e); });
                break;

            case 'edit':
                TG.prompt('ویرایش پیام', 'متن جدید', m.content, function (text) {
                    TG.api('edit', { uid: uid, content: text }).then(function (res) {
                        self.byUid[uid] = res.message;
                        for (let i = 0; i < self.messages.length; i++) {
                            if (self.messages[i].uid === uid) self.messages[i] = res.message;
                        }
                        LocalStore.saveMessages(self.chatKey, [res.message]);
                        const el = document.querySelector('.tg-msg[data-uid="' + uid + '"]');
                        if (el) {
                            const tmp = document.createElement('div');
                            tmp.innerHTML = TG.renderMessage(res.message, { lastReadByOther: self.otherLastRead || 0 });
                            el.replaceWith(tmp.firstElementChild);
                        }
                        TG.toast('✅ ویرایش شد');
                    }).catch(function (e) { TG.toast('⚠️ ' + e.message); });
                });
                break;

            case 'pin':
                TG.api('pin_message', { uid: uid }).then(function (res) {
                    TG.toast(res.pinned ? '📌 سنجاق شد' : 'پیام از سنجاق خارج شد');
                    self.sync();
                }).catch(function (e) { TG.toast('⚠️ ' + e.message); });
                break;

            case 'delete':
                const isMine = m.is_mine;
                const box = TG.modal('حذف پیام',
                    '<p style="font-size:13.5px;line-height:2;color:#c7d5e2">این پیام حذف شود؟</p>'
                    + '<label class="tg-pick-item" style="margin-top:10px"><input type="checkbox" id="tgDelAll" ' + (isMine ? '' : 'disabled') + '>'
                    + '<span class="nm"><span class="n">حذف برای همه</span>'
                    + '<span class="s">' + (isMine ? 'پیام از دید همه اعضا حذف می‌شود' : 'فقط فرستنده یا مدیر می‌تواند برای همه حذف کند') + '</span></span></label>',
                    '<button type="button" class="tg-btn ghost" data-close>انصراف</button>'
                    + '<button type="button" class="tg-btn danger" id="tgDelYes">حذف</button>');
                box.querySelector('#tgDelYes').addEventListener('click', function () {
                    const all = box.querySelector('#tgDelAll').checked;
                    TG.closeModal();
                    self.removeMessage(uid, all);
                });
                break;
        }
    };

    Chat.removeMessage = function (uid, forEveryone) {
        const self = this;
        TG.api('delete', { uid: uid, for_everyone: forEveryone ? '1' : '0' }).then(function () {
            const el = document.querySelector('.tg-msg[data-uid="' + uid + '"]');
            if (forEveryone) {
                if (el) {
                    el.classList.add('deleted');
                    el.innerHTML = '<div class="tg-msg-text">🚫 این پیام حذف شد</div>'
                        + '<div class="tg-meta"><span>' + TG.timeShort(self.byUid[uid] ? self.byUid[uid].created_at : '') + '</span></div>';
                }
            } else if (el) {
                el.remove();
            }
            delete self.byUid[uid];
            self.messages = self.messages.filter(function (m) { return m.uid !== uid; });
            LocalStore.saveMessages(self.chatKey, [{ uid: uid, chat_key: self.chatKey, server_id: 0, deleted_for_me: !forEveryone, deleted_for_all: forEveryone, type: 'system', content: '', created_at: new Date().toISOString(), is_mine: false, reactions: [] }]);
            TG.toast(forEveryone ? '🗑 پیام برای همه حذف شد' : '🗑 پیام از دید شما حذف شد');
            TG.updateStoreBar();
        }).catch(function (e) { TG.toast('⚠️ ' + e.message); });
    };

    Chat.react = function (uid, emoji) {
        const self = this;
        TG.api('react', { uid: uid, emoji: emoji }).then(function (res) {
            const el = document.querySelector('.tg-msg[data-uid="' + uid + '"]');
            if (!el) return;
            const old = el.querySelector('.tg-reactions');
            if (old) old.remove();
            const grouped = {};
            Object.keys(res.reactions || {}).forEach(function (e) {
                grouped[e] = res.reactions[e];
            });
            const keys = Object.keys(grouped);
            if (!keys.length) return;
            let html = '<div class="tg-reactions">';
            keys.forEach(function (e) {
                html += '<button type="button" class="tg-reaction' + (grouped[e].mine ? ' mine' : '') + '" data-react="' + TG.esc(uid) + '" data-emoji="' + TG.esc(e) + '">'
                      + e + ' <span>' + TG.faDigits(grouped[e].count) + '</span></button>';
            });
            html += '</div>';
            const tmp = document.createElement('div');
            tmp.innerHTML = html;
            el.appendChild(tmp.firstElementChild);
        }).catch(function (e) { TG.toast('⚠️ ' + e.message); });
    };

    Chat.openMessageMenu = function (el) {
        const uid = el.dataset.uid;
        const m = this.byUid[uid];
        if (!m) return;
        el.classList.add('menu-open');
    };

    /* -------------------------------------------------------------------- */
    /* پاسخ / پیش‌نویس / تایپ                                               */
    /* -------------------------------------------------------------------- */

    Chat.setReply = function (m) {
        this.replyTo = m;
        const box = $('#tgReplyPreview');
        if (box) {
            box.classList.add('on');
            box.querySelector('.n').textContent = m.sender_name || '';
            box.querySelector('.t').textContent = String(m.content || (m.type === 'file' ? '📎 فایل' : '📷 رسانه')).slice(0, 90);
        }
        const input = $('#tgInput');
        if (input) input.focus();
    };

    Chat.clearReply = function () {
        this.replyTo = null;
        const box = $('#tgReplyPreview');
        if (box) box.classList.remove('on');
    };

    Chat.draftKey = function () { return 'draft:' + this.chatKey; };

    Chat.saveDraft = function (text, force) {
        if (!LocalStore.db || !LocalStore.enabled) return;
        const now = Date.now();
        if (!force && this._lastDraft && now - this._lastDraft < 900) return;
        this._lastDraft = now;
        LocalStore._setMeta(this.draftKey(), String(text || ''));
    };

    Chat.restoreDraft = function () {
        const self = this;
        if (!LocalStore.db || !LocalStore.enabled) return;
        LocalStore._getMeta(this.draftKey()).then(function (d) {
            const input = $('#tgInput');
            if (d && input && !input.value) {
                input.value = d;
                input.style.height = Math.min(input.scrollHeight, 140) + 'px';
            }
        });
    };

    Chat.sendTyping = function () {
        const now = Date.now();
        if (this._lastTyping && now - this._lastTyping < 2500) return;
        this._lastTyping = now;
        TG.api('typing', { chat_key: this.chatKey }, { noCsrf: true }).catch(function () { /* ignore */ });
    };

    Chat.markRead = function () {
        TG.api('mark_read', { chat_type: this.type, chat_uid: this.uid }, { noCsrf: true })
            .then(function (res) {
                const badge = document.querySelector('[data-unread-badge]');
                if (badge) badge.textContent = res.unread > 0 ? TG.faDigits(res.unread) : '';
            })
            .catch(function () { /* ignore */ });
    };

    /* -------------------------------------------------------------------- */
    /* جستجو در گفتگو                                                       */
    /* -------------------------------------------------------------------- */

    Chat.openSearch = function () {
        const self = this;
        const box = TG.modal('جستجو در پیام‌ها',
            '<div class="tg-field"><label>عبارت مورد نظر</label><input type="text" id="tgSearchInput" placeholder="جستجو…"></div>'
            + '<div id="tgSearchResults" style="max-height:320px;overflow:auto"></div>',
            '<button type="button" class="tg-btn ghost" data-close>بستن</button>', true);

        const input = box.querySelector('#tgSearchInput');
        const out = box.querySelector('#tgSearchResults');
        let timer = null;

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                const q = input.value.trim();
                if (q.length < 2) { out.innerHTML = ''; return; }
                out.innerHTML = '<div class="tg-loading"><div class="tg-spinner"></div>در حال جستجو…</div>';
                TG.get('search', { q: q }).then(function (res) {
                    const items = (res.results || []).filter(function (m) {
                        return self.type === 'group' ? m.group_uid === self.uid
                             : (self.type === 'saved' ? m.chat_type === 'saved' : m.conversation_uid === self.uid);
                    });
                    if (!items.length) { out.innerHTML = '<p style="color:#8698ab;font-size:13px;text-align:center;padding:20px">نتیجه‌ای پیدا نشد</p>'; return; }
                    out.innerHTML = items.map(function (m) {
                        return '<div class="tg-pick-item" data-uid="' + TG.esc(m.uid) + '">'
                            + '<div class="nm"><div class="n">' + TG.esc(m.sender_name) + '</div>'
                            + '<div class="s">' + TG.esc(String(m.content || 'رسانه').slice(0, 70)) + '</div></div>'
                            + '<div class="s">' + TG.esc(TG.timeShort(m.created_at)) + '</div></div>';
                    }).join('');
                    out.querySelectorAll('[data-uid]').forEach(function (el) {
                        el.addEventListener('click', function () {
                            TG.closeModal();
                            self.gotoMessage(el.dataset.uid);
                        });
                    });
                }).catch(function (e) { out.innerHTML = '<p style="color:#ff9d9d;font-size:13px">' + TG.esc(e.message) + '</p>'; });
            }, 350);
        });

        setTimeout(function () { input.focus(); }, 60);
    };

    /* -------------------------------------------------------------------- */
    /* نظرسنجی / همگام‌سازی دوره‌ای                                          */
    /* -------------------------------------------------------------------- */

    Chat.startPolling = function () {
        const self = this;
        if (this.pollTimer) clearInterval(this.pollTimer);
        this.pollTimer = setInterval(function () {
            if (document.hidden) return;
            self.sync();
            TG.api('ping', {}, { noCsrf: true }).catch(function () { /* ignore */ });
        }, this.pollEvery);

        // حضور کاربر
        setInterval(function () { TG.api('ping', {}, { noCsrf: true }).catch(function () {}); }, 45000);
    };

    /* -------------------------------------------------------------------- */

    global.Chat = Chat;

})(typeof window !== 'undefined' ? window : this);
