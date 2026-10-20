# LibreNMS Topology & Spanning Tree plugin

NetBrain-style **Layer 2** and **Layer 3** network diagrams plus **spanning tree**, **link & VLAN**, **routing & IP**
and **redundancy & failure impact** diagnostics for [LibreNMS](https://www.librenms.org), built entirely from data
LibreNMS already collects (LLDP/CDP/xDP neighbors, ports, VLANs, port-channels, BRIDGE-MIB STP, IP addresses, OSPF,
BGP, transceiver sensors and the hardware inventory).

![Layer 2 map](docs/images/l2-map.png)

| Layer 2 map | Layer 3 map |
|---|---|
| Switches as Cisco-style icons, tiered top-down (WAN edge / firewall → core → access → endpoints) | Routers, L3 switches and firewalls joined through their subnets |
| Interface names at **both ends** of every link (`Te1/0/1 [R]`), link speed, port-channels collapsed (`2x 10G`) | Point-to-point subnets (/29–/31) as direct links, LAN segments as subnet "media" nodes |
| STP overlay: ★ root bridge, `[R]`/`[D]`/`[ALT]` port roles, ⊗ on blocked ports, dashed orange blocked links | Interface name + IP address at each end, VRF filter, loopbacks and stub networks per device |
| VLAN filter dims switches/links that do not carry the VLAN and switches the STP overlay to that VLAN's instance | OSPF adjacencies and BGP sessions (iBGP/eBGP, external AS clouds), red when not full/established |
| Unmanaged LLDP/CDP neighbors (APs, phones, servers, unknown switches); an unmanaged neighbor behind a root port is flagged `ROOT?` | |

Both maps: center on any device with N hops, filter by device group or location, search, hierarchical or
organic layout, drag-and-save layout (per browser), color links by speed / utilization / status,
details panel for every device and link (speed, duplex, VLANs, traffic, STP state, LAG members), export to
PNG and CSV, full screen, and shareable URLs. On the L2 map, **Simulate failure** in the details of a device or link
shows which devices would lose their path to the core (failures add up, so "what if both go down" works too), and
the top-most down device of an outage is marked with the number of down devices behind it.

### Spanning tree diagnostics

One page (plus a JSON API) that analyzes every STP instance (802.1D/RSTP/MST CIST and Cisco PVST+/Rapid-PVST+ per VLAN):

| Check | Severity |
|---|---|
| Bridges of one connected L2 domain disagree on the root bridge | critical |
| Bridging loop: a cycle of links that are forwarding on both ends (LAG and MLAG/vPC aware) | critical |
| Ports in `broken` state (root/loop inconsistent, guard err-disable) | critical |
| Root bridge not monitored by LibreNMS (rogue switch) – traced along root ports to the exit port, FDB sightings and OUI vendor | warning |
| Root elected with default priority, root not one of your designated roots, root at the edge of the topology | warning |
| High topology change rate per day of uptime, with the most likely source ports (edge ports without portfast) | warning |
| Ports with many transitions to forwarding (flapping) | warning |
| Forwarding ports that are neither root nor designated | warning |
| Operational timers different from the root, invalid 802.1D timer relationship, non-default timers | warning / info |
| Mixed STP modes (PVST+ / Rapid-PVST+ / MST) in one domain | warning / info |
| Bridges more hops from the root than the configured diameter | warning |
| Inter-switch switchports without STP (BPDU filter / STP disabled) | warning |
| Switches without STP data that sit on redundant L2 paths, switches without STP data | warning / info |
| Root bridge / root port changes from the LibreNMS eventlog | warning / info |

Identical findings in many PVST VLANs are reported once with the list of VLANs. The page also lists every
STP domain (root bridge ID, members, blocked ports, max hops, last topology change, timers), all
blocked ports with their neighbor across the link, every bridge instance and recent STP events.

![Spanning tree diagnostics](docs/images/stp-diagnostics.png)

VLAN view with the STP overlay – the VLAN 30 root is an unmanaged switch behind `acc-sw3 Gi1/0/24`:

![VLAN 30 spanning tree](docs/images/l2-vlan-stp.png)

![Layer 3 map](docs/images/l3-map.png)

A **device overview panel** shows each switch's STP role per VLAN (root / root port / cost / blocked ports /
last topology change), its issues and links to the maps centered on that device.

![Device panel](docs/images/device-panel.png)

### Link & VLAN diagnostics

