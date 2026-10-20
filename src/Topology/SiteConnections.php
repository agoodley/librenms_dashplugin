<?php

namespace Agoodley\LibrenmsTopology\Topology;

use Agoodley\LibrenmsTopology\Support\InterfaceName;

/**
 * Links between devices at different sites, for SiteBuilder: neighbor links (one per cable) and
 * point-to-point subnets. A subnet on a cable only adds its network to it, also when it is
 * configured on a sub-interface, a VLAN interface or a port-channel over the cable; other subnets
 * are links of their own, measured on the physical port (or port-channel) under them. Tunnels and
 * other logical interfaces have no capacity of their own (their ifSpeed is a nominal bandwidth),
 * so a link on them only shows its traffic.
 */
class SiteConnections
{
    /** Interface types without a capacity of their own (port-channels aside: see hasCapacity()). */
    private const LOGICAL_TYPES = ['tunnel', 'mplstunnel', 'propvirtual', 'l2vlan', 'l3ipvlan', 'l3ipxvlan', 'softwareloopback',
        'ifpwtype', 'virtualipaddress', 'bridge'];

    /** Deepest interface stack followed (a unit over a port-channel over its members). */
    private const STACK_DEPTH = 4;

    /** @var array<int, array> */
    private array $connections = [];

    /** @var array<int, string[]> connection index => networks of the subnets on it */
    private array $networks = [];

    /** @var array<string, int[]> device pair ("12-34") => indexes of the cables between them */
    private array $cables = [];

