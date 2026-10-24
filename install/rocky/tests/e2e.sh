#!/bin/bash
# PHP code for LibreNMS is in single quotes on purpose (SC2016); pass, fail and stop always succeed (SC2015).
# shellcheck disable=SC2016,SC2015
# install/rocky/tests/e2e.sh: end to end on a server install.sh set up (a test container), through the Ansible
# plugin's own pages as its admin would use them:
#  1. a fake Cisco IOS switch (SSH only) added to LibreNMS as the plugin's e2e seeds add one: os ios, the overwrite
#     IP, no SNMP;
#  2. Oxidized lists it (LibreNMS' API feeds it);
#  3. a change made with the template "Fix port descriptions" (the example playbook fix_port_descriptions.yml) on the
#     switch: preview, create, dry run (proven by the plugin), approve, apply; the run changed the device;
#  4. Oxidized's backups of it before and after the batch, the one after holding the new description.
# The switch must listen on IP:22 with the login the installer was given (fake-switches: netops / netops-pw).
#
#   e2e.sh NAME IP [INTERFACE]          e.g. e2e.sh inst-sw1 127.0.4.11 FastEthernet0/1
#
# Each step prints PASS or FAIL; the exit code is 0 when all passed. Verification of batches is switched off first
# (in the plugin's settings, as an admin would): a switch without SNMP never has the fresh LibreNMS data it waits for.
set -uo pipefail
NAME=$1 IP=$2 IFACE=${3:-FastEthernet0/1}
# the port as LibreNMS names it (its ifName): the fix template's answer names it so, and the playbook finds it in the
# running configuration by that abbreviation
IFNAME=$(sed -E 's/^([A-Za-z]{2})[A-Za-z-]*/\1/' <<<"$IFACE")
OX_PORT=${OX_PORT:-8888}
CRED=/root/librenms-installer-credentials
cred() { sed -n "s/^$1=//p" "$CRED" | head -n 1; }
ADMIN=${INSTALLER_ADMIN_USER:-admin}
PASSWORD=${INSTALLER_ADMIN_PASSWORD:-$(cred ADMIN_PASSWORD)}
BASE=http://localhost
DESC="installer e2e $(date +%H%M%S)"
FAILS=0
pass() { printf 'PASS  %s\n' "$*"; }
fail() { printf 'FAIL  %s\n' "$*"; FAILS=$((FAILS + 1)); }
stop() { fail "$*"; printf '%d failures\n' "$FAILS"; exit 1; }
JAR=$(mktemp)
trap 'rm -f "$JAR"' EXIT
web() { curl -s --noproxy '*' --max-time 120 -b "$JAR" -c "$JAR" "$@"; }
csrf() { web "$BASE/$1" | sed -n 's/.*<meta name="csrf-token" content="\([^"]*\)".*/\1/p' | head -n 1; }
api() {  # METHOD PATH [JSON]
    local token; token=$(csrf plugin/ansible/changes)
    web -X "$1" -H "X-CSRF-TOKEN: $token" -H 'Accept: application/json' -H 'Content-Type: application/json' ${3:+--data "$3"} "$BASE/$2"
}
field() { python3 -c 'import json,sys
d=json.loads(sys.argv[1])
for k in sys.argv[2].split("."): d = d[int(k)] if isinstance(d, list) else d.get(k)
print(json.dumps(d) if isinstance(d, (dict, list)) else d)' "$1" "$2" 2>/dev/null; }
php_lnms() { (cd /opt/librenms && runuser -u librenms -- env E2E_NAME="$NAME" E2E_IP="$IP" E2E_IFACE="$IFACE" E2E_IFNAME="$IFNAME" php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '"$1"); }

# --- 1. the switch in LibreNMS, as the plugin's e2e seeds add one
id=$(php_lnms '$name = getenv("E2E_NAME"); $ip = getenv("E2E_IP");
$id = DB::table("devices")->where("hostname", $name)->value("device_id");
if (! $id) {
    $id = DB::table("devices")->insertGetId(["hostname" => $name, "sysName" => $name, "display" => $name, "os" => "ios", "overwrite_ip" => $ip,
        "ip" => inet_pton($ip), "status" => 1, "status_reason" => "", "disabled" => 0, "ignore" => 0, "snmp_disable" => 1, "type" => "network",
        "hardware" => "Fake IOS switch", "version" => "15.0(2)SE", "community" => "public", "snmpver" => "v2c", "inserted" => now(), "last_polled" => now()]);
}
if (! DB::table("ports")->where("device_id", $id)->where("ifIndex", 1)->exists()) {
    DB::table("ports")->insert(["device_id" => $id, "ifIndex" => 1, "ifName" => getenv("E2E_IFNAME"), "ifDescr" => getenv("E2E_IFACE"),
        "ifType" => "ethernetCsmacd", "ifAdminStatus" => "up", "ifOperStatus" => "up", "disabled" => 0, "deleted" => 0, "ignore" => 0]);
}
echo $id;')
[[ $id =~ ^[0-9]+$ ]] && pass "device $NAME ($IP) in LibreNMS (#$id)" || stop "device $NAME not added: $id"

# --- 2. Oxidized lists it (its service starts within a minute once LibreNMS has a device)
for _ in $(seq 1 30); do
    nodes=$(curl -s --noproxy '*' --max-time 5 "http://127.0.0.1:$OX_PORT/nodes.json" || true)
    grep -q "\"name\":\"$NAME\"" <<<"$nodes" && break
    sleep 5
done
grep -q "\"name\":\"$NAME\"" <<<"$nodes" && pass "Oxidized lists $NAME" || fail "Oxidized does not list $NAME (nodes: ${nodes:0:200})"

# --- 3. the change, through the plugin's pages
token=$(csrf login)
web -o /dev/null --data-urlencode "_token=$token" --data-urlencode "username=$ADMIN" --data-urlencode "password=$PASSWORD" "$BASE/login"
[[ $(web -o /dev/null -w '%{http_code}' "$BASE/plugin/ansible/changes") == 200 ]] && pass "logged in as $ADMIN" || stop "cannot log in as $ADMIN"
token=$(csrf plugin/settings/ansible)
web -o /dev/null --data-urlencode "_token=$token" --data-urlencode "verify=0" --data-urlencode "section=ans-verify-form" "$BASE/plugin/ansible/settings/runs"
template=$(php_lnms 'echo json_decode(DB::table("ansible_config")->where("name", "settings")->value("value"), true)["fix_template_port_descriptions"] ?? "";')
[[ -n $template ]] && pass "the port description fix template is #$template (the plugin's settings)" || stop "no fix template in the plugin's settings"
answer="$NAME $IFNAME $DESC"
request=$(python3 -c 'import json,sys; print(json.dumps({"template": sys.argv[1], "devices": [int(sys.argv[2])], "canary": [int(sys.argv[2])],
    "vars": {"port_descriptions": sys.argv[3]}, "reference": "E2E-INSTALLER", "unplanned": "hand"}))' "$template" "$id" "$answer")
preview=$(api POST plugin/ansible/api/changes/preview "$request")
[[ $(field "$preview" ok) == True ]] && pass "preview: $(field "$preview" batches | python3 -c 'import json,sys; print(len(json.load(sys.stdin)), "batch(es)")')" \
    || stop "preview refused: ${preview:0:600}"
store=$(python3 -c 'import json,sys,secrets
r=json.loads(sys.argv[1]); p=json.loads(sys.argv[2])
r["batches"] = [[d["device_id"] for d in b["devices"]] for b in p["batches"]]
r["devices"] = [d for b in r["batches"] for d in b]
r["canary"] = r["batches"][0]
r["blast_signature"] = p.get("blast_signature") or ""
r["request_token"] = secrets.token_hex(16)
print(json.dumps(r))' "$request" "$preview")
created=$(api POST plugin/ansible/changes "$store")
change=$(field "$created" change_id)
[[ $change =~ ^[0-9]+$ ]] && pass "change #$change created" || stop "change not created: ${created:0:600}"

wait_status() {  # STATUS... : poll the change until its status is one of them (or it ends), at most 10 minutes
    local s
    for _ in $(seq 1 120); do
        state=$(api GET "plugin/ansible/api/changes/$change")
        s=$(field "$state" status)
        for want in "$@"; do [[ $s == "$want" ]] && return 0; done
        [[ $(field "$state" final) == True ]] && return 1
        sleep 5
    done
    return 1
}
out=$(api POST "plugin/ansible/changes/$change/dry-run" '{"secrets":{}}')
if wait_status awaiting_approval ready; then pass "dry run proven: the change awaits approval"; else stop "dry run: status $(field "$state" status) (${out:0:300}) $(field "$state" label)"; fi
out=$(api POST "plugin/ansible/changes/$change/approve" '{"comment":"e2e of the installer"}')
if wait_status ready; then pass "approved"; else stop "approve: status $(field "$state" status) (${out:0:300})"; fi
out=$(api POST "plugin/ansible/changes/$change/apply" '{"secrets":{}}')
if wait_status applied; then pass "applied"; else stop "apply: status $(field "$state" status) $(field "$state" label) (${out:0:300})"; fi

# --- what the plugin recorded, and the switch's configuration in Oxidized. The backup after the batch is followed by
# the change page's poll and the plugin's sync command: wait for it.
target() { cd /opt/librenms && runuser -u librenms -- env E2E_CHANGE="$change" php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode(DB::table("ansible_change_targets")->where("change_id", (int) getenv("E2E_CHANGE"))->first());'; }
for _ in $(seq 1 60); do
    api GET "plugin/ansible/api/changes/$change" >/dev/null
    [[ $(field "$(target)" after_status) == requested ]] || break
    sleep 5
done
results=$(cd /opt/librenms && runuser -u librenms -- env E2E_CHANGE="$change" php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$t = DB::table("ansible_change_targets")->where("change_id", (int) getenv("E2E_CHANGE"))->first();
echo json_encode($t);')
dry=$(field "$results" dry_result); result=$(field "$results" result)
[[ $dry == changed ]] && pass "the dry run: would change $NAME" || fail "the dry run's result for $NAME: $dry"
[[ $result == changed ]] && pass "the batch changed $NAME" || fail "the batch's result for $NAME: $result"
before=$(field "$results" before_status); after=$(field "$results" after_status)
[[ $before == fresh && $after == fresh ]] && pass "Oxidized backups before and after the batch: fresh ($(field "$results" before_version | cut -c1-8) -> $(field "$results" after_version | cut -c1-8))" \
    || fail "Oxidized backups before/after the batch: $before / $after ($(field "$results" before_error) $(field "$results" after_error))"
config=$(curl -s --noproxy '*' --max-time 20 "http://127.0.0.1:$OX_PORT/node/fetch/$NAME")
grep -q "description $DESC" <<<"$config" && pass "Oxidized's backup of $NAME holds the new description ($DESC)" || fail "Oxidized's backup of $NAME lacks the description '$DESC' (${config:0:200})"

printf '%d failures\n' "$FAILS"
exit "$FAILS"
