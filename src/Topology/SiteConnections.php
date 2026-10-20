<?php

namespace Agoodley\LibrenmsTopology\Topology;

use Agoodley\LibrenmsTopology\Support\InterfaceName;

/**
 * Connections between devices at different sites, for SiteBuilder: neighbor links (one per cable),
 * point-to-point subnets that no neighbor link already covers, OSPF adjacencies and BGP sessions
 * (one per session to a peer LibreNMS does not monitor).
 */
class SiteConnections
{
    /** @var array<int, array> */
    private array $connections = [];

    /** @var array<string, int> port pair ("12-34", also of the port-channels) => index of the neighbor link on it */
    private array $linkPorts = [];

    /**
     * @param  array<int, array>  $devices  device_id => row with location_id (see SiteBuilder)
     */
    public function __construct(private readonly array $devices)
    {
    }

    /**
     * Neighbor links between devices of different sites.
     *
     * @param  array<int, array>  $links  rows of L2Builder::uniqueLinks()
     * @param  array<int, array>  $ports  port_id => row (link ports and their port-channels)
     * @param  array<int, int>  $lag  member port_id => port-channel port_id
     */
    public function addLinks(array $links, array $ports, array $lag): self
    {
        foreach ($links as $link) {
            $aDevice = (int) $link['a_device'];
            $bDevice = (int) ($link['b_device'] ?? 0);
            if ($this->site($aDevice) === null || $this->site($bDevice) === null || $this->site($aDevice) === $this->site($bDevice)) {
                continue;
            }
            $aPort = (int) ($link['a_port'] ?? 0);
            $bPort = (int) ($link['b_port'] ?? 0);
            $pa = $aPort ? ($ports[$aPort] ?? null) : null;
            $pb = $bPort ? ($ports[$bPort] ?? null) : null;

            $index = count($this->connections);
            $this->connections[] = $this->trafficLink('l2', $aDevice, $pa, $bDevice, $pb, [
                'b_name' => (string) ($link['b_name'] ?? ''),
                'aggregate_a' => isset($lag[$aPort]) ? ($ports[$lag[$aPort]] ?? null) : null,
                'aggregate_b' => isset($lag[$bPort]) ? ($ports[$lag[$bPort]] ?? null) : null,
                'protocols' => $link['protocols'] ?? [],
                'status' => L2Builder::isUp($pa, $pb) ? 'up' : 'down',
            ]);

            if ($aPort && $bPort) {
                $this->linkPorts[self::pair($aPort, $bPort)] = $index;
                if (isset($lag[$aPort], $lag[$bPort])) {
                    $this->linkPorts[self::pair($lag[$aPort], $lag[$bPort])] = $index;
                }
            }
        }

        return $this;
    }

    /**
     * Ports of the point-to-point subnets between sites, whose speed and traffic addL3() needs.
     *
     * @param  array  $l3  result of L3Builder::build()
     * @return int[]
     */
    public function subnetPorts(array $l3): array
    {
        $ports = [];
        foreach ($l3['edges'] as $edge) {
            if ($edge['kind'] === 'p2p' && $this->crossesSites($edge)) {
                $ports[] = (int) $edge['a']['port_id'];
                $ports[] = (int) $edge['b']['port_id'];
            }
        }

        return array_values(array_unique(array_filter($ports)));
    }

