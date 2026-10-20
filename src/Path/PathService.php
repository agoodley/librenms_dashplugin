<?php

namespace Agoodley\LibrenmsTopology\Path;

use Agoodley\LibrenmsTopology\Data\PathRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Hosts\HostLocator;
use Agoodley\LibrenmsTopology\Hosts\HostQuery;
use Agoodley\LibrenmsTopology\Hosts\HostService;
use Agoodley\LibrenmsTopology\Stp\Finding;
use Agoodley\LibrenmsTopology\Stp\StpAnalyzer;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\Cidr;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * A→B path trace on the data the user may see: resolves both ends (a host located with the host
 * locator, or a device and port), then follows the Layer 2 path within a subnet or VLAN (L2Tracer)
 * or the routed path from A's gateway router by router (L3Tracer) with the Layer 2 segments between
 * the routers, and describes every hop: ports, speed, utilization, errors, duplex, spanning tree
 * state and the route each router uses.
 */
class PathService
{
    private const ROUTING_ROLES = [DeviceRole::ROUTER, DeviceRole::L3SWITCH, DeviceRole::FIREWALL];

    /** Utilization from which a link is critically full (percent). */
    private const FULL = 95;

    private ?array $network = null;

    /** @var array<string, L2Tracer> VLAN key => tracer */
    private array $tracers = [];

    /** Addresses and adjacencies of the routers, without routes: owners of addresses, gateways. */
    private ?L3Tracer $lookups = null;

    /** @var array{addresses: array, adjacencies: array, roles: array, names: array, routers: int[]}|null */
    private ?array $l3Inputs = null;

    /** @var array<int, array>|null see PathRepository::routeData() */
    private ?array $routeData = null;

    /** @var (\Closure(string): array)|null */
    private ?\Closure $hosts;

    private \Closure $urls;

    /** @var array<int, array{level: string, text: string}> */
    private array $notes = [];

    /** @var Finding[] */
    private array $findings = [];

    /**
     * @param  (\Closure(string): array)|null  $hosts  host locator search (HostService::search); none: hosts are not located
     * @param  (\Closure(string, array): string)|null  $urls  route name and parameters => URL (default: route())
     */
    public function __construct(
        private readonly PathRepository $repo,
        private readonly TopologyRepository $topology,
        private readonly PluginSettings $settings,
        ?\Closure $hosts = null,
        ?\Closure $urls = null,
    ) {
        $this->hosts = $hosts;
        $this->urls = $urls ?? fn (string $name, array $parameters = []) => route($name, $parameters);
    }

    /**
     * @param  array{a?: string, b?: string, a_device?: ?int, a_port?: ?int, b_device?: ?int, b_port?: ?int, vlan?: ?int}  $query
     * @return array{query: array, a: ?array, b: ?array, mode: ?string, vlan: ?int, complete: bool, hops: array, findings: array,
     *     notes: array, summary: ?array, map_url: ?string, message: ?string}
     */
    public function trace(array $query): array
    {
        $query = [
            'a' => trim((string) ($query['a'] ?? '')), 'b' => trim((string) ($query['b'] ?? '')),
            'a_device' => $query['a_device'] ?? null, 'a_port' => $query['a_port'] ?? null,
            'b_device' => $query['b_device'] ?? null, 'b_port' => $query['b_port'] ?? null,
            'vlan' => $query['vlan'] ?? null,
        ];
        $result = ['query' => $query, 'a' => null, 'b' => null, 'mode' => null, 'vlan' => null, 'complete' => false, 'hops' => [],
            'findings' => [], 'device_names' => [], 'notes' => [], 'summary' => null, 'map_url' => null, 'message' => null];
        $hasA = $query['a'] !== '' || $query['a_device'];
        $hasB = $query['b'] !== '' || $query['b_device'];
        if (! $hasA || ! $hasB) {
            $result['message'] = $hasA || $hasB ? 'Enter both ends of the path.' : null;

            return $result;
        }

        $a = $this->endpoint($query['a'], $query['a_device'], $query['a_port']);
        $b = $this->endpoint($query['b'], $query['b_device'], $query['b_port']);
        $result['a'] = self::publicEndpoint($a);
        $result['b'] = self::publicEndpoint($b);
        if (! $a['resolved'] || ! $b['resolved']) {
            $result['message'] = implode(' ', array_filter([$a['resolved'] ? null : 'A: ' . $a['message'], $b['resolved'] ? null : 'B: ' . $b['message']]));

            return $result;
        }

        $this->network['stp_ports'] = array_merge($this->network()['stp_ports'], array_filter([$a['port_id'], $b['port_id']]));
        [$mode, $rows, $complete, $vlan] = $this->path($a, $b, $query['vlan'] ? (int) $query['vlan'] : null);
        $hops = $this->describe($rows);
        $this->check($hops);

        $findings = array_map(fn (Finding $finding) => $finding->toArray(), $this->findings);
        usort($findings, fn ($x, $y) => (Finding::SEVERITY_ORDER[$x['severity']] ?? 9) <=> (Finding::SEVERITY_ORDER[$y['severity']] ?? 9));

        return array_merge($result, [
            'mode' => $mode,
            'vlan' => $vlan,
            'complete' => $complete,
            'hops' => $hops,
            'findings' => $findings,
            'device_names' => $this->names(array_merge([], ...array_column($findings, 'devices'))),
            'notes' => $this->notes,
            'summary' => $this->summary($hops, $findings, $complete, $mode),
            'map_url' => $this->mapUrl($hops, $vlan),
        ]);
    }

    // ------------------------------------------------------------------ endpoints

