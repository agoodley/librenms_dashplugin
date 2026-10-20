<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Data\PathRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Path\L2Tracer;
use Agoodley\LibrenmsTopology\Path\PathService;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\Cidr;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

/**
 * PathService on PathFixture's campus through in-memory repositories: how the ends are resolved, Layer 2 within a
 * subnet and routed between subnets with the switches between the routers, a path leaving the devices the user can
 * see, devices out of sight crossed without a trace of them, findings and the map link.
 */
class PathServiceTest extends TestCase
{
    /**
     * Host locator answers of the campus hosts.
     */
    private static function hosts(): \Closure
    {
        $host = fn (string $mac, int $deviceId, int $portId, string $port, int $vlan, string $ip, int $gateway, int $interface) => [
            'mac' => $mac, 'readable' => implode(':', str_split($mac, 2)), 'vendor' => null, 'vlan' => $vlan, 'gateway' => null, 'status' => 'located',
            'edge' => ['device_id' => $deviceId, 'port_id' => $portId, 'vlans' => [$vlan], 'port' => $port, 'port_url' => null],
            'device' => null, 'notes' => [],
            'addresses' => [['ip' => $ip, 'display' => $ip, 'device_id' => $gateway, 'port_id' => $interface, 'family' => 4, 'context' => null]],
        ];
        $answers = [
            '10.90.110.11' => $host('009011000011', PathFixture::ACC1, 305, 'Gi1/0/5', 110, '10.90.110.11', PathFixture::DIST1, 1110),
            '10.90.110.12' => $host('009011000012', PathFixture::ACC2, 406, 'Gi1/0/6', 110, '10.90.110.12', PathFixture::DIST1, 1110),
            '10.90.130.31' => $host('009013000031', PathFixture::ACC3, 803, 'Gi1/0/3', 130, '10.90.130.31', PathFixture::RTR2, 6130),
        ];

        return fn (string $query) => ['devices' => [], 'resolved' => [], 'truncated' => false,
            'hosts' => isset($answers[$query]) ? [$answers[$query]] : [], 'message' => isset($answers[$query]) ? null : 'No ARP or IPv6 neighbor entry for ' . $query . '.'];
    }

    /**
     * @param  int[]|null  $visible  devices the user may see (null: all)
     * @param  (\Closure(string): array)|null  $hosts  host locator answers (default: hosts())
     */
    private static function service(?PathFixture $net = null, ?array $visible = null, array $settings = [], ?FakePathRepository &$repo = null, ?int $routes = 100,
        bool $admin = false, ?FakePathTopology &$topology = null, ?\Closure $hosts = null): PathService
    {
        $net ??= PathFixture::campus();
        $visible ??= array_keys($net->devices);
        $repo = new FakePathRepository($net, $visible);
        $repo->size = $routes;
        $topology = new FakePathTopology($net, $visible);

        return new PathService($repo, $topology, new PluginSettings($settings), $hosts ?? self::hosts(),
            fn (string $name, array $parameters = []) => $name . '?' . http_build_query($parameters), $admin);
    }

    private static function codes(array $result): array
    {
        return array_column($result['findings'], 'code');
    }

    private static function names(array $result): array
    {
        return array_map(fn ($hop) => $hop['name'], $result['hops']);
    }

    public function testHostsInOneSubnetGetTheLayer2Path(): void
    {
        $result = self::service()->trace(['a' => '10.90.110.11', 'b' => '10.90.110.12']);

        $this->assertSame(['l2', 110, true], [$result['mode'], $result['vlan'], $result['complete']]);
        $this->assertSame(['10.90.110.11 (00:90:11:00:00:11)', 'acc1', 'dist1', 'acc2', '10.90.110.12 (00:90:11:00:00:12)'], self::names($result));
        [, $acc1, $dist1, $acc2] = $result['hops'];
        $this->assertSame(['Gi1/0/5', 'Gi1/0/49'], [$acc1['in']['name'], $acc1['out']['name']]);
        $this->assertSame(['Te1/0/11', 'Po21', 'Po1', 'Gi1/0/6'], [$dist1['in']['name'], $dist1['out']['name'], $acc2['in']['name'], $acc2['out']['name']]);
        $this->assertSame(['Te1/0/21', 'Te1/0/22'], array_column($dist1['out']['members'], 'name'), 'the port-channel\'s members');
        $this->assertSame('forwarding', $acc1['out']['stp']['state']);
        $this->assertSame(20_000_000_000, $dist1['link']['speed']);
        $this->assertSame('topology.l2?device=1&depth=1&vlan=110&path=d%3A3%2Cd%3A1%2Cd%3A4&path_ports=301%3A111%2C120%3A400', $result['map_url']);
        $this->assertSame([], array_filter($result['notes'], fn ($note) => str_contains($note['text'], 'HSRP')), 'no gateway talk on an L2 path');
    }

