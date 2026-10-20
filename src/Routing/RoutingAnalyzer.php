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
 *    mismatches between the interfaces of one subnet, costs that differ per direction, routers of
 *    one area whose link-state databases disagree and DR elections on point-to-point links;
 *  - BGP: sessions that are down with their last error as text, a configured remote AS that is not
 *    the peer's AS, sessions configured on one side only, recent resets, accepted prefixes near the
 *    configured limit or none at all;
 *  - IP: duplicate addresses, different prefix lengths on the two ends of a link and one subnet in
 *    two VRFs on neighboring devices.
 * Pure PHP: all data is passed in as arrays so it can be tested without LibreNMS.
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

    /** @var array<int, true> reported devices (the group / location filter) */
    private array $scope;

    /** @var array<int, array> interface addresses, see indexAddresses() */
    private array $addresses = [];

    /** @var array<string, int[]> "vrf|ip" => address indexes */
    private array $owners = [];

    /** @var array<string, int[]> ip => address indexes in any VRF */
    private array $anyVrf = [];

    /** @var array<int, int[]> device => address indexes */
    private array $byDevice = [];

    /** @var array<string, array> "device|vrf" => OSPF instance */
    private array $instances = [];

    /** @var array<int, string[]> device => its OSPF instance keys */
    private array $instancesOf = [];

    /** @var array<string, array<int, true>> router ID => devices using it */
    private array $routersWithId = [];

    /** @var array<int, array> OSPF interfaces */
    private array $interfaces = [];

    /** @var array<string, int> "device|vrf|ip" => OSPF interface index */
    private array $interfaceAt = [];

    /** @var array<string, int[]> instance key => OSPF interface indexes */
    private array $interfacesOf = [];

    /** @var array<string, int[]> instance key => OSPF neighbor indexes */
    private array $neighborsOf = [];

    /** @var array<string, true> "device|vrf|ip" of the OSPF interfaces with a neighbor */
    private array $talking = [];

    /** @var array<int, array> OSPF neighbors seen by the analyzed routers */
    private array $neighbors = [];

    /** @var array<string, int> "device|vrf" => OSPF domain (routers joined by adjacencies) */
    private array $domains = [];

    /** @var array<int, array> BGP sessions */
    private array $peers = [];

    /** @var array<int, int[]> device => indexes of its BGP sessions */
    private array $peersOf = [];

    /** @var array<string, true> disabled check codes and categories */
    private array $disabled;

    /** @var string[] CIDRs left out of the IP checks */
    private array $ignored;

    /** @var array<int, array{0: Finding, 1: string[]}> findings with their VRFs */
    private array $findings = [];

    /** @var array<string, string> mask cache */
    private static array $masks = [];

    /**
     * @param  array<int, array>  $devices  device_id => device row (display, hostname, sysName, status, uptime,
     *                                       bgpLocalAs) of every device the user may see
     * @param  int[]  $scope  reported devices (the group / location filter)
     * @param  array<int, array>  $addresses  IPv4 and IPv6 interface addresses: device_id, port_id, ifName, ifDescr,
     *                                        ifType, ifMtu, ifOperStatus, address, prefixlen and vrf (its VRF name)
     * @param  array{instances?: array, areas?: array, ports?: array, neighbors?: array}  $ospf  rows of
     *                                        ospf_instances, ospf_areas, ospf_ports and ospf_nbrs
     * @param  array{peers?: array, prefixes?: array}  $bgp  rows of bgpPeers (with 'vrf', its VRF name) and
     *                                        bgpPeers_cbgp
     * @param  array<int, array<int, true>>  $adjacent  device => devices it has an LLDP/CDP link with (OSPF neighbors are
     *                                                  added: both prove a direct connection)
     * @param  array<int, array<string, int>>  $vrfAs  device => VRF name => local AS of that VRF
     */
    public function __construct(
        private array $devices,
        array $scope,
        array $addresses,
        private PluginSettings $settings,
        private array $ospf = [],
        private array $bgp = [],
        private array $adjacent = [],
        private array $vrfAs = [],
    ) {
        $this->scope = array_fill_keys(array_map('intval', $scope), true);
        $this->disabled = array_fill_keys(array_map('strtolower', $settings->list('routing_disabled_checks')), true);
        $this->ignored = $settings->list('routing_ignore_addresses');
        $this->indexAddresses($addresses);
        $this->prepareOspf();
        $this->prepareBgp();
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

        $row = fn (array $r) => $keep(array_values(array_filter([$r['device_id'], $r['remote_device'] ?? null])), [$r['vrf']])
            && isset($this->scope[$r['device_id']]);
        $routers = array_values(array_filter($this->routerRows(), fn ($r) => $keep([$r['device_id']], [$r['vrf']])));
        $adjacencies = array_values(array_filter($this->neighborRows(), $row));
        $sessions = array_values(array_filter($this->sessionRows(), $row));

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
            'vrfs' => $this->vrfNames(),
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
            ],
            'addresses' => $addresses,
            'findings' => $counts,
            'categories' => $categories,
            'status' => $counts['critical'] ? 'critical' : ($counts['warning'] ? 'warning' : 'ok'),
        ];
    }

    // ---------------------------------------------------------------- data

    /**
     * Index the interface addresses: owner of each address (per VRF and in any VRF) and addresses
     * per device. Link-local, loopback and multicast addresses are left out.
     */
    private function indexAddresses(array $rows): void
    {
        $portNames = [];
        foreach ($rows as $row) {
            $deviceId = (int) ($row['device_id'] ?? 0);
            $packed = @inet_pton(trim((string) ($row['address'] ?? '')));
            if ($packed === false || ! isset($this->devices[$deviceId]) || self::ignorable($packed)) {
                continue;
            }

            $ip = (string) inet_ntop($packed);
            $bits = strlen($packed) * 8;
            $family = $bits === 128 ? 6 : 4;
            $prefix = (int) ($row['prefixlen'] ?? 0);
            $vrf = (string) ($row['vrf'] ?? '');
            $network = $prefix >= 0 && $prefix <= $bits ? inet_ntop($packed & self::mask(strlen($packed), $prefix)) . '/' . $prefix : null;
            $host = $prefix >= $bits || strtolower((string) ($row['ifType'] ?? '')) === 'softwareloopback';
            $name = ($row['ifName'] ?? '') . '|' . ($row['ifDescr'] ?? '');
            $index = count($this->addresses);
            $this->addresses[] = [
                'device_id' => $deviceId,
                'port_id' => ! empty($row['port_id']) ? (int) $row['port_id'] : null,
                'port' => $portNames[$name] ??= InterfaceName::short(InterfaceName::label($row)),
                'ip' => $ip,
                'packed' => $packed,
                'prefix' => $prefix,
                'family' => $family,
                'vrf' => $vrf,
                'network' => $network,
                'host' => $host,
                'mtu' => (int) ($row['ifMtu'] ?? 0) ?: null,
            ];
            $this->owners[$vrf . '|' . $ip][] = $index;
            $this->anyVrf[$ip][] = $index;
            $this->byDevice[$deviceId][] = $index;
        }
    }

    /**
     * OSPF instances, interfaces and neighbors of the devices that are up (a down device's rows are
     * from before it went down), with the interface facing each neighbor and the device owning the
     * neighbor's address; then the OSPF domains (routers joined by adjacencies, per VRF).
     */
    private function prepareOspf(): void
    {
        foreach ($this->ospf['instances'] ?? [] as $row) {
            $deviceId = (int) ($row['device_id'] ?? 0);
            if (! $this->isUp($deviceId) || self::enum($row['ospfAdminStat'] ?? 'enabled', self::ADMIN) === 'disabled') {
                continue;
            }
            $vrf = (string) ($row['context_name'] ?? '');
            $key = $deviceId . '|' . $vrf;
            $routerId = self::ip((string) ($row['ospfRouterId'] ?? '')) ?? trim((string) ($row['ospfRouterId'] ?? ''));
            $this->instances[$key] = [
                'device_id' => $deviceId,
                'vrf' => $vrf,
                'router_id' => $routerId,
                'abr' => self::enum($row['ospfAreaBdrRtrStatus'] ?? '', [1 => 'true', 2 => 'false']) === 'true',
                'asbr' => self::enum($row['ospfASBdrRtrStatus'] ?? '', [1 => 'true', 2 => 'false']) === 'true',
            ];
            $this->instancesOf[$deviceId][] = $key;
            if (self::validRouterId($routerId)) {
                $this->routersWithId[$routerId][$deviceId] = true;
            }
        }

        foreach ($this->ospf['ports'] ?? [] as $row) {
            $deviceId = (int) ($row['device_id'] ?? 0);
            $vrf = (string) ($row['context_name'] ?? '');
            if (! isset($this->instances[$deviceId . '|' . $vrf]) || self::enum($row['ospfIfAdminStat'] ?? 'enabled', self::ADMIN) === 'disabled') {
                continue;
            }
            $ip = self::ip((string) ($row['ospfIfIpAddress'] ?? ''));
            $address = $ip !== null && $ip !== '0.0.0.0' ? $this->ownAddress($deviceId, $ip, $vrf) : null;
            $index = count($this->interfaces);
            $this->interfaceAt[$deviceId . '|' . $vrf . '|' . $ip] = $index;
            $this->interfacesOf[$deviceId . '|' . $vrf][] = $index;
            $this->interfaces[] = [
                'device_id' => $deviceId,
                'vrf' => $vrf,
                'ip' => $ip,
                'port_id' => (int) ($row['port_id'] ?? 0) ?: ($address['port_id'] ?? null),
                'port' => $address['port'] ?? ($ip ?? ''),
                'network' => $address['network'] ?? null,
                'prefix' => $address['prefix'] ?? null,
                'area' => self::area($row['ospfIfAreaId'] ?? ''),
                'type' => self::enum($row['ospfIfType'] ?? null, self::IF_TYPES),
                'state' => self::enum($row['ospfIfState'] ?? null, self::IF_STATES),
                'hello' => isset($row['ospfIfHelloInterval']) ? (int) $row['ospfIfHelloInterval'] : null,
                'dead' => isset($row['ospfIfRtrDeadInterval']) ? (int) $row['ospfIfRtrDeadInterval'] : null,
                'auth' => self::enum($row['ospfIfAuthType'] ?? null, self::AUTH_TYPES),
                'cost' => isset($row['ospfIfMetricValue']) ? (int) $row['ospfIfMetricValue'] : null,
                'dr' => self::ip((string) ($row['ospfIfDesignatedRouter'] ?? '')),
            ];
        }

        foreach ($this->ospf['neighbors'] ?? [] as $row) {
            $deviceId = (int) ($row['device_id'] ?? 0);
            $vrf = (string) ($row['context_name'] ?? '');
            $ip = self::ip((string) ($row['ospfNbrIpAddr'] ?? ''));
            if ($ip === null || ! isset($this->instances[$deviceId . '|' . $vrf])) {
                continue;
            }
            $routerId = self::ip((string) ($row['ospfNbrRtrId'] ?? '')) ?? trim((string) ($row['ospfNbrRtrId'] ?? ''));
            $remote = $this->owner($ip, $vrf, $deviceId);
            $local = $this->facing($deviceId, $ip, $vrf);
            $interface = $local !== null ? ($this->interfaces[$this->interfaceAt[$deviceId . '|' . $vrf . '|' . $local['ip']] ?? -1] ?? null) : null;
            if ($interface !== null) {
                $this->talking[$deviceId . '|' . $vrf . '|' . $interface['ip']] = true;
            }
            $this->neighborsOf[$deviceId . '|' . $vrf][] = count($this->neighbors);
            $this->neighbors[] = [
                'device_id' => $deviceId,
                'vrf' => $vrf,
                'ip' => $ip,
                'router_id' => $routerId,
                'state' => self::enum($row['ospfNbrState'] ?? null, self::NBR_STATES),
                'remote_device' => $remote['device_id'] ?? $this->routerWithId($routerId, $deviceId),
                'remote' => $remote,
                'local' => $local,
                'interface' => $interface,
            ];
        }

        // directly connected routers, and the OSPF domains: routers (per VRF) joined by the adjacencies they report
        foreach ($this->neighbors as $neighbor) {
            if ($neighbor['remote_device'] !== null && $neighbor['remote_device'] !== $neighbor['device_id']) {
                $this->adjacent[$neighbor['device_id']][$neighbor['remote_device']] = true;
                $this->adjacent[$neighbor['remote_device']][$neighbor['device_id']] = true;
            }
        }
        $graph = new Graph;
        foreach (array_keys($this->instances) as $key) {
            $graph->addNode($key);
        }
        foreach ($this->neighbors as $neighbor) {
            $remote = $neighbor['remote_device'] === null ? null : $this->instanceKey($neighbor['remote_device'], $neighbor['remote']['vrf'] ?? $neighbor['vrf']);
            if ($remote !== null) {
                $graph->addEdge($neighbor['device_id'] . '|' . $neighbor['vrf'], $remote);
            }
        }
        foreach ($graph->components() as $index => $component) {
            foreach ($component as $key) {
                $this->domains[(string) $key] = $index;
            }
        }
    }

    /**
     * BGP sessions of the devices that are up, with the device owning the peer address, the local
     * AS (of the session's VRF) and the prefix counters of bgpPeers_cbgp.
     */
    private function prepareBgp(): void
    {
        $prefixes = [];
        foreach ($this->bgp['prefixes'] ?? [] as $row) {
            $ip = self::ip((string) ($row['bgpPeerIdentifier'] ?? ''));
            if ($ip !== null) {
                $prefixes[(int) $row['device_id'] . '|' . ($row['context_name'] ?? '') . '|' . $ip][] = [
                    'family' => self::family((string) ($row['afi'] ?? ''), (string) ($row['safi'] ?? '')),
                    'accepted' => (int) ($row['AcceptedPrefixes'] ?? 0),
                    'denied' => (int) ($row['DeniedPrefixes'] ?? 0),
                    'limit' => (int) ($row['PrefixAdminLimit'] ?? 0),
                    'threshold' => (int) ($row['PrefixThreshold'] ?? 0),
                ];
            }
        }

        foreach ($this->bgp['peers'] ?? [] as $row) {
            $deviceId = (int) ($row['device_id'] ?? 0);
            $ip = self::ip((string) ($row['bgpPeerIdentifier'] ?? '')) ?? self::ip((string) ($row['bgpPeerRemoteAddr'] ?? ''));
            if ($ip === null || ! $this->isUp($deviceId)) {
                continue;
            }
            $vrf = (string) ($row['vrf'] ?? $row['context_name'] ?? '');
            $remoteAs = (int) ($row['bgpPeerRemoteAs'] ?? 0) ?: null;
            $localAs = $this->localAs($deviceId, $vrf);
            $state = self::enum($row['bgpPeerState'] ?? null, self::BGP_STATES);
            $remote = $this->owner($ip, $vrf, $deviceId);
            $localIp = self::ip((string) ($row['bgpLocalAddr'] ?? ''));
            $local = ($localIp !== null && $localIp !== '0.0.0.0' ? $this->ownAddress($deviceId, $localIp, $vrf) : null) ?? $this->facing($deviceId, $ip, $vrf);
            $text = trim((string) ($row['bgpPeerLastErrorText'] ?? ''));
            $index = count($this->peers);
            $this->peers[] = [
                'device_id' => $deviceId,
                'vrf' => $vrf,
                'ip' => $ip,
                'remote_as' => $remoteAs,
                'local_as' => $localAs,
                'type' => $remoteAs === null || $localAs === null ? null : ($remoteAs === $localAs ? 'iBGP' : 'eBGP'),
                'state' => $state,
                'established' => $state === 'established',
                'shut' => in_array(self::enum($row['bgpPeerAdminStatus'] ?? null, self::BGP_ADMIN), self::BGP_SHUT, true),
                'error' => BgpErrors::describe(self::number($row['bgpPeerLastErrorCode'] ?? null), self::number($row['bgpPeerLastErrorSubCode'] ?? null)),
                'error_text' => $text,
                'time' => max(0, (int) ($row['bgpPeerFsmEstablishedTime'] ?? 0)),
                'description' => trim((string) ($row['bgpPeerDescr'] ?? '')) ?: trim((string) ($row['astext'] ?? '')),
                'remote_device' => $remote['device_id'] ?? null,
                'remote' => $remote,
                'local' => $local,
                'prefixes' => $prefixes[$deviceId . '|' . ($row['context_name'] ?? '') . '|' . $ip] ?? [],
            ];
            $this->peersOf[$deviceId][] = $index;
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
            $own = $this->instances[$neighbor['device_id'] . '|' . $neighbor['vrf']]['router_id'] ?? '';
            if (! self::validRouterId($own) || $neighbor['router_id'] !== $own || isset($reported[$own])) {
                continue;
            }
            $reported[$own] = true;
            $this->add(new Finding(
                Finding::CRITICAL,
                'ospf_duplicate_router_id',
                'OSPF neighbor ' . $neighbor['ip'] . ' of ' . $this->name($neighbor['device_id']) . ' uses the same router ID ' . $own,
                'The neighbor (a router LibreNMS does not monitor) and ' . $this->name($neighbor['device_id']) . ' both use router ID ' . $own
                . ': their router LSAs replace each other and the adjacency cannot be trusted.',
                'Give every router a unique router ID, usually its loopback address, then restart the OSPF process.',
                null,
                [$neighbor['device_id']],
                array_filter([$neighbor['local']['port_id'] ?? null]),
            ), [$neighbor['vrf']]);
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
            $state = $neighbor['state'];
            $p2p = ($neighbor['interface']['type'] ?? null) === 'pointtopoint';
            if ($state === 'full' || ($state === 'twoway' && ! $p2p) || $state === '') {
                continue;
            }
            $here = 'd:' . $neighbor['device_id'];
            $there = $neighbor['remote_device'] !== null ? 'd:' . $neighbor['remote_device'] : 'ip:' . $neighbor['ip'];
            $network = $neighbor['local']['network'] ?? $neighbor['ip'];
            $key = (strcmp($here, $there) < 0 ? "$here|$there" : "$there|$here") . '|' . $neighbor['vrf'] . '|' . $network;
            $pairs[$key][] = $neighbor;
        }

        $rank = ['exchangestart' => 0, 'exchange' => 0, 'init' => 1, 'loading' => 2, 'twoway' => 3, 'attempt' => 4, 'down' => 4];
        foreach ($pairs as $rows) {
            usort($rows, fn ($a, $b) => ($rank[$a['state']] ?? 9) <=> ($rank[$b['state']] ?? 9));
            $first = $rows[0];
            $state = $first['state'];
            $devices = array_values(array_unique(array_filter([$first['device_id'], $first['remote_device']], fn ($d) => $d !== null)));
            $ports = array_values(array_filter([$first['local']['port_id'] ?? null, $first['remote']['port_id'] ?? null]));
            $seen = implode(', ', array_map(fn ($r) => $this->name($r['device_id']) . ' sees ' . $r['ip'] . ' in ' . self::label($r['state']), $rows));
            $pair = $this->end($first['device_id'], $first['local']['port'] ?? null) . ' ↔ '
                . ($first['remote_device'] !== null ? $this->end($first['remote_device'], $first['remote']['port'] ?? null) : $first['ip'] . ' (router ID ' . $first['router_id'] . ')');
            $down = $first['remote_device'] !== null && ! $this->isUp($first['remote_device']) ? ' ' . $this->name($first['remote_device']) . ' itself is down.' : '';

            if (in_array($state, ['exchangestart', 'exchange'], true)) {
                $mtuHere = $first['local']['mtu'] ?? null;
                $mtuThere = $first['remote']['mtu'] ?? null;
                if ($mtuHere !== null && $mtuThere !== null && $mtuHere !== $mtuThere) {
                    $this->add(new Finding(
                        Finding::CRITICAL,
                        'ospf_mtu_mismatch',
                        "OSPF adjacency $pair stuck in " . self::label($state) . ": MTU $mtuHere vs $mtuThere",
                        "The routers start the database exchange but never finish it: $seen. The interface MTUs differ ($mtuHere on "
                        . $this->name($first['device_id']) . ", $mtuThere on " . $this->name((int) $first['remote_device']) . '), so the larger database packets of one side are dropped by the other.',
                        'Set the same IP MTU on both interfaces. ip ospf mtu-ignore brings the adjacency up but large packets are still dropped.',
                        null,
                        $devices,
                        $ports,
                    ), [$first['vrf']]);

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
    private function stuck(array $neighbor, string $pair, string $what, string $detail, string $fix, array $devices, array $ports): void
    {
        $this->add(new Finding(Finding::WARNING, 'ospf_neighbor_stuck', "OSPF adjacency $pair $what", $detail, $fix, null, $devices, $ports), [$neighbor['vrf']]);
    }

    /**
     * Interfaces of one subnet must agree on hello / dead intervals, area, authentication and
     * network type; costs that differ per direction make routing asymmetric; and a point-to-point
     * link (/30, /31, /126, /127) with the broadcast network type elects a DR for nothing.
     */
    private function checkInterfaces(): void
    {
        $groups = [];
        foreach ($this->interfaces as $interface) {
            if ($interface['network'] !== null) {
                $groups[$interface['vrf'] . '|' . $interface['network']][] = $interface;
            }
        }
        ksort($groups);

        foreach ($groups as $key => $interfaces) {
            [$vrf, $network] = explode('|', $key, 2);
            $devices = array_values(array_unique(array_column($interfaces, 'device_id')));
            $ports = array_values(array_filter(array_column($interfaces, 'port_id')));
            $where = $network . ($vrf !== '' ? " (VRF $vrf)" : '');

            if (count($devices) >= 2) {
                $timers = $this->differing($interfaces, fn ($i) => $i['hello'] === null && $i['dead'] === null ? null : ($i['hello'] ?? '?') . '/' . ($i['dead'] ?? '?') . 's');
                if ($timers) {
                    $this->add(new Finding(Finding::CRITICAL, 'ospf_timer_mismatch',
                        "OSPF hello / dead intervals differ on $where: $timers",
                        'Routers only form an adjacency when their hello and dead intervals are equal, so these routers cannot become neighbors on this subnet.',
                        'Use the same intervals on every interface of the subnet (ip ospf hello-interval / dead-interval), or the defaults everywhere.',
                        null, $devices, $ports), [$vrf]);
                }
                $areas = $this->differing($interfaces, fn ($i) => $i['area'] === '' ? null : 'area ' . $i['area']);
                if ($areas) {
                    $this->add(new Finding(Finding::CRITICAL, 'ospf_area_mismatch',
                        "OSPF area differs on $where: $areas",
                        'Interfaces in one subnet must be in the same area; hellos from another area are ignored, so no adjacency forms.',
                        'Put every interface of the subnet in the same area.',
                        null, $devices, $ports), [$vrf]);
                }
                $auth = $this->differing($interfaces, fn ($i) => $i['auth'] === '' ? null : self::label($i['auth']));
                if ($auth) {
                    $this->add(new Finding(Finding::CRITICAL, 'ospf_auth_mismatch',
                        "OSPF authentication differs on $where: $auth",
                        'Hellos with another authentication type are dropped, so no adjacency forms.',
                        'Configure the same authentication type and key on every interface of the subnet (or in the area).',
                        null, $devices, $ports), [$vrf]);
                }
                $types = $this->differing($interfaces, fn ($i) => $i['type'] === '' ? null : self::label($i['type']));
                if ($types) {
                    $this->add(new Finding(Finding::WARNING, 'ospf_network_type_mismatch',
                        "OSPF network type differs on $where: $types",
                        'With different network types the routers describe the link differently (a transit network on one side, a point-to-point link on the other): adjacencies can form while routes over the link are missing, and the default timers differ.',
                        'Use the same network type on every interface of the subnet, point-to-point for a link between two routers.',
                        null, $devices, $ports), [$vrf]);
                }
                $costs = array_values(array_filter($interfaces, fn ($i) => $i['cost'] !== null));
                if (count($interfaces) === 2 && count($devices) === 2 && count($costs) === 2 && $costs[0]['cost'] !== $costs[1]['cost']) {
                    $this->add(new Finding(Finding::INFO, 'ospf_cost_asymmetric',
                        "OSPF cost differs per direction on $where: " . $this->values($costs, fn ($i) => (string) $i['cost']),
                        'Traffic between ' . $this->names($devices) . ' can take different paths in each direction: stateful firewalls drop the half they do not see, and troubleshooting gets harder.',
                        'Use the same cost on both interfaces (ip ospf cost), unless the asymmetry is on purpose.',
                        null, $devices, $ports), [$vrf]);
                }
            }

            // only prefixes with room for two addresses (a LAN with two routers also has hosts), and only
            // where OSPF talks to a neighbor: a passive interface elects itself without consequence
            $prefix = (int) explode('/', $network)[1];
            $p2p = in_array($prefix, Cidr::family($network) === 4 ? [30, 31] : [126, 127], true);
            $talking = count($devices) >= 2 || array_filter($interfaces, fn ($i) => isset($this->talking[$i['device_id'] . '|' . $i['vrf'] . '|' . $i['ip']]));
            $elected = array_filter($interfaces, fn ($i) => in_array($i['type'], ['broadcast', 'nbma'], true)
                && (in_array($i['state'], ['designatedrouter', 'backupdesignatedrouter', 'otherdesignatedrouter'], true) || ($i['dr'] !== null && $i['dr'] !== '0.0.0.0')));
            if ($p2p && $talking && $elected) {
                $this->add(new Finding(Finding::INFO, 'ospf_dr_on_p2p',
                    "DR election on the point-to-point link $where: " . $this->values($interfaces, fn ($i) => self::label($i['type'])),
                    'Only two routers share this subnet, but the broadcast network type makes them elect a designated router: the adjacency comes up slower and the link adds a network LSA.',
                    'Set the network type to point-to-point on both ends (ip ospf network point-to-point; Junos: interface-type p2p).',
                    null, $devices, $ports), [$vrf]);
            }
        }
    }

    /**
     * Routers of one area and one OSPF domain must agree on the area type (stub / NSSA) and, once
     * converged, hold the same link-state database (LSA count and checksum sum of the area).
     */
    private function checkAreas(): void
    {
        $areas = [];
        foreach ($this->ospf['areas'] ?? [] as $row) {
            $deviceId = (int) ($row['device_id'] ?? 0);
            $key = $deviceId . '|' . ($row['context_name'] ?? '');
            if (! isset($this->instances[$key])) {
                continue;
            }
            $area = self::area($row['ospfAreaId'] ?? '');
            $areas[($this->domains[$key] ?? $key) . '|' . $area][] = [
                'device_id' => $deviceId,
                'vrf' => (string) ($row['context_name'] ?? ''),
                'area' => $area,
                'count' => isset($row['ospfAreaLsaCount']) ? (int) $row['ospfAreaLsaCount'] : null,
                'sum' => isset($row['ospfAreaLsaCksumSum']) ? (int) $row['ospfAreaLsaCksumSum'] : null,
                'type' => self::enum($row['ospfImportAsExtern'] ?? null, self::AREA_TYPES),
            ];
        }

        foreach ($areas as $routers) {
            if (count(array_unique(array_column($routers, 'device_id'))) < 2) {
                continue;
            }
            $area = $routers[0]['area'];
            $devices = array_values(array_unique(array_column($routers, 'device_id')));
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
            $databases = array_unique(array_map(fn ($r) => $r['count'] . '/' . $r['sum'], $known));
            if (count($databases) > 1) {
                $sameCount = count(array_unique(array_column($known, 'count'))) === 1;
                $this->add(new Finding(Finding::WARNING, 'ospf_lsdb_mismatch',
                    "OSPF area $area: the link-state databases differ (" . $this->values($known, fn ($r) => $r['count'] . ' LSAs', false) . ')',
                    ($sameCount ? 'The routers hold the same number of LSAs but different contents (checksum sum). ' : '')
                    . 'Routers of one area should hold the same LSAs. A difference can be a moment during a change (devices are polled minutes apart); if it stays, LSAs are not flooded between them.',
                    'Look for adjacencies that are not Full, MTU problems or LSA filtering between these routers, and compare their OSPF databases.',
                    null, $devices), $vrfs);
            }
        }
    }

    // ---------------------------------------------------------------- BGP checks

    /**
     * Sessions that are not established (unless shut down on purpose): the cause when LibreNMS
     * can tell (remote AS mismatch, no neighbor on the other router), else the last error as text.
     * Established sessions that were reset recently or accept no prefixes are reported too.
     */
    private function checkSessions(): void
    {
        $window = $this->settings->int('routing_bgp_reset_hours', 0, 8760) * 3600;
        foreach ($this->peers as $peer) {
            if ($peer['shut']) {
                continue;
            }
            $devices = array_values(array_unique(array_filter([$peer['device_id'], $peer['remote_device']], fn ($d) => $d !== null)));
            $ports = array_values(array_filter([$peer['local']['port_id'] ?? null, $peer['remote']['port_id'] ?? null]));
            $session = $this->session($peer);

            if ($peer['established']) {
                $this->checkReset($peer, $session, $window, $devices, $ports);
                $this->checkNoPrefixes($peer, $session, $devices, $ports);

                continue;
            }

            if ($this->checkRemoteAs($peer, $session, $devices, $ports) || $this->checkOneSided($peer, $session, $devices, $ports)) {
                continue;
            }

            $error = $peer['error'];
            $severity = $error !== null && $error['code'] === 6 && $error['subcode'] === 2 ? Finding::INFO : Finding::WARNING;
            $detail = $error
                ? 'Last error: ' . $error['text'] . ($error['cause'] !== '' ? ': ' . $error['cause'] : '') . '.'
                : 'No error was exchanged: the TCP connection to ' . $peer['ip'] . ' never came up.';
            if ($peer['error_text'] !== '') {
                $detail .= ' The router says: "' . $peer['error_text'] . '".';
            }
            $detail .= $peer['time'] > 0 ? ' Last established ' . StpAnalyzer::duration($peer['time']) . ' ago.' : '';
            if ($peer['remote_device'] !== null && ! $this->isUp($peer['remote_device'])) {
                $detail .= ' ' . $this->name($peer['remote_device']) . ' itself is down.';
            } elseif ($peer['remote_device'] !== null && ! isset($this->peersOf[$peer['remote_device']])) {
                $detail .= ' ' . $this->name($peer['remote_device']) . ' reports no BGP sessions: BGP is not configured there, or its bgp-peers module is off.';
            }
            $local = $peer['local']['ip'] ?? 'this router';
            $this->add(new Finding(
                $severity,
                'bgp_session_down',
                'BGP session ' . $session . ' is ' . self::label($peer['state']) . ($error ? ': ' . explode(' (', $error['text'])[0] : ''),
                $detail,
                match (true) {
                    $error !== null && $error['code'] === 6 && $error['subcode'] === 3 => 'The neighbor was removed on the other router: remove it here too, or configure it again there.',
                    $error !== null && $error['code'] === 6 && $error['subcode'] === 2 => 'If the session should be up, enable it again on the router that shut it down.',
                    $error !== null => 'Fix the cause above, then reset the session.',
                    default => "Check that $peer[ip] is reachable from $local, that TCP port 179 is allowed in between, that the peer has a neighbor for $local, and for eBGP between loopbacks that ebgp-multihop and update-source are set.",
                },
                null,
                $devices,
                $ports,
            ), [$peer['vrf']]);
        }
    }

    /**
     * A session that is not up because the remote AS configured here is not the AS of the peer
     * (when the peer is monitored). An established session with another AS uses local-as or a
     * confederation, so it is not reported.
     */
    private function checkRemoteAs(array $peer, string $session, array $devices, array $ports): bool
    {
        if ($peer['remote_device'] === null || $peer['remote_as'] === null) {
            return false;
        }
        $peerAs = $this->localAs($peer['remote_device'], $peer['remote']['vrf'] ?? $peer['vrf']);
        if ($peerAs === null || $peerAs === $peer['remote_as']) {
            return false;
        }

        $this->add(new Finding(
            Finding::CRITICAL,
            'bgp_remote_as_mismatch',
            "BGP session $session: remote AS $peer[remote_as] configured, " . $this->name($peer['remote_device']) . " is AS $peerAs",
            'The session cannot come up: ' . $this->name($peer['remote_device']) . " opens it with AS $peerAs, which " . $this->name($peer['device_id'])
            . ' rejects (OPEN Message Error / Bad Peer AS).'
            . ($peer['error'] !== null && ! ($peer['error']['code'] === 2 && $peer['error']['subcode'] === 2) ? ' Last error: ' . $peer['error']['text'] . '.' : ''),
            'Correct the remote AS of the neighbor on ' . $this->name($peer['device_id']) . ' (or the local AS of ' . $this->name($peer['remote_device']) . ').',
            null,
            $devices,
            $ports,
        ), [$peer['vrf']]);

        return true;
    }

    /**
     * A session that is not up because the monitored peer has no neighbor for any address of this
     * router (when the peer reports BGP sessions at all).
     */
    private function checkOneSided(array $peer, string $session, array $devices, array $ports): bool
    {
        $remote = $peer['remote_device'];
        if ($remote === null || $remote === $peer['device_id'] || ! isset($this->peersOf[$remote]) || ! $this->isUp($remote)) {
            return false;
        }
        foreach ($this->peersOf[$remote] as $index) {
            foreach ($this->anyVrf[$this->peers[$index]['ip']] ?? [] as $address) {
                if ($this->addresses[$address]['device_id'] === $peer['device_id']) {
                    return false;
                }
            }
        }

        $local = $peer['local']['ip'] ?? $this->name($peer['device_id']);
        $this->add(new Finding(
            Finding::WARNING,
            'bgp_one_sided',
            "BGP session $session is configured on " . $this->name($peer['device_id']) . ' only',
            $this->name($remote) . ' has BGP sessions, but none to an address of ' . $this->name($peer['device_id'])
            . ', so this session can never come up (it is ' . self::label($peer['state']) . ').',
            'Add the neighbor ' . $local . ' on ' . $this->name($remote) . ', or remove the neighbor ' . $peer['ip'] . ' from ' . $this->name($peer['device_id']) . '.',
            null,
            $devices,
            $ports,
        ), [$peer['vrf']]);

        return true;
    }

    /**
     * An established session that came up less than the window ago: it was reset. Resets caused by
     * a restart of the router itself are told apart (uptime), protocol errors are warnings.
     */
    private function checkReset(array $peer, string $session, int $window, array $devices, array $ports): void
    {
        if ($window <= 0 || $peer['time'] <= 0 || $peer['time'] >= $window) {
            return;
        }
        $uptime = (int) ($this->devices[$peer['device_id']]['uptime'] ?? 0);
        $restart = $uptime > 0 && $uptime <= $peer['time'] + self::RESTART_MARGIN;
        $error = $peer['error'];
        $planned = $error !== null && (BgpErrors::administrative($error['code'], $error['subcode']) || ($error['code'] === 6 && $error['subcode'] === 7));
        $protocol = $error !== null && ! $planned;

        $detail = 'Up for ' . StpAnalyzer::duration($peer['time']) . '.';
        if ($restart) {
            $detail .= ' ' . $this->name($peer['device_id']) . ' itself restarted ' . StpAnalyzer::duration($uptime) . ' ago.';
        } elseif ($error !== null) {
            $detail .= ' Last error: ' . $error['text'] . ($error['cause'] !== '' ? ': ' . $error['cause'] : '') . '.';
        } else {
            $detail .= ' No error was recorded (the TCP connection was closed, or the peer restarted).';
        }

        $this->add(new Finding(
            $protocol && ! $restart ? Finding::WARNING : Finding::INFO,
            'bgp_recent_reset',
            "BGP session $session was reset " . StpAnalyzer::duration($peer['time']) . ' ago',
            $detail,
            match (true) {
                $restart => 'Nothing to do if the restart was planned.',
                $planned => 'Nothing to do if the reset was planned.',
                default => 'If it happens again, follow the cause above: repeated resets point to an unstable path or peer.',
            },
            null,
            $devices,
            $ports,
        ), [$peer['vrf']]);
    }

    /**
     * An established session that accepts no prefixes in any address family (only when the router
     * reports prefix counters, bgpPeers_cbgp).
     */
    private function checkNoPrefixes(array $peer, string $session, array $devices, array $ports): void
    {
        if (! $peer['prefixes'] || array_sum(array_column($peer['prefixes'], 'accepted')) > 0) {
            return;
        }
        $denied = array_sum(array_column($peer['prefixes'], 'denied'));
        $this->add(new Finding(
            Finding::INFO,
            'bgp_no_prefixes',
            "BGP session $session is up but accepts no prefixes",
            $denied > 0
                ? "All $denied prefixes it received were denied by the inbound policy."
                : 'The peer sends nothing, or nothing passes the inbound policy. Peers that only receive routes look like this too.',
            'Check the inbound route-map / prefix-list on ' . $this->name($peer['device_id']) . ' and what the peer advertises.',
            null,
            $devices,
            $ports,
        ), [$peer['vrf']]);
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
            if ($peer['shut']) {
                continue;
            }
            foreach ($peer['prefixes'] as $counters) {
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
                    array_values(array_unique(array_filter([$peer['device_id'], $peer['remote_device']], fn ($d) => $d !== null))),
                    array_values(array_filter([$peer['local']['port_id'] ?? null])),
                ), [$peer['vrf']]);
            }
        }
    }

    // ---------------------------------------------------------------- IP checks

    /**
     * The same address in one VRF on several devices. When each device also has its own address
     * in the subnet it is probably a shared gateway (VRRP, HSRP, anycast gateway) reported as an
     * interface address; on three or more devices probably an anycast address.
     */
    private function checkDuplicates(): void
    {
        $groups = [];
        foreach ($this->addresses as $index => $address) {
            if (! $this->isIgnored($address['ip'])) {
                $groups[$address['vrf'] . '|' . $address['ip']][] = $index;
            }
        }

        foreach ($groups as $indexes) {
            $rows = array_map(fn ($i) => $this->addresses[$i], $indexes);
            $devices = array_values(array_unique(array_column($rows, 'device_id')));
            if (count($devices) < 2) {
                continue;
            }
            $first = $rows[0];
            $shared = ! $first['host'] && count($devices) === 2 && array_reduce($devices, fn ($all, $deviceId) => $all && $this->hasOtherAddress($deviceId, $first), true);
            $anycast = count($devices) > 2;
            $where = $first['vrf'] !== '' ? ' (VRF ' . $first['vrf'] . ')' : '';
            $this->add(new Finding(
                $shared || $anycast ? Finding::INFO : Finding::WARNING,
                'ip_duplicate',
                'duplicate IP address ' . $first['ip'] . $where . ' on ' . implode(', ', array_map(fn ($r) => $this->end($r['device_id'], $r['port']), $rows)),
                match (true) {
                    $shared => 'Both devices also have their own address in ' . $first['network'] . ', so this is probably a shared gateway address (VRRP, HSRP or an anycast gateway) that the devices report as an interface address.',
                    $anycast => 'The address is on ' . count($devices) . ' devices: probably an anycast address on purpose.',
                    default => 'Both devices answer for the address: traffic to it reaches whichever device answered ARP / neighbor discovery last, so connections break at random. The L3 map shows only one of them.',
                },
                $shared || $anycast
                    ? 'If the address is shared on purpose, add it to the ignored addresses in the routing settings.'
                    : 'Give one of the interfaces another address.',
                null,
                $devices,
                array_values(array_filter(array_column($rows, 'port_id'))),
            ), [$first['vrf']]);
        }
    }

    /**
     * Two ends of a link with different prefix lengths: the address of one device lies in the
     * longer prefix of the other (so both consider the other on-link) while the prefixes differ.
     * Host routes and loopbacks are left out.
     */
    private function checkMasks(): void
    {
        $byNetwork = [];
        $prefixes = [];
        foreach ($this->addresses as $index => $address) {
            if ($address['host'] || $address['network'] === null || $this->isIgnored($address['ip'])) {
                continue;
            }
            $byNetwork[$address['vrf'] . '|' . $address['network']][] = $index;
            $prefixes[$address['vrf'] . '|' . $address['family']][$address['prefix']] = true;
        }

        $reported = [];
        foreach ($byNetwork as $indexes) {
            foreach ($indexes as $shortIndex) {
                $short = $this->addresses[$shortIndex];
                foreach (array_keys($prefixes[$short['vrf'] . '|' . $short['family']]) as $prefix) {
                    if ($prefix <= $short['prefix']) {
                        continue;
                    }
                    $key = $short['vrf'] . '|' . inet_ntop($short['packed'] & self::mask(strlen($short['packed']), $prefix)) . '/' . $prefix;
                    foreach ($byNetwork[$key] ?? [] as $longIndex) {
                        $long = $this->addresses[$longIndex];
                        $pair = $longIndex . '|' . $shortIndex;
                        if ($long['device_id'] === $short['device_id'] || $long['ip'] === $short['ip'] || isset($reported[$pair])) {
                            continue;
                        }
                        $reported[$pair] = true;
                        $this->add(new Finding(
                            Finding::WARNING,
                            'ip_mask_mismatch',
                            'Subnet mask mismatch: ' . $this->end($long['device_id'], $long['port']) . ' ' . $long['ip'] . '/' . $long['prefix'] . ' and '
                            . $this->end($short['device_id'], $short['port']) . ' ' . $short['ip'] . '/' . $short['prefix'],
                            'Both addresses lie in ' . $long['network'] . ', but ' . $this->name($short['device_id']) . ' uses a /' . $short['prefix']
                            . ': the devices disagree on which addresses are on the link, so traffic to the rest of ' . $short['network'] . ' is sent to the wrong place.',
                            'Use the same prefix length on both ends.',
                            null,
                            [$long['device_id'], $short['device_id']],
                            array_values(array_filter([$long['port_id'], $short['port_id']])),
                        ), [$long['vrf']]);
                    }
                }
            }
        }
    }

    /**
     * One subnet in two VRFs on two neighbors (LLDP/CDP or OSPF): the interfaces facing each other
     * are in different routing tables. A device that uses no VRF at all with its address in the global
     * table is left out (a customer router on a provider's VRF interface).
     */
    private function checkVrfs(): void
    {
        $byNetwork = [];
        $usesVrfs = [];
        foreach ($this->addresses as $index => $address) {
            if ($address['vrf'] !== '') {
                $usesVrfs[$address['device_id']] = true;
            }
            if (! $address['host'] && $address['network'] !== null && ! $this->isIgnored($address['ip'])) {
                $byNetwork[$address['network']][$address['vrf']][$address['device_id']] ??= $index;
            }
        }

        foreach ($byNetwork as $network => $vrfs) {
            if (count($vrfs) < 2) {
                continue;
            }
            ksort($vrfs);
            $names = array_keys($vrfs);
            $reported = [];
            foreach ($names as $i => $vrfA) {
                foreach (array_slice($names, $i + 1) as $vrfB) {
                    foreach ($vrfs[$vrfA] as $deviceA => $indexA) {
                        foreach ($vrfs[$vrfB] as $deviceB => $indexB) {
                            $pair = min($deviceA, $deviceB) . '|' . max($deviceA, $deviceB);
                            if ($deviceA === $deviceB || isset($reported[$pair]) || ! isset($this->adjacent[$deviceA][$deviceB])) {
                                continue;
                            }
                            if (((string) $vrfA === '' && ! isset($usesVrfs[$deviceA])) || ((string) $vrfB === '' && ! isset($usesVrfs[$deviceB]))) {
                                continue;
                            }
                            $reported[$pair] = true;
                            $a = $this->addresses[$indexA];
                            $b = $this->addresses[$indexB];
                            $table = fn ($vrf) => (string) $vrf === '' ? 'the global table' : "VRF $vrf";
                            $this->add(new Finding(
                                Finding::WARNING,
                                'ip_vrf_mismatch',
                                "$network is in " . $table($vrfA) . ' on ' . $this->end($deviceA, $a['port']) . ' and in ' . $table($vrfB) . ' on ' . $this->end($deviceB, $b['port']),
                                $this->name($deviceA) . ' and ' . $this->name($deviceB) . ' are neighbors (LLDP/CDP or OSPF) with an address in the same subnet, but in different routing tables: the two interfaces cannot reach each other.',
                                'Put both interfaces in the same VRF, or use another subnet if they are separate networks.',
                                null,
                                [$deviceA, $deviceB],
                                array_values(array_filter([$a['port_id'], $b['port_id']])),
                            ), [(string) $vrfA, (string) $vrfB]);
                        }
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------- tables

    /**
     * One row per OSPF router (per VRF): router ID, ABR / ASBR, areas and its adjacencies.
     */
    private function routerRows(): array
    {
        $rows = [];
        foreach ($this->instances as $key => $instance) {
            $interfaces = array_map(fn ($i) => $this->interfaces[$i], $this->interfacesOf[$key] ?? []);
            $neighbors = array_map(fn ($i) => $this->neighbors[$i], $this->neighborsOf[$key] ?? []);
            $areas = array_values(array_unique(array_filter(array_column($interfaces, 'area'), fn ($area) => $area !== '')));
            sort($areas);
            $rows[] = $instance + [
                'areas' => $areas,
                'interfaces' => count($interfaces),
                'neighbors' => count($neighbors),
                'full' => count(array_filter($neighbors, fn ($n) => $this->adjacencyOk($n))),
            ];
        }
        usort($rows, fn ($a, $b) => [$this->name($a['device_id']), $a['vrf']] <=> [$this->name($b['device_id']), $b['vrf']]);

        return $rows;
    }

    /**
     * One row per OSPF neighbor a router reports, problems first.
     */
    private function neighborRows(): array
    {
        $rows = [];
        foreach ($this->neighbors as $neighbor) {
            $rows[] = [
                'device_id' => $neighbor['device_id'],
                'vrf' => $neighbor['vrf'],
                'port_id' => $neighbor['local']['port_id'] ?? null,
                'port' => $neighbor['local']['port'] ?? null,
                'area' => $neighbor['interface']['area'] ?? null,
                'ip' => $neighbor['ip'],
                'router_id' => $neighbor['router_id'],
                'remote_device' => $neighbor['remote_device'],
                'remote_port_id' => $neighbor['remote']['port_id'] ?? null,
                'state' => self::label($neighbor['state']),
                'ok' => $this->adjacencyOk($neighbor),
            ];
        }
        usort($rows, fn ($a, $b) => [$a['ok'], $this->name($a['device_id']), $a['ip']] <=> [$b['ok'], $this->name($b['device_id']), $b['ip']]);

        return $rows;
    }

    /**
     * One row per BGP session, sessions that are not up first.
     */
    private function sessionRows(): array
    {
        $rows = [];
        foreach ($this->peers as $peer) {
            $limits = array_filter(array_column($peer['prefixes'], 'limit'));
            $rows[] = [
                'device_id' => $peer['device_id'],
                'vrf' => $peer['vrf'],
                'ip' => $peer['ip'],
                'local_ip' => $peer['local']['ip'] ?? null,
                'remote_as' => $peer['remote_as'],
                'type' => $peer['type'],
                'description' => $peer['description'],
                'remote_device' => $peer['remote_device'],
                'state' => $peer['shut'] ? 'shut down' : self::label($peer['state']),
                'established' => $peer['established'],
                'shut' => $peer['shut'],
                'time' => $peer['time'] ?: null,
                'error' => $peer['error']['text'] ?? null,
                'error_text' => $peer['error_text'] ?: null,
                'accepted' => $peer['prefixes'] ? array_sum(array_column($peer['prefixes'], 'accepted')) : null,
                'limit' => $limits ? min($limits) : null,
            ];
        }
        $rank = fn ($r) => $r['established'] ? 2 : ($r['shut'] ? 1 : 0);
        usort($rows, fn ($a, $b) => [$rank($a), $this->name($a['device_id']), $a['ip']] <=> [$rank($b), $this->name($b['device_id']), $b['ip']]);

        return $rows;
    }

    // ---------------------------------------------------------------- helpers

    private function adjacencyOk(array $neighbor): bool
    {
        return $neighbor['state'] === 'full' || ($neighbor['state'] === 'twoway' && ($neighbor['interface']['type'] ?? null) !== 'pointtopoint');
    }

    /**
     * Address of the device that owns $ip (in the VRF first), other than $not.
     */
    private function owner(string $ip, string $vrf, ?int $not = null): ?array
    {
        foreach ([$this->owners[$vrf . '|' . $ip] ?? [], $this->anyVrf[$ip] ?? []] as $indexes) {
            foreach ($indexes as $index) {
                if ($this->addresses[$index]['device_id'] !== $not) {
                    return $this->addresses[$index];
                }
            }
        }

        return null;
    }

    /**
     * The device's own address row for $ip (in the VRF first).
     */
    private function ownAddress(int $deviceId, string $ip, string $vrf): ?array
    {
        $found = null;
        foreach ($this->anyVrf[$ip] ?? [] as $index) {
            $address = $this->addresses[$index];
            if ($address['device_id'] === $deviceId && ($found === null || $address['vrf'] === $vrf)) {
                $found = $address;
            }
        }

        return $found;
    }

    /**
     * The device's interface address whose subnet holds $ip: the longest prefix, in the VRF first.
     */
    private function facing(int $deviceId, string $ip, string $vrf): ?array
    {
        $packed = (string) @inet_pton($ip);
        $best = null;
        $bestScore = null;
        foreach ($this->byDevice[$deviceId] ?? [] as $index) {
            $address = $this->addresses[$index];
            if ($address['host'] || strlen($address['packed']) !== strlen($packed)) {
                continue;
            }
            $mask = self::mask(strlen($packed), $address['prefix']);
            if (($address['packed'] & $mask) !== ($packed & $mask)) {
                continue;
            }
            $score = [$address['vrf'] === $vrf ? 1 : 0, $address['prefix']];
            if ($bestScore === null || $score > $bestScore) {
                [$best, $bestScore] = [$address, $score];
            }
        }

        return $best;
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
     * Does the device have another address than $address in the same subnet?
     */
    private function hasOtherAddress(int $deviceId, array $address): bool
    {
        foreach ($this->byDevice[$deviceId] ?? [] as $index) {
            $other = $this->addresses[$index];
            if ($other['ip'] !== $address['ip'] && $other['vrf'] === $address['vrf'] && $other['network'] === $address['network']) {
                return true;
            }
        }

        return false;
    }

    /**
     * "core1 → 10.0.0.2 (AS 65001, ISP-A)" or "core1 → edge1 (10.0.0.2, AS 65001)" for a monitored peer.
     */
    private function session(array $peer): string
    {
        $details = array_filter([
            $peer['remote_as'] !== null ? 'AS ' . $peer['remote_as'] : null,
            $peer['description'] !== '' && $peer['remote_device'] === null ? $peer['description'] : null,
            $peer['vrf'] !== '' ? 'VRF ' . $peer['vrf'] : null,
        ]);
        $target = $peer['remote_device'] !== null ? $this->name($peer['remote_device']) . ' (' . $peer['ip'] . ($details ? ', ' . implode(', ', $details) : '') . ')'
            : $peer['ip'] . ($details ? ' (' . implode(', ', $details) . ')' : '');

        return $this->name($peer['device_id']) . ' → ' . $target;
    }

    /**
     * "core1 Te1/0/1: 10/40s, dist1 Te1/0/1: 5/20s" when the items do not all have the same value
     * (items without a value are left out), else ''.
     *
     * @param  callable(array): ?string  $value
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
     * @param  callable(array): string  $value
     */
    private function values(array $items, callable $value, bool $withPort = true): string
    {
        return implode(', ', array_map(fn ($item) => $this->end($item['device_id'], $withPort ? ($item['port'] ?? null) : null) . ': ' . $value($item), $items));
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
        return count(array_filter($this->addresses, fn ($a) => isset($this->scope[$a['device_id']])
            && ($device === null || $a['device_id'] === $device) && ($vrf === null || $a['vrf'] === $vrf)));
    }

    /**
     * VRF names in the data, for the filter.
     *
     * @return string[]
     */
    private function vrfNames(): array
    {
        $names = array_merge(
            array_column($this->addresses, 'vrf'),
            array_column($this->instances, 'vrf'),
            array_column($this->peers, 'vrf'),
        );
        $names = array_values(array_unique(array_filter(array_map('strval', $names), fn ($name) => $name !== '')));
        sort($names);

        return $names;
    }

    private function add(Finding $finding, array $vrfs): void
    {
        $code = $finding->code;
        if (! isset($this->disabled[$code]) && ! isset($this->disabled[self::CHECKS[$code] ?? ''])) {
            $this->findings[] = [$finding, array_values(array_unique(array_map('strval', $vrfs)))];
        }
    }

    /**
     * Link-local, loopback, unspecified and multicast addresses never describe the network (the
     * ranges of Cidr::isIgnorable(), compared in binary for speed).
     */
    private static function ignorable(string $packed): bool
    {
        static $ranges = null;
        if ($ranges === null) {
            $ranges = [];
            foreach (['127.0.0.0/8', '169.254.0.0/16', '0.0.0.0/8', '224.0.0.0/4', 'fe80::/10', '::1/128', '::/128', 'ff00::/8'] as $cidr) {
                [$network, $prefix] = explode('/', $cidr);
                $bytes = (string) inet_pton($network);
                $ranges[strlen($bytes)][] = [$bytes, self::mask(strlen($bytes), (int) $prefix)];
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
        $packed = @inet_pton(trim($address));

        return $packed === false ? null : (string) inet_ntop($packed);
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

    /**
     * Binary netmask of $prefix bits for an address of $bytes bytes.
     */
    private static function mask(int $bytes, int $prefix): string
    {
        $key = $bytes . '/' . $prefix;
        if (! isset(self::$masks[$key])) {
            $prefix = max(0, min($bytes * 8, $prefix));
            $mask = str_repeat("\xff", intdiv($prefix, 8));
            if ($prefix % 8) {
                $mask .= chr((0xff << (8 - $prefix % 8)) & 0xff);
            }
            self::$masks[$key] = str_pad($mask, $bytes, "\x00");
        }

        return self::$masks[$key];
    }
}
