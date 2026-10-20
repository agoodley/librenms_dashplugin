<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

/**
 * Small campus: two cores joined by a port-channel, two access switches dual-homed to both cores.
 *
 *        core1 (root) ==Po1== core2
 *          |   \           /   |
 *          |     \       /     |
 *         acc1 ---x     x--- acc2     (x = blocking toward core2)
 */
class CampusFixture
{
    public const MAC = [1 => '00000000c001', 2 => '00000000c002', 3 => '00000000a001', 4 => '00000000a002'];

    public static function devices(): array
    {
        $devices = [];
        foreach ([1 => 'core1', 2 => 'core2', 3 => 'acc1', 4 => 'acc2'] as $id => $name) {
            $devices[$id] = [
                'device_id' => $id,
                'hostname' => $name . '.example.net',
                'display' => $name,
                'sysName' => $name,
                'type' => 'network',
                'os' => 'ios',
                'status' => 1,
                'disabled' => 0,
                'uptime' => 86400 * 10,
                'role' => $id <= 2 ? 'l3switch' : 'switch',
                'url' => "/device/$id",
            ];
        }

        return $devices;
    }

    public static function ports(): array
    {
        $ports = [];
        $add = function (int $portId, int $deviceId, string $name, int $speed = 10000000000, string $oper = 'up') use (&$ports): void {
            $ports[$portId] = [
                'port_id' => $portId, 'device_id' => $deviceId, 'ifIndex' => $portId, 'ifName' => $name, 'ifDescr' => $name,
                'ifAlias' => '', 'ifSpeed' => $speed, 'ifOperStatus' => $oper, 'ifAdminStatus' => 'up', 'ifType' => 'ethernetCsmacd',
                'ifVlan' => '1', 'ifTrunk' => 'dot1Q', 'ifDuplex' => 'fullDuplex', 'ifMtu' => 1500,
                'ifInOctets_rate' => 125000000, 'ifOutOctets_rate' => 12500000, 'ifInErrors_rate' => 0, 'ifOutErrors_rate' => 0,
                'switchport' => true, 'has_ip' => false,
            ];
        };
        // core1
        $add(10, 1, 'Port-channel1', 20000000000);
        $add(11, 1, 'TenGigabitEthernet1/1/1');
        $add(12, 1, 'TenGigabitEthernet1/1/2');
        $add(13, 1, 'TenGigabitEthernet1/0/3');
        $add(14, 1, 'TenGigabitEthernet1/0/4');
        // core2
        $add(20, 2, 'Port-channel1', 20000000000);
        $add(21, 2, 'TenGigabitEthernet1/1/1');
        $add(22, 2, 'TenGigabitEthernet1/1/2');
        $add(23, 2, 'TenGigabitEthernet1/0/3');
        $add(24, 2, 'TenGigabitEthernet1/0/4');
        // access
        $add(31, 3, 'GigabitEthernet1/0/49', 1000000000);
        $add(32, 3, 'GigabitEthernet1/0/50', 1000000000);
        $add(33, 3, 'GigabitEthernet1/0/1', 1000000000);
        $add(41, 4, 'GigabitEthernet1/0/49', 1000000000);
        $add(42, 4, 'GigabitEthernet1/0/50', 1000000000);
        $ports[10]['ifType'] = $ports[20]['ifType'] = 'propVirtual';

        return $ports;
    }

    public static function lagParent(): array
    {
        return [11 => 10, 12 => 10, 21 => 20, 22 => 20];
    }