    public function testHostsInDifferentSubnetsGetTheRoutedPathWithTheSwitchesBetween(): void
    {
        $result = self::service()->trace(['a' => '10.90.110.11', 'b' => '10.90.130.31']);

        $this->assertSame(['l3', true], [$result['mode'], $result['complete']]);
        $this->assertSame(['10.90.110.11 (00:90:11:00:00:11)', 'acc1', 'dist1', 'rtr1', 'rtr3', 'rtr2', 'acc3', '10.90.130.31 (00:90:13:00:00:31)'], self::names($result));
        $hops = array_combine(self::names($result), $result['hops']);
        $this->assertSame(['l3', '0.0.0.0/0', '10.90.0.0'], [$hops['dist1']['layer'], $hops['dist1']['route']['prefix'], $hops['dist1']['route']['next_hop']]);
        $this->assertSame(['Te1/0/11', 'Vl110', '10.90.110.2'], [$hops['dist1']['in']['name'], $hops['dist1']['in']['interface']['name'], $hops['dist1']['in']['interface']['address']],
            'dist1 is entered on the physical port, routing on its SVI');
        $this->assertSame(['Te1/0/48', '10.90.0.1'], [$hops['dist1']['out']['name'], $hops['dist1']['out']['address']]);
        $this->assertNotNull($hops['rtr3']['estimated']);
        $this->assertSame(['Gi0/0/1', 'Gi0/0/1.130', '10.90.130.0/24'], [$hops['rtr2']['out']['name'], $hops['rtr2']['out']['interface']['name'], $hops['rtr2']['connected']],
            'the sub-interface leaves by its physical port');
        $this->assertSame(['Gi1/0/49', 'Gi1/0/3', 130], [$hops['acc3']['in']['name'], $hops['acc3']['out']['name'], $hops['acc3']['vlan']]);
        $codes = array_column($result['findings'], 'code');
        $this->assertContains('estimated_hops', $codes);
        $this->assertSame('ok', $result['summary']['status'] === 'info' ? 'ok' : $result['summary']['status']);
        $this->assertSame([4, 1], [$result['summary']['routers'], $result['summary']['estimated']]);
    }

    public function testThePathResumesWhereTheDestinationIsVisibleAgain(): void
    {
        $result = self::service()->trace(['a' => '10.90.110.11', 'b_device' => PathFixture::REMOTE]);

        $this->assertFalse($result['complete']);
        $this->assertSame(['10.90.110.11 (00:90:11:00:00:11)', 'acc1', 'dist1', 'rtr1', 'next hop 203.0.113.1', 'remote'], self::names($result));
        $this->assertSame('outside', $result['hops'][4]['type']);
        $this->assertSame('Gi0/0/0', end($result['hops'])['in']['name'], 'remote is reached on its interface');
        $this->assertContains('path_outside', array_column($result['findings'], 'code'));
        $this->assertStringEndsWith('path_ports=301%3A111%2C148%3A501%2C-', $result['map_url'], 'no link drawn across the gap');
    }

