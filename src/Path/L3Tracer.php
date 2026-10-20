<?php

namespace Agoodley\LibrenmsTopology\Path;

use Agoodley\LibrenmsTopology\Support\Cidr;
use Agoodley\LibrenmsTopology\Support\DeviceRole;

/**
 * The routed path toward one destination address, router by router: the longest matching prefix of
 * each router's route table (LibreNMS' `route` table, the routes of the router's VRF), whose next hop
 * leads to the router owning that address, until a router has the destination's subnet connected.
 * Equal cost next hops are reported, the path follows the first (lowest metric, then lowest address).
 *
 * The `route` discovery module is off by default. A router without route data is crossed by an
 * estimate: the first step of the shortest way, over subnets it shares with other routers and its
 * OSPF / BGP neighbors, toward a router with the destination's subnet connected.
 */
class L3Tracer
{
    /** Routers followed at most. */
    public const MAX_HOPS = 30;

    /** Routes this much older than the newest route of their router are kept history (seconds). */
    public const STALE_AFTER = 3600;

    private const ROUTING_ROLES = [DeviceRole::ROUTER, DeviceRole::L3SWITCH, DeviceRole::FIREWALL];

    /** inetCidrRouteType */
    private const TYPE_REJECT = 2;

    private const TYPE_LOCAL = 3;

    private const TYPE_BLACKHOLE = 5;

    private const PROTOCOLS = [2 => 'connected', 3 => 'static', 8 => 'RIP', 9 => 'IS-IS', 11 => 'IGRP', 13 => 'OSPF', 14 => 'BGP', 16 => 'EIGRP'];

    /** @var array<string, array<int, array>> canonical address => interfaces with it */
    private array $byAddress = [];

    /** @var array<int, array<int, array>> device_id => interfaces */
    private array $byDevice = [];

    /** @var array<int, array<int, array>>|null device_id => [neighbor device_id => how] */
    private ?array $adjacent = null;

    /**
     * @param  array<int, array>  $interfaces  device_id, port_id, address, prefixlen, vrf ('' for the global table) of the
     *                                         devices the user may see
     * @param  array<int, array<int, array>>  $routes  device_id => the routes that cover the destination: context, dest,
     *                                                 pfxlen, next_hop, port_id, type, proto, metric, updated (unix time)
     * @param  array<int, array{count: int, newest: int, contexts?: array<string, int>}>  $routeData  devices with routes in
     *                                                                                       LibreNMS (any destination): how many, the newest one's time, routes per VRF
     * @param  array<int, array>  $adjacencies  OSPF / BGP neighbors: device_id, port_id (local, may be null), local_ip, neighbor_ip,
     *                                          kind (ospf / bgp), vrf
     * @param  array<int, string>  $roles  device_id => role
     * @param  array<int, string>  $names  device_id => display name
     */
    public function __construct(
        array $interfaces,
        private readonly array $routes,
        private readonly array $routeData,
        private readonly array $adjacencies,
        private readonly array $roles,
        private readonly array $names = [],
    ) {
        foreach ($interfaces as $interface) {
            $address = Cidr::canonical((string) $interface['address']);
            if ($address === null || Cidr::isIgnorable($address)) {
                continue;
            }
            $interface = ['device_id' => (int) $interface['device_id'], 'port_id' => (int) $interface['port_id'], 'address' => $address,
                'prefixlen' => (int) $interface['prefixlen'], 'vrf' => (string) ($interface['vrf'] ?? '')];
            $this->byAddress[$address][] = $interface;
            $this->byDevice[$interface['device_id']][] = $interface;
        }
    }

