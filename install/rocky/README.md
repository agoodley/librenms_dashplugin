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
* [Device SSH host keys](#device-ssh-host-keys)
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
# then add the devices to LibreNMS, and confirm their SSH host keys by their fingerprints:
install/rocky/device-keys.sh
# devices with a login of their own (the others use the one above): a line each in /root/device-logins.csv, then
install/rocky/device-logins.sh
```

Ansible and Oxidized refuse a device whose SSH host key they do not know: `device-keys.sh` takes the keys of the
devices in LibreNMS and installs those you confirm ([Device SSH host keys](#device-ssh-host-keys)). Every device logs in
with the default login unless `device-logins.sh` gives it its own ([Device logins](#device-logins)).

Run the installer from a reviewed, root-owned checkout whose parent directories are not writable by service
accounts. Do not run the copy under `/opt/librenms-plugins` as root. Privileged cron, scheduler, SNMP, logrotate,
command-wrapper and completion files now come from the installer's bundled templates, based on LibreNMS 26.10.0,
instead of the live service-owned `/opt/librenms` tree. Keep those templates reviewed when upgrading LibreNMS.

On upgrade, unchanged installer-managed Oxidized configurations gain `input.ssh.secure: true` and `vars.ssh_host_key`
([Device SSH host keys](#device-ssh-host-keys)). Manually edited configurations are preserved with a warning: set
both yourself and provision verified keys.
Repair playbooks now save the entire running configuration to startup after all fixes on that host succeed.
That includes unrelated pending changes: review that scope before approving an apply. Dry runs never save.

On a server that has a LibreNMS already, name one of its admins: `install/rocky/install.sh --admin-user netadmin`. The
Ansible plugin's run and approve permissions go to them, and Semaphore UI's admin takes their name and e-mail (with
`--admin-password` as its password, else a generated one in the credentials file). Without `--admin-user` the
installer looks for the admin `admin`, and stops before it changes anything when LibreNMS has none of that name.

It asks once before it starts (`--yes` for unattended runs), says what it does part by part and ends with a summary:
where each part answers, the passwords and tokens it generated (in `/root/librenms-installer-credentials`, root only,
never on the screen or in the log) and what to do next. Everything it runs is logged in `/var/log/librenms-installer/`
(`latest.log` is the last run). A first run takes 5 to 30 minutes (5 in offline mode on a fast server), most of it dnf,
Composer and the compilation of Oxidized's gems.

Passwords and tokens never go on a command line either, where every user of the server can read them (`ps`):
commands get them on stdin, in their environment or in files of mode 600. Two places need a way round that.
Semaphore UI's admin is made with a throwaway password (its command line tool takes a password only as an argument)
while Semaphore UI does not run, and gets its real password as a bcrypt hash in Semaphore UI's database before it
starts. LibreNMS' tables come from its migrations, not from its schema dump, which Laravel loads with the database
password on the mysql client's command line.

## What it installs

The parts, in the order a run sets them up (`--skip` and `--only` name them):

| Part | What | How |
|---|---|---|
| `librenms` | LibreNMS, only when the server has no `/opt/librenms`: the newest 26.x release (the tag, found among the repository's tags at install time) on LibreNMS' release update channel, so that `daily.sh` keeps it on releases; `--librenms-ref` installs another tag, a branch or a commit (`master`, LibreNMS' development branch, is a moving target that its documentation installs, and puts it on the master channel). The summary says which: `git describe` of `/opt/librenms` | as [LibreNMS' install documentation](https://docs.librenms.org/Installation/Install-LibreNMS/) does it on Rocky 9 and 10: EPEL, Remi's repository with the `php:remi-8.5` module stream, its packages (PHP-FPM, nginx, MariaDB, net-snmp, fping, rrdtool, its Python modules), the `librenms` user, `/opt/librenms` with its permissions, `composer install`, PHP's time zone, MariaDB's settings, the PHP-FPM pool, the nginx site, SELinux and firewalld, snmpd, the cron jobs, the scheduler timer and logrotate; then what its web installer does: the database and its user, `.env`, `lnms migrate --seed`, `config.php` and the first admin; `./validate.php` at the end, after LibreNMS' poller cron job ran once as cron runs it; and PAM's account check of the `librenms` user for crond, as crond makes it before each job (it stops the run when cron could run none of them: LibreNMS would never poll) |
| `plugins` | both plugins of this repository | as their READMEs describe it: one clone in `/opt/librenms-plugins/librenms_dashplugin`, two Composer *path* repositories in the `librenms` user's global Composer configuration, `./lnms plugin:add` of each, `./lnms migrate`, both enabled; all as the `librenms` user |
| `ansible` | Ansible where Semaphore UI runs it | a Python 3.12 virtual environment in `/opt/ansible` with the versions the Semaphore UI 2.19.17 image ships, which the plugin was tested with ([`files/ansible-requirements.txt`](files/ansible-requirements.txt)): `ansible` 13.5.0, so ansible-core 2.20.10 and the collections the plugin's platform mapping names (`cisco.ios`, `cisco.iosxr`, `cisco.nxos`, `arista.eos`, `junipernetworks.junos`, `vyos.vyos`, `community.routeros`, `community.ciscosmb`, `dellemc.enterprise_sonic`, `ansible.netcommon`, `ansible.utils`, ...), and paramiko; a dry run checks that it prints the check-mode markers the plugin's dry run proof needs (`ANSIBLE_CHECK_MODE_MARKERS`) |
| `semaphore` | Semaphore UI 2.19.17 (the version the plugin was tested with) and the project for LibreNMS | its release RPM (or tarball or binary), its own MariaDB database and user, `/etc/semaphore/config.json`, the service `semaphore` as the user `semaphore`, on `127.0.0.1:3000`, its admin; then the project "LibreNMS changes" as the [Ansible plugin's README](../../ansible/README.md#semaphore-ui-setup) sets it up: the key store entries "Devices: SSH login" and "Devices: enable password" (the devices' login of the options, or empty and named), a Git repository on the server (`file:///var/lib/semaphore/playbooks.git`) with the plugin's inventory script and the example fix playbooks of `ansible/contrib/`, the inventory "LibreNMS" (the script), the variable group "LibreNMS" (`LIBRENMS_URL`, `ANSIBLE_CHECK_MODE_MARKERS=true`, the secret `LIBRENMS_TOKEN`), the templates "Fix port descriptions" and "Fix spanning tree root priority", and the Task Runner user `librenms` with its API token. In LibreNMS: the inventory user `ansible-inventory` (role global-read: it sees the devices and changes nothing) with its API token; the Ansible plugin's runner connection, inventory user and fix templates, saved as its settings page saves them (with its checks and eventlog entries); the run and approve permissions of the admin; its connection test |
| `oxidized` | Oxidized 0.37.0 with oxidized-web 0.18.1 (the versions the plugin was tested with), on by default | as [Oxidized's documentation](https://github.com/ytti/oxidized#rocky-linux-red-hat-enterprise-linux) installs it on Rocky: EPEL, CRB, Ruby (the `ruby:3.3` module stream on Rocky 9), the build packages, the gems, the user `oxidized` and the service `oxidized`; its devices come from LibreNMS' API (the LibreNMS user `oxidized`, global-read, with an API token of its own), its versions go to git, its REST API listens on `127.0.0.1:8888`, the devices' login is the one of the options; LibreNMS' Oxidized settings point at it (`oxidized.enabled`, `oxidized.url`, `oxidized.features.versioning`, `oxidized.reload_nodes`). A LibreNMS that already uses another Oxidized keeps it |

**A LibreNMS the server has already** (one in `/opt/librenms` that the installer did not install) keeps its web
server, PHP, MariaDB settings, `.env` and `config.php` as they are. The installer checks that its version is one the
plugins support (26.10 or a later 26.x: they were developed and tested against 26.10) and stops with what to do when
it is not (update it, `./daily.sh`), checks PHP 8.2+ with curl and mbstring, that `--admin-user` names one of its
admins (Quick start), and warns when its scheduler does not run; then it adds the plugins and the rest: `./lnms
plugin:add` changes LibreNMS' `composer.json` and `composer.lock` (as the plugins' READMEs install them), `./lnms
route:cache` renews its route cache (when it has one), and the installer adds to LibreNMS' database (the plugins'
tables, the users `ansible-inventory` and `oxidized` and their API tokens, the plugins' settings and LibreNMS'
Oxidized settings) and, under SELinux, turns on `httpd_can_network_connect`. Semaphore UI gets a database of its own
in the server's MariaDB (installed when the server has none).

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
| `--admin-user NAME` | `INSTALLER_ADMIN_USER` | `admin` | the LibreNMS admin who gets the Ansible plugin's run and approve permissions, and Semaphore UI's admin: created in a LibreNMS the installer installs; in a LibreNMS installed otherwise, one of its admins (the run stops before it changes anything when there is none) |
| `--admin-password PW` | `INSTALLER_ADMIN_PASSWORD` | generated | their password when they are created (LibreNMS and Semaphore UI alike) |
| `--admin-email EMAIL` | `INSTALLER_ADMIN_EMAIL` | `<admin user>@<server name>` | the e-mail of an admin the installer makes: the LibreNMS admin's, and Semaphore UI's admin's when LibreNMS has none for them (Semaphore UI's admin gets the LibreNMS admin's e-mail; e-mails are unique in Semaphore UI) |
| `--server-name NAME` | `INSTALLER_SERVER_NAME` | `hostname -f` | nginx' `server_name` for LibreNMS |
| `--timezone ZONE` | `INSTALLER_TIMEZONE` | the system's | PHP's time zone (`date.timezone`) |
| `--php-stream STREAM` | `INSTALLER_PHP_STREAM` | `remi-8.5` | PHP's module stream: Remi's 8.5 as LibreNMS documents it, or `remi-8.4`; `8.3` takes Rocky's own PHP (LibreNMS 26.10 then warns that 8.4 is its minimum) |
| `--librenms-url URL` | `INSTALLER_LIBRENMS_URL` | `http://localhost` | how Semaphore UI (the inventory script) and Oxidized on this server reach LibreNMS' API; for a LibreNMS installed otherwise, the first of its `APP_URL`, `http://localhost` and `https://localhost` that answers |
| `--device-user USER` | `INSTALLER_DEVICE_USER` | none | the devices' SSH login, for Semaphore UI's key store and Oxidized; without it both are set up empty, to fill in later |
| `--device-password PW` | `INSTALLER_DEVICE_PASSWORD` | | |
| `--device-enable-password PW` | `INSTALLER_DEVICE_ENABLE_PASSWORD` | the device password | for platforms with an enable mode (IOS, IOS-XE, EOS) |
| `--plugin-source URL\|DIR` | `INSTALLER_PLUGIN_SOURCE` | the run before's, else `https://github.com/agoodley/librenms_dashplugin` | this repository: a Git URL, a local Git checkout (it may be one that root alone may read, in `/root` say), or a plain directory; a private GitHub repository takes `GITHUB_TOKEN` from the environment (given to git as a header, never stored in the clone) |
| `--plugin-branch BRANCH` | `INSTALLER_PLUGIN_BRANCH` | the run before's (with its source), else `main` | for a local Git checkout: its checked-out branch |
| `--librenms-source SRC` | `INSTALLER_LIBRENMS_SOURCE` | `https://github.com/librenms/librenms.git` | a Git URL, or a directory or `.tar.gz` of a LibreNMS with its `vendor/` installed |
| `--librenms-ref REF` | `INSTALLER_LIBRENMS_REF` | the newest 26.x release tag | what of the Git URL to install: a tag (`26.10.0`), a branch (`master`: LibreNMS' daily development, on its master update channel) or a commit; the plugins support LibreNMS 26.10 and later 26.x |
| `--semaphore-source FILE` | `INSTALLER_SEMAPHORE_SOURCE` | the GitHub release | Semaphore UI 2.19.17 as its `.rpm`, `.tar.gz` or binary |
| `--composer-source FILE` | `INSTALLER_COMPOSER_SOURCE` | getcomposer.org | `composer.phar`, when LibreNMS has none |
| `--pip-wheels DIR` | `INSTALLER_PIP_WHEELS` | PyPI | Python wheels for Ansible and LibreNMS' Python modules |
| `--gems DIR` | `INSTALLER_GEMS` | RubyGems | `.gem` files for Oxidized |
| `--distro-source FILE` | `INSTALLER_DISTRO_SOURCE` | GitHub | librenms-agent's `distro` script (snmpd's `extend distro`) |
| `--offline DIR` | `INSTALLER_OFFLINE` | | all of these from one directory ([Offline mode](#offline-mode)) |
| `--semaphore-listen ADDR`, `--semaphore-port PORT` | `INSTALLER_SEMAPHORE_LISTEN`, `INSTALLER_SEMAPHORE_PORT` | `127.0.0.1`, `3000` | another address than a loopback one also opens the port in firewalld |
| `--oxidized-listen ADDR`, `--oxidized-port PORT` | `INSTALLER_OXIDIZED_LISTEN`, `INSTALLER_OXIDIZED_PORT` | `127.0.0.1`, `8888` | the same for Oxidized's REST API |
| `--device-known-hosts FILE` (or `--oxidized-known-hosts`) | `INSTALLER_DEVICE_KNOWN_HOSTS` (or `INSTALLER_OXIDIZED_KNOWN_HOSTS`) | the file there stays | the devices' SSH host keys, verified (OpenSSH known_hosts format), installed as `/etc/ssh/ssh_known_hosts` for Ansible and Oxidized ([Device SSH host keys](#device-ssh-host-keys): `device-keys.sh` makes and installs such a file) |

Options that cannot be used stop the run before it changes anything, with what is wrong (`install.sh: --skip:
unknown part 'ansibel' (the parts: librenms plugins ansible semaphore oxidized)`). A port that another program holds
stops the run before the service is started (the error names the option that moves it).

## Device SSH host keys

Ansible (the runs Semaphore UI makes for the Ansible plugin) and Oxidized check every device's SSH host key, and refuse
a device whose key they do not know or that changed: whoever answers on a device's address must not get the devices'
login. Both read `/etc/ssh/ssh_known_hosts`, as every SSH client of the server does, and only root may change it.
`--device-known-hosts FILE` installs FILE (an OpenSSH known_hosts file) there; a later run without the option leaves the
file as it is, and so does every run when the file there is the server's own (add the keys to it yourself then).
Without the keys, no change gets past its dry run and Oxidized takes no backup: the run warns when there are none.

`install/rocky/device-keys.sh` makes that file for the devices in LibreNMS, and installs it. Run it as root once the
devices are in LibreNMS, and again when a device is added or gets a new key:

```bash
install/rocky/device-keys.sh             # every device Ansible or Oxidized connects to
install/rocky/device-keys.sh sw13 sw14   # only these (hostname, sysName, IP address or LibreNMS device id)
install/rocky/device-keys.sh --list      # whether the file has each device's keys; changes nothing
```

* **Where:** it asks LibreNMS where Ansible connects to each device (the Ansible plugin's inventory: the overwrite IP,
  else the hostname) and where Oxidized does (LibreNMS' API: the device's IP, as `[IP]:port` with another SSH port),
  takes the keys found there with ssh-keyscan (RSA, ECDSA and Ed25519), and compares them with
  `/root/device-known-hosts` (`--file FILE` for another file). Until that file exists, it starts from the keys installed
  now.
* **New keys:** for a device whose keys the file lacks, it shows their fingerprints and asks whether the device shows
  the same, on its console or through another source you trust: a scan alone does not prove who answered. The keys you
  confirm go into the file under each of the device's addresses, below a line that says whose they are and when. Keys
  the file holds for another address of the same device are added without asking, since no one else can answer with
  them.
* **Changed keys:** a key that changed is shown next to the one the file has. Ansible and Oxidized refuse the device
  until you confirm the new key, and the old line stays in the file as a comment.
* **Installing:** at the end it installs the file as `/etc/ssh/ssh_known_hosts`, as `--device-known-hosts` does; a file
  there of the server's own stays, and it says so. Keep to that one file: `install.sh --device-known-hosts` with
  another file would replace what `device-keys.sh` installed.
* **`--accept-new`** adds the keys of devices the file has none for without asking, as ssh's
  `StrictHostKeyChecking=accept-new` does: only on a network you trust, and never a key that changed.
* **Exit code** 3: the file still lacks the keys of a device that answered (not confirmed, or changed); with `--list`,
  there is something to confirm. 0: nothing is missing.

A device may show its fingerprints in another form: `ssh-keygen -l -E md5 -f /root/device-known-hosts` lists the MD5
ones, and a device that shows its public key itself can be compared with the file's line.

By hand, take each device's keys under the address Ansible and Oxidized connect to (`[address]:port` for another port
than 22), with every key type it has:

```bash
ssh-keyscan 10.0.0.11 10.0.0.12 >> /root/device-known-hosts   # each device's keys, of every type it offers
ssh-keygen -lf /root/device-known-hosts                         # their fingerprints
```

then compare each fingerprint with the one the device shows on its console, or another source you trust. Then run the
installer with `--device-known-hosts /root/device-known-hosts`, and again when a device is added or gets a new key.

How Ansible and Oxidized check the keys:

* **Ansible:** Semaphore UI runs it with `ANSIBLE_HOST_KEY_CHECKING=False`. The installer gives the project's variable
  group "LibreNMS" the extra variable `ansible_host_key_checking: true`, which outweighs that (it adds it when the group
  has no such variable: set it to `false` there to turn the check off, which hands the devices' login to whoever
  answers on a device's address).
* **Oxidized:** `input.ssh.secure: true`, and `vars.ssh_host_key` asks for an RSA key's SHA-2 signatures (rsa-sha2-512,
  rsa-sha2-256) first. net-ssh would ask for the SHA-1 one (ssh-rsa) first, which Rocky's crypto policy refuses to
  verify (`EVP_DigestVerifyInit: invalid digest` in Oxidized's log). A device that has nothing but ssh-rsa (an old IOS
  release) cannot be checked under Rocky's DEFAULT policy: update its software, or allow SHA-1 signatures for the whole
  server (`update-crypto-policies --set DEFAULT:SHA1`, weaker for every program on it). That list takes a device's key
  types in a fixed order rather than the order of the keys the file has, so the file must hold every key of a device
  (ssh-keyscan takes them all); Oxidized's per-device variables can give a device its own `ssh_host_key`.
* An earlier installer put the keys in `/home/oxidized/.ssh/known_hosts`: a run removes that copy while it holds what
  `/etc/ssh/ssh_known_hosts` holds, and warns about it otherwise (Oxidized trusts the keys of both).

## Device logins

Every device logs in with the default login, the one the installer was given (`--device-user` and
`INSTALLER_DEVICE_PASSWORD`, a RADIUS account for instance), unless it has a login of its own.
`install/rocky/device-logins.sh` gives devices their own logins, from a CSV file that root alone may read
(`/root/device-logins.csv`; `--file FILE` for another). The file has a header line, then a line per device that has its
own login; `#` starts a comment line:

```text
device,username,password,enable
sw5.example.com,localadmin,S3cret!,En4ble!
10.20.0.7,admin2,"pass,with,commas",
```

* **`device`:** its hostname, sysName, IP address or LibreNMS device id.
* **`enable`:** its enable password. When it is empty, the password itself is used.
* Quote a value that has a comma or a quote, as spreadsheets export it; other columns are left out.

Run it as root once the devices are in LibreNMS, and again after each change of the file (`--list` shows what it would
change, and changes nothing):

```bash
(umask 077; vi /root/device-logins.csv)    # root's alone: it holds the passwords
install/rocky/device-logins.sh --list
install/rocky/device-logins.sh
```

What it does for each device of the file:

* **Oxidized** (the backups): a group `login-<device id>` with the device's login in Oxidized's configuration, which the
  installer keeps on its later runs. LibreNMS gives Oxidized the device's group (`oxidized.maps.group.hostname`, with
  `oxidized.group_support` on). Its backups go to the same repository as the others' (`single_repo`), as
  `login-<id>/<hostname>`: the ones from before it had its own login stay under its name.