    public function testDevicesOutOfSightAreCrossedWithoutATraceOfThem(): void
    {
        // the user may see the access switches only: the distribution switches between them are hidden
        $visible = [PathFixture::ACC1, PathFixture::ACC2];
        $result = self::service(null, $visible)->trace(['a_device' => PathFixture::ACC1, 'a_port' => 305, 'b_device' => PathFixture::ACC2, 'b_port' => 406, 'vlan' => 110]);

        $this->assertTrue($result['complete']);
        $this->assertSame(['device', 'hidden', 'device'], array_column($result['hops'], 'type'));
        $this->assertSame([null, null, null], [$result['hops'][1]['device_id'], $result['hops'][1]['in'], $result['hops'][1]['out']]);
        $json = json_encode($result);
        foreach (['dist1', 'Te1/0/11', 'Po21', 'Te1/0/21', '"device_id":1', '"device_id":2', 'd:1', 'd:2'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret);
        }
        $this->assertStringContainsString('path_ports=-', $result['map_url']);

        $this->assertStringContainsString('not a device you can see', self::service(null, $visible)->trace(['a_device' => PathFixture::DIST1, 'b_device' => PathFixture::ACC2])['message']);
        $this->assertStringContainsString('not a port of acc1', self::service(null, $visible)->trace(['a_device' => PathFixture::ACC1, 'a_port' => 111, 'b_device' => PathFixture::ACC2])['message'],
            'a port of a hidden device');
    }

    public function testEndsResolveFromAnInterfaceAddressAndReportWhatIsMissing(): void
    {
        $result = self::service()->trace(['a' => '10.90.0.7', 'b' => '10.90.130.31']);
        $this->assertSame(['device', 'rtr2', 'Gi0/0/0'], [$result['a']['kind'], $result['a']['device'], $result['a']['port']]);
        $this->assertSame(['rtr2', 'acc3'], array_slice(self::names($result), 0, 2), 'a router starts the routed path itself');
        $this->assertNull($result['hops'][0]['in'], 'nothing comes in at the start');

        $result = self::service()->trace(['a' => '10.90.110.11', 'b' => '10.9.9.9']);
        $this->assertSame([], $result['hops']);
        $this->assertStringStartsWith('B: ', $result['message']);
        $this->assertFalse($result['b']['resolved']);
        $this->assertArrayNotHasKey('arp', $result['a']);

        $this->assertSame('Enter both ends of the path.', self::service()->trace(['a' => '10.90.110.11'])['message']);
        $this->assertNull(self::service()->trace([])['message']);
        $this->assertStringContainsString('Enter an IPv4', self::service()->trace(['a' => 'not a valid name!', 'b' => '10.90.110.12'])['message']);
    }

    public function testABlockedVlanGivesAFindingAndAGap(): void
    {
        $net = PathFixture::campus();
        $net->portVlans[400] = [1 => true, 120 => false]; // acc2's Po1 no longer carries VLAN 110, its uplink to dist2 blocks it

        $result = self::service($net)->trace(['a' => '10.90.110.11', 'b' => '10.90.110.12']);
        $this->assertFalse($result['complete']);
        $this->assertSame(['host', 'device', 'gap', 'device'], array_column($result['hops'], 'type'));
        $finding = $result['findings'][0];
        $this->assertSame(['critical', 'no_l2_path'], [$finding['severity'], $finding['code']]);
        $this->assertStringContainsString('acc2 Po1 (does not carry VLAN 110)', $finding['detail']);
        $this->assertStringContainsString('acc2 Gi1/0/51 (blocking)', $finding['detail']);
    }

    public function testProblemsOnThePathAreFindings(): void
    {
        $net = PathFixture::campus();
        $net->ports[111]['ifDuplex'] = 'halfDuplex';
        $net->ports[111]['ifInErrors_delta'] = 500;
        $net->ports[301]['ifOutOctets_rate'] = 1_225_000_000; // 9.8 Gbit/s of 10
        $net->ports[122]['ifOperStatus'] = 'down';

        $result = self::service($net)->trace(['a' => '10.90.110.11', 'b' => '10.90.110.12']);
        $byCode = array_column($result['findings'], null, 'code');
        $this->assertSame('critical', $byCode['busy_link']['severity'], 'at 98%');
        $this->assertSame('warning', $byCode['half_duplex']['severity']);
        $this->assertSame('warning', $byCode['port_errors']['severity']);
        $this->assertStringContainsString('dist1 Po21 ↔ acc2 Po1', $byCode['lag_degraded']['title'], 'both ends of the port-channel in one finding');
        $this->assertSame(1, count(array_filter($result['findings'], fn ($f) => $f['code'] === 'lag_degraded')));
        $this->assertSame(['util' => 98.0, 'where' => 'acc1 Gi1/0/49 outbound'], $result['summary']['busiest']);
        $this->assertSame([PathFixture::ACC1 => 'acc1', PathFixture::DIST1 => 'dist1', PathFixture::ACC2 => 'acc2'], $result['device_names']);
    }

