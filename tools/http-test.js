#!/usr/bin/env node
/**
 * ============================================================================
 *  Odsco — تست واقعی از راه HTTP (با نشست و کوکی واقعی)
 * ----------------------------------------------------------------------------
 *  مثل مرورگر لاگین می‌کند، کوکی نگه می‌دارد، ریدایرکت‌ها را دنبال می‌کند و
 *  خطاهای PHP را از هدر X-Odsco-Errors می‌خواند.
 *
 *  اجرا:  node tools/http-test.js [base]
 * ============================================================================
 */

const BASE = process.argv[2] || 'http://127.0.0.1:8080';

const results = [];
let pass = 0; let fail = 0;

function check(label, cond, detail) {
  if (cond) { pass++; console.log('  ✅ ' + label); }
  else { fail++; results.push(label + (detail ? ' → ' + detail : '')); console.log('  ❌ ' + label + (detail ? ' → ' + detail : '')); }
}
function section(t) { console.log('\n── ' + t + ' ' + '─'.repeat(Math.max(0, 54 - t.length))); }

class Client {
  constructor(name) { this.name = name; this.cookies = {}; }
  cookieHeader() {
    return Object.entries(this.cookies).map(([k, v]) => k + '=' + v).join('; ');
  }
  eat(res) {
    const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
    for (const c of raw) {
      const [pair] = c.split(';');
      const i = pair.indexOf('=');
      if (i > 0) this.cookies[pair.slice(0, i).trim()] = pair.slice(i + 1).trim();
    }
  }
  async request(method, url, { form, follow = 0, headers = {} } = {}) {
    const opts = { method, redirect: 'manual', headers: { ...headers } };
    const ch = this.cookieHeader();
    if (ch) opts.headers.Cookie = ch;
    if (form) {
      opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
      opts.body = new URLSearchParams(form).toString();
    }
    let res = await fetch(BASE + url, opts);
    this.eat(res);
    let hops = 0;
    while ([301, 302, 303, 307, 308].includes(res.status) && hops < follow) {
      let loc = res.headers.get('location') || '';
      if (!loc) break;
      if (loc.startsWith('/')) loc = loc;
      else {
        const base = url.split('/'); base.pop();
        loc = base.join('/') + '/' + loc;
      }
      res = await fetch(BASE + loc, { method: 'GET', redirect: 'manual', headers: opts.headers });
      this.eat(res);
      hops++;
    }
    const body = await res.text();
    let errors = []; let fatal = '';
    try { errors = JSON.parse(decodeURIComponent(res.headers.get('x-odsco-errors') || '[]')); } catch (e) { /* ignore */ }
    try { fatal = decodeURIComponent(res.headers.get('x-odsco-fatal') || ''); } catch (e) { /* ignore */ }
    return { status: res.status, body, location: res.headers.get('location'), errors, fatal, url };
  }
  get(url, o) { return this.request('GET', url, o); }
  post(url, form, o) { return this.request('POST', url, { ...o, form }); }
}

/**
 * توکن CSRF را از صفحه بیرون می‌کشد:
 * یا از فیلد فرم (csrf_field) یا از data-csrf روی #tgRoot (صفحه‌های پیام‌رسان).
 */
function csrf(body) {
  let m = /name="csrf_token" value="([^"]+)"/.exec(body);
  if (m) return m[1];
  m = /data-csrf="([^"]+)"/.exec(body);
  return m ? m[1] : '';
}

const ADMIN_PAGES = [
  'dashboard.php', 'workspace.php', 'attendance.php', 'attendance.php?tab=requests',
  'attendance.php?tab=month', 'automation.php', 'automation.php?tab=logs',
  'automation.php?tab=cron', 'automation.php?edit=new', 'broadcast.php',
  'manage-projects.php', 'manage-projects.php?add=1', 'manage-users.php',
  'manage-clients.php', 'manage-messages.php', 'media-library.php',
  'media-library.php?tab=trash', 'manage-blog.php', 'manage-categories.php',
  'manage-team.php', 'access.php', 'settings.php', 'settings.php?section=ops', 'logs.php',
];

