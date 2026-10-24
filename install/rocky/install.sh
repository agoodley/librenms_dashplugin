#!/usr/bin/env bash
# shellcheck source-path=SCRIPTDIR
# install/rocky/install.sh: set up LibreNMS (when the server has none), the LibreNMS topology and Ansible plugins of
# this repository, Ansible with the network collections, Semaphore UI (the runner the Ansible plugin drives) and
# Oxidized (the backups it takes) on Rocky Linux 9 or 10. Run it as root; install.sh --help lists the options and
# install/rocky/README.md says what it changes on the server.
#
# One run sets up, in order, each part it finds missing and checks the parts that are there: a second run changes
# nothing that is right, and a run that was stopped picks up where it stopped. --check says what it would do.

set -Eeuo pipefail
shopt -s inherit_errexit
# bash 5.2 (Rocky 10) gives & in ${var//pattern/replacement} a meaning: values (passwords, names) are put in as they are
shopt -u patsub_replacement 2>/dev/null || true
umask 022
export LC_ALL=C.UTF-8

INSTALLER_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
FILES_DIR=$INSTALLER_DIR/files
# shellcheck source=lib/common.sh
. "$INSTALLER_DIR/lib/common.sh"
# shellcheck source=lib/librenms.sh
. "$INSTALLER_DIR/lib/librenms.sh"
# shellcheck source=lib/plugins.sh
. "$INSTALLER_DIR/lib/plugins.sh"
# shellcheck source=lib/ansible.sh
. "$INSTALLER_DIR/lib/ansible.sh"
# shellcheck source=lib/semaphore.sh
. "$INSTALLER_DIR/lib/semaphore.sh"
# shellcheck source=lib/oxidized.sh
. "$INSTALLER_DIR/lib/oxidized.sh"

PARTS=(librenms plugins ansible semaphore oxidized)
SEMAPHORE_VERSION=2.19.17
OXIDIZED_VERSION=0.37.0
OXIDIZED_WEB_VERSION=0.18.1
LIBRENMS_MIN=26.10.0      # the plugins were developed and tested against LibreNMS 26.10
LIBRENMS_MAX_MAJOR=26

usage() {
    cat <<'EOF'
Usage: install.sh [options]        (as root, on Rocky Linux 9 or 10)

Sets up, in this order, each part it finds missing, and checks the parts that are there:
  librenms   LibreNMS itself, only when the server has none (/opt/librenms), as LibreNMS documents it for Rocky
  plugins    the topology and Ansible plugins of this repository (one clone, a Composer path repository)
  ansible    ansible-core 2.20 with the network collections (the ansible 13.5.0 package) in /opt/ansible
  semaphore  Semaphore UI 2.19.17, its project for LibreNMS, and the Ansible plugin's connection to it
  oxidized   Oxidized 0.37.0 with oxidized-web, fed by LibreNMS, and LibreNMS' Oxidized settings

Run
  --check                  say what would be done; change nothing
  --skip PART[,PART...]    leave these parts out                    (INSTALLER_SKIP)
  --only PART[,PART...]    set up only these parts                  (INSTALLER_ONLY)
  -y, --yes                do not ask before starting               (INSTALLER_YES=1)
  -v, --verbose            show every step (the log has them anyway)
  -h, --help               this text

The admin
  --admin-user NAME        the LibreNMS admin who gets the Ansible plugin's run and approve permissions, also the
                           Semaphore UI admin; created when missing (default: admin)       (INSTALLER_ADMIN_USER)
  --admin-password PW      their password, used when they are created (default: generated)
                                                                                       (INSTALLER_ADMIN_PASSWORD)
  --admin-email EMAIL      (default: root@<server name>)                              (INSTALLER_ADMIN_EMAIL)

LibreNMS
  --server-name NAME       the web server's name (default: hostname -f)              (INSTALLER_SERVER_NAME)
  --timezone ZONE          PHP's timezone, e.g. Europe/Berlin (default: the system's) (INSTALLER_TIMEZONE)
  --php-stream STREAM      PHP's module stream (default: remi-8.5, as LibreNMS documents) (INSTALLER_PHP_STREAM)
  --librenms-url URL       how Semaphore UI and Oxidized on this server reach LibreNMS (default: http://localhost,
                           or for a LibreNMS installed otherwise the first of its APP_URL and http://localhost that
                           answers its API)                                          (INSTALLER_LIBRENMS_URL)