Both ends of every LLDP/CDP link between two monitored devices are compared (a port-channel counts as one link) and
every VLAN is followed across the trunks:

| Check | Severity |
|---|---|
| Optical receive power below the transceiver's alarm threshold (the DOM thresholds the device reports) | critical |
| Speed, duplex and MTU mismatches between the two ends, half-duplex links (MTU differences up to 22 bytes are header accounting) | warning |
| Trunk facing an access port, native VLAN mismatch, access ports in different VLANs | warning |
| **Split VLAN**: a VLAN in use (hosts or a VLAN interface) on switches of one L2 domain that no trunk path connects, naming the trunks that allow it on one end only | warning / info |
| Allowed VLANs differ on a trunk although both switches use the VLAN (a redundant path that silently does not carry it) | warning |
| Port-channel on one end only, members landing in different port-channels, degraded port-channel (members down), uneven load across members | warning |
| Interface errors and packet discards in the last poll | warning |
| Optical receive power below the warning threshold, or within a margin of the alarm threshold | warning / info |
| Port descriptions naming the wrong neighbor (also ports that now face an unmanaged AP or phone), inter-switch ports without description – with the configuration lines that fix them (Cisco-style, Junos, ProCurve, RouterOS) | warning / info |
| Neighbors listed by one end only (LLDP/CDP off on one side, or a unidirectional link), devices without neighbor data | info |

The page also lists every link with both ends side by side (speed, duplex, MTU, mode and native VLAN, VLANs, errors,
receive level) and highlights the values that differ. Links to monitored neighbors outside the selected group or location
are compared too. A VLAN that is only allowed toward a switch without hosts in it (pruned by design) is not reported;
VLANs that are local to each switch on purpose can be ignored, and every check can be switched off, in the settings.

![Link & VLAN diagnostics](docs/images/link-diagnostics.png)

### Routing & IP diagnostics

The Layer 3 counterpart of the spanning tree page: OSPF routers and adjacencies, BGP sessions and the interface
addresses of every device you can see are checked against each other:

| Check | Severity |
|---|---|
| **Duplicate OSPF router ID** on two routers (critical when they are in one OSPF domain), or a neighbor reporting the router's own ID | critical / warning |
| **OSPF adjacency stuck in ExStart / Exchange** with the MTU of both ends (critical when the interface MTUs differ) | critical / warning |
| OSPF hello / dead interval, area, area type (stub / NSSA) or authentication that differs between the interfaces of one link or the routers of one area: no adjacency can form | critical |
| **BGP remote AS** configured on one router that is not the local AS of the (monitored) peer, when the peer was rejected for it (Bad Peer AS) or the session never came up | critical |
| OSPF adjacency stuck in Init or Loading, 2-Way on a point-to-point interface, a configured neighbor that is down | warning |
| OSPF network type that differs on one link; routers of one area whose LSA counts differ by more than 1 % | warning |
| **BGP session down** (unless shut down on purpose), with its last error as text ("Cease / Maximum Number of Prefixes Reached (6/1)") and what usually causes it; sessions configured on one router only (never established, while the peer's sessions in that VRF are polled) | warning |
| Accepted prefixes near (warning) or at (critical) the peer's maximum-prefix limit, per address family | warning / critical |
| **Duplicate IP address** in one VRF on interfaces that are up, within one network; different prefix lengths on two neighbors | warning |
| BGP session reset recently (with its cause, or the router's own restart); session up without any accepted prefix | info / warning |
| OSPF cost that differs per direction on a link; DR election on a point-to-point link (/30, /31) | info |
| Shared addresses on several devices: a gateway address when each device also has its own (VRRP, HSRP, anycast gateway), anycast on loopbacks, host addresses or three or more devices, or devices LibreNMS sees no link between (separate networks may reuse addresses) | info |
| A link (LLDP/CDP, or an OSPF adjacency) that joins two differently named VRFs, on routers that both have both VRFs | info |

Subnets are reused by networks that never meet (customers, sites, VRFs), so interfaces and addresses are only compared
where they are on one link: the two ports of an LLDP/CDP link (with their subinterfaces), the two ends of an OSPF
adjacency, neighbors in one routing table, the two addresses of a /30 or /31 held by two devices, or, for OSPF
interfaces, one OSPF domain. A link whose ends are in differently named VRFs (a provider edge facing a customer router)
is a routing boundary, so a provider and its customers are separate networks; a link end without an address (an L2
port, a LAG member, a port with SVIs behind it) is in the VRFs of its device's addresses that share a subnet with the
other end. A duplicate address between devices that LibreNMS sees no link between in that routing table is only info:
that includes sites joined only through BGP or an MPLS VPN, which count as separate networks. Host-local bridges
(virbr0, docker0, ...) are left out.
VRF names are local to each router and compared without case: a provider edge facing a customer router joins VRFs on
purpose and is not reported. LSA checksum sums are not compared (they change with every LSA refresh), BGP sessions in a
state outside the BGP state machine (Junos peers that LibreNMS has not matched yet) are listed but not checked.

Below the findings the page lists the OSPF routers (router ID, ABR / ASBR, areas), every adjacency as each router
reports it, and every BGP session with its state, uptime, last error and prefixes, problems first. Filter by device,
group, location or VRF; routers outside the selected group or location still count as neighbors and peers.

![Routing & IP diagnostics](docs/images/routing-diagnostics.png)

### Redundancy & failure impact

Every device should keep a path to **the core**: the core devices set in the settings, else the designated root
bridges of the spanning tree settings, else the switches given the lowest spanning tree bridge priority (when it was
lowered on purpose), else the most central switches. The page shows which one it used. Paths are followed through the
whole network you can see; port-channels count as one link and their members back each other up. Servers, access points
and phones are never a path between two switches, even when they are cabled to both.

| Check | Severity |
|---|---|
| **Outage**: the top-most down device, with the down devices that are only reachable through it ("acc-sw3 is down, 14 devices behind it") – start there, the others are probably fine | critical |
| **Redundancy lost**: devices that normally have a second path, but it is down (link down, shut down, or the device at its end down), with the single points of failure they depend on now; a single uplink port-channel running on one cable | warning |
| **Fake redundancy – stack member / line card**: all uplinks of a device, or all members of a port-channel, on one stack member or line card of a device that has several (from the hardware inventory, else from interface names such as `Gi2/0/1` or `xe-1/0/0`) | warning |
| **Single point of failure**: a device or a single cable whose failure cuts switches, routers or firewalls off the core, with what it cuts off | warning from 2 switches cut off, else info |
| **Fake redundancy – same switch**: several separate links to the same switch, which only protect against a cable failure | info |

Below the findings, the **failure impact** table lists every device and link whose failure cuts monitored devices off
the core, largest first (including what only takes access points or servers with it), each with a *Simulate* button that
opens the L2 map with that failure applied.

![Redundancy & failure impact](docs/images/redundancy.png)

![Failure simulation on the L2 map](docs/images/failure-simulation.png)

*(Screenshots use a simulated demo network.)*

## Requirements

* LibreNMS 26.x (developed and tested against 26.9 / master of October 2026), PHP 8.2+
* The LibreNMS modules that feed the plugin enabled for your devices:
  `discovery-protocols` (links), `ports`, `vlans`, `ports-stack`, `stp`, `ipv4-addresses`
  (`ipv6-addresses`), `vrf`, `ospf`, `bgp-peers`, `fdb-table` (rogue root tracing), `transceivers` and `sensors`
  (optical levels), `entity-physical` (stack members and line cards). The maps and checks work with whatever subset
  is available.

## Installation

The plugin is a standard Composer package (LibreNMS "plugin package"). Run everything as the `librenms` user.

```bash
sudo su - librenms

# 1. tell composer where the package lives (stored in the librenms user's global composer
#    config so it survives LibreNMS updates)
composer config --global repositories.librenms-topology vcs https://github.com/agoodley/librenms_dashplugin

# 2. install it with LibreNMS' plugin installer
cd /opt/librenms
./lnms plugin:add agoodley/librenms-topology-plugin dev-main
```

If the repository is private, give composer a GitHub token first:
`composer config --global github-oauth.github.com <token>`.

To install from a local checkout instead, use a path repository:

```bash
composer config --global repositories.librenms-topology '{"type": "path", "url": "/opt/librenms-plugins/librenms_dashplugin", "options": {"symlink": true}}'
./lnms plugin:add agoodley/librenms-topology-plugin @dev
```

The plugin is enabled automatically. Open **Overview → Plugins → L2 Topology Map / L3 Topology Map / STP
Diagnostics / Link & VLAN Diagnostics / Routing & IP Diagnostics / Redundancy & Failure Impact**, or go to
`/plugin/topology`. It can be disabled or configured in **Overview → Plugins → Plugin Admin → topology → Settings**.

To remove it: `./lnms plugin:remove agoodley/librenms-topology-plugin`.

## Usage tips

* **Center on a device** and pick the number of hops for large networks (the map is capped at *Maximum devices*,
  default 400). Double-click any device to re-center on it.
* Pick a **VLAN** on the L2 map to see which switches/trunks carry it and that VLAN's spanning tree
  (root bridge, port roles, blocked ports). Domain rows and findings on the STP page link straight to that view.
* Arrange devices by dragging, then **Save layout** (stored in your browser for that map scope).
* The suggested configuration under a description finding is ready to paste; it names each port after its current
  LLDP/CDP neighbor (`core2 Te1/1/1`).
* Before maintenance on a switch, open it on the L2 map and use **Simulate failure**: the box lists what loses its path
  to the core, computed on the whole network as it is right now (links and devices that are already down stay down),
  including devices that are not on the map. Add more failures to check a pair, *Reset* to start over.
  `l2?fail=d:12,p:345` opens the map with device 12 and the link of port 345 failed (`api/simulate?fail=...` returns the
  same as JSON).
* If the automatic core is not your core, set **Core devices** in the settings; the switches next to the LibreNMS
  poller are a good choice when you want "what can the poller still reach".
* On the routing page, a BGP session's last error is decoded ("Hold Timer Expired (4/0)": keepalives lost) – look there
  first when a peer flaps. Addresses that are shared on purpose (anycast, VRRP / HSRP virtual addresses that a device
  reports as interface addresses) can be left out of the IP checks in the settings.
* `plugin/topology/api/l2`, `api/l3`, `api/stp`, `api/links`, `api/routing` and `api/redundancy` return the same data as
  JSON (same query parameters as the pages, e.g. `api/stp?vlan=30&group=2`, `api/links?device=12`,
  `api/routing?vrf=PROD`) for automation.

## Settings

| Setting | Default | |
|---|---|---|
| Default hops | 2 | hops drawn around a centered device |
| Maximum devices | 400 | larger maps are trimmed to the best connected devices |
| Show unmanaged neighbors / Collapse LAGs / Hide stub networks | on | defaults of the map toggles |
| Point-to-point prefix | 29 | two-device IPv4 subnets this long or longer become direct links |
| Excluded subnets | – | CIDRs never drawn on the L3 map (e.g. management networks) |
| Designated root bridges | – | hostnames/sysNames/ids; warn when any other bridge is root |
| Topology change window / rate | 60 min / 50 per day | |
| Flapping threshold | 100 | transitions to forwarding |
| Maximum diameter | 7 | bridge hops from the root |
| Event history | 7 days | STP eventlog entries analyzed |
| Interface error threshold | 10 | errors per poll on an inter-switch port (0 disables) |
| Discard threshold | 1 % | share of the packets discarded (0 disables) |
| Port-channel imbalance | 40 % | a member above this utilization carrying 3x the traffic of another member (0 disables) |
| Optical margin | 2 dB | receive power this close to the alarm threshold is reported (0 disables) |
| Ignored VLANs | – | e.g. `999 1002-1005`: VLANs local to each switch by design |
| Disabled checks | – | check codes or categories (`mismatch`, `vlans`, `lags`, `counters`, `optics`, `neighbors`, `descriptions`), e.g. `mtu_mismatch description_missing` |
| BGP reset window | 24 h | sessions that came up less than this ago are reported as reset (0 disables) |
| Prefix limit warning | 80 % | share of a peer's maximum-prefix limit that warns (100 % is critical; 0 only reports peers at the limit) |
| Ignored addresses | – | CIDRs or addresses left out of the IP checks (anycast, virtual gateway addresses) |
| Disabled routing checks | – | check codes or categories (`ospf`, `bgp`, `ip`), e.g. `ospf_cost_asymmetric bgp_no_prefixes` |
| Core devices | – | hostnames/sysNames/ids every device should keep a path to; empty: designated root bridges, else automatic |
| Warn from | 2 | switches, routers or firewalls cut off by one failure before a single point of failure is a warning |
| Disabled redundancy checks | – | check codes or categories (`outages`, `lost`, `fake`, `spof`), e.g. `spof_link uplinks_same_device` |

## Permissions

Every map and diagnostic only uses devices the logged-in user may see (LibreNMS device permissions).
Neighbors on devices the user cannot see are shown as unmanaged neighbors (their LLDP/CDP name only), and a
root bridge on such a device is not reported as a rogue switch.

## How it works

* `src/Data/` – all database access, permission scoped (`ScopedRepository`); `TopologyRepository` serves the maps and
  the diagnostics.
* `src/Support/Navigation.php` – the pages, as listed in the navigation, the plugin menu and the landing page.
* `src/Topology/L2Builder.php`, `src/Topology/L3Builder.php` – build the map graphs (link de-duplication,
  LAG/MLAG handling, VLAN membership, subnet classification, routing overlays, hierarchy tiers).
* `src/Stp/StpIndex.php`, `src/Stp/StpAnalyzer.php` – STP role derivation (BRIDGE-MIB has no port role) and
  the diagnostics.
* `src/Links/LinkAnalyzer.php` – the link and VLAN checks (both ends of each link, port-channels, VLAN islands per
  L2 domain, descriptions and their configuration lines).
* `src/Routing/RoutingAnalyzer.php` – the OSPF, BGP and IP checks (OSPF domains from the adjacencies, the interface
  facing each neighbor, BGP peers resolved to monitored devices); `BgpErrors` turns NOTIFICATION codes into text. The
  rows are streamed in and kept in small objects (2,000 routers with 50,000 addresses take about 50 MB and 0.6 s);
  with a device selected, `RoutingService` reads only the addresses its checks need.
* `src/Redundancy/RedundancyAnalyzer.php` – single points of failure (cut vertices and bridges toward the core,
  `Support\Graph::cuts()`), fake and lost redundancy and outages; `CoreFinder` picks the core, `PortUnits` reads stack
  members and line cards from the hardware inventory.
* `resources/assets/topology.js` – the map client, drawn with the vis-network library bundled with LibreNMS.

The builders and analyzer are plain PHP with no LibreNMS dependency and are covered by unit tests:

```bash
composer install
vendor/bin/phpunit
```

## Limitations

* STP data comes from the BRIDGE-MIB as polled by LibreNMS: MST instances other than the CIST are not
  available, and findings reflect the last poll (a check can fire during re-convergence).
* Stacks / virtual chassis that are not modelled as one device, and MLAG pairs without a detected
  port-channel, can produce a "possible bridging loop" warning – the finding says so.
* Saved layouts live in the browser's local storage, they are not shared between users.
* LibreNMS refreshes VLAN memberships at discovery (every 6 hours by default) and counters at every poll, so a
  VLAN change can take until the next discovery to show up. The split VLAN check can only follow links that
  LLDP/CDP reports; a VLAN that crosses a link without LLDP/CDP can be reported as split.
* The redundancy analysis only knows links that LLDP/CDP reports. LibreNMS removes a link that LLDP/CDP no longer sees
  at the next discovery, so "redundancy lost" covers failures since the last discovery run; a link that has been down
  for longer is simply missing (roadmap item 5 keeps a baseline). It looks at device and link failures, not at VLANs:
  a backup trunk that does not carry a VLAN is a link & VLAN finding.
* The routing checks see what LibreNMS polls: OSPFv2 (OSPF-MIB; OSPFv3 is not checked) and BGP sessions; accepted
  prefix counts come from Cisco, Junos, Arista, Dell OS10 and Huawei VRP routers (bgpPeers_cbgp), the maximum-prefix
  limits only from Cisco. Adjacency and session states are a snapshot of the last poll, so a state seen during a change
  can show up once. A session or address is matched to a device only when that device is monitored and visible to you
  and the address is on that device only; a one-sided session needs the peer's BGP sessions in that VRF to be polled
  (an SNMP context per VRF). A session that has never come up to a confederation peer can be reported as a remote AS
  mismatch, and interfaces on one LAN behind a switch are only compared when their routers are in one OSPF domain.
  Subinterfaces are matched to the port LLDP/CDP reports by name (Gi0/1.100 to Gi0/1).
* Servers, access points and phones are recognized by their LibreNMS device type, unmanaged neighbors by their LLDP/CDP
  platform; an unmanaged neighbor without a platform counts as a switch. Down devices without any LLDP/CDP neighbor are
  not listed as outages: nothing tells what they depend on.

## Roadmap

Planned tools (host locator, topology history, automatic device dependencies and more) are listed in
[ROADMAP.md](ROADMAP.md).

## License

GPL-3.0-or-later, like LibreNMS.
