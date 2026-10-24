# shellcheck shell=bash
# install/rocky/lib/semaphore.sh: part 4, Semaphore UI (the runner of the Ansible plugin) and its project for LibreNMS,
# as the Ansible plugin's README sets it up (ansible/README.md, Semaphore UI setup), then the plugin's connection.
#
#  * Semaphore UI 2.19.17 (the version the plugin was tested with) from its release RPM, tarball or binary; its own
#    MariaDB database and user; /etc/semaphore/config.json; a systemd service as the user semaphore, on 127.0.0.1:3000.
#  * Its admin (--admin-user), and a project "LibreNMS changes": the key store entries of the devices' login, a Git
#    repository (file:// on this server) with the plugin's inventory script and example fix playbooks, the LibreNMS
#    inventory, the variable group (LIBRENMS_URL, ANSIBLE_CHECK_MODE_MARKERS=true, the secret LIBRENMS_TOKEN), a template
#    for each fix playbook, and the Task Runner user librenms with its API token.
#  * In LibreNMS: the inventory user ansible-inventory (global-read: it sees the devices, changes nothing) with its API
#    token; the plugin's runner connection, inventory user and fix templates; the run and approve permissions of the admin.

SEM_HOME=/var/lib/semaphore
SEM_CONF=/etc/semaphore/config.json
SEM_REPO=$SEM_HOME/playbooks.git
SEM_PROJECT="LibreNMS changes"
SEM_RUNNER=librenms                 # the Task Runner user (the README's suggestion)
LNMS_INVENTORY_USER=ansible-inventory
SEM_BIN=''
SEM_TOKEN=''                        # the installer's own admin token of this run (one hour; removed at the end)
SEM_API=''

semaphore_api_url() { printf 'http://127.0.0.1:%s' "$SEMAPHORE_PORT"; }

part_semaphore() {
    part_header semaphore "Semaphore UI $SEMAPHORE_VERSION, its project for LibreNMS, and the Ansible plugin's connection to it"
    librenms_require
    SEM_API=$(semaphore_api_url)
    sem_binary
    sem_user
    sem_database
    sem_config
    sem_service
    sem_playbook_repo
    if [[ $CHECK == 1 ]]; then
        # --check does not log in to Semaphore UI (that would make a token): a run checks the project through its API
        local project; project=$(state_get semaphore_project || true)
        if [[ -n $project ]]; then
            info "the project \"$SEM_PROJECT\" (#$project) was set up by an earlier run: a run checks it, its templates and the plugin's connection again"
            summary_add "Semaphore UI $SEMAPHORE_VERSION: project #$project (a run checks it through the API)"
        else
            would "set up the project \"$SEM_PROJECT\" (keys, repository, inventory, variable group, templates, the Task Runner user and token)"
            would "set the Ansible plugin's runner connection, inventory user and fix templates, and grant $ADMIN_USER the run and approve permissions"
            summary_add "Semaphore UI $SEMAPHORE_VERSION: would be set up"
        fi
        return 0
    fi
    sem_admin
    sem_admin_token
    lnms_inventory_user
    librenms_url "$LNMS_INVENTORY_TOKEN"
    sem_project
    sem_runner_token
    selinux_boolean_on httpd_can_network_connect "LibreNMS' PHP reaches Semaphore UI's API"
    ansible_connect
    if [[ $SEMAPHORE_LISTEN != 127.* && $SEMAPHORE_LISTEN != ::1 ]]; then firewall_open "$SEMAPHORE_PORT/tcp"; fi
    local empty=''
    [[ -n $SEM_LOGIN_EMPTY ]] && empty="; the devices' login is empty: fill it in"
    summary_add "Semaphore UI $SEMAPHORE_VERSION on http://$SEMAPHORE_LISTEN:$SEMAPHORE_PORT (admin: $ADMIN_USER), project \"$SEM_PROJECT\" (#${SEM_PROJECT_ID:-?})$empty"
}

# semaphore_cleanup: remove the installer's admin token of this run (at exit).
semaphore_cleanup() {
    [[ -n $SEM_TOKEN && -n $SEM_API ]] || return 0
    python3 -I "$WORK/lib/semaphore_api.py" token-delete <<<"$(json_object url "$SEM_API" token "$SEM_TOKEN")" >/dev/null 2>>"$LOG_FILE" || true
    SEM_TOKEN=''
}

