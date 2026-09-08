#!/usr/bin/env node
/**
 * ============================================================================
 *  Odsco — سرور توسعه برای تست واقعی با مرورگر
 * ----------------------------------------------------------------------------
 *  PHP روی این محیط نصب نیست، پس هر درخواست .php را به php-wasm-cli می‌دهیم
 *  (tools/cgi-run.php) و فایل‌های ثابت را مستقیم سرو می‌کنیم.
 *  نشست‌ها واقعی‌اند (فایل در tools/.sessions) و کوکی ODSCO_SESS کار می‌کند.
 *
 *  اجرا:  node tools/serve.js [port]
 * ============================================================================
 */

const http = require('http');
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const PORT = parseInt(process.argv[2] || process.env.PORT || '8080', 10);
const HOST = '0.0.0.0';

const PHP = process.env.ODSCO_PHP
  || path.join(process.env.HOME || '/root', 'tools/node_modules/.bin/php-wasm-cli');
if (!fs.existsSync(PHP)) {
  console.error('❌ PHP پیدا نشد:', PHP);
  process.exit(1);
}

const MIME = {
  '.html': 'text/html; charset=utf-8', '.htm': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8', '.js': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8', '.png': 'image/png', '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg', '.gif': 'image/gif', '.webp': 'image/webp', '.svg': 'image/svg+xml',
  '.ttf': 'font/ttf', '.woff': 'font/woff', '.woff2': 'font/woff2', '.ico': 'image/x-icon',
  '.txt': 'text/plain; charset=utf-8', '.pdf': 'application/pdf', '.mp4': 'video/mp4',
  '.webmanifest': 'application/manifest+json',
};

// مسیرهایی که نباید از بیرون سرو شوند (همان چیزی که web.config بلاک می‌کند)
const BLOCKED_SEGMENTS = ['includes', 'data', 'tools', 'database'];
const BLOCKED_EXT = ['.sqlite', '.sql', '.json', '.lock', '.log'];

function isBlocked(urlPath) {
  const parts = urlPath.split('/').filter(Boolean);
  if (parts.some((p) => BLOCKED_SEGMENTS.includes(p))) return true;
  if (BLOCKED_EXT.includes(path.extname(urlPath).toLowerCase())) return true;
  return false;
}

function parseCookies(header) {
  const out = {};
  (header || '').split(';').forEach((pair) => {
    const i = pair.indexOf('=');
    if (i > 0) out[pair.slice(0, i).trim()] = decodeURIComponent(pair.slice(i + 1).trim());
  });
  return out;
}

/**
 * جای‌گذاری یک مقدار در ساختار تودرتو، دقیقاً مثل PHP:
 *   a=1          → {a: '1'}
 *   a[]=1&a[]=2  → {a: ['1','2']}
 *   a[0]=1       → {a: {'0':'1'}}
 *   u[usr][msg]=1→ {u: {usr: {msg: '1'}}}
 * querystring.parse خودِ Node هیچ‌کدام از این‌ها را نمی‌فهمد.
 */
function phpSet(out, rawKey, val) {
  const m = /^([^\[]*)((?:\[[^\]]*\])*)$/.exec(rawKey);
  if (!m) { out[rawKey] = val; return; }
  const base = m[1];
  const segs = [...(m[2] || '').matchAll(/\[([^\]]*)\]/g)].map((x) => x[1]);
  if (segs.length === 0) { out[base] = val; return; }

  if (typeof out[base] !== 'object' || out[base] === null) out[base] = segs[0] === '' ? [] : {};
  let node = out[base];

  for (let k = 0; k < segs.length; k++) {
    const seg = segs[k];
    const isLast = k === segs.length - 1;
    const nextSeg = segs[k + 1];

    if (seg === '') {
      if (!Array.isArray(node)) return;
      if (isLast) { node.push(val); return; }
      const child = nextSeg === '' ? [] : {};
      node.push(child);
      node = child;
    } else {
      if (isLast) { node[seg] = val; return; }
      if (typeof node[seg] !== 'object' || node[seg] === null) node[seg] = nextSeg === '' ? [] : {};
      node = node[seg];
    }
  }
}

function parsePhpForm(str) {
  const out = {};
  for (const pair of str.split('&')) {
    if (!pair) continue;
    const i = pair.indexOf('=');
    const key = decodeURIComponent((i < 0 ? pair : pair.slice(0, i)).replace(/\+/g, ' '));
    const val = i < 0 ? '' : decodeURIComponent(pair.slice(i + 1).replace(/\+/g, ' '));
    phpSet(out, key, val);
  }
  return out;
}

