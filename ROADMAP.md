# Roadmap

Ideas for the plugin's next tools, roughly in the order they are worth building. Every item uses data LibreNMS
already collects; the tables and modules each one needs are listed so it can be picked up directly.

The plugin's conventions apply to all of them:

* database access only in `src/Data/`, in repositories that extend `ScopedRepository` so every query stays inside
  the devices the user may see; a feature adds its own repository rather than growing `TopologyRepository`;
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
| 3 | [Redundancy & failure impact](README.md#redundancy--failure-impact) | **done** | – |
| 14 | [Rollout planner](README.md#rollout-planner) | **done** | – |
| 7 | [Automatic device dependencies](README.md#device-dependencies) | **done** | – |
| 9 | [More L3 map layers](README.md#layer-3-map-overlays) | **done** | – |
| 2 | [Host locator and port tab](#2-host-locator-where-is-this-plugged-in-and-port-tab) | next | M |
| 5 | [Topology history, diff and baseline](#5-topology-history-diff-and-baseline) | planned | M–L |
| 6 | [Background diagnostics → eventlog](#6-background-diagnostics--eventlog) | planned, after 5 | S |
| 4 | [Routing & IP diagnostics](#4-routing--ip-diagnostics) | planned | M |
| 12 | [Coverage report](#12-coverage-report) | planned | S |
| 10 | [Loop evidence on the STP page](#10-loop-evidence-on-the-stp-page) | planned | S–M |
| 8 | [Site / WAN view](#8-site--wan-view) | later | M |
| 11 | [Sharing & export](#11-sharing--export) | later | M each |
| 13 | [A→B path trace](#13-ab-path-trace) | later, after 2 | L |

Effort: S = new checks or an overlay on data the plugin already loads; M = new queries plus a page or tab;
L = several new parts (tables, jobs, UI).

**Why this order:** 2 answers the most frequent day-to-day question. 5 and 6 share the same scheduled job and tables and
turn every check into something that runs without anyone opening a page. The rest builds on those.

## What a plugin can do in LibreNMS

* Hooks: menu entry, single page, settings page, device overview panel and **port tab**. The port tab is not used yet.
  There is no hook for dashboard widgets or device tabs.
* A plugin package can ship routes, views and **database migrations**, and register **scheduled commands**
  (LibreNMS runs Laravel's scheduler).
* The only ways onto a LibreNMS dashboard are indirect: the eventlog widget (see 6) and the Custom Map widget (see 11).

## Next up

### 2. Host locator ("where is this plugged in?") and port tab

* Enter an IP, MAC or hostname; get the switch port, VLAN, vendor, first/last seen and 802.1X user, highlighted on the
  L2 map.
* LibreNMS' own FDB search lists every switch that learned the MAC and only marks a port when it holds exactly one
  MAC, which misses phones with a PC behind them, APs and VM hosts. The plugin knows which ports are inter-switch
  links and port-channels, so it can rule them out and find the edge port.
* **Port tab** on every port page: the device and port at the far end, STP role per VLAN, VLANs, port-channel, MACs and
  IPs learned on the port. It runs on every port page view, so it must stay cheap.
* Data: `ipv4_mac` (ARP), `ipv6_nd`, `ports_fdb` (`created_at` / `updated_at` give first/last seen), `ports_nac`
  (802.1X; the `nac` poller module is off by default), `vendor_ouis`.
* Caveat: FDB and ARP tables are refreshed at discovery (every 6 hours by default); the `arp-table` poller module (off
  by default) refreshes ARP every poll. Show "last seen" with every answer.

## Planned

### 5. Topology history, diff and baseline

* A scheduled job snapshots links, port-channels, STP roots and blocked ports, and OSPF/BGP adjacencies into plugin
  tables (store changes, not full copies).
* **What changed since yesterday / last week:** links added or removed, a port whose neighbor changed (moved cable),
  a new unmanaged switch or AP, root bridge moved, adjacency lost.
* **Baseline:** save the current topology as the expected one and report what is missing. LibreNMS deletes LLDP rows
  that are no longer seen, so a redundant uplink that disappears leaves no trace today: the redundancy page reports a
  lost uplink only until the next discovery. With a baseline it can keep reporting it.
* Later: show the maps as they were at a given time.
* Needs: migrations, a scheduled command, a retention setting.

### 6. Background diagnostics → eventlog

* Run the STP and link checks on a schedule and write new and resolved findings to the LibreNMS eventlog. They then
  show on device pages and in the eventlog dashboard widget, and alert rules can match them.
* Keep a findings history (first seen, resolved) and mark "new since the last run" on the pages.
* A finding's identity is its code, devices, ports and VLAN.
* Builds on the scheduled job and tables of 5.

### 4. Routing & IP diagnostics

The L3 counterpart of the spanning tree page.

* **OSPF**
  * duplicate router IDs (`ospf_instances.ospfRouterId`)
  * neighbors stuck in ExStart / Exchange, which usually means an MTU mismatch (compare `ports.ifMtu` of both ends)
  * hello / dead interval, area or network type mismatch on one subnet (`ospf_ports`)
  * different costs in each direction (`ospfIfMetricValue`)
  * routers of one area whose databases disagree (`ospf_areas.ospfAreaLsaCount` / `ospfAreaLsaCksumSum`)
  * DR/BDR elections on point-to-point links
* **BGP**
  * last error decoded to text (`bgpPeerLastErrorCode` / `SubCode`, e.g. "hold timer expired")
  * recent session resets (`bgpPeerFsmEstablishedTime`)
  * peer configured with a remote AS that is not the peer's `bgpLocalAs`
  * sessions configured on one side only
  * accepted prefixes near the configured limit, or none at all (`bgpPeers_cbgp`)
* **IP**
  * duplicate addresses (the L3 map currently keeps one of them silently)
  * different subnet masks on the two ends of a link
  * the same subnet in two VRFs on neighboring devices
* Builds on: the subnet index of `L3Builder`, the `Finding` format and the layout of the diagnostics pages.

### 12. Coverage report

* LLDP/CDP neighbors that look like switches, routers, APs or firewalls (`DeviceRole::fromPlatform`) but are not
  monitored, where they are connected, with a link to add them.
* Monitored devices without neighbor data, which leave gaps in the maps. The link diagnostics already report devices
  that other switches see but that report no neighbors themselves.

### 10. Loop evidence on the STP page

* Broadcast / multicast storms on inter-switch links (`ports_statistics` broadcast and multicast packet rates).
* Messages from the `syslog` table, for devices that send syslog to LibreNMS: MAC flapping, err-disable, BPDU / loop /
  root guard, UDLD, CDP duplex and native VLAN mismatch. Vendor message patterns should be configurable.

## Later

### 8. Site / WAN view

* One node per location with the links between sites added up (count, capacity, utilization, status), optionally
  placed geographically from `locations.lat` / `lng`; click a site to open its L2/L3 map.
* Keeps large networks readable, since a device map is capped at *Maximum devices* (400 by default).

### 11. Sharing & export

* **Shared views:** named views (scope, filters, layout) stored on the server and shared by the team, replacing the
  layouts saved in each browser.
* **draw.io / Visio export** with icons and positions for documentation (GraphML for yEd).
* **Export to a LibreNMS Custom Map** (`custom_maps`, `custom_map_nodes`, `custom_map_edges`): a native, editable map
  with live utilization that can go on a dashboard through the Custom Map widget. The schema changes between LibreNMS
  versions, so guard it by version.

### 13. A→B path trace

* **L2:** between two hosts located with 2, follow the forwarding ports of the spanning tree (the active tree has a
  single path) and show every hop's ports, speed, utilization and errors.
* **L3:** hop-by-hop lookups in the `route` table (the `route` discovery module is off by default and heavy), falling
  back to connected subnets and OSPF/BGP adjacencies.

## Ideas for separate plugins

These are useful but are not about topology, or would change devices, so they fit better in plugins of their own.

* **Change automation with Ansible:** push configuration changes to all or some devices from LibreNMS, made safer by
  this plugin's topology data. It is the largest of these ideas, so it is worked out
  [below](#change-automation-with-ansible).
* **Switch port capacity:** ports unused for N days (`ifLastChange` and uptime), err-disabled ports, free (PoE) ports
  per wiring closet or location.
* **Config compliance via Oxidized:** rule checks on backed-up configurations (portfast and BPDU guard on edge ports,
  root guard on downlinks, AAA / NTP / SNMPv3 baselines). It could also confirm the STP page's guess about edge ports
  without portfast.
* **Inventory & lifecycle:** approved software versions per model, end-of-life dates, serial numbers, optics
  inventory (`transceivers`).
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
    and its releases have been paused since July 2024 for a refactoring, so check its status first.

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
| 1 | Connection to the runner; an Ansible inventory from LibreNMS (device groups, locations, LibreNMS `os` mapped to `ansible_network_os`; devices without a mapping are refused); run a template on selected devices; live status and log; an audit log (who, what, when, result), also in each device's eventlog | – |
| 2 | Dry run (`--check --diff`) showing the diff per device; approval, optionally by a second admin; batches that stop at the first failures; Oxidized backups before and after (its REST API's `node/next`); a LibreNMS maintenance window for the targets | 1 |
| 3 | Rollout plan and blast radius from this plugin (`api/rollout` and `api/simulate`); the maintenance window also covers what each batch cuts off | 1 |
| 4 | Verification: topology and findings before and after the change (root bridge moved, uplink lost, new single point of failure); one-click fixes for findings with a clear configuration (port descriptions, STP root priority) | items 5 and 6 |

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
* Effort: L overall; phase 1 is M.