# ---------------------------------------------------------------------------------------------------- the steps

sem_find_binary() {
    SEM_BIN=''
    local b
    for b in /usr/bin/semaphore /usr/local/bin/semaphore; do [[ -x $b ]] && { SEM_BIN=$b; return 0; }; done
    return 0
}

sem_binary() {
    sem_find_binary
    if [[ -n $SEM_BIN ]]; then
        local v; v=$("$SEM_BIN" version 2>/dev/null || true)
        if [[ $v == "v$SEMAPHORE_VERSION"* ]]; then detail "Semaphore UI $v is installed ($SEM_BIN)"; return 0; fi
        die "Semaphore UI ${v:-of an unknown version} is installed ($SEM_BIN), and the plugin was tested with $SEMAPHORE_VERSION" \
            "remove it (or install $SEMAPHORE_VERSION), then run the installer again; or leave this part out (--skip semaphore)"
    fi
    local src=$SEMAPHORE_SOURCE arch; arch=$(semaphore_arch)
    if [[ -z $src ]]; then
        [[ -z $OFFLINE_DIR ]] || die "--offline: no Semaphore UI $SEMAPHORE_VERSION in $OFFLINE_DIR" "put semaphore_${SEMAPHORE_VERSION}_linux_${arch}.rpm (or its .tar.gz, or the binary) there, or give --semaphore-source"
        would "install Semaphore UI $SEMAPHORE_VERSION (its release RPM from GitHub)" && return 0
        local base="https://github.com/semaphoreui/semaphore/releases/download/v$SEMAPHORE_VERSION"
        local rpm="semaphore_${SEMAPHORE_VERSION}_linux_${arch}.rpm"
        DOWNLOAD_HINT="check that this server reaches GitHub, or download $rpm from $base/ and give it with --semaphore-source" \
            download "$base/semaphore_${SEMAPHORE_VERSION}_checksums.txt" "$WORK/semaphore_checksums.txt"
        local sum; sum=$(awk -v f="$rpm" '$2 == f {print $1}' "$WORK/semaphore_checksums.txt")
        [[ -n $sum ]] || die "the release checksums of Semaphore UI $SEMAPHORE_VERSION do not list $rpm" "download it from $base/ and give it with --semaphore-source"
        DOWNLOAD_HINT="check that this server reaches GitHub, or give the file with --semaphore-source" download "$base/$rpm" "$WORK/$rpm" "$sum"
        src=$WORK/$rpm
    fi
    case $src in
        *.rpm)
            act "install Semaphore UI $SEMAPHORE_VERSION ($(basename "$src"))" dnf -y install "$src" ;;
        *.tar.gz|*.tgz)
            would "install Semaphore UI $SEMAPHORE_VERSION from $(basename "$src") as /usr/local/bin/semaphore" && return 0
            mkdir -p "$WORK/semaphore"
            run tar -xzf "$src" -C "$WORK/semaphore"
            [[ -f $WORK/semaphore/semaphore ]] || die "$src holds no semaphore binary" "give Semaphore UI's release tarball, RPM or binary"
            run install -m 755 "$WORK/semaphore/semaphore" /usr/local/bin/semaphore ;;
        *)
            would "install Semaphore UI $SEMAPHORE_VERSION from $src as /usr/local/bin/semaphore" && return 0
            run install -m 755 "$src" /usr/local/bin/semaphore ;;
    esac
    [[ $CHECK == 1 ]] && return 0
    sem_find_binary
    local v; v=$("$SEM_BIN" version 2>/dev/null || true)
    [[ $v == "v$SEMAPHORE_VERSION"* ]] || die "$src is Semaphore UI ${v:-of an unknown version}, not $SEMAPHORE_VERSION" "give Semaphore UI $SEMAPHORE_VERSION"
    info "Semaphore UI $v installed ($SEM_BIN)"
}

sem_user() {
    if getent passwd semaphore >/dev/null; then detail "the semaphore user exists"; return 0; fi
    act "add the user semaphore (Semaphore UI and the Ansible it runs; home $SEM_HOME)" useradd --system --home-dir "$SEM_HOME" --create-home --shell /sbin/nologin semaphore
}

