#!/usr/bin/env bash
# shellcheck source-path=SCRIPTDIR
# install/rocky/device-keys.sh: the devices' SSH host keys, which Ansible and Oxidized check every device against. It
# asks LibreNMS which devices Ansible (the Ansible plugin's inventory) and Oxidized connect to, and at which address;
# takes the keys found there (ssh-keyscan); shows the fingerprints of those the file of verified keys lacks
# (/root/device-known-hosts), one device at a time, and adds the ones you confirm; then installs that file as
# /etc/ssh/ssh_known_hosts, as install.sh --device-known-hosts does. Run it as root once the devices are in LibreNMS,
# and again when a device is added or gets a new key. device-keys.sh --help lists the options; install/rocky/README.md
# (Device SSH host keys) says more.

set -Eeuo pipefail
shopt -s inherit_errexit
shopt -u patsub_replacement 2>/dev/null || true
umask 022
export LC_ALL=C.UTF-8

INSTALLER_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lib/common.sh
. "$INSTALLER_DIR/lib/common.sh"
# shellcheck source=lib/librenms.sh
. "$INSTALLER_DIR/lib/librenms.sh"
# shellcheck source=lib/oxidized.sh
. "$INSTALLER_DIR/lib/oxidized.sh"

usage() {
    cat <<'EOF'
Usage: device-keys.sh [options] [DEVICE...]    (as root, on the LibreNMS server, once the devices are in LibreNMS)

Takes the SSH host keys of the devices Ansible and Oxidized connect to, at the addresses they connect to; shows the
fingerprints of the keys the file of verified keys does not have, one device at a time, and adds the ones you confirm;
then installs the file as /etc/ssh/ssh_known_hosts (as install.sh --device-known-hosts FILE does), which Ansible and
Oxidized check every device against. Compare the fingerprints with the ones the device shows (on its console, or
another source you trust): a scan alone does not prove who answered. A key that changed is shown with the one the
file has, and never taken without asking.

  DEVICE...          only these devices (hostname, sysName, IP address or LibreNMS device id)
  --file FILE        the devices' verified keys (default: /root/device-known-hosts; until there is one, it starts
                     from the keys installed now)
  --list             show whether the file has each device's keys; change nothing
  --accept-new       add the keys of a device the file has none for without asking (as ssh's
                     StrictHostKeyChecking=accept-new: only on a network you trust); never a key that changed
  --timeout SECONDS  how long a device has to answer (default: 5)
  -h, --help         this text

Exit code 0: the file holds the keys of every device that answered; 3: it does not (keys not confirmed, or one
that changed); 1: an error; 2: a bad option, or a device LibreNMS does not have.
EOF
}

bad_option() {
    printf 'device-keys.sh: %s\nSee device-keys.sh --help.\n' "$1" >&2
    exit 2
}

KEYS_FILE=/root/device-known-hosts
LIST=0
ACCEPT_NEW=0
TIMEOUT=5
WANTED=()

parse_options() {
    local opt value
    while [[ $# -gt 0 ]]; do
        opt=$1
        if [[ $opt == --*=* ]]; then value=${opt#*=}; opt=${opt%%=*}; set -- "$opt" "$value" "${@:2}"; fi
        case $opt in
            -h|--help) usage; exit 0 ;;
            --list) LIST=1 ;;
            --accept-new) ACCEPT_NEW=1 ;;
            --file|--timeout)
                [[ $# -ge 2 && -n $2 ]] || bad_option "$opt needs a value"
                if [[ $opt == --file ]]; then KEYS_FILE=$2; else TIMEOUT=$2; fi
                shift ;;
            --) shift; WANTED+=("$@"); break ;;
            -*) bad_option "unknown option: $opt" ;;
            *) WANTED+=("$opt") ;;
        esac
        shift
    done
    [[ $TIMEOUT =~ ^[0-9]{1,3}$ && $TIMEOUT -ge 1 && $TIMEOUT -le 120 ]] || bad_option "--timeout: '$TIMEOUT' is not a number of seconds from 1 to 120"
    if [[ $LIST == 1 && $ACCEPT_NEW == 1 ]]; then bad_option "--list changes nothing: it takes no --accept-new"; fi
    if [[ -e $KEYS_FILE && ! -f $KEYS_FILE ]]; then bad_option "--file: $KEYS_FILE is not a file"; fi
    if [[ $(realpath -m "$KEYS_FILE") == "$(realpath -m "$KNOWN_HOSTS_FILE")" ]]; then
        bad_option "--file: give a file of your own: device-keys.sh installs it as $KNOWN_HOSTS_FILE"
    fi
    if [[ ! -d $(dirname "$KEYS_FILE") ]]; then bad_option "--file: $(dirname "$KEYS_FILE") is not a directory"; fi
    if [[ -f $KEYS_FILE && -s $KEYS_FILE ]] && ! known_hosts_ok "$KEYS_FILE" && grep -q -v -E '^[[:space:]]*(#|$)' "$KEYS_FILE"; then
        bad_option "--file: $KEYS_FILE is not a known_hosts file (a line per key: <host>[,<host>...] <key type> <base64 key>)"
    fi
    return 0
}

