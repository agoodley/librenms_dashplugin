<?php

namespace Agoodley\LibrenmsTopology\Topology;

use Agoodley\LibrenmsTopology\Support\Cidr;
use Agoodley\LibrenmsTopology\Support\Graph;

/**
 * Routing sessions between sites, for SiteBuilder. An OSPF adjacency or a BGP session between two
 * monitored routers at different sites is one connection per circuit (the subnet of an adjacency,
 * the two addresses of a session) with the state each router reports: the worse side wins, and a
 * BGP session an administrator shut down is disabled rather than down. Neighbors are found among
 * every device the user may see, so a session to a device outside the map's scope is left out
 * rather than drawn as an unknown network. BGP sessions to peers LibreNMS does not monitor join the
 * site to a cloud per remote AS; a private AS number can be a different network at every site (each
 * branch's firewall in AS65001), so its clouds are per site, except that sites peering with the same
 * address share one when it is not on a subnet of their routers (a cloud VPN gateway in AS65515) or
 * when it is on a tunnel subnet that their routers join with addresses of their own (a DMVPN hub).
 */
class SiteSessions
{
    /** OSPF neighbor states of a working adjacency (2-way: DROTHER routers on a shared segment). */
    private const OSPF_UP = ['full', 'twoway'];

    /** BGP administrative states of an enabled session (BGP4-MIB start, Junos running; empty when unknown). */
    private const BGP_ENABLED = ['start', 'running', ''];

    /** @var array<string, array{kind: string, devices: int[], sides: array<int, array>, network: ?string}> */
    private array $sessions = [];

    /** @var array<string, array> sessions to clouds by device, VRF and peer */
    private array $clouds = [];

    /**
     * @param  array<int, array>  $devices  device_id => row with location_id, location and bgpLocalAs: the map's scope
     * @param  array<string, array<int, array{device_id: int, prefixlen: int, vrf: string}>>  $owners  address => devices
     *     the user may see with that interface address, by device id (SiteRepository::addressOwners())
     * @param  array<int, array<int, array{0: string, 1: int, 2?: array}>>  $interfaces  device_id => interface addresses,
     *     prefix lengths and ports of the routers listed by unknownSources() (SiteRepository::interfaceAddresses())
     */
    public function __construct(private readonly array $devices, private readonly array $owners, private readonly array $interfaces = [])
    {
    }

    /**
     * Routers with sessions to peers in a private AS that LibreNMS does not monitor and no known
     * local address (a session that is down has none): whether such a peer is on a subnet of the
     * router then comes from the router's interface addresses.
     *
     * @param  array<int, array>  $rows  bgpPeers rows
     * @param  array<string, array>  $owners  see the constructor
     * @return int[]
     */
    public static function unknownSources(array $rows, array $owners): array
    {
        $devices = [];
        foreach ($rows as $row) {
            $local = (int) $row['device_id'];
            $peer = self::canonical((string) ($row['bgpPeerIdentifier'] ?? ''));
            $own = self::canonical((string) ($row['bgpLocalAddr'] ?? ''));
            if ($peer !== null && ! isset($owners[$peer]) && self::isPrivateAs((int) ($row['bgpPeerRemoteAs'] ?? 0))
                && ($own === null || ! in_array($local, array_column($owners[$own] ?? [], 'device_id'), true))) {
                $devices[$local] = $local;
            }
        }

        return array_values($devices);
    }

    /**
     * OSPF adjacencies between sites. Both routers report the other's address in the subnet of the
     * adjacency, which keeps parallel links apart; an unnumbered link (a neighbor found by its
     * router id or a host address) is only known by its pair of routers.
     *
     * @param  array<int, array>  $rows  ospf_nbrs rows: device_id, ospfNbrIpAddr, ospfNbrRtrId, ospfNbrState, context_name
     */
    public function addOspf(array $rows): self
    {
        foreach ($rows as $row) {
            $local = (int) $row['device_id'];
            $address = self::canonical((string) ($row['ospfNbrIpAddr'] ?? ''));
            $owner = $this->owner($address, (string) ($row['context_name'] ?? ''));
            $byRouterId = $owner === null;
            $owner ??= $this->owner(self::canonical((string) ($row['ospfNbrRtrId'] ?? '')), '');
            if ($owner === null || ! $this->betweenSites($local, $owner['device_id'])) {
                continue; // a neighbor LibreNMS does not monitor (no site), outside the scope, or at the same site
            }

            $circuit = $byRouterId || $address === null ? null : self::subnet($address, $owner['prefixlen']);
            $state = strtolower(trim((string) ($row['ospfNbrState'] ?? ''))) ?: 'unknown';
            $this->side('ospf|' . self::pair($local, $owner['device_id']) . '|' . ($circuit ?? 'unnumbered'), 'ospf', $local, $owner['device_id'], [
                'state' => $state,
                'up' => in_array($state, self::OSPF_UP, true),
                'disabled' => false,
                'own' => null,
                'peer' => $address,
                'type' => 'OSPF',
                'description' => '',
            ], $circuit);
        }

        return $this;
    }

