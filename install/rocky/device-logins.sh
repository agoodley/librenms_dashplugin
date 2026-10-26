#!/usr/bin/env bash
# shellcheck source-path=SCRIPTDIR
# install/rocky/device-logins.sh: the devices that have a login of their own, from a CSV file (/root/device-logins.csv:
# device,username,password,enable); the others log in with the default one (install.sh --device-user, a RADIUS account
# for instance). For each of them:
#  * Oxidized gets a group with its login, in its configuration (which the installer writes, and keeps the groups of),
#    and LibreNMS gives Oxidized the device's group (oxidized.maps.group.hostname, with oxidized.group_support on);
#  * Ansible gets its login from inventory/host_vars/<name>.yml in the playbook repository, which takes the passwords
#    from secrets of the variable group "LibreNMS" (Semaphore UI puts them in the environment of the project's runs).
# A device whose line is gone goes back to the default login, once confirmed. Run it as root once the devices are in
# LibreNMS, and again after each change of the file. device-logins.sh --help lists the options; install/rocky/README.md
# (Device logins) says more.

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
# shellcheck source=lib/semaphore.sh
. "$INSTALLER_DIR/lib/semaphore.sh"
# shellcheck source=lib/oxidized.sh
. "$INSTALLER_DIR/lib/oxidized.sh"

usage() {
    cat <<'EOF'
Usage: device-logins.sh [options]              (as root, on the LibreNMS server, once the devices are in LibreNMS)

Gives the devices of a CSV file a login of their own, for Ansible (the Ansible plugin's changes) and for Oxidized
(the backups); every other device logs in with the default login (install.sh --device-user). The file is root's
alone: it holds the passwords. A header line, then a line per device (# starts a comment):

  device,username,password,enable
  sw5.example.com,localadmin,S3cret!,En4ble!     device: hostname, sysName, IP address or LibreNMS device id
  10.20.0.7,admin2,"with,commas",                enable: the enable password; empty: the password itself

  --file FILE   the file (default: /root/device-logins.csv)
  --list        what the file gives each device, and what a run would change; change nothing
  --yes         let devices whose line is gone go back to the default login without asking
  -h, --help    this text

Run it again after each change of the file: a device whose line is gone goes back to the default login.
EOF
}

bad_option() {
    printf 'device-logins.sh: %s\nSee device-logins.sh --help.\n' "$1" >&2
    exit 2
}

LOGINS_FILE=/root/device-logins.csv
LIST=0
YES=0
GROUP_PREFIX=login-
SECRET_PREFIX=DEVICE_LOGIN_
SEM_ENVIRONMENT=LibreNMS

parse_options() {
    local opt value
    while [[ $# -gt 0 ]]; do
        opt=$1
        if [[ $opt == --*=* ]]; then value=${opt#*=}; opt=${opt%%=*}; set -- "$opt" "$value" "${@:2}"; fi
        case $opt in
            -h|--help) usage; exit 0 ;;
            --list) LIST=1 ;;
            --yes|-y) YES=1 ;;
            --file)
                [[ $# -ge 2 && -n $2 ]] || bad_option "--file needs a value"
                LOGINS_FILE=$2
                shift ;;
            *) bad_option "unknown option: $opt" ;;
        esac
        shift
    done
}

# logins_json EXPRESSION: one of this script's Python expressions over the run's logins (L: the file's logins, R: the
# devices whose line is gone), printed as it is when it is text, else as JSON. The logins hold the passwords: they
# are read from the run's own file (root's alone), never passed on a command line.
logins_json() {
    python3 -I -c 'import json, sys
d = json.load(open(sys.argv[1]))
L, R = d["logins"], d["removed"]
r = eval(sys.argv[2])
print(r if isinstance(r, str) else json.dumps(r))' "$SECRET_DIR/logins.json" "$1"
}

# logins_oxidized: a group per device with its login in Oxidized's configuration (ox_config, with the values the
# configuration has), then LibreNMS' mapping of the devices to their groups, then Oxidized reads the devices again.
logins_oxidized() {
    CURRENT_PART=oxidized
    if [[ ! -f $OX_CONF ]]; then
        warn "there is no Oxidized of the installer ($OX_CONF): no device gets its own login for backups"
        return 0
    fi
    local values; values=$(ox_config_values)
    LIBRENMS_RESOLVED=$(json_field "$values" librenms_url)
    OX_TOKEN=$(json_field "$values" token)
    OXIDIZED_LISTEN=$(json_field "$values" listen)
    OXIDIZED_PORT=$(json_field "$values" port)
    SERVER_NAME=$(json_field "$values" server)
    if [[ -z $LIBRENMS_RESOLVED || -z $OX_TOKEN || -z $OXIDIZED_LISTEN || -z $OXIDIZED_PORT || -z $SERVER_NAME ]]; then
        die "$OX_CONF is not the configuration the installer writes: device-logins.sh cannot give it the devices' logins" \
            "run install.sh once (it writes the configuration again while it is the one it wrote), or give Oxidized the groups yourself"
    fi
    # the configuration's own default login stays (ox_config reads it when none is given)
    DEVICE_USER='' DEVICE_PASSWORD='' DEVICE_ENABLE_PASSWORD=''
    OX_GROUPS=$(logins_json '[{"name": x["group"], "device": x["title"], "username": x["username"], "password": x["password"], "enable": x["enable"]} for x in L]')
    ox_config
    local have want
    have=$(json_field "$(ox_config_values)" groups | python3 -I -c 'import json, sys; print(json.dumps(json.load(sys.stdin), sort_keys=True))')
    want=$(python3 -I -c 'import json, sys; print(json.dumps(json.load(sys.stdin), sort_keys=True))' <<<"$OX_GROUPS")
    if [[ $have != "$want" ]]; then
        die "$OX_CONF was changed by hand, so the installer leaves it as it is, and it has not the devices' logins" \
            "give Oxidized the groups yourself (install/rocky/README.md, Device logins), or let the installer write the file again (move it away, then run install.sh)"
    fi
    # LibreNMS gives Oxidized each device's group; group support goes off again when none is left (if it was turned on here)
    local groups support=null
    groups=$(logins_json '{x["hostname"]: x["group"] for x in L}')
    if [[ $groups == '{}' && $(state_get device_logins_group_support || true) == 1 ]]; then support=false; fi
    helper oxidized-groups "{\"groups\": $groups, \"prefix\": \"$GROUP_PREFIX\", \"support\": $support}"
    local line
    while IFS= read -r line; do
        [[ -n $line ]] || continue
        CHANGES+=("oxidized: LibreNMS' $line")
        info "LibreNMS' $line"
        case $line in
            'oxidized.group_support: on') state_set device_logins_group_support 1 ;;
            'oxidized.group_support: off') state_set device_logins_group_support 0 ;;
        esac
    done < <(json_field "$HELPER_OUT" changed | python3 -I -c 'import json, sys; print("\n".join(json.load(sys.stdin)))')
    # Oxidized reads the devices again: a restart when its configuration changed, else its REST API's reload
    if [[ $(state_get oxidized_restart || true) == 1 ]]; then
        restart_if_pending oxidized_restart oxidized
    elif [[ $(json_field "$HELPER_OUT" changed) != '[]' ]] && systemctl is-active --quiet oxidized; then
        probe curl -s --noproxy '*' --max-time 30 "$(local_service_url "$OXIDIZED_LISTEN" "$OXIDIZED_PORT")/reload.json" || true
    fi
}

