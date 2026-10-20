<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * Parses map/diagnostic query parameters (shared by pages and the JSON API so map URLs are shareable).
 */
class MapParams
{
    /**
     * More L3 overlays and whether each is on unless switched off (the map only offers those with data).
     * MPLS LSPs are off: an RSVP full mesh draws an edge between every two PEs.
     */
    public const L3_LAYERS = ['ospfv3' => true, 'isis' => true, 'ipsec' => true, 'mpls' => false, 'pw' => true];

    /**
     * Normalize the query string of a map / STP page or API call. Missing values fall back to the
     * plugin settings; invalid values are ignored.
     *
     * @return array{device: ?int, depth: int, group: ?int, location: ?int, vlan: ?int, vrf: ?string, unmanaged: bool,
     *     lag: bool, isolated: bool, stubs: bool, ospf: bool, bgp: bool, ospfv3: bool, isis: bool, ipsec: bool, mpls: bool,
     *     pw: bool, ipv6: bool, all_devices: bool}
     */
    public static function parse(array $query, PluginSettings $settings): array
    {
        return [
            'device' => self::int($query, 'device'),
            'depth' => max(1, min(10, self::int($query, 'depth') ?? $settings->int('default_depth', 1, 10))),
            'group' => self::int($query, 'group'),
            'location' => self::int($query, 'location'),
            'vlan' => self::int($query, 'vlan'),
            'vrf' => isset($query['vrf']) && $query['vrf'] !== '' ? mb_substr((string) $query['vrf'], 0, 128) : null,
            'unmanaged' => self::bool($query, 'unmanaged', $settings->bool('show_unmanaged')),
            'lag' => self::bool($query, 'lag', $settings->bool('collapse_lag')),
            'isolated' => self::bool($query, 'isolated', false),
            'stubs' => self::bool($query, 'stubs', ! $settings->bool('l3_hide_stubs')),
            'ospf' => self::bool($query, 'ospf', true),
            'bgp' => self::bool($query, 'bgp', true),
            'ospfv3' => self::bool($query, 'ospfv3', self::L3_LAYERS['ospfv3']),
            'isis' => self::bool($query, 'isis', self::L3_LAYERS['isis']),
            'ipsec' => self::bool($query, 'ipsec', self::L3_LAYERS['ipsec']),
            'mpls' => self::bool($query, 'mpls', self::L3_LAYERS['mpls']),
            'pw' => self::bool($query, 'pw', self::L3_LAYERS['pw']),
            'ipv6' => self::bool($query, 'ipv6', false),
            'all_devices' => self::bool($query, 'all_devices', false),
        ];
    }

    /**
     * Positive integer parameter or null.
     */
    private static function int(array $query, string $key): ?int
    {
        $value = $query[$key] ?? null;
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * Checkbox parameter ('1'/'0'); absent or empty uses $default.
     */
    private static function bool(array $query, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $query) || $query[$key] === '' || $query[$key] === null) {
            return $default;
        }

        return in_array(strtolower((string) $query[$key]), ['1', 'true', 'on', 'yes'], true);
    }
}
