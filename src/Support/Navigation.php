<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * The plugin's pages, in the order and groups of the navigation shown on every page, the LibreNMS
 * plugin menu and the landing page. A new page adds its entry here and its route in routes/web.php.
 */
class Navigation
{
    /** Navigation groups in order: key => label and Font Awesome icon. */
    public const GROUPS = [
        'maps' => ['label' => 'Maps', 'icon' => 'fa-map'],
        'diagnostics' => ['label' => 'Diagnostics', 'icon' => 'fa-heartbeat'],
        'tools' => ['label' => 'Tools', 'icon' => 'fa-wrench'],
        'history' => ['label' => 'History', 'icon' => 'fa-history'],
    ];

    /**
     * key (the `active` of the nav partial) => route: route name; group: one of GROUPS; tab: short label in
     * the navigation; title: menu and landing page; icon: Font Awesome class; description: landing page;
     * scoped (optional, default true): links keep the device, group, location and VLAN of the current page.
     */
    public const PAGES = [
        'l2' => [
            'route' => 'topology.l2', 'group' => 'maps', 'tab' => 'Layer 2', 'title' => 'Layer 2 Map', 'icon' => 'fa-sitemap',
            'description' => 'Switches and their LLDP/CDP links with interface names, link speeds, port-channels, VLAN highlighting and a spanning tree overlay (root bridge, blocked ports).',
        ],
        'l3' => [
            'route' => 'topology.l3', 'group' => 'maps', 'tab' => 'Layer 3', 'title' => 'Layer 3 Map', 'icon' => 'fa-project-diagram',
            'description' => 'Routers, L3 switches and firewalls joined through their IP subnets, with point-to-point links, LAN segments, VRFs, OSPF / BGP adjacencies and OSPFv3, IS-IS, IPsec, MPLS and pseudowire overlays.',
        ],
        'sites' => [
            'route' => 'topology.sites', 'group' => 'maps', 'tab' => 'Sites', 'title' => 'Site / WAN Map', 'icon' => 'fa-globe',
            'description' => 'One node per location with its devices and outages, joined by the links, point-to-point subnets and OSPF / BGP sessions between sites, optionally placed by latitude and longitude.',
        ],
        'views' => [
            'route' => 'topology.views', 'group' => 'maps', 'tab' => 'Saved Views', 'title' => 'Saved Views', 'icon' => 'fa-bookmark', 'scoped' => false,
            'description' => 'Named views of the L2 and L3 maps with their scope, filters and device positions, personal or shared with the team; open, rename, share or delete them.',
        ],
        'stp' => [
            'route' => 'topology.stp', 'group' => 'diagnostics', 'tab' => 'Spanning Tree', 'title' => 'Spanning Tree Diagnostics', 'icon' => 'fa-stethoscope',
            'description' => 'Root bridge consistency, rogue roots, topology change storms, flapping and blocked ports, bridging loops, timer and protocol mismatches, and loop evidence from broadcast storms and syslog.',
        ],
        'links' => [
            'route' => 'topology.links', 'group' => 'diagnostics', 'tab' => 'Link & VLAN', 'title' => 'Link & VLAN Diagnostics', 'icon' => 'fa-link',
            'description' => 'Both ends of every link compared: speed, duplex, MTU, trunk/native/allowed VLANs and port-channels, plus split VLANs, errors, optical levels and port descriptions.',
        ],
        'interfaces' => [
            'route' => 'topology.interfaces', 'group' => 'diagnostics', 'tab' => 'Interfaces', 'title' => 'Interface Health', 'icon' => 'fa-plug',
            'description' => 'Every Ethernet port, desk ports too: links that keep going down, speeds flapping between 1G and 100M or stuck lower than they were, half duplex, late collisions and errors over the last hours.',
        ],
        'routing' => [
            'route' => 'topology.routing', 'group' => 'diagnostics', 'tab' => 'Routing & IP', 'title' => 'Routing & IP Diagnostics', 'icon' => 'fa-route',
            'description' => 'OSPF router IDs, adjacencies, interface settings and area databases, BGP sessions with their last error as text and prefix limits, and IP problems: duplicate addresses, mask mismatches, subnets split between VRFs.',
        ],
        'redundancy' => [
            'route' => 'topology.redundancy', 'group' => 'diagnostics', 'tab' => 'Redundancy', 'title' => 'Redundancy & Failure Impact', 'icon' => 'fa-code-branch',
            'description' => 'Single points of failure, uplinks that only look redundant (same switch, same stack member), redundancy lost right now, and outages with the devices behind them.',
        ],
        'hosts' => [
            'route' => 'topology.hosts', 'group' => 'tools', 'tab' => 'Host Locator', 'title' => 'Host Locator', 'icon' => 'fa-map-marker', 'scoped' => false,
            'description' => 'Where is this plugged in? An IP, MAC or hostname to its switch port, VLAN, vendor and 802.1X user, following the forwarding tables past uplinks, port-channels, phones and access points.',
        ],
        'path' => [
            'route' => 'topology.path', 'group' => 'tools', 'tab' => 'Path Trace', 'title' => 'Path Trace', 'icon' => 'fa-arrows-left-right', 'scoped' => false,
            'description' => 'Every hop between two hosts or devices: through the forwarding ports of the spanning tree within a VLAN, or router by router through the route tables, with the speed, utilization, errors and duplex of each port.',
        ],
        'coverage' => [
            'route' => 'topology.coverage', 'group' => 'diagnostics', 'tab' => 'Coverage', 'title' => 'Monitoring Coverage', 'icon' => 'fa-binoculars',
            'description' => 'Switches, routers, firewalls and access points seen as LLDP/CDP neighbors but not monitored, devices missing from the maps because they report no neighbors, and ports that look like unmanaged switches.',
        ],
        'changes' => [
            'route' => 'topology.changes', 'group' => 'history', 'tab' => 'Changes', 'title' => 'Topology Changes', 'icon' => 'fa-history',
            'description' => 'What changed over the last day or week (moved cables, new neighbors, root bridge moves, lost adjacencies), what is missing compared with a saved baseline, and findings that came or went.',
        ],
        'rollout' => [
            'route' => 'topology.rollout', 'group' => 'tools', 'tab' => 'Rollout Planner', 'title' => 'Rollout Planner', 'icon' => 'fa-layer-group',
            'description' => 'Batches for a firmware upgrade, reboot or configuration change of many devices: farthest from the core first, never two devices that back each other up at once, and what each batch takes down.',
        ],
        'dependencies' => [
            'route' => 'topology.dependencies', 'group' => 'tools', 'tab' => 'Dependencies', 'title' => 'Device Dependencies', 'icon' => 'fa-sort-amount-down',
            'description' => 'LibreNMS parent devices derived from the topology toward the core (both uplinks of dual-homed devices), compared with the current ones and checked against past outages.',
        ],
    ];

    /**
     * Groups with their pages in navigation order; groups without pages are left out.
     *
     * @return array<string, array{label: string, icon: string, pages: array<string, array>}>
     */
    public static function groups(): array
    {
        $groups = [];
        foreach (self::GROUPS as $key => $group) {
            $pages = array_filter(self::PAGES, fn (array $page) => $page['group'] === $key);
            if ($pages) {
                $groups[$key] = $group + ['pages' => $pages];
            }
        }

        return $groups;
    }

    /**
     * Group of a page, null for an unknown page.
     */
    public static function groupOf(string $page): ?string
    {
        return self::PAGES[$page]['group'] ?? null;
    }

    /**
     * Does the page keep the current device, group, location and VLAN in its links?
     */
    public static function scoped(array $page): bool
    {
        return $page['scoped'] ?? true;
    }
}
