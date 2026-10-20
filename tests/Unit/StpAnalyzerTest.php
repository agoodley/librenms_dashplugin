<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Stp\StpAnalyzer;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

class StpAnalyzerTest extends TestCase
{
    private function analyze(array $instanceOverrides = [], array $portOverrides = [], array $settings = [], array $events = [], array $lookups = [], ?array $links = null): array
    {
        $analyzer = new StpAnalyzer(
            CampusFixture::devices(),
            new StpIndex(CampusFixture::stpInstances($instanceOverrides), CampusFixture::stpPorts($portOverrides), CampusFixture::lagParent()),
            $links ?? CampusFixture::links(),
            CampusFixture::ports(),
            CampusFixture::lagParent(),
            new PluginSettings($settings),
            $events,
            strtotime('2026-10-19 12:00:00'),
        );

        return $analyzer->analyze($lookups);
    }

    private function codes(array $result): array
    {
        return array_column($result['findings'], 'severity', 'code');
    }

    public function testHealthyCampus(): void
    {
        $result = $this->analyze();
        $this->assertSame('ok', $result['summary']['status'], json_encode($result['findings']));
        $this->assertCount(1, $result['domains']);
        $domain = $result['domains'][0];
        $this->assertSame(1, $domain['root']['device_id']);
        $this->assertSame('core1', $domain['root']['name']);
        $this->assertSame('4096.00:00:00:00:c0:01', $domain['root']['bridge_id']);
        $this->assertSame(4, $domain['members']);
        $this->assertSame(2, $domain['blocked']);
        $this->assertSame(1, $domain['max_hops']);
        $this->assertCount(2, $result['blocked']);
        $this->assertSame('alternate', $result['blocked'][0]['role']);
        $this->assertSame('core2', $result['blocked'][0]['neighbor']['name']);
    }

    public function testForwardingLoopIsDetected(): void
    {
        $result = $this->analyze([], [32 => ['state' => 'forwarding']]);
        $codes = $this->codes($result);
        $this->assertSame('critical', $codes['forwarding_loop'] ?? null);
        $this->assertSame('warning', $codes['nondesignated_forwarding'] ?? null);
    }

    public function testChecksCanBeDisabledByCodeOrCategory(): void
    {
        $broken = [32 => ['state' => 'forwarding']];

        $byCode = $this->codes($this->analyze([], $broken, ['stp_disabled_checks' => 'forwarding_loop']));
        $this->assertArrayNotHasKey('forwarding_loop', $byCode);
        $this->assertArrayHasKey('nondesignated_forwarding', $byCode);

        $byCategory = $this->analyze([], $broken, ['stp_disabled_checks' => 'ports, loops']);
        $this->assertArrayNotHasKey('nondesignated_forwarding', $this->codes($byCategory));
        $this->assertArrayNotHasKey('forwarding_loop', $this->codes($byCategory));

        $all = $this->analyze([], $broken);
        $this->assertSame('loops', array_column($all['findings'], 'category', 'code')['forwarding_loop']);
    }

    public function testMlagIsNotALoop(): void
    {
        // leafA(5) and leafB(6) share access(7) Po70 and a peer link: all forwarding is expected
        $devices = [];
        foreach ([5 => 'leafA', 6 => 'leafB', 7 => 'access'] as $id => $name) {
            $devices[$id] = ['device_id' => $id, 'display' => $name, 'hostname' => $name, 'role' => 'switch', 'uptime' => 864000];
        }
        $ports = [];
        foreach ([51 => 5, 52 => 5, 50 => 5, 61 => 6, 62 => 6, 60 => 6, 71 => 7, 72 => 7, 70 => 7] as $portId => $deviceId) {
            $ports[$portId] = ['port_id' => $portId, 'device_id' => $deviceId, 'ifName' => "port$portId", 'ifOperStatus' => 'up'];
        }
        $lag = [71 => 70, 72 => 70, 51 => 50, 61 => 60];
        $links = [
            ['local_device_id' => 7, 'local_port_id' => 71, 'remote_device_id' => 5, 'remote_port_id' => 51],
            ['local_device_id' => 7, 'local_port_id' => 72, 'remote_device_id' => 6, 'remote_port_id' => 61],
            ['local_device_id' => 5, 'local_port_id' => 52, 'remote_device_id' => 6, 'remote_port_id' => 62],
        ];
        $instances = [];
        $stpPorts = [];
        foreach ([5, 6, 7] as $id) {
            $instances[] = ['device_id' => $id, 'vlan' => null, 'bridgeAddress' => "00000000000$id", 'designatedRoot' => '000000000005', 'priority' => $id === 5 ? 4096 : 32768, 'rootPort' => $id === 5 ? 0 : 1];
        }
        foreach ([50 => 5, 52 => 5, 60 => 6, 62 => 6, 70 => 7] as $portId => $deviceId) {
            $stpPorts[] = ['device_id' => $deviceId, 'vlan' => null, 'port_id' => $portId, 'port_index' => $portId, 'state' => 'forwarding', 'designatedBridge' => '000000000005'];
        }

        $analyzer = new StpAnalyzer($devices, new StpIndex($instances, $stpPorts, $lag), $links, $ports, $lag, new PluginSettings());
        $codes = $this->codes($analyzer->analyze());
        $this->assertArrayNotHasKey('forwarding_loop', $codes);
    }

