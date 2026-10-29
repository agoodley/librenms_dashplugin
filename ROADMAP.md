# Roadmap

The plugin's tools and what could come next. Items 1 to 15 are built and described in the [README](README.md). Ideas
that do not fit this plugin are listed as [separate plugins](#ideas-for-separate-plugins).

The plugin's conventions, which every tool follows and a new one should too:

* database access only in `src/Data/`, in repositories that extend `ScopedRepository` so every query stays inside
  the devices the user may see; a feature adds its own repository rather than growing `TopologyRepository`;
* a LibreNMS table that a role permission guards is read only when the user's role may view it
  (`ScopedRepository::TABLE_MODELS`, `allowsTable()`), and a page lists the tables it reads in `Support\RoleData`, so
  it says what the role hides;
* the analysis in plain PHP classes with no LibreNMS dependency, covered by unit tests
  (like `StpAnalyzer`, `LinkAnalyzer` and `RedundancyAnalyzer`);
* a page is listed in `Support\Navigation` (the navigation of every page, the plugin menu and the landing page) and
  routed in `routes/web.php`; a feature with its own pages gets its own controller, built on the `page()` and `json()`
  helpers of `Http\Controllers\Concerns\BuildsTopology`;
* every finding says what is wrong and how to fix it, and noisy checks can be switched off in the settings.

## Status

| # | Tool | Status | Effort |
|---|---|---|---|
| 1 | [Link & VLAN diagnostics](README.md#link--vlan-diagnostics) | **done** | – |
| 2 | [Host locator and port tab](README.md#host-locator-and-port-tab) | **done** | – |
| 3 | [Redundancy & failure impact](README.md#redundancy--failure-impact) | **done** | – |
| 4 | [Routing & IP diagnostics](README.md#routing--ip-diagnostics) | **done** | – |
| 5 | [Topology history, diff and baseline](README.md#topology-history-baseline-and-background-checks) | **done** | – |
| 6 | [Background diagnostics → eventlog](README.md#topology-history-baseline-and-background-checks) | **done** | – |
| 7 | [Automatic device dependencies](README.md#device-dependencies) | **done** | – |
| 8 | [Site / WAN map](README.md#site--wan-map) | **done** | – |
| 9 | [More L3 map layers](README.md#layer-3-map-overlays) | **done** | – |
| 10 | [Loop evidence on the STP page](README.md#spanning-tree-diagnostics) | **done** | – |
| 11 | [Sharing & export](README.md#sharing--export) | **done** | – |
| 12 | [Monitoring coverage](README.md#monitoring-coverage) | **done** | – |
| 13 | [A→B path trace](README.md#path-trace) | **done** | – |
| 14 | [Rollout planner](README.md#rollout-planner) | **done** | – |
| 15 | [Maps at a point in time](README.md#maps-at-a-point-in-time) | **done** | – |
| 16 | [Interface health](README.md#interface-health): flapping links and speeds, half duplex and errors on every port | **done** | – |

Effort: S = new checks or an overlay on data the plugin already loads; M = new queries plus a page or tab;
L = several new parts (tables, jobs, UI).

## What a plugin can do in LibreNMS

* Hooks: menu entry, single page, settings page, device overview panel and **port tab** (used by the host locator).
  There is no hook for dashboard widgets or device tabs.
* A plugin package can ship routes, views and **database migrations**, and register **scheduled commands**
  (LibreNMS runs Laravel's scheduler).
* The only ways onto a LibreNMS dashboard are indirect: the eventlog widget (the background checks write their
  findings there) and the Custom Map widget (a map exported as a [Custom Map](README.md#sharing--export)).

## Ideas for separate plugins

These are useful but are not about topology, or would change devices, so they fit better in plugins of their own.

* **Change automation with Ansible:** push configuration changes to all or some devices from LibreNMS, made safer by
  this plugin's topology data. It is the largest of these ideas, so it is worked out
  [below](#change-automation-with-ansible); its four phases are built, as [a plugin of its own](ansible/README.md)
  in this repository.
* **Switch port capacity:** ports unused for N days (`ifLastChange` and uptime), err-disabled ports, free (PoE) ports
  per wiring closet or location.
* **Config compliance via Oxidized:** rule checks on backed-up configurations (portfast and BPDU guard on edge ports,
  root guard on downlinks, AAA / NTP / SNMPv3 baselines). It could also confirm the STP page's guess about edge ports
  without portfast.
* **Inventory & lifecycle:** approved software versions per model, end-of-life dates, serial numbers, optics
  inventory (`transceivers`); planned in phases [below](#inventory--lifecycle), not started.
* **NetBox sync:** push LLDP-discovered cables, IP addresses and VLANs to NetBox, or compare NetBox's documented cabling
  with what LLDP sees. It could reuse this plugin's link de-duplication.

### Change automation with Ansible

Push configuration changes (NTP, syslog, SNMP and AAA servers, VLANs, ACLs, port descriptions) to all or some devices
from the LibreNMS pages.

* **Why a separate plugin:** this plugin only reads LibreNMS data. Pushing changes means LibreNMS can change every
  device, and it needs SSH credentials and an Ansible runtime. Many teams will want the topology tools without that. It
  still appears in the same LibreNMS menu and device pages.
* **Engine:** Ansible, for its vendor coverage (IOS, NX-OS, EOS, Junos, Aruba CX, ...) and its dry runs with diffs. It
  runs in a runner with a REST API, not as `ansible-playbook` started by LibreNMS:
  * [Semaphore UI](https://semaphoreui.com) by default: open source (MIT), one binary and a database. Check which
    features are only in its paid edition.
  * [AWX](https://github.com/ansible/awx) for full RBAC, approvals and a credential vault. It installs on Kubernetes,
    and has had no release since 24.6.1 (2 July 2024): its refactoring was merged in January 2026, the web UI moved to
    a repository of its own (April 2026) and a new gateway fronts the API at `/api/controller/v2/` (May 2026), but as
    of October 2026 there is no release or release date. Its job template API (`/api/v2/job_templates/{id}/launch/`,
    `/jobs/{id}/stdout/`, `/jobs/{id}/cancel/`) is unchanged, while its OAuth2 token endpoints are gone, so an AWX
    adapter needs a configurable API path and authentication.

  The runner keeps the SSH credentials and the playbooks stay in Git, where they are reviewed. The plugin picks the
  targets, launches, follows and verifies. A small adapter interface keeps other runners possible.
* **No raw CLI by default:** the plugin launches reviewed templates with device lists and structured variables (for
  example a list of interface and description pairs), not configuration text typed into the browser.
* **Not chosen:** Nornir with NAPALM (fast, vendor-neutral diffs and rollback, but no UI, API or permissions: a service
  to write and maintain), Salt (heavier, less common for networks), Nautobot Golden Config (built around intended
  configuration, a different model from LibreNMS' discovered state). Oxidized does not push, but takes the backups
  before and after a change.

| Phase | Scope | Needs |
|---|---|---|
| 1 | **Done:** [the Ansible plugin](ansible/README.md). Connection to the runner; an Ansible inventory from LibreNMS (device groups, locations, LibreNMS `os` mapped to `ansible_network_os`; devices without a mapping are refused); run a template on selected devices; live status and log; an audit log (who, what, when, result), also in each device's eventlog | – |
| 2 | **Done:** [changes](ansible/README.md#how-a-change-works). A dry run (`--check --diff`) the plugin proves from the log (`ANSIBLE_CHECK_MODE_MARKERS`), with the diff per device; approval, optionally by a second admin, bound to the dry run, the template as the runner has it and its commit, and checked again before every batch; batches (a first one on its own, then batches of a set size) that stop at the first failures; Oxidized backups before and after each batch (LibreNMS' Oxidized client, `node/next`); a LibreNMS maintenance window per batch | 1 |
| 3 | **Done:** [rollout plan and blast radius](ansible/README.md#rollout-plan-and-blast-radius) from this plugin, through a [planning API](README.md#planning-api-for-other-plugins) for other plugins (the data of `api/rollout` and `api/simulate`, scoped as its pages). A new change is planned in the planner's order (farthest from the core first, the core last, never two devices that back each other up in one batch), the devices it cannot plan left out or planned by hand; what each batch cuts off is approved with the change and computed again before each batch and right before its launch: a batch whose blast radius grew waits for an approver, one whose blast radius cannot be computed for an admin's explicit decision (or the settings refuse them); the maintenance window also covers what each batch cuts off. Without the topology plugin, phase 2's plan with the blast radius unknown | 1 |
| 4 | **Done:** [verification](ansible/README.md#verification) of each batch through a [verification API](README.md#verification-api-for-other-plugins) for other plugins: the state of the network around the batch (its devices, what it cuts off, their neighbors and spanning tree domains: the topology history's facts and the background checks' findings) before it and after it on fresh LibreNMS data, compared; a batch that lost an uplink, moved a root bridge or made a new single point of failure pauses the change until an approver accepts it (or the settings stop it); the change ends verified, verified with accepted changes, or not verified. [One-click fixes](ansible/README.md#fixes) of the findings with a clear configuration (port descriptions, STP root priority), as data checked field by field, applied and verified like any change | 1; this plugin's topology history and background checks (items 5 and 6) |

* **Safety:**
  * admin only, ideally with a permission of its own; the runner's API token may only launch the plugin's templates;
  * data discovered from the network is untrusted: an LLDP/CDP neighbor can advertise any name, newlines and commands
    included. Strip control characters, allow a safe character set and cap the length before it goes into a
    configuration; port descriptions named after neighbors are the first case;
  * commit-confirm where the platform has it (Junos `commit confirmed`, IOS-XR, EOS configure sessions), so a change
    that cuts off management rolls itself back;
  * never everything at once: every run goes in batches.
* Keep it to picking targets, launching, following and verifying on top of the runner, not a configuration management
  product.
* Effort: L overall; each of the four phases was M.

### Inventory & lifecycle

Software versions, end-of-life dates, serial numbers and optics across the network, checked against what is approved.
Not started: it waits for a real-world test of the plugins built so far.

* **Why a separate plugin:** it is about the devices themselves, not the topology between them.
* **Data:** LibreNMS already collects it. The plugin adds no polling: it reads each device's model, software version,
  serial number and features (`devices`), its modules with their serial numbers and revisions (`entPhysical`, LibreNMS'
  *Inventory* page) and its optics (`transceivers`: type, vendor, model, serial, wavelength, DOM).
* **What it adds:** policy and lifecycle on top of that data. It follows this plugin's conventions: scoped
  repositories, the analysis in plain PHP with unit tests, background checks that write to the eventlog.

| Phase | Scope | Effort |
|---|---|---|
| 1 | Inventory reports: models and software versions across the network; device and module serial numbers; optics by type, vendor, model and wavelength, third-party optics included. Filtered by device group and location, exported as CSV, with a panel on each device page | S–M |
| 2 | Software compliance: approved, minimum and blocked versions per model or OS; findings for the devices that do not comply; a scheduled check whose eventlog entries alert rules can match; a history of the upgrades it saw | M |
| 3 | Lifecycle: end-of-sale, end-of-software-maintenance and end-of-support dates per model or part number; contracts and warranties per serial number; reports such as "past end of support" or "ending within 12 months" per location; optics in ports unused for N days | M |
| 4 | Changes and upgrades: hardware changes recorded (a module, optic or serial number swapped), as the topology history records links; optionally, upgrade campaigns through the [Ansible plugin](ansible/README.md): the devices that do not comply in one change, planned, dry-run, approved, applied in batches and verified | M–L |

* **Open decisions:**
  * where end-of-life dates come from: LibreNMS has none, so a CSV/JSON import and manual edits (no outside
    connection), or a vendor API (Cisco's EoX API needs API credentials; most vendors offer none);
  * whether contracts and warranties belong here or in an asset system;
  * whether phase 4's upgrade campaigns are in scope: OS upgrades and reloads would be the riskiest automation of these
    plugins;
  * whether the plugin lives in this repository, as the Ansible plugin does, or in its own.
* Effort: M–L overall.
