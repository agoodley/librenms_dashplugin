<?php

namespace Agoodley\LibrenmsTopology\Hosts;

use Agoodley\LibrenmsTopology\Stp\StpAnalyzer;
use Agoodley\LibrenmsTopology\Support\DeviceRole;

/**
 * Finds the switch port a MAC address is plugged into, from the forwarding tables (ports_fdb) of
 * every switch that learned it.
 *
 * A switch learns a host's MAC on the port toward the host, so on most switches that is an
 * inter-switch link. Each entry is followed to the neighbor on its port (LLDP/CDP in either
 * direction, across port-channels, or, on a port without one, a switch whose own MAC the port
 * learned): an entry pointing to a switch that learned the MAC too is a hop on the way. The others
 * are where trails end: an access port, an access point, IP phone, server or unmanaged switch on
 * it, or a monitored device that does not list the host (no forwarding table data, a host that was
 * silent when the table was read, or an address of the device itself such as a virtual router
 * MAC). Of several ends the best explained one wins, then the newest sighting (a client roamed, a
 * laptop moved), then the port with fewer MACs: an uplink without LLDP/CDP carries every MAC of the
 * switches behind it. Entries from before a switch's last forwarding table read are reported
 * separately: the host moved or left.
 *
 * The answer is a port (located), a monitored device the host is somewhere behind (behind), or the
 * device the address belongs to: one of its interfaces (owner) or a virtual router address it
 * answers as master / active router (gateway).
 */
class HostLocator
{
    /** An entry this much older than its switch's newest one is from an earlier read (seconds). */
    public const STALE_AFTER = 900;

    /** Sightings further apart are not simultaneous: the host moved or roamed in between (seconds). */
    public const EARLIER_AFTER = 1800;

    /** Roles of monitored devices that forward between their links. */
    public const NETWORK_ROLES = DeviceRole::NETWORK;

    /**
     * Where a trail ends, best explained first. monitored: a monitored switch, router or firewall without
     * forwarding table data; not-listed: one whose forwarding table does not list the host.
     */
    private const ENDS = ['owner' => 0, 'access' => 1, 'end-device' => 1, 'unmanaged' => 1, 'monitored' => 2, 'not-listed' => 3, 'unknown-port' => 4];

    /** Which neighbor on a port explains the host best; a switch that does not list it only when it is the only one. */
    private const NEIGHBOR_ORDER = ['owner' => 0, 'unmanaged' => 1, 'monitored' => 2, 'end-device' => 3, 'not-listed' => 4];

    private const ROLE_NAMES = [
        DeviceRole::WIRELESS => 'access point', DeviceRole::PHONE => 'phone', DeviceRole::SERVER => 'server',
        DeviceRole::ROUTER => 'router', DeviceRole::FIREWALL => 'firewall', DeviceRole::SWITCH => 'switch',
        DeviceRole::L3SWITCH => 'switch', DeviceRole::OTHER => 'device', DeviceRole::UNKNOWN => 'switch',
    ];

    /** @var array<int, int[]> aggregate port_id => member port_ids */
    private array $members = [];

    /** @var array<int, int> port_id => current MACs */
    private array $counts = [];

    /**
     * @param  array<int, array>  $ports  port_id => device_id, name, aggregate (bool), macs (current MACs, when known up front)
     * @param  array<int, array<int, array>>  $neighbors  port_id => LLDP/CDP neighbors on the port: name, port,
     *                                                     device_id (monitored and visible, else null), role
     * @param  array<int, int>  $lagParent  member port_id => aggregate port_id
     * @param  array<int, int>  $since  device_id => start of its last forwarding table read: older entries are from an
     *                                  earlier read; a device missing here has no forwarding table data
     * @param  array<int, array{name: string, role?: string}>  $devices  monitored devices
     * @param  int  $sharedPortMacs  MACs on an access port without a neighbor from which it is flagged as shared
     * @param  array<int, int[]>  $switchMacs  port_id => monitored devices whose own MAC the port learned at the last read
     * @param  array<string, \Closure>  $lookups  data asked for only when an answer needs it:
     *         macs (int[] port_ids => array<int, int> current MACs per port): of trail ends;
     *         behind (int device_id, int[] devices not to pass => int[] devices): reachable from the device over LLDP/CDP links,
     *         where an 802.1X session can be when the trail ends at it;
     *         routes (int device_id, int[] VLANs, string[] addresses => bool): the device has a layer 3 interface in one of
     *         the VLANs or an address in the subnet of one of the addresses (it can answer a virtual router address)
     */
    public function __construct(
        private readonly array $ports,
        private readonly array $neighbors,
        private readonly array $lagParent,
        private readonly array $since,
        private readonly array $devices,
        private readonly int $sharedPortMacs = 5,
        private readonly array $switchMacs = [],
        private readonly array $lookups = [],
    ) {
        foreach ($lagParent as $member => $aggregate) {
            $this->members[$aggregate][] = $member;
        }
    }