    /** @var array<int, array<int, int[]>> cable index => device_id => its port on the cable and that port's port-channel */
    private array $cablePorts = [];

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
            $this->cables[self::pair($aDevice, $bDevice)][] = $index;
            $this->cablePorts[$index] = [
                $aDevice => array_values(array_filter([$aPort, $lag[$aPort] ?? 0])),
                $bDevice => array_values(array_filter([$bPort, $lag[$bPort] ?? 0])),
            ];
        }

        return $this;
    }

    /**
     * Ports of the point-to-point subnets between sites, where addSubnets() starts.
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
     * Every port under the given ones in the interface stack, whose rows addSubnets() needs.
     *
     * @param  int[]  $portIds
     * @param  array<int, int[]>  $stack  see addSubnets()
     * @return int[]
     */
    public static function below(array $portIds, array $stack): array
    {
        $seen = [];
        $queue = array_values($portIds);
        while ($queue) {
            foreach ($stack[array_pop($queue)] ?? [] as $low) {
                if (! isset($seen[$low])) {
                    $seen[$low] = true;
                    $queue[] = $low;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Parent names of the sub-interfaces that the interface stack does not place, by the unit naming
     * of most vendors (xe-0/1/0.0 on xe-0/1/0, Te0/0/0.100 on Te0/0/0, ae0.0 on ae0).
     *
     * @param  array<int, array>  $ports  port_id => row
     * @param  array<int, int[]>  $stack  see addSubnets()
     * @return array<int, array{device_id: int, names: string[]}> port_id => device and candidate parent names
     */
    public static function unitParents(array $ports, array $stack): array
    {
        $parents = [];
        foreach ($ports as $portId => $port) {
            if (! empty($stack[$portId])) {
                continue;
            }
            $names = [];
            foreach (['ifName', 'ifDescr'] as $field) {
                if (preg_match('/^(.+)\.\d+$/', trim((string) ($port[$field] ?? '')), $match)) {
                    $names[] = $match[1];
                }
            }
            if ($names) {
                $parents[(int) $portId] = ['device_id' => (int) $port['device_id'], 'names' => array_values(array_unique($names))];
            }
        }

        return $parents;
    }

    /**
     * Point-to-point subnets between sites (the p2p links of the L3 map). Where their interfaces run
     * is found through the interface stack: a subnet on a cable's port, or on a sub-interface or
     * port-channel over it, adds its network to that cable; a subnet on interfaces without a known
     * port (VLAN interfaces, port-channels whose members are not listed) to the cables between its
     * two devices. Other subnets are links of their own, one per pair of ports under them, so both
     * address families and the sub-interfaces of the same ports (VRF-lite) make one link. A tunnel is
     * always a link of its own.
     *
     * @param  array  $l3  result of L3Builder::build()
     * @param  array<int, array>  $ports  port_id => row of the subnet ports and of every port below them (below())
     * @param  array<int, int[]>  $stack  port_id => ports right below it: a sub-interface's parent, a port-channel's
     *                                    members (ifStackTable, port-channel membership, unitParents())
     */
    public function addSubnets(array $l3, array $ports, array $stack): self
    {
        $subnets = [];
        foreach ($l3['edges'] as $edge) {
            if ($edge['kind'] !== 'p2p' || ! $this->crossesSites($edge)) {
                continue;
            }
            $aDevice = (int) $edge['a']['device_id'];
            $bDevice = (int) $edge['b']['device_id'];
            $a = self::resolve((int) $edge['a']['port_id'], $ports, $stack);
            $b = self::resolve((int) $edge['b']['port_id'], $ports, $stack);
            $network = $edge['network'] . ($edge['vrf'] !== '' ? ' (' . $edge['vrf'] . ')' : '');

            $cables = $this->cables[self::pair($aDevice, $bDevice)] ?? [];
            $on = array_filter($cables, fn (int $index) => array_intersect($this->cablePorts[$index][$aDevice] ?? [], $a['ports'])
                || array_intersect($this->cablePorts[$index][$bDevice] ?? [], $b['ports']));
            if (! $on && ! $a['physical'] && ! $b['physical'] && ! $a['tunnel'] && ! $b['tunnel']) {
                $on = $cables;
            }
            if ($on) {
                foreach ($on as $index) {
                    $this->networks[$index][] = $network;
                }

                continue;
            }

            $key = self::pair($aDevice, $bDevice) . '|' . ($a['carrier'] ?? 'p' . $a['port']) . '|' . ($b['carrier'] ?? 'p' . $b['port']);
            if (isset($subnets[$key])) {
                $this->networks[$subnets[$key]][] = $network;
                if ($edge['status'] !== 'up') {
                    $this->connections[$subnets[$key]]['status'] = 'down';
                }

                continue;
            }
            $subnets[$key] = count($this->connections);
            $this->networks[$subnets[$key]][] = $network;
            $pa = $ports[$a['carrier'] ?? $a['port']] ?? null;
            $pb = $ports[$b['carrier'] ?? $b['port']] ?? null;
            $this->connections[] = $this->trafficLink('p2p', $aDevice, $pa, $bDevice, $pb, [
                'a_name' => (string) ($edge['a']['name'] ?? ''),
                'b_name' => (string) ($edge['b']['name'] ?? ''),
                'status' => $edge['status'],
            ]);
        }

        return $this;
    }

    /**
     * @return array<int, array> connections in SiteBuilder's format
     */
    public function all(): array
    {
        $connections = $this->connections;
        foreach ($this->networks as $index => $networks) {
            $networks = array_values(array_unique($networks));
            sort($networks, SORT_NATURAL);
            $connections[$index]['network'] = implode(', ', $networks);
        }

        return $connections;
    }

    /**
     * Where a subnet's interface runs, down the interface stack: every port on the way, the physical
     * ports at the bottom, the port whose speed is the capacity (the interface itself, its parent or
     * its port-channel; none for VLAN interfaces and tunnels) and whether it is a tunnel.
     *
     * @return array{port: int, ports: int[], physical: int[], carrier: int|null, tunnel: bool}
     */
    private static function resolve(int $portId, array $ports, array $stack): array
    {
        $end = ['port' => $portId, 'ports' => [], 'physical' => [], 'carrier' => null, 'tunnel' => false];
        $walk = function (int $id, bool $placed, int $depth) use (&$walk, &$end, $ports, $stack): void {
            if (in_array($id, $end['ports'], true)) {
                return;
            }
            $end['ports'][] = $id;
            $port = $ports[$id] ?? null;
            $lows = $depth < self::STACK_DEPTH ? array_values(array_unique($stack[$id] ?? [])) : [];
            $end['tunnel'] = $end['tunnel'] || ($port !== null && self::isTunnel($port));
            // a sub-interface runs on its parent, a port-channel is the capacity of its members
            $capacity = $port !== null && self::hasCapacity($port) && (count($lows) !== 1 || self::isAggregate($port));
            if (! $placed && ($capacity || count($lows) > 1)) {
                $end['carrier'] = $capacity ? $id : null; // a VLAN interface over several ports has no single one
                $placed = true;
            }
            foreach ($lows as $low) {
                $walk($low, $placed, $depth + 1);
            }
            if (! $lows && $capacity && ! self::isAggregate($port)) {
                $end['physical'][] = $id;
            }
        };
        $walk($portId, false, 0);

        return $end;
    }

    /**
     * Does an interface have a capacity of its own? Port-channels do; tunnels, VLAN interfaces,
     * sub-interfaces and other logical interfaces do not, their ifSpeed is nominal.
     */
    private static function hasCapacity(array $port): bool
    {
        return self::isAggregate($port)
            || ! (in_array(strtolower((string) ($port['ifType'] ?? '')), self::LOGICAL_TYPES, true) || self::isTunnel($port));
    }

    private static function isAggregate(array $port): bool
    {
        $name = InterfaceName::label($port);

        return strtolower((string) ($port['ifType'] ?? '')) === 'ieee8023adlag'
            || (InterfaceName::isLagName($name) && ! preg_match('/\.\d+$/', $name));
    }

    private static function isTunnel(array $port): bool
    {
        return in_array(strtolower((string) ($port['ifType'] ?? '')), ['tunnel', 'mplstunnel'], true)
            || (bool) preg_match('/^(tunnel|tu\d|tun\d|gr-|ip-|st0|vti)/i', InterfaceName::label($port));
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
     * A connection that carries traffic: the capacity of the slower end that has one of its own (0
     * when neither has: traffic only), and traffic as seen from end a with the utilization, measured
     * at the first end with a capacity.
     *
     * @param  array{a_name?: string, b_name?: string, aggregate_a?: ?array, aggregate_b?: ?array, protocols?: array,
     *     status: string}  $extra
     */
    private function trafficLink(string $kind, int $aDevice, ?array $pa, int $bDevice, ?array $pb, array $extra): array
    {
        $capacity = array_filter([$pa, $pb], fn (?array $port) => $port !== null && self::hasCapacity($port));
        $speeds = array_filter(array_map(fn (array $port) => (int) ($port['ifSpeed'] ?? 0), $capacity));
        $speed = $speeds ? min($speeds) : 0;
        $atB = $capacity ? ! isset($capacity[0]) : $pa === null;
        $measured = $atB ? $pb : $pa;
        $in = (int) ($measured['ifInOctets_rate'] ?? 0) * 8;
        $out = (int) ($measured['ifOutOctets_rate'] ?? 0) * 8;
        if ($atB) {
            [$in, $out] = [$out, $in];
        }
        $end = fn (int $deviceId, ?array $port, string $name, ?array $aggregate) => [
            'device_id' => $deviceId,
            'port' => $port ? InterfaceName::short(InterfaceName::label($port)) : (InterfaceName::short($name) ?: null),
            'port_url' => $port['url'] ?? null,
            'aggregate' => $aggregate ? InterfaceName::short(InterfaceName::label($aggregate)) : null,
        ];

        return [
            'kind' => $kind,
            'a' => $end($aDevice, $pa, $extra['a_name'] ?? '', $extra['aggregate_a'] ?? null),
            'b' => $end($bDevice, $pb, $extra['b_name'] ?? '', $extra['aggregate_b'] ?? null),
            'speed' => $speed,
            'status' => $extra['status'],
            'util' => $speed > 0 && $capacity ? round(max($in, $out) / $speed * 100, 1) : null,
            'in_bps' => $in,
            'out_bps' => $out,
            'network' => null,
            'protocols' => $extra['protocols'] ?? [],
        ];
    }

    private static function pair(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }
}
