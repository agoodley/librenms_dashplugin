<?php

namespace Agoodley\LibrenmsTopology\History;

use Agoodley\LibrenmsTopology\Support\Cidr;
use Agoodley\LibrenmsTopology\Support\DeviceRole;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Topology\L2Builder;

/**
 * Today's L2 or L3 map with what changed since a time, or since the saved baseline: marks on the links,
 * sessions and devices that are new or changed, and "ghosts" (drawn dashed) for the links and sessions that
 * are gone, with a ghost far end when it is not on the map. The facts come as two sets: those present at the
 * start that changed or went (before) and those present now that are new or changed (after), as the Changes
 * page reads them (HistoryService::window()) or as the baseline holds them; a fact in both is no change.
 * Root bridges compare per switch and VLAN (Baseline::rootChanges()), blocked ports per port and VLAN.
 *
 * Names come from FactText, so a device the user may not see is named as on the Changes page, and a ghost
 * node never carries the id of such a device.
 */
class ChangeMarks
{
    /** Marks: what each says on the map's legend. */
    public const TYPES = [
        'device_added' => 'Added to LibreNMS',
        'link_new' => 'New link',
        'link_gone' => 'Link gone',
        'member_added' => 'Port-channel member joined',
        'member_left' => 'Port-channel member left',
        'root_new' => 'New root bridge',
        'root_old' => 'No longer the root bridge',
        'blocking_started' => 'Port started blocking',
        'blocking_stopped' => 'Port stopped blocking',
        'ospf_up' => 'OSPF adjacency up',
        'ospf_lost' => 'OSPF adjacency lost',
        'bgp_up' => 'BGP session established',
        'bgp_lost' => 'BGP session down',
    ];

    /** @var array<string, array> node id => node of today's map */
    private array $nodes = [];

    /** @var array<int, array<int, int>> port_id => indexes of the edges with that port (members and aggregates) */
    private array $portEdges = [];

    /** @var array<string, int> "kind|node id|address" => index of an OSPF / BGP edge */
    private array $sessionEdges = [];

    /** @var array<int, array> fact row id => the ChangeReport event it is part of */
    private array $eventOf = [];

    /** @var array<int, array<int, array>> edge index => marks */
    private array $edgeMarks = [];

    /** @var array<string, array<int, array>> node id => marks */
    private array $nodeMarks = [];

    /** @var array<string, array> ghost node id => node */
    private array $ghostNodes = [];

    /** @var array<string, array> "from|to|kind" => ghost edge */
    private array $ghostEdges = [];

    /** Changes whose links, sessions or devices are not on this map. */
    private int $unplaced = 0;

    /**
     * @param  array<int, array>  $events  ChangeReport events with the ids of their rows (facts)
     */
    private function __construct(private readonly array $result, private readonly FactText $text, array $events)
    {
        foreach ($result['nodes'] as $node) {
            $this->nodes[$node['id']] = $node;
        }
        foreach ($result['edges'] as $index => $edge) {
            if (! empty($edge['group'])) {
                continue; // end devices drawn as one node per switch and kind
            }
            $ports = [$edge['a']['port_id'] ?? null, $edge['b']['port_id'] ?? null];
            foreach ($edge['members'] ?? [] as $member) {
                array_push($ports, $member['a_port_id'] ?? null, $member['b_port_id'] ?? null);
            }
            foreach (array_unique(array_filter($ports)) as $port) {
                $this->portEdges[(int) $port][] = $index;
            }
            if (in_array($edge['kind'] ?? null, [FactCollector::OSPF, FactCollector::BGP], true)) {
                foreach ([$edge['from'], $edge['to']] as $node) {
                    foreach (array_merge($edge['addresses'] ?? [], $edge['peers'] ?? []) as $address) {
                        $this->sessionEdges[$edge['kind'] . '|' . $node . '|' . strtolower((string) $address)] ??= $index;
                    }
                }
            }
        }
        foreach ($events as $event) {
            foreach ($event['facts'] ?? [] as $id) {
                $this->eventOf[(int) $id] ??= $event;
            }
        }
    }