    /**
     * Point-to-point subnets, OSPF adjacencies and BGP sessions between sites, as the L3 map finds
     * them. A subnet on a cable that a neighbor link already covers only adds its network to it,
     * and both families of a dual stack link make one connection.
     *
     * @param  array  $l3  result of L3Builder::build()
     * @param  array<int, array>  $ports  port_id => row of the point-to-point ports (subnetPorts())
     * @param  array<int, array>  $bgp  bgpPeers rows the L3 map was built from
     */
    public function addL3(array $l3, array $ports, array $bgp): self
    {
        $clouds = [];
        foreach ($l3['nodes'] as $node) {
            if ($node['type'] === 'as') {
                $clouds[$node['id']] = $node;
            }
        }
        $sessions = [];
        foreach ($bgp as $row) {
            $sessions[(int) $row['device_id'] . '|' . $row['bgpPeerIdentifier']] = $row;
        }

        $subnets = [];
        foreach ($l3['edges'] as $edge) {
            $cloud = $clouds[$edge['to']] ?? null;
            if (! $this->crossesSites($edge) && ! ($cloud && $edge['kind'] === 'bgp' && $this->site(self::deviceId($edge['from'])) !== null)) {
                continue; // inside one site, a subnet member, or an OSPF neighbor LibreNMS does not monitor
            }
            $from = self::deviceId($edge['from']);

            if ($edge['kind'] === 'p2p') {
                $key = self::pair((int) $edge['a']['port_id'], (int) $edge['b']['port_id']);
                $network = $edge['network'] . ($edge['vrf'] !== '' ? ' (' . $edge['vrf'] . ')' : '');
                $index = $this->linkPorts[$key] ?? $subnets[$key] ?? null;
                if ($index !== null) {
                    $this->connections[$index]['network'] = trim(($this->connections[$index]['network'] ?? '') . ', ' . $network, ', ');
                    continue;
                }
                $subnets[$key] = count($this->connections);
                $pa = $ports[(int) $edge['a']['port_id']] ?? null;
                $pb = $ports[(int) $edge['b']['port_id']] ?? null;
                $this->connections[] = $this->trafficLink('p2p', (int) $edge['a']['device_id'], $pa, (int) $edge['b']['device_id'], $pb, [
                    'b_name' => (string) ($edge['b']['name'] ?? ''),
                    'network' => $network,
                    'status' => $edge['status'],
                ]);
            } elseif ($cloud) {
                // sessions to an AS LibreNMS does not monitor are separate peerings (e.g. two provider routers)
                foreach (array_unique($edge['peers']) as $peer) {
                    $row = $sessions[$from . '|' . $peer] ?? null;
                    $state = strtolower((string) ($row['bgpPeerState'] ?? $edge['state']));
                    $this->connections[] = [
                        'kind' => 'bgp',
                        'a' => ['device_id' => $from],
                        'b' => null,
                        'cloud' => [
                            'id' => $cloud['id'],
                            'label' => $cloud['label'],
                            'name' => $cloud['name'],
                            'remote_as' => (int) ($edge['remote_as'] ?? 0) ?: null,
                        ],
                        'status' => $state === 'established' ? 'up' : 'down',
                        'state' => $state,
                        'type' => $edge['type'],
                        'network' => $peer,
                        'description' => (string) ($row['bgpPeerDescr'] ?? $edge['description'] ?? ''),
                    ];
                }
            } elseif ($edge['kind'] === 'ospf' || $edge['kind'] === 'bgp') {
                // one adjacency or session, seen from both of its routers
                $this->connections[] = [
                    'kind' => $edge['kind'],
                    'a' => ['device_id' => $from],
                    'b' => ['device_id' => self::deviceId($edge['to'])],
                    'status' => $edge['status'],
                    'state' => $edge['state'] ?? null,
                    'type' => $edge['type'] ?? 'OSPF',
                    'network' => implode(', ', array_unique($edge['peers'] ?? $edge['addresses'] ?? [])),
                    'description' => (string) ($edge['description'] ?? ''),
                ];
            }
        }

        return $this;
    }

    /**
     * @return array<int, array> connections in SiteBuilder's format
     */
    public function all(): array
    {
        return $this->connections;
    }

    /**
     * Does an L3 map edge join two monitored devices at different sites?
     */
    private function crossesSites(array $edge): bool
    {
        $from = $this->site(self::deviceId($edge['from']));
        $to = $this->site(self::deviceId($edge['to']));

        return $from !== null && $to !== null && $from !== $to;
    }

    private function site(int $deviceId): ?string
    {
        return isset($this->devices[$deviceId]) ? SiteBuilder::siteId($this->devices[$deviceId]) : null;
    }

    /**
     * Device id of an L3 map node ("d:12"), 0 for subnets, external routers and AS clouds.
     */
    private static function deviceId(string $node): int
    {
        return str_starts_with($node, 'd:') ? (int) substr($node, 2) : 0;
    }

    /**
     * A connection that carries traffic: speed (the slower end), traffic and utilization measured at end a.
     *
     * @param  array{b_name?: string, aggregate_a?: ?array, aggregate_b?: ?array, protocols?: array, network?: string,
     *     status: string}  $extra
     */
    private function trafficLink(string $kind, int $aDevice, ?array $pa, int $bDevice, ?array $pb, array $extra): array
    {
        $speeds = array_filter([(int) ($pa['ifSpeed'] ?? 0), (int) ($pb['ifSpeed'] ?? 0)]);
        $speed = $speeds ? min($speeds) : 0;
        $in = (int) ($pa['ifInOctets_rate'] ?? 0) * 8;
        $out = (int) ($pa['ifOutOctets_rate'] ?? 0) * 8;
        $end = fn (int $deviceId, ?array $port, string $name, ?array $aggregate) => [
            'device_id' => $deviceId,
            'port' => $port ? InterfaceName::short(InterfaceName::label($port)) : (InterfaceName::short($name) ?: null),
            'port_url' => $port['url'] ?? null,
            'aggregate' => $aggregate ? InterfaceName::short(InterfaceName::label($aggregate)) : null,
        ];

        return [
            'kind' => $kind,
            'a' => $end($aDevice, $pa, '', $extra['aggregate_a'] ?? null),
            'b' => $end($bDevice, $pb, $extra['b_name'] ?? '', $extra['aggregate_b'] ?? null),
            'speed' => $speed,
            'status' => $extra['status'],
            'util' => $speed > 0 && $pa ? round(max($in, $out) / $speed * 100, 1) : null,
            'in_bps' => $in,
            'out_bps' => $out,
            'network' => $extra['network'] ?? null,
            'protocols' => $extra['protocols'] ?? [],
        ];
    }

    private static function pair(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }
}
