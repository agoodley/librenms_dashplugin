#!/bin/bash
# install/rocky/tests/device-keys-integration.sh NAME IP: device-keys.sh on a test system after tests/e2e.sh, with the
# fake switch NAME in LibreNMS at IP and its key installed (fake-switch.sh, then --device-known-hosts). It finds the
# switch where Ansible and Oxidized connect to it and knows its key; a file without the key gets it once confirmed,
# and is installed; a changed key is shown, left out with --accept-new, taken once confirmed. At the end the keys
# installed before are installed again, by device-keys.sh. Run as root on the test system.
set -euo pipefail
NAME=${1:?usage: device-keys-integration.sh NAME IP} IP=${2:?usage: device-keys-integration.sh NAME IP}
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
KEYS=$HERE/../device-keys.sh
SYSTEM=/etc/ssh/ssh_known_hosts
OWN=/root/device-known-hosts
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
cp -p "$SYSTEM" "$fixture/installed"
[[ ! -e $OWN ]] || { echo "FAIL  $OWN exists before the test" >&2; exit 1; }

fail() { echo "FAIL  $*" >&2; exit 1; }
# run CODE ARGS...: device-keys.sh ARGS, its answers from $ANSWERS; it must end with CODE; what it printed in $out
out=''
run() {
    local want=$1 rc=0
    shift
    out=$(printf '%s' "${ANSWERS:-}" | "$KEYS" "$@" 2>&1) || rc=$?
    [[ $rc == "$want" ]] || fail "device-keys.sh $* ended with $rc, not $want: $out"
}
has() { grep -q -F -- "$1" <<<"$out" || fail "device-keys.sh $2 does not say '$1': $out"; }
keys_of() { grep -v -E '^[[:space:]]*(#|$)' "$1" || true; }
# the switch's key, as fake-switch.sh wrote it
key=$(awk -v ip="$IP" '{ n = split($1, h, ","); for (i = 1; i <= n; i++) if (h[i] == ip) { print $2 " " $3; exit } }' /root/ci-device-known-hosts)
[[ -n $key ]] || fail "/root/ci-device-known-hosts has no key for $IP"

# the switch, where Ansible (the plugin's inventory: its overwrite IP) and Oxidized (LibreNMS' API) connect to it
run 0 --list
grep -q -E "^$NAME \(LibreNMS device [0-9]+\): known$" <<<"$out" || fail "--list does not know $NAME's key: $out"
has "Ansible and Oxidized connect to $IP" --list
[[ ! -e $OWN ]] || fail "--list wrote $OWN"
echo "PASS  device-keys.sh finds $NAME where Ansible and Oxidized connect to it ($IP), and knows its key"

# no file of its own yet: it starts from the keys the installer installed, and installs nothing new
run 0
cmp -s "$OWN" "$fixture/installed" || fail "$OWN is not the installed keys: $(cat "$OWN")"
cmp -s "$SYSTEM" "$fixture/installed" || fail "$SYSTEM changed"
has "$SYSTEM has these keys already" ''
echo "PASS  without a file of its own, it starts from the keys installed"

# a file without the switch's key: not added when the answer is no, added and installed when it is yes
: >"$fixture/new"
ANSWERS=$'n\n' run 3 --file "$fixture/new"
has "$NAME (LibreNMS device" "--file (new)"
has ": new" "--file (new)"
[[ -z $(keys_of "$fixture/new") ]] || fail "a key not confirmed was added: $(cat "$fixture/new")"
cmp -s "$SYSTEM" "$fixture/installed" || fail "$SYSTEM changed for a file without keys"
ANSWERS=$'y\n' run 0 --file "$fixture/new"
[[ $(keys_of "$fixture/new") == "$IP $key" ]] || fail "the confirmed key is not in the file: $(cat "$fixture/new")"
cmp -s "$SYSTEM" "$fixture/new" || fail "$SYSTEM is not the file confirmed"
grep -q '^# '"$NAME"' (LibreNMS device [0-9]*): confirmed on ' "$fixture/new" || fail "no line says whose the key is: $(cat "$fixture/new")"
echo "PASS  a device the file lacks is added once confirmed, and the file installed for Ansible and Oxidized"

# a changed key: shown, never taken with --accept-new, taken once confirmed
ssh-keygen -q -t rsa -b 2048 -N '' -f "$fixture/other"
printf '%s %s\n' "$IP" "$(cut -d' ' -f1,2 "$fixture/other.pub")" >"$fixture/changed"
cp "$fixture/changed" "$fixture/changed.before"
run 3 --list --file "$fixture/changed"
has 'KEY CHANGED' "--list (changed)"
run 3 --accept-new --file "$fixture/changed"
has 'never takes a key that changed' "--accept-new (changed)"
cmp -s "$fixture/changed" "$fixture/changed.before" || fail "--accept-new changed the file: $(cat "$fixture/changed")"
ANSWERS=$'y\n' run 0 --file "$fixture/changed"
[[ $(keys_of "$fixture/changed") == "$IP $key" ]] || fail "the confirmed key did not replace the old one: $(cat "$fixture/changed")"
grep -q -F "# the key of $NAME changed, " "$fixture/changed" || fail "the old key is not kept as a comment: $(cat "$fixture/changed")"
cmp -s "$SYSTEM" "$fixture/changed" || fail "$SYSTEM is not the file confirmed"
echo "PASS  a changed key is shown, left out with --accept-new, and taken once confirmed"

# a device LibreNMS does not have; a run while the installer runs
run 2 --list no-such-device
has 'LibreNMS has no device no-such-device' "no-such-device"
flock /var/lib/librenms-installer/lock sleep 10 &
holder=$!
sleep 1
run 2
has 'the installer runs' "(while the installer runs)"
kill "$holder" 2>/dev/null || true
wait "$holder" 2>/dev/null || true
echo "PASS  it refuses a device LibreNMS does not have, and a run while the installer runs"

# back to the keys installed before, through device-keys.sh and the installer's state
run 0
cmp -s "$SYSTEM" "$fixture/installed" || fail "$SYSTEM is not the keys installed before: $(cat "$SYSTEM")"
echo "PASS  and back to the keys installed before"
