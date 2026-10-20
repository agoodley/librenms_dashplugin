<?php

namespace Agoodley\LibrenmsTopology\History;

use Agoodley\LibrenmsTopology\Stp\StpIndex;
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
    /** Unmanaged neighbor roles worth reporting when they show up. */
    private const NETWORK_ROLES = [DeviceRole::SWITCH, DeviceRole::L3SWITCH, DeviceRole::ROUTER, DeviceRole::FIREWALL, DeviceRole::WIRELESS];

    /** Events of one change: what it is about, how much attention it needs. */
    public const TYPES = [
        'device_added' => ['devices', 'info'],
        'cable_moved' => ['links', 'warning'],
        'link_removed' => ['links', 'warning'],
        'link_back' => ['links', 'info'],
        'link_added' => ['links', 'info'],
        'unmanaged_new' => ['links', 'warning'],
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

        $added = $report->newDevices($added);
        $report->links(self::ofKind($added, FactCollector::LINK), self::ofKind($removed, FactCollector::LINK));
        $report->lagMembers(self::ofKind($added, FactCollector::LAG), 'lag_member_added');
        $report->lagMembers(self::ofKind($removed, FactCollector::LAG), 'lag_member_removed');
        $report->roots(self::ofKind($added, FactCollector::STP_ROOT), self::ofKind($removed, FactCollector::STP_ROOT));
        $report->blocking(self::ofKind($added, FactCollector::STP_BLOCKED), 'blocking_started');
        $report->blocking(self::ofKind($removed, FactCollector::STP_BLOCKED), 'blocking_stopped');
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
            $counts = array_count_values(array_column($rows, 'kind'));
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
     * Links: a port whose neighbor changed is a moved cable (or a link that came back), the rest
     * are new or gone links; a new unmanaged switch or access point is called out.
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
            if ($node !== null && ! isset($this->priorNodes[$node]) && in_array($row['data']['b']['role'] ?? null, self::NETWORK_ROLES, true)) {
                $newNodes[$node][] = $row;
                continue;
            }
            $this->add($row['first_seen'], 'link_added', 'New link ' . $this->text->describe($this->fact($row)), self::devices($row));
        }
        foreach ($newNodes as $rows) {
            $b = $rows[0]['data']['b'];
            $where = array_map(fn ($row) => $this->text->linkEnd($row['data']['a']), $rows);
            $this->add(min(array_column($rows, 'first_seen')), 'unmanaged_new', 'New unmanaged ' . self::role($b['role']) . ' ' . ($b['name'] ?? 'neighbor')
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
     * Root bridge changes: one event per instance, old and new root, with the switches that saw it.
     */
    private function roots(array $added, array $removed): void
    {
        $removedBy = [];
        foreach ($removed as $index => $row) {
            $removedBy[$row['data']['device'] . '|' . $row['data']['vlan']][] = $index;
        }

        $changes = [];
        $used = [];
        foreach ($added as $row) {
            $key = $row['data']['device'] . '|' . $row['data']['vlan'];
            $old = null;
            foreach ($removedBy[$key] ?? [] as $index) {
                if (! isset($used[$index]) && $removed[$index]['removed_at'] <= $row['first_seen']) {
                    $used[$index] = true;
                    $old = $removed[$index];
                    break;
                }
            }
            $type = $old ? 'root_changed' : 'root_new';
            $changes[$type . '|' . $row['first_seen'] . '|' . $row['data']['vlan'] . '|' . ($old['data']['root'] ?? '') . '|' . $row['data']['root']][] = [$row, $old];
        }
        foreach ($removed as $index => $row) {
            if (! isset($used[$index])) {
                $changes['root_gone|' . $row['removed_at'] . '|' . $row['data']['vlan'] . '|' . $row['data']['root'] . '|'][] = [$row, null];
            }
        }

        foreach ($changes as $group => $pairs) {
            $type = explode('|', $group)[0];
            [$row, $old] = $pairs[0];
            $data = $row['data'];
            $devices = array_values(array_unique(array_map(fn ($pair) => (int) $pair[0]['data']['device'], $pairs)));
            $seen = $this->deviceList($devices);
            $instance = StpIndex::vlanLabel((int) $data['vlan']);
            $text = match ($type) {
                'root_changed' => "$instance: root bridge changed from " . $this->text->root($old['data']) . ' to ' . $this->text->root($data) . " (seen by $seen)",
                'root_new' => "$instance: root bridge " . $this->text->root($data) . " (new instance on $seen)",
                default => "$instance: no longer reported by $seen (root bridge was " . $this->text->root($data) . ')',
            };
            $this->add($type === 'root_gone' ? $row['removed_at'] : $row['first_seen'], $type, $text, $devices);
        }
    }

    /**
     * Ports that started or stopped blocking, one event per port and snapshot with its instances.
     */
    private function blocking(array $rows, string $type): void
    {
        $groups = [];
        foreach ($rows as $row) {
            $time = $type === 'blocking_started' ? $row['first_seen'] : $row['removed_at'];
            $groups[$time . '|' . $row['data']['device'] . '|' . $row['data']['port_name']][] = $row;
        }
        foreach ($groups as $group) {
            $data = $group[0]['data'];
            $vlans = array_map(fn ($row) => StpIndex::vlanLabel((int) $row['data']['vlan']), $group);
            sort($vlans, SORT_NATURAL);
            $this->add($type === 'blocking_started' ? $group[0]['first_seen'] : $group[0]['removed_at'], $type,
                $this->text->device($data['device'], $data['device_name']) . ' ' . $data['port_name']
                . ($type === 'blocking_started' ? ' started blocking in ' : ' stopped blocking in ') . implode(', ', $vlans),
                [(int) $data['device']]);
        }
    }

    /**
     * OSPF adjacencies and BGP sessions; when both routers are monitored their two reports of the
     * same adjacency become one event.
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
            $row = $group[0];
            $describe = str_starts_with($type, 'ospf') ? $this->text->ospf($row['data']) : $this->text->bgp($row['data']);
            $both = count($group) > 1 ? ' (reported by both routers)' : '';
            $this->add(str_ends_with($type, '_up') ? $row['first_seen'] : $row['removed_at'], $type,
                $words[$type][0] . $describe . $words[$type][1] . $both, array_values(array_unique(array_merge(...array_map(fn ($row) => self::devices($row), $group)))));
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
     * "core1, core2 and 7 more" for the devices the user may see (others are counted).
     *
     * @param  int[]  $devices
     */
    private function deviceList(array $devices): string
    {
        $names = [];
        $hidden = 0;
        foreach ($devices as $device) {
            if ($this->text->visible($device)) {
                $names[] = $this->text->device($device, null);
            } else {
                $hidden++;
            }
        }
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $more = count($names) > 5 ? count($names) - 5 : 0;
        $list = implode(', ', array_slice($names, 0, 5));
        $extra = $more + $hidden;
        if ($extra) {
            $list .= ($list !== '' ? ' and ' : '') . $extra . ($list !== '' ? ' more' : ' devices');
        }

        return $list;
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
            DeviceRole::WIRELESS => 'access point',
            DeviceRole::ROUTER => 'router',
            DeviceRole::FIREWALL => 'firewall',
            default => 'switch',
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

        return trim(($days ? "{$days}d " : '') . ($hours ? "{$hours}h " : '') . ($days ? '' : "{$minutes}m"));
    }
}
