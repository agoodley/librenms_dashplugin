<?php

namespace Agoodley\LibrenmsTopology\Routing;

use Agoodley\LibrenmsTopology\Stp\Finding;
use Agoodley\LibrenmsTopology\Stp\StpAnalyzer;
use Agoodley\LibrenmsTopology\Support\Cidr;
use Agoodley\LibrenmsTopology\Support\Graph;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * Routing and IP checks, the Layer 3 counterpart of the spanning tree diagnostics:
 *  - OSPF: duplicate router IDs, adjacencies that do not reach Full (ExStart / Exchange with the MTU
 *    of both ends), hello / dead interval, area, area type, network type and authentication
 *    mismatches between the interfaces of one link, costs that differ per direction, routers of one
 *    area whose link-state databases disagree and DR elections on point-to-point links;
 *  - BGP: sessions that are down with their last error as text, a configured remote AS the peer is
 *    rejected for, sessions configured on one side only, recent resets, accepted prefixes near the
 *    configured limit or none at all;
 *  - IP: duplicate addresses, different prefix lengths on two neighbors and links that join
 *    differently named VRFs.
 * Subnets are reused by networks that never meet (customers, sites, VRFs), so interfaces and
 * addresses are only compared where they are on one link: on two neighbors (LLDP/CDP, OSPF) or,
 * for OSPF interfaces, within one OSPF domain.
 * Pure PHP: all data is passed in (arrays or generators, each read once) so it can be tested
 * without LibreNMS. It is indexed while it is read and kept in small objects (Address,
 * OspfInterface, OspfNeighbor, BgpSession), for networks with thousands of routers.
 */
class RoutingAnalyzer
{
    /** Check codes and their category; the "disabled checks" setting accepts either. */
    public const CHECKS = [
        'ospf_duplicate_router_id' => 'ospf',
        'ospf_mtu_mismatch' => 'ospf',
        'ospf_neighbor_stuck' => 'ospf',
        'ospf_timer_mismatch' => 'ospf',
        'ospf_area_mismatch' => 'ospf',
        'ospf_area_type_mismatch' => 'ospf',
        'ospf_auth_mismatch' => 'ospf',
        'ospf_network_type_mismatch' => 'ospf',
        'ospf_lsdb_mismatch' => 'ospf',
        'ospf_cost_asymmetric' => 'ospf',
        'ospf_dr_on_p2p' => 'ospf',
        'bgp_remote_as_mismatch' => 'bgp',
        'bgp_one_sided' => 'bgp',
        'bgp_session_down' => 'bgp',
        'bgp_prefix_limit' => 'bgp',
        'bgp_recent_reset' => 'bgp',
        'bgp_no_prefixes' => 'bgp',
        'ip_duplicate' => 'ip',
        'ip_mask_mismatch' => 'ip',
        'ip_vrf_mismatch' => 'ip',
    ];

    /** Order of the categories within one severity. */
    private const CATEGORY_ORDER = ['ospf', 'bgp', 'ip'];

    /** Enumerations as LibreNMS stores them: names (enumStrings, lowercased here) or, on older versions, numbers. */
    private const NBR_STATES = [1 => 'down', 2 => 'attempt', 3 => 'init', 4 => 'twoway', 5 => 'exchangestart', 6 => 'exchange', 7 => 'loading', 8 => 'full'];

    private const IF_TYPES = [1 => 'broadcast', 2 => 'nbma', 3 => 'pointtopoint', 5 => 'pointtomultipoint'];

    private const IF_STATES = [1 => 'down', 2 => 'loopback', 3 => 'waiting', 4 => 'pointtopoint', 5 => 'designatedrouter', 6 => 'backupdesignatedrouter', 7 => 'otherdesignatedrouter'];

    private const AUTH_TYPES = [0 => 'none', 1 => 'simplepassword', 2 => 'md5'];

    private const AREA_TYPES = [1 => 'importexternal', 2 => 'importnoexternal', 3 => 'importnssa'];

    private const ADMIN = [1 => 'enabled', 2 => 'disabled'];

    private const BGP_STATES = [1 => 'idle', 2 => 'connect', 3 => 'active', 4 => 'opensent', 5 => 'openconfirm', 6 => 'established'];

    private const BGP_ADMIN = [1 => 'stop', 2 => 'start'];

    /** bgpPeerAdminStatus values of a session shut down on purpose (BGP4-MIB, Junos, Nokia...). */
    private const BGP_SHUT = ['stop', 'halted', 'adminshutdown', 'shutdown', 'disabled', 'down'];

    private const LABELS = [
        'down' => 'Down', 'attempt' => 'Attempt', 'init' => 'Init', 'twoway' => '2-Way', 'exchangestart' => 'ExStart',
        'exchange' => 'Exchange', 'loading' => 'Loading', 'full' => 'Full', 'idle' => 'Idle', 'connect' => 'Connect',
        'active' => 'Active', 'opensent' => 'OpenSent', 'openconfirm' => 'OpenConfirm', 'established' => 'Established',
        'broadcast' => 'broadcast', 'nbma' => 'NBMA', 'pointtopoint' => 'point-to-point', 'pointtomultipoint' => 'point-to-multipoint',
        'none' => 'none', 'simplepassword' => 'plain text password', 'md5' => 'MD5',
        'importexternal' => 'normal', 'importnoexternal' => 'stub', 'importnssa' => 'NSSA',
    ];

    /** A session reset this long after the router itself started was caused by the restart (seconds). */
    private const RESTART_MARGIN = 600;

    /** AS_TRANS (RFC 6793): what a two-byte speaker sees instead of a four-byte AS, so it is compared with nothing. */
    private const AS_TRANS = 23456;

    /**
     * Routers of one area whose LSA counts differ by at most this share (and one LSA) are taken as a
     * change in progress: LibreNMS polls them at different moments.
     */
    private const LSDB_TOLERANCE = 0.01;

    /** Bridges whose addresses stay inside the host behind NAT (libvirt, Docker, LXC/LXD, Podman, CNI, VirtualBox). */
    private const HOST_LOCAL = '/^((virbr|docker|lxcbr|lxdbr|podman|cni|vboxnet)\d+|br-[0-9a-f]{12})$/i';

    /** @var array<int, true> reported devices (the group / location filter) */
    private array $scope;

    /** @var array<string, Address|list<Address>> binary address => its row, or its rows when several ports have it */
    private array $byIp = [];

    /** @var array<int, list<Address>> device => its addresses */
    private array $byDevice = [];

    /** @var array<int, array<int, true>> device => devices it is directly connected to (LLDP/CDP links, OSPF adjacencies) */
    private array $adjacent = [];

    /** @var array<int, array{0: int, 1: int, 2: int, 3: int}> port pair (see pair()) => LLDP/CDP link: device, port, device, port */
    private array $links = [];

    /** @var list<array{0: int, 1: int, 2: ?string, 3: int, 4: int, 5: ?string}> LLDP/CDP links: device, port, port name, device, port, port name (0 / null: unknown) */
    private array $lldp = [];

    /** @var array<int, int> subinterface => the linked port it belongs to ("Gi0/1.100" of "Gi0/1") */
    private array $linkPort = [];

    /** @var array<int, list<Address>> linked port => the addresses on it and on its subinterfaces */
    private array $onLink = [];

    /** @var array<string, array<int, array<int, true>>> VRF name (lowercase) => device => devices it is joined to in that routing table */
    private array $joined = [];

    /** @var array<int, array<int, true>> device => devices it is joined to in every routing table (links without addresses, see joinLinks()) */
    private array $joinedAny = [];

    /** @var array<int, array{0: array<string, list<Address>>, 1: array<int, array<int, true>>}> device => see subnetsOf() (while joinLinks() runs) */
    private array $subnets = [];

    /** @var array<int, array<int, array<int, array<string, list<Address>>>>> device => address length => prefix length => its addresses of longer prefixes by their subnet of that length (see sharing()) */
    private array $inside = [];

    /** @var array<int, true> devices with addresses in a VRF of their own (not the global table) */
    private array $inVrf = [];

    /** @var array<int, int>|null device => its network over the links that join every routing table (made once, see network()) */
    private ?array $anyNetworks = null;

    /** @var array<string, array<int, int>> VRF name (lowercase) => union-find parents of the networks, after that table's own links */
    private array $tableNetworks = [];

    /** @var array<int, array<string, true>> device => names of its VRFs, lowercase */
    private array $vrfsOf = [];

    /** @var array<int, array<string, int>> device => VRF name => the VRF's own local AS */
    private array $vrfAs = [];

    /** @var array<string, true> VRF names in the data, for the filter */
    private array $vrfNames = [];

    /** @var array<string, string> strings shared by many rows (VRF names, areas, states) */
    private array $strings = [];

    /** @var array<string, array> "device|vrf" => OSPF instance with its areas and interface / neighbor counts */
    private array $instances = [];

    /** @var array<int, string[]> device => its OSPF instance keys */
    private array $instancesOf = [];

    /** @var array<string, array<int, array<string, true>>> router ID => devices using it => the VRFs of their instances with it */
    private array $routersWithId = [];

    /** @var list<OspfInterface> */
    private array $interfaces = [];

    /** @var array<string, OspfInterface> "device|vrf|ip" => OSPF interface */
    private array $interfaceAt = [];

    /** @var list<OspfNeighbor> OSPF neighbors seen by the analyzed routers */
    private array $neighbors = [];

    /** @var array<int, array<string, true>> device => binary addresses of its OSPF neighbors */
    private array $ospfPeers = [];

    /** @var array<string, int> "device|vrf" => OSPF domain (routers joined by adjacencies) */
    private array $domains = [];

    /** @var list<array{0: int, 1: string, 2: string, 3: ?int, 4: string}> OSPF areas: device, vrf, area, LSA count, type */
    private array $areas = [];

    /** @var list<BgpSession> */
    private array $peers = [];

    /** @var array<int, list<BgpSession>> device => its BGP sessions */
    private array $peersOf = [];

    /** @var array<string, true> disabled check codes and categories */
    private array $disabled;

    /** @var string[] CIDRs left out of the IP checks */
    private array $ignored;

    /** @var array<int, array{0: Finding, 1: string[]}> findings with their VRFs */
    private array $findings = [];

    /**
     * @param  array<int, array>  $devices  device_id => device row (display, hostname, sysName, status, uptime,
     *                                       bgpLocalAs) of every device the user may see
     * @param  int[]  $scope  reported devices (the group / location filter)
     * @param  iterable<array>  $addresses  IPv4 and IPv6 interface addresses: device_id, port_id, ifName, ifDescr,
     *                                      ifType, ifMtu, ifOperStatus, address, prefixlen and vrf (its VRF name)
     * @param  array{instances?: iterable, areas?: iterable, ports?: iterable, neighbors?: iterable}  $ospf  rows of
     *                                      ospf_instances, ospf_areas, ospf_ports and ospf_nbrs
     * @param  array{peers?: iterable, prefixes?: iterable}  $bgp  rows of bgpPeers (with 'vrf', its VRF name) and
     *                                      bgpPeers_cbgp
     * @param  iterable<array>  $links  LLDP/CDP links between the devices: local_device_id, local_port_id,
     *                                  remote_device_id, remote_port_id
     * @param  array<int, array<string, ?int>>  $vrfs  device => its VRF names => the VRF's own local AS (null or 0: none)
     */
    public function __construct(
        private array $devices,
        array $scope,
        iterable $addresses,
        private PluginSettings $settings,
        array $ospf = [],
        array $bgp = [],
        iterable $links = [],
        array $vrfs = [],
    ) {
        $this->scope = array_fill_keys(array_map('intval', $scope), true);
        $this->disabled = array_fill_keys(array_map('strtolower', $settings->list('routing_disabled_checks')), true);
        $this->ignored = $settings->list('routing_ignore_addresses');
        $this->readVrfs($vrfs);
        $this->readLinks($links);
        $this->readAddresses($addresses);
        $this->joinLinks();
        $this->readOspf($ospf);
        $this->readBgp($bgp);
    }