    public function testAHugeRouteTableIsNotRead(): void
    {
        $result = self::service(null, null, ['path_route_rows' => '1000'], $repo, 5000)->trace(['a' => '10.90.110.11', 'b' => '10.90.130.31']);

        $this->assertSame([], $repo->calls, 'no route reads');
        $notes = implode(' ', array_column($result['notes'], 'text'));
        $this->assertStringContainsString('more than the 1,000 routes a path trace reads', $notes);
        $this->assertStringNotContainsString('5,000', $notes, 'the size of the whole table is for admins');
        $this->assertTrue($result['complete'], 'every router estimated from subnets and neighbors');
        $this->assertSame(['dist1', 'rtr1', 'rtr3', 'rtr2'], array_values(array_map(fn ($hop) => $hop['name'], array_filter($result['hops'], fn ($hop) => $hop['layer'] === 'l3'))));
        $estimated = array_column($result['findings'], null, 'code')['estimated_hops'];
        $this->assertStringContainsString('Route table limit', $estimated['recommendation'], 'not: enable the route discovery module');
        $this->assertStringNotContainsString('discovery_modules', $estimated['recommendation']);

        $admin = self::service(null, null, ['path_route_rows' => '1000'], $repo, 5000, true)->trace(['a' => '10.90.110.11', 'b' => '10.90.130.31']);
        $this->assertStringContainsString('holds about 5,000 routes, more than the 1,000', implode(' ', array_column($admin['notes'], 'text')));
    }

    public function testASecondTraceStartsClean(): void
    {
        $service = self::service();
        $this->assertContains('path_outside', self::codes($service->trace(['a' => '10.90.110.11', 'b_device' => PathFixture::REMOTE])));

        $second = $service->trace(['a' => '10.90.110.11', 'b' => '10.90.110.12']);
        $this->assertSame([], self::codes($second), 'no finding of the first trace');
        $this->assertSame([], $second['notes']);
        $this->assertSame(['l2', true], [$second['mode'], $second['complete']]);
    }

    public function testOnlyTheLinksAroundThePathAreRead(): void
    {
        // forty more access switches on dist2, in a chain
        $net = PathFixture::campus();
        $previous = 212;
        for ($id = 20; $id < 60; $id++) {
            $net->device($id, 'chain' . $id, 'switch')->port(5000 + $id * 10, $id, 'Te1/1/1')->port(5001 + $id * 10, $id, 'Te1/1/2');
            $net->link($previous, 5000 + $id * 10);
            $net->portVlans[5000 + $id * 10] = $net->portVlans[5001 + $id * 10] = [1 => true, 110 => false];
            $previous = 5001 + $id * 10;
        }
        $net->links = array_values(array_filter($net->links, fn ($link) => ! in_array(403, [$link['local_port_id'], $link['remote_port_id']], true)));

        $result = self::service($net, null, [], $repo, 100, false, $topology)->trace(['a' => '10.90.110.11', 'b' => '10.90.110.12']);
        $this->assertSame(['l2', true], [$result['mode'], $result['complete']]);
        $read = array_merge(...$repo->linkReads);
        sort($read);
        $this->assertSame([PathFixture::DIST1, PathFixture::ACC1, PathFixture::ACC2], array_values(array_unique($read)),
            'the links of the ends and of dist1: the path through it is the shortest before dist2 is read');
        $this->assertSame([], array_values(array_filter(array_unique($topology->deviceReads), fn ($id) => $id >= 20)), 'no row of the chain');
    }