    /**
     * One end of the path: a device (and port) given by id, the device owning an address or named so, or a host the host
     * locator finds.
     */
    private function endpoint(string $text, mixed $deviceId, mixed $portId): array
    {
        $endpoint = ['input' => $text, 'resolved' => false, 'message' => null, 'kind' => null, 'label' => $text, 'device' => null, 'port' => null, 'port_url' => null, 'mac' => null,
            'readable' => null, 'vendor' => null, 'ip' => null, 'ips' => [], 'device_id' => null, 'port_id' => null, 'vlan' => null, 'status' => null,
            'notes' => [], 'others' => 0, 'arp' => [], 'host_url' => null];

        if ($deviceId) {
            return $this->deviceEndpoint($endpoint, (int) $deviceId, $portId ? (int) $portId : null);
        }

        $parsed = HostQuery::parse($text);
        if ($parsed['kind'] === HostQuery::INVALID) {
            return ['message' => 'Enter an IPv4 or IPv6 address, a MAC address, a hostname or a device name.'] + $endpoint;
        }
        $ip = in_array($parsed['kind'], [HostQuery::IPV4, HostQuery::IPV6], true) ? (Cidr::canonical($parsed['value']) ?? $parsed['value']) : null;
        if ($ip !== null) {
            $owner = $this->lookups()->owners($ip)[0] ?? null;
            if ($owner !== null) {
                // an interface address of a device: the path ends at that device and interface
                $device = $this->deviceEndpoint($endpoint, $owner['device_id'], $owner['port_id']);

                return array_merge($device, ['ip' => $ip, 'ips' => array_values(array_unique(array_merge([$ip], $device['ips'])))]);
            }
        }

        if ($this->hosts === null) {
            return ['message' => $text . ' is not an address or name of a device you can see.'] + $endpoint;
        }
        $search = ($this->hosts)($text);
        $device = $search['devices'][0] ?? null;
        if ($device !== null && in_array($device['role'], HostLocator::NETWORK_ROLES, true)) {
            $endpoint = $this->deviceEndpoint($endpoint, (int) $device['device_id'], null);
            if (count($search['devices']) > 1) {
                $endpoint['notes'][] = ['level' => 'info', 'text' => count($search['devices']) . ' devices are named like this; the path uses ' . $endpoint['label'] . '.'];
            }

            return $endpoint;
        }

        $endpoint['host_url'] = ($this->urls)('topology.hosts', ['q' => $text]);
        $usable = array_values(array_filter($search['hosts'], fn ($host) => in_array($host['status'], ['located', 'behind', 'owner', 'gateway'], true)));
        $host = $usable[0] ?? null;
        if ($host === null) {
            $why = match ($search['hosts'][0]['status'] ?? null) {
                'uplinks' => 'the switches that learned it only list it on links between switches.',
                'none' => 'no switch you can see has it in its forwarding table.',
                default => null,
            };

            return ['message' => $why !== null ? $text . ' cannot be located: ' . $why : ($search['message'] ?? $text . ' cannot be located.')] + $endpoint;
        }

        $ips = array_values(array_unique(array_merge($ip !== null ? [$ip] : [],
            array_map(fn ($address) => Cidr::canonical((string) $address['ip']) ?? (string) $address['ip'], $host['addresses']))));
        usort($ips, fn ($x, $y) => Cidr::family($x) <=> Cidr::family($y)); // IPv4 first
        if ($ip !== null) {
            $ips = array_values(array_unique(array_merge([$ip], $ips)));
        }
        $endpoint = array_merge($endpoint, [
            'resolved' => true, 'kind' => 'host', 'mac' => $host['mac'], 'readable' => $host['readable'], 'vendor' => $host['vendor'],
            'ips' => $ips, 'ip' => $ips[0] ?? null, 'status' => $host['status'], 'others' => count($search['hosts']) - 1,
            'arp' => array_map(fn ($address) => ['device_id' => (int) $address['device_id'], 'port_id' => (int) $address['port_id']], $host['addresses']),
            'notes' => array_slice($host['notes'], 0, 3),
            'label' => $host['readable'] && strtolower($host['readable']) !== strtolower($text) ? $text . ' (' . $host['readable'] . ')' : $text,
        ]);

        switch ($host['status']) {
            case 'located':
                $endpoint['device_id'] = (int) $host['edge']['device_id'];
                $endpoint['port_id'] = (int) $host['edge']['port_id'] ?: null;
                $endpoint['port'] = $endpoint['port_id'] ? $host['edge']['port'] : null;
                $endpoint['port_url'] = $host['edge']['port_url'] ?? null;
                $endpoint['vlan'] = $host['vlan'] ?? ($host['edge']['vlans'][0] ?? null);
                break;
            case 'behind':
                $endpoint['device_id'] = (int) $host['device']['device_id'];
                $endpoint['vlan'] = $host['vlan'] ?? ($host['edge']['vlans'][0] ?? null);
                $endpoint['notes'][] = ['level' => 'warning', 'text' => 'Located only as far as ' . $host['device']['name'] . ': the path ends there.'];
                break;
            default:
                // the address belongs to a monitored switch, router or firewall (its own or a virtual router address)
                $device = $this->deviceEndpoint($endpoint, (int) $host['device']['device_id'], null);

                return array_merge($device, ['ip' => $endpoint['ip'] ?? $device['ip'], 'ips' => array_values(array_unique(array_merge($ips, $device['ips'])))]);
        }
        $endpoint['device'] = $this->deviceName($endpoint['device_id']);

        return $endpoint;
    }

    /**
     * A monitored device (and port) as an end of the path; its addresses are where routing toward it ends.
     */
    private function deviceEndpoint(array $endpoint, int $deviceId, ?int $portId): array
    {
        $device = $this->repo->device($deviceId);
        if ($device === null || ! isset($this->network()['devices'][$deviceId])) {
            return ['message' => 'Device #' . $deviceId . ' is not a device you can see.'] + $endpoint;
        }
        $port = null;
        if ($portId !== null) {
            $port = $this->port($portId);
            if ($port === null || (int) $port['device_id'] !== $deviceId) {
                return ['message' => 'Port #' . $portId . ' is not a port of ' . $device['name'] . '.'] + $endpoint;
            }
        }

        // the address of the port, the one LibreNMS polls the device on, any other interface address
        $interfaces = array_values(array_filter($this->network()['addresses'], fn ($row) => (int) $row['device_id'] === $deviceId));
        $ips = array_merge(
            array_column(array_filter($interfaces, fn ($row) => $portId !== null && (int) $row['port_id'] === $portId), 'address'),
            $device['ips'],
            array_column($interfaces, 'address'),
        );
        $ips = array_values(array_unique(array_filter(array_map(fn ($ip) => Cidr::canonical((string) $ip), $ips), fn ($ip) => $ip !== null && ! Cidr::isIgnorable($ip))));
        $vlan = $port !== null && is_numeric($port['ifVlan'] ?? null) && (int) $port['ifVlan'] > 0 ? (int) $port['ifVlan'] : null;

        return array_merge($endpoint, [
            'resolved' => true, 'kind' => 'device', 'status' => 'device', 'device_id' => $deviceId, 'port_id' => $portId, 'vlan' => $vlan,
            'ips' => $ips, 'ip' => $ips[0] ?? null, 'device' => $device['name'],
            'port' => $port !== null ? InterfaceName::short(InterfaceName::label($port)) : null, 'port_url' => $port['url'] ?? null,
            'label' => $device['name'] . ($port !== null ? ' ' . InterfaceName::short(InterfaceName::label($port)) : ''),
        ]);
    }

    /**
     * What the page and the API show of an end: no ARP rows.
     */
    private static function publicEndpoint(array $endpoint): array
    {
        unset($endpoint['arp']);

        return $endpoint;
    }

    // ------------------------------------------------------------------ the path

    /**
     * Layer 2 within one subnet or VLAN, else routed from A's gateway.
     *
     * @return array{0: string, 1: array<int, array>, 2: bool, 3: ?int} mode, rows, complete, VLAN of an L2 path
     */
    private function path(array $a, array $b, ?int $vlan): array
    {
        $aIp = $a['ip'];
        $bIp = $b['ip'];
        $routed = $aIp !== null && $bIp !== null && Cidr::family($aIp) === Cidr::family($bIp);
        if ($aIp !== null && $bIp !== null && ! $routed) {
            $this->notes[] = ['level' => 'info', 'text' => 'A has an ' . (Cidr::family($aIp) === 4 ? 'IPv4' : 'IPv6') . ' address and B an '
                . (Cidr::family($bIp) === 4 ? 'IPv4' : 'IPv6') . ' one: only the Layer 2 path can be traced.'];
        }

        $gateway = $routed ? $this->gateway($a) : null;
        if ($routed && $gateway === null) {
            $this->notes[] = ['level' => 'warning', 'text' => 'No router you can see has an interface in the subnet of ' . self::display($aIp)
                . ', so the routed path from A cannot be followed: the Layer 2 path is shown instead.'];
            $routed = false;
        } elseif ($routed && $gateway['address'] !== null && Cidr::contains((string) Cidr::network($gateway['address'], (int) $gateway['prefixlen']), $bIp)
            && $gateway['device_id'] !== $b['device_id']) {
            $routed = false; // the same subnet: B is reached at Layer 2
            $vlan ??= $this->l2Port($gateway['device_id'], $gateway['port_id'])[1];
        }
        foreach (['A' => $a, 'B' => $b] as $side => $end) {
            if ($end['ip'] === null) {
                $this->notes[] = ['level' => 'info', 'text' => $side . ' has no IP address known to LibreNMS' . ($end['kind'] === 'host' ? ' (no ARP entry of it on the routers you can see)' : '')
                    . ': only the Layer 2 path can be traced.'];
            }
        }

        if (! $routed) {
            $vlan ??= $a['vlan'] ?? $b['vlan'];
            if ($a['vlan'] !== null && $b['vlan'] !== null && $a['vlan'] !== $b['vlan']) {
                $this->notes[] = ['level' => 'warning', 'text' => 'A is in VLAN ' . $a['vlan'] . ' and B in VLAN ' . $b['vlan']
                    . ($aIp === null || $bIp === null ? ', and the IP address of ' . ($aIp === null ? 'A' : 'B') . ' is not known' : '')
                    . ': the Layer 2 path in VLAN ' . $vlan . ' is shown.'];
            }
            [$rows, $complete] = $this->l2Path($a, $b, $vlan);

            return ['l2', $rows, $complete, $vlan];
        }

        [$rows, $complete] = $this->l3Path($a, $b, $gateway);

        return ['l3', $rows, $complete, null];
    }