    /**
     * The map with `changes` (marks: type, time, text) on its nodes and edges that changed, and the summary in
     * changes: ghosts (nodes and edges to draw dashed), counts per type of mark and how many changes are not on
     * this map (unplaced).
     *
     * @param  string  $mode  l2 or l3
     * @param  array<int, array>  $before  stored facts present at the start that changed or went (time: removed_at)
     * @param  array<int, array>  $after  stored facts present now that are new or changed (time: first_seen)
     * @param  array<int, array>  $events  ChangeReport events with their facts (none when comparing with the baseline)
     * @param  array<string, int>  $goneAt  hash => when a fact of $before went, when its removed_at is not that (the baseline)
     */
    public static function apply(string $mode, array $result, array $before, array $after, FactText $text, array $events = [], array $goneAt = []): array
    {
        $marks = new self($result, $text, $events);
        $beforeByHash = self::byHash($before);
        $afterByHash = self::byHash($after);
        $gone = array_diff_key($beforeByHash, $afterByHash);
        $new = array_diff_key($afterByHash, $beforeByHash);
        $time = fn (array $row, bool $isGone) => $isGone ? ($goneAt[$row['hash']] ?? $row['removed_at'] ?? null) : $row['first_seen'];

        if ($mode === 'l2') {
            foreach ($new as $row) {
                match ($row['kind']) {
                    FactCollector::LINK => $marks->newLink($row, $time($row, false)),
                    FactCollector::LAG => $marks->member($row, 'member_added', $time($row, false)),
                    default => null,
                };
            }
            foreach ($gone as $row) {
                match ($row['kind']) {
                    FactCollector::LINK => $marks->goneLink($row, $time($row, true)),
                    FactCollector::LAG => $marks->member($row, 'member_left', $time($row, true)),
                    default => null,
                };
            }
            $marks->roots(self::ofKind($beforeByHash, FactCollector::STP_ROOT), self::ofKind($afterByHash, FactCollector::STP_ROOT), $goneAt);
            $marks->blocking(self::ofKind($before, FactCollector::STP_BLOCKED), self::ofKind($after, FactCollector::STP_BLOCKED), $goneAt);
        } else {
            foreach ($new as $row) {
                if (in_array($row['kind'], [FactCollector::OSPF, FactCollector::BGP], true)) {
                    $marks->newSession($row, $time($row, false));
                }
            }
            foreach ($gone as $row) {
                if (in_array($row['kind'], [FactCollector::OSPF, FactCollector::BGP], true)) {
                    $marks->goneSession($row, $time($row, true));
                }
            }
        }
        foreach ($events as $event) {
            if ($event['type'] === 'device_added') {
                foreach ($event['devices'] as $device) {
                    $marks->markNode('d:' . $device, 'device_added', $event['time'], $event['text']);
                }
            }
        }

        return $marks->result();
    }

    /**
     * A link that is there now and was not: its edge on the map.
     */
    private function newLink(array $row, ?int $time): void
    {
        $index = $this->linkEdge($row);
        if ($index === null) {
            $this->unplaced++;

            return;
        }
        $this->markEdge($index, 'link_new', $time, $this->eventText($row) ?? 'New link ' . $this->text->describe(self::fact($row)));
    }

    /**
     * A link that was there and is not: a ghost edge between its ends that are on the map, or to a ghost node.
     */
    private function goneLink(array $row, ?int $time): void
    {
        $ends = [$row['data']['a'] ?? [], $row['data']['b'] ?? []];
        $here = null;
        foreach ($ends as $index => $end) {
            if ($here === null && isset($end['device']) && $this->text->visible((int) $end['device']) && isset($this->nodes['d:' . $end['device']])) {
                $here = $index;
            }
        }
        if ($here === null) {
            $this->unplaced++;

            return;
        }
        $near = $ends[$here];
        $far = $ends[1 - $here];
        $from = 'd:' . $near['device'];
        $to = match (true) {
            isset($far['device']) && $this->text->visible((int) $far['device']) && isset($this->nodes['d:' . $far['device']]) => 'd:' . $far['device'],
            ! isset($far['device']) && isset($far['node']) && isset($this->nodes[$far['node']]) => (string) $far['node'],
            default => $this->ghostNode($this->farEndKey($row, 1 - $here, $far), $far),
        };
        $this->ghostEdge($from, $to, 'link', $near, $far, 'link_gone', $time, $this->eventText($row) ?? 'Link gone: ' . $this->text->describe(self::fact($row)));
    }

