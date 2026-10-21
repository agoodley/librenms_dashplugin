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
 * page reads them (HistoryService::window()) or as the baseline holds them; a fact in both is no change. Since
 * a time, only the facts of the Changes page's events count, each with all its periods (a fact's data can change
 * without a change of the network, a switch renamed or a port re-created: a period no event names), and the cable
 * to a neighbour LibreNMS started or stopped monitoring is one fact, as the Changes page takes it (the link to the
 * unmanaged neighbour, then the link between the two devices): what the Changes page does not report is not marked
 * either, and a mark tells the event that brought about the state now (a link gone: the last time it went). Root
 * bridges compare per switch and VLAN (Baseline::rootChanges()), blocked ports per port (its device and name) and
 * VLAN; on a map of one VLAN only the spanning tree instance it shows counts.
 *
 * Names come from FactText, so a device the user may not see is named as on the Changes page; a ghost node's id
 * is a number of this answer, which tells nothing about the device behind it.
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

    /** @var array<string, int> "device|port name" (InterfaceName::normalize()) => port_id of today's map */
    private array $portByName = [];

    /** @var array<string, int> "kind|node id|address" => index of an OSPF / BGP edge */
    private array $sessionEdges = [];

    /** @var array<string, string> fact hash => its identity, for the facts of a cable whose neighbour LibreNMS started or stopped monitoring */
    private readonly array $identity;

    /** @var array<string, array{at: int, event: array}> identity => the ChangeReport event that started its latest period with one (at: that start) */
    private array $started = [];

    /** @var array<string, array{at: int, event: array}> identity => the ChangeReport event that ended its latest period with one (at: that end) */
    private array $ended = [];

    /** @var array<string, array> identity => its row that ended last: a fact that is gone, as it last was */
    private array $lastEnded = [];

    /** @var array<int, array<string, int>> switch => root bridge (MAC) => when the Changes page last saw a root move to it */
    private array $rootMoves = [];

    /** @var array<string, array<string, array<int, int>>> "device|port name" => started|stopped => VLAN => when the Changes page saw it */
    private array $blockingTimes = [];

    /** @var array<int, array<int, array>> edge index => marks */
    private array $edgeMarks = [];

    /** @var array<string, array<int, array>> node id => marks */
    private array $nodeMarks = [];

    /** @var array<string, string> what a ghost node stands for => its id in this answer */
    private array $ghostIds = [];

    /** @var array<string, array> ghost node id => node */
    private array $ghostNodes = [];

    /** @var array<string, array> "from|to|kind" => ghost edge */
    private array $ghostEdges = [];

    /** Changes whose links, sessions or devices are not on this map. */
    private int $unplaced = 0;

    /** The map's VLAN: only the spanning tree instance it shows is marked; null: all, with their VLANs. */
    private readonly ?int $vlan;

    /** Does the map draw unmanaged neighbours (and the devices the user may not see, drawn as such)? */
    private readonly bool $unmanaged;

    /** @var array<int, true>|null every device LibreNMS has, for a user who may see all of them (null otherwise) */
    private readonly ?array $existing;

    /**
     * @param  array<int, array>  $events  ChangeReport events with the ids of their rows (facts)
     * @param  array<int, array>  $rows  the stored rows the events come from
     * @param  array<string, string>  $identity  hash => identity (identities())
     * @param  array{vlan?: ?int, unmanaged?: bool, existing?: ?array}  $options
     */
    private function __construct(private readonly array $result, private readonly FactText $text, array $events, array $rows, array $identity, array $options)
    {
        $this->identity = $identity;
        $this->vlan = $options['vlan'] ?? null;
        $this->unmanaged = $options['unmanaged'] ?? true;
        $this->existing = $options['existing'] ?? null;
        foreach ($result['nodes'] as $node) {
            $this->nodes[$node['id']] = $node;
        }
        foreach ($result['edges'] as $index => $edge) {
            if (! empty($edge['group'])) {
                continue; // end devices drawn as one node per switch and kind
            }
            $ports = [$edge['a']['port_id'] ?? null, $edge['b']['port_id'] ?? null];
            foreach (['a', 'b'] as $side) {
                if (isset($edge[$side]['device_id'], $edge[$side]['port_id'], $edge[$side]['name'])) {
                    $this->portByName[$edge[$side]['device_id'] . '|' . InterfaceName::normalize((string) $edge[$side]['name'])] ??= (int) $edge[$side]['port_id'];
                }
            }
            foreach ($edge['members'] ?? [] as $member) {
                array_push($ports, $member['a_port_id'] ?? null, $member['b_port_id'] ?? null);
                foreach (['a', 'b'] as $side) {
                    if (isset($edge[$side]['device_id'], $member[$side . '_port_id'], $member[$side])) {
                        $this->portByName[$edge[$side]['device_id'] . '|' . InterfaceName::normalize((string) $member[$side])] ??= (int) $member[$side . '_port_id'];
                    }
                }
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
        $this->indexEvents($events, $rows);
    }

    /**
     * What the Changes page's events say about the facts: per fact (its identity, with all its periods: a later one
     * may have no event) the event that started the latest of its periods that an event started and the event that
     * ended the latest that one ended, when a switch's root bridge last moved to each root bridge, and when a port
     * (its device and name) last started or stopped blocking each VLAN. An event at or after the end of a period ended
     * it (a link gone, the old end of a moved cable, the link before a link back after it was gone), else it started
     * it. Also each fact's row that ended last.
     *
     * @param  array<int, array>  $events
     * @param  array<int, array>  $rows
     */
    private function indexEvents(array $events, array $rows): void
    {
        $rowsById = [];
        foreach ($rows as $row) {
            if (isset($row['id'])) {
                $rowsById[(int) $row['id']] = $row;
            }
            $identity = $this->identityOf($row);
            if ($row['removed_at'] !== null && (int) $row['removed_at'] > (int) ($this->lastEnded[$identity]['removed_at'] ?? PHP_INT_MIN)) {
                $this->lastEnded[$identity] = $row;
            }
        }
        foreach ($events as $event) {
            $time = (int) $event['time'];
            $blocked = ['before' => [], 'after' => []];
            foreach ($event['facts'] ?? [] as $id) {
                $row = $rowsById[(int) $id] ?? null;
                if ($row === null) {
                    continue;
                }
                if ($row['removed_at'] !== null && $time >= (int) $row['removed_at']) {
                    self::keepLatest($this->ended, $this->identityOf($row), (int) $row['removed_at'], $event);
                } else {
                    self::keepLatest($this->started, $this->identityOf($row), (int) $row['first_seen'], $event);
                }
                // the root bridge a switch moved to (its fact that started then) and a port's VLANs on either side
                if ($event['type'] === 'root_changed' && $row['kind'] === FactCollector::STP_ROOT && (int) $row['first_seen'] === $time) {
                    $root = (string) ($row['data']['root'] ?? '');
                    $this->rootMoves[(int) $row['data']['device']][$root] = max($this->rootMoves[(int) $row['data']['device']][$root] ?? 0, $time);
                }
                if (str_starts_with($event['type'], 'blocking_') && $row['kind'] === FactCollector::STP_BLOCKED) {
                    $side = (int) $row['first_seen'] === $time ? 'after' : 'before';
                    $blocked[$side][self::portKey($row['data'])] = array_merge($blocked[$side][self::portKey($row['data'])] ?? [], FactText::vlans($row['data']));
                }
            }
            foreach (array_unique(array_merge(array_keys($blocked['before']), array_keys($blocked['after']))) as $port) {
                $was = $blocked['before'][$port] ?? [];
                $now = $blocked['after'][$port] ?? [];
                foreach (['started' => array_diff($now, $was), 'stopped' => array_diff($was, $now)] as $way => $vlans) {
                    foreach ($vlans as $vlan) {
                        $this->blockingTimes[$port][$way][(int) $vlan] = max($this->blockingTimes[$port][$way][(int) $vlan] ?? 0, $time);
                    }
                }
            }
        }
    }

    /**
     * Keep the event of a fact's latest period ($at: when it started or ended), of two events of one period the later.
     *
     * @param  array<string, array{at: int, event: array}>  $index
     */
    private static function keepLatest(array &$index, string $identity, int $at, array $event): void
    {
        if (! isset($index[$identity]) || [$at, (int) $event['time']] > [$index[$identity]['at'], (int) $index[$identity]['event']['time']]) {
            $index[$identity] = ['at' => $at, 'event' => $event];
        }
    }

    /**
     * The Changes page's event a mark of a fact tells, the one that brought about its state now: for a fact that is
     * gone, the one that ended its latest period (a link that went, came back and went again went the last time);
     * for a new one, the one that started its latest period with one (a neighbour LibreNMS monitors by now was new
     * as an unmanaged one); null without one (the baseline).
     */
    private function event(array $row, bool $isGone): ?array
    {
        return ($isGone ? $this->ended : $this->started)[$this->identityOf($row)]['event'] ?? null;
    }

    /**
     * A fact as it last was: its row that ended last (a gone link that flapped, or whose neighbour LibreNMS monitored
     * by then), else the row given.
     */
    private function lastPeriod(array $row): array
    {
        return $this->lastEnded[$this->identityOf($row)] ?? $row;
    }

    private function identityOf(array $row): string
    {
        return $this->identity[$row['hash']] ?? $row['hash'];
    }

    /**
     * A blocked port by its device and name, as the Changes page takes it (LibreNMS may re-create it under a new id).
     */
    private static function portKey(array $data): string
    {
        return (int) ($data['device'] ?? 0) . '|' . InterfaceName::normalize((string) ($data['port_name'] ?? ''));
    }

    /**
     * The map with `changes` (marks: type, time, text, and the VLANs of a spanning tree mark) on its nodes and edges
     * that changed, and the summary in changes: ghosts (nodes and edges to draw dashed), counts per type of mark, how
     * many changes are not on this map (unplaced) and the VLAN the marks are of (null: all).
     *
     * @param  string  $mode  l2 or l3
     * @param  array<int, array>  $before  stored facts present at the start that changed or went (time: removed_at)
     * @param  array<int, array>  $after  stored facts present now that are new or changed (time: first_seen)
     * @param  array{events?: ?array, rows?: array, same_cables?: array, gone_at?: array<string, int>, vlan?: ?int, unmanaged?: bool, existing?: ?array}  $options
     *     events: the Changes page's events with their facts since the time (only these facts are marked, with all their
     *     periods); null when comparing with the baseline. rows: the stored rows the events come from (default: $before
     *     and $after). same_cables: ChangeReport's pairs of rows of a cable whose neighbour LibreNMS started or stopped
     *     monitoring ([removed row id, added row id]): one fact. gone_at: hash => when a fact of $before went, when its
     *     removed_at is not that (the baseline).
     *     vlan: the map's VLAN. unmanaged: the map draws unmanaged neighbours (default true). existing: every device
     *     LibreNMS has, for a user who may see all of them (a gone far end not among them was monitored then: its ghost
     *     is drawn whatever the map's unmanaged neighbours).
     */
    public static function apply(string $mode, array $result, array $before, array $after, FactText $text, array $options = []): array
    {
        $events = $options['events'] ?? null;
        $rows = $options['rows'] ?? array_merge($before, $after);
        $identity = self::identities($rows, $options['same_cables'] ?? []);
        if ($events !== null) {
            $reported = self::reported($events, $rows, $identity);
            $inReport = fn (array $row) => isset($reported[$identity[$row['hash']] ?? $row['hash']]);
            $before = array_values(array_filter($before, $inReport));
            $after = array_values(array_filter($after, $inReport));
        }
        $goneAt = $options['gone_at'] ?? [];
        $marks = new self($result, $text, $events ?? [], $rows, $identity, $options);
        $beforeById = self::byIdentity($before, $identity);
        $afterById = self::byIdentity($after, $identity);
        $gone = array_map(fn (array $row) => $marks->lastPeriod($row), array_diff_key($beforeById, $afterById));
        $new = array_diff_key($afterById, $beforeById);
        // when: as the Changes page saw it, else (the baseline) when the period started or ended
        $time = fn (array $row, bool $isGone) => $marks->event($row, $isGone)['time'] ?? ($isGone ? ($goneAt[$row['hash']] ?? $row['removed_at'] ?? null) : $row['first_seen']);

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
            $marks->roots(self::ofKind($beforeById, FactCollector::STP_ROOT), self::ofKind($afterById, FactCollector::STP_ROOT), $goneAt);
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
        foreach ($events ?? [] as $event) {
            if ($event['type'] === 'device_added') {
                foreach ($event['devices'] as $device) {
                    $marks->markNode('d:' . $device, 'device_added', $event['time'], $event['text']);
                }
            }
        }

        return $marks->result();
    }

    /**
     * The facts (identities) the Changes page's events are about. A fact counts with all its periods: one that started
     * without a change of the network (its switch or the root bridge renamed, its port re-created under a new id) is
     * named by no event, yet it is the fact's state now; so does the link of a cable whose neighbour LibreNMS started
     * or stopped monitoring, the same fact as the link before it.
     *
     * @param  array<int, array>  $events  ChangeReport events with the ids of their rows (facts)
     * @param  array<int, array>  $rows  the stored rows the events come from
     * @param  array<string, string>  $identity  hash => identity (identities())
     * @return array<string, true>
     */
    public static function reported(array $events, array $rows, array $identity = []): array
    {
        $ids = [];
        foreach ($events as $event) {
            foreach ($event['facts'] ?? [] as $id) {
                $ids[(int) $id] = true;
            }
        }
        $reported = [];
        foreach ($rows as $row) {
            if (isset($row['id'], $ids[(int) $row['id']])) {
                $reported[$identity[$row['hash']] ?? $row['hash']] = true;
            }
        }

        return $reported;
    }

    /**
     * Each fact's identity: its hash, but one for the facts of a cable whose neighbour LibreNMS started or stopped
     * monitoring (ChangeReport's same cables: the link to the unmanaged neighbour and the link between the two
     * devices), so that what the Changes page reports of one is of the other.
     *
     * @param  array<int, array>  $rows  the stored rows the pairs come from
     * @param  array<int, array{0: int, 1: int}>  $sameCables  [removed row id, added row id]
     * @return array<string, string> hash => identity, for the facts of these cables
     */
    private static function identities(array $rows, array $sameCables): array
    {
        $hashOf = [];
        foreach ($rows as $row) {
            if (isset($row['id'])) {
                $hashOf[(int) $row['id']] = $row['hash'];
            }
        }
        // a cable seen as a few facts (from either end, unmanaged and monitored, again unmanaged): one tree each
        $parent = [];
        $root = function (string $hash) use (&$parent): string {
            while (isset($parent[$hash])) {
                $hash = $parent[$hash];
            }

            return $hash;
        };
        foreach ($sameCables as [$removed, $added]) {
            if (isset($hashOf[(int) $removed], $hashOf[(int) $added]) && ($from = $root($hashOf[(int) $removed])) !== ($to = $root($hashOf[(int) $added]))) {
                $parent[$to] = $from;
            }
        }
        $identity = [];
        foreach (array_keys($parent) as $hash) {
            $identity[$hash] = $root($hash);
        }

        return $identity;
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
        $this->markEdge($index, 'link_new', $time, $this->eventText($row, false) ?? 'New link ' . $this->text->describe(self::fact($row)));
    }

    /**
     * A link that was there and is not: a ghost edge between its ends that are on the map, or to a ghost node. A far
     * end that was an unmanaged neighbour (or a device the user may not see, which the map draws as one) gets no
     * ghost on a map without unmanaged neighbours; one LibreNMS monitored then does.
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
            default => null,
        };
        if ($to === null) {
            if (! $this->unmanaged && ! $this->monitored($far)) {
                $this->unplaced++;

                return;
            }
            $to = $this->ghostNode($this->farEndKey($row, 1 - $here, $far), $far);
        }
        $this->ghostEdge($from, $to, 'link', $near, $far, 'link_gone', $time, $this->eventText($row, true) ?? 'Link gone: ' . $this->text->describe(self::fact($row)));
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
        $this->markEdge($index, $type, $time, $this->eventText($row, $type === 'member_left') ?? $this->text->device((int) $data['device'], $data['device_name'] ?? null) . ' '
            . ($data['aggregate_name'] ?? '') . ': ' . ($data['member_name'] ?? '') . ($type === 'member_added' ? ' joined' : ' left'));
    }

    /**
     * Root bridges per switch and VLAN before and after (Baseline::rootChanges()): where the root bridge of instances
     * moved, the new root bridge and the old one get a mark for those VLANs, when they are on the map. An instance a
     * switch started or stopped reporting moved no root bridge. On a map of one VLAN, a move counts for the switches
     * whose instance the map shows (shownVlans()).
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
        // when each switch's root bridges changed (the baseline): its latest new or ended fact
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
            if ($change['from'] === null || $change['to'] === null) {
                continue;
            }
            $deviceVlans = [];
            foreach ($change['device_vlans'] as $device => $vlans) {
                if ($this->text->visible($device)) {
                    $deviceVlans[$device] = $this->shownVlans($device, $vlans);
                }
            }
            if (! $deviceVlans) {
                continue;
            }
            $deviceVlans = array_filter($deviceVlans);
            if (! $deviceVlans) {
                $this->unplaced++; // instances this map does not show

                continue;
            }
            $devices = array_keys($deviceVlans);
            $vlans = array_values(array_unique(array_merge(...array_values($deviceVlans))));
            sort($vlans);
            // when: the Changes page's latest move of these switches to the new root bridge, else their facts' times
            $root = (string) ($change['to']['root'] ?? '');
            $time = max(array_map(fn ($id) => $this->rootMoves[$id][$root] ?? 0, $devices)) ?: (max(array_map(fn ($id) => $changed[$id] ?? 0, $devices)) ?: null);
            $text = $this->text->rootChange(['devices' => $devices, 'vlans' => $vlans, 'device_vlans' => $deviceVlans] + $change);
            $placed = false;
            foreach (['to' => 'root_new', 'from' => 'root_old'] as $side => $type) {
                $root = $change[$side]['root_device'] ?? null;
                if ($root !== null && $this->text->visible((int) $root) && isset($this->nodes['d:' . $root])) {
                    $placed = $this->markNode('d:' . $root, $type, $time, $text, $vlans) || $placed;
                }
            }
            if (! $placed) {
                $this->unplaced++;
            }
        }
    }

    /**
     * Blocked ports before and after per port, a port by its device and name as the Changes page takes it (LibreNMS
     * may have re-created it under a new id), and VLAN: the edge of a port that started or stopped blocking.
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
                $key = self::portKey($data);
                $ports[$key]['data'] = $data;
                $ports[$key]['ids'][(int) $data['port']] = true;
                $ports[$key][$side] = array_merge($ports[$key][$side] ?? [], FactText::vlans($data));
                $ports[$key]['time'] = max($ports[$key]['time'] ?? 0, (int) ($side === 'after' ? $row['first_seen'] : ($goneAt[$row['hash']] ?? $row['removed_at'] ?? 0)));
            }
        }
        foreach ($ports as $key => $port) {
            $data = $port['data'];
            $device = (int) $data['device'];
            $started = $this->shownVlans($device, array_values(array_unique(array_diff($port['after'] ?? [], $port['before'] ?? []))));
            $stopped = $this->shownVlans($device, array_values(array_unique(array_diff($port['before'] ?? [], $port['after'] ?? []))));
            if (! $started && ! $stopped) {
                // no change, or none of the instance this map shows
                if (array_diff($port['after'] ?? [], $port['before'] ?? []) || array_diff($port['before'] ?? [], $port['after'] ?? [])) {
                    $this->unplaced++;
                }
                continue;
            }
            $index = null;
            foreach (array_keys($port['ids']) as $id) {
                $index ??= $this->portEdges[$id][0] ?? null;
            }
            $index ??= isset($this->portByName[$key]) ? ($this->portEdges[$this->portByName[$key]][0] ?? null) : null;
            if ($index === null || ! $this->text->visible($device)) {
                $this->unplaced++;
                continue;
            }
            $name = $this->text->device($device, $data['device_name'] ?? null) . ' ' . ($data['port_name'] ?? '');
            foreach (['started' => $started, 'stopped' => $stopped] as $way => $vlans) {
                if ($vlans) {
                    sort($vlans);
                    // when: the Changes page's latest start or stop of these VLANs on the port, else the facts' times (the baseline)
                    $time = max(array_map(fn ($vlan) => $this->blockingTimes[$key][$way][$vlan] ?? 0, $vlans)) ?: ($port['time'] ?: null);
                    $this->markEdge($index, 'blocking_' . $way, $time, $name . ' ' . $way . ' blocking in ' . FactText::vlanList($vlans), $vlans);
                }
            }
        }
    }

    /**
     * The instances (VLAN keys) among $vlans that a mark of $device is about on this map: with a VLAN chosen, only the
     * instance the map shows for the device (its node's spanning tree), else all of them (the mark names them).
     *
     * @param  int[]  $vlans
     * @return int[]
     */
    private function shownVlans(int $device, array $vlans): array
    {
        if ($this->vlan === null) {
            return array_values($vlans);
        }
        $shown = $this->nodes['d:' . $device]['stp']['vlan'] ?? null;

        return $shown !== null && in_array((int) $shown, array_map('intval', $vlans), true) ? [(int) $shown] : [];
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
        $this->markEdge($index, $type, $time, $this->eventText($row, false) ?? self::TYPES[$type] . ': ' . $this->sessionText($row));
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
        $this->ghostEdge('d:' . $device, $to, $row['kind'], null, null, $type, $time, $this->eventText($row, true) ?? self::TYPES[$type] . ': ' . $this->sessionText($row));
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
     * Was a link's far end a device LibreNMS monitored: one the user may see, or one LibreNMS no longer has (known
     * to a user who may see every device)? The others are drawn as unmanaged neighbours.
     */
    private function monitored(array $far): bool
    {
        if (! isset($far['device'])) {
            return false;
        }
        $device = (int) $far['device'];

        return $this->text->visible($device) || ($this->existing !== null && ! isset($this->existing[$device]));
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
     * Ghost node of a gone far end, named as the Changes page names it. Its id is the next number of this answer.
     */
    private function ghostNode(string $key, array $end): string
    {
        $id = $this->ghostIds[$key] ??= 'ghost:' . (count($this->ghostIds) + 1);
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

    /**
     * @param  int[]  $vlans  the instances a spanning tree mark is about
     */
    private function markEdge(int $index, string $type, ?int $time, string $text, array $vlans = []): void
    {
        $this->edgeMarks[$index] ??= [];
        self::addMark($this->edgeMarks[$index], $type, $time, $text, $vlans);
    }

    /**
     * Mark a node of the map; false when it is not on it.
     *
     * @param  int[]  $vlans  the instances a spanning tree mark is about
     */
    private function markNode(string $id, string $type, ?int $time, string $text, array $vlans = []): bool
    {
        if (! isset($this->nodes[$id])) {
            return false;
        }
        $this->nodeMarks[$id] ??= [];
        self::addMark($this->nodeMarks[$id], $type, $time, $text, $vlans);

        return true;
    }

    /**
     * Add a mark once (both halves of a moved cable, both routers of a session tell the same).
     *
     * @param  array<int, array>  $marks
     * @param  int[]  $vlans
     */
    private static function addMark(array &$marks, string $type, ?int $time, string $text, array $vlans = []): void
    {
        foreach ($marks as $mark) {
            if ($mark['type'] === $type && $mark['text'] === $text) {
                return;
            }
        }
        $marks[] = ['type' => $type, 'time' => $time, 'text' => $text] + ($vlans ? ['vlans' => array_values($vlans)] : []);
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
            // the VLAN the marks are of; null: all, each spanning tree mark with its VLANs
            'vlan' => $this->vlan,
        ];

        return $result;
    }

    /**
     * Wording of the ChangeReport event a mark of a fact tells (event(): a moved cable says where it went), null
     * without one.
     */
    private function eventText(array $row, bool $isGone): ?string
    {
        return $this->event($row, $isGone)['text'] ?? null;
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
     * @param  array<string, string>  $identity  hash => identity (identities())
     * @return array<string, array> identity (the hash, but for the facts of a cable that are one) => row
     */
    private static function byIdentity(array $rows, array $identity): array
    {
        $byIdentity = [];
        foreach ($rows as $row) {
            $byIdentity[$identity[$row['hash']] ?? $row['hash']] = $row;
        }

        return $byIdentity;
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