* **Ansible** (the plugin's changes): `inventory/host_vars/<name>.yml` in the playbook repository, named as the
  plugin's inventory names the device. It sets `ansible_user` and reads the passwords from the secrets
  `DEVICE_LOGIN_<id>_PASSWORD` and `DEVICE_LOGIN_<id>_ENABLE` of the variable group "LibreNMS": Semaphore UI puts them
  in the environment of the project's runs, and they never go into the repository. These variables outweigh the login
  of the project's key store, which the other devices use.
* **A device whose line is gone** goes back to the default login: its group, mapping, secrets and host_vars file are
  removed. The run asks first, and `--yes` lets it do so without asking (a run without a terminal stops with what it
  would remove). An empty file with only the header line removes every device's own login.

The passwords stay out of LibreNMS: they are in the file, in Oxidized's configuration (the oxidized user's alone) and
in Semaphore UI's secrets, and never on the screen, in a log or on a command line. The run's state
(`/var/lib/librenms-installer/state`) keeps only a salted hash of each device's login, to tell what changed.

In LibreNMS, the Ansible plugin marks these devices *own login*, read only, on its Inventory page and in their device
panel, from LibreNMS' Oxidized group mapping (never the login itself). When one does not apply as `device-logins.sh`
gave it (a renamed device, group support turned off, another map rule of LibreNMS' that wins, a device that is gone),
the marker says why and what to do ([Own logins](../../ansible/README.md#using-it)).

Things to know:

* LibreNMS gives a device its group by its hostname: run it again after renaming a device in LibreNMS.
* LibreNMS' other entries of `oxidized.maps.group` stay as they are. A map of another field (os, location) that
  matches one of these devices gives it that group instead, and with it that group's login.
* A change in progress keeps the logins it was dry-run with: its batches run the commit of its dry run.
* A host_vars file of the same name that `device-logins.sh` did not write stays as it is (it warns), and so does an
  Oxidized configuration changed by hand: then it stops, and the groups are yours to add.

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

DIR, and the paths the options give, may be readable by root alone (in `/root`, say): what the `librenms` user installs
from them, LibreNMS' Python wheels and a Git checkout of the plugins, is copied for it into the run's own directory,
which goes with the run.

## What it changes on the server

**Packages and repositories:** `epel-release`, `remi-release` (Remi's repositories with HTTPS mirror lists), the
`php:remi-8.5` module stream (with `--php-stream` another), CRB (for Oxidized's build packages), the `ruby:3.3`
module stream on Rocky 9; the packages of LibreNMS' documentation and nginx, `python3.12` on Rocky 9, Ruby and
Oxidized's build packages, `mariadb-server` when the server has none; Semaphore UI's RPM. Outside dnf:
`/opt/librenms`, `/opt/librenms-plugins`, `/opt/ansible` (pip), the gems in `/usr/local` (`/usr/local/bin/oxidized`),
LibreNMS' missing Python modules with pip as librenms (`/opt/librenms/.local`), `/usr/local/bin/semaphore` (from a tarball or
binary), `/usr/bin/distro`, `/usr/local/bin/composer` (only when an existing LibreNMS has no Composer).

**Users:** `librenms` (home `/opt/librenms`), `semaphore` (`/var/lib/semaphore`), `oxidized` (`/home/oxidized`). In
LibreNMS: the admin of `--admin-user` (in a LibreNMS it installs), `ansible-inventory` and `oxidized` (global-read)
with an API token each. In Semaphore UI: the admin and the Task Runner user `librenms`. In MariaDB:
`librenms@localhost` and `semaphore@localhost`/`semaphore@127.0.0.1` with their databases.

**Services:** `mariadb`, `php-fpm`, `nginx`, `snmpd`, `crond`, `librenms-scheduler.timer` (for a LibreNMS it installs),
`semaphore`, `oxidized`, all enabled.

**Ports:** 80 (nginx: LibreNMS) and 161/udp (snmpd, community in the credentials file) on every address; 3000
(Semaphore UI) and 8888 (Oxidized) on 127.0.0.1 only, unless `--semaphore-listen` or `--oxidized-listen` say
otherwise; 3306 (MariaDB) on 127.0.0.1 only when the installer installed MariaDB (`bind-address` in
`/etc/my.cnf.d/90-librenms.cnf`), while a MariaDB the server had keeps listening where it did (Rocky's MariaDB listens
on every address).

**Files:** `/opt/librenms/.env` made readable by `librenms` only (mode 640: it holds the database password and
`APP_KEY`), `/etc/php.d/90-librenms.ini` (PHP's time zone), `/etc/my.cnf.d/90-librenms.cnf` (MariaDB's settings for
LibreNMS, and `bind-address=127.0.0.1` for a MariaDB the installer installed), `/etc/php-fpm.d/librenms.conf`,
`/etc/nginx/conf.d/librenms.conf`, `/etc/nginx/nginx.conf` without its default server (as LibreNMS' documentation
says; the original is kept as `/etc/nginx/nginx.conf.librenms-installer`), `/etc/snmp/snmpd.conf` (while it is the
package's), `/etc/cron.d/librenms`, `/etc/logrotate.d/librenms`,
`/etc/systemd/system/librenms-scheduler.{service,timer}`, `semaphore.service` and `oxidized.service`,
`/etc/semaphore/config.json`, `~oxidized/.config/oxidized/config`, `/etc/ssh/ssh_known_hosts` from
`--device-known-hosts` or `device-keys.sh` (unless the server has one of its own) and `device-keys.sh`'s
`/root/device-known-hosts`, `/usr/bin/lnms`, the bash completion of lnms; for `device-logins.sh` (from your
`/root/device-logins.csv`): Oxidized's groups, LibreNMS' `oxidized.group_support` and `oxidized.maps.group.hostname`,
the variable group's secrets `DEVICE_LOGIN_*` and the playbook repository's `inventory/host_vars/`; the
librenms user's global Composer configuration (`/opt/librenms/.config/composer/config.json`: the two path
repositories); the installer's own `/var/lib/librenms-installer/` (what earlier runs did),
`/var/log/librenms-installer/` and `/root/librenms-installer-credentials`.

**SELinux** (when it is enforcing or permissive), as LibreNMS' documentation sets it: the file contexts of
`/opt/librenms` (`html`, `rrd`, `storage`, `logs`, `cache`, `librenms-service.py`) with `restorecon`, `.env`'s context,
the booleans `httpd_can_sendmail`, `httpd_execmem` and `httpd_can_network_connect_db`, and its `http_fping` policy module;
then `httpd_can_network_connect`, which LibreNMS' PHP needs to reach Semaphore UI and Oxidized (LibreNMS' Oxidized
documentation asks for it too). Semaphore UI and Oxidized run as systemd services of their own (unconfined).

**firewalld** (when it runs), in its default zone, now and permanently: the services `http` and `https` for a LibreNMS it
installs; Semaphore UI's and Oxidized's ports when they listen on another address than a loopback one.

## Running it again, and runs that stop

A run checks every part and changes only what is not right; when nothing is, it says *Nothing needed a change*. It
never undoes what an admin set up since: a file it wrote and someone changed (the nginx site, Oxidized's
configuration, a playbook in the playbook repository) stays as it is, with a warning; a plugin an admin disabled stays
disabled; the key store entries keep their secrets unless the devices' login of the options changed; settings of the
plugins other than the connection, the inventory user and the fix templates are left alone. A run with another
`--admin-user` makes that admin Semaphore UI's admin and removes the one an earlier run made (the state remembers
which).

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
3. **The devices' SSH host keys:** `install/rocky/device-keys.sh` takes the keys of the devices you added and asks you
   to confirm each device's by its fingerprints ([Device SSH host keys](#device-ssh-host-keys)). Until then Ansible and
   Oxidized refuse the devices: no backup, and no change past its dry run. Run it again when you add a device or one
   gets a new key.
4. **Devices with a login of their own:** a line each in `/root/device-logins.csv`, then `install/rocky/device-logins.sh`
   ([Device logins](#device-logins)); the others keep the default login.
5. **The devices' login**, when you did not give it: in Semaphore UI (project "LibreNMS changes", *Key Store*: "Devices:
   SSH login" and "Devices: enable password") and in `~oxidized/.config/oxidized/config` (`username`, `password`,
   `vars: enable`), then `systemctl restart oxidized`. Or run the installer again with `--device-user` and
   `INSTALLER_DEVICE_PASSWORD`. Oxidized does not start while LibreNMS has no device (its service tries again every
   minute).
6. **Semaphore UI** listens on the server only: `ssh -L 3000:127.0.0.1:3000 root@<server>`, then `http://localhost:3000`
   (the admin's login).
7. **The first change:** *Overview > Plugins > Ansible > Changes > New change* (or *Fixes*). Review the example fix
   playbooks first (Cisco IOS); add your own to the playbook repository (as the semaphore user, who owns it: `sudo -u
   semaphore git clone /var/lib/semaphore/playbooks.git /tmp/playbooks`, then commit and push as semaphore) with a
   template each, as the Ansible plugin's README describes. Its defaults ask for an approval (the admin holds the
   approve permission: grant it to others in the plugin's settings), refuse a batch without a fresh Oxidized backup,
   and verify each batch with the topology plugin (which waits for LibreNMS' discovery and polling of the devices).

## Testing it

`install/rocky/tests/` has what the installer was tested with, in containers with systemd as PID 1:

* `Dockerfile`, `entrypoint.sh`: a Rocky Linux test system (`docker build --build-arg
  BASE=quay.io/rockylinux/rockylinux:10 -t inst-rocky10 install/rocky/tests`, `docker run -d --privileged --name
  inst-r10 -v $PWD:/src:ro inst-rocky10`). Extra CA certificates (behind a TLS-intercepting proxy) go into
  `install/rocky/tests/ca/` as `*.crt` or `*.pem`, which git ignores; without them the directory holds only its
  `.gitignore`. The units that would touch the host when the container shares its network (firewalld, NetworkManager,
  networkd, resolved, udev, sysctl, chrony, tuned, ...) are masked, and the entrypoint makes `/proc/sys` and `/sys`
  read-only. On a host whose AppArmor confines PAM's helper `unix_chkpwd` (Ubuntu 24.04, as on GitHub's runners), the
  helper cannot read Rocky's `/etc/shadow` (mode 000) in the container. Rocky's PAM reads it only through that helper,
  so cron runs none of LibreNMS' jobs there, and the installer stops at its check of that. Add `capability
  dac_read_search,` (newer AppArmor releases grant it) to `/etc/apparmor.d/local/unix-chkpwd` on the host, then run
  `apparmor_parser -r /etc/apparmor.d/unix-chkpwd`, as CI does.
* `verify.sh [--no-cron-wait]`: what a run should have made: cron polling on its own (it waits past the next */5 tick
  for a poll by LibreNMS' poller cron job, newer than the installer's own, and finds no PAM refusal of librenms in
  crond's journal), LibreNMS on nginx, `./validate.php`, the admin's login and both plugins' pages, both plugins
  enabled, Semaphore UI's project, the Ansible plugin's connection test as its settings page runs it, the inventory
  endpoint, Oxidized and LibreNMS' Oxidized settings.
* `e2e.sh NAME IP`: a fake Cisco IOS switch added to LibreNMS, then a change through the Ansible plugin's pages with
  the template "Fix port descriptions": preview, dry run (proven), approval, apply, and Oxidized's backups before and
  after the batch, the one after with the new description (`E2E_ADD_ONLY=1`: only the switch in LibreNMS;
  `SWITCH_USER` and `SWITCH_PASSWORD`: a switch with a login of its own). `fake-switch.sh NAME IP [USER PASSWORD]`
  starts the switch in a test system (SSH only, by default `netops` / `netops-pw`): [fake-switches](https://pypi.org/project/fake-switches/) 1.5.0 with
  `fake-switches.patch` (`show privilege`, `terminal`, `end`, the enable password under Python 3, `spanning-tree vlan
  <list> priority <n>`: what `cisco.ios` and the example playbooks need), on the versions of
  `fake-switches-requirements.txt` (fake-switches asks for a Twisted that no longer builds on Python 3), as the lab
  runs it. It writes the switch's host key to `/root/ci-device-known-hosts` for `--device-known-hosts`; like most IOS
  releases, the switch offers its RSA key's SHA-1 signature besides the SHA-2 ones. `read-switch-config.py` reads its
  running or startup configuration (e2e.sh: the dry run leaves startup as it was, the apply saves the description).
* `security-integration.sh IP`: what a service account must not reach through the installer (Git hooks, writes through
  its symlinks, SQL in a database password), what it reads through a copy (paths that root alone may read), and the
  host key checks against the fake switch: Oxidized with its configuration (unknown and changed keys refused, the
  known one checked by its SHA-2 signature) and Ansible as Semaphore UI runs it (`ANSIBLE_HOST_KEY_CHECKING=False` and
  the variable group's `ansible_host_key_checking`: known passes, changed and unknown do not).
* `device-keys-integration.sh NAME IP`: `device-keys.sh` after `e2e.sh`: it finds the fake switch where Ansible and
  Oxidized connect to it and knows its key; a file without the key gets it only once confirmed, and is installed; a
  changed key is shown, left out with `--accept-new` and taken once confirmed; it refuses a device LibreNMS does not
  have and a run while the installer runs; at the end the keys installed before are installed again.
* `device-logins-integration.sh NAME IP USER PASSWORD OTHER OTHER_IP`: `device-logins.sh` with a second fake switch
  whose only login is its own: it gets its group in Oxidized and LibreNMS' mapping, the variable group's secrets and
  its host_vars file; a change on it through the Ansible plugin works with Oxidized's backups before and after, and
  still on the switch of the default login; its line removed (once confirmed), its login is gone everywhere; then it
  gets it back, which the installer's next run keeps.
* `diagnose.sh [--run-poller]`: crond and its journal, LibreNMS' cron file, pollers and log, the services; with
  `--run-poller` then the poller cron job as cron runs it. CI prints it into the job log when a step fails, and
  `verify.sh` when LibreNMS' poller check fails (it then waits for cron's next run and checks again).
* `unit.sh`: checks of the installer's own functions that need no server (CI runs it with shellcheck): both forms in
  which Semaphore UI 2.19.17's binaries print their version (`v2.19.17-f8cefe6-1792391878` of the container image,
  `2.19.17-f8cefe6-1792391818` of the release RPM) count as 2.19.17, another version (2.19.18, 2.19.170) does not;
  passwords and tokens survive the JSON helpers that keep them off command lines; only plain time zone names pass
  (`--timezone` ends up in php.ini); then `test_*.py`, the installer's functions in fixtures (firewalld, listener URLs,
  Semaphore UI's settings and variable group, Oxidized's configuration, the device keys, the files root installs, Git,
  pip and SQL as the service accounts see them, copies of what they may not read) and `device-keys.sh`'s core against
  a fake ssh-keyscan (new, changed, revoked and wildcard keys, a device's name and IP address, its SSH port, what it
  asks and what it writes), and `device-logins.sh`'s parts (its CSV file and what it says of it, Oxidized's groups
  written and read back, the variable group's secrets against a fake API).
* `stubs/`: `getenforce`, `firewall-cmd`, `semanage`, `setsebool`, ... that record their calls: with them first on
  `PATH`, a container (where SELinux is disabled and firewalld does not run) goes through the installer's SELinux and
  firewalld steps.

The CI workflow `.github/workflows/installer.yml` runs shellcheck and `unit.sh`, then the installer on Rocky Linux 9
and 10 containers (the online path: LibreNMS, its packages and Semaphore UI from GitHub), `verify.sh`, a run with the
fake switch's host key (`--device-known-hosts`), `security-integration.sh`, `e2e.sh`, `device-keys-integration.sh`,
`device-logins-integration.sh` with a second fake switch, a second run that must change nothing, the plugins from a checkout in `/root` and from the repository's URL with the
workflow's token; on changes to `install/` and on demand.

## Undoing it

There is no uninstaller; what it did is listed [above](#what-it-changes-on-the-server). For the plugins alone, follow
their READMEs (*Removing*: `./lnms migrate:reset --path=...` and `./lnms plugin:remove` of each, then
`rm -rf /opt/librenms-plugins` and the two repositories of the librenms user's global Composer configuration). For the
rest, as root (first the devices' own logins, while Oxidized and Semaphore UI are there: an empty file of logins):

```bash
printf 'device,username,password,enable\n' >/root/device-logins.csv && install/rocky/device-logins.sh --yes
systemctl disable --now oxidized semaphore
rm -f /etc/systemd/system/oxidized.service /etc/systemd/system/semaphore.service && systemctl daemon-reload
gem uninstall -a -x oxidized oxidized-web            # and the gems they brought, if nothing else uses them
userdel -r oxidized; userdel -r semaphore
rm -rf /etc/semaphore /opt/ansible /usr/local/bin/semaphore; dnf -y remove semaphore   # whichever it was
mysql -e "DROP DATABASE semaphore; DROP USER 'semaphore'@'localhost'; DROP USER 'semaphore'@'127.0.0.1';"
cd /opt/librenms && sudo -u librenms ./lnms config:set oxidized.enabled false   # a LibreNMS that stays
rm -rf /var/lib/librenms-installer /var/log/librenms-installer /root/librenms-installer-credentials
rm -f /etc/ssh/ssh_known_hosts /root/device-known-hosts   # if it holds only the devices' keys (device-keys.sh's)
rm -f /root/device-logins.csv
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
* `./validate.php` warns "Your database schema has extra migrations (...)": the 17 migrations it lists
  (2026_10_20_000000_create_topology_history_tables to 2026_11_05_000000_ansible_change_verification) are the plugins'
  own tables, and LibreNMS warns so for every plugin package with migrations while it is installed (the plugins'
  READMEs say more). It is expected; never apply validate.php's offer to drop their tables (they hold the topology
  history and the Ansible plugin's audit log). It also warns about a server without devices.
