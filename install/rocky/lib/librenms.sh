# shellcheck shell=bash
# install/rocky/lib/librenms.sh: part 1, LibreNMS. When the server has none (/opt/librenms), it is installed as
# LibreNMS' install documentation does it on Rocky Linux 9 and 10 (doc/Installation/Install-LibreNMS.md: PHP from
# Remi's module stream, PHP-FPM, nginx, MariaDB with LibreNMS' settings, net-snmp, fping, its Python modules, the
# librenms user, the cron jobs, the scheduler, logrotate, SELinux and firewalld), with what the web installer does
# (.env, the database, the first admin). A LibreNMS installed otherwise is never changed: only its version is checked.

LNMS_DIR=/opt/librenms
LNMS_MODE=''      # new: no /opt/librenms; ours: set up by the installer (maybe not finished); existing: installed otherwise
LNMS_VERSION=''
LNMS_INSTALLED_NOW=0

librenms_version_of() {
    local f=$1/LibreNMS/Util/Version.php
    [[ -f $f ]] || return 0
    sed -n "s/.*const VERSION = '\([0-9][0-9.]*\)'.*/\1/p" "$f" | sed -n 1p
}

librenms_detect() {
    local mark; mark=$(state_get librenms || true)
    if [[ ! -e $LNMS_DIR ]]; then
        LNMS_MODE=new
    elif [[ $mark == installing || $mark == installed ]]; then
        LNMS_MODE=ours
    else
        LNMS_MODE=existing
    fi
    LNMS_VERSION=$(librenms_version_of "$LNMS_DIR")
    log "LibreNMS: $LNMS_MODE${LNMS_VERSION:+, version $LNMS_VERSION}"
}

# version_ge A B: whether version A is B or later.
version_ge() { [[ $(printf '%s\n%s\n' "$1" "$2" | sort -V | sed -n 1p) == "$2" ]]; }

# librenms_supported VERSION: whether the plugins support this LibreNMS (26.10 or a later 26.x).
librenms_supported() {
    [[ -n $1 ]] && version_ge "$1" "$LIBRENMS_MIN" && [[ ${1%%.*} -le $LIBRENMS_MAX_MAJOR ]]
}

librenms_unsupported() {
    local v=${1:-unknown}
    if [[ $v != unknown ]] && version_ge "$v" "$((LIBRENMS_MAX_MAJOR + 1))"; then
        die "LibreNMS $v is newer than the plugins support (LibreNMS $LIBRENMS_MAX_MAJOR.x from ${LIBRENMS_MIN%.0}; they were developed and tested against 26.10)" \
            "use a release of the plugins made for LibreNMS ${v%%.*}, or a LibreNMS ${LIBRENMS_MAX_MAJOR}.x"
    fi
    die "LibreNMS $v is older than the plugins support (${LIBRENMS_MIN%.0} or a later ${LIBRENMS_MAX_MAJOR}.x)" \
        "update LibreNMS first (as the librenms user in $LNMS_DIR: ./daily.sh, then ./validate.php), then run the installer again"
}

# librenms_require: stop a part that needs LibreNMS when there is none (or one the plugins do not support).
librenms_require() {
    if [[ ! -d $LNMS_DIR ]]; then
        if [[ $CHECK == 1 && ${WANT[librenms]} == 1 ]]; then return 0; fi
        die "there is no LibreNMS in $LNMS_DIR" "run the installer without --skip librenms (or --only), so it installs LibreNMS first"
    fi
    if [[ $(state_get librenms || true) == installing ]]; then
        if [[ $CHECK == 1 && ${WANT[librenms]} == 1 ]]; then return 0; fi
        die "the installation of LibreNMS has not finished" "run the installer without --skip librenms, so it finishes it"
    fi
    librenms_supported "$LNMS_VERSION" || librenms_unsupported "$LNMS_VERSION"
}

# lnms ARGS...: LibreNMS' lnms as the librenms user.
lnms() { in_dir "$LNMS_DIR" as librenms "$LNMS_DIR/lnms" "$@"; }

# helper COMMAND JSON: librenms_helper.php as the librenms user, the JSON request on stdin; its JSON answer goes to
# HELPER_OUT (not printed: callers read it without a subshell, so that a refusal stops the run).
HELPER_OUT=''
helper() {
    local command=$1 json=$2 rc=0
    log "+ librenms_helper.php $command (request not logged)"
    HELPER_OUT=$(cd "$LNMS_DIR" && as librenms php "$WORK/lib/librenms_helper.php" "$command" 2>>"$LOG_FILE" <<<"$json") || rc=$?
    if [[ $rc != 0 ]]; then
        local error; error=$(json_field "$HELPER_OUT" error 2>/dev/null || true)
        die "LibreNMS refused: ${error:-librenms_helper.php $command failed (exit code $rc)}" "${HELPER_HINT:-read the log, and logs/librenms.log in $LNMS_DIR}"
    fi
    if ! python3 -I -c 'import json,sys; json.load(sys.stdin)' <<<"$HELPER_OUT" 2>/dev/null; then
        log "librenms_helper.php $command answered: ${HELPER_OUT:0:2000}"
        die "LibreNMS answered librenms_helper.php $command with something else than JSON (the log has it)" "read the log, and logs/librenms.log in $LNMS_DIR"
    fi
}

