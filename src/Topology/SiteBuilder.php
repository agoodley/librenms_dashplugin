<?php

namespace Agoodley\LibrenmsTopology\Topology;

/**
 * Builds the site / WAN map: one node per location with its device counts and down devices, and one
 * link per pair of sites adding up the connections between their devices (neighbor links,
 * point-to-point subnets, OSPF adjacencies and BGP sessions). BGP sessions to peers LibreNMS does not
 * monitor join the site to one cloud per remote AS, such as a provider's MPLS VPN or internet transit.
 * The devices without a location are one site too, but no place that joins sites: the map is never
 * walked through it.
 */
class SiteBuilder
{
    /** Node id of the devices without a location. */
    public const NO_LOCATION = 'site:none';

    /** Connections that carry traffic and add up to the capacity; the others are routing sessions. */
    private const LINK_KINDS = ['l2', 'p2p'];

    /** Order of the members of a site link. */
    private const KIND_ORDER = ['l2' => 0, 'p2p' => 1, 'ospf' => 2, 'bgp' => 3];

    /**
     * @param  array<int, array>  $devices  device_id => row: display, hostname, status, disabled, role, url, location_id,
     *                                      location, lat, lng
     * @param  array<int, array>  $connections  between two devices, or a device and an AS cloud: kind (l2, p2p, ospf, bgp),
     *     a / b (ends: device_id, port, port_url, aggregate; b is null for a cloud), cloud (id, label, name, remote_as,
     *     private, shared), speed (bps, 0 when only the traffic is known), status (up / down / disabled: shut down on purpose),
     *     util (%), in_bps / out_bps (measured at end a), network, state, states (of the a and b sides of a session),
     *     type, protocols, description
     * @param  array<int, int>  $outages  device_id => start of the outage (unix time) of down devices
     */
    public function __construct(
        private readonly array $devices,
        private readonly array $connections,
        private readonly array $outages = [],
    ) {
    }

    /**
     * @param  array{center?: int|null, depth?: int|null, isolated?: bool, maxSites?: int}  $options
     *     center: location id of the site to center on, with the sites within depth hops (a cloud is a hop);
     *     isolated: keep sites without links; maxSites: largest map, the best connected sites are kept
     * @return array{nodes: array, edges: array, stats: array, center: string|null, total: int, truncated: bool}
     */
    public function build(array $options = []): array
    {
        $nodes = $this->sites();
        [$edges, $clouds] = $this->siteLinks($nodes);
        $nodes += $clouds;
        $adjacent = self::adjacency($edges);
        $degree = fn (string $id) => count($adjacent[$id] ?? []);

        $center = ! empty($options['center']) ? 'site:' . (int) $options['center'] : null;
        if ($center !== null && ! isset($nodes[$center])) {
            $center = null; // no device of this location in the scope
        }

        $kept = $center !== null
            ? self::hops($adjacent, $center, max(1, (int) ($options['depth'] ?? 1)))
            : array_fill_keys(array_keys($nodes), true);
        if (! ($options['isolated'] ?? false)) {
            $kept = array_filter($kept, fn ($_, $id) => $id === $center || $degree((string) $id) > 0, ARRAY_FILTER_USE_BOTH);
        }

        $siteIds = array_values(array_filter(array_map('strval', array_keys($kept)), fn ($id) => $nodes[$id]['type'] === 'site'));
        $total = count($siteIds);
        $max = max(1, (int) ($options['maxSites'] ?? PHP_INT_MAX));
        $truncated = $total > $max;
        if ($truncated) {
            // the centered site, then the best connected; the devices without a location only when there is room
            $rank = fn (string $id) => [$id === $center, $id !== self::NO_LOCATION, $degree($id), $nodes[$id]['devices']];
            usort($siteIds, fn ($a, $b) => [...$rank($b), $nodes[$a]['name']] <=> [...$rank($a), $nodes[$b]['name']]);
            $kept = array_intersect_key($kept, array_flip(array_slice($siteIds, 0, $max)))
                + array_filter($kept, fn ($_, $id) => $nodes[$id]['type'] !== 'site', ARRAY_FILTER_USE_BOTH);
        }

        $edges = array_values(array_filter($edges, fn ($edge) => isset($kept[$edge['from']], $kept[$edge['to']])));
        $linked = [];
        foreach ($edges as $index => &$edge) {
            $edge['id'] = 'e' . $index;
            $linked[$edge['from']] = ($linked[$edge['from']] ?? 0) + 1;
            $linked[$edge['to']] = ($linked[$edge['to']] ?? 0) + 1;
        }
        unset($edge);

        // clouds only stay with at least one of their sites
        $nodes = array_filter($nodes, fn ($node, $id) => isset($kept[$id]) && ($node['type'] === 'site' || isset($linked[$id])), ARRAY_FILTER_USE_BOTH);
        foreach ($nodes as $id => &$node) {
            $node['degree'] = $linked[$id] ?? 0;
        }
        unset($node);
        $this->assignLevels($nodes, $edges, $center);

        $sites = array_filter($nodes, fn ($node) => $node['type'] === 'site');

        return [
            'nodes' => array_values($nodes),
            'edges' => $edges,
            'stats' => [
                'sites' => count($sites),
                'devices' => array_sum(array_column($sites, 'devices')),
                'down_devices' => array_sum(array_column($sites, 'down')),
                'down_sites' => count(array_filter($sites, fn ($site) => $site['status'] === 'down')),
                'degraded_sites' => count(array_filter($sites, fn ($site) => $site['status'] === 'degraded')),
                'links' => count($edges),
                'down_links' => count(array_filter($edges, fn ($edge) => $edge['status'] === 'down')),
                'degraded_links' => count(array_filter($edges, fn ($edge) => $edge['status'] === 'degraded')),
                'clouds' => count($nodes) - count($sites),
            ],
            'center' => $center,
            'total' => $total,
            'truncated' => $truncated,
        ];
    }

