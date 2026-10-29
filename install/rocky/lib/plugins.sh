# shellcheck shell=bash
# install/rocky/lib/plugins.sh: part 2, the topology plugin (the repository's root), the Ansible plugin (ansible/) and
# the compliance plugin (compliance/), installed as their READMEs describe it from one clone:
# /opt/librenms-plugins/librenms_dashplugin, a Composer path repository for each in the librenms user's global Composer
# configuration, ./lnms plugin:add of each, ./lnms migrate, all as the librenms user. A run updates the clone from its source; when it moved, the plugins are added again (Composer
# takes the new version) and migrated.

PLUGINS_DIR=/opt/librenms-plugins
PLUGIN_CLONE=$PLUGINS_DIR/librenms_dashplugin
TOPOLOGY_PKG=agoodley/librenms-topology-plugin
ANSIBLE_PKG=agoodley/librenms-ansible-plugin
COMPLIANCE_PKG=agoodley/librenms-compliance-plugin
PLUGIN_COMMIT=''
PLUGIN_UPDATED=0

part_plugins() {
    part_header plugins "The topology, Ansible and compliance plugins, from $PLUGIN_SOURCE"
    librenms_require
    if [[ $CHECK == 1 && ! -f $LNMS_DIR/artisan ]]; then
        would "clone $PLUGIN_SOURCE into $PLUGIN_CLONE, add the three plugins with ./lnms plugin:add, run ./lnms migrate and enable them"
        summary_add "the plugins: would be installed"
        return 0
    fi
    [[ ! -L $PLUGINS_DIR ]] || die "$PLUGINS_DIR is a symlink" "replace it with a root-owned directory before installing plugins"
    if [[ ! -d $PLUGINS_DIR || $(stat -c '%U:%G %a' "$PLUGINS_DIR") != 'root:root 755' ]]; then
        act "secure $PLUGINS_DIR (root-owned parent; the checkout belongs to librenms)" install -d -o root -g root -m 755 "$PLUGINS_DIR"
    fi
    plugins_clone
    plugins_composer_repos
    plugins_add
    plugins_migrate
    plugins_enable
    plugins_routes
    summary_add "topology + Ansible + compliance plugins from ${PLUGIN_SOURCE##*/} ($PLUGIN_BRANCH_USED${PLUGIN_COMMIT:+, $PLUGIN_COMMIT})$( [[ $PLUGIN_UPDATED == 1 ]] && echo ', installed/updated' ), enabled"
}

# Even read-only Git operations can run hooks/helpers configured in a checkout. Never run them as root in a
# service-owned repository. Local sources may have another owner; that trust exception stays unprivileged too.
clone_git() { as librenms git -C "$PLUGIN_CLONE" "$@"; }
# source_git: git in the plugins' local checkout, or in the copy of it that librenms may read (PLUGIN_FETCH)
source_git() {
    local dir=${PLUGIN_FETCH:-$PLUGIN_SOURCE}
    as librenms git -c safe.directory="$dir" -c safe.directory="$dir/.git" -C "$dir" "$@"
}
PLUGIN_FETCH=''

# git_auth CMD...: CMD with the GitHub token (GITHUB_TOKEN) as an HTTP header for github.com, given to git in its
# environment (GIT_CONFIG_*), never on its command line or in the clone's configuration.
git_auth() {
    if [[ -n $GITHUB_TOKEN_VALUE ]]; then
        local basic; basic=$(printf 'x-access-token:%s' "$GITHUB_TOKEN_VALUE" | base64 -w0)
        GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=http.https://github.com/.extraheader GIT_CONFIG_VALUE_0="Authorization: Basic $basic" "$@"
    else
        "$@"
    fi
}

