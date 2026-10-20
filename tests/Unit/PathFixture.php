<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Path\L2Tracer;
use Agoodley\LibrenmsTopology\Path\L3Tracer;
use Agoodley\LibrenmsTopology\Stp\StpIndex;

/**
 * A routed campus for the path tracers, built up in plain arrays like the repositories return them.
 *
 * Layer 2: dist1 and dist2 (L3 switches) joined by Po10 (2 x 10G), acc1 uplinked to both (Gi1/0/49 to dist1, Gi1/0/50
 * to dist2), acc2 on dist1 by Po1 (2 x 10G) and on dist2 by Gi1/0/51; PVST with dist1 the root of VLAN 110 and dist2
 * the root of VLAN 120, so acc1 and acc2 block their uplink toward the other distribution switch in each VLAN.
 * rtr1 hangs off dist1 Te1/0/48 by a routed /31. Hosts: acc1 Gi1/0/5 and acc2 Gi1/0/6 in VLAN 110.
 *
 * Layer 3: dist1 Vlan110 10.90.110.2/24, dist2 Vlan110 10.90.110.3/24, the /31s dist1-rtr1 10.90.0.0/31 and
 * dist2-rtr1 10.90.0.2/31, rtr1-rtr3 10.90.0.4/31, rtr3-rtr2 10.90.0.6/31, rtr2 Gi0/0/1.130 10.90.130.1/24 on the trunk to acc3
 * (host port Gi1/0/3), and "remote" (198.51.100.10) behind rtr1's default route to the internet (203.0.113.1).
 */
class PathFixture
{
    public const DIST1 = 1;

    public const DIST2 = 2;

    public const ACC1 = 3;

    public const ACC2 = 4;

    public const RTR1 = 5;

    public const RTR2 = 6;

    public const RTR3 = 7;

    public const ACC3 = 8;

    public const REMOTE = 9;

    public const NOW = 1_800_000_000;

    public array $devices = [];

    public array $ports = [];

    public array $links = [];

    public array $lag = [];

    public array $portVlans = [];

    public array $routed = [];

    public array $instances = [];

    public array $stpPorts = [];

    public array $interfaces = [];

    public array $routes = [];

    public array $routeData = [];

    public array $adjacencies = [];