    /**
     * Run every check and return the data of the page: summary, findings and the OSPF and BGP
     * tables, limited to the reported devices, to $device (what involves it) and to a VRF.
     */
    public function analyze(?int $device = null, ?string $vrf = null): array
    {
        $this->findings = [];
        $this->checkRouterIds();
        $this->checkAdjacencies();
        $this->checkInterfaces();
        $this->checkAreas();
        $this->checkSessions();
        $this->checkPrefixLimits();
        $this->checkDuplicates();
        $this->checkMasks();
        $this->checkVrfs();

        $vrf = $vrf === '' ? null : $vrf;
        $keep = fn (array $devices, array $vrfs) => $this->inScope($devices)
            && ($device === null || in_array($device, $devices, true))
            && ($vrf === null || in_array($vrf, $vrfs, true));

        $findings = [];
        foreach ($this->findings as [$finding, $vrfs]) {
            if ($keep($finding->devices, $vrfs)) {
                $findings[] = $finding->toArray() + ['category' => self::CHECKS[$finding->code] ?? 'other', 'vrfs' => $vrfs];
            }
        }
        $categoryOrder = array_flip(self::CATEGORY_ORDER);
        $codeOrder = array_flip(array_keys(self::CHECKS));
        $key = fn ($f) => [Finding::SEVERITY_ORDER[$f['severity']], $categoryOrder[$f['category']] ?? 99, $codeOrder[$f['code']] ?? 99, $f['title']];
        usort($findings, fn ($a, $b) => $key($a) <=> $key($b));

        // table rows: of a reported router, and with the device on either end
        $row = fn (int $deviceId, ?int $remote, string $vrf) => isset($this->scope[$deviceId])
            && $keep($remote !== null && $remote !== $deviceId ? [$deviceId, $remote] : [$deviceId], [$vrf]);
        $routers = $this->routerRows(fn (array $instance) => $keep([$instance['device_id']], [$instance['vrf']]));
        $adjacencies = $this->neighborRows(fn (OspfNeighbor $n) => $row($n->device, $n->remoteDevice, $n->vrf));
        $sessions = $this->sessionRows(fn (BgpSession $s) => $row($s->device, $s->remoteDevice, $s->vrf));

        $names = [];
        foreach ($findings as $finding) {
            foreach ($finding['devices'] as $deviceId) {
                $names[$deviceId] = $this->name($deviceId);
            }
        }
        foreach ([$routers, $adjacencies, $sessions] as $rows) {
            foreach ($rows as $r) {
                foreach ([$r['device_id'], $r['remote_device'] ?? null] as $deviceId) {
                    if ($deviceId !== null) {
                        $names[$deviceId] = $this->name($deviceId);
                    }
                }
            }
        }

        return [
            'device_names' => $names,
            'vrfs' => $this->vrfList(),
            'summary' => self::summarize($findings, $routers, $adjacencies, $sessions, $this->addressCount($device, $vrf)),
            'findings' => $findings,
            'ospf_routers' => $routers,
            'ospf_neighbors' => $adjacencies,
            'bgp_sessions' => $sessions,
        ];
    }

    /**
     * Summary cards of a (filtered) result.
     */
    public static function summarize(array $findings, array $routers, array $adjacencies, array $sessions, int $addresses): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        $categories = array_fill_keys(array_values(array_unique(self::CHECKS)), 0);
        foreach ($findings as $finding) {
            $counts[$finding['severity']]++;
            $categories[$finding['category']] = ($categories[$finding['category']] ?? 0) + 1;
        }

