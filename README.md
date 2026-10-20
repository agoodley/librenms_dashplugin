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

### Host locator and port tab

*Where is this plugged in?* Enter an IPv4 or IPv6 address, a MAC address in any notation or a hostname (a device in
LibreNMS, else DNS) under **Tools → Host Locator**. The answer is the switch port, its VLAN and description, the vendor of
the MAC, the host's IP addresses, its 802.1X / MAB user, and first / last seen.

A host's MAC is learned on every switch along its path, on the port toward it. LibreNMS' own MAC search lists all of
them and only marks a port that holds a single MAC. The host locator follows each entry to the LLDP/CDP neighbor on its
port instead: an inter-switch link or port-channel toward a switch that learned the MAC too is a hop on the way, so the
search ends on the switch the host is plugged into, including:

* a computer behind an **IP phone**, a wireless client behind an **access point**, a virtual machine behind a server
  (the neighbor on the port says which: its CDP platform, or for LLDP its system description);
* hosts behind an **unmanaged switch** (an LLDP/CDP neighbor that LibreNMS does not monitor), or on a port with several
  MACs and no neighbor at all (flagged: probably a hub, switch or virtualization host);
* a switch without LLDP/CDP: a port that learned the switch's own MAC leads to it; without that, its uplink carries the
  MACs of everything behind it and loses to a port with far fewer;
* a host that **moved** or a wireless client that **roamed**: entries from before a switch's last forwarding table
  discovery are listed as old, and of two sightings the newer wins ("seen here 3.8h before the newest sighting");
* a server on **two switches** (a port-channel across a switch pair): the second link is named as such, no warning;
* a **silent host**: when the trail ends at a monitored switch that does not list the MAC (switches forget idle hosts
  after a few minutes) or has no forwarding table data, the answer is *behind* that switch, and the L2 map draws the host
  next to it;
* the address of a **switch, router or firewall** itself, an interface MAC or a **VRRP / HSRP / GLBP** virtual router MAC:
  the answer is the device (for a gateway, the router that is master / active now), not a port on the way to it;
* a host the forwarding tables do not show yet (LibreNMS reads them every 6 hours), that sits behind a switch without
  one, or that moved since: its current **802.1X / MAB session** (polled every 5 minutes) gives the port.

The HSRP and VRRP MACs are the same in every VLAN that uses the group number, so a gateway is located in the VLAN of
the router interface with the ARP entry (Vlan10, irb.10, ve 10, the unit of a subinterface such as Gi0/0.10); when that
names no VLAN the MAC is learned in, and when the MAC itself is searched, there is one answer per VLAN. Every other port
that learned the MAC is listed with the reason the host is not there. **Show on the L2 map** opens the map centered on the switch with the host drawn
next to it on its port (next to the switch it is behind; for a device's own address, the device).

Every **port page** gets a *Plugins* tab with the port's far end (LLDP/CDP in both directions), port-channel, VLANs,
spanning tree state and role per instance, the MAC addresses learned on it (vendor, IP addresses, last seen, a *Locate*
link each) and its 802.1X / MAB sessions.

![Host locator](docs/images/host-locator.png)

![Port tab](docs/images/port-tab.png)

*(Screenshots use a simulated demo network.)*

## Requirements

* LibreNMS 26.x (developed and tested against 26.9 / master of October 2026), PHP 8.2+
* The LibreNMS modules that feed the plugin enabled for your devices:
  `discovery-protocols` (links), `ports`, `vlans`, `ports-stack`, `stp`, `ipv4-addresses`
  (`ipv6-addresses`), `ospf`, `bgp-peers`, `fdb-table` (rogue root tracing, host locator), `arp-table` and `ipv6-nd`
  (host locator), `nac` (802.1X sessions, off by default), `transceivers` and `sensors` (optical levels),
  `entity-physical` (stack members and line cards). The maps and checks work with whatever subset is available; the
  MAC vendors need LibreNMS' OUI table (`mac_oui.enabled`), DNS lookups of the host locator `getent` (glibc or musl).

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
* If the automatic core is not your core, set **Core devices** in the settings; the switches next to the LibreNMS
  poller are a good choice when you want "what can the poller still reach".
* **Host locator** links are shareable: `hosts?q=10.10.10.55`, and `l2?device=7&host=d4bed9010203` opens the L2 map
  around device 7 with that host drawn next to the switch it is plugged into (the switch has to be on the map). On a port
  page, *Plugins* → *Locate* on a MAC finds where that host really is when the port is an uplink.
* `plugin/topology/api/l2`, `api/l3`, `api/stp`, `api/links` and `api/redundancy` return the same data as JSON (same
  query parameters as the pages, e.g. `api/stp?vlan=30&group=2`, `api/links?device=12`) for automation, and
  `api/hosts?q=...` the host locator's answer.

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
| Shared access port | 5 MACs | an access port without LLDP/CDP neighbor with this many MACs is flagged by the host locator |
| DNS lookups | on | hostnames that are not LibreNMS devices are resolved with the server's resolver (`/usr/bin/getent` or `/bin/getent`) in a subprocess stopped after 2 seconds, 4 seconds per search; answers and failures are cached for 5 minutes, 20 lookups per user and minute |

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
* `src/Hosts/HostLocator.php` – follows a MAC's forwarding table entries to the switch port it is plugged into;
  `HostService` turns IP addresses and names into MACs and builds the port tab.
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
* Servers, access points and phones are recognized by their LibreNMS device type, unmanaged neighbors by their CDP
  platform or, for LLDP (which has no platform), their system description; a neighbor with neither counts as a switch. Down devices without any LLDP/CDP neighbor are
  not listed as outages: nothing tells what they depend on.
* The host locator works from the forwarding (MAC address), ARP and IPv6 neighbor tables LibreNMS reads at discovery,
  every 6 hours by default (the `arp-table` poller module refreshes ARP every poll, `nac` is off by default), so every
  answer shows when the host was last seen. LibreNMS keeps forwarding table entries it no longer sees for 10 days
  (`ports_fdb_purge`); the locator lists entries from before a switch's last discovery as old (the start of the
  device's last discovery, confirmed by one entry from it) and counts MACs on a port from that discovery only.
  Switches whose forwarding table LibreNMS does not read end the trail.
* Query cost: LibreNMS has no index on the address in the ARP table or on anything but port and device in the IPv6
  neighbor table, so an IPv4 search reads the whole ARP table once and every search the whole IPv6 neighbor table (twice
  for an IPv6 address); the port tab (on every port page) shows IPv4 addresses only and uses indexed lookups. 802.1X
  sessions are looked up on the ports where the MAC is learned, and by the MAC alone (the whole, small session table)
  when none of them has a current one.

## Roadmap

Planned tools (topology history, routing diagnostics, automatic device dependencies and more) are listed in
[ROADMAP.md](ROADMAP.md).

## License

GPL-3.0-or-later, like LibreNMS.
