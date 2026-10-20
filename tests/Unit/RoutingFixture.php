<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Routing\RoutingAnalyzer;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * Builds routed networks for the routing tests. network() is healthy: three routers in OSPF area 0
 * (a triangle of /30 links, each with an LLDP link), r4 behind r3 in area 1, iBGP between the
 * loopbacks of r1 and r2 (AS 65000) and an eBGP session from r1 to an ISP (AS 64500) with 800
 * prefixes of a 2,000 limit.
 *
 *          r1 ---- r2         r1 -- ISP 203.0.113.1 (AS 64500)
 *            \    /
 *             r3 ---- r4 (area 1)
 */
class RoutingFixture
{
    public array $devices = [];

    public array $addresses = [];

    public array $ospf = ['instances' => [], 'areas' => [], 'ports' => [], 'neighbors' => []];

    public array $bgp = ['peers' => [], 'prefixes' => []];

    /** @var list<array> LLDP/CDP links (rows of the links table) */
    public array $links = [];

    /** @var array<int, array<string, ?int>> device => its VRF names => the VRF's own local AS */
    public array $vrfs = [];

    private int $nextPort = 100;

    public static function network(): self
    {
        $net = new self;
        foreach ([1 => 'r1', 2 => 'r2', 3 => 'r3', 4 => 'r4'] as $id => $name) {
            $net->router($id, $name)->address($id, 'Loopback0', "$id.$id.$id.$id/32", '', ['ifType' => 'softwareLoopback']);
            $net->ospfRouter($id, "$id.$id.$id.$id");
        }
        $net->ospfLink(1, 'Te0/0/1', 2, 'Te0/0/1', '10.0.12.0/30');
        $net->ospfLink(1, 'Te0/0/2', 3, 'Te0/0/1', '10.0.13.0/30');
        $net->ospfLink(2, 'Te0/0/2', 3, 'Te0/0/2', '10.0.23.0/30');
        $net->ospfLink(3, 'Te0/0/3', 4, 'Te0/0/1', '10.1.34.0/30', '0.0.0.1');
        foreach ([1, 2, 3] as $id) {
            $net->area($id, '0.0.0.0', 12, 400000);
        }
        $net->area(3, '0.0.0.1', 5, 90000)->area(4, '0.0.0.1', 5, 90000);

        $net->bgpPeer(1, '2.2.2.2', 65000, ['bgpLocalAddr' => '1.1.1.1'])->bgpPeer(2, '1.1.1.1', 65000, ['bgpLocalAddr' => '2.2.2.2']);
        $net->address(1, 'Gi0/0/0', '203.0.113.2/30');
        $net->bgpPeer(1, '203.0.113.1', 64500, ['bgpPeerDescr' => 'ISP-A'])->prefixes(1, '203.0.113.1', 800, 2000);

        return $net;
    }

    /**
     * A monitored router, up for 60 days.
     */
    public function router(int $id, string $name, ?int $as = 65000, array $values = []): self
    {
        $this->devices[$id] = array_merge([
            'device_id' => $id, 'hostname' => "$name.example.net", 'display' => $name, 'sysName' => $name, 'status' => 1,
            'uptime' => 86400 * 60, 'bgpLocalAs' => $as,
        ], $values);

        return $this;
    }

    /**
     * An interface address ("10.0.12.1/30") on a new port (or on the port $values['port_id']); returns
     * the port_id. A VRF is added to the device's VRFs.
     */
    public function address(int $device, string $port, string $cidr, string $vrf = '', array $values = []): int
    {
        [$ip, $prefix] = explode('/', $cidr);
        $portId = $values['port_id'] ?? ++$this->nextPort;
        if ($vrf !== '') {
            $this->vrfs[$device][$vrf] ??= null;
        }
        $this->addresses[] = array_merge([
            'device_id' => $device, 'port_id' => $portId, 'ifName' => $port, 'ifDescr' => $port, 'ifType' => 'ethernetCsmacd',
            'ifMtu' => 1500, 'ifOperStatus' => 'up', 'address' => $ip, 'prefixlen' => (int) $prefix, 'vrf' => $vrf,
        ], $values);

        return $portId;
    }

    public function ospfRouter(int $device, string $routerId, string $vrf = ''): self
    {
        $this->ospf['instances'][] = [
            'device_id' => $device, 'ospfRouterId' => $routerId, 'ospfAdminStat' => 'enabled', 'ospfAreaBdrRtrStatus' => 'false',
            'ospfASBdrRtrStatus' => 'false', 'context_name' => $vrf,
        ];

        return $this;
    }

