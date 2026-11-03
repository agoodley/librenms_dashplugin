# shellcheck shell=bash
# install/rocky/lib/oxidized.sh: part 5, Oxidized (the backups the Ansible plugin takes before and after each batch),
# as Oxidized's documentation installs it on Rocky Linux (README.md of the gem: EPEL, CRB, Ruby 3.1 or later, the
# build packages, gem install oxidized oxidized-web, a user of its own, extra/oxidized.service), here pinned to the
# versions the plugin was tested with: oxidized 0.37.0 and oxidized-web 0.18.1 on Ruby 3.3.
# Its devices come from LibreNMS' API (the LibreNMS user oxidized, global-read, with an API token of its own), its
# versions go to git, its REST API listens on 127.0.0.1:8888; LibreNMS' Oxidized settings point at it.
# A LibreNMS that uses another Oxidized already keeps it: this part then changes nothing.

OX_HOME=/home/oxidized
OX_DIR=$OX_HOME/.config/oxidized
OX_CONF=$OX_DIR/config
LNMS_OXIDIZED_USER=oxidized

part_oxidized() {
    part_header oxidized "Oxidized $OXIDIZED_VERSION with oxidized-web $OXIDIZED_WEB_VERSION, fed by LibreNMS"
    librenms_require
    OX_URL=$(local_service_url "$OXIDIZED_LISTEN" "$OXIDIZED_PORT")
    if [[ -f $LNMS_DIR/artisan && -f $LNMS_DIR/.env ]] && [[ $(state_get librenms_db || true) == yes || $LNMS_MODE == existing ]]; then
        local current; current=$(lnms config:get --dump oxidized.url 2>>"$LOG_FILE" | tail -n 1 || true)
        current=${current//\"/}; current=${current//\\\//\/}
        local previous; previous=$(ox_previous_url)
        if [[ -n $current && $current != null && ${current%/} != "$OX_URL" && ${current%/} != "$previous" ]]; then
            info "LibreNMS already uses the Oxidized at $current: the Ansible plugin's backups go there, and this part changes nothing"
            summary_add "Oxidized: LibreNMS uses $current already (left as it is)"
            return 0
        fi
    fi
    ox_ruby
    ox_gems
    ox_user
    ox_known_hosts
    if [[ $CHECK == 1 && ! -f $OX_CONF ]]; then
        would "add the LibreNMS user $LNMS_OXIDIZED_USER (global-read) with an API token for Oxidized"
        would "write $OX_CONF (devices from LibreNMS, git output, REST API on $OXIDIZED_LISTEN:$OXIDIZED_PORT), add the service oxidized"
        would "point LibreNMS' Oxidized settings at $OX_URL"
        summary_add "Oxidized $OXIDIZED_VERSION: would be installed"
        return 0
    fi
    ox_librenms_user
    librenms_url "$OX_TOKEN"
    ox_config
    ox_service
    ox_librenms_settings
    state_set oxidized_url "$OX_URL"
    selinux_boolean_on httpd_can_network_connect "LibreNMS' PHP reaches Oxidized, as LibreNMS' Oxidized documentation says"
    if [[ $OXIDIZED_LISTEN != 127.* && $OXIDIZED_LISTEN != ::1 ]]; then
        firewall_open "$OXIDIZED_PORT/tcp"
        # oxidized-web has no login: whoever reaches the port reads every device configuration (communities, keys, password hashes)
        warn "Oxidized's REST API on $OXIDIZED_LISTEN:$OXIDIZED_PORT has no login and serves every device's configuration to whoever reaches it: keep it on 127.0.0.1 unless a firewall in front limits who does"
    fi
    ox_verify
    summary_add "Oxidized $OXIDIZED_VERSION on http://$OXIDIZED_LISTEN:$OXIDIZED_PORT: $OX_STATUS"
}

# A managed installation may move its listener. Preserve unrelated, administrator-selected Oxidized endpoints.
ox_previous_url() {
    local previous; previous=$(state_get oxidized_url || true)
    if [[ -n $previous ]]; then printf '%s' "$previous"; return; fi
    # Migration from installers that always saved 127.0.0.1, regardless of their configured listener.
    if [[ -f $OX_CONF && -n $(state_get oxidized_config || true) ]]; then
        local port; port=$(sed -n 's/^    port: \([0-9][0-9]*\)$/\1/p' "$OX_CONF" | tail -n 1)
        [[ -z $port ]] || printf 'http://127.0.0.1:%s' "$port"
    fi
    return 0
}

# ox_known_hosts: the devices' verified keys (device_known_hosts: /etc/ssh/ssh_known_hosts, never trusted on first
# use), and the copy in Oxidized's home an earlier installer made. net-ssh trusts the keys of both files, so a key
# removed from one would stay trusted through the other: the copy goes while it holds what the system file holds.
ox_known_hosts() {
    device_keys_tools
    device_known_hosts
    local copy=$OX_HOME/.ssh/known_hosts
    [[ -f $copy ]] || return 0
    if [[ -f $KNOWN_HOSTS_FILE ]] && as oxidized cmp -s "$copy" "$KNOWN_HOSTS_FILE"; then
        act "remove $copy, an earlier installer's copy of $KNOWN_HOSTS_FILE (Oxidized reads that one)" as oxidized rm -f "$copy"
    else
        warn "$copy holds SSH host keys too: Oxidized trusts them as well as those of $KNOWN_HOSTS_FILE (keep the two in step, or remove $copy)"
    fi
}

ox_ruby() {
    dnf_install "EPEL" epel-release
    dnf_install "dnf's plugins" dnf-plugins-core
    local repos; repos=$(dnf repolist --enabled 2>/dev/null || true)
    if ! grep -q '^crb' <<<"$repos"; then
        act "enable the CRB repository (Oxidized's build packages)" dnf config-manager --set-enabled crb
    fi
    if rpm -q ruby >/dev/null 2>&1; then
        local v; v=$(ruby -e 'print RUBY_VERSION' 2>/dev/null || true)
        version_ge "${v:-0}" 3.0 || die "Ruby $v is installed, and Oxidized needs 3.0 or later (its documentation: 3.1 on Rocky 9)" \
            "switch Ruby to a newer module stream (dnf module switch-to ruby:3.3), or leave Oxidized out (--skip oxidized)"
        version_ge "$v" 3.1 || warn "Ruby $v: Oxidized's documentation asks for 3.1 or later on Rocky 9 (it was tested with 3.3)"
    elif [[ $OS_MAJOR == 9 && $(module_stream ruby) != 3.3 ]]; then
        act "enable the ruby:3.3 module stream (the Ruby Oxidized was tested with)" dnf -y module enable ruby:3.3
    fi
    dnf_install "Ruby and Oxidized's build packages" ruby ruby-devel sqlite-devel openssl-devel pkgconf-pkg-config cmake \
        libssh-devel libicu-devel zlib-devel gcc-c++ libyaml-devel which make
}

ox_gems() {
    local have_ox=0 have_web=0
    gem list -i oxidized -v "$OXIDIZED_VERSION" >/dev/null 2>&1 && have_ox=1
    gem list -i oxidized-web -v "$OXIDIZED_WEB_VERSION" >/dev/null 2>&1 && have_web=1
    if [[ $have_ox == 1 && $have_web == 1 ]]; then detail "oxidized $OXIDIZED_VERSION and oxidized-web $OXIDIZED_WEB_VERSION are installed"; return 0; fi
    if [[ -n $GEMS_DIR ]]; then
        local ox web
        ox=$(find "$GEMS_DIR" -maxdepth 1 -name "oxidized-$OXIDIZED_VERSION.gem" -print -quit)
        web=$(find "$GEMS_DIR" -maxdepth 1 -name "oxidized-web-$OXIDIZED_WEB_VERSION.gem" -print -quit)
        [[ -n $ox && -n $web ]] || die "$GEMS_DIR lacks oxidized-$OXIDIZED_VERSION.gem or oxidized-web-$OXIDIZED_WEB_VERSION.gem" "see install/rocky/README.md (Offline mode) for how to make the gem directory"
        FAIL_HINT="the gem directory must hold every gem they depend on (see install/rocky/README.md, Offline mode)" \
            act "install oxidized $OXIDIZED_VERSION and oxidized-web $OXIDIZED_WEB_VERSION from $GEMS_DIR (native gems are compiled: some minutes)" \
            in_dir "$GEMS_DIR" gem install --local --no-document "$(basename "$ox")" "$(basename "$web")"
    else
        FAIL_HINT="check that this server reaches rubygems.org, or give local gems (--gems DIR)" \
            act "install the gem oxidized $OXIDIZED_VERSION (native gems are compiled: some minutes)" gem install --no-document oxidized -v "$OXIDIZED_VERSION"
        FAIL_HINT="check that this server reaches rubygems.org, or give local gems (--gems DIR)" \
            act "install the gem oxidized-web $OXIDIZED_WEB_VERSION" gem install --no-document oxidized-web -v "$OXIDIZED_WEB_VERSION"
    fi
}

ox_bin() {
    local b
    for b in /usr/local/bin/oxidized /usr/bin/oxidized; do [[ -x $b ]] && { printf '%s' "$b"; return 0; }; done
    printf '/usr/local/bin/oxidized'
}

ox_user() {
    if getent passwd oxidized >/dev/null; then detail "the oxidized user exists"; return 0; fi
    act "add the user oxidized (as Oxidized's documentation does)" useradd -s /bin/bash -m oxidized
}

# ox_librenms_user: the LibreNMS user whose API token Oxidized reads the devices with (global-read).
ox_librenms_user() {
    helper user-ensure "$(json_object username "$LNMS_OXIDIZED_USER" password "$(secret_for LIBRENMS_OXIDIZED_PASSWORD 32)" roles '@json:["global-read"]' \
        realname "Oxidized" descr "Oxidized backups (API token)")"
    [[ $(json_field "$HELPER_OUT" created) == true ]] && info "LibreNMS user $LNMS_OXIDIZED_USER (global-read: Oxidized's)"
    OX_TOKEN=$(cred_get LIBRENMS_OXIDIZED_TOKEN || true)
    if [[ -n $OX_TOKEN ]]; then
        helper token-check "$(json_object token "$OX_TOKEN" username "$LNMS_OXIDIZED_USER")"
        [[ $(json_field "$HELPER_OUT" valid) == true ]] && return 0
        warn "Oxidized's API token in $CRED_FILE is no longer valid: making a new one"
    fi
    info "a LibreNMS API token for $LNMS_OXIDIZED_USER (oxidized)"
    helper token-create "$(json_object username "$LNMS_OXIDIZED_USER" name oxidized)"
    OX_TOKEN=$(json_field "$HELPER_OUT" token)
    cred_set LIBRENMS_OXIDIZED_TOKEN "$OX_TOKEN"
}
OX_TOKEN=''

# ox_config_values: what the Oxidized configuration the installer wrote holds, as JSON: the default login's username,
# LibreNMS' URL and the token of its source (librenms_url, token), the REST API's address and port (listen, port), the
# server name of its git e-mail (server), and the groups of the devices with a login of their own (groups: name,
# device, username, password, enable; device-logins.sh's). {} when there is no configuration.
ox_config_values() {
    [[ -f $OX_CONF ]] || { printf '{}'; return 0; }
    python3 -I - "$OX_CONF" <<'PY'
import json, re, sys
out, groups, group, note, top = {}, [], None, '', None
for line in open(sys.argv[1], encoding='utf-8').read().splitlines():
    text = line.strip()
    indent = len(line) - len(line.lstrip(' '))
    if not text or text == '---' or (indent == 0 and text.startswith('#')):
        continue
    if indent == 0:
        top = text.split(':', 1)[0]
        if text.startswith('username: '):
            try:
                out['username'] = json.loads(text[len('username: '):])
            except ValueError:
                pass
        continue
    try:
        if top == 'groups':
            if indent == 2 and text.startswith('#'):
                note = text[1:].strip()
            elif indent == 2 and re.fullmatch(r'login-[0-9]+:', text):
                group = {'name': text[:-1], 'device': note, 'username': '', 'password': '', 'enable': ''}
                groups.append(group)
                note = ''
            elif group is not None and indent == 4 and text.startswith(('username: ', 'password: ')):
                key, value = text.split(': ', 1)
                group[key] = json.loads(value)
            elif group is not None and indent == 6 and text.startswith('enable: '):
                group['enable'] = json.loads(text[len('enable: '):])
        elif top == 'source' and indent == 4 and text.startswith('url: '):
            out['librenms_url'] = re.sub(r'/api/v0/oxidized$', '', json.loads(text[len('url: '):]))
        elif top == 'source' and indent == 6 and text.startswith('X-Auth-Token: '):
            out['token'] = json.loads(text[len('X-Auth-Token: '):])
        elif top == 'output' and indent == 4 and text.startswith('email: '):
            out['server'] = json.loads(text[len('email: '):]).split('@', 1)[-1]
        elif top == 'extensions' and indent == 4 and text.startswith('listen: '):
            out['listen'] = json.loads(text[len('listen: '):])
        elif top == 'extensions' and indent == 4 and text.startswith('port: '):
            out['port'] = text[len('port: '):]
    except ValueError:
        continue
out['groups'] = groups
print(json.dumps(out))
PY
}

# OX_GROUPS: the groups of the devices with a login of their own, as JSON (device-logins.sh sets it); unset, the
# configuration keeps the ones it has.
OX_GROUPS=''

# ox_config: Oxidized's configuration. The installer writes it while it is the one it wrote last (an admin who
# changes it takes it over: the run then only warns about what it would set).
ox_config() {
    local text user=$DEVICE_USER password=$DEVICE_PASSWORD enable=$DEVICE_ENABLE_PASSWORD groups=$OX_GROUPS
    if [[ -z $groups ]]; then groups=$(json_field "$(ox_config_values)" groups); fi
    [[ -n $groups ]] || groups='[]'
    if [[ -z $user && -f $OX_CONF ]]; then
        # no login given this time: the one the configuration has stays
        local current
        current=$(python3 -I - "$OX_CONF" <<'PY'
import json, sys
values = {}
for line in open(sys.argv[1], encoding='utf-8'):
    for key, name in (('username: ', 'user'), ('password: ', 'password'), ('  enable: ', 'enable')):
        if line.startswith(key):
            try:
                values[name] = json.loads(line[len(key):])
            except ValueError:
                pass
print(json.dumps(values))
PY
) || current='{}'
        user=$(json_field "$current" user); password=$(json_field "$current" password); enable=$(json_field "$current" enable)
    fi
    text=$(I_USER=$user I_PASSWORD=$password I_ENABLE=$enable I_URL=$LIBRENMS_RESOLVED I_TOKEN=$OX_TOKEN I_GROUPS=$groups \
        I_LISTEN=$OXIDIZED_LISTEN I_PORT=$OXIDIZED_PORT I_DIR=$OX_DIR I_SERVER=$SERVER_NAME python3 -I - <<'PY'
import json, os, re
e = os.environ
q = json.dumps  # a JSON string is a YAML double-quoted scalar
# the devices with a login of their own (device-logins.sh): a group each, which LibreNMS gives Oxidized for it
groups = []
for g in json.loads(e['I_GROUPS']):
    if not re.fullmatch(r'login-[0-9]+', g['name']):
        raise SystemExit('ox_config: %r is not a group of device-logins.sh' % g['name'])
    note = ''.join(c if c.isprintable() else '?' for c in g.get('device') or '')[:200]
    groups += (['  # ' + note] if note else []) + ['  ' + g['name'] + ':', '    username: ' + q(g['username']),
                                                   '    password: ' + q(g['password']), '    vars:', '      enable: ' + q(g['enable'])]
if groups:
    groups = ['# the devices with a login of their own (install/rocky/device-logins.sh): LibreNMS gives Oxidized their group',
              'groups:'] + groups
print('\n'.join([
    '---',
    '# Oxidized for LibreNMS (install/rocky/install.sh): the devices come from LibreNMS\' API, their versions go to git,',
    '# the REST API (oxidized-web) is what LibreNMS and its Ansible plugin use. The installer rewrites this file while it',
    '# is the one it wrote; once you change it, it is yours.',
    'username: ' + q(e['I_USER']),
    'password: ' + q(e['I_PASSWORD']),
    'resolve_dns: true',
    'interval: 3600',
    'debug: false',
    'run_once: false',
    'threads: 30',
    'timeout: 20',
    'timelimit: 300',
    'retries: 3',
    '# a backup the Ansible plugin asks for starts at once (its README: Oxidized and maintenance windows)',
    'next_adds_job: true',
    'vars:',
    '  enable: ' + q(e['I_ENABLE']),
    '  # net-ssh asks an RSA key for its SHA-1 signature (ssh-rsa) first, which Rocky\'s crypto policy refuses to',
    '  # verify: SHA-2 first (the others follow, ssh-rsa last for devices that have nothing else)',
    '  ssh_host_key: ' + q(','.join(['rsa-sha2-512', 'rsa-sha2-256', 'ssh-ed25519', 'ecdsa-sha2-nistp256',
                                     'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521'])),
] + groups + [
    'pid: ' + q(e['I_DIR'] + '/pid'),
    'crash:',
    '  directory: ' + q(e['I_DIR'] + '/crashes'),
    '  hostnames: false',
    'stats:',
    '  history_size: 10',
    'input:',
    '  default: ssh',
    '  debug: false',
    '  ssh:',
    '    secure: true',
    '  utf8_encoded: true',
    'output:',
    '  default: git',
    '  git:',
    '    user: Oxidized',
    '    email: ' + q('oxidized@' + e['I_SERVER']),
    '    repo: ' + q(e['I_DIR'] + '/configs.git'),
    '    # every group in that one repository (as <group>/<device>), not a repository of its own',
    '    single_repo: true',
    'source:',
    '  default: http',
    '  debug: false',
    '  http:',
    '    url: ' + q(e['I_URL'] + '/api/v0/oxidized'),
    '    secure: true',
    '    map:',
    '      name: hostname',
    '      model: os',
    '      group: group',
    '      ip: ip',
    '    headers:',
    '      X-Auth-Token: ' + q(e['I_TOKEN']),
    'extensions:',
    '  oxidized-web:',
    '    load: true',
    '    listen: ' + q(e['I_LISTEN']),
    '    port: ' + e['I_PORT'],
]))
PY
)
    if [[ $CHECK == 0 ]]; then as oxidized install -d -m 700 "$OX_HOME/.config" "$OX_DIR"; fi
    local last current
    last=$(state_get oxidized_config || true)
    current=$( [[ -f $OX_CONF ]] && sha256sum "$OX_CONF" | cut -d' ' -f1 || true)
    if [[ -n $current && $current != "$last" ]]; then
        if [[ $(sha256sum <<<"$text" | cut -d' ' -f1) != "$current" ]]; then
            warn "$OX_CONF was changed by hand: left as it is (check input.ssh.secure is true, vars.ssh_host_key, its username, password, source url and token, and extensions.oxidized-web)"
        fi
        return 0
    fi
    if put_text "$text
" "$OX_CONF" oxidized:oxidized 600 "write $OX_CONF"; then
        state_set oxidized_restart 1
        [[ $CHECK == 0 ]] && state_set oxidized_config "$(sha256sum "$OX_CONF" | cut -d' ' -f1)"
    fi
    return 0
}

ox_service() {
    if put_text "$(render oxidized.service OXIDIZED_BIN="$(ox_bin)" OXIDIZED_PID="$OX_DIR/pid")" /etc/systemd/system/oxidized.service root:root 644 \
        "add the service oxidized (/etc/systemd/system/oxidized.service)"; then
        [[ $CHECK == 0 ]] && run systemctl daemon-reload
        state_set oxidized_restart 1
    fi
    port_check "$OXIDIZED_PORT" "Oxidized's REST API" "free it, or give Oxidized another one (--oxidized-port)" oxidized ruby
    restart_if_pending oxidized_restart oxidized
    service_enable_start oxidized
}

# ox_librenms_settings: LibreNMS' Oxidized settings (its documentation, Extensions/Oxidized): on, at our Oxidized,
# with versioning (the git output), and a reload of Oxidized's nodes when a device is added.
ox_librenms_settings() {
    local setting value current
    for setting in "oxidized.enabled=true" "oxidized.url=$OX_URL" "oxidized.features.versioning=true" "oxidized.reload_nodes=true"; do
        value=${setting#*=}; setting=${setting%%=*}
        current=$(lnms config:get --dump "$setting" 2>>"$LOG_FILE" | tail -n 1 || true)
        current=${current//\"/}; current=${current//\\\//\/}
        if [[ $current == "$value" ]]; then detail "LibreNMS: $setting is $value"; continue; fi
        act "set LibreNMS' $setting to $value" lnms config:set "$setting" "$value"
    done
}

# ox_verify: Oxidized's REST API answers with the devices of LibreNMS. Oxidized does not start while LibreNMS has no
# device it can back up: its service then tries again every minute.
ox_verify() {
    [[ $CHECK == 1 ]] && { OX_STATUS="(checked by a run)"; return 0; }
    local devices; devices=$(curl -s --max-time 15 --noproxy '*' --config - <<<"url = \"$LIBRENMS_RESOLVED/api/v0/oxidized\"
header = \"X-Auth-Token: $OX_TOKEN\"" | python3 -I -c 'import json,sys
try: print(len(json.load(sys.stdin)))
except Exception: print("?")' || echo '?')
    if [[ $devices == 0 ]]; then
        OX_STATUS="waits for LibreNMS' first device (Oxidized does not start without one; its service tries again every minute)"
        info "Oxidized $OX_STATUS"
        return 0
    fi
    if wait_http "$OX_URL/nodes.json" 90; then
        local nodes; nodes=$(curl -s --max-time 15 --noproxy '*' "$OX_URL/nodes.json" | python3 -I -c 'import json,sys
try: print(len(json.load(sys.stdin)))
except Exception: print("?")' || echo '?')
        OX_STATUS="answers, $nodes of LibreNMS' $devices devices"
        info "Oxidized $OX_STATUS"
    else
        OX_STATUS="does not answer yet (journalctl -u oxidized says why)"
        warn "Oxidized $OX_STATUS"
    fi
}
OX_STATUS=''
