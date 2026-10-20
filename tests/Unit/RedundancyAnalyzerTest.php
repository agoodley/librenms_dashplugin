<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Topology\TopologyService;
use PHPUnit\Framework\TestCase;

class RedundancyAnalyzerTest extends TestCase
{
    private const C1 = RedundancyFixture::CORE1;
    private const C2 = RedundancyFixture::CORE2;
    private const D1 = RedundancyFixture::DIST1;
    private const D2 = RedundancyFixture::DIST2;
    private const A1 = RedundancyFixture::ACC1;
    private const A2 = RedundancyFixture::ACC2;
    private const AP = RedundancyFixture::AP1;

    private static function codes(array $result): array
    {
        $codes = array_column($result['findings'], 'code');
        sort($codes);

        return $codes;
    }

    private static function find(array $result, string $code, ?string $titlePart = null): ?array
    {
        foreach ($result['findings'] as $finding) {
            if ($finding['code'] === $code && ($titlePart === null || str_contains($finding['title'], $titlePart))) {
                return $finding;
            }
        }

        return null;
    }

    public function testRedundantCampusHasNoFindings(): void
    {
        $result = RedundancyFixture::campus()->analyzer()->analyze();

        $this->assertSame([], $result['findings']);
        $this->assertSame('ok', $result['summary']['status']);
        $this->assertSame([['device_id' => self::C1, 'mode' => 'configured'], ['device_id' => self::C2, 'mode' => 'configured']], $result['core']['devices']);
        $this->assertSame('redundancy_core', $result['core']['setting']);

        // the access point hangs off acc1: impact without a finding
        $this->assertCount(2, $result['impact']);
        $this->assertSame([self::AP], $result['impact'][0]['devices']);
        $this->assertSame(0, $result['impact'][0]['network']);
        $this->assertSame('d:' . self::A1, $result['impact'][0]['fail']);
    }

    public function testSingleHomedAccessSwitch(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->link(8, 'Gi1/0/49', self::D1, 'Gi1/0/3');
        $result = $net->analyzer()->analyze();

        $this->assertSame(['spof_device', 'spof_link'], self::codes($result));

        $device = self::find($result, 'spof_device');
        $this->assertSame('info', $device['severity']);
        $this->assertSame('single point of failure: dist1', $device['title']);
        $this->assertStringContainsString('If dist1 fails, 1 monitored device loses its path to the core: acc3.', $device['detail']);
        $this->assertSame([self::D1, 8], $device['devices']);

        $link = self::find($result, 'spof_link');
        $this->assertSame('single link: acc3 Gi1/0/49 ↔ dist1 Gi1/0/3', $link['title']);
        $this->assertStringContainsString('acc3 has no other path to the core', $link['detail']);
        $this->assertEqualsCanonicalizing([$net->portId(8, 'Gi1/0/49'), $net->portId(self::D1, 'Gi1/0/3')], $link['ports']);
    }

    public function testSeverityFollowsTheNumberOfSwitchesCutOff(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->link(8, 'Gi1/0/49', self::D1, 'Gi1/0/3');
        $net->device(9, 'acc4')->link(9, 'Gi1/0/49', 8, 'Gi1/0/48');
        $net->device(10, 'ap2', 'wireless')->link(10, 'eth0', 9, 'Gi1/0/10');
        $result = $net->analyzer()->analyze();

        // dist1 and acc3's uplink cut off two switches: warning; acc3 and acc4's uplink one: info
        $this->assertSame('warning', self::find($result, 'spof_device', 'dist1')['severity']);
        $this->assertSame('warning', self::find($result, 'spof_link', 'acc3 Gi1/0/49')['severity']);
        $this->assertSame('info', self::find($result, 'spof_device', 'acc3')['severity']);
        $this->assertSame('info', self::find($result, 'spof_link', 'acc4 Gi1/0/49')['severity']);
        $this->assertNull(self::find($result, 'spof_device', 'acc4'), 'only an access point behind acc4');
        $this->assertStringContainsString('3 monitored devices lose their path to the core: acc3, acc4, ap2', self::find($result, 'spof_device', 'dist1')['detail']);

        // the biggest single point first, the impact table sorted by switches cut off
        $this->assertSame('spof_device', $result['findings'][0]['code']);
        $this->assertSame('dist1', $result['impact'][0]['label']);
        $this->assertSame(2, $result['impact'][0]['network']);

        $higher = $net->analyzer(['redundancy_warn_devices' => '3'])->analyze();
        $this->assertSame('info', self::find($higher, 'spof_device', 'dist1')['severity']);
    }