    /**
     * BGP sessions between sites and to peers LibreNMS does not monitor (a cloud per remote AS).
     * The two routers' rows of a session are matched by its addresses: each side's local address is
     * the other's peer address.
     *
     * @param  array<int, array>  $rows  bgpPeers rows: device_id, bgpPeerIdentifier, bgpPeerRemoteAs, bgpPeerState,
     *                                   bgpPeerAdminStatus, bgpLocalAddr, bgpPeerDescr, context_name
     */
    public function addBgp(array $rows): self
    {
        $pairs = [];
        $clouds = [];
        foreach ($rows as $row) {
            $local = (int) $row['device_id'];
            $peer = self::canonical((string) ($row['bgpPeerIdentifier'] ?? ''));
            if (! isset($this->devices[$local]) || $peer === null) {
                continue;
            }
            $remoteAs = (int) ($row['bgpPeerRemoteAs'] ?? 0);
            $state = strtolower(trim((string) ($row['bgpPeerState'] ?? ''))) ?: 'unknown';
            $side = [
                'state' => $state,
                'up' => $state === 'established',
                'disabled' => ! in_array(strtolower(trim((string) ($row['bgpPeerAdminStatus'] ?? ''))), self::BGP_ENABLED, true),
                'own' => self::canonical((string) ($row['bgpLocalAddr'] ?? '')),
                'peer' => $peer,
                'vrf' => (string) ($row['context_name'] ?? ''),
                'type' => $remoteAs && $remoteAs === (int) ($this->devices[$local]['bgpLocalAs'] ?? 0) ? 'iBGP' : 'eBGP',
                'description' => (string) ($row['bgpPeerDescr'] ?? ''),
                'device_id' => $local,
            ];

            $owner = $this->owner($peer, $side['vrf']);
            if ($owner === null) {
                $clouds[] = [$local, $remoteAs, $side];
            } elseif ($this->betweenSites($local, $owner['device_id'])) {
                $pairs[self::pair($local, $owner['device_id'])][] = $side + ['remote' => $owner['device_id']];
            }
        }

        foreach ($pairs as $pair => $sides) {
            foreach (self::matchSessions($sides) as $index => $session) {
                foreach ($session as $side) {
                    $this->side("bgp|$pair|$index", 'bgp', $side['device_id'], $side['remote'], $side);
                }
            }
        }
        $shared = $this->sharedClouds($clouds);
        foreach ($clouds as [$local, $remoteAs, $side]) {
            $this->cloudSession($local, $remoteAs, $side, $shared[$remoteAs . '|' . SiteBuilder::siteId($this->devices[$local])] ?? null);
        }

        return $this;
    }

    /**
     * @return array<int, array> connections in SiteBuilder's format; sessions between devices carry the state
     *                           of each side (states: [a, b])
     */
    public function all(): array
    {
        $connections = [];
        foreach ($this->sessions as $session) {
            [$a, $b] = $session['devices'];
            $sa = $session['sides'][$a] ?? null;
            $sb = $session['sides'][$b] ?? null;
            $sides = array_values(array_filter([$sa, $sb]));
            $states = [$sa['state'] ?? null, $sb['state'] ?? null];
            $addresses = array_values(array_unique(array_filter([$sa['own'] ?? $sb['peer'] ?? null, $sb['own'] ?? $sa['peer'] ?? null])));
            $connections[] = [
                'kind' => $session['kind'],
                'a' => ['device_id' => $a],
                'b' => ['device_id' => $b],
                'status' => self::status($sides),
                'state' => implode(' / ', array_values(array_unique(array_filter($states)))),
                'states' => $states,
                'type' => $sides[0]['type'],
                'network' => $session['network'] ?? implode(', ', $addresses),
                'description' => (string) current(array_filter(array_column($sides, 'description')) ?: ['']),
            ];
        }

        return array_merge($connections, array_values($this->clouds));
    }

