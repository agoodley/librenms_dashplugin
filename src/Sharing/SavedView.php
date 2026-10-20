<?php

namespace Agoodley\LibrenmsTopology\Sharing;

/**
 * What a saved map view holds and how it is read back: the map's parameters as MapParams parses them,
 * the positions of its nodes, the options of the map's view bar, and its scope in words. Positions only
 * ever leave the server for the nodes of the map the viewer is shown (forMap()).
 */
class SavedView
{
    /** Positions kept per view: more nodes than any map draws (Maximum devices and their neighbors). */
    public const MAX_POSITIONS = 20000;

    /** Map pages that have views. */
    public const PAGES = ['l2' => 'Layer 2', 'l3' => 'Layer 3'];

    /** Options of the map's view bar a view keeps, and their values. */
    private const OPTIONS = [
        'layout' => ['hierarchical', 'organic'],
        'colorBy' => ['speed', 'util', 'status'],
        'interfaceLabels' => 'bool',
        'linkLabels' => 'bool',
        'stp' => 'bool',
        'outages' => 'bool',
    ];

    /** Parameters that belong to one map only. */
    private const ONLY = [
        'l2' => ['vlan', 'unmanaged', 'lag', 'isolated'],
        'l3' => ['vrf', 'stubs', 'ospf', 'bgp', 'ospfv3', 'isis', 'ipsec', 'mpls', 'pw', 'ipv6', 'all_devices'],
    ];

    /**
     * Query string parameters of a map URL for these parameters (MapParams::parse() output): the
     * values of this map, toggles as 1/0 so that they override the plugin's defaults.
     *
     * @return array<string, string>
     */
    public static function query(string $page, array $params): array
    {
        $other = self::ONLY[$page === 'l2' ? 'l3' : 'l2'];
        $query = [];
        foreach ($params as $key => $value) {
            if (in_array($key, $other, true) || $value === null || $value === '') {
                continue;
            }
            $query[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }
        if (empty($params['device'])) {
            unset($query['depth']); // hops only count around a centered device
        }

        return $query;
    }

    /**
     * Positions sent by a browser, {node id: [x, y] or {x, y}}: anything else is dropped.
     *
     * @return array<string, array{0: float, 1: float}>
     */
    public static function positions(mixed $input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! is_array($input)) {
            return [];
        }

        $positions = [];
        foreach (array_slice($input, 0, self::MAX_POSITIONS, true) as $id => $position) {
            if (! is_string($id) || $id === '' || strlen($id) > 300 || ! is_array($position)) {
                continue;
            }
            $x = $position['x'] ?? $position[0] ?? null;
            $y = $position['y'] ?? $position[1] ?? null;
            if (is_numeric($x) && is_numeric($y) && abs((float) $x) < 1e7 && abs((float) $y) < 1e7) {
                $positions[$id] = [round((float) $x, 1), round((float) $y, 1)];
            }
        }

        return $positions;
    }

    /**
     * The positions of a view for the nodes of the map a viewer is shown: never a node that map does
     * not have (node ids can hold the names of neighbors the viewer may not see).
     *
     * @param  array<string, array{0: float, 1: float}>  $positions
     * @param  array<int, array>  $nodes  the map's nodes (with id)
     * @return array<string, array{x: float, y: float}>
     */
    public static function forMap(array $positions, array $nodes): array
    {
        $layout = [];
        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');
            if (isset($positions[$id])) {
                $layout[$id] = ['x' => (float) $positions[$id][0], 'y' => (float) $positions[$id][1]];
            }
        }

        return $layout;
    }

    /**
     * View bar options sent by a browser: the known ones with an allowed value.
     *
     * @return array<string, string|bool>
     */
    public static function options(mixed $input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        $options = [];
        foreach (self::OPTIONS as $key => $allowed) {
            $value = is_array($input) ? ($input[$key] ?? null) : null;
            if ($allowed === 'bool' && is_bool($value)) {
                $options[$key] = $value;
            } elseif (is_array($allowed) && in_array($value, $allowed, true)) {
                $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * Name of a view as typed: trimmed, without control characters, at most 100 characters; null when empty.
     */
    public static function name(mixed $input): ?string
    {
        if (! is_scalar($input)) {
            return null;
        }
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $input));

        return $name === '' ? null : mb_substr($name, 0, 100);
    }

    /**
     * Scope of a view in words: "core1, 2 hops · location HQ · VLAN 30", or "whole network".
     *
     * @param  array  $params  MapParams::parse() output
     * @param  array{device?: ?string, group?: ?string, location?: ?string}  $names  names the viewer may see, null otherwise
     */
    public static function describe(string $page, array $params, array $names): string
    {
        $parts = [];
        if (! empty($params['device'])) {
            $parts[] = (($names['device'] ?? null) ?: 'a device you cannot see') . ', ' . (int) ($params['depth'] ?? 1) . ' hop' . ((int) ($params['depth'] ?? 1) === 1 ? '' : 's');
        }
        if (! empty($params['group'])) {
            $parts[] = 'group ' . (($names['group'] ?? null) ?: '(one you cannot see)');
        }
        if (! empty($params['location'])) {
            $parts[] = 'location ' . (($names['location'] ?? null) ?: '(one you cannot see)');
        }
        if ($page === 'l2' && ! empty($params['vlan'])) {
            $parts[] = 'VLAN ' . (int) $params['vlan'];
        }
        if ($page === 'l3' && ! empty($params['vrf'])) {
            $parts[] = 'VRF ' . $params['vrf'];
        }

        return $parts ? implode(' · ', $parts) : 'whole network';
    }
}