    public function testRoutesNotReadAgainAtTheLastDiscoveryAreEstimated(): void
    {
        $net = PathFixture::campus();
        $net->routeData[PathFixture::RTR1]['discovered'] = PathFixture::NOW + 2 * 86400;

        $result = self::service($net)->trace(['a' => '10.90.110.11', 'b' => '10.90.130.31']);
        $this->assertTrue($result['complete']);
        $hops = array_combine(self::names($result), $result['hops']);
        $this->assertStringContainsString('2d before its last discovery', $hops['rtr1']['estimated']);
        $this->assertSame([null, '10.90.0.5'], [$hops['rtr1']['route']['prefix'], $hops['rtr1']['route']['next_hop']], 'no route of it: the estimated next hop');
        $this->assertStringContainsString('routes_max_number', array_column($result['findings'], null, 'code')['estimated_hops']['recommendation']);
    }

    public function testALoopThroughAnEstimateIsNotARoutingLoop(): void
    {
        // rtr2 defaults to rtr3 (no routes, estimated on to rtr1), and rtr1 now routes VLAN 110 back to rtr3
        $net = PathFixture::campus();
        $net->routes[PathFixture::RTR1] = array_values(array_filter($net->routes[PathFixture::RTR1], fn ($route) => $route['dest'] !== '10.90.110.0'));
        $net->route(PathFixture::RTR1, '10.90.110.0', 24, '10.90.0.5', 503, 'static');

        $result = self::service($net)->trace(['a_device' => PathFixture::RTR2, 'b' => '10.90.110.12']);
        $this->assertFalse($result['complete']);
        $this->assertNotContains('routing_loop', self::codes($result));
        $finding = array_column($result['findings'], null, 'code')['path_unknown'];
        $this->assertSame('warning', $finding['severity']);
        $this->assertStringContainsString('an estimated next hop is part of that way', $finding['detail']);
    }

    public function testAnAddressOfSeveralDevicesIsTheOneThePathReaches(): void
    {
        // dist2 has rtr2's 10.90.130.1 too (a duplicate address)
        $net = PathFixture::campus();
        $net->address(PathFixture::DIST2, 2130, 'Vlan130', '10.90.130.1', 24);

        $result = self::service($net)->trace(['a' => '10.90.110.11', 'b' => '10.90.130.1']);
        $this->assertSame(['rtr2', 'device'], [$result['b']['device'], $result['b']['kind']], 'the router the routed path reaches');
        $this->assertStringContainsString('the path reaches rtr2', $result['b']['notes'][0]['text']);
        $this->assertSame('rtr2', end($result['hops'])['name']);
        $this->assertTrue($result['complete']);

        $a = self::service($net)->trace(['a' => '10.90.130.1', 'b' => '10.90.110.12'])['a'];
        $this->assertSame('dist2', $a['device'], 'the lower id first');
        $this->assertStringContainsString('10.90.130.1 is an address of 2 devices (dist2, rtr2): the path uses dist2.', $a['notes'][0]['text']);
    }

    public function testAHiddenHopAndAStaleHostAreSaidSo(): void
    {
        $result = self::service(null, [PathFixture::ACC1, PathFixture::ACC2])->trace(['a_device' => PathFixture::ACC1, 'a_port' => 305, 'b_device' => PathFixture::ACC2, 'vlan' => 110]);
        $this->assertStringContainsString('path_hidden=1', (string) $result['map_url']);

        $hosts = self::hosts();
        $stale = function (string $query) use ($hosts): array {
            $answer = $hosts($query);
            foreach ($answer['hosts'] as &$host) {
                $host['stale'] = true;
            }

            return $answer;
        };
        $result = self::service(null, null, [], $repo, 100, false, $topology, $stale)->trace(['a' => '10.90.110.11', 'b' => '10.90.110.12']);
        $this->assertStringContainsString('did not list this host at their last discovery', $result['a']['notes'][0]['text']);
        $this->assertTrue($result['complete'], 'still traced from where it was seen');
    }

