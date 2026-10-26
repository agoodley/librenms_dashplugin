#!/bin/bash
# install/rocky/tests/device-logins-integration.sh NAME IP USER PASSWORD OTHER OTHER_IP: device-logins.sh on a test
# system after tests/e2e.sh and device-keys-integration.sh. A second fake switch NAME at IP has only the login USER /
# PASSWORD (fake-switch.sh NAME IP USER PASSWORD), not the default one: it is added to LibreNMS and its key taken
# (device-keys.sh); a CSV file gives it its login; then a change through the Ansible plugin, with Oxidized's backups
# before and after (e2e.sh), works on it, and still on OTHER (OTHER_IP), the switch of the default login; its line
# removed, the login is gone from Oxidized, LibreNMS, Semaphore UI and the playbook repository (once confirmed); and
# the file back, for the installer's second run, which keeps it. Run as root on the test system.
set -euo pipefail
usage='usage: device-logins-integration.sh NAME IP USER PASSWORD OTHER OTHER_IP'
NAME=${1:?$usage} IP=${2:?$usage} USER_NAME=${3:?$usage} USER_PASSWORD=${4:?$usage} OTHER=${5:?$usage} OTHER_IP=${6:?$usage}
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
LOGINS=/root/ci-device-logins.csv
OX_CONF=/home/oxidized/.config/oxidized/config
CRED=/root/librenms-installer-credentials

