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
    /** MAC addresses located per search (an IP address can have several, e.g. a VRRP virtual MAC). */
    public const MAX_HOSTS = 8;

    /** MACs listed on the port tab. */
    public const PORT_MACS = 50;

    /**
     * A MAC learned on more switches than this (a gateway's) only gets the end of its trails worked
     * out: no per-switch freshness, and MACs counted only on ports without a neighbor.
     */
    public const WIDE_DEVICES = 50;

    /** @var callable(string): string[] */
    private $resolver;

    /**
     * @param  callable(string): string[]|null  $resolver  hostname => IP addresses (default: DNS)
     */
    public function __construct(
        private readonly HostRepository $repo,
        private readonly TopologyRepository $topology,
        private readonly PluginSettings $settings,
        ?callable $resolver = null,
    ) {
        $this->resolver = $resolver ?? [self::class, 'resolve'];
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
                // a monitored device: where its addresses and its own interface MACs are learned
                $devices = $this->repo->devicesByName($query['value']);
                $result['devices'] = $devices;
                $ips = array_values(array_unique(array_merge(...array_column($devices, 'ips') ?: [[]])));
                if (! $ips) {
                    foreach ($devices ? array_column($devices, 'hostname') : [$query['value']] as $name) {
                        $ips = array_merge($ips, ($this->resolver)($name));
                    }
                    $ips = array_slice(array_values(array_unique($ips)), 0, 4);
                }
                $result['resolved'] = array_map(fn ($ip) => str_contains($ip, ':') ? HostQuery::ipv6Short($ip) : $ip, $ips);
                if ($devices) {
                    $macs = array_values(array_unique(array_column($this->repo->fdbByMacs($this->repo->deviceMacs(array_column($devices, 'device_id'))), 'mac')));
                }
                if (! $ips && ! $macs) {
                    $result['message'] = $devices
                        ? $query['value'] . ' is monitored by LibreNMS, but its address is unknown and no switch you can see has learned the MAC address of one of its interfaces.'
                        : $query['value'] . ' is not a device in LibreNMS and does not resolve in DNS.';

                    return $result;
                }
                break;
        }

        foreach ($ips as $ip) {
            $rows = str_contains($ip, ':') ? $this->repo->ndByIp(HostQuery::ipv6Full($ip)) : $this->repo->arpByIp($ip);
            foreach ($rows as $row) {
                $macs[] = $row['mac'];
            }
        }
        $macs = array_values(array_unique(array_filter($macs, fn ($mac) => strlen($mac) === 12)));
        if ($ips && ! $macs) {
            $result['message'] = 'No ARP or IPv6 neighbor entry for ' . implode(', ', $result['resolved'] ?: array_map(fn ($ip) => str_contains($ip, ':') ? HostQuery::ipv6Short($ip) : $ip, $ips))
                . ' on the devices you can see. LibreNMS learns IP addresses from the ARP and neighbor tables of routers and L3 switches.';

            return $result;
        }

        $result['truncated'] = count($macs) > self::MAX_HOSTS;
        $result['hosts'] = $this->locate(array_slice($macs, 0, self::MAX_HOSTS));

        return $result;
    }

    /**
     * Everything the port tab shows about one port: far end, port-channel, VLANs, spanning tree,
     * the MACs learned on it and 802.1X sessions. Queries by port and device only.
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

        $fresh = $this->repo->fdbFreshness([$deviceId]);
        $fdb = $this->repo->fdbByPort($portId, self::PORT_MACS);
        $macs = array_values(array_unique(array_column($fdb, 'mac')));
        $ouis = $this->repo->vendors($macs);
        $addresses = [];
        foreach ($this->repo->addressesByMacs($macs) as $row) {
            $addresses[$row['mac']][] = $row['family'] === 6 ? HostQuery::ipv6Short($row['ip']) : $row['ip'];
        }
        $newest = $fresh[$deviceId] ?? 0;
        $learned = [];
        foreach ($fdb as $row) {
            $learned[] = $row + [
                'readable' => Mac::readable($row['mac']),
                'vendor' => Mac::vendor($row['mac'], $ouis),
                'ips' => array_values(array_unique($addresses[$row['mac']] ?? [])),
                'stale' => $row['last_seen'] < $newest - HostLocator::STALE_AFTER,
                'locate_url' => route('topology.hosts', ['q' => Mac::readable($row['mac'])]),
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
            'mac_count' => $this->repo->macCounts([$portId], $fresh, HostLocator::STALE_AFTER)[$portId] ?? 0,
            'macs' => $learned,
            'nac' => array_map(fn ($row) => $row + ['readable' => Mac::readable($row['mac'])], $this->repo->nacByPort($portId)),
            'last_discovery' => $newest ?: null,
            'map_url' => route('topology.l2', ['device' => $deviceId, 'depth' => 1]),
        ];
    }

    /**
     * Locate each MAC and describe the answer with names and links.
     *
     * @param  string[]  $macs
     */
    private function locate(array $macs): array
    {
        $fdb = $this->repo->fdbByMacs($macs);
        $deviceIds = array_values(array_unique(array_column($fdb, 'device_id')));
        $portIds = array_values(array_unique(array_filter(array_column($fdb, 'port_id'))));
        $wide = count($deviceIds) > self::WIDE_DEVICES;
        $fresh = $wide ? [] : $this->repo->fdbFreshness($deviceIds);

        $lag = $this->topology->lagParents($deviceIds);
        $wanted = array_flip($portIds);
        $members = array_keys(array_filter($lag, fn ($parent) => isset($wanted[$parent])));
        $ports = $this->repo->ports(array_merge($portIds, $members));
        $neighbors = $this->repo->neighbors(array_merge($portIds, $members));
        $countPorts = $wide ? array_filter($portIds, fn ($id) => empty($neighbors[$id]) && ! in_array($id, $lag, true)) : $portIds;
        $counts = $this->repo->macCounts($countPorts, $fresh, HostLocator::STALE_AFTER);

        $neighborDevices = [];
        foreach ($neighbors as $list) {
            foreach ($list as $neighbor) {
                if ($neighbor['device_id']) {
                    $neighborDevices[] = $neighbor['device_id'];
                }
            }
        }
        $devices = $this->repo->deviceRows(array_merge($deviceIds, $neighborDevices));
        $names = array_map(fn ($device) => $device['name'], $devices);

        $locatorPorts = [];
        $aggregates = array_flip(array_values($lag));
        foreach ($ports as $portId => $port) {
            $locatorPorts[$portId] = [
                'device_id' => (int) $port['device_id'],
                'name' => self::portLabel($port, $portId),
                'aggregate' => isset($aggregates[$portId]) || InterfaceName::isLagName($port['ifName'] ?? '') || ($port['ifType'] ?? '') === 'ieee8023adLag',
                'macs' => $counts[$portId] ?? 0,
            ];
        }
        $locator = new HostLocator($locatorPorts, $neighbors, $lag, $fresh, $names, $this->settings->int('hosts_shared_port_macs', 2, 10000));

        $vlanNames = [];
        $byMac = [];
        foreach ($fdb as $row) {
            $byMac[$row['mac']][] = $row;
            if ($row['vlan'] !== null && $row['vlan_name'] !== null) {
                $vlanNames[$row['device_id'] . ':' . $row['vlan']] = $row['vlan_name'];
            }
        }
        $ouis = $this->repo->vendors($macs);
        $addresses = [];
        foreach ($this->repo->addressesByMacs($macs) as $row) {
            $addresses[$row['mac']][] = $row;
        }
        $nac = [];
        foreach ($this->repo->nacByMacs($macs) as $row) {
            $nac[$row['mac']][] = $row;
        }
        $ownerDevices = array_values(array_unique(array_merge($deviceIds, $neighborDevices)));

        $describe = function (array $entry) use ($ports, $devices, $vlanNames): array {
            $deviceId = $entry['device_id'];
            $port = $ports[$entry['port_id']] ?? null;
            $neighbor = $entry['neighbor'] ?? null;

            return array_merge($entry, [
                'device' => $devices[$deviceId]['name'] ?? '#' . $deviceId,
                'device_url' => $devices[$deviceId]['url'] ?? url('device/' . $deviceId),
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
        foreach ($macs as $mac) {
            $located = $locator->locate($mac, $byMac[$mac] ?? [], $this->repo->owners($mac, $ownerDevices));
            $edge = $located['edge'] ? $describe($located['edge']) : null;
            $hosts[] = [
                'mac' => $mac,
                'readable' => Mac::readable($mac),
                'vendor' => Mac::vendor($mac, $ouis),
                'addresses' => array_map(fn ($row) => $row + [
                    'display' => $row['family'] === 6 ? HostQuery::ipv6Short($row['ip']) : $row['ip'],
                    'device' => $devices[$row['device_id']]['name'] ?? null,
                ], $addresses[$mac] ?? []),
                'nac' => $nac[$mac] ?? [],
                'status' => $located['status'],
                'stale' => $located['stale'],
                'edge' => $edge === null ? null : $edge + [
                    'map_url' => route('topology.l2', ['device' => $edge['device_id'], 'depth' => 1, 'host' => $mac]),
                ],
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

        return $hosts;
    }

    /**
     * Short interface name (Gi1/0/1), ifDescr when there is no ifName.
     */
    private static function portLabel(?array $port, int $portId): string
    {
        return $port ? InterfaceName::short(InterfaceName::label($port)) : 'port #' . $portId;
    }

    /**
     * A and AAAA records of a name, with a short resolver timeout so a broken DNS does not hang the page.
     *
     * @return string[]
     */
    public static function resolve(string $name): array
    {
        $previous = getenv('RES_OPTIONS');
        putenv('RES_OPTIONS=timeout:1 attempts:1');
        try {
            $records = @dns_get_record($name, DNS_A | DNS_AAAA) ?: [];
        } catch (\Throwable) {
            $records = [];
        } finally {
            putenv($previous === false ? 'RES_OPTIONS' : 'RES_OPTIONS=' . $previous);
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}