const MSG_PAGES = [
  'index.php', 'chat.php?saved=1', 'groups.php', 'notices.php',
  'settings.php', 'attendance.php',
];

const CLIENT_PAGES = ['index.php', 'projects.php', 'reports.php', 'notifications.php'];

const PUBLIC_PAGES = ['/', '/about/', '/services/', '/blog/', '/project/', '/contact/'];

const IGNORE_ERR = [
  /session_destroy\(\): Trying to destroy uninitialized session/,
];
const relevant = (errs) => errs.filter((e) => !IGNORE_ERR.some((re) => re.test(e)));

async function login(client, path, user, pass) {
  const page = await client.get(path);
  const token = csrf(page.body);
  const res = await client.post(path, { username: user, password: pass, csrf_token: token });
  return { page, res, token };
}

(async () => {
  console.log('🌐 تست HTTP روی ' + BASE);

  // ---------------------------------------------------------------- admin
  section('ورود پنل مدیریت');
  const admin = new Client('admin');
  const la = await login(admin, '/admin/login.php', 'qa_admin', 'QaPass1234');
  check('صفحه ورود رندر می‌شود', la.page.status === 200 && la.page.body.includes('ورود به پنل مدیریت'), 'status=' + la.page.status);
  check('توکن CSRF در فرم هست', la.token !== '');
  check('ورود موفق ریدایرکت می‌دهد', la.res.status === 302 || la.res.status === 301 || la.res.status === 303,
    'status=' + la.res.status + ' fatal=' + la.res.fatal + ' errors=' + la.res.errors.join(' | '));
  check('کوکی نشست گرفته شد', Object.keys(admin.cookies).length > 0, JSON.stringify(admin.cookies));

  const dash = await admin.get('/admin/dashboard.php');
  check('داشبورد بعد از ورود باز می‌شود', dash.status === 200 && dash.body.length > 3000,
    'status=' + dash.status + ' len=' + dash.body.length + ' loc=' + dash.location + ' fatal=' + dash.fatal);
  check('داشبورد به لاگین برنمی‌گردد', !dash.body.includes('name="csrf_token" value') || dash.body.includes('sidebar'),
    'body head: ' + dash.body.slice(0, 120).replace(/\s+/g, ' '));

  section('ورود با رمز اشتباه');
  const bad = new Client('bad');
  const lb = await login(bad, '/admin/login.php', 'qa_admin', 'wrong-password');
  check('رمز اشتباه ریدایرکت نمی‌دهد', lb.res.status === 200, 'status=' + lb.res.status);
  check('پیام خطا نمایش داده می‌شود', lb.res.body.includes('اشتباه') || lb.res.body.includes('غیرفعال'),
    'no error text in body');
  const afterBad = await bad.get('/admin/dashboard.php');
  check('بدون ورود، داشبورد در دسترس نیست', afterBad.status === 302 || afterBad.location === 'login.php',
    'status=' + afterBad.status + ' loc=' + afterBad.location);

  // ---------------------------------------------------------------- صفحات ادمین
  section('صفحه‌های پنل مدیریت');
  for (const p of ADMIN_PAGES) {
    const r = await admin.get('/admin/' + p);
    const errs = relevant(r.errors);
    const ok = r.status === 200 && !r.fatal && errs.length === 0 && r.body.length > 800;
    check('admin/' + p, ok,
      'status=' + r.status + ' len=' + r.body.length + (r.fatal ? ' FATAL: ' + r.fatal : '') +
      (errs.length ? ' ERR: ' + errs.slice(0, 2).join(' | ') : ''));
  }

  // ---------------------------------------------------------------- پیام‌رسان
  section('ورود پیام‌رسان');
  const msg = new Client('msg');
  const lm = await login(msg, '/messenger/login.php', 'qa_admin', 'QaPass1234');
  check('ورود پیام‌رسان موفق', [301, 302, 303].includes(lm.res.status), 'status=' + lm.res.status + ' fatal=' + lm.res.fatal + ' err=' + lm.res.errors.join('|'));
  const mIndex = await msg.get('/messenger/index.php');
  check('صفحه اصلی پیام‌رسان باز می‌شود', mIndex.status === 200 && mIndex.body.length > 3000,
    'status=' + mIndex.status + ' len=' + mIndex.body.length + ' fatal=' + mIndex.fatal);

  section('صفحه‌های پیام‌رسان');
  for (const p of MSG_PAGES) {
    const r = await msg.get('/messenger/' + p);
    const errs = relevant(r.errors);
    check('messenger/' + p, r.status === 200 && !r.fatal && errs.length === 0,
      'status=' + r.status + ' len=' + r.body.length + (r.fatal ? ' FATAL: ' + r.fatal : '') +
      (errs.length ? ' ERR: ' + errs.slice(0, 2).join(' | ') : ''));
  }

  section('API پیام‌رسان از راه HTTP');
  const ping = await msg.get('/messenger/api.php?a=ping');
  let pj = {}; try { pj = JSON.parse(ping.body); } catch (e) { /* ignore */ }
  check('ping پاسخ JSON می‌دهد', ping.status === 200 && pj.success === true,
    'status=' + ping.status + ' body=' + ping.body.slice(0, 160));

  const tk = csrf(mIndex.body) || csrf((await msg.get('/messenger/settings.php')).body);
  check('توکن CSRF پیام‌رسان پیدا شد', tk !== '');

  const chats = await msg.post('/messenger/api.php?a=chats', { csrf_token: tk });
  let cj = {}; try { cj = JSON.parse(chats.body); } catch (e) { /* ignore */ }
  check('chats با کوکی واقعی کار می‌کند', cj.success === true && Array.isArray(cj.chats),
    'status=' + chats.status + ' body=' + chats.body.slice(0, 200));

  section('گروه در پیام‌رسان');
  const created = await msg.post('/messenger/api.php?a=group_create', {
    csrf_token: tk, name: 'گروه تست HTTP ' + Date.now(), 'members[]': '',
  });
  let gj = {}; try { gj = JSON.parse(created.body); } catch (e) { /* ignore */ }
  const grpUid = gj.success ? String(gj.group && gj.group.uid ? gj.group.uid : gj.group || '') : '';
  check('ساخت گروه از راه HTTP', gj.success === true && grpUid !== '', 'body=' + created.body.slice(0, 180));

  if (grpUid) {
    const gi = await msg.get('/messenger/group-info.php?group=' + grpUid);
    const giErr = relevant(gi.errors);
    check('group-info.php?group=… باز می‌شود',
      gi.status === 200 && !gi.fatal && giErr.length === 0 && gi.body.includes('tgRoot'),
      'status=' + gi.status + (gi.fatal ? ' FATAL: ' + gi.fatal : '') + (giErr.length ? ' ERR: ' + giErr.slice(0, 2).join(' | ') : ''));

    const gc = await msg.get('/messenger/chat.php?group=' + grpUid);
    const gcErr = relevant(gc.errors);
    check('chat.php?group=… باز می‌شود', gc.status === 200 && !gc.fatal && gcErr.length === 0,
      'status=' + gc.status + (gc.fatal ? ' FATAL: ' + gc.fatal : '') + (gcErr.length ? ' ERR: ' + gcErr.slice(0, 2).join(' | ') : ''));

    await msg.post('/messenger/api.php?a=group_delete', { csrf_token: tk, group: grpUid });
  }

  // ---------------------------------------------------------------- کارفرما
  section('پنل کارفرما (بدون ورود)');
  const cl0 = new Client('client0');
  const clLogin = await cl0.get('/client/login.php');
  check('صفحه ورود کارفرما رندر می‌شود', clLogin.status === 200 && clLogin.body.includes('پنل کارفرما'),
    'status=' + clLogin.status + ' fatal=' + clLogin.fatal + ' err=' + clLogin.errors.join('|'));
  for (const p of CLIENT_PAGES) {
    const r = await cl0.get('/client/' + p);
    check('client/' + p + ' (بدون ورود) ریدایرکت می‌دهد',
      r.status === 302 || r.location === 'login.php' || r.body.includes('cl-login'),
      'status=' + r.status + ' loc=' + r.location);
  }

  section('پنل کارفرما (وارد شده)');
  const cl = new Client('client');
  const clIn = await login(cl, '/client/login.php', 'qa_client', 'QaPass1234');
  check('ورود کارفرما موفق', [301, 302, 303].includes(clIn.res.status),
    'status=' + clIn.res.status + ' fatal=' + clIn.res.fatal + ' err=' + clIn.res.errors.join('|'));
  for (const p of CLIENT_PAGES) {
    const r = await cl.get('/client/' + p);
    const e3 = relevant(r.errors);
    check('client/' + p, r.status === 200 && !r.fatal && e3.length === 0 && r.body.length > 600,
      'status=' + r.status + ' len=' + r.body.length + (r.fatal ? ' FATAL: ' + r.fatal : '') +
      (e3.length ? ' ERR: ' + e3.slice(0, 2).join(' | ') : ''));
  }
  const clHome = await cl.get('/client/index.php');
  check('کارفرما پروژه خودش را می‌بیند',
    clHome.body.includes('پروژه') , 'len=' + clHome.body.length);
  // کارفرما نباید به پنل مدیریت برسد
  const clAdmin = await cl.get('/admin/dashboard.php');
  check('کارفرما به پنل مدیریت راه ندارد', clAdmin.status === 302 || clAdmin.body.includes('login'),
    'status=' + clAdmin.status);

  // ---------------------------------------------------------------- حضور و غیاب
  section('بخش حضور و غیاب (صفحه مستقل)');
  const at = new Client('attendance');
  const atLogin = await at.get('/attendance/login.php');
  check('صفحه ورود حضور و غیاب رندر می‌شود',
    atLogin.status === 200 && atLogin.body.includes('حضور و غیاب'),
    'status=' + atLogin.status + ' fatal=' + atLogin.fatal + ' err=' + atLogin.errors.join('|'));

  const atIn = await login(at, '/attendance/login.php', 'qa_emp', 'QaPass1234');
  check('ورود کارمند به حضور و غیاب', atIn.res.status === 302 || atIn.res.status === 303,
    'status=' + atIn.res.status + ' fatal=' + atIn.res.fatal + ' err=' + atIn.res.errors.join('|'));

  const atHome = await at.get('/attendance/');
  const atErrs = relevant(atHome.errors);
  check('صفحه «امروز» باز می‌شود', atHome.status === 200 && atHome.body.includes('ثبت ورود') && !atHome.fatal && atErrs.length === 0,
    'status=' + atHome.status + ' len=' + atHome.body.length + (atHome.fatal ? ' FATAL: ' + atHome.fatal : '') +
    (atErrs.length ? ' ERR: ' + atErrs.slice(0, 2).join(' | ') : ''));

  for (const t of ['requests', 'month']) {
    const r = await at.get('/attendance/?tab=' + t);
    const e2 = relevant(r.errors);
    check('attendance/?tab=' + t, r.status === 200 && !r.fatal && e2.length === 0,
      'status=' + r.status + ' len=' + r.body.length + (r.fatal ? ' FATAL: ' + r.fatal : '') +
      (e2.length ? ' ERR: ' + e2.slice(0, 2).join(' | ') : ''));
  }

  const atTeam = await at.get('/attendance/?tab=team');
  check('کارمند عادی تب تیم را نمی‌بیند',
    !atTeam.body.includes('وضعیت امروز تیم'), 'کارمند به تب مدیریتی دسترسی دارد');

  // ثبت ورود واقعی
  const tk2 = csrf(atHome.body);
  const ci = await at.post('/attendance/', { action: 'check_in', csrf_token: tk2 });
  check('ثبت ورود با POST کار می‌کند', ci.status === 200 && (ci.body.includes('ورود ثبت شد') || ci.body.includes('خروج')),
    'status=' + ci.status + ' snippet=' + ci.body.replace(/\s+/g, ' ').slice(0, 160));

  const co = await at.post('/attendance/', { action: 'check_out', csrf_token: tk2 });
  check('ثبت خروج با POST کار می‌کند', co.status === 200,
    'status=' + co.status);

  // مدیر باید تب تیم و ساخت QR را داشته باشد
  const atm = new Client('att-mgr');
  await login(atm, '/attendance/login.php', 'qa_manager', 'QaPass1234');
  const team = await atm.get('/attendance/?tab=team');
  const teamErrs = relevant(team.errors);
  check('مدیر تب وضعیت تیم را می‌بیند', team.status === 200 && team.body.includes('وضعیت امروز تیم'),
    'status=' + team.status + (team.fatal ? ' FATAL: ' + team.fatal : ''));
  check('تب تیم بدون خطا', !team.fatal && teamErrs.length === 0,
    (team.fatal || teamErrs.slice(0, 2).join(' | ')));

  const qr = await atm.post('/attendance/', { action: 'make_qr', minutes: '15', csrf_token: csrf(team.body) });
  const qrCode = (/کد QR ساخته شد: ([A-Z0-9]+)/.exec(qr.body) || [])[1] || '';
  check('ساخت کد QR توسط مدیر', qrCode.length === 16, 'code=' + qrCode);

  if (qrCode) {
    const scanned = await at.get('/attendance/?code=' + qrCode);
    check('اسکن QR توسط کارمند ثبت می‌شود',
      scanned.status === 200 && (scanned.body.includes('ثبت شد') || scanned.body.includes('خروج')),
      'status=' + scanned.status + ' snippet=' + scanned.body.replace(/\s+/g, ' ').slice(0, 140));
  }

  // درخواست دستی
  const req = await at.post('/attendance/', {
    action: 'request', csrf_token: tk2,
    day: new Date(Date.now() - 5 * 864e5).toISOString().slice(0, 10),
    check_in: '08:30', check_out: '16:30', reason: 'فراموشی اسکن (تست HTTP)',
  });
  check('ثبت درخواست دستی', req.status === 200 && (req.body.includes('ارسال شد') || req.body.includes('تعطیل') || req.body.includes('وجود دارد')),
    'status=' + req.status + ' snippet=' + req.body.replace(/\s+/g, ' ').slice(0, 160));

  const atOut = await at.get('/attendance/logout.php');
  check('خروج از حضور و غیاب', atOut.status === 302 || atOut.location === 'login.php', 'status=' + atOut.status);
  const afterOut = await at.get('/attendance/');
  check('بعد از خروج، صفحه در دسترس نیست', afterOut.status === 302 || afterOut.body.includes('at-login'),
    'status=' + afterOut.status);

  // ---------------------------------------------------------------- صفحات عمومی
  section('صفحه‌های عمومی');
  for (const p of PUBLIC_PAGES) {
    const r = await new Client('pub').get(p);
    const errs = relevant(r.errors);
    check(p, r.status === 200 && !r.fatal && errs.length === 0,
      'status=' + r.status + ' len=' + r.body.length + (r.fatal ? ' FATAL: ' + r.fatal : '') +
      (errs.length ? ' ERR: ' + errs.slice(0, 2).join(' | ') : ''));
  }

  section('مسیرهای بسته');
  if (process.env.ODSCO_NO_FILTER === '1') {
    console.log('  ⏭️  رد شد — سرور تست (php -S) فیلتر web.config را ندارد؛ روی IIS این مسیرها ۴۰۳ می‌شوند.');
  } else {
    for (const p of ['/data/users.json', '/includes/config.php', '/tools/smoke-test.php', '/database/schema.mysql.sql']) {
      const r = await new Client('pub').get(p);
      check(p + ' بسته است', r.status === 403 || r.status === 404, 'status=' + r.status);
    }
  }

  // ---------------------------------------------------------------- خلاصه
  console.log('\n' + '═'.repeat(60));
  console.log('نتیجه HTTP: ' + pass + ' موفق / ' + fail + ' ناموفق');
  if (results.length) { console.log('\nموارد ناموفق:'); for (const r of results) console.log('  • ' + r); }
  console.log('═'.repeat(60));
  process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('💥 خطای غیرمنتظره:', e); process.exit(2); });
