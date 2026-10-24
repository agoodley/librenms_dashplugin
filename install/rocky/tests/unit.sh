#!/bin/bash
# shellcheck source-path=SCRIPTDIR
# install/rocky/tests/unit.sh: checks of the installer's own functions that need no server, no root and no network
# (CI runs it with shellcheck; anywhere: bash install/rocky/tests/unit.sh). Each check prints PASS or FAIL; the exit
# code is the number of failures.
set -uo pipefail
ROCKY=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
FAILS=0

# the Semaphore UI version the installer pins, as install.sh sets it
SEMAPHORE_VERSION=$(sed -n 's/^SEMAPHORE_VERSION=//p' "$ROCKY/install.sh")
export SEMAPHORE_VERSION
# shellcheck source=../lib/semaphore.sh
. "$ROCKY/lib/semaphore.sh"
# shellcheck source=../lib/librenms.sh
. "$ROCKY/lib/librenms.sh"
# shellcheck source=../lib/common.sh
. "$ROCKY/lib/common.sh"

# check WHAT GOT WANT: one check
check() {
    if [[ $2 == "$3" ]]; then printf 'PASS  %s\n' "$1"; else printf 'FAIL  %s: %s, should be %s\n' "$1" "$2" "$3"; FAILS=$((FAILS + 1)); fi
}

# semaphore_version WANT TEXT: sem_version_ok accepts (ok) or refuses (refused) TEXT, what `semaphore version` prints.
semaphore_version() {
    local want=$1 text=$2 got=refused
    if sem_version_ok "$text"; then got=ok; fi
    if [[ $got == "$want" ]]; then
        printf 'PASS  Semaphore UI version %-32s %s\n' "'$text'" "$got"
    else
        printf 'FAIL  Semaphore UI version %-32s %s, should be %s\n' "'$text'" "$got" "$want"
        FAILS=$((FAILS + 1))
    fi
}

[[ $SEMAPHORE_VERSION == 2.19.17 ]] || { echo "FAIL  install.sh pins Semaphore UI '$SEMAPHORE_VERSION', these checks are for 2.19.17"; exit 1; }
semaphore_version ok 'v2.19.17-f8cefe6-1792391878'          # the binary of the Semaphore UI 2.19.17 container image
semaphore_version ok '2.19.17-f8cefe6-1792391818'           # the binary of the release RPM (no "v", another build stamp)
semaphore_version refused '2.19.18-0d3e5a1-1793000000'      # another version
semaphore_version refused 'v2.19.170'                       # not a prefix: 2.19.170 is not 2.19.17
semaphore_version refused 'v2.19.1-f8cefe6-1792391878'
semaphore_version refused ''                                # no answer (the binary did not run)

# json_object and json_field: values (passwords, tokens) go to python on stdin; they come back as they were
# shellcheck disable=SC2016 # the $ and the backticks are part of the test password
tricky='p&ss "w\o$rd" `x` ä'
json=$(json_object login netops password "$tricky" empty '' list '@json:["a", 2]')
check "json_object/json_field: a password with quotes, backslash, \$, & and non-ASCII" "$(json_field "$json" password)" "$tricky"
check "json_object/json_field: an empty value" "$(json_field "$json" empty)" ''
check "json_object/json_field: @json: values and list items" "$(json_field "$json" list.1)" 2
check "json_field: a missing field" "$(json_field "$json" nothing.here)" ''

# valid_timezone: only a plain zone name that exists under /usr/share/zoneinfo (it ends up in php.ini)
timezone() {  # timezone WANT NAME
    local got=refused
    if valid_timezone "$2"; then got=ok; fi
    check "time zone $(printf '%q' "$2")" "$got" "$1"
}
if [[ -f /usr/share/zoneinfo/Europe/Berlin ]]; then
    for tz in UTC Europe/Berlin Etc/GMT+5 America/Port-au-Prince America/Argentina/Buenos_Aires; do timezone ok "$tz"; done
else
    echo "SKIP  time zones that exist (this machine has no /usr/share/zoneinfo)"
fi
for tz in ../../../etc/passwd /etc/passwd Europe/../Europe/Berlin ./UTC Europe//Berlin posix/Europe/Berlin right/UTC \
    'Europe/Berlin
date.timezone = UTC' 'UTC ' '' Europe/Nowhere; do
    timezone refused "$tz"
done

printf '%d failures\n' "$FAILS"
exit "$FAILS"
