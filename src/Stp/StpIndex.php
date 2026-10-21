<?php

namespace Agoodley\LibrenmsTopology\Stp;

/**
 * Indexed, normalized view of the LibreNMS `stp` and `ports_stp` tables.
 *
 * VLAN keys: LibreNMS stores the default instance (802.1D/RSTP/MST CIST or Cisco PVST VLAN 1)
 * with vlan NULL. Those are normalized to key 0. Per-VLAN (PVST) instances keep their VLAN id.
 */
class StpIndex
{
    public const BLOCKING_STATES = ['blocking', 'discarding'];

    /** @var array<int, array<int, array>> device_id => vlanKey => instance */
    private array $instances = [];

    /** @var array<int, array<int, array<int, array>>> device_id => vlanKey => port_id => port */
    private array $ports = [];

    /** @var array<int, array<int, array<int, array>>> device_id => vlanKey => port_index => port */
    private array $portsByIndex = [];

    /** @var array<string, int> bridge MAC => device_id */
    private array $bridges = [];

    /** @var array<int, int[]> device_id => VLAN keys of all its instances, also those whose rows are not loaded (partial()) */
    private array $keys = [];

    /**
     * @param  iterable<array|object>  $instances  rows of the stp table
     * @param  iterable<array|object>  $ports  rows of the ports_stp table
     * @param  array<int, int>  $lagParent  member port_id => aggregate port_id
     */
    public function __construct(iterable $instances = [], iterable $ports = [], private array $lagParent = [])
    {
        foreach ($instances as $row) {
            $row = self::normalizeInstance((array) $row);
            $this->instances[$row['device_id']][$row['vlan']] = $row;
            if ($row['bridgeAddress'] !== '') {
                $this->bridges[$row['bridgeAddress']] ??= $row['device_id'];
            }
        }

        $this->addPorts($ports);

        foreach ($this->instances as $deviceId => &$byVlan) {
            ksort($byVlan);
            $this->keys[$deviceId] = array_keys($byVlan);
        }
    }

    /**
     * Index holding the rows of some instances only, usually the one instance per device that
     * carries a VLAN, while it knows every device's other instances by their VLAN key and every
     * bridge MAC: enough for the map overlay and the analysis of one VLAN on a PVST network, whose
     * stp table has a row per switch and VLAN.
     *
     * @param  array<int, int[]>  $keys  device_id => VLAN keys of all its instances
     * @param  iterable<array|object>  $instances  rows of the stp table of the instances to load
     * @param  iterable<array|object>  $ports  rows of the ports_stp table
     * @param  array<int, int>  $lagParent  member port_id => aggregate port_id
     * @param  array<string, int>  $bridges  bridge MAC (12 hex characters) => device_id, of all instances
     */
    public static function partial(array $keys, iterable $instances, iterable $ports, array $lagParent, array $bridges): static
    {
        $index = new static($instances, $ports, $lagParent);
        foreach ($keys as $deviceId => $deviceKeys) {
            $deviceKeys = array_values(array_unique(array_map('intval', $deviceKeys)));
            sort($deviceKeys);
            $index->keys[(int) $deviceId] = $deviceKeys;
        }
        $index->bridges = $bridges + $index->bridges;

        return $index;
    }

    /**
     * The same bridges and instances with other port rows: the background checks load the port
     * rows of one instance (VLAN) at a time.
     */
    public function withPorts(iterable $ports): static
    {
        $index = clone $this;
        $index->ports = [];
        $index->portsByIndex = [];
        $index->addPorts($ports);

        return $index;
    }

    private function addPorts(iterable $ports): void
    {
        foreach ($ports as $row) {
            $row = self::normalizePort((array) $row);
            $this->ports[$row['device_id']][$row['vlan']][$row['port_id']] = $row;
            $this->portsByIndex[$row['device_id']][$row['vlan']][$row['port_index']] = $row;
        }
    }

