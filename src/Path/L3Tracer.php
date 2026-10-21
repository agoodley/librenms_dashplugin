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
 * A next hop is found on the router's own subnets; an IPv6 link-local one on the route's interface (the router on that
 * link); a recursive one (iBGP, an MPLS VPN toward the far PE's loopback) through the router's own route toward it.
 * The routers after it route the destination themselves when they have a route to it (plain IP), and forward toward
 * that next hop only when they have none (a label switched path, a BGP-free core). When several devices have the next
 * hop's address, the one on the egress link is taken, else the first whose own route does not lead back; the walk on
 * from such a guess is uncertain.
 *
 * The `route` discovery module is off by default, a router can have more routes than LibreNMS reads of it, the table
 * may not have been read and the user's role may not view routes: such a router is crossed by an estimate, the first
 * step of the shortest way over subnets it shares with other routers and its OSPF / BGP neighbors (never back to a router
 * already on the path) toward a router with the destination's subnet connected.
 *
 * Everything is asked of L3Data when the walk needs it: a router's interfaces when the walk reaches it, the owners and
 * subnets of an address, the neighbors of the routers an estimate looks at.
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

    /** Routers an estimate looks at most on its way toward a router with the destination. */
    private const MAX_ESTIMATE = 500;

    /** Members of a subnet from which it is a LAN of many routers, not the transit between them. */
    private const LAN_MEMBERS = 64;

    /** Why a router's routes are used or not (routeState()). */
    public const ROUTES_OK = 'ok';

    public const ROUTES_NONE = 'none';

    public const ROUTES_VRF = 'vrf';

    public const ROUTES_STALE = 'stale';

    public const ROUTES_SKIPPED = 'skipped';

    /** The user's LibreNMS role may not view routes. */
    public const ROUTES_HIDDEN = 'hidden';

    /** The routes toward this address were not read (L3Data::routes() reads a few addresses only). */
    public const ROUTES_UNREAD = 'unread';

    public const ROUTING_ROLES = [DeviceRole::ROUTER, DeviceRole::L3SWITCH, DeviceRole::FIREWALL];

    /** inetCidrRouteType */
    private const TYPE_REJECT = 2;

    private const TYPE_LOCAL = 3;

    private const TYPE_BLACKHOLE = 5;

    private const PROTOCOLS = [2 => 'connected', 3 => 'static', 8 => 'RIP', 9 => 'IS-IS', 11 => 'IGRP', 13 => 'OSPF', 14 => 'BGP', 16 => 'EIGRP'];

    /** @var array<int, array<int, array>> device_id => its interfaces (not link-local), read when the walk reaches it */
    private array $byDevice = [];

    /** @var array<int, array<int, array>> device_id => its link-local interfaces */
    private array $linkLocalOf = [];

    /** @var array<string, array<int, array>> address => interfaces with it */
    private array $owned = [];

    /** @var array<string, array<int, array>> address => interfaces on the subnets that hold it */
    private array $subnetsOf = [];

    /** @var array<int, string> */
    private array $roleOf = [];

    /** @var array<int, array<int, array>> device_id => [neighbor device_id => how], for the routers an estimate looked at */
    private array $adjacent = [];

    /** @var array<string, array<int, array>|null> address => device_id => routes covering it, null when not read */
    private array $routeCache = [];

    /** @var array<string, array<int, array{device_id: int, port_id: int}>> address => router ports with its ARP / neighbor entries */
    private array $entries = [];

    private ?array $routeData = null;

    /** Address family of the trace's destination: the interfaces of a port are taken in it first. */
    private int $family = 4;

    public function __construct(private readonly L3Data $data)
    {
    }

    /**
     * Routers from $start to the one with the destination connected.
     *
     * @param  int|null  $ingressPort  the interface the traffic arrives on at the first router
     * @return array{hops: array<int, array>, end: string, egress: ?array, next_hop: ?string, loop: ?int, uncertain_loop: bool, uncertain: bool, stuck: ?string}
     *     hops: device_id, vrf, in_port, in_address, out_port, out_address, next_hop, via (the neighbor a recursive next hop is
     *     reached through), toward / toward_from (the recursive next hop this router forwards toward, as it has no route to
     *     the destination, and the router whose route chose it), route (prefix, next_hops, protocol, metric, updated),
     *     estimated (null or the reason) with estimated_why (a ROUTES_ state), recursive (a recursive next hop the router's
     *     routes do not lead to: the routers between are not known; unread: the address toward which its routes were not
     *     read), ambiguous (other devices with the next hop's address:
     *     the next router is a guess), routes_read (time of the router's newest route); end: local (the destination is the
     *     last router's own address), connected (on the last router's subnet: egress), no_route, blackhole, outside
     *     (next_hop belongs to no device the user may see), loop (back to the hop index loop; uncertain_loop when an
     *     estimated or guessed hop is in it), limit, stuck (no current routes and no way toward the destination: stuck is
     *     the ROUTES_ state); uncertain: a hop before the last one was estimated or guessed
     */
    public function trace(int $start, ?int $ingressPort, string $vrf, string $destination): array
    {
        $destination = Cidr::canonical($destination) ?? $destination;
        $this->family = Cidr::family($destination);
        $hops = [];
        $visited = [];
        $device = $start;
        $in = $ingressPort ? $this->interfaceOn($start, $ingressPort) : null;
        // the destination, and the recursive next hops the routers on the way forward toward when they have no route to it
        $targets = [['address' => $destination, 'outer' => $vrf, 'from' => null]];
        $result = function (string $end, array $extra = []) use (&$hops): array {
            $uncertain = (bool) array_filter(array_slice($hops, 0, -1), fn ($hop) => $hop['estimated'] !== null || $hop['ambiguous']);

            return $extra + ['hops' => $hops, 'end' => $end, 'egress' => null, 'next_hop' => null, 'loop' => null, 'uncertain_loop' => false,
                'uncertain' => $uncertain, 'stuck' => null];
        };

        while (true) {
            // a recursive next hop reached: on toward what it was the next hop for, in the VRF of that route
            while (count($targets) > 1 && $this->owns($device, $targets[count($targets) - 1]['address'])) {
                $done = array_pop($targets);
                $vrf = $done['outer'] === '' || $this->hasVrf($device, $done['outer']) ? $done['outer'] : $vrf;
            }
            // a router with a way to the destination of its own routes it (plain IP)
            if (count($targets) > 1) {
                $outer = $targets[1]['outer'];
                if (($outer === '' || $this->hasVrf($device, $outer)) && $this->routesItself($device, $outer, $destination)) {
                    $targets = [$targets[0]];
                    $vrf = $outer;
                }
            }
            $target = $targets[count($targets) - 1];
            $address = $target['address'];
            $key = $device . '|' . $vrf . '|' . $address;
            if (isset($visited[$key])) {
                $loop = $visited[$key];

                return $result('loop', ['loop' => $loop, 'uncertain_loop' => (bool) array_filter(array_slice($hops, $loop), fn ($hop) => $hop['estimated'] !== null || $hop['ambiguous'])]);
            }
            if (count($hops) >= self::MAX_HOPS) {
                return $result('limit');
            }
            $visited[$key] = count($hops);
            $avoid = array_merge(array_column($hops, 'device_id'), [$device]);
            $hop = ['device_id' => $device, 'vrf' => $vrf, 'in_port' => $in['port_id'] ?? null, 'in_address' => $in['address'] ?? null,
                'out_port' => null, 'out_address' => null, 'next_hop' => null, 'via' => null, 'toward' => $target['from'] !== null ? $address : null,
                'toward_from' => $target['from'], 'route' => null, 'estimated' => null, 'estimated_why' => null, 'recursive' => false, 'unread' => null,
                'ambiguous' => [], 'routes_read' => $this->newest($device)];

            if ($target['from'] === null && $this->owns($device, $destination)) {
                $hops[] = $hop;

                return $result('local');
            }

            $state = $this->routeState($device, $vrf);
            if ($state === self::ROUTES_OK && $this->routesOf($device, $address) === null) {
                $state = self::ROUTES_UNREAD;
            }
            $connected = $this->connected($device, $vrf, $address);
            $route = $state === self::ROUTES_OK ? $this->lookup($device, $vrf, $address) : null;

            // the longest prefix of the route table and of the connected subnets; the route table on a tie
            if ($connected !== null && ($route === null || $connected['prefixlen'] > $route['length'])) {
                $hop = ['out_port' => $connected['port_id'], 'out_address' => $connected['address']] + $hop;
                if ($target['from'] === null) {
                    $hops[] = $hop;

                    return $result('connected', ['egress' => $connected]);
                }
                // the recursive next hop is on this subnet
                [$owner, $hop['ambiguous']] = $this->nextHopOwner($address, $vrf, $device, $connected['port_id'], $connected, $address, $avoid);
                $hop['next_hop'] = $address;
                $hops[] = $hop;
                if ($owner === null) {
                    return $result('outside', ['next_hop' => $address]);
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
                    if ($target['from'] === null) {
                        $hops[] = $hop;

                        return $result('connected', ['egress' => $egress ?? ['device_id' => $device, 'port_id' => $route['port_id'], 'address' => null, 'prefixlen' => 0, 'vrf' => $vrf, 'up' => true]]);
                    }
                    [$owner, $hop['ambiguous']] = $this->nextHopOwner($address, $vrf, $device, $route['port_id'] ?: null, $egress, $address, $avoid);
                    $hop['next_hop'] = $address;
                    $hops[] = $hop;
                    if ($owner === null) {
                        return $result('outside', ['next_hop' => $address]);
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
                    $hop['unread'] = $step['unread'];
                    [$owner, $hop['ambiguous']] = $this->nextHopOwner($route['next_hop'], $step['vrf'], $device, null, null, $address, $avoid);
                } else {
                    $hop['via'] = $step['via'] !== $route['next_hop'] ? $step['via'] : null;
                    [$owner, $hop['ambiguous']] = $this->nextHopOwner($step['via'], $step['vrf'], $device, $step['egress_port'], $step['egress'], $address, $avoid);
                }
                $hops[] = $hop;
                if ($owner === null) {
                    return $result('outside', ['next_hop' => $step['via'] ?? $route['next_hop']]);
                }
                if ($hop['via'] !== null) {
                    // the routers on the way forward toward the recursive next hop when they have no route to the destination
                    $targets[] = ['address' => $route['next_hop'], 'outer' => $vrf, 'from' => $device];
                    [$device, $vrf, $in] = $this->arrive($owner, $vrf, $step['vrf']);
                } else {
                    [$device, $vrf, $in] = $this->arrive($owner, $vrf, null);
                }

                continue;
            }

            // no current routes of this router: one step toward the target, never back to a router already on the path
            $step = $this->estimate($device, $vrf, $address, $avoid);
            if ($step === null) {
                $hops[] = $hop;

                return $result('stuck', ['stuck' => $state]);
            }
            $hops[] = ['out_port' => $step['out']['port_id'] ?? null, 'out_address' => $step['out']['address'] ?? null,
                'next_hop' => $step['in']['address'] ?? null, 'estimated' => $this->estimateReason($device, $vrf, $state, $step, $address), 'estimated_why' => $state] + $hop;
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
        foreach ($this->interfacesOf($deviceId) as $interface) {
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
     * Routing devices with the address's subnet connected (an up interface), in that VRF if any has it there, else in any
     * VRF. L3Data finds the subnets by the networks LibreNMS keeps of them: when it has none (an address row without a
     * network id), the router interfaces that hold the address's ARP / neighbor entries (arpEntries()) are its gateways.
     *
     * @return array<int, array> the connected interfaces
     */
    public function gateways(string $address, ?string $vrf = null): array
    {
        $address = Cidr::canonical($address) ?? $address;
        $this->subnetsOf[$address] ??= self::rows($this->data->subnets($address));
        $found = $this->routing(array_filter($this->subnetsOf[$address], fn ($interface) => $interface['up'] && self::isSubnet($interface) && self::onSubnet($interface, $address)));
        if (! $found) {
            $found = $this->routing($this->entryInterfaces($address));
        }
        if ($vrf !== null && array_filter($found, fn ($interface) => $interface['vrf'] === $vrf)) {
            $found = array_values(array_filter($found, fn ($interface) => $interface['vrf'] === $vrf));
        }
        usort($found, fn ($a, $b) => [$b['prefixlen'], $a['device_id']] <=> [$a['prefixlen'], $b['device_id']]);

        return $found;
    }

    /**
     * Where routers hold ARP / IPv6 neighbor entries of an address (the router and the port of each, as the host locator
     * found them): its gateways when LibreNMS keeps no network of its subnet (see gateways()).
     *
     * @param  array<int, array{device_id: int, port_id: int}>  $entries
     */
    public function arpEntries(string $address, array $entries): void
    {
        $address = Cidr::canonical($address) ?? $address;
        foreach ($entries as $entry) {
            $this->entries[$address][] = ['device_id' => (int) $entry['device_id'], 'port_id' => (int) $entry['port_id']];
        }
    }

    /**
     * Interfaces with the address (devices the user may see), the ones in $vrf first, then the ones that are up. A
     * link-local address has none: it only counts on a link.
     *
     * @return array<int, array>
     */
    public function owners(string $address, string $vrf = ''): array
    {
        $address = Cidr::canonical($address) ?? $address;
        if (self::isLinkLocal($address)) {
            return [];
        }
        $this->prefetchOwners([$address]);
        $owners = $this->owned[$address];
        usort($owners, fn ($a, $b) => [$a['vrf'] !== $vrf, ! $a['up'], $a['device_id']] <=> [$b['vrf'] !== $vrf, ! $b['up'], $b['device_id']]);

        return $owners;
    }

    /**
     * The interface of a device on one port: an address of the trace's family (a global one before a link-local one),
     * else any.
     */
    public function interfaceOn(int $deviceId, int $portId): ?array
    {
        $found = array_values(array_filter(array_merge($this->interfacesOf($deviceId), $this->linkLocalOf[$deviceId] ?? []),
            fn ($interface) => $interface['port_id'] === $portId));
        foreach ($found as $interface) {
            if (Cidr::family($interface['address']) === $this->family) {
                return $interface;
            }
        }

        return $found[0] ?? null;
    }

    /**
     * Can the device's routes in the VRF be used (ROUTES_OK), and if not, why: the user's role may not view routes, the
     * route table was not read, none in LibreNMS, not read again at the last discovery (stale), or none in this VRF. A
     * router whose last discovery ended after the route summary was read is not judged stale: that discovery may have read
     * its routes again.
     */
    public function routeState(int $deviceId, string $vrf): string
    {
        if ($this->data->routesHidden()) {
            return self::ROUTES_HIDDEN;
        }
        if ($this->data->skipped()) {
            return self::ROUTES_SKIPPED;
        }
        $data = $this->summary()[$deviceId] ?? null;
        if ($data === null || (int) ($data['count'] ?? 0) === 0) {
            return self::ROUTES_NONE;
        }
        $finished = (int) ($data['finished'] ?? 0);
        if ((int) ($data['discovered'] ?? 0) > 0 && (int) $data['newest'] < (int) $data['discovered'] - self::DISCOVERY_MARGIN
            && ($finished === 0 || (int) ($data['built'] ?? PHP_INT_MAX) >= $finished)) {
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

    /**
     * Interfaces of a device (not link-local), read from L3Data once.
     *
     * @return array<int, array>
     */
    private function interfacesOf(int $deviceId): array
    {
        $this->load([$deviceId]);

        return $this->byDevice[$deviceId];
    }

    /**
     * Read the interfaces of the devices not read yet.
     *
     * @param  int[]  $deviceIds
     */
    private function load(array $deviceIds): void
    {
        $missing = array_values(array_filter(array_unique($deviceIds), fn ($id) => ! isset($this->byDevice[$id])));
        if (! $missing) {
            return;
        }
        foreach ($missing as $deviceId) {
            $this->byDevice[$deviceId] = [];
            $this->linkLocalOf[$deviceId] = [];
        }
        foreach (self::rows($this->data->interfaces($missing)) as $interface) {
            if (! isset($this->byDevice[$interface['device_id']])) {
                continue;
            }
            if (self::isLinkLocal($interface['address'])) {
                $this->linkLocalOf[$interface['device_id']][] = $interface;
            } elseif (! Cidr::isIgnorable($interface['address'])) {
                $this->byDevice[$interface['device_id']][] = $interface;
            }
        }
    }

    /**
     * Read the owners of the addresses not read yet, in one go.
     *
     * @param  string[]  $addresses
     */
    private function prefetchOwners(array $addresses): void
    {
        $missing = [];
        foreach ($addresses as $address) {
            $address = Cidr::canonical((string) $address);
            if ($address !== null && ! self::isLinkLocal($address) && ! isset($this->owned[$address])) {
                $missing[$address] = true;
                $this->owned[$address] = [];
            }
        }
        foreach ($missing ? self::rows($this->data->owners(array_keys($missing))) : [] as $interface) {
            if (isset($missing[$interface['address']])) {
                $this->owned[$interface['address']][] = $interface;
            }
        }
    }

    /**
     * Roles of devices, read from L3Data once.
     *
     * @param  int[]  $deviceIds
     * @return array<int, string>
     */
    private function roles(array $deviceIds): array
    {
        $missing = array_values(array_filter(array_unique($deviceIds), fn ($id) => ! isset($this->roleOf[$id])));
        if ($missing) {
            $roles = $this->data->roles($missing);
            foreach ($missing as $deviceId) {
                $this->roleOf[$deviceId] = $roles[$deviceId] ?? DeviceRole::UNKNOWN;
            }
        }

        return array_intersect_key($this->roleOf, array_flip($deviceIds));
    }

    private function summary(): array
    {
        return $this->routeData ??= $this->data->routeData();
    }

    private function newest(int $deviceId): ?int
    {
        $newest = (int) ($this->summary()[$deviceId]['newest'] ?? 0);

        return $newest > 0 ? $newest : null;
    }

    /**
     * Routes of a device that cover an address; null when the routes toward the address were not read (L3Data reads
     * them for a few addresses only), which is not "no route".
     */
    private function routesOf(int $deviceId, string $address): ?array
    {
        if (! array_key_exists($address, $this->routeCache)) {
            $this->routeCache[$address] = $this->data->routes($address);
        }

        return $this->routeCache[$address] === null ? null : $this->routeCache[$address][$deviceId] ?? [];
    }

    /**
     * Does the device route the destination itself: an address or a connected subnet of its own, or a current route?
     * Routes toward it that were not read do not tell: no.
     */
    private function routesItself(int $deviceId, string $vrf, string $destination): bool
    {
        return $this->owns($deviceId, $destination) || $this->connected($deviceId, $vrf, $destination) !== null
            || ($this->routeState($deviceId, $vrf) === self::ROUTES_OK && $this->lookup($deviceId, $vrf, $destination) !== null);
    }

    /**
     * The longest prefix route of the device toward the address, among its current routes in the VRF (the ones of its
     * newest read: the summary's, or a newer one among these routes); a route over an interface that is down no longer
     * counts (the router withdrew it since LibreNMS read it). Null also when the routes toward the address were not read
     * (routesOf(): the callers that tell the two apart ask it first).
     *
     * @return array{prefix: string, length: int, next_hop: ?string, next_hops: string[], port_id: int, type: int, protocol: string, metric: int, updated: int}|null
     */
    private function lookup(int $deviceId, string $vrf, string $address): ?array
    {
        $routes = $this->routesOf($deviceId, $address) ?? [];
        $newest = max((int) ($this->summary()[$deviceId]['newest'] ?? 0), $routes ? max(array_map(fn ($route) => (int) $route['updated'], $routes)) : 0);
        $best = null;
        $candidates = [];
        foreach ($routes as $route) {
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
     * the global table: an MPLS VPN). via null: the router's routes do not lead to it, or (unread) its routes toward that
     * address were not read.
     *
     * @return array{via: ?string, egress_port: ?int, egress: ?array, vrf: string, unread: ?string}
     */
    private function resolve(int $deviceId, string $vrf, array $route): array
    {
        $nextHop = (string) $route['next_hop'];
        $unread = null;
        $table = $vrf !== '' && ! array_filter($this->owners($nextHop, $vrf), fn ($owner) => $owner['vrf'] === $vrf)
            && array_filter($this->owners($nextHop), fn ($owner) => $owner['vrf'] === '') ? '' : $vrf;
        $address = $nextHop;
        $portId = (int) $route['port_id'] ?: null;
        for ($depth = 0; $depth <= self::MAX_RECURSION; $depth++) {
            $egress = $portId !== null ? $this->interfaceOn($deviceId, $portId) : null;
            if (self::isLinkLocal($address)) {
                return ['via' => $address, 'egress_port' => $portId, 'egress' => $egress, 'vrf' => $table, 'unread' => null];
            }
            if ($egress !== null && self::isSubnet($egress) && self::onSubnet($egress, $address)) {
                return ['via' => $address, 'egress_port' => $portId, 'egress' => $egress, 'vrf' => $table, 'unread' => null];
            }
            $facing = $this->facing($deviceId, $address, $table);
            if ($facing !== null) {
                return ['via' => $address, 'egress_port' => $facing['port_id'], 'egress' => $facing, 'vrf' => $table, 'unread' => null];
            }
            $usable = $depth < self::MAX_RECURSION && $this->routeState($deviceId, $table) === self::ROUTES_OK;
            if ($usable && $this->routesOf($deviceId, $address) === null) {
                $unread = $address;
                break;
            }
            $inner = $usable ? $this->lookup($deviceId, $table, $address) : null;
            if ($inner === null || $inner['length'] === 0 || in_array($inner['type'], [self::TYPE_REJECT, self::TYPE_BLACKHOLE], true)) {
                break;
            }
            if ($inner['next_hop'] === null) {
                // an interface route toward it: on that interface's link
                $portId = $inner['port_id'] ?: null;

                return ['via' => $address, 'egress_port' => $portId, 'egress' => $portId !== null ? $this->interfaceOn($deviceId, $portId) : null, 'vrf' => $table,
                    'unread' => null];
            }
            $address = $inner['next_hop'];
            $portId = $inner['port_id'] ?: null;
        }
        // the routes do not lead to it (or were not read): out of the route's interface when that has no address of its own
        // (unnumbered), else unknown
        $portId = (int) $route['port_id'] ?: null;
        $egress = $portId !== null ? $this->interfaceOn($deviceId, $portId) : null;
        $unnumbered = $portId !== null && ($egress === null || self::isLinkLocal($egress['address']));

        return ['via' => $unnumbered ? $nextHop : null, 'egress_port' => $portId, 'egress' => $egress, 'vrf' => $table, 'unread' => $unnumbered ? null : $unread];
    }

    /**
     * The interface a next hop address belongs to. When several devices have it (overlapping subnets of VRFs, anycast
     * gateways), the one on the egress link: an LLDP/CDP neighbor of the egress port, else the one on the egress
     * interface's subnet, else the first whose own route toward the target does not lead back onto the path; the second
     * value lists the other devices it could be as well. A link-local address only counts on the egress link (onLink()).
     *
     * @param  int[]  $avoid  the routers on the path
     * @return array{0: ?array, 1: int[]}
     */
    private function nextHopOwner(string $address, string $vrf, int $from, ?int $egressPort, ?array $egress, string $target, array $avoid): array
    {
        $near = $egressPort !== null ? $this->data->linked($from, $egressPort) : [];
        if (self::isLinkLocal($address)) {
            return $this->onLink($address, $vrf, $from, $egressPort, $near);
        }

        $candidates = array_values(array_filter($this->owners($address, $vrf), fn ($owner) => $owner['device_id'] !== $from));
        if (count(self::deviceIds($candidates)) > 1) {
            $linked = array_values(array_filter($candidates, fn ($owner) => array_key_exists($owner['device_id'], $near)));
            $onSubnet = $egress !== null && $egress['address'] !== null
                ? array_values(array_filter($candidates, fn ($owner) => self::onSubnet($owner, (string) $egress['address']))) : [];
            $candidates = $linked ?: ($onSubnet ?: $candidates);
        }
        if (count(self::deviceIds($candidates)) > 1) {
            $back = [];
            foreach ($candidates as $owner) {
                $back[$owner['device_id']] ??= $this->leadsBack($owner, $target, $avoid);
            }
            usort($candidates, fn ($x, $y) => $back[$x['device_id']] <=> $back[$y['device_id']]);
        }
        if (! $candidates) {
            return [null, []];
        }

        return [$candidates[0], array_values(array_diff(self::deviceIds($candidates), [$candidates[0]['device_id']]))];
    }

    /**
     * The router on the egress link of a link-local next hop: among the LLDP/CDP neighbors of the egress port and the
     * devices on the subnets of the router's interfaces on that port, the one that has the address, else (LibreNMS
     * without link-local addresses) the ones that route: a switch between passes the frames on.
     *
     * @param  array<int, ?int>  $near  LLDP/CDP neighbors of the egress port
     * @return array{0: ?array, 1: int[]}
     */
    private function onLink(string $address, string $vrf, int $from, ?int $egressPort, array $near): array
    {
        $ports = $near;
        $own = $egressPort !== null ? array_values(array_filter($this->interfacesOf($from), fn ($interface) => $interface['port_id'] === $egressPort && self::isSubnet($interface))) : [];
        foreach ($own ? self::rows($this->data->peers($own)) : [] as $peer) {
            if (array_filter($own, fn ($interface) => $interface['vrf'] === $peer['vrf'] && self::onSubnet($interface, $peer['address']))) {
                $ports[$peer['device_id']] ??= $peer['port_id'];
            }
        }
        unset($ports[$from]);
        $this->load(array_keys($ports));
        $owners = [];
        foreach ($ports as $deviceId => $port) {
            foreach ($this->linkLocalOf[$deviceId] ?? [] as $interface) {
                if ($interface['address'] === $address && (! isset($owners[$deviceId]) || $interface['port_id'] === $port)) {
                    $owners[$deviceId] = $interface;
                }
            }
        }
        if (! $owners) {
            $roles = $this->roles(array_keys($ports));
            foreach ($ports as $deviceId => $port) {
                if (in_array($roles[$deviceId] ?? DeviceRole::UNKNOWN, self::ROUTING_ROLES, true)) {
                    $owners[$deviceId] = ['device_id' => $deviceId, 'port_id' => (int) $port, 'address' => $address, 'prefixlen' => 64, 'vrf' => $vrf,
                        'up' => true, 'network_id' => 0];
                }
            }
        }
        if (! $owners) {
            return [null, []];
        }
        $first = (int) array_key_first($owners);

        return [$owners[$first], array_values(array_diff(array_keys($owners), [$first]))];
    }

    /**
     * Does a device's own route toward the target lead back to a router on the path? Routes that were not read do not tell: no.
     *
     * @param  int[]  $avoid
     */
    private function leadsBack(array $owner, string $target, array $avoid): bool
    {
        $deviceId = $owner['device_id'];
        $vrf = $owner['vrf'];
        if ($this->routeState($deviceId, $vrf) !== self::ROUTES_OK || $this->connected($deviceId, $vrf, $target) !== null) {
            return false;
        }
        $route = $this->lookup($deviceId, $vrf, $target);
        if ($route === null || $route['next_hop'] === null) {
            return false;
        }
        foreach ($this->owners($route['next_hop'], $vrf) as $next) {
            if (in_array($next['device_id'], $avoid, true)) {
                return true;
            }
        }

        return false;
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
        foreach ($this->interfacesOf($deviceId) as $interface) {
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
        foreach ($this->interfacesOf($deviceId) as $interface) {
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
        foreach ($this->interfacesOf($deviceId) as $interface) {
            if (! $interface['up'] || ! self::isSubnet($interface) || ($vrf !== null && $interface['vrf'] !== $vrf)) {
                continue;
            }
            if (self::onSubnet($interface, $address) && ($best === null || $interface['prefixlen'] > $best['prefixlen'])) {
                $best = $interface;
            }
        }

        return $best;
    }

    private function hasVrf(int $deviceId, string $vrf): bool
    {
        foreach ($this->interfacesOf($deviceId) as $interface) {
            if ($interface['vrf'] === $vrf) {
                return true;
            }
        }

        return false;
    }

    /**
     * Without current routes: the first step of the shortest way over shared subnets and OSPF / BGP neighbors toward a
     * routing device with the target's subnet connected (or the router owning it, for a recursive next hop), avoiding
     * the routers already on the path. The neighbors are read a ring of routers at a time.
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

        $previous = [$deviceId => null];
        $ring = [$deviceId];
        $found = null;
        $seen = 0;
        while ($ring && $found === null && $seen < self::MAX_ESTIMATE) {
            $this->prepare($ring);
            $next = [];
            foreach ($ring as $node) {
                $seen++;
                $neighbors = $this->adjacent[$node] ?? [];
                ksort($neighbors);
                foreach ($neighbors as $neighbor => $how) {
                    if (array_key_exists($neighbor, $previous) || isset($avoid[$neighbor])) {
                        continue;
                    }
                    $previous[$neighbor] = [$node, $how];
                    if (isset($targets[$neighbor])) {
                        $found = $neighbor;
                        break 2;
                    }
                    $next[] = $neighbor;
                }
            }
            $ring = $next;
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
     * The neighbors of some routers: OSPF / BGP neighbors first (they say more than a shared subnet), then the routing
     * devices on the subnets of their up interfaces (not a LAN of many routers).
     *
     * @param  int[]  $nodes
     */
    private function prepare(array $nodes): void
    {
        $nodes = array_values(array_filter($nodes, fn ($node) => ! isset($this->adjacent[$node])));
        if (! $nodes) {
            return;
        }
        $this->load($nodes);
        $adjacent = array_fill_keys($nodes, []);

        $rows = $this->data->adjacencies($nodes);
        $this->prefetchOwners(array_merge(array_column($rows, 'neighbor_ip'), array_filter(array_column($rows, 'local_ip'))));
        foreach ($rows as $neighbor) {
            $local = (int) $neighbor['device_id'];
            $remote = isset($adjacent[$local]) ? ($this->owners((string) $neighbor['neighbor_ip'], (string) ($neighbor['vrf'] ?? ''))[0] ?? null) : null;
            if ($remote === null || $remote['device_id'] === $local) {
                continue;
            }
            $out = ! empty($neighbor['port_id']) ? $this->interfaceOn($local, (int) $neighbor['port_id'])
                : (! empty($neighbor['local_ip']) ? ($this->owners((string) $neighbor['local_ip'])[0] ?? null) : $this->facing($local, $remote['address']));
            $adjacent[$local][$remote['device_id']] ??= ['kind' => (string) $neighbor['kind'], 'out' => $out, 'in' => $remote];
        }

        $own = [];
        foreach ($nodes as $node) {
            foreach ($this->byDevice[$node] as $interface) {
                if ($interface['up'] && self::isSubnet($interface)) {
                    $own[] = $interface;
                }
            }
        }
        $bySubnet = [];
        foreach ($own ? self::rows($this->data->peers($own)) : [] as $peer) {
            if ($peer['up'] && self::isSubnet($peer)) {
                $bySubnet[self::subnetKey($peer)][$peer['device_id'] . '|' . $peer['port_id'] . '|' . $peer['address']] = $peer;
            }
        }
        $members = [];
        foreach ($bySubnet as $subnet) {
            foreach ($subnet as $peer) {
                $members[] = $peer['device_id'];
            }
        }
        $roles = $this->roles($members);
        foreach ($own as $interface) {
            $subnet = $bySubnet[self::subnetKey($interface)] ?? [];
            if (count($subnet) > self::LAN_MEMBERS) {
                continue;
            }
            foreach ($subnet as $peer) {
                if ($peer['device_id'] !== $interface['device_id'] && in_array($roles[$peer['device_id']] ?? DeviceRole::UNKNOWN, self::ROUTING_ROLES, true)) {
                    $adjacent[$interface['device_id']][$peer['device_id']] ??= ['kind' => 'subnet', 'out' => $interface, 'in' => $peer];
                }
            }
        }
        $this->adjacent += $adjacent;
    }

    /**
     * Why a router's next hop toward the target is estimated, and how.
     */
    private function estimateReason(int $deviceId, string $vrf, string $state, array $step, string $target): string
    {
        $name = $this->data->name($deviceId);
        $data = $this->summary()[$deviceId] ?? [];
        $why = match ($state) {
            self::ROUTES_HIDDEN => 'Your LibreNMS role may not view routes',
            self::ROUTES_UNREAD => 'The routes toward ' . $target . ' were not read (a path trace reads the route table toward a few addresses only)',
            self::ROUTES_SKIPPED => 'The route table of LibreNMS was not read (it holds more routes than a path trace reads)',
            self::ROUTES_STALE => 'LibreNMS\' routes of ' . $name . ' date from ' . StpAnalyzer::duration(max(0, (int) ($data['discovered'] ?? 0) - (int) ($data['newest'] ?? 0)))
                . ' before its last discovery (the route module no longer reads them, or it has more routes than LibreNMS reads of a device)',
            self::ROUTES_VRF => 'LibreNMS has routes of ' . $name . ', but none ' . ($vrf === '' ? 'in its global table' : 'in VRF ' . $vrf),
            default => 'LibreNMS has no routes of ' . $name . ' (the route discovery module is off for it)',
        };
        $next = match ($step['how']) {
            'ospf' => 'its OSPF neighbor ' . $this->data->name($step['device_id']),
            'bgp' => 'its BGP neighbor ' . $this->data->name($step['device_id']),
            default => $this->data->name($step['device_id']) . ', on a subnet they share',
        };
        $goal = $step['owner'] ? 'which has the next hop\'s address' : 'which has the destination\'s subnet connected';

        return $why . ': the next hop is taken to be ' . $next . ($step['device_id'] === $step['found'] ? ', ' . $goal
            : ', on the shortest way toward ' . $this->data->name($step['found']) . ', ' . $goal) . '.';
    }

    /**
     * The longest prefix interface of each routing device and VRF among some interfaces.
     *
     * @param  array<int, array>  $interfaces
     * @return array<int, array>
     */
    private function routing(array $interfaces): array
    {
        $roles = $this->roles(array_column($interfaces, 'device_id'));
        $best = [];
        foreach ($interfaces as $interface) {
            $key = $interface['device_id'] . '|' . $interface['vrf'];
            if (in_array($roles[$interface['device_id']] ?? DeviceRole::UNKNOWN, self::ROUTING_ROLES, true)
                && (! isset($best[$key]) || $interface['prefixlen'] > $best[$key]['prefixlen'])) {
                $best[$key] = $interface;
            }
        }

        return array_values($best);
    }

    /**
     * The up interfaces of the address's family on the ports that hold its ARP / neighbor entries (arpEntries()): of each
     * port the one whose subnet holds the address, else the longest prefix.
     *
     * @return array<int, array>
     */
    private function entryInterfaces(string $address): array
    {
        $found = [];
        foreach ($this->entries[$address] ?? [] as $entry) {
            $on = array_values(array_filter($this->interfacesOf($entry['device_id']), fn ($interface) => $interface['port_id'] === $entry['port_id'] && $interface['up']
                && self::isSubnet($interface) && Cidr::family($interface['address']) === Cidr::family($address)));
            usort($on, fn ($x, $y) => [! self::onSubnet($x, $address), -$x['prefixlen']] <=> [! self::onSubnet($y, $address), -$y['prefixlen']]);
            if ($on) {
                $found[] = $on[0];
            }
        }

        return $found;
    }

    /**
     * Interfaces as L3Tracer keeps them (canonical address); rows without a valid address are dropped.
     *
     * @return array<int, array>
     */
    private static function rows(array $rows): array
    {
        $interfaces = [];
        foreach ($rows as $row) {
            $address = Cidr::canonical((string) $row['address']);
            if ($address !== null) {
                $interfaces[] = ['device_id' => (int) $row['device_id'], 'port_id' => (int) $row['port_id'], 'address' => $address, 'prefixlen' => (int) $row['prefixlen'],
                    'vrf' => (string) ($row['vrf'] ?? ''), 'up' => (bool) ($row['up'] ?? true), 'network_id' => (int) ($row['network_id'] ?? 0)];
            }
        }

        return $interfaces;
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

    private static function subnetKey(array $interface): string
    {
        return $interface['vrf'] . '|' . Cidr::network($interface['address'], $interface['prefixlen']);
    }

    /**
     * Sort key of an address (numeric order), interface routes first.
     */
    private static function sortable(?string $address): string
    {
        return $address === null ? '' : (string) @inet_pton($address);
    }
}