# logins_semaphore: the secrets of the devices' passwords in the variable group (semaphore_api.py logins), with the
# installer's own Semaphore UI admin token for this run.
logins_semaphore() {
    CURRENT_PART=semaphore
    sem_find_binary
    if [[ -z $SEM_BIN || ! -f $SEM_CONF ]]; then
        warn "there is no Semaphore UI of the installer: no device gets its own login for Ansible"
        return 0
    fi
    SEMAPHORE_LISTEN=$(sem_config_value interface)
    SEMAPHORE_LISTEN=${SEMAPHORE_LISTEN#[}
    SEMAPHORE_LISTEN=${SEMAPHORE_LISTEN%]}
    SEMAPHORE_PORT=$(sem_config_value port)
    SEMAPHORE_PORT=${SEMAPHORE_PORT#:}
    SEM_API=$(semaphore_api_url)
    ADMIN_USER=$(state_get semaphore_admin || true)
    [[ -n $ADMIN_USER ]] || ADMIN_USER="admin"
    sem_admin_token
    local request out
    request=$(I_URL=$SEM_API I_TOKEN=$SEM_TOKEN I_PROJECT=$SEM_PROJECT I_ENV=$SEM_ENVIRONMENT I_PREFIX=$SECRET_PREFIX \
        python3 -I -c 'import json, os, sys
e = os.environ
d = json.load(open(sys.argv[1]))
secrets, changed = {}, []
for x in d["logins"]:
    password, enable = x["secrets"]
    secrets[password], secrets[enable] = x["password"], x["enable"]
    if x["status"] != "as it was":
        changed += [password, enable]
print(json.dumps({"url": e["I_URL"], "token": e["I_TOKEN"], "project": e["I_PROJECT"], "environment": e["I_ENV"],
                  "prefix": e["I_PREFIX"], "secrets": secrets, "changed": changed}))' "$SECRET_DIR/logins.json")
    log "+ semaphore_api.py logins (the request holds secrets: only the changes are logged)"
    out=$(python3 -I "$WORK/lib/semaphore_api.py" logins <<<"$request" 2>>"$LOG_FILE") || {
        die "Semaphore UI refused the devices' logins: $(json_field "$out" error 2>/dev/null || true)" "read the log, and journalctl -u semaphore"
    }
    local line
    while IFS= read -r line; do
        [[ -n $line ]] || continue
        CHANGES+=("semaphore: $line")
        info "variable group \"$SEM_ENVIRONMENT\": $line"
    done < <(json_field "$out" changes | python3 -I -c 'import json, sys; print("\n".join(json.load(sys.stdin)))')
}

# logins_playbooks: inventory/host_vars/<name>.yml of each device in the playbook repository, committed as the
# semaphore user (who owns it); the files of devices whose line is gone are removed. A file of the same name that
# device-logins.sh did not write stays as it is.
logins_playbooks() {
    CURRENT_PART=semaphore
    if [[ ! -d $SEM_REPO ]]; then
        warn "there is no playbook repository ($SEM_REPO): no device gets its own login for Ansible"
        return 0
    fi
    local work=$WORK/playbooks dir name f changed=()
    install -d -o semaphore -g semaphore "$work"
    run as semaphore git clone --quiet "file://$SEM_REPO" "$work/repo"
    dir=$work/repo/inventory/host_vars
    as semaphore install -d "$dir"
    while IFS= read -r name; do
        [[ -n $name ]] || continue
        if [[ ! -f $WORK/host_vars/$name ]] && [[ $(as semaphore head -n 1 "$dir/$name") == '# device-logins.sh:'* ]]; then
            run in_dir "$work/repo" as semaphore git rm --quiet "inventory/host_vars/$name"
            changed+=("-$name")
        fi
    done < <(as semaphore find "$dir" -maxdepth 1 -type f -name '*.yml' -printf '%f\n')
    for f in "$WORK/host_vars"/*.yml; do
        [[ -f $f ]] || continue
        name=${f##*/}
        if as semaphore test -e "$dir/$name"; then
            if [[ $(as semaphore head -n 1 "$dir/$name") != '# device-logins.sh:'* ]]; then
                warn "inventory/host_vars/$name of the playbook repository is your own: left as it is (give it that device's login yourself: install/rocky/README.md, Device logins)"
                continue
            fi
            as semaphore cmp -s "$f" "$dir/$name" && continue
        fi
        as semaphore install -m 644 "$f" "$dir/$name"
        changed+=("$name")
    done
    if [[ ${#changed[@]} == 0 ]]; then detail "the playbook repository has the devices' host_vars already"; return 0; fi
    run in_dir "$work/repo" as semaphore git add --all
    run in_dir "$work/repo" as semaphore git -c user.name="LibreNMS installer" -c user.email="root@$(hostname -f 2>/dev/null || hostname)" \
        commit --quiet -m "device-logins.sh: the host_vars of the devices with a login of their own (${changed[*]})"
    run in_dir "$work/repo" as semaphore git push --quiet origin HEAD:main
    CHANGES+=("semaphore: the playbook repository's inventory/host_vars: ${changed[*]}")
    info "the playbook repository's inventory/host_vars: ${changed[*]}"
}

main() {
    parse_options "$@"
    [[ $EUID == 0 ]] || { printf 'device-logins.sh: run it as root (sudo -i first).\n' >&2; exit 2; }
    [[ -f $LNMS_DIR/artisan ]] || { printf 'device-logins.sh: there is no LibreNMS in %s: run install.sh first.\n' "$LNMS_DIR" >&2; exit 1; }
    if [[ ! -f $LOGINS_FILE ]]; then
        printf 'device-logins.sh: there is no %s: make it, root'"'"'s alone (umask 077), with a header line\n' "$LOGINS_FILE" >&2
        printf '"device,username,password,enable" and a line per device that has a login of its own (device-logins.sh --help).\n' >&2
        exit 2
    fi
    mkdir -p "$STATE_DIR" "$LOG_DIR"
    chmod 700 "$STATE_DIR" "$LOG_DIR"
    if [[ $LIST == 0 ]]; then
        # the installer's lock: Oxidized's configuration and the playbook repository change in one run at a time
        exec 9>"$STATE_DIR/lock"
        flock -n 9 || { printf 'device-logins.sh: the installer runs (%s/lock): try again once it has finished.\n' "$STATE_DIR" >&2; exit 2; }
    fi
    LOG_FILE=$LOG_DIR/device-logins-$(date +%Y%m%d-%H%M%S).log
    (umask 077 && : >"$LOG_FILE")
    WORK=$(mktemp -d /tmp/device-logins.XXXXXX)
    trap 'semaphore_cleanup || true; rm -rf "$WORK"' EXIT
    # librenms_helper.php runs as librenms and the host_vars files are installed as semaphore: both read from here;
    # the logins themselves stay in secret/ (root's alone)
    chmod 755 "$WORK"
    cp -r "$INSTALLER_DIR/lib" "$WORK/"
    chmod -R a+rX "$WORK"
    SECRET_DIR=$WORK/secret
    install -d -m 700 "$SECRET_DIR"
    CURRENT_PART=device-logins
    log "install/rocky/device-logins.sh started: $(quote_cmd "$@")"

    # the file holds passwords: root's alone
    local mode; mode=$(stat -c %a "$LOGINS_FILE")
    if (( 8#$mode & 8#077 )); then
        chmod go= "$LOGINS_FILE"
        say "$LOGINS_FILE was mode $mode: it is root's alone now (it holds passwords)"
    fi

    HELPER_HINT="read the log ($LOG_FILE), and logs/librenms.log in $LNMS_DIR" \
        helper device-addresses "$(json_object oxidized_user "$LNMS_OXIDIZED_USER")"
    printf '%s\n' "$HELPER_OUT" >"$WORK/devices.json"
    # the last run: a hash of each device's login (never the login), with a salt of this server's
    local salt; salt=$(state_get device_logins_salt || true)
    if [[ -z $salt ]]; then salt=$(gen_secret 32); [[ $LIST == 1 ]] || state_set device_logins_salt "$salt"; fi
    I_SALT=$salt python3 -I -c 'import json, os, re, sys
devices = {}
if os.path.exists(sys.argv[1]):
    for line in open(sys.argv[1], encoding="utf-8"):
        m = re.match(r"device_login_([0-9]+)=(.*)$", line.rstrip("\n"))
        if m and m.group(2):
            devices[m.group(1)] = m.group(2)
json.dump({"salt": os.environ["I_SALT"], "devices": devices}, open(sys.argv[2], "w"))' "$STATE_FILE" "$SECRET_DIR/state.json"

    local rc=0 default
    default=$(json_field "$(ox_config_values)" username)
    python3 -I -u "$INSTALLER_DIR/lib/device_logins.py" --devices "$WORK/devices.json" --file "$LOGINS_FILE" \
        --state "$SECRET_DIR/state.json" --out "$SECRET_DIR/logins.json" --host-vars "$WORK/host_vars" --default-user "$default" \
        2>&1 | tee -a "$LOG_FILE" || rc=$?
    [[ $rc == 0 ]] || exit "$rc"
    if [[ $LIST == 1 ]]; then printf '(--list: nothing changed)\n'; exit 0; fi

    local removed; removed=$(logins_json '", ".join(r["title"] for r in R)')
    if [[ -n $removed && $YES == 0 ]]; then
        if [[ ! -t 0 ]]; then
            printf 'device-logins.sh: %s would go back to the default login (no line in %s any more): give --yes when they should, or put their lines back.\n' "$removed" "$LOGINS_FILE" >&2
            exit 2
        fi
        local answer
        printf '%s go back to the default login (no line in %s any more). Continue? [y/N] ' "$removed" "$LOGINS_FILE"
        read -r answer
        [[ $answer == [yY]* ]] || { printf 'Nothing changed.\n'; exit 0; }
    fi

    printf '\n'
    logins_semaphore
    logins_playbooks
    logins_oxidized
    CURRENT_PART=device-logins
    # what this run gave each device (a hash of its login), for the next run to tell what changed
    local line
    while IFS= read -r line; do
        if [[ -n $line ]]; then state_set "device_login_${line%%=*}" "${line#*=}"; fi
    done < <(logins_json '"\n".join("%d=%s" % (x["id"], x["hash"]) for x in L)')
    while IFS= read -r line; do
        if [[ -n $line ]]; then state_unset "device_login_$line"; fi
    done < <(logins_json '"\n".join(str(r["id"]) for r in R)')
    if [[ ${#WARNINGS[@]} -gt 0 ]]; then
        printf '\nWarnings:\n'
        local w; for w in "${WARNINGS[@]}"; do printf '  - %s\n' "$w"; done
    fi
    if [[ ${#CHANGES[@]} == 0 ]]; then say "Nothing needed a change."; fi
    printf 'Log: %s\n' "$LOG_FILE"
    log "finished"
}

main "$@"
