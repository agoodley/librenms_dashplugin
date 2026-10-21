<?php

namespace Agoodley\LibrenmsTopology\Sharing;

use Agoodley\LibrenmsTopology\Support\MapParams;

/**
 * What a saved map view holds and the rules for reading it back: the map's parameters as MapParams
 * parses them, the positions of its nodes and the options of the map's view bar; who may open and
 * change a view, and what a user is shown of it (present()): never a device, group, location or VRF
 * they may not see. Positions only ever leave the server for the nodes of the map the viewer is shown
 * (forMap()).
 */
class SavedView
{
    /** Positions read from a browser: more nodes than any map draws (Maximum devices and their neighbors). */
    public const MAX_POSITIONS = 20000;

    /** Longest node id kept (a map node id names a device or an LLDP/CDP neighbor). */
    public const MAX_ID_LENGTH = 200;

    /** Largest layout stored (positions, JSON encoded): a view keeps only the nodes of its map. */
    public const MAX_LAYOUT_BYTES = 1048576;

    /** Views a user may keep. */
    public const MAX_PER_USER = 200;

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
            // a view shows the map as it is when opened, never one of the past (MapParams::time())
            if (in_array($key, $other, true) || in_array($key, [...MapParams::TIME_KEYS, 'time_error'], true) || $value === null || $value === '') {
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
     * Query of a map page that opens a view: the URL's own parameters (without view) win over the
     * view's, which fill in the rest, the L3 overlay toggles too (the map page only submits a toggle
     * that is in its query).
     *
     * @param  array<string, mixed>  $url  the request's query
     * @param  array  $params  the view's parameters (present())
     * @return array<string, mixed>
     */
    public static function mergeQuery(string $page, array $url, array $params): array
    {
        unset($url['view']);

        return $url + self::query($page, $params);
    }

    /**
     * May the user open the view: their own, a shared one, or, as a LibreNMS admin, a personal view whose owner
     * no longer exists (to delete it)?
     *
     * @param  array{user_id: int|string, shared: mixed, owner_id?: ?int}  $row  topology_views row, joined to its owner (owner_id)
     */
    public static function canOpen(array $row, int $userId, bool $admin = false): bool
    {
        return (int) $row['user_id'] === $userId || (bool) $row['shared'] || ($admin && self::orphaned($row));
    }

    /**
     * May the user change (rename, share, update, delete) the view: their own, or a shared one when they
     * are a LibreNMS admin?
     *
     * @param  array{user_id: int|string, shared: mixed}  $row
     */
    public static function canEdit(array $row, int $userId, bool $admin): bool
    {
        return (int) $row['user_id'] === $userId || ((bool) $row['shared'] && $admin);
    }

    /**
     * May the user delete the view: one they may change, or, as a LibreNMS admin, a personal view whose owner
     * no longer exists (nobody else can open it)?
     *
     * @param  array{user_id: int|string, shared: mixed, owner_id?: ?int}  $row  topology_views row joined to its owner (owner_id)
     */
    public static function canDelete(array $row, int $userId, bool $admin): bool
    {
        return self::canEdit($row, $userId, $admin) || ($admin && self::orphaned($row));
    }

    /**
     * Is the view's owner gone (a views row joined to users without a match: owner_id null)?
     */
    private static function orphaned(array $row): bool
    {
        return array_key_exists('owner_id', $row) && $row['owner_id'] === null;
    }

    /**
     * Why a user may not save another view, or null.
     */
    public static function tooMany(int $views): ?string
    {
        return $views >= self::MAX_PER_USER
            ? 'You keep ' . self::MAX_PER_USER . ' saved views, the most a user may: delete some on the Saved Views page to save another.' : null;
    }

    /**
     * The positions of the nodes a map has (the map the view is saved from), and why they cannot be stored, or null.
     *
     * @param  array<string, array{0: float, 1: float}>  $positions  positions()
     * @param  array<int, array>  $nodes  the map's nodes (with id)
     * @return array{0: array<string, array{0: float, 1: float}>, 1: ?string}
     */
    public static function forStorage(array $positions, array $nodes): array
    {
        $kept = array_intersect_key($positions, array_flip(array_map(fn (array $node) => (string) ($node['id'] ?? ''), $nodes)));
        $error = strlen((string) json_encode($kept)) > self::MAX_LAYOUT_BYTES ? 'The layout of this view is too large to save (more than 1 MB).' : null;

        return [$kept, $error];
    }

    /**
     * What a user is shown of a view they may open: its parameters without the device, group,
     * location and VRF they may not see (their ids and names would tell them), its scope in words,
     * owner and whether they may change it. The url is added by the caller.
     *
     * @param  array{id: int|string, user_id: int|string, name: string, page: string, query: ?string, options?: ?string,
     *     shared: mixed, updated_at?: ?string, username?: ?string}  $row  topology_views row with its owner's username
     * @param  array{device?: ?string, group?: ?string, location?: ?string, vrf?: ?string}  $names  the names of the view's
     *     device, group, location and VRF that the user may see, null for the others
     * @return array{id: int, name: string, page: string, params: array, options: array, scope: string, owner: string, mine: bool,
     *     shared: bool, can_edit: bool, can_delete: bool, updated_at: ?string}
     */
    public static function present(array $row, int $userId, bool $admin, array $names): array
    {
        $params = json_decode((string) $row['query'], true);
        $params = is_array($params) ? $params : [];
        $page = (string) $row['page'];
        $scope = self::describe($page, $params, $names);
        foreach (['device', 'group', 'location', 'vrf'] as $key) {
            if (! empty($params[$key]) && ($names[$key] ?? null) === null) {
                $params[$key] = null;
            }
        }
        $mine = (int) $row['user_id'] === $userId;

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'page' => $page,
            'params' => $params,
            'options' => self::options(json_decode((string) ($row['options'] ?? ''), true)),
            'scope' => $scope,
            'owner' => $mine ? 'you' : (string) (($row['username'] ?? null) ?: 'a former user'),
            'mine' => $mine,
            'shared' => (bool) $row['shared'],
            'can_edit' => self::canEdit($row, $userId, $admin),
            'can_delete' => self::canDelete($row, $userId, $admin),
            'updated_at' => $row['updated_at'] ?? null,
        ];
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
            if (! is_string($id) || $id === '' || strlen($id) > self::MAX_ID_LENGTH || ! is_array($position)) {
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
     * @param  array{device?: ?string, group?: ?string, location?: ?string, vrf?: ?string}  $names  names the viewer may see,
     *     null otherwise
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
            $parts[] = 'VRF ' . (($names['vrf'] ?? null) ?: '(one you cannot see)');
        }

        return $parts ? implode(' · ', $parts) : 'whole network';
    }
}
