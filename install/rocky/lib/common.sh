# shellcheck shell=bash
# install/rocky/lib/common.sh: output, the log, running commands, files, the installer's state and the credentials
# file. Sourced by install.sh.
#
# Rules every part follows:
#  * Everything that runs is logged (LOG_FILE): the command line, then its output. Secrets never reach the screen,
#    the log or a command line (argv is visible to every user in ps): they go to commands on stdin or in files of
#    mode 600, and commands that print a secret have their output captured, not logged.
#  * A change goes through act(): with --check it only says what it would do.
#  * Each step finds out whether its work is done already, so a second run changes nothing that is right and a run
#    that was stopped picks up where it stopped. Files are written atomically (a temporary file, then mv).

STATE_DIR=/var/lib/librenms-installer
LOG_DIR=/var/log/librenms-installer
CRED_FILE=/root/librenms-installer-credentials
STATE_FILE=$STATE_DIR/state

CHECK=0          # --check: say what would be done, change nothing
VERBOSE=0
LOG_FILE=/dev/null
WORK=''          # a temporary directory for this run (helpers, downloads), removed at exit
CURRENT_PART=''
declare -a SUMMARY=()   # "part|what happened" lines for the summary
declare -a WARNINGS=()
declare -a CHANGES=()   # what this run changed (or would change, with --check)
declare -A WANT=()      # part => 1 when this run sets it up (install.sh's --skip/--only)

# ---------------------------------------------------------------------------------------------------- output

_ts() { date '+%F %T'; }

# log TEXT...: a line in the log only, without the values of LOG_REDACT (secrets a command line has to carry).
declare -a LOG_REDACT=()
log() {
    local text="$*" secret
    for secret in "${LOG_REDACT[@]}"; do text=${text//"$secret"/<hidden>}; done
    printf '%s %s\n' "$(_ts)" "$text" >>"$LOG_FILE"
}

# say TEXT...: a line on the screen and in the log.
say() { printf '%s\n' "$*"; log "$*"; }

# part_header NAME TEXT: the heading of a part.
part_header() {
    CURRENT_PART=$1
    printf '\n==> %s\n' "$2"
    log "==> $2"
}

# info TEXT: a detail of the current part.
info() { printf '    %s\n' "$*"; log "    $*"; }

# detail TEXT: a detail that is shown with --verbose only (always logged).
detail() {
    if [[ $VERBOSE == 1 ]]; then printf '    %s\n' "$*"; fi
    log "    $*"
}

# warn TEXT: a warning, repeated in the summary.
warn() {
    printf '    WARNING: %s\n' "$*" >&2
    log "WARNING: $*"
    WARNINGS+=("${CURRENT_PART:+$CURRENT_PART: }$*")
}

# summary_add TEXT: what the current part did, for the summary at the end.
summary_add() { SUMMARY+=("${CURRENT_PART}|$*"); }

# die WHAT [WHAT TO DO]: stop the run with what failed and what to do.
die() {
    local what=$1 todo=${2:-}
    {
        printf '\nERROR%s: %s\n' "${CURRENT_PART:+ ($CURRENT_PART)}" "$what"
        if [[ -n $todo ]]; then printf 'What to do: %s\n' "$todo"; fi
        if [[ $LOG_FILE != /dev/null ]]; then printf 'The log of this run: %s\n' "$LOG_FILE"; fi
        if [[ $CHECK == 0 ]]; then printf 'Run the installer again once it is fixed: it picks up where it stopped.\n'; fi
    } >&2
    log "ERROR: $what${todo:+ | what to do: $todo}"
    # in a command substitution this ends only the subshell: the marker tells the ERR trap the error was reported
    if [[ -n $WORK && -d $WORK ]]; then : >"$WORK/.died"; fi
    exit 1
}

# quote_cmd ARGS...: a command line as the log shows it.
quote_cmd() {
    local out='' a
    for a in "$@"; do
        if [[ $a =~ ^[A-Za-z0-9_./:=@%+,-]+$ ]]; then out+=" $a"; else out+=" $(printf '%q' "$a")"; fi
    done
    printf '%s' "${out# }"
}

# ---------------------------------------------------------------------------------------------------- commands

# _log_size: the current size of the log, to show what a failed command printed.
_log_size() { if [[ -f $LOG_FILE ]]; then stat -c %s "$LOG_FILE"; else echo 0; fi; }

# _fail_cmd START CMD...: stop the run after a command failed, with the last lines it wrote to the log.
_fail_cmd() {
    local start=$1; shift
    local tail=''
    if [[ -f $LOG_FILE ]]; then tail=$(tail -c +"$((start + 1))" "$LOG_FILE" | tail -n 15 | sed 's/^/    | /'); fi
    die "this command failed: $(quote_cmd "$@")${tail:+
$tail}" "${FAIL_HINT:-read the log for the whole output, fix the cause, then run the installer again}"
}