    /**
     * Node id of the site of a device.
     */
    public static function siteId(array $device): string
    {
        return ! empty($device['location_id']) ? 'site:' . (int) $device['location_id'] : self::NO_LOCATION;
    }

    /**
     * One node per location with device counts by role and the down devices.
     *
     * @return array<string, array>
     */
    private function sites(): array
    {
        $sites = [];
        foreach ($this->devices as $deviceId => $device) {
            $id = self::siteId($device);
            if (! isset($sites[$id])) {
                [$lat, $lng] = self::coordinates($device['lat'] ?? null, $device['lng'] ?? null);
                $sites[$id] = [
                    'id' => $id,
                    'type' => 'site',
                    'managed' => true,
                    'location_id' => ! empty($device['location_id']) ? (int) $device['location_id'] : null,
                    'name' => $id === self::NO_LOCATION ? 'No location' : (string) $device['location'],
                    'role' => 'site',
                    'lat' => $lat,
                    'lng' => $lng,
                    'devices' => 0,
                    'down' => 0,
                    'roles' => [],
                    'down_devices' => [],
                    'outage_since' => null,
                ];
            }

            $site = &$sites[$id];
            $site['devices']++;
            $role = (string) ($device['role'] ?? 'other');
            $site['roles'][$role] = ($site['roles'][$role] ?? 0) + 1;
            if (L2Builder::deviceStatus($device) === 'down') {
                $since = $this->outages[$deviceId] ?? null;
                $site['down']++;
                $site['down_devices'][] = ['device_id' => (int) $deviceId, 'name' => $this->deviceName((int) $deviceId), 'url' => $device['url'] ?? null, 'since' => $since];
                if ($since !== null && ($site['outage_since'] === null || $since < $site['outage_since'])) {
                    $site['outage_since'] = $since;
                }
            }
            unset($site);
        }

        foreach ($sites as &$site) {
            arsort($site['roles']);
            usort($site['down_devices'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
            $site['status'] = match (true) {
                $site['down'] === 0 => 'up',
                $site['down'] === $site['devices'] => 'down',
                default => 'degraded',
            };
            $site['label'] = $site['name'] . "\n" . $site['devices'] . ' device' . ($site['devices'] === 1 ? '' : 's')
                . ($site['down'] ? ' · ' . $site['down'] . ' down' : '');
        }
        unset($site);
        uasort($sites, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $sites;
    }

    /**
     * Connections between devices of different sites added up per pair of sites, and the AS clouds of
     * BGP sessions to unmonitored peers.
     *
     * @param  array<string, array>  $sites
     * @return array{0: array<string, array>, 1: array<string, array>} site links by "from|to", clouds by id
     */
    private function siteLinks(array $sites): array
    {
        $edges = [];
        $clouds = [];
        foreach ($this->connections as $connection) {
            $aDevice = (int) ($connection['a']['device_id'] ?? 0);
            if (! isset($this->devices[$aDevice])) {
                continue;
            }
            $from = self::siteId($this->devices[$aDevice]);

            if (! empty($connection['b'])) {
                $bDevice = (int) ($connection['b']['device_id'] ?? 0);
                if (! isset($this->devices[$bDevice])) {
                    continue;
                }
                $to = self::siteId($this->devices[$bDevice]);
                if ($to === $from) {
                    continue; // inside one site
                }
            } elseif (! empty($connection['cloud']['id'])) {
                $cloud = $connection['cloud'];
                $to = (string) $cloud['id'];
                $clouds[$to] ??= [
                    'id' => $to,
                    'type' => 'as',
                    'managed' => false,
                    'label' => (string) ($cloud['label'] ?? $to),
                    'name' => (string) ($cloud['name'] ?? $to),
                    'role' => 'cloud',
                    'status' => 'unknown',
                    'remote_as' => $cloud['remote_as'] ?? null,
                    'private' => (bool) ($cloud['private'] ?? false),
                    'shared' => (bool) ($cloud['shared'] ?? false),
                ];
            } else {
                continue;
            }

            // site to site links run from the lower id; a cloud is always the far end
            $flip = isset($sites[$to]) && strcmp($from, $to) > 0;
            [$edgeFrom, $edgeTo] = $flip ? [$to, $from] : [$from, $to];
            $key = "$edgeFrom|$edgeTo";
            $edges[$key] ??= [
                'kind' => 'site',
                'from' => $edgeFrom,
                'to' => $edgeTo,
                'links' => 0,
                'routing' => ['ospf' => 0, 'bgp' => 0],
                'speed' => 0,
                'util' => null,
                'from_bps' => 0,
                'to_bps' => 0,
                'members' => [],
                'a' => null,
                'b' => null,
            ];

            $member = $this->member($connection, $flip);
            $edge = &$edges[$key];
            if (in_array($member['kind'], self::LINK_KINDS, true)) {
                $edge['links']++;
                $edge['speed'] += $member['speed'];
                if ($member['util'] !== null) {
                    $edge['util'] = max($edge['util'] ?? 0, $member['util']);
                }
                $edge['from_bps'] += $member['out_bps'];
                $edge['to_bps'] += $member['in_bps'];
            } else {
                $edge['routing'][$member['kind']] = ($edge['routing'][$member['kind']] ?? 0) + 1;
            }
            $edge['members'][] = $member;
            unset($edge);
        }

        foreach ($edges as &$edge) {
            // a session shut down on purpose is neither up nor a fault
            $active = array_filter($edge['members'], fn ($m) => $m['status'] !== 'disabled');
            $up = count(array_filter($active, fn ($m) => $m['status'] === 'up'));
            $edge['status'] = match (true) {
                ! $active => 'disabled',
                $up === count($active) => 'up',
                $up === 0 => 'down',
                default => 'degraded',
            };
            usort($edge['members'], fn ($a, $b) => [self::KIND_ORDER[$a['kind']] ?? 9, $a['a']['device'], (string) $a['a']['port']]
                <=> [self::KIND_ORDER[$b['kind']] ?? 9, $b['a']['device'], (string) $b['a']['port']]);
        }
        unset($edge);
        ksort($edges);

        return [$edges, $clouds];
    }

    /**
     * Member of a site link with device names, its end in the site link's first site as end a.
     */
    private function member(array $connection, bool $flip): array
    {
        $end = function (?array $end): ?array {
            if (! $end) {
                return null;
            }
            $deviceId = (int) $end['device_id'];

            return [
                'device_id' => $deviceId,
                'device' => $this->deviceName($deviceId),
                'device_url' => $this->devices[$deviceId]['url'] ?? null,
                'port' => $end['port'] ?? null,
                'port_url' => $end['port_url'] ?? null,
                'aggregate' => $end['aggregate'] ?? null,
            ];
        };

        $a = $end($connection['a']);
        $b = $end($connection['b'] ?? null);
        $in = (int) ($connection['in_bps'] ?? 0);
        $out = (int) ($connection['out_bps'] ?? 0);
        $states = $connection['states'] ?? null;
        if ($flip && $b !== null) {
            [$a, $b, $in, $out] = [$b, $a, $out, $in];
            $states = $states ? array_reverse($states) : null;
        }
        $status = (string) ($connection['status'] ?? 'up');

        return [
            'kind' => (string) $connection['kind'],
            'a' => $a,
            'b' => $b,
            'peer' => $connection['cloud']['label'] ?? null,
            'speed' => (int) ($connection['speed'] ?? 0),
            'status' => in_array($status, ['up', 'disabled'], true) ? $status : 'down',
            'util' => isset($connection['util']) ? (float) $connection['util'] : null,
            'in_bps' => $in,
            'out_bps' => $out,
            'network' => $connection['network'] ?? null,
            // the state each side reports, in the member's a / b order, when they differ ("full / exstart")
            'state' => $states ? (implode(' / ', array_values(array_unique(array_filter($states)))) ?: null) : ($connection['state'] ?? null),
            'type' => $connection['type'] ?? null,
            'protocols' => array_values($connection['protocols'] ?? []),
            'description' => (string) ($connection['description'] ?? ''),
        ];
    }

    /**
     * Hierarchy levels for the top-down layout: hops from the centered site, otherwise from the best
     * connected site of each island (most site links, then most devices), never through the devices
     * without a location.
     */
    private function assignLevels(array &$nodes, array $edges, ?string $center): void
    {
        $adjacent = self::adjacency($edges);
        $rank = fn (string $id) => [$id === $center, $nodes[$id]['type'] === 'site' && $id !== self::NO_LOCATION, $nodes[$id]['type'] === 'site',
            count($adjacent[$id] ?? []), $nodes[$id]['devices'] ?? 0];
        $roots = array_map('strval', array_keys($nodes));
        usort($roots, fn ($a, $b) => [...$rank($b), $nodes[$a]['name']] <=> [...$rank($a), $nodes[$b]['name']]);

        $levels = [];
        foreach ($roots as $root) {
            if (! isset($levels[$root])) {
                $levels += self::hops($adjacent, $root, null, $levels);
            }
        }

        foreach ($nodes as $id => &$node) {
            $node['level'] = $levels[$id] ?? 0;
        }
    }

    /**
     * Site links as neighbor lists.
     *
     * @return array<string, string[]>
     */
    private static function adjacency(array $edges): array
    {
        $adjacent = [];
        foreach ($edges as $edge) {
            $adjacent[$edge['from']][] = $edge['to'];
            $adjacent[$edge['to']][] = $edge['from'];
        }

        return $adjacent;
    }

    /**
     * Hops from a node over the site links, up to $max. The devices without a location are reached
     * but not crossed (unless the walk starts there): they are not a place that joins two sites.
     *
     * @param  array<string, mixed>  $visited  nodes left out
     * @return array<string, int>
     */
    private static function hops(array $adjacent, string $start, ?int $max = null, array $visited = []): array
    {
        $distance = [$start => 0];
        $queue = [$start];
        for ($i = 0; $i < count($queue); $i++) {
            $node = $queue[$i];
            if (($max !== null && $distance[$node] >= $max) || ($node === self::NO_LOCATION && $node !== $start)) {
                continue;
            }
            foreach ($adjacent[$node] ?? [] as $next) {
                if (! isset($distance[$next]) && ! isset($visited[$next])) {
                    $distance[$next] = $distance[$node] + 1;
                    $queue[] = $next;
                }
            }
        }

        return $distance;
    }

    private function deviceName(int $deviceId): string
    {
        $device = $this->devices[$deviceId] ?? [];

        return (string) (($device['display'] ?? '') ?: ($device['hostname'] ?? '') ?: '#' . $deviceId);
    }

    /**
     * Latitude and longitude of a location, null when unknown, out of range or 0 / 0 (unset).
     *
     * @return array{0: float|null, 1: float|null}
     */
    private static function coordinates(mixed $lat, mixed $lng): array
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return [null, null];
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) {
            return [null, null];
        }

        return [$lat, $lng];
    }
}
