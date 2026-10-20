<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Topology\SiteBuilder;
use PHPUnit\Framework\TestCase;

class SiteBuilderTest extends TestCase
{
    /**
     * Device row as SiteRepository returns it.
     */
    private static function device(int $id, string $name, ?int $location, string $role = 'switch', int $status = 1, mixed $lat = null, mixed $lng = null): array
    {
        return [
            'device_id' => $id, 'display' => $name, 'hostname' => "$name.example.net", 'status' => $status, 'disabled' => 0, 'role' => $role,
            'url' => "/device/$id", 'location_id' => $location, 'location' => $location ? "Site $location" : null, 'lat' => $lat, 'lng' => $lng,
        ];
    }

    /**
     * A cable between two devices (SiteConnections format).
     */
    private static function link(int $a, string $aPort, int $b, string $bPort, int $speed = 10000000000, string $status = 'up', int $in = 0, int $out = 0): array
    {
        return [
            'kind' => 'l2', 'a' => ['device_id' => $a, 'port' => $aPort], 'b' => ['device_id' => $b, 'port' => $bPort], 'speed' => $speed,
            'status' => $status, 'util' => $speed ? round(max($in, $out) / $speed * 100, 1) : null, 'in_bps' => $in, 'out_bps' => $out, 'protocols' => ['lldp'],
        ];
    }

    private static function session(string $kind, int $a, ?int $b, string $status = 'up', ?string $cloud = null): array
    {
        return [
            'kind' => $kind, 'a' => ['device_id' => $a], 'b' => $b ? ['device_id' => $b] : null, 'status' => $status, 'state' => $status === 'up' ? 'established' : 'idle',
            'type' => $kind === 'bgp' ? 'eBGP' : 'OSPF',
            'cloud' => $cloud ? ['id' => "as:$cloud", 'label' => "AS$cloud", 'name' => "Autonomous System $cloud", 'remote_as' => (int) $cloud] : null,
        ];
    }

    private static function node(array $result, string $id): ?array
    {
        foreach ($result['nodes'] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }

        return null;
    }

    public function testOneNodePerLocation(): void
    {
        $devices = [
            1 => self::device(1, 'core1', 1, 'l3switch', 1, '51.5074', '-0.1278'),
            2 => self::device(2, 'acc1', 1),
            3 => self::device(3, 'ap1', 1, 'wireless', 0),
            4 => self::device(4, 'rtr-b', 2, 'router', 0, '0', '0'),
            5 => self::device(5, 'lab', null, 'switch', 1, '95', '10'),
        ];
        $connections = [self::link(1, 'Te1/1/1', 4, 'Te0/0/0'), self::link(2, 'Gi1/0/49', 5, 'Gi0/1')];
        $result = (new SiteBuilder($devices, $connections, [3 => 1700000000]))->build();

        $hq = self::node($result, 'site:1');
        $this->assertSame('Site 1', $hq['name']);
        $this->assertSame(3, $hq['devices']);
        $this->assertSame(1, $hq['down']);
        $this->assertSame('degraded', $hq['status']);
        $this->assertSame(['l3switch' => 1, 'switch' => 1, 'wireless' => 1], $hq['roles']);
        $this->assertSame([['device_id' => 3, 'name' => 'ap1', 'url' => '/device/3', 'since' => 1700000000]], $hq['down_devices']);
        $this->assertSame(1700000000, $hq['outage_since']);
        $this->assertSame([51.5074, -0.1278], [$hq['lat'], $hq['lng']]);
        $this->assertSame("Site 1\n3 devices · 1 down", $hq['label']);

        // every device down; 0 / 0 is an unset location
        $branch = self::node($result, 'site:2');
        $this->assertSame('down', $branch['status']);
        $this->assertSame([null, null], [$branch['lat'], $branch['lng']]);

        // devices without a location are one site; coordinates out of range are ignored
        $none = self::node($result, SiteBuilder::NO_LOCATION);
        $this->assertSame('No location', $none['name']);
        $this->assertNull($none['location_id']);
        $this->assertSame([null, null], [$none['lat'], $none['lng']]);
        $this->assertSame(['sites' => 3, 'devices' => 5, 'down_devices' => 2, 'down_sites' => 1, 'degraded_sites' => 1, 'links' => 2,
            'down_links' => 0, 'degraded_links' => 0, 'clouds' => 0], $result['stats']);
    }