    /**
     * Routers from $start to the one with the destination connected.
     *
     * @param  int|null  $ingressPort  the interface the traffic arrives on at the first router
     * @return array{hops: array<int, array>, end: string, egress: ?array, next_hop: ?string, loop: ?int}
     *     hops: device_id, vrf, in_port, in_address, out_port, out_address, next_hop, route (prefix, next_hops, protocol,
     *     metric, updated), estimated (null or the reason); end: local (the destination is the last router's own address),
     *     connected (on the last router's subnet: egress), no_route, blackhole, outside (next_hop belongs to no device the
     *     user may see), loop (back to the hop index loop), limit, stuck (no route data and no way toward the destination)
     */
    public function trace(int $start, ?int $ingressPort, string $vrf, string $destination): array
    {
        $destination = Cidr::canonical($destination) ?? $destination;
        $hops = [];
        $visited = [];
        $device = $start;
        $in = $ingressPort ? $this->interfaceOn($start, $ingressPort) : null;
        $result = function (string $end, array $extra = []) use (&$hops): array {
            return $extra + ['hops' => $hops, 'end' => $end, 'egress' => null, 'next_hop' => null, 'loop' => null];
        };

        while (true) {
            if (isset($visited[$device . '|' . $vrf])) {
                return $result('loop', ['loop' => $visited[$device . '|' . $vrf]]);
            }
            if (count($hops) >= self::MAX_HOPS) {
                return $result('limit');
            }
            $visited[$device . '|' . $vrf] = count($hops);
            $hop = ['device_id' => $device, 'vrf' => $vrf, 'in_port' => $in['port_id'] ?? null, 'in_address' => $in['address'] ?? null,
                'out_port' => null, 'out_address' => null, 'next_hop' => null, 'route' => null, 'estimated' => null];

            foreach ($this->byDevice[$device] ?? [] as $interface) {
                if ($interface['address'] === $destination) {
                    $hops[] = $hop;

                    return $result('local');
                }
            }
            $connected = $this->connected($device, $vrf, $destination);
            if ($connected !== null) {
                $hops[] = ['out_port' => $connected['port_id'], 'out_address' => $connected['address']] + $hop;

                return $result('connected', ['egress' => $connected]);
            }

            if ($this->hasRoutes($device, $vrf)) {
                $route = $this->lookup($device, $vrf, $destination);
                if ($route === null) {
                    $hops[] = $hop;

                    return $result('no_route');
                }
                $hop['route'] = $route;
                if (in_array($route['type'], [self::TYPE_REJECT, self::TYPE_BLACKHOLE], true)) {
                    $hops[] = $hop;

                    return $result('blackhole');
                }
                if ($route['next_hop'] === null) {
                    // an interface route: the destination is on the subnet of that interface
                    $egress = $route['port_id'] ? $this->interfaceOn($device, $route['port_id']) : null;
                    $hops[] = ['out_port' => $route['port_id'] ?: null, 'out_address' => $egress['address'] ?? null] + $hop;

                    return $result('connected', ['egress' => $egress ?? ['device_id' => $device, 'port_id' => $route['port_id'], 'address' => null, 'prefixlen' => 0, 'vrf' => $vrf]]);
                }
                $hop['next_hop'] = $route['next_hop'];
                $owner = $this->owner($route['next_hop'], $vrf);
                $out = $route['port_id'] ? $this->interfaceOn($device, $route['port_id']) : $this->facing($device, $route['next_hop']);
                $hop['out_port'] = $route['port_id'] ?: ($out['port_id'] ?? null);
                $hop['out_address'] = $out['address'] ?? null;
                $hops[] = $hop;
                if ($owner === null) {
                    return $result('outside', ['next_hop' => $route['next_hop']]);
                }
                // the far router looks the destination up in the VRF of the interface the traffic arrives on, except for a VRF
                // route whose next hop is in the global table (an MPLS VPN toward the far PE): it stays in the VRF
                $vrf = $vrf !== '' && $owner['vrf'] === '' && $this->hasVrf($owner['device_id'], $vrf) ? $vrf : $owner['vrf'];
                $device = $owner['device_id'];
                $in = $owner;

                continue;
            }

            // no route table: one step toward a router with the destination connected
            $step = $this->estimate($device, $vrf, $destination);
            if ($step === null) {
                $hops[] = $hop;

                return $result('stuck');
            }
            $hops[] = ['out_port' => $step['out']['port_id'] ?? null, 'out_address' => $step['out']['address'] ?? null,
                'next_hop' => $step['in']['address'] ?? null, 'estimated' => $step['reason']] + $hop;
            $vrf = $step['in']['vrf'] ?? $vrf;
            $device = $step['device_id'];
            $in = $step['in'];
        }
    }

