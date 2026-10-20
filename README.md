# LibreNMS Topology & Spanning Tree plugin

NetBrain-style **Layer 2** and **Layer 3** network diagrams plus **spanning tree**, **link & VLAN** and
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

### Coverage report

What LibreNMS does not see, so the gaps in the maps and the checks are known:

| Check | Severity |
|---|---|
| **Not monitored**: an LLDP/CDP neighbor whose CDP platform, LLDP system description or name says switch, router, firewall or access point, with every port it is seen on (its LLDP and CDP sightings merged) and an *Add* button for users who may add devices | warning (access points and wireless controllers: info) |
| **Not linked**: a neighbor whose name or address is that of one monitored device, but LibreNMS did not link the two (the name it advertises is not the device's hostname or sysName, or the link is older than the device), so the link is missing from the maps | info |
| **Name of several devices**: a neighbor named like several monitored devices (and the hostname of none of them): LibreNMS cannot tell which one it is and links none | info |
| **Disabled**: a neighbor that is a device disabled in LibreNMS, so it is not polled | info |
| **No LLDP/CDP neighbors reported**: a monitored switch, router, firewall or access point that other devices see but that reports no neighbors itself, so its links come from the other end only (the Link & VLAN page reports it too; it says when LibreNMS' `discovery-protocols` module is off for the device) | info |
| **No neighbor data**: a monitored switch, router, firewall or access point that reports no neighbors and that no monitored device sees: it stands alone on the maps | warning for switches and routers that are up, else info |
| **Several neighbors on one port**: two or more LLDP/CDP neighbors on one port (two that are not phones, or two phones): something LibreNMS does not see connects them | info |
| **Possible unmanaged switch or hub**: an Ethernet access port with 4 or more MAC addresses (setting) in its device's last forwarding table discovery and no LLDP/CDP neighbor that explains them (a phone only explains the PC behind it) – an unmanaged switch or hub, or a server, hypervisor or access point that bridges without announcing itself | info |

The type of a neighbor comes from its CDP platform or, for LLDP, its system description, which names the software: Cisco
IOS (the model in it tells a C2960 switch from a C2900 router), IOS XE, NX-OS and IOS XR, Arista EOS, Juniper, Aruba
(CX switches and access points), ExtremeXOS, Dell OS10, MikroTik, VyOS, FortiGate and Firepower, Catalyst 9800 wireless
controllers and UniFi access points, for example. Neighbors of unknown type are listed (folded away) without a finding,
phones and servers are only counted. A neighbor LibreNMS linked to a device you may not see is listed as monitored,
without a finding. Servers, printers, other devices that usually run no LLDP/CDP and firewalls whose OS supports neither
(Cisco ASA and FTD) are listed without a finding among the monitored devices without neighbors; a device that is down is
info.
Factory default names (`Switch`, `MikroTik`, ...) are one neighbor per port and never match a monitored device. Trunks,
port-channels, ports that are not Ethernet (such as an access point's radio) and ports whose MAC addresses are in three or
more VLANs (access points, hypervisors) are not counted as a possible unmanaged switch. The advice for an unmonitored
neighbor depends on LibreNMS' LLDP/CDP autodiscovery: off (`autodiscovery.xdp`), on without discovery networks (`nets`
is empty by default, then it adds nothing), or on (then the eventlog of the device that sees the neighbor says why adding
it failed). Each list stops at 500 rows (the most important first) and says how many there are; the summary counts all.

![Monitoring coverage](docs/images/coverage.png)

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

The plugin is enabled automatically. Open **Overview → Plugins → L2 Topology Map / L3 Topology Map / STP
Diagnostics / Link & VLAN Diagnostics / Redundancy & Failure Impact**, or go to `/plugin/topology`. It can be disabled or configured in
**Overview → Plugins → Plugin Admin → topology → Settings**.

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
* The **Coverage** page lists the network devices LibreNMS only knows as LLDP/CDP neighbors: adding them (or turning on
  LLDP/CDP autodiscovery, `autodiscovery.xdp`) fills the gaps in the maps and in the redundancy analysis.
* If the automatic core is not your core, set **Core devices** in the settings; the switches next to the LibreNMS
  poller are a good choice when you want "what can the poller still reach".
* `plugin/topology/api/l2`, `api/l3`, `api/stp`, `api/links`, `api/redundancy` and `api/coverage` return the same data as
  JSON (same query parameters as the pages, e.g. `api/stp?vlan=30&group=2`, `api/links?device=12`) for automation.
  `api/coverage` cuts each list at the same 500 rows as the page and returns the full counts as `findings_total`,
  `neighbors_total`, `devices_total` and `ports_total`: narrow it with `device`, `group` or `location` for the rest.
  Phones and servers among the neighbors are only counted, in `summary.other_neighbors`.

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
| Core devices | – | hostnames/sysNames/ids every device should keep a path to; empty: designated root bridges, else automatic |
| Warn from | 2 | switches, routers or firewalls cut off by one failure before a single point of failure is a warning |
| Disabled redundancy checks | – | check codes or categories (`outages`, `lost`, `fake`, `spof`), e.g. `spof_link uplinks_same_device` |
| Ports with many MAC addresses | 4 | MAC addresses in the last discovery on an access port without a neighbor that explains them before it is reported as a possible unmanaged switch (0 disables the count, several neighbors on one port are still reported) |
| Disabled coverage checks | – | check codes or categories (`unmonitored`, `devices`, `ports`), e.g. `not_linked hub_suspected` |

## Permissions

Every map and diagnostic only uses devices the logged-in user may see (LibreNMS device permissions).
Neighbors on devices the user cannot see are shown as unmanaged neighbors (their LLDP/CDP name only), and a
root bridge on such a device is not reported as a rogue switch. The coverage report lists a neighbor LibreNMS linked to
such a device as monitored without naming the device (LibreNMS shows the neighbor's name on the device's neighbors tab
too), and compares the names of neighbors LibreNMS did not link only with the devices the user may see (also disabled
ones), so it tells nothing else about the others.

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
* `src/Redundancy/RedundancyAnalyzer.php` – single points of failure (cut vertices and bridges toward the core,
  `Support\Graph::cuts()`), fake and lost redundancy and outages; `CoreFinder` picks the core, `PortUnits` reads stack
  members and line cards from the hardware inventory.
* `src/Coverage/CoverageAnalyzer.php` – the coverage report (unmonitored, unlinked and ambiguous neighbors, devices
  without neighbor data, ports with several neighbors or many MAC addresses); `Links\NeighborReports` is the index of
  which device reports which neighbor, shared with the link checks.
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
* The coverage report only knows what LLDP/CDP and the forwarding tables report: a switch that sends no LLDP/CDP shows
  up at best as a port with many MAC addresses. LibreNMS does not keep the management address a neighbor advertises, so
  *Add* opens the Add Device page without it. Neighbors LibreNMS did not link are matched to monitored devices by
  hostname, sysName, display name (a name without domain matches the same first label with any domain) and interface
  address; LibreNMS itself also links by MAC address, which the report cannot check. A neighbor whose platform,
  description and name tell nothing is listed as of unknown type, without a finding. MAC addresses are those of each
  device's last discovery (LibreNMS keeps older ones for `ports_fdb_purge` days): when that discovery found none on a
  port, the port is not reported. The LLDP/CDP rows are read from the database in chunks and phones and servers are
  only counted, so the whole network of 1,200 switches with 36,000 phones and 2.8 million forwarding table entries takes
  about 2 seconds and 30 MB of PHP memory.
* Servers, access points and phones are recognized by their LibreNMS device type, unmanaged neighbors by their CDP
  platform or, for LLDP (which has no platform), their system description; a neighbor with neither counts as a switch. Down devices without any LLDP/CDP neighbor are
  not listed as outages: nothing tells what they depend on.

## Roadmap

Planned tools (host locator, topology history, routing diagnostics, automatic device dependencies and more) are
listed in [ROADMAP.md](ROADMAP.md).

## License

GPL-3.0-or-later, like LibreNMS.
