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

printf '%d failures\n' "$FAILS"
exit "$FAILS"
