# Roadmap

The plugin's tools and what could come next. Items 1 to 17 are built and described in the [README](README.md). Ideas
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
| 17 | [Switch port capacity](README.md#switch-port-capacity): free, unused, err-disabled and PoE ports per switch and wiring closet | **done** | – |

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
  in this repository, and its diagnostic runs (show commands in check mode, from the maps too) and the reports of its
  runs and changes (HTML to print or save as PDF, Word, ZIP).
* **Switch port capacity:** built into this plugin after all ([item 17](README.md#switch-port-capacity)): it reads the
  same port data as the interface health checks, by device group and location, and its record of the ports lives with
  the topology history.
* **Config compliance via Oxidized:** built, as [a plugin of its own](compliance/README.md) in this repository: rule
  checks on the configuration backups LibreNMS shows on a device's Config tab (Oxidized, RANCID or Unimus): portfast and
  BPDU guard on edge ports, no portfast toward a switch, root guard toward the switches below (what each port faces
  from LibreNMS' LLDP/CDP and spanning tree data), AAA, NTP, syslog, SNMP and SSH baselines, and rules of your own in
  YAML; hourly checks, the findings with the lines of the backups, the eventlog, a device panel, an API for other
  plugins. It also confirms the STP page's guess about edge ports without portfast (or rules it out).
* **Inventory & lifecycle:** approved software versions per model, end-of-life dates, serial numbers, optics
  inventory (`transceivers`); planned in phases [below](#inventory--lifecycle), of which phase 1 (the inventory reports)
  is built, as [a plugin of its own](inventory/README.md) in this repository.
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
| 5 | **Done:** [diagnostic runs](ansible/README.md#diagnostic-runs), as NetBrain's runbooks run show commands from a map: a template run in check mode (`--check --diff`) on the devices chosen (on its Diagnostics page, a device's panel, or the devices selected on this plugin's maps), as no change: no approval, batches, backups or windows, as its log must prove that every task ran in check mode (an unproven one is said in red, as a dry run's); its page shows what each task printed per device, the devices that printed the same grouped side by side. An example show commands playbook for Cisco IOS, set up by the installer | 2 |
| 6 | **Done:** [reports and exports](ansible/README.md#reports-and-exports), as NetBrain exports a runbook's results: each run and each change exports what its page shows as a self-contained HTML report (to print or save as PDF), a Word document, or a ZIP with the raw data (each device's output or diff, the logs, CSV and JSON) | 5 |

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
Phase 1 is built, as [a plugin of its own](inventory/README.md) in this repository (`inventory/`); the next phases wait
for a real-world test of the plugins built so far.

* **Why a separate plugin:** it is about the devices themselves, not the topology between them.
* **Data:** LibreNMS already collects it. The plugin adds no polling: it reads each device's model, software version,
  serial number and features (`devices`), its modules with their serial numbers and revisions (`entPhysical`, LibreNMS'
  *Inventory* page) and its optics (`transceivers`: type, vendor, model, serial, wavelength, DOM).
* **What it adds:** policy and lifecycle on top of that data. It follows this plugin's conventions: scoped
  repositories, the analysis in plain PHP with unit tests, background checks that write to the eventlog.

| Phase | Scope | Effort |
|---|---|---|
| 1 | **Built.** Inventory reports: models and software versions across the network; device and module serial numbers; optics by type, vendor, model and wavelength, third-party optics included. Filtered by device group and location, exported as CSV, with a panel on each device page; and a search of serial and part numbers | S–M |
| 2 | Software compliance: approved, minimum and blocked versions per model or OS; findings for the devices that do not comply; a scheduled check whose eventlog entries alert rules can match; a history of the upgrades it saw | M |
| 3 | Lifecycle: end-of-sale, end-of-software-maintenance and end-of-support dates per model or part number; contracts and warranties per serial number; reports such as "past end of support" or "ending within 12 months" per location; optics in ports unused for N days | M |
| 4 | Changes and upgrades: hardware changes recorded (a module, optic or serial number swapped), as the topology history records links; optionally, upgrade campaigns through the [Ansible plugin](ansible/README.md): the devices that do not comply in one change, planned, dry-run, approved, applied in batches and verified | M–L |

* **Open decisions:**
  * where end-of-life dates come from: LibreNMS has none; the sources that exist are listed
    [below](#sources-of-end-of-life-dates), with a proposal (an import, plus endoflife.date and Cisco's EoX API as
    optional connectors);
  * whether contracts and warranties belong here or in an asset system;
  * whether phase 4's upgrade campaigns are in scope: OS upgrades and reloads would be the riskiest automation of these
    plugins;
  * where the plugin lives: decided, in this repository (`inventory/`), as the Ansible and compliance plugins.
* Effort: M–L overall.

#### Sources of end-of-life dates

Researched in November 2026. There is no free, open dataset of network hardware end-of-life dates; what exists:

| Source | Covers | Access | Notes |
|---|---|---|---|
| [endoflife.date](https://endoflife.date) ([GitHub](https://github.com/endoflife-date/endoflife.date), MIT) | software releases of 487 products; of network systems only Cisco IOS XE, Palo Alto PAN-OS, FortiOS, F5 BIG-IP, MikroTik RouterOS, OpenWrt, OPNsense and Stormshield (firmware, management center and firewalls) | a free JSON API without a key (`https://endoflife.date/api/v1/products/<slug>`, in beta) | nothing for Junos, Arista EOS, NX-OS, classic IOS, IOS XR, ASA or Aruba, and no switch or router models |
| Cisco's EoX API | end of sale, end of software maintenance and last date of support, per product ID or serial number | a Smart Net Total Care or partner support contract and an app registered in Cisco's API Console (OAuth client credentials) | the only official machine-readable source of hardware dates; LibreNMS' `devices.hardware` and `entPhysicalModelName` hold Cisco product IDs; the NetBox plugin [netbox-cisco-support-api](https://pypi.org/project/netbox-cisco-support-api/) uses it, and Nautobot's lifecycle app needs a job of your own for it |
| Juniper, Arista, HPE Aruba | end-of-life notices | web pages, no public API | Juniper shows them in Routing Director, a paid product |
| Aggregators: [EOSL.ai](https://github.com/EOSL-AI), [eol.network](https://eol.network), [eosl.date](https://eosl.date), [endoflife.ai](https://endoflife.ai) | hardware of many vendors, the dates linked to the vendors' bulletins | APIs or web pages; licences unclear (one listing calls EOSL.ai's data CC BY 4.0, not confirmed by EOSL.ai) | their dates disagree with each other and with endoflife.date (Cisco IOS XE), so a starting point to check against the vendor's notice |
| [OpenEoX](https://www.oasis-open.org/committees/openeox) (OASIS) | a machine-readable JSON format of general availability, end of sales, end of security support and end of life | a standard, not a dataset: Core Schema v1.0 finished its public review in August 2026 ([comments](https://github.com/oasis-tcs/openeox/issues)); CISA endorsed it in February 2026 and asked vendors to publish it free of charge; Cisco takes part | no vendor publishes it yet |

Proposal for phase 3: the plugin keeps the dates in a table of its own, each with its source, filled from a CSV/JSON
import shaped like OpenEoX (it works without an outside connection, and vendor feeds fit it once they exist), from
endoflife.date for the operating systems it covers (refreshed daily; needs outbound HTTPS from the LibreNMS server) and,
for those with a Cisco support contract, from Cisco's EoX API. Dates edited by hand win over imported ones.
