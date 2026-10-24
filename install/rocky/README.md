# Installer for Rocky Linux

`install/rocky/install.sh` sets up everything this repository needs for a real-world test on a Rocky Linux 9 or 10
server (x86_64), in one run: LibreNMS itself when the server has none, the [topology plugin](../../README.md) and the
[Ansible plugin](../../ansible/README.md), Ansible with the network collections, [Semaphore UI](https://semaphoreui.com)
(the runner the Ansible plugin drives) with its project for LibreNMS, and [Oxidized](https://github.com/ytti/oxidized)
(the backups the Ansible plugin takes before and after each batch). It sets up each part it finds missing and checks
the parts that are there: a second run changes nothing that is right, and a run that was stopped picks up where it
stopped. On a LibreNMS it did not install, it adds the plugins and leaves the web server, PHP, MariaDB and the
configuration files (`.env`, `config.php`) as they are.

* [Quick start](#quick-start)
* [What it installs](#what-it-installs)
* [Options](#options)
* [Offline mode](#offline-mode)
* [What it changes on the server](#what-it-changes-on-the-server)
* [Running it again, and runs that stop](#running-it-again-and-runs-that-stop)
* [After the installation](#after-the-installation)
* [Testing it](#testing-it)
* [Undoing it](#undoing-it)
* [Limits](#limits)

## Quick start

As root, on the server (it needs the internet: Rocky's repositories, EPEL, Remi's PHP, GitHub, Packagist, PyPI and
RubyGems; or [local copies](#offline-mode)):

```bash
dnf -y install git
git clone https://github.com/agoodley/librenms_dashplugin
cd librenms_dashplugin
install/rocky/install.sh --check                      # what it would do; changes nothing
INSTALLER_DEVICE_PASSWORD='the devices password' install/rocky/install.sh --device-user netops --server-name librenms.example.com
```

It asks once before it starts (`--yes` for unattended runs), says what it does part by part and ends with a summary:
where each part answers, the passwords and tokens it generated (in `/root/librenms-installer-credentials`, root only,
never on the screen or in the log) and what to do next. Everything it runs is logged in `/var/log/librenms-installer/`
(`latest.log` is the last run). A first run takes 5 to 30 minutes (5 in offline mode on a fast server), most of it dnf,
Composer and the compilation of Oxidized's gems.

## What it installs

The parts, in the order a run sets them up (`--skip` and `--only` name them):

| Part | What | How |
|---|---|---|
| `librenms` | LibreNMS (the master branch, as its documentation installs it), only when the server has no `/opt/librenms` | as [LibreNMS' install documentation](https://docs.librenms.org/Installation/Install-LibreNMS/) does it on Rocky 9 and 10: EPEL, Remi's repository with the `php:remi-8.5` module stream, its packages (PHP-FPM, nginx, MariaDB, net-snmp, fping, rrdtool, its Python modules), the `librenms` user, `/opt/librenms` with its permissions, `composer install`, PHP's time zone, MariaDB's settings, the PHP-FPM pool, the nginx site, SELinux and firewalld, snmpd, the cron jobs, the scheduler timer and logrotate; then what its web installer does: the database and its user, `.env`, `lnms migrate --seed`, `config.php` and the first admin; `./validate.php` at the end |
| `plugins` | both plugins of this repository | as their READMEs describe it: one clone in `/opt/librenms-plugins/librenms_dashplugin`, two Composer *path* repositories in the `librenms` user's global Composer configuration, `./lnms plugin:add` of each, `./lnms migrate`, both enabled; all as the `librenms` user |
| `ansible` | Ansible where Semaphore UI runs it | a Python 3.12 virtual environment in `/opt/ansible` with the versions the Semaphore UI 2.19.17 image ships, which the plugin was tested with ([`files/ansible-requirements.txt`](files/ansible-requirements.txt)): `ansible` 13.5.0, so ansible-core 2.20.10 and the collections the plugin's platform mapping names (`cisco.ios`, `cisco.iosxr`, `cisco.nxos`, `arista.eos`, `junipernetworks.junos`, `vyos.vyos`, `community.routeros`, `community.ciscosmb`, `dellemc.enterprise_sonic`, `ansible.netcommon`, `ansible.utils`, ...), and paramiko; a dry run checks that it prints the check-mode markers the plugin's dry run proof needs (`ANSIBLE_CHECK_MODE_MARKERS`) |
| `semaphore` | Semaphore UI 2.19.17 (the version the plugin was tested with) and the project for LibreNMS | its release RPM (or tarball or binary), its own MariaDB database and user, `/etc/semaphore/config.json`, the service `semaphore` as the user `semaphore`, on `127.0.0.1:3000`, its admin; then the project "LibreNMS changes" as the [Ansible plugin's README](../../ansible/README.md#semaphore-ui-setup) sets it up: the key store entries "Devices: SSH login" and "Devices: enable password" (the devices' login of the options, or empty and named), a Git repository on the server (`file:///var/lib/semaphore/playbooks.git`) with the plugin's inventory script and the example fix playbooks of `ansible/contrib/`, the inventory "LibreNMS" (the script), the variable group "LibreNMS" (`LIBRENMS_URL`, `ANSIBLE_CHECK_MODE_MARKERS=true`, the secret `LIBRENMS_TOKEN`), the templates "Fix port descriptions" and "Fix spanning tree root priority", and the Task Runner user `librenms` with its API token. In LibreNMS: the inventory user `ansible-inventory` (role global-read: it sees the devices and changes nothing) with its API token; the Ansible plugin's runner connection, inventory user and fix templates, saved as its settings page saves them (with its checks and eventlog entries); the run and approve permissions of the admin; its connection test |
| `oxidized` | Oxidized 0.37.0 with oxidized-web 0.18.1 (the versions the plugin was tested with), on by default | as [Oxidized's documentation](https://github.com/ytti/oxidized#rocky-linux-red-hat-enterprise-linux) installs it on Rocky: EPEL, CRB, Ruby (the `ruby:3.3` module stream on Rocky 9), the build packages, the gems, the user `oxidized` and the service `oxidized`; its devices come from LibreNMS' API (the LibreNMS user `oxidized`, global-read, with an API token of its own), its versions go to git, its REST API listens on `127.0.0.1:8888`, the devices' login is the one of the options; LibreNMS' Oxidized settings point at it (`oxidized.enabled`, `oxidized.url`, `oxidized.features.versioning`, `oxidized.reload_nodes`). A LibreNMS that already uses another Oxidized keeps it |

**A LibreNMS the server has already** (one in `/opt/librenms` that the installer did not install) keeps its web
server, PHP, MariaDB settings, `.env` and `config.php` as they are. The installer checks that its version is one the
plugins support (26.10 or a later 26.x: they were developed and tested against 26.10) and stops with what to do when
it is not (update it, `./daily.sh`), checks PHP 8.2+ with curl and mbstring and warns when its scheduler does not run;
then it adds the plugins and the rest: `./lnms plugin:add` changes LibreNMS' `composer.json` and `composer.lock` (as
the plugins' READMEs install them), `./lnms route:cache` renews its route cache (when it has one), and the installer
adds to LibreNMS' database (the plugins' tables, the users `ansible-inventory` and `oxidized` and their API tokens,
the admin of `--admin-user` when there is none, the plugins' settings and LibreNMS' Oxidized settings) and, under
SELinux, turns on `httpd_can_network_connect`. Semaphore UI gets a database of its own in the server's MariaDB
(installed when the server has none).

## Options

Every option has an environment variable for unattended runs; passwords are better given that way (options show in
`ps` and in the shell's history). `install.sh --help` lists them too.

| Option | Variable | Default | |
|---|---|---|---|
| `--check` | `INSTALLER_CHECK=1` | | say what it would do; change nothing (it reads what it can, but does not log in to Semaphore UI) |
| `--skip PART[,PART]` | `INSTALLER_SKIP` | | leave parts out: `librenms`, `plugins`, `ansible`, `semaphore`, `oxidized` |
| `--only PART[,PART]` | `INSTALLER_ONLY` | | set up only these parts |
| `-y`, `--yes` | `INSTALLER_YES=1` | | do not ask before starting |
| `-v`, `--verbose` | | | show every step (the log has them anyway) |
| `--admin-user NAME` | `INSTALLER_ADMIN_USER` | `admin` | the LibreNMS admin who gets the Ansible plugin's run and approve permissions (created when missing), and Semaphore UI's admin |
| `--admin-password PW` | `INSTALLER_ADMIN_PASSWORD` | generated | their password when they are created (LibreNMS and Semaphore UI alike) |
| `--admin-email EMAIL` | `INSTALLER_ADMIN_EMAIL` | `root@<server name>` | |
| `--server-name NAME` | `INSTALLER_SERVER_NAME` | `hostname -f` | nginx' `server_name` for LibreNMS |
| `--timezone ZONE` | `INSTALLER_TIMEZONE` | the system's | PHP's time zone (`date.timezone`) |
| `--php-stream STREAM` | `INSTALLER_PHP_STREAM` | `remi-8.5` | PHP's module stream: Remi's 8.5 as LibreNMS documents it, or `remi-8.4`; `8.3` takes Rocky's own PHP (LibreNMS 26.10 then warns that 8.4 is its minimum) |
| `--librenms-url URL` | `INSTALLER_LIBRENMS_URL` | `http://localhost` | how Semaphore UI (the inventory script) and Oxidized on this server reach LibreNMS' API; for a LibreNMS installed otherwise, the first of its `APP_URL`, `http://localhost` and `https://localhost` that answers |
| `--device-user USER` | `INSTALLER_DEVICE_USER` | none | the devices' SSH login, for Semaphore UI's key store and Oxidized; without it both are set up empty, to fill in later |
| `--device-password PW` | `INSTALLER_DEVICE_PASSWORD` | | |
| `--device-enable-password PW` | `INSTALLER_DEVICE_ENABLE_PASSWORD` | the device password | for platforms with an enable mode (IOS, IOS-XE, EOS) |
| `--plugin-source URL\|DIR` | `INSTALLER_PLUGIN_SOURCE` | the run before's, else `https://github.com/agoodley/librenms_dashplugin` | this repository: a Git URL, a local Git checkout, or a plain directory; a private GitHub repository takes `GITHUB_TOKEN` from the environment (given to git as a header, never stored in the clone) |
| `--plugin-branch BRANCH` | `INSTALLER_PLUGIN_BRANCH` | the run before's (with its source), else `main` | for a local Git checkout: its checked-out branch |
| `--librenms-source SRC` | `INSTALLER_LIBRENMS_SOURCE` | `https://github.com/librenms/librenms.git` | a Git URL, or a directory or `.tar.gz` of a LibreNMS with its `vendor/` installed |
| `--semaphore-source FILE` | `INSTALLER_SEMAPHORE_SOURCE` | the GitHub release | Semaphore UI 2.19.17 as its `.rpm`, `.tar.gz` or binary |
| `--composer-source FILE` | `INSTALLER_COMPOSER_SOURCE` | getcomposer.org | `composer.phar`, when LibreNMS has none |
| `--pip-wheels DIR` | `INSTALLER_PIP_WHEELS` | PyPI | Python wheels for Ansible and LibreNMS' Python modules |
| `--gems DIR` | `INSTALLER_GEMS` | RubyGems | `.gem` files for Oxidized |
| `--distro-source FILE` | `INSTALLER_DISTRO_SOURCE` | GitHub | librenms-agent's `distro` script (snmpd's `extend distro`) |
| `--offline DIR` | `INSTALLER_OFFLINE` | | all of these from one directory ([Offline mode](#offline-mode)) |
| `--semaphore-listen ADDR`, `--semaphore-port PORT` | `INSTALLER_SEMAPHORE_LISTEN`, `INSTALLER_SEMAPHORE_PORT` | `127.0.0.1`, `3000` | another address than a loopback one also opens the port in firewalld |
| `--oxidized-listen ADDR`, `--oxidized-port PORT` | `INSTALLER_OXIDIZED_LISTEN`, `INSTALLER_OXIDIZED_PORT` | `127.0.0.1`, `8888` | the same for Oxidized's REST API |

Options that cannot be used stop the run before it changes anything, with what is wrong (`install.sh: --skip:
unknown part 'ansibel' (the parts: librenms plugins ansible semaphore oxidized)`). A port that another program holds
stops the run before the service is started (the error names the option that moves it).

## Offline mode

When the server cannot reach GitHub (or should not download anything but packages), give local copies of what it would
download: each with its option, or all at once in a directory with `--offline DIR`, which takes these names and never
contacts GitHub:

| In DIR | What | How to make it (on a machine that has the internet) |
|---|---|---|
| `librenms.tar.gz` (or `librenms/`) | LibreNMS with its PHP dependencies | `git clone https://github.com/librenms/librenms.git && cd librenms && ./scripts/composer_wrapper.php install --no-dev && tar -czf ../librenms.tar.gz .` (needs PHP 8.2+ with the extensions LibreNMS asks for). The installer leaves out what belongs to the machine the copy was made on: `.env` (its `APP_KEY`), `config.php`, `composer.plugins.json`, the caches, sessions, logs and graphs |
| `librenms_dashplugin/` | this repository | `git clone https://github.com/agoodley/librenms_dashplugin` |
| `semaphore_2.19.17_linux_amd64.rpm` (or `semaphore_2.19.17_linux_amd64.tar.gz`, or the binary as `semaphore-2.19.17` or `semaphore`) | Semaphore UI | from <https://github.com/semaphoreui/semaphore/releases/tag/v2.19.17> |
| `composer.phar` | Composer, only when the LibreNMS copy has none | <https://getcomposer.org/download/latest-stable/composer.phar> |
| `wheels/` | Python wheels: Ansible, and LibreNMS' Python modules dnf has not | on a Rocky Linux of the same version: `python3.12 -m pip download -d wheels -r install/rocky/files/ansible-requirements.txt` and `python3 -m pip download -d wheels -r librenms/requirements.txt` |
| `gems/` | Oxidized's gems with all they depend on | on a Rocky Linux of the same version, with Ruby 3.3 and Oxidized's build packages: `gem install --install-dir /tmp/gems --no-document oxidized:0.37.0 oxidized-web:0.18.1 && cp /tmp/gems/cache/*.gem gems/` |
| `distro` | librenms-agent's distro script | <https://raw.githubusercontent.com/librenms/librenms-agent/master/snmp/distro> |

A copy that is missing is downloaded as usual (wheels from PyPI, gems from RubyGems), except what comes from GitHub:
then the run stops and says which copy it needs (without `distro`, snmpd's `extend distro` has nothing to run). dnf
still needs the server's package repositories (Rocky's, EPEL, CRB and Remi's), or local mirrors of them. The
installer switches Remi's repositories to Remi's own HTTPS mirror list (`httpsmirror`, which its repository files
offer), so they also work behind a proxy that only passes HTTPS.

## What it changes on the server

**Packages and repositories:** `epel-release`, `remi-release` (Remi's repositories with HTTPS mirror lists), the
`php:remi-8.5` module stream (with `--php-stream` another), CRB (for Oxidized's build packages), the `ruby:3.3`
module stream on Rocky 9; the packages of LibreNMS' documentation and nginx, `python3.12` on Rocky 9, Ruby and
Oxidized's build packages, `mariadb-server` when the server has none; Semaphore UI's RPM. Outside dnf:
`/opt/librenms`, `/opt/librenms-plugins`, `/opt/ansible` (pip), the gems in `/usr/local` (`/usr/local/bin/oxidized`),
LibreNMS' missing Python modules with pip (`/usr/local/lib/python3*`), `/usr/local/bin/semaphore` (from a tarball or
binary), `/usr/bin/distro`, `/usr/local/bin/composer` (only when an existing LibreNMS has no Composer).

**Users:** `librenms` (home `/opt/librenms`), `semaphore` (`/var/lib/semaphore`), `oxidized` (`/home/oxidized`). In
LibreNMS: the admin of `--admin-user` (when missing), `ansible-inventory` and `oxidized` (global-read) with an API token
each. In Semaphore UI: the admin and the Task Runner user `librenms`. In MariaDB: `librenms@localhost` and
`semaphore@localhost`/`semaphore@127.0.0.1` with their databases.

**Services:** `mariadb`, `php-fpm`, `nginx`, `snmpd`, `crond`, `librenms-scheduler.timer` (for a LibreNMS it installs),
`semaphore`, `oxidized`, all enabled.

**Ports:** 80 (nginx: LibreNMS) and 161/udp (snmpd, community in the credentials file) on every address; 3306
(MariaDB), 3000 (Semaphore UI) and 8888 (Oxidized) on 127.0.0.1 only, unless `--semaphore-listen` or
`--oxidized-listen` say otherwise.

**Files:** `/opt/librenms/.env` made readable by `librenms` only (mode 640: it holds the database password and
`APP_KEY`), `/etc/php.d/90-librenms.ini` (PHP's time zone), `/etc/my.cnf.d/90-librenms.cnf` (MariaDB's settings),
`/etc/php-fpm.d/librenms.conf`, `/etc/nginx/conf.d/librenms.conf`, `/etc/nginx/nginx.conf` without its default server
(as LibreNMS' documentation says; the original is kept as `/etc/nginx/nginx.conf.librenms-installer`),
`/etc/snmp/snmpd.conf` (while it is the package's), `/etc/cron.d/librenms`, `/etc/logrotate.d/librenms`,
`/etc/systemd/system/librenms-scheduler.{service,timer}`, `semaphore.service` and `oxidized.service`,
`/etc/semaphore/config.json`, `~oxidized/.config/oxidized/config`, `/usr/bin/lnms`, the bash completion of lnms; the
librenms user's global Composer configuration (`/opt/librenms/.config/composer/config.json`: the two path
repositories); the installer's own `/var/lib/librenms-installer/` (what earlier runs did), `/var/log/librenms-installer/`
and `/root/librenms-installer-credentials`.

**SELinux** (when it is enforcing or permissive), as LibreNMS' documentation sets it: the file contexts of
`/opt/librenms` (`html`, `rrd`, `storage`, `logs`, `cache`, `librenms-service.py`) with `restorecon`, `.env`'s context,
the booleans `httpd_can_sendmail`, `httpd_execmem` and `httpd_can_network_connect_db`, and its `http_fping` policy module;
then `httpd_can_network_connect`, which LibreNMS' PHP needs to reach Semaphore UI and Oxidized (LibreNMS' Oxidized
documentation asks for it too). Semaphore UI and Oxidized run as systemd services of their own (unconfined).

**firewalld** (when it runs), in its default zone, now and permanently: the services `http` and `https` for a LibreNMS it
installs; Semaphore UI's and Oxidized's ports when they listen on another address than a loopback one.

## Running it again, and runs that stop

A run checks every part and changes only what is not right; when nothing is, it says *Nothing needed a change*. It
never undoes what an admin set up since: a file it wrote and someone changed (the nginx site, Oxidized's configuration,
a playbook in the playbook repository) stays as it is, with a warning; a plugin an admin disabled stays disabled; the
key store entries keep their secrets unless the devices' login of the options changed; settings of the plugins other
than the connection, the inventory user and the fix templates are left alone.

A run updates the plugins: it fetches the clone's branch from the plugins' source (without `--plugin-source` and
`--plugin-branch`, the source and branch of the run before) and, when it moved, adds both
plugins again (Composer takes the new version), migrates their tables, caches LibreNMS' routes again and puts a new
inventory script or fix playbook into the playbook repository (unless someone changed that file there).

A run that was stopped (an error, Ctrl-C, a kill, a reboot) picks up where it stopped: each step finds out whether its
work is done, LibreNMS and the plugins' clone are copied into place only when complete, configuration files are written
atomically, a service whose configuration changed is restarted even when the run stopped right after the change. Two
runs at the same time are refused (a lock in `/var/lib/librenms-installer/`). An error stops the run with what failed,
the last lines of the command's output, what to do, and the log.

## After the installation

1. **Log in** to LibreNMS at `http://<server name>/` as the admin (the password of `--admin-password`, or
   `ADMIN_PASSWORD` in the credentials file). Do not connect it to the internet before you set up HTTPS: LibreNMS'
   documentation does not do it, and neither does the installer.
2. **Add the devices** (*Devices > Add Device*, or `lnms device:add`) with LLDP/CDP discovered (the topology plugin
   builds everything from it), and set the topology plugin's *Core devices* (*Overview > Plugins > Plugin Admin >
   topology > Settings*).
3. **The devices' login**, when you did not give it: in Semaphore UI (project "LibreNMS changes", *Key Store*: "Devices:
   SSH login" and "Devices: enable password") and in `~oxidized/.config/oxidized/config` (`username`, `password`,
   `vars: enable`), then `systemctl restart oxidized`. Or run the installer again with `--device-user` and
   `INSTALLER_DEVICE_PASSWORD`. Oxidized does not start while LibreNMS has no device (its service tries again every
   minute).
4. **Semaphore UI** listens on the server only: `ssh -L 3000:127.0.0.1:3000 root@<server>`, then `http://localhost:3000`
   (the admin's login).
5. **The first change:** *Overview > Plugins > Ansible > Changes > New change* (or *Fixes*). Review the example fix
   playbooks first (Cisco IOS); add your own to the playbook repository (`git clone /var/lib/semaphore/playbooks.git`)
   with a template each, as the Ansible plugin's README describes. Its defaults ask for an approval (the admin holds the
   approve permission: grant it to others in the plugin's settings), refuse a batch without a fresh Oxidized backup, and
   verify each batch with the topology plugin (which waits for LibreNMS' discovery and polling of the devices).

## Testing it

`install/rocky/tests/` has what the installer was tested with, in containers with systemd as PID 1:

* `Dockerfile`, `entrypoint.sh`: a Rocky Linux test system (`docker build --build-arg BASE=quay.io/rockylinux/rockylinux:10
  -t inst-rocky10 install/rocky/tests`, `docker run -d --privileged --name inst-r10 -v $PWD:/src:ro inst-rocky10`). The
  units that would touch the host when the container shares its network (firewalld, NetworkManager, networkd, resolved,
  udev, sysctl, chrony, tuned, ...) are masked, and the entrypoint makes `/proc/sys` and `/sys` read-only.
* `verify.sh`: what a run should have made: LibreNMS on nginx, `./validate.php`, the admin's login and both plugins'
  pages, both plugins enabled, Semaphore UI's project, the Ansible plugin's connection test as its settings page runs it,
  the inventory endpoint, Oxidized and LibreNMS' Oxidized settings.
* `e2e.sh NAME IP`: a fake Cisco IOS switch (e.g. [fake-switches](https://pypi.org/project/fake-switches/), SSH only)
  added to LibreNMS, then a change through the Ansible plugin's pages with the template "Fix port descriptions": preview,
  dry run (proven), approval, apply, and Oxidized's backups before and after the batch, the one after with the new
  description.
* `unit.sh`: checks of the installer's own functions that need no server (CI runs it with shellcheck): that both forms
  in which Semaphore UI 2.19.17's binaries print their version (`v2.19.17-f8cefe6-1792391878` of the container image,
  `2.19.17-f8cefe6-1792391818` of the release RPM) count as 2.19.17, and that another version (2.19.18, 2.19.170)
  does not.
* `stubs/`: `getenforce`, `firewall-cmd`, `semanage`, `setsebool`, ... that record their calls: with them first on
  `PATH`, a container (where SELinux is disabled and firewalld does not run) goes through the installer's SELinux and
  firewalld steps.

The CI workflow `.github/workflows/installer.yml` runs shellcheck and `unit.sh`, then the installer on Rocky Linux 9
and 10 containers (the online path: LibreNMS, its packages and Semaphore UI from GitHub), `verify.sh`, `e2e.sh`, a
second run that must change nothing, and the plugins from the repository's URL with the workflow's token; on changes
to `install/` and on demand.

## Undoing it

There is no uninstaller; what it did is listed [above](#what-it-changes-on-the-server). For the plugins alone, follow
their READMEs (*Removing*: `./lnms migrate:reset --path=...` and `./lnms plugin:remove` of each, then
`rm -rf /opt/librenms-plugins` and the two repositories of the librenms user's global Composer configuration). For the
rest, as root:

```bash
systemctl disable --now oxidized semaphore
rm -f /etc/systemd/system/oxidized.service /etc/systemd/system/semaphore.service && systemctl daemon-reload
gem uninstall -a -x oxidized oxidized-web            # and the gems they brought, if nothing else uses them
userdel -r oxidized; userdel -r semaphore
rm -rf /etc/semaphore /opt/ansible /usr/local/bin/semaphore; dnf -y remove semaphore   # whichever it was
mysql -e "DROP DATABASE semaphore; DROP USER 'semaphore'@'localhost'; DROP USER 'semaphore'@'127.0.0.1';"
cd /opt/librenms && sudo -u librenms ./lnms config:set oxidized.enabled false   # a LibreNMS that stays
rm -rf /var/lib/librenms-installer /var/log/librenms-installer /root/librenms-installer-credentials
```

and, for a LibreNMS it installed, as LibreNMS' own components are removed: `systemctl disable --now nginx php-fpm
mariadb snmpd librenms-scheduler.timer`, the files of [What it changes](#what-it-changes-on-the-server) (restore
`/etc/nginx/nginx.conf.librenms-installer`), `rm -rf /opt/librenms`, `userdel librenms`, the database `librenms`, and the
packages and repositories if nothing else needs them. With SELinux, `semanage fcontext -d` the contexts and `semodule
-r http_fping`; with firewalld, `firewall-cmd --permanent --remove-service http --remove-service https`.

## Limits

* Tested in containers (Rocky Linux 9.8 and 10.2, x86_64) in offline mode; the online downloads (LibreNMS and its
  Composer packages from GitHub, Semaphore UI's release RPM) are what the CI runs. aarch64 has every package and release
  the installer uses, but is not tested.
* In a container SELinux is disabled and firewalld does not run: their steps were tested with the stubs (the commands
  the installer runs and that a second run runs none), not against a real policy. A real server with SELinux enforcing
  may need more (`audit2why < /var/log/audit/audit.log` says what was denied).
* HTTPS is not set up (as in LibreNMS' documentation), nor are SNMP and the devices: a fresh LibreNMS has no device,
  and Oxidized waits for the first.
* Offline mode still needs dnf repositories, and Packagist's metadata for `./lnms plugin:add` (Composer resolves the
  plugins there; their only dependency comes with LibreNMS). A LibreNMS copy older than a day makes `./validate.php`
  warn that the installation is out of date until LibreNMS' daily update has run.
* `./validate.php` warns about the plugins' extra migrations while they are installed (their READMEs say why), and
  about a server without devices.
