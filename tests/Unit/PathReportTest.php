<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Path\L3Tracer;
use Agoodley\LibrenmsTopology\Path\PathAssembler;
use Agoodley\LibrenmsTopology\Path\PathLog;
use Agoodley\LibrenmsTopology\Path\PathReport;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

/**
 * PathReport on rows of PathFixture's campus and the rows of their devices and ports (no repositories): what each hop
 * shows, the problems on the path, the totals and the map link.
 */
class PathReportTest extends TestCase
{
    private static function report(array $settings = []): PathReport
    {
        return new PathReport(new PluginSettings($settings), fn (string $name, array $parameters = []) => $name . '?' . http_build_query($parameters));
    }

    /**
     * The rows of the Layer 2 path from acc1 Gi1/0/5 to acc2 Gi1/0/6 in VLAN 110.
     */
    private static function campusRows(PathFixture $net): array
    {
        $trace = $net->l2(110)->trace(PathFixture::ACC1, PathFixture::ACC2, 110, ['enter' => 305, 'exit' => 406]);

        return array_map(fn ($hop) => array_merge(PathAssembler::emptyRow(), $hop, ['in_vlan' => 110, 'out_vlan' => 110]), $trace['hops']);
    }

    /**
     * What PathService reads for the report: device and port rows, counters, spanning tree states.
     */
    private static function details(PathFixture $net, array $rows, ?int $vlan = 110): array
    {
        $ports = [];
        foreach (PathReport::portIds($rows) as $portId) {
            if (isset($net->ports[$portId])) {
                $ports[$portId] = $net->ports[$portId] + ['url' => 'port/' . $portId, 'poll_period' => 300];
            }
        }
        $stp = $net->stp($vlan);

        return [
            'devices' => array_map(fn ($device) => $device + ['url' => 'device/' . $device['device_id']], $net->devices),
            'ports' => $ports,
            'statistics' => [406 => ['ifOutDiscards_delta' => 5000]],
            'stp' => fn (int $deviceId, int $portId, ?int $vlan) => $stp->portStatus($deviceId, $portId, $vlan),
        ];
    }

    public function testEveryHopShowsItsPortsAndTheLinkToTheNext(): void
    {
        $net = PathFixture::campus();
        $rows = self::campusRows($net);
        $this->assertSame([305, 301, 111, 120, 121, 122, 400, 401, 402, 406], PathReport::portIds($rows), 'the ports and the port-channel members on the path');

        $hops = self::report()->hops($rows, self::details($net, $rows));
        $this->assertSame(['acc1', 'dist1', 'acc2'], array_column($hops, 'name'));
        [$acc1, $dist1, $acc2] = $hops;
        $this->assertSame(['Gi1/0/5', 'Gi1/0/49', 'forwarding', 'port/301'], [$acc1['in']['name'], $acc1['out']['name'], $acc1['out']['stp']['state'], $acc1['out']['url']]);
        $this->assertSame(['Po21', ['Te1/0/21', 'Te1/0/22']], [$dist1['out']['name'], array_column($dist1['out']['members'], 'name')]);
        $this->assertSame(['speed' => 20_000_000_000, 'members' => 2, 'up' => 2, 'lag' => true, 'status' => 'up'], $dist1['link']);
        $this->assertSame([5000, 'l2', 110, 'device/4'], [$acc2['out']['out_discards'], $acc2['layer'], $acc2['vlan'], $acc2['url']]);
        $this->assertNull($acc2['link'], 'the last hop');

        $this->assertSame('topology.l2?device=1&depth=1&vlan=110&path=d%3A3%2Cd%3A1%2Cd%3A4&path_ports=301%3A111%2C120%3A400', self::report()->mapUrl($hops, 110));
    }

    public function testProblemsOnThePortsAreFindings(): void
    {
        $net = PathFixture::campus();
        $net->ports[111]['ifDuplex'] = 'halfDuplex';
        $net->ports[111]['ifInErrors_delta'] = 500;
        $net->ports[301]['ifOutOctets_rate'] = 1_100_000_000; // 8.8 Gbit/s of 10
        $net->ports[122]['ifOperStatus'] = 'down';
        $net->ports[406]['ifOperStatus'] = 'down';
        $rows = self::campusRows($net);
        $hops = self::report(['links_discard_percent' => '1'])->hops($rows, self::details($net, $rows));
        $log = new PathLog;
        self::report(['links_discard_percent' => '1'])->check($hops, $log);
        $findings = $log->findings();

        $byCode = array_column($findings, null, 'code');
        $this->assertSame(['critical', 'acc2 Gi1/0/6'], [$byCode['port_down']['severity'], substr($byCode['port_down']['title'], -12)]);
        $this->assertSame('warning', $byCode['busy_link']['severity'], '88% is busy, not full');
        $this->assertSame(['warning', 'warning'], [$byCode['half_duplex']['severity'], $byCode['port_errors']['severity']]);
        $this->assertStringContainsString('dist1 Po21 ↔ acc2 Po1', $byCode['lag_degraded']['title'], 'both ends of the port-channel in one finding');
        $this->assertSame([PathFixture::DIST1, PathFixture::ACC2], $byCode['lag_degraded']['devices']);
        $this->assertStringContainsString('discarded', $byCode['port_discards']['detail']);
        $this->assertSame('critical', $findings[0]['severity'], 'the most severe first');

        $summary = self::report()->summary($hops, $findings, true, 'l2');
        $this->assertSame(['util' => 88.0, 'where' => 'acc1 Gi1/0/49 outbound'], $summary['busiest']);
        $this->assertSame(['devices' => 3, 'critical' => 1, 'status' => 'critical'], array_intersect_key($summary, array_flip(['devices', 'critical', 'status'])));
        $this->assertSame(10_000_000_000, $summary['slowest']['speed']);
    }