# sem_database: Semaphore UI's own database and user in MariaDB (installed and started when the server has none).
sem_database() {
    if ! rpm -q mariadb-server >/dev/null 2>&1; then
        dnf_install "MariaDB (for Semaphore UI's database)" mariadb-server
    fi
    port_check 3306 MariaDB "free it (another database server?)" mariadbd mysqld
    service_enable_start mariadb
    [[ $CHECK == 1 ]] && ! command -v mysql >/dev/null && { would "create the database semaphore and its user"; return 0; }
    local password; password=$(sem_config_value mysql.pass)
    [[ -n $password ]] || password=$(secret_for SEMAPHORE_DB_PASSWORD)
    if db_password_ok semaphore "$password" semaphore && MYSQL_PWD=$password probe mysql -u semaphore -h 127.0.0.1 -e 'SELECT 1' semaphore; then
        detail "the database semaphore and its user are there"
        SEM_DB_PASSWORD=$password
        return 0
    fi
    would "create the database semaphore and its user (MariaDB)" && return 0
    mysql_root "CREATE DATABASE IF NOT EXISTS semaphore CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS 'semaphore'@'localhost' IDENTIFIED BY '$password';
CREATE USER IF NOT EXISTS 'semaphore'@'127.0.0.1' IDENTIFIED BY '$password';
ALTER USER 'semaphore'@'localhost' IDENTIFIED BY '$password';
ALTER USER 'semaphore'@'127.0.0.1' IDENTIFIED BY '$password';
GRANT ALL PRIVILEGES ON semaphore.* TO 'semaphore'@'localhost';
GRANT ALL PRIVILEGES ON semaphore.* TO 'semaphore'@'127.0.0.1';
FLUSH PRIVILEGES;"
    cred_set SEMAPHORE_DB_PASSWORD "$password"
    SEM_DB_PASSWORD=$password
}
SEM_DB_PASSWORD=''

# sem_config_value PATH: a value of /etc/semaphore/config.json (empty when there is none).
sem_config_value() {
    [[ -f $SEM_CONF ]] || return 0
    python3 -I -c 'import json,sys
d=json.load(open(sys.argv[1]))
for k in sys.argv[2].split("."): d = d.get(k, "") if isinstance(d, dict) else ""
print(d)' "$SEM_CONF" "$1"
}

# sem_config: /etc/semaphore/config.json (root:semaphore 640). Its encryption keys are made once and never change
# (the key store is encrypted with access_key_encryption).
sem_config() {
    local text
    text=$(I_PASSWORD=$SEM_DB_PASSWORD I_LISTEN=$SEMAPHORE_LISTEN I_PORT=$SEMAPHORE_PORT I_TMP=$SEM_HOME/tmp python3 -I - "$SEM_CONF" <<'PY'
import base64, json, os, sys
path = sys.argv[1]
try:
    with open(path) as f:
        current = json.load(f)
except (OSError, ValueError):
    current = {}
e = os.environ
config = dict(current)
mysql = dict(current.get('mysql') or {})
mysql.update({'host': '127.0.0.1:3306', 'user': 'semaphore', 'name': 'semaphore'})
mysql['pass'] = e['I_PASSWORD'] or mysql.get('pass', '')
mysql.setdefault('options', {})
config.update({'mysql': mysql, 'dialect': 'mysql', 'interface': e['I_LISTEN'], 'port': ':' + e['I_PORT'], 'tmp_path': e['I_TMP']})
for key in ('cookie_hash', 'cookie_encryption', 'access_key_encryption'):
    if not config.get(key):
        config[key] = base64.b64encode(os.urandom(32)).decode()
print(json.dumps(config, indent=2, sort_keys=True))
PY
) || die "cannot make Semaphore UI's configuration" "read the log"
    if [[ $CHECK == 1 ]]; then
        # new encryption keys every time: only whether the file is there or its settings change counts
        if [[ ! -f $SEM_CONF ]]; then would "write $SEM_CONF (MariaDB, $SEMAPHORE_LISTEN:$SEMAPHORE_PORT)"
        elif [[ $(sem_config_value interface) != "$SEMAPHORE_LISTEN" || $(sem_config_value port) != ":$SEMAPHORE_PORT" ]]; then
            would "change $SEM_CONF to listen on $SEMAPHORE_LISTEN:$SEMAPHORE_PORT"
        fi
        return 0
    fi
    install -d -o root -g semaphore -m 750 /etc/semaphore
    install -d -o semaphore -g semaphore -m 750 "$SEM_HOME" "$SEM_HOME/tmp"
    if put_text "$text
" "$SEM_CONF" root:semaphore 640 "write $SEM_CONF (MariaDB, $SEMAPHORE_LISTEN:$SEMAPHORE_PORT)"; then
        state_set semaphore_restart 1
    fi
}

