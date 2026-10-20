<?php

namespace Agoodley\LibrenmsTopology\History;

use Agoodley\LibrenmsTopology\Support\DeviceRole;

/**
 * The Changes page: what appeared and disappeared in a time window, told the way an engineer
 * would (a moved cable rather than a link removed plus a link added, one root bridge change per
 * spanning tree instance rather than one per switch).
 *
 * Facts come as stored periods of presence (FactCollector + the snapshot): a fact added in the
 * window started in it (except facts of the first snapshot, which existed before the history
 * started), a fact removed in the window ended in it.
 */
class ChangeReport
{
    /** Unmanaged neighbors that are no news when they show up (any other LLDP/CDP speaker may forward traffic). */
    private const END_ROLES = [DeviceRole::PHONE, DeviceRole::SERVER];

    /** Events of one change: what it is about, how much attention it needs. */
    public const TYPES = [
        'device_added' => ['devices', 'info'],
        'cable_moved' => ['links', 'warning'],
        'link_removed' => ['links', 'warning'],
        'link_back' => ['links', 'info'],
        'link_added' => ['links', 'info'],
        'unmanaged_new' => ['links', 'warning'],
        'neighbor_new' => ['links', 'info'],
        'lag_member_removed' => ['links', 'warning'],
        'lag_member_added' => ['links', 'info'],
        'root_changed' => ['stp', 'warning'],
        'root_new' => ['stp', 'info'],
        'root_gone' => ['stp', 'info'],
        'blocking_started' => ['stp', 'info'],
        'blocking_stopped' => ['stp', 'info'],
        'ospf_lost' => ['routing', 'warning'],
        'ospf_up' => ['routing', 'info'],
        'bgp_lost' => ['routing', 'warning'],
        'bgp_up' => ['routing', 'info'],
    ];

    /** @var array<int, array> */
    private array $events = [];

    /**
     * @param  array<int, true>  $newDevices  device_id => true for devices added to LibreNMS in the window
     * @param  array<string, true>  $priorNodes  unmanaged neighbor node ids already seen before the window
     */
    private function __construct(private readonly FactText $text, private readonly array $newDevices, private readonly array $priorNodes)
    {
    }

    /**
     * @param  array<int, array>  $rows  stored facts (kind, fact_key, device_a, device_b, data as array, initial,
     *                                   first_seen and removed_at as timestamps) that started or ended in the window
     * @return array{events: array<int, array{time: int, type: string, category: string, level: string, text: string, devices: int[]}>,
     *     counts: array<string, int>}
     */
    public static function build(array $rows, int $from, int $to, FactText $text, array $newDevices = [], array $priorNodes = []): array
    {
        $report = new self($text, $newDevices, $priorNodes);
        $added = [];
        $removed = [];
        foreach ($rows as $row) {
            if (! $row['initial'] && $row['first_seen'] >= $from && $row['first_seen'] <= $to) {
                $added[] = $row;
            }
            if ($row['removed_at'] !== null && $row['removed_at'] >= $from && $row['removed_at'] <= $to) {
                $removed[] = $row;
            }
        }

        [$addedLinks, $removedLinks] = $report->sameCables(self::ofKind($added, FactCollector::LINK), self::ofKind($removed, FactCollector::LINK));
        $added = $report->newDevices(array_merge($addedLinks, self::notOfKind($added, FactCollector::LINK)));
        $report->links(self::ofKind($added, FactCollector::LINK), $removedLinks);
        $report->lagMembers(self::ofKind($added, FactCollector::LAG), 'lag_member_added');
        $report->lagMembers(self::ofKind($removed, FactCollector::LAG), 'lag_member_removed');
        $report->roots(self::ofKind($added, FactCollector::STP_ROOT), self::ofKind($removed, FactCollector::STP_ROOT));
        $report->blocking(self::ofKind($added, FactCollector::STP_BLOCKED), self::ofKind($removed, FactCollector::STP_BLOCKED));
        $report->adjacencies(self::ofKind($added, FactCollector::OSPF), 'ospf_up');
        $report->adjacencies(self::ofKind($removed, FactCollector::OSPF), 'ospf_lost');
        $report->adjacencies(self::ofKind($added, FactCollector::BGP), 'bgp_up');
        $report->adjacencies(self::ofKind($removed, FactCollector::BGP), 'bgp_lost');

        $order = array_flip(array_keys(self::TYPES));
        usort($report->events, fn ($a, $b) => [$b['time'], $order[$a['type']], $a['text']] <=> [$a['time'], $order[$b['type']], $b['text']]);

        $counts = array_fill_keys(array_keys(self::TYPES), 0);
        foreach ($report->events as $event) {
            $counts[$event['type']]++;
        }

        return ['events' => $report->events, 'counts' => $counts];
    }