# run CMD...: run a command (also with --check: only for commands that change nothing), its output to the log;
# stop the run when it fails. FAIL_HINT=... before it says what to do then.
run() {
    log "+ $(quote_cmd "$@")"
    local start; start=$(_log_size)
    if ! "$@" >>"$LOG_FILE" 2>&1 </dev/null; then _fail_cmd "$start" "$@"; fi
}

# probe CMD...: run a command that only looks, output to the log; its exit code is the result.
probe() {
    log "? $(quote_cmd "$@")"
    "$@" >>"$LOG_FILE" 2>&1 </dev/null
}

# capture VAR CMD...: run a command that only looks and keep what it prints (stdout) in VAR (stderr to the log).
capture() {
    local __var=$1; shift
    log "? $(quote_cmd "$@")"
    local __out __rc=0
    __out=$("$@" 2>>"$LOG_FILE" </dev/null) || __rc=$?
    printf -v "$__var" '%s' "$__out"
    return "$__rc"
}

# run_input INPUT CMD...: run a command with INPUT on its stdin; the input is not logged (it may hold a secret),
# the output is. Stops the run when it fails.
run_input() {
    local input=$1; shift
    log "+ $(quote_cmd "$@") (stdin not logged)"
    local start; start=$(_log_size)
    if ! "$@" >>"$LOG_FILE" 2>&1 <<<"$input"; then _fail_cmd "$start" "$@"; fi
}

# run_secret VAR INPUT CMD...: run a command whose output holds a secret: stdin INPUT (not logged), stdout kept in
# VAR (not logged), stderr to the log. Stops the run when it fails.
run_secret() {
    local __var=$1 __input=$2; shift 2
    log "+ $(quote_cmd "$@") (stdin and output not logged: they hold secrets)"
    local __out __rc=0 start; start=$(_log_size)
    __out=$("$@" 2>>"$LOG_FILE" <<<"$__input") || __rc=$?
    if [[ $__rc != 0 ]]; then _fail_cmd "$start" "$@"; fi
    printf -v "$__var" '%s' "$__out"
}

# act DESCRIPTION CMD...: a change. With --check it is only said; otherwise said, logged and run.
act() {
    local desc=$1; shift
    CHANGES+=("$CURRENT_PART: $desc")
    if [[ $CHECK == 1 ]]; then
        info "would $desc"
        return 0
    fi
    info "$desc"
    run "$@"
}

# would DESCRIPTION: with --check, say a change it would make (for changes made by several commands below it);
# returns 0 with --check (so the caller skips the change), 1 otherwise. Without --check it says what it does.
would() {
    CHANGES+=("$CURRENT_PART: $1")
    if [[ $CHECK == 1 ]]; then info "would $1"; return 0; fi
    info "$1"
    return 1
}

# as USER CMD...: run CMD as USER, with USER's home; the rest of the environment stays (proxies). For run, probe and
# capture: run as librenms /opt/librenms/lnms migrate.
as() {
    local user=$1; shift
    local home; home=$(getent passwd "$user" | cut -d: -f6)
    runuser -u "$user" -- env "HOME=$home" "USER=$user" "LOGNAME=$user" "$@"
}