    public function testASingleSpanningTreeInstanceForAVlanIsSaidSo(): void
    {
        // acc1 runs one RSTP / MSTP instance: LibreNMS has it as the common instance
        $net = PathFixture::campus();
        $net->instances = array_values(array_filter($net->instances, fn ($row) => $row['device_id'] !== PathFixture::ACC1));
        $net->instances[] = ['device_id' => PathFixture::ACC1, 'vlan' => null, 'rootBridge' => 0, 'bridgeAddress' => '00aa03000001', 'designatedRoot' => '00aa01000110', 'rootPort' => 0, 'priority' => 32768];
        $net->stpPorts = array_values(array_filter($net->stpPorts, fn ($row) => $row['device_id'] !== PathFixture::ACC1));
        foreach ([301 => 'forwarding', 302 => 'discarding', 305 => 'forwarding'] as $portId => $state) {
            $net->stpPorts[] = ['device_id' => PathFixture::ACC1, 'vlan' => null, 'port_id' => $portId, 'port_index' => $portId, 'state' => $state, 'enable' => 'enabled', 'designatedBridge' => ''];
        }

        $result = self::service($net)->trace(['a' => '10.90.110.11', 'b' => '10.90.110.12']);
        $this->assertSame(['acc1', 'dist1', 'acc2'], array_slice(self::names($result), 1, 3));
        $this->assertStringContainsString('acc1 runs a single spanning tree instance in LibreNMS', implode(' ', array_column($result['notes'], 'text')));
    }
}

/**
 * The path repository on PathFixture's data: links anonymize devices out of sight like PathRepository::linksAt().
 */
final class FakePathRepository extends PathRepository
{
    public ?int $size = 100;

    /** @var string[] route table reads */
    public array $calls = [];

    /** @var array<int, int[]> devices whose links each read asked for */
    public array $linkReads = [];

    /**
     * @param  int[]  $visible
     */
    public function __construct(private readonly PathFixture $net, private readonly array $visible)
    {
        parent::__construct(null);
    }

    public function canSee(int $deviceId): bool
    {
        return in_array($deviceId, $this->visible, true);
    }

    public function linksAt(array $deviceIds, array $hidden = [], array $names = []): array
    {
        $this->linkReads[] = $deviceIds;
        $remotes = array_merge($deviceIds, $this->hiddenDeviceIds($hidden));
        $links = [];
        foreach ($this->net->links as $index => $link) {
            $remote = (int) $link['remote_device_id'];
            if ($this->canSee($link['local_device_id']) && (in_array($link['local_device_id'], $deviceIds, true) || ($remote && in_array($remote, $remotes, true))
                || (! $remote && in_array(strtolower($link['remote_hostname']), array_map('strtolower', $names), true)))) {
                $links[$index + 1] = $this->anonymized(['id' => $index + 1] + $link);
            }
        }

        return $links;
    }

    public function portsWithVlans(array $portIds): array
    {
        return array_fill_keys(array_keys(array_intersect_key($this->net->portVlans, array_flip($portIds))), true);
    }

    public function portVlans(array $portIds, int $vlan): array
    {
        $result = [];
        foreach (array_intersect_key($this->net->portVlans, array_flip($portIds)) as $portId => $vlans) {
            if (isset($vlans[$vlan])) {
                $result[$portId] = $vlans[$vlan];
            }
        }

        return $result;
    }

    public function portsWithIp(array $portIds): array
    {
        $result = [];
        foreach ($this->net->interfaces as $interface) {
            if (in_array($interface['port_id'], $portIds, true)) {
                $result[$interface['port_id']] = true;
            }
        }

        return $result;
    }

    public function routeTableSize(): ?int
    {
        return $this->size;
    }

    public function stpInstances(array $ids, ?int $vlan): array
    {
        return array_values(array_filter($this->net->instances, fn ($row) => in_array($row['device_id'], $ids, true) && $this->canSee($row['device_id'])));
    }

    public function stpPorts(array $keys, array $portIds): array
    {
        return array_values(array_filter($this->net->stpPorts, fn ($row) => in_array($row['port_id'], $portIds, true)
            && ($keys[$row['device_id']] ?? null) === StpIndex::vlanKey($row['vlan'])));
    }