    /**
     * VLAN key of an stp/ports_stp row: NULL, 0 and 1 (default instance / VLAN 1) become 0.
     */
    public static function vlanKey(mixed $vlan): int
    {
        $vlan = (int) $vlan;

        return $vlan <= 1 ? 0 : $vlan;
    }

    /**
     * Label of a VLAN key for display ("VLAN 1 / CST", "VLAN 20").
     */
    public static function vlanLabel(int $key): string
    {
        return $key === 0 ? 'VLAN 1 / CST' : 'VLAN ' . $key;
    }

    /**
     * stp table row with typed values; MACs as 12 hex characters, times in seconds
     * (LibreNMS already converts BRIDGE-MIB centiseconds).
     */
    public static function normalizeInstance(array $row): array
    {
        return [
            'device_id' => (int) $row['device_id'],
            'vlan' => self::vlanKey($row['vlan'] ?? null),
            'rootBridge' => (bool) ($row['rootBridge'] ?? false),
            'bridgeAddress' => self::mac($row['bridgeAddress'] ?? ''),
            'protocolSpecification' => (string) ($row['protocolSpecification'] ?? 'unknown'),
            'priority' => (int) ($row['priority'] ?? 0),
            'timeSinceTopologyChange' => (int) ($row['timeSinceTopologyChange'] ?? 0),
            'topChanges' => (int) ($row['topChanges'] ?? 0),
            'designatedRoot' => self::mac($row['designatedRoot'] ?? ''),
            'rootCost' => (int) ($row['rootCost'] ?? 0),
            'rootPort' => (int) ($row['rootPort'] ?? 0),
            'maxAge' => (int) ($row['maxAge'] ?? 0),
            'helloTime' => (int) ($row['helloTime'] ?? 0),
            'holdTime' => (int) ($row['holdTime'] ?? 0),
            'forwardDelay' => (int) ($row['forwardDelay'] ?? 0),
            'bridgeMaxAge' => (int) ($row['bridgeMaxAge'] ?? 0),
            'bridgeHelloTime' => (int) ($row['bridgeHelloTime'] ?? 0),
            'bridgeForwardDelay' => (int) ($row['bridgeForwardDelay'] ?? 0),
        ];
    }

    /**
     * ports_stp table row with typed values; state/enable lowercase, MACs as 12 hex characters.
     */
    public static function normalizePort(array $row): array
    {
        return [
            'device_id' => (int) $row['device_id'],
            'vlan' => self::vlanKey($row['vlan'] ?? null),
            'port_id' => (int) $row['port_id'],
            'port_index' => (int) ($row['port_index'] ?? 0),
            'priority' => (int) ($row['priority'] ?? 0),
            'state' => strtolower((string) ($row['state'] ?? 'unknown')),
            'enable' => strtolower((string) ($row['enable'] ?? 'unknown')),
            'pathCost' => (int) ($row['pathCost'] ?? 0),
            'designatedRoot' => self::mac($row['designatedRoot'] ?? ''),
            'designatedCost' => (int) ($row['designatedCost'] ?? 0),
            'designatedBridge' => self::mac($row['designatedBridge'] ?? ''),
            'designatedPort' => (int) ($row['designatedPort'] ?? 0),
            'forwardTransitions' => (int) ($row['forwardTransitions'] ?? 0),
        ];
    }

    /**
     * Normalize a MAC/bridge address to 12 lowercase hex characters ('' when unknown).
     */
    public static function mac(?string $value): string
    {
        $hex = preg_replace('/[^0-9a-f]/', '', strtolower((string) $value));
        if ($hex === null || strlen($hex) < 12) {
            return '';
        }

        $hex = substr($hex, -12);

        return $hex === '000000000000' ? '' : $hex;
    }

    /**
     * aa:bb:cc:dd:ee:ff for display, "?" when unknown.
     */
    public static function formatMac(string $hex): string
    {
        return $hex === '' ? '?' : implode(':', str_split($hex, 2));
    }