        return [
            'ospf' => [
                'routers' => count($routers),
                'neighbors' => count($adjacencies),
                'full' => count(array_filter($adjacencies, fn ($r) => $r['ok'])),
            ],
            'bgp' => [
                'sessions' => count($sessions),
                'established' => count(array_filter($sessions, fn ($r) => $r['established'])),
                'shut' => count(array_filter($sessions, fn ($r) => $r['shut'])),
                'unknown' => count(array_filter($sessions, fn ($r) => $r['unknown'] && ! $r['shut'])),
            ],
            'addresses' => $addresses,
            'findings' => $counts,
            'categories' => $categories,
            'status' => $counts['critical'] ? 'critical' : ($counts['warning'] ? 'warning' : 'ok'),
        ];
    }

    // ---------------------------------------------------------------- data

    /**
     * VRF names of each device (lowercase) and the local AS of the VRFs that have their own.
     */
    private function readVrfs(array $vrfs): void
    {
        foreach ($vrfs as $deviceId => $names) {
            foreach ($names as $name => $as) {
                $name = (string) $name;
                if ($name !== '') {
                    $this->vrfsOf[(int) $deviceId][strtolower($name)] = true;
                    $this->vrfNames[$name] = true;
                    if ((int) $as > 0) {
                        $this->vrfAs[(int) $deviceId][$name] = (int) $as;
                    }
                }
            }
        }
    }

    /**
     * LLDP/CDP links: the devices they connect and, when both ports are known, the ports facing each other.
     */
    private function readLinks(iterable $links): void
    {
        foreach ($links as $link) {
            $a = (int) ($link['local_device_id'] ?? 0);
            $b = (int) ($link['remote_device_id'] ?? 0);
            if ($a === $b || ! isset($this->devices[$a], $this->devices[$b])) {
                continue;
            }
            $this->adjacent[$a][$b] = true;
            $this->adjacent[$b][$a] = true;
            $portA = (int) ($link['local_port_id'] ?? 0);
            $portB = (int) ($link['remote_port_id'] ?? 0);
            $this->lldp[] = [$a, $portA, self::portName($link, 'local'), $b, $portB, self::portName($link, 'remote')];
            if ($portA > 0 && $portB > 0 && $portA !== $portB) {
                $this->links[self::pair($portA, $portB)] ??= [$a, $portA, $b, $portB];
            }
        }
    }

    /**
     * The addresses on each linked port and its subinterfaces, and the routing tables each LLDP/CDP
     * link joins: the VRFs both ends are in, so a link whose ends are in VRFs of different names (a
     * provider edge facing a customer router) is a routing boundary and joins none. An end without
     * addresses (an L2 port, a LAG member, a port with SVIs behind it, or a port LibreNMS did not
     * resolve) facing one with addresses is in the VRFs of its device's addresses that share a subnet
     * with that end (see sharedVrfs()). A link with no addresses on either end joins every table
     * unless a subnet the two devices share is evidence of a boundary (see agreedVrfs()).
     */
    private function joinLinks(): void
    {
        $parents = [];
        foreach ($this->lldp as [$a, $portA, $nameA, $b, $portB, $nameB]) {
            foreach ([[$a, $portA, $nameA], [$b, $portB, $nameB]] as [$deviceId, $port, $name]) {
                if ($port > 0) {
                    $this->onLink[$port] ??= [];
                    if ($name !== null) {
                        $parents[$deviceId][$name] = $port;
                    }
                }
            }
        }
        foreach ($this->onLink ? $this->byDevice : [] as $deviceId => $addresses) {
            foreach ($addresses as $address) {
                $parent = self::parentPort($address->port);
                if ($parent !== null && isset($parents[$deviceId][$parent]) && $address->portId !== null) {
                    $this->linkPort[$address->portId] = $parents[$deviceId][$parent];
                }
                $port = $this->linkPort[$address->portId ?? 0] ?? $address->portId;
                if ($port !== null && isset($this->onLink[$port])) {
                    $this->onLink[$port][] = $address;
                }
            }
        }

        $peers = [];
        foreach ($this->lldp as [$a, $portA, , $b, $portB]) {
            if ($portA > 0) {
                $peers[$portA][$b] = true;
            }
            if ($portB > 0) {
                $peers[$portB][$a] = true;
            }
        }
        $tables = [];
        foreach ($this->lldp as [$a, $portA, , $b, $portB]) {
            $endA = $this->onLink[$portA] ?? [];
            $endB = $this->onLink[$portB] ?? [];
            if ($endA || $endB) {
                $vrfsA = $endA ? self::vrfsOf($endA) : $this->sharedVrfs($a, $b, $endB, $peers);
                $vrfsB = $endB ? self::vrfsOf($endB) : $this->sharedVrfs($b, $a, $endA, $peers);
                $vrfs = $vrfsA === null ? $vrfsB : ($vrfsB === null ? $vrfsA : array_intersect_key($vrfsA, $vrfsB));
            } else {
                // the same for every link between the two devices (the members of a LAG)
                [$vrfs] = $tables[min($a, $b) . '-' . max($a, $b)] ??= [$this->agreedVrfs($a, $b, $peers)];
                if ($vrfs === null) {
                    $this->joinedAny[$a][$b] = $this->joinedAny[$b][$a] = true;

                    continue;
                }
            }
            foreach (array_keys($vrfs) as $vrf) {
                $this->joined[(string) $vrf][$a][$b] = $this->joined[(string) $vrf][$b][$a] = true;
            }
        }
        $this->subnets = $this->inside = [];
    }

    /**
     * VRF names (lowercase) of a device's addresses whose subnet holds an address of the other end
     * of a link (loopbacks and the ports of links to third devices left out); null when there are none.
     *
     * @param  list<Address>  $theirs  the addresses of the other end
     * @param  array<int, array<int, true>>  $peers  linked port => devices at its other ends
     * @return array<string, true>|null
     */
    private function sharedVrfs(int $device, int $other, array $theirs, array $peers): ?array
    {
        [$bySubnet, $prefixes] = $this->subnetsOf($device);
        $vrfs = [];
        foreach ($theirs as $their) {
            foreach (array_keys($prefixes[strlen($their->packed)] ?? []) as $prefix) {
                foreach ($bySubnet[$their->key($prefix)] ?? [] as $mine) {
                    if ($this->usable($mine, $other, $peers)) {
                        $vrfs[strtolower($mine->vrf)] = true;
                    }
                }
            }
        }

        return $vrfs ?: null;
    }

    /**
     * The routing tables a link without addresses on either end joins, from the subnets its two devices
     * share (loopbacks and the ports of links to third devices left out). It is one L2 domain that joins
     * every table (null), switches sharing a management subnet or VRF-lite trunks, unless a shared
     * subnet is evidence of a routing boundary: no VRF name holds it on both devices, and it is a
     * point-to-point subnet (/30, /31, /126, /127: a provider edge facing a customer router over a
     * port-channel) or both devices have addresses in VRFs of their own (a VRF that is only defined,
     * like the management VRF of an access switch managed in-band, does not count). Then it joins the
     * VRFs that hold a shared subnet on both devices, possibly none.
     *
     * @param  array<int, array<int, true>>  $peers  linked port => devices at its other ends
     * @return array<string, true>|null
     */
    private function agreedVrfs(int $a, int $b, array $peers): ?array
    {
        // the addresses of the device with fewer, looked up in the other's subnets (a hub has thousands)
        [$small, $big] = count($this->byDevice[$a] ?? []) <= count($this->byDevice[$b] ?? []) ? [$a, $b] : [$b, $a];
        $shared = [];
        foreach ($this->byDevice[$small] ?? [] as $mine) {
            if (! $this->usable($mine, $big, $peers)) {
                continue;
            }
            foreach ($this->sharing($big, $mine) as [$key, $prefix, $their]) {
                if ($this->usable($their, $small, $peers)) {
                    $shared[$key][0] = $prefix >= strlen($mine->packed) * 8 - 2;
                    $shared[$key][1][strtolower($mine->vrf)] = true;
                    $shared[$key][2][strtolower($their->vrf)] = true;
                }
            }
        }

        $agreed = [];
        $boundary = false;
        $ownVrfs = isset($this->inVrf[$a], $this->inVrf[$b]);
        foreach ($shared as [$pointToPoint, $mine, $theirs]) {
            $both = array_intersect_key($mine, $theirs);
            $agreed += $both;
            $boundary = $boundary || (! $both && ($pointToPoint || $ownVrfs));
        }

        return $boundary ? $agreed : null;
    }

    /**
     * A device's addresses (loopbacks left out) that share a subnet with an address, each with that
     * subnet (the larger of the two) and its prefix length: those whose subnet holds the address and
     * those of longer prefixes in its subnet.
     *
     * @return list<array{0: string, 1: int, 2: Address}>
     */
    private function sharing(int $device, Address $address): array
    {
        [$bySubnet, $prefixes] = $this->subnetsOf($device);
        $bytes = strlen($address->packed);
        $found = [];
        foreach (array_keys($prefixes[$bytes] ?? []) as $prefix) {
            if ($prefix <= $address->prefix) {
                $key = $address->key($prefix);
                foreach ($bySubnet[$key] ?? [] as $other) {
                    $found[] = [$key, $prefix, $other];
                }
            }
        }
        if (! isset($this->inside[$device][$bytes][$address->prefix])) {
            $inside = [];
            foreach ($this->byDevice[$device] ?? [] as $other) {
                if (! $other->host && $other->prefix > $address->prefix && strlen($other->packed) === $bytes) {
                    $inside[$other->key($address->prefix)][] = $other;
                }
            }
            $this->inside[$device][$bytes][$address->prefix] = $inside;
        }
        $key = $address->key();
        foreach ($this->inside[$device][$bytes][$address->prefix][$key] ?? [] as $other) {
            $found[] = [$key, $address->prefix, $other];
        }

        return $found;
    }

    /**
     * A device's addresses (loopbacks left out) by subnet, and its prefix lengths by address length.
     *
     * @return array{0: array<string, list<Address>>, 1: array<int, array<int, true>>}
     */
    private function subnetsOf(int $device): array
    {
        if (! isset($this->subnets[$device])) {
            $index = [[], []];
            foreach ($this->byDevice[$device] ?? [] as $address) {
                if (! $address->host) {
                    $index[0][$address->key()][] = $address;
                    $index[1][strlen($address->packed)][$address->prefix] = true;
                }
            }
            $this->subnets[$device] = $index;
        }

        return $this->subnets[$device];
    }

    /**
     * Can an address stand for a link to $peer? Not a loopback, and not on a port linked to devices
     * other than $peer (the address belongs to those links).
     *
     * @param  array<int, array<int, true>>  $peers  linked port => devices at its other ends
     */
    private function usable(Address $address, int $peer, array $peers): bool
    {
        $port = $this->linkPort[$address->portId ?? 0] ?? $address->portId ?? 0;

        return ! $address->host && (! isset($peers[$port]) || isset($peers[$port][$peer]));
    }

    /**
     * VRF names (lowercase) of addresses.
     *
     * @param  list<Address>  $addresses
     * @return array<string, true>
     */
    private static function vrfsOf(array $addresses): array
    {
        $vrfs = [];
        foreach ($addresses as $address) {
            $vrfs[strtolower($address->vrf)] = true;
        }

        return $vrfs;
    }

    /**
     * Index the interface addresses by address and by device. Link-local, loopback (127/8) and
     * multicast addresses are left out.
     */
    private function readAddresses(iterable $rows): void
    {
        $portNames = [];
        foreach ($rows as $row) {
            $deviceId = (int) ($row['device_id'] ?? 0);
            $packed = Address::pack($row['address'] ?? null);
            if ($packed === null || ! isset($this->devices[$deviceId]) || self::ignorable($packed)) {
                continue;
            }

            $bits = strlen($packed) * 8;
            $prefix = (int) ($row['prefixlen'] ?? $bits);
            $prefix = $prefix >= 0 && $prefix <= $bits ? $prefix : $bits;
            $vrf = $this->vrf($deviceId, (string) ($row['vrf'] ?? ''));
            $status = strtolower(trim((string) ($row['ifOperStatus'] ?? '')));
            $name = ($row['ifName'] ?? '') . '|' . ($row['ifDescr'] ?? '');
            $address = new Address(
                $deviceId,
                ! empty($row['port_id']) ? (int) $row['port_id'] : null,
                $portNames[$name] ??= InterfaceName::short(InterfaceName::label($row)),
                $packed,
                $prefix,
                $vrf,
                $prefix >= $bits || strtolower((string) ($row['ifType'] ?? '')) === 'softwareloopback',
                $status === '' || $status === 'up',
                (int) ($row['ifMtu'] ?? 0) ?: null,
            );

            $this->byDevice[$deviceId][] = $address;
            if ($vrf !== '') {
                $this->inVrf[$deviceId] = true;
            }
            if (! isset($this->byIp[$packed])) {
                $this->byIp[$packed] = $address;
            } elseif (is_array($this->byIp[$packed])) {
                $this->byIp[$packed][] = $address;
            } else {
                $this->byIp[$packed] = [$this->byIp[$packed], $address];
            }
        }
    }

    /**
     * OSPF instances, interfaces and neighbors of the devices that are up (a down device's rows are
     * from before it went down), with the interface facing each neighbor and the device owning the
     * neighbor's address; then the OSPF domains (routers joined by adjacencies, per VRF) and the areas.
     */
    private function readOspf(array $ospf): void
    {
        foreach ($ospf['instances'] ?? [] as $row) {
            $deviceId = (int) ($row['device_id'] ?? 0);
            if (! $this->isUp($deviceId) || self::enum($row['ospfAdminStat'] ?? 'enabled', self::ADMIN) === 'disabled') {
                continue;
            }
            $vrf = $this->vrf($deviceId, (string) ($row['context_name'] ?? ''));
            $key = $deviceId . '|' . $vrf;
            $routerId = self::ip((string) ($row['ospfRouterId'] ?? '')) ?? trim((string) ($row['ospfRouterId'] ?? ''));
            $this->instances[$key] = [
                'device_id' => $deviceId,
                'vrf' => $vrf,
                'router_id' => $routerId,
                'abr' => self::enum($row['ospfAreaBdrRtrStatus'] ?? '', [1 => 'true', 2 => 'false']) === 'true',
                'asbr' => self::enum($row['ospfASBdrRtrStatus'] ?? '', [1 => 'true', 2 => 'false']) === 'true',
                'areas' => [],
                'interfaces' => 0,
                'neighbors' => 0,
                'full' => 0,
            ];
            $this->instancesOf[$deviceId][] = $key;
            if (self::validRouterId($routerId)) {
                $this->routersWithId[$routerId][$deviceId][$vrf] = true;
            }
        }

        foreach ($ospf['ports'] ?? [] as $row) {
            $deviceId = (int) ($row['device_id'] ?? 0);
            $key = $deviceId . '|' . ($row['context_name'] ?? '');
            if (! isset($this->instances[$key]) || self::enum($row['ospfIfAdminStat'] ?? 'enabled', self::ADMIN) === 'disabled') {
                continue;
            }
            $vrf = $this->instances[$key]['vrf'];
            $ip = self::ip((string) ($row['ospfIfIpAddress'] ?? ''));
            $address = $ip !== null && $ip !== '0.0.0.0' ? $this->ownAddress($deviceId, (string) inet_pton($ip), $vrf) : null;
            $area = $this->intern(self::area($row['ospfIfAreaId'] ?? ''));
            $interface = new OspfInterface(
                $deviceId,
                $vrf,
                $ip,
                $address,
                (int) ($row['port_id'] ?? 0) ?: $address?->portId,
                $area,
                $this->intern(self::enum($row['ospfIfType'] ?? null, self::IF_TYPES)),
                $this->intern(self::enum($row['ospfIfState'] ?? null, self::IF_STATES)),
                isset($row['ospfIfHelloInterval']) ? (int) $row['ospfIfHelloInterval'] : null,
                isset($row['ospfIfRtrDeadInterval']) ? (int) $row['ospfIfRtrDeadInterval'] : null,
                $this->intern(self::enum($row['ospfIfAuthType'] ?? null, self::AUTH_TYPES)),
                isset($row['ospfIfMetricValue']) ? (int) $row['ospfIfMetricValue'] : null,
                self::ip((string) ($row['ospfIfDesignatedRouter'] ?? '')),
            );
            $this->interfaces[] = $interface;
            if ($ip !== null) {
                $this->interfaceAt[$key . '|' . $ip] = $interface;
            }
            $this->instances[$key]['interfaces']++;
            if ($area !== '') {
                $this->instances[$key]['areas'][$area] = true;
            }
        }

        foreach ($ospf['neighbors'] ?? [] as $row) {
            $deviceId = (int) ($row['device_id'] ?? 0);
            $key = $deviceId . '|' . ($row['context_name'] ?? '');
            $ip = self::ip((string) ($row['ospfNbrIpAddr'] ?? ''));
            if ($ip === null || ! isset($this->instances[$key])) {
                continue;
            }
            $vrf = $this->instances[$key]['vrf'];
            $packed = (string) inet_pton($ip);
            $routerId = self::ip((string) ($row['ospfNbrRtrId'] ?? '')) ?? trim((string) ($row['ospfNbrRtrId'] ?? ''));
            [$remoteDevice, $remote] = $this->owner($packed, $vrf, $deviceId);
            if ($remoteDevice === null && ($remoteDevice = $this->routerWithId($routerId, $deviceId)) !== null) {
                $remote = $this->ownAddress($remoteDevice, $packed, $vrf);
            }
            // the neighbor's own instance: the one with its router ID, else the VRF of its address
            $instances = $remoteDevice !== null ? ($this->routersWithId[$routerId][$remoteDevice] ?? []) : [];
            $remoteVrf = count($instances) === 1 ? (string) array_key_first($instances) : $remote?->vrf;
            $local = $this->facing($deviceId, $packed, $vrf);
            $interface = $local !== null ? ($this->interfaceAt[$key . '|' . $local->ip()] ?? null) : null;
            if ($interface !== null) {
                $interface->talking = true;
            }
            $neighbor = new OspfNeighbor($deviceId, $vrf, $ip, $routerId, $this->intern(self::enum($row['ospfNbrState'] ?? null, self::NBR_STATES)),
                $remoteDevice, $remoteVrf, $remote, $local, $interface);
            $this->neighbors[] = $neighbor;
            $this->instances[$key]['neighbors']++;
            $this->instances[$key]['full'] += $neighbor->ok() ? 1 : 0;
            $this->ospfPeers[$deviceId][$packed] = true;
            if ($remoteDevice !== null && $remoteDevice !== $deviceId) {
                $this->adjacent[$deviceId][$remoteDevice] = true;
                $this->adjacent[$remoteDevice][$deviceId] = true;
                if ($remoteVrf !== null && strtolower($remoteVrf) === strtolower($vrf)) {
                    $this->joined[strtolower($vrf)][$deviceId][$remoteDevice] = $this->joined[strtolower($vrf)][$remoteDevice][$deviceId] = true;
                }
            }
        }

        $graph = new Graph;
        foreach (array_keys($this->instances) as $key) {
            $graph->addNode($key);
        }
        foreach ($this->neighbors as $neighbor) {
            $remote = $neighbor->remoteDevice === null ? null : $this->instanceKey($neighbor->remoteDevice, $neighbor->remoteVrf ?? $neighbor->vrf);
            if ($remote !== null) {
                $graph->addEdge($neighbor->device . '|' . $neighbor->vrf, $remote);
            }
        }
        foreach ($graph->components() as $index => $component) {
            foreach ($component as $key) {
                $this->domains[(string) $key] = $index;
            }
        }

        foreach ($ospf['areas'] ?? [] as $row) {
            $deviceId = (int) ($row['device_id'] ?? 0);
            $key = $deviceId . '|' . ($row['context_name'] ?? '');
            if (isset($this->instances[$key])) {
                $this->areas[] = [
                    $deviceId,
                    $this->instances[$key]['vrf'],
                    $this->intern(self::area($row['ospfAreaId'] ?? '')),
                    isset($row['ospfAreaLsaCount']) ? (int) $row['ospfAreaLsaCount'] : null,
                    $this->intern(self::enum($row['ospfImportAsExtern'] ?? null, self::AREA_TYPES)),
                ];
            }
        }
    }

    /**
     * BGP sessions of the devices that are up, with the device owning the peer address, the local
     * AS (of the session's VRF) and the prefix counters of bgpPeers_cbgp.
     */
    private function readBgp(array $bgp): void
    {
        $prefixes = [];
        foreach ($bgp['prefixes'] ?? [] as $row) {
            $packed = Address::pack($row['bgpPeerIdentifier'] ?? null);
            if ($packed !== null) {
                $prefixes[(int) ($row['device_id'] ?? 0) . '|' . ($row['context_name'] ?? '') . '|' . $packed][] = [
                    'family' => $this->intern(self::family((string) ($row['afi'] ?? ''), (string) ($row['safi'] ?? ''))),
                    'accepted' => (int) ($row['AcceptedPrefixes'] ?? 0),
                    'denied' => (int) ($row['DeniedPrefixes'] ?? 0),
                    'limit' => (int) ($row['PrefixAdminLimit'] ?? 0),
                    'threshold' => (int) ($row['PrefixThreshold'] ?? 0),
                ];
            }
        }

        foreach ($bgp['peers'] ?? [] as $row) {
            $deviceId = (int) ($row['device_id'] ?? 0);
            $ip = self::ip((string) ($row['bgpPeerIdentifier'] ?? '')) ?? self::ip((string) ($row['bgpPeerRemoteAddr'] ?? ''));
            if ($ip === null || ! $this->isUp($deviceId)) {
                continue;
            }
            $packed = (string) inet_pton($ip);
            $vrf = $this->vrf($deviceId, (string) ($row['vrf'] ?? $row['context_name'] ?? ''));
            [$remoteDevice, $remote] = $this->owner($packed, $vrf, $deviceId);
            $localIp = Address::pack($row['bgpLocalAddr'] ?? null);
            $local = ($localIp !== null && trim($localIp, "\0") !== '' ? $this->ownAddress($deviceId, $localIp, $vrf) : null) ?? $this->facing($deviceId, $packed, $vrf);
            $session = new BgpSession(
                $deviceId,
                $vrf,
                $ip,
                (int) ($row['bgpPeerRemoteAs'] ?? 0) ?: null,
                $this->localAs($deviceId, $vrf),
                $this->intern(self::enum($row['bgpPeerState'] ?? null, self::BGP_STATES)),
                in_array(self::enum($row['bgpPeerAdminStatus'] ?? null, self::BGP_ADMIN), self::BGP_SHUT, true),
                self::number($row['bgpPeerLastErrorCode'] ?? null),
                self::number($row['bgpPeerLastErrorSubCode'] ?? null),
                trim((string) ($row['bgpPeerLastErrorText'] ?? '')),
                max(0, (int) ($row['bgpPeerFsmEstablishedTime'] ?? 0)),
                trim((string) ($row['bgpPeerDescr'] ?? '')) ?: trim((string) ($row['astext'] ?? '')),
                $remoteDevice,
                $remote,
                $local,
                $prefixes[$deviceId . '|' . ($row['context_name'] ?? '') . '|' . $packed] ?? [],
            );
            $this->peers[] = $session;
            $this->peersOf[$deviceId][] = $session;
        }
    }

    // ---------------------------------------------------------------- OSPF checks

    /**
     * The same router ID on two routers: in one OSPF domain their LSAs replace each other (routes
     * flap, adjacencies reset), in separate domains it breaks as soon as they meet. A neighbor that
     * reports the router's own ID is a duplicate with a router LibreNMS does not monitor.
     */
    private function checkRouterIds(): void
    {
        $byId = [];
        foreach ($this->instances as $key => $instance) {
            if (self::validRouterId($instance['router_id'])) {
                $byId[$instance['router_id']][$key] = $instance;
            }
        }

        $reported = [];
        foreach ($byId as $routerId => $instances) {
            $devices = array_values(array_unique(array_column($instances, 'device_id')));
            if (count($devices) < 2) {
                continue;
            }
            $domains = array_map(fn ($key) => $this->domains[$key] ?? $key, array_keys($instances));
            $shared = count($domains) !== count(array_unique($domains));
            $reported[$routerId] = true;
            $this->add(new Finding(
                $shared ? Finding::CRITICAL : Finding::WARNING,
                'ospf_duplicate_router_id',
                "duplicate OSPF router ID $routerId: " . $this->names($devices),
                $shared
                    ? 'These routers are in the same OSPF domain: their router LSAs replace each other, so routes flap and adjacencies reset.'
                    : 'These routers are in separate OSPF domains here (or connected through routers LibreNMS does not monitor); the duplicate breaks routing as soon as they are in one domain.',
                'Give every router a unique router ID, usually its loopback address (router-id under the OSPF process), then restart the OSPF process.',
                null,
                $devices,
            ), array_values(array_unique(array_column($instances, 'vrf'))));
        }

        foreach ($this->neighbors as $neighbor) {
            $own = $this->instances[$neighbor->device . '|' . $neighbor->vrf]['router_id'] ?? '';
            if (! self::validRouterId($own) || $neighbor->routerId !== $own || isset($reported[$own])) {
                continue;
            }
            $reported[$own] = true;
            $this->add(new Finding(
                Finding::CRITICAL,
                'ospf_duplicate_router_id',
                'OSPF neighbor ' . $neighbor->ip . ' of ' . $this->name($neighbor->device) . ' uses the same router ID ' . $own,
                'The neighbor (a router LibreNMS does not monitor) and ' . $this->name($neighbor->device) . ' both use router ID ' . $own
                . ': their router LSAs replace each other and the adjacency cannot be trusted.',
                'Give every router a unique router ID, usually its loopback address, then restart the OSPF process.',
                null,
                [$neighbor->device],
                array_filter([$neighbor->local?->portId]),
            ), [$neighbor->vrf]);
        }
    }

    /**
     * Adjacencies that do not reach Full, one finding per pair of routers on a subnet. ExStart /
     * Exchange compares the MTU of both ends; 2-Way is only a problem on point-to-point interfaces
     * (two routers that are neither DR nor BDR stay in 2-Way on a broadcast segment).
     */
    private function checkAdjacencies(): void
    {
        $pairs = [];
        foreach ($this->neighbors as $neighbor) {
            if ($neighbor->ok() || $neighbor->state === '') {
                continue;
            }
            $here = 'd:' . $neighbor->device;
            $there = $neighbor->remoteDevice !== null ? 'd:' . $neighbor->remoteDevice : 'ip:' . $neighbor->ip;
            $network = $neighbor->local?->network() ?? $neighbor->ip;
            $pairs[(strcmp($here, $there) < 0 ? "$here|$there" : "$there|$here") . '|' . $network][] = $neighbor;
        }

        $rank = ['exchangestart' => 0, 'exchange' => 0, 'init' => 1, 'loading' => 2, 'twoway' => 3, 'attempt' => 4, 'down' => 4];
        foreach ($pairs as $rows) {
            usort($rows, fn ($a, $b) => ($rank[$a->state] ?? 9) <=> ($rank[$b->state] ?? 9));
            $first = $rows[0];
            $state = $first->state;
            $devices = array_values(array_unique(array_filter([$first->device, $first->remoteDevice], fn ($d) => $d !== null)));
            $ports = array_values(array_filter([$first->local?->portId, $first->remote?->portId]));
            $seen = implode(', ', array_map(fn ($r) => $this->name($r->device) . ' sees ' . $r->ip . ' in ' . self::label($r->state), $rows));
            $pair = $this->end($first->device, $first->local?->port) . ' ↔ '
                . ($first->remoteDevice !== null ? $this->end($first->remoteDevice, $first->remote?->port) : $first->ip . ' (router ID ' . $first->routerId . ')');
            $down = $first->remoteDevice !== null && ! $this->isUp($first->remoteDevice) ? ' ' . $this->name($first->remoteDevice) . ' itself is down.' : '';

            if (in_array($state, ['exchangestart', 'exchange'], true)) {
                $mtuHere = $first->local?->mtu;
                $mtuThere = $first->remote?->mtu;
                if ($mtuHere !== null && $mtuThere !== null && $mtuHere !== $mtuThere) {
                    $this->add(new Finding(
                        Finding::CRITICAL,
                        'ospf_mtu_mismatch',
                        "OSPF adjacency $pair stuck in " . self::label($state) . ": MTU $mtuHere vs $mtuThere",
                        "The routers start the database exchange but never finish it: $seen. The interface MTUs differ ($mtuHere on "
                        . $this->name($first->device) . ", $mtuThere on " . $this->name((int) $first->remoteDevice) . '), so the larger database packets of one side are dropped by the other.',
                        'Set the same IP MTU on both interfaces. ip ospf mtu-ignore brings the adjacency up but large packets are still dropped.',
                        null,
                        $devices,
                        $ports,
                    ), [$first->vrf]);

                    continue;
                }
                $mtuText = $mtuHere !== null && $mtuHere === $mtuThere ? " The interface MTUs match ($mtuHere): compare the IP MTU, which can differ from the interface MTU LibreNMS reports." : '';
                $this->stuck($first, $pair, 'stuck in ' . self::label($state),
                    "The database exchange does not finish: $seen. This is nearly always an MTU mismatch, less often a duplicate router ID.$mtuText$down",
                    'Make the IP MTU of both interfaces equal (ip mtu / mtu); ip ospf mtu-ignore hides the mismatch but large packets are still dropped.',
                    $devices, $ports);
            } elseif ($state === 'init') {
                $this->stuck($first, $pair, 'stuck in Init',
                    "Hellos arrive from the neighbor, but it does not see this router's hellos: $seen. Hellos are lost in one direction (ACL, multicast filtering, a one-way link) or rejected (authentication, area, timers or subnet differ).$down",
                    'Check ACLs and multicast (224.0.0.5) on both ends, then compare the OSPF settings of both interfaces.',
                    $devices, $ports);
            } elseif ($state === 'loading') {
                $this->stuck($first, $pair, 'stuck in Loading',
                    "Link-state requests are not answered: $seen. LSAs are lost or rejected on the way (MTU, a corrupted or oversized LSA).$down",
                    'Check the MTU and the interface errors on both ends, and the logs of both routers for rejected LSAs.',
                    $devices, $ports);
            } elseif ($state === 'twoway') {
                $this->stuck($first, $pair, 'stays in 2-Way on a point-to-point interface',
                    "The routers see each other but never exchange their databases: $seen.$down",
                    'Use the same network type (point-to-point) on both ends; a DR election on one side keeps the other in 2-Way.',
                    $devices, $ports);
            } else {
                $this->stuck($first, $pair, 'is ' . self::label($state),
                    "No hellos arrive from this configured neighbor: $seen.$down",
                    'Check that the neighbor is up and reachable, and that the configured neighbor address is still right.',
                    $devices, $ports);
            }
        }
    }

    /**
     * Finding of an adjacency that does not reach Full.
     *
     * @param  int[]  $devices
     * @param  int[]  $ports
     */
    private function stuck(OspfNeighbor $neighbor, string $pair, string $what, string $detail, string $fix, array $devices, array $ports): void
    {
        $this->add(new Finding(Finding::WARNING, 'ospf_neighbor_stuck', "OSPF adjacency $pair $what", $detail, $fix, null, $devices, $ports), [$neighbor->vrf]);
    }

    /**
     * Interfaces on one link must agree on hello / dead intervals, area, authentication and network
     * type; costs that differ per direction make routing asymmetric; and a point-to-point link (/30,
     * /31, /126, /127) with the broadcast network type elects a DR for nothing. The interfaces of a
     * subnet are only compared where they are on one link (see links()).
     */
    private function checkInterfaces(): void
    {
        $subnets = [];
        $pairs = [];
        foreach ($this->interfaces as $interface) {
            if ($interface->address !== null && ! $interface->address->host) {
                $subnets[$key = $interface->address->key()][] = $interface;
                if (self::pointToPoint($interface->address)) {
                    $pairs[$key] = [];
                }
            }
        }
        // the addresses in each point-to-point subnet of the devices that are up, out of all addresses
        foreach ($pairs ? $this->byDevice : [] as $deviceId => $addresses) {
            foreach ($addresses as $address) {
                if (self::pointToPoint($address) && isset($pairs[$key = $address->key()]) && $this->isUp($deviceId)) {
                    $pairs[$key][$deviceId][$address->packed] = true;
                }
            }
        }

        foreach ($subnets as $key => $interfaces) {
            foreach ($this->links($interfaces, self::twoEnds($pairs[$key] ?? [])) as $link) {
                $this->compareInterfaces($link);
            }
        }
    }

    /**
     * The interfaces of one subnet grouped by link: two interfaces are on one link when their routers
     * are in one OSPF domain or the interfaces face each other (faces()), and all are when the subnet
     * is a point-to-point one whose two addresses two devices hold ($pair, see twoEnds(): the two ends
     * of a circuit without LLDP and without an adjacency, e.g. because of the mismatch). Networks that
     * never meet reuse subnets, so their interfaces are not compared.
     *
     * @param  list<OspfInterface>  $interfaces
     * @return list<list<OspfInterface>>
     */
    private function links(array $interfaces, bool $pair = false): array
    {
        if (count($interfaces) === 1 || $pair) {
            return [$interfaces];
        }

        $parent = array_keys($interfaces);
        $find = function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $i = $parent[$i] = $parent[$parent[$i]];
            }

            return $i;
        };
        $join = function (int $i, int $j) use (&$parent, $find): void {
            $parent[$find($i)] = $find($j);
        };

        $byDomain = [];
        $byDevice = [];
        foreach ($interfaces as $i => $interface) {
            $domain = $this->domains[$interface->device . '|' . $interface->vrf] ?? null;
            if ($domain !== null && isset($byDomain[$domain])) {
                $join($i, $byDomain[$domain]);
            } elseif ($domain !== null) {
                $byDomain[$domain] = $i;
            }
            $byDevice[$interface->device][] = $i;
        }
        foreach ($interfaces as $i => $interface) {
            foreach ($byDevice as $deviceId => $indexes) {
                if (! isset($this->adjacent[$interface->device][$deviceId])) {
                    continue;
                }
                foreach ($indexes as $j) {
                    if ($j > $i && $this->faces($interface->address, $interfaces[$j]->address)) {
                        $join($i, $j);
                    }
                }
            }
        }

        $links = [];
        foreach ($interfaces as $i => $interface) {
            $links[$find($i)][] = $interface;
        }

        return array_values($links);
    }

    /**
     * Compare the OSPF interfaces of one link.
     *
     * @param  list<OspfInterface>  $interfaces
     */
    private function compareInterfaces(array $interfaces): void
    {
        $network = $interfaces[0]->address->network();
        $vrfs = array_values(array_unique(array_map(fn ($i) => $i->vrf, $interfaces)));
        $where = $network . ($vrfs !== [''] ? ' (VRF ' . implode(' / ', array_map(fn ($vrf) => $vrf === '' ? 'global' : $vrf, $vrfs)) . ')' : '');
        $devices = array_values(array_unique(array_map(fn ($i) => $i->device, $interfaces)));
        $ports = array_values(array_filter(array_map(fn ($i) => $i->portId, $interfaces)));

        if (count($devices) >= 2) {
            $timers = $this->differing($interfaces, fn ($i) => $i->hello === null && $i->dead === null ? null : ($i->hello ?? '?') . '/' . ($i->dead ?? '?') . 's');
            if ($timers) {
                $this->add(new Finding(Finding::CRITICAL, 'ospf_timer_mismatch',
                    "OSPF hello / dead intervals differ on $where: $timers",
                    'Routers only form an adjacency when their hello and dead intervals are equal, so these routers cannot become neighbors on this link.',
                    'Use the same intervals on every interface of the link (ip ospf hello-interval / dead-interval), or the defaults everywhere.',
                    null, $devices, $ports), $vrfs);
            }
            $areas = $this->differing($interfaces, fn ($i) => $i->area === '' ? null : 'area ' . $i->area);
            if ($areas) {
                $this->add(new Finding(Finding::CRITICAL, 'ospf_area_mismatch',
                    "OSPF area differs on $where: $areas",
                    'Interfaces on one link must be in the same area; hellos from another area are ignored, so no adjacency forms.',
                    'Put every interface of the link in the same area.',
                    null, $devices, $ports), $vrfs);
            }
            $auth = $this->differing($interfaces, fn ($i) => $i->auth === '' ? null : self::label($i->auth));
            if ($auth) {
                $this->add(new Finding(Finding::CRITICAL, 'ospf_auth_mismatch',
                    "OSPF authentication differs on $where: $auth",
                    'Hellos with another authentication type are dropped, so no adjacency forms.',
                    'Configure the same authentication type and key on every interface of the link (or in the area).',
                    null, $devices, $ports), $vrfs);
            }
            $types = $this->differing($interfaces, fn ($i) => $i->type === '' ? null : self::label($i->type));
            if ($types) {
                $this->add(new Finding(Finding::WARNING, 'ospf_network_type_mismatch',
                    "OSPF network type differs on $where: $types",
                    'With different network types the routers describe the link differently (a transit network on one side, a point-to-point link on the other): adjacencies can form while routes over the link are missing, and the default timers differ.',
                    'Use the same network type on every interface of the link, point-to-point for a link between two routers.',
                    null, $devices, $ports), $vrfs);
            }
            $costs = array_values(array_filter($interfaces, fn ($i) => $i->cost !== null));
            if (count($interfaces) === 2 && count($devices) === 2 && count($costs) === 2 && $costs[0]->cost !== $costs[1]->cost) {
                $this->add(new Finding(Finding::INFO, 'ospf_cost_asymmetric',
                    "OSPF cost differs per direction on $where: " . $this->values($costs, fn ($i) => (string) $i->cost),
                    'Traffic between ' . $this->names($devices) . ' can take different paths in each direction: stateful firewalls drop the half they do not see, and troubleshooting gets harder.',
                    'Use the same cost on both interfaces (ip ospf cost), unless the asymmetry is on purpose.',
                    null, $devices, $ports), $vrfs);
            }
        }

        // only prefixes with room for two addresses (a LAN with two routers also has hosts), and only
        // where OSPF talks to a neighbor: a passive interface elects itself without consequence
        $p2p = self::pointToPoint($interfaces[0]->address);
        $talking = count($devices) >= 2 || array_filter($interfaces, fn ($i) => $i->talking);
        $elected = array_filter($interfaces, fn ($i) => in_array($i->type, ['broadcast', 'nbma'], true)
            && (in_array($i->state, ['designatedrouter', 'backupdesignatedrouter', 'otherdesignatedrouter'], true) || ($i->dr !== null && $i->dr !== '0.0.0.0')));
        if ($p2p && $talking && $elected) {
            $this->add(new Finding(Finding::INFO, 'ospf_dr_on_p2p',
                "DR election on the point-to-point link $where: " . $this->values($interfaces, fn ($i) => self::label($i->type)),
                'Only two routers share this subnet, but the broadcast network type makes them elect a designated router: the adjacency comes up slower and the link adds a network LSA.',
                'Set the network type to point-to-point on both ends (ip ospf network point-to-point; Junos: interface-type p2p).',
                null, $devices, $ports), $vrfs);
        }
    }

    /**
     * Routers of one area and one OSPF domain must agree on the area type (stub / NSSA) and, once
     * converged, hold the same link-state database. The LSA checksum sum is not compared: it changes
     * whenever an LSA is refreshed (every 30 minutes each), so routers polled at different moments
     * rarely agree on it.
     */
    private function checkAreas(): void
    {
        $groups = [];
        foreach ($this->areas as [$deviceId, $vrf, $area, $count, $type]) {
            $key = $deviceId . '|' . $vrf;
            $groups[($this->domains[$key] ?? $key) . '|' . $area][] = ['device_id' => $deviceId, 'vrf' => $vrf, 'area' => $area, 'count' => $count, 'type' => $type];
        }

        foreach ($groups as $routers) {
            $devices = array_values(array_unique(array_column($routers, 'device_id')));
            if (count($devices) < 2) {
                continue;
            }
            $area = $routers[0]['area'];
            $vrfs = array_values(array_unique(array_column($routers, 'vrf')));

            $types = $this->differing($routers, fn ($r) => $r['type'] === '' ? null : self::label($r['type']), false);
            if ($types) {
                $this->add(new Finding(Finding::CRITICAL, 'ospf_area_type_mismatch',
                    "OSPF area $area type differs: $types",
                    'Routers only become neighbors when they agree on the area type (the stub / NSSA flags in their hellos), so the routers with the other type are cut off from the area.',
                    'Configure the area as stub / NSSA on every router of the area, or on none.',
                    null, $devices), $vrfs);
            }

            $known = array_values(array_filter($routers, fn ($r) => $r['count'] !== null));
            $counts = array_column($known, 'count');
            if (count($counts) >= 2 && max($counts) - min($counts) > max(1, (int) floor(max($counts) * self::LSDB_TOLERANCE))) {
                $this->add(new Finding(Finding::WARNING, 'ospf_lsdb_mismatch',
                    "OSPF area $area: the link-state databases differ (" . $this->values($known, fn ($r) => $r['count'] . ' LSAs', false) . ')',
                    'Routers of one area hold the same LSAs once the area has converged. LibreNMS polls them minutes apart, so a difference can be a change in progress; if it persists over several polls, LSAs are not flooded between these routers.',
                    'If it persists, look for adjacencies that are not Full, MTU problems or LSA filtering between these routers, and compare their OSPF databases.',
                    null, $devices), $vrfs);
            }
        }
    }

    // ---------------------------------------------------------------- BGP checks

    /**
     * Sessions that are not established (unless shut down on purpose): the cause when LibreNMS
     * can tell (remote AS mismatch, no neighbor on the other router), else the last error as text.
     * Established sessions that were reset recently or accept no prefixes are reported too.
     * Sessions in a state outside the BGP state machine ("unknown") carry no data.
     */
    private function checkSessions(): void
    {
        $window = $this->settings->int('routing_bgp_reset_hours', 0, 8760) * 3600;
        foreach ($this->peers as $peer) {
            if ($peer->shut || ! $peer->known()) {
                continue;
            }
            $devices = array_values(array_unique(array_filter([$peer->device, $peer->remoteDevice], fn ($d) => $d !== null)));
            $ports = array_values(array_filter([$peer->local?->portId, $peer->remote?->portId]));
            $session = $this->session($peer);

            if ($peer->established()) {
                $this->checkReset($peer, $session, $window, $devices, $ports);
                $this->checkNoPrefixes($peer, $session, $devices, $ports);

                continue;
            }

            if (! $this->checkRemoteAs($peer, $session, $devices, $ports) && ! $this->checkOneSided($peer, $session, $devices, $ports)) {
                $this->sessionDown($peer, $session, $devices, $ports);
            }
        }
    }

    /**
     * A session that is down with its last error as text and what usually causes it.
     *
     * @param  int[]  $devices
     * @param  int[]  $ports
     */
    private function sessionDown(BgpSession $peer, string $session, array $devices, array $ports): void
    {
        $error = $peer->error();
        $detail = match (true) {
            $error !== null => 'Last error: ' . $error['text'] . ($error['cause'] !== '' ? ': ' . $error['cause'] : '') . '.',
            $peer->time > 0 => 'The session went down without a NOTIFICATION: the TCP connection was closed or timed out (the path or the peer failed, or the peer restarted).',
            default => 'No error was exchanged: the TCP connection to ' . $peer->ip . ' never came up.',
        };
        if ($peer->errorText !== '') {
            $detail .= ' The router says: "' . $peer->errorText . '".';
        }
        $detail .= $peer->time > 0 ? ' Last established ' . StpAnalyzer::duration($peer->time) . ' ago.' : '';
        $remote = $peer->remoteDevice;
        if ($remote !== null && ! $this->isUp($remote)) {
            $detail .= ' ' . $this->name($remote) . ' itself is down.';
        } elseif ($remote !== null && ! isset($this->peersOf[$remote])) {
            $detail .= ' ' . $this->name($remote) . ' reports no BGP sessions: BGP is not configured there, or its bgp-peers module is off.';
        } elseif ($remote !== null && $peer->remote !== null && ! $this->hasSessionsIn($remote, $peer->remote->vrf)) {
            $detail .= ' ' . $this->name($remote) . ' reports no BGP sessions in VRF ' . $peer->remote->vrf . ' (LibreNMS only polls the BGP sessions of a VRF with an SNMP context).';
        }
        $local = $peer->local?->ip() ?? 'this router';

        $this->add(new Finding(
            $error !== null && $error['code'] === 6 && $error['subcode'] === 2 ? Finding::INFO : Finding::WARNING,
            'bgp_session_down',
            'BGP session ' . $session . ' is ' . self::label($peer->state) . ($error ? ': ' . explode(' (', $error['text'])[0] : ''),
            $detail,
            match (true) {
                $error !== null && $error['code'] === 6 && $error['subcode'] === 3 => 'The neighbor was removed on the other router: remove it here too, or configure it again there.',
                $error !== null && $error['code'] === 6 && $error['subcode'] === 2 => 'If the session should be up, enable it again on the router that shut it down.',
                $error !== null => 'Fix the cause above, then reset the session.',
                $peer->time > 0 => "Check the path between $local and {$peer->ip} and the peer itself: the session worked until " . StpAnalyzer::duration($peer->time) . ' ago.',
                default => "Check that {$peer->ip} is reachable from $local, that TCP port 179 is allowed in between, that the peer has a neighbor for $local, and for eBGP between loopbacks that ebgp-multihop and update-source are set.",
            },
            null,
            $devices,
            $ports,
        ), [$peer->vrf]);
    }

    /**
     * A session that is not up because the remote AS configured here is not the AS of the
     * (monitored) peer. Only when the peer was rejected for it (Bad Peer AS on either side) or the
     * session never came up: one that worked before presents another AS on purpose (local-as, a
     * confederation). AS_TRANS (23456) is never compared.
     *
     * @param  int[]  $devices
     * @param  int[]  $ports
     */
    private function checkRemoteAs(BgpSession $peer, string $session, array $devices, array $ports): bool
    {
        if ($peer->remoteDevice === null || $peer->remoteAs === null || $peer->remoteAs === self::AS_TRANS) {
            return false;
        }
        $peerAs = $this->localAs($peer->remoteDevice, $peer->remote->vrf ?? $peer->vrf);
        if ($peerAs === null || $peerAs === self::AS_TRANS || $peerAs === $peer->remoteAs) {
            return false;
        }
        $reverse = $this->reverse($peer);
        $rejected = $peer->hasError(2, 2) || ($reverse !== null && $reverse->hasError(2, 2));
        if (! $rejected && $peer->time > 0) {
            return false;
        }

        $here = $this->name($peer->device);
        $there = $this->name($peer->remoteDevice);
        $error = $peer->error();
        $this->add(new Finding(
            Finding::CRITICAL,
            'bgp_remote_as_mismatch',
            "BGP session $session: remote AS {$peer->remoteAs} configured, $there is AS $peerAs",
            ($rejected
                ? "The session cannot come up: $there opens it with AS $peerAs, which $here rejects (OPEN Message Error / Bad Peer AS)."
                : "The session has never come up, and $there is AS $peerAs: $here rejects its OPEN (Bad Peer AS) unless $there presents AS {$peer->remoteAs} to it (local-as, a confederation).")
            . ($error !== null && ! $peer->hasError(2, 2) ? ' Last error: ' . $error['text'] . '.' : ''),
            "Correct the remote AS of the neighbor on $here (or the local AS of $there).",
            null,
            $devices,
            $ports,
        ), [$peer->vrf]);

        return true;
    }

    /**
     * A session that never came up because the monitored peer has no neighbor for any address of
     * this router. Only when the peer's sessions in the VRF of the peer address are polled (the
     * sessions of a VRF need an SNMP context), and not for a session that was up before.
     *
     * @param  int[]  $devices
     * @param  int[]  $ports
     */
    private function checkOneSided(BgpSession $peer, string $session, array $devices, array $ports): bool
    {
        $remote = $peer->remoteDevice;
        if ($remote === null || $remote === $peer->device || $peer->time > 0 || $peer->remote === null || ! $this->isUp($remote)
            || ! $this->hasSessionsIn($remote, $peer->remote->vrf) || $this->reverse($peer) !== null) {
            return false;
        }

        $local = $peer->local?->ip() ?? $this->name($peer->device);
        $this->add(new Finding(
            Finding::WARNING,
            'bgp_one_sided',
            "BGP session $session is configured on " . $this->name($peer->device) . ' only',
            $this->name($remote) . ' has BGP sessions' . ($peer->remote->vrf !== '' ? ' in VRF ' . $peer->remote->vrf : '') . ', but none to an address of ' . $this->name($peer->device)
            . ', so this session can never come up (it is ' . self::label($peer->state) . ' and was never established).',
            'Add the neighbor ' . $local . ' on ' . $this->name($remote) . ', or remove the neighbor ' . $peer->ip . ' from ' . $this->name($peer->device) . '.',
            null,
            $devices,
            $ports,
        ), [$peer->vrf]);

        return true;
    }

    /**
     * An established session that came up less than the window ago: it was reset. Resets caused by
     * a restart of the router itself are told apart (uptime), protocol errors are warnings.
     *
     * @param  int[]  $devices
     * @param  int[]  $ports
     */
    private function checkReset(BgpSession $peer, string $session, int $window, array $devices, array $ports): void
    {
        if ($window <= 0 || $peer->time <= 0 || $peer->time >= $window) {
            return;
        }
        $uptime = (int) ($this->devices[$peer->device]['uptime'] ?? 0);
        $restart = $uptime > 0 && $uptime <= $peer->time + self::RESTART_MARGIN;
        $error = $peer->error();
        $planned = $error !== null && (BgpErrors::administrative($error['code'], $error['subcode']) || $peer->hasError(6, 7));
        $protocol = $error !== null && ! $planned;

        $detail = 'Up for ' . StpAnalyzer::duration($peer->time) . '.';
        if ($restart) {
            $detail .= ' ' . $this->name($peer->device) . ' itself restarted ' . StpAnalyzer::duration($uptime) . ' ago.';
        } elseif ($error !== null) {
            $detail .= ' Last error: ' . $error['text'] . ($error['cause'] !== '' ? ': ' . $error['cause'] : '') . '.'
                . ' (Routers keep the last error until the next one, so it can be from an earlier reset.)';
        } else {
            $detail .= ' No error was recorded (the TCP connection was closed, or the peer restarted).';
        }

        $this->add(new Finding(
            $protocol && ! $restart ? Finding::WARNING : Finding::INFO,
            'bgp_recent_reset',
            "BGP session $session was reset " . StpAnalyzer::duration($peer->time) . ' ago',
            $detail,
            match (true) {
                $restart => 'Nothing to do if the restart was planned.',
                $planned => 'Nothing to do if the reset was planned.',
                default => 'If it happens again, follow the cause above: repeated resets point to an unstable path or peer.',
            },
            null,
            $devices,
            $ports,
        ), [$peer->vrf]);
    }

    /**
     * An established session that accepts no prefixes in any address family (only when the router
     * reports prefix counters, bgpPeers_cbgp).
     *
     * @param  int[]  $devices
     * @param  int[]  $ports
     */
    private function checkNoPrefixes(BgpSession $peer, string $session, array $devices, array $ports): void
    {
        if (! $peer->prefixes || array_sum(array_column($peer->prefixes, 'accepted')) > 0) {
            return;
        }
        $denied = array_sum(array_column($peer->prefixes, 'denied'));
        $this->add(new Finding(
            Finding::INFO,
            'bgp_no_prefixes',
            "BGP session $session is up but accepts no prefixes",
            $denied > 0
                ? "All $denied prefixes it received were denied by the inbound policy."
                : 'The peer sends nothing, or nothing passes the inbound policy. Peers that only receive routes look like this too.',
            'Check the inbound route-map / prefix-list on ' . $this->name($peer->device) . ' and what the peer advertises.',
            null,
            $devices,
            $ports,
        ), [$peer->vrf]);
    }

    /**
     * Accepted prefixes at or near the maximum configured for the peer (bgpPeers_cbgp), per
     * address family: at the limit the router tears the session down or stops accepting routes.
     * A warning share of 0 only reports peers at the limit.
     */
    private function checkPrefixLimits(): void
    {
        $share = $this->settings->int('routing_prefix_warn_percent', 0, 100) ?: 100;
        foreach ($this->peers as $peer) {
            if ($peer->shut) {
                continue;
            }
            foreach ($peer->prefixes as $counters) {
                if ($counters['limit'] <= 0 || $counters['accepted'] * 100 < $counters['limit'] * $share) {
                    continue;
                }
                $percent = (int) floor($counters['accepted'] * 100 / $counters['limit']);
                $this->add(new Finding(
                    $counters['accepted'] >= $counters['limit'] ? Finding::CRITICAL : Finding::WARNING,
                    'bgp_prefix_limit',
                    'BGP session ' . $this->session($peer) . " is at $percent% of its prefix limit (" . $counters['family'] . ': '
                    . number_format($counters['accepted']) . ' of ' . number_format($counters['limit']) . ')',
                    'At the limit the router tears the session down or stops accepting routes, depending on its configuration.'
                    . ($counters['threshold'] > 0 ? ' The router\'s own warning threshold is ' . $counters['threshold'] . '%.' : ''),
                    'Raise the maximum-prefix limit with headroom, or ask the peer to aggregate or filter what it sends.',
                    null,
                    array_values(array_unique(array_filter([$peer->device, $peer->remoteDevice], fn ($d) => $d !== null))),
                    array_values(array_filter([$peer->local?->portId])),
                ), [$peer->vrf]);
            }
        }
    }

    // ---------------------------------------------------------------- IP checks

    /**
     * The same address in one VRF on several devices, on interfaces that are up of devices that are
     * up (a down port does not answer for its address, a down device's addresses are from before it
     * went down); host-local bridges (virbr0, docker0...) are left out. When each device also has its
     * own address in the subnet it is probably a shared gateway (VRRP, HSRP, anycast gateway) reported
     * as an interface address; a loopback or host address, or one on three or more devices, is
     * probably anycast. When no two of the devices are in one network of the routing table
     * (network()), they may be separate networks that reuse the address: info.
     */
    private function checkDuplicates(): void
    {
        foreach ($this->byIp as $entries) {
            if (! is_array($entries)) {
                continue;
            }
            $groups = [];
            foreach ($entries as $address) {
                if ($address->up && $this->isUp($address->device) && ! preg_match(self::HOST_LOCAL, $address->port)) {
                    $groups[strtolower($address->vrf)][] = $address;
                }
            }

            foreach ($groups as $vrf => $rows) {
                $devices = array_values(array_unique(array_map(fn ($a) => $a->device, $rows)));
                $first = $rows[0];
                if (count($devices) < 2 || $this->isIgnored($first->ip())) {
                    continue;
                }
                $separate = count(array_unique(array_map(fn ($deviceId) => $this->network($deviceId, (string) $vrf), $devices))) === count($devices);
                $shared = ! $first->host && count($devices) === 2 && array_reduce($devices, fn ($all, $deviceId) => $all && $this->hasOtherAddress($deviceId, $first), true);
                $anycast = count($devices) > 2 || $first->host;
                $where = $first->vrf !== '' ? ' (VRF ' . $first->vrf . ')' : '';
                $this->add(new Finding(
                    $shared || $anycast || $separate ? Finding::INFO : Finding::WARNING,
                    'ip_duplicate',
                    'duplicate IP address ' . $first->ip() . $where . ' on ' . implode(', ', array_map(fn ($a) => $this->end($a->device, $a->port), $rows)),
                    match (true) {
                        $shared => 'Both devices also have their own address in ' . $first->network() . ', so this is probably a shared gateway address (VRRP, HSRP or an anycast gateway) that the devices report as an interface address.',
                        $anycast => 'The address is on ' . (count($devices) > 2 ? count($devices) . ' devices' : 'a loopback or as a host address on two devices') . ': probably an anycast address on purpose.',
                        $separate => 'LibreNMS sees no link between these devices in this routing table (LLDP/CDP, OSPF): they may be separate networks that reuse the address (customers, sites, VRFs), then it is no problem. If they are connected through devices LibreNMS does not monitor, both answer for the address and connections break at random.',
                        default => 'Both devices answer for the address: traffic to it reaches whichever device answered ARP / neighbor discovery last, so connections break at random. The L3 map shows only one of them.',
                    },
                    match (true) {
                        $shared || $anycast => 'If the address is shared on purpose, add it to the ignored addresses in the routing settings.',
                        $separate => 'If the networks are connected, give one of the interfaces another address; if they are separate, add the address to the ignored addresses in the routing settings.',
                        default => 'Give one of the interfaces another address.',
                    },
                    null,
                    $devices,
                    array_values(array_filter(array_map(fn ($a) => $a->portId, $rows))),
                ), array_values(array_unique(array_map(fn ($a) => $a->vrf, $rows))));
            }
        }
    }

    /**
     * Two addresses that face each other (faces()) with different prefix lengths, where each lies in
     * the other's subnet (so both consider the other on-link) while the subnets differ. Host routes
     * and loopbacks are left out, and so are devices that are down.
     */
    private function checkMasks(): void
    {
        // subnets of the devices that have neighbors, and the prefix lengths of each such device
        $subnets = [];
        $prefixesOf = [];
        foreach ($this->byDevice as $deviceId => $addresses) {
            if (! isset($this->adjacent[$deviceId]) || ! $this->isUp($deviceId)) {
                continue;
            }
            foreach ($addresses as $address) {
                if ($address->host) {
                    continue;
                }
                $key = $address->key();
                if (! isset($subnets[$key])) {
                    $subnets[$key] = $address;
                } elseif (is_array($subnets[$key])) {
                    $subnets[$key][] = $address;
                } else {
                    $subnets[$key] = [$subnets[$key], $address];
                }
                $prefixesOf[$deviceId][strlen($address->packed)][$address->prefix] = true;
            }
        }

        $reported = [];
        $longer = [];
        foreach ($subnets as $entries) {
            foreach (is_array($entries) ? $entries : [$entries] as $short) {
                // prefix lengths of the neighbors' addresses
                $bytes = strlen($short->packed);
                if (! isset($longer[$short->device][$bytes])) {
                    $longer[$short->device][$bytes] = [];
                    foreach (array_keys($this->adjacent[$short->device]) as $neighbor) {
                        $longer[$short->device][$bytes] += $prefixesOf[$neighbor][$bytes] ?? [];
                    }
                }
                foreach (array_keys($longer[$short->device][$bytes]) as $prefix) {
                    if ($prefix <= $short->prefix || ! isset($subnets[$short->key($prefix)])) {
                        continue;
                    }
                    $found = $subnets[$short->key($prefix)];
                    foreach (is_array($found) ? $found : [$found] as $long) {
                        $pair = $long->packed . '|' . $short->packed . '|' . $long->device . '|' . $short->device;
                        if ($long->packed === $short->packed || isset($reported[$pair]) || ! $this->faces($short, $long)
                            || $this->isIgnored($long->ip()) || $this->isIgnored($short->ip())) {
                            continue;
                        }
                        $reported[$pair] = true;
                        $this->add(new Finding(
                            Finding::WARNING,
                            'ip_mask_mismatch',
                            'Subnet mask mismatch: ' . $this->end($long->device, $long->port) . ' ' . $long->ip() . '/' . $long->prefix . ' and '
                            . $this->end($short->device, $short->port) . ' ' . $short->ip() . '/' . $short->prefix,
                            'Both addresses lie in ' . $long->network() . ', but ' . $this->name($short->device) . ' uses a /' . $short->prefix
                            . ': the devices disagree on which addresses are on the link, so traffic to the rest of ' . $short->network() . ' is sent to the wrong place.',
                            'Use the same prefix length on both ends.',
                            null,
                            [$long->device, $short->device],
                            array_values(array_filter([$long->portId, $short->portId])),
                        ), array_values(array_unique([$long->vrf, $short->vrf])));
                    }
                }
            }
        }
    }

    /**
     * A link that joins two differently named VRFs: the two ports of an LLDP/CDP link (with their
     * subinterfaces), or the two addresses of an OSPF adjacency, share a subnet but are in VRFs of
     * different names (compared without case). VRF names are local to each router, so this is only reported when both routers
     * have both VRFs (the global table is on every router): a provider edge facing a customer router,
     * or inter-AS option A with other names, joins VRFs on purpose.
     */
    private function checkVrfs(): void
    {
        $reported = [];
        foreach ($this->links as [, $portA, , $portB]) {
            foreach ($this->onLink[$portA] ?? [] as $a) {
                foreach ($this->onLink[$portB] ?? [] as $b) {
                    $this->joinedVrfs($a, $b, 'an LLDP/CDP link', $reported);
                }
            }
        }
        foreach ($this->neighbors as $neighbor) {
            if ($neighbor->local !== null && $neighbor->remote !== null) {
                $this->joinedVrfs($neighbor->local, $neighbor->remote, 'an OSPF adjacency', $reported);
            }
        }
    }

    /**
     * Report two addresses facing each other in differently named VRFs (see checkVrfs()).
     *
     * @param  array<string, true>  $reported
     */
    private function joinedVrfs(Address $a, Address $b, string $via, array &$reported): void
    {
        $vrfA = strtolower($a->vrf);
        $vrfB = strtolower($b->vrf);
        if ($vrfA === $vrfB || $a->device === $b->device || $a->host || $b->host || ! ($a->contains($b->packed) || $b->contains($a->packed))
            || ! $this->hasVrf($a->device, $vrfB) || ! $this->hasVrf($b->device, $vrfA) || ! $this->isUp($a->device) || ! $this->isUp($b->device)) {
            return;
        }
        if (strcmp($this->name($a->device), $this->name($b->device)) > 0) {
            [$a, $b] = [$b, $a];
        }
        $key = $a->device . '|' . $a->packed . '|' . $b->device . '|' . $b->packed;
        if (isset($reported[$key]) || $this->isIgnored($a->ip()) || $this->isIgnored($b->ip())) {
            return;
        }
        $reported[$key] = true;

        $table = fn (Address $address) => $address->vrf === '' ? 'the global table' : 'VRF ' . $address->vrf;
        $network = ($a->prefix <= $b->prefix ? $a : $b)->network();
        $this->add(new Finding(
            Finding::INFO,
            'ip_vrf_mismatch',
            $table($a) . ' on ' . $this->end($a->device, $a->port) . ' is joined to ' . $table($b) . ' on ' . $this->end($b->device, $b->port) . " ($network)",
            'The two interfaces face each other (' . $via . ') in ' . $network . ', but are in different routing tables, although '
            . $this->name($a->device) . ' and ' . $this->name($b->device) . ' both have ' . $table($a) . ' and ' . $table($b)
            . ': routes and traffic pass from one into the other here. VRF names are local to each router, so this can be on purpose (a route leak, inter-AS option A).',
            'If the VRFs should stay apart, put both interfaces in the same VRF.',
            null,
            [$a->device, $b->device],
            array_values(array_filter([$a->portId, $b->portId])),
        ), [$a->vrf, $b->vrf]);
    }

    // ---------------------------------------------------------------- tables

    /**
     * One row per OSPF router (per VRF): router ID, ABR / ASBR, areas and its adjacencies.
     *
     * @param  callable(array): bool  $keep
     */
    private function routerRows(callable $keep): array
    {
        $rows = [];
        foreach ($this->instances as $instance) {
            if (! $keep($instance)) {
                continue;
            }
            $areas = array_map('strval', array_keys($instance['areas']));
            sort($areas);
            $rows[] = [
                'device_id' => $instance['device_id'],
                'vrf' => $instance['vrf'],
                'router_id' => $instance['router_id'],
                'abr' => $instance['abr'],
                'asbr' => $instance['asbr'],
                'areas' => $areas,
                'interfaces' => $instance['interfaces'],
                'neighbors' => $instance['neighbors'],
                'full' => $instance['full'],
            ];
        }
        usort($rows, fn ($a, $b) => [$this->name($a['device_id']), $a['vrf']] <=> [$this->name($b['device_id']), $b['vrf']]);

        return $rows;
    }

    /**
     * One row per OSPF neighbor a router reports, problems first.
     *
     * @param  callable(OspfNeighbor): bool  $keep
     */
    private function neighborRows(callable $keep): array
    {
        $rows = [];
        foreach ($this->neighbors as $neighbor) {
            if (! $keep($neighbor)) {
                continue;
            }
            $rows[] = [
                'device_id' => $neighbor->device,
                'vrf' => $neighbor->vrf,
                'port_id' => $neighbor->local?->portId,
                'port' => $neighbor->local?->port,
                'area' => $neighbor->interface?->area,
                'ip' => $neighbor->ip,
                'router_id' => $neighbor->routerId,
                'remote_device' => $neighbor->remoteDevice,
                'remote_port_id' => $neighbor->remote?->portId,
                'state' => self::label($neighbor->state),
                'ok' => $neighbor->ok(),
            ];
        }
        usort($rows, fn ($a, $b) => [$a['ok'], $this->name($a['device_id']), $a['ip']] <=> [$b['ok'], $this->name($b['device_id']), $b['ip']]);

        return $rows;
    }

    /**
     * One row per BGP session, sessions that are not up first; prefix counters per address family.
     *
     * @param  callable(BgpSession): bool  $keep
     */
    private function sessionRows(callable $keep): array
    {
        $rows = [];
        foreach ($this->peers as $peer) {
            if (! $keep($peer)) {
                continue;
            }
            $rows[] = [
                'device_id' => $peer->device,
                'vrf' => $peer->vrf,
                'ip' => $peer->ip,
                'local_ip' => $peer->local?->ip(),
                'remote_as' => $peer->remoteAs,
                'type' => $peer->type(),
                'description' => $peer->description,
                'remote_device' => $peer->remoteDevice,
                'state' => $peer->shut ? 'shut down' : self::label($peer->state ?: 'unknown'),
                'established' => $peer->established(),
                'shut' => $peer->shut,
                'unknown' => ! $peer->known(),
                'time' => $peer->time ?: null,
                'error' => $peer->error()['text'] ?? null,
                'error_text' => $peer->errorText ?: null,
                'prefixes' => array_map(fn ($c) => ['family' => $c['family'], 'accepted' => $c['accepted'], 'limit' => $c['limit'] ?: null], $peer->prefixes),
            ];
        }
        $rank = fn ($r) => $r['established'] ? 2 : ($r['shut'] || $r['unknown'] ? 1 : 0);
        usort($rows, fn ($a, $b) => [$rank($a), $this->name($a['device_id']), $a['ip']] <=> [$rank($b), $this->name($b['device_id']), $b['ip']]);

        return $rows;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Device owning a (binary) address, other than $not, and its address row: among the addresses in
     * VRFs of the same name first, else in any VRF. Nobody when several devices have it, unless only
     * one of them is up (no guessing, as LibreNMS does for bgpPeerIface); the row is null when the
     * device has the address more than once (in several VRFs).
     *
     * @return array{0: ?int, 1: ?Address}
     */
    private function owner(string $packed, string $vrf, ?int $not = null): array
    {
        $entries = $this->byIp[$packed] ?? [];
        $entries = is_array($entries) ? $entries : [$entries];
        $vrf = strtolower($vrf);
        foreach ([true, false] as $sameVrf) {
            $found = [];
            foreach ($entries as $address) {
                if ($address->device !== $not && (! $sameVrf || strtolower($address->vrf) === $vrf)) {
                    $found[$address->device][] = $address;
                }
            }
            if (count($found) > 1) {
                $found = array_filter($found, fn ($deviceId) => $this->isUp($deviceId), ARRAY_FILTER_USE_KEY);
                if (count($found) !== 1) {
                    return [null, null];
                }
            }
            foreach ($found as $deviceId => $rows) {
                return [$deviceId, count($rows) === 1 ? $rows[0] : null];
            }
        }

        return [null, null];
    }

    /**
     * The device's own address row for a (binary) address, in the VRF first.
     */
    private function ownAddress(int $deviceId, string $packed, string $vrf): ?Address
    {
        $entries = $this->byIp[$packed] ?? [];
        $found = null;
        foreach (is_array($entries) ? $entries : [$entries] as $address) {
            if ($address->device === $deviceId && ($found === null || strtolower($address->vrf) === strtolower($vrf))) {
                $found = $address;
            }
        }

        return $found;
    }

    /**
     * The device's interface address whose subnet holds a (binary) address: the longest prefix, in
     * the VRF first.
     */
    private function facing(int $deviceId, string $packed, string $vrf): ?Address
    {
        $vrf = strtolower($vrf);
        $best = null;
        $bestScore = null;
        foreach ($this->byDevice[$deviceId] ?? [] as $address) {
            if ($address->host || ! $address->contains($packed)) {
                continue;
            }
            $score = [strtolower($address->vrf) === $vrf ? 1 : 0, $address->prefix];
            if ($bestScore === null || $score > $bestScore) {
                [$best, $bestScore] = [$address, $score];
            }
        }

        return $best;
    }

    /**
     * Do two addresses face each other? Their devices are neighbors (LLDP/CDP, OSPF) and their ports
     * (or the ports they are subinterfaces of) are the two ends of an LLDP/CDP link, an OSPF adjacency
     * joins them, or they are in VRFs of the same name that a link or adjacency joins (see joined()).
     */
    private function faces(?Address $a, ?Address $b): bool
    {
        if ($a === null || $b === null || $a->device === $b->device || ! isset($this->adjacent[$a->device][$b->device])) {
            return false;
        }
        $portA = $this->linkPort[$a->portId ?? 0] ?? $a->portId;
        $portB = $this->linkPort[$b->portId ?? 0] ?? $b->portId;

        return ($portA !== null && $portB !== null && isset($this->links[self::pair($portA, $portB)]))
            || isset($this->ospfPeers[$a->device][$b->packed]) || isset($this->ospfPeers[$b->device][$a->packed])
            || (strtolower($a->vrf) === strtolower($b->vrf) && $this->joined($a->device, $b->device, strtolower($a->vrf)));
    }

    /**
     * Are two devices directly joined in a routing table: an LLDP/CDP link that joins it (see
     * joinLinks()), or an OSPF adjacency between instances in VRFs of that name?
     */
    private function joined(int $a, int $b, string $vrf): bool
    {
        return isset($this->joined[$vrf][$a][$b]) || isset($this->joinedAny[$a][$b]);
    }

    /**
     * Network of a device in a routing table (a device id standing for it): the devices joined in it
     * (joined()), directly or through others. The networks of the links that join every table are made
     * once; each table's own links are merged on top of them (union-find), so a table costs only its own
     * links.
     */
    private function network(int $deviceId, string $vrf): int
    {
        if ($this->anyNetworks === null) {
            $parents = [];
            foreach ($this->joinedAny as $a => $neighbors) {
                foreach (array_keys($neighbors) as $b) {
                    self::union($parents, $a, $b);
                }
            }
            $this->anyNetworks = [];
            foreach (array_keys($parents) as $device) {
                $this->anyNetworks[$device] = self::find($parents, $device);
            }
        }
        if (! isset($this->tableNetworks[$vrf])) {
            $parents = [];
            foreach ($this->joined[$vrf] ?? [] as $a => $neighbors) {
                foreach (array_keys($neighbors) as $b) {
                    self::union($parents, $this->anyNetworks[$a] ?? $a, $this->anyNetworks[$b] ?? $b);
                }
            }
            $this->tableNetworks[$vrf] = $parents;
        }

        return self::find($this->tableNetworks[$vrf], $this->anyNetworks[$deviceId] ?? $deviceId);
    }

    /**
     * Root of a union-find tree (roots have no parent), halving the path on the way.
     *
     * @param  array<int, int>  $parents
     */
    private static function find(array &$parents, int $node): int
    {
        while (isset($parents[$node])) {
            $node = $parents[$node] = $parents[$parents[$node]] ?? $parents[$node];
        }

        return $node;
    }

    /**
     * @param  array<int, int>  $parents
     */
    private static function union(array &$parents, int $a, int $b): void
    {
        $a = self::find($parents, $a);
        $b = self::find($parents, $b);
        if ($a !== $b) {
            $parents[$a] = $b;
        }
    }

    /**
     * The peer's own session back to this router: a session of the peer device to an address of this
     * device, the one to this session's local address first.
     */
    private function reverse(BgpSession $peer): ?BgpSession
    {
        $found = null;
        foreach ($this->peersOf[$peer->remoteDevice] ?? [] as $other) {
            $packed = (string) inet_pton($other->ip);
            if ($peer->local !== null && $packed === $peer->local->packed) {
                return $other;
            }
            if ($found === null && $this->owns($peer->device, $packed)) {
                $found = $other;
            }
        }

        return $found;
    }

    /**
     * Does the device have this (binary) address on any port?
     */
    private function owns(int $deviceId, string $packed): bool
    {
        $entries = $this->byIp[$packed] ?? [];
        foreach (is_array($entries) ? $entries : [$entries] as $address) {
            if ($address->device === $deviceId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the device report BGP sessions in this VRF?
     */
    private function hasSessionsIn(int $deviceId, string $vrf): bool
    {
        foreach ($this->peersOf[$deviceId] ?? [] as $session) {
            if (strtolower($session->vrf) === strtolower($vrf)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the device have a VRF of this (lowercase) name? Every device has the global table ('').
     */
    private function hasVrf(int $deviceId, string $vrf): bool
    {
        return $vrf === '' || isset($this->vrfsOf[$deviceId][$vrf]);
    }

    /**
     * Device of the OSPF router with this router ID when there is exactly one other than $not
     * (neighbors without a known address, e.g. on unnumbered links).
     */
    private function routerWithId(string $routerId, int $not): ?int
    {
        $found = array_diff_key($this->routersWithId[$routerId] ?? [], [$not => true]);

        return count($found) === 1 ? (int) array_key_first($found) : null;
    }

    /**
     * OSPF instance key of a router in a VRF, or its only instance.
     */
    private function instanceKey(int $deviceId, string $vrf): ?string
    {
        if (isset($this->instances[$deviceId . '|' . $vrf])) {
            return $deviceId . '|' . $vrf;
        }
        $keys = $this->instancesOf[$deviceId] ?? [];

        return count($keys) === 1 ? $keys[0] : null;
    }

    /**
     * Local AS of a device for a VRF: the VRF's own AS when it has one, else the device's.
     */
    private function localAs(int $deviceId, string $vrf): ?int
    {
        return ($this->vrfAs[$deviceId][$vrf] ?? null) ?: ((int) ($this->devices[$deviceId]['bgpLocalAs'] ?? 0) ?: null);
    }

    /**
     * Does the device have another address than $address in the same subnet and VRF?
     */
    private function hasOtherAddress(int $deviceId, Address $address): bool
    {
        foreach ($this->byDevice[$deviceId] ?? [] as $other) {
            if ($other->packed !== $address->packed && $other->prefix === $address->prefix && strtolower($other->vrf) === strtolower($address->vrf)
                && $other->contains($address->packed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "core1 → 10.0.0.2 (AS 65001, ISP-A)" or "core1 → edge1 (10.0.0.2, AS 65001)" for a monitored peer.
     */
    private function session(BgpSession $peer): string
    {
        $details = array_filter([
            $peer->remoteAs !== null ? 'AS ' . $peer->remoteAs : null,
            $peer->description !== '' && $peer->remoteDevice === null ? $peer->description : null,
            $peer->vrf !== '' ? 'VRF ' . $peer->vrf : null,
        ]);
        $target = $peer->remoteDevice !== null ? $this->name($peer->remoteDevice) . ' (' . $peer->ip . ($details ? ', ' . implode(', ', $details) : '') . ')'
            : $peer->ip . ($details ? ' (' . implode(', ', $details) . ')' : '');

        return $this->name($peer->device) . ' → ' . $target;
    }

    /**
     * "core1 Te1/0/1: 10/40s, dist1 Te1/0/1: 5/20s" when the items do not all have the same value
     * (items without a value are left out), else ''.
     *
     * @param  list<OspfInterface|array>  $items  interfaces, or rows with a device_id
     * @param  callable(mixed): ?string  $value
     */
    private function differing(array $items, callable $value, bool $withPort = true): string
    {
        $known = array_values(array_filter($items, fn ($item) => $value($item) !== null));
        if (count(array_unique(array_map($value, $known))) < 2) {
            return '';
        }

        return $this->values($known, fn ($item) => (string) $value($item), $withPort);
    }

    /**
     * "core1 Te1/0/1: 10, dist1 Te1/0/1: 100".
     *
     * @param  list<OspfInterface|array>  $items  interfaces, or rows with a device_id
     * @param  callable(mixed): string  $value
     */
    private function values(array $items, callable $value, bool $withPort = true): string
    {
        return implode(', ', array_map(fn ($item) => $item instanceof OspfInterface
            ? $this->end($item->device, $withPort ? $item->port() : null) . ': ' . $value($item)
            : $this->end($item['device_id'], null) . ': ' . $value($item), $items));
    }

    private function end(int $deviceId, ?string $port): string
    {
        return $this->name($deviceId) . ($port !== null && $port !== '' ? ' ' . $port : '');
    }

    /**
     * @param  int[]  $devices
     */
    private function names(array $devices): string
    {
        $names = array_map(fn ($id) => $this->name($id), array_slice($devices, 0, 8));

        return implode(', ', $names) . (count($devices) > 8 ? ' and ' . (count($devices) - 8) . ' more' : '');
    }

    private function name(int $deviceId): string
    {
        $device = $this->devices[$deviceId] ?? [];

        return (string) (($device['display'] ?? '') ?: ($device['sysName'] ?? '') ?: ($device['hostname'] ?? '') ?: "device #$deviceId");
    }

    private function isUp(int $deviceId): bool
    {
        return isset($this->devices[$deviceId]) && ! empty($this->devices[$deviceId]['status']);
    }

    /**
     * @param  int[]  $devices
     */
    private function inScope(array $devices): bool
    {
        foreach ($devices as $deviceId) {
            if (isset($this->scope[$deviceId])) {
                return true;
            }
        }

        return false;
    }

    private function isIgnored(string $ip): bool
    {
        return $this->ignored && Cidr::containedInAny($this->ignored, $ip);
    }

    /**
     * Interface addresses of the reported devices (for the summary).
     */
    private function addressCount(?int $device, ?string $vrf): int
    {
        $count = 0;
        foreach ($device !== null ? [$device] : array_keys($this->scope) as $deviceId) {
            if (! isset($this->scope[$deviceId])) {
                continue;
            }
            foreach ($this->byDevice[$deviceId] ?? [] as $address) {
                $count += $vrf === null || $address->vrf === $vrf ? 1 : 0;
            }
        }

        return $count;
    }

    /**
     * VRF names of the devices and the data, for the filter.
     *
     * @return string[]
     */
    private function vrfList(): array
    {
        $names = array_map('strval', array_keys($this->vrfNames));
        sort($names);

        return $names;
    }

    /**
     * A VRF name of a device's data: shared between its rows, and recorded for the filter and as one
     * of the device's VRFs.
     */
    private function vrf(int $deviceId, string $name): string
    {
        if ($name !== '') {
            $this->vrfNames[$name] = true;
            $this->vrfsOf[$deviceId][strtolower($name)] = true;
        }

        return $this->intern($name);
    }

    /**
     * One copy of a string that many rows hold (VRF names, areas, states).
     */
    private function intern(string $value): string
    {
        return $this->strings[$value] ??= $value;
    }

    private function add(Finding $finding, array $vrfs): void
    {
        $code = $finding->code;
        if (! isset($this->disabled[$code]) && ! isset($this->disabled[self::CHECKS[$code] ?? ''])) {
            $this->findings[] = [$finding, array_values(array_unique(array_map('strval', $vrfs)))];
        }
    }

    /**
     * Are these the two ends of a point-to-point subnet: two devices with one address each, and
     * different ones (two customers' routers on the same PE-CE address are not)?
     *
     * @param  array<int, array<string, true>>  $holders  device => its addresses in the subnet
     */
    private static function twoEnds(array $holders): bool
    {
        if (count($holders) !== 2) {
            return false;
        }
        [$a, $b] = array_values($holders);

        return count($a) === 1 && count($b) === 1 && array_key_first($a) !== array_key_first($b);
    }

    /**
     * A subnet with room for two addresses: /30, /31 (IPv4) or /126, /127 (IPv6).
     */
    private static function pointToPoint(Address $address): bool
    {
        return in_array($address->prefix, strlen($address->packed) === 4 ? [30, 31] : [126, 127], true);
    }

    /**
     * The port a subinterface belongs to, by name ("Gi0/1" of "Gi0/1.100", "xe-0/0/1" of
     * "xe-0/0/1.0"), null for a name without a unit.
     */
    public static function parentPort(string $port): ?string
    {
        $dot = strpos($port, '.');

        return $dot === false || $dot === 0 ? null : substr($port, 0, $dot);
    }

    /**
     * Short name of one end's port of a links row ($end: local or remote), null when unknown.
     */
    public static function portName(array $link, string $end): ?string
    {
        $port = ['ifName' => $link[$end . '_ifName'] ?? null, 'ifDescr' => $link[$end . '_ifDescr'] ?? null];

        return $port['ifName'] || $port['ifDescr'] ? InterfaceName::short(InterfaceName::label($port)) : null;
    }

    /**
     * One integer for a pair of port ids, in either order.
     */
    private static function pair(int $a, int $b): int
    {
        return (min($a, $b) << 32) | max($a, $b);
    }

    /**
     * Link-local, loopback, unspecified and multicast addresses never describe the network (the
     * ranges of Cidr::isIgnorable(), compared in binary for speed).
     */
    public static function ignorable(string $packed): bool
    {
        static $ranges = null;
        if ($ranges === null) {
            $ranges = [];
            foreach (['127.0.0.0/8', '169.254.0.0/16', '0.0.0.0/8', '224.0.0.0/4', 'fe80::/10', '::1/128', '::/128', 'ff00::/8'] as $cidr) {
                [$network, $prefix] = explode('/', $cidr);
                $bytes = (string) inet_pton($network);
                $ranges[strlen($bytes)][] = [$bytes, Address::mask(strlen($bytes), (int) $prefix)];
            }
        }
        foreach ($ranges[strlen($packed)] ?? [] as [$network, $mask]) {
            if (($packed & $mask) === $network) {
                return true;
            }
        }

        return false;
    }

    /**
     * Canonical text of an IPv4 / IPv6 address, null when it is not one.
     */
    private static function ip(string $address): ?string
    {
        $packed = Address::pack($address);

        return $packed === null ? null : (string) inet_ntop($packed);
    }

    /**
     * Lowercase enumeration name from a stored name or number.
     *
     * @param  array<int, string>  $numbers
     */
    private static function enum(mixed $value, array $numbers): string
    {
        $value = trim((string) $value);
        if ($value !== '' && ctype_digit($value)) {
            return $numbers[(int) $value] ?? $value;
        }

        return strtolower(str_replace(['-', '_', ' '], '', $value));
    }

    private static function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Dotted area ID ("0" and "0.0.0.0" are the same area).
     */
    private static function area(mixed $value): string
    {
        $value = trim((string) $value);

        return ctype_digit($value) && $value !== '' ? long2ip((int) $value) : $value;
    }

    private static function validRouterId(string $routerId): bool
    {
        return $routerId !== '' && $routerId !== '0.0.0.0';
    }

    private static function label(string $value): string
    {
        return self::LABELS[$value] ?? $value;
    }

    /**
     * "IPv4 unicast" from the afi / safi of bgpPeers_cbgp.
     */
    private static function family(string $afi, string $safi): string
    {
        $afi = strtolower($afi);
        $name = match ($afi) {
            'ipv4', '1' => 'IPv4',
            'ipv6', '2' => 'IPv6',
            'l2vpn', '25' => 'L2VPN',
            default => $afi,
        };
        $safi = strtolower($safi);
        $sub = match ($safi) {
            '1', 'unicast' => 'unicast',
            '2', 'multicast' => 'multicast',
            '128', 'vpn', 'mplsvpn' => 'VPN',
            '70', 'evpn' => 'EVPN',
            default => $safi,
        };

        return trim("$name $sub");
    }
}