    /**
     * Both sides report every link (as LibreNMS stores them), plus an LLDP phone on acc1.
     */
    public static function links(): array
    {
        $pairs = [[1, 11, 2, 21], [1, 12, 2, 22], [1, 13, 3, 31], [2, 23, 3, 32], [1, 14, 4, 41], [2, 24, 4, 42]];
        $ports = self::ports();
        $links = [];
        foreach ($pairs as [$ad, $ap, $bd, $bp]) {
            foreach ([[$ad, $ap, $bd, $bp], [$bd, $bp, $ad, $ap]] as [$ld, $lp, $rd, $rp]) {
                $links[] = [
                    'local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd, 'remote_port_id' => $rp,
                    'remote_hostname' => "dev$rd", 'remote_port' => $ports[$rp]['ifName'], 'remote_platform' => 'cisco WS-C3850',
                    'remote_version' => '', 'protocol' => 'cdp',
                ];
            }
        }
        // the same core link is also learned via LLDP
        $links[] = ['local_device_id' => 1, 'local_port_id' => 13, 'remote_device_id' => 3, 'remote_port_id' => 31, 'remote_hostname' => 'acc1',
            'remote_port' => 'Gi1/0/49', 'remote_platform' => '', 'remote_version' => '', 'protocol' => 'lldp'];
        $links[] = ['local_device_id' => 3, 'local_port_id' => 33, 'remote_device_id' => 0, 'remote_port_id' => null, 'remote_hostname' => 'SEP001122334455',
            'remote_port' => 'Port 1', 'remote_platform' => 'Cisco IP Phone 8845', 'remote_version' => '', 'protocol' => 'cdp'];

        return $links;
    }

    public static function portVlans(): array
    {
        return [
            10 => [1 => true, 10 => false, 20 => false], 20 => [1 => true, 10 => false, 20 => false],
            13 => [1 => true, 10 => false], 31 => [1 => true, 10 => false],
            23 => [1 => true, 10 => false], 32 => [1 => true, 10 => false],
            14 => [1 => true, 20 => false], 41 => [1 => true, 20 => false],
            24 => [1 => true, 20 => false], 42 => [1 => true, 20 => false],
        ];
    }

    public static function stpInstances(array $overrides = []): array
    {
        $root = self::MAC[1];
        $rows = [
            1 => ['priority' => 4096, 'rootPort' => 0, 'rootCost' => 0],
            2 => ['priority' => 8192, 'rootPort' => 20, 'rootCost' => 2],
            3 => ['priority' => 32768, 'rootPort' => 31, 'rootCost' => 4],
            4 => ['priority' => 32768, 'rootPort' => 41, 'rootCost' => 4],
        ];
        $result = [];
        foreach ($rows as $deviceId => $row) {
            $result[] = array_merge([
                'device_id' => $deviceId, 'vlan' => null, 'rootBridge' => $deviceId === 1 ? 1 : 0, 'bridgeAddress' => self::MAC[$deviceId],
                'protocolSpecification' => 'rapidPvstPlus', 'timeSinceTopologyChange' => 86400, 'topChanges' => 3, 'designatedRoot' => $root,
                'maxAge' => 20, 'helloTime' => 2, 'holdTime' => 1, 'forwardDelay' => 15, 'bridgeMaxAge' => 20, 'bridgeHelloTime' => 2,
                'bridgeForwardDelay' => 15,
            ], $row, $overrides[$deviceId] ?? []);
        }

        return $result;
    }

    public static function stpPorts(array $overrides = []): array
    {
        $designatedBy = [10 => 1, 13 => 1, 14 => 1, 20 => 1, 23 => 2, 24 => 2, 31 => 1, 32 => 2, 41 => 1, 42 => 2];
        $state = [32 => 'blocking', 42 => 'blocking'];
        $device = [10 => 1, 13 => 1, 14 => 1, 20 => 2, 23 => 2, 24 => 2, 31 => 3, 32 => 3, 33 => 3, 41 => 4, 42 => 4];
        $designatedBy[33] = 3;
        $rows = [];
        foreach ($device as $portId => $deviceId) {
            $rows[] = array_merge([
                'device_id' => $deviceId, 'vlan' => null, 'port_id' => $portId, 'port_index' => $portId, 'priority' => 128,
                'state' => $state[$portId] ?? 'forwarding', 'enable' => 'enabled', 'pathCost' => 2, 'designatedRoot' => self::MAC[1],
                'designatedCost' => 0, 'designatedBridge' => self::MAC[$designatedBy[$portId]], 'designatedPort' => $portId,
                'forwardTransitions' => 1,
            ], $overrides[$portId] ?? []);
        }

        return $rows;
    }
}
