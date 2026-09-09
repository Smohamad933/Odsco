#!/usr/bin/env bash
# ============================================================================
#  رندر واقعی همه صفحه‌ها — هر صفحه در زیرفرآیند خودش
#  استفاده:  bash tools/render-test.sh
# ============================================================================
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; cd "$ROOT"

if command -v php >/dev/null 2>&1; then PHP="php"
elif [ -x "$HOME/tools/node_modules/.bin/php-wasm-cli" ]; then PHP="$HOME/tools/node_modules/.bin/php-wasm-cli"
else echo "❌ PHP پیدا نشد"; exit 1; fi

DBFILE="${ODSCO_TEST_DB:-/tmp/odsco_test.sqlite}"
rm -f "$DBFILE"
mkdir -p includes
BACKUP=""
[ -f includes/config.local.php ] && { BACKUP="$(mktemp)"; cp includes/config.local.php "$BACKUP"; }
cat > includes/config.local.php <<EOF
<?php
return ['driver' => 'sqlite', 'sqlite_path' => '$DBFILE', 'prefix' => '', 'debug' => false];
EOF
restore() { if [ -n "$BACKUP" ]; then mv "$BACKUP" includes/config.local.php; else rm -f includes/config.local.php; fi; }
trap restore EXIT

$PHP -r 'require_once "includes/config.php"; require_once "includes/migrate.php";
odsco_install_schema(Db::i()); $m = Migrator::run();
echo "✅ دیتابیس تست: ", count(Users::list()), " کاربر / ", count(Projects::list()), " پروژه\n";
if ($m["errors"]) { foreach ($m["errors"] as $e) echo "❌ $e\n"; exit(1); }' || exit 1
echo

# یک پروژه واقعی برای تست نمای تک‌پروژه
PROJ="$($PHP -r 'require_once "includes/config.php"; $p = Projects::list(); echo $p[0]["uid"] ?? "";')"

PASS=0; FAIL=0; FAILED=()
run() {
  local page="$1" qs="${2:-}" area="${3:-admin}"
  local out json
  out="$($PHP tools/render-one.php "$page" "$qs" "$area" 2>&1)"
  json="$(echo "$out" | grep '^__RENDER__' | tail -1 | sed 's/^__RENDER__//')"
  if [ -z "$json" ]; then
    FAIL=$((FAIL+1)); FAILED+=("$page — بدون خروجی: $(echo "$out" | grep -m1 -E 'Fatal|error' || echo '?')")
    printf '  ❌ %-46s crash\n' "$page${qs:+?$qs}"; return
  fi
  if echo "$json" | grep -q '"ok":true'; then
    PASS=$((PASS+1)); printf '  ✅ %-46s %s bytes\n' "$page${qs:+?$qs}" "$(echo "$json" | sed -n 's/.*"bytes":\([0-9]*\).*/\1/p')"
  else
    FAIL=$((FAIL+1))
    local why; why="$(echo "$json" | sed -n 's/.*"fatal":"\([^"]*\)".*/\1/p')"
    [ -z "$why" ] && why="$(echo "$json" | sed -n 's/.*"errors":\[\("\([^"]*\)"\)\?.*/\1/p')"
    FAILED+=("$page${qs:+?$qs} — $why")
    printf '  ❌ %-46s %s\n' "$page${qs:+?$qs}" "$why"
  fi
}

echo "── صفحات عمومی ──────────────────────────────────────────"
for p in index.php about/index.php services/index.php blog/index.php project/index.php contact/index.php; do
  [ -f "$p" ] && run "$p" "" public
done

echo; echo "── نصب‌کننده ────────────────────────────────────────────"
run install/index.php "" public
run install/check.php "" public

echo; echo "── پنل مدیریت ───────────────────────────────────────────"
run admin/dashboard.php
run admin/workspace.php
[ -n "$PROJ" ] && run admin/workspace.php "project=$PROJ"
run admin/attendance.php
run admin/attendance.php "tab=requests"
run admin/attendance.php "tab=month"
run admin/automation.php
run admin/automation.php "tab=logs"
run admin/automation.php "tab=cron"
run admin/automation.php "edit=new"
run admin/broadcast.php
run admin/manage-projects.php
run admin/manage-projects.php "add=1"
run admin/manage-messages.php
run admin/media-library.php
run admin/media-library.php "tab=trash"
run admin/manage-users.php
run admin/manage-clients.php
run admin/manage-blog.php
run admin/manage-categories.php
run admin/manage-team.php
run admin/access.php
run admin/settings.php
run admin/logs.php

# صفحه‌هایی که به پارامتر نیاز دارند
SLUG="$($PHP -r 'require_once "includes/config.php"; $p = Blog::list(); echo $p[0]["slug"] ?? "";')"
[ -n "$SLUG" ] && run blog/post.php "slug=$SLUG" public

echo; echo "── پیام‌رسان ─────────────────────────────────────────────"
run messenger/index.php       "" messenger
run messenger/chat.php        "saved=1" messenger
CONV="$($PHP -r 'require_once "includes/config.php"; require_once "includes/messenger.php";
$u = Users::list(["active" => true]); $a = $u[0]["uid"] ?? ""; $b = $u[1]["uid"] ?? "";
echo ($a && $b) ? Messenger::conversationFor($a, $b) : "";')"
[ -n "$CONV" ] && run messenger/chat.php "conversation=$CONV" messenger
GRP="$($PHP -r 'require_once "includes/config.php"; require_once "includes/messenger.php";
$u = Users::list(["active" => true]); $a = $u[0]["uid"] ?? ""; $b = $u[1]["uid"] ?? "";
echo ($a && $b) ? Messenger::createGroup("گروه تست رندر", [$b], $a) : "";')"
[ -n "$GRP" ] && run messenger/chat.php "group=$GRP" messenger
[ -n "$GRP" ] && run messenger/group-info.php "group=$GRP" messenger
run messenger/groups.php      "" messenger
run messenger/notices.php     "" messenger
run messenger/settings.php    "" messenger
run messenger/attendance.php  "" messenger

echo; echo "── حضور و غیاب (صفحه مستقل) ─────────────────────────────"
run attendance/login.php      "" anon
run attendance/index.php      "" attendance
run attendance/index.php      "tab=requests" attendance
run attendance/index.php      "tab=month" attendance
run attendance/index.php      "tab=team" attendance
PEER="$($PHP -r 'require_once "includes/config.php"; $u = Users::list(["active" => true]); echo $u[0]["uid"] ?? "";')"
[ -n "$PEER" ] && run messenger/profile.php "user=$PEER" messenger
[ -n "$PROJ" ] && run client/projects.php "project=$PROJ" client

echo; echo "── پنل کارفرما ───────────────────────────────────────────"
run client/index.php          "" client
run client/projects.php       "" client
run client/reports.php        "" client
run client/notifications.php  "" client

[ -n "${GRP:-}" ] && $PHP -r 'require_once "includes/config.php"; require_once "includes/messenger.php";
Messenger::deleteGroup($argv[1]);' "$GRP" >/dev/null 2>&1

echo; echo "──────────────────────────────────────────────────────────"
echo "نتیجه رندر: $PASS موفق / $FAIL ناموفق"
if [ ${#FAILED[@]} -gt 0 ]; then echo; echo "موارد ناموفق:"; for f in "${FAILED[@]}"; do echo "  • $f"; done; fi
exit $(( FAIL > 0 ))