    /**
     * The interface of the device whose subnet holds the address (longest prefix), in the VRF.
     */
    public function connected(int $deviceId, string $vrf, string $address): ?array
    {
        $best = null;
        foreach ($this->byDevice[$deviceId] ?? [] as $interface) {
            if ($interface['vrf'] !== $vrf || ! self::isSubnet($interface)) {
                continue;
            }
            $network = Cidr::network($interface['address'], $interface['prefixlen']);
            if ($network !== null && Cidr::contains($network, $address) && ($best === null || $interface['prefixlen'] > $best['prefixlen'])) {
                $best = $interface;
            }
        }

        return $best;
    }

    /**
     * Routing devices with the address's subnet connected, in that VRF if any has it there, else in any VRF.
     *
     * @return array<int, array> the connected interfaces
     */
    public function gateways(string $address, ?string $vrf = null): array
    {
        $address = Cidr::canonical($address) ?? $address;
        $found = [];
        foreach ($this->byDevice as $deviceId => $interfaces) {
            if (! in_array($this->roles[$deviceId] ?? DeviceRole::UNKNOWN, self::ROUTING_ROLES, true)) {
                continue;
            }
            foreach (array_unique(array_column($interfaces, 'vrf')) as $interfaceVrf) {
                $connected = $this->connected($deviceId, (string) $interfaceVrf, $address);
                if ($connected !== null) {
                    $found[] = $connected;
                }
            }
        }
        if ($vrf !== null && array_filter($found, fn ($interface) => $interface['vrf'] === $vrf)) {
            $found = array_values(array_filter($found, fn ($interface) => $interface['vrf'] === $vrf));
        }
        usort($found, fn ($a, $b) => [$b['prefixlen'], $a['device_id']] <=> [$a['prefixlen'], $b['device_id']]);

        return $found;
    }

    /**
     * Interfaces with the address (monitored devices the user may see), the ones in $vrf first.
     *
     * @return array<int, array>
     */
    public function owners(string $address, string $vrf = ''): array
    {
        $owners = $this->byAddress[Cidr::canonical($address) ?? $address] ?? [];
        usort($owners, fn ($a, $b) => [$a['vrf'] !== $vrf, $a['device_id']] <=> [$b['vrf'] !== $vrf, $b['device_id']]);

        return $owners;
    }

    /**
     * Interfaces of a device on one port.
     */
    public function interfaceOn(int $deviceId, int $portId): ?array
    {
        foreach ($this->byDevice[$deviceId] ?? [] as $interface) {
            if ($interface['port_id'] === $portId) {
                return $interface;
            }
        }

        return null;
    }

    /**
     * Name of a routing protocol (inetCidrRouteProto).
     */
    public static function protocolName(int $proto): string
    {
        return self::PROTOCOLS[$proto] ?? ($proto > 0 ? 'protocol ' . $proto : 'unknown');
    }

    /**
     * Does LibreNMS have routes of the device in that VRF (a VRF whose routes it did not read counts as none)?
     */
    private function hasRoutes(int $deviceId, string $vrf): bool
    {
        $data = $this->routeData[$deviceId] ?? null;

        return $data !== null && (! isset($data['contexts']) || isset($data['contexts'][$vrf]));
    }

