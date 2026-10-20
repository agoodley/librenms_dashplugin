<?php

namespace Agoodley\LibrenmsTopology\Path;

use Agoodley\LibrenmsTopology\Stp\StpAnalyzer;
use Agoodley\LibrenmsTopology\Support\Cidr;
use Agoodley\LibrenmsTopology\Support\DeviceRole;

/**
 * The routed path toward one destination address, router by router: the longest matching prefix of each router's
 * current routes (LibreNMS' `route` table, the routes of the router's VRF) and of its connected subnets (interfaces that
 * are up; the route table on a tie), whose next hop leads to the router owning that address, until a router has the
 * destination's subnet connected. Equal cost next hops are reported, the path follows the first (lowest metric, then
 * lowest address).
 *
 * A next hop is found on the router's own subnets; an IPv6 link-local one through the route's interface (the neighbor on
 * that link); a recursive one (iBGP, an MPLS VPN toward the far PE's loopback) through the router's own route toward it,
 * and the routers on the way are then followed toward that next hop. When several devices have the next hop's address,
 * the one on the egress link is taken.
 *
 * The `route` discovery module is off by default, a router can have more routes than LibreNMS reads of it, and the table
 * may not have been read: such a router is crossed by an estimate, the first step of the shortest way over subnets it
 * shares with other routers and its OSPF / BGP neighbors (never back to a router already on the path) toward a router
 * with the destination's subnet connected.
 */
class L3Tracer
{
    /** Routers followed at most. */
    public const MAX_HOPS = 30;

    /** Routes this much older than the newest route of their router are kept history (seconds). */
    public const STALE_AFTER = 3600;

    /** A route table whose newest route is this much older than the start of its router's last discovery was not read again then (seconds). */
    public const DISCOVERY_MARGIN = 900;

    /** Routes of a router followed at most to resolve one recursive next hop. */
    private const MAX_RECURSION = 4;

    /** Why a router's routes are used or not (routeState()). */
    public const ROUTES_OK = 'ok';

    public const ROUTES_NONE = 'none';

    public const ROUTES_VRF = 'vrf';

    public const ROUTES_STALE = 'stale';

    public const ROUTES_SKIPPED = 'skipped';

    private const ROUTING_ROLES = [DeviceRole::ROUTER, DeviceRole::L3SWITCH, DeviceRole::FIREWALL];

    /** inetCidrRouteType */
    private const TYPE_REJECT = 2;

    private const TYPE_LOCAL = 3;

    private const TYPE_BLACKHOLE = 5;

    private const PROTOCOLS = [2 => 'connected', 3 => 'static', 8 => 'RIP', 9 => 'IS-IS', 11 => 'IGRP', 13 => 'OSPF', 14 => 'BGP', 16 => 'EIGRP'];

    /** @var array<string, array<int, array>> canonical address => interfaces with it (not link-local) */
    private array $byAddress = [];

    /** @var array<int, array<int, array>> device_id => interfaces (not link-local) */
    private array $byDevice = [];

    /** @var array<string, array<int, array>> link-local address => interfaces with it */
    private array $linkLocal = [];

    /** @var array<string, true> addresses whose owners were looked up */
    private array $looked = [];

    /** @var array<string, array<int, array>> address => device_id => routes covering it */
    private array $routeCache = [];

    /** @var array<int, array<int, array>>|null device_id => [neighbor device_id => how] */
    private ?array $adjacent = null;

    /** Address family of the trace's destination: the interfaces of a port are taken in it first. */
    private int $family = 4;

