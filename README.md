# LibreNMS Topology & Spanning Tree plugin

NetBrain-style **Layer 2**, **Layer 3** and **site / WAN** network diagrams plus **spanning tree**, **link & VLAN** and
**redundancy & failure impact** diagnostics for [LibreNMS](https://www.librenms.org), built entirely from data LibreNMS
already collects (LLDP/CDP/xDP neighbors, ports, VLANs, port-channels, BRIDGE-MIB STP, IP addresses, OSPF, BGP,
transceiver sensors and the hardware inventory).

![Layer 2 map](docs/images/l2-map.png)

| Layer 2 map | Layer 3 map |
|---|---|
| Switches as Cisco-style icons, tiered top-down (WAN edge / firewall → core → access → endpoints) | Routers, L3 switches and firewalls joined through their subnets |
| Interface names at **both ends** of every link (`Te1/0/1 [R]`), link speed, port-channels collapsed (`2x 10G`) | Point-to-point subnets (/29–/31) as direct links, LAN segments as subnet "media" nodes |
| STP overlay: ★ root bridge, `[R]`/`[D]`/`[ALT]` port roles, ⊗ on blocked ports, dashed orange blocked links | Interface name + IP address at each end, VRF filter, loopbacks and stub networks per device |
| VLAN filter dims switches/links that do not carry the VLAN and switches the STP overlay to that VLAN's instance | OSPF adjacencies and BGP sessions (iBGP/eBGP, external AS clouds), red when not full/established |
| Unmanaged LLDP/CDP neighbors (APs, phones, servers, unknown switches); an unmanaged neighbor behind a root port is flagged `ROOT?` | OSPFv3, IS-IS, IPsec, MPLS LSP and pseudowire [overlays](#layer-3-map-overlays), with an MTU check of the pseudowires' attachment circuits |

Both maps: center on any device with N hops, filter by device group or location, search, hierarchical or
organic layout, drag-and-save layout (per browser), color links by speed / utilization / status,
details panel for every device and link (speed, duplex, VLANs, traffic, STP state, LAG members), export to
PNG and CSV, full screen, and shareable URLs. On the L2 map, **Simulate failure** in the details of a device or link
shows which devices would lose their path to the core (failures add up, so "what if both go down" works too), and
the top-most down device of an outage is marked with the number of down devices behind it.

### Layer 3 map overlays

Besides OSPF and BGP, the L3 map draws the overlays of LibreNMS modules that are often off. Each has its own toggle,
shown only when the devices drawn have such data:

| Overlay | LibreNMS module | |
|---|---|---|
| OSPFv3 adjacencies | `ospfv3` (on by default) | neighbors are found by router ID within their context (VRF): their address is usually link-local |
| IS-IS adjacencies | `isis` discovery and poller modules (off by default) | by the neighbor's address (never a link-local one), else its system ID: as learnt from the other adjacencies, or a loopback it encodes (`0100.0000.0001` = 10.0.0.1) |
| IPsec tunnels | `cipsec-tunnels` poller module: off by default, on in the OS definitions of Cisco IOS, IOS XE, IOS XR, NX-OS, ASA and most other Cisco OSes, and of Firebrick | to the device owning the peer address |
| MPLS LSPs | `mpls` (Nokia SR OS) | **toggle off by default**; arrow toward the tail, red when an LSP that is in service is down. Past 500 pairs of devices (an RSVP full mesh) the pairs with LSPs down and then the busiest are drawn, and the status line says so |
| Pseudowires | `cisco-pw` discovery module: off by default, on in the OS definitions of Cisco IOS, IOS XE, IOS XR and NX-OS | orange with both MTUs when the attachment circuits disagree (see below) |

Both ends of an adjacency, tunnel, LSP or pseudowire are merged into one link per pair of devices, and its details
list them all (LSP states and paths, tunnel names, pseudowire VC IDs, ports and MTUs). A far end that is a device you
may see but that is not on the map (beyond the hops, in another location or group, not an L3 device) is drawn faded,
with its name: double-click it to center the map on it. OSPF and BGP do the same. Such a device beyond the hops adds
its own side of the adjacency, session or tunnel; one in another location or group has no data on the map, so its
edge says the state is seen from the drawn side only. At most 100 of these devices are drawn, within the map's device
limit; the others appear as their address or AS, and the status line says so. An unmonitored peer is one external
node per address, whatever saw it (a router can be OSPF neighbor, LSP tail and IPsec peer at once); BGP peers that no
device owns still go to the cloud of their AS. Addresses are looked up in the global table first, so a customer VRF
that reuses a loopback range does not hide the router.

LibreNMS keeps no pseudowire state and loses most peers: it never sets `peer_device_id`, and `peer_ldp_id` is a signed
INT that holds 2147483647 for every LDP ID from 128.0.0.0 on. When both ends lost it, a pseudowire is paired with the
one other device that has its VC ID, unless a device you may not see has that VC ID too, and its details say "paired
by VC ID (LDP id not stored)": PEs whose real peers are unmonitored and reuse the VC ID can still be paired wrongly.
One that cannot be paired goes to an "unknown peers" node of its PE. The MTU check compares the MTUs the two attachment
circuits signal, which must match for a pseudowire to come up (RFC 4447): the interface MTU, less the Ethernet header on
IOS XR, which counts it (and the VLAN tag of a subinterface): XR 1514, or 1518 on a dot1q subinterface, is IOS XE 1500.

![L3 map overlays](docs/images/l3-layers.png)

### Site / WAN map

One node per LibreNMS location, with its device count and what is down, and one link per pair of sites that adds up
everything between their devices: LLDP/CDP links (one per cable, port-channel members included), point-to-point
subnets, and OSPF adjacencies and BGP sessions (one per circuit, with the state each router reports). A subnet on a
cable, or on a sub-interface, VLAN interface or port-channel over it, only adds its network to the cable; other subnets,
such as tunnels or carrier circuits, are links of their own. BGP sessions to peers LibreNMS does not monitor join the
site to a cloud per remote AS, which shows an MPLS VPN or internet transit provider and which sites hang off it; a
private AS number is a cloud per site, since sites often reuse them.

![Site / WAN map](docs/images/site-map.png)

* A site is orange when some of its devices are down and red when all are; a link is dashed orange when some of its
  cables or sessions are down, and dashed purple when it is only a routing session (BGP over a provider network). A
  BGP session shut down by an administrator is grey and does not count as down.
* Link width and color follow the capacity of its cables (or the busiest cable's utilization, or the status); the
  details list every cable with both ports, speed, utilization and subnet, and every session with its state. Tunnels
  and other logical interfaces have no capacity of their own (a tunnel's interface speed is a nominal bandwidth), so a
  link on them only shows its traffic; a subnet on a sub-interface is measured on the physical port under it.
* Layouts: hierarchical (the best connected site on top), organic, or **geographic** from the latitude and longitude of
  the locations (sites in one city are pushed apart, sites without coordinates wait in a grid underneath; when most
  sites have none, the map is laid out without them).
* Center on a site to see it with its neighbors within N hops, or filter by device group; double-click a site, or use
  the buttons in its details, to open its L2 or L3 map, redundancy page or device list.

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

### Rollout planner

**Tools → Rollout Planner** puts the devices of a firmware upgrade, a reboot or a configuration change in batches, using
the core and the links of the redundancy page as they are now. Pick devices, or a device group or location (its
switches, routers and firewalls by default), and optionally the most devices per batch:

* **Farthest from the core first, the core last.** A device's path to the core only runs through devices closer to the
  core, so a change that breaks a device never cuts off a device that still waits for it.
* **Never two devices that back each other up in one batch**: a batch going down as a whole cuts off no more than its
  devices one at a time. The two switches of a redundant pair, two switches that share a dual-homed server or an
  unmonitored switch, and two core switches always land in different batches.
* **What goes down while a batch runs**: the monitored devices it cuts off and the unmanaged neighbors (phones, access
  points) that go down with it – what its maintenance window must cover. Each batch has its hostnames ready to copy and
  a *Simulate* button that fails the batch on the L2 map. A batch that takes down the last core device of a part of
  the network is counted like that simulation: the largest remaining piece carries on.

Picked devices are planned as they are. Devices that are down now, without any LLDP/CDP neighbor or without a path to
the core now are left out and listed to plan by hand (nothing tells what depends on them), and so are picked ids that
are unknown, disabled or not visible to you. When a part of the network has a single core device and another switch
is cabled like a second core switch, the page names it: add both to *Core devices*. `api/rollout?devices=12,13` (or
`group`, `location`, `size`, `network=0`) returns the plan as JSON.

![Rollout planner](docs/images/rollout.png)

### Device dependencies

LibreNMS suppresses a device's alerts while **all** its parent devices are down, but the parents are set by hand.
**Tools → Dependencies** derives them from the topology: the parents of a device are its neighbors one hop closer to
the core over every LLDP/CDP link, also links that are down now.

* Dual-homed devices get both uplink devices, so their alerts are only suppressed when both are down.
* A switch also gets the switches next to it at the same distance from the core that rank before it: lower spanning
  tree bridge priority (without the VLAN bits), then a switch cabled like a second core switch, then lower device id.
  So a switch cabled to both switches of a core pair gets both even when only one of them is set as core, and the order
  of two switches stays the same when other switches are added or removed.
* Parents always come first in that order (closer to the core, then by rank), so derived dependencies never form a loop.
* An unmonitored switch in between is skipped to the next monitored device toward the core.
* Core devices and devices without a path to a core keep their current parents.

The page shows the core, the current and the derived parents of each device (changed, new, same or kept), and how many
of its outages in the last 30 days started while all its parents were down – with the current and with the derived
parents: the alerts LibreNMS suppresses. A disabled parent is labelled as such: it never counts as down. Admins apply
selected changes, or every change of the view at once (also for thousands of devices). What the preview showed goes
with the request: a device whose derived parents changed since is skipped, a view whose changes changed is not applied.
A device that would end up on a loop with dependencies kept by hand is refused, the other changes are applied, and
every change is written to the device's eventlog with the parents it had before.

![Device dependencies](docs/images/dependencies.png)

*(Screenshots use a simulated demo network.)*

## Requirements

* LibreNMS 26.x (developed and tested against 26.9 / master of October 2026), PHP 8.2+
* The LibreNMS modules that feed the plugin enabled for your devices:
  `discovery-protocols` (links), `ports`, `vlans`, `ports-stack`, `stp`, `ipv4-addresses`
  (`ipv6-addresses`), `ospf`, `bgp-peers`, `fdb-table` (rogue root tracing), `transceivers` and `sensors`
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

The plugin is enabled automatically. Its maps and diagnostics pages are listed under **Overview → Plugins**, and
`/plugin/topology` shows them all with a short description. It can be disabled or configured in
**Overview → Plugins → Plugin Admin → topology → Settings**.

To remove it: `./lnms plugin:remove agoodley/librenms-topology-plugin`.

## Usage tips

* **Center on a device** and pick the number of hops for large networks (the map is capped at *Maximum devices*,
  default 400). Double-click any device to re-center on it.
* Pick a **VLAN** on the L2 map to see which switches/trunks carry it and that VLAN's spanning tree
  (root bridge, port roles, blocked ports). Domain rows and findings on the STP page link straight to that view.
* Arrange devices by dragging, then **Save layout** (stored in your browser for that map scope).
* On the L3 map, click a router and pick an overlay under *Connections* to list its LSPs, tunnels or pseudowires;
  `l3?isis=0&mpls=1` (and `api/l3`) switches IS-IS off and the MPLS LSPs on. A faded device is outside the map:
  double-click it to follow the tunnel or LSP there.
* The suggested configuration under a description finding is ready to paste; it names each port after its current
  LLDP/CDP neighbor (`core2 Te1/1/1`).
* Before maintenance on a switch, open it on the L2 map and use **Simulate failure**: the box lists what loses its path
  to the core, computed on the whole network as it is right now (links and devices that are already down stay down),
  including devices that are not on the map. Add more failures to check a pair, *Reset* to start over.
  `l2?fail=d:12,p:345` opens the map with device 12 and the link of port 345 failed (`api/simulate?fail=...` returns the
  same as JSON).
* If the automatic core is not your core, set **Core devices** in the settings; the switches next to the LibreNMS
  poller are a good choice when you want "what can the poller still reach". Set both switches of a core pair: with only
  one, the other one gets parents on the dependencies page and the rollout planner plans it like any other switch
  (both pages name a switch that is cabled like a second core switch).
* Before a firmware rollout, plan it on **Rollout Planner** for the device group or location, then put each batch and
  the devices listed under it in a LibreNMS maintenance window while it runs.
* Give the locations a latitude and longitude (LibreNMS can look them up from the address) and the site map can lay
  the network out geographically; the first visit uses that layout.
* `plugin/topology/api/l2`, `api/l3`, `api/sites`, `api/stp`, `api/links`, `api/redundancy`, `api/rollout` and
  `api/dependencies` return the same data as JSON (same query parameters as the pages, e.g. `api/stp?vlan=30&group=2`,
  `api/links?device=12`) for automation.

## Settings

| Setting | Default | |
|---|---|---|
| Default hops | 2 | hops drawn around a centered device |
| Maximum devices | 400 | larger maps are trimmed to the best connected devices (on the site map: sites) |
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
| Core devices | – | hostnames/sysNames/ids every device should keep a path to; empty: designated root bridges, else automatic |
| Warn from | 2 | switches, routers or firewalls cut off by one failure before a single point of failure is a warning |
| Disabled redundancy checks | – | check codes or categories (`outages`, `lost`, `fake`, `spof`), e.g. `spof_link uplinks_same_device` |
| Rollout batch size | 0 | default most devices per rollout batch (0: no limit); the planner page can change it |
| Outage history | 30 days | outages the derived dependencies are checked against |

## Permissions

Every map and diagnostic only uses devices the logged-in user may see (LibreNMS device permissions).
Neighbors on devices the user cannot see are shown as unmanaged neighbors (their LLDP/CDP name only), and a
root bridge on such a device is not reported as a rogue switch. Only LibreNMS admins can apply derived device
dependencies; other users see the page read only.

## How it works

* `src/Data/` – all database access, permission scoped (`ScopedRepository`); `TopologyRepository` serves the maps and
  the diagnostics.
* `src/Support/Navigation.php` – the pages, as listed in the navigation, the plugin menu and the landing page.
* `src/Topology/L2Builder.php`, `src/Topology/L3Builder.php` – build the map graphs (link de-duplication,
  LAG/MLAG handling, VLAN membership, subnet classification, hierarchy tiers, and the L3 overlays: OSPF, OSPFv3, IS-IS,
  BGP, IPsec, MPLS LSPs and pseudowires, whose far ends are devices of the map, stubs of devices outside it or one
  external node per address). `Data\L3LayerRepository` reads the newer layers and recognizes far ends among the devices
  the user may see.
* `src/Stp/StpIndex.php`, `src/Stp/StpAnalyzer.php` – STP role derivation (BRIDGE-MIB has no port role) and
  the diagnostics.
* `src/Links/LinkAnalyzer.php` – the link and VLAN checks (both ends of each link, port-channels, VLAN islands per
  L2 domain, descriptions and their configuration lines).
* `src/Redundancy/RedundancyAnalyzer.php` – single points of failure (cut vertices and bridges toward the core,
  `Support\Graph::cuts()`), fake and lost redundancy and outages; `CoreFinder` picks the core, `PortUnits` reads stack
  members and line cards from the hardware inventory.
* `src/Topology/SiteConnections.php`, `SiteSessions.php`, `SiteBuilder.php` – the site map: the cables and
  point-to-point subnets between devices at different locations (the L2 map's link de-duplication and the L3 map's
  subnets, placed on physical ports through the interface stack), their OSPF adjacencies and BGP sessions per circuit,
  added up per pair of sites.
* `src/Redundancy/RolloutPlanner.php`, `src/Redundancy/DependencyPlanner.php` – rollout batches and device dependencies
  on the graphs of the redundancy analysis (`Topology\PlanningService`, `Data\DependencyRepository`); `CoreAdvice` names
  a switch cabled like a second core switch.
* `resources/assets/topology.js` – the map client, drawn with the vis-network library bundled with LibreNMS.

The builders and analyzer are plain PHP with no LibreNMS dependency and are covered by unit tests:

```bash
composer install
vendor/bin/phpunit
```

## Limitations

* The site map knows the links between monitored devices. Unmanaged neighbors, devices the user may not see and OSPF
  neighbors LibreNMS does not monitor are left out (their location is unknown), as are sessions to devices outside a
  device group filter; BGP peers LibreNMS does not monitor are grouped by AS, so two providers in the same AS are one
  cloud. The devices without a location are one site, which the map never walks through (hops, hierarchy).

* STP data comes from the BRIDGE-MIB as polled by LibreNMS: MST instances other than the CIST are not
  available, and findings reflect the last poll (a check can fire during re-convergence).
* Stacks / virtual chassis that are not modelled as one device, and MLAG pairs without a detected
  port-channel, can produce a "possible bridging loop" warning – the finding says so.
* Saved layouts live in the browser's local storage, they are not shared between users.
* L3 overlays: a far end found on several devices (a duplicated address or router ID) is not guessed: it is an
  external node, like an unknown peer or one on a device you may not see, which shows only the address. Pseudowires
  whose peers LibreNMS lost on several PEs with the same VC ID stay unpaired, and their state is unknown; the MTU check
  assumes one VLAN tag on an IOS XR subinterface (a QinQ one is flagged). Far ends that are not on the map are looked
  up in `ipv4_addresses` / `ipv6_addresses`, which have no index on the address: one table scan per map load for up to
  5,000 addresses.
* LibreNMS refreshes VLAN memberships at discovery (every 6 hours by default) and counters at every poll, so a
  VLAN change can take until the next discovery to show up. The split VLAN check can only follow links that
  LLDP/CDP reports; a VLAN that crosses a link without LLDP/CDP can be reported as split.
* The redundancy analysis only knows links that LLDP/CDP reports. LibreNMS removes a link that LLDP/CDP no longer sees
  at the next discovery, so "redundancy lost" covers failures since the last discovery run; a link that has been down
  for longer is simply missing (roadmap item 5 keeps a baseline). It looks at device and link failures, not at VLANs:
  a backup trunk that does not carry a VLAN is a link & VLAN finding.
* Servers, access points and phones are recognized by their LibreNMS device type, unmanaged neighbors by their CDP
  platform or, for LLDP (which has no platform), their system description; a neighbor with neither counts as a switch. Down devices without any LLDP/CDP neighbor are
  not listed as outages: nothing tells what they depend on.
* The rollout planner and the device dependencies follow the same LLDP/CDP links: devices without neighbor data get no
  parents and are left out of rollout plans. Whether devices back each other up is decided strictly (a part without
  its core devices counts as cut off as a whole), so the core devices of a part never share a batch.
* Derived parents never form a loop, so of two switches at the same distance from the core only one can depend on the
  other, and in a mesh a device can keep a path through switches farther from the core while all its parents are down:
  LibreNMS then suppresses its alerts although it may still be reachable. LibreNMS parents must be monitored devices,
  so a device behind an unmonitored switch depends on the next monitored one.

## Roadmap

Planned tools (host locator, topology history, routing diagnostics and more) are listed in [ROADMAP.md](ROADMAP.md).

## License

GPL-3.0-or-later, like LibreNMS.
