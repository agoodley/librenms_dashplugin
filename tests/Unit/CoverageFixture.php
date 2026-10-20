<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Coverage\CoverageAnalyzer;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * Builds networks for the coverage tests: monitored devices, their ports, LLDP/CDP rows (between
 * monitored devices or towards neighbours LibreNMS does not monitor), busy ports and the devices
 * LibreNMS knows by name.
 */
class CoverageFixture
{
    public array $devices = [];

    public array $ports = [];

    public array $links = [];

    public array $lag = [];

    /** @var array<int, array> known devices that are not analyzable (disabled, or not visible to the user) */
    public array $extraKnown = [];

    /** @var array<int, string[]> device_id => addresses on its interfaces */
    public array $addresses = [];

    public array $busy = [];

    /** @var array<int, array<int, bool>> port_id => [vlan => untagged] */
    public array $portVlans = [];

    public array $moduleOff = [];

    public bool $autodiscovery = true;

    private int $nextPort = 5000;

    /**
     * A monitored device, up.
     */
    public function device(int $id, string $name, string $role = 'switch', array $values = []): self
    {
        $this->devices[$id] = array_merge([
            'device_id' => $id, 'hostname' => "$name.example.net", 'sysName' => $name, 'display' => $name, 'os' => 'ios',
            'type' => in_array($role, ['switch', 'l3switch', 'router'], true) ? 'network' : $role, 'role' => $role, 'status' => 1,
        ], $values);

        return $this;
    }

    /**
     * A port of a device; returns its port_id (an existing port of that name is reused).
     */
    public function port(int $deviceId, string $name, array $values = []): int
    {
        foreach ($this->ports as $portId => $port) {
            if ($port['device_id'] === $deviceId && $port['ifName'] === $name) {
                $this->ports[$portId] = array_merge($port, $values);

                return $portId;
            }
        }
        $portId = ++$this->nextPort;
        $this->ports[$portId] = array_merge([
            'port_id' => $portId, 'device_id' => $deviceId, 'ifIndex' => $portId, 'ifName' => $name, 'ifDescr' => $name,
            'ifAlias' => '', 'ifType' => 'ethernetCsmacd', 'ifTrunk' => null, 'ifOperStatus' => 'up',
        ], $values);

        return $portId;
    }

    /**
     * A cable between two monitored devices, reported by $a and, unless $bReports is false, by $b.
     */
    public function link(int $a, string $aPort, int $b, string $bPort, bool $bReports = true): self
    {
        $pa = $this->port($a, $aPort);
        $pb = $this->port($b, $bPort);
        $this->links[] = self::row($a, $pa, $b, $pb, $this->devices[$b]['sysName'] ?? "device$b", $bPort);
        if ($bReports) {
            $this->links[] = self::row($b, $pb, $a, $pa, $this->devices[$a]['sysName'] ?? "device$a", $aPort);
        }

        return $this;
    }

    /**
     * An LLDP/CDP neighbour LibreNMS did not link to a device, seen on a device's port.
     */
    public function neighbor(int $deviceId, string $port, string $host, string $platform = '', string $remotePort = 'Gi0/1', string $protocol = 'lldp', array $values = []): self
    {
        $portId = $this->port($deviceId, $port);
        $this->links[] = array_merge(self::row($deviceId, $portId, 0, null, $host, $remotePort, $platform, $protocol), $values);

        return $this;
    }

    /**
     * A port with $macs MAC addresses in the forwarding table.
     */
    public function busy(int $deviceId, string $port, int $macs, array $vlans = [10], array $portValues = []): int
    {
        $portId = $this->port($deviceId, $port, $portValues);
        $this->busy[$portId] = ['device_id' => $deviceId, 'macs' => $macs, 'vlans' => $vlans, 'last_seen' => 1760000000];

        return $portId;
    }

    /**
     * A device LibreNMS knows that is not analyzed: disabled, or not visible to the user.
     */
    public function known(int $id, string $name, bool $visible = true, bool $disabled = false): self
    {
        $this->extraKnown[$id] = ['name' => $visible ? $name : '', 'names' => [strtolower($name)], 'visible' => $visible, 'disabled' => $disabled];

        return $this;
    }

    /**
     * @param  int[]|null  $scope  analyzed devices (default: all)
     */
    public function analyzer(array $settings = [], ?array $scope = null): CoverageAnalyzer
    {
        $known = $this->extraKnown;
        foreach ($this->devices as $id => $device) {
            $known[$id] = [
                'name' => $device['display'],
                'names' => array_merge(array_map('strtolower', [$device['hostname'], $device['sysName'], $device['display']]), $this->addresses[$id] ?? []),
                'visible' => true,
                'disabled' => false,
            ];
        }

        return new CoverageAnalyzer(
            $this->devices,
            $scope ?? array_keys($this->devices),
            $this->links,
            $this->ports,
            $this->lag,
            $known,
            $this->busy,
            $this->portVlans,
            $this->moduleOff,
            $this->autodiscovery,
            new PluginSettings($settings),
        );
    }

    /**
     * A links table row.
     */
    public static function row(int $ld, int $lp, int $rd, ?int $rp, string $host, string $remotePort, string $platform = '', string $protocol = 'lldp'): array
    {
        return [
            'local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd, 'remote_port_id' => $rp, 'remote_hostname' => $host,
            'remote_port' => $remotePort, 'remote_platform' => $platform, 'remote_version' => '', 'protocol' => $protocol, 'hidden' => false,
        ];
    }
}