    /**
     * Where one MAC address is connected.
     *
     * @param  array<int, array>  $rows  forwarding table entries of the MAC: device_id, port_id, vlan, first_seen, last_seen
     * @param  array<int, array>  $owners  interfaces of monitored devices with this MAC: device_id, port
     * @param  array<int, array>  $sessions  802.1X / MAB sessions of the MAC: device_id, port_id, vlan, started, last_seen,
     *                                       current (not historical, on a switch that polls sessions and was polled recently)
     * @param  string[]  $ips  addresses of the MAC (ARP / IPv6 neighbor entries) in the answer's VLAN
     * @return array{status: string, stale: bool, edge: array|null, device: array|null, gateway: array|null,
     *               seen: array<int, array>, notes: array<int, array{level: string, text: string}>}
     */
    public function locate(string $mac, array $rows, array $owners = [], array $sessions = [], array $ips = []): array
    {
        $gateway = Mac::gateway($mac);
        $ownerPorts = [];
        foreach ($owners as $owner) {
            $ownerPorts[(int) $owner['device_id']][] = (string) $owner['port'];
        }
        $notes = [];
        foreach ($ownerPorts as $deviceId => $interfaces) {
            $notes[] = self::note('info', 'This is the MAC address of ' . $this->deviceName($deviceId) . ' ' . self::listed(array_values(array_unique($interfaces))) . '.');
        }
        $gatewayNote = $gateway ? 'Virtual router address (' . $gateway['label'] . '): the router that is master / active for the group sends from it.' : null;
        if ($gatewayNote === null && Mac::isLocal($mac) && ! Mac::isMulticast($mac)) {
            $notes[] = self::note('info', 'Locally administered address: randomized by phones and laptops for privacy, or a virtual interface. It has no vendor and can change.');
        }

        $session = self::currentSession($sessions);
        $entries = $this->entries($rows);
        if (! $entries) {
            $notes = $gatewayNote === null ? $notes : array_merge($notes, [self::note('info', $gatewayNote)]);
            if ($session === null) {
                return self::result('none', false, null, null, $gateway, [], $notes);
            }
            $notes[] = self::note('info', 'Located by its current 802.1X / MAB session on ' . $this->sessionPort($session)
                . ': no forwarding table you can see lists it yet (LibreNMS reads them at discovery, every 6 hours by default).');
            $edge = $this->sessionEdge($session, $ownerPorts);

            return self::result('located', false, $edge, null, $gateway, [], array_merge($notes, $this->edgeNotes($mac, $edge)));
        }

        $current = array_filter($entries, fn ($entry) => ! $entry['stale']);
        $stale = ! $current;
        $working = $current ?: $entries;
        $learned = [];
        foreach ($working as $entry) {
            $learned[$entry['device_id']] = true;
        }

        // follow every entry to the neighbor on its port
        $seen = [];
        $ends = [];
        foreach ($working as $key => $entry) {
            $trail = $this->trail($entry, $learned, $ownerPorts);
            if ($trail['toward'] !== null) {
                $seen[$key] = $entry + ['reason' => 'toward-switch', 'detail' => $trail['toward']];
            } else {
                $ends[$key] = $entry + ['via' => $trail['via'], 'neighbor' => $trail['neighbor']];
            }
        }

        $edge = null;
        if ($ends) {
            $this->countMacs(array_column($ends, 'port_id'));
            foreach ($ends as &$end) {
                $end['macs'] = $this->counts[$end['port_id']] ?? 0;
            }
            unset($end);
            $edge = $this->pickEdge($ends);
            foreach ($ends as $key => $end) {
                $seen[$key] = $end + $this->otherEnd($end, $edge, $notes);
            }
        } else {
            $notes[] = self::note('warning', 'Every switch that learned it points to another switch that learned it: the forwarding tables are from different times or the network has a loop.');
        }

        // what the answer is: a port, a device the host is behind, or the device the address belongs to
        $status = $edge === null ? 'uplinks' : 'located';
        $device = null;
        if ($edge !== null) {
            $at = $edge['neighbor']['device_id'] ?? null;
            $owner = $this->networkOwner(array_keys($ownerPorts), $edge);
            if ($owner !== null) {
                $status = 'owner';
                $device = $owner;
                if ($edge['device_id'] === $owner || ($edge['via'] === 'owner' && $at === $owner)) {
                    $seen[] = $edge + ['reason' => 'toward-owner', 'detail' => $edge['device_id'] === $owner
                        ? 'learned by ' . $this->deviceName($owner) . ' itself'
                        : $this->linkTo($edge) . ', the device with this address'];
                    $edge = null;
                }
            } elseif ($gateway && $at !== null && ($edge['via'] === 'owner' || $edge['via'] === 'not-listed'
                // a device without forwarding table data may be a switch on the way: it answers the address only with an
                // interface in its VLAN or an address in its subnet
                || ($edge['via'] === 'monitored' && $this->routes($at, $edge['vlans'], $ips)))) {
                $status = 'gateway';
                $device = $at;
                $gatewayNote = 'Virtual router address (' . $gateway['label'] . '): ' . $this->deviceName($at) . ' sends from it as the master / active router of the group.';
                $seen[] = $edge + ['reason' => 'toward-owner', 'detail' => $this->linkTo($edge) . ', which answers it'];
                $edge = null;
            } elseif (in_array($edge['via'], ['monitored', 'not-listed'], true) && $at !== null) {
                $status = 'behind';
                $device = $at;
            }
        }
        if ($gatewayNote !== null) {
            array_splice($notes, count($ownerPorts), 0, [self::note('info', $gatewayNote)]);
        }

        // a current 802.1X / MAB session (its switch polls sessions and was polled recently) gives the port when the trail ends without one:
        // on the switch the trail leads to or behind it, or among the switches of a trail that only finds uplinks; and it
        // replaces a forwarding table answer on another port when the host moved since that sighting. LibreNMS keeps one
        // session row per MAC and switch and moves it to the new port (keeping when it was created), so on the edge's own
        // switch an update after the sighting tells; on another switch only the start does (a phone can keep an old one up)
        if ($session !== null && in_array($status, ['located', 'behind', 'uplinks'], true)) {
            $lastSighting = $edge['last_seen'] ?? max(array_column($working, 'last_seen'));
            $newer = (int) $session['last_seen'] > $lastSighting;
            $same = $edge !== null && $edge['port_id'] === (int) $session['port_id'];
            $sameSwitch = $edge !== null && $edge['device_id'] === (int) $session['device_id'];
            $use = match (true) {
                $status === 'behind' => $this->sessionBehind($session, (int) $device, $learned),
                $status === 'uplinks' => isset($learned[(int) $session['device_id']]),
                $same => false,
                $sameSwitch => $newer,
                default => self::sessionStart($session) > $edge['last_seen'],
            };
            if ($use) {
                $why = match (true) {
                    $status === 'behind' => ': the forwarding tables lead to ' . $this->deviceName((int) $device) . ', which '
                        . ($edge['via'] === 'monitored' ? 'has no forwarding table data.' : 'does not list it.'),
                    $status === 'uplinks' => ': the forwarding tables only list it on links between switches.',
                    $sameSwitch => ', which LibreNMS updated after the forwarding tables last saw the host on ' . $this->portName($edge['port_id'])
                        . ' of the same switch: the host moved since.',
                    default => ', which started after the forwarding tables last saw the host on ' . $this->deviceName($edge['device_id']) . ' '
                        . $this->portName($edge['port_id']) . ': the host moved since.',
                };
                if ($edge !== null) {
                    $seen[] = $edge + match (true) {
                        $status === 'behind' => ['reason' => 'behind-monitored', 'detail' => 'toward ' . $edge['neighbor']['name'] . ', '
                            . ($edge['via'] === 'monitored' ? 'which has no forwarding table data' : 'whose forwarding table does not list it')],
                        $sameSwitch => ['reason' => 'earlier', 'detail' => 'seen here ' . StpAnalyzer::duration(max(0, (int) $session['last_seen'] - $edge['last_seen']))
                            . ' before its 802.1X / MAB session on ' . $this->portName((int) $session['port_id']) . ' was updated: the host moved since'],
                        default => ['reason' => 'earlier', 'detail' => 'seen here ' . StpAnalyzer::duration(max(0, self::sessionStart($session) - $edge['last_seen']))
                            . ' before its 802.1X / MAB session elsewhere started: the host moved since'],
                    };
                }
                $notes[] = self::note('info', 'Located by its current 802.1X / MAB session on ' . $this->sessionPort($session) . $why);
                $edge = $this->sessionEdge($session, $ownerPorts);
                $status = 'located';
                $device = null;
                $stale = $stale && ! $newer;
            } elseif ($same) {
                // a session updated after the forwarding table entry shows the host is still there
                $stale = $stale && ! $newer;
            } else {
                $notes[] = self::note('info', 'An 802.1X / MAB session of this MAC is on ' . $this->sessionPort($session) . match (true) {
                    $status !== 'located' => ', where the forwarding tables do not lead: it is not used as the location.',
                    $sameSwitch => ', but LibreNMS last updated it before the forwarding tables saw the host here: it is not used as the location.',
                    default => ', but it started before the forwarding tables saw the host here: the switch keeps an old session (a phone or hub keeps the port up).',
                });
            }
        }

        if ($edge !== null) {
            $notes = array_merge($notes, $this->edgeNotes($mac, $edge));
        }
        if ($stale && ($edge !== null || $device !== null)) {
            $notes[] = self::note('warning', 'Not seen at the last forwarding table discovery: the host may be switched off or gone.');
        }
        foreach ($entries as $key => $entry) {
            if ($entry['stale'] && ! $stale) {
                $seen[$key] = $entry + ['reason' => 'stale', 'detail' => 'not seen at the last discovery of ' . $this->deviceName($entry['device_id'])];
            }
        }

        usort($seen, fn ($a, $b) => [$a['reason'] === 'stale', -$a['last_seen'], $a['device_id'], $a['port_id']] <=> [$b['reason'] === 'stale', -$b['last_seen'], $b['device_id'], $b['port_id']]);

        return self::result($status, $stale, $edge, $device === null ? null : ['device_id' => $device, 'name' => $this->deviceName($device)], $gateway, array_values($seen), $notes);
    }