# in_dir DIR CMD...: run CMD in DIR, in a subshell (the installer's own directory stays).
in_dir() { (cd "$1" && shift && "$@"); }

# ---------------------------------------------------------------------------------------------------- files

# file_differs SRC DST: whether DST is missing or has another content than SRC.
file_differs() { [[ ! -f $2 ]] || ! cmp -s "$1" "$2"; }

# put_file SRC DST OWNER:GROUP MODE [DESCRIPTION]: install SRC as DST when its content, owner or mode differ;
# atomically. Returns 0 when it changed (or would change) DST, 1 when DST was right already.
put_file() {
    local src=$1 dst=$2 owner=$3 mode=$4 desc=${5:-write $2}
    if ! file_differs "$src" "$dst" && [[ $(stat -c '%U:%G %a' "$dst") == "$owner ${mode#0}" ]]; then
        detail "$dst is up to date"
        return 1
    fi
    would "$desc" && return 0
    local tmp; tmp=$(mktemp "$(dirname "$dst")/.$(basename "$dst").XXXXXX")
    cat "$src" >"$tmp"
    chown "$owner" "$tmp"
    chmod "$mode" "$tmp"
    mv -f "$tmp" "$dst"
    log "wrote $dst ($owner $mode)"
    return 0
}

# put_text TEXT DST OWNER:GROUP MODE [DESCRIPTION]: put_file with the content given as text.
put_text() {
    local text=$1; shift
    local src; src=$(mktemp "$WORK/text.XXXXXX")
    printf '%s' "$text" >"$src"
    local rc=0
    put_file "$src" "$@" || rc=$?
    rm -f "$src"
    return "$rc"
}