    /**
     * A port-channel member that joined or left: the edge of its cable, else of its port-channel.
     */
    private function member(array $row, string $type, ?int $time): void
    {
        $data = $row['data'];
        $index = $this->portEdges[(int) ($data['member'] ?? 0)][0] ?? $this->portEdges[(int) ($data['aggregate'] ?? 0)][0] ?? null;
        if ($index === null || ! $this->text->visible((int) ($data['device'] ?? 0))) {
            $this->unplaced++;

            return;
        }
        $this->markEdge($index, $type, $time, $this->eventText($row) ?? $this->text->device((int) $data['device'], $data['device_name'] ?? null) . ' '
            . ($data['aggregate_name'] ?? '') . ': ' . ($data['member_name'] ?? '') . ($type === 'member_added' ? ' joined' : ' left'));
    }

    /**
     * Root bridges per switch and VLAN before and after (Baseline::rootChanges()): where the root bridge of instances
     * moved, the new root bridge and the old one get a mark for those VLANs, when they are on the map. An instance a
     * switch started or stopped reporting moved no root bridge.
     *
     * @param  array<string, array>  $before  hash => root bridge fact present at the start
     * @param  array<string, array>  $after  hash => root bridge fact present now
     * @param  array<string, int>  $goneAt
     */
    private function roots(array $before, array $after, array $goneAt): void
    {
        if (! $before && ! $after) {
            return;
        }
        // when each switch's root bridges changed: its latest new or ended fact
        $changed = [];
        foreach ($after as $row) {
            $changed[(int) $row['data']['device']] = max($changed[(int) $row['data']['device']] ?? 0, (int) $row['first_seen']);
        }
        foreach ($before as $hash => $row) {
            if (! isset($after[$hash])) {
                $changed[(int) $row['data']['device']] = max($changed[(int) $row['data']['device']] ?? 0, (int) ($goneAt[$hash] ?? $row['removed_at'] ?? 0));
            }
        }
        foreach (Baseline::rootChanges($before, $after) as $change) {
            $devices = array_values(array_filter($change['devices'], fn ($id) => $this->text->visible($id)));
            if (! $devices || $change['from'] === null || $change['to'] === null) {
                continue;
            }
            $time = max(array_map(fn ($id) => $changed[$id] ?? 0, $devices)) ?: null;
            $text = $this->text->rootChange(['devices' => $devices] + $change);
            $placed = false;
            foreach (['to' => 'root_new', 'from' => 'root_old'] as $side => $type) {
                $root = $change[$side]['root_device'] ?? null;
                if ($root !== null && $this->text->visible((int) $root) && isset($this->nodes['d:' . $root])) {
                    $placed = $this->markNode('d:' . $root, $type, $time, $text) || $placed;
                }
            }
            if (! $placed) {
                $this->unplaced++;
            }
        }
    }