    /**
     * The longest prefix route of the device toward the address, among its current routes in the VRF.
     *
     * @return array{prefix: string, next_hop: ?string, next_hops: string[], port_id: int, type: int, protocol: string, metric: int, updated: int}|null
     */
    private function lookup(int $deviceId, string $vrf, string $address): ?array
    {
        $newest = (int) ($this->routeData[$deviceId]['newest'] ?? 0);
        $best = null;
        $candidates = [];
        foreach ($this->routes[$deviceId] ?? [] as $route) {
            if ((string) $route['context'] !== $vrf || (int) $route['updated'] < $newest - self::STALE_AFTER) {
                continue;
            }
            $prefix = Cidr::network((string) $route['dest'], (int) $route['pfxlen']);
            if ($prefix === null || ! Cidr::contains($prefix, $address)) {
                continue;
            }
            $length = (int) $route['pfxlen'];
            if ($best === null || $length > $best) {
                $best = $length;
                $candidates = [];
            }
            if ($length === $best) {
                $candidates[] = $route + ['prefix' => $prefix];
            }
        }
        if (! $candidates) {
            return null;
        }

        // equal cost next hops: lowest metric first, then the lowest address
        $hops = [];
        foreach ($candidates as $route) {
            $nextHop = self::nextHop($route);
            $hops[$nextHop ?? ''] ??= $route + ['hop' => $nextHop];
        }
        uasort($hops, fn ($a, $b) => [(int) $a['metric'], self::sortable($a['hop'])] <=> [(int) $b['metric'], self::sortable($b['hop'])]);
        $chosen = reset($hops);
        $equal = array_filter($hops, fn ($route) => (int) $route['metric'] === (int) $chosen['metric']);

        return [
            'prefix' => $chosen['prefix'],
            'next_hop' => $chosen['hop'],
            'next_hops' => array_values(array_filter(array_map(fn ($route) => $route['hop'], $equal))),
            'port_id' => (int) $chosen['port_id'],
            'type' => (int) $chosen['type'],
            'protocol' => self::protocolName((int) $chosen['proto']),
            'metric' => (int) $chosen['metric'],
            'updated' => (int) $chosen['updated'],
        ];
    }

    /**
     * Next hop of a route, null for an interface (directly connected or local) route.
     */
    private static function nextHop(array $route): ?string
    {
        $hop = Cidr::canonical((string) $route['next_hop']);
        if ($hop === null || $hop === '0.0.0.0' || $hop === '::' || (int) $route['type'] === self::TYPE_LOCAL) {
            return null;
        }

        return $hop;
    }

    /**
     * The interface with the next hop address, on a device the user may see; the VRF's first.
     */
    private function owner(string $address, string $vrf): ?array
    {
        return $this->owners($address, $vrf)[0] ?? null;
    }

    /**
     * The device's interface on the subnet of an address (the egress toward a next hop).
     */
    private function facing(int $deviceId, string $address): ?array
    {
        $best = null;
        foreach ($this->byDevice[$deviceId] ?? [] as $interface) {
            $network = self::isSubnet($interface) ? Cidr::network($interface['address'], $interface['prefixlen']) : null;
            if ($network !== null && Cidr::contains($network, $address) && ($best === null || $interface['prefixlen'] > $best['prefixlen'])) {
                $best = $interface;
            }
        }

        return $best;
    }

    private function hasVrf(int $deviceId, string $vrf): bool
    {
        foreach ($this->byDevice[$deviceId] ?? [] as $interface) {
            if ($interface['vrf'] === $vrf) {
                return true;
            }
        }

        return false;
    }

