# shellcheck shell=bash
# Shared by the stubs: each records its command line in $STUB_LOG and keeps what it "set" in $STUB_STATE, so that a
# second run sees it as set (and changes nothing). They change nothing on the system.
STUB_LOG=${STUB_LOG:-/var/log/installer-stubs.log}
STUB_STATE=${STUB_STATE:-/var/lib/installer-stubs}
mkdir -p "$STUB_STATE"
printf '%s %s\n' "$(basename "$0")" "$*" >>"$STUB_LOG"