function parseBody(req, raw, cb) {
  const chunks = [];
  req.on('data', (c) => chunks.push(c));
  req.on('end', () => {
    const buf = Buffer.concat(chunks);
    const type = (req.headers['content-type'] || '').split(';')[0].trim();

    if (type === 'multipart/form-data') {
      const boundary = (req.headers['content-type'].match(/boundary=(.+)$/) || [])[1];
      const fields = {}; const files = {};
      if (boundary) {
        const text = buf.toString('binary');
        text.split('--' + boundary).forEach((part) => {
          const m = /Content-Disposition: form-data; name="([^"]+)"(?:; filename="([^"]*)")?([\s\S]*?)\r\n\r\n([\s\S]*)$/.exec(part);
          if (!m) return;
          const [, name, filename, head, value] = m;
          const body = value.replace(/\r\n$/, '');
          if (filename) {
            phpSet(files, name, {
              name: filename, type: /Content-Type: (.+)\r\n/.exec(head)?.[1] || 'application/octet-stream',
              tmp_name: '/tmp/upload_' + Math.random().toString(36).slice(2),
              error: 0, size: body.length, _content: body,
            });
          } else {
            phpSet(fields, name, body);
          }
        });
      }
      return cb({ fields, files });
    }

    if (type === 'application/json') {
      try { return cb({ fields: JSON.parse(buf.toString('utf8')), files: {} }); }
      catch (e) { return cb({ fields: {}, files: {} }); }
    }

    return cb({ fields: parsePhpForm(buf.toString('utf8')), files: {} });
  });
}

function runPhp(script, req, urlPath, parsed, cookies, res) {
  const outFile = path.join(ROOT, 'tools/.sessions', 'cgi_' + Date.now() + '_' + Math.random().toString(36).slice(2) + '.json');
  const payload = JSON.stringify({
    script, uri: urlPath + (req.url.includes('?') ? '?' + req.url.split('?')[1] : ''),
    method: req.method, get: parsed.get || {}, post: parsed.fields || {}, cookies,
    files: parsed.files || {}, headers: req.headers, host: req.headers.host,
    ip: '127.0.0.1', https: false, referer: req.headers.referer || '',
    content_type: req.headers['content-type'] || 'application/x-www-form-urlencoded',
  });

  const child = spawn(PHP, [path.join(ROOT, 'tools/cgi-run.php')], {
    cwd: ROOT, env: { ...process.env, ODSCO_CGI_OUT: outFile },
  });

  let stdout = ''; let stderr = '';
  child.stdout.on('data', (d) => { stdout += d; });
  child.stderr.on('data', (d) => { stderr += d; });

  const timer = setTimeout(() => { child.kill('SIGKILL'); }, 60000);

  child.on('close', () => {
    clearTimeout(timer);
    let out = null;
    try { out = JSON.parse(fs.readFileSync(outFile, 'utf8')); } catch (e) { /* ignore */ }
    try { fs.unlinkSync(outFile); } catch (e) { /* ignore */ }

    if (!out) {
      res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('CGI failed.\nstdout: ' + stdout.slice(0, 2000) + '\nstderr: ' + stderr.slice(0, 2000));
      return;
    }

    const headers = { 'Content-Type': 'text/html; charset=utf-8' };
    if (out.set_cookie) headers['Set-Cookie'] = out.set_cookie;
    (out.headers || []).forEach((h) => {
      const i = h.indexOf(':');
      if (i > 0) headers[h.slice(0, i).trim()] = h.slice(i + 1).trim();
    });

    const body = Buffer.from(out.body || '', 'utf8');
    headers['Content-Length'] = body.length;
    headers['X-Odsco-Errors'] = encodeURIComponent(JSON.stringify(out.errors || []));
    if (out.fatal) headers['X-Odsco-Fatal'] = encodeURIComponent(out.fatal);
    res.writeHead(out.status || 200, headers);
    res.end(body);
  });

  child.stdin.write(payload);
  child.stdin.end();
}

const server = http.createServer((req, res) => {
  const urlPath = decodeURIComponent(req.url.split('?')[0]);

  if (isBlocked(urlPath)) {
    res.writeHead(403, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('403 دسترسی مجاز نیست');
    return;
  }

  const cookies = parseCookies(req.headers.cookie);

  const handle = (parsed) => {
    let filePath = path.join(ROOT, urlPath);
    if (!filePath.startsWith(ROOT)) { res.writeHead(403); res.end('403'); return; }

    let isPhp = filePath.toLowerCase().endsWith('.php');
    if (!isPhp) {
      // مسیر بدون پسوند → index.php یا index.html
      if (fs.existsSync(filePath) && fs.statSync(filePath).isDirectory()) {
        const idxPhp = path.join(filePath, 'index.php');
        const idxHtml = path.join(filePath, 'index.html');
        if (fs.existsSync(idxPhp)) { filePath = idxPhp; isPhp = true; }
        else if (fs.existsSync(idxHtml)) { filePath = idxHtml; }
        else {
          res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
          res.end('404 پیدا نشد: ' + urlPath);
          return;
        }
      }
    }

    if (isPhp) {
      const get = {};
      new URLSearchParams((req.url.split('?')[1] || '')).forEach((v, k) => { get[k] = v; });
      return runPhp(filePath, req, urlPath, { ...parsed, get }, cookies, res);
    }

    fs.readFile(filePath, (err, data) => {
      if (err) {
        res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
        res.end('404 پیدا نشد: ' + urlPath);
        return;
      }
      res.writeHead(200, { 'Content-Type': MIME[path.extname(filePath).toLowerCase()] || 'application/octet-stream' });
      res.end(data);
    });
  };

  if (req.method === 'GET' || req.method === 'HEAD') handle({ fields: {}, files: {} });
  else parseBody(req, null, handle);
});

server.listen(PORT, HOST, () => {
  console.log('✅ سرور Odsco روی http://' + HOST + ':' + PORT + ' (ریشه: ' + ROOT + ')');
  console.log('   PHP: ' + PHP);
});