# json_field JSON PATH: a field of a JSON document (PATH: a.b.c), as text ('' for null, JSON for objects). The document
# (it may hold a token or a password) goes to python on stdin: a command line is visible to every user (ps).
json_field() {
    python3 -I -c 'import json,sys
d=json.load(sys.stdin)
for k in sys.argv[1].split("."):
    d = d.get(k) if isinstance(d, dict) else (d[int(k)] if isinstance(d, list) and k.isdigit() and int(k) < len(d) else None)
print("" if d is None else (json.dumps(d) if isinstance(d, (dict, list)) else ("true" if d is True else "false" if d is False else d)))' "$2" <<<"$1"
}

# json_object KEY VALUE...: a JSON object of strings (VALUE "@json:..." is put in as JSON). The values (passwords,
# tokens) go to python on stdin, NUL-separated by printf (a shell builtin: no command line), never as its arguments.
json_object() {
    printf '%s\0' "$@" | python3 -I -c 'import json,sys
a=[x.decode("utf-8", "surrogateescape") for x in sys.stdin.buffer.read().split(b"\0")[:-1]]; o={}
for i in range(0, len(a) - 1, 2):
    v=a[i+1]
    o[a[i]] = json.loads(v[6:]) if v.startswith("@json:") else v
print(json.dumps(o))'
}

# ---------------------------------------------------------------------------------------------------- the part

part_librenms() {
    if [[ $LNMS_MODE == existing ]]; then
        part_header librenms "LibreNMS ${LNMS_VERSION:-(version unknown)} in $LNMS_DIR, installed otherwise: left as it is"
        librenms_check_existing
        return
    fi
    if [[ $LNMS_MODE == new ]]; then
        part_header librenms "LibreNMS: none on this server, so it is installed (as LibreNMS documents it for Rocky Linux $OS_MAJOR)"
    else
        part_header librenms "LibreNMS ${LNMS_VERSION}: set up by an earlier run of the installer, checking it"
    fi
    [[ $LNMS_MODE == new ]] && LNMS_INSTALLED_NOW=1
    lnms_repos
    lnms_packages
    lnms_user
    lnms_source
    lnms_permissions
    lnms_python
    lnms_composer
    lnms_php_settings
    lnms_mariadb
    lnms_database
    lnms_env
    lnms_php_fpm
    lnms_nginx
    lnms_selinux
    firewall_open http https
    lnms_completion
    lnms_snmpd
    lnms_cron_scheduler
    lnms_logrotate
    lnms_migrate
    lnms_admin
    lnms_validate
    if [[ $CHECK == 0 ]]; then
        state_set librenms installed
        LNMS_MODE=ours
        LNMS_VERSION=$(librenms_version_of "$LNMS_DIR")
    fi
    if [[ $CHECK == 1 && $LNMS_MODE == new ]]; then
        summary_add "LibreNMS (from $LIBRENMS_SOURCE): would be installed, on http://$SERVER_NAME/"
    else
        summary_add "LibreNMS ${LNMS_VERSION}: http://$SERVER_NAME/ (admin: $ADMIN_USER)$( [[ $LNMS_INSTALLED_NOW == 1 ]] && echo ', installed by this run' )"
    fi
}

