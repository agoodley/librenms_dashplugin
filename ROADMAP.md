# Roadmap

Ideas for the plugin's next tools, roughly in the order they are worth building. Every item uses data LibreNMS
already collects; the tables and modules each one needs are listed so it can be picked up directly.

The plugin's conventions apply to all of them:

* database access only in `src/Data/TopologyRepository.php`, scoped to the devices the user may see;
* the analysis in plain PHP classes with no LibreNMS dependency, covered by unit tests
  (like `StpAnalyzer` and `LinkAnalyzer`);
* every finding says what is wrong and how to fix it, and noisy checks can be switched off in the settings.

## Status

| # | Tool | Status | Effort |
|---|---|---|---|
| 1 | [Link & VLAN diagnostics](README.md#link--vlan-diagnostics) | **done** | – |
| 3 | [Redundancy & failure impact](#3-redundancy--failure-impact) | next | S–M |
| 2 | [Host locator and port tab](#2-host-locator-where-is-this-plugged-in-and-port-tab) | next | M |
| 5 | [Topology history, diff and baseline](#5-topology-history-diff-and-baseline) | planned | M–L |
| 6 | [Background diagnostics → eventlog](#6-background-diagnostics--eventlog) | planned, after 5 | S |
| 4 | [Routing & IP diagnostics](#4-routing--ip-diagnostics) | planned | M |
| 7 | [Automatic device dependencies](#7-automatic-device-dependencies) | planned | M |
| 12 | [Coverage report](#12-coverage-report) | planned | S |
| 9 | [More L3 map layers](#9-more-l3-map-layers) | planned | S each |
| 10 | [Loop evidence on the STP page](#10-loop-evidence-on-the-stp-page) | planned | S–M |
| 8 | [Site / WAN view](#8-site--wan-view) | later | M |
| 11 | [Sharing & export](#11-sharing--export) | later | M each |
| 13 | [A→B path trace](#13-ab-path-trace) | later, after 2 | L |

Effort: S = new checks or an overlay on data the plugin already loads; M = new queries plus a page or tab;
L = several new parts (tables, jobs, UI).

**Why this order:** 3 is mostly graph work on data the plugin already loads. 2 answers the most frequent day-to-day
question. 5 and 6 share the same scheduled job and tables and turn every check into something that runs without anyone
opening a page. The rest builds on those.

## What a plugin can do in LibreNMS

* Hooks: menu entry, single page, settings page, device overview panel and **port tab**. The port tab is not used yet.
  There is no hook for dashboard widgets or device tabs.
* A plugin package can ship routes, views and **database migrations**, and register **scheduled commands**
  (LibreNMS runs Laravel's scheduler).
* The only ways onto a LibreNMS dashboard are indirect: the eventlog widget (see 6) and the Custom Map widget (see 11).

## Next up

### 3. Redundancy & failure impact

* **Single points of failure:** devices and links whose loss splits the network (articulation points and bridges of the
  L2 graph): single-homed access switches, single uplinks.
* **Fake redundancy:** both uplinks of a switch ending on the same upstream device, or the members of a port-channel
  on the same stack member or line card.
* **Redundancy lost now:** one of two uplinks down, degraded port-channels, a backup path blocked by something other
  than spanning tree.
* **Simulate a failure:** click a device or link on the map to see which devices lose their path to the core (or to the
  poller), for planning changes.
* **Outage view:** mark the top-most down device and count what sits behind it ("acc-sw3 down, 14 devices behind it").
* Data: `links`, `ports`, `ports_stack`, `entPhysical` (`ifIndex` and the `entPhysicalContainedIn` hierarchy give the
  module / stack member of a port), `devices.status`.
* Builds on: `Support\Graph` (bridge finding already used by the STP checks), the link de-duplication of
  `L2Builder` / `LinkAnalyzer`, an overlay in `topology.js`.

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
  that are no longer seen, so a redundant uplink that disappears leaves no trace today.
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

### 7. Automatic device dependencies

* LibreNMS suppresses a device's alerts while **all** its parent devices are down, but parents are maintained by hand.
* Derive the parents from the topology, toward the core or the poller (dual-homed devices get two parents), show the
  difference from the current `device_relationships`, and let an admin apply it (`Device::parents()->sync()`).
* Parents always closer to the core keep the result free of loops. `device_outages` history can confirm the
  derived dependencies.
* This writes LibreNMS data: admin only, with a preview.

### 12. Coverage report

* LLDP/CDP neighbors that look like switches, routers, APs or firewalls (`DeviceRole::fromPlatform`) but are not
  monitored, where they are connected, with a link to add them.
* Monitored devices without neighbor data, which leave gaps in the maps. The link diagnostics already report devices
  that other switches see but that report no neighbors themselves.

### 9. More L3 map layers

* OSPFv3 (`ospfv3_nbrs`), IS-IS (`isis_adjacencies`; the module is off by default), IPsec tunnels (`ipsec_tunnels`,
  peer address → device), MPLS LSPs (`mpls_lsps`, from/to addresses) and pseudowires (`pseudowires`; local vs peer MTU
  gives a free MTU check).
* Each is about the size of `L3Builder::addOspf()`.

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

These are useful but are not about topology, so they fit better in plugins of their own.

* **Switch port capacity:** ports unused for N days (`ifLastChange` and uptime), err-disabled ports, free (PoE) ports
  per wiring closet or location.
* **Config compliance via Oxidized:** rule checks on backed-up configurations (portfast and BPDU guard on edge ports,
  root guard on downlinks, AAA / NTP / SNMPv3 baselines). It could also confirm the STP page's guess about edge ports
  without portfast.
* **Inventory & lifecycle:** approved software versions per model, end-of-life dates, serial numbers, optics
  inventory (`transceivers`).
* **NetBox sync:** push LLDP-discovered cables, IP addresses and VLANs to NetBox, or compare NetBox's documented cabling
  with what LLDP sees. It could reuse this plugin's link de-duplication.