    /**
     * Rows of the L2 path: A, the devices between, B.
     *
     * @return array{0: array<int, array>, 1: bool}
     */
    private function l2Path(array $a, array $b, ?int $vlan): array
    {
        $rows = $a['kind'] === 'host' ? [$this->hostRow($a)] : [];
        $ports = ['enter' => $a['port_id'], 'exit' => $b['port_id']];
        $segment = $this->segment($a['device_id'], $b['device_id'], $vlan, $ports);
        $rows = $this->append($rows, $segment ?? $this->broken($a['device_id'], $b['device_id'], $vlan, $ports));
        if ($segment !== null && $b['kind'] === 'host') {
            $rows[] = $this->hostRow($b);
        }

        return [$rows, $segment !== null];
    }

    /**
     * Rows of the routed path: A, the L2 segment to its gateway, the routers with the segments between them, the segment
     * from the last router to B, B.
     *
     * @param  array{device_id: int, port_id: ?int, vrf: string, address: ?string}  $gateway
     * @return array{0: array<int, array>, 1: bool}
     */
    private function l3Path(array $a, array $b, array $gateway): array
    {
        $this->gatewayNote($a, $gateway);
        // a router as A starts the path itself: the traffic does not come in on its interface
        $itself = $a['kind'] === 'device' && $gateway['device_id'] === $a['device_id'];
        $trace = $this->l3For($b['ip'])->trace($gateway['device_id'], $itself ? null : ($gateway['port_id'] ?: null), $gateway['vrf'], $b['ip']);
        $hops = $trace['hops'];
        $rows = $a['kind'] === 'host' ? [$this->hostRow($a)] : [];
        $complete = true;

        // A to its gateway
        if (! $itself) {
            [$arrive, $vlan] = $this->l2Port($gateway['device_id'], $gateway['port_id']);
            $ports = ['enter' => $a['port_id'], 'arrive' => $arrive];
            $segment = $this->segment($a['device_id'], $gateway['device_id'], $vlan ?? $a['vlan'], $ports);
            $complete = $segment !== null;
            $rows = $this->append($rows, $segment ?? $this->broken($a['device_id'], $gateway['device_id'], $vlan ?? $a['vlan'], $ports));
        }

        // router to router
        foreach ($hops as $index => $hop) {
            $rows = $this->append($rows, [$this->routerRow($hop)]);
            $next = $hops[$index + 1] ?? null;
            if ($next !== null) {
                $rows = $this->append($rows, $this->between($hop, $next));
            }
        }

        // the last router to B
        $last = end($hops);
        $tail = null;
        $reached = false;
        switch ($trace['end']) {
            case 'connected':
                $tail = $trace['egress'];
                break;
            case 'local':
                $reached = (int) $last['device_id'] === $b['device_id'];
                break;
            case 'outside':
                $complete = false;
                $resume = $this->gateway($b);
                $resumes = $resume !== null && $resume['device_id'] !== (int) $last['device_id'];
                $rows[] = ['kind' => 'outside', 'name' => 'next hop ' . self::display((string) $trace['next_hop'])] + self::emptyRow();
                $this->add(Finding::WARNING, 'path_outside', 'the path leaves the devices you can see at ' . self::display((string) $trace['next_hop']),
                    $this->deviceName((int) $last['device_id']) . ' routes ' . self::display($b['ip']) . ' to ' . self::display((string) $trace['next_hop'])
                    . ', which is not an address of a device you can see in LibreNMS (not monitored, or out of your sight).'
                    . ($resumes ? ' The path resumes at ' . $this->deviceName($resume['device_id']) . ', which has the destination\'s subnet connected.' : ''),
                    'Add the routers on the way to LibreNMS to see the whole path.', [(int) $last['device_id']]);
                if ($resumes) {
                    // B itself (a router) is reached on its address; otherwise the router delivers to B's subnet
                    $itself = $b['kind'] === 'device' && $resume['device_id'] === $b['device_id'];
                    $rows[] = $this->routerRow(['device_id' => $resume['device_id'], 'vrf' => $resume['vrf'],
                        'in_port' => $itself ? $resume['port_id'] : null, 'in_address' => $itself ? $resume['address'] : null,
                        'out_port' => $itself ? null : $resume['port_id'], 'out_address' => $itself ? null : $resume['address'],
                        'next_hop' => null, 'route' => null, 'estimated' => null]);
                    $tail = $resume;
                }
                break;
            default:
                $complete = false;
                $this->routingFinding($trace, $b);
        }

        if ($tail !== null) {
            if ($b['kind'] === 'device' && $tail['device_id'] === $b['device_id'] && $b['port_id'] === null) {
                $reached = true;
            } else {
                [$leave, $vlan] = $this->l2Port($tail['device_id'], $tail['port_id'] ?? null);
                $ports = ['leave' => $leave, 'exit' => $b['port_id']];
                $segment = $this->segment($tail['device_id'], $b['device_id'], $vlan ?? $b['vlan'], $ports);
                $reached = $segment !== null;
                $rows = $this->append($rows, $segment ?? $this->broken($tail['device_id'], $b['device_id'], $vlan ?? $b['vlan'], $ports));
            }
        }
        if ($reached && $b['kind'] === 'host') {
            $rows[] = $this->hostRow($b);
        }

        return [$rows, $complete && $reached];
    }

    /**
     * Rows between two consecutive routers: their direct link, the switches between their interfaces, or the two routers
     * alone with a note when no LLDP/CDP link the user may see joins them.
     */
    private function between(array $hop, array $next): array
    {
        $from = (int) $hop['device_id'];
        $to = (int) $next['device_id'];
        [$leave, $leaveVlan] = $this->l2Port($from, $hop['out_port']);
        [$arrive, $arriveVlan] = $this->l2Port($to, $next['in_port']);
        if ($leaveVlan === null && $arriveVlan === null) {
            $edge = $this->tracer(null)->directEdge($from, $to, $leave, $arrive);
            if ($edge !== null) {
                return [
                    ['node' => 'd:' . $from, 'kind' => 'device', 'device_id' => $from, 'out_edge' => $edge, 'out_port' => L2Tracer::portOn($edge, 'd:' . $from)] + self::emptyRow(),
                    ['node' => 'd:' . $to, 'kind' => 'device', 'device_id' => $to, 'in_edge' => $edge, 'in_port' => L2Tracer::portOn($edge, 'd:' . $to)] + self::emptyRow(),
                ];
            }
        }
        $segment = $this->segment($from, $to, $leaveVlan ?? $arriveVlan, ['leave' => $leave, 'arrive' => $arrive]);
        if ($segment !== null) {
            return $segment;
        }

        return [
            ['node' => 'd:' . $from, 'kind' => 'device', 'device_id' => $from, 'link_note' => 'No LLDP/CDP link you can see joins ' . $this->deviceName($from)
                . ' and ' . $this->deviceName($to) . ': the devices between them are not known.'] + self::emptyRow(),
            ['node' => 'd:' . $to, 'kind' => 'device', 'device_id' => $to] + self::emptyRow(),
        ];
    }

    /**
     * The router A's traffic leaves its subnet through: A itself when A is a router, else a routing device with A's
     * subnet connected, preferably the one with A's ARP entry.
     *
     * @return array{device_id: int, port_id: ?int, vrf: string, address: ?string}|null
     */
    private function gateway(array $endpoint): ?array
    {
        if ($endpoint['ip'] === null) {
            return null;
        }
        if ($endpoint['kind'] === 'device' && in_array($this->role($endpoint['device_id']), self::ROUTING_ROLES, true)) {
            foreach ($this->lookups()->owners($endpoint['ip']) as $own) {
                if ($own['device_id'] === $endpoint['device_id']) {
                    return $own;
                }
            }

            return ['device_id' => $endpoint['device_id'], 'port_id' => null, 'vrf' => '', 'address' => null];
        }

        return $this->gatewayCandidates($endpoint)[0] ?? null;
    }

