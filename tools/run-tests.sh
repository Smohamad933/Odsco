#!/usr/bin/env bash
# ============================================================================
#  اجرای تست‌های پروژه Odsco
#  استفاده:  bash tools/run-tests.sh
#
#  اگر PHP روی سیستم باشد از همان استفاده می‌کند، وگرنه از نسخه wasm محلی.
# ============================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# --- پیدا کردن PHP -----------------------------------------------------------
if command -v php >/dev/null 2>&1; then
  PHP="php"
elif [ -x "$HOME/tools/node_modules/.bin/php-wasm-cli" ]; then
  PHP="$HOME/tools/node_modules/.bin/php-wasm-cli"
else
  echo "❌ PHP پیدا نشد. یا php نصب کنید یا در پوشه tools:  npm i @php-wasm/cli"
  exit 1
fi

echo "PHP: $($PHP -r 'echo PHP_VERSION;')"
echo

# --- ۱) بررسی نحوی همه فایل‌ها ----------------------------------------------
echo "═══ ۱) بررسی نحوی (php -l) ═══"
LINT_FAIL=0
while IFS= read -r f; do
  out="$($PHP -l "$f" 2>&1)"
  if ! echo "$out" | grep -q "No syntax errors"; then
    echo "❌ $f"
    echo "$out" | head -4
    LINT_FAIL=1
  fi
done < <(find . -name "*.php" -not -path "./node_modules/*" -not -path "./.git/*")
[ $LINT_FAIL -eq 0 ] && echo "✅ همه فایل‌ها بدون خطای نحوی"
echo

# --- ۲) آماده‌سازی دیتابیس تست ----------------------------------------------
DBFILE="${ODSCO_TEST_DB:-/tmp/odsco_test.sqlite}"
rm -f "$DBFILE"

mkdir -p "$ROOT/includes"
BACKUP=""
if [ -f "$ROOT/includes/config.local.php" ]; then
  BACKUP="$(mktemp)"
  cp "$ROOT/includes/config.local.php" "$BACKUP"
fi

cat > "$ROOT/includes/config.local.php" <<EOF
<?php
return ['driver' => 'sqlite', 'sqlite_path' => '$DBFILE', 'prefix' => '', 'debug' => false];
EOF

restore() {
  if [ -n "$BACKUP" ]; then mv "$BACKUP" "$ROOT/includes/config.local.php";
  else rm -f "$ROOT/includes/config.local.php"; fi
}
trap restore EXIT

echo "═══ ۲) نصب اسکیمای دیتابیس + مهاجرت داده ═══"
$PHP -r '
require_once "includes/config.php";
require_once "includes/migrate.php";
$r = odsco_install_schema(Db::i());
$m = Migrator::run();
echo "✅ ", count($r["created"]), " جدول / ", $r["indexes"], " ایندکس / ", array_sum($m["counts"]), " رکورد مهاجرت‌شده\n";
if ($m["errors"]) { echo "❌ خطاهای مهاجرت:\n"; foreach ($m["errors"] as $e) echo "   - $e\n"; exit(1); }
' || exit 1

echo

# --- ۳) تست یکپارچه ---------------------------------------------------------
echo "═══ ۳) تست یکپارچه ═══"
# نکته: برخی بیلدهای PHP (مثل php-wasm) هنگام خطای مهلک کد خروج ۰ برمی‌گردانند،
# برای همین خروجی را هم بررسی می‌کنیم.
OUT="$($PHP tools/smoke-test.php 2>&1)"
CODE=$?
echo "$OUT"

RESULT=0
echo "$OUT" | grep -q "Fatal error"   && { echo "❌ خطای مهلک در اجرا"; RESULT=1; }
echo "$OUT" | grep -q "^Warning:"      && { echo "❌ هشدار PHP در اجرا"; RESULT=1; }
echo "$OUT" | grep -q "^Notice:"       && { echo "❌ Notice در اجرا"; RESULT=1; }
echo "$OUT" | grep -q "Deprecated:"    && { echo "❌ Deprecated در اجرا"; RESULT=1; }
echo "$OUT" | grep -q "❌"             && { echo "❌ یک یا چند assertion ناموفق"; RESULT=1; }
echo "$OUT" | grep -qE "ناموفق: *$"   && RESULT=1
[ $CODE -ne 0 ] && RESULT=1

# --- ۴) سازگاری API و رندر واقعی صفحه‌ها ------------------------------------
echo "═══ ۴) سازگاری فراخوانی‌ها با API ═══"
API_OUT="$($PHP tools/check-api.php 2>&1)"
echo "$API_OUT" | tail -3
echo "$API_OUT" | grep -q "✅" || RESULT=1
echo

