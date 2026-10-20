<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Redundancy\RedundancyAnalyzer;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * Builds networks for the redundancy tests. campus() is fully redundant: two cores joined by a
 * port-channel, two distribution switches with an uplink to each core, two access switches with
 * an uplink to each distribution switch and an access point behind acc1.
 *
 *        core1 ==Po1== core2
 *          |   \     /   |
 *          |     \ /     |
 *        dist1        dist2
 *          |   \     /   |
 *          |     \ /     |
 *         acc1        acc2
 *          |
 *         ap1
 */
class RedundancyFixture
{
    public const CORE1 = 1;
    public const CORE2 = 2;
    public const DIST1 = 3;
    public const DIST2 = 4;
    public const ACC1 = 5;
    public const ACC2 = 6;
    public const AP1 = 7;

    public array $devices = [];

    public array $ports = [];

    public array $links = [];

    public array $lag = [];

    private int $nextPort = 1000;

    public static function campus(): self
    {
        $net = new self;
        $net->device(self::CORE1, 'core1')->device(self::CORE2, 'core2')->device(self::DIST1, 'dist1')->device(self::DIST2, 'dist2')
            ->device(self::ACC1, 'acc1')->device(self::ACC2, 'acc2')->device(self::AP1, 'ap1', 'wireless');
        $net->lagLink(self::CORE1, 'Port-channel1', ['Te1/1/1', 'Te1/1/2'], self::CORE2, 'Port-channel1', ['Te1/1/1', 'Te1/1/2']);
        $net->link(self::DIST1, 'Te1/1/1', self::CORE1, 'Te1/0/1')->link(self::DIST1, 'Te1/1/2', self::CORE2, 'Te1/0/1');
        $net->link(self::DIST2, 'Te1/1/1', self::CORE1, 'Te1/0/2')->link(self::DIST2, 'Te1/1/2', self::CORE2, 'Te1/0/2');
        $net->link(self::ACC1, 'Gi1/0/49', self::DIST1, 'Gi1/0/1')->link(self::ACC1, 'Gi1/0/50', self::DIST2, 'Gi1/0/1');
        $net->link(self::ACC2, 'Gi1/0/49', self::DIST1, 'Gi1/0/2')->link(self::ACC2, 'Gi1/0/50', self::DIST2, 'Gi1/0/2');
        $net->link(self::AP1, 'eth0', self::ACC1, 'Gi1/0/10');

        return $net;
    }

    /**
     * A monitored device, up.
     */
    public function device(int $id, string $name, string $role = 'switch', array $values = []): self
    {
        $this->devices[$id] = array_merge([
            'device_id' => $id, 'hostname' => "$name.example.net", 'display' => $name, 'sysName' => $name, 'os' => 'ios',
            'role' => $role, 'status' => 1,
        ], $values);

        return $this;
    }

    /**
     * A port of a device, up; returns its port_id.
     */
    public function port(int $deviceId, string $name, array $values = []): int
    {
        $portId = ++$this->nextPort;
        $this->ports[$portId] = array_merge([
            'port_id' => $portId, 'device_id' => $deviceId, 'ifIndex' => $portId, 'ifName' => $name, 'ifDescr' => $name,
            'ifOperStatus' => 'up', 'ifAdminStatus' => 'up', 'ifSpeed' => 10000000000,
        ], $values);

        return $portId;
    }

    /**
     * Port id of a device's interface.
     */
    public function portId(int $deviceId, string $name): int
    {
        foreach ($this->ports as $portId => $port) {
            if ($port['device_id'] === $deviceId && $port['ifName'] === $name) {
                return $portId;
            }
        }

        throw new \InvalidArgumentException("no port $name on device $deviceId");
    }

    /**
     * Change port columns (status...) of a device's interface.
     */
    public function setPort(int $deviceId, string $name, array $values): self
    {
        $portId = $this->portId($deviceId, $name);
        $this->ports[$portId] = array_merge($this->ports[$portId], $values);

        return $this;
    }

    /**
     * A cable between two monitored devices, reported by both ends.
     */
    public function link(int $a, string $aPort, int $b, string $bPort): self
    {
        $pa = $this->port($a, $aPort);
        $pb = $this->port($b, $bPort);
        $this->links[] = self::row($a, $pa, $b, $pb, '', $bPort);
        $this->links[] = self::row($b, $pb, $a, $pa, '', $aPort);

        return $this;
    }

    /**
     * A port-channel between two monitored devices.
     *
     * @param  string[]  $aMembers
     * @param  string[]  $bMembers
     */
    public function lagLink(int $a, string $aAggregate, array $aMembers, int $b, string $bAggregate, array $bMembers): self
    {
        $aParent = $this->port($a, $aAggregate, ['ifType' => 'ieee8023adLag']);
        $bParent = $this->port($b, $bAggregate, ['ifType' => 'ieee8023adLag']);
        foreach ($aMembers as $index => $aName) {
            $this->link($a, $aName, $b, $bMembers[$index]);
            $this->lag[$this->portId($a, $aName)] = $aParent;
            $this->lag[$this->portId($b, $bMembers[$index])] = $bParent;
        }

        return $this;
    }

    /**
     * An LLDP/CDP neighbour LibreNMS does not monitor, seen on a device's port.
     */
    public function neighbor(int $deviceId, string $port, string $host, string $platform = ''): self
    {
        $portId = $this->port($deviceId, $port);
        $this->links[] = self::row($deviceId, $portId, 0, null, $host, 'eth0', $platform);

        return $this;
    }

    /**
     * Remove both directions of the links between two devices.
     */
    public function unlink(int $a, int $b): self
    {
        $this->links = array_values(array_filter($this->links, fn ($l) => ! (in_array($l['local_device_id'], [$a, $b], true) && in_array($l['remote_device_id'], [$a, $b], true))));

        return $this;
    }

    /**
     * @param  int[]|null  $scope  reported devices (default: all)
     */
    public function analyzer(array $settings = [], ?array $scope = null, array $entityUnits = [], array $outageSince = [], array $stpPriority = []): RedundancyAnalyzer
    {
        return new RedundancyAnalyzer(
            $this->devices,
            $scope ?? array_keys($this->devices),
            $this->links,
            $this->ports,
            $this->lag,
            new PluginSettings($settings + ['redundancy_core' => 'core1 core2']),
            $entityUnits,
            $outageSince,
            $stpPriority,
        );
    }

    /**
     * A links table row.
     */
    public static function row(int $ld, int $lp, int $rd, ?int $rp, string $host, string $remotePort, string $platform = ''): array
    {
        return [
            'local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd, 'remote_port_id' => $rp, 'remote_hostname' => $host,
            'remote_port' => $remotePort, 'remote_platform' => $platform, 'remote_version' => '', 'protocol' => 'lldp',
        ];
    }
}
