<?php

namespace Agoodley\LibrenmsTopology\Hosts;

use Agoodley\LibrenmsTopology\Support\DeviceRole;

/**
 * Finds the switch port a MAC address is plugged into, from the forwarding tables (ports_fdb) of
 * every switch that learned it.
 *
 * A switch learns a host's MAC on the port toward the host, so on most switches that is an
 * inter-switch link. Each entry is followed to the neighbor on its port (LLDP/CDP, in either
 * direction, across port-channels): an entry pointing to a switch that learned the MAC too is a
 * hop on the way; the others are where the trail ends: an access port, an access point, IP phone,
 * server or unmanaged switch on it, or a monitored switch whose forwarding table does not list the
 * host. When several trails end, the port with the fewest MACs wins: an uplink without LLDP/CDP
 * carries every MAC of the switches behind it. Entries from before a switch's last forwarding
 * table discovery are reported separately: the host moved or left.
 */
class HostLocator
{
    /** An entry older than its switch's newest one by more than this is from an earlier discovery (seconds). */
    public const STALE_AFTER = 900;

    /** Roles of monitored devices that forward between their links. */
    private const NETWORK_ROLES = [DeviceRole::SWITCH, DeviceRole::L3SWITCH, DeviceRole::ROUTER, DeviceRole::FIREWALL];

    /** Roles of unmanaged neighbors that do not forward (an unknown platform counts as a switch). */
    private const END_ROLES = [DeviceRole::SERVER, DeviceRole::PHONE, DeviceRole::WIRELESS, DeviceRole::OTHER];

    /** Where a trail ends, best first. */
    private const ENDS = ['owner' => 0, 'access' => 1, 'end-device' => 1, 'unmanaged' => 1, 'monitored' => 2, 'unknown-port' => 3];

    /** Which neighbor on a port explains the host best. */
    private const NEIGHBOR_ORDER = ['owner' => 0, 'unmanaged' => 1, 'monitored' => 2, 'end-device' => 3];

    private const ROLE_NAMES = [
        DeviceRole::WIRELESS => 'access point', DeviceRole::PHONE => 'phone', DeviceRole::SERVER => 'server',
        DeviceRole::ROUTER => 'router', DeviceRole::FIREWALL => 'firewall', DeviceRole::SWITCH => 'switch',
        DeviceRole::L3SWITCH => 'switch', DeviceRole::OTHER => 'device', DeviceRole::UNKNOWN => 'switch',
    ];

    /**
     * @param  array<int, array>  $ports  port_id => device_id, name, aggregate (bool), macs (MACs learned on it now)
     * @param  array<int, array<int, array>>  $neighbors  port_id => LLDP/CDP neighbors on the port: name, port,
     *                                                     device_id (monitored and visible, else null), role
     * @param  array<int, int>  $lagParent  member port_id => aggregate port_id
     * @param  array<int, int>  $fresh  device_id => time of its newest forwarding table entry
     * @param  array<int, string>  $deviceNames
     * @param  int  $sharedPortMacs  MACs on an access port without a neighbor from which it is flagged as shared
     */
    public function __construct(
        private readonly array $ports,
        private readonly array $neighbors,
        private readonly array $lagParent,
        private readonly array $fresh,
        private readonly array $deviceNames,
        private readonly int $sharedPortMacs = 5,
    ) {
    }