fail() { echo "FAIL  $*" >&2; exit 1; }
# run CODE ARGS...: device-logins.sh ARGS (stdin: nothing, as from a job); it must end with CODE; what it printed in $out
out=''
run() {
    local want=$1 rc=0
    shift
    out=$("$HERE/../device-logins.sh" "$@" </dev/null 2>&1) || rc=$?
    [[ $rc == "$want" ]] || fail "device-logins.sh $* ended with $rc, not $want: $out"
}
has() { grep -q -F -- "$1" <<<"$out" || fail "device-logins.sh $2 does not say '$1': $out"; }
oxidized_api() {
    curl -s --noproxy '*' --max-time 10 -H "X-Auth-Token: $(sed -n 's/^LIBRENMS_OXIDIZED_TOKEN=//p' "$CRED")" http://localhost/api/v0/oxidized
}
group_of() {  # group_of HOSTNAME: its group in LibreNMS' API for Oxidized ('' for none)
    oxidized_api | python3 -c 'import json, sys
print(next((d.get("group") or "" for d in json.load(sys.stdin) if d["hostname"] == sys.argv[1]), ""))' "$1"
}
full_name() {  # full_name HOSTNAME: its node in Oxidized (<group>/<name> in a group), once Oxidized lists it
    local n
    for _ in $(seq 1 60); do
        n=$(curl -s --noproxy '*' --max-time 5 http://127.0.0.1:8888/nodes.json | python3 -c 'import json, sys
print(next((n.get("full_name") or n["name"] for n in json.load(sys.stdin) if n["name"] == sys.argv[1]), ""))' "$1" 2>/dev/null || true)
        [[ $n == "$2" ]] && { printf '%s' "$n"; return 0; }
        sleep 2
    done
    printf '%s' "$n"
}
host_vars() {  # host_vars: the files of inventory/host_vars in the playbook repository
    local d; d=$(runuser -u semaphore -- mktemp -d)
    runuser -u semaphore -- git clone --quiet file:///var/lib/semaphore/playbooks.git "$d/repo"
    runuser -u semaphore -- find "$d/repo/inventory" -path '*/host_vars/*.yml' -printf '%f\n' | sort | tr '\n' ' '
    rm -rf "$d"
}
secrets() {  # secrets: the names of the variable group's secrets, through Semaphore UI's API
    local token sem
    sem=$(command -v semaphore || echo /usr/local/bin/semaphore)
    token=$("$sem" users token create --login admin --name ci-device-logins --ttl 1h --config /etc/semaphore/config.json 2>/dev/null | tr -d '[:space:]')
    T=$token python3 - <<'PY'
import json, os, urllib.request
opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
def call(method, path):
    request = urllib.request.Request('http://127.0.0.1:3000/api' + path, method=method, headers={'Authorization': 'Bearer ' + os.environ['T']})
    with opener.open(request, timeout=30) as response:
        raw = response.read().decode()
        return json.loads(raw) if raw.strip() else None
project = next(p for p in call('GET', '/projects') if p['name'] == 'LibreNMS changes')
env = next(e for e in call('GET', '/project/%d/environment' % project['id']) if e['name'] == 'LibreNMS')
env = call('GET', '/project/%d/environment/%d' % (project['id'], env['id']))
print(' '.join(sorted(s['name'] for s in env.get('secrets') or [])))
call('DELETE', '/user/tokens/' + os.environ['T'])
PY
}

# the switch in LibreNMS, and its key (the fake switches share theirs: it is known already, at another address)
E2E_ADD_ONLY=1 "$HERE/e2e.sh" "$NAME" "$IP" >/dev/null || fail "e2e.sh could not add $NAME to LibreNMS"
"$HERE/../device-keys.sh" --accept-new "$NAME" </dev/null >/dev/null || fail "device-keys.sh did not take the key of $NAME"
# shellcheck disable=SC2016 # PHP code
id=$(cd /opt/librenms && runuser -u librenms -- php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo DB::table("devices")->where("hostname", $argv[1])->value("device_id");' "$NAME")
[[ $id =~ ^[0-9]+$ ]] || fail "no LibreNMS device $NAME"
group=login-$id

# the file: root's alone, a comment, a password with a comma; --list changes nothing
(umask 077 && printf 'device,username,password,enable\n# %s has a login of its own\n%s,%s,"%s",\n' "$NAME" "$NAME" "$USER_NAME" "$USER_PASSWORD" >"$LOGINS")
run 0 --file "$LOGINS" --list
has "$NAME (LibreNMS device $id): $USER_NAME, new" --list
[[ -z $(group_of "$NAME") ]] || fail "--list gave $NAME a group already"
if grep -q -F -- "$USER_PASSWORD" <<<"$out"; then fail "--list printed the password"; fi
run 0 --file "$LOGINS"
if grep -q -F -- "$USER_PASSWORD" <<<"$out" || grep -r -q -F -- "$USER_PASSWORD" /var/log/librenms-installer/; then fail "the password is in the output or a log"; fi
[[ $(group_of "$NAME") == "$group" ]] || fail "LibreNMS does not give Oxidized the group $group for $NAME"
[[ -z $(group_of "$OTHER") ]] || fail "LibreNMS gives $OTHER a group"
grep -q -F "  $group:" "$OX_CONF" || fail "Oxidized's configuration has no group $group"
[[ $(stat -c '%U %a' "$OX_CONF") == 'oxidized 600' ]] || fail "Oxidized's configuration is $(stat -c '%U %a' "$OX_CONF")"
[[ $(full_name "$NAME" "$group/$NAME") == "$group/$NAME" ]] || fail "Oxidized does not have $NAME in its group $group"
[[ $(host_vars) == *"$NAME.yml "* ]] || fail "the playbook repository has no inventory/host_vars/$NAME.yml: $(host_vars)"
[[ " $(secrets) " == *" DEVICE_LOGIN_${id}_PASSWORD "* ]] || fail "the variable group has no secret DEVICE_LOGIN_${id}_PASSWORD: $(secrets)"
run 0 --file "$LOGINS"
has 'Nothing needed a change.' "(a second run)"
echo "PASS  device-logins.sh gives $NAME its own login: Oxidized's group $group, LibreNMS' mapping, the variable group's secrets, host_vars"

# a change on it with its own login (the default one does not log in there), Oxidized's backups before and after
SWITCH_USER=$USER_NAME SWITCH_PASSWORD=$USER_PASSWORD "$HERE/e2e.sh" "$NAME" "$IP" >/tmp/ci-e2e-own-login.out 2>&1 \
    || fail "the change on $NAME with its own login: $(grep -E '^FAIL' /tmp/ci-e2e-own-login.out)"
echo "PASS  a change through the Ansible plugin on $NAME with its own login, and Oxidized's backups before and after"
"$HERE/e2e.sh" "$OTHER" "$OTHER_IP" >/tmp/ci-e2e-default-login.out 2>&1 \
    || fail "the change on $OTHER with the default login: $(grep -E '^FAIL' /tmp/ci-e2e-default-login.out)"
echo "PASS  and on $OTHER with the default login (no group), Oxidized's group support on"

# its line removed: not without --yes (no terminal to ask), then gone everywhere
printf 'device,username,password,enable\n' >"$LOGINS"
run 2 --file "$LOGINS"
has 'give --yes' "(a line removed, no --yes)"
[[ $(group_of "$NAME") == "$group" ]] || fail "without --yes, $NAME lost its group"
run 0 --file "$LOGINS" --yes
[[ -z $(group_of "$NAME") ]] || fail "$NAME still has a group"
if grep -q -F "  $group:" "$OX_CONF"; then fail "Oxidized's configuration still has the group $group"; fi
[[ $(host_vars) != *"$NAME.yml"* ]] || fail "the playbook repository still has inventory/host_vars/$NAME.yml"
[[ " $(secrets) " != *" DEVICE_LOGIN_${id}_"* ]] || fail "the variable group still has the secrets of $NAME: $(secrets)"
[[ $(cd /opt/librenms && runuser -u librenms -- ./lnms config:get oxidized.group_support) == false ]] || fail "Oxidized's group support is still on"
echo "PASS  its line removed (once confirmed): its login is gone from Oxidized, LibreNMS, the variable group and the repository"

# and back, for the installer's second run (which must keep it)
(umask 077 && printf 'device,username,password,enable\n%s,%s,"%s",\n' "$NAME" "$USER_NAME" "$USER_PASSWORD" >"$LOGINS")
run 0 --file "$LOGINS"
[[ $(group_of "$NAME") == "$group" ]] || fail "$NAME did not get its group back"
echo "PASS  and back again"