    public static function campus(): self
    {
        $net = new self;
        $net->device(self::DIST1, 'dist1', 'l3switch')->device(self::DIST2, 'dist2', 'l3switch')
            ->device(self::ACC1, 'acc1', 'switch')->device(self::ACC2, 'acc2', 'switch')
            ->device(self::RTR1, 'rtr1', 'router')->device(self::RTR2, 'rtr2', 'router')->device(self::RTR3, 'rtr3', 'router');

        // dist1 Po10 = Te1/0/1 + Te1/0/2 <-> dist2 Po10
        $net->port(100, self::DIST1, 'Po10', 20, 'ieee8023adLag')->port(101, self::DIST1, 'Te1/0/1')->port(102, self::DIST1, 'Te1/0/2');
        $net->port(200, self::DIST2, 'Po10', 20, 'ieee8023adLag')->port(201, self::DIST2, 'Te1/0/1')->port(202, self::DIST2, 'Te1/0/2');
        $net->member(101, 100)->member(102, 100)->member(201, 200)->member(202, 200);
        $net->link(101, 201)->link(102, 202);
        // acc1 Gi1/0/49 -> dist1 Te1/0/11, Gi1/0/50 -> dist2 Te1/0/11 (1G)
        $net->port(111, self::DIST1, 'Te1/0/11')->port(211, self::DIST2, 'Te1/0/11', 1);
        $net->port(301, self::ACC1, 'Gi1/0/49')->port(302, self::ACC1, 'Gi1/0/50', 1)->port(305, self::ACC1, 'Gi1/0/5', 1, 'ethernetCsmacd', '110');
        $net->link(301, 111)->link(302, 211);
        // acc2 Po1 = Gi1/0/49 + Gi1/0/50 -> dist1 Po21; Gi1/0/51 -> dist2 Te1/0/12
        $net->port(120, self::DIST1, 'Po21', 20, 'ieee8023adLag')->port(121, self::DIST1, 'Te1/0/21')->port(122, self::DIST1, 'Te1/0/22');
        $net->port(400, self::ACC2, 'Po1', 20, 'ieee8023adLag')->port(401, self::ACC2, 'Gi1/0/49')->port(402, self::ACC2, 'Gi1/0/50');
        $net->port(403, self::ACC2, 'Gi1/0/51')->port(212, self::DIST2, 'Te1/0/12')->port(406, self::ACC2, 'Gi1/0/6', 1, 'ethernetCsmacd', '110');
        $net->member(121, 120)->member(122, 120)->member(401, 400)->member(402, 400);
        $net->link(401, 121)->link(402, 122)->link(403, 212);
        // rtr1 Gi0/0/0 <-> dist1 Te1/0/48 (routed), rtr1 Gi0/0/1 <-> dist2 Te1/0/48
        $net->port(148, self::DIST1, 'Te1/0/48')->port(501, self::RTR1, 'Gi0/0/0', 1)->port(248, self::DIST2, 'Te1/0/48')->port(502, self::RTR1, 'Gi0/0/1', 1);
        $net->link(148, 501)->link(248, 502);
        $net->routed = [148 => true, 501 => true, 248 => true, 502 => true];

        foreach ([100, 200, 111, 211, 301, 302, 120, 400, 403, 212] as $trunk) {
            $net->portVlans[$trunk] = [1 => true, 110 => false, 120 => false];
        }
        $net->portVlans[305] = [110 => true];
        $net->portVlans[406] = [110 => true];

        // PVST: dist1 roots VLAN 110, dist2 VLAN 120
        foreach ([110 => self::DIST1, 120 => self::DIST2] as $vlan => $root) {
            foreach ([self::DIST1, self::DIST2, self::ACC1, self::ACC2] as $deviceId) {
                $net->instances[] = ['device_id' => $deviceId, 'vlan' => $vlan, 'rootBridge' => $deviceId === $root ? 1 : 0, 'bridgeAddress' => sprintf('00aa%02d%06d', $deviceId, $vlan),
                    'designatedRoot' => sprintf('00aa%02d%06d', $root, $vlan), 'rootPort' => 0, 'priority' => $deviceId === $root ? 4096 : 32768];
            }
            $blocked = $vlan === 110 ? [302, 403] : [301, 400];
            foreach ([100, 200, 111, 211, 301, 302, 120, 400, 403, 212, 305, 406] as $portId) {
                $net->stpPorts[] = ['device_id' => $net->ports[$portId]['device_id'], 'vlan' => $vlan, 'port_id' => $portId, 'port_index' => $portId,
                    'state' => in_array($portId, $blocked, true) ? 'blocking' : 'forwarding', 'enable' => 'enabled', 'designatedBridge' => ''];
            }
        }

        // addresses
        $net->address(self::DIST1, 1110, 'Vlan110', '10.90.110.2', 24)->address(self::DIST2, 2110, 'Vlan110', '10.90.110.3', 24)
            ->address(self::DIST1, 148, null, '10.90.0.1', 31)->address(self::RTR1, 501, null, '10.90.0.0', 31)
            ->address(self::DIST2, 248, null, '10.90.0.3', 31)->address(self::RTR1, 502, null, '10.90.0.2', 31)
            ->address(self::RTR1, 503, 'Gi0/0/2', '10.90.0.4', 31)->address(self::RTR3, 701, 'Gi0/0/0', '10.90.0.5', 31)
            ->address(self::RTR3, 702, 'Gi0/0/1', '10.90.0.6', 31)->address(self::RTR2, 601, 'Gi0/0/0', '10.90.0.7', 31)
            ->address(self::RTR2, 6130, 'Gi0/0/1.130', '10.90.130.1', 24)->address(self::RTR1, 599, 'Lo0', '10.255.0.1', 32);

        // routes: dist1 / dist2 default to rtr1; rtr1 to the branch via rtr3, ECMP back to VLAN 110; rtr3 has none; rtr2 defaults to rtr3
        $net->route(self::DIST1, '0.0.0.0', 0, '10.90.0.0', 148, 'static')->route(self::DIST2, '0.0.0.0', 0, '10.90.0.2', 248, 'static')
            ->route(self::RTR1, '10.90.130.0', 24, '10.90.0.5', 503, 'ospf', 20)
            ->route(self::RTR1, '10.90.130.0', 24, '10.90.0.99', 503, 'ospf', 10, self::NOW - 3 * 86400) // kept history: ignored
            ->route(self::RTR1, '10.90.110.0', 24, '10.90.0.3', 502, 'ospf', 20)->route(self::RTR1, '10.90.110.0', 24, '10.90.0.1', 501, 'ospf', 20)
            ->route(self::RTR1, '0.0.0.0', 0, '203.0.113.1', 504, 'static')
            ->route(self::RTR2, '0.0.0.0', 0, '10.90.0.6', 601, 'static')->route(self::RTR2, '10.90.130.0', 24, '0.0.0.0', 6130, 'local', 0, null, 3);
        // acc3 on rtr2's trunk Gi0/0/1 (VLAN 130 on its sub-interface), and a router behind the internet
        $net->device(self::ACC3, 'acc3', 'switch')->device(self::REMOTE, 'remote', 'router');
        $net->port(610, PathFixture::RTR2, 'Gi0/0/1', 1)->port(801, self::ACC3, 'Gi1/0/49', 1)->port(803, self::ACC3, 'Gi1/0/3', 1, 'ethernetCsmacd', '130');
        $net->link(610, 801);
        $net->portVlans += [610 => [1 => true, 130 => false], 801 => [1 => true, 130 => false], 803 => [130 => true]];
        $net->address(self::REMOTE, 901, 'Gi0/0/0', '198.51.100.1', 30)->address(self::REMOTE, 999, 'Lo0', '198.51.100.10', 32);
        $net->adjacencies[] = ['device_id' => self::RTR3, 'port_id' => 702, 'local_ip' => null, 'neighbor_ip' => '10.90.0.7', 'kind' => 'ospf', 'vrf' => ''];
        $net->adjacencies[] = ['device_id' => self::RTR3, 'port_id' => 701, 'local_ip' => null, 'neighbor_ip' => '10.90.0.4', 'kind' => 'ospf', 'vrf' => ''];

        return $net;
    }