    public function testRootDisagreement(): void
    {
        $result = $this->analyze([4 => ['designatedRoot' => '0000000000ff']]);
        $this->assertSame('critical', $this->codes($result)['root_mismatch'] ?? null);
        $this->assertSame('critical', $result['summary']['status']);
    }

    public function testUnmonitoredRootIsTracedThroughRootPorts(): void
    {
        $rogue = 'deadbeef0001';
        $overrides = [];
        foreach ([1, 2, 3, 4] as $id) {
            $overrides[$id] = ['designatedRoot' => $rogue, 'rootBridge' => 0];
        }
        $overrides[1]['rootPort'] = 99;
        $ports = [99 => ['state' => 'forwarding']];
        $links = CampusFixture::links();
        $links[] = ['local_device_id' => 1, 'local_port_id' => 99, 'remote_device_id' => 0, 'remote_port_id' => null, 'remote_hostname' => 'rogue-sw', 'remote_port' => 'port 8', 'protocol' => 'lldp'];

        $analyzer = new StpAnalyzer(
            CampusFixture::devices(),
            new StpIndex(CampusFixture::stpInstances($overrides), array_merge(CampusFixture::stpPorts(), [[
                'device_id' => 1, 'vlan' => null, 'port_id' => 99, 'port_index' => 99, 'state' => 'forwarding', 'designatedBridge' => $rogue,
            ]]), CampusFixture::lagParent()),
            $links,
            CampusFixture::ports() + [99 => ['port_id' => 99, 'device_id' => 1, 'ifName' => 'GigabitEthernet1/0/48', 'ifOperStatus' => 'up']],
            CampusFixture::lagParent(),
            new PluginSettings(),
        );
        $this->assertSame([$rogue], $analyzer->unknownRootMacs());

        $result = $analyzer->analyze([$rogue => ['owners' => [], 'fdb' => [['device_id' => 1, 'port_id' => 99, 'vlan' => 1]], 'vendor' => 'TP-Link']]);
        $finding = array_values(array_filter($result['findings'], fn ($f) => $f['code'] === 'root_unmanaged'))[0] ?? null;
        $this->assertNotNull($finding);
        $this->assertStringContainsString('TP-Link', $finding['detail']);
        $this->assertStringContainsString('core1 Gi1/0/48 (towards rogue-sw port 8)', $finding['detail']);
        $this->assertFalse($result['domains'][0]['root']['monitored']);
    }

    public function testRootOutsideUserPermissionsIsNotRogue(): void
    {
        $hiddenRoot = '00000000ffff';
        $overrides = [];
        foreach ([1, 2, 3, 4] as $id) {
            $overrides[$id] = ['designatedRoot' => $hiddenRoot, 'rootBridge' => 0];
        }
        $result = $this->analyze($overrides, [], [], [], [$hiddenRoot => ['owners' => [], 'fdb' => [], 'vendor' => '', 'hidden' => true]]);
        $this->assertArrayNotHasKey('root_unmanaged', $this->codes($result));
        $this->assertSame('monitored bridge (no access)', $result['domains'][0]['root']['name']);
    }