    /**
     * Where one MAC address is connected.
     *
     * @param  array<int, array>  $rows  forwarding table entries of the MAC: device_id, port_id, vlan, first_seen, last_seen
     * @param  array<int, array>  $owners  interfaces of monitored devices with this MAC: device_id, port
     * @return array{status: string, stale: bool, edge: array|null, seen: array<int, array>, notes: array<int, array{level: string, text: string}>}
     */
    public function locate(string $mac, array $rows, array $owners = []): array
    {
        $notes = [];
        foreach ($owners as $owner) {
            $notes[] = self::note('info', 'This is the MAC address of ' . $this->deviceName((int) $owner['device_id']) . ' ' . $owner['port'] . '.');
        }
        if (Mac::isLocal($mac) && ! Mac::isMulticast($mac)) {
            $notes[] = self::note('info', 'Locally administered address: randomized by phones and laptops for privacy, or a virtual interface. It has no vendor and can change.');
        }

        $ownerDevices = [];
        foreach ($owners as $owner) {
            $ownerDevices[(int) $owner['device_id']] = true;
        }

        $entries = $this->entries($rows);
        if (! $entries) {
            return ['status' => 'none', 'stale' => false, 'edge' => null, 'seen' => [], 'notes' => $notes];
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
            [$end, $neighbor, $toward] = $this->trail($entry, $learned, $ownerDevices);
            if ($toward !== null) {
                $seen[$key] = $entry + [
                    'reason' => 'toward-switch',
                    'detail' => ($this->isAggregate($entry['port_id']) ? 'port-channel' : 'link') . ' toward ' . $toward . ', which also learned it',
                ];
            } else {
                $ends[$key] = $entry + ['via' => $end, 'neighbor' => $neighbor];
            }
        }

        $edge = null;
        if ($ends) {
            uasort($ends, fn ($a, $b) => [self::ENDS[$a['via']], $a['macs'], -$a['last_seen'], $a['device_id'], $a['port_id']]
                <=> [self::ENDS[$b['via']], $b['macs'], -$b['last_seen'], $b['device_id'], $b['port_id']]);
            $edge = array_shift($ends);
            foreach ($ends as $key => $end) {
                $seen[$key] = $end + $this->otherEnd($end, $edge, $notes);
            }
            $notes = array_merge($notes, $this->edgeNotes($mac, $edge));
        } else {
            $notes[] = self::note('warning', 'Every switch that learned it points to another switch that learned it: the forwarding tables are from different times or the network has a loop.');
        }

        if ($stale && $edge) {
            $notes[] = self::note('warning', 'Not seen at the last forwarding table discovery: the host may be switched off or gone.');
        }
        foreach ($entries as $key => $entry) {
            if ($entry['stale'] && ! $stale) {
                $seen[$key] = $entry + ['reason' => 'stale', 'detail' => 'not seen at the last discovery of ' . $this->deviceName($entry['device_id'])];
            }
        }

        usort($seen, fn ($a, $b) => [$a['reason'] === 'stale', -$a['last_seen'], $a['device_id'], $a['port_id']] <=> [$b['reason'] === 'stale', -$b['last_seen'], $b['device_id'], $b['port_id']]);

        return [
            'status' => match (true) {
                $edge === null => 'uplinks',
                $edge['via'] === 'monitored' => 'behind',
                default => 'located',
            },
            'stale' => $stale,
            'edge' => $edge,
            'seen' => array_values($seen),
            'notes' => $notes,
        ];
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
                'macs' => (int) ($this->ports[$portId]['macs'] ?? 0),
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
            $newest = $this->fresh[$entry['device_id']] ?? $entry['last_seen'];
            $entry['stale'] = $entry['last_seen'] < $newest - self::STALE_AFTER;
        }
        unset($entry);