    /**
     * Facts of devices that were added to LibreNMS in the window are one event per device: LibreNMS
     * started to see them, the network did not change.
     *
     * @return array<int, array> the other added facts
     */
    private function newDevices(array $added): array
    {
        $byDevice = [];
        $rest = [];
        foreach ($added as $row) {
            $device = isset($this->newDevices[(int) $row['device_a']]) ? (int) $row['device_a']
                : (isset($this->newDevices[(int) $row['device_b']]) ? (int) $row['device_b'] : null);
            if ($device === null) {
                $rest[] = $row;
            } else {
                $byDevice[$device][] = $row;
            }
        }

        $kinds = [
            FactCollector::LINK => ['link', 'links'], FactCollector::LAG => ['port-channel member', 'port-channel members'],
            FactCollector::STP_ROOT => ['spanning tree instance', 'spanning tree instances'], FactCollector::STP_BLOCKED => ['blocked port', 'blocked ports'],
            FactCollector::OSPF => ['OSPF adjacency', 'OSPF adjacencies'], FactCollector::BGP => ['BGP session', 'BGP sessions'],
        ];
        foreach ($byDevice as $device => $rows) {
            $counts = [];
            foreach ($rows as $row) {
                // a root bridge fact holds all the instances (VLANs) it is the root of
                $counts[$row['kind']] = ($counts[$row['kind']] ?? 0) + ($row['kind'] === FactCollector::STP_ROOT ? count(FactText::vlans($row['data'])) : 1);
            }
            $parts = [];
            foreach ($kinds as $kind => [$one, $many]) {
                if (isset($counts[$kind])) {
                    $parts[] = $counts[$kind] . ' ' . ($counts[$kind] === 1 ? $one : $many);
                }
            }
            $this->add(min(array_column($rows, 'first_seen')), 'device_added',
                $this->text->device($device, null) . ' was added to LibreNMS: ' . implode(', ', $parts), [$device]);
        }

        return $rest;
    }

    /**
     * LibreNMS starting or stopping to monitor a neighbor turns the same cable from an unmanaged
     * neighbor into a link between two monitored devices, or back: no change of the network. Such
     * pairs are left out; the link of a device added to LibreNMS stays for its "added" event.
     *
     * @return array{0: array<int, array>, 1: array<int, array>} added and removed link rows left
     */
    private function sameCables(array $added, array $removed): array
    {
        $removedByPort = [];
        foreach ($removed as $index => $row) {
            foreach (self::monitoredEnds($row) as $port) {
                $removedByPort[$port][] = $index;
            }
        }

        foreach ($added as $addedIndex => $row) {
            foreach (self::monitoredEnds($row) as $port) {
                foreach ($removedByPort[$port] ?? [] as $index) {
                    if (! isset($removed[$index])) {
                        continue;
                    }
                    [, $was] = self::splitAt($removed[$index], $port);
                    [, $now] = self::splitAt($row, $port);
                    if (FactCollector::sameNeighbor($was['end'], $now['end'])) {
                        unset($removed[$index]);
                        if (! isset($this->newDevices[(int) $row['device_a']]) && ! isset($this->newDevices[(int) $row['device_b']])) {
                            unset($added[$addedIndex]);
                        }
                        continue 3;
                    }
                }
            }
        }

        return [array_values($added), array_values($removed)];
    }