    public function testRootPlacementChecks(): void
    {
        $result = $this->analyze([1 => ['priority' => 32768]], [], ['stp_preferred_roots' => 'core2']);
        $codes = $this->codes($result);
        $this->assertSame('warning', $codes['root_default_priority'] ?? null);
        $this->assertSame('warning', $codes['root_not_preferred'] ?? null);
    }

    public function testTopologyChangeAndFlapping(): void
    {
        $result = $this->analyze(
            [3 => ['topChanges' => 5000, 'timeSinceTopologyChange' => 30]],
            [33 => ['forwardTransitions' => 4000]],
        );
        $codes = $this->codes($result);
        $this->assertSame('warning', $codes['tc_rate'] ?? null);
        $this->assertSame('warning', $codes['port_flapping'] ?? null);
        $tc = array_values(array_filter($result['findings'], fn ($f) => $f['code'] === 'tc_rate'))[0];
        $this->assertStringContainsString('acc1 Gi1/0/1 (4000)', $tc['detail']);
    }

    public function testTimersAndProtocols(): void
    {
        $result = $this->analyze([
            1 => ['bridgeMaxAge' => 40, 'maxAge' => 40],
            4 => ['protocolSpecification' => 'pvstPlus'],
        ]);
        $codes = $this->codes($result);
        $this->assertSame('warning', $codes['timer_relation'] ?? null);
        $this->assertSame('warning', $codes['timer_mismatch'] ?? null);
        $this->assertSame('warning', $codes['protocol_mix'] ?? null);
    }

    public function testBrokenPortAndEvents(): void
    {
        $events = [
            ['device_id' => 3, 'datetime' => '2026-10-19 10:00:00', 'message' => 'STP designated root changed: a > b', 'severity' => 4],
            ['device_id' => 3, 'datetime' => '2026-10-19 10:05:00', 'message' => 'STP root port changed: 31 > 32', 'severity' => 4],
            ['device_id' => 3, 'datetime' => '2020-01-01 10:05:00', 'message' => 'STP designated root changed: old', 'severity' => 4],
        ];
        $result = $this->analyze([], [42 => ['state' => 'broken']], [], $events);
        $codes = $this->codes($result);
        $this->assertSame('critical', $codes['port_broken'] ?? null);
        $this->assertSame('warning', $codes['root_changed'] ?? null);
        $this->assertSame('info', $codes['root_port_changed'] ?? null);
    }

    public function testInterSwitchPortWithoutStp(): void
    {
        $ports = array_values(array_filter(CampusFixture::stpPorts(), fn ($p) => $p['port_id'] !== 42));
        $analyzer = new StpAnalyzer(
            CampusFixture::devices(),
            new StpIndex(CampusFixture::stpInstances(), $ports, CampusFixture::lagParent()),
            CampusFixture::links(),
            CampusFixture::ports(),
            CampusFixture::lagParent(),
            new PluginSettings(),
        );
        $result = $analyzer->analyze();
        $finding = array_values(array_filter($result['findings'], fn ($f) => $f['code'] === 'stp_disabled_port'))[0] ?? null;
        $this->assertNotNull($finding);
        $this->assertSame([42], $finding['ports']);
    }

    public function testPortRoles(): void
    {
        $instance = StpIndex::normalizeInstance(['device_id' => 3, 'bridgeAddress' => 'aa', 'designatedRoot' => '00:00:00:00:c0:01', 'rootPort' => 31]);
        $this->assertSame('', $instance['bridgeAddress']);
        $instance['bridgeAddress'] = '00000000a001';
        $this->assertSame('root', StpIndex::role($instance, ['port_index' => 31, 'state' => 'forwarding', 'designatedBridge' => '00000000c001']));
        $this->assertSame('alternate', StpIndex::role($instance, ['port_index' => 32, 'state' => 'blocking', 'designatedBridge' => '00000000c002']));
        $this->assertSame('backup', StpIndex::role($instance, ['port_index' => 34, 'state' => 'blocking', 'designatedBridge' => '00000000a001']));
        $this->assertSame('designated', StpIndex::role($instance, ['port_index' => 33, 'state' => 'forwarding', 'designatedBridge' => '00000000a001']));
        $this->assertSame('nondesignated', StpIndex::role($instance, ['port_index' => 35, 'state' => 'forwarding', 'designatedBridge' => '00000000c002']));
    }