    /**
     * @param  array<int, array>  $interfaces  device_id, port_id, address, prefixlen, vrf ('' for the global table), up (default
     *                                         true) of the routing devices the user may see; link-local IPv6 addresses included
     * @param  array<int, array<int, array>>|\Closure(string): ?array  $routes  device_id => routes (context, dest, pfxlen, next_hop,
     *                                                                         port_id, type, proto, metric, updated as unix time), or a
     *                                                                         function giving the routes that cover an address (null: not read)
     * @param  array<int, array>|\Closure(): array  $routeData  devices with routes in LibreNMS (any destination): count, newest (time
     *                                                    of the newest route), contexts (routes per VRF), discovered (start of the last discovery)
     * @param  array<int, array>  $adjacencies  OSPF / BGP neighbors: device_id, port_id (local, may be null), local_ip, neighbor_ip,
     *                                          kind (ospf / bgp), vrf
     * @param  array<int, string>  $roles  device_id => role
     * @param  array<int, string>  $names  device_id => display name
     * @param  (\Closure(int, int): array<int, ?int>)|null  $linked  devices joined by LLDP/CDP on a port of a device: device_id => their port
     * @param  (\Closure(string): array)|null  $lookupOwners  interfaces of other devices with an address
     * @param  bool  $skipped  the route table was not read (too large): every router is estimated
     */
    public function __construct(
        array $interfaces,
        private readonly array|\Closure $routes,
        private array|\Closure $routeData,
        private readonly array $adjacencies,
        private readonly array $roles,
        private readonly array $names = [],
        private readonly ?\Closure $linked = null,
        private readonly ?\Closure $lookupOwners = null,
        private readonly bool $skipped = false,
    ) {
        foreach ($interfaces as $interface) {
            $this->addInterface($interface);
        }
    }

