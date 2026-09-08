#!/usr/bin/env node
/**
 * ============================================================================
 *  Odsco — اجرای واقعی جاوااسکریپت صفحه‌ها در DOM (jsdom)
 * ----------------------------------------------------------------------------
 *  هر صفحه را با نشست واقعی از سرور توسعه می‌گیرد، اسکریپت‌هایش را اجرا می‌کند
 *  و خطاهای جاوااسکریپت، عنصرهای گم‌شده و جریان واقعی ارسال پیام را می‌سنجد.
 *
 *  اجرا:  node tools/dom-test.js [base]
 * ============================================================================
 */

const path = require('path');
const { JSDOM, VirtualConsole } = require('/home/user/tools/node_modules/jsdom');

/*
 * IndexedDB واقعی برای jsdom.
 * jsdom خودش IndexedDB ندارد، بنابراین LocalStore.supported() همیشه false بود و
 * «ذخیره‌سازی روی دستگاه کاربر» — که هستهٔ خواستهٔ کاربر است — هیچ‌وقت اجرا نمی‌شد.
 * با fake-indexeddb همان کد مرورگر اینجا واقعاً راه می‌افتد.
 */
require('/home/user/tools/node_modules/fake-indexeddb/auto');

const BASE = process.argv[2] || 'http://127.0.0.1:8080';

let pass = 0; let fail = 0; const failures = [];
function check(label, cond, detail) {
  if (cond) { pass++; console.log('  ✅ ' + label); }
  else { fail++; failures.push(label + (detail ? ' → ' + detail : '')); console.log('  ❌ ' + label + (detail ? ' → ' + detail : '')); }
}
function section(t) { console.log('\n── ' + t + ' ' + '─'.repeat(Math.max(0, 52 - t.length))); }

const NOISE = [/favicon/i, /Could not parse CSS/i, /Error: Not implemented: window.scrollTo/];
const noisy = (s) => NOISE.some((r) => r.test(String(s)));

class Jar {
  constructor() { this.cookies = new Map(); }
  header() { return [...this.cookies].map(([k, v]) => k + '=' + v).join('; '); }
  eat(res) {
    const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
    for (const c of raw) {
      const [pair] = c.split(';'); const i = pair.indexOf('=');
      if (i > 0) this.cookies.set(pair.slice(0, i).trim(), pair.slice(i + 1).trim());
    }
  }
  /**
   * ریدایرکت‌ها را دستی دنبال می‌کند چون کوکیِ پاسخِ میانی (نشست تازه بعد از
   * session_regenerate_id) باید گرفته شود؛ با redirect:'follow' از دست می‌رود.
   */
  async req(url, opts = {}) {
    let target = BASE + url;
    let method = opts.method || 'GET';
    let res = null;
    for (let hop = 0; hop < 8; hop++) {
      const headers = { ...(opts.headers || {}) };
      const h = this.header(); if (h) headers.Cookie = h;
      const init = { method, headers, redirect: 'manual' };
      if (method !== 'GET' && opts.body !== undefined) init.body = opts.body;
      res = await fetch(target, init);
      this.eat(res);
      if (![301, 302, 303, 307, 308].includes(res.status)) break;
      const loc = res.headers.get('location') || '';
      if (!loc) break;
      target = new URL(loc, target).toString();
      method = 'GET';
    }
    return res;
  }
  get(url) { return this.req(url); }
  post(url, form) {
    return this.req(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(form).toString(),
    });
  }
}

function csrfOf(html) {
  let m = /name="csrf_token" value="([^"]+)"/.exec(html);
  if (m) return m[1];
  m = /data-csrf="([^"]+)"/.exec(html);
  return m ? m[1] : '';
}

async function login(jar, url, user, pass) {
  const page = await (await jar.get(url)).text();
  const token = csrfOf(page);
  const res = await jar.post(url, { username: user, password: pass, csrf_token: token });
  return { html: await res.text(), url: res.url, status: res.status, token };
}

/**
 * صفحه را با اسکریپت‌های واقعی اجرا می‌کند.
 * هر JSDOM تایمر و بارگذار زنده دارد؛ اگر نبندیم حافظه تمام می‌شود،
 * پس پیش از ساخت نمونه بعدی، نمونه قبلی بسته می‌شود.
 */
