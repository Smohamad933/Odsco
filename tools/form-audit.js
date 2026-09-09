#!/usr/bin/env node
/**
 * ============================================================================
 *  Odsco — بازرسی قرارداد فرم‌ها با کد سمت سرور
 * ----------------------------------------------------------------------------
 *  برای هر <form> در صفحه‌های واقعی:
 *    ۱) چه فیلدهایی می‌فرستد؟
 *    ۲) اسکریپت مقصد چه کلیدهایی از $_POST/$_REQUEST می‌خواند؟
 *  هر کلید خوانده‌شده که در هیچ فرمی ارسال نمی‌شود = دکمه‌ای که ساکت کار نمی‌کند.
 *  (همین نوع باگ، صفحهٔ اتوماسیون را کاملاً از کار انداخته بود.)
 *
 *  اجرا:  node tools/form-audit.js [base]
 * ============================================================================
 */
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8080';
const jar = new Map();
const hdr = () => [...jar].map(([k, v]) => k + '=' + v).join('; ');
const eat = (r) => { for (const c of (r.headers.getSetCookie ? r.headers.getSetCookie() : [])) { const [p] = c.split(';'); const i = p.indexOf('='); if (i > 0) jar.set(p.slice(0, i).trim(), p.slice(i + 1).trim()); } };
const tok = (h) => (/name="csrf_token" value="([^"]+)"/.exec(h) || [])[1];

async function login(entry, user, pass) {
  jar.clear();
  let r = await fetch(BASE + entry, { headers: { Cookie: hdr() } }); eat(r);
  r = await fetch(BASE + entry, { method: 'POST', redirect: 'manual', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: hdr() }, body: new URLSearchParams({ username: user, password: pass, csrf_token: tok(await r.text()) }) });
  eat(r);
}
async function get(p) { const r = await fetch(BASE + p, { headers: { Cookie: hdr() } }); eat(r); return await r.text(); }

/** نام فیلدهای یک تکه HTML — آرایه‌ها را با [] نرمال می‌کند */
function fieldNames(html) {
  const out = new Set();
  const re = /<(input|select|textarea|button)\b[^>]*>/gi;
  let m;
  while ((m = re.exec(html))) {
    const tag = m[0];
    if (/type\s*=\s*"(submit|button|reset|image)"/i.test(tag)) continue;
    const n = /name\s*=\s*"([^"]*)"/i.exec(tag);
    if (!n) continue;
    // a_type[] → a_type ؛ c_field[0] → c_field
    out.add(n[1].replace(/\[[^\]]*\]/g, ''));
  }
  return out;
}

/** فرم‌های یک صفحه: [{action, method, fields}] — فرم‌های تودرتو هم دیده می‌شوند */
function forms(html) {
  const out = [];
  const re = /<form\b[^>]*>/gi;
  let m;
  while ((m = re.exec(html))) {
    const tag = m[0];
    const action = (/action\s*=\s*"([^"]*)"/i.exec(tag) || [])[1] || '';
    const method = (/method\s*=\s*"([^"]*)"/i.exec(tag) || [])[1] || 'get';
    if (method.toLowerCase() !== 'post') continue;
    // بدنه فرم تا </form> بعدی
    const end = html.indexOf('</form>', re.lastIndex);
    const body = end < 0 ? '' : html.slice(re.lastIndex, end);
    out.push({ action: action.split('?')[0] || '(همین صفحه)', fields: fieldNames(body) });
  }
  return out;
}

/** کلیدهایی که یک اسکریپت PHP از $_POST/$_REQUEST می‌خواند */
function phpPostKeys(file) {
  const src = fs.readFileSync(file, 'utf8');
  const keys = new Set();
  const re = /\$_(?:POST|REQUEST)\[\s*'([^']+)'\s*\]/g;
  let m;
  while ((m = re.exec(src))) keys.add(m[1]);
  return keys;
}

const PAGES = [
  ['/admin/login.php', 'admin'], ['/admin/dashboard.php', 'admin'], ['/admin/users.php', 'admin'],
  ['/admin/manage-users.php', 'admin'], ['/admin/access.php', 'admin'], ['/admin/automation.php', 'admin'],
  ['/admin/settings.php', 'admin'], ['/admin/notices.php', 'admin'], ['/admin/messages.php', 'admin'],
  ['/admin/projects.php', 'admin'], ['/admin/attendance.php', 'admin'],
  ['/messenger/login.php', 'msg'], ['/messenger/index.php', 'msg'], ['/messenger/settings.php', 'msg'],
  ['/messenger/groups.php', 'msg'], ['/messenger/notices.php', 'msg'], ['/messenger/profile.php', 'msg'],
  ['/attendance/login.php', 'att'], ['/attendance/index.php', 'att'],
];

(async () => {
  console.log('🔎 بازرسی قرارداد فرم‌ها با $_POST سمت سرور\n');
  const creds = { admin: ['/admin/login.php', 'qa_admin', 'QaPass1234'], msg: ['/messenger/login.php', 'qa_admin', 'QaPass1234'], att: ['/attendance/login.php', 'qa_manager', 'QaPass1234'] };
  const logged = new Set();

  let problems = 0, checked = 0;
  let current = '';
  for (const [url, area] of PAGES) {
    if (current !== area) { await login(...creds[area]); current = area; logged.add(area); }
    const html = await get(url);
    if (html.includes('ورود به') && html.length < 4000) continue;
    const fs_ = forms(html);
    if (!fs_.length) continue;

    // همه فیلدهای همه فرم‌های این صفحه که به یک مقصد می‌روند
    const byTarget = new Map();
    for (const f of fs_) {
      const t = f.action;
      if (!byTarget.has(t)) byTarget.set(t, new Set());
      f.fields.forEach((x) => byTarget.get(t).add(x));
    }

    for (const [target, sent] of byTarget) {
      const file = target === '(همین صفحه)' ? path.join(process.cwd(), url.split('?')[0])
        : path.join(process.cwd(), url.split('/').slice(0, -1).join('/'), target);
      if (!fs.existsSync(file)) continue;
      const reads = phpPostKeys(file);
      checked++;
      const missing = [...reads].filter((k) => k !== 'csrf_token' && !sent.has(k));
      if (missing.length) {
        problems++;
        console.log('  ⚠️  ' + url + '  →  ' + target);
        console.log('       سرور این کلیدها را می‌خواند ولی فرم نمی‌فرستد: ' + missing.join(', '));
      }
    }
  }

  console.log('\n════════════════════════════════════════');
  console.log(problems === 0
    ? '✅ همه ' + checked + ' فرم با اسکریپت مقصدشان هم‌خوان‌اند'
    : '⚠️  ' + problems + ' ناهم‌خوانی از ' + checked + ' فرم');
  console.log('════════════════════════════════════════');
})();
