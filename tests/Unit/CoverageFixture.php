<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Coverage\CoverageAnalyzer;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * Builds networks for the coverage tests: monitored devices, their ports, LLDP/CDP rows as LibreNMS
 * stores them (between monitored devices or towards neighbours LibreNMS does not monitor), busy
 * ports and the devices the user may see that are not analyzed.
 */
class CoverageFixture
{
    public array $devices = [];

    public array $ports = [];

    public array $links = [];

    public array $lag = [];

    /** @var array<int, array> devices the user may see that are not analyzed (disabled ones) */
    public array $extraKnown = [];

    /** @var array<int, string[]> device_id => addresses on its interfaces */
    public array $addresses = [];

    public array $busy = [];

    /** @var array<int, array<int, bool>> port_id => [vlan => untagged] */
    public array $portVlans = [];

    public array $moduleOff = [];

    /** LLDP/CDP autodiscovery of LibreNMS: on, off or no_nets */
    public string $autodiscovery = 'on';

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
     * An LLDP/CDP neighbour LibreNMS did not link to a device, seen on a device's port. $about is the
     * CDP platform, or for LLDP the system description.
     */
    public function neighbor(int $deviceId, string $port, string $host, string $about = '', string $remotePort = 'Gi0/1', string $protocol = 'lldp', array $values = []): self
    {
        $portId = $this->port($deviceId, $port);
        $this->links[] = array_merge(self::row($deviceId, $portId, 0, null, $host, $remotePort, $about, $protocol), $values);

        return $this;
    }

    /**
     * A port with $macs MAC addresses in the last forwarding table discovery, in the given VLANs.
     */
    public function busy(int $deviceId, string $port, int $macs, array $vlans = [10], array $portValues = []): int
    {
        $portId = $this->port($deviceId, $port, $portValues);
        $this->busy[$portId] = ['device_id' => $deviceId, 'macs' => $macs, 'vlans' => $vlans, 'vlan_count' => count(array_unique($vlans)), 'last_seen' => 1760000000];

        return $portId;
    }

    /**
     * A device the user may see that is not analyzed: a disabled one (devices the user may not see are
     * not passed at all).
     */
    public function known(int $id, string $name, bool $disabled = true): self
    {
        $this->extraKnown[$id] = ['name' => $name, 'hostname' => strtolower($name), 'sysname' => strtolower($name), 'names' => [strtolower($name)], 'disabled' => $disabled];

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
                'hostname' => strtolower($device['hostname']),
                'sysname' => strtolower($device['sysName']),
                'names' => array_merge(array_map('strtolower', [$device['hostname'], $device['sysName'], $device['display']]), $this->addresses[$id] ?? []),
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
     * A links table row as LibreNMS stores it: CDP has a platform, LLDP has none and keeps the
     * system description in remote_version.
     */
    public static function row(int $ld, int $lp, int $rd, ?int $rp, string $host, string $remotePort, string $about = '', string $protocol = 'lldp'): array
    {
        $cdp = strtolower($protocol) === 'cdp';

        return [
            'local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd, 'remote_port_id' => $rp, 'remote_hostname' => $host,
            'remote_port' => $remotePort, 'remote_platform' => $cdp ? $about : null, 'remote_version' => $cdp ? '' : $about, 'protocol' => $protocol,
        ];
    }
}