    /**
     * Blocked ports per port and VLAN before and after: the edge of a port that started or stopped blocking.
     *
     * @param  array<int, array>  $before  blocked port facts present at the start
     * @param  array<int, array>  $after  blocked port facts present now
     * @param  array<string, int>  $goneAt
     */
    private function blocking(array $before, array $after, array $goneAt): void
    {
        $ports = [];
        foreach (['before' => $before, 'after' => $after] as $side => $rows) {
            foreach ($rows as $row) {
                $data = $row['data'];
                $key = (int) $data['device'] . ':' . (int) $data['port'];
                $ports[$key]['data'] = $data;
                $ports[$key][$side] = array_merge($ports[$key][$side] ?? [], FactText::vlans($data));
                $ports[$key]['time'] = max($ports[$key]['time'] ?? 0, (int) ($side === 'after' ? $row['first_seen'] : ($goneAt[$row['hash']] ?? $row['removed_at'] ?? 0)));
                $ports[$key]['rows'][] = $row;
            }
        }
        foreach ($ports as $port) {
            $data = $port['data'];
            $started = array_values(array_diff($port['after'] ?? [], $port['before'] ?? []));
            $stopped = array_values(array_diff($port['before'] ?? [], $port['after'] ?? []));
            if (! $started && ! $stopped) {
                continue;
            }
            $index = $this->portEdges[(int) $data['port']][0] ?? null;
            if ($index === null || ! $this->text->visible((int) $data['device'])) {
                $this->unplaced++;
                continue;
            }
            $name = $this->text->device((int) $data['device'], $data['device_name'] ?? null) . ' ' . ($data['port_name'] ?? '');
            foreach (['blocking_started' => $started, 'blocking_stopped' => $stopped] as $type => $vlans) {
                if ($vlans) {
                    $this->markEdge($index, $type, $port['time'] ?: null, $name . ($type === 'blocking_started' ? ' started blocking in ' : ' stopped blocking in ')
                        . FactText::vlanList($vlans));
                }
            }
        }
    }

    /**
     * An OSPF adjacency or BGP session that is there now and was not: its edge on the map.
     */
    private function newSession(array $row, ?int $time): void
    {
        $data = $row['data'];
        $address = strtolower((string) ($row['kind'] === FactCollector::OSPF ? ($data['ip'] ?? '') : ($data['peer'] ?? '')));
        $index = null;
        foreach (array_filter([$data['device'] ?? null, $data['neighbor'] ?? null]) as $device) {
            $index ??= $this->sessionEdges[$row['kind'] . '|d:' . $device . '|' . $address] ?? null;
        }
        if ($index === null || ! $this->text->visible((int) $data['device'])) {
            $this->unplaced++;

            return;
        }
        $type = $row['kind'] === FactCollector::OSPF ? 'ospf_up' : 'bgp_up';
        $this->markEdge($index, $type, $time, $this->eventText($row) ?? self::TYPES[$type] . ': ' . $this->sessionText($row));
    }

    /**
     * An OSPF adjacency or BGP session that was there and is not: a ghost edge from the router that reported it to
     * its neighbor, the neighbour's external node or AS cloud on the map, or a ghost node.
     */
    private function goneSession(array $row, ?int $time): void
    {
        $data = $row['data'];
        $device = (int) ($data['device'] ?? 0);
        $neighbor = isset($data['neighbor']) && $this->text->visible((int) $data['neighbor']) && isset($this->nodes['d:' . $data['neighbor']]) ? (int) $data['neighbor'] : null;
        if (! $this->text->visible($device) || ! isset($this->nodes['d:' . $device])) {
            $this->unplaced++;

            return;
        }
        $context = (string) ($data['context'] ?? '');
        if ($row['kind'] === FactCollector::OSPF) {
            $key = (string) ($data['router_id'] ?? '') !== '' ? (string) $data['router_id'] : (string) ($data['ip'] ?? '');
            $external = 'x:' . ($context !== '' ? $context . '|' : '') . (Cidr::canonical($key) ?? $key);
            $far = ['name' => 'router ' . $key, 'role' => DeviceRole::ROUTER];
            $type = 'ospf_lost';
        } else {
            $external = 'as:' . (((int) ($data['remote_as'] ?? 0)) ?: (string) ($data['peer'] ?? ''));
            $far = ['name' => ((int) ($data['remote_as'] ?? 0)) ? 'AS' . (int) $data['remote_as'] : 'BGP peer ' . ($data['peer'] ?? ''), 'role' => 'cloud'];
            $type = 'bgp_lost';
        }
        $to = match (true) {
            $neighbor !== null => 'd:' . $neighbor,
            isset($this->nodes[$external]) => $external,
            default => $this->ghostNode($row['kind'] . ':' . $external, $far),
        };
        $this->ghostEdge('d:' . $device, $to, $row['kind'], null, null, $type, $time, $this->eventText($row) ?? self::TYPES[$type] . ': ' . $this->sessionText($row));
    }