# A LibreNMS installed otherwise: its version (the plugins need 26.10 or a later 26.x) and what the plugins need of it.
librenms_check_existing() {
    librenms_supported "$LNMS_VERSION" || librenms_unsupported "$LNMS_VERSION"
    info "LibreNMS $LNMS_VERSION: the plugins support it"
    local php
    php=$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)
    if [[ -z $php ]]; then die "the php command of this server does not run" "LibreNMS needs PHP 8.2 or later on the command line"; fi
    version_ge "$php" 8.2 || die "PHP $php is older than the plugins support (8.2 or later)" "update PHP as LibreNMS' documentation says, then run the installer again"
    local ext modules; modules=$(php -m 2>/dev/null || true)
    for ext in curl mbstring; do
        grep -qx "$ext" <<<"$modules" || die "PHP has no $ext extension, which the Ansible plugin needs" "install it (dnf install php-$ext), then run the installer again"
    done
    if ! systemctl is-active --quiet librenms-scheduler.timer && ! grep -qs 'schedule:run' /etc/cron.d/* /var/spool/cron/librenms; then
        warn "LibreNMS' scheduler does not seem to run (no librenms-scheduler.timer, no schedule:run cron job): the plugins' background jobs need it (see ./validate.php)"
    fi
    if [[ ! -f $LNMS_DIR/.env ]]; then die "$LNMS_DIR has no .env: this LibreNMS is not set up" "finish LibreNMS' installation first"; fi
    summary_add "LibreNMS $LNMS_VERSION (installed otherwise): left as it is"
}

# ---------------------------------------------------------------------------------------------------- the steps

lnms_repos() {
    dnf_install "EPEL" epel-release
    dnf_install "dnf's plugins" dnf-plugins-core
    if [[ $PHP_STREAM == remi-* ]]; then
        if ! rpm -q remi-release >/dev/null 2>&1; then
            FAIL_HINT="check that this server reaches https://rpms.remirepo.net, or use --php-stream for another PHP" \
                act "add Remi's repository (PHP for Enterprise Linux, as LibreNMS documents)" dnf -y install "https://rpms.remirepo.net/enterprise/remi-release-$OS_MAJOR.rpm"
        fi
        remi_https_mirrors
    fi
    local stream; stream=$(module_stream php)
    if [[ $PHP_STREAM == remi-* || $OS_MAJOR == 9 ]]; then
        if [[ $stream != "$PHP_STREAM" ]]; then
            if [[ -n $stream ]] && rpm -q php-common >/dev/null 2>&1; then
                die "PHP is installed from the php:$stream module stream, not php:$PHP_STREAM" "give --php-stream $stream to keep it, or switch it yourself (dnf module switch-to php:$PHP_STREAM)"
            fi
            act "reset dnf's php module" dnf -y module reset php
            act "enable the php:$PHP_STREAM module stream" dnf -y module enable "php:$PHP_STREAM"
        fi
    fi
}

# Remi's repositories list their mirrors over plain HTTP by default; its repository files offer an HTTPS list
# (httpsmirror), which also works behind proxies that only pass HTTPS.
remi_https_mirrors() {
    [[ $CHECK == 1 && ! -f /etc/yum.repos.d/remi-safe.repo ]] && return 0
    local repo file current want
    for repo in remi-modular remi-safe; do
        file=/etc/yum.repos.d/$repo.repo
        [[ -f $file ]] || continue
        current=$(awk -v s="[$repo]" '$0 == s {f = 1; next} /^\[/ {f = 0} f && /^mirrorlist=/ {sub(/^mirrorlist=/, ""); print; exit}' "$file")
        want="https://rpms.remirepo.net/enterprise/\$releasever_major/${repo#remi-}/\$basearch/httpsmirror"
        if [[ $current != "$want" ]]; then
            act "use Remi's HTTPS mirror list for $repo" dnf config-manager --save --setopt="$repo.mirrorlist=$want"
        fi
    done
}

lnms_packages() {
    local pkgs=(acl bash-completion cronie fping gcc git mariadb-server mtr net-snmp net-snmp-utils nmap php-fpm php-cli php-common
        php-curl php-gd php-gmp php-json php-mbstring php-process php-snmp php-xml php-zip php-mysqlnd policycoreutils-python-utils
        python3 python3-devel python3-PyMySQL python3-redis python3-pip python3-systemd rrdtool unzip nginx)
    if [[ $OS_MAJOR == 9 ]]; then pkgs+=(python3-memcached); else pkgs+=(python3-setuptools); fi
    dnf_install "LibreNMS' packages (PHP $PHP_STREAM, PHP-FPM, nginx, MariaDB, net-snmp, fping, rrdtool, Python)" "${pkgs[@]}"
}

lnms_user() {
    if getent passwd librenms >/dev/null; then detail "the librenms user exists"; return 0; fi
    act "add the librenms user (home $LNMS_DIR)" useradd librenms -d "$LNMS_DIR" -M -r -s /bin/bash
}

# /opt/librenms from a Git URL (as LibreNMS documents), a directory or a tarball (offline mode). It is made in
# /opt/.librenms.partial and moved in place when complete, so /opt/librenms never holds half a copy.
lnms_source() {
    if [[ -d $LNMS_DIR ]]; then detail "$LNMS_DIR is there"; return 0; fi
    would "get LibreNMS from $LIBRENMS_SOURCE into $LNMS_DIR" && return 0
    state_set librenms installing
    local partial=/opt/.librenms.partial
    rm -rf "$partial"
    if [[ $LIBRENMS_SOURCE == *://* ]]; then
        [[ -z $OFFLINE_DIR ]] || die "--offline: LibreNMS would come from $LIBRENMS_SOURCE" "give a local copy (--librenms-source DIR|FILE, or librenms.tar.gz in $OFFLINE_DIR)"
        FAIL_HINT="check that this server reaches $LIBRENMS_SOURCE, or give a local copy (--librenms-source)" run git clone "$LIBRENMS_SOURCE" "$partial"
    elif [[ -d $LIBRENMS_SOURCE ]]; then
        mkdir -p "$partial"
        run in_dir "$LIBRENMS_SOURCE" tar -cf "$WORK/librenms.tar" .
        run tar -xf "$WORK/librenms.tar" -C "$partial"
        rm -f "$WORK/librenms.tar"
    else
        mkdir -p "$partial"
        run tar -xf "$LIBRENMS_SOURCE" -C "$partial"
        local entries; entries=$(find "$partial" -mindepth 1 -maxdepth 1 | wc -l)
        if [[ $entries == 1 && ! -f $partial/composer.json ]]; then
            local top; top=$(find "$partial" -mindepth 1 -maxdepth 1)
            mv "$top" "$partial.top" && rmdir "$partial" && mv "$partial.top" "$partial"
        fi
    fi
    grep -qs '"name": "librenms/librenms"' "$partial/composer.json" && [[ -f $partial/LibreNMS/Util/Version.php ]] \
        || die "$LIBRENMS_SOURCE does not hold LibreNMS" "give a LibreNMS clone, directory or tarball"
    local version; version=$(librenms_version_of "$partial")
    librenms_supported "$version" || { rm -rf "$partial"; librenms_unsupported "$version"; }
    if [[ $LIBRENMS_SOURCE != *://* && ! -f $partial/vendor/autoload.php ]]; then
        rm -rf "$partial"
        die "$LIBRENMS_SOURCE has no vendor/ (LibreNMS' PHP dependencies, which come from GitHub)" \
            "make the copy on a machine that reaches GitHub: git clone https://github.com/librenms/librenms.git && cd librenms && ./scripts/composer_wrapper.php install --no-dev"
    fi
    # what belongs to the server a copy was made on: its .env (APP_KEY), config, caches, logs, sessions, graphs
    rm -f "$partial/.env" "$partial/config.php" "$partial/composer.plugins.json" "$partial"/bootstrap/cache/*.php
    local d
    for d in storage/framework/sessions storage/framework/views storage/framework/cache/data rrd; do
        [[ -d $partial/$d ]] && find "$partial/$d" -mindepth 1 ! -name .gitignore -delete
    done
    [[ -d $partial/logs ]] && find "$partial/logs" -type f -name '*.log' -delete
    run mv "$partial" "$LNMS_DIR"
    state_set librenms_perms pending
    info "LibreNMS $version is in $LNMS_DIR"
}

lnms_permissions() {
    [[ -d $LNMS_DIR ]] || return 0
    [[ $(state_get librenms_perms || true) == yes ]] && { detail "the permissions of $LNMS_DIR were set"; return 0; }
    would "set the owner and permissions of $LNMS_DIR (librenms, as LibreNMS documents)" && return 0
    run chown -R librenms:librenms "$LNMS_DIR"
    run chmod 771 "$LNMS_DIR"
    local dirs=("$LNMS_DIR/rrd" "$LNMS_DIR/logs" "$LNMS_DIR/bootstrap/cache/" "$LNMS_DIR/storage/")
    run setfacl -d -m g::rwx "${dirs[@]}"
    run setfacl -R -m g::rwx "${dirs[@]}"
    state_set librenms_perms yes
}

# LibreNMS' Python modules (requirements.txt): dnf has most of them, pip the rest (what ./validate.php says to do).
lnms_python() {
    if [[ ! -d $LNMS_DIR ]]; then would "install the Python modules of LibreNMS' requirements.txt"; return 0; fi
    if probe as librenms python3 "$LNMS_DIR/scripts/dynamic_check_requirements.py"; then detail "LibreNMS' Python modules are installed"; return 0; fi
    act "install LibreNMS' Python modules from dnf" dnf -y --setopt=strict=0 install python3-psutil python3-dotenv
    [[ $CHECK == 1 ]] && return 0
    if ! probe as librenms python3 "$LNMS_DIR/scripts/dynamic_check_requirements.py"; then
        local args=(-r "$LNMS_DIR/requirements.txt")
        if [[ -n $PIP_WHEELS ]]; then args=(--no-index --find-links "$PIP_WHEELS" "${args[@]}"); fi
        FAIL_HINT="check that this server reaches PyPI, or give local wheels (--pip-wheels)" \
            act "install the rest of LibreNMS' Python modules with pip (requirements.txt)" pip3 install --disable-pip-version-check "${args[@]}"
    fi
}

# composer.phar (when neither LibreNMS nor the server has one), then LibreNMS' PHP dependencies.
lnms_composer() {
    if [[ ! -d $LNMS_DIR ]]; then would "install LibreNMS' PHP dependencies (composer install --no-dev)"; return 0; fi
    if [[ ! -f $LNMS_DIR/composer.phar ]] && ! command -v composer >/dev/null; then
        composer_install "$LNMS_DIR/composer.phar" librenms:librenms
    fi
    if [[ $(state_get librenms_composer || true) == yes && -f $LNMS_DIR/vendor/autoload.php ]]; then
        detail "LibreNMS' PHP dependencies are installed"
        return 0
    fi
    FAIL_HINT="check that this server reaches GitHub and Packagist (or give a LibreNMS copy with its vendor/ as --librenms-source)" \
        act "install LibreNMS' PHP dependencies (composer install --no-dev)" in_dir "$LNMS_DIR" as librenms php scripts/composer_wrapper.php install --no-dev --no-interaction
    state_set librenms_composer yes
}

# composer_install DEST OWNER: composer.phar from --composer-source or getcomposer.org (checked against its SHA-256).
composer_install() {
    local dest=$1 owner=$2
    would "install composer.phar as $dest" && return 0
    local tmp=$WORK/composer.phar
    if [[ -n $COMPOSER_SOURCE ]]; then
        cp "$COMPOSER_SOURCE" "$tmp"
    else
        download https://getcomposer.org/download/latest-stable/composer.phar.sha256 "$WORK/composer.sha256"
        DOWNLOAD_HINT="check that this server reaches getcomposer.org, or give composer.phar with --composer-source" \
            download https://getcomposer.org/download/latest-stable/composer.phar "$tmp" "$(cut -d' ' -f1 "$WORK/composer.sha256")"
    fi
    php "$tmp" --version >>"$LOG_FILE" 2>&1 || die "$tmp is not a working composer.phar" "give composer.phar with --composer-source"
    install -o "${owner%%:*}" -g "${owner##*:}" -m 755 "$tmp" "$dest"
    log "installed $dest"
}

lnms_php_settings() {
    if put_text "; LibreNMS (install/rocky/install.sh): PHP's time zone, as LibreNMS' documentation sets it in php.ini
date.timezone = $TIMEZONE
" /etc/php.d/90-librenms.ini root:root 644 "set PHP's time zone ($TIMEZONE) in /etc/php.d/90-librenms.ini"; then
        state_set php_fpm_restart 1
    fi
}

lnms_mariadb() {
    if put_text "# LibreNMS (install/rocky/install.sh): the settings LibreNMS' documentation asks for
[mariadb]
innodb_file_per_table=1
lower_case_table_names=0
" /etc/my.cnf.d/90-librenms.cnf root:root 644 "set MariaDB's LibreNMS settings in /etc/my.cnf.d/90-librenms.cnf"; then
        state_set mariadb_restart 1
    fi
    port_check 3306 MariaDB "free it (another database server?)" mariadbd mysqld
    restart_if_pending mariadb_restart mariadb
    service_enable_start mariadb
}

# mysql_root SQL: run SQL as MariaDB's root (unix socket, or /root/.my.cnf); the SQL is not logged (passwords).
mysql_root() {
    if ! probe mysql -u root -e 'SELECT 1'; then
        die "MariaDB does not let root in (it asks for a password)" "put root's password in /root/.my.cnf ([client] user=root password=...), then run the installer again"
    fi
    run_input "$1" mysql -u root
}

# db_password_ok USER PASSWORD DATABASE: whether USER logs in to DATABASE with PASSWORD.
db_password_ok() { MYSQL_PWD=$2 probe mysql -u "$1" -h localhost -e 'SELECT 1' "$3"; }

lnms_database() {
    if [[ $CHECK == 1 ]] && ! command -v mysql >/dev/null; then would "create the librenms database and its user"; return 0; fi
    local password
    lnms_env_get DB_PASSWORD
    password=$ENV_VALUE
    [[ -n $password ]] || password=$(secret_for LIBRENMS_DB_PASSWORD)
    if db_password_ok librenms "$password" librenms; then detail "the librenms database and its user are there"; LNMS_DB_PASSWORD=$password; return 0; fi
    would "create the librenms database and its user (utf8mb4, as LibreNMS documents)" && return 0
    mysql_root "CREATE DATABASE IF NOT EXISTS librenms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'librenms'@'localhost' IDENTIFIED BY '$password';
ALTER USER 'librenms'@'localhost' IDENTIFIED BY '$password';
GRANT ALL PRIVILEGES ON librenms.* TO 'librenms'@'localhost';
FLUSH PRIVILEGES;"
    cred_set LIBRENMS_DB_PASSWORD "$password"
    LNMS_DB_PASSWORD=$password
}
LNMS_DB_PASSWORD=''

# lnms_env_get KEY: a value of LibreNMS' .env in ENV_VALUE (empty when unset).
ENV_VALUE=''
lnms_env_get() {
    ENV_VALUE=''
    [[ -f $LNMS_DIR/.env && -f $LNMS_DIR/vendor/autoload.php ]] || return 0
    helper env-get "$(json_object keys "@json:[\"$1\"]")"
    ENV_VALUE=$(json_field "$HELPER_OUT" "values.$1")
}

lnms_env() {
    if [[ ! -f $LNMS_DIR/.env ]]; then would "write the database settings into $LNMS_DIR/.env"; return 0; fi
    local database password
    lnms_env_get DB_DATABASE; database=$ENV_VALUE
    lnms_env_get DB_PASSWORD; password=$ENV_VALUE
    if [[ -n $database && -n $password ]]; then
        [[ $database == librenms ]] || warn "$LNMS_DIR/.env names the database $database: left as it is"
        detail "$LNMS_DIR/.env has the database settings"
    elif ! would "write the database settings into $LNMS_DIR/.env"; then
        # LibreNMS' EnvHelper fills the settings that are empty, as the web installer does
        helper env-set "$(json_object set "@json:$(json_object DB_HOST localhost DB_DATABASE librenms DB_USERNAME librenms DB_PASSWORD "$LNMS_DB_PASSWORD")" unset '@json:["INSTALL"]')"
    fi
    # .env holds the database password and APP_KEY: LibreNMS (its PHP-FPM pool, cron jobs, scheduler) reads it as librenms
    local mode; mode=$(stat -c %a "$LNMS_DIR/.env")
    if [[ $mode != 640 && $mode != 600 ]]; then act "make $LNMS_DIR/.env readable by librenms only (mode $mode -> 640)" chmod 640 "$LNMS_DIR/.env"; fi
}

lnms_php_fpm() {
    local pool=/etc/php-fpm.d/librenms.conf
    if [[ -f $pool ]]; then
        detail "$pool is there"
    elif [[ -f /etc/php-fpm.d/www.conf ]]; then
        # LibreNMS' documentation: a copy of www.conf, as [librenms], run as librenms, listening on its own socket
        local text
        text=$(sed -e 's/^\[www\]/[librenms]/' -e 's/^user = .*/user = librenms/' -e 's/^group = .*/group = librenms/' \
            -e 's|^listen = .*|listen = /run/php-fpm-librenms.sock|' /etc/php-fpm.d/www.conf)
        put_text "$text