    /**
     * Links: a port whose neighbor changed is a moved cable (or a link that came back), the rest
     * are new or gone links; a new unmanaged switch, access point or unknown LLDP/CDP speaker is
     * called out (phones and servers are plain new links).
     */
    private function links(array $added, array $removed): void
    {
        $removedByPort = [];
        foreach ($removed as $index => $row) {
            foreach (self::monitoredEnds($row) as $port) {
                $removedByPort[$port][] = $index;
            }
        }

        $usedRemoved = [];
        $rest = [];
        foreach ($added as $row) {
            $match = null;
            foreach (self::monitoredEnds($row) as $port) {
                foreach ($removedByPort[$port] ?? [] as $index) {
                    if (! isset($usedRemoved[$index]) && $removed[$index]['removed_at'] <= $row['first_seen']) {
                        $match = [$port, $index];
                        break 2;
                    }
                }
            }
            if ($match === null) {
                $rest[] = $row;
                continue;
            }

            [$port, $index] = $match;
            $usedRemoved[$index] = true;
            $old = $removed[$index];
            [$here, $was] = self::splitAt($old, $port);
            [, $now] = self::splitAt($row, $port);
            if ($was['key'] === $now['key']) {
                $this->add($row['first_seen'], 'link_back', $this->text->describe($this->fact($row)) . ': back after it was gone '
                    . self::span($row['first_seen'] - $old['removed_at']), self::devices($row));
            } else {
                $this->add($row['first_seen'], 'cable_moved', $this->text->linkEnd($here['end']) . ' now connects to ' . $this->text->linkEnd($now['end'])
                    . ' (was ' . $this->text->linkEnd($was['end']) . ')', array_values(array_unique(array_merge(self::devices($row), self::devices($old)))));
            }
        }

        $newNodes = [];
        foreach ($rest as $row) {
            $node = $row['data']['b']['node'] ?? null;
            if ($node !== null && ! isset($this->priorNodes[$node]) && ! in_array($row['data']['b']['role'] ?? null, self::END_ROLES, true)) {
                $newNodes[$node][] = $row;
                continue;
            }
            $this->add($row['first_seen'], 'link_added', 'New link ' . $this->text->describe($this->fact($row)), self::devices($row));
        }
        foreach ($newNodes as $rows) {
            $b = $rows[0]['data']['b'];
            $where = array_map(fn ($row) => $this->text->linkEnd($row['data']['a']), $rows);
            $role = self::role($b['role'] ?? null);
            $this->add(min(array_column($rows, 'first_seen')), $role === 'neighbor' ? 'neighbor_new' : 'unmanaged_new', "New unmanaged $role " . ($b['name'] ?? 'neighbor')
                . (isset($b['platform']) ? ' (' . $b['platform'] . ')' : '') . ' on ' . implode(', ', $where), array_values(array_unique(array_merge(...array_map(fn ($row) => self::devices($row), $rows)))));
        }

        foreach ($removed as $index => $row) {
            if (! isset($usedRemoved[$index])) {
                $this->add($row['removed_at'], 'link_removed', 'Link gone: ' . $this->text->describe($this->fact($row)), self::devices($row));
            }
        }
    }

    /**
     * Port-channel members that joined or left, one event per port-channel and snapshot.
     */
    private function lagMembers(array $rows, string $type): void
    {
        $groups = [];
        foreach ($rows as $row) {
            $time = $type === 'lag_member_added' ? $row['first_seen'] : $row['removed_at'];
            $groups[$time . '|' . $row['data']['device'] . '|' . $row['data']['aggregate_name']][] = $row;
        }
        foreach ($groups as $group) {
            $data = $group[0]['data'];
            $members = implode(', ', array_column(array_column($group, 'data'), 'member_name'));
            $this->add($type === 'lag_member_added' ? $group[0]['first_seen'] : $group[0]['removed_at'], $type,
                $this->text->device($data['device'], $data['device_name']) . ' ' . $data['aggregate_name'] . ': ' . $members
                . ($type === 'lag_member_added' ? ' joined' : ' left'),
                [(int) $data['device']]);
        }
    }