    /**
     * Without route data: the first step of the shortest way over shared subnets and OSPF / BGP neighbors toward a
     * routing device with the destination's subnet connected.
     *
     * @return array{device_id: int, out: ?array, in: ?array, reason: string}|null
     */
    private function estimate(int $deviceId, string $vrf, string $destination): ?array
    {
        $targets = [];
        foreach ($this->gateways($destination, $vrf) as $interface) {
            $targets[$interface['device_id']] = $interface;
        }
        unset($targets[$deviceId]);
        if (! $targets) {
            return null;
        }

        $adjacent = $this->adjacency();
        $previous = [$deviceId => null];
        $queue = [$deviceId];
        $found = null;
        for ($i = 0; $i < count($queue) && $found === null; $i++) {
            $node = $queue[$i];
            $neighbors = $adjacent[$node] ?? [];
            ksort($neighbors);
            foreach ($neighbors as $next => $how) {
                if (array_key_exists($next, $previous)) {
                    continue;
                }
                $previous[$next] = [$node, $how];
                if (isset($targets[$next])) {
                    $found = $next;
                    break;
                }
                $queue[] = $next;
            }
        }
        if ($found === null) {
            return null;
        }

        $node = $found;
        while ($previous[$node][0] !== $deviceId) {
            $node = $previous[$node][0];
        }
        $how = $previous[$node][1];
        $next = match ($how['kind']) {
            'ospf' => 'its OSPF neighbor ' . $this->name($node),
            'bgp' => 'its BGP neighbor ' . $this->name($node),
            default => $this->name($node) . ', on a subnet they share',
        };

        return [
            'device_id' => $node,
            'out' => $how['out'],
            'in' => $how['in'],
            'reason' => 'LibreNMS has no routes of ' . $this->name($deviceId) . ' (the route discovery module is off for it): the next hop is taken to be '
                . $next . ($node === $found ? ', which has the destination\'s subnet connected' : ', on the shortest way toward ' . $this->name($found)
                    . ', which has the destination\'s subnet connected') . '.',
        ];
    }

    /**
     * Routing devices joined by a shared subnet (connected interfaces) or an OSPF / BGP neighborship.
     *
     * @return array<int, array<int, array{kind: string, out: ?array, in: ?array}>>
     */
    private function adjacency(): array
    {
        if ($this->adjacent !== null) {
            return $this->adjacent;
        }

        // OSPF / BGP neighbors first: they say more than a shared subnet
        $adjacent = [];
        foreach ($this->adjacencies as $neighbor) {
            $local = (int) $neighbor['device_id'];
            $remote = $this->owners((string) $neighbor['neighbor_ip'], (string) ($neighbor['vrf'] ?? ''))[0] ?? null;
            if ($remote === null || $remote['device_id'] === $local) {
                continue;
            }
            $out = ! empty($neighbor['port_id']) ? $this->interfaceOn($local, (int) $neighbor['port_id'])
                : (! empty($neighbor['local_ip']) ? ($this->owners((string) $neighbor['local_ip'])[0] ?? null) : $this->facing($local, $remote['address']));
            $adjacent[$local][$remote['device_id']] ??= ['kind' => (string) $neighbor['kind'], 'out' => $out, 'in' => $remote];
        }
        $bySubnet = [];
        foreach ($this->byDevice as $deviceId => $interfaces) {
            if (! in_array($this->roles[$deviceId] ?? DeviceRole::UNKNOWN, self::ROUTING_ROLES, true)) {
                continue;
            }
            foreach ($interfaces as $interface) {
                if (self::isSubnet($interface)) {
                    $bySubnet[$interface['vrf'] . '|' . Cidr::network($interface['address'], $interface['prefixlen'])][] = $interface;
                }
            }
        }
        foreach ($bySubnet as $members) {
            if (count($members) > 64) {
                continue; // a large LAN of many routers: not the transit between them
            }
            foreach ($members as $a) {
                foreach ($members as $b) {
                    if ($a['device_id'] !== $b['device_id']) {
                        $adjacent[$a['device_id']][$b['device_id']] ??= ['kind' => 'subnet', 'out' => $a, 'in' => $b];
                    }
                }
            }
        }

        return $this->adjacent = $adjacent;
    }

    private function name(int $deviceId): string
    {
        return $this->names[$deviceId] ?? 'device #' . $deviceId;
    }

    /**
     * An interface address with a subnet around it (not a /32 or /128 host address).
     */
    private static function isSubnet(array $interface): bool
    {
        return $interface['prefixlen'] > 0 && $interface['prefixlen'] < (Cidr::family($interface['address']) === 4 ? 32 : 128);
    }

    /**
     * Sort key of an address (numeric order), interface routes first.
     */
    private static function sortable(?string $address): string
    {
        return $address === null ? '' : (string) @inet_pton($address);
    }
}