" "$pool" root:root 644 "add the PHP-FPM pool librenms ($pool)" && state_set php_fpm_restart 1
    else
        would "add the PHP-FPM pool librenms ($pool)"
    fi
    restart_if_pending php_fpm_restart php-fpm
    service_enable_start php-fpm
}

lnms_nginx() {
    local conf=/etc/nginx/conf.d/librenms.conf listen6=''
    if [[ -f /proc/net/if_inet6 ]]; then listen6=' listen      [::]:80;'; fi
    if [[ -f $conf ]] && ! grep -q 'install/rocky/install.sh' "$conf"; then
        detail "$conf was changed by hand: left as it is"
    elif put_text "$(render nginx-librenms.conf SERVER_NAME="$SERVER_NAME" LISTEN6="$listen6")" "$conf" root:root 644 "add LibreNMS' site to nginx ($conf, server_name $SERVER_NAME)"; then
        state_set nginx_reload 1
    fi
    # LibreNMS' documentation: delete the server section of nginx.conf (its default site); kept as nginx.conf.librenms-installer
    if [[ -f /etc/nginx/nginx.conf ]] && python3 -I "$WORK/lib/nginx_default_server.py" --check /etc/nginx/nginx.conf; then
        if ! would "remove nginx's default site from /etc/nginx/nginx.conf (kept as /etc/nginx/nginx.conf.librenms-installer)"; then
            [[ -f /etc/nginx/nginx.conf.librenms-installer ]] || cp -p /etc/nginx/nginx.conf /etc/nginx/nginx.conf.librenms-installer
            run python3 -I "$WORK/lib/nginx_default_server.py" /etc/nginx/nginx.conf
            state_set nginx_reload 1
        fi
    fi
    if [[ $CHECK == 0 && -x /usr/sbin/nginx ]]; then FAIL_HINT="fix the nginx configuration (nginx -t says what is wrong)" run nginx -t; fi
    port_check 80 "LibreNMS' web server (nginx)" "stop the web server that holds it (LibreNMS' documentation uses nginx alone on this server)" nginx
    restart_if_pending nginx_reload nginx reload
    service_enable_start nginx
}