    public function testPortChannelUplinkIsNotASingleLink(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->lagLink(8, 'Port-channel1', ['Gi1/0/49', 'Gi1/0/50'], self::D1, 'Port-channel3', ['Gi1/0/3', 'Gi1/0/4']);
        $result = $net->analyzer()->analyze();

        // the members back each other up; dist1 itself stays a single point of failure
        $this->assertSame(['spof_device'], self::codes($result));
        $row = array_values(array_filter($result['impact'], fn ($r) => $r['kind'] === 'port-channel'))[0];
        $this->assertSame('acc3 Po1 ↔ dist1 Po3 (2 links)', $row['label']);
        $this->assertSame('p:' . $net->portId(self::D1, 'Gi1/0/3'), $row['fail']);
    }

    public function testSeveralSeparateLinksToTheSameSwitch(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->link(8, 'Gi1/0/49', self::D1, 'Gi1/0/3')->link(8, 'Gi1/0/50', self::D1, 'Gi1/0/4');
        $result = $net->analyzer()->analyze();

        $this->assertSame(['spof_device', 'uplinks_same_device'], self::codes($result));
        $finding = self::find($result, 'uplinks_same_device');
        $this->assertSame('info', $finding['severity']);
        $this->assertStringContainsString('acc3 (Gi1/0/49, Gi1/0/50) connects to dist1 over several separate links', $finding['detail']);
        $this->assertNull(self::find($result, 'spof_link'), 'parallel links back each other up');
    }

    public function testUplinksOnOneStackMemberFromTheInventory(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->link(8, 'Gi1/1/1', self::D1, 'Gi1/0/3')->link(8, 'Gi1/1/2', self::D2, 'Gi1/0/3');
        $net->device(9, 'acc4')->link(9, 'Gi1/0/49', 8, 'Gi2/0/48');
        $units = [8 => [
            $net->portId(8, 'Gi1/1/1') => ['key' => 'member11', 'label' => 'Switch 1'],
            $net->portId(8, 'Gi1/1/2') => ['key' => 'member11', 'label' => 'Switch 1'],
            $net->portId(8, 'Gi2/0/48') => ['key' => 'member12', 'label' => 'Switch 2'],
        ]];
        $result = $net->analyzer([], null, $units)->analyze();

        $finding = self::find($result, 'unit_spof');
        $this->assertSame('warning', $finding['severity']);
        $this->assertSame('single point of failure inside acc3: Switch 1', $finding['title']);
        $this->assertStringContainsString('All links that connect acc3 to the core leave from Switch 1 (Gi1/1/1 to dist1, Gi1/1/2 to dist2)', $finding['detail']);
        $this->assertStringContainsString('the rest of acc3 loses its path to the core, and with it 1 monitored device behind it: acc4.', $finding['detail']);
        $this->assertSame([8, 9], $finding['devices']);

        // spread over both members: no finding
        $units[8][$net->portId(8, 'Gi1/1/2')] = ['key' => 'member12', 'label' => 'Switch 2'];
        $this->assertNull(self::find($net->analyzer([], null, $units)->analyze(), 'unit_spof'));
    }

    public function testPortChannelLandingOnOneMemberOfTheFarStack(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->lagLink(8, 'Port-channel1', ['Gi1/0/49', 'Gi1/0/50'], self::D1, 'Port-channel3', ['Gi1/0/3', 'Gi1/0/4']);
        $units = [self::D1 => [
            $net->portId(self::D1, 'Gi1/0/3') => ['key' => 'member1', 'label' => 'Switch 1'],
            $net->portId(self::D1, 'Gi1/0/4') => ['key' => 'member1', 'label' => 'Switch 1'],
            $net->portId(self::D1, 'Gi1/0/1') => ['key' => 'member2', 'label' => 'Switch 2'],
        ]];
        $finding = self::find($net->analyzer([], null, $units)->analyze(), 'unit_spof');

        $this->assertSame('single point of failure inside dist1: Switch 1', $finding['title']);
        $this->assertStringContainsString('acc3 connects to dist1 only through Switch 1 (Po3 (Gi1/0/3, Gi1/0/4) to acc3)', $finding['detail']);
    }

