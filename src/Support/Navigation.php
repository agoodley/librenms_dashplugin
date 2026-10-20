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
            'description' => 'Routers, L3 switches and firewalls joined through their IP subnets, with point-to-point links, LAN segments, VRFs and OSPF / BGP adjacencies.',
        ],
        'stp' => [
            'route' => 'topology.stp', 'group' => 'diagnostics', 'tab' => 'Spanning Tree', 'title' => 'Spanning Tree Diagnostics', 'icon' => 'fa-stethoscope',
            'description' => 'Root bridge consistency, rogue roots, topology change storms, flapping and blocked ports, bridging loops, timer and protocol mismatches.',
        ],
        'links' => [
            'route' => 'topology.links', 'group' => 'diagnostics', 'tab' => 'Link & VLAN', 'title' => 'Link & VLAN Diagnostics', 'icon' => 'fa-link',
            'description' => 'Both ends of every link compared: speed, duplex, MTU, trunk/native/allowed VLANs and port-channels, plus split VLANs, errors, optical levels and port descriptions.',
        ],
        'redundancy' => [
            'route' => 'topology.redundancy', 'group' => 'diagnostics', 'tab' => 'Redundancy', 'title' => 'Redundancy & Failure Impact', 'icon' => 'fa-code-branch',
            'description' => 'Single points of failure, uplinks that only look redundant (same switch, same stack member), redundancy lost right now, and outages with the devices behind them.',
        ],
        'changes' => [
            'route' => 'topology.changes', 'group' => 'history', 'tab' => 'Changes', 'title' => 'Topology Changes', 'icon' => 'fa-history',
            'description' => 'What changed over the last day or week (moved cables, new neighbors, root bridge moves, lost adjacencies), what is missing compared with a saved baseline, and findings that came or went.',
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