    public function testConnectionsBetweenTwoSitesAddUp(): void
    {
        $devices = [
            1 => self::device(1, 'core1', 1), 2 => self::device(2, 'core2', 1), 3 => self::device(3, 'acc1', 1),
            7 => self::device(7, 'rtr-b', 2, 'router'), 8 => self::device(8, 'sw-b', 2),
        ];
        $connections = [
            // reported from the remote site: its end a is in site 2
            self::link(7, 'Te0/0/0', 1, 'Te1/1/1', 10000000000, 'up', 2000000000, 1000000000),
            self::link(2, 'Te1/1/1', 8, 'Te1/1/1', 10000000000, 'down'),
            self::session('ospf', 1, 7),
            self::session('bgp', 7, 1),
            self::link(1, 'Gi1/0/1', 3, 'Gi1/0/49'), // inside site 1
        ];
        $result = (new SiteBuilder($devices, $connections))->build();

        $this->assertCount(1, $result['edges']);
        $edge = $result['edges'][0];
        $this->assertSame(['site:1', 'site:2', 'site', 2], [$edge['from'], $edge['to'], $edge['kind'], $edge['links']]);
        $this->assertSame(['ospf' => 1, 'bgp' => 1], $edge['routing']);
        $this->assertSame(20000000000, $edge['speed']);
        $this->assertSame(20.0, $edge['util']);
        $this->assertSame('degraded', $edge['status']);
        // traffic is turned around with the member: rtr-b received 2G from core1 (site 1 to site 2)
        $this->assertSame([2000000000, 1000000000], [$edge['from_bps'], $edge['to_bps']]);
        $this->assertSame(['l2', 'l2', 'ospf', 'bgp'], array_column($edge['members'], 'kind'));
        $this->assertSame('core1', $edge['members'][0]['a']['device']);
        $this->assertSame('Te1/1/1', $edge['members'][0]['a']['port']);
        $this->assertSame('rtr-b', $edge['members'][0]['b']['device']);
        $this->assertSame([2000000000, 1000000000], [$edge['members'][0]['out_bps'], $edge['members'][0]['in_bps']]);
        $this->assertSame('core1', $edge['members'][3]['a']['device'], 'sessions are oriented too');
    }

    public function testRoutingOnlyLinksAndClouds(): void
    {
        $devices = [1 => self::device(1, 'rtr-a', 1, 'router'), 2 => self::device(2, 'rtr-b', 2, 'router'), 3 => self::device(3, 'rtr-c', 3, 'router')];
        $connections = [
            self::session('bgp', 1, null, 'up', '65000'),
            self::session('bgp', 2, null, 'up', '65000'),
            self::session('bgp', 2, null, 'down', '65000'),
            self::session('bgp', 3, null, 'down', '65000'),
            self::session('bgp', 99, null, 'up', '65000'), // device outside the scope
        ];
        $result = (new SiteBuilder($devices, $connections))->build();

        $cloud = self::node($result, 'as:65000');
        $this->assertSame(['as', 'AS65000', 'cloud', 65000, 3], [$cloud['type'], $cloud['label'], $cloud['role'], $cloud['remote_as'], $cloud['degree']]);
        $statuses = [];
        foreach ($result['edges'] as $edge) {
            $this->assertSame('as:65000', $edge['to'], 'the cloud is the far end');
            $this->assertSame(0, $edge['links']);
            $statuses[$edge['from']] = $edge['status'];
        }
        $this->assertSame(['site:1' => 'up', 'site:2' => 'degraded', 'site:3' => 'down'], $statuses);
        $this->assertSame('AS65000', $result['edges'][0]['members'][0]['peer']);
        $this->assertNull($result['edges'][0]['members'][0]['b']);
    }