# SELinux, as LibreNMS' documentation sets it (only when SELinux is enforcing or permissive).
lnms_selinux() {
    if ! selinux_active; then detail "SELinux is disabled: no contexts to set"; return 0; fi
    [[ $(state_get librenms_selinux || true) == yes ]] && { detail "the SELinux contexts of LibreNMS were set"; return 0; }
    would "set LibreNMS' SELinux contexts and booleans, and add the http_fping policy module" && return 0
    local spec
    for spec in "httpd_sys_content_t|$LNMS_DIR/html(/.*)?" "httpd_sys_rw_content_t|$LNMS_DIR/(rrd|storage)(/.*)?" \
        "httpd_log_t|$LNMS_DIR/logs(/.*)?" "httpd_cache_t|$LNMS_DIR/cache(/.*)?" "bin_t|$LNMS_DIR/librenms-service.py"; do
        selinux_fcontext "${spec%%|*}" "${spec#*|}"
    done
    run restorecon -RF "$LNMS_DIR"
    run setsebool -P httpd_can_sendmail=1
    run setsebool -P httpd_execmem=1
    run setsebool -P httpd_can_network_connect_db=1
    [[ -f $LNMS_DIR/.env ]] && run chcon -t httpd_sys_rw_content_t "$LNMS_DIR/.env"
    local modules; modules=$(semodule -l 2>/dev/null || true)
    if ! grep -q '^http_fping' <<<"$modules"; then
        local d=$WORK/selinux
        mkdir -p "$d"
        cp "$FILES_DIR/http_fping.tt" "$d/"
        run checkmodule -M -m -o "$d/http_fping.mod" "$d/http_fping.tt"
        run semodule_package -o "$d/http_fping.pp" -m "$d/http_fping.mod"
        run semodule -i "$d/http_fping.pp"
    fi
    state_set librenms_selinux yes
}