    /**
     * Root bridge changes. A switch's root bridge facts that ended and started at one snapshot give
     * the root of each instance (VLAN) before and after; instances whose root changed, appeared or
     * went away make one event per change and snapshot, with the switches that saw it.
     */
    private function roots(array $added, array $removed): void
    {
        $sides = [];
        foreach ($removed as $row) {
            $sides[$row['data']['device'] . '|' . $row['removed_at']]['before'][] = $row['data'];
        }
        foreach ($added as $row) {
            $sides[$row['data']['device'] . '|' . $row['first_seen']]['after'][] = $row['data'];
        }

        $changes = [];
        foreach ($sides as $key => $side) {
            [$device, $time] = array_map('intval', explode('|', $key));
            $before = self::byVlan($side['before'] ?? []);
            $after = self::byVlan($side['after'] ?? []);
            foreach ($after + $before as $vlan => $ignored) {
                $old = $before[$vlan] ?? null;
                $new = $after[$vlan] ?? null;
                if ($old !== null && $new !== null && $old['root'] === $new['root']) {
                    continue; // same root bridge, other instances changed
                }
                $type = $old === null ? 'root_new' : ($new === null ? 'root_gone' : 'root_changed');
                $group = $type . '|' . $time . '|' . ($old['root'] ?? '') . '|' . ($new['root'] ?? '');
                $changes[$group] ??= ['type' => $type, 'time' => $time, 'old' => $old, 'new' => $new, 'vlans' => [], 'devices' => []];
                $changes[$group]['vlans'][$vlan] = true;
                $changes[$group]['devices'][$device] = true;
            }
        }

        foreach ($changes as $change) {
            $devices = array_keys($change['devices']);
            $seen = $this->deviceList($devices);
            $instances = FactText::vlanList(array_keys($change['vlans']));
            $text = match ($change['type']) {
                'root_changed' => "$instances: root bridge changed from " . $this->text->root($change['old']) . ' to ' . $this->text->root($change['new']) . " (seen by $seen)",
                'root_new' => "$instances: root bridge " . $this->text->root($change['new']) . " (new instance on $seen)",
                default => "$instances: no longer reported by $seen (root bridge was " . $this->text->root($change['old']) . ')',
            };
            $this->add($change['time'], $change['type'], $text, $devices);
        }
    }

    /**
     * Instance (VLAN key) => root bridge fact data.
     *
     * @param  array<int, array>  $facts  root bridge fact data of one switch
     * @return array<int, array>
     */
    private static function byVlan(array $facts): array
    {
        $roots = [];
        foreach ($facts as $data) {
            foreach (FactText::vlans($data) as $vlan) {
                $roots[$vlan] = $data;
            }
        }

        return $roots;
    }

    /**
     * Ports that started or stopped blocking: a port's facts that ended and started at one snapshot
     * give the instances it blocked before and after; one event per port, snapshot and direction.
     */
    private function blocking(array $added, array $removed): void
    {
        $ports = [];
        foreach ($removed as $row) {
            $ports[$row['removed_at'] . '|' . $row['data']['device'] . '|' . $row['data']['port_name']]['before'][] = $row['data'];
        }
        foreach ($added as $row) {
            $ports[$row['first_seen'] . '|' . $row['data']['device'] . '|' . $row['data']['port_name']]['after'][] = $row['data'];
        }

        foreach ($ports as $key => $side) {
            $time = (int) explode('|', $key)[0];
            $data = ($side['after'] ?? $side['before'])[0];
            $before = array_merge([], ...array_map(fn ($facts) => FactText::vlans($facts), $side['before'] ?? []));
            $after = array_merge([], ...array_map(fn ($facts) => FactText::vlans($facts), $side['after'] ?? []));
            foreach (['blocking_started' => array_diff($after, $before), 'blocking_stopped' => array_diff($before, $after)] as $type => $vlans) {
                if ($vlans) {
                    $this->add($time, $type, $this->text->device($data['device'], $data['device_name']) . ' ' . $data['port_name']
                        . ($type === 'blocking_started' ? ' started blocking in ' : ' stopped blocking in ') . FactText::vlanList($vlans),
                        [(int) $data['device']]);
                }
            }
        }
    }