main() {
    parse_options "$@"
    [[ $EUID == 0 ]] || { printf 'device-keys.sh: run it as root (sudo -i first).\n' >&2; exit 2; }
    [[ -f $LNMS_DIR/artisan ]] || { printf 'device-keys.sh: there is no LibreNMS in %s: run install.sh first.\n' "$LNMS_DIR" >&2; exit 1; }
    command -v ssh-keyscan >/dev/null || { printf 'device-keys.sh: ssh-keyscan is missing: dnf install openssh-clients (install.sh installs it).\n' >&2; exit 1; }
    mkdir -p "$STATE_DIR" "$LOG_DIR"
    chmod 700 "$STATE_DIR" "$LOG_DIR"
    if [[ $LIST == 0 ]]; then
        # the installer's lock: the file of keys and /etc/ssh/ssh_known_hosts change in one run at a time
        exec 9>"$STATE_DIR/lock"
        flock -n 9 || { printf 'device-keys.sh: the installer runs (%s/lock): try again once it has finished.\n' "$STATE_DIR" >&2; exit 2; }
    fi
    LOG_FILE=$LOG_DIR/device-keys-$(date +%Y%m%d-%H%M%S).log
    (umask 077 && : >"$LOG_FILE")
    WORK=$(mktemp -d /tmp/device-keys.XXXXXX)
    trap 'rm -rf "$WORK"' EXIT
    # librenms_helper.php runs as librenms, who may not read this directory (/root)
    chmod 755 "$WORK"
    cp -r "$INSTALLER_DIR/lib" "$WORK/"
    chmod -R a+rX "$WORK"
    CURRENT_PART=device-keys
    log "install/rocky/device-keys.sh started: $(quote_cmd "$@")"

    HELPER_HINT="read the log ($LOG_FILE), and logs/librenms.log in $LNMS_DIR" \
        helper device-addresses "$(json_object oxidized_user "$LNMS_OXIDIZED_USER")"
    printf '%s\n' "$HELPER_OUT" >"$WORK/devices.json"

    # no file of keys yet: it starts from the keys the installer installed (not from a file of the server's own)
    local seed=() installed
    if [[ ! -e $KEYS_FILE && -s $KNOWN_HOSTS_FILE ]]; then
        installed=$(sha256sum "$KNOWN_HOSTS_FILE" | cut -d' ' -f1)
        if [[ $installed == "$(state_get device_known_hosts || true)" ]]; then seed=(--seed "$KNOWN_HOSTS_FILE"); fi
    fi
    local args=(--devices "$WORK/devices.json" --file "$KEYS_FILE" --timeout "$TIMEOUT" "${seed[@]}")
    [[ $LIST == 1 ]] && args+=(--list)
    [[ $ACCEPT_NEW == 1 ]] && args+=(--accept-new)
    local rc=0
    python3 -I -u "$INSTALLER_DIR/lib/device_keys.py" "${args[@]}" -- "${WANTED[@]}" 2>&1 | tee -a "$LOG_FILE" || rc=$?
    case $rc in
        0|3) ;;
        *) log "device_keys.py ended with exit code $rc"; exit "$rc" ;;
    esac
    if [[ $LIST == 1 ]]; then exit "$rc"; fi

    # the file, installed for Ansible and Oxidized
    if [[ ! -f $KEYS_FILE ]] || ! known_hosts_ok "$KEYS_FILE"; then
        say "$KEYS_FILE has no key yet: $KNOWN_HOSTS_FILE stays as it is"
        exit "$rc"
    fi
    DEVICE_KNOWN_HOSTS=$KEYS_FILE
    device_known_hosts
    if ! cmp -s "$KEYS_FILE" "$KNOWN_HOSTS_FILE"; then
        say "$KNOWN_HOSTS_FILE was not changed: Ansible and Oxidized do not have the keys of $KEYS_FILE"
        exit 1
    fi
    if [[ ${#CHANGES[@]} == 0 ]]; then say "$KNOWN_HOSTS_FILE has these keys already"; fi
    log "finished"
    exit "$rc"
}

main "$@"