    /**
     * Routers from $start to the one with the destination connected.
     *
     * @param  int|null  $ingressPort  the interface the traffic arrives on at the first router
     * @return array{hops: array<int, array>, end: string, egress: ?array, next_hop: ?string, loop: ?int, estimated_loop: bool, stuck: ?string}
     *     hops: device_id, vrf, in_port, in_address, out_port, out_address, next_hop, via (the neighbor a recursive next hop is
     *     reached through), toward (the recursive next hop this router routes toward), route (prefix, next_hops, protocol, metric,
     *     updated), estimated (null or the reason) with estimated_why (a ROUTES_ state), recursive (a recursive next hop the
     *     router's routes do not lead to: the routers between are not known), ambiguous (other devices with the next hop's
     *     address), routes_read (time of the router's newest route); end: local (the destination is the last router's own
     *     address), connected (on the last router's subnet: egress), no_route, blackhole, outside (next_hop belongs to no device the
     *     user may see), loop (back to the hop index loop; estimated_loop when an estimated hop is in it), limit, stuck (no
     *     current routes and no way toward the destination: stuck is the ROUTES_ state)
     */
    public function trace(int $start, ?int $ingressPort, string $vrf, string $destination): array
    {
        $destination = Cidr::canonical($destination) ?? $destination;
        $this->family = Cidr::family($destination);
        $hops = [];
        $visited = [];
        $device = $start;
        $in = $ingressPort ? $this->interfaceOn($start, $ingressPort) : null;
        // the destination, and the recursive next hops the routers on the way route toward
        $targets = [['address' => $destination, 'outer' => $vrf]];
        $result = function (string $end, array $extra = []) use (&$hops): array {
            return $extra + ['hops' => $hops, 'end' => $end, 'egress' => null, 'next_hop' => null, 'loop' => null, 'estimated_loop' => false, 'stuck' => null];
        };

        while (true) {
            // a recursive next hop reached: on toward what it was the next hop for, in the VRF of that route
            while (count($targets) > 1 && $this->owns($device, $targets[count($targets) - 1]['address'])) {
                $done = array_pop($targets);
                $vrf = $done['outer'] === '' || $this->hasVrf($device, $done['outer']) ? $done['outer'] : $vrf;
            }
            $target = $targets[count($targets) - 1]['address'];
            $toward = count($targets) > 1 ? $target : null;
            $key = $device . '|' . $vrf . '|' . $target;
            if (isset($visited[$key])) {
                $loop = $visited[$key];

                return $result('loop', ['loop' => $loop, 'estimated_loop' => (bool) array_filter(array_slice($hops, $loop), fn ($hop) => $hop['estimated'] !== null)]);
            }
            if (count($hops) >= self::MAX_HOPS) {
                return $result('limit');
            }
            $visited[$key] = count($hops);
            $hop = ['device_id' => $device, 'vrf' => $vrf, 'in_port' => $in['port_id'] ?? null, 'in_address' => $in['address'] ?? null,
                'out_port' => null, 'out_address' => null, 'next_hop' => null, 'via' => null, 'toward' => $toward, 'route' => null,
                'estimated' => null, 'estimated_why' => null, 'recursive' => false, 'ambiguous' => [], 'routes_read' => $this->newest($device)];

            if ($toward === null && $this->owns($device, $destination)) {
                $hops[] = $hop;

                return $result('local');
            }

            $state = $this->routeState($device, $vrf);
            $connected = $this->connected($device, $vrf, $target);
            $route = $state === self::ROUTES_OK ? $this->lookup($device, $vrf, $target) : null;

            // the longest prefix of the route table and of the connected subnets; the route table on a tie
            if ($connected !== null && ($route === null || $connected['prefixlen'] > $route['length'])) {
                $hop = ['out_port' => $connected['port_id'], 'out_address' => $connected['address']] + $hop;
                if ($toward === null) {
                    $hops[] = $hop;

                    return $result('connected', ['egress' => $connected]);
                }
                // the recursive next hop is on this subnet
                [$owner, $hop['ambiguous']] = $this->nextHopOwner($target, $vrf, $device, $connected['port_id'], $connected);
                $hop['next_hop'] = $target;
                $hops[] = $hop;
                if ($owner === null) {
                    return $result('outside', ['next_hop' => $target]);
                }
                [$device, $vrf, $in] = $this->arrive($owner, $vrf, null);

                continue;
            }

            if ($state === self::ROUTES_OK) {
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
                    // an interface route: the target is on the subnet of that interface
                    $egress = $route['port_id'] ? $this->interfaceOn($device, $route['port_id']) : null;
                    $hop = ['out_port' => $route['port_id'] ?: null, 'out_address' => $egress['address'] ?? null] + $hop;
                    if ($toward === null) {
                        $hops[] = $hop;

                        return $result('connected', ['egress' => $egress ?? ['device_id' => $device, 'port_id' => $route['port_id'], 'address' => null, 'prefixlen' => 0, 'vrf' => $vrf, 'up' => true]]);
                    }
                    [$owner, $hop['ambiguous']] = $this->nextHopOwner($target, $vrf, $device, $route['port_id'] ?: null, $egress);
                    $hop['next_hop'] = $target;
                    $hops[] = $hop;
                    if ($owner === null) {
                        return $result('outside', ['next_hop' => $target]);
                    }
                    [$device, $vrf, $in] = $this->arrive($owner, $vrf, null);

                    continue;
                }

                // the neighbor the next hop is reached through: itself on a subnet of the router, else by the router's routes toward it
                $step = $this->resolve($device, $vrf, $route);
                $hop['next_hop'] = $route['next_hop'];
                $hop['out_port'] = $step['egress_port'];
                $hop['out_address'] = $step['egress']['address'] ?? null;
                if ($step['via'] === null) {
                    $hop['recursive'] = true;
                    [$owner, $hop['ambiguous']] = $this->nextHopOwner($route['next_hop'], $step['vrf'], $device, null, null);
                } else {
                    $hop['via'] = $step['via'] !== $route['next_hop'] ? $step['via'] : null;
                    [$owner, $hop['ambiguous']] = $this->nextHopOwner($step['via'], $step['vrf'], $device, $step['egress_port'], $step['egress']);
                }
                $hops[] = $hop;
                if ($owner === null) {
                    return $result('outside', ['next_hop' => $step['via'] ?? $route['next_hop']]);
                }
                if ($hop['via'] !== null) {
                    // the routers on the way forward toward the recursive next hop (a recursive route, an MPLS LSP), in its table
                    $targets[] = ['address' => $route['next_hop'], 'outer' => $vrf];
                    [$device, $vrf, $in] = $this->arrive($owner, $vrf, $step['vrf']);
                } else {
                    [$device, $vrf, $in] = $this->arrive($owner, $vrf, null);
                }

                continue;
            }

            // no current routes of this router: one step toward the target, never back to a router already on the path
            $step = $this->estimate($device, $vrf, $target, array_merge(array_column($hops, 'device_id'), [$device]));
            if ($step === null) {
                $hops[] = $hop;

                return $result('stuck', ['stuck' => $state]);
            }
            $hops[] = ['out_port' => $step['out']['port_id'] ?? null, 'out_address' => $step['out']['address'] ?? null,
                'next_hop' => $step['in']['address'] ?? null, 'estimated' => $this->estimateReason($device, $vrf, $state, $step), 'estimated_why' => $state] + $hop;
            $vrf = $step['in']['vrf'] ?? $vrf;
            $device = $step['device_id'];
            $in = $step['in'];
        }
    }

    /**
     * The up interface of the device whose subnet holds the address (longest prefix), in the VRF.
     */
    public function connected(int $deviceId, string $vrf, string $address): ?array
    {
        $best = null;
        foreach ($this->byDevice[$deviceId] ?? [] as $interface) {
            if ($interface['vrf'] !== $vrf || ! $interface['up'] || ! self::isSubnet($interface)) {
                continue;
            }
            if (self::onSubnet($interface, $address) && ($best === null || $interface['prefixlen'] > $best['prefixlen'])) {
                $best = $interface;
            }
        }

        return $best;
    }

    /**
     * Routing devices with the address's subnet connected (an up interface), in that VRF if any has it there, else in any VRF.
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
     * Interfaces with the address (devices the user may see), the ones in $vrf first, then the ones that are up.
     *
     * @return array<int, array>
     */
    public function owners(string $address, string $vrf = ''): array
    {
        $address = Cidr::canonical($address) ?? $address;
        if (self::isLinkLocal($address)) {
            return $this->linkLocal[$address] ?? [];
        }
        if (! isset($this->byAddress[$address]) && $this->lookupOwners !== null && ! isset($this->looked[$address])) {
            $this->looked[$address] = true;
            foreach (($this->lookupOwners)($address) as $interface) {
                $this->addInterface($interface);
            }
        }
        $owners = $this->byAddress[$address] ?? [];
        usort($owners, fn ($a, $b) => [$a['vrf'] !== $vrf, ! $a['up'], $a['device_id']] <=> [$b['vrf'] !== $vrf, ! $b['up'], $b['device_id']]);

        return $owners;
    }

    /**
     * The interface of a device on one port: an address of the trace's family (a global one before a link-local one),
     * else any.
     */
    public function interfaceOn(int $deviceId, int $portId): ?array
    {
        $found = [];
        foreach ($this->byDevice[$deviceId] ?? [] as $interface) {
            if ($interface['port_id'] === $portId) {
                $found[] = $interface;
            }
        }
        foreach ($this->linkLocal as $interfaces) {
            foreach ($interfaces as $interface) {
                if ($interface['device_id'] === $deviceId && $interface['port_id'] === $portId) {
                    $found[] = $interface;
                }
            }
        }
        foreach ($found as $interface) {
            if (Cidr::family($interface['address']) === $this->family) {
                return $interface;
            }
        }

        return $found[0] ?? null;
    }

    /**
     * Can the device's routes in the VRF be used (ROUTES_OK), and if not, why: none in LibreNMS, none in this VRF, not
     * read again at the last discovery (stale), or the route table was not read.
     */
    public function routeState(int $deviceId, string $vrf): string
    {
        if ($this->skipped) {
            return self::ROUTES_SKIPPED;
        }
        $data = $this->data()[$deviceId] ?? null;
        if ($data === null || (int) ($data['count'] ?? 0) === 0) {
            return self::ROUTES_NONE;
        }
        if ((int) ($data['discovered'] ?? 0) > 0 && (int) $data['newest'] < (int) $data['discovered'] - self::DISCOVERY_MARGIN) {
            return self::ROUTES_STALE;
        }
        if (isset($data['contexts']) && ! isset($data['contexts'][$vrf])) {
            return self::ROUTES_VRF;
        }

        return self::ROUTES_OK;
    }

    /**
     * Name of a routing protocol (inetCidrRouteProto).
     */
    public static function protocolName(int $proto): string
    {
        return self::PROTOCOLS[$proto] ?? ($proto > 0 ? 'protocol ' . $proto : 'unknown');
    }

    /**
     * An IPv6 link-local address (fe80::/10)?
     */
    public static function isLinkLocal(string $address): bool
    {
        return str_contains($address, ':') && Cidr::contains('fe80::/10', $address);
    }

    private function addInterface(array $interface): void
    {
        $address = Cidr::canonical((string) $interface['address']);
        if ($address === null) {
            return;
        }
        $interface = ['device_id' => (int) $interface['device_id'], 'port_id' => (int) $interface['port_id'], 'address' => $address,
            'prefixlen' => (int) $interface['prefixlen'], 'vrf' => (string) ($interface['vrf'] ?? ''), 'up' => (bool) ($interface['up'] ?? true)];
        foreach ($this->byDevice[$interface['device_id']] ?? [] as $known) {
            if ($known['port_id'] === $interface['port_id'] && $known['address'] === $address) {
                return;
            }
        }
        if (self::isLinkLocal($address)) {
            $this->linkLocal[$address][] = $interface;

            return;
        }
        if (Cidr::isIgnorable($address)) {
            return;
        }
        $this->byAddress[$address][] = $interface;
        $this->byDevice[$interface['device_id']][] = $interface;
    }

    /**
     * Summary of the route data (resolved once).
     */
    private function data(): array
    {
        if ($this->routeData instanceof \Closure) {
            $this->routeData = ($this->routeData)();
        }

        return $this->routeData;
    }

    private function newest(int $deviceId): ?int
    {
        $newest = (int) ($this->data()[$deviceId]['newest'] ?? 0);

        return $newest > 0 ? $newest : null;
    }

    /**
     * Routes of a device that cover an address.
     */
    private function routesOf(int $deviceId, string $address): array
    {
        if (is_array($this->routes)) {
            return $this->routes[$deviceId] ?? [];
        }
        if (! array_key_exists($address, $this->routeCache)) {
            $this->routeCache[$address] = ($this->routes)($address) ?? [];
        }

        return $this->routeCache[$address][$deviceId] ?? [];
    }

    /**
     * The longest prefix route of the device toward the address, among its current routes in the VRF; a route over an
     * interface that is down no longer counts (the router withdrew it since LibreNMS read it).
     *
     * @return array{prefix: string, length: int, next_hop: ?string, next_hops: string[], port_id: int, type: int, protocol: string, metric: int, updated: int}|null
     */
    private function lookup(int $deviceId, string $vrf, string $address): ?array
    {
        $newest = (int) ($this->data()[$deviceId]['newest'] ?? 0);
        $best = null;
        $candidates = [];
        foreach ($this->routesOf($deviceId, $address) as $route) {
            if ((string) $route['context'] !== $vrf || (int) $route['updated'] < $newest - self::STALE_AFTER || $this->portDown($deviceId, (int) $route['port_id'])) {
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
            'length' => (int) $chosen['pfxlen'],
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
     * The neighbor a route's next hop is reached through: the next hop itself when it is on a subnet of the router (an
     * IPv6 link-local one on the route's interface), else the next hop of the router's own route toward it, recursively
     * (never by a default route). vrf: the table the next hop is in (the global one for a VRF route toward an address of
     * the global table: an MPLS VPN). via null: the router's routes do not lead to it.
     *
     * @return array{via: ?string, egress_port: ?int, egress: ?array, vrf: string}
     */
    private function resolve(int $deviceId, string $vrf, array $route): array
    {
        $nextHop = (string) $route['next_hop'];
        $table = $vrf !== '' && ! array_filter($this->owners($nextHop, $vrf), fn ($owner) => $owner['vrf'] === $vrf)
            && array_filter($this->owners($nextHop), fn ($owner) => $owner['vrf'] === '') ? '' : $vrf;
        $address = $nextHop;
        $portId = (int) $route['port_id'] ?: null;
        for ($depth = 0; $depth <= self::MAX_RECURSION; $depth++) {
            $egress = $portId !== null ? $this->interfaceOn($deviceId, $portId) : null;
            if (self::isLinkLocal($address)) {
                return ['via' => $address, 'egress_port' => $portId, 'egress' => $egress, 'vrf' => $table];
            }
            if ($egress !== null && self::isSubnet($egress) && self::onSubnet($egress, $address)) {
                return ['via' => $address, 'egress_port' => $portId, 'egress' => $egress, 'vrf' => $table];
            }
            $facing = $this->facing($deviceId, $address, $table);
            if ($facing !== null) {
                return ['via' => $address, 'egress_port' => $facing['port_id'], 'egress' => $facing, 'vrf' => $table];
            }
            $inner = $depth < self::MAX_RECURSION && $this->routeState($deviceId, $table) === self::ROUTES_OK ? $this->lookup($deviceId, $table, $address) : null;
            if ($inner === null || $inner['length'] === 0 || in_array($inner['type'], [self::TYPE_REJECT, self::TYPE_BLACKHOLE], true)) {
                break;
            }
            if ($inner['next_hop'] === null) {
                // an interface route toward it: on that interface's link
                $portId = $inner['port_id'] ?: null;

                return ['via' => $address, 'egress_port' => $portId, 'egress' => $portId !== null ? $this->interfaceOn($deviceId, $portId) : null, 'vrf' => $table];
            }
            $address = $inner['next_hop'];
            $portId = $inner['port_id'] ?: null;
        }
        $portId = (int) $route['port_id'] ?: null;

        return ['via' => null, 'egress_port' => $portId, 'egress' => $portId !== null ? $this->interfaceOn($deviceId, $portId) : null, 'vrf' => $table];
    }

    /**
     * The interface a next hop address belongs to. When several devices have it (overlapping subnets of VRFs, anycast
     * gateways), the one on the egress link: an LLDP/CDP neighbor of the egress port, else the one on the egress
     * interface's subnet. A link-local address only counts on the egress link; without link-local addresses in LibreNMS
     * the LLDP/CDP neighbor on that link is taken. The second value lists the other devices it could be as well.
     *
     * @return array{0: ?array, 1: int[]}
     */
    private function nextHopOwner(string $address, string $vrf, int $from, ?int $egressPort, ?array $egress): array
    {
        $linkLocal = self::isLinkLocal($address);
        $candidates = array_values(array_filter($this->owners($address, $vrf), fn ($owner) => $owner['device_id'] !== $from));
        $near = $egressPort !== null && $this->linked !== null ? ($this->linked)($from, $egressPort) : [];
        $linked = array_values(array_filter($candidates, fn ($owner) => array_key_exists($owner['device_id'], $near)));

        if ($linkLocal) {
            $onLink = $linked ?: array_values(array_filter($candidates, fn ($owner) => $egress !== null && $this->sharesSubnet($owner['device_id'], $egress)));
            if (! $onLink && count($near) === 1) {
                // no link-local addresses in LibreNMS: the neighbor on that link
                $neighbor = (int) array_key_first($near);
                $port = $near[$neighbor];

                return [['device_id' => $neighbor, 'port_id' => (int) $port, 'address' => $address, 'prefixlen' => 64, 'vrf' => $vrf, 'up' => true], []];
            }
            $candidates = $onLink;
        } elseif (count(self::deviceIds($candidates)) > 1) {
            if ($linked) {
                $candidates = $linked;
            } elseif ($egress !== null && ($onSubnet = array_values(array_filter($candidates, fn ($owner) => self::onSubnet($owner, (string) $egress['address']))))) {
                $candidates = $onSubnet;
            }
        }
        if (! $candidates) {
            return [null, []];
        }
        $others = array_values(array_diff(self::deviceIds($candidates), [$candidates[0]['device_id']]));

        return [$candidates[0], $others];
    }

    /**
     * Where the next router looks the target up: in the VRF of the interface the traffic arrives on, except for a VRF
     * route whose next hop is in the global table (an MPLS VPN toward the far PE: it stays in the VRF), and toward a
     * recursive next hop in the table that next hop is in.
     *
     * @return array{0: int, 1: string, 2: array}
     */
    private function arrive(array $owner, string $vrf, ?string $table): array
    {
        $next = $table ?? ($vrf !== '' && $owner['vrf'] === '' && $this->hasVrf($owner['device_id'], $vrf) ? $vrf : $owner['vrf']);

        return [$owner['device_id'], $next, $owner];
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
     * Does the device have the address (not link-local) on one of its interfaces?
     */
    private function owns(int $deviceId, string $address): bool
    {
        foreach ($this->byDevice[$deviceId] ?? [] as $interface) {
            if ($interface['address'] === $address) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is every interface of the device on the port down (a port without interfaces is not)?
     */
    private function portDown(int $deviceId, int $portId): bool
    {
        if ($portId <= 0) {
            return false;
        }
        $found = false;
        foreach ($this->byDevice[$deviceId] ?? [] as $interface) {
            if ($interface['port_id'] === $portId) {
                if ($interface['up']) {
                    return false;
                }
                $found = true;
            }
        }

        return $found;
    }

    /**
     * The device's up interface on the subnet of an address (the egress toward a next hop), in the VRF.
     */
    private function facing(int $deviceId, string $address, ?string $vrf = null): ?array
    {
        $best = null;
        foreach ($this->byDevice[$deviceId] ?? [] as $interface) {
            if (! $interface['up'] || ! self::isSubnet($interface) || ($vrf !== null && $interface['vrf'] !== $vrf)) {
                continue;
            }
            if (self::onSubnet($interface, $address) && ($best === null || $interface['prefixlen'] > $best['prefixlen'])) {
                $best = $interface;
            }
        }

        return $best;
    }

    /**
     * Does the device have an interface on the subnet of this one?
     */
    private function sharesSubnet(int $deviceId, array $interface): bool
    {
        foreach ($this->byDevice[$deviceId] ?? [] as $own) {
            if (self::isSubnet($own) && $own['vrf'] === $interface['vrf'] && self::onSubnet($own, (string) $interface['address'])) {
                return true;
            }
        }

        return false;
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
     * Without current routes: the first step of the shortest way over shared subnets and OSPF / BGP neighbors toward a
     * routing device with the target's subnet connected (or the router owning it, for a recursive next hop), avoiding
     * the routers already on the path.
     *
     * @param  int[]  $avoid
     * @return array{device_id: int, out: ?array, in: ?array, how: string, found: int, owner: bool}|null
     */
    private function estimate(int $deviceId, string $vrf, string $target, array $avoid): ?array
    {
        $avoid = array_fill_keys($avoid, true);
        $targets = [];
        foreach ($this->gateways($target, $vrf) as $interface) {
            $targets[$interface['device_id']] = false;
        }
        foreach ($this->owners($target, $vrf) as $interface) {
            $targets[$interface['device_id']] ??= true;
        }
        $targets = array_diff_key($targets, $avoid, [$deviceId => true]);
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
                if (array_key_exists($next, $previous) || isset($avoid[$next])) {
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

        return ['device_id' => $node, 'out' => $how['out'], 'in' => $how['in'], 'how' => $how['kind'], 'found' => $found, 'owner' => $targets[$found]];
    }

    /**
     * Why a router's next hop is estimated, and how.
     */
    private function estimateReason(int $deviceId, string $vrf, string $state, array $step): string
    {
        $name = $this->name($deviceId);
        $why = match ($state) {
            self::ROUTES_SKIPPED => 'The route table of LibreNMS was not read (it holds more routes than a path trace reads)',
            self::ROUTES_STALE => 'LibreNMS\' routes of ' . $name . ' date from ' . StpAnalyzer::duration(max(0, (int) ($this->data()[$deviceId]['discovered'] ?? 0)
                - (int) ($this->data()[$deviceId]['newest'] ?? 0))) . ' before its last discovery (the route module no longer reads them, or it has more routes than LibreNMS reads of a device)',
            self::ROUTES_VRF => 'LibreNMS has routes of ' . $name . ', but none ' . ($vrf === '' ? 'in its global table' : 'in VRF ' . $vrf),
            default => 'LibreNMS has no routes of ' . $name . ' (the route discovery module is off for it)',
        };
        $next = match ($step['how']) {
            'ospf' => 'its OSPF neighbor ' . $this->name($step['device_id']),
            'bgp' => 'its BGP neighbor ' . $this->name($step['device_id']),
            default => $this->name($step['device_id']) . ', on a subnet they share',
        };
        $goal = $step['owner'] ? 'which has the next hop\'s address' : 'which has the destination\'s subnet connected';

        return $why . ': the next hop is taken to be ' . $next . ($step['device_id'] === $step['found'] ? ', ' . $goal
            : ', on the shortest way toward ' . $this->name($step['found']) . ', ' . $goal) . '.';
    }

    /**
     * Routing devices joined by a shared subnet (up interfaces) or an OSPF / BGP neighborship.
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
                if ($interface['up'] && self::isSubnet($interface)) {
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
     * @return int[] the distinct devices of some interfaces
     */
    private static function deviceIds(array $interfaces): array
    {
        return array_values(array_unique(array_map(fn ($interface) => $interface['device_id'], $interfaces)));
    }

    /**
     * An interface address with a subnet around it (not a /32 or /128 host address).
     */
    private static function isSubnet(array $interface): bool
    {
        return $interface['prefixlen'] > 0 && $interface['prefixlen'] < (Cidr::family($interface['address']) === 4 ? 32 : 128);
    }

    /**
     * Is the address on the interface's subnet?
     */
    private static function onSubnet(array $interface, string $address): bool
    {
        $network = Cidr::network($interface['address'], $interface['prefixlen']);

        return $network !== null && Cidr::family($interface['address']) === Cidr::family($address) && Cidr::contains($network, $address);
    }

    /**
     * Sort key of an address (numeric order), interface routes first.
     */
    private static function sortable(?string $address): string
    {
        return $address === null ? '' : (string) @inet_pton($address);
    }
}