# plugin_source_kind: url, git (a local Git checkout) or dir (a plain directory).
plugin_source_kind() {
    if [[ $PLUGIN_SOURCE == *://* || $PLUGIN_SOURCE == *@*:* ]]; then echo url
    elif [[ -e $PLUGIN_SOURCE/.git ]] || source_git rev-parse --git-dir >/dev/null 2>&1; then echo git
    else echo dir; fi
}

plugins_clone() {
    local kind; kind=$(plugin_source_kind)
    # src is where the plugins come from (the clone's origin), fetch what git reads: for a local checkout that
    # librenms may not read (one in /root, say), a copy of it in the run's directory
    local src=$PLUGIN_SOURCE fetch=$PLUGIN_SOURCE
    if [[ $kind == git ]]; then
        src=$(cd "$PLUGIN_SOURCE" && pwd)
        fetch=$(readable_copy librenms "$src" plugin-source)
        PLUGIN_FETCH=$fetch
    fi
    local branch=$PLUGIN_BRANCH
    if [[ -z $branch ]]; then
        if [[ $kind == git ]]; then
            branch=$(source_git symbolic-ref --short HEAD 2>/dev/null || echo main)
        else
            branch=main
        fi
    fi
    PLUGIN_BRANCH_USED=$branch
    if [[ $kind == dir ]]; then plugins_copy; return 0; fi
    if [[ ! -d $PLUGIN_CLONE/.git ]]; then
        would "clone $PLUGIN_SOURCE ($branch) into $PLUGIN_CLONE" && return 0
        local partial=$PLUGINS_DIR/.librenms_dashplugin.partial
        rm -rf "$partial"
        install -d -o librenms -g librenms -m 755 "$partial"
        FAIL_HINT="check that this server reaches $PLUGIN_SOURCE (a private repository needs GITHUB_TOKEN), or give a local copy (--plugin-source DIR)" \
            run git_auth as librenms git -c safe.directory="$fetch" -c safe.directory="$fetch/.git" clone --branch "$branch" "$fetch" "$partial"
        if [[ $fetch != "$src" ]]; then run as librenms git -C "$partial" remote set-url origin "$src"; fi
        # a copy of a plain directory (an earlier --plugin-source DIR) makes way for the clone
        rm -rf "$PLUGIN_CLONE.old"
        if [[ -d $PLUGIN_CLONE ]]; then mv "$PLUGIN_CLONE" "$PLUGIN_CLONE.old"; fi
        run mv "$partial" "$PLUGIN_CLONE"
        rm -rf "$PLUGIN_CLONE.old"
        PLUGIN_UPDATED=1
    else
        local origin; origin=$(clone_git config --get remote.origin.url || true)
        if [[ $origin != "$src" ]]; then
            act "take the plugins from $src (the clone came from $origin)" as librenms git -C "$PLUGIN_CLONE" remote set-url origin "$src"
        fi
        local before after
        before=$(clone_git rev-parse HEAD)
        if [[ $CHECK == 1 ]]; then
            local remote
            remote=$(git_auth as librenms git -c safe.directory="$fetch" -c safe.directory="$fetch/.git" ls-remote "$fetch" "refs/heads/$branch" 2>>"$LOG_FILE" | cut -f1 || true)
            if [[ -z $remote ]]; then
                info "$src has no branch $branch (a run stops there)"
            elif [[ $remote != "$before" ]]; then
                would "update the plugins from $src ($branch): ${before:0:7} -> ${remote:0:7}, then add them again and migrate"
            else
                detail "the plugins are up to date (${before:0:7})"
            fi
        else
            local from=origin
            [[ $fetch != "$src" ]] && from=$fetch
            FAIL_HINT="check that this server reaches $src (a private repository needs GITHUB_TOKEN)" \
                run git_auth as librenms git -c safe.directory="$fetch" -c safe.directory="$fetch/.git" -C "$PLUGIN_CLONE" fetch --quiet "$from" "$branch"
            local current; current=$(clone_git symbolic-ref --short HEAD 2>/dev/null || true)
            if [[ $current != "$branch" ]]; then
                run as librenms git -C "$PLUGIN_CLONE" checkout --quiet -B "$branch" FETCH_HEAD
            elif [[ -n $(clone_git status --porcelain --untracked-files=no) ]]; then
                warn "$PLUGIN_CLONE has local changes: not updated (git -C $PLUGIN_CLONE status shows them)"
            elif ! probe as librenms git -C "$PLUGIN_CLONE" merge --ff-only --quiet FETCH_HEAD; then
                warn "$PLUGIN_CLONE has commits of its own and cannot follow $branch of $src: not updated"
            fi
            after=$(clone_git rev-parse HEAD)
            if [[ $before != "$after" ]]; then
                info "the plugins: updated ${before:0:7} -> ${after:0:7}"
                PLUGIN_UPDATED=1
                CHANGES+=("plugins: updated to ${after:0:7}")
            else
                detail "the plugins are up to date (${after:0:7})"
            fi
        fi
    fi
    [[ -d $PLUGIN_CLONE/.git ]] && PLUGIN_COMMIT=$(clone_git rev-parse --short HEAD)
    state_set plugin_source "$PLUGIN_SOURCE"
    state_set plugin_branch "$PLUGIN_BRANCH_USED"
    return 0
}

# plugins_copy: a plain directory as the source (an extracted archive): copied when its content differs.
plugins_copy() {
    PLUGIN_BRANCH_USED='a copy'
    if [[ -d $PLUGIN_CLONE ]] && diff -rq -x .git "$PLUGIN_SOURCE" "$PLUGIN_CLONE" >/dev/null 2>&1; then
        detail "$PLUGIN_CLONE has the content of $PLUGIN_SOURCE"
    elif ! would "copy $PLUGIN_SOURCE into $PLUGIN_CLONE"; then
        local partial=$PLUGINS_DIR/.librenms_dashplugin.partial
        rm -rf "$partial"
        install -d -o librenms -g librenms -m 755 "$partial"
        run in_dir "$PLUGIN_SOURCE" tar -cf "$WORK/plugins.tar" --exclude=./.git .
        run as librenms tar -xf "$WORK/plugins.tar" -C "$partial"
        rm -rf "$PLUGIN_CLONE.old"
        [[ -d $PLUGIN_CLONE ]] && mv "$PLUGIN_CLONE" "$PLUGIN_CLONE.old"
        run mv "$partial" "$PLUGIN_CLONE"
        rm -rf "$PLUGIN_CLONE.old"
        PLUGIN_UPDATED=1
    fi
    # a plain directory has no branch: a later run without --plugin-source copies from it again
    state_set plugin_source "$PLUGIN_SOURCE"
    state_set plugin_branch ''
}

# composer_run ARGS...: Composer through LibreNMS' wrapper, as the librenms user.
composer_run() { in_dir "$LNMS_DIR" as librenms env -u TERM php "$LNMS_DIR/scripts/composer_wrapper.php" --no-interaction "$@"; }

# plugins_composer_repos: the plugins' path repositories in the librenms user's global Composer configuration (so
# that they survive LibreNMS' updates), as the plugins' READMEs set them.
plugins_composer_repos() {
    if [[ ! -f $LNMS_DIR/composer.phar ]] && ! command -v composer >/dev/null; then composer_install /usr/local/bin/composer root:root; fi
    local name url current want
    for name in librenms-topology librenms-ansible librenms-compliance; do
        url=$PLUGIN_CLONE
        [[ $name == librenms-ansible ]] && url=$PLUGIN_CLONE/ansible
        [[ $name == librenms-compliance ]] && url=$PLUGIN_CLONE/compliance
        want="{\"type\": \"path\", \"url\": \"$url\", \"options\": {\"symlink\": true}}"
        current=$(composer_global_repo "$name")
        if [[ $current == "path $url true" ]]; then detail "Composer knows the path repository $name"; continue; fi
        act "add the Composer path repository $name ($url) to the librenms user's global configuration" \
            composer_run config --global "repositories.$name" "$want"
    done
}

# composer_global_repo NAME: "type url symlink" of a repository in the librenms user's global Composer configuration.
composer_global_repo() {
    local home
    home=$(in_dir "$LNMS_DIR" as librenms env -u TERM php "$LNMS_DIR/scripts/composer_wrapper.php" --no-interaction config --global home 2>/dev/null | tail -n 1 || true)
    [[ -n $home && -f $home/config.json ]] || return 0
    python3 -I -c 'import json,sys
r=(json.load(open(sys.argv[1])).get("repositories") or {})
r=r.get(sys.argv[2]) if isinstance(r, dict) else next((x for x in r if isinstance(x, dict) and x.get("name") == sys.argv[2]), None)
if isinstance(r, dict): print(r.get("type"), r.get("url"), str(bool((r.get("options") or {}).get("symlink"))).lower())' "$home/config.json" "$1" 2>/dev/null || true
}

# plugin_installed PACKAGE DIR: whether LibreNMS has the package as a plugin, linked to DIR of the clone.
plugin_installed() {
    grep -qs "\"$1\"" "$LNMS_DIR/composer.plugins.json" && [[ $(readlink -f "$LNMS_DIR/vendor/$1" 2>/dev/null) == "$(readlink -f "$2")" ]]
}

plugins_add() {
    local pkg dir
    for pkg in "$TOPOLOGY_PKG" "$ANSIBLE_PKG" "$COMPLIANCE_PKG"; do
        dir=$PLUGIN_CLONE
        [[ $pkg == "$ANSIBLE_PKG" ]] && dir=$PLUGIN_CLONE/ansible
        [[ $pkg == "$COMPLIANCE_PKG" ]] && dir=$PLUGIN_CLONE/compliance
        if [[ $PLUGIN_UPDATED == 0 ]] && plugin_installed "$pkg" "$dir"; then
            detail "LibreNMS has $pkg"
            continue
        fi
        FAIL_HINT="read the log (Composer says why); ./lnms plugin:add $pkg @dev as the librenms user shows it again" \
            act "add $pkg to LibreNMS (./lnms plugin:add $pkg @dev)" lnms plugin:add "$pkg" @dev
    done
}

plugins_migrate() {
    if [[ $CHECK == 1 && ! -L $LNMS_DIR/vendor/$COMPLIANCE_PKG ]]; then would "create the plugins' tables (./lnms migrate)"; return 0; fi
    helper plugins-status '{}'
    local pending; pending=$(json_field "$HELPER_OUT" pending_migrations)
    if [[ $pending == '[]' ]]; then detail "the plugins' tables are up to date"; return 0; fi
    act "create or update the plugins' tables (./lnms migrate: $(python3 -I -c 'import json,sys; print(len(json.load(sys.stdin)))' <<<"$pending") migrations)" \
        lnms migrate --force --no-interaction
}

# plugins_enable: LibreNMS enables a plugin it sees for the first time; the installer makes sure of it once. A plugin
# an admin disabled later stays disabled (the summary says so).
plugins_enable() {
    if [[ $CHECK == 1 && ! -L $LNMS_DIR/vendor/$COMPLIANCE_PKG ]]; then would "enable the plugins in LibreNMS"; return 0; fi
    helper plugins-status '{}'
    local status=$HELPER_OUT name
    for name in topology ansible compliance; do
        local enabled; enabled=$(json_field "$status" "enabled.$name")
        if [[ $enabled == true ]]; then detail "the $name plugin is enabled"; continue; fi
        if [[ $(state_get "plugin_${name}_enabled" || true) == yes && $enabled == false ]]; then
            warn "the $name plugin is disabled in LibreNMS (Plugin Admin): left as an admin set it"
            continue
        fi
        act "enable the $name plugin" lnms plugin:enable "$name"
    done
    if [[ $CHECK == 0 ]]; then
        helper plugins-status '{}'
        for name in topology ansible compliance; do
            if [[ $(json_field "$HELPER_OUT" "enabled.$name") == true ]]; then state_set "plugin_${name}_enabled" yes; fi
        done
    fi
}
PLUGIN_BRANCH_USED=''

# plugins_routes: LibreNMS caches its routes (composer install runs artisan optimize), and ./lnms plugin:add leaves
# that cache as it was: without the plugins' routes their pages fail (Route [ansible.changes] not defined) until the
# next composer install. LibreNMS' plugin:enable caches the routes again; so does the installer, when the cache lacks
# the plugins' routes.
plugins_routes() {
    local cache
    cache=$(find "$LNMS_DIR/bootstrap/cache" -maxdepth 1 -name 'routes-v*.php' -print -quit 2>/dev/null)
    [[ -n $cache ]] || { detail "LibreNMS does not cache its routes"; return 0; }
    if [[ $PLUGIN_UPDATED == 0 ]] && grep -q "'topology\.l2'" "$cache" && grep -q "'ansible\.changes'" "$cache" && grep -q "'compliance\.overview'" "$cache"; then
        detail "LibreNMS' route cache has the plugins' routes"
        return 0
    fi
    act "cache LibreNMS' routes again, with the plugins' (./lnms route:cache)" lnms route:cache
}
