<?php

namespace Agoodley\LibrenmsTopology\Path;

use Agoodley\LibrenmsTopology\Stp\Finding;
use Agoodley\LibrenmsTopology\Stp\StpAnalyzer;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * What the page and the API show of a path: the hops with their ports (speed, status, duplex, traffic, errors, spanning
 * tree state, port-channel members, the router interface used), the link to the next hop and the routing decision;
 * the problems on the path; totals; the L2 map link with the path highlighted. Plain PHP on the rows of PathAssembler
 * and the rows of their devices and ports.
 */
class PathReport
{
    /** Utilization from which a link is critically full (percent). */
    private const FULL = 95;

    /**
     * @param  \Closure(string, array): string  $urls  route name and parameters => URL
     */
    public function __construct(private readonly PluginSettings $settings, private readonly \Closure $urls)
    {
    }

    /**
     * Ports a description of the rows reads: the hops' ports, the router interfaces on them, the members of their
     * port-channels.
     *
     * @param  array<int, array>  $rows
     * @return int[]
     */
    public static function portIds(array $rows): array
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

        return array_values(array_unique(array_filter(array_map(fn ($id) => $id === null ? null : (int) $id, $portIds))));
    }

    /**
     * Hop rows for the page and the API: names, links, ports with their counters, the routing decision.
     *
     * @param  array<int, array>  $rows
     * @param  array{devices: array<int, array>, ports: array<int, array>, statistics: array<int, array>, stp: \Closure(int, int, ?int): ?array}  $details
     *     the rows of the hops' devices and of their ports (portIds()), discard counters, the spanning tree state of a port in a VLAN
     */
    public function hops(array $rows, array $details): array
    {
        $hops = [];
        foreach ($rows as $index => $row) {
            $next = $rows[$index + 1] ?? null;
            $hop = ['type' => $row['kind'], 'name' => $row['name'], 'device_id' => null, 'url' => null, 'role' => null, 'node' => null,
                'in' => null, 'out' => null, 'layer' => $row['l3'] !== null ? 'l3' : 'l2', 'vlan' => $row['in_vlan'] ?? $row['out_vlan'], 'vrf' => null, 'route' => null,
                'connected' => null, 'estimated' => null, 'estimated_why' => null, 'toward' => null, 'routes_read' => null, 'link' => null, 'notes' => []];
            switch ($row['kind']) {
                case 'host':
                    $endpoint = $row['endpoint'];
                    $hop = array_merge($hop, ['name' => $endpoint['label'], 'mac' => $endpoint['readable'], 'ips' => array_map([PathService::class, 'display'], $endpoint['ips']),
                        'vendor' => $endpoint['vendor'], 'url' => $endpoint['host_url']]);
                    break;
                case 'device':
                    $device = $details['devices'][$row['device_id']] ?? [];
                    $l3 = $row['l3'];
                    $hop = array_merge($hop, [
                        'name' => (string) (($device['display'] ?? '') ?: ($device['hostname'] ?? 'device #' . $row['device_id'])), 'device_id' => $row['device_id'],
                        'url' => $device['url'] ?? null, 'role' => $device['role'] ?? null, 'node' => $row['node'],
                        'in' => $this->portView($row['device_id'], $row['in_port'], $row['in_edge'], $row['node'], $row['in_vlan'], $details, $l3['in_port'] ?? null, $l3['in_address'] ?? null),
                        'out' => $this->portView($row['device_id'], $row['out_port'], $row['out_edge'], $row['node'], $row['out_vlan'], $details, $l3['out_port'] ?? null, $l3['out_address'] ?? null),
                    ]);
                    if ($l3 !== null) {
                        $hop['vrf'] = ($l3['vrf'] ?? '') !== '' ? $l3['vrf'] : null;
                        $hop['estimated'] = $l3['estimated'] ?? null;
                        $hop['estimated_why'] = $l3['estimated_why'] ?? null;
                        $hop['connected'] = $l3['connected'] ?? null;
                        $hop['toward'] = isset($l3['toward']) ? PathService::display((string) $l3['toward']) : null;
                        $hop['routes_read'] = $l3['routes_read'] ?? null;
                        if (($l3['route'] ?? null) !== null || ($l3['next_hop'] ?? null) !== null) {
                            $route = $l3['route'] ?? [];
                            $hop['route'] = [
                                'prefix' => $route['prefix'] ?? null,
                                'next_hop' => ($l3['next_hop'] ?? null) !== null ? PathService::display($l3['next_hop']) : null,
                                'via' => ($l3['via'] ?? null) !== null ? PathService::display($l3['via']) : null,
                                'next_hops' => array_map([PathService::class, 'display'], $route['next_hops'] ?? []),
                                'protocol' => $route['protocol'] ?? null,
                                'metric' => $route['metric'] ?? null,
                                'updated' => $route['updated'] ?? null,
                                'recursive' => (bool) ($l3['recursive'] ?? false),
                                'unread' => ($l3['unread'] ?? null) !== null ? PathService::display($l3['unread']) : null,
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
                $hop['link'] = self::linkView($row['out_edge'], $details['ports']);
            }
            if ($row['link_note'] !== null) {
                $hop['notes'][] = ['level' => 'info', 'text' => $row['link_note']];
            }
            $hops[] = $hop;
        }

        return $hops;
    }

    /**
     * Problems on the path: ports down, half duplex, errors, discards, busy links, port-channels missing members,
     * estimated hops.
     */
    public function check(array $hops, PathLog $log): void
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
                $estimated[] = $hop;
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
                    $log->add(Finding::CRITICAL, 'port_down', 'port down on the path: ' . $label,
                        "$label is " . $port['status'] . ($port['admin'] === 'down' ? ' (shut down)' : '') . '.', 'Bring the port up, or move the host to a working port.', $devices, $ports);
                }
                if ($port['duplex'] === 'half') {
                    $log->add(Finding::WARNING, 'half_duplex', 'half duplex on the path: ' . $label,
                        "$label runs half duplex: collisions slow every transfer on the path.",
                        'Set both ends to auto-negotiation, or both to the same fixed speed and full duplex.', $devices, $ports);
                }
                if ($errorLimit > 0 && $port['in_errors'] + $port['out_errors'] >= $errorLimit) {
                    $log->add(Finding::WARNING, 'port_errors', 'interface errors on the path: ' . $label,
                        "$label: " . number_format($port['in_errors']) . ' input and ' . number_format($port['out_errors']) . ' output errors' . self::period($port['period']) . '.',
                        'Check the cable, the patch panel and the optics on both ends, and look for a duplex mismatch.', $devices, $ports);
                }
                foreach (['in' => ['incoming', 'inbound'], 'out' => ['outgoing', 'outbound']] as $direction => [$packetsWord, $trafficWord]) {
                    $discards = $port[$direction . '_discards'];
                    $packets = $port[$direction . '_packets'] + $discards;
                    if ($discardPercent > 0 && $discards >= 100 && $discards / max(1, $packets) * 100 >= $discardPercent) {
                        $log->add(Finding::WARNING, 'port_discards', 'packet discards on the path: ' . $label,
                            "$label discarded " . round($discards / max(1, $packets) * 100, 1) . "% of $packetsWord packets (" . number_format($discards) . ')' . self::period($port['period']) . '.',
                            'Outgoing discards mean congestion or microbursts: check the utilization and QoS of this link.', $devices, $ports);
                    }
                    $util = $port[$direction . '_util'];
                    if ($util !== null && $util >= $busy) {
                        $log->add($util >= self::FULL ? Finding::CRITICAL : Finding::WARNING, 'busy_link', 'busy link on the path: ' . $label,
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
                    $log->add(Finding::WARNING, 'lag_degraded', 'port-channel missing members on the path: ' . $label,
                        "The port-channel is up, but not all its members are: $missing. The path runs on less capacity and without the redundancy the port-channel should give.",
                        'Check the cables and optics of the missing members, and that both ends bundle them.', $devices, $ports);
                }
            }
        }
        if ($estimated) {
            $names = implode(', ', array_map(fn ($hop) => $hop['name'], $estimated));
            $states = array_values(array_unique(array_map(fn ($hop) => (string) ($hop['estimated_why'] ?? L3Tracer::ROUTES_NONE), $estimated)));
            $one = count($estimated) === 1;
            $log->add(Finding::INFO, 'estimated_hops', ($one ? 'the next hop of ' : 'the next hops of ') . $names . ($one ? ' is' : ' are') . ' estimated',
                'No current routes of ' . ($one ? 'this router' : 'these routers') . ' could be used (see each estimate for why): the next hop is estimated from'
                . ' the subnets and OSPF / BGP neighbors ' . ($one ? 'it shares' : 'they share') . ' with other routers. With static routes, policy routing or ECMP the real path can differ.',
                implode(' ', array_map(fn ($state) => PathAssembler::routesAdvice($state), $states)), array_map(fn ($hop) => $hop['device_id'], $estimated));
        }
    }

    /**
     * Totals of the path: devices, routers, the slowest and the busiest link, findings.
     */
    public function summary(array $hops, array $findings, bool $complete, string $mode): array
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
     * between each two (a dash where the path is not drawn: a gap, a device out of the user's sight); path_hidden=1 when
     * the path crosses a device out of the user's sight (the map does not show what lies beyond it).
     */
    public function mapUrl(array $hops, ?int $vlan): ?string
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
            'path_hidden' => array_filter($hops, fn ($hop) => $hop['type'] === 'hidden') ? 1 : null,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * A port of a hop: name, link to its page, speed, status, duplex, traffic, errors, spanning tree state, members of a
     * port-channel, and the router interface (and address) the traffic uses on it.
     */
    private function portView(int $deviceId, ?int $portId, ?array $edge, string $node, ?int $vlan, array $details, ?int $interfaceId, ?string $address): ?array
    {
        $ports = $details['ports'];
        $interface = null;
        $own = $interfaceId !== null && ($interfaceId === $portId || $portId === null);
        if ($interfaceId !== null && ! $own && ($port = $ports[$interfaceId] ?? null) !== null) {
            $interface = ['name' => InterfaceName::short(InterfaceName::label($port)), 'url' => $port['url'] ?? null,
                'address' => $address !== null ? PathService::display($address) : null];
        }
        $portId ??= $interfaceId;
        $port = $portId !== null ? ($ports[$portId] ?? null) : null;
        if ($port === null) {
            return $interface === null ? null : ['name' => $interface['name'], 'interface' => $interface];
        }
        $speed = (int) ($port['ifSpeed'] ?? 0);
        $in = (int) ($port['ifInOctets_rate'] ?? 0) * 8;
        $out = (int) ($port['ifOutOctets_rate'] ?? 0) * 8;
        $stats = $details['statistics'][$portId] ?? [];
        $members = [];
        foreach ($edge !== null ? L2Tracer::membersOn($edge, $node) : [] as $member) {
            $memberPort = $member !== $portId ? ($ports[$member] ?? null) : null;
            if ($memberPort !== null) {
                $members[] = ['name' => InterfaceName::short(InterfaceName::label($memberPort)), 'url' => $memberPort['url'] ?? null,
                    'status' => $memberPort['ifOperStatus'] ?? null, 'speed' => (int) ($memberPort['ifSpeed'] ?? 0)];
            }
        }
        $stp = ($details['stp'])($deviceId, $portId, $vlan);

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
            'address' => $own && $address !== null ? PathService::display($address) : null,
            'interface' => $interface,
        ];
    }

    /**
     * The link from a hop to the next: speed, port-channel members up.
     *
     * @param  array<int, array>  $ports
     */
    private static function linkView(array $edge, array $ports): array
    {
        $up = 0;
        foreach ($edge['members'] as $member) {
            $states = array_filter([$ports[(int) $member['a']]['ifOperStatus'] ?? null, $ports[(int) $member['b']]['ifOperStatus'] ?? null]);
            $up += array_diff($states, ['up']) ? 0 : 1;
        }
        $count = count($edge['members']);

        return [
            'speed' => L2Tracer::linkSpeed($edge, $ports),
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
}