    public function device(int $id, string $name, string $role): self
    {
        $this->devices[$id] = ['device_id' => $id, 'hostname' => $name . '.example.net', 'display' => $name, 'role' => $role];

        return $this;
    }

    public function port(int $id, int $deviceId, string $name, int $gbit = 10, string $type = 'ethernetCsmacd', ?string $vlan = null): self
    {
        $this->ports[$id] = ['port_id' => $id, 'device_id' => $deviceId, 'ifName' => $name, 'ifDescr' => $name, 'ifAlias' => '', 'ifType' => $type,
            'ifSpeed' => $gbit * 1_000_000_000, 'ifOperStatus' => 'up', 'ifAdminStatus' => 'up', 'ifDuplex' => 'fullDuplex', 'ifVlan' => $vlan,
            'ifTrunk' => $vlan === null ? 'dot1Q' : null, 'ifInOctets_rate' => 0, 'ifOutOctets_rate' => 0];

        return $this;
    }

    public function member(int $member, int $aggregate): self
    {
        $this->lag[$member] = $aggregate;

        return $this;
    }

    /**
     * An LLDP link reported by both ends.
     */
    public function link(int $a, int $b): self
    {
        foreach ([[$a, $b], [$b, $a]] as [$local, $remote]) {
            $this->links[] = ['local_device_id' => $this->ports[$local]['device_id'], 'local_port_id' => $local, 'remote_device_id' => $this->ports[$remote]['device_id'],
                'remote_port_id' => $remote, 'remote_hostname' => $this->devices[$this->ports[$remote]['device_id']]['display'], 'remote_port' => $this->ports[$remote]['ifName'],
                'remote_platform' => '', 'remote_version' => '', 'protocol' => 'lldp'];
        }

        return $this;
    }