        return $entries;
    }

    /**
     * Where an entry leads: [how the trail ends, the neighbor there, null] or, for a hop on the way,
     * [null, null, name of the switch it points to].
     *
     * @param  array<int, true>  $learned  switches with an entry for the MAC
     * @param  array<int, true>  $ownerDevices  monitored devices with an interface that has this MAC
     * @return array{0: string|null, 1: array|null, 2: string|null}
     */
    private function trail(array $entry, array $learned, array $ownerDevices): array
    {
        if ($entry['port_id'] <= 0 || ! isset($this->ports[$entry['port_id']])) {
            return ['unknown-port', null, null];
        }

        $neighbors = $this->neighborsOf($entry['port_id']);
        foreach ($neighbors as $neighbor) {
            $deviceId = $neighbor['device_id'];
            if ($deviceId !== null && $deviceId !== $entry['device_id'] && isset($learned[$deviceId])) {
                return [null, null, $this->deviceName($deviceId)];
            }
        }

        // the neighbor that explains the host best; a switch that keeps its forwarding table
        // up to date and does not list the host is not where it is
        $best = null;
        $names = [];
        foreach ($neighbors as $neighbor) {
            $deviceId = $neighbor['device_id'];
            $end = $deviceId !== null && isset($ownerDevices[$deviceId]) ? 'owner' : $this->endKind($neighbor);
            if ($end === 'monitored' && isset($this->fresh[$deviceId]) && $this->fresh[$deviceId] >= $entry['last_seen'] - self::STALE_AFTER) {
                continue;
            }
            $names[$neighbor['name']] = true;
            if ($best === null || self::NEIGHBOR_ORDER[$end] < self::NEIGHBOR_ORDER[$best['end']]) {
                $best = $neighbor + ['end' => $end];
            }
        }
        if ($best !== null) {
            $best['others'] = array_values(array_diff(array_keys($names), [$best['name']]));
        }

        return $best === null ? ['access', null, null] : [$best['end'], $best, null];
    }

    /**
     * monitored: a monitored switch, router or firewall (it does not list the host, or the trail
     * would continue there); unmanaged: a neighbor that forwards and is not monitored (or not visible);
     * end-device: an access point, phone, server or other end device.
     */
    private function endKind(array $neighbor): string
    {
        $role = $neighbor['role'] ?? DeviceRole::UNKNOWN;
        if ($neighbor['device_id'] !== null) {
            return in_array($role, self::NETWORK_ROLES, true) ? 'monitored' : 'end-device';
        }

        return in_array($role, self::END_ROLES, true) ? 'end-device' : 'unmanaged';
    }

    /**
     * LLDP/CDP neighbors of a port; a port-channel's neighbors are those of its members.
     *
     * @return array<int, array>
     */
    private function neighborsOf(int $portId): array
    {
        $result = $this->neighbors[$portId] ?? [];
        if ($this->isAggregate($portId)) {
            foreach ($this->lagParent as $member => $aggregate) {
                if ($aggregate === $portId) {
                    $result = array_merge($result, $this->neighbors[$member] ?? []);
                }
            }
        }

        return $result;
    }

    private function isAggregate(int $portId): bool
    {
        return ! empty($this->ports[$portId]['aggregate']) || in_array($portId, $this->lagParent, true);
    }

    /**
     * Why another trail end is not the answer.
     *
     * @param  array  $notes  host notes, extended with a warning when two access ports learned it
     * @return array{reason: string, detail: string}
     */
    private function otherEnd(array $end, array $edge, array &$notes): array
    {
        if ($end['via'] === 'unknown-port') {
            return ['reason' => 'unknown-port', 'detail' => 'learned on a port LibreNMS could not map (bridge port without ifIndex)'];
        }
        $destination = $edge['neighbor']['device_id'] ?? null;
        if ($destination !== null && ($end['neighbor']['device_id'] ?? null) === $destination) {
            return ['reason' => 'same-destination', 'detail' => 'another path to ' . $end['neighbor']['name']];
        }
        if ($end['via'] === 'monitored') {
            return ['reason' => 'behind-monitored', 'detail' => 'toward ' . $end['neighbor']['name'] . ', whose forwarding table does not list it'];
        }
        if ($end['via'] === 'access' && $end['macs'] >= max(2 * $edge['macs'], $edge['macs'] + 3)) {
            return ['reason' => 'uplink', 'detail' => 'carries ' . $end['macs'] . ' MACs: probably an uplink to a switch without LLDP/CDP'];
        }

        $notes[] = self::note('warning', 'Also learned on another access port (' . $this->deviceName($end['device_id']) . ' ' . $this->portName($end['port_id'])
            . '): the host may have moved, or a loop, a bridge or an unmanaged switch joins the two ports.');

        return ['reason' => 'access', 'detail' => 'also learned here (' . $end['macs'] . ' MAC' . ($end['macs'] === 1 ? '' : 's') . ' on the port'
            . ($end['neighbor'] ? ', behind ' . $end['neighbor']['name'] : '') . ')'];
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
        $notes = [];
        if ($neighbor && $neighbor['others']) {
            $notes[] = self::note('info', 'Several LLDP/CDP neighbors on this port (' . implode(', ', array_merge([$neighbor['name']], $neighbor['others']))
                . '): an unmanaged switch or hub joins them.');
        }

        return array_merge($notes, match ($edge['via']) {
            'owner' => [],
            'end-device' => [self::note('info', match (true) {
                (bool) preg_match('/^sep([0-9a-f]{12})$/', strtolower($neighbor['name'])) && substr(strtolower($neighbor['name']), 3) === $mac => 'This is the IP phone ' . $neighbor['name'] . ' itself.',
                $neighbor['role'] === DeviceRole::WIRELESS => 'Behind access point ' . $neighbor['name'] . ': a wireless client (or the access point itself).',
                $neighbor['role'] === DeviceRole::PHONE => 'Behind IP phone ' . $neighbor['name'] . ': a computer on the phone\'s PC port (or the phone itself).',
                $neighbor['role'] === DeviceRole::SERVER => 'Behind server ' . $neighbor['name'] . ': a virtual machine or container (or the server itself).',
                default => 'Behind ' . $neighbor['name'] . ' (' . $role . ').',
            })],
            'unmanaged' => [self::note('info', 'Behind ' . $neighbor['name'] . ', an unmanaged ' . $role . ' (not monitored by LibreNMS, or not visible to you).')],
            'monitored' => [self::note('warning', 'The trail ends at ' . $neighbor['name'] . ': LibreNMS monitors it but its forwarding table does not list the host (forwarding table discovery off or not supported), so the host is somewhere behind it.')],
            'unknown-port' => [self::note('warning', 'Learned on a port LibreNMS could not map (bridge port without ifIndex).')],
            default => $edge['macs'] >= $this->sharedPortMacs
                ? [self::note('info', $edge['macs'] . ' MACs are learned on this port and it has no LLDP/CDP neighbor: an unmanaged switch, hub or virtualization host is probably connected to it.')]
                : [],
        });
    }

    private function deviceName(int $deviceId): string
    {
        return $this->deviceNames[$deviceId] ?? 'device #' . $deviceId;
    }

    private function portName(int $portId): string
    {
        return (string) ($this->ports[$portId]['name'] ?? 'port #' . $portId);
    }

    /**
     * @return array{level: string, text: string}
     */
    private static function note(string $level, string $text): array
    {
        return ['level' => $level, 'text' => $text];
    }
}
