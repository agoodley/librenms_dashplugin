<?php

namespace Agoodley\LibrenmsTopology\Redundancy;

/**
 * Stack member or line card of ports, from the ENTITY-MIB inventory LibreNMS stores in
 * entPhysical. The unit of a port is the part of the device that takes the port down with it:
 *  - in a stack (Cisco StackWise / StackWise Virtual, Dell, ...) the stack member: the chassis
 *    below the stack entity;
 *  - in a single chassis the line card: the top-most replaceable (FRU) module above the port.
 * Fixed modules (not replaceable) do not count, so a fixed switch has no units.
 */
class PortUnits
{
    /** entPhysicalClass numbers (ENTITY-MIB PhysicalClass) as some OS modules store them. */
    private const CLASSES = [
        '1' => 'other', '2' => 'unknown', '3' => 'chassis', '4' => 'backplane', '5' => 'container', '6' => 'powersupply',
        '7' => 'fan', '8' => 'sensor', '9' => 'module', '10' => 'port', '11' => 'stack', '12' => 'cpu',
    ];

    /** entPhysicalClass values of port entities (repository filter; their ancestors can be of any class). */
    public const PORT_CLASSES = ['port', '10'];

    /**
     * @param  array<int, array>  $rows  entPhysical rows of one device: the port entities and all their
     *                                   ancestors (entPhysicalIndex, entPhysicalContainedIn, entPhysicalClass,
     *                                   entPhysicalIsFRU, entPhysicalName, entPhysicalDescr, ifIndex)
     * @return array<int, array{key: string, label: string}> ifIndex => unit; ports whose entities
     *                                                         disagree are left out
     */
    public static function fromEntities(array $rows): array
    {
        $byIndex = [];
        foreach ($rows as $row) {
            $byIndex[(int) $row['entPhysicalIndex']] = $row;
        }

        $units = [];
        $conflicts = [];
        foreach ($byIndex as $row) {
            $ifIndex = (int) ($row['ifIndex'] ?? 0);
            if ($ifIndex <= 0 || self::className($row) !== 'port') {
                continue;
            }
            $unit = self::unitOf($row, $byIndex);
            if ($unit === null) {
                continue;
            }
            if (isset($units[$ifIndex]) && $units[$ifIndex]['key'] !== $unit['key']) {
                $conflicts[$ifIndex] = true;
            }
            $units[$ifIndex] = $unit;
        }

        return array_diff_key($units, $conflicts);
    }

    /**
     * Unit of one port entity: the stack member, else the top-most replaceable module.
     *
     * @param  array<int, array>  $byIndex  entPhysicalIndex => row
     * @return array{key: string, label: string}|null
     */
    private static function unitOf(array $port, array $byIndex): ?array
    {
        // ancestors from the port up to the root, guarding against loops in broken inventories
        $chain = [];
        $seen = [];
        for ($row = $port; $row !== null && ! isset($seen[(int) $row['entPhysicalIndex']]) && count($chain) < 32;
            $row = $byIndex[(int) ($row['entPhysicalContainedIn'] ?? 0)] ?? null) {
            $seen[(int) $row['entPhysicalIndex']] = true;
            $chain[] = $row;
        }

        $member = null;
        foreach ($chain as $row) {
            $class = self::className($row);
            if ($class === 'chassis') {
                $member = $row; // the chassis closest to the stack wins
            } elseif ($class === 'stack') {
                return $member ? self::unit('member', $member) : null;
            }
        }

        $module = null;
        foreach ($chain as $row) {
            if (self::className($row) === 'module' && self::isFru($row)) {
                $module = $row;
            }
        }

        return $module ? self::unit('module', $module) : null;
    }

    /**
     * @return array{key: string, label: string}
     */
    private static function unit(string $kind, array $row): array
    {
        $label = trim((string) ($row['entPhysicalName'] ?? '')) ?: trim((string) ($row['entPhysicalDescr'] ?? ''));

        return [
            'key' => $kind . (int) $row['entPhysicalIndex'],
            'label' => mb_substr($label !== '' ? $label : $kind . ' ' . (int) $row['entPhysicalIndex'], 0, 60),
        ];
    }

    private static function className(array $row): string
    {
        $class = strtolower(trim((string) ($row['entPhysicalClass'] ?? '')));

        return self::CLASSES[$class] ?? $class;
    }

    /**
     * entPhysicalIsFRU is stored as 'true' / 'false' or as the TruthValue number (1 true, 2 false).
     */
    private static function isFru(array $row): bool
    {
        return in_array(strtolower(trim((string) ($row['entPhysicalIsFRU'] ?? ''))), ['true', '1'], true);
    }
}