    /**
     * Rows of the BGP sessions between two devices grouped per session: by the session's two
     * addresses where a row has its local address, then rows without one (a session that is not
     * established) join a session with their peer address that lacks their side, or else one of the
     * other device's rows without a local address in the same family and VRF.
     *
     * @param  array<int, array>  $sides
     * @return array<int, array<int, array>>
     */
    private static function matchSessions(array $sides): array
    {
        $sessions = [];
        $loose = [];
        foreach ($sides as $side) {
            if ($side['own'] === null) {
                $loose[] = $side;
                continue;
            }
            $addresses = [$side['own'], $side['peer']];
            sort($addresses);
            $sessions[implode(' ', $addresses)][] = $side;
        }

        $sessions = array_values(array_map(fn (array $members) => ['addresses' => array_values(array_unique(array_merge(
            array_column($members, 'own'), array_column($members, 'peer')))), 'members' => $members], $sessions));
        foreach ($loose as $side) {
            $match = null;
            foreach ($sessions as $index => $session) {
                if (in_array($side['device_id'], array_column($session['members'], 'device_id'), true)) {
                    continue;
                }
                if (in_array($side['peer'], $session['addresses'], true)) {
                    $match = $index;
                    break;
                }
                $other = $session['members'][0];
                $match ??= $other['own'] === null && count($session['members']) === 1 && $other['vrf'] === $side['vrf']
                    && Cidr::family($other['peer']) === Cidr::family($side['peer']) ? $index : null;
            }
            if ($match === null) {
                $sessions[] = ['addresses' => [$side['peer']], 'members' => [$side]];
            } else {
                $sessions[$match]['members'][] = $side;
                $sessions[$match]['addresses'][] = $side['peer'];
            }
        }

        return array_column($sessions, 'members');
    }

    /**
     * One router's view of a session between two devices; a router reporting the same circuit twice
     * (two unnumbered links) keeps the worse state.
     */
    private function side(string $key, string $kind, int $local, int $remote, array $side, ?string $network = null): void
    {
        $this->sessions[$key] ??= ['kind' => $kind, 'devices' => [min($local, $remote), max($local, $remote)], 'sides' => [], 'network' => $network];
        $known = $this->sessions[$key]['sides'][$local] ?? null;
        if ($known === null || self::rank($side) > self::rank($known)) {
            $this->sessions[$key]['sides'][$local] = $side;
        }
    }

    /**
     * Private AS clouds that several sites share: sites whose routers peer with the same address in
     * the same private AS, when that address is not on a subnet of their routers (a session from a
     * loopback or a tunnel to a cloud VPN gateway), or is on a tunnel subnet their routers join with
     * an address of their own (spokes .2, .3, .4 of a DMVPN hub at .1). Every branch's firewall at
     * 192.168.255.2 stays a cloud per site, with the router at the same address everywhere or at an
     * address of its own on an Ethernet transit LAN.
     *
     * @param  array<int, array{0: int, 1: int, 2: array}>  $clouds  sessions to unmonitored peers: router, remote AS, side
     * @return array<string, string> "AS|site id" => id of the shared cloud
     */
    private function sharedClouds(array $clouds): array
    {
        $peers = [];
        foreach ($clouds as [$local, $remoteAs, $side]) {
            if (self::isPrivateAs($remoteAs)) {
                $site = $remoteAs . '|' . SiteBuilder::siteId($this->devices[$local]);
                [$connected, $address, $tunnel] = $this->source($local, $side);
                $peer = &$peers[$remoteAs . '@' . $side['peer']];
                $peer['sites'][$site] = true;
                $peer['remote'] = ($peer['remote'] ?? false) || $connected === false;
                if ($connected) {
                    $peer['sources'][$site][$address] = $tunnel;
                }
                unset($peer);
            }
        }

        $graph = new Graph;
        foreach ($peers as $address => $peer) {
            // the routers' addresses on the peer's subnet, of each site: no two sites with the same one, all on tunnels
            $sources = array_merge(...array_map('array_keys', array_values($peer['sources'] ?? [])));
            $segment = count($peer['sources'] ?? []) > 1 && count(array_unique($sources)) === count($sources)
                && ! in_array(false, array_merge(...array_map('array_values', array_values($peer['sources']))), true);
            if (count($peer['sites']) > 1 && ($segment || ($peer['remote'] && ! $sources))) {
                foreach (array_keys($peer['sites']) as $site) {
                    $graph->addEdge($site, $address);
                }
            }
        }
        $shared = [];
        foreach ($graph->components() as $component) {
            $addresses = array_values(array_filter($component, fn ($node) => str_contains((string) $node, '@')));
            sort($addresses);
            foreach (array_diff($component, $addresses) as $site) {
                $shared[$site] = 'as:' . $addresses[0];
            }
        }

        return $shared;
    }

