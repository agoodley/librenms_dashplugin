<?php

namespace Agoodley\LibrenmsTopology\Hosts;

use Agoodley\LibrenmsTopology\Data\HostRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\Cidr;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Support\PortStack;

/**
 * Host locator searches and the port tab, on the data the user may see: turns an address or name
 * into MAC addresses (ARP / IPv6 neighbor tables, LibreNMS device names, DNS) and locates each one
 * with HostLocator.
 */
class HostService
{
    /** Answers per search (an IP address can have several MACs, a virtual router MAC one answer per VLAN). */
    public const MAX_HOSTS = 8;

    /** Addresses of a device or a DNS name looked up per search. */
    public const MAX_IPS = 8;

    /** Seconds all DNS lookups of one search may take together. */
    public const DNS_BUDGET = 4.0;

    /**
     * Poll intervals within which a switch must have been polled for its 802.1X / MAB sessions to count (LibreNMS
     * warns about devices not polled for as long).
     */
    public const SESSION_POLLS = 3;

    /** LLDP/CDP hops followed from the switch a trail ends at to the switches behind it. */
    public const BEHIND_HOPS = 3;

    /** MACs listed on the port tab. */
    public const PORT_MACS = 50;

    /** @var (callable(string, float): (string[]|string))|null */
    private $resolver;

    private \Closure $urls;

    private \Closure $clock;

    /** @var array<int, array> device_id => layer 3 addresses (routes lookup) */
    private array $layer3 = [];

    /** @var array<int, bool> device_id => has OSPF neighbors or BGP peers (routes lookup) */
    private array $routers = [];

    /** @var array<int, int[]> device_id => linked device ids (behind lookup) */
    private array $links = [];

    /**
     * @param  (callable(string, float): (string[]|string))|null  $resolver  hostname and seconds left => IP addresses, or
     *                                                                      why it was not looked up (DnsResolver); none: no DNS
     * @param  (\Closure(string, array): string)|null  $urls  route name and parameters => URL (default: route())
     * @param  (\Closure(): int)|null  $clock  the time now (default: time())
     */
    public function __construct(
        private readonly HostRepository $repo,
        private readonly TopologyRepository $topology,
        private readonly PluginSettings $settings,
        ?callable $resolver = null,
        ?\Closure $urls = null,
        ?\Closure $clock = null,
    ) {
        $this->resolver = $resolver;
        $this->urls = $urls ?? fn (string $name, array $parameters = []) => route($name, $parameters);
        $this->clock = $clock ?? fn (): int => time();
    }