    /**
     * The map's edge of a link fact: one with the port of an end the user may see, joined to the far end (the
     * device, else a neighbour that is no device on the map).
     */
    private function linkEdge(array $row): ?int
    {
        $ends = [$row['data']['a'] ?? [], $row['data']['b'] ?? []];
        foreach ($ends as $side => $end) {
            if (! isset($end['device'], $end['port']) || ! $this->text->visible((int) $end['device'])) {
                continue;
            }
            $far = $ends[1 - $side];
            $farNode = isset($far['device']) && $this->text->visible((int) $far['device']) ? 'd:' . $far['device'] : null;
            foreach ($this->portEdges[(int) $end['port']] ?? [] as $index) {
                $edge = $this->result['edges'][$index];
                $other = $edge['from'] === 'd:' . $end['device'] ? $edge['to'] : $edge['from'];
                if ($farNode !== null ? $other === $farNode : ! str_starts_with($other, 'd:')) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Which ghost node the far end of a gone link is: one per device the user may see, or per name the neighbour
     * reported (the links of a switch removed from LibreNMS all end at one ghost, as the L2 map joins an unmanaged
     * neighbour's links), and one per link when the far end has no such name (no name, a generic one, or a device
     * the user may not see that only the masked name stands for).
     */
    private function farEndKey(array $row, int $side, array $far): string
    {
        if (isset($far['device']) && $this->text->visible((int) $far['device'])) {
            return 'device:' . (int) $far['device'];
        }
        $host = strtolower(trim((string) (isset($far['device']) ? ($far['host'] ?? '') : ($far['name'] ?? ''))));
        if ($host === '' || $host === 'unknown neighbor' || in_array($host, L2Builder::GENERIC_HOSTNAMES, true)
            || in_array(strtolower(L2Builder::shortHost($host)), L2Builder::GENERIC_HOSTNAMES, true)) {
            return $row['hash'] . ':' . $side;
        }

        return 'host:' . $host;
    }

    /**
     * Ghost node of a gone far end, named as the Changes page names it.
     */
    private function ghostNode(string $key, array $end): string
    {
        $id = 'ghost:' . substr(sha1($key), 0, 16);
        if (isset($end['device'])) {
            $visible = $this->text->visible((int) $end['device']);
            $name = $this->text->device((int) $end['device'], $end['name'] ?? null, $end['host'] ?? PastMap::MASKED);
            $role = DeviceRole::UNKNOWN;
        } else {
            $visible = false;
            $name = (string) ($end['name'] ?? 'unknown neighbor');
            $role = (string) ($end['role'] ?? DeviceRole::UNKNOWN);
        }
        $this->ghostNodes[$id] ??= [
            'id' => $id,
            'type' => 'ghost',
            'managed' => false,
            'device_id' => $visible ? (int) $end['device'] : null,
            'label' => L2Builder::shortHost($name),
            'name' => $name,
            'role' => $role,
            'status' => 'unknown',
            'ghost' => true,
        ];

        return $id;
    }

    /**
     * Ghost edge between two nodes (one per pair and kind: both routers' reports of a session are one), with the
     * port names of a link's ends.
     */
    private function ghostEdge(string $from, string $to, string $kind, ?array $near, ?array $far, string $type, ?int $time, string $text): void
    {
        // a session's pair in either order (each router reported it), a link's by its port
        $key = (strcmp($from, $to) < 0 ? "$from|$to" : "$to|$from") . '|' . $kind . '|' . ($near !== null ? $from . ':' . ($near['port_name'] ?? '') : '');
        $end = fn (?array $data) => $data === null ? null : ['name' => (string) ($data['port_name'] ?? ''), 'short' => InterfaceName::short((string) ($data['port_name'] ?? ''))];
        $this->ghostEdges[$key] ??= ['id' => 'ghost:e' . count($this->ghostEdges), 'kind' => 'ghost', 'ghost_kind' => $kind, 'from' => $from, 'to' => $to,
            'a' => $end($near), 'b' => $end($far), 'status' => 'gone', 'changes' => []];
        self::addMark($this->ghostEdges[$key]['changes'], $type, $time, $text);
    }

    private function markEdge(int $index, string $type, ?int $time, string $text): void
    {
        $this->edgeMarks[$index] ??= [];
        self::addMark($this->edgeMarks[$index], $type, $time, $text);
    }

    /**
     * Mark a node of the map; false when it is not on it.
     */
    private function markNode(string $id, string $type, ?int $time, string $text): bool
    {
        if (! isset($this->nodes[$id])) {
            return false;
        }
        $this->nodeMarks[$id] ??= [];
        self::addMark($this->nodeMarks[$id], $type, $time, $text);

        return true;
    }

    /**
     * Add a mark once (both halves of a moved cable, both routers of a session tell the same).
     *
     * @param  array<int, array>  $marks
     */
    private static function addMark(array &$marks, string $type, ?int $time, string $text): void
    {
        foreach ($marks as $mark) {
            if ($mark['type'] === $type && $mark['text'] === $text) {
                return;
            }
        }
        $marks[] = ['type' => $type, 'time' => $time, 'text' => $text];
    }

    /**
     * The map with the marks on its nodes and edges and the summary.
     */
    private function result(): array
    {
        $result = $this->result;
        $counts = array_fill_keys(array_keys(self::TYPES), 0);
        foreach ($result['nodes'] as &$node) {
            foreach ($this->nodeMarks[$node['id']] ?? [] as $mark) {
                $node['changes'][] = $mark;
                $counts[$mark['type']]++;
            }
        }
        unset($node);
        foreach ($result['edges'] as $index => &$edge) {
            foreach ($this->edgeMarks[$index] ?? [] as $mark) {
                $edge['changes'][] = $mark;
                $counts[$mark['type']]++;
            }
        }
        unset($edge);
        foreach ($this->ghostEdges as $edge) {
            foreach ($edge['changes'] as $mark) {
                $counts[$mark['type']]++;
            }
        }
        $result['changes'] = [
            'ghosts' => ['nodes' => array_values($this->ghostNodes), 'edges' => array_values($this->ghostEdges)],
            'counts' => array_filter($counts),
            'unplaced' => $this->unplaced,
        ];

        return $result;
    }

    /**
     * Wording of the ChangeReport event a fact is part of (a moved cable says where it went), null without one.
     */
    private function eventText(array $row): ?string
    {
        return isset($row['id']) ? ($this->eventOf[(int) $row['id']]['text'] ?? null) : null;
    }

    private function sessionText(array $row): string
    {
        return $row['kind'] === FactCollector::OSPF ? $this->text->ospf($row['data']) : $this->text->bgp($row['data']);
    }

    /**
     * Stored row as the fact FactText describes.
     */
    private static function fact(array $row): array
    {
        return ['kind' => $row['kind'], 'key' => $row['fact_key'] ?? $row['key'] ?? '', 'data' => $row['data']];
    }

    /**
     * @param  array<int, array>  $rows
     * @return array<string, array> hash => row (the latest period of a fact)
     */
    private static function byHash(array $rows): array
    {
        $byHash = [];
        foreach ($rows as $row) {
            $byHash[$row['hash']] = $row;
        }

        return $byHash;
    }

    /**
     * @param  array<array-key, array>  $rows
     * @return array<array-key, array>
     */
    private static function ofKind(array $rows, string $kind): array
    {
        return array_filter($rows, fn ($row) => $row['kind'] === $kind);
    }
}