    /**
     * Routing devices with the endpoint's subnet connected, the one with its ARP entry first.
     *
     * @return array<int, array>
     */
    private function gatewayCandidates(array $endpoint): array
    {
        $candidates = $this->lookups()->gateways($endpoint['ip']);
        $arp = array_column($endpoint['arp'], 'device_id');
        usort($candidates, fn ($x, $y) => [! in_array($x['device_id'], $arp, true), -$x['prefixlen'], $x['device_id']]
            <=> [! in_array($y['device_id'], $arp, true), -$y['prefixlen'], $y['device_id']]);

        return $candidates;
    }

    /**
     * Which of several routers on A's subnet the path leaves through, and why.
     */
    private function gatewayNote(array $endpoint, array $gateway): void
    {
        if ($endpoint['kind'] === 'device' && $gateway['device_id'] === $endpoint['device_id']) {
            return;
        }
        $others = array_values(array_unique(array_filter(array_column($this->gatewayCandidates($endpoint), 'device_id'), fn ($id) => $id !== $gateway['device_id'])));
        if ($others) {
            $arp = in_array($gateway['device_id'], array_column($endpoint['arp'], 'device_id'), true);
            $this->notes[] = ['level' => 'info', 'text' => $this->deviceName($others[0]) . (count($others) > 1 ? ' and ' . (count($others) - 1) . ' more' : '')
                . ' also ' . (count($others) > 1 ? 'have' : 'has') . ' an interface in the subnet of ' . self::display($endpoint['ip']) . ': the path leaves it through '
                . $this->deviceName($gateway['device_id']) . ($arp ? ', which has its ARP entry' : '') . '. With HSRP / VRRP the active router can be another one.'];
        }
    }

    /**
     * The Layer 2 path between two devices in a VLAN, null when there is no forwarding path.
     *
     * @param  array{leave?: ?int, arrive?: ?int, enter?: ?int, exit?: ?int}  $ports
     * @return array<int, array>|null rows
     */
    private function segment(int $from, int $to, ?int $vlan, array $ports): ?array
    {
        $trace = $this->tracer($vlan)->trace($from, $to, $vlan, $ports);
        if (! $trace['found']) {
            return null;
        }
        if ($trace['alternatives'] > 0) {
            $this->notes[] = ['level' => 'info', 'text' => 'Between ' . $this->deviceName($from) . ' and ' . $this->deviceName($to) . ' '
                . ($trace['alternatives'] === 1 ? 'another path' : $trace['alternatives'] . ' other paths') . ' of the same length exist'
                . ($trace['mlag'] ? ': a port-channel spans a switch pair (MLAG / vPC), so traffic is spread over both.'
                    : ($trace['unknown_stp'] ? ': some of their ports have no spanning tree data, so the one in use cannot be told. The path shown prefers ports known to forward, then faster links.'
                        : '. The path shown is one of them.'))];
        }

        return array_map(fn ($hop) => array_merge(self::emptyRow(), $hop, ['in_vlan' => $vlan, 'out_vlan' => $vlan]), $trace['hops']);
    }

    /**
     * Rows for a missing Layer 2 path: the two ends and a gap, with the finding that explains it.
     *
     * @param  array{leave?: ?int, arrive?: ?int, enter?: ?int, exit?: ?int}  $ports
     */
    private function broken(int $from, int $to, ?int $vlan, array $ports): array
    {
        $blockers = $this->tracer($vlan)->blockers($from, $to, $vlan);
        $where = $vlan === null ? '' : ' in VLAN ' . $vlan;
        if ($blockers) {
            $list = array_map(fn ($blocker) => $this->deviceName($blocker['device_id']) . ' ' . $this->portName($blocker['port_id']) . ' (' . $blocker['why'] . ')', array_slice($blockers, 0, 8));
            $this->add(Finding::CRITICAL, 'no_l2_path', 'no forwarding path' . $where . ' between ' . $this->deviceName($from) . ' and ' . $this->deviceName($to),
                'Every chain of links between them is stopped: ' . implode(', ', $list) . (count($blockers) > 8 ? ' and ' . (count($blockers) - 8) . ' more' : '') . '.',
                'Check that the VLAN is allowed on the trunks between them and that their links are up; a port blocked by spanning tree on every path means a link is missing or down.',
                array_values(array_unique(array_merge([$from, $to], array_column($blockers, 'device_id')))), array_column($blockers, 'port_id'), $vlan);
        } else {
            $this->add(Finding::WARNING, 'not_linked', $this->deviceName($from) . ' and ' . $this->deviceName($to) . ' are not linked by LLDP/CDP',
                'No chain of LLDP/CDP links you can see joins them' . $where . ', so the switches between them are not known.',
                'Enable LLDP or CDP on the switches between them and check that LibreNMS polls them (and that you may see them).', [$from, $to], [], $vlan);
        }

        return [
            ['node' => 'd:' . $from, 'kind' => 'device', 'device_id' => $from, 'in_port' => $ports['enter'] ?? null, 'out_port' => $ports['leave'] ?? null,
                'in_vlan' => $vlan, 'out_vlan' => $vlan] + self::emptyRow(),
            ['kind' => 'gap', 'name' => $blockers ? 'no forwarding path' . $where : 'not linked by LLDP/CDP', 'in_vlan' => $vlan, 'out_vlan' => $vlan] + self::emptyRow(),
            ['node' => 'd:' . $to, 'kind' => 'device', 'device_id' => $to, 'in_port' => $ports['arrive'] ?? null, 'out_port' => $ports['exit'] ?? null,
                'in_vlan' => $vlan, 'out_vlan' => $vlan] + self::emptyRow(),
        ];
    }

    /**
     * Join a segment to the rows: when it starts on the device the rows end on, that row leaves by the segment's first
     * link and keeps where it came in, its routing decision and its VLAN.
     */
    private function append(array $rows, array $segment): array
    {
        $last = count($rows) - 1;
        if ($segment && $last >= 0 && $rows[$last]['node'] !== null && $rows[$last]['node'] === $segment[0]['node']) {
            $first = array_shift($segment);
            if ($first['out_edge'] !== null || $first['out_port'] !== null) {
                $rows[$last]['out_edge'] = $first['out_edge'];
                $rows[$last]['out_port'] = $first['out_port'];
                $rows[$last]['out_vlan'] = $first['out_vlan'];
            }
            foreach (['in_port', 'in_edge', 'in_vlan', 'out_vlan', 'l3', 'link_note'] as $key) {
                $rows[$last][$key] ??= $first[$key];
            }
        }

        return array_merge($rows, $segment);
    }

    private function hostRow(array $endpoint): array
    {
        return ['kind' => 'host', 'name' => $endpoint['label'], 'endpoint' => $endpoint, 'in_vlan' => $endpoint['vlan'], 'out_vlan' => $endpoint['vlan']] + self::emptyRow();
    }

    private function routerRow(array $hop): array
    {
        return ['node' => 'd:' . $hop['device_id'], 'kind' => 'device', 'device_id' => (int) $hop['device_id'], 'l3' => $hop] + self::emptyRow();
    }

    private static function emptyRow(): array
    {
        return ['node' => null, 'kind' => null, 'device_id' => null, 'name' => null, 'role' => null, 'in_edge' => null, 'out_edge' => null,
            'in_port' => null, 'out_port' => null, 'in_vlan' => null, 'out_vlan' => null, 'l3' => null, 'link_note' => null, 'endpoint' => null];
    }