sem_service() {
    if [[ $CHECK == 1 && -z $SEM_BIN ]]; then would "add and start the service semaphore (as the user semaphore)"; return 0; fi
    if put_text "$(render semaphore.service SEMAPHORE_BIN="$SEM_BIN" ANSIBLE_VENV="$ANSIBLE_VENV")" /etc/systemd/system/semaphore.service root:root 644 \
        "add the service semaphore (/etc/systemd/system/semaphore.service)"; then
        [[ $CHECK == 0 ]] && run systemctl daemon-reload
        state_set semaphore_restart 1
    fi
    port_check "$SEMAPHORE_PORT" "Semaphore UI" "free it, or give Semaphore UI another one (--semaphore-port)" semaphore
    restart_if_pending semaphore_restart semaphore
    service_enable_start semaphore
    [[ $CHECK == 1 ]] && return 0
    wait_http "$SEM_API/api/ping" 60 || die "Semaphore UI does not answer on $SEM_API" "see journalctl -u semaphore"
}

# sem_playbook_repo: the Git repository of the project's playbooks (file:// on this server, so Semaphore UI records the
# commit of each task): the plugin's inventory script and the example fix playbooks of ansible/contrib/. A file is
# updated when the plugins bring a new version of it and nobody changed it in the repository since the installer put it.
sem_playbook_repo() {
    local src=$PLUGIN_CLONE/ansible/contrib
    if [[ ! -d $src ]]; then would "make the playbook repository $SEM_REPO (the plugin's inventory script and fix playbooks)"; return 0; fi
    if [[ ! -d $SEM_REPO ]]; then
        would "make the playbook repository $SEM_REPO" && return 0
        run as semaphore git init --bare --initial-branch=main "$SEM_REPO"
    fi
    [[ $CHECK == 1 && ! -d $SEM_REPO ]] && return 0
    local work=$WORK/playbooks
    rm -rf "$work"
    install -d -o semaphore -g semaphore "$work"
    run as semaphore git clone --quiet "file://$SEM_REPO" "$work/repo"
    local -A files=([inventory/librenms_inventory.py]=$src/librenms_inventory.py [fix_port_descriptions.yml]=$src/playbooks/fix_port_descriptions.yml
        [fix_stp_root_priority.yml]=$src/playbooks/fix_stp_root_priority.yml [README.md]=$FILES_DIR/playbooks-README.md)
    local path changed=() kept=() shipped current last key
    for path in "${!files[@]}"; do
        key=repo_$(tr -c 'A-Za-z0-9' '_' <<<"$path")
        shipped=$(sha256sum "${files[$path]}" | cut -d' ' -f1)
        current=$( [[ -f $work/repo/$path ]] && sha256sum "$work/repo/$path" | cut -d' ' -f1 || true)
        last=$(state_get "$key" || true)
        if [[ $current == "$shipped" ]]; then
            continue
        elif [[ -z $current || $current == "$last" ]]; then
            changed+=("$path")
            if [[ $CHECK == 0 ]]; then
                install -d -o semaphore -g semaphore "$(dirname "$work/repo/$path")"
                install -o semaphore -g semaphore -m "$( [[ $path == *.py ]] && echo 755 || echo 644 )" "${files[$path]}" "$work/repo/$path"
            fi
        else
            kept+=("$path")
        fi
    done
    if [[ ${#kept[@]} -gt 0 ]]; then warn "the playbook repository has its own version of ${kept[*]}: left as it is (the plugins bring a newer one in $src)"; fi
    if [[ ${#changed[@]} == 0 ]]; then detail "the playbook repository is up to date"; return 0; fi
    would "commit ${changed[*]} to the playbook repository $SEM_REPO" && return 0
    run in_dir "$work/repo" as semaphore git add --all
    run in_dir "$work/repo" as semaphore git -c user.name="LibreNMS installer" -c user.email="root@$SERVER_NAME" commit --quiet \
        -m "From the LibreNMS plugins ($PLUGIN_COMMIT): ${changed[*]}"
    run in_dir "$work/repo" as semaphore git push --quiet origin HEAD:main
    for path in "${changed[@]}"; do
        key=repo_$(tr -c 'A-Za-z0-9' '_' <<<"$path")
        state_set "$key" "$(sha256sum "${files[$path]}" | cut -d' ' -f1)"
    done
    info "the playbook repository has: ${changed[*]}"
}

# sem_admin: the admin (--admin-user). It is made with a throwaway password (on the command line, which every user
# can read in ps for that moment), which the API then replaces with the real one. The throwaway stays in the
# credentials file until then, so a run stopped in between finishes it the next time.
sem_admin() {
    local throwaway; throwaway=$(cred_get SEMAPHORE_ADMIN_THROWAWAY || true)
    if "$SEM_BIN" users get --login "$ADMIN_USER" --config "$SEM_CONF" >/dev/null 2>&1; then
        if [[ -z $throwaway ]]; then detail "the Semaphore UI admin $ADMIN_USER exists"; return 0; fi
        info "the Semaphore UI admin $ADMIN_USER still has the installer's throwaway password: setting the real one"
    else
        throwaway=$(gen_secret 32)
        cred_set SEMAPHORE_ADMIN_THROWAWAY "$throwaway"
        LOG_REDACT+=("$throwaway")
        act "add the Semaphore UI admin $ADMIN_USER" "$SEM_BIN" users add --admin --login "$ADMIN_USER" --name "$ADMIN_USER" \
            --email "$ADMIN_EMAIL" --password "$throwaway" --config "$SEM_CONF"
    fi
    sem_admin_token
    local password=$ADMIN_PASSWORD
    [[ -n $password ]] || password=$(cred_get ADMIN_PASSWORD || true)
    [[ -n $password ]] || password=$(secret_for SEMAPHORE_ADMIN_PASSWORD 20)
    local out
    run_secret out "$(json_object url "$SEM_API" token "$SEM_TOKEN" username "$ADMIN_USER" current_password "$throwaway" password "$password")" \
        python3 -I "$WORK/lib/semaphore_api.py" password
    cred_unset SEMAPHORE_ADMIN_THROWAWAY
}

sem_admin_token() {
    [[ -n $SEM_TOKEN ]] && return 0
    run_secret SEM_TOKEN '' "$SEM_BIN" users token create --login "$ADMIN_USER" --name librenms-installer --ttl 1h --config "$SEM_CONF"
    SEM_TOKEN=$(tr -d '[:space:]' <<<"$SEM_TOKEN")
    [[ -n $SEM_TOKEN ]] || die "Semaphore UI made no API token for $ADMIN_USER" "see journalctl -u semaphore"
}

# lnms_inventory_user: the LibreNMS user whose API token the runner fetches the inventory with (global-read: it sees
# the devices and changes nothing), and its token (made again when the one the installer keeps is no longer valid).
lnms_inventory_user() {
    helper user-ensure "$(json_object username "$LNMS_INVENTORY_USER" password "$(secret_for LIBRENMS_INVENTORY_PASSWORD 32)" roles '@json:["global-read"]' \
        realname "Ansible inventory (Semaphore UI)" descr "Ansible plugin inventory user")"
    local roles; roles=$(json_field "$HELPER_OUT" roles)
    LNMS_INVENTORY_ID=$(json_field "$HELPER_OUT" user_id)
    [[ $(json_field "$HELPER_OUT" created) == true ]] && info "LibreNMS user $LNMS_INVENTORY_USER (global-read: the Ansible plugin's inventory user)"
    [[ $roles == '["global-read"]' ]] || warn "the LibreNMS user $LNMS_INVENTORY_USER has the roles $roles; the installer made it with global-read only (it needs no more)"
    LNMS_INVENTORY_TOKEN=$(cred_get LIBRENMS_INVENTORY_TOKEN || true)
    INVENTORY_TOKEN_NEW=0
    if [[ -n $LNMS_INVENTORY_TOKEN ]]; then
        helper token-check "$(json_object token "$LNMS_INVENTORY_TOKEN" username "$LNMS_INVENTORY_USER")"
        [[ $(json_field "$HELPER_OUT" valid) == true ]] && return 0
        warn "the API token of $LNMS_INVENTORY_USER in $CRED_FILE is no longer valid: making a new one"
    fi
    info "a LibreNMS API token for $LNMS_INVENTORY_USER (ansible-inventory)"
    helper token-create "$(json_object username "$LNMS_INVENTORY_USER" name ansible-inventory)"
    LNMS_INVENTORY_TOKEN=$(json_field "$HELPER_OUT" token)
    cred_set LIBRENMS_INVENTORY_TOKEN "$LNMS_INVENTORY_TOKEN"
    INVENTORY_TOKEN_NEW=1
}
LNMS_INVENTORY_ID=''
LNMS_INVENTORY_TOKEN=''
INVENTORY_TOKEN_NEW=0

# lnms_api_ok URL TOKEN: whether LibreNMS' API answers at URL with the token (HTTP 200 without a redirect).
lnms_api_ok() {
    local code
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 --noproxy '*' --config - <<<"url = \"$1/api/v0/system\"
header = \"X-Auth-Token: $2\"" 2>/dev/null || true)
    log "LibreNMS API at $1: HTTP $code"
    [[ $code == 200 ]]
}

# librenms_url TOKEN: how Semaphore UI and Oxidized on this server reach LibreNMS (LIBRENMS_RESOLVED), tried with an
# API token of LibreNMS.
librenms_url() {
    [[ -n $LIBRENMS_RESOLVED ]] && return 0
    local token=$1
    local candidates=() url
    if [[ -n $LIBRENMS_URL ]]; then
        candidates=("$LIBRENMS_URL")
    elif [[ $LNMS_MODE == existing ]]; then
        lnms_env_get APP_URL
        [[ -n $ENV_VALUE ]] && candidates+=("${ENV_VALUE%/}")
        candidates+=(http://localhost https://localhost)
    else
        candidates=(http://localhost)
    fi
    for url in "${candidates[@]}"; do
        if lnms_api_ok "$url" "$token"; then LIBRENMS_RESOLVED=$url; detail "Semaphore UI and Oxidized reach LibreNMS at $url"; return 0; fi
    done
    die "LibreNMS' API does not answer on this server at ${candidates[*]}" \
        "give the address Semaphore UI and Oxidized on this server reach LibreNMS at with --librenms-url (https with a certificate this server trusts, no redirect)"
}
LIBRENMS_RESOLVED=''

# sem_project: the project and what is in it, through Semaphore UI's API (semaphore_api.py setup).
sem_project() {
    local login_hash update=false
    login_hash=$( [[ -n $DEVICE_USER ]] && printf '%s\0%s\0%s' "$DEVICE_USER" "$DEVICE_PASSWORD" "$DEVICE_ENABLE_PASSWORD" | sha256sum | cut -d' ' -f1 || true)
    if [[ -n $login_hash && $login_hash != "$(state_get device_login || true)" ]]; then update=true; fi
    local request
    request=$(I_SEM_API=$SEM_API I_SEM_TOKEN=$SEM_TOKEN I_PROJECT=$SEM_PROJECT I_DEVICE_USER=$DEVICE_USER I_DEVICE_PASSWORD=$DEVICE_PASSWORD \
        I_DEVICE_ENABLE_PASSWORD=$DEVICE_ENABLE_PASSWORD I_UPDATE=$update I_REPO=$SEM_REPO I_LIBRENMS_URL=$LIBRENMS_RESOLVED \
        I_INVENTORY_TOKEN=$LNMS_INVENTORY_TOKEN I_TOKEN_NEW=$INVENTORY_TOKEN_NEW I_RUNNER=$SEM_RUNNER I_SERVER=$SERVER_NAME \
        I_RUNNER_PASSWORD="$(secret_for SEMAPHORE_RUNNER_PASSWORD 32)" python3 -I - <<'PY'
import json, os
e = os.environ
print(json.dumps({
    'url': e['I_SEM_API'], 'token': e['I_SEM_TOKEN'], 'project': e['I_PROJECT'],
    'keys': {
        'login': {'name': 'Devices: SSH login', 'login': e['I_DEVICE_USER'], 'password': e['I_DEVICE_PASSWORD'], 'update': e['I_UPDATE'] == 'true'},
        'become': {'name': 'Devices: enable password', 'login': '', 'password': e['I_DEVICE_ENABLE_PASSWORD'], 'update': e['I_UPDATE'] == 'true'},
    },
    'repository': {'name': 'LibreNMS playbooks', 'git_url': 'file://' + e['I_REPO'], 'git_branch': 'main'},
    'inventory': {'name': 'LibreNMS', 'path': 'inventory/librenms_inventory.py'},
    'environment': {'name': 'LibreNMS', 'env': {'LIBRENMS_URL': e['I_LIBRENMS_URL'], 'ANSIBLE_CHECK_MODE_MARKERS': 'true'},
                    'secret_env': {'LIBRENMS_TOKEN': e['I_INVENTORY_TOKEN']}, 'update_secrets': e['I_TOKEN_NEW'] == '1'},
    'templates': [
        {'kind': 'port_descriptions', 'name': 'Fix port descriptions', 'playbook': 'fix_port_descriptions.yml', 'arguments': [],
         'description': 'LibreNMS Ansible plugin: the port description fixes of its Fixes page (an example for Cisco IOS: review it)',
         'survey': 'port_descriptions', 'survey_title': 'Port descriptions', 'survey_description': 'one line per port: <host> <interface> <description>'},
        {'kind': 'stp_root_priority', 'name': 'Fix spanning tree root priority', 'playbook': 'fix_stp_root_priority.yml', 'arguments': ['-v'],
         'description': 'LibreNMS Ansible plugin: the spanning tree root priority fixes of its Fixes page (an example for Cisco IOS: review it)',
         'survey': 'stp_priorities', 'survey_title': 'Spanning tree priorities', 'survey_description': 'one line per device and mode: <host> <mode> <instances> <priority>'},
    ],
    'runner': {'username': e['I_RUNNER'], 'name': 'LibreNMS (Ansible plugin)', 'email': e['I_RUNNER'] + '@' + e['I_SERVER'],
               'password': e['I_RUNNER_PASSWORD']},
}))
PY
)
    local out
    log "+ semaphore_api.py setup (request and answer hold secrets: only the changes are logged)"
    out=$(python3 -I "$WORK/lib/semaphore_api.py" setup <<<"$request" 2>>"$LOG_FILE") || {
        die "Semaphore UI's project could not be set up: $(json_field "$out" error 2>/dev/null || echo "see the log")" "fix it in Semaphore UI (or remove the object it names), then run the installer again"
    }
    local change
    while IFS= read -r change; do
        [[ -n $change ]] && { info "Semaphore UI: $change"; CHANGES+=("semaphore: $change"); log "semaphore: $change"; }
    done < <(python3 -I -c 'import json,sys; [print(c) for c in json.loads(sys.argv[1])["changes"]]' "$out")
    SEM_PROJECT_ID=$(json_field "$out" project_id)
    SEM_INVENTORY_ID=$(json_field "$out" inventory_id)
    SEM_TEMPLATE_PORTS=$(json_field "$out" templates.port_descriptions)
    SEM_TEMPLATE_STP=$(json_field "$out" templates.stp_root_priority)
    SEM_LOGIN_EMPTY=''
    [[ $(json_field "$out" login_key_empty) == true ]] && SEM_LOGIN_EMPTY=1
    [[ -n $login_hash ]] && state_set device_login "$login_hash"
    state_set semaphore_project "$SEM_PROJECT_ID"
}
SEM_PROJECT_ID=''
SEM_INVENTORY_ID=''
SEM_TEMPLATE_PORTS=''
SEM_TEMPLATE_STP=''
SEM_LOGIN_EMPTY=''

# sem_runner_token: the API token of the Task Runner user, which the plugin uses (made again when the one the
# installer keeps is no longer Semaphore UI's).
sem_runner_token() {
    SEM_RUNNER_TOKEN=$(cred_get SEMAPHORE_RUNNER_TOKEN || true)
    if [[ -n $SEM_RUNNER_TOKEN ]]; then
        local code
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 --noproxy '*' --config - <<<"url = \"$SEM_API/api/user\"
header = \"Authorization: Bearer $SEM_RUNNER_TOKEN\"" || true)
        [[ $code == 200 ]] && return 0
        warn "the Task Runner token in $CRED_FILE is no longer Semaphore UI's: making a new one"
    fi
    info "an API token for the Task Runner user $SEM_RUNNER (the Ansible plugin's)"
    run_secret SEM_RUNNER_TOKEN '' "$SEM_BIN" users token create --login "$SEM_RUNNER" --name librenms-ansible-plugin --config "$SEM_CONF"
    SEM_RUNNER_TOKEN=$(tr -d '[:space:]' <<<"$SEM_RUNNER_TOKEN")
    cred_set SEMAPHORE_RUNNER_TOKEN "$SEM_RUNNER_TOKEN"
}
SEM_RUNNER_TOKEN=''

# ansible_connect: the Ansible plugin's settings, saved as its settings page saves them (librenms_helper.php
# ansible-setup): the runner connection, the inventory user, the fix templates and the admin's permissions; then the
# page's Test connection, whose answer the run reports.
ansible_connect() {
    local request
    request=$(I_URL="http://127.0.0.1:$SEMAPHORE_PORT" I_TOKEN=$SEM_RUNNER_TOKEN I_PROJECT=$SEM_PROJECT_ID I_INV=$SEM_INVENTORY_ID \
        I_USER=$LNMS_INVENTORY_ID I_PORTS=$SEM_TEMPLATE_PORTS I_STP=$SEM_TEMPLATE_STP I_ADMIN=$ADMIN_USER python3 -I -c 'import json,os
e=os.environ
print(json.dumps({"by": "the installer (install/rocky/install.sh)", "admin": e["I_ADMIN"],
  "connection": {"url": e["I_URL"], "token": e["I_TOKEN"], "project": e["I_PROJECT"], "inventory": e["I_INV"], "verify_tls": "1", "ca_bundle": "", "timeout": "15"},
  "settings": {"inventory_user": e["I_USER"], "fix_template_port_descriptions": e["I_PORTS"], "fix_template_stp_root_priority": e["I_STP"]}}))')
    HELPER_HINT="the Ansible plugin checks what it is given as its settings page does: see the message" helper ansible-setup "$request"
    local out=$HELPER_OUT line
    for line in connection_changes settings_changes granted; do
        local items; items=$(json_field "$out" "$line")
        [[ $items == '[]' || -z $items ]] && continue
        info "Ansible plugin: $(python3 -I -c 'import json,sys; print("; ".join(json.loads(sys.argv[1])))' "$items")"
        CHANGES+=("semaphore: Ansible plugin $line")
    done
    local ok; ok=$(json_field "$out" test.ok)
    if [[ $ok != true ]]; then
        warn "the Ansible plugin's connection test failed: $(json_field "$out" test.error)"
        return 0
    fi
    local usable
    usable=$(python3 -I -c 'import json,sys
t=json.loads(sys.argv[1])["test"]
print("; ".join("%s: %s" % (x["name"], "usable" if x["usable"] else "not usable (" + ", ".join(x["reasons"]) + ")") for x in t.get("templates", [])))' "$out")
    info "Ansible plugin connection test: Semaphore UI $(json_field "$out" test.version), role $(json_field "$out" test.role); templates: $usable"
    local warnings; warnings=$(python3 -I -c 'import json,sys; print("\n".join(json.loads(sys.argv[1])["test"].get("warnings", [])))' "$out")
    while IFS= read -r line; do [[ -n $line ]] && detail "connection test: $line"; done <<<"$warnings"
    if grep -q 'not usable' <<<"$usable"; then warn "a template is not usable by the Ansible plugin: $usable"; fi
}