    public function testIdenticalFindingsAreMergedAcrossVlans(): void
    {
        $finding = ['severity' => 'warning', 'code' => 'port_flapping', 'title' => 'ports flap', 'detail' => 'x', 'devices' => [3], 'ports' => [33]];
        $merged = StpAnalyzer::mergeAcrossVlans([
            $finding + ['vlan' => 10, 'vlans' => [10]],
            $finding + ['vlan' => 0, 'vlans' => [0]],
            ['detail' => 'different'] + $finding + ['vlan' => 20, 'vlans' => [20]],
        ]);
        $this->assertCount(2, $merged);
        $this->assertSame([0, 10], $merged[0]['vlans']);
        $this->assertSame('VLAN 1 / CST, VLAN 10', $merged[0]['vlan_label']);
        $this->assertSame(0, $merged[0]['vlan']);
    }

    public function testPvstBridgeWithoutTheVlanDoesNotJoinVlanIslands(): void
    {
        // A - B - C chained; VLAN 20 exists on A and C only (separate islands, separate roots),
        // B runs PVST for VLANs 1 and 30. B must not be taken for a single-instance (CST) bridge.
        $devices = [];
        foreach ([1 => 'A', 2 => 'B', 3 => 'C'] as $id => $name) {
            $devices[$id] = ['device_id' => $id, 'display' => $name, 'role' => 'switch', 'uptime' => 864000];
        }
        $instances = [];
        foreach ([1 => [null, 20], 2 => [null, 30], 3 => [null, 20]] as $id => $vlans) {
            foreach ($vlans as $vlan) {
                $root = $vlan === 20 ? "00000000000$id" : '000000000002';
                $instances[] = ['device_id' => $id, 'vlan' => $vlan, 'bridgeAddress' => "00000000000$id", 'designatedRoot' => $root, 'priority' => 32768];
            }
        }
        $links = [
            ['local_device_id' => 1, 'local_port_id' => 11, 'remote_device_id' => 2, 'remote_port_id' => 21],
            ['local_device_id' => 2, 'local_port_id' => 22, 'remote_device_id' => 3, 'remote_port_id' => 31],
        ];
        $index = new StpIndex($instances);
        $this->assertNull($index->keyFor(2, 20), 'PVST bridge without VLAN 20');

        $result = (new StpAnalyzer($devices, $index, $links, [], [], new PluginSettings()))->analyze([], 20);
        $this->assertArrayNotHasKey('root_mismatch', $this->codes($result));
        $this->assertCount(2, $result['domains']);
    }

    public function testPortStpFlagCoversInstancesThatWereNotLoaded(): void
    {
        // port 42 has no rows in the loaded instance, but ports_stp has rows for it in another VLAN
        $stpPorts = array_values(array_filter(CampusFixture::stpPorts(), fn ($p) => $p['port_id'] !== 42));
        $ports = CampusFixture::ports();
        foreach ($ports as &$port) {
            $port['has_stp'] = $port['port_id'] !== 42 ? in_array($port['port_id'], array_column($stpPorts, 'port_id'), true) : true;
        }
        unset($port);
        $analyzer = new StpAnalyzer(CampusFixture::devices(), new StpIndex(CampusFixture::stpInstances(), $stpPorts, CampusFixture::lagParent()),
            CampusFixture::links(), $ports, CampusFixture::lagParent(), new PluginSettings());
        $this->assertArrayNotHasKey('stp_disabled_port', $this->codes($analyzer->analyze()));
    }

    public function testRootChangeBurstOfOnePollCountsOnce(): void
    {
        $burst = [];
        foreach (range(0, 49) as $second) {
            $burst[] = ['device_id' => 3, 'datetime' => sprintf('2026-10-19 10:00:%02d', $second % 60), 'message' => 'STP designated root changed: a > b'];
        }
        $result = $this->analyze([], [], [], $burst);
        $finding = array_values(array_filter($result['findings'], fn ($f) => $f['code'] === 'root_changed'))[0];
        $this->assertSame('warning', $finding['severity']);
        $this->assertStringContainsString('acc1 (1)', $finding['detail']);

        $polls = [];
        foreach (['09:00', '09:05', '09:10'] as $time) {
            $polls[] = ['device_id' => 3, 'datetime' => "2026-10-19 $time:00", 'message' => 'STP designated root changed: a > b'];
        }
        $this->assertSame('critical', $this->codes($this->analyze([], [], [], $polls))['root_changed']);
    }