    /**
     * @return array{query: array, devices: array, resolved: string[], hosts: array, message: ?string, truncated: bool}
     */
    public function search(string $input): array
    {
        $query = HostQuery::parse($input);
        $result = ['query' => $query, 'devices' => [], 'resolved' => [], 'hosts' => [], 'message' => null, 'truncated' => false];

        $macs = [];
        $ips = [];
        $searched = [];
        $ends = [];
        switch ($query['kind']) {
            case HostQuery::EMPTY:
                return $result;
            case HostQuery::INVALID:
                $result['message'] = 'Enter an IPv4 or IPv6 address, a MAC address or a hostname.';

                return $result;
            case HostQuery::MAC:
                $macs = [$query['value']];
                break;
            case HostQuery::IPV4:
            case HostQuery::IPV6:
                $ips = [$query['value']];
                break;
            case HostQuery::NAME:
                $devices = $this->repo->devicesByName($query['value']);
                $result['devices'] = $devices;
                $searched = array_column($devices, 'device_id');
                $ips = array_slice(array_values(array_unique(array_merge([], ...array_column($devices, 'ips')))), 0, self::MAX_IPS);
                if (! $devices) {
                    $ips = $this->resolve([$query['value']], $why);
                    if (! $ips) {
                        $result['message'] = $query['value'] . ' is not a device in LibreNMS' . ($why === null ? ' and does not resolve in DNS.' : '. ' . $why);

                        return $result;
                    }
                } elseif (! $ips) {
                    // devices LibreNMS polls by hostname without a stored address
                    $ips = $this->resolve(array_column($devices, 'hostname'), $why);
                }
                $result['resolved'] = array_map([self::class, 'display'], $ips);
                // where the interfaces of a server or other end device are plugged in; a switch or router is the answer itself
                $ends = array_filter($devices, fn ($device) => ! in_array($device['role'], HostLocator::NETWORK_ROLES, true));
                $macs = $ends ? $this->repo->deviceMacs(array_column($ends, 'device_id')) : [];
                break;
        }

        // the MACs of the addresses, with the VLANs of the router interfaces that have the ARP / neighbor entries
        $targets = [];
        if ($ips) {
            $rows = $this->repo->addressesByIps($ips);
            $vlans = $this->interfaceVlans(array_column($rows, 'port_id'));
            foreach ($rows as $row) {
                $targets[$row['mac']] ??= ['mac' => $row['mac'], 'vlans' => [], 'ips' => [], 'optional' => false];
                $targets[$row['mac']]['ips'][] = $row['ip'];
                if (isset($vlans[$row['port_id']])) {
                    $targets[$row['mac']]['vlans'][] = $vlans[$row['port_id']];
                }
            }
        }
        foreach ($macs as $mac) {
            $targets[$mac] ??= ['mac' => $mac, 'vlans' => [], 'ips' => [], 'optional' => $query['kind'] === HostQuery::NAME];
        }
        $targets = array_values(array_filter($targets, fn ($target) => strlen($target['mac']) === 12));

        if ($targets) {
            [$result['hosts'], $result['truncated']] = $this->locate($targets, $searched);
        }
        if (! $result['hosts']) {
            $addresses = implode(', ', $result['resolved'] ?: array_map([self::class, 'display'], $ips));
            $result['message'] = match (true) {
                (bool) $ends => $query['value'] . ' is monitored by LibreNMS, but ' . ($ips ? 'its address (' . $addresses . ') is in no ARP or IPv6 neighbor table you can see' : 'its address is unknown')
                    . ' and no switch you can see has learned the MAC address of one of its interfaces.',
                (bool) $result['devices'] => $query['value'] . ' is a switch, router or firewall monitored by LibreNMS: its device page and the L2 map show its links'
                    . ($ips ? '; its address (' . $addresses . ') is in no ARP or IPv6 neighbor table you can see.' : '.'),
                default => 'No ARP or IPv6 neighbor entry for ' . $addresses . ' on the devices you can see. LibreNMS learns IP addresses from the ARP and neighbor tables of routers and L3 switches.',
            };
        }

        return $result;
    }