# selinux_fcontext TYPE SPEC: a file context rule (added, or changed when it is there with another type).
selinux_fcontext() {
    local rules; rules=$(semanage fcontext -l 2>/dev/null || true)
    if grep -qF "$2 " <<<"$rules"; then
        run semanage fcontext -m -t "$1" "$2"
    else
        run semanage fcontext -a -t "$1" "$2"
    fi
}

lnms_completion() {
    if [[ ! -L /usr/bin/lnms && ! -e /usr/bin/lnms ]]; then act "link /usr/bin/lnms" ln -s "$LNMS_DIR/lnms" /usr/bin/lnms; fi
    if [[ -f $LNMS_DIR/misc/lnms-completion.bash ]]; then
        put_file "$LNMS_DIR/misc/lnms-completion.bash" /etc/bash_completion.d/lnms-completion.bash root:root 644 "add lnms' bash completion" || true
    fi
}

# snmpd on this server, as LibreNMS' documentation sets it up (v2c, a generated community), when its configuration
# is still the package's.
lnms_snmpd() {
    local conf=/etc/snmp/snmpd.conf
    if [[ ! -f $LNMS_DIR/snmpd.conf.example ]]; then would "configure snmpd (LibreNMS' snmpd.conf.example, a generated community)"; return 0; fi
    if [[ $(state_get snmpd || true) != yes ]]; then
        local verify; verify=$(rpm -V net-snmp 2>/dev/null || true)
        if [[ -f $conf ]] && grep -q " $conf\$" <<<"$verify"; then
            warn "$conf was changed on this server: left as it is (LibreNMS' documentation replaces it with snmpd.conf.example)"
        else
            local community text; community=$(secret_for SNMP_COMMUNITY 20)
            text=$(<"$LNMS_DIR/snmpd.conf.example")
            # the community goes in by the shell (sed would have it on its command line, visible to every user)
            text=${text//RANDOMSTRINGGOESHERE/$community}
            if put_text "$text
" "$conf" root:root 600 "configure snmpd (LibreNMS' snmpd.conf.example, community in $CRED_FILE)"; then
                state_set snmpd_restart 1
            fi
        fi
        state_set snmpd yes
    fi
    if [[ ! -x /usr/bin/distro ]]; then
        if [[ -n $DISTRO_SOURCE ]]; then
            act "install /usr/bin/distro (librenms-agent's distro script, from $DISTRO_SOURCE)" install -m 755 "$DISTRO_SOURCE" /usr/bin/distro
        elif [[ -n $OFFLINE_DIR ]]; then
            warn "no distro script in $OFFLINE_DIR: snmpd's 'extend distro' has none (LibreNMS then shows less about this server's OS)"
        elif ! would "install /usr/bin/distro (librenms-agent's distro script, from GitHub)"; then
            download https://raw.githubusercontent.com/librenms/librenms-agent/master/snmp/distro "$WORK/distro"
            install -m 755 "$WORK/distro" /usr/bin/distro
        fi
    fi
    restart_if_pending snmpd_restart snmpd
    service_enable_start snmpd
}

lnms_cron_scheduler() {
    [[ -d $LNMS_DIR/dist ]] || { would "add LibreNMS' cron jobs and its scheduler timer"; return 0; }
    put_file "$LNMS_DIR/dist/librenms.cron" /etc/cron.d/librenms root:root 644 "add LibreNMS' cron jobs (/etc/cron.d/librenms)" || true
    local unit changed=0
    for unit in librenms-scheduler.service librenms-scheduler.timer; do
        put_file "$LNMS_DIR/dist/$unit" "/etc/systemd/system/$unit" root:root 644 "add $unit" && changed=1
    done
    if [[ $changed == 1 && $CHECK == 0 ]]; then run systemctl daemon-reload; fi
    service_enable_start crond
    service_enable_start librenms-scheduler.timer
}

lnms_logrotate() {
    [[ -f $LNMS_DIR/misc/librenms.logrotate ]] || { would "add LibreNMS' logrotate configuration"; return 0; }
    put_file "$LNMS_DIR/misc/librenms.logrotate" /etc/logrotate.d/librenms root:root 644 "add LibreNMS' logrotate configuration" || true
}

# The database tables (lnms migrate --seed, as the web installer does), and config.php. Laravel would load LibreNMS'
# schema dump (database/schema/mysql-schema.sql) with the mysql client, the database password on its command line
# (visible to every user): a schema path that does not exist makes it run the migrations themselves (a few seconds).
lnms_migrate() {
    [[ -f $LNMS_DIR/artisan ]] || { would "create LibreNMS' database tables (migrate --seed)"; return 0; }
    if [[ $(state_get librenms_db || true) != yes ]]; then
        local args=(migrate --seed --force --no-interaction "--schema-path=$WORK/no-schema-dump.sql")
        act "create LibreNMS' database tables (lnms migrate --seed)" lnms "${args[@]}"
        state_set librenms_db yes
    else
        detail "LibreNMS' database tables were created"
    fi
    if [[ ! -f $LNMS_DIR/config.php && -f $LNMS_DIR/config.php.default ]]; then
        act "add $LNMS_DIR/config.php (from config.php.default, as the web installer does)" install -o librenms -g librenms -m 644 "$LNMS_DIR/config.php.default" "$LNMS_DIR/config.php"
    fi
}

# The first admin (also the one who gets the Ansible plugin's permissions).
lnms_admin() {
    [[ -f $LNMS_DIR/artisan ]] || { would "add the LibreNMS admin $ADMIN_USER"; return 0; }
    librenms_admin_ensure
}

# librenms_admin_ensure: the admin of --admin-user, created when missing (password: --admin-password or generated).
librenms_admin_ensure() {
    if [[ $CHECK == 1 ]] && [[ $(state_get librenms_db || true) != yes && $LNMS_MODE != existing ]]; then
        would "add the LibreNMS admin $ADMIN_USER"
        return 0
    fi
    helper user-get "$(json_object username "$ADMIN_USER")"
    if [[ $(json_field "$HELPER_OUT" exists) == true ]]; then
        [[ $(json_field "$HELPER_OUT" admin) == true ]] || die "the LibreNMS user $ADMIN_USER exists and is not an admin" "name an admin with --admin-user, or make $ADMIN_USER an admin"
        detail "the LibreNMS admin $ADMIN_USER exists"
        return 0
    fi
    would "add the LibreNMS admin $ADMIN_USER" && return 0
    local password=$ADMIN_PASSWORD
    if [[ -z $password ]]; then password=$(secret_for ADMIN_PASSWORD 20); fi
    helper user-ensure "$(json_object username "$ADMIN_USER" password "$password" roles '@json:["admin"]' email "$ADMIN_EMAIL" realname "LibreNMS admin")"
}

# ./validate.php: what it still reports. A server without devices or a fresh install has a few warnings.
lnms_validate() {
    [[ -f $LNMS_DIR/validate.php && $CHECK == 0 ]] || return 0
    # the scheduler's heartbeat (a task it runs every five minutes) and the poller cron job, once now: validate.php
    # otherwise reports a scheduler and pollers that have not run yet
    run in_dir "$LNMS_DIR" as librenms php artisan schedule:test --name="schedule operational check" --no-ansi --no-interaction
    run in_dir "$LNMS_DIR" as librenms ./poller-wrapper.py 1
    local out
    out=$(in_dir "$LNMS_DIR" as librenms ./validate.php 2>&1 | sed 's/\x1b\[[0-9;]*m//g' || true)
    printf '%s\n' "$out" >>"$LOG_FILE"
    local fails warns
    fails=$(grep -c '\[FAIL\]' <<<"$out" || true)
    warns=$(grep -c '\[WARN\]' <<<"$out" || true)
    if [[ $fails == 0 && $warns == 0 ]]; then
        info "./validate.php: everything OK"
    else
        info "./validate.php: $fails failures, $warns warnings (the log has its whole output):"
        grep -E '\[(FAIL|WARN)\]' <<<"$out" | sed -n 's/^/      /;1,20p' || true

    fi
}