let lastDom = null;
async function render(jar, url, { wait = 2500 } = {}) {
  if (lastDom) { try { lastDom.window.close(); } catch (e) { /* ignore */ } lastDom = null; }
  const res = await jar.get(url);
  const html = await res.text();
  const errors = [];
  const vc = new VirtualConsole();
  vc.on('jsdomError', (e) => { if (!noisy(e.message)) errors.push('jsdomError: ' + (e.detail || e.message)); });
  vc.on('error', (...a) => { const t = a.join(' '); if (!noisy(t)) errors.push('console.error: ' + t); });
  vc.on('warn', (...a) => { const t = a.join(' '); if (!noisy(t)) errors.push('console.warn: ' + t); });

  const dom = new JSDOM(html, {
    url: BASE + url,
    runScripts: 'dangerously',
    // فایل‌های js/css از همان سرور توسعه بارگذاری می‌شوند (نیاز به کوکی ندارند)
    resources: 'usable',
    pretendToBeVisual: true,
    virtualConsole: vc,
    // پیش از اجرای هر اسکریپتی، محیط را کامل می‌کنیم
    beforeParse(window) {
      // jsdom این‌ها را ندارد ولی همه مرورگرها دارند؛ نبودشان تست را بی‌دلیل می‌شکند
      if (!window.Element.prototype.scrollIntoView) window.Element.prototype.scrollIntoView = function () {};
      if (!window.Element.prototype.scrollBy) window.Element.prototype.scrollBy = function () {};
      if (!window.scrollTo) window.scrollTo = function () {};

      // IndexedDB واقعی (همان چیزی که مرورگر دارد) → مسیر local-first فعال می‌شود
      window.indexedDB = globalThis.indexedDB;
      window.IDBKeyRange = globalThis.IDBKeyRange;
      window.IDBRequest = globalThis.IDBRequest;
      window.IDBTransaction = globalThis.IDBTransaction;
      window.IDBDatabase = globalThis.IDBDatabase;
      window.IDBCursor = globalThis.IDBCursor;
      window.IDBCursorWithValue = globalThis.IDBCursorWithValue;
      if (!window.URL.createObjectURL) {
        let n = 0;
        window.URL.createObjectURL = function () { return 'blob:odsco/' + (++n); };
        window.URL.revokeObjectURL = function () {};
      }

      // درخواست‌های fetch با کوکی نشست همان کاربر بروند
      window.fetch = (u, o) => {
        const abs = new URL(String(u), BASE + url).toString();
        const headers = { ...((o && o.headers) || {}) };
        const h = jar.header(); if (h) headers.Cookie = h;
        return fetch(abs, { ...o, headers, redirect: 'manual' }).then(async (r) => {
          jar.eat(r);
          return r;
        });
      };
    },
  });

  await new Promise((r) => setTimeout(r, wait));
  lastDom = dom;
  return { dom, window: dom.window, document: dom.window.document, errors, status: res.status, html };
}