    /**
     * The physical port and VLAN behind a router interface: an SVI (Vlan10, irb.10) has no port of its own, a
     * sub-interface (Gi0/0.130, ae0.100) is its parent port in that VLAN, any other port is itself (no VLAN).
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function l2Port(int $deviceId, ?int $portId): array
    {
        $port = $portId ? $this->port($portId) : null;
        if ($port === null) {
            return [null, null];
        }
        $name = InterfaceName::label($port);
        $vlan = HostService::interfaceVlan($name);
        if (preg_match('#^(.+)\.(\d{1,4})$#', $name, $match) && ! preg_match('/^(irb|vlan)$/i', $match[1])) {
            $parent = $this->repo->portsByName($deviceId, [$match[1]])[strtolower($match[1])] ?? null;
            $tag = is_numeric($port['ifVlan'] ?? null) && (int) $port['ifVlan'] > 0 ? (int) $port['ifVlan'] : $vlan;

            return [$parent, $tag];
        }
        if ($vlan !== null || ($port['ifType'] ?? '') === 'l3ipvlan') {
            return [null, $vlan];
        }

        return [$portId, null];
    }

    /**
     * Finding for a routed path that stops before B.
     */
    private function routingFinding(array $trace, array $b): void
    {
        $last = end($trace['hops']);
        $id = (int) $last['device_id'];
        $name = $this->deviceName($id);
        $to = self::display((string) $b['ip']);
        match ($trace['end']) {
            'no_route' => $this->add(Finding::CRITICAL, 'no_route', "$name has no route to $to",
                "The route table of $name in LibreNMS has no prefix that covers $to and no default route, so it drops the traffic.",
                'Add a route (or a default route) toward the destination, or check the routing protocol that should provide it.', [$id]),
            'blackhole' => $this->add(Finding::CRITICAL, 'blackhole_route', "$name discards traffic to $to",
                "The best route of $name toward $to (" . ($last['route']['prefix'] ?? '?') . ') is a reject / null route.',
                'Remove the null route, or add a more specific route if the destination should be reachable.', [$id]),
            'loop' => $this->add(Finding::CRITICAL, 'routing_loop', "routing loop toward $to",
                'The next hops lead back to ' . $this->deviceName((int) $trace['hops'][$trace['loop']]['device_id']) . ': '
                . implode(' → ', array_map(fn ($hop) => $this->deviceName((int) $hop['device_id']), array_slice($trace['hops'], (int) $trace['loop'])))
                . ' → ' . $this->deviceName((int) $trace['hops'][$trace['loop']]['device_id']) . '.',
                'Check the routes of these routers toward the destination: they point at each other.', array_map(fn ($hop) => (int) $hop['device_id'], $trace['hops'])),
            'limit' => $this->add(Finding::WARNING, 'hop_limit', 'more than ' . L3Tracer::MAX_HOPS . ' routers toward ' . $to,
                'The path was followed for ' . L3Tracer::MAX_HOPS . ' routers without reaching the destination.', 'Check for a routing loop through devices you cannot see.', []),
            default => $this->add(Finding::WARNING, 'path_unknown', "the way on from $name toward $to is not known",
                "LibreNMS has no routes of $name, and no router you can see on its subnets or among its OSPF / BGP neighbors leads to $to's subnet.",
                'Enable the route discovery module for the routers on the path (lnms config:set discovery_modules.route true, or per device), and add the router with the destination\'s subnet to LibreNMS.',
                [$id]),
        };
    }

    // ------------------------------------------------------------------ describing the hops

    /**
     * Hop rows for the page and the API: names, links, ports with their counters, the routing decision.
     *
     * @param  array<int, array>  $rows
     */
    private function describe(array $rows): array
    {
        $portIds = [];
        foreach ($rows as $row) {
            if ($row['kind'] !== 'device') {
                continue;
            }
            foreach (['in', 'out'] as $side) {
                $portIds[] = $row[$side . '_port'];
                $portIds[] = $row['l3'][$side . '_port'] ?? null;
                if ($row[$side . '_edge'] !== null) {
                    $portIds = array_merge($portIds, L2Tracer::membersOn($row[$side . '_edge'], $row['node']));
                }
            }
        }
        $portIds = array_values(array_unique(array_filter($portIds)));
        $this->loadPorts($portIds);
        $statistics = $this->topology->portStatistics($portIds);

        $hops = [];
        foreach ($rows as $index => $row) {
            $next = $rows[$index + 1] ?? null;
            $hop = ['type' => $row['kind'], 'name' => $row['name'], 'device_id' => null, 'url' => null, 'role' => null, 'node' => null,
                'in' => null, 'out' => null, 'layer' => $row['l3'] !== null ? 'l3' : 'l2', 'vlan' => $row['in_vlan'] ?? $row['out_vlan'], 'vrf' => null, 'route' => null,
                'connected' => null, 'estimated' => null, 'link' => null, 'notes' => []];
            switch ($row['kind']) {
                case 'host':
                    $endpoint = $row['endpoint'];
                    $hop = array_merge($hop, ['name' => $endpoint['label'], 'mac' => $endpoint['readable'], 'ips' => array_map([self::class, 'display'], $endpoint['ips']),
                        'vendor' => $endpoint['vendor'], 'url' => $endpoint['host_url']]);
                    break;
                case 'device':
                    $device = $this->network()['devices'][$row['device_id']] ?? [];
                    $l3 = $row['l3'];
                    $hop = array_merge($hop, [
                        'name' => $this->deviceName($row['device_id']), 'device_id' => $row['device_id'], 'url' => $device['url'] ?? null,
                        'role' => $device['role'] ?? null, 'node' => $row['node'],
                        'in' => $this->portView($row['device_id'], $row['in_port'], $row['in_edge'], $row['node'], $row['in_vlan'], $statistics, $l3['in_port'] ?? null, $l3['in_address'] ?? null),
                        'out' => $this->portView($row['device_id'], $row['out_port'], $row['out_edge'], $row['node'], $row['out_vlan'], $statistics, $l3['out_port'] ?? null, $l3['out_address'] ?? null),
                    ]);
                    if ($l3 !== null) {
                        $hop['vrf'] = $l3['vrf'] !== '' ? $l3['vrf'] : null;
                        $hop['estimated'] = $l3['estimated'];
                        // the last router: the subnet it delivers to
                        $interface = $l3['route'] === null && $l3['estimated'] === null && $l3['out_port'] ? $this->lookups()->interfaceOn($row['device_id'], (int) $l3['out_port']) : null;
                        $hop['connected'] = $interface !== null ? Cidr::network($interface['address'], $interface['prefixlen']) : null;
                        if ($l3['route'] !== null || $l3['next_hop'] !== null) {
                            $route = $l3['route'] ?? [];
                            $hop['route'] = [
                                'prefix' => $route['prefix'] ?? null,
                                'next_hop' => $l3['next_hop'] !== null ? self::display($l3['next_hop']) : null,
                                'next_hops' => array_map([self::class, 'display'], $route['next_hops'] ?? []),
                                'protocol' => $route['protocol'] ?? null,
                                'metric' => $route['metric'] ?? null,
                                'updated' => $route['updated'] ?? null,
                            ];
                        }
                    }
                    break;
                case 'unmanaged':
                    $hop = array_merge($hop, ['node' => $row['node'], 'role' => $row['role'],
                        'in' => $row['in_edge'] !== null ? ['name' => self::neighborPort($row['in_edge'], $row['node'])] : null,
                        'out' => $row['out_edge'] !== null ? ['name' => self::neighborPort($row['out_edge'], $row['node'])] : null]);
                    break;
                case 'hidden':
                    $hop['name'] = 'a device you may not see';
                    break;
            }
            if ($row['out_edge'] !== null && $next !== null && in_array($next['kind'], ['device', 'unmanaged', 'hidden'], true)) {
                $hop['link'] = $this->linkView($row['out_edge']);
            }
            if ($row['link_note'] !== null) {
                $hop['notes'][] = ['level' => 'info', 'text' => $row['link_note']];
            }
            $hops[] = $hop;
        }

        return $hops;
    }