    public function routerCandidates(array $ids): array
    {
        return array_values(array_filter($ids, fn ($id) => $this->canSee($id) && in_array($this->net->devices[$id]['role'] ?? '', ['router', 'l3switch', 'firewall'], true)));
    }

    public function interfaces(array $deviceIds): array
    {
        return array_values(array_filter($this->net->interfaces, fn ($interface) => in_array($interface['device_id'], $deviceIds, true) && $this->canSee($interface['device_id'])));
    }

    public function owners(string $address): array
    {
        return array_values(array_filter($this->net->interfaces, fn ($interface) => Cidr::canonical($interface['address']) === Cidr::canonical($address)
            && $this->canSee($interface['device_id'])));
    }

    public function routeData(array $deviceIds): array
    {
        $this->calls[] = 'routeData';

        return array_intersect_key($this->net->routeData, array_flip(array_filter($deviceIds, fn ($id) => $this->canSee($id))));
    }

    public function routesToward(string $destination, array $deviceIds): array
    {
        $this->calls[] = 'routesToward';
        $routes = [];
        foreach (array_intersect_key($this->net->routes, array_flip($deviceIds)) as $deviceId => $rows) {
            $routes[$deviceId] = array_values(array_filter($rows, fn ($route) => Cidr::contains($route['dest'] . '/' . $route['pfxlen'], $destination)));
        }

        return $routes;
    }

    public function portsByName(int $deviceId, array $names): array
    {
        $found = [];
        foreach ($this->net->ports as $portId => $port) {
            if ($port['device_id'] === $deviceId && in_array($port['ifName'], $names, true)) {
                $found[strtolower($port['ifName'])] = $portId;
            }
        }

        return $found;
    }

    public function device(int $deviceId): ?array
    {
        $device = $this->net->devices[$deviceId] ?? null;

        return $device && $this->canSee($deviceId) ? ['device_id' => $deviceId, 'name' => $device['display'], 'hostname' => $device['hostname'], 'ips' => []] : null;
    }
}

/**
 * The topology repository on PathFixture's data, limited to the devices the user may see.
 */
final class FakePathTopology extends TopologyRepository
{
    /** @var int[] devices whose rows were read */
    public array $deviceReads = [];

    /**
     * @param  int[]  $visible
     */
    public function __construct(private readonly PathFixture $net, private readonly array $visible)
    {
        parent::__construct(null);
    }

    public function deviceIds(?int $groupId = null, ?int $locationId = null): array
    {
        return $this->visible;
    }

    public function devices(array $ids, bool $roles = true): array
    {
        $devices = [];
        foreach ($ids as $id) {
            $this->deviceReads[] = $id;
            if (isset($this->net->devices[$id])) {
                $devices[$id] = $this->net->devices[$id] + ['url' => 'device/' . $id];
            }
        }

        return $devices;
    }

    public function lagParents(array $ids, bool $strict = false): array
    {
        return array_filter($this->net->lag, fn ($aggregate) => in_array($this->net->ports[$aggregate]['device_id'], $ids, true));
    }

    public function ports(array $portIds, ?array $columns = null): array
    {
        $ports = [];
        foreach ($portIds as $portId) {
            if (isset($this->net->ports[(int) $portId])) {
                $ports[(int) $portId] = $columns === null ? $this->net->ports[(int) $portId] + ['url' => null, 'poll_period' => 300]
                    : array_intersect_key($this->net->ports[(int) $portId], array_flip($columns));
            }
        }

        return $ports;
    }

    public function ospfNeighbors(array $ids): array
    {
        return array_values(array_map(fn ($row) => ['device_id' => $row['device_id'], 'port_id' => $row['port_id'], 'ospfNbrIpAddr' => $row['neighbor_ip'],
            'ospfNbrRtrId' => '', 'ospfNbrState' => 'full', 'context_name' => ''], array_filter($this->net->adjacencies, fn ($row) => in_array($row['device_id'], $ids, true))));
    }

    public function bgpPeers(array $ids): array
    {
        return [];
    }

    public function portStatistics(array $portIds): array
    {
        return [];
    }
}