The devices' login (Semaphore UI's key store and Oxidized; without it both are set up empty, to fill in later)
  --device-user USER                                                                 (INSTALLER_DEVICE_USER)
  --device-password PW                                                               (INSTALLER_DEVICE_PASSWORD)
  --device-enable-password PW   (default: the device password)              (INSTALLER_DEVICE_ENABLE_PASSWORD)

Sources (local copies of every download: offline mode)
  --plugin-source URL|DIR  this repository: a Git URL or a local directory (default: the run before's, else
                           https://github.com/agoodley/librenms_dashplugin; GITHUB_TOKEN for a private GitHub
                           repository)                                              (INSTALLER_PLUGIN_SOURCE)
  --plugin-branch BRANCH   (default: the run before's, else main; for a local Git directory, its checked-out
                           branch)                                                  (INSTALLER_PLUGIN_BRANCH)
  --librenms-source SRC    a Git URL (default: https://github.com/librenms/librenms.git), or a directory or
                           .tar.gz of a LibreNMS with its vendor/ installed          (INSTALLER_LIBRENMS_SOURCE)
  --semaphore-source FILE  Semaphore UI 2.19.17 as its .rpm, .tar.gz or binary (default: its GitHub release)
                                                                                    (INSTALLER_SEMAPHORE_SOURCE)
  --composer-source FILE   composer.phar (default: https://getcomposer.org)         (INSTALLER_COMPOSER_SOURCE)
  --pip-wheels DIR         Python wheels for Ansible and LibreNMS (default: PyPI)       (INSTALLER_PIP_WHEELS)
  --gems DIR               .gem files for Oxidized (default: RubyGems)                         (INSTALLER_GEMS)
  --distro-source FILE     librenms-agent's distro script (default: GitHub)          (INSTALLER_DISTRO_SOURCE)
  --offline DIR            take each of these from DIR (librenms.tar.gz or librenms/, librenms_dashplugin/,
                           semaphore_2.19.17_linux_<arch>.rpm|.tar.gz or semaphore, composer.phar, wheels/,
                           gems/, distro) and never contact GitHub                         (INSTALLER_OFFLINE)

Semaphore UI and Oxidized
  --semaphore-listen ADDR  (default: 127.0.0.1)                                   (INSTALLER_SEMAPHORE_LISTEN)
  --semaphore-port PORT    (default: 3000)                                          (INSTALLER_SEMAPHORE_PORT)
  --oxidized-listen ADDR   (default: 127.0.0.1)                                    (INSTALLER_OXIDIZED_LISTEN)
  --oxidized-port PORT     (default: 8888)                                           (INSTALLER_OXIDIZED_PORT)

Passwords are better given in the environment than as options (options show in ps and the shell's history).
Generated passwords and tokens go to /root/librenms-installer-credentials (root only); every command is logged
in /var/log/librenms-installer/. More in install/rocky/README.md.
EOF
}

# bad_option TEXT: stop on an option that cannot be used.
bad_option() {
    printf 'install.sh: %s\n' "$1" >&2
    printf 'See install.sh --help.\n' >&2
    exit 2
}

# ---------------------------------------------------------------------------------------------------- options

OPT_SKIP=${INSTALLER_SKIP:-}
OPT_ONLY=${INSTALLER_ONLY:-}
OPT_YES=${INSTALLER_YES:-0}
ADMIN_USER=${INSTALLER_ADMIN_USER:-admin}
ADMIN_PASSWORD=${INSTALLER_ADMIN_PASSWORD:-}
ADMIN_EMAIL=${INSTALLER_ADMIN_EMAIL:-}
SERVER_NAME=${INSTALLER_SERVER_NAME:-}
TIMEZONE=${INSTALLER_TIMEZONE:-}
PHP_STREAM=${INSTALLER_PHP_STREAM:-remi-8.5}
LIBRENMS_URL=${INSTALLER_LIBRENMS_URL:-}
DEVICE_USER=${INSTALLER_DEVICE_USER:-}
DEVICE_PASSWORD=${INSTALLER_DEVICE_PASSWORD:-}
DEVICE_ENABLE_PASSWORD=${INSTALLER_DEVICE_ENABLE_PASSWORD:-}
PLUGIN_SOURCE=${INSTALLER_PLUGIN_SOURCE:-}
PLUGIN_BRANCH=${INSTALLER_PLUGIN_BRANCH:-}
LIBRENMS_SOURCE=${INSTALLER_LIBRENMS_SOURCE:-}
SEMAPHORE_SOURCE=${INSTALLER_SEMAPHORE_SOURCE:-}
COMPOSER_SOURCE=${INSTALLER_COMPOSER_SOURCE:-}
PIP_WHEELS=${INSTALLER_PIP_WHEELS:-}
GEMS_DIR=${INSTALLER_GEMS:-}
DISTRO_SOURCE=${INSTALLER_DISTRO_SOURCE:-}
OFFLINE_DIR=${INSTALLER_OFFLINE:-}
SEMAPHORE_LISTEN=${INSTALLER_SEMAPHORE_LISTEN:-127.0.0.1}
SEMAPHORE_PORT=${INSTALLER_SEMAPHORE_PORT:-3000}
OXIDIZED_LISTEN=${INSTALLER_OXIDIZED_LISTEN:-127.0.0.1}
OXIDIZED_PORT=${INSTALLER_OXIDIZED_PORT:-8888}
GITHUB_TOKEN_VALUE=${GITHUB_TOKEN:-}
[[ ${INSTALLER_CHECK:-0} == 1 ]] && CHECK=1
# secrets stay in this shell, not in the environment of every command the installer runs
unset INSTALLER_ADMIN_PASSWORD INSTALLER_DEVICE_PASSWORD INSTALLER_DEVICE_ENABLE_PASSWORD GITHUB_TOKEN

VALUE_OPTIONS=(--skip --only --admin-user --admin-password --admin-email --server-name --timezone --php-stream --librenms-url
    --device-user --device-password --device-enable-password --plugin-source --plugin-branch --librenms-source --semaphore-source
    --composer-source --pip-wheels --gems --distro-source --offline --semaphore-listen --semaphore-port --oxidized-listen --oxidized-port)

parse_options() {
    local opt value
    while [[ $# -gt 0 ]]; do
        opt=$1; value=''
        if [[ $opt == --*=* ]]; then value=${opt#*=}; opt=${opt%%=*}; set -- "$opt" "$value" "${@:2}"; fi
        case $opt in
            -h|--help) usage; exit 0 ;;
            --check) CHECK=1; shift; continue ;;
            -y|--yes) OPT_YES=1; shift; continue ;;
            -v|--verbose) VERBOSE=1; shift; continue ;;
        esac
        [[ $opt == -* ]] || bad_option "unexpected argument: $opt"
        [[ " ${VALUE_OPTIONS[*]} " == *" $opt "* ]] || bad_option "unknown option: $opt"
        [[ $# -ge 2 && -n $2 ]] || bad_option "$opt needs a value"
        value=$2
        case $opt in
            --skip) OPT_SKIP=$value ;;
            --only) OPT_ONLY=$value ;;
            --admin-user) ADMIN_USER=$value ;;
            --admin-password) ADMIN_PASSWORD=$value ;;
            --admin-email) ADMIN_EMAIL=$value ;;
            --server-name) SERVER_NAME=$value ;;
            --timezone) TIMEZONE=$value ;;
            --php-stream) PHP_STREAM=$value ;;
            --librenms-url) LIBRENMS_URL=$value ;;
            --device-user) DEVICE_USER=$value ;;
            --device-password) DEVICE_PASSWORD=$value ;;
            --device-enable-password) DEVICE_ENABLE_PASSWORD=$value ;;
            --plugin-source) PLUGIN_SOURCE=$value ;;
            --plugin-branch) PLUGIN_BRANCH=$value ;;
            --librenms-source) LIBRENMS_SOURCE=$value ;;
            --semaphore-source) SEMAPHORE_SOURCE=$value ;;
            --composer-source) COMPOSER_SOURCE=$value ;;
            --pip-wheels) PIP_WHEELS=$value ;;
            --gems) GEMS_DIR=$value ;;
            --distro-source) DISTRO_SOURCE=$value ;;
            --offline) OFFLINE_DIR=$value ;;
            --semaphore-listen) SEMAPHORE_LISTEN=$value ;;
            --semaphore-port) SEMAPHORE_PORT=$value ;;
            --oxidized-listen) OXIDIZED_LISTEN=$value ;;
            --oxidized-port) OXIDIZED_PORT=$value ;;
            *) bad_option "unknown option: $opt" ;;
        esac
        shift 2
    done
}