    /**
     * A point-to-point OSPF link: the .1 address on $a, the .2 on $b, an OSPF interface on both
     * (point-to-point, 10/40 s, cost 10), the adjacency seen from both sides and an LLDP link.
     *
     * @param  array  $aIf  ospf_ports columns of a's interface (and 'ifMtu' for its port)
     */
    public function ospfLink(int $a, string $aPort, int $b, string $bPort, string $network, string $area = '0.0.0.0', array $aIf = [], array $bIf = [],
        string $aSees = 'full', string $bSees = 'full', string $vrf = ''): self
    {
        [$base, $prefix] = explode('/', $network);
        $first = long2ip(ip2long($base) + 1);
        $second = long2ip(ip2long($base) + 2);
        $ports = [];
        foreach ([[$a, $aPort, $first, $aIf], [$b, $bPort, $second, $bIf]] as [$device, $port, $ip, $if]) {
            $ports[] = $portId = $this->address($device, $port, "$ip/$prefix", $vrf, isset($if['ifMtu']) ? ['ifMtu' => $if['ifMtu']] : []);
            unset($if['ifMtu']);
            $this->ospf['ports'][] = array_merge([
                'device_id' => $device, 'port_id' => $portId, 'ospfIfIpAddress' => $ip, 'ospfAddressLessIf' => 0, 'ospfIfAreaId' => $area,
                'ospfIfType' => 'pointToPoint', 'ospfIfAdminStat' => 'enabled', 'ospfIfHelloInterval' => 10, 'ospfIfRtrDeadInterval' => 40,
                'ospfIfState' => 'pointToPoint', 'ospfIfDesignatedRouter' => '0.0.0.0', 'ospfIfAuthType' => 'none', 'ospfIfMetricValue' => 10,
                'context_name' => $vrf,
            ], $if);
        }
        $this->neighbor($a, $second, "$b.$b.$b.$b", $aSees, $vrf)->neighbor($b, $first, "$a.$a.$a.$a", $bSees, $vrf);
        $this->link($a, $ports[0], $b, $ports[1]);

        return $this;
    }

    /**
     * An LLDP/CDP link between two ports (the port of the remote end may be unknown).
     */
    public function link(int $a, ?int $aPort, int $b, ?int $bPort): self
    {
        $this->links[] = ['local_device_id' => $a, 'local_port_id' => $aPort, 'remote_device_id' => $b, 'remote_port_id' => $bPort];

        return $this;
    }

    public function neighbor(int $device, string $ip, string $routerId, string $state = 'full', string $vrf = ''): self
    {
        $this->ospf['neighbors'][] = [
            'device_id' => $device, 'port_id' => 0, 'ospfNbrIpAddr' => $ip, 'ospfNbrRtrId' => $routerId, 'ospfNbrState' => $state, 'context_name' => $vrf,
        ];

        return $this;
    }

    public function area(int $device, string $area, int $count, int $sum, string $type = 'importExternal', string $vrf = ''): self
    {
        $this->ospf['areas'][] = [
            'device_id' => $device, 'ospfAreaId' => $area, 'ospfAreaLsaCount' => $count, 'ospfAreaLsaCksumSum' => $sum,
            'ospfImportAsExtern' => $type, 'context_name' => $vrf,
        ];

        return $this;
    }

    /**
     * A BGP session, established for 30 days unless $values say otherwise.
     */
    public function bgpPeer(int $device, string $peer, int $remoteAs, array $values = []): self
    {
        $this->bgp['peers'][] = array_merge([
            'device_id' => $device, 'bgpPeerIdentifier' => $peer, 'bgpPeerRemoteAs' => $remoteAs, 'bgpPeerState' => 'established',
            'bgpPeerAdminStatus' => 'start', 'bgpPeerLastErrorCode' => 0, 'bgpPeerLastErrorSubCode' => 0, 'bgpPeerLastErrorText' => null,
            'bgpLocalAddr' => '0.0.0.0', 'bgpPeerDescr' => '', 'astext' => '', 'bgpPeerFsmEstablishedTime' => 86400 * 30, 'context_name' => '',
            'vrf' => '',
        ], $values);

        return $this;
    }

    public function prefixes(int $device, string $peer, int $accepted, int $limit = 0, array $values = []): self
    {
        $this->bgp['prefixes'][] = array_merge([
            'device_id' => $device, 'bgpPeerIdentifier' => $peer, 'afi' => 'ipv4', 'safi' => 'unicast', 'AcceptedPrefixes' => $accepted,
            'DeniedPrefixes' => 0, 'PrefixAdminLimit' => $limit, 'PrefixThreshold' => 75, 'context_name' => '',
        ], $values);

        return $this;
    }

    /**
     * Change the rows of a table matching $where.
     */
    public function update(string $table, array $where, array $values): self
    {
        [$group, $key] = match ($table) {
            'instances', 'areas', 'ports', 'neighbors' => ['ospf', $table],
            'peers', 'prefixes' => ['bgp', $table],
            default => [null, $table],
        };
        $rows = $group ? $this->{$group}[$key] : $this->{$key};
        foreach ($rows as &$row) {
            if (array_intersect_assoc($where, $row) == $where) {
                $row = array_merge($row, $values);
            }
        }
        unset($row);
        if ($group) {
            $this->{$group}[$key] = $rows;
        } else {
            $this->{$key} = $rows;
        }

        return $this;
    }

    /**
     * @param  int[]|null  $scope  reported devices (default: all)
     */
    public function analyzer(array $settings = [], ?array $scope = null): RoutingAnalyzer
    {
        return new RoutingAnalyzer(
            $this->devices,
            $scope ?? array_keys($this->devices),
            $this->addresses,
            new PluginSettings($settings),
            $this->ospf,
            $this->bgp,
            $this->links,
            $this->vrfs,
        );
    }
}