    /**
     * Everything the port tab shows about one port: far end, port-channel, VLANs, spanning tree,
     * the MACs learned on it (with their IPv4 addresses) and 802.1X sessions. It is on every port
     * page, so it only uses indexed lookups: by port, device or MAC (IPv6 neighbors have no MAC index).
     */
    public function portTab(int $deviceId, int $portId): array
    {
        $ports = $this->repo->ports([$portId]);
        if (! isset($ports[$portId]) || (int) $ports[$portId]['device_id'] !== $deviceId) {
            return ['visible' => false];
        }
        $port = $ports[$portId];

        $lag = $this->topology->lagParents([$deviceId]);
        $parent = $lag[$portId] ?? null;
        $members = array_keys($lag, $portId, true);
        $related = $this->repo->ports(array_merge($members, $parent ? [$parent] : []));
        $neighborRows = $this->repo->neighbors(array_merge([$portId], $members));
        $neighbors = [];
        foreach (array_merge([$portId], $members) as $id) {
            foreach ($neighborRows[$id] ?? [] as $neighbor) {
                $neighbors[] = $neighbor + ['via' => $id === $portId ? null : self::portLabel($related[$id] ?? null, $id)];
            }
        }
        $neighborDevices = $this->repo->deviceRows(array_filter(array_column($neighbors, 'device_id')));
        foreach ($neighbors as &$neighbor) {
            $neighbor['url'] = $neighbor['device_id'] ? ($neighborDevices[$neighbor['device_id']]['url'] ?? null) : null;
        }
        unset($neighbor);

        [$instances, $stpPorts] = $this->repo->stpRows($deviceId, array_filter([$portId, $parent]));
        $index = new StpIndex($instances, $stpPorts, $lag);
        $stp = [];
        foreach ($index->instancesFor($deviceId) as $key => $instance) {
            $status = $index->portStatus($deviceId, $portId, $key === 0 ? 1 : $key);
            if ($status) {
                $stp[] = $status + ['root_bridge' => StpIndex::isRoot($instance), 'label' => StpIndex::vlanLabel($key)];
            }
        }

        // a port-channel member's MACs are learned on the port-channel
        $fdbPort = $portId;
        $fdb = $this->repo->fdbByPort($portId, self::PORT_MACS);
        if (! $fdb && $parent) {
            $fdbPort = $parent;
            $fdb = $this->repo->fdbByPort($parent, self::PORT_MACS);
        }
        $since = $this->repo->fdbSince([$deviceId], $fdb);
        $start = $since[$deviceId] ?? null;
        $macs = array_values(array_unique(array_column($fdb, 'mac')));
        $ouis = $this->repo->vendors($macs);
        $addresses = [];
        foreach ($this->repo->addressesByMacs($macs, false) as $row) {
            $addresses[$row['mac']][] = $row['ip'];
        }
        $learned = [];
        foreach ($fdb as $row) {
            $learned[] = $row + [
                'readable' => Mac::readable($row['mac']),
                'vendor' => Mac::vendor($row['mac'], $ouis),
                'ips' => array_values(array_unique($addresses[$row['mac']] ?? [])),
                'stale' => $start !== null && $row['last_seen'] < $start,
                'locate_url' => ($this->urls)('topology.hosts', ['q' => Mac::readable($row['mac'])]),
            ];
        }
        $sessions = $this->repo->nacByPort($portId);
        $devices = [];
        $polled = [];
        $this->sessionSwitches([$sessions], $devices, $polled);

        return [
            'visible' => true,
            'port' => $port + ['label' => self::portLabel($port, $portId)],
            'neighbors' => $neighbors,
            'parent' => $parent ? ['port_id' => $parent, 'label' => self::portLabel($related[$parent] ?? null, $parent), 'url' => $related[$parent]['url'] ?? null] : null,
            'members' => array_map(fn ($id) => ['port_id' => $id, 'label' => self::portLabel($related[$id] ?? null, $id), 'url' => $related[$id]['url'] ?? null,
                'status' => $related[$id]['ifOperStatus'] ?? null], $members),
            'vlans' => $this->repo->portVlans($deviceId, $portId),
            'stp' => $stp,
            'fdb_port' => $fdbPort === $portId ? null : ['label' => self::portLabel($related[$fdbPort] ?? null, $fdbPort), 'url' => $related[$fdbPort]['url'] ?? null],
            'mac_count' => $this->repo->macCounts([$fdbPort], $since)[$fdbPort] ?? 0,
            'macs' => $learned,
            'nac' => array_map(fn ($row) => $row + ['readable' => Mac::readable($row['mac'])], $this->markSessions($sessions, $devices, $polled)),
            // when the switch's forwarding table was read: its current entries carry the time, else the start of the discovery
            'last_discovery' => $start === null ? null : max($start, $fdb ? max(array_column($fdb, 'last_seen')) : 0),
            'map_url' => ($this->urls)('topology.l2', ['device' => $deviceId, 'depth' => 1]),
        ];
    }

    /**
     * VLAN of a router interface from its name: Vlan10 / Vl10 (Cisco, Arista, Dell, Aruba), Vlanif10 (Huawei),
     * Vlan-interface10 (H3C / Comware), ve 10 (Brocade / Ruckus), irb.10 and vlan.10 (Juniper) and the unit of a
     * subinterface (Gi0/0.10, ethernet1/1.10). The unit or ve number is usually, not always, the VLAN id.
     */
    public static function interfaceVlan(string $name): ?int
    {
        return InterfaceName::vlanInterface($name) ?? InterfaceName::subInterfaceUnit($name);
    }