    /**
     * A port of a hop: name, link to its page, speed, status, duplex, traffic, errors, spanning tree state, members of a
     * port-channel, and the router interface (and address) the traffic uses on it.
     *
     * @param  array<int, array>  $statistics
     */
    private function portView(int $deviceId, ?int $portId, ?array $edge, string $node, ?int $vlan, array $statistics, ?int $interfaceId, ?string $address): ?array
    {
        $interface = null;
        $own = $interfaceId !== null && ($interfaceId === $portId || $portId === null);
        if ($interfaceId !== null && ! $own && ($port = $this->port($interfaceId)) !== null) {
            $interface = ['name' => InterfaceName::short(InterfaceName::label($port)), 'url' => $port['url'] ?? null,
                'address' => $address !== null ? self::display($address) : null];
        }
        $portId ??= $interfaceId;
        $port = $portId !== null ? $this->port($portId) : null;
        if ($port === null) {
            return $interface === null ? null : ['name' => $interface['name'], 'interface' => $interface];
        }
        $speed = (int) ($port['ifSpeed'] ?? 0);
        $in = (int) ($port['ifInOctets_rate'] ?? 0) * 8;
        $out = (int) ($port['ifOutOctets_rate'] ?? 0) * 8;
        $stats = $statistics[$portId] ?? [];
        $members = [];
        foreach ($edge !== null ? L2Tracer::membersOn($edge, $node) : [] as $member) {
            $memberPort = $member !== $portId ? $this->port($member) : null;
            if ($memberPort !== null) {
                $members[] = ['name' => InterfaceName::short(InterfaceName::label($memberPort)), 'url' => $memberPort['url'] ?? null,
                    'status' => $memberPort['ifOperStatus'] ?? null, 'speed' => (int) ($memberPort['ifSpeed'] ?? 0)];
            }
        }
        $stp = $this->stpFor($vlan)->portStatus($deviceId, $portId, $vlan);

        return [
            'port_id' => $portId,
            'name' => InterfaceName::short(InterfaceName::label($port)),
            'url' => $port['url'] ?? null,
            'alias' => trim((string) ($port['ifAlias'] ?? '')),
            'speed' => $speed,
            'status' => $port['ifOperStatus'] ?? null,
            'admin' => $port['ifAdminStatus'] ?? null,
            'duplex' => self::duplex($port['ifDuplex'] ?? null),
            'in_bps' => $in,
            'out_bps' => $out,
            'in_util' => $speed > 0 ? round($in / $speed * 100, 1) : null,
            'out_util' => $speed > 0 ? round($out / $speed * 100, 1) : null,
            'in_errors' => max(0, (int) ($port['ifInErrors_delta'] ?? 0)),
            'out_errors' => max(0, (int) ($port['ifOutErrors_delta'] ?? 0)),
            'in_discards' => max(0, (int) ($stats['ifInDiscards_delta'] ?? 0)),
            'out_discards' => max(0, (int) ($stats['ifOutDiscards_delta'] ?? 0)),
            'in_packets' => max(0, (int) ($port['ifInUcastPkts_delta'] ?? 0)),
            'out_packets' => max(0, (int) ($port['ifOutUcastPkts_delta'] ?? 0)),
            'period' => (int) ($port['poll_period'] ?? 0),
            'stp' => $stp ? ['state' => $stp['state'], 'role' => $stp['role'], 'vlan' => $stp['vlan']] : null,
            'members' => $members,
            'address' => $own && $address !== null ? self::display($address) : null,
            'interface' => $interface,
        ];
    }

    /**
     * The link from a hop to the next: speed, port-channel members up.
     */
    private function linkView(array $edge): array
    {
        $up = 0;
        foreach ($edge['members'] as $member) {
            $states = array_filter([$this->port((int) $member['a'])['ifOperStatus'] ?? null, $this->port((int) $member['b'])['ifOperStatus'] ?? null]);
            $up += array_diff($states, ['up']) ? 0 : 1;
        }
        $count = count($edge['members']);

        return [
            'speed' => $this->tracer(null)->speed($edge),
            'members' => $count,
            'up' => $up,
            'lag' => $edge['lag'] || $count > 1,
            'status' => $up === $count ? 'up' : ($up === 0 ? 'down' : 'degraded'),
        ];
    }

    /**
     * Port of an unmanaged neighbor on a link, as LLDP/CDP reported it.
     */
    private static function neighborPort(array $edge, string $node): string
    {
        $names = array_values(array_filter(array_column($edge['members'], 'b_name')));

        return $edge['to'] === $node ? InterfaceName::short($names[0] ?? '') : '';
    }

    // ------------------------------------------------------------------ findings and summary

    /**
     * Problems on the path: ports down, half duplex, errors, discards, busy links, port-channels missing members,
     * estimated hops.
     */
    private function check(array $hops): void
    {
        $errorLimit = $this->settings->int('links_error_threshold', 0);
        $discardPercent = $this->settings->int('links_discard_percent', 0, 100);
        $busy = $this->settings->int('path_util_percent', 1, 100);
        $seen = [];
        $estimated = [];
        $lags = []; // port-channels already reported with the other end of their link
        foreach ($hops as $index => $hop) {
            if ($hop['type'] !== 'device') {
                continue;
            }
            if ($hop['estimated'] !== null) {
                $estimated[] = $hop['device_id'];
            }
            foreach (['in', 'out'] as $side) {
                $port = $hop[$side];
                if ($port === null || ! isset($port['port_id']) || isset($seen[$port['port_id']])) {
                    continue;
                }
                $seen[$port['port_id']] = true;
                $label = $hop['name'] . ' ' . $port['name'];
                $devices = [$hop['device_id']];
                $ports = [$port['port_id']];
                if ($port['status'] !== null && $port['status'] !== 'up') {
                    $this->add(Finding::CRITICAL, 'port_down', 'port down on the path: ' . $label,
                        "$label is " . $port['status'] . ($port['admin'] === 'down' ? ' (shut down)' : '') . '.', 'Bring the port up, or move the host to a working port.', $devices, $ports);
                }
                if ($port['duplex'] === 'half') {
                    $this->add(Finding::WARNING, 'half_duplex', 'half duplex on the path: ' . $label,
                        "$label runs half duplex: collisions slow every transfer on the path.",
                        'Set both ends to auto-negotiation, or both to the same fixed speed and full duplex.', $devices, $ports);
                }
                if ($errorLimit > 0 && $port['in_errors'] + $port['out_errors'] >= $errorLimit) {
                    $this->add(Finding::WARNING, 'port_errors', 'interface errors on the path: ' . $label,
                        "$label: " . number_format($port['in_errors']) . ' input and ' . number_format($port['out_errors']) . ' output errors' . self::period($port['period']) . '.',
                        'Check the cable, the patch panel and the optics on both ends, and look for a duplex mismatch.', $devices, $ports);
                }
                foreach (['in' => ['incoming', 'inbound'], 'out' => ['outgoing', 'outbound']] as $direction => [$packetsWord, $trafficWord]) {
                    $discards = $port[$direction . '_discards'];
                    $packets = $port[$direction . '_packets'] + $discards;
                    if ($discardPercent > 0 && $discards >= 100 && $discards / max(1, $packets) * 100 >= $discardPercent) {
                        $this->add(Finding::WARNING, 'port_discards', 'packet discards on the path: ' . $label,
                            "$label discarded " . round($discards / max(1, $packets) * 100, 1) . "% of $packetsWord packets (" . number_format($discards) . ')' . self::period($port['period']) . '.',
                            'Outgoing discards mean congestion or microbursts: check the utilization and QoS of this link.', $devices, $ports);
                    }
                    $util = $port[$direction . '_util'];
                    if ($util !== null && $util >= $busy) {
                        $this->add($util >= self::FULL ? Finding::CRITICAL : Finding::WARNING, 'busy_link', 'busy link on the path: ' . $label,
                            "$label is at $util% $trafficWord (" . self::speedText($port[$direction . '_bps']) . ' of ' . self::speedText($port['speed']) . ').',
                            'Traffic on this path shares a link that is nearly full: add capacity or move traffic away from it.', $devices, $ports);
                    }
                }
                $down = array_values(array_filter($port['members'], fn ($member) => $member['status'] !== 'up'));
                if ($down && ! isset($lags[$port['port_id']])) {
                    $missing = $label . ' ' . implode(', ', array_column($down, 'name'));
                    $next = $side === 'out' ? ($hops[$index + 1] ?? null) : null;
                    $far = $next !== null && $next['type'] === 'device' && ! empty($next['in']['members']) ? $next['in'] : null;
                    if ($far !== null) {
                        $lags[$far['port_id']] = true;
                        $label .= ' ↔ ' . $next['name'] . ' ' . $far['name'];
                        $farDown = array_column(array_filter($far['members'], fn ($member) => $member['status'] !== 'up'), 'name');
                        $missing .= $farDown ? ', ' . $next['name'] . ' ' . implode(', ', $farDown) : '';
                        $devices[] = $next['device_id'];
                        $ports[] = $far['port_id'];
                    }
                    $this->add(Finding::WARNING, 'lag_degraded', 'port-channel missing members on the path: ' . $label,
                        "The port-channel is up, but not all its members are: $missing. The path runs on less capacity and without the redundancy the port-channel should give.",
                        'Check the cables and optics of the missing members, and that both ends bundle them.', $devices, $ports);
                }
            }
        }
        if ($estimated) {
            $this->add(Finding::INFO, 'estimated_hops', 'routes of ' . implode(', ', array_map(fn ($id) => $this->deviceName($id), $estimated)) . ' are not in LibreNMS',
                'The next hop after ' . (count($estimated) === 1 ? 'this router' : 'these routers') . ' is estimated from the subnets and OSPF / BGP neighbors they share:'
                . ' with static routes, policy routing or ECMP the real path can differ.',
                'Enable the route discovery module (lnms config:set discovery_modules.route true, or per device) so LibreNMS reads their route tables.', $estimated);
        }
    }

