#!/bin/bash
# PHP code for LibreNMS is in single quotes on purpose (SC2016); pass, fail and stop always succeed (SC2015).
# shellcheck disable=SC2016,SC2015
# install/rocky/tests/verify.sh: check, on a server install.sh set up, that what it installed works. Run as root on
# that server (a test container). Each check prints PASS or FAIL; the exit code is the number of failures.
#
#   verify.sh [--oxidized-port PORT] [--semaphore-port PORT] [--expect-devices N]
#
# The admin's name and password come from INSTALLER_ADMIN_USER / INSTALLER_ADMIN_PASSWORD, else from the installer's
# credentials file.
set -uo pipefail
CRED=/root/librenms-installer-credentials
OX_PORT=8888
SEM_PORT=3000
EXPECT_DEVICES=''
while [[ $# -gt 0 ]]; do
    case $1 in
        --oxidized-port) OX_PORT=$2; shift 2 ;;
        --semaphore-port) SEM_PORT=$2; shift 2 ;;
        --expect-devices) EXPECT_DEVICES=$2; shift 2 ;;
        *) echo "unknown option $1" >&2; exit 2 ;;
    esac
done
cred() { sed -n "s/^$1=//p" "$CRED" 2>/dev/null | head -n 1; }
ADMIN=${INSTALLER_ADMIN_USER:-admin}
PASSWORD=${INSTALLER_ADMIN_PASSWORD:-$(cred ADMIN_PASSWORD)}
BASE=http://localhost
FAILS=0
pass() { printf 'PASS  %s\n' "$*"; }
fail() { printf 'FAIL  %s\n' "$*"; FAILS=$((FAILS + 1)); }
check() { local what=$1; shift; if "$@"; then pass "$what"; else fail "$what"; fi; }

JAR=$(mktemp)
trap 'rm -f "$JAR"' EXIT
web() { curl -s --noproxy '*' --max-time 60 -b "$JAR" -c "$JAR" "$@"; }
csrf() { web "$BASE/$1" | sed -n 's/.*<meta name="csrf-token" content="\([^"]*\)".*/\1/p' | head -n 1; }

# --- LibreNMS on nginx
code=$(web -o /dev/null -w '%{http_code}' "$BASE/login")
check "LibreNMS answers on nginx (GET /login: $code)" test "$code" = 200

# --- validate.php
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
validate() { (cd /opt/librenms && runuser -u librenms -- ./validate.php 2>&1 | sed 's/\x1b\[[0-9;]*m//g'); }
out=$(validate)
# LibreNMS' poller check wants a poller run within rrd.step (5 minutes). The installer ran the poller once; after
# that only cron runs it, every 5 minutes. When the check fails: what cron did, then its next run, then the check again
# (diagnose.sh --run-poller runs the poller itself only when cron did not).
if grep -q 'python wrapper poller' <<<"$out"; then
    echo "      validate.php: the python wrapper poller check failed. What cron and LibreNMS say:"
    if [[ -x $HERE/diagnose.sh ]]; then "$HERE/diagnose.sh" | sed 's/^/      /'; fi
    since=$(date '+%Y-%m-%d %H:%M:%S')
    pause=$(( 300 - $(date +%s) % 300 + 45 ))
    echo "      waiting ${pause} s for the poller cron job's next run ($since UTC + ${pause} s)"
    sleep "$pause"
    echo "      crond since then:"
    journalctl -u crond --since "$since" --no-pager -o short-iso 2>&1 | grep -v '^-- ' | sed 's/^/        /'
    mysql -t -e 'SELECT poller_name, last_polled, NOW() AS now FROM librenms.pollers' 2>&1 | sed 's/^/        /'
    out=$(validate)
    if grep -q 'python wrapper poller' <<<"$out" && [[ -x $HERE/diagnose.sh ]]; then
        echo "      cron did not run the poller: the poller run by hand (diagnose.sh --run-poller):"
        "$HERE/diagnose.sh" --run-poller | sed 's/^/      /'
    fi
fi
fails=$(grep -c '\[FAIL\]' <<<"$out")
warns=$(grep -c '\[WARN\]' <<<"$out")
grep -E '\[(FAIL|WARN)\]' <<<"$out" | sed 's/^/      /'
check "./validate.php: $fails failures, $warns warnings" test "$fails" = 0