    public function testCenteredSiteWithItsNeighbors(): void
    {
        // site 1 - site 2 - site 3 - site 4 and a cloud on site 3
        $devices = [];
        foreach ([1, 2, 3, 4] as $site) {
            $devices[$site] = self::device($site, "sw$site", $site);
        }
        $connections = [self::link(1, 'a', 2, 'b'), self::link(2, 'a', 3, 'b'), self::link(3, 'a', 4, 'b'), self::session('bgp', 3, null, 'up', '65000')];
        $builder = new SiteBuilder($devices, $connections);

        $result = $builder->build(['center' => 2, 'depth' => 1]);
        $this->assertEqualsCanonicalizing(['site:1', 'site:2', 'site:3'], array_column($result['nodes'], 'id'));
        $this->assertSame('site:2', $result['center']);
        $this->assertSame(0, self::node($result, 'site:2')['level']);
        $this->assertCount(2, $result['edges']);
        $this->assertSame(['e0', 'e1'], array_column($result['edges'], 'id'));

        // a cloud is a hop
        $two = $builder->build(['center' => 2, 'depth' => 2]);
        $this->assertEqualsCanonicalizing(['site:1', 'site:2', 'site:3', 'site:4', 'as:65000'], array_column($two['nodes'], 'id'));

        // a location without devices in the scope: the whole map
        $unknown = $builder->build(['center' => 99, 'depth' => 1]);
        $this->assertNull($unknown['center']);
        $this->assertCount(5, $unknown['nodes']);
    }

    public function testUnlinkedSitesOnlyWhenAsked(): void
    {
        $devices = [1 => self::device(1, 'a', 1), 2 => self::device(2, 'b', 2), 3 => self::device(3, 'c', 3)];
        $builder = new SiteBuilder($devices, [self::link(1, 'x', 2, 'y')]);

        $this->assertEqualsCanonicalizing(['site:1', 'site:2'], array_column($builder->build()['nodes'], 'id'));
        $this->assertCount(3, $builder->build(['isolated' => true])['nodes']);
        // the centered site stays even without links
        $this->assertSame(['site:3'], array_column($builder->build(['center' => 3])['nodes'], 'id'));
    }

    public function testLargeMapsKeepTheBestConnectedSites(): void
    {
        // a hub with three spokes, one spoke with a second link to another spoke
        $devices = [];
        foreach ([1, 2, 3, 4] as $site) {
            $devices[$site] = self::device($site, "sw$site", $site);
        }
        $connections = [self::link(1, 'a', 2, 'b'), self::link(1, 'c', 3, 'd'), self::link(1, 'e', 4, 'f'), self::link(2, 'g', 3, 'h')];
        $result = (new SiteBuilder($devices, $connections))->build(['maxSites' => 2]);

        $this->assertTrue($result['truncated']);
        $this->assertSame(4, $result['total']);
        $this->assertEqualsCanonicalizing(['site:1', 'site:2'], array_column($result['nodes'], 'id'));
        $this->assertCount(1, $result['edges']);
        $this->assertSame(0, self::node($result, 'site:1')['level'], 'the hub is the top of the hierarchy');
    }

    public function testSiteIdOfADevice(): void
    {
        $this->assertSame('site:7', SiteBuilder::siteId(['location_id' => 7]));
        $this->assertSame(SiteBuilder::NO_LOCATION, SiteBuilder::siteId(['location_id' => null]));
        $this->assertSame(SiteBuilder::NO_LOCATION, SiteBuilder::siteId([]));
    }
}