    /**
     * Totals of the path: devices, routers, the slowest and the busiest link, findings.
     */
    private function summary(array $hops, array $findings, bool $complete, string $mode): array
    {
        $slowest = null;
        $busiest = null;
        foreach ($hops as $index => $hop) {
            $next = $hops[$index + 1] ?? null;
            if ($hop['link'] !== null && $hop['link']['speed'] > 0 && ($slowest === null || $hop['link']['speed'] < $slowest['speed'])) {
                $slowest = ['speed' => $hop['link']['speed'], 'where' => trim($hop['name'] . ' ' . ($hop['out']['name'] ?? '')) . ' → ' . trim(($next['name'] ?? '') . ' ' . ($next['in']['name'] ?? ''))];
            }
            foreach (['in', 'out'] as $side) {
                foreach (['in' => 'inbound', 'out' => 'outbound'] as $direction => $word) {
                    $util = $hop[$side][$direction . '_util'] ?? null;
                    if ($util !== null && ($busiest === null || $util > $busiest['util'])) {
                        $busiest = ['util' => $util, 'where' => $hop['name'] . ' ' . $hop[$side]['name'] . ' ' . $word];
                    }
                }
            }
        }
        $severities = array_count_values(array_column($findings, 'severity'));

        return [
            'mode' => $mode,
            'complete' => $complete,
            'devices' => count(array_filter($hops, fn ($hop) => in_array($hop['type'], ['device', 'unmanaged', 'hidden'], true))),
            'routers' => count(array_filter($hops, fn ($hop) => $hop['layer'] === 'l3')),
            'hidden' => count(array_filter($hops, fn ($hop) => $hop['type'] === 'hidden')),
            'estimated' => count(array_filter($hops, fn ($hop) => $hop['estimated'] !== null)),
            'slowest' => $slowest,
            'busiest' => $busiest,
            'critical' => $severities[Finding::CRITICAL] ?? 0,
            'warning' => $severities[Finding::WARNING] ?? 0,
            'info' => $severities[Finding::INFO] ?? 0,
            'status' => Finding::worst(array_column($findings, 'severity')),
        ];
    }

    /**
     * The L2 map around the path with the path highlighted: path= node ids in order, path_ports= the port pair of the link
     * between each two (a dash where the path is not drawn: a gap, a device out of the user's sight).
     */
    private function mapUrl(array $hops, ?int $vlan): ?string
    {
        $nodes = [];
        $pairs = [];
        $previous = null;
        foreach ($hops as $hop) {
            if (! in_array($hop['type'], ['device', 'unmanaged'], true) || $hop['node'] === null) {
                $previous = null;
                continue;
            }
            if ($nodes && end($nodes) === $hop['node']) {
                $previous = $hop;
                continue;
            }
            if ($nodes) {
                $pairs[] = $previous !== null && $previous['link'] !== null ? ($previous['out']['port_id'] ?? '') . ':' . ($hop['in']['port_id'] ?? '') : '-';
            }
            $nodes[] = $hop['node'];
            $previous = $hop;
        }
        $devices = array_values(array_filter($hops, fn ($hop) => $hop['type'] === 'device'));
        if (! $devices) {
            return null;
        }
        $center = $devices[intdiv(count($devices) - 1, 2)];
        $position = array_search($center['node'], $nodes, true);
        $depth = max(1, min(10, max((int) $position, count($nodes) - 1 - (int) $position)));

        return ($this->urls)('topology.l2', array_filter([
            'device' => $center['device_id'], 'depth' => $depth, 'vlan' => $vlan,
            'path' => implode(',', $nodes),
            'path_ports' => implode(',', $pairs),
        ], fn ($value) => $value !== null && $value !== ''));
    }

    // ------------------------------------------------------------------ data

    /**
     * What the tracers need of the network the user may see, read once.
     */
    private function network(): array
    {
        if ($this->network !== null) {
            return $this->network;
        }

        $ids = $this->topology->deviceIds();
        $links = $this->repo->links($ids);
        $lag = $this->topology->lagParents($ids);
        $linkPorts = [];
        foreach ($links as $link) {
            $linkPorts[] = (int) $link['local_port_id'];
            $linkPorts[] = (int) ($link['remote_port_id'] ?? 0);
        }
        $linkPorts = array_values(array_filter(array_unique($linkPorts)));
        $aggregates = array_values(array_unique(array_filter(array_map(fn ($port) => $lag[$port] ?? null, $linkPorts))));
        $members = array_keys(array_intersect($lag, $aggregates));
        $ports = $this->topology->ports(array_merge($linkPorts, $aggregates, $members));
        $portVlans = $this->topology->portVlans(array_merge($linkPorts, $aggregates, $members));
        $routed = [];
        foreach ($this->topology->portsWithIp(array_merge($linkPorts, $aggregates)) as $portId => $true) {
            $port = $ports[$portId] ?? [];
            if (! isset($portVlans[$portId]) && empty($port['ifTrunk']) && ! (is_numeric($port['ifVlan'] ?? null) && (int) $port['ifVlan'] > 0)) {
                $routed[$portId] = true;
            }
        }

        return $this->network = [
            'devices' => $this->topology->devices($ids),
            'links' => $links,
            'lag' => $lag,
            'ports' => $ports,
            'port_vlans' => $portVlans,
            'routed' => $routed,
            'addresses' => $this->topology->addresses($ids, true),
            'stp' => [],
            // ports whose spanning tree state the path can need: the links' and the ends'
            'stp_ports' => array_merge($linkPorts, $aggregates, $members),
        ];
    }

    /**
     * A port row (cached), null when it is not a port of a device the user may see.
     */
    private function port(int $portId): ?array
    {
        $this->loadPorts([$portId]);

        return $this->network['ports'][$portId];
    }

