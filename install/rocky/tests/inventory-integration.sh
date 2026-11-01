#!/bin/bash
# PHP code for LibreNMS is in single quotes on purpose (SC2016); pass, fail and check always succeed (SC2015).
# shellcheck disable=SC2016,SC2015
# install/rocky/tests/inventory-integration.sh: the inventory plugin's software compliance (phase 2) on a server
# install.sh set up (a test container), through its own pages as an admin and a user would use them:
#  1. devices with known software, as a poll leaves it (ping-only devices; a stack of two models in the hardware
#     inventory; an IOS-XE version as LibreNMS writes it, the image's name first);
#  2. its tables and its hourly schedule;
#  3. policies saved through the settings form (a wrong one refused first), which checks the devices at once: each
#     device's status, the eventlog entries and their severities, an alert rule on them (LibreNMS' rule parser);
#  4. the pages, the device panel, the overview card and the CSV exports;
#  5. upgrades seen by inventory:check (the history, the eventlog), Check now;
#  6. a user who may see one device: its rows only, no Check now, no settings.
# Its devices (p2-*) and user (p2user) are removed at the end, whatever the outcome; the policies stay in the settings.
#
#   inventory-integration.sh
#
# Each step prints PASS or FAIL; the exit code is the number of failures.
set -uo pipefail
CRED=/root/librenms-installer-credentials
cred() { sed -n "s/^$1=//p" "$CRED" | head -n 1; }
ADMIN=${INSTALLER_ADMIN_USER:-admin}
PASSWORD=${INSTALLER_ADMIN_PASSWORD:-$(cred ADMIN_PASSWORD)}
BASE=http://localhost
FAILS=0
pass() { printf 'PASS  %s\n' "$*"; }
fail() { printf 'FAIL  %s\n' "$*"; FAILS=$((FAILS + 1)); }
check() { local what=$1; shift; if "$@"; then pass "$what"; else fail "$what"; fi; }
# shellcheck disable=SC2317,SC2329 # called through check
has() { grep -q -- "$1" <<<"$2"; }
count() { grep -c -- "$1" <<<"$2"; }
JAR=$(mktemp)
web() { curl -s --noproxy '*' --max-time 120 -b "$JAR" -c "$JAR" "$@"; }
csrf() { web "$BASE/$1" | sed -n 's/.*<meta name="csrf-token" content="\([^"]*\)".*/\1/p' | head -n 1; }
login() {  # USER PASSWORD
    : >"$JAR"
    local token; token=$(csrf login)
    web -o /dev/null --data-urlencode "_token=$token" --data-urlencode "username=$1" --data-urlencode "password=$2" "$BASE/login"
}
php_lnms() { (cd /opt/librenms && runuser -u librenms -- php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '"$1"); }
lnms() { (cd /opt/librenms && runuser -u librenms -- ./lnms "$@"); }
text() { python3 -I -c 'import html,re,sys; s=sys.stdin.read(); s=re.sub(r"(?s)<(script|style).*?</\1>","",s); print(re.sub(r"\s+"," ",html.unescape(re.sub(r"<[^>]+>"," ",s))))'; }
NAMES=(p2-core1 p2-acc1 p2-stack p2-leaf p2-srv)
# shellcheck disable=SC2317,SC2329 # the EXIT trap's
cleanup() {
    local name
    for name in "${NAMES[@]}"; do lnms device:remove "$name" >/dev/null 2>&1; done
    php_lnms '\App\Models\User::where("username", "p2user")->get()->each->delete();' >/dev/null 2>&1
    rm -f "$JAR"
}
trap cleanup EXIT

# --- 0. a clean start: the plugin's results and its eventlog entries go (its settings are saved again below)
php_lnms 'DB::table("inventory_software")->delete(); DB::table("inventory_upgrades")->delete(); DB::table("eventlog")->where("type", "inventory")->delete();'

# --- 1. devices with known software, as a poll would leave them (ping-only devices: nothing polls their SNMP)
declare -A OS=([p2-core1]=iosxe [p2-acc1]=iosxe [p2-stack]=ios [p2-leaf]=arista_eos [p2-srv]=linux)
for name in "${NAMES[@]}"; do
    exists=$(php_lnms 'echo DB::table("devices")->where("hostname", "'"$name"'")->exists() ? "yes" : "no";')
    [[ $exists == yes ]] || lnms device:add --ping-only --force --os "${OS[$name]}" "$name" >/dev/null 2>&1 || fail "lnms device:add $name"
done
php_lnms '
$set = ["p2-core1" => ["C9300-48P", "17.9.5"], "p2-acc1" => ["C9300-24T", "CAT9K_IOSXE 17.3.1"], "p2-stack" => ["WS-C2960X-48FPS-L", "15.2(4)E10"],
    "p2-leaf" => ["DCS-7050SX3-48YC8", ""], "p2-srv" => ["", "6.1"]];
foreach ($set as $name => [$hardware, $version]) {
    DB::table("devices")->where("hostname", $name)->update(["hardware" => $hardware, "version" => $version, "display" => ""]);
}
$stack = DB::table("devices")->where("hostname", "p2-stack")->value("device_id");
DB::table("entPhysical")->where("device_id", $stack)->delete();
$row = fn (int $index, string $model, string $serial) => array_filter(["device_id" => $stack, "entPhysicalIndex" => $index, "entPhysicalDescr" => "member",
    "entPhysicalClass" => "chassis", "entPhysicalName" => "Switch $index", "entPhysicalModelName" => $model, "entPhysicalSerialNum" => $serial,
    "entPhysicalContainedIn" => 0, "entPhysicalParentRelPos" => $index, "entPhysicalMfgName" => "Cisco", "entPhysicalHardwareRev" => "V01",
    "entPhysicalFirmwareRev" => "", "entPhysicalSoftwareRev" => "", "entPhysicalIsFRU" => "false", "entPhysicalAlias" => "", "entPhysicalAssetID" => "",
    "entPhysicalVendorType" => ""], fn ($v, $k) => Schema::hasColumn("entPhysical", $k), ARRAY_FILTER_USE_BOTH);
DB::table("entPhysical")->insert([$row(1001, "WS-C2960X-48FPS-L", "FCW2001A001"), $row(2001, "WS-C2960X-24PS-L", "FCW2001A002")]);'
ids=$(php_lnms 'echo json_encode(DB::table("devices")->where("hostname", "like", "p2-%")->pluck("device_id", "hostname"));')
dev() { python3 -I -c 'import json,sys; print(json.loads(sys.argv[1])[sys.argv[2]])' "$ids" "$1"; }
idlist=$(python3 -I -c 'import json,sys; print(",".join(str(v) for v in json.loads(sys.argv[1]).values()))' "$ids")
check "the test devices are in LibreNMS ($ids)" test "$(tr ',' '\n' <<<"$idlist" | grep -c .)" = "${#NAMES[@]}"
events() { php_lnms 'foreach (DB::table("eventlog")->where("type", "inventory")->whereIn("device_id", ['"$idlist"'])->orderBy("event_id")->get(["device_id", "message", "severity"]) as $e) echo $e->device_id, "|", $e->severity, "|", $e->message, "\n";'; }
statuses() { php_lnms 'foreach (DB::table("inventory_software")->join("devices", "devices.device_id", "=", "inventory_software.device_id")->where("hostname", "like", "p2-%")->orderBy("hostname")->get(["hostname", "inventory_software.status", "inventory_software.policy"]) as $r) echo "$r->hostname=$r->status($r->policy) ";'; }

# --- 2. the tables and the schedule
tables=$(php_lnms 'echo implode(",", array_filter(["inventory_config", "inventory_software", "inventory_upgrades"], fn ($t) => Schema::hasTable($t)));')
check "the plugin's tables exist ($tables)" test "$tables" = "inventory_config,inventory_software,inventory_upgrades"
sched=$(lnms schedule:list 2>&1 | grep -i 'inventory:check' | head -n 1)
check "the scheduler runs inventory:check hourly (${sched:-not listed})" has '23 \+\* \* \* \*' "$sched"

# --- 3. the admin saves policies through the settings form; nothing is saved while one is wrong
login "$ADMIN" "$PASSWORD"
token=$(csrf plugin/settings/inventory)
POLICIES="- name: IOS-XE advisories
  os: [ios, iosxe]
  blocked: ['16.*', '17.3.1']
  note: cisco-sa-test
- name: Catalyst 9300
  os: iosxe
  model: C9300-*
  approved: ['17.9.5', '17.12.*']
  minimum: '17.9.4'
- name: Catalyst 2960-X
  model: WS-C2960X-24PS-L
  minimum: '15.2(7)E8'
- name: EOS
  os: arista_eos
  minimum: '4.30.1F'"
save() { web -L --data-urlencode "_token=$token" --data-urlencode "device_group=" --data-urlencode "eventlog=1" --data-urlencode "eventlog_upgrades=1" \
    --data-urlencode "policies=$1" "$BASE/plugin/inventory/settings" | text; }
out=$(save "- os: arista_eos
  minimum: 4.30")
check "a policy with a version YAML reads as a number is refused" has "Nothing was saved.*write versions in quotes" "$out"
out=$(save "$POLICIES")
saved=$(grep -o 'Saved, and the devices checked with these settings: [^;]*' <<<"$out" | head -n 1)
check "the policies are saved and the devices checked at once (${saved:-no message})" test -n "$saved"

st=$(statuses)
echo "      $st"
check "p2-acc1 CAT9K_IOSXE 17.3.1 (release 17.3.1) is blocked by the policy for its os" has "p2-acc1=blocked(IOS-XE advisories)" "$st"
check "p2-core1 17.9.5 is approved by the policy for its model" has "p2-core1=approved(Catalyst 9300)" "$st"
check "p2-stack gets the policy of a stack member's model, and is below its minimum" has "p2-stack=below_minimum(Catalyst 2960-X)" "$st"
check "p2-leaf, without a version, is version unknown" has "p2-leaf=unknown(EOS)" "$st"
check "p2-srv has no policy" has "p2-srv=no_policy()" "$st"

ev=$(events)
check "eventlog: p2-acc1 not compliant, an error (5)" has "^$(dev p2-acc1)|5|Inventory: software not compliant: CAT9K_IOSXE 17.3.1 is blocked by the policy IOS-XE advisories. Note: cisco-sa-test" "$ev"
check "eventlog: p2-stack not compliant, a warning (4)" has "^$(dev p2-stack)|4|Inventory: software not compliant: 15.2(4)E10 is older than 15.2(7)E8" "$ev"
check "eventlog: nothing for a compliant device" test "$(count "^$(dev p2-core1)|" "$ev")" = 0
changed=$(php_lnms 'echo DB::table("eventlog")->where("type", "inventory")->whereNull("device_id")->where("message", "like", "%changed the settings%")->count();')
check "eventlog: the change of the settings ($changed)" test "$changed" -ge 1
matches=$(php_lnms '$json = json_encode(["condition" => "AND", "valid" => true, "rules" => [
    ["id" => "eventlog.type", "field" => "eventlog.type", "type" => "string", "input" => "text", "operator" => "equal", "value" => "inventory"],
    ["id" => "eventlog.message", "field" => "eventlog.message", "type" => "string", "input" => "text", "operator" => "begins_with", "value" => "Inventory: software not compliant"],
    ["id" => "eventlog.datetime", "field" => "eventlog.datetime", "type" => "string", "input" => "text", "operator" => "greater_or_equal", "value" => "`macros.past_60m`"]]]);
$sql = \LibreNMS\Alerting\QueryBuilderParser::fromJson($json)->toSql(true);
foreach (["p2-acc1", "p2-stack", "p2-core1"] as $name) echo $name, "=", count(DB::select($sql, [DB::table("devices")->where("hostname", $name)->value("device_id")])), " ";')
check "an alert rule on the eventlog (the README's) matches the devices that are not compliant ($matches)" test "$matches" = "p2-acc1=1 p2-stack=1 p2-core1=0 "

# --- 4. the pages as the admin (q=p2- leaves out the devices of the other tests)
for page in software 'software?status=not_compliant' upgrades overview "software?device=$(dev p2-stack)" settings; do
    url=plugin/inventory/$page
    [[ $page == settings ]] && url=plugin/settings/inventory
    code=$(web -o /dev/null -w '%{http_code}' "$BASE/$url")
    check "page $url ($code)" test "$code" = 200
done
sw=$(web "$BASE/plugin/inventory/software?status=not_compliant&q=p2-" | text)
check "software page: the devices not compliant, the worst first" has "p2-acc1 .* blocked .*p2-stack .* below the minimum" "$sw"
check "software page: the policies" has "Policies .*IOS-XE advisories .*Catalyst 2960-X" "$sw"
check "software page: Check now, for an admin" has "Check now" "$sw"
ov=$(web "$BASE/plugin/inventory/overview?q=p2-" | text)
check "overview: the software card" has "Software not compliant 2 1 blocked, 1 below the minimum or not approved" "$ov"
dp=$(web "$BASE/device/device=$(dev p2-acc1)/" | text)
check "device page: the panel's software line" has "Software blocked CAT9K_IOSXE 17.3.1 (IOS-XE advisories)" "$dp"
csv=$(web "$BASE/plugin/inventory/export/software?status=not_compliant&q=p2-")
check "software CSV: a header and the two devices not compliant" test "$(count $'\r$' "$csv")" = 3
sp=$(web "$BASE/plugin/settings/inventory" | text)
check "settings page: the last check" has "Last check: .* devices: " "$sp"

# --- 5. upgrades, seen by the command the scheduler runs
php_lnms 'DB::table("devices")->where("hostname", "p2-acc1")->update(["version" => "CAT9K_IOSXE 17.9.5"]);
DB::table("devices")->where("hostname", "p2-core1")->update(["version" => "17.10.1"]);
DB::table("devices")->where("hostname", "p2-stack")->update(["version" => "15.2(7)E10"]);
DB::table("devices")->where("hostname", "p2-leaf")->update(["version" => "4.29.2F"]);'
out=$(lnms inventory:check -v 2>&1)
check "inventory:check runs ($(tail -n 1 <<<"$out" | cut -c1-160))" has "inventory:check: .* devices: " "$out"
up=$(php_lnms 'foreach (DB::table("inventory_upgrades")->whereIn("device_id", ['"$idlist"'])->orderBy("id")->get() as $u) echo "$u->device_id:$u->from_version>$u->to_version:$u->direction ";')
echo "      $up"
check "the upgrades are recorded, a first version is none" test "$(grep -o ':upgrade ' <<<"$up" | wc -l)" = 3
check "p2-acc1's upgrade" has "$(dev p2-acc1):CAT9K_IOSXE 17.3.1>CAT9K_IOSXE 17.9.5:upgrade" "$up"
ev=$(events)
check "eventlog: p2-acc1 compliant again (ok)" has "^$(dev p2-acc1)|1|Inventory: software compliant: CAT9K_IOSXE 17.9.5 is approved" "$ev"
check "eventlog: p2-core1 upgraded to a version not approved" has "^$(dev p2-core1)|4|Inventory: software not compliant: 17.10.1 is not approved" "$ev"
check "eventlog: an entry for each upgrade" test "$(count '|Inventory: software upgraded from' "$ev")" = 3
check "eventlog: p2-leaf's first version, below the minimum" has "^$(dev p2-leaf)|4|Inventory: software not compliant: 4.29.2F is older than 4.30.1F" "$ev"
ug=$(web "$BASE/plugin/inventory/upgrades?q=p2-" | text)
check "upgrades page: the upgrades" has "p2-stack .* 15.2(4)E10 15.2(7)E10 upgrade" "$ug"
csv=$(web "$BASE/plugin/inventory/export/upgrades?q=p2-")
check "upgrades CSV: a header and the 3 upgrades" test "$(count $'\r$' "$csv")" = 4
token=$(csrf plugin/inventory/software)
out=$(web -L --data-urlencode "_token=$token" "$BASE/plugin/inventory/software/check" | text)
check "Check now: it says what the check found" has "Checked: [0-9]* devices: " "$out"

# --- 6. a user who may see one device: its rows only; no settings, no check now
php_lnms '$u = \App\Models\User::firstOrNew(["username" => "p2user"]);
$u->fill(["realname" => "p2", "email" => "p2@example.test", "auth_type" => "mysql", "can_modify_passwd" => 0]);
$u->setPassword("p2-Password-1");
$u->save();
if (method_exists($u, "syncRoles")) { $u->syncRoles(["user"]); }
DB::table("devices_perms")->where("user_id", $u->user_id)->delete();
DB::table("devices_perms")->insert(["user_id" => $u->user_id, "device_id" => DB::table("devices")->where("hostname", "p2-core1")->value("device_id")]);'
login p2user 'p2-Password-1'
sw=$(web "$BASE/plugin/inventory/software" | text)
check "the user sees their device" has "p2-core1" "$sw"
check "and no other of the test" test "$(count 'p2-acc1\|p2-stack' "$sw")" = 0
check "and no Check now" test "$(count 'Check now' "$sw")" = 0
token=$(csrf plugin/inventory/software)
code=$(web -o /dev/null -w '%{http_code}' --data-urlencode "_token=$token" "$BASE/plugin/inventory/software/check")
check "a check now is refused ($code)" test "$code" = 403
code=$(web -o /dev/null -w '%{http_code}' --data-urlencode "_token=$token" --data-urlencode "policies=" --data-urlencode "eventlog=1" \
    --data-urlencode "eventlog_upgrades=1" "$BASE/plugin/inventory/settings")
check "saving the settings is refused ($code)" test "$code" = 403
csv=$(web "$BASE/plugin/inventory/export/upgrades")
check "the user's upgrades CSV: their device's only" test "$(count 'p2-acc1\|p2-stack' "$csv")" = 0

errors=$(grep -c 'inventory plugin:' /opt/librenms/logs/librenms.log 2>/dev/null)
check "no error of the plugin in LibreNMS' log (${errors:-0})" test "${errors:-0}" = 0
printf '%d failures\n' "$FAILS"
exit "$FAILS"
