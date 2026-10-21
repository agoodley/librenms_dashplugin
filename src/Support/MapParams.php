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

    /** Parameters of a map's time: as of a time (at), or today's map with the changes since a time (since). */
    public const TIME_KEYS = ['at', 'since'];

    /** since= value that compares with the saved baseline instead of a time. */
    public const BASELINE = 'baseline';

    /** A date, optionally with a time (minutes, seconds) and a UTC offset: 2026-10-21, 2026-10-21T09:00, 2026-10-21 09:00:00+02:00. */
    private const ISO_TIME = '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?$/i';

    /**
     * Normalize the query string of a map / STP page or API call. Missing values fall back to the
     * plugin settings; invalid values are ignored, except the map's time (time()): an invalid one is
     * reported in time_error, as drawing today's map instead would mislead.
     *
     * @return array{device: ?int, depth: int, group: ?int, location: ?int, vlan: ?int, vrf: ?string, unmanaged: bool,
     *     lag: bool, isolated: bool, stubs: bool, ospf: bool, bgp: bool, ospfv3: bool, isis: bool, ipsec: bool, mpls: bool,
     *     pw: bool, ipv6: bool, all_devices: bool, at: ?int, since: int|string|null, time_error: ?string}
     */
    public static function parse(array $query, PluginSettings $settings): array
    {
        return self::options($query, $settings) + self::time($query);
    }

    /**
     * The map's time: at (a timestamp: the map as the history recorded it then) or since (a timestamp, or
     * BASELINE: today's map with what changed since then), never both. A date and time is written as ISO 8601
     * in the server's time zone unless it carries an offset ("2026-10-21T09:00"); a date alone is its midnight.
     * One that is no such date, lies in the future or comes with the other parameter is left out and
     * explained in time_error.
     *
     * @return array{at: ?int, since: int|string|null, time_error: ?string}
     */
    public static function time(array $query, ?int $now = null): array
    {
        $now ??= time();
        $given = [];
        foreach (self::TIME_KEYS as $key) {
            $value = $query[$key] ?? null;
            if ($value !== null && (! is_scalar($value) || trim((string) $value) !== '')) {
                $given[$key] = is_scalar($value) ? trim((string) $value) : '';
            }
        }
        $none = ['at' => null, 'since' => null, 'time_error' => null];
        if (count($given) > 1) {
            return ['time_error' => 'Show the map either as of a time (at) or with the changes since a time (since), not both.'] + $none;
        }
        if (! $given) {
            return $none;
        }

        $key = array_key_first($given);
        $value = $given[$key];
        if ($key === 'since' && strtolower($value) === self::BASELINE) {
            return ['since' => self::BASELINE] + $none;
        }
        [$year, $month, $day] = array_map('intval', explode('-', substr($value, 0, 10)) + [0, 0, 0]);
        $time = preg_match(self::ISO_TIME, $value) && checkdate($month, $day, $year) ? strtotime($value) : false;
        if ($time === false) {
            return ['time_error' => 'The time "' . mb_substr($value, 0, 40) . '" is not a date and time such as ' . date('Y-m-d\TH:i', $now) . '.'] + $none;
        }
        if ($time > $now) {
            return ['time_error' => 'The time ' . date('Y-m-d H:i', $time) . ' is in the future: the history only knows the past.'] + $none;
        }

        return [$key => $time] + $none;
    }

    /**
     * Which time the parameters ask for: 'at', 'since' or null (the map as it is now).
     */
    public static function timeMode(array $p): ?string
    {
        foreach (self::TIME_KEYS as $key) {
            if (($p[$key] ?? null) !== null) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Does a query string ask for a map at another time (at or since given, valid or not)? Saved views, layouts
     * and Custom Map exports keep the map as it is now.
     */
    public static function asksForTime(array $query): bool
    {
        foreach (self::TIME_KEYS as $key) {
            $value = $query[$key] ?? null;
            if ($value !== null && (! is_scalar($value) || trim((string) $value) !== '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The filters and toggles of parse().
     */
    private static function options(array $query, PluginSettings $settings): array
    {
        return [
            'device' => self::int($query, 'device'),
            'depth' => max(1, min(10, self::int($query, 'depth') ?? $settings->int('default_depth', 1, 10))),
            'group' => self::int($query, 'group'),
            'location' => self::int($query, 'location'),
            'vlan' => self::int($query, 'vlan'),
            'vrf' => isset($query['vrf']) && is_scalar($query['vrf']) && $query['vrf'] !== '' ? mb_substr((string) $query['vrf'], 0, 128) : null,
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
     * The first of device, group and location that a request fills in (as Request::filled() reads it: not
     * absent, empty or blank) but that parse() would drop as no id (abc, 0, -1, a list), or null. A request
     * that writes refuses it (HTTP 422): read as "no filter", it would widen the write to every device
     * (ScopedRepository::strictScope()).
     */
    public static function invalidScope(array $input): ?string
    {
        foreach (['device', 'group', 'location'] as $key) {
            $value = $input[$key] ?? null;
            $given = $value !== null && (! is_scalar($value) || is_bool($value) || trim((string) $value) !== '');
            if ($given && self::int($input, $key) === null) {
                return $key;
            }
        }

        return null;
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
     * Checkbox parameter ('1'/'0'); absent, empty or not a single value uses $default.
     */
    private static function bool(array $query, string $key, bool $default): bool
    {
        if (! isset($query[$key]) || ! is_scalar($query[$key]) || $query[$key] === '') {
            return $default;
        }

        return in_array(strtolower((string) $query[$key]), ['1', 'true', 'on', 'yes'], true);
    }
}