echo "═══ ۴ب) پیشوند جدول‌ها ═══"
PREFIX_OUT="$($PHP tools/prefix-test.php 2>&1)"
echo "$PREFIX_OUT" | grep -E "^  ❌|نتیجه پیشوند" | tail -6
echo "$PREFIX_OUT" | grep -qE "نتیجه پیشوند: [0-9]+ موفق / 0 ناموفق" || RESULT=1
echo

echo "═══ ۵) رندر واقعی صفحه‌ها ═══"
RENDER_OUT="$(bash tools/render-test.sh 2>&1)"
echo "$RENDER_OUT" | grep -E "^  (✅|❌)|نتیجه رندر" | tail -8
echo "$RENDER_OUT" | grep -qE "نتیجه رندر: [0-9]+ موفق / 0 ناموفق" || RESULT=1
echo

echo "═══ ۶) اکشن‌های API پیام‌رسان ═══"
# هر اکشن messenger/api.php در یک زیرفرآیند واقعی صدا زده می‌شود.
export ODSCO_PHP="$PHP"
APIACT_OUT="$($PHP tools/api-test.php 2>&1)"
echo "$APIACT_OUT" | grep -E "^  ❌|نتیجه API" | tail -12
echo "$APIACT_OUT" | grep -qE "نتیجه API: [0-9]+ موفق / 0 ناموفق" || RESULT=1
echo

# --- ۷) تست واقعی از راه HTTP ----------------------------------------------
# اگر PHP واقعی روی سیستم باشد از سرور خود PHP استفاده می‌شود، وگرنه
# tools/serve.js درخواست‌ها را به php-wasm می‌دهد.
# کاربران تست برای مرحله‌های ۷ و ۸ — بعد از تست یکپارچه، چون آن تست
# داده‌های آزمایشی خودش را پاک می‌کند.
$PHP tools/make-test-users.php || exit 1
echo

echo "═══ ۷) تست HTTP با نشست و کوکی واقعی ═══"
if command -v node >/dev/null 2>&1; then
  PORT=8199
  SRV_PID=""
  if command -v php >/dev/null 2>&1; then
    php -S 127.0.0.1:$PORT -t "$ROOT" >/tmp/odsco_srv.log 2>&1 &
    SRV_PID=$!
    export ODSCO_NO_FILTER=1   # سرور داخلی PHP فیلتر web.config را ندارد
    echo "  (سرور: php -S روی پورت $PORT)"
  else
    node tools/serve.js $PORT >/tmp/odsco_srv.log 2>&1 &
    SRV_PID=$!
    echo "  (سرور: tools/serve.js روی پورت $PORT)"
  fi

  for _ in $(seq 1 40); do
    if node -e "fetch('http://127.0.0.1:$PORT/').then(()=>process.exit(0)).catch(()=>process.exit(1))" 2>/dev/null; then break; fi
    sleep 1
  done

  HTTP_OUT="$(node tools/http-test.js http://127.0.0.1:$PORT 2>&1)"
  echo "$HTTP_OUT" | grep -E "^  ❌|نتیجه HTTP|⏭️" | tail -14
  echo "$HTTP_OUT" | grep -qE "نتیجه HTTP: [0-9]+ موفق / 0 ناموفق" || RESULT=1

  [ -n "$SRV_PID" ] && kill "$SRV_PID" 2>/dev/null
  wait "$SRV_PID" 2>/dev/null
else
  echo "  ⏭️  node نصب نیست — این مرحله رد شد."
fi
echo

# --- ۸) اجرای جاوااسکریپت صفحه‌ها در DOM -----------------------------------
echo "═══ ۸) اجرای JS صفحه‌ها در DOM ═══"
if command -v node >/dev/null 2>&1 && [ -d "$HOME/tools/node_modules/jsdom" ]; then
  PORT=8199
  node tools/serve.js $PORT >/tmp/odsco_srv2.log 2>&1 &
  SRV_PID=$!
  for _ in $(seq 1 40); do
    if node -e "fetch('http://127.0.0.1:$PORT/').then(()=>process.exit(0)).catch(()=>process.exit(1))" 2>/dev/null; then break; fi
    sleep 1
  done
  DOM_OUT="$(node tools/dom-test.js http://127.0.0.1:$PORT 2>&1)"
  echo "$DOM_OUT" | grep -E "^  ❌|نتیجه DOM" | tail -14
  echo "$DOM_OUT" | grep -qE "نتیجه DOM/JS: [0-9]+ موفق / 0 ناموفق" || RESULT=1
  kill "$SRV_PID" 2>/dev/null
  wait "$SRV_PID" 2>/dev/null
else
  echo "  ⏭️  node یا jsdom نصب نیست — این مرحله رد شد (npm i jsdom)."
fi
echo

[ $RESULT -eq 0 ] && [ $LINT_FAIL -eq 0 ] && echo "🎉 همه تست‌ها موفق" || echo "⚠️  تست‌ها ناموفق بودند"
exit $((RESULT + LINT_FAIL))
