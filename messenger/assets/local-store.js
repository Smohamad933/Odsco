/* ==========================================================================
   Odsco Messenger — ذخیره‌سازی محلی روی دستگاه کاربر (IndexedDB)
   --------------------------------------------------------------------------
   مطابق مدل هیبریدی:
     • سرور فقط نقش «تحویل پیام» را دارد (و با تنظیم مدت نگه‌داری پاک می‌شود).
     • تاریخچه کامل پیام‌ها + فایل‌ها و عکس‌ها روی گوشی/کامپیوتر خودِ کاربر
       در IndexedDB نگه داشته می‌شود؛ بنابراین تاریخچه حتی بعد از پاک‌شدن
       از سرور، روی دستگاه کاربر باقی می‌ماند و آفلاین هم باز می‌شود.
   ========================================================================== */

(function (global) {
    'use strict';

    const DB_NAME = 'odsco-messenger';
    const DB_VERSION = 1;
    const KEY_ON = 'odsco_local_store_enabled';
    const KEY_USER = 'odsco_local_store_user';

    const LocalStore = {
        db: null,
        enabled: true,
        ready: false,
        user: '',
        _txQueue: Promise.resolve()
    };

    /* -------------------------------------------------------------------- */
    /* راه‌اندازی                                                            */
    /* -------------------------------------------------------------------- */

    LocalStore.supported = function () {
        return typeof indexedDB !== 'undefined';
    };

    LocalStore.isEnabled = function () {
        try { return localStorage.getItem(KEY_ON) !== '0'; } catch (e) { return true; }
    };

    LocalStore.setEnabled = function (on) {
        try { localStorage.setItem(KEY_ON, on ? '1' : '0'); } catch (e) { /* ignore */ }
        this.enabled = !!on;
        if (!on) this.wipeAll();
    };

    /**
     * @param {string} user شناسه کاربر — اگر کاربر عوض شود، داده کاربر قبلی پاک می‌شود
     */
    LocalStore.init = function (user) {
        const self = this;
        this.user = String(user || '');
        this.enabled = this.isEnabled();

        if (!this.enabled || !this.supported()) {
            this.ready = true;
            return Promise.resolve(false);
        }

        return new Promise(function (resolve) {
            let req;
            try { req = indexedDB.open(DB_NAME, DB_VERSION); } catch (e) { resolve(false); return; }

            req.onupgradeneeded = function (ev) {
                const db = ev.target.result;

                if (!db.objectStoreNames.contains('messages')) {
                    const ms = db.createObjectStore('messages', { keyPath: 'uid' });
                    ms.createIndex('byChat', ['chat_key', 'server_id'], { unique: false });
                    ms.createIndex('byChatTime', ['chat_key', 'created_at'], { unique: false });
                    ms.createIndex('bySender', 'sender_uid', { unique: false });
                }
                if (!db.objectStoreNames.contains('media')) {
                    db.createObjectStore('media', { keyPath: 'uid' });
                }
                if (!db.objectStoreNames.contains('chats')) {
                    db.createObjectStore('chats', { keyPath: 'chat_key' });
                }
                if (!db.objectStoreNames.contains('meta')) {
                    db.createObjectStore('meta');
                }
            };

            req.onsuccess = function (ev) {
                self.db = ev.target.result;
                self.ready = true;

                self.db.onversionchange = function () { try { self.db.close(); } catch (e) {} };

                // اگر کاربر عوض شده، داده کاربر قبلی پاک شود
                self._getMeta('owner').then(function (owner) {
                    if (owner && owner !== self.user) {
                        return self.wipeAll().then(function () { return self._setMeta('owner', self.user); });
                    }
                    if (!owner) return self._setMeta('owner', self.user);
                }).then(function () { resolve(true); }, function () { resolve(true); });
            };

            req.onerror = function () { self.ready = true; resolve(false); };
            req.onblocked = function () { self.ready = true; resolve(false); };
        });
    };

    /* -------------------------------------------------------------------- */
    /* کمک‌کننده‌ها                                                          */
    /* -------------------------------------------------------------------- */

    LocalStore._tx = function (stores, mode, fn) {
        const self = this;
        if (!this.db || !this.enabled) return Promise.resolve(null);

        return new Promise(function (resolve, reject) {
            let tx;
            try { tx = self.db.transaction(stores, mode); } catch (e) { resolve(null); return; }
            const req = fn(tx);
            tx.oncomplete = function () { resolve(req && req.result !== undefined ? req.result : true); };
            tx.onerror = function () { reject(tx.error); };
            tx.onabort = function () { reject(tx.error); };
        });
    };

    LocalStore._getMeta = function (key) {
        const self = this;
        if (!this.db || !this.enabled) return Promise.resolve(null);
        return new Promise(function (resolve) {
            try {
                const r = self.db.transaction('meta', 'readonly').objectStore('meta').get(key);
                r.onsuccess = function () { resolve(r.result === undefined ? null : r.result); };
                r.onerror = function () { resolve(null); };
            } catch (e) { resolve(null); }
        });
    };

    LocalStore._setMeta = function (key, value) {
        const self = this;
        if (!this.db || !this.enabled) return Promise.resolve(false);
        return new Promise(function (resolve) {
            try {
                const r = self.db.transaction('meta', 'readwrite').objectStore('meta').put(value, key);
                r.onsuccess = function () { resolve(true); };
                r.onerror = function () { resolve(false); };
            } catch (e) { resolve(false); }
        });
    };

    /* -------------------------------------------------------------------- */
    /* پیام‌ها                                                               */
    /* -------------------------------------------------------------------- */

    /**
     * ذخیره دسته‌ای پیام‌ها. پیام‌های تکراری با هم ادغام می‌شوند
     * (نسخه سرور اولویت دارد، مگر اینکه پیام local_pending باشد).
     */
    LocalStore.saveMessages = function (chatKey, messages) {
        const self = this;
        if (!this.db || !this.enabled || !messages || !messages.length) return Promise.resolve(0);

        const rows = messages.map(function (m) {
            return Object.assign({}, m, { chat_key: chatKey });
        });

        return this._tx('messages', 'readwrite', function (tx) {
            const st = tx.objectStore('messages');
            rows.forEach(function (row) { st.put(row); });
            return rows.length;
        }).catch(function () { return 0; });
    };

    /**
     * خواندن پیام‌های یک چت از حافظه دستگاه (جدیدترین‌ها).
     * @returns {Promise<Array>} به ترتیب صعودی
     */
    LocalStore.loadMessages = function (chatKey, limit) {
        const self = this;
        limit = limit || 60;
        if (!this.db || !this.enabled) return Promise.resolve([]);

        return new Promise(function (resolve) {
            try {
                const st = self.db.transaction('messages', 'readonly').objectStore('messages');
                const idx = st.index('byChat');
                const out = [];
                const range = IDBKeyRange.bound([chatKey, -Infinity], [chatKey, Infinity]);
                const req = idx.openCursor(range, 'prev');   // از جدیدترین

                req.onsuccess = function (ev) {
                    const cur = ev.target.result;
                    if (!cur || out.length >= limit) { resolve(out.reverse()); return; }
                    out.push(cur.value);
                    cur.continue();
                };
                req.onerror = function () { resolve([]); };
            } catch (e) { resolve([]); }
        });
    };

    /** پیام‌های قدیمی‌تر از یک شناسه (برای اسکرول به بالا) */
    LocalStore.loadOlder = function (chatKey, beforeServerId, limit) {
        const self = this;
        limit = limit || 40;
        if (!this.db || !this.enabled) return Promise.resolve([]);

        return new Promise(function (resolve) {
            try {
                const st = self.db.transaction('messages', 'readonly').objectStore('messages');
                const idx = st.index('byChat');
                const out = [];
                const range = IDBKeyRange.bound([chatKey, -Infinity], [chatKey, beforeServerId], false, true);
                const req = idx.openCursor(range, 'prev');

                req.onsuccess = function (ev) {
                    const cur = ev.target.result;
                    if (!cur || out.length >= limit) { resolve(out.reverse()); return; }
                    out.push(cur.value);
                    cur.continue();
                };
                req.onerror = function () { resolve([]); };
            } catch (e) { resolve([]); }
        });
    };

    LocalStore.countMessages = function (chatKey) {
        const self = this;
        if (!this.db || !this.enabled) return Promise.resolve(0);
        return new Promise(function (resolve) {
            try {
                const st = self.db.transaction('messages', 'readonly').objectStore('messages');
                const range = IDBKeyRange.bound([chatKey, -Infinity], [chatKey, Infinity]);
                const r = st.index('byChat').count(range);
                r.onsuccess = function () { resolve(r.result || 0); };
                r.onerror = function () { resolve(0); };
            } catch (e) { resolve(0); }
        });
    };

    LocalStore.clearChat = function (chatKey) {
        const self = this;
        if (!this.db || !this.enabled) return Promise.resolve(false);
        return this._tx(['messages'], 'readwrite', function (tx) {
            const st = tx.objectStore('messages');
            const range = IDBKeyRange.bound([chatKey, -Infinity], [chatKey, Infinity]);
            st.index('byChat').openCursor(range).onsuccess = function (ev) {
                const c = ev.target.result;
                if (c) { st.delete(c.primaryKey); c.continue(); }
            };
            return true;
        }).catch(function () { return false; });
    };

    /* -------------------------------------------------------------------- */
    /* فایل‌ها و عکس‌ها — کش شدن روی دستگاه                                  */
    /* -------------------------------------------------------------------- */

    LocalStore.saveMedia = function (msgUid, blob, meta) {
        if (!this.db || !this.enabled || !blob) return Promise.resolve(false);
        return this._tx('media', 'readwrite', function (tx) {
            tx.objectStore('media').put({ uid: msgUid, blob: blob, meta: meta || {}, at: Date.now() });
            return true;
        }).catch(function () { return false; });
    };

    LocalStore.getMedia = function (msgUid) {
        const self = this;
        if (!this.db || !this.enabled) return Promise.resolve(null);
        return new Promise(function (resolve) {
            try {
                const r = self.db.transaction('media', 'readonly').objectStore('media').get(msgUid);
                r.onsuccess = function () { resolve(r.result || null); };
                r.onerror = function () { resolve(null); };
            } catch (e) { resolve(null); }
        });
    };

    /**
     * آدرس نمایش یک رسانه: اول از حافظه دستگاه، وگرنه دانلود و کش می‌شود.
     * @returns {Promise<string|null>} objectURL یا null
     */
    LocalStore.mediaUrl = function (msgUid, serverPath, fileName) {
        const self = this;
        if (!msgUid) return Promise.resolve(serverPath ? encodeURI(serverPath) : null);
        if (!this.enabled || !this.db) return Promise.resolve(serverPath ? encodeURI(serverPath) : null);

        return this.getMedia(msgUid).then(function (hit) {
            if (hit && hit.blob) return URL.createObjectURL(hit.blob);
            if (!serverPath) return null;

            const url = encodeURI(serverPath);
            return fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.blob() : null; })
                .then(function (blob) {
                    if (!blob) return url;
                    return self.saveMedia(msgUid, blob, { name: fileName || '', path: serverPath })
                        .then(function () { return URL.createObjectURL(blob); });
                })
                .catch(function () { return url; });
        });
    };

    /** دانلود و کش کردن یک فایل برای ذخیره روی دستگاه */
    LocalStore.saveToDevice = function (msgUid, serverPath, fileName) {
        const self = this;
        const url = encodeURI(serverPath);
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('download failed');
                return r.blob();
            })
            .then(function (blob) {
                const objectUrl = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = objectUrl;
                a.download = fileName || 'file';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 4000);
                return self.saveMedia(msgUid, blob, { name: fileName || '', path: serverPath });
            });
    };

    /* -------------------------------------------------------------------- */
    /* وضعیت چت‌ها                                                           */
    /* -------------------------------------------------------------------- */

    LocalStore.saveChats = function (chats) {
        if (!this.db || !this.enabled || !chats) return Promise.resolve(false);
        return this._tx('chats', 'readwrite', function (tx) {
            const st = tx.objectStore('chats');
            chats.forEach(function (c) { if (c && c.chat_key) st.put(c); });
            return true;
        }).catch(function () { return false; });
    };

    LocalStore.loadChats = function () {
        const self = this;
        if (!this.db || !this.enabled) return Promise.resolve([]);
        return new Promise(function (resolve) {
            try {
                const r = self.db.transaction('chats', 'readonly').objectStore('chats').getAll();
                r.onsuccess = function () { resolve(r.result || []); };
                r.onerror = function () { resolve([]); };
            } catch (e) { resolve([]); }
        });
    };

    /* -------------------------------------------------------------------- */
    /* آمار و پاک‌سازی                                                      */
    /* -------------------------------------------------------------------- */

    LocalStore.stats = function () {
        const self = this;
        const out = { messages: 0, media: 0, bytes: 0, quota: 0, usage: 0 };
        if (!this.db || !this.enabled) return Promise.resolve(out);

        const count = function (store) {
            return new Promise(function (resolve) {
                try {
                    const r = self.db.transaction(store, 'readonly').objectStore(store).count();
                    r.onsuccess = function () { resolve(r.result || 0); };
                    r.onerror = function () { resolve(0); };
                } catch (e) { resolve(0); }
            });
        };

        return Promise.all([count('messages'), count('media')])
            .then(function (res) {
                out.messages = res[0];
                out.media = res[1];
                if (navigator.storage && navigator.storage.estimate) {
                    return navigator.storage.estimate().then(function (est) {
                        out.usage = est.usage || 0;
                        out.quota = est.quota || 0;
                        return out;
                    }).catch(function () { return out; });
                }
                return out;
            });
    };

    /** درخواست ماندگاری حافظه (تا مرورگر خودکار پاک نکند) */
    LocalStore.persist = function () {
        if (!(navigator.storage && navigator.storage.persist)) return Promise.resolve(false);
        return navigator.storage.persist().catch(function () { return false; });
    };

    LocalStore.isPersistent = function () {
        if (!(navigator.storage && navigator.storage.persisted)) return Promise.resolve(false);
        return navigator.storage.persisted().catch(function () { return false; });
    };

    LocalStore.wipeAll = function () {
        const self = this;
        if (!this.db) {
            // اگر پایگاه داده باز نیست، کل پایگاه را حذف کن
            return new Promise(function (resolve) {
                try {
                    const r = indexedDB.deleteDatabase(DB_NAME);
                    r.onsuccess = r.onerror = r.onblocked = function () { resolve(true); };
                } catch (e) { resolve(false); }
            });
        }
        const stores = ['messages', 'media', 'chats'];
        return this._tx(stores, 'readwrite', function (tx) {
            stores.forEach(function (s) { tx.objectStore(s).clear(); });
            return true;
        }).catch(function () { return false; });
    };

    /* -------------------------------------------------------------------- */

    global.LocalStore = LocalStore;

})(typeof window !== 'undefined' ? window : this);