    /**
     * IP addresses of names with the DNS resolver, all lookups within DNS_BUDGET seconds.
     *
     * @param  string[]  $names
     * @param  string|null  $why  set when DNS was not asked: switched off, too many lookups, no resolver
     * @return string[]
     */
    private function resolve(array $names, ?string &$why): array
    {
        $why = null;
        if ($this->resolver === null || ! $this->settings->bool('hosts_dns')) {
            $why = $this->resolver === null ? null : 'DNS lookups are switched off in the plugin settings.';

            return [];
        }

        $ips = [];
        $deadline = $this->seconds() + $this->dnsBudget();
        foreach (array_unique($names) as $name) {
            $left = $deadline - $this->seconds();
            if ($left < 0.1) {
                break;
            }
            $answer = ($this->resolver)($name, min(DnsResolver::TIMEOUT, $left));
            if (is_string($answer)) {
                $why = $answer;
                break;
            }
            $ips = array_merge($ips, $answer);
        }

        return array_slice(array_values(array_unique(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false))), 0, self::MAX_IPS);
    }

    /**
     * Seconds all DNS lookups of one search may take together.
     */
    protected function dnsBudget(): float
    {
        return self::DNS_BUDGET;
    }

    /**
     * Clock of the DNS budget, in seconds.
     */
    protected function seconds(): float
    {
        return microtime(true);
    }

    /**
     * Locate each MAC and describe the answers with names and links.
     *
     * @param  array<int, array{mac: string, vlans: int[], ips: string[], optional: bool}>  $targets  vlans: of the router
     *         interfaces with the ARP / neighbor entries of the searched ips; optional: left out when no switch learned it
     * @param  int[]  $searched  devices searched by name, owners of their interface MACs
     * @return array{0: array, 1: bool} answers, truncated
     */
    private function locate(array $targets, array $searched): array
    {
        $byMac = [];
        foreach ($this->repo->fdbByMacs(array_values(array_unique(array_column($targets, 'mac')))) as $row) {
            $byMac[$row['mac']][] = $row;
        }

        // one answer per MAC. A virtual router MAC is the same in every VLAN using its group number (HSRP group 1, VRRP
        // group 10), so it gets one answer per VLAN: the VLANs of its ARP entries, else every VLAN it is learned in,
        // showing only the searched address (the router interface does not tell its VLAN, or tells another one)
        $answers = [];
        foreach ($targets as $target) {
            $rows = $byMac[$target['mac']] ?? [];
            if (! $rows && $target['optional']) {
                continue;
            }
            if (! Mac::gateway($target['mac'])) {
                $answers[] = ['vlan' => null, 'rows' => $rows, 'only' => null] + $target;
                continue;
            }
            $groups = self::byVlan($rows);
            $matched = array_values(array_filter($groups, fn ($group) => $group[0] !== null && in_array($group[0], $target['vlans'], true)));
            $only = $target['ips'] && ! $matched ? array_values(array_unique($target['ips'])) : null;
            foreach ($matched ?: ($groups ?: [[null, []]]) as [$vlan, $group]) {
                $answers[] = ['vlan' => $vlan, 'rows' => $group, 'only' => $only] + $target;
            }
        }
        $truncated = count($answers) > self::MAX_HOSTS;
        $answers = array_slice($answers, 0, self::MAX_HOSTS);
        if (! $answers) {
            return [[], false];
        }

        $rows = array_merge(...array_column($answers, 'rows'));
        $macs = array_values(array_unique(array_column($answers, 'mac')));
        $deviceIds = array_values(array_unique(array_column($rows, 'device_id')));
        $portIds = array_values(array_unique(array_filter(array_column($rows, 'port_id'))));

        // 802.1X / MAB sessions on the ports where the MACs are learned (indexed); a MAC without a current session there by
        // itself: a host that connected after the last forwarding table read, or behind a switch without one
        $nac = [];
        foreach ($this->repo->nacByMacs($macs, $portIds) as $row) {
            $nac[$row['mac']][$row['id']] = $row;
        }
        $devices = [];
        $polled = [];
        $this->sessionSwitches($nac, $devices, $polled);
        $missing = array_values(array_filter($macs, fn ($mac) => ! array_filter(array_column($this->markSessions($nac[$mac] ?? [], $devices, $polled), 'current'))));
        foreach ($missing ? $this->repo->nacByMacs($missing) : [] as $row) {
            $nac[$row['mac']][$row['id']] = $row;
        }
        $this->sessionSwitches($nac, $devices, $polled);
        $sessionPorts = [];
        $sessionDevices = [];
        foreach ($nac as $mac => $sessions) {
            usort($sessions, fn ($a, $b) => [$a['historical'], -$a['last_seen']] <=> [$b['historical'], -$b['last_seen']]);
            $nac[$mac] = $sessions;
            foreach ($sessions as $row) {
                if (! $row['historical']) {
                    $sessionPorts[] = $row['port_id'];
                    $sessionDevices[] = $row['device_id'];
                }
            }
        }

        $lag = $this->topology->lagParents($deviceIds);
        $wanted = array_flip($portIds);
        $members = array_keys(array_filter($lag, fn ($parent) => isset($wanted[$parent])));
        $ports = $this->repo->ports(array_merge($portIds, $members, $sessionPorts));
        $neighbors = $this->repo->neighbors(array_merge($portIds, $members, $sessionPorts));
        $neighborDevices = [];
        $withNeighbors = [];
        foreach ($neighbors as $portId => $list) {
            foreach ($list as $neighbor) {
                if ($neighbor['device_id']) {
                    $neighborDevices[] = $neighbor['device_id'];
                }
            }
            if ($list) {
                $withNeighbors[$portId] = true;
                $withNeighbors[$lag[$portId] ?? $portId] = true;
            }
        }
        $owners = $this->repo->owners($macs, array_merge($deviceIds, $neighborDevices, $searched));
        $ownerDevices = [];
        foreach ($owners as $list) {
            foreach ($list as $owner) {
                $ownerDevices[] = $owner['device_id'];
            }
        }
        $devices += $this->repo->deviceRows(array_values(array_diff(array_merge($deviceIds, $neighborDevices, $ownerDevices, $sessionDevices), array_keys($devices))));
        // the switches that learned the MACs, and the neighbors a trail can end at (with or without forwarding table data)
        $since = $this->repo->fdbSince(array_merge($deviceIds, $neighborDevices), $rows);

        // ports without LLDP/CDP neighbor: which of the switches that learned the MACs they lead to
        $bare = array_values(array_filter($portIds, fn ($portId) => isset($ports[$portId]) && ! isset($withNeighbors[$portId])));
        $switchMacs = $bare ? $this->repo->switchMacsOn($bare, $deviceIds, $since) : [];

        $locatorPorts = [];
        $aggregates = array_flip(array_values($lag));
        foreach ($ports as $portId => $port) {
            $locatorPorts[$portId] = [
                'device_id' => (int) $port['device_id'],
                'name' => self::portLabel($port, $portId),
                'aggregate' => isset($aggregates[$portId]) || PortStack::isAggregate($port),
            ];
        }
        foreach ($nac as $mac => $sessions) {
            $nac[$mac] = $this->markSessions($sessions, $devices, $polled);
        }

        $locator = new HostLocator($locatorPorts, $neighbors, $lag, $since,
            array_map(fn ($device) => ['name' => $device['name'], 'role' => $device['role']], $devices),
            $this->settings->sharedPortMacs(), $switchMacs, [
                'macs' => fn (array $ids) => $this->repo->macCounts($ids, $since),
                'behind' => fn (int $deviceId, array $exclude) => $this->devicesBehind($deviceId, $exclude),
                'routes' => fn (int $deviceId, array $vlans, array $ips) => $this->routes($deviceId, $vlans, $ips),
            ]);

        $vlanNames = [];
        foreach ($rows as $row) {
            if ($row['vlan'] !== null && $row['vlan_name'] !== null) {
                $vlanNames[$row['device_id'] . ':' . $row['vlan']] = $row['vlan_name'];
            }
        }
        $ouis = $this->repo->vendors($macs);
        $addressRows = $this->repo->addressesByMacs($macs);
        $addressVlans = $this->interfaceVlans(array_column($addressRows, 'port_id'));
        $addresses = [];
        foreach ($addressRows as $row) {
            $addresses[$row['mac']][] = $row + ['vlan' => $addressVlans[$row['port_id']] ?? null];
        }

        $describe = function (array $entry) use ($ports, $devices, $vlanNames): array {
            $deviceId = $entry['device_id'];
            $port = $ports[$entry['port_id']] ?? null;
            $neighbor = $entry['neighbor'] ?? null;

            return array_merge($entry, [
                'device' => $devices[$deviceId]['name'] ?? '#' . $deviceId,
                'device_url' => $devices[$deviceId]['url'] ?? null,
                'port' => $entry['port_id'] > 0 ? self::portLabel($port, $entry['port_id']) : 'unknown port',
                'port_url' => $port['url'] ?? null,
                'description' => trim((string) ($port['ifAlias'] ?? '')),
                'port_status' => $port['ifOperStatus'] ?? null,
                'vlan_names' => array_map(fn ($vlan) => $vlanNames[$deviceId . ':' . $vlan] ?? null, $entry['vlans']),
                'neighbor' => $neighbor ? [
                    'name' => $neighbor['name'], 'port' => $neighbor['port'], 'device_id' => $neighbor['device_id'], 'role' => $neighbor['role'],
                    'url' => $neighbor['device_id'] ? ($devices[$neighbor['device_id']]['url'] ?? null) : null,
                ] : null,
            ]);
        };

        $hosts = [];
        foreach ($answers as $answer) {
            $mac = $answer['mac'];
            $vlan = $answer['vlan'];
            $own = array_values(array_filter($addresses[$mac] ?? [], fn ($row) => $answer['only'] !== null ? in_array($row['ip'], $answer['only'], true)
                : $vlan === null || $row['vlan'] === null || $row['vlan'] === $vlan));
            $located = $locator->locate($mac, $answer['rows'], $owners[$mac] ?? [], $nac[$mac] ?? [], array_column($own, 'ip'));
            $hosts[] = [
                'mac' => $mac,
                'readable' => Mac::readable($mac),
                'vendor' => Mac::vendor($mac, $ouis),
                'vlan' => $vlan,
                'gateway' => $located['gateway'],
                'addresses' => array_map(fn ($row) => $row + [
                    'display' => self::display($row['ip']),
                    'device' => $devices[$row['device_id']]['name'] ?? null,
                ], $own),
                'nac' => $nac[$mac] ?? [],
                'status' => $located['status'],
                'stale' => $located['stale'],
                'edge' => $located['edge'] ? $describe($located['edge']) : null,
                'device' => $located['device'] ? $located['device'] + ['url' => $devices[$located['device']['device_id']]['url'] ?? null] : null,
                'map_url' => $this->mapUrl($located, $mac),
                'seen' => array_map($describe, $located['seen']),
                'notes' => $located['notes'],
            ];
        }

        // names of the routers that learned the addresses and of the 802.1X ports
        $deviceIds = [];
        $portIds = [];
        foreach ($hosts as $host) {
            foreach (array_merge($host['addresses'], $host['nac']) as $row) {
                $deviceIds[] = $row['device_id'];
            }
            foreach ($host['nac'] as $row) {
                $portIds[] = $row['port_id'];
            }
        }
        $devices += $this->repo->deviceRows(array_diff(array_unique($deviceIds), array_keys($devices)));
        $ports += $this->repo->ports(array_diff(array_unique($portIds), array_keys($ports)));
        foreach ($hosts as &$host) {
            foreach ($host['addresses'] as &$address) {
                $address['device'] ??= $devices[$address['device_id']]['name'] ?? '#' . $address['device_id'];
            }
            unset($address);
            foreach ($host['nac'] as &$session) {
                $session['device'] = $devices[$session['device_id']]['name'] ?? '#' . $session['device_id'];
                $session['port'] = self::portLabel($ports[$session['port_id']] ?? null, $session['port_id']);
                $session['port_url'] = $ports[$session['port_id']]['url'] ?? null;
            }
            unset($session);
        }
        unset($host);

        return [$hosts, $truncated];
    }

    /**
     * Reads what tells whether sessions are current about the switches of those that are not historical and were not
     * read yet: their device row (last poll) and whether they poll sessions.
     *
     * @param  array<string, array<int, array>>  $nac  MAC => sessions
     * @param  array<int, array>  $devices  device rows read so far
     * @param  array<int, bool>  $polled  device_id => polls sessions, of the switches read so far
     */
    private function sessionSwitches(array $nac, array &$devices, array &$polled): void
    {
        $new = [];
        foreach ($nac as $sessions) {
            foreach ($sessions as $row) {
                if (! $row['historical'] && ! isset($polled[$row['device_id']])) {
                    $new[$row['device_id']] = false;
                }
            }
        }
        if ($new) {
            $devices += $this->repo->deviceRows(array_keys($new));
            $polled += $this->repo->nacPolled(array_intersect_key($devices, $new)) + $new;
        }
    }

    /**
     * Marks the sessions current or not, and tells why one that is not historical does not count. LibreNMS marks the
     * sessions a switch no longer reports historical at every poll of its nac module and updates the others only when
     * one of their values changes, so a session that is not historical is current when its switch polls sessions and
     * was polled within SESSION_POLLS poll intervals.
     *
     * @param  array<int, array>  $sessions
     * @param  array<int, array>  $devices  device rows (last_polled) of their switches
     * @param  array<int, bool>  $polled  device_id => the switch polls sessions
     * @return array<int, array> the sessions with current (bool) and inactive (why not, or null)
     */
    private function markSessions(array $sessions, array $devices, array $polled): array
    {
        $recent = ($this->clock)() - self::SESSION_POLLS * $this->repo->pollInterval();
        foreach ($sessions as $i => $row) {
            $last = $devices[$row['device_id']]['last_polled'] ?? null;
            $sessions[$i]['inactive'] = match (true) {
                (bool) $row['historical'] => null,
                empty($polled[$row['device_id']]) => 'the nac poller module is off for this switch',
                $last === null => 'the switch was never polled',
                $last < $recent => 'the switch was last polled ' . date('Y-m-d H:i', $last),
                default => null,
            };
            $sessions[$i]['current'] = ! $row['historical'] && $sessions[$i]['inactive'] === null;
        }

        return $sessions;
    }

    /**
     * Devices behind a switch, seen from the trail: reachable from it over LLDP/CDP links within BEHIND_HOPS hops,
     * except the trail's switches and devices linked to one of them (they have another way to the trail, like the
     * second core switch of a dual-homed access switch).
     *
     * @param  int[]  $trail  the switches with an entry for the MAC
     * @return int[]
     */
    private function devicesBehind(int $deviceId, array $trail): array
    {
        $visited = array_fill_keys($trail, true) + [$deviceId => true];
        $found = [];
        $frontier = [$deviceId];
        $links = $this->linked($frontier);
        for ($hop = 0; $hop < self::BEHIND_HOPS && $frontier && count($found) < 200; $hop++) {
            $candidates = [];
            foreach ($frontier as $id) {
                foreach ($links[$id] ?? [] as $linked) {
                    if (! isset($visited[$linked])) {
                        $visited[$linked] = true;
                        $candidates[] = $linked;
                    }
                }
            }
            if (! $candidates) {
                break;
            }
            $links = $this->linked($candidates);
            $frontier = array_values(array_filter($candidates, fn ($id) => ! array_intersect($links[$id] ?? [], $trail)));
            array_push($found, ...$frontier);
        }

        return $found;
    }

    /**
     * LLDP/CDP links of devices, each read once (the search behind a switch runs for every answer).
     *
     * @param  int[]  $deviceIds
     * @return array<int, int[]> device_id => linked device ids
     */
    private function linked(array $deviceIds): array
    {
        $missing = array_values(array_diff($deviceIds, array_keys($this->links)));
        if ($missing) {
            $this->links += $this->repo->linkedDevices($missing) + array_fill_keys($missing, []);
        }

        return array_intersect_key($this->links, array_flip($deviceIds));
    }

    /**
     * Does the device route in one of the VLANs or subnets? It needs an interface in one of the VLANs (Vlan20, irb.20) or
     * an address whose subnet holds one of the addresses, and to be a router: a switch has its management address in a
     * VLAN too. A router has two or more routed IPv4 interfaces or OSPF / BGP, as the maps tell routers (roleFacts).
     *
     * @param  int[]  $vlans
     * @param  string[]  $ips
     */
    private function routes(int $deviceId, array $vlans, array $ips): bool
    {
        $addresses = $this->layer3[$deviceId] ??= $this->repo->layer3Addresses([$deviceId])[$deviceId] ?? [];
        $evidence = false;
        foreach ($addresses as $address) {
            $vlan = self::interfaceVlan($address['port']);
            $network = Cidr::network(HostQuery::ipv6Short($address['ip']), $address['prefix']);
            $evidence = ($vlan !== null && in_array($vlan, $vlans, true))
                || ($network !== null && array_filter($ips, fn ($ip) => Cidr::contains($network, HostQuery::ipv6Short($ip))));
            if ($evidence) {
                break;
            }
        }
        if (! $evidence) {
            return false;
        }

        $routed = array_unique(array_column(array_filter($addresses, fn ($address) => $address['family'] === 4 && $address['prefix'] < 32), 'port_id'));

        return count($routed) >= 2 || ($this->routers[$deviceId] ??= isset($this->repo->routingDevices([$deviceId])[$deviceId]));
    }

    /**
     * Where the L2 map shows the answer: the host next to its switch or next to the device it is behind;
     * a device the address belongs to is the answer itself.
     */
    private function mapUrl(array $located, string $mac): ?string
    {
        return match ($located['status']) {
            'located' => ($this->urls)('topology.l2', ['device' => $located['edge']['device_id'], 'depth' => 1, 'host' => $mac]),
            'behind' => ($this->urls)('topology.l2', ['device' => $located['device']['device_id'], 'depth' => 1, 'host' => $mac]),
            'owner', 'gateway' => ($this->urls)('topology.l2', ['device' => $located['device']['device_id'], 'depth' => 1]),
            default => null,
        };
    }

    /**
     * @param  int[]  $portIds  router interfaces
     * @return array<int, int> port_id => VLAN of the interface
     */
    private function interfaceVlans(array $portIds): array
    {
        $vlans = [];
        foreach ($this->repo->ports($portIds) as $portId => $port) {
            $vlan = self::interfaceVlan(InterfaceName::label($port));
            if ($vlan !== null) {
                $vlans[$portId] = $vlan;
            }
        }

        return $vlans;
    }

    /**
     * Entries per VLAN, lowest first, entries without VLAN last.
     *
     * @return array<int, array{0: ?int, 1: array}>
     */
    private static function byVlan(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['vlan'] ?? PHP_INT_MAX][] = $row;
        }
        ksort($groups);

        return array_map(fn ($vlan, $group) => [$vlan === PHP_INT_MAX ? null : $vlan, $group], array_keys($groups), array_values($groups));
    }

    /**
     * Short interface name (Gi1/0/1), ifDescr when there is no ifName.
     */
    private static function portLabel(?array $port, int $portId): string
    {
        return $port ? InterfaceName::short(InterfaceName::label($port)) : 'port #' . $portId;
    }

    /**
     * IPv6 in its short form, IPv4 as is.
     */
    private static function display(string $ip): string
    {
        return str_contains($ip, ':') ? HostQuery::ipv6Short($ip) : $ip;
    }
}