# render TEMPLATE KEY=VALUE...: a template of files/ with @KEY@ replaced by VALUE (values of one line).
render() {
    local text; text=$(<"$FILES_DIR/$1"); shift
    local kv key value
    for kv in "$@"; do
        key=${kv%%=*}; value=${kv#*=}
        text=${text//@$key@/$value}
    done
    printf '%s\n' "$text"
}

# ---------------------------------------------------------------------------------------------------- state

# The state file (root only) remembers what earlier runs did; the credentials file the generated secrets. Both are
# KEY=VALUE lines, read by a parser (never sourced) and rewritten atomically.

_kv_get() {  # FILE KEY
    [[ -f $1 ]] || return 1
    local line
    while IFS= read -r line; do
        if [[ $line == "$2="* ]]; then printf '%s' "${line#*=}"; return 0; fi
    done <"$1"
    return 1
}

_kv_set() {  # FILE KEY VALUE HEADER
    local file=$1 key=$2 value=$3 header=$4 tmp found=0 line
    [[ $value != *$'\n'* ]] || die "internal error: a value for $key spans lines"
    tmp=$(umask 077 && mktemp "$(dirname "$file")/.$(basename "$file").XXXXXX")
    {
        if [[ -f $file ]]; then
            while IFS= read -r line; do
                if [[ $line == "$key="* ]]; then printf '%s=%s\n' "$key" "$value"; found=1; else printf '%s\n' "$line"; fi
            done <"$file"
        else
            printf '%s\n' "$header"
        fi
        if [[ $found == 0 ]]; then printf '%s=%s\n' "$key" "$value"; fi
    } >"$tmp"
    chmod 600 "$tmp"
    mv -f "$tmp" "$file"
}

state_get() { _kv_get "$STATE_FILE" "$1"; }
state_set() {
    [[ $CHECK == 1 ]] && return 0
    _kv_set "$STATE_FILE" "$1" "$2" "# install/rocky/install.sh: what earlier runs did (do not edit)"
    log "state: $1=$2"
}

cred_get() { _kv_get "$CRED_FILE" "$1"; }
cred_unset() {
    [[ $CHECK == 1 || ! -f $CRED_FILE ]] && return 0
    local tmp; tmp=$(umask 077 && mktemp "$(dirname "$CRED_FILE")/.$(basename "$CRED_FILE").XXXXXX")
    grep -v "^$1=" "$CRED_FILE" >"$tmp" || true
    chmod 600 "$tmp"
    mv -f "$tmp" "$CRED_FILE"
    log "credentials file: $1 removed"
}
cred_set() {
    [[ $CHECK == 1 ]] && return 0
    _kv_set "$CRED_FILE" "$1" "$2" "# Passwords and tokens the LibreNMS installer (install/rocky/install.sh) generated. Root only (mode 600).
# The services keep their own copies (named next to each): this file is for you, and lets a later run of the
# installer check what it set up. Keep it safe; delete it when you no longer need it (a later run then makes new
# tokens where it needs them)."
    log "credentials file: $1 set (value not logged)"
}

# gen_secret [LENGTH]: a random password of letters and digits.
gen_secret() {
    local len=${1:-24} out=''
    while [[ ${#out} -lt $len ]]; do
        out+=$(head -c 256 /dev/urandom | tr -dc 'A-Za-z0-9')
    done
    printf '%s' "${out:0:$len}"
}

# secret_for KEY [LENGTH]: the secret stored under KEY in the credentials file, generated (and stored) when there is none.
secret_for() {
    local key=$1 len=${2:-24} value
    if value=$(cred_get "$key") && [[ -n $value ]]; then printf '%s' "$value"; return 0; fi
    value=$(gen_secret "$len")
    cred_set "$key" "$value"
    printf '%s' "$value"
}

# ---------------------------------------------------------------------------------------------------- system

# pkgs_missing PKG...: the packages (or provides) of the list that are not installed, one per line.
pkgs_missing() {
    local p
    for p in "$@"; do
        rpm -q --whatprovides "$p" >/dev/null 2>&1 || printf '%s\n' "$p"
    done
}

# dnf_install DESCRIPTION PKG...: install the packages that are missing.
dnf_install() {
    local desc=$1; shift
    local missing
    mapfile -t missing < <(pkgs_missing "$@")
    if [[ ${#missing[@]} == 0 ]]; then
        detail "$desc: installed already"
        return 0
    fi
    FAIL_HINT="check that this server reaches its package repositories (dnf repolist), then run the installer again" \
        act "install $desc: ${missing[*]}" dnf -y install "${missing[@]}"
}

# module_stream NAME: the enabled stream of a dnf module, empty when none.
module_stream() {
    local f=/etc/dnf/modules.d/$1.module
    [[ -f $f ]] || return 0
    if grep -q '^state=enabled' "$f"; then sed -n 's/^stream=//p' "$f"; fi
}

# service_enable_start UNIT: enable a service and start it, when it is not.
service_enable_start() {
    local unit=$1
    if ! systemctl is-enabled --quiet "$unit" 2>/dev/null; then
        act "enable $unit" systemctl enable "$unit"
    fi
    # activating: starting, or waiting to be restarted (Restart=): systemd is at it already
    local state; state=$(systemctl show -p ActiveState --value "$unit" 2>/dev/null || true)
    case $state in
        active|reloading|activating) detail "$unit is $state" ;;
        *) act "start $unit" systemctl start "$unit" ;;
    esac
}

# restart_if_pending FLAG UNIT [reload]: restart (or reload) a service when FLAG is set in the state (set before its
# configuration was changed, so a run stopped in between restarts it the next time), then clear FLAG. A service that
# does not run yet is left to service_enable_start (call this first): its start reads the new configuration.
restart_if_pending() {
    local flag=$1 unit=$2 how=${3:-restart}
    [[ $(state_get "$flag" || true) == 1 ]] || return 0
    if systemctl is-active --quiet "$unit"; then
        act "$how $unit (its configuration changed)" systemctl "$how" "$unit"
    fi
    state_set "$flag" 0
}

# port_owner PORT: the process that listens on a TCP port (empty when none).
port_owner() {
    local out; out=$(ss -Htlnp "sport = :$1" 2>/dev/null || true)
    if [[ $out =~ users:\(\(\"([^\"]+)\" ]]; then printf '%s' "${BASH_REMATCH[1]}"; fi
}

# port_check PORT WHAT HINT OWNER...: stop when a TCP port is in use by another program than OWNER (a process
# name); a port held by a process this server cannot see (another container) counts as in use.
port_check() {
    local port=$1 what=$2 hint=$3; shift 3
    [[ -n $(ss -Htln "sport = :$port" 2>/dev/null) ]] || return 0
    local owner o; owner=$(port_owner "$port")
    for o in "$@"; do [[ $owner == "$o" ]] && return 0; done
    die "port $port, which $what needs, is in use by ${owner:-a program this installer cannot see (another container?)}" "$hint"
}

# wait_http URL SECONDS: wait until URL answers (any HTTP status but 000/5xx).
wait_http() {
    local url=$1 limit=$2 i code
    for ((i = 0; i < limit; i++)); do
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 --noproxy '*' "$url" || true)
        if [[ $code =~ ^[1-4] ]]; then return 0; fi
        sleep 1
    done
    return 1
}

# selinux_active: whether SELinux enforces or warns (getenforce: Enforcing or Permissive).
selinux_active() {
    command -v getenforce >/dev/null 2>&1 || return 1
    local mode; mode=$(getenforce 2>/dev/null || true)
    [[ $mode == Enforcing || $mode == Permissive ]]
}

# firewalld_active: whether firewalld runs.
firewalld_active() {
    command -v firewall-cmd >/dev/null 2>&1 || return 1
    [[ $(firewall-cmd --state 2>/dev/null || true) == running ]]
}

# firewall_open WHAT...: open services (http) or ports (3000/tcp) in firewalld's default zone, now and permanently.
firewall_open() {
    if ! firewalld_active; then
        detail "firewalld does not run: no firewall rules to add for $*"
        return 0
    fi
    local zone item args=()
    zone=$(firewall-cmd --get-default-zone)
    for item in "$@"; do
        if [[ $item == */* ]]; then
            firewall-cmd --zone "$zone" --query-port "$item" >/dev/null 2>&1 || args+=(--add-port "$item")
        else
            firewall-cmd --zone "$zone" --query-service "$item" >/dev/null 2>&1 || args+=(--add-service "$item")
        fi
    done
    if [[ ${#args[@]} == 0 ]]; then
        detail "firewalld zone $zone allows $* already"
        return 0
    fi
    act "allow ${args[*]} in firewalld (zone $zone, now)" firewall-cmd --zone "$zone" "${args[@]}"
    act "allow ${args[*]} in firewalld (zone $zone, permanently)" firewall-cmd --permanent --zone "$zone" "${args[@]}"
}

# download URL DEST [SHA256]: download over HTTPS (proxies from the environment), verified when a checksum is given.
download() {
    local url=$1 dest=$2 sum=${3:-}
    [[ $url == https://* ]] || die "internal error: $url is not an HTTPS URL"
    log "+ curl $url -> $dest"
    local start; start=$(_log_size)
    if ! curl -fsSL --retry 3 --connect-timeout 20 -o "$dest" "$url" 2>>"$LOG_FILE"; then
        FAIL_HINT="${DOWNLOAD_HINT:-check that this server reaches $url}" _fail_cmd "$start" curl "$url"
    fi
    if [[ -n $sum ]] && [[ $(sha256sum "$dest" | cut -d' ' -f1) != "$sum" ]]; then
        die "the download of $url does not have the expected SHA-256 checksum" \
            "try again; if it keeps failing, download the file elsewhere and give it as a local copy (see install/rocky/README.md, Offline mode)"
    fi
}