    /**
     * Forwarding table entries merged per switch port (the same MAC can be learned in several VLANs).
     *
     * @return array<string, array>
     */
    private function entries(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $deviceId = (int) $row['device_id'];
            $portId = (int) $row['port_id'];
            $key = "$deviceId:$portId";
            $lastSeen = (int) $row['last_seen'];
            $entry = $entries[$key] ?? [
                'device_id' => $deviceId,
                'port_id' => $portId,
                'vlans' => [],
                'first_seen' => (int) $row['first_seen'],
                'last_seen' => $lastSeen,
            ];
            if ($row['vlan'] !== null && ! in_array((int) $row['vlan'], $entry['vlans'], true)) {
                $entry['vlans'][] = (int) $row['vlan'];
            }
            $entry['first_seen'] = min($entry['first_seen'], (int) $row['first_seen']);
            $entry['last_seen'] = max($entry['last_seen'], $lastSeen);
            $entries[$key] = $entry;
        }

        foreach ($entries as &$entry) {
            sort($entry['vlans']);
            $since = $this->since[$entry['device_id']] ?? null;
            $entry['stale'] = $since !== null && $entry['last_seen'] < $since;
        }
        unset($entry);

        return $entries;
    }

    /**
     * Where an entry leads: toward (why it is a hop on the way), or how the trail ends (via) and the neighbor there.
     *
     * @param  array<int, true>  $learned  switches with an entry for the MAC
     * @param  array<int, string[]>  $ownerPorts  monitored devices with an interface that has this MAC
     * @return array{toward: ?string, via: ?string, neighbor: ?array}
     */
    private function trail(array $entry, array $learned, array $ownerPorts): array
    {
        $portId = $entry['port_id'];
        if ($portId <= 0 || ! isset($this->ports[$portId])) {
            return ['toward' => null, 'via' => 'unknown-port', 'neighbor' => null];
        }

        $link = $this->isAggregate($portId) ? 'port-channel' : 'link';
        $neighbors = $this->neighborsOf($portId);
        foreach ($neighbors as $neighbor) {
            $deviceId = $neighbor['device_id'];
            if ($deviceId !== null && $deviceId !== $entry['device_id'] && isset($learned[$deviceId])) {
                return ['toward' => $link . ' toward ' . $this->deviceName($deviceId) . ', which also learned it', 'via' => null, 'neighbor' => null];
            }
        }

        if (! $neighbors) {
            // a port that learned the own MAC of a switch that learned the host too leads to that switch
            foreach ($this->switchMacs[$portId] ?? [] as $deviceId) {
                if ($deviceId !== $entry['device_id'] && isset($learned[$deviceId])) {
                    return ['toward' => $link . ' toward ' . $this->deviceName($deviceId) . ' (its own MAC address is learned here), which also learned it',
                        'via' => null, 'neighbor' => null];
                }
            }

            return ['toward' => null, 'via' => 'access', 'neighbor' => null];
        }

        $best = null;
        $names = [];
        foreach ($neighbors as $neighbor) {
            $deviceId = $neighbor['device_id'];
            $end = $deviceId !== null && isset($ownerPorts[$deviceId]) ? 'owner' : $this->endKind($neighbor);
            $names[(string) $neighbor['name']] = true;
            if ($best === null || self::NEIGHBOR_ORDER[$end] < self::NEIGHBOR_ORDER[$best['end']]) {
                $best = $neighbor + ['end' => $end];
            }
        }
        $best['others'] = array_values(array_diff(array_map('strval', array_keys($names)), [(string) $best['name']]));

        return ['toward' => null, 'via' => $best['end'], 'neighbor' => $best];
    }

    /**
     * monitored / not-listed: a monitored switch, router or firewall that did not learn the host (else the trail
     * would continue there), without forwarding table data or with one that does not list it; unmanaged: a
     * neighbor that forwards and is not monitored (or not visible); end-device: an access point, phone, server
     * or other end device.
     */
    private function endKind(array $neighbor): string
    {
        $role = $neighbor['role'] ?? DeviceRole::UNKNOWN;
        if ($neighbor['device_id'] !== null) {
            if (! in_array($role, self::NETWORK_ROLES, true)) {
                return 'end-device';
            }

            return isset($this->since[$neighbor['device_id']]) ? 'not-listed' : 'monitored';
        }

        return DeviceRole::isEndDevice($role) ? 'end-device' : 'unmanaged';  // an unknown platform counts as a switch
    }

    /**
     * LLDP/CDP neighbors of a port; a port-channel's neighbors are those of its members.
     *
     * @return array<int, array>
     */
    private function neighborsOf(int $portId): array
    {
        $result = $this->neighbors[$portId] ?? [];
        foreach ($this->members[$portId] ?? [] as $member) {
            $result = array_merge($result, $this->neighbors[$member] ?? []);
        }

        return $result;
    }

    private function isAggregate(int $portId): bool
    {
        return ! empty($this->ports[$portId]['aggregate']) || isset($this->members[$portId]);
    }

    /**
     * Fetch the current MAC counts of trail end ports not known yet.
     *
     * @param  int[]  $portIds
     */
    private function countMacs(array $portIds): void
    {
        $missing = array_values(array_diff(array_unique($portIds), array_keys($this->counts)));
        if (! $missing) {
            return;
        }

        $counts = isset($this->lookups['macs']) ? ($this->lookups['macs'])($missing) : [];
        foreach ($missing as $portId) {
            $this->counts[$portId] = (int) ($counts[$portId] ?? $this->ports[$portId]['macs'] ?? 0);
        }
    }

    /**
     * The best trail end, taken out of $ends: best explained, then the newest sighting (one much older than
     * the newest of its kind is from before the host moved or roamed), then fewer MACs on the port.
     *
     * @param  array<string, array>  $ends  extended with uplink and earlier flags
     */
    private function pickEdge(array &$ends): array
    {
        // an access port with far more MACs than another trail end is an uplink to a switch without LLDP/CDP
        foreach ($ends as $key => $end) {
            $fewest = null;
            foreach ($ends as $other => $candidate) {
                if ($other !== $key && self::ENDS[$candidate['via']] === 1) {
                    $fewest = min($fewest ?? PHP_INT_MAX, $candidate['macs']);
                }
            }
            $ends[$key]['uplink'] = $end['via'] === 'access' && $fewest !== null && self::uplinkLike($end['macs'], $fewest);
        }

        $group = fn (array $end) => self::ENDS[$end['via']] . ($end['uplink'] ? 'u' : '');
        $newest = [];
        foreach ($ends as $end) {
            $newest[$group($end)] = max($newest[$group($end)] ?? 0, $end['last_seen']);
        }
        foreach ($ends as $key => $end) {
            $ends[$key]['earlier'] = $end['last_seen'] < $newest[$group($end)] - self::EARLIER_AFTER;
        }

        $rank = fn (array $end) => [self::ENDS[$end['via']], $end['uplink'], $end['earlier'], $end['macs'], -$end['last_seen'], $end['device_id'], $end['port_id']];
        uasort($ends, fn ($a, $b) => $rank($a) <=> $rank($b));
        $key = array_key_first($ends);
        $edge = $ends[$key];
        unset($ends[$key]);

        return $edge;
    }

    /**
     * Why another trail end is not the answer.
     *
     * @param  array  $notes  host notes, extended when two access ports learned it at the same time (a warning), or an
     *                        access port and a port toward a switch that cannot be followed
     * @return array{reason: string, detail: string}
     */
    private function otherEnd(array $end, array $edge, array &$notes): array
    {
        if ($end['via'] === 'unknown-port') {
            return ['reason' => 'unknown-port', 'detail' => 'learned on a port LibreNMS could not map (bridge port without ifIndex)'];
        }
        $neighbor = $end['neighbor'];
        if ($neighbor !== null && $edge['neighbor'] !== null && self::sameNeighbor($neighbor, $edge['neighbor'])) {
            return $neighbor['device_id'] !== null && in_array($neighbor['role'] ?? '', self::NETWORK_ROLES, true)
                ? ['reason' => 'same-destination', 'detail' => 'another path to ' . $neighbor['name']]
                : ['reason' => 'dual-homed', 'detail' => 'another link to ' . $neighbor['name'] . ': it is connected to both switches (a port-channel across a switch pair, or dual-homed)'];
        }
        if (in_array($end['via'], ['monitored', 'not-listed'], true)) {
            return ['reason' => 'behind-monitored', 'detail' => 'toward ' . $neighbor['name'] . ', '
                . ($end['via'] === 'monitored' ? 'which has no forwarding table data' : 'whose forwarding table does not list it')];
        }
        if ($end['last_seen'] < $edge['last_seen'] - self::EARLIER_AFTER) {
            return ['reason' => 'earlier', 'detail' => 'seen here ' . StpAnalyzer::duration($edge['last_seen'] - $end['last_seen'])
                . ' before the newest sighting: the host moved or roamed since'];
        }
        // a neighbor that forwards but cannot be followed: not monitored, or a switch the user may not see (both look the
        // same, so they are treated the same). With many MACs it is a switch on the way, like an uplink without LLDP/CDP
        $unmanaged = $end['via'] === 'unmanaged';
        if (($end['via'] === 'access' || $unmanaged) && ($end['uplink'] || self::uplinkLike($end['macs'], $edge['macs']))) {
            return ['reason' => 'uplink', 'detail' => $unmanaged
                ? 'toward ' . $neighbor['name'] . ' (not monitored by LibreNMS, or not visible to you), which carries ' . $end['macs'] . ' MACs: probably a switch on the way'
                : 'carries ' . $end['macs'] . ' MACs: probably an uplink to a switch without LLDP/CDP'];
        }

        $where = $this->deviceName($end['device_id']) . ' ' . $this->portName($end['port_id']);
        $notes[] = $unmanaged
            ? self::note('info', 'Also learned at about the same time on ' . $where . ' toward ' . $neighbor['name'] . ', which LibreNMS does not monitor or you cannot see:'
                . ' the trail cannot be followed past it, so it may lead here; else a loop or bridge joins the two ports.')
            : self::note('warning', 'Also learned on another access port (' . $where . ') at about the same time: a loop, a bridge or an unmanaged switch joins the two ports,'
                . ' or the host moved between the two readings.');

        return ['reason' => 'access', 'detail' => 'also learned here (' . $end['macs'] . ' MAC' . ($end['macs'] === 1 ? '' : 's') . ' on the port'
            . ($neighbor ? ($unmanaged ? ', toward ' : ', behind ') . $neighbor['name'] : '') . ')'];
    }

    /**
     * The monitored switch, router or firewall the address belongs to, preferably the one the trail ends at.
     *
     * @param  int[]  $owners
     */
    private function networkOwner(array $owners, array $edge): ?int
    {
        $network = array_values(array_filter($owners, fn ($deviceId) => in_array($this->devices[$deviceId]['role'] ?? DeviceRole::UNKNOWN, self::NETWORK_ROLES, true)));
        foreach ([$edge['neighbor']['device_id'] ?? null, $edge['device_id']] as $candidate) {
            if ($candidate !== null && in_array($candidate, $network, true)) {
                return $candidate;
            }
        }

        return $network[0] ?? null;
    }

    /**
     * What the trail end says about the host.
     *
     * @return array<int, array{level: string, text: string}>
     */
    private function edgeNotes(string $mac, array $edge): array
    {
        $neighbor = $edge['neighbor'];
        $role = self::ROLE_NAMES[$neighbor['role'] ?? DeviceRole::UNKNOWN] ?? 'device';
        $via = $edge['via'];
        if ($via === 'nac') {
            // a port found by its 802.1X session: what is on the port
            $via = in_array($neighbor['end'] ?? null, ['end-device', 'unmanaged'], true) ? $neighbor['end'] : 'nac';
        }
        $notes = [];
        if ($neighbor && $neighbor['others']) {
            $notes[] = self::note('info', 'Several LLDP/CDP neighbors on this port (' . implode(', ', array_merge([$neighbor['name']], $neighbor['others']))
                . '): an unmanaged switch or hub joins them.');
        }

        return array_merge($notes, match ($via) {
            'owner', 'nac' => [],
            'end-device' => [self::note('info', match (true) {
                (bool) preg_match('/^sep([0-9a-f]{12})$/', strtolower($neighbor['name'])) && substr(strtolower($neighbor['name']), 3) === $mac => 'This is the IP phone ' . $neighbor['name'] . ' itself.',
                $neighbor['role'] === DeviceRole::WIRELESS => 'Behind access point ' . $neighbor['name'] . ': a wireless client (or the access point itself).',
                $neighbor['role'] === DeviceRole::PHONE => 'Behind IP phone ' . $neighbor['name'] . ': a computer on the phone\'s PC port (or the phone itself).',
                $neighbor['role'] === DeviceRole::SERVER => 'Behind server ' . $neighbor['name'] . ': a virtual machine or container (or the server itself).',
                default => 'Behind ' . $neighbor['name'] . ' (' . $role . ').',
            })],
            'unmanaged' => [self::note('info', 'Behind ' . $neighbor['name'] . ', an unmanaged ' . $role . ' (not monitored by LibreNMS, or not visible to you).')],
            'monitored' => [self::note('warning', 'The trail ends at ' . $neighbor['name'] . ': LibreNMS monitors it but has no forwarding table from it (forwarding table discovery off or not supported), so the host is somewhere behind it.')],
            'not-listed' => [self::note('warning', 'The trail ends at ' . $neighbor['name'] . ': its forwarding table does not list the host. The host was silent when the table was read'
                . ' (switches forget idle hosts after about 5 minutes) or connected after that, or the address belongs to ' . $neighbor['name'] . ' itself.')],
            'unknown-port' => [self::note('warning', 'Learned on a port LibreNMS could not map (bridge port without ifIndex).')],
            default => $edge['macs'] >= $this->sharedPortMacs
                ? [self::note('info', $edge['macs'] . ' MACs are learned on this port and it has no LLDP/CDP neighbor: an unmanaged switch, hub or virtualization host is probably connected to it.')]
                : [],
        });
    }

    /**
     * The current 802.1X / MAB session that started last (a switch keeps an old session that a phone keeps up next to
     * the one where the host is now, and a session's update time only tells when one of its values last changed).
     */
    private static function currentSession(array $sessions): ?array
    {
        $current = array_values(array_filter($sessions, fn ($session) => ! empty($session['current']) && empty($session['historical']) && (int) $session['port_id'] > 0));
        usort($current, fn ($a, $b) => [self::sessionStart($b), $b['last_seen']] <=> [self::sessionStart($a), $a['last_seen']]);

        return $current[0] ?? null;
    }

    /**
     * When a session started (a switch refreshes a session's update time at every poll while its reauthentication timer
     * runs, so only the start tells whether it is newer than a forwarding table entry).
     */
    private static function sessionStart(array $session): int
    {
        return (int) ($session['started'] ?? $session['first_seen'] ?? $session['last_seen']);
    }

    /**
     * Is the session on the device the trail ends at, or on a device behind it (not through the switches of the trail)?
     *
     * @param  array<int, true>  $learned  switches with an entry for the MAC
     */
    private function sessionBehind(array $session, int $deviceId, array $learned): bool
    {
        $at = (int) $session['device_id'];
        if ($at === $deviceId) {
            return true;
        }
        $behind = $this->lookups['behind'] ?? null;

        return $behind !== null && in_array($at, $behind($deviceId, array_keys($learned)), true);
    }

    /**
     * Layer 3 evidence that a device answers a virtual router address (see the routes lookup).
     *
     * @param  int[]  $vlans
     * @param  string[]  $ips
     */
    private function routes(int $deviceId, array $vlans, array $ips): bool
    {
        $routes = $this->lookups['routes'] ?? null;

        return $routes !== null && (bool) $routes($deviceId, $vlans, $ips);
    }

    /**
     * The port of an 802.1X / MAB session as a trail end, with the neighbor on the port.
     *
     * @param  array<int, string[]>  $ownerPorts
     */
    private function sessionEdge(array $session, array $ownerPorts): array
    {
        $portId = (int) $session['port_id'];
        $lastSeen = (int) $session['last_seen'];
        $entry = ['device_id' => (int) $session['device_id'], 'port_id' => $portId, 'vlans' => isset($session['vlan']) ? [(int) $session['vlan']] : [],
            'first_seen' => self::sessionStart($session) ?: $lastSeen, 'last_seen' => $lastSeen, 'stale' => false];
        $trail = $this->trail($entry, [], $ownerPorts);
        $this->countMacs([$portId]);

        return $entry + ['via' => 'nac', 'neighbor' => $trail['neighbor'], 'macs' => $this->counts[$portId] ?? 0];
    }

    private function sessionPort(array $session): string
    {
        return $this->deviceName((int) $session['device_id']) . ' ' . $this->portName((int) $session['port_id']);
    }

    /**
     * An access port with this many MACs where another trail end has $fewest looks like an uplink.
     */
    private static function uplinkLike(int $macs, int $fewest): bool
    {
        return $macs >= max(2 * $fewest, $fewest + 3);
    }

    /**
     * The same device on two trail ends (the same monitored device, or the same LLDP/CDP name).
     */
    private static function sameNeighbor(array $a, array $b): bool
    {
        if ($a['device_id'] !== null || $b['device_id'] !== null) {
            return $a['device_id'] === $b['device_id'];
        }
        $name = strtolower((string) $a['name']);

        return $name !== '' && $name !== 'unknown neighbor' && $name === strtolower((string) $b['name']);
    }

    private function linkTo(array $edge): string
    {
        return ($this->isAggregate($edge['port_id']) ? 'port-channel' : 'link') . ' toward ' . ($edge['neighbor']['name'] ?? $this->deviceName($edge['device_id']));
    }

    private function deviceName(int $deviceId): string
    {
        return (string) ($this->devices[$deviceId]['name'] ?? 'device #' . $deviceId);
    }

    private function portName(int $portId): string
    {
        return (string) ($this->ports[$portId]['name'] ?? 'port #' . $portId);
    }

    /**
     * Vl10 / Vl10 and Vl20 / Vl10, Vl20, Vl30 and 64 more interfaces (Cisco SVIs share one MAC).
     *
     * @param  string[]  $names
     */
    private static function listed(array $names): string
    {
        $count = count($names);
        if ($count > 4) {
            return implode(', ', array_slice($names, 0, 3)) . ' and ' . ($count - 3) . ' more interfaces';
        }

        return $count > 1 ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names) : (string) ($names[0] ?? '');
    }

    private static function result(string $status, bool $stale, ?array $edge, ?array $device, ?array $gateway, array $seen, array $notes): array
    {
        return ['status' => $status, 'stale' => $stale, 'edge' => $edge, 'device' => $device, 'gateway' => $gateway, 'seen' => $seen, 'notes' => $notes];
    }

    /**
     * @return array{level: string, text: string}
     */
    private static function note(string $level, string $text): array
    {
        return ['level' => $level, 'text' => $text];
    }
}
