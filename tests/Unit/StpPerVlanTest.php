<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Stp\StpAnalyzer;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

/**
 * The background checks analyze a PVST network one VLAN at a time (TopologyService::stpFindings());
 * the findings must be those of the page's analysis of all VLANs at once.
 */
class StpPerVlanTest extends TestCase
{
    /** VLAN => port overrides: VLAN 20 forwards on acc1's backup uplink (a loop), VLAN 30 has a broken port. */
    private const VLANS = [null => [], 20 => [32 => ['state' => 'forwarding']], 30 => [42 => ['state' => 'broken']]];

    private static function instances(): array
    {
        $rows = [];
        foreach (array_keys(self::VLANS) as $vlan) {
            foreach (CampusFixture::stpInstances() as $row) {
                $rows[] = ['vlan' => $vlan === '' ? null : $vlan] + $row;
            }
        }

        return $rows;
    }

    private static function ports(?int $only = null): array
    {
        $rows = [];
        foreach (self::VLANS as $vlan => $overrides) {
            $vlan = $vlan === '' ? null : $vlan;
            if ($only === null || StpIndex::vlanKey($vlan) === $only) {
                foreach (CampusFixture::stpPorts($overrides) as $row) {
                    $rows[] = ['vlan' => $vlan] + $row;
                }
            }
        }

        return $rows;
    }

    private static function analyzer(StpIndex $index, array $settings = []): StpAnalyzer
    {
        return new StpAnalyzer(CampusFixture::devices(), $index, CampusFixture::links(), CampusFixture::ports(), CampusFixture::lagParent(),
            new PluginSettings($settings), [['device_id' => 3, 'datetime' => '2026-10-19 11:00:00', 'message' => 'designated root changed']],
            strtotime('2026-10-19 12:00:00'));
    }

    private static function normalized(array $findings): array
    {
        $rows = array_map(function (array $f) {
            sort($f['devices']);
            sort($f['ports']);
            sort($f['vlans']);

            return [$f['code'], $f['severity'], $f['title'], $f['devices'], $f['ports'], $f['vlans']];
        }, $findings);
        sort($rows);

        return $rows;
    }

    public function testOneVlanAtATimeFindsWhatTheWholeAnalysisFinds(): void
    {
        $whole = self::analyzer(new StpIndex(self::instances(), self::ports(), CampusFixture::lagParent()))->analyze();

        $base = new StpIndex(self::instances(), [], CampusFixture::lagParent());
        $analyzer = self::analyzer($base);
        $findings = [];
        $networkWide = true;
        foreach ([0, 20, 30] as $key) {
            array_push($findings, ...$analyzer->withInstance($base->withPorts(self::ports($key)), CampusFixture::ports())->instanceFindings([], $key, $networkWide));
            $networkWide = false;
        }
        $perVlan = StpAnalyzer::mergeAcrossVlans($findings);

        $this->assertNotEmpty($whole['findings']);
        $this->assertContains('forwarding_loop', array_column($perVlan, 'code'));
        $this->assertSame(self::normalized($whole['findings']), self::normalized($perVlan));
        $this->assertSame(1, count(array_filter($perVlan, fn ($f) => $f['code'] === 'root_changed')), 'network-wide checks once');
    }

    public function testAnIndexOfOneInstancePerDeviceAnalyzesThatVlanLikeOneWithEveryInstance(): void
    {
        // the STP page and the L2 map read the rows of the selected VLAN only and know the other instances by key
        $keys = [];
        $bridges = [];
        foreach (self::instances() as $row) {
            $keys[$row['device_id']][] = StpIndex::vlanKey($row['vlan']);
            if (($mac = StpIndex::mac($row['bridgeAddress'] ?? '')) !== '') {
                $bridges[$mac] ??= $row['device_id'];
            }
        }
        foreach ([0 => 1, 20 => 20, 30 => 30] as $key => $vlan) {
            $complete = new StpIndex(self::instances(), self::ports($key), CampusFixture::lagParent());
            $rows = array_filter(self::instances(), fn (array $row) => StpIndex::vlanKey($row['vlan']) === $key);
            $partial = StpIndex::partial($keys, $rows, self::ports($key), CampusFixture::lagParent(), $bridges);

            $this->assertSame($complete->deviceIds(), $partial->deviceIds());
            foreach ($complete->deviceIds() as $deviceId) {
                $this->assertSame($complete->keyFor($deviceId, $vlan), $partial->keyFor($deviceId, $vlan));
                $this->assertSame(array_keys($complete->instancesFor($deviceId)), $partial->keysFor($deviceId));
            }
            $expected = self::analyzer($complete)->analyze([], $vlan);
            $actual = self::analyzer($partial)->analyze([], $vlan);
            $this->assertNotEmpty($expected['bridges']);
            foreach (['findings', 'bridges', 'blocked', 'domains'] as $part) {
                $this->assertEquals($expected[$part], $actual[$part], "VLAN $vlan: $part");
            }
        }
    }

    public function testTheVlansOfADeviceAnalyzedOnEveryBridgeLikeOnTheWholeIndex(): void
    {
        // a device's STP page reads the instances of its VLANs (20 and 30) on every bridge; a single instance
        // bridge brings its default instance, which is not analyzed for itself
        $keys = [];
        $bridges = [];
        foreach (self::instances() as $row) {
            $keys[$row['device_id']][] = StpIndex::vlanKey($row['vlan']);
            if (($mac = StpIndex::mac($row['bridgeAddress'] ?? '')) !== '') {
                $bridges[$mac] ??= $row['device_id'];
            }
        }
        $wanted = fn (array $row) => in_array(StpIndex::vlanKey($row['vlan']), [20, 30], true) || ($row['device_id'] === 4 && StpIndex::vlanKey($row['vlan']) === 0);
        $complete = new StpIndex(self::instances(), self::ports(), CampusFixture::lagParent());
        $partial = StpIndex::partial($keys, array_filter(self::instances(), $wanted), array_filter(self::ports(), $wanted), CampusFixture::lagParent(), $bridges);

        $expected = self::analyzer($complete)->analyze([], null, [20, 30]);
        $actual = self::analyzer($partial)->analyze([], null, [20, 30]);
        $this->assertCount(2 * count(CampusFixture::stpInstances()), $expected['bridges'], 'the bridges of VLANs 20 and 30 only');
        $this->assertEquals($expected['findings'], $actual['findings']);
        // the bridges of a VLAN come in the order their rows were read
        foreach (['bridges', 'blocked', 'domains'] as $part) {
            $this->assertEqualsCanonicalizing($expected[$part], $actual[$part], $part);
        }
        $this->assertNotSame(self::analyzer($complete)->analyze()['bridges'], $expected['bridges']);
    }

    public function testMergingMergedFindingsKeepsAllVlans(): void
    {
        $finding = ['severity' => 'warning', 'code' => 'port_flapping', 'title' => 't', 'detail' => 'd', 'devices' => [3], 'ports' => [33]];
        $once = StpAnalyzer::mergeAcrossVlans([$finding + ['vlan' => 10, 'vlans' => [10]], $finding + ['vlan' => 20, 'vlans' => [20]]]);
        $twice = StpAnalyzer::mergeAcrossVlans(array_merge($once, StpAnalyzer::mergeAcrossVlans([$finding + ['vlan' => 30, 'vlans' => [30]]])));

        $this->assertSame([10, 20, 30], $twice[0]['vlans']);
        $this->assertSame(10, $twice[0]['vlan']);
    }
}