    /**
     * Is the peer of a session on a subnet of the router, the router's address on it and whether that
     * is a tunnel interface: [true, address, tunnel], [false, null, false] when it is not on one,
     * [null, null, false] when that is not known. The session's local address tells while the session
     * is up, the router's interface addresses when it is down.
     *
     * @return array{0: bool|null, 1: string|null, 2: bool}
     */
    private function source(int $local, array $side): array
    {
        foreach ($side['own'] === null ? [] : ($this->owners[$side['own']] ?? []) as $owner) {
            if ($owner['device_id'] === $local) {
                $subnet = self::subnet($side['own'], $owner['prefixlen']);

                return $subnet !== null && Cidr::contains($subnet, $side['peer'])
                    ? [true, $side['own'], SiteConnections::isTunnel($owner['port'] ?? [])] : [false, null, false];
            }
        }
        if (! isset($this->interfaces[$local])) {
            return [null, null, false];
        }
        foreach ($this->interfaces[$local] as $interface) {
            $address = self::canonical($interface[0]);
            $subnet = $address === null ? null : self::subnet($address, $interface[1]);
            if ($subnet !== null && Cidr::contains($subnet, $side['peer'])) {
                return [true, $address, SiteConnections::isTunnel($interface[2] ?? [])];
            }
        }

        return [false, null, false];
    }

    /**
     * A session to a peer LibreNMS does not monitor (or the user may not see): a cloud per remote
     * AS; for a private AS number per site, or the cloud the site shares with others (sharedClouds()).
     */
    private function cloudSession(int $local, int $remoteAs, array $side, ?string $shared): void
    {
        $key = $local . '|' . $side['vrf'] . '|' . $side['peer'];
        if (isset($this->clouds[$key])) {
            return; // the same session listed twice
        }
        $device = $this->devices[$local];
        $private = self::isPrivateAs($remoteAs);
        $this->clouds[$key] = [
            'kind' => 'bgp',
            'a' => ['device_id' => $local],
            'b' => null,
            'cloud' => [
                'id' => $shared ?? 'as:' . ($remoteAs ?: $side['peer']) . ($private ? '@' . SiteBuilder::siteId($device) : ''),
                'label' => $remoteAs ? 'AS' . $remoteAs : $side['peer'],
                'name' => match (true) {
                    ! $remoteAs => 'BGP peer ' . $side['peer'],
                    $private && $shared === null => 'Autonomous System ' . $remoteAs . ' at ' . (($device['location'] ?? '') ?: 'no location'),
                    default => 'Autonomous System ' . $remoteAs,
                },
                'remote_as' => $remoteAs ?: null,
                'private' => $private,
                'shared' => $shared !== null,
            ],
            'status' => self::status([$side]),
            'state' => $side['state'],
            'type' => $side['type'],
            'network' => $side['peer'],
            'description' => $side['description'],
        ];
    }

    /**
     * Owner of an interface address, in the same VRF first.
     *
     * @return array{device_id: int, prefixlen: int, vrf: string}|null
     */
    private function owner(?string $address, string $vrf): ?array
    {
        $owners = $address === null ? [] : ($this->owners[$address] ?? []);
        foreach ($owners as $owner) {
            if ($owner['vrf'] === $vrf) {
                return $owner;
            }
        }

        return $owners[0] ?? null;
    }

    /**
     * Two monitored devices of the map's scope at different sites?
     */
    private function betweenSites(int $local, int $remote): bool
    {
        return $local !== $remote && isset($this->devices[$local], $this->devices[$remote])
            && SiteBuilder::siteId($this->devices[$local]) !== SiteBuilder::siteId($this->devices[$remote]);
    }

    /**
     * Status of a session from its sides: disabled when shut down on either router, up when every side is up.
     */
    private static function status(array $sides): string
    {
        return match (true) {
            (bool) array_filter($sides, fn (array $side) => $side['disabled']) => 'disabled',
            count(array_filter($sides, fn (array $side) => $side['up'])) === count($sides) => 'up',
            default => 'down',
        };
    }

    private static function rank(array $side): int
    {
        return $side['disabled'] ? 2 : ($side['up'] ? 0 : 1);
    }

    /**
     * Subnet of an adjacency from the neighbor's address and prefix; null for a host address.
     */
    private static function subnet(string $address, int $prefix): ?string
    {
        return $prefix >= (Cidr::family($address) === 4 ? 32 : 128) ? null : Cidr::network($address, $prefix);
    }

    /**
     * Address in its canonical form (2001:DB8:0::1 => 2001:db8::1); null when not an address or unspecified.
     */
    private static function canonical(string $address): ?string
    {
        $packed = @inet_pton(trim($address));
        if ($packed === false || trim($packed, "\0") === '') {
            return null;
        }

        return inet_ntop($packed);
    }

    /**
     * Private AS numbers (RFC 6996), which networks reuse at every site.
     */
    private static function isPrivateAs(int $asn): bool
    {
        return ($asn >= 64512 && $asn <= 65534) || ($asn >= 4200000000 && $asn <= 4294967294);
    }

    private static function pair(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }
}