    public function testPortChannelMemberWithUnknownPortMayUseAnotherUnit(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->lagLink(8, 'Port-channel1', ['Gi1/0/49', 'Gi1/0/50'], self::D1, 'Port-channel3', ['Gi1/0/3', 'Gi1/0/4']);
        $net->neighbor(8, 'Gi2/0/1', 'phone-1', 'Cisco IP Phone');
        // neither end resolved acc3's port of the second member: it may sit on either stack member
        $unknown = $net->portId(8, 'Gi1/0/50');
        foreach ($net->links as &$link) {
            if ($link['local_port_id'] === $unknown) {
                $link['local_port_id'] = 0;
            }
            if ($link['remote_port_id'] === $unknown) {
                $link['remote_port_id'] = null;
            }
        }
        unset($link);
        $units = [8 => [
            $net->portId(8, 'Gi1/0/49') => ['key' => 'member1', 'label' => 'Switch 1'],
            $net->portId(8, 'Gi2/0/1') => ['key' => 'member2', 'label' => 'Switch 2'],
        ]];

        $this->assertNull(self::find($net->analyzer([], null, $units)->analyze(), 'unit_spof'));
    }

    public function testUnitOfASingleCableIsLeftToTheSingleLinkFinding(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->link(8, 'Gi1/0/49', self::D1, 'Gi1/0/3');
        $units = [self::D1 => [
            $net->portId(self::D1, 'Gi1/0/3') => ['key' => 'member1', 'label' => 'Switch 1'],
            $net->portId(self::D1, 'Gi1/0/1') => ['key' => 'member2', 'label' => 'Switch 2'],
        ]];

        $this->assertNull(self::find($net->analyzer([], null, $units)->analyze(), 'unit_spof'));
    }

    public function testUnitsFromInterfaceNames(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'ex-vc', 'switch', ['os' => 'junos'])->link(8, 'xe-0/1/0', self::D1, 'Gi1/0/3')->link(8, 'xe-0/1/1', self::D2, 'Gi1/0/3');
        $net->neighbor(8, 'ge-1/0/1', 'phone-1', 'Cisco IP Phone');
        $finding = self::find($net->analyzer()->analyze(), 'unit_spof');

        $this->assertSame('single point of failure inside ex-vc: FPC 0', $finding['title']);

        // a device whose inventory maps ports does not fall back to names, even without units
        $this->assertNull(self::find($net->analyzer([], null, [8 => []])->analyze(), 'unit_spof'));