    public function testRootOutsideAnalyzedScopeIsNotReported(): void
    {
        $outside = '0000000000aa';
        $overrides = [];
        foreach ([1, 2, 3, 4] as $id) {
            $overrides[$id] = ['designatedRoot' => $outside, 'rootBridge' => 0];
        }
        $lookups = [$outside => ['owners' => [['device_id' => 99, 'port_id' => null, 'name' => 'dc-core', 'source' => 'stp']], 'fdb' => [], 'vendor' => '', 'hidden' => false]];
        $result = $this->analyze($overrides, [], [], [], $lookups);
        $codes = $this->codes($result);
        $this->assertArrayNotHasKey('root_unmanaged', $codes);
        $this->assertArrayNotHasKey('root_not_polled', $codes);
        $this->assertSame('dc-core', $result['domains'][0]['root']['name']);
        $this->assertSame(99, $result['domains'][0]['root']['device_id']);
    }

    public function testSummaryOfFilteredResult(): void
    {
        $result = $this->analyze([], [42 => ['state' => 'broken']]);
        $bridges = array_values(array_filter($result['bridges'], fn ($b) => $b['device_id'] === 4));
        $blocked = array_values(array_filter($result['blocked'], fn ($b) => $b['device_id'] === 4));
        $summary = StpAnalyzer::summarize($result['domains'], $bridges, $blocked, [], 3600);
        $this->assertSame(1, $summary['bridges']);
        $this->assertSame(1, $summary['instances']);
        $this->assertSame(0, $summary['blocked_ports'], 'broken ports are not counted as blocked');
        $this->assertSame('ok', $summary['status']);
    }

    public function testIndexHelpers(): void
    {
        $index = new StpIndex(CampusFixture::stpInstances([4 => ['designatedRoot' => '112233445566']]), CampusFixture::stpPorts(), CampusFixture::lagParent());
        $this->assertSame(['112233445566'], $index->unknownRootMacs());
        $this->assertSame(31, $index->rootPortId(3, null));
        $this->assertNull($index->rootPortId(1, null), 'root bridge has no root port');
        $this->assertContains(33, $index->portIds());
    }

    public function testRedundantPathThroughSwitchWithoutStpData(): void
    {
        // acc1 reports no STP data but is dual-homed to both cores (a loop STP must protect)
        $instances = array_values(array_filter(CampusFixture::stpInstances(), fn ($i) => $i['device_id'] !== 3));
        $ports = array_values(array_filter(CampusFixture::stpPorts(), fn ($p) => $p['device_id'] !== 3));
        $analyzer = new StpAnalyzer(CampusFixture::devices(), new StpIndex($instances, $ports, CampusFixture::lagParent()),
            CampusFixture::links(), CampusFixture::ports(), CampusFixture::lagParent(), new PluginSettings());
        $finding = array_values(array_filter($analyzer->analyze()['findings'], fn ($f) => $f['code'] === 'loop_without_stp'))[0] ?? null;
        $this->assertNotNull($finding);
        $this->assertSame([3], $finding['devices']);
    }

    public function testPvstKeySelection(): void
    {
        $index = new StpIndex([
            ['device_id' => 1, 'vlan' => null, 'bridgeAddress' => '000000000001', 'designatedRoot' => '000000000001'],
            ['device_id' => 1, 'vlan' => 10, 'bridgeAddress' => '000000000001', 'designatedRoot' => '000000000001'],
            ['device_id' => 2, 'vlan' => null, 'bridgeAddress' => '000000000002', 'designatedRoot' => '000000000001'],
        ]);
        $this->assertSame(10, $index->keyFor(1, 10));
        $this->assertNull($index->keyFor(1, 20)); // PVST device without that VLAN
        $this->assertSame(0, $index->keyFor(1, 1));
        $this->assertSame(0, $index->keyFor(2, 20)); // single instance (MST/RSTP) covers all VLANs
        $this->assertNull($index->keyFor(3, null));
    }
}