    /**
     * Bridge ID as shown by switches: priority.mac (e.g. 4097.00:a0:c9:00:00:01).
     */
    public static function bridgeId(int $priority, string $mac): string
    {
        return $priority . '.' . self::formatMac($mac);
    }

    /**
     * 802.1D blocking or 802.1w discarding.
     */
    public static function isBlocking(?string $state): bool
    {
        return in_array($state, self::BLOCKING_STATES, true);
    }

    /**
     * Device owning a bridge MAC, if that bridge is monitored.
     */
    public function bridgeOwner(string $mac): ?int
    {
        return $this->bridges[$mac] ?? null;
    }

    /**
     * Root bridge MACs that belong to none of the indexed bridges (to be looked up elsewhere:
     * other devices, interface MACs, FDB).
     *
     * @return string[]
     */
    public function unknownRootMacs(): array
    {
        $macs = [];
        foreach ($this->instances as $byVlan) {
            foreach ($byVlan as $instance) {
                if ($instance['designatedRoot'] !== '' && ! isset($this->bridges[$instance['designatedRoot']])) {
                    $macs[$instance['designatedRoot']] = true;
                }
            }
        }

        // numeric looking MACs become int array keys
        return array_map('strval', array_keys($macs));
    }

    /**
     * All port ids with STP rows in the index.
     *
     * @return int[]
     */
    public function portIds(): array
    {
        $ids = [];
        foreach ($this->ports as $byVlan) {
            foreach ($byVlan as $ports) {
                foreach (array_keys($ports) as $portId) {
                    $ids[$portId] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * port_id of the root port of the instance carrying $vlan, null on the root bridge or when unknown.
     */
    public function rootPortId(int $deviceId, ?int $vlan): ?int
    {
        $key = $this->keyFor($deviceId, $vlan);
        $instance = $key !== null ? $this->instance($deviceId, $key) : null;
        if (! $instance || ! $instance['rootPort'] || self::isRoot($instance)) {
            return null;
        }

        return $this->portByIndex($deviceId, $key, $instance['rootPort'])['port_id'] ?? null;
    }

    /**
     * Does the device report any STP instance?
     */
    public function hasStp(int $deviceId): bool
    {
        return ! empty($this->keys[$deviceId]);
    }

    /**
     * @return int[]
     */
    public function deviceIds(): array
    {
        return array_keys($this->keys);
    }

    /**
     * VLAN keys of all instances of a device, lowest first (also those whose rows are not loaded).
     *
     * @return int[]
     */
    public function keysFor(int $deviceId): array
    {
        return $this->keys[$deviceId] ?? [];
    }

    /**
     * @return array<int, array> vlanKey => instance
     */
    public function instancesFor(int $deviceId): array
    {
        return $this->instances[$deviceId] ?? [];
    }

    /**
     * Instance of a device for a VLAN key, null when the device has none.
     */
    public function instance(int $deviceId, int $vlanKey): ?array
    {
        return $this->instances[$deviceId][$vlanKey] ?? null;
    }

    /**
     * All instances grouped by vlan key.
     *
     * @return array<int, array<int, array>> vlanKey => device_id => instance
     */
    public function byVlan(): array
    {
        $result = [];
        foreach ($this->instances as $deviceId => $byVlan) {
            foreach ($byVlan as $key => $instance) {
                $result[$key][$deviceId] = $instance;
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * Which instance of a device controls the given VLAN?
     * PVST devices: the instance of that VLAN. Single instance devices (802.1D/RSTP/MST CIST): that instance.
     */
    public function keyFor(int $deviceId, ?int $vlan): ?int
    {
        return self::selectKey($this->keys[$deviceId] ?? [], $vlan);
    }

    /**
     * keyFor() on a device's VLAN keys.
     *
     * @param  int[]  $keys
     */
    public static function selectKey(array $keys, ?int $vlan): ?int
    {
        if (! $keys) {
            return null;
        }

        $vlanKey = self::vlanKey($vlan);
        if (in_array($vlanKey, $keys, true)) {
            return $vlanKey;
        }

        if ($keys === [0]) {
            return 0; // single common instance covers every VLAN
        }

        return $vlan === null ? min($keys) : null;
    }

    /**
     * @return array<int, array> port_id => port
     */
    public function portsFor(int $deviceId, int $vlanKey): array
    {
        return $this->ports[$deviceId][$vlanKey] ?? [];
    }

    /**
     * Port row by BRIDGE-MIB bridge port number (dot1dStpRootPort refers to it).
     */
    public function portByIndex(int $deviceId, int $vlanKey, int $portIndex): ?array
    {
        return $this->portsByIndex[$deviceId][$vlanKey][$portIndex] ?? null;
    }

    /**
     * STP row for a port, falling back to its LAG (port-channel) where STP actually runs.
     */
    public function portRow(int $deviceId, int $vlanKey, int $portId): ?array
    {
        if (isset($this->ports[$deviceId][$vlanKey][$portId])) {
            return $this->ports[$deviceId][$vlanKey][$portId] + ['via_lag' => false];
        }

        $parent = $this->lagParent[$portId] ?? null;
        if ($parent && isset($this->ports[$deviceId][$vlanKey][$parent])) {
            return $this->ports[$deviceId][$vlanKey][$parent] + ['via_lag' => true];
        }

        return null;
    }

    /**
     * STP state and role of a port for a VLAN (null VLAN = default instance).
     *
     * @return array{vlan: int, state: string, role: string, cost: int, transitions: int, via_lag: bool, designated_bridge: string}|null
     */
    public function portStatus(int $deviceId, ?int $portId, ?int $vlan): ?array
    {
        if (! $portId) {
            return null;
        }

        $key = $this->keyFor($deviceId, $vlan);
        if ($key === null) {
            return null;
        }

        $row = $this->portRow($deviceId, $key, $portId);
        if ($row === null) {
            return null;
        }

        return [
            'vlan' => $key,
            'state' => $row['state'],
            'role' => self::role($this->instance($deviceId, $key), $row),
            'cost' => $row['pathCost'],
            'transitions' => $row['forwardTransitions'],
            'via_lag' => $row['via_lag'],
            'designated_bridge' => $row['designatedBridge'],
        ];
    }

    /**
     * Derive the 802.1D/802.1w port role from BRIDGE-MIB data (the MIB has no role column).
     */
    public static function role(?array $instance, array $port): string
    {
        $state = $port['state'] ?? 'unknown';
        $designatedBridge = $port['designatedBridge'] ?? '';

        if ($instance) {
            $isRoot = $instance['rootBridge'] || ($instance['bridgeAddress'] !== '' && $instance['bridgeAddress'] === $instance['designatedRoot']);

            if (! $isRoot && $instance['rootPort'] > 0 && ($port['port_index'] ?? -1) === $instance['rootPort']) {
                return 'root';
            }

            if (self::isBlocking($state)) {
                return $designatedBridge !== '' && $designatedBridge === $instance['bridgeAddress'] ? 'backup' : 'alternate';
            }

            if ($isRoot && $state === 'forwarding') {
                return 'designated';
            }

            if ($instance['bridgeAddress'] !== '' && $designatedBridge === $instance['bridgeAddress']) {
                return 'designated';
            }
        } elseif (self::isBlocking($state)) {
            return 'alternate';
        }

        return match ($state) {
            'forwarding' => 'nondesignated',
            'disabled' => 'disabled',
            'broken' => 'broken',
            default => 'unknown',
        };
    }

    /**
     * Is the root bridge of this instance the device itself?
     */
    public static function isRoot(array $instance): bool
    {
        return $instance['rootBridge'] || ($instance['bridgeAddress'] !== '' && $instance['bridgeAddress'] === $instance['designatedRoot']);
    }
}
