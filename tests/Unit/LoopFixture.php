<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Stp\LoopEvidence;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * Builds networks, port counters and syslog messages for the loop evidence tests.
 */
class LoopFixture
{
    /** The analysis time of every test. */
    public const NOW = 1792500000;

    public array $devices = [];

    public array $ports = [];

    public array $links = [];

    public array $syslog = [];

    private int $nextPort = 100;

    /**
     * A monitored device, up.
     */
    public function device(int $id, string $name, string $role = 'switch', int $status = 1): self
    {
        $this->devices[$id] = ['device_id' => $id, 'hostname' => "$name.example.net", 'display' => $name, 'role' => $role, 'status' => $status];

        return $this;
    }

    /**
     * A port, up and polled five minutes ago, without packet counters; returns its port_id.
     */
    public function port(int $deviceId, string $name, array $values = []): int
    {
        $portId = ++$this->nextPort;
        $this->ports[$portId] = array_merge([
            'port_id' => $portId, 'device_id' => $deviceId, 'ifIndex' => $portId, 'ifName' => $name, 'ifDescr' => $name,
            'ifSpeed' => 1000000000, 'ifOperStatus' => 'up', 'ifInUcastPkts_rate' => null, 'ifOutUcastPkts_rate' => null,
            'poll_time' => self::NOW - 300, 'poll_period' => 300,
            'ifInBroadcastPkts_rate' => null, 'ifOutBroadcastPkts_rate' => null, 'ifInMulticastPkts_rate' => null, 'ifOutMulticastPkts_rate' => null,
        ], $values);

        return $portId;
    }

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
     * A cable between two monitored devices, reported by both ends.
     */
    public function link(int $a, string $aPort, int $b, string $bPort): self
    {
        $pa = $this->port($a, $aPort);
        $pb = $this->port($b, $bPort);
        $this->links[] = self::row($a, $pa, $b, $pb, $this->devices[$b]['display'], $bPort);
        $this->links[] = self::row($b, $pb, $a, $pa, $this->devices[$a]['display'], $aPort);

        return $this;
    }

    /**
     * An LLDP/CDP neighbour LibreNMS does not monitor.
     */
    public function neighbor(int $deviceId, string $port, string $host, string $platform = ''): self
    {
        $portId = $this->port($deviceId, $port);
        $this->links[] = self::row($deviceId, $portId, 0, null, $host, 'eth0', $platform);

        return $this;
    }

    /**
     * Set port columns (packet rates, status, speed...) of a device's interface.
     */
    public function set(int $deviceId, string $port, array $values): self
    {
        $portId = $this->portId($deviceId, $port);
        $this->ports[$portId] = array_merge($this->ports[$portId], $values);

        return $this;
    }

    /**
     * A syslog message received $ago seconds before NOW.
     */
    public function log(int $deviceId, string $program, string $msg, int $ago = 600): self
    {
        $this->syslog[] = [
            'seq' => count($this->syslog) + 1, 'device_id' => $deviceId, 'timestamp' => date('Y-m-d H:i:s', self::NOW - $ago),
            'program' => $program, 'msg' => $msg,
        ];

        return $this;
    }

    public function evidence(array $settings = [], array $context = []): array
    {
        return (new LoopEvidence($this->devices, $this->links, $this->ports, $this->syslog, $this->ports, new PluginSettings($settings), self::NOW))
            ->analyze($context);
    }

    public static function row(int $ld, int $lp, int $rd, ?int $rp, string $host, string $remotePort, string $platform = ''): array
    {
        return [
            'local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd, 'remote_port_id' => $rp, 'remote_hostname' => $host,
            'remote_port' => $remotePort, 'remote_platform' => $platform, 'protocol' => 'lldp',
        ];
    }
}