    /**
     * A link reported by one end only, toward a neighbor that is not monitored (or, with HIDDEN . n, out of the user's sight).
     */
    public function neighbor(int $local, string $name, string $port = 'eth0', string $platform = ''): self
    {
        $this->links[] = ['local_device_id' => $this->ports[$local]['device_id'], 'local_port_id' => $local, 'remote_device_id' => 0, 'remote_port_id' => null,
            'remote_hostname' => $name, 'remote_port' => $port, 'remote_platform' => $platform, 'remote_version' => '', 'protocol' => 'lldp'];

        return $this;
    }

    public function address(int $deviceId, int $portId, ?string $name, string $address, int $prefix, string $vrf = '', bool $up = true): self
    {
        $this->interfaces[] = ['device_id' => $deviceId, 'port_id' => $portId, 'address' => $address, 'prefixlen' => $prefix, 'vrf' => $vrf, 'up' => $up];
        if ($name !== null && ! isset($this->ports[$portId])) {
            $this->port($portId, $deviceId, $name, 1, str_starts_with($name, 'Vlan') ? 'l3ipvlan' : 'ethernetCsmacd');
        }

        return $this;
    }

    public function route(int $deviceId, string $dest, int $length, string $nextHop, int $portId, string $protocol, int $metric = 0, ?int $updated = null,
        int $type = 4, string $context = ''): self
    {
        $proto = ['local' => 2, 'static' => 3, 'ospf' => 13, 'bgp' => 14][$protocol];
        $this->routes[$deviceId][] = ['context' => $context, 'dest' => $dest, 'pfxlen' => $length, 'next_hop' => $nextHop, 'port_id' => $portId,
            'type' => $type, 'proto' => $proto, 'metric' => $metric, 'updated' => $updated ?? self::NOW];
        $this->routeData[$deviceId] ??= ['count' => 0, 'newest' => 0, 'contexts' => []];
        $this->routeData[$deviceId]['count']++;
        $this->routeData[$deviceId]['newest'] = max($this->routeData[$deviceId]['newest'], $updated ?? self::NOW);
        $this->routeData[$deviceId]['contexts'][$context] = ($this->routeData[$deviceId]['contexts'][$context] ?? 0) + 1;

        return $this;
    }

    public function stp(?int $vlan): StpIndex
    {
        $selector = new StpIndex($this->instances);
        $rows = [];
        foreach ($this->stpPorts as $row) {
            if ($selector->keyFor($row['device_id'], $vlan) === StpIndex::vlanKey($row['vlan'])) {
                $rows[] = $row;
            }
        }

        return new StpIndex($this->instances, $rows, $this->lag);
    }

    public function l2(?int $vlan): L2Tracer
    {
        return new L2Tracer($this->devices, $this->links, $this->ports, $this->lag, $this->stp($vlan), $this->portVlans, $this->routed);
    }

    public function l3(bool $skipped = false): L3Tracer
    {
        $routing = array_filter($this->devices, fn ($device) => in_array($device['role'], ['router', 'l3switch', 'firewall'], true));

        return new L3Tracer(array_values(array_filter($this->interfaces, fn ($interface) => isset($routing[$interface['device_id']]))), $this->routes, $this->routeData,
            $this->adjacencies, array_map(fn ($device) => $device['role'], $this->devices), array_map(fn ($device) => $device['display'], $this->devices),
            $this->linked(), fn (string $address) => array_values(array_filter($this->interfaces, fn ($interface) => $interface['address'] === $address)), $skipped);
    }

    /**
     * Devices joined by LLDP/CDP on a port (or its port-channel): device_id => their port.
     */
    public function linked(): \Closure
    {
        return function (int $deviceId, int $portId): array {
            $ports = [$portId => true] + (isset($this->lag[$portId]) ? [$this->lag[$portId] => true] : []);
            $found = [];
            foreach ($this->links as $link) {
                if ($link['local_device_id'] === $deviceId && isset($ports[$link['local_port_id']]) && $link['remote_device_id']) {
                    $found[$link['remote_device_id']] = $link['remote_port_id'];
                }
            }

            return $found;
        };
    }

    /**
     * Device names of a trace's hops.
     */
    public static function names(array $trace): array
    {
        return array_map(fn ($hop) => $hop['name'], $trace['hops']);
    }
}