    /**
     * OSPF adjacencies and BGP sessions; when both routers are monitored their two reports of the
     * same adjacency become one event (parallel adjacencies between two routers stay apart).
     */
    private function adjacencies(array $rows, string $type): void
    {
        $groups = [];
        foreach ($rows as $row) {
            $data = $row['data'];
            $time = str_ends_with($type, '_up') ? $row['first_seen'] : $row['removed_at'];
            $pair = ($data['neighbor'] ?? null) !== null
                ? min($data['device'], $data['neighbor']) . '-' . max($data['device'], $data['neighbor'])
                : $data['device'] . '>' . ($data['ip'] ?? $data['peer'] ?? '');
            $groups[$time . '|' . $data['context'] . '|' . $pair][] = $row;
        }

        $words = [
            'ospf_up' => ['OSPF adjacency ', ' up'], 'ospf_lost' => ['OSPF adjacency ', ' lost'],
            'bgp_up' => ['BGP session ', ' established'], 'bgp_lost' => ['BGP session ', ' down'],
        ];
        foreach ($groups as $group) {
            usort($group, fn ($a, $b) => $a['data']['device'] <=> $b['data']['device']);
            // one report from each router is one adjacency; anything else is several of them
            $both = count($group) === 2 && $group[0]['data']['device'] !== $group[1]['data']['device'];
            foreach ($both ? [$group] : array_map(fn ($row) => [$row], $group) as $reports) {
                $row = $reports[0];
                $describe = str_starts_with($type, 'ospf') ? $this->text->ospf($row['data']) : $this->text->bgp($row['data']);
                $this->add(str_ends_with($type, '_up') ? $row['first_seen'] : $row['removed_at'], $type,
                    $words[$type][0] . $describe . $words[$type][1] . ($both ? ' (reported by both routers)' : ''),
                    array_values(array_unique(array_merge(...array_map(fn ($row) => self::devices($row), $reports)))));
            }
        }
    }

    private function add(int $time, string $type, string $text, array $devices): void
    {
        [$category, $level] = self::TYPES[$type];
        $this->events[] = [
            'time' => $time,
            'type' => $type,
            'category' => $category,
            'level' => $level,
            'text' => $text,
            'devices' => array_values(array_filter($devices, fn ($id) => $this->text->visible($id))),
        ];
    }

    /**
     * "core1, core2 and 7 more" for the devices the user may see (the others are not counted).
     *
     * @param  int[]  $devices
     */
    private function deviceList(array $devices): string
    {
        $names = [];
        foreach ($devices as $device) {
            if ($this->text->visible($device)) {
                $names[] = $this->text->device($device, null);
            }
        }
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $more = count($names) - 5;

        return implode(', ', array_slice($names, 0, 5)) . ($more > 0 ? " and $more more" : '');
    }

    /**
     * Stored row as the fact array FactText describes.
     */
    private function fact(array $row): array
    {
        return ['kind' => $row['kind'], 'key' => $row['fact_key'], 'data' => $row['data']];
    }

    /**
     * @return array<int, array>
     */
    private static function ofKind(array $rows, string $kind): array
    {
        return array_values(array_filter($rows, fn ($row) => $row['kind'] === $kind));
    }

    /**
     * @return array<int, array>
     */
    private static function notOfKind(array $rows, string $kind): array
    {
        return array_values(array_filter($rows, fn ($row) => $row['kind'] !== $kind));
    }

    /**
     * Identities of the ends of a link on monitored devices ("d5:gi1/0/49").
     *
     * @return string[]
     */
    private static function monitoredEnds(array $row): array
    {
        return array_values(array_filter(explode('|', $row['fact_key']), fn ($end) => str_starts_with($end, 'd')));
    }

    /**
     * The end of a link at $port and the far end, each as identity + data.
     *
     * @return array{0: array{key: string, end: array}, 1: array{key: string, end: array}}
     */
    private static function splitAt(array $row, string $port): array
    {
        [$a, $b] = explode('|', $row['fact_key'], 2);

        return $a === $port
            ? [['key' => $a, 'end' => $row['data']['a']], ['key' => $b, 'end' => $row['data']['b']]]
            : [['key' => $b, 'end' => $row['data']['b']], ['key' => $a, 'end' => $row['data']['a']]];
    }

    /**
     * @return int[]
     */
    private static function devices(array $row): array
    {
        return array_values(array_filter([(int) $row['device_a'], (int) $row['device_b']]));
    }

    private static function role(?string $role): string
    {
        return match ($role) {
            DeviceRole::SWITCH, DeviceRole::L3SWITCH => 'switch',
            DeviceRole::WIRELESS => 'access point',
            DeviceRole::ROUTER => 'router',
            DeviceRole::FIREWALL => 'firewall',
            default => 'neighbor',
        };
    }

    /**
     * "2h 5m" for a duration in seconds.
     */
    private static function span(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = array_filter([$days ? "{$days}d" : '', $hours ? "{$hours}h" : '', $minutes && ! $days ? "{$minutes}m" : '']);

        return $parts ? implode(' ', $parts) : '0m';
    }
}
