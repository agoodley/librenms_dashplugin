<?php

namespace Agoodley\LibrenmsTopology\Hosts;

use Agoodley\LibrenmsTopology\Data\HostRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

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

    /** MACs listed on the port tab. */
    public const PORT_MACS = 50;

    /** @var (callable(string): (string[]|null))|null */
    private $resolver;

    private \Closure $urls;

    /**
     * @param  (callable(string): (string[]|null))|null  $resolver  hostname => IP addresses, null when the user made
     *                                                             too many lookups (DnsResolver); none: no DNS
     * @param  (\Closure(string, array): string)|null  $urls  route name and parameters => URL (default: route())
     */
    public function __construct(
        private readonly HostRepository $repo,
        private readonly TopologyRepository $topology,
        private readonly PluginSettings $settings,
        ?callable $resolver = null,
        ?\Closure $urls = null,
    ) {
        $this->resolver = $resolver;
        $this->urls = $urls ?? fn (string $name, array $parameters = []) => route($name, $parameters);
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
                    $ips = $this->resolve($query['value'], $result['message']);
                    if ($ips === null) {
                        return $result;
                    }
                } elseif (! $ips && $this->resolver !== null && $this->settings->bool('hosts_dns')) {
                    // devices LibreNMS polls by hostname without a stored address
                    foreach (array_unique(array_column($devices, 'hostname')) as $hostname) {
                        $ips = array_merge($ips, ($this->resolver)($hostname) ?? []);
                    }
                    $ips = array_slice(array_values(array_unique($ips)), 0, self::MAX_IPS);
                }
                $result['resolved'] = array_map([self::class, 'display'], $ips);
                // where the interfaces of a server or other end device are plugged in; a switch or router is the answer itself
                $ends = array_filter($devices, fn ($device) => ! in_array($device['role'], HostLocator::NETWORK_ROLES, true));
                $macs = $ends ? $this->repo->deviceMacs(array_column($ends, 'device_id')) : [];
                break;
        }

        // the MACs of the addresses, each in the VLAN of the router interface that has the ARP / neighbor entry
        $targets = [];
        if ($ips) {
            $rows = $this->repo->addressesByIps($ips);
            $vlans = $this->interfaceVlans(array_column($rows, 'port_id'));
            foreach ($rows as $row) {
                $vlan = $vlans[$row['port_id']] ?? null;
                $targets[$row['mac'] . '|' . $vlan] ??= ['mac' => $row['mac'], 'vlan' => $vlan, 'optional' => false];
            }
        }
        foreach ($macs as $mac) {
            $targets[$mac . '|'] ??= ['mac' => $mac, 'vlan' => null, 'optional' => $query['kind'] === HostQuery::NAME];
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
            'nac' => array_map(fn ($row) => $row + ['readable' => Mac::readable($row['mac'])], $this->repo->nacByPort($portId)),
            // when the switch's forwarding table was read: its current entries carry the time, else the start of the discovery
            'last_discovery' => $start === null ? null : max($start, $fdb ? max(array_column($fdb, 'last_seen')) : 0),
            'map_url' => ($this->urls)('topology.l2', ['device' => $deviceId, 'depth' => 1]),
        ];
    }

    /**
     * VLAN of a router interface from its name: Vlan10 / Vl10 (Cisco, Arista, Dell, Aruba), Vlanif10
     * (Huawei), Vlan-interface10 (H3C / Comware), irb.10 and vlan.10 (Juniper).
     */
    public static function interfaceVlan(string $name): ?int
    {
        if (! preg_match('/^(?:vl|vlan|vlanif|vlan-interface|irb\.|vlan\.)\s*(\d{1,4})$/i', trim($name), $match)) {
            return null;
        }
        $vlan = (int) $match[1];

        return $vlan >= 1 && $vlan <= 4094 ? $vlan : null;
    }

    /**
     * IP addresses of a name that is not a LibreNMS device, or null with the reason in $message.
     *
     * @return string[]|null
     */
    private function resolve(string $name, ?string &$message): ?array
    {
        if ($this->resolver === null || ! $this->settings->bool('hosts_dns')) {
            $message = $name . ' is not a device in LibreNMS' . ($this->resolver === null ? '.' : ' (DNS lookups are switched off in the plugin settings).');

            return null;
        }

        $ips = ($this->resolver)($name);
        if ($ips === null) {
            $message = 'Too many DNS lookups: try again in a minute.';

            return null;
        }
        $ips = array_slice(array_values(array_unique(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false))), 0, self::MAX_IPS);
        if (! $ips) {
            $message = $name . ' is not a device in LibreNMS and does not resolve in DNS.';

            return null;
        }

        return $ips;
    }

    /**
     * Locate each MAC and describe the answers with names and links.
     *
     * @param  array<int, array{mac: string, vlan: ?int, optional: bool}>  $targets  vlan: only the entries of that VLAN
     *                                                                                 (from the ARP entry); optional: left
     *                                                                                 out when no switch learned it
     * @param  int[]  $searched  devices searched by name, owners of their interface MACs
     * @return array{0: array, 1: bool} answers, truncated
     */
    private function locate(array $targets, array $searched): array
    {
        $byMac = [];
        foreach ($this->repo->fdbByMacs(array_values(array_unique(array_column($targets, 'mac')))) as $row) {
            $byMac[$row['mac']][] = $row;
        }

        // one answer per MAC; a virtual router MAC searched by itself one per VLAN (HSRP group 1 is the same MAC in every VLAN)
        $answers = [];
        foreach ($targets as $target) {
            $rows = $byMac[$target['mac']] ?? [];
            $gateway = Mac::gateway($target['mac']) !== null;
            if (! $rows && $target['optional']) {
                continue;
            }
            if ($target['vlan'] !== null) {
                $answers[] = ['rows' => self::inVlan($rows, $target['vlan'], $gateway)] + $target;
                continue;
            }
            $groups = $gateway ? self::byVlan($rows) : [];
            if (count($groups) > 1) {
                foreach ($groups as [$vlan, $group]) {
                    $answers[] = ['vlan' => $vlan, 'rows' => $group] + $target;
                }
            } else {
                $answers[] = ['rows' => $rows] + $target;
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

        $lag = $this->topology->lagParents($deviceIds);
        $wanted = array_flip($portIds);
        $members = array_keys(array_filter($lag, fn ($parent) => isset($wanted[$parent])));
        $ports = $this->repo->ports(array_merge($portIds, $members));
        $neighbors = $this->repo->neighbors(array_merge($portIds, $members));
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
        $devices = $this->repo->deviceRows(array_merge($deviceIds, $neighborDevices, $ownerDevices));
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
                'aggregate' => isset($aggregates[$portId]) || InterfaceName::isLagName($port['ifName'] ?? '') || ($port['ifType'] ?? '') === 'ieee8023adLag',
            ];
        }
        $locator = new HostLocator($locatorPorts, $neighbors, $lag, $since,
            array_map(fn ($device) => ['name' => $device['name'], 'role' => $device['role']], $devices),
            $this->settings->int('hosts_shared_port_macs', 2, 10000), $switchMacs,
            fn (array $ids) => $this->repo->macCounts($ids, $since));

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
        $nac = [];
        foreach ($this->repo->nacByMacs($macs, $portIds) as $row) {
            $nac[$row['mac']][] = $row;
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
            $located = $locator->locate($mac, $answer['rows'], $owners[$mac] ?? []);
            $vlan = $answer['vlan'];
            $hosts[] = [
                'mac' => $mac,
                'readable' => Mac::readable($mac),
                'vendor' => Mac::vendor($mac, $ouis),
                'vlan' => $vlan,
                'gateway' => $located['gateway'],
                'addresses' => array_values(array_map(fn ($row) => $row + [
                    'display' => self::display($row['ip']),
                    'device' => $devices[$row['device_id']]['name'] ?? null,
                ], array_filter($addresses[$mac] ?? [], fn ($row) => $vlan === null || $row['vlan'] === null || $row['vlan'] === $vlan))),
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
     * The entries of one VLAN (and those without VLAN). A host's MAC is in one VLAN, so when no entry has
     * that VLAN (VLANs not mapped on some switches) all entries are used; a virtual router MAC's only those.
     */
    private static function inVlan(array $rows, int $vlan, bool $strict): array
    {
        $matching = array_values(array_filter($rows, fn ($row) => $row['vlan'] === $vlan || $row['vlan'] === null));
        if ($strict || in_array($vlan, array_column($rows, 'vlan'), true)) {
            return $matching;
        }

        return $rows;
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