    public function testARoutedHopShowsItsRouteOrWhyItIsEstimated(): void
    {
        $net = PathFixture::campus();
        $route = ['prefix' => '10.90.130.0/24', 'length' => 24, 'next_hop' => '10.255.0.2', 'next_hops' => ['10.255.0.2'], 'port_id' => 0, 'type' => 4,
            'protocol' => 'BGP', 'metric' => 200, 'updated' => PathFixture::NOW];
        $l3 = ['device_id' => PathFixture::RTR1, 'vrf' => '', 'in_port' => 501, 'in_address' => '10.90.0.0', 'out_port' => 503, 'out_address' => '10.90.0.4',
            'next_hop' => '10.255.0.2', 'via' => '10.90.0.5', 'toward' => null, 'route' => $route, 'estimated' => null, 'estimated_why' => null,
            'recursive' => false, 'ambiguous' => [], 'routes_read' => PathFixture::NOW, 'connected' => null];
        $estimated = ['device_id' => PathFixture::RTR3, 'in_port' => 701, 'in_address' => '10.90.0.5', 'out_port' => 702, 'out_address' => '10.90.0.6',
            'next_hop' => '10.90.0.7', 'via' => null, 'toward' => '10.255.0.2', 'route' => null, 'estimated' => 'LibreNMS\' routes of rtr3 date from 2d before its last discovery: ...',
            'estimated_why' => L3Tracer::ROUTES_STALE, 'routes_read' => PathFixture::NOW - 5 * 86400] + $l3;
        $rows = [
            ['node' => 'd:5', 'kind' => 'device', 'device_id' => PathFixture::RTR1, 'in_port' => 501, 'out_port' => 503, 'l3' => $l3] + PathAssembler::emptyRow(),
            ['node' => 'd:7', 'kind' => 'device', 'device_id' => PathFixture::RTR3, 'in_port' => 701, 'out_port' => 702, 'l3' => $estimated] + PathAssembler::emptyRow(),
        ];

        $hops = self::report()->hops($rows, self::details($net, $rows, null));
        [$rtr1, $rtr3] = $hops;
        $this->assertSame(['10.90.130.0/24', '10.255.0.2', '10.90.0.5', 'BGP', false], [$rtr1['route']['prefix'], $rtr1['route']['next_hop'], $rtr1['route']['via'],
            $rtr1['route']['protocol'], $rtr1['route']['recursive']]);
        $this->assertSame(['10.90.0.0', 'l3', PathFixture::NOW], [$rtr1['in']['address'], $rtr1['layer'], $rtr1['routes_read']]);
        $this->assertSame(['10.255.0.2', L3Tracer::ROUTES_STALE, null], [$rtr3['toward'], $rtr3['estimated_why'], $rtr3['route']['prefix']]);

        $log = new PathLog;
        self::report()->check($hops, $log);
        $estimatedHops = array_column($log->findings(), null, 'code')['estimated_hops'];
        $this->assertSame(['info', [PathFixture::RTR3]], [$estimatedHops['severity'], $estimatedHops['devices']]);
        $this->assertStringContainsString('routes_max_number', $estimatedHops['recommendation'], 'stale: not "enable the route module"');
        $this->assertSame(['routers' => 2, 'estimated' => 1], array_intersect_key(self::report()->summary($hops, $log->findings(), true, 'l3'), ['routers' => 0, 'estimated' => 0]));
    }

    public function testTheMapLinkMarksGapsAndDevicesOutOfSight(): void
    {
        $device = fn (int $id, ?int $in, ?int $out, bool $link = true) => ['type' => 'device', 'node' => 'd:' . $id, 'device_id' => $id, 'name' => 'd' . $id,
            'in' => $in === null ? null : ['port_id' => $in], 'out' => $out === null ? null : ['port_id' => $out], 'link' => $link ? ['speed' => 1] : null];
        $hops = [$device(3, 305, 301), ['type' => 'hidden', 'node' => null, 'name' => 'a device you may not see', 'link' => ['speed' => 1]], $device(4, 403, 406, false),
            ['type' => 'gap', 'node' => null, 'name' => 'not linked by LLDP/CDP', 'link' => null], $device(8, null, null, false)];

        $url = self::report()->mapUrl($hops, null);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['d:3,d:4,d:8', '-,-', '1', '4'], [$query['path'], $query['path_ports'], $query['path_hidden'], $query['device']],
            'no link drawn across the hidden device or the gap; the map is told the path crosses a device out of sight');
        $this->assertNull(self::report()->mapUrl([['type' => 'host', 'node' => null, 'name' => 'pc', 'link' => null]], null), 'no device, no map');
    }
}