# parts_list TEXT OPTION: the parts named in a --skip/--only value, checked, in PARTS_LISTED (no subshell: a bad
# part stops the run here).
PARTS_LISTED=()
parts_list() {
    local text=$1 opt=$2 p items=()
    PARTS_LISTED=()
    IFS=', ' read -r -a items <<<"$text"
    for p in "${items[@]}"; do
        [[ -z $p ]] && continue
        [[ " ${PARTS[*]} " == *" $p "* ]] || bad_option "$opt: unknown part '$p' (the parts: ${PARTS[*]})"
        PARTS_LISTED+=("$p")
    done
}

check_options() {
    local p skip=() only=()
    if [[ -n $OPT_SKIP && -n $OPT_ONLY ]]; then bad_option "--skip and --only cannot be used together"; fi
    parts_list "$OPT_SKIP" --skip; skip=("${PARTS_LISTED[@]}")
    parts_list "$OPT_ONLY" --only; only=("${PARTS_LISTED[@]}")
    for p in "${PARTS[@]}"; do
        if [[ ${#only[@]} -gt 0 ]]; then
            [[ " ${only[*]} " == *" $p "* ]] && WANT[$p]=1 || WANT[$p]=0
        else
            [[ " ${skip[*]} " == *" $p "* ]] && WANT[$p]=0 || WANT[$p]=1
        fi
    done

    [[ $ADMIN_USER =~ ^[A-Za-z0-9][A-Za-z0-9._@-]{0,63}$ ]] \
        || bad_option "--admin-user: '$ADMIN_USER' is not a user name (letters, digits and . _ @ -, at most 64)"
    if [[ -n $ADMIN_PASSWORD && ${#ADMIN_PASSWORD} -lt 8 ]]; then bad_option "--admin-password: use at least 8 characters"; fi
    if [[ -n $ADMIN_EMAIL && ! $ADMIN_EMAIL =~ ^[^@[:space:]]+@[^@[:space:]]+$ ]]; then bad_option "--admin-email: '$ADMIN_EMAIL' is not an e-mail address"; fi
    if [[ -n $SERVER_NAME && ! $SERVER_NAME =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,252})$ ]]; then
        bad_option "--server-name: '$SERVER_NAME' is not a host name"
    fi
    if [[ -n $TIMEZONE && ! -f /usr/share/zoneinfo/$TIMEZONE ]]; then bad_option "--timezone: '$TIMEZONE' is not a time zone (see timedatectl list-timezones)"; fi
    [[ $PHP_STREAM =~ ^(remi-)?8\.[2-9]$ ]] || bad_option "--php-stream: '$PHP_STREAM' is not a PHP 8.2+ stream (e.g. remi-8.5, remi-8.4, or 8.3 for Rocky's own)"
    if [[ -n $LIBRENMS_URL && ! $LIBRENMS_URL =~ ^https?://[^/?#[:space:]]+(/[^?#[:space:]]*)?$ ]]; then
        bad_option "--librenms-url: '$LIBRENMS_URL' is not an http(s) URL"
    fi
    LIBRENMS_URL=${LIBRENMS_URL%/}
    if [[ -n $DEVICE_USER$DEVICE_PASSWORD$DEVICE_ENABLE_PASSWORD && ( -z $DEVICE_USER || -z $DEVICE_PASSWORD ) ]]; then
        bad_option "the devices' login needs both --device-user and --device-password"
    fi
    if [[ -n $DEVICE_USER && ! $DEVICE_USER =~ ^[A-Za-z0-9._@-]{1,64}$ ]]; then bad_option "--device-user: '$DEVICE_USER' is not a login name"; fi
    local pw
    for pw in "$DEVICE_PASSWORD" "$DEVICE_ENABLE_PASSWORD" "$ADMIN_PASSWORD"; do
        if [[ $pw == *[$'\n\r\t']* ]]; then bad_option "a password may not hold line breaks or tabs"; fi
    done
    [[ -z $DEVICE_ENABLE_PASSWORD ]] && DEVICE_ENABLE_PASSWORD=$DEVICE_PASSWORD
    local port
    for port in "$SEMAPHORE_PORT" "$OXIDIZED_PORT"; do
        [[ $port =~ ^[0-9]{1,5}$ && $port -ge 1 && $port -le 65535 ]] || bad_option "'$port' is not a TCP port"
    done
    [[ $SEMAPHORE_PORT != "$OXIDIZED_PORT" ]] || bad_option "Semaphore UI and Oxidized need two ports"
    local addr
    for addr in "$SEMAPHORE_LISTEN" "$OXIDIZED_LISTEN"; do
        [[ $addr =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ || $addr == '::' || $addr == '::1' ]] \
            || bad_option "'$addr' is not an address to listen on (an IPv4 address such as 127.0.0.1 or 0.0.0.0)"
    done
    if [[ -n $PLUGIN_BRANCH && ! $PLUGIN_BRANCH =~ ^[A-Za-z0-9._/-]{1,200}$ ]]; then bad_option "--plugin-branch: '$PLUGIN_BRANCH' is not a branch name"; fi

    # local copies: they must exist
    if [[ -n $OFFLINE_DIR ]]; then
        [[ -d $OFFLINE_DIR ]] || bad_option "--offline: $OFFLINE_DIR is not a directory"
        OFFLINE_DIR=$(cd "$OFFLINE_DIR" && pwd)
        offline_defaults
    fi
    local f
    for f in "$SEMAPHORE_SOURCE" "$COMPOSER_SOURCE" "$DISTRO_SOURCE"; do
        if [[ -n $f && ! -f $f ]]; then bad_option "$f: no such file"; fi
    done
    for f in "$PIP_WHEELS" "$GEMS_DIR"; do
        if [[ -n $f && ! -d $f ]]; then bad_option "$f: no such directory"; fi
    done
    if [[ -n $LIBRENMS_SOURCE && $LIBRENMS_SOURCE != *://* && ! -e $LIBRENMS_SOURCE ]]; then bad_option "--librenms-source: $LIBRENMS_SOURCE does not exist"; fi
    if [[ -n $PLUGIN_SOURCE && $PLUGIN_SOURCE != *://* && $PLUGIN_SOURCE != *@*:* && ! -d $PLUGIN_SOURCE ]]; then
        bad_option "--plugin-source: $PLUGIN_SOURCE is neither a Git URL nor a directory"
    fi
    # a later run takes the plugins from where (and the branch) the installer took them before, unless told otherwise
    if [[ -z $PLUGIN_SOURCE ]] && PLUGIN_SOURCE=$(state_get plugin_source 2>/dev/null); then
        if [[ -z $PLUGIN_BRANCH ]]; then PLUGIN_BRANCH=$(state_get plugin_branch 2>/dev/null || true); fi
        if [[ ${WANT[plugins]} == 1 && $PLUGIN_SOURCE != *://* && $PLUGIN_SOURCE != *@*:* && ! -d $PLUGIN_SOURCE ]]; then
            bad_option "the plugins came from $PLUGIN_SOURCE, which is gone: give their source with --plugin-source (or --skip plugins)"
        fi
    fi
    [[ -z $PLUGIN_SOURCE ]] && PLUGIN_SOURCE=https://github.com/agoodley/librenms_dashplugin
    if [[ -n $OFFLINE_DIR && ${WANT[plugins]} == 1 && $PLUGIN_SOURCE == *github.com* ]]; then
        bad_option "--offline: give the plugins as a local directory (--plugin-source DIR, or librenms_dashplugin/ in $OFFLINE_DIR)"
    fi
    [[ -z $LIBRENMS_SOURCE ]] && LIBRENMS_SOURCE=https://github.com/librenms/librenms.git
    return 0
}

# offline_defaults: the local copies of an --offline directory, for the sources not given otherwise.
offline_defaults() {
    local d=$OFFLINE_DIR f
    if [[ -z $LIBRENMS_SOURCE ]]; then
        for f in "$d/librenms.tar.gz" "$d/librenms.tgz" "$d/librenms"; do [[ -e $f ]] && { LIBRENMS_SOURCE=$f; break; }; done
    fi
    if [[ -z $PLUGIN_SOURCE && -d $d/librenms_dashplugin ]]; then PLUGIN_SOURCE=$d/librenms_dashplugin; fi
    if [[ -z $SEMAPHORE_SOURCE ]]; then
        for f in "$d/semaphore_${SEMAPHORE_VERSION}_linux_$(semaphore_arch).rpm" "$d/semaphore_${SEMAPHORE_VERSION}_linux_$(semaphore_arch).tar.gz" \
            "$d/semaphore-$SEMAPHORE_VERSION" "$d/semaphore"; do
            [[ -f $f ]] && { SEMAPHORE_SOURCE=$f; break; }
        done
    fi
    if [[ -z $COMPOSER_SOURCE && -f $d/composer.phar ]]; then COMPOSER_SOURCE=$d/composer.phar; fi
    if [[ -z $PIP_WHEELS && -d $d/wheels ]]; then PIP_WHEELS=$d/wheels; fi
    if [[ -z $GEMS_DIR && -d $d/gems ]]; then GEMS_DIR=$d/gems; fi
    if [[ -z $DISTRO_SOURCE && -f $d/distro ]]; then DISTRO_SOURCE=$d/distro; fi
    return 0
}

# ---------------------------------------------------------------------------------------------------- preflight

OS_MAJOR=''
ARCH=''
preflight() {
    [[ $EUID == 0 ]] || { printf 'install.sh: run it as root (sudo -i first).\n' >&2; exit 2; }
    [[ -r /etc/os-release ]] || die "no /etc/os-release: this is not Rocky Linux"
    local id version
    id=$(. /etc/os-release && printf '%s' "$ID")
    version=$(. /etc/os-release && printf '%s' "$VERSION_ID")
    OS_MAJOR=${version%%.*}
    [[ $id == rocky && ( $OS_MAJOR == 9 || $OS_MAJOR == 10 ) ]] \
        || die "this installer is for Rocky Linux 9 and 10, and this is $id $version" "use a Rocky Linux 9 or 10 server"
    if [[ $OS_MAJOR == 10 && $PHP_STREAM != remi-* && $PHP_STREAM != 8.3 ]]; then
        bad_option "--php-stream: Rocky Linux 10's own PHP is 8.3, not $PHP_STREAM (give 8.3, or one of Remi's such as remi-8.5)"
    fi
    ARCH=$(uname -m)
    case $ARCH in
        x86_64) ;;
        aarch64) WARN_ARCH=1 ;;
        *) die "the processor architecture $ARCH is not supported" "use an x86_64 (or aarch64) server" ;;
    esac
    [[ -d /run/systemd/system ]] || die "systemd does not run: the services need it" "run the installer on a server booted with systemd"
    command -v python3 >/dev/null || die "python3 is missing (dnf needs it on Rocky Linux)" "dnf install python3"
    [[ -n $SERVER_NAME ]] || SERVER_NAME=$(hostname -f 2>/dev/null || hostname)
    [[ -n $ADMIN_EMAIL ]] || ADMIN_EMAIL="root@$SERVER_NAME"
    [[ -n $TIMEZONE ]] || TIMEZONE=$(timedatectl show -p Timezone --value 2>/dev/null || true)
    if [[ -z $TIMEZONE || ! -f /usr/share/zoneinfo/$TIMEZONE ]]; then
        TIMEZONE=$(readlink /etc/localtime 2>/dev/null | sed -n 's|.*/zoneinfo/||p' || true)
    fi
    [[ -n $TIMEZONE && -f /usr/share/zoneinfo/$TIMEZONE ]] || TIMEZONE=UTC
}
WARN_ARCH=0

# semaphore_arch: the architecture in Semaphore UI's release names.
semaphore_arch() { case $(uname -m) in aarch64) echo arm64 ;; *) echo amd64 ;; esac; }

start_run() {
    mkdir -p "$STATE_DIR" "$LOG_DIR"
    chmod 700 "$STATE_DIR" "$LOG_DIR"
    exec 9>"$STATE_DIR/lock"
    flock -n 9 || { printf 'install.sh: another run of the installer is in progress (%s/lock).\n' "$STATE_DIR" >&2; exit 2; }
    LOG_FILE=$LOG_DIR/$( [[ $CHECK == 1 ]] && echo check || echo install )-$(date +%Y%m%d-%H%M%S).log
    (umask 077 && : >"$LOG_FILE")
    ln -sfn "$(basename "$LOG_FILE")" "$LOG_DIR/latest.log"
    WORK=$(mktemp -d /tmp/librenms-installer.XXXXXX)
    chmod 755 "$WORK"
    cp -r "$INSTALLER_DIR/lib" "$INSTALLER_DIR/files" "$WORK/"
    chmod -R a+rX "$WORK"
    trap on_exit EXIT
    trap 'on_signal INT' INT
    trap 'on_signal TERM' TERM
    trap 'on_error $LINENO "$BASH_COMMAND"' ERR
    log "install/rocky/install.sh started$( [[ $CHECK == 1 ]] && echo ' (--check)' ): $(quote_cmd "${ORIG_ARGS[@]}" | sed -E 's/(--(admin|device|device-enable)-password[ =])[^ ]*/\1<hidden>/g')"
    log "Rocky Linux $(. /etc/os-release && echo "$VERSION_ID") $ARCH; server name $SERVER_NAME; parts: $(for p in "${PARTS[@]}"; do printf '%s=%s ' "$p" "${WANT[$p]}"; done)"
}

on_error() {
    local line=$1 cmd=$2
    trap - ERR
    # an error a subshell reported already (die in a command substitution): nothing more to say
    if [[ -n $WORK && -f $WORK/.died ]]; then exit 1; fi
    die "unexpected failure at install.sh/lib line $line: $cmd" "read the log, fix the cause, then run the installer again"
}

on_signal() {
    trap - ERR
    printf '\nStopped (%s). Run the installer again: it picks up where it stopped.\n' "$1" >&2
    log "stopped by SIG$1"
    exit 130
}

on_exit() {
    local rc=$?
    semaphore_cleanup || true
    if [[ -n $WORK && -d $WORK ]]; then rm -rf "$WORK"; fi
    if [[ $rc == 0 ]]; then log "finished"; else log "ended with exit code $rc"; fi
}

# ---------------------------------------------------------------------------------------------------- run

confirm() {
    [[ $CHECK == 1 || $OPT_YES == 1 ]] && return 0
    if [[ ! -t 0 ]]; then
        die "the installer asks before it starts, and stdin is not a terminal" "give --yes (or INSTALLER_YES=1) for unattended runs, or --check to see what it would do"
    fi
    local p list=()
    for p in "${PARTS[@]}"; do [[ ${WANT[$p]} == 1 ]] && list+=("$p"); done
    printf 'This sets up or checks on %s: %s.\n' "$(hostname)" "${list[*]}"
    printf 'install.sh --check shows what it would change. Continue? [y/N] '
    local answer; read -r answer
    [[ $answer == [yY]* ]] || { printf 'Nothing was changed.\n'; exit 0; }
}

print_summary() {
    printf '\n==> Summary%s\n' "$( [[ $CHECK == 1 ]] && echo ' (--check: nothing was changed)' )"
    local line part
    for line in "${SUMMARY[@]}"; do
        part=${line%%|*}
        printf '  %-10s %s\n' "$part" "${line#*|}"
    done
    if [[ ${#WARNINGS[@]} -gt 0 ]]; then
        printf '\nWarnings:\n'
        for line in "${WARNINGS[@]}"; do printf '  - %s\n' "$line"; done
    fi
    if [[ $CHECK == 1 ]]; then
        case ${#CHANGES[@]} in
            0) printf '\nIt would change nothing.\n' ;;
            1) printf '\nIt would make 1 change (listed above).\n' ;;
            *) printf '\nIt would make %d changes (listed above).\n' "${#CHANGES[@]}" ;;
        esac
    else
        if [[ ${#CHANGES[@]} == 0 ]]; then printf '\nNothing needed a change.\n'; fi
        if [[ -f $CRED_FILE ]]; then printf '\nPasswords and tokens: %s (root only)\n' "$CRED_FILE"; fi
    fi
    printf 'Log: %s\n' "$LOG_FILE"
    {
        printf 'summary:\n'
        for line in "${SUMMARY[@]}"; do printf '  %s\n' "${line/|/: }"; done
        for line in "${WARNINGS[@]}"; do printf '  warning: %s\n' "$line"; done
        printf '  changes: %d\n' "${#CHANGES[@]}"
    } >>"$LOG_FILE"
}

next_steps() {
    [[ $CHECK == 1 ]] && return 0
    local pw="the password you gave"
    if [[ -z $ADMIN_PASSWORD ]]; then pw="ADMIN_PASSWORD in $CRED_FILE"; fi
    printf '\nNext\n'
    if [[ ${WANT[librenms]} == 1 && $LNMS_MODE != existing ]]; then
        printf '  - Log in to LibreNMS at http://%s/ as %s (%s).\n' "$SERVER_NAME" "$ADMIN_USER" "$pw"
    fi
    printf '  - Add your devices to LibreNMS (Devices > Add Device, or lnms device:add), with their LLDP/CDP neighbors discovered\n'
    printf '    for the topology plugin; set its Core devices (Plugin Admin > topology > Settings).\n'
    if [[ -z $DEVICE_USER && ( ${WANT[semaphore]} == 1 || ${WANT[oxidized]} == 1 ) ]]; then
        printf '  - The devices'"'"' login was not given: set it in Semaphore UI (project "%s", Key Store: "Devices: SSH login",\n' "$SEM_PROJECT"
        printf '    "Devices: enable password") and in %s (username, password, vars: enable), then\n' "$OX_CONF"
        printf '    systemctl restart oxidized; or run the installer again with --device-user and INSTALLER_DEVICE_PASSWORD.\n'
    fi
    if [[ ${WANT[semaphore]} == 1 ]]; then
        if [[ $SEMAPHORE_LISTEN == 127.0.0.1 ]]; then
            printf '  - Semaphore UI listens on this server only: ssh -L %s:127.0.0.1:%s root@%s, then http://localhost:%s (%s).\n' \
                "$SEMAPHORE_PORT" "$SEMAPHORE_PORT" "$SERVER_NAME" "$SEMAPHORE_PORT" "$ADMIN_USER"
        fi
        printf '  - The first change: LibreNMS > Overview > Plugins > Ansible > Changes > New change (or Fixes); the dry run\n'
        printf '    shows what would change on each device before anything is applied (ansible/README.md, Using it).\n'
    fi
    printf '  - What the installer changed on this server, and how to undo it: install/rocky/README.md.\n'
}

main() {
    ORIG_ARGS=("$@")
    parse_options "$@"
    check_options
    preflight
    start_run
    printf 'LibreNMS installer for Rocky Linux %s%s (log: %s)\n' "$OS_MAJOR" "$( [[ $CHECK == 1 ]] && echo ', --check: nothing is changed' )" "$LOG_FILE"
    if [[ $WARN_ARCH == 1 ]]; then warn "aarch64 is not tested: every part has aarch64 packages, but expect surprises"; fi
    librenms_detect
    confirm
    local p
    for p in "${PARTS[@]}"; do
        if [[ ${WANT[$p]} == 1 ]]; then
            "part_$p"
        else
            CURRENT_PART=$p
            summary_add "skipped (--skip/--only)"
            log "part $p skipped"
        fi
    done
    CURRENT_PART=''
    print_summary
    next_steps
}

main "$@"
