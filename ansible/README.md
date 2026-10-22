# LibreNMS Ansible plugin

Run reviewed Ansible templates on [LibreNMS](https://www.librenms.org) devices from the LibreNMS pages, through
[Semaphore UI](https://semaphoreui.com), with an inventory built from LibreNMS, live status and log, and an audit log
that is also written to each device's eventlog. This is phase 1 of
[change automation with Ansible](../ROADMAP.md#change-automation-with-ansible).

* **Runs:** the audit log (who ran which template on which devices, when, with which answers, and what each device
  reported), with filters.
* **New run:** pick a template, the target devices and the survey answers, see exactly what will be launched, launch.
* **Run:** live status, per-device result and the Ansible log, refreshed every 2 seconds; Stop.
* **Inventory:** what the runner gets from LibreNMS, the devices left out and why, and the setup steps.
* An **Ansible** panel on each device's overview page, and **Settings** in Plugin Admin.

![A finished run on three switches with its log](docs/images/run.png)

It is a plugin of its own, next to the [topology plugin](../README.md) in this repository: the topology plugin only
reads LibreNMS data, while this one lets LibreNMS change devices, and it needs a runner with the device credentials.
Many teams will want the topology tools without that. Both appear in the same LibreNMS menu and device pages.

The plugin does not run Ansible itself and holds no device credentials. Semaphore UI keeps the credentials, the
playbooks stay in Git where they are reviewed, and the plugin only picks the targets, checks what it launches,
launches, follows and records.

| Runs | New run (with refused devices) |
|---|---|
| ![Runs](docs/images/runs.png) | ![New run](docs/images/new-run.png) |

| Inventory | Settings with a connection test |
|---|---|
| ![Inventory](docs/images/inventory.png) | ![Settings](docs/images/settings.png) |

![Device panel](docs/images/device-panel.png)

*(Screenshots: a LibreNMS test instance with simulated devices and three simulated Cisco IOS switches.)*

## Contents

* [Requirements](#requirements)
* [Installation](#installation)
* [Semaphore UI setup](#semaphore-ui-setup)
* [Settings](#settings)
* [Permissions](#permissions)
* [Using it](#using-it)
* [How it works](#how-it-works)
* [Safety](#safety)
* [Semaphore UI behaviour the plugin relies on](#semaphore-ui-behaviour-the-plugin-relies-on)
* [Limitations](#limitations)
* [Later phases](#later-phases)

## Requirements

* LibreNMS 26.x (developed and tested against 26.10), PHP 8.2+ with the curl and mbstring extensions (LibreNMS needs
  them too), and the LibreNMS scheduler (`librenms-scheduler` timer or cron, see `./validate.php`).
* [Semaphore UI](https://semaphoreui.com) 2.x; developed and tested against 2.19.17. The plugin needs only the free
  edition: API tokens, the project roles, survey variables, file inventories, environments with secrets, every launch
  option and the task output API are all free. (Pro adds project runners, external secret storages, the Docker
  executor, the per-host task summary and audit-log export; Enterprise adds high availability, custom roles and
  per-template permissions. Official builds contain the paid code and unlock it with a licence key.)
* Ansible on the runner with the collections of your platforms. The Semaphore UI container image ships Ansible with
  `cisco.ios`, `cisco.iosxr`, `cisco.nxos`, `arista.eos`, `junipernetworks.junos`, `vyos.vyos`, `community.routeros`,
  `community.ciscosmb` and `dellemc.enterprise_sonic`, among others.
* The runner must reach LibreNMS over HTTP(S) (it fetches the inventory from it), and LibreNMS must reach the runner's
  API.

## Installation

The plugin is the Composer package `agoodley/librenms-ansible-plugin` in the `ansible/` directory of this
repository. Composer cannot install a package from a subdirectory of a Git repository, so it is installed from a clone,
through a Composer *path* repository. Run everything as the `librenms` user:

```bash
sudo su - librenms

# 1. a clone of the repository (any directory the librenms user can read)
git clone https://github.com/agoodley/librenms_dashplugin /opt/librenms-plugins/librenms_dashplugin

# 2. tell composer where the package is (stored in the librenms user's global composer config, so it survives
#    LibreNMS updates)
composer config --global repositories.librenms-ansible '{"type": "path", "url": "/opt/librenms-plugins/librenms_dashplugin/ansible", "options": {"symlink": true}}'

# 3. install it with LibreNMS' plugin installer, and create its tables and its permission
cd /opt/librenms
./lnms plugin:add agoodley/librenms-ansible-plugin @dev
./lnms migrate
```

The plugin is enabled when it is installed (LibreNMS enables new plugins), but does nothing until an admin enters the
runner connection. Its pages are for LibreNMS admins: **Overview → Plugins → Ansible**, and its settings in
**Overview → Plugins → Plugin Admin → ansible → Settings**.

The plugin keeps its data in tables of its own: the runs (`ansible_runs`, `ansible_run_targets`), the runner's inventory
fetches (`ansible_inventory_fetches`) and the runner connection, encrypted (`ansible_config`). The migration also
creates the permission `ansible.run`. `./validate.php` then warns that the schema "has extra migrations
(2026_10_24_000000_create_ansible_tables)": that is the plugin's migration, and the warning stays while the plugin is
installed. Do not delete its row from the `migrations` table, and never apply validate.php's offer to drop the
`ansible_*` tables: they hold the audit log.

**Updating:** `git pull` in the clone (the path repository links to it), then `./lnms migrate`.

**Removing:**

```bash
./lnms migrate:reset --path=vendor/agoodley/librenms-ansible-plugin/database/migrations   # drops the ansible_* tables and the permission
./lnms plugin:remove agoodley/librenms-ansible-plugin
```

`./lnms plugin:remove` alone keeps the tables, so a reinstall keeps the audit log.

## Semaphore UI setup

The plugin talks to one Semaphore UI project with the token of a user who may only run tasks (the **Task Runner**
role). The runner **pulls** the inventory from LibreNMS with a script of the plugin, so LibreNMS never needs a token
that could change Semaphore. Step by step (the names are suggestions):

1. **A project for the plugin**, e.g. "LibreNMS changes". Everything below belongs to it; keep it apart from projects
   whose templates the plugin should not run.
2. **A Task Runner user and its token.** Create a user (e.g. `librenms`, not an admin), add it to the project with the
   role *Task Runner*, log in as that user and create an API token (user menu → *API Tokens*). That token goes in the
   plugin's settings. Not an Owner's or Manager's token, nor a Semaphore admin's: those may change templates, and so
   their playbook and repository, which lets whoever holds the token run any code with the stored device credentials.
   The plugin launches nothing with such a token (it asks the runner for the token's role and user before every
   preview and launch), nor with one whose user may not run tasks (*Guest*); *Test connection* says why and what to
   change.
3. **The device credentials** in the project's *Key Store*: a *Login with password* key (or an SSH key) for the
   devices, and the enable password as a key too when your platforms need it (the plugin's platform mapping sets
   `ansible_become` and `ansible_become_method: enable` for IOS, IOS-XE and EOS).
4. **The repository** of your playbooks (*Repositories*): only reviewed playbooks belong there (see [Safety](#safety)).
5. **The inventory script:** copy [`contrib/librenms_inventory.py`](contrib/librenms_inventory.py) into the
   repository, e.g. as `inventory/librenms_inventory.py`, make it executable and commit it:

   ```bash
   cp /opt/librenms-plugins/librenms_dashplugin/ansible/contrib/librenms_inventory.py inventory/
   git add inventory/librenms_inventory.py && git update-index --chmod=+x inventory/librenms_inventory.py && git commit -m "LibreNMS inventory"
   ```

   It is plain Python (standard library only): `--list` prints the inventory of the plugin, `--host NAME` one host's
   variables. It reads `LIBRENMS_URL`, `LIBRENMS_TOKEN`, and optionally `LIBRENMS_CA_BUNDLE` (a PEM file to verify
   LibreNMS' certificate with) and `LIBRENMS_TIMEOUT` (seconds, default 30); it always verifies TLS certificates,
   never follows redirects, and on an error prints what to check to stderr and exits with code 1.
6. **A LibreNMS user for the inventory** and its API token: a user whose LibreNMS role sees the devices the runner may
   change (e.g. *global-read*, or a user limited to some devices), then *API Access* in its user menu (or as an admin
   for it) → create a token. The inventory has only the devices this user may see.
7. **An environment** (*Variable Groups*): the environment variable `LIBRENMS_URL` = the LibreNMS address (e.g.
   `https://librenms.example.com`), and the **secret** environment variable `LIBRENMS_TOKEN` = the token of step 6.
   Semaphore passes both to Ansible, and so to the inventory script; secrets are write-only in Semaphore.
8. **The LibreNMS inventory** (*Inventory*): type *File*, path `inventory/librenms_inventory.py` in the repository of
   step 4, with the device key of step 3 as its user credentials (and the enable key as *Sudo credentials* when
   needed). Its id (in its address or the API) goes in the plugin's settings.
9. **Templates:** each template the plugin may launch uses the LibreNMS inventory and the environment, and has
   **Allow limit override** ticked. Without it, Semaphore silently ignores the limit and runs the template on every
   host of the inventory: the plugin refuses such templates. A template must not have a limit of its own, nor CLI
   arguments other than verbosity, `--diff`, `--check`, `--forks`, `--timeout`, `--tags`, `--skip-tags`,
   `--force-handlers` and `--flush-cache`.
10. **Survey variables** are the only variables the plugin sends, as typed answers: *String*, *Text* (several lines),
    *Integer*, *Enum* (one of a list) and *Secret*; *required* is enforced by the plugin. Their names may not be
    Ansible connection or magic variables (`ansible_*`, `hostvars`, `inventory_hostname`, `omit`, ...) nor `librenms_*`;
    environment-variable survey variables may not set `PATH`, `ANSIBLE_*`, `LIBRENMS_*`, proxies or other variables
    that change how Ansible, Python, SSH or the inventory script run. For a playbook that takes a list (such as several
    interfaces), use a *Text* variable and split its lines in the playbook.

Then, in the plugin's settings: the runner URL, the token, the project id, the inventory id, **Save connection**, and
**Test connection**: it shows the Semaphore version, the token's role, the LibreNMS inventory, and every template of
the project as usable or not, with what to change. Finally grant the run permission to the admins who may launch runs.
The Inventory page shows when the runner last fetched the inventory, as which user and how many hosts it got.

## Settings

The runner connection is saved by the plugin itself (encrypted with LibreNMS' `APP_KEY`), and only LibreNMS admins can
change it: these settings decide where the token is sent and which inventory counts as LibreNMS'. The other settings
are saved by LibreNMS (holders of its `plugin.admin` permission can change them).

| Setting | Default | Meaning |
|---|---|---|
| *Runner connection (plugin, admins only)* | | |
| Runner URL | – | Semaphore UI's address, e.g. `https://semaphore.example.com` (http or https; without `/api`) |
| API token | – | the Task Runner user's token; write-only: never shown again, never sent to a browser; an empty field keeps it |
| Project id | – | the project of the plugin (the number in its address, `/project/<id>/...`) |
| LibreNMS inventory id | – | the file inventory that runs the inventory script; only templates with this inventory can be launched |
| Verify TLS certificates | on | off only for a test lab: anyone between LibreNMS and the runner could read the token |
| CA bundle | (system CAs) | a PEM file on the LibreNMS server to verify the runner's certificate with |
| Timeout | 15 s | per call to the runner (1–120) |
| *Targets and runs (LibreNMS)* | | |
| Platform mapping | the defaults below | one line per LibreNMS os: `os ansible_network_os [connection] [become]`; a device whose os has no line cannot be a target |
| Allowed device group | every device | only the members of this LibreNMS device group are in the inventory and can be targets |
| Max targets per run | 25 | 1–500 |
| Output size | 1024 KB | the log kept per run (16–65536 KB); the runner keeps the whole log |
| Lost after | 24 h | a run still unfinished after this long ends as *lost* (1–720 h) |
| *Run permission (plugin, admins only)* | | which admins may launch and stop runs ([Permissions](#permissions)) |

The default platform mapping, for platforms whose LibreNMS os names and Ansible collections were both checked:

```text
ios          cisco.ios.ios                   network_cli  become
iosxe        cisco.ios.ios                   network_cli  become
iosxr        cisco.iosxr.iosxr               network_cli
nxos         cisco.nxos.nxos                 network_cli
arista_eos   arista.eos.eos                  network_cli  become
junos        junipernetworks.junos.junos     network_cli
vyos         vyos.vyos.vyos                  network_cli
routeros     community.routeros.routeros     network_cli
ciscosb      community.ciscosmb.ciscosmb     network_cli
dell-sonic   dellemc.enterprise_sonic.sonic  network_cli
```

The connection is `network_cli`, `netconf`, `httpapi` (short for `ansible.netcommon.*`), `ssh`, `paramiko` or a full
plugin name; `become` sets `ansible_become: true` and `ansible_become_method: enable`. Junos uses `network_cli` because
its `netconf` connection needs the `ncclient` Python library on the runner, which the Semaphore UI image does not
have: with it installed, `junos junipernetworks.junos.junos netconf` lets the Junos resource modules work. Platforms
not listed (Cisco ASA, ArubaOS-CX, Fortinet, ...) need their collection on the runner first, then a line here.

## Permissions

* **Viewing** the runs, a run, the inventory, the JSON and the device panel is for LibreNMS **admins**. Everyone else
  gets HTTP 403 on every route of the plugin and sees no menu entry, panel or button.
* **Launching and stopping** runs also needs the plugin's own permission, `ansible.run`, which an admin grants on the
  settings page (*Run permission*). Admins do not get it automatically: an admin without it sees everything, but no
  *New run* or *Stop*, and the plugin answers 403 to such requests.
* The permission keeps launch rights explicit and visible; it is no barrier between admins (an admin can grant it,
  also to themselves).
* `ansible.run` is a **direct permission of the user**, not of a role. LibreNMS' role editor (*Roles*) saves only the
  permissions of its own list (`resources/definitions/permissions.yaml`), so a plugin permission given to a role is
  dropped the next time someone saves that role; its user editor sets roles only, and keeps a user's direct
  permissions. (Both were checked in LibreNMS 26.10.) The permission is read from the user's own permissions, because
  LibreNMS lets admins pass every permission check.
* **The inventory endpoint** (`/plugin/ansible/api/inventory`) takes a LibreNMS API token (as `X-Auth-Token`, like
  LibreNMS' API, or as a Bearer token) instead of a session, and answers with the devices that token's user may see.
* The **settings** of the runner connection and the run permission are for admins; LibreNMS limits the settings page
  itself to holders of `plugin.admin`.

## Using it

* **New run** (*Runs → New run*, or *Run a template on this device* in a device's Ansible panel): choose a template
  (the ones the plugin cannot launch are listed with the reason), then the targets: filter by device group, location,
  platform or text; each device shows its inventory name, platform, LibreNMS status and, when it cannot be a target,
  why (disabled, no platform mapping, outside the allowed group, busy in another unfinished run, not visible to the
  inventory's user, no usable address). Answer the survey variables, then **Preview**: it shows exactly what will be
  launched: the template, the targets with their inventory names, refused devices, the answers (secrets masked) and
  the limit. **Launch** checks everything again against the runner and opens the run.
* **Run:** the status, the template, who launched it and when, each target's result with the PLAY RECAP counters, the
  answers, and the log with Ansible's colours, followed every 2 seconds while the run is unfinished. **Stop** asks the
  runner to stop the task (Ansible finishes the step it is in on each device).
* **Runs:** filter by device, user, template, status and dates; each run lists its targets and their results. The
  *JSON* button gives the same list.
* **Inventory:** the hosts with their variables and groups, the groups, the devices left out and why, the runner's
  last fetch (when, as which user, how many hosts) and a JSON download of the inventory.
* **Device panel:** the device's latest runs (template, when, who, result) and a link to run a template on it.
* **Eventlog:** each target gets an entry (type `ansible`) when its run starts ("Ansible run #12 started: template
  'Port descriptions' by admin") and when it ends, with its result and counters; the severity follows the result
  (ok, info for skipped, notice for changed, warning for not run, error for failed and unreachable). The reference is
  the run's address (`plugin/ansible/runs/12`).

## How it works

* **Inventory:** built from LibreNMS' devices by the plugin for the user of the API token: the devices that user may
  see that are enabled, in the allowed device group (when set), have a platform mapping and a usable address. A
  host's name is its LibreNMS hostname when that is a plain Ansible host name (letters, digits, `.`, `_`, `-`, not
  starting with `-` or `.`, at most 253 characters), else `device<id>` (IPv6 addresses, names with other characters);
  a name used twice (ignoring case) goes to the device with the lowest id, and a host never has a group's name (a
  limit naming a group would select all of its hosts). Names are computed over every LibreNMS device, so they do not
  depend on which devices a user may see. Host variables: `ansible_host` (the device's overwrite IP, else its
  hostname), `ansible_network_os`, `ansible_connection` (and `ansible_become` / `ansible_become_method`) from the
  platform mapping, and `librenms_device_id`, `librenms_hostname`, `librenms_os`, `librenms_sysname`,
  `librenms_location`, `librenms_hardware`, `librenms_version`, `librenms_type`. Groups: `os_<os>`,
  `platform_<ansible_network_os>`, `group_<device group>`, `location_<location>` and `type_<device type>`, in
  `[a-z0-9_]`, numbered when two names collide. The answer is Ansible's dynamic inventory JSON with `_meta.hostvars`.
* **Templates:** before every preview and launch the plugin asks the runner for the template again (no cache) and
  launches it only when its inventory is the LibreNMS one (a file inventory), it allows the limit override, it has no
  limit of its own, its CLI arguments are on the short list above, its survey variables have safe names and known
  types, and the token's user may run it.
* **Launch:** the targets are checked again (the plugin's own run of the target check, as the inventory's user sees
  them), at most *Max targets per run*; then, under a lock (LibreNMS' cache), the plugin checks that no target is busy
  in another unfinished run and records the run before it asks the runner: two launches cannot take the same device,
  and a form that is submitted twice starts one run (each form has a one-time token). The runner gets the template id,
  the limit (the targets' inventory names), the answers that are not secret as extra variables, the secret ones in
  Semaphore's write-only secret field, and a message "LibreNMS Ansible run #N by user"; nothing else.
* **Following:** the run page asks the plugin every 2 seconds, and the plugin asks the runner at most every 2 seconds
  per run (a lock per run keeps two pages and the sync command from doing it at once). The `ansible:sync` command does
  the same every minute for every unfinished run (through the LibreNMS scheduler, one at a time, on one server, while
  the plugin is enabled and its connection set up; output in `logs/ansible-sync.log`), so the audit log is complete
  when nobody watches. The output is stored as it comes, up to *Output size*.
* **Results:** when the runner reports the task finished, the plugin reads the output once more (Semaphore reports
  the end before the last lines are readable) and takes each target's result from the last `PLAY RECAP`: unreachable,
  failed, changed, ok, skipped, or *not run* for a target the recap does not name. A target that failed on its
  connection without any task succeeding counts as unreachable (network connections report an unreachable device as a
  failed task). A host in the recap that is not a target flags the run, on its page and with an error in the
  eventlog. A run whose task the runner no longer has, or that is unfinished after *Lost after*, ends as *lost*.

## Safety

* **The runner token is a Task Runner's**: before every preview and launch the plugin asks Semaphore for the token's
  role in the project (`GET /api/project/{p}/role`) and its user (`GET /api/user`), and launches nothing when the
  role is Owner or Manager or the user is a Semaphore admin (they could change templates), or when the role may not
  run tasks. The token is stored encrypted (Laravel's `Crypt` with LibreNMS' `APP_KEY`), never
  shown again or sent to a browser, never in an error message or log, and only sent to the URL an admin saved with it.
  Calls to the runner verify TLS certificates (unless an admin turns that off), never follow redirects, and time out.
* **Only reviewed templates and playbooks:** the plugin launches only templates that pass the checks above, and sends
  only the survey answers, never playbooks, inventories, branches, CLI arguments or configuration text. But a Task
  Runner token can start any template of the project and pass any extra variables to it, and a task may name any
  playbook file of the repository: anyone who steals the token can run any playbook in that repository on the
  inventory's devices, and likely run commands on the runner through Ansible's connection variables. So the repository
  behind the plugin's project must hold only reviewed playbooks, the project only the plugin's templates, and the
  token must be guarded like the device credentials. Semaphore Enterprise's per-template permissions and custom roles
  narrow it further.
* **Who:** admins only, and only those holding `ansible.run` may launch and stop.
* **Targets:** only devices that exist, are enabled, in the allowed group, mapped, not busy, visible to the
  inventory's user, at most the max; the limit is made only of the plugin's own inventory names, which cannot be
  Ansible patterns (no `,` `:` `!` `&` `*` `?` `[` `]` `~` `@`) or group names. Ansible applies the limit to every
  play of a playbook: a play on `localhost` or on a host the playbook adds at run time is skipped (checked with
  Ansible core 2.20); tasks delegated to another host still run there, on behalf of a target.
* **Network data is untrusted:** sysName, location, hardware, version and display, device group and location names
  can be anything a device or user reported. Before they go into the inventory, control and format characters become
  spaces and everything outside letters, digits, space and `.,:_-/()@+=` is dropped (so no `{`, `}`, `%` or `#`, and no
  Jinja can reach a playbook), at most 128 characters. Group names are reduced to `[a-z0-9_]`. An address that starts
  with `-` or has other characters (an SSH option, a scope) leaves the device out.
* **Answers** are checked on the server by type: a whole number, one of the choices, or text without control
  characters (new lines only in *Text*), without Jinja delimiters (`{{ }}`, `{% %}`, `{# #}`: Ansible renders every
  string it is given), at most 1,000 characters (10,000 for *Text*); required ones must be given. Secret answers go
  to the runner only: never into the database, the eventlog, a log line (they are marked as sensitive parameters, so
  PHP leaves them out of stack traces), the task message or a page.
* **Output** is untrusted too: it is stored capped and shown escaped; ANSI colours become a fixed set of CSS classes,
  other escape sequences and control characters are dropped, and the run page adds new lines as text, never as HTML.
* **Inventory endpoint:** read only (GET), a LibreNMS API token (a session cookie is not enough), scoped to the
  token user's devices, cleaned values, no credentials (no SNMP data), JSON.
* **CSRF** on every POST; errors are JSON that says what to fix.

## Semaphore UI behaviour the plugin relies on

Checked against Semaphore UI 2.19.17:

* A task is started with `POST /api/project/{p}/tasks` and `{"template_id", "message", "environment", "secret",
  "params": {"limit": [...]}}`; the plugin never sends `inventory_id`, `playbook`, `git_branch` or `arguments`.
* Survey answers go in `environment` (a JSON object; JSON types are kept, so the plugin sends whole numbers as
  numbers, while Semaphore's own page sends them as text), secret ones in `secret`: Semaphore passes them to Ansible
  as extra variables, does not store them, and does not return them with the task. An answer to a survey variable of
  the *environment* kind becomes an environment variable of the run, not an extra variable. Semaphore's API does not
  enforce *required*: the plugin does.
* With *Allow limit override*, the task's limit replaces the template's own limit, and a `--limit` in the template's
  CLI arguments too (the task's comes later); but a `-e` in the arguments overrides the survey answers (later extra
  variables win), and in Ansible an `-i` adds an inventory. Without the override, Semaphore ignores the task's limit and
  runs on every host of the inventory, while the launch answer still shows the limit; a task's `inventory_id` is
  ignored too.
* Task statuses: `waiting`, `starting`, `waiting_confirmation`, `confirmed` (queued), `running`, `stopping`, and the
  final `success`, `error`, `stopped` and `rejected` (a refused confirmation; the plugin counts it as failed). The tasks of one template run one after the other: a second
  launch of a template waits in the queue. A stopped queued task stays `stopping` until it would have started.
* `GET .../tasks/{id}/output` returns the whole log at every call, one record per line with ANSI colour codes; the
  task is reported finished before its last lines can be read. `POST .../tasks/{id}/stop` needs the body
  `{"force": false}` (an empty body is refused). `GET .../tasks/{id}` answers HTTP 400 for a task it does not have.
* The per-host task summary is a paid feature; the plugin reads the `PLAY RECAP` itself.

## Limitations

* One runner (Semaphore UI) and one project. The runner interface is ready for an AWX adapter (job templates,
  `ask_limit_on_launch`, `survey_spec`, `/api/v2/job_templates/{id}/launch/`), which would also need a configurable
  API path and authentication: AWX's next release puts its API behind a gateway.
* Semaphore's survey variables are single values: a playbook that changes a list of things takes a *Text* variable
  and splits its lines. Survey variables are the only way to give a run variables.
* The plugin trusts the template's playbook once the template passes its checks; what the playbook does is reviewed
  in Git, not by the plugin.
* Between the check of a template and the launch, someone with Semaphore rights could change the template; the
  plugin checks again at every launch, but cannot close that window.
* The results come from the PLAY RECAP: a playbook that runs several plays shows the totals per host. A device that
  fails on its connection after an earlier task succeeded counts as failed, not unreachable.
* A run page shows at most *Output size* of the log; the runner keeps all of it.
* The target list loads every device of LibreNMS at once (fine for thousands of devices, slow for many more).
* Runs are not batched yet: all targets of a run go at once (at most *Max targets per run*).

## Later phases

See [ROADMAP.md](../ROADMAP.md#change-automation-with-ansible): dry runs (`--check --diff`) with the diff per device,
approvals, batches that stop at the first failures, Oxidized backups before and after, maintenance windows (phase
2); a rollout plan and the blast radius from the topology plugin (phase 3); verification of the topology before and
after (phase 4). The runner interface already carries the check and diff flags of the dry runs.

Other LibreNMS inventory plugins for Ansible exist (e.g. `0uwl/librenms-inventory-plugin`); they do not map LibreNMS'
os to `ansible_network_os` or clean the network data, which is why this plugin builds its own.

## License

GPL-3.0-or-later, like LibreNMS.