    /**
     * Read the ports not read yet in one query; a port of a device out of the user's sight is cached as null.
     *
     * @param  int[]  $portIds
     */
    private function loadPorts(array $portIds): void
    {
        $this->network();
        $missing = array_values(array_filter($portIds, fn ($id) => ! array_key_exists($id, $this->network['ports'])));
        if (! $missing) {
            return;
        }
        $ports = $this->topology->ports($missing);
        foreach ($missing as $portId) {
            $port = $ports[$portId] ?? null;
            $this->network['ports'][$portId] = $port !== null && isset($this->network['devices'][(int) $port['device_id']]) ? $port : null;
        }
    }

    /**
     * The L2 tracer for a VLAN (its spanning tree instance on every bridge).
     */
    private function tracer(?int $vlan): L2Tracer
    {
        $key = (string) ($vlan ?? 'default');
        if (! isset($this->tracers[$key])) {
            $network = $this->network();
            $base = reset($this->tracers);
            $this->tracers[$key] = $base !== false
                ? $base->withStp($this->stpFor($vlan))
                : new L2Tracer($network['devices'], $network['links'], array_filter($network['ports']), $network['lag'], $this->stpFor($vlan),
                    $network['port_vlans'], $network['routed']);
        }

        return $this->tracers[$key];
    }

    /**
     * Spanning tree index holding, for every bridge, the ports of the instance that carries the VLAN.
     */
    private function stpFor(?int $vlan): StpIndex
    {
        $key = (string) ($vlan ?? 'default');
        $this->network();
        if (! isset($this->network['stp'][$key])) {
            $instances = $this->repo->stpInstances(array_keys($this->network['devices']), $vlan);
            $selector = new StpIndex($instances);
            $keys = [];
            foreach ($selector->deviceIds() as $deviceId) {
                $keys[$deviceId] = $selector->keyFor($deviceId, $vlan);
            }
            $this->network['stp'][$key] = new StpIndex($instances, $this->repo->stpPorts($keys, $this->network['stp_ports']), $this->network['lag']);
        }

        return $this->network['stp'][$key];
    }

    /**
     * Addresses, roles and adjacencies of the routers for the L3 tracers.
     *
     * @return array{addresses: array, adjacencies: array, roles: array, names: array, routers: int[]}
     */
    private function l3Inputs(): array
    {
        if ($this->l3Inputs === null) {
            $network = $this->network();
            $roles = array_map(fn ($device) => (string) ($device['role'] ?? DeviceRole::UNKNOWN), $network['devices']);
            $routers = array_keys(array_filter($roles, fn ($role) => in_array($role, self::ROUTING_ROLES, true)));
            $adjacencies = [];
            foreach ($this->topology->ospfNeighbors($routers) as $row) {
                if (strtolower((string) $row['ospfNbrState']) === 'full') {
                    $adjacencies[] = ['device_id' => (int) $row['device_id'], 'port_id' => (int) ($row['port_id'] ?? 0) ?: null, 'local_ip' => null,
                        'neighbor_ip' => (string) $row['ospfNbrIpAddr'], 'kind' => 'ospf', 'vrf' => (string) ($row['context_name'] ?? '')];
                }
            }
            foreach ($this->topology->bgpPeers($routers) as $row) {
                if (strtolower((string) $row['bgpPeerState']) === 'established') {
                    $adjacencies[] = ['device_id' => (int) $row['device_id'], 'port_id' => null, 'local_ip' => (string) ($row['bgpLocalAddr'] ?? ''),
                        'neighbor_ip' => (string) $row['bgpPeerIdentifier'], 'kind' => 'bgp', 'vrf' => (string) ($row['context_name'] ?? '')];
                }
            }
            $this->l3Inputs = [
                'addresses' => $network['addresses'],
                'adjacencies' => $adjacencies,
                'roles' => $roles,
                'names' => array_map(fn ($device) => (string) (($device['display'] ?? '') ?: ($device['hostname'] ?? '')), $network['devices']),
                'routers' => $routers,
            ];
        }

        return $this->l3Inputs;
    }

    /**
     * Address lookups (owners, gateways, connected subnets) without routes.
     */
    private function lookups(): L3Tracer
    {
        if ($this->lookups === null) {
            $inputs = $this->l3Inputs();
            $this->lookups = new L3Tracer($inputs['addresses'], [], [], $inputs['adjacencies'], $inputs['roles'], $inputs['names']);
        }

        return $this->lookups;
    }

    /**
     * The L3 tracer with the routes toward one destination. The route table is read twice (which routers have routes,
     * the routes covering the destination), unless it is larger than the path_route_rows setting allows.
     */
    private function l3For(string $destination): L3Tracer
    {
        $inputs = $this->l3Inputs();
        if ($this->routeData === null) {
            $limit = $this->settings->int('path_route_rows', 0);
            $size = $this->repo->routeTableSize();
            if ($size !== null && $limit > 0 && $size > $limit) {
                $this->notes[] = ['level' => 'warning', 'text' => 'The route table of LibreNMS holds about ' . number_format($size) . ' routes, more than the '
                    . number_format($limit) . ' a path trace reads (setting "Route table limit"): every router is crossed by an estimate from its subnets and neighbors.'];
                $this->routeData = [];
            } else {
                $this->routeData = $this->repo->routeData($inputs['routers']);
            }
        }
        $routes = $this->routeData ? $this->repo->routesToward($destination, array_keys($this->routeData)) : [];

        return new L3Tracer($inputs['addresses'], $routes, $this->routeData, $inputs['adjacencies'], $inputs['roles'], $inputs['names']);
    }

    /**
     * Names of the devices among $ids the user may see.
     *
     * @param  int[]  $ids
     * @return array<int, string>
     */
    private function names(array $ids): array
    {
        $names = [];
        foreach (array_unique($ids) as $id) {
            if (isset($this->network()['devices'][$id])) {
                $names[$id] = $this->deviceName($id);
            }
        }

        return $names;
    }

    private function role(?int $deviceId): ?string
    {
        return $deviceId === null ? null : ($this->network()['devices'][$deviceId]['role'] ?? null);
    }

    private function deviceName(?int $deviceId): string
    {
        $device = $deviceId === null ? null : ($this->network()['devices'][$deviceId] ?? null);

        return $device ? (string) (($device['display'] ?? '') ?: ($device['hostname'] ?? $deviceId)) : 'device #' . $deviceId;
    }

    private function portName(?int $portId): string
    {
        $port = $portId ? $this->port($portId) : null;

        return $port ? InterfaceName::short(InterfaceName::label($port)) : 'port #' . $portId;
    }

    /**
     * @param  int[]  $devices
     * @param  int[]  $ports
     */
    private function add(string $severity, string $code, string $title, string $detail, string $recommendation, array $devices = [], array $ports = [], ?int $vlan = null): void
    {
        $this->findings[] = new Finding($severity, $code, $title, $detail, $recommendation, $vlan, $devices, $ports);
    }

    private static function duplex(?string $value): ?string
    {
        return match (strtolower((string) $value)) {
            'fullduplex', 'full' => 'full',
            'halfduplex', 'half' => 'half',
            default => null,
        };
    }

    private static function period(int $seconds): string
    {
        return $seconds > 0 ? ' in the last ' . StpAnalyzer::duration($seconds) : ' in the last poll';
    }

    /**
     * 850 Mbit/s, 10 Gbit/s.
     */
    public static function speedText(int $bps): string
    {
        foreach ([1_000_000_000_000 => 'Tbit/s', 1_000_000_000 => 'Gbit/s', 1_000_000 => 'Mbit/s', 1_000 => 'kbit/s'] as $unit => $name) {
            if ($bps >= $unit) {
                $value = round($bps / $unit, $bps < 10 * $unit ? 1 : 0);

                return ($value == (int) $value ? (string) (int) $value : number_format($value, 1, '.', '')) . ' ' . $name;
            }
        }

        return $bps . ' bit/s';
    }

    /**
     * IPv6 in its short form, IPv4 as is.
     */
    public static function display(string $ip): string
    {
        return str_contains($ip, ':') ? HostQuery::ipv6Short($ip) : $ip;
    }
}