        // only switches and routers name ports by member / slot
        $net->devices[8]['role'] = 'server';
        $this->assertNull(self::find($net->analyzer()->analyze(), 'unit_spof'));
    }

    public function testRedundancyLostWhenAnUplinkIsDown(): void
    {
        $net = RedundancyFixture::campus();
        $net->setPort(self::A1, 'Gi1/0/50', ['ifOperStatus' => 'down'])->setPort(self::D2, 'Gi1/0/1', ['ifOperStatus' => 'down', 'ifAdminStatus' => 'down']);
        $result = $net->analyzer()->analyze();

        $this->assertSame(['redundancy_lost'], self::codes($result));
        $finding = $result['findings'][0];
        $this->assertSame('redundancy lost: acc1', $finding['title']);
        $this->assertSame(
            'acc1 normally has a second path to the core, but it is down: acc1 Gi1/0/50 ↔ dist2 Gi1/0/1 (shut down on dist2 Gi1/0/1). '
            . 'Single points of failure now: dist1; the link acc1 Gi1/0/49 ↔ dist1 Gi1/0/1. Behind it: ap1.',
            $finding['detail'],
        );
        $this->assertContains(self::AP, $finding['devices']);
        $this->assertContains($net->portId(self::D2, 'Gi1/0/1'), $finding['ports']);
    }

    public function testOutageWithDevicesBehindIt(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->link(8, 'Gi1/0/49', self::D2, 'Gi1/0/3');
        $net->device(9, 'acc4')->link(9, 'Gi1/0/49', 8, 'Gi1/0/48');
        $net->devices[self::D2]['status'] = 0;
        $net->devices[8]['status'] = 0;
        $net->devices[9]['status'] = 0;
        $result = $net->analyzer([], null, [], [self::D2 => 1700000000])->analyze();

        $outage = self::find($result, 'outage');
        $this->assertSame('critical', $outage['severity']);
        $this->assertSame('outage: dist2 is down, 2 devices behind it', $outage['title']);
        $this->assertSame('dist2 is down (since 2023-11-14 22:13 UTC). Only reachable through it, and down too: acc3, acc4.', $outage['detail']);
        $this->assertSame([['device_id' => self::D2, 'name' => 'dist2', 'since' => 1700000000, 'behind' => [8, 9], 'shared' => [], 'shared_with' => [],
            'unmanaged' => 0, 'isolated' => false]], $result['outages']);
        $this->assertSame(3, $result['summary']['down']);

        // acc1 and acc2 depend on dist1 alone now
        $lost = self::find($result, 'redundancy_lost');
        $this->assertSame('redundancy lost: acc1, acc2', $lost['title']);
        $this->assertStringContainsString('but it is down: dist2 is down.', $lost['detail']);
        $this->assertSame('critical', $result['summary']['status']);
    }

    public function testOutageSeenThroughAnUnmanagedSwitch(): void
    {
        $net = RedundancyFixture::campus();
        // acc3 hangs off an unmanaged switch below dist1, acc4 off acc3
        $net->device(8, 'acc3', 'switch', ['status' => 0]);
        $net->device(9, 'acc4', 'switch', ['status' => 0]);
        $pd = $net->port(self::D1, 'Gi1/0/5');
        $p8 = $net->port(8, 'Gi1/0/49');
        $net->links[] = RedundancyFixture::row(self::D1, $pd, 0, null, 'desk-switch', 'port1');
        $net->links[] = RedundancyFixture::row(8, $p8, 0, null, 'desk-switch', 'port2');
        $net->link(9, 'Gi1/0/49', 8, 'Gi1/0/48');
        $result = $net->analyzer()->analyze();

        $this->assertCount(1, $result['outages']);
        $this->assertSame(8, $result['outages'][0]['device_id']);
        $this->assertSame([9], $result['outages'][0]['behind']);
    }

    public function testDeviceWithoutPowerIsTheTopMostFailure(): void
    {
        // dist1 sees its port to acc3 go down with acc3: acc3 is still where the outage starts
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3', 'switch', ['status' => 0])->link(8, 'Gi1/0/49', self::D1, 'Gi1/0/3');
        $net->device(9, 'acc4', 'switch', ['status' => 0])->link(9, 'Gi1/0/49', 8, 'Gi1/0/48');
        $net->setPort(self::D1, 'Gi1/0/3', ['ifOperStatus' => 'down'])->setPort(8, 'Gi1/0/49', ['ifOperStatus' => 'down']);
        $result = $net->analyzer()->analyze();

        $this->assertSame('outage: acc3 is down, 1 device behind it', self::find($result, 'outage')['title']);
        $this->assertFalse($result['outages'][0]['isolated']);
    }

    public function testDevicesDownWithoutAnyUpNeighbor(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'site-sw1', 'switch', ['status' => 0])->device(9, 'site-sw2', 'switch', ['status' => 0]);
        $net->link(8, 'Gi1/0/1', 9, 'Gi1/0/1');
        $result = $net->analyzer()->analyze();

        $outage = self::find($result, 'outage');
        $this->assertSame('outage: 2 devices down, none of their neighbors is up: site-sw1', $outage['title']);
        $this->assertTrue($result['outages'][0]['isolated']);
        $this->assertSame([9], $result['outages'][0]['behind']);
    }

    public function testPortChannelDownToOneLink(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->lagLink(8, 'Port-channel1', ['Gi1/0/49', 'Gi1/0/50'], self::D1, 'Port-channel3', ['Gi1/0/3', 'Gi1/0/4']);
        $net->setPort(8, 'Gi1/0/50', ['ifOperStatus' => 'down']);
        $finding = self::find($net->analyzer()->analyze(), 'redundancy_lost');

        $this->assertSame('port-channel down to one link: dist1 Po3 ↔ acc3 Po1 (2 links)', $finding['title']);
        $this->assertStringContainsString('acc3 depends on this port-channel alone, and it runs on 1 of its 2 links (down: dist1 Gi1/0/4)', $finding['detail']);
    }

    public function testOnlyCoreDevice(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->link(8, 'Gi1/0/49', self::C1, 'Gi1/0/48');
        $result = $net->analyzer(['redundancy_core' => 'core1'])->analyze();

        // core2 and everything dual-homed keeps working: only acc3 is cut off from the largest part
        $finding = self::find($result, 'spof_device', 'core1');
        $this->assertStringStartsWith('core1 is the only core device of this part of the network. If core1 fails, the network splits into 2 parts and 1 monitored device', $finding['detail']);
        $this->assertSame([self::C1, 8], $finding['devices']);
    }

    public function testAutomaticCoreAndSpanningTreeRoot(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->link(8, 'Gi1/0/49', self::D1, 'Gi1/0/3');

        $automatic = $net->analyzer(['redundancy_core' => ''])->analyze();
        $this->assertSame(['automatic'], array_values(array_unique(array_column($automatic['core']['devices'], 'mode'))));
        $this->assertNull($automatic['core']['setting']);
        // whichever switch is the reference, acc3 depends on dist1 and its uplink
        $this->assertSame(['spof_device', 'spof_link'], self::codes($automatic));

        $stp = $net->analyzer(['redundancy_core' => ''], null, [], [], [self::C1 => 24577, self::C2 => 28673, self::D1 => 32769])->analyze();
        $this->assertSame([['device_id' => self::C1, 'mode' => 'stp']], $stp['core']['devices']);

        $preferred = $net->analyzer(['redundancy_core' => '', 'stp_preferred_roots' => 'core2.example.net'])->analyze();
        $this->assertSame([['device_id' => self::C2, 'mode' => 'configured']], $preferred['core']['devices']);
        $this->assertSame('stp_preferred_roots', $preferred['core']['setting']);
    }

    public function testUnmanagedSwitchAsSinglePointOfFailure(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->device(9, 'acc4');
        $pd = $net->port(self::D1, 'Gi1/0/5');
        $net->links[] = RedundancyFixture::row(self::D1, $pd, 0, null, 'old-switch', 'port1', 'HP ProCurve Switch');
        foreach ([8, 9] as $deviceId) {
            $net->links[] = RedundancyFixture::row($deviceId, $net->port($deviceId, 'Gi1/0/49'), 0, null, 'old-switch', 'port' . $deviceId, 'HP ProCurve Switch');
        }
        $finding = self::find($net->analyzer()->analyze(), 'spof_device', 'old-switch');

        $this->assertSame('single point of failure: old-switch (unmanaged neighbor)', $finding['title']);
        $this->assertSame('warning', $finding['severity']);
        $this->assertSame([8, 9], $finding['devices']);
    }

    public function testScopeAndDisabledChecks(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->link(8, 'Gi1/0/49', self::D1, 'Gi1/0/3');

        // paths are analyzed through the whole network, findings kept when a device is in scope
        $this->assertSame(['spof_device', 'spof_link'], self::codes($net->analyzer([], [8])->analyze()));
        $this->assertSame([], $net->analyzer([], [self::A2])->analyze()['findings']);
        $this->assertSame(1, $net->analyzer([], [self::A2])->analyze()['summary']['devices']);

        $this->assertSame(['spof_device'], self::codes($net->analyzer(['redundancy_disabled_checks' => 'spof_link'])->analyze()));
        $this->assertSame([], $net->analyzer(['redundancy_disabled_checks' => 'spof'])->analyze()['findings']);
    }

    public function testDeviceFilter(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->link(8, 'Gi1/0/49', self::D1, 'Gi1/0/3');
        $result = TopologyService::filterRedundancyForDevice($net->analyzer()->analyze(), self::A1);

        $this->assertSame([], $result['findings']);
        $this->assertSame(['device', 'link'], array_column($result['impact'], 'kind')); // acc1 and its link cut off ap1
        $this->assertSame('ok', $result['summary']['status']);

        $acc3 = TopologyService::filterRedundancyForDevice($net->analyzer()->analyze(), 8);
        $this->assertSame(['spof_device', 'spof_link'], self::codes($acc3));
    }

    public function testCoreNodesForTheMap(): void
    {
        $this->assertSame(['d:1' => 'configured', 'd:2' => 'configured'], RedundancyFixture::campus()->analyzer()->coreNodes());
    }

    public function testDisabledNeighborStillPassesTraffic(): void
    {
        // acc3 reaches dist2 only through a switch that is not analyzed (disabled in LibreNMS)
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->link(8, 'Gi1/0/49', self::D1, 'Gi1/0/3')->device(9, 'old-sw')->link(8, 'Gi1/0/50', 9, 'Gi1/0/1')->link(9, 'Gi1/0/2', self::D2, 'Gi1/0/3');
        unset($net->devices[9]);
        $net->links = array_values(array_filter($net->links, fn ($l) => $l['local_device_id'] !== 9));
        foreach ($net->links as &$link) {
            if ($link['remote_device_id'] === 9) {
                $link['remote_hostname'] = 'old-sw';
            }
        }
        unset($link);

        // acc3 and dist2 both see old-sw: it joins them like an unmanaged switch
        $this->assertSame([], $net->analyzer()->analyze()['findings']);

        // without a name each report is a separate dead end and acc3 depends on dist1
        foreach ($net->links as &$link) {
            $link['remote_hostname'] = '';
        }
        unset($link);
        $this->assertSame(['spof_device', 'spof_link'], self::codes($net->analyzer()->analyze()));
    }
}
