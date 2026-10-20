<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

/**
 * Consistent campus for the link checks: two cores joined by a port-channel and two access
 * switches with one uplink to each core. Every link runs 10G full duplex with MTU 9216, every
 * trunk uses native VLAN 1 and every port description names its neighbour. acc1 uses VLAN 10,
 * acc2 VLAN 20, the cores route both.
 *
 *        core1 ==Po1== core2
 *          |   \     /   |
 *          |     \ /     |
 *          |     / \     |
 *         acc1        acc2
 */
class LinkFixture
{
    /** port_id => [device, ifName, description] */
    private const PORTS = [
        10 => [1, 'Port-channel1', 'core2 Po1'],
        11 => [1, 'TenGigabitEthernet1/1/1', 'core2 Te1/1/1'],
        12 => [1, 'TenGigabitEthernet1/1/2', 'core2 Te1/1/2'],
        13 => [1, 'TenGigabitEthernet1/0/3', 'acc1 Te1/1/1'],
        14 => [1, 'TenGigabitEthernet1/0/4', 'acc2 Te1/1/1'],
        20 => [2, 'Port-channel1', 'core1 Po1'],
        21 => [2, 'TenGigabitEthernet1/1/1', 'core1 Te1/1/1'],
        22 => [2, 'TenGigabitEthernet1/1/2', 'core1 Te1/1/2'],
        23 => [2, 'TenGigabitEthernet1/0/3', 'acc1 Te1/1/2'],
        24 => [2, 'TenGigabitEthernet1/0/4', 'acc2 Te1/1/2'],
        31 => [3, 'TenGigabitEthernet1/1/1', 'core1 Te1/0/3'],
        32 => [3, 'TenGigabitEthernet1/1/2', 'core2 Te1/0/3'],
        41 => [4, 'TenGigabitEthernet1/1/1', 'core1 Te1/0/4'],
        42 => [4, 'TenGigabitEthernet1/1/2', 'core2 Te1/0/4'],
    ];

    public static function devices(): array
    {
        $devices = [];
        foreach ([1 => 'core1', 2 => 'core2', 3 => 'acc1', 4 => 'acc2'] as $id => $name) {
            $devices[$id] = [
                'device_id' => $id, 'hostname' => "$name.example.net", 'display' => $name, 'sysName' => $name, 'os' => 'ios',
                'role' => $id <= 2 ? 'l3switch' : 'switch',
            ];
        }

        return $devices;
    }

    /**
     * @param  array<int, array>  $overrides  port_id => column values
     */
    public static function ports(array $overrides = []): array
    {
        $ports = [];
        foreach (self::PORTS as $portId => [$deviceId, $name, $alias]) {
            $ports[$portId] = self::port($portId, $deviceId, $name, ($overrides[$portId] ?? []) + ['ifAlias' => $alias]);
        }

        return $ports;
    }

    /**
     * A port row as TopologyRepository::ports() returns it (plus the has_ip flag), up and healthy.
     */
    public static function port(int $portId, int $deviceId, string $name, array $values = []): array
    {
        $aggregate = str_starts_with($name, 'Port-channel');

        return array_merge([
            'port_id' => $portId, 'device_id' => $deviceId, 'ifIndex' => $portId, 'ifName' => $name, 'ifDescr' => $name, 'ifAlias' => '',
            'ifSpeed' => $aggregate ? 20000000000 : 10000000000, 'ifOperStatus' => 'up', 'ifAdminStatus' => 'up',
            'ifType' => $aggregate ? 'ieee8023adLag' : 'ethernetCsmacd', 'ifVlan' => '1', 'ifTrunk' => 'dot1Q', 'ifDuplex' => 'fullDuplex',
            'ifMtu' => 9216, 'ifInOctets_rate' => 125000000, 'ifOutOctets_rate' => 125000000, 'ifInErrors_delta' => 0, 'ifOutErrors_delta' => 0,
            'ifInUcastPkts_delta' => 1000000, 'ifOutUcastPkts_delta' => 1000000, 'poll_period' => 300, 'has_ip' => false,
        ], $values);
    }

    public static function lagParent(): array
    {
        return [11 => 10, 12 => 10, 21 => 20, 22 => 20];
    }

    /**
     * Both ends report every link, as LibreNMS stores LLDP/CDP neighbours.
     */
    public static function links(): array
    {
        $links = [];
        foreach ([[1, 11, 2, 21], [1, 12, 2, 22], [1, 13, 3, 31], [2, 23, 3, 32], [1, 14, 4, 41], [2, 24, 4, 42]] as [$ad, $ap, $bd, $bp]) {
            $links[] = self::link($ad, $ap, $bd, $bp);
            $links[] = self::link($bd, $bp, $ad, $ap);
        }

        return $links;
    }

    /**
     * A links table row; remote device 0 is a neighbour LibreNMS does not monitor.
     */
    public static function link(int $ld, int $lp, int $rd, ?int $rp, string $host = '', string $remotePort = '', string $platform = ''): array
    {
        return [
            'local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd, 'remote_port_id' => $rp, 'remote_hostname' => $host,
            'remote_port' => $remotePort, 'remote_platform' => $platform, 'remote_version' => '', 'protocol' => 'lldp',
        ];
    }

    /**
     * port_id => [vlan => untagged]; an override replaces the VLANs of that port.
     */
    public static function portVlans(array $overrides = []): array
    {
        return array_replace([
            10 => [1 => true, 10 => false, 20 => false], 20 => [1 => true, 10 => false, 20 => false],
            13 => [1 => true, 10 => false], 31 => [1 => true, 10 => false],
            23 => [1 => true, 10 => false], 32 => [1 => true, 10 => false],
            14 => [1 => true, 20 => false], 41 => [1 => true, 20 => false],
            24 => [1 => true, 20 => false], 42 => [1 => true, 20 => false],
        ], $overrides);
    }

    /**
     * VLANs in use: the cores route VLAN 10 and 20, acc1 has hosts in VLAN 10, acc2 in VLAN 20.
     */
    public static function activeVlans(): array
    {
        return [1 => [10 => true, 20 => true], 2 => [10 => true, 20 => true], 3 => [10 => true], 4 => [20 => true]];
    }
}