(async () => {
  console.log('🧪 تست DOM/JS روی ' + BASE);

  // ---------------------------------------------------------------- ادمین
  section('پنل مدیریت — اجرا در DOM');
  const admin = new Jar();
  const la = await login(admin, '/admin/login.php', 'qa_admin', 'QaPass1234');
  check('ورود ادمین موفق', la.url.includes('dashboard.php'),
    'url=' + la.url + ' status=' + la.status + ' body=' + la.html.replace(/\s+/g, ' ').slice(0, 140));

  {
    const r = await render(admin, '/admin/dashboard.php');
    check('داشبورد: بدون خطای JS', r.errors.length === 0, r.errors.slice(0, 3).join(' | '));
    check('داشبورد: سایدبار رندر شده', !!r.document.querySelector('.sidebar'), 'no .sidebar');
    check('داشبورد: محتوای اصلی دارد', r.document.querySelectorAll('.stat-card, .card, table').length > 0,
      'no cards/tables');
  }

  const ADMIN_PAGES = [
    ['workspace.php', '.admin-layout'],
    ['attendance.php', '.admin-layout'],
    ['attendance.php?tab=requests', '.admin-layout'],
    ['attendance.php?tab=month', '.admin-layout'],
    ['automation.php', '.admin-layout'],
    ['automation.php?edit=new', 'form'],
    ['broadcast.php', 'form'],
    ['manage-projects.php', '.admin-layout'],
    ['manage-users.php', '.admin-layout'],
    ['manage-clients.php', '.admin-layout'],
    ['manage-messages.php', '.admin-layout'],
    ['media-library.php', '.admin-layout'],
    ['manage-blog.php', '.admin-layout'],
    ['manage-team.php', '.admin-layout'],
    ['access.php', '.acc-table'],
    ['settings.php', 'form'],
    ['settings.php?section=ops', 'form'],
    ['logs.php', '.admin-layout'],
  ];
  for (const [p, sel] of ADMIN_PAGES) {
    const r = await render(admin, '/admin/' + p, { wait: 1200 });
    const missing = r.document.querySelector(sel) ? '' : ' عنصر «' + sel + '» نیست';
    check('admin/' + p, r.errors.length === 0 && !missing,
      r.errors.slice(0, 2).join(' | ') + missing);
  }

  // ---------------------------------------------------------------- پیام‌رسان
  section('پیام‌رسان — اجرا در DOM');
  const mj = new Jar();
  const lm = await login(mj, '/messenger/login.php', 'qa_admin', 'QaPass1234');
  check('ورود پیام‌رسان موفق', lm.url.includes('index.php'), 'url=' + lm.url);

  {
    const r = await render(mj, '/messenger/index.php', { wait: 3000 });
    check('index: بدون خطای JS', r.errors.length === 0, r.errors.slice(0, 3).join(' | '));
    check('index: TG.boot اجرا شده', r.window.TG && typeof r.window.TG.api === 'function', 'TG undefined');
    check('index: توکن CSRF در TG هست', !!(r.window.TG && r.window.TG.csrf), 'TG.csrf empty');
    check('index: کاربر فعلی شناخته شده', !!(r.window.TG && r.window.TG.me && r.window.TG.me.uid),
      'TG.me=' + JSON.stringify(r.window.TG && r.window.TG.me));
    check('index: ریشه برنامه هست', !!r.document.getElementById('tgRoot'), 'no #tgRoot');
  }

  section('پیام‌رسان — جریان واقعی ارسال پیام');
  let convUid = '';
  {
    const r = await render(mj, '/messenger/index.php', { wait: 3000 });
    const TG = r.window.TG;
    if (TG) {
      // مخاطب‌ها
      const contacts = await TG.get('contacts').catch((e) => ({ error: String(e.message || e) }));
      check('TG.get(contacts) کار می‌کند', Array.isArray(contacts.contacts), JSON.stringify(contacts).slice(0, 200));
      const peer = (contacts.contacts || [])[0];
      if (peer) {
        const c = await TG.api('conversation', { user_uid: peer.uid || peer.user_uid }).catch((e) => ({ error: String(e.message || e) }));
        check('TG.api(conversation) کار می‌کند', !!c.conversation, JSON.stringify(c).slice(0, 200));
        convUid = c.conversation || '';
      }
    }
  }

  if (convUid) {
    const r = await render(mj, '/messenger/chat.php?conversation=' + convUid, { wait: 3500 });
    check('chat: بدون خطای JS', r.errors.length === 0, r.errors.slice(0, 3).join(' | '));
    check('chat: ناحیه پیام‌ها هست',
      !!r.document.querySelector('#tgMessages, .tg-messages, [id*=Messages], .tg-msg-list'),
      'no message container');
    check('chat: جعبه ورودی هست', !!r.document.querySelector('#tgInput, textarea, [contenteditable]'),
      'no input box');

    // ---- مسیر local-first: داده باید روی IndexedDB دستگاه بنشیند ----
    const LS = r.window.LocalStore;
    check('chat: LocalStore بارگذاری شده', !!LS, 'window.LocalStore وجود ندارد');
    if (LS) {
      check('chat: IndexedDB در دسترس است', LS.supported() === true, 'supported() = false');
      check('chat: دیتابیس محلی باز شده', !!LS.db, 'LocalStore.db خالی است');
      const chatKey = r.document.getElementById('tgRoot').dataset.chatKey;
      const stored0 = await LS.loadMessages(chatKey, 100);
      const cnt0 = await LS.countMessages(chatKey);
      check('chat: countMessages با loadMessages هم‌خوان است', cnt0 === stored0.length,
        'count=' + cnt0 + ' load=' + stored0.length);
      global.__chatKey = chatKey;
    }

    const marker = 'پیام تست DOM ' + Date.now();
    const sent = await r.window.TG.api('send', {
      chat_type: 'private', chat_uid: convUid, content: marker,
    }).catch((e) => ({ error: String(e.message || e) }));
    check('chat: ارسال پیام با TG.api', sent.success === true, JSON.stringify(sent).slice(0, 200));

    if (sent.success) {
      const r2 = await render(mj, '/messenger/chat.php?conversation=' + convUid, { wait: 3500 });
      const text = r2.document.body.textContent || '';
      check('chat: پیام ارسال‌شده رندر می‌شود', text.includes(marker),
        'پیام در DOM نیست — رندر پیام کار نمی‌کند');
      check('chat: بعد از رندر هم بدون خطا', r2.errors.length === 0, r2.errors.slice(0, 3).join(' | '));

      // ---- local-first: پیام ارسال‌شده باید روی دستگاه کاربر بنشیند ----
      const LS2 = r2.window.LocalStore;
      const key = global.__chatKey || r2.document.getElementById('tgRoot').dataset.chatKey;
      if (LS2 && LS2.db) {
        const stored = await LS2.loadMessages(key, 200);
        check('chat: پیام روی دستگاه کاربر ذخیره شد', stored.some((m) => (m.content || '').indexOf(marker) >= 0),
          'IndexedDB این پیام را ندارد (' + stored.length + ' پیام ذخیره‌شده) — local-first کار نمی‌کند');
        check('chat: تاریخچه محلی قابل بازیابی است', stored.length > 0,
          'IndexedDB خالی است (chatKey=' + key + ')');
      } else {
        check('chat: IndexedDB در صفحه دوم باز است', false, 'LocalStore.db خالی');
      }
    }
  } else {
    check('chat: گفتگویی برای تست پیدا شد', false, 'convUid خالی');
  }

  section('پیام‌رسان — صفحه‌های دیگر');
  for (const p of ['groups.php', 'notices.php', 'settings.php', 'attendance.php', 'chat.php?saved=1']) {
    const r = await render(mj, '/messenger/' + p, { wait: 2000 });
    check('messenger/' + p, r.errors.length === 0, r.errors.slice(0, 3).join(' | '));
  }

  // ---------------------------------------------------------------- کارفرما
  section('پنل کارفرما — اجرا در DOM');
  const cj = new Jar();
  const lc = await login(cj, '/client/login.php', 'qa_client', 'QaPass1234');
  check('ورود کارفرما موفق', lc.url.includes('/client/index.php'), 'url=' + lc.url + ' status=' + lc.status);
  for (const p of ['index.php', 'projects.php', 'reports.php', 'notifications.php']) {
    const r = await render(cj, '/client/' + p, { wait: 1500 });
    check('client/' + p, r.errors.length === 0 && r.document.body.textContent.length > 200,
      r.errors.slice(0, 2).join(' | ') + ' len=' + r.document.body.textContent.length);
  }

  // ---------------------------------------------------------------- حضور و غیاب
  section('بخش حضور و غیاب — اجرا در DOM');
  const aj = new Jar();
  const la2 = await login(aj, '/attendance/login.php', 'qa_emp', 'QaPass1234');
  check('ورود به حضور و غیاب', la2.url.includes('/attendance/'), 'url=' + la2.url);

  {
    const r = await render(aj, '/attendance/', { wait: 2000 });
    check('attendance: بدون خطای JS', r.errors.length === 0, r.errors.slice(0, 3).join(' | '));
    check('attendance: دکمه ثبت ورود هست', r.document.body.textContent.includes('ثبت ورود'), 'no check-in button');
    check('attendance: ساعت زنده فعال است', !!r.document.getElementById('atClock'), 'no #atClock');
  }
  for (const t of ['requests', 'month']) {
    const r = await render(aj, '/attendance/?tab=' + t, { wait: 1500 });
    check('attendance/?tab=' + t, r.errors.length === 0, r.errors.slice(0, 3).join(' | '));
  }

  const amj = new Jar();
  await login(amj, '/attendance/login.php', 'qa_manager', 'QaPass1234');
  {
    const r = await render(amj, '/attendance/?tab=team', { wait: 1500 });
    check('attendance/?tab=team (مدیر)', r.errors.length === 0 && r.document.body.textContent.includes('وضعیت امروز تیم'),
      r.errors.slice(0, 3).join(' | '));
  }

  // صفحه دسترسی کاربران در ادمین
  {
    const r = await render(admin, '/admin/access.php', { wait: 1500 });
    check('admin/access.php بدون خطای JS', r.errors.length === 0, r.errors.slice(0, 3).join(' | '));
    check('admin/access.php جدول دسترسی دارد', !!r.document.querySelector('.acc-table'), 'no .acc-table');
    check('admin/access.php سوییچ پیام‌رسان دارد', !!r.document.querySelector('.sw-msg'), 'no .sw-msg');
    const rows = r.document.querySelectorAll('tbody tr').length;
    check('admin/access.php کاربران را لیست می‌کند', rows > 0, 'rows=' + rows);
  }

  // ---------------------------------------------------------------- عمومی
  section('سایت عمومی — اجرا در DOM');
  for (const p of ['/', '/about/', '/services/', '/blog/', '/project/', '/contact/']) {
    const r = await render(new Jar(), p, { wait: 1500 });
    check(p, r.errors.length === 0, r.errors.slice(0, 3).join(' | '));
  }

  console.log('\n' + '═'.repeat(60));
  console.log('نتیجه DOM/JS: ' + pass + ' موفق / ' + fail + ' ناموفق');
  if (failures.length) { console.log('\nموارد ناموفق:'); for (const f of failures) console.log('  • ' + f); }
  console.log('═'.repeat(60));
  process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('💥', e); process.exit(2); });