# --- the admin logs in, and the plugins' pages load
token=$(csrf login)
code=$(web -o /dev/null -w '%{http_code}' --data-urlencode "_token=$token" --data-urlencode "username=$ADMIN" --data-urlencode "password=$PASSWORD" "$BASE/login")
home=$(web -o /dev/null -w '%{http_code}' "$BASE/")
check "the admin $ADMIN logs in (POST /login: $code, GET /: $home)" test "$home" = 200
for page in plugin/topology plugin/topology/l2 plugin/topology/stp plugin/ansible/changes plugin/ansible/inventory plugin/settings/ansible plugin/settings/topology; do
    body=$(web -w '\n%{http_code}' "$BASE/$page")
    code=${body##*$'\n'}
    if [[ $code == 200 ]] && ! grep -qiE 'whoops|exception|error 500' <<<"${body%$'\n'*}"; then pass "page $page (200)"; else fail "page $page ($code)"; fi
done
plugins=$(cd /opt/librenms && runuser -u librenms -- php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (DB::table("plugins")->whereIn("plugin_name", ["topology", "ansible"])->get() as $p) { echo $p->plugin_name, "=", $p->plugin_active, " "; }')
check "both plugins enabled in LibreNMS ($plugins)" grep -q 'topology=1.*ansible=1\|ansible=1.*topology=1' <<<"$plugins"

# --- Semaphore UI: its project, and the Ansible plugin's connection test (as its settings page runs it)
code=$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "http://127.0.0.1:$SEM_PORT/api/ping")
check "Semaphore UI answers (GET /api/ping: $code)" test "$code" = 200
runner=$(cred SEMAPHORE_RUNNER_TOKEN)
projects=$(curl -s --noproxy '*' --config - <<<"url = \"http://127.0.0.1:$SEM_PORT/api/projects\"
header = \"Authorization: Bearer $runner\"")
check "Semaphore UI's project \"LibreNMS changes\" exists (the Task Runner's token sees it)" grep -q '"name":"LibreNMS changes"' <<<"$projects"
token=$(csrf plugin/settings/ansible)
test_json=$(web -X POST -H "X-CSRF-TOKEN: $token" -H 'Accept: application/json' "$BASE/plugin/ansible/settings/test")
summary=$(python3 -c 'import json,sys
d=json.loads(sys.argv[1])
if not d.get("ok"): print("not ok: %s" % d.get("error")); sys.exit(1)
t=d.get("templates", [])
usable=[x["name"] for x in t if x["usable"]]
print("Semaphore %s, role %s, inventory %s, %d of %d templates usable (%s); warnings: %s" % (d.get("version"), d.get("role"), (d.get("inventory") or {}).get("id"), len(usable), len(t), ", ".join(usable), " | ".join(d.get("warnings", []))))
sys.exit(0 if d.get("role") == "task_runner" and t and len(usable) == len(t) and d.get("inventory") else 1)' "$test_json" 2>&1)
check "the Ansible plugin's connection test passes: $summary" test $? = 0

# --- the inventory endpoint with the inventory user's token (what the runner fetches)
inv=$(curl -s --noproxy '*' --config - <<<"url = \"$BASE/plugin/ansible/api/inventory\"
header = \"X-Auth-Token: $(cred LIBRENMS_INVENTORY_TOKEN)\"")
check "the plugin's inventory endpoint answers the inventory user ($(python3 -c 'import json,sys; d=json.loads(sys.argv[1]); print(len(d.get("_meta",{}).get("hostvars",{})), "hosts")' "$inv" 2>/dev/null || echo "not JSON"))" \
    python3 -c 'import json,sys; json.loads(sys.argv[1])["_meta"]' "$inv"

# --- Oxidized
devices=$(curl -s --noproxy '*' --config - <<<"url = \"$BASE/api/v0/oxidized\"
header = \"X-Auth-Token: $(cred LIBRENMS_OXIDIZED_TOKEN)\"" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))' 2>/dev/null || echo '?')
if [[ $devices == 0 ]]; then
    if systemctl is-enabled --quiet oxidized; then pass "Oxidized waits for LibreNMS' first device (LibreNMS' API lists none for it)"; else fail "Oxidized's service is not enabled"; fi
else
    nodes=$(curl -s --noproxy '*' --max-time 10 "http://127.0.0.1:$OX_PORT/nodes.json" | python3 -c 'import json,sys; print(" ".join(sorted(n["name"] for n in json.load(sys.stdin))))' 2>/dev/null)
    check "Oxidized answers and lists LibreNMS' devices ($devices in LibreNMS' API; Oxidized: ${nodes:-none})" test -n "$nodes"
fi
if [[ -n $EXPECT_DEVICES ]]; then check "LibreNMS' Oxidized API lists $EXPECT_DEVICES devices ($devices)" test "$devices" = "$EXPECT_DEVICES"; fi
ox_url=$(cd /opt/librenms && runuser -u librenms -- ./lnms config:get oxidized.url 2>/dev/null)
check "LibreNMS' Oxidized settings point at it ($ox_url)" test "$ox_url" = "http://127.0.0.1:$OX_PORT"

printf '%d failures\n' "$FAILS"
exit "$FAILS"
