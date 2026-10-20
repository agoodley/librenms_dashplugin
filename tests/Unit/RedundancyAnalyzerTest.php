<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

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
        // simulating the row fails the whole port-channel, not one member
        $this->assertSame('l:d:3|d:8', $row['fail']);
        $simulated = $net->analyzer()->simulate([$row['fail']]);
        $this->assertSame(['d:8'], array_column($simulated['lost'], 'node'));
        $this->assertSame('dist1 Po3 ↔ acc3 Po1 (2 links)', $simulated['failed'][0]['label']);
    }

    public function testSimulatePortChannelToASwitchPair(): void
    {
        // acc3 has one port-channel to the dist1/dist2 MLAG pair, two cables to each, and a member
        // that was never cabled
        $net = RedundancyFixture::campus();
        $net->device(10, 'acc3');
        $po = $net->port(10, 'Port-channel1', ['ifType' => 'ieee8023adLag']);
        $net->lag[$net->port(10, 'Gi1/0/52', ['ifOperStatus' => 'down'])] = $po;
        foreach ([self::D1 => ['Gi1/0/48', 'Gi1/0/49'], self::D2 => ['Gi1/0/50', 'Gi1/0/51']] as $dist => $members) {
            $parent = $net->port($dist, 'Port-channel10', ['ifType' => 'ieee8023adLag']);
            foreach ($members as $index => $member) {
                $net->link(10, $member, $dist, 'Gi1/0/1' . $index);
                $net->lag[$net->portId(10, $member)] = $po;
                $net->lag[$net->portId($dist, 'Gi1/0/1' . $index)] = $parent;
            }
        }
        $analyzer = $net->analyzer();
        $lost = fn (array $refs) => array_column($analyzer->simulate($refs)['lost'], 'node');

        // the cables to dist1 (what the map sends for that edge): dist2 carries on
        $this->assertSame([], $lost(['l:d:10|d:3']));
        $this->assertSame('acc3 Po1 ↔ dist1 Po10 (2 links)', $analyzer->simulate(['l:d:10|d:3'])['failed'][0]['label']);
        $this->assertSame([], $lost(['p:' . $net->portId(self::D1, 'Port-channel10')]));

        // acc3's port-channel: the cables to both switches
        $result = $analyzer->simulate(['p:' . $po]);
        $this->assertSame(['d:10'], array_column($result['lost'], 'node'));
        $this->assertSame('acc3 Po1 ↔ dist1, dist2', $result['failed'][0]['label']);

        // two devices without a link between them: nothing to fail
        $this->assertSame([], $analyzer->simulate(['l:d:10|d:1'])['failed']);
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
        $result = $net->analyzer()->analyze(self::A1);

        $this->assertSame([], $result['findings']);
        $this->assertSame(['device', 'link'], array_column($result['impact'], 'kind')); // acc1 and its link cut off ap1
        $this->assertSame('ok', $result['summary']['status']);
        $this->assertSame(1, $result['summary']['devices']);

        $this->assertSame(['spof_device', 'spof_link'], self::codes($net->analyzer()->analyze(8)));
    }

    public function testDualHomedServerDoesNotJoinTwoSwitches(): void
    {
        // acc1 hangs off dist1 alone; a server cabled to acc1 and acc2 is no path between them
        $net = RedundancyFixture::campus()->unlink(self::A1, self::D2);
        $net->device(8, 'esx1', 'server')->link(8, 'vmnic0', self::A1, 'Gi1/0/30')->link(8, 'vmnic1', self::A2, 'Gi1/0/30');
        $result = $net->analyzer()->analyze();

        $this->assertSame(['spof_device', 'spof_link'], self::codes($result));
        $this->assertStringContainsString('lose their path to the core: acc1, ap1.', self::find($result, 'spof_device', 'dist1')['detail']);
        // the server itself keeps a path through acc2 when dist1 fails
        $this->assertNotContains(8, self::find($result, 'spof_device', 'dist1')['devices']);

        // the same for a server LibreNMS only knows from CDP (platform) or LLDP (system description only)
        $net = RedundancyFixture::campus()->unlink(self::A1, self::D2);
        $net->neighbor(self::A1, 'Gi1/0/30', 'esx01', 'VMware ESXi 8.0.2')->neighbor(self::A2, 'Gi1/0/30', 'esx01', 'VMware ESXi 8.0.2');
        $this->assertSame(['spof_device', 'spof_link'], self::codes($net->analyzer()->analyze()));
        $net = RedundancyFixture::campus()->unlink(self::A1, self::D2);
        $esx = 'VMware ESXi 8.0.2 build-22380479 VMware, Inc. VMware7,1';
        $net->neighbor(self::A1, 'Gi1/0/30', 'esx01', '', $esx)->neighbor(self::A2, 'Gi1/0/30', 'esx01', '', $esx);
        $this->assertSame(['spof_device', 'spof_link'], self::codes($net->analyzer()->analyze()));
    }

    public function testServerThatLostOneOfItsLinks(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'esx1', 'server')->link(8, 'vmnic0', self::A1, 'Gi1/0/30')->link(8, 'vmnic1', self::A2, 'Gi1/0/30');
        $net->setPort(8, 'vmnic1', ['ifOperStatus' => 'down'])->setPort(self::A2, 'Gi1/0/30', ['ifOperStatus' => 'down']);
        $finding = self::find($net->analyzer()->analyze(), 'redundancy_lost');

        $this->assertSame('redundancy lost: esx1', $finding['title']);
        $this->assertStringContainsString('but it is down: esx1 vmnic1 ↔ acc2 Gi1/0/30 (link down). Single points of failure now: acc1', $finding['detail']);
    }

    public function testUpServerIsNoWayIntoAnOutage(): void
    {
        // acc3 is down behind dist1's single link; acc4 behind acc3 shares an up server with acc1
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3', 'switch', ['status' => 0])->link(8, 'Gi1/0/49', self::D1, 'Gi1/0/3');
        $net->device(9, 'acc4', 'switch', ['status' => 0])->link(9, 'Gi1/0/49', 8, 'Gi1/0/48');
        $net->device(10, 'esx1', 'server')->link(10, 'vmnic0', 9, 'Gi1/0/30')->link(10, 'vmnic1', self::A1, 'Gi1/0/30');
        $result = $net->analyzer()->analyze();

        $this->assertSame(['outage: acc3 is down, 1 device behind it'], array_column(array_filter($result['findings'], fn ($f) => $f['code'] === 'outage'), 'title'));
        $this->assertSame([[8, [9]]], array_map(fn ($o) => [$o['device_id'], $o['behind']], $result['outages']));
    }

    public function testDownServerIsItsOwnOutage(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'esx1', 'server', ['status' => 0])->link(8, 'vmnic0', self::A1, 'Gi1/0/30')->link(8, 'vmnic1', self::A2, 'Gi1/0/30');
        $result = $net->analyzer()->analyze();

        $this->assertSame([[8, [], false]], array_map(fn ($o) => [$o['device_id'], $o['behind'], $o['isolated']], $result['outages']));
        $this->assertNull(self::find($result, 'outage'));
    }

    public function testDevicesWithoutNeighborsAreNeitherCoreNorOutages(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'wan-rtr1', 'router')->device(9, 'oldswitch')->device(10, 'ups1', 'other', ['status' => 0]);
        $result = $net->analyzer()->analyze();

        $this->assertSame([self::C1, self::C2], array_column($result['core']['devices'], 'device_id'));
        $this->assertSame([], $result['outages']);
        $automatic = array_column($net->analyzer(['redundancy_core' => ''])->analyze()['core']['devices'], 'device_id');
        $this->assertSame([], array_intersect([8, 9], $automatic));
    }

    public function testUnitFindingNamesOnlyTheLinksTowardTheCore(): void
    {
        // acc3's uplinks and its access point are on member 1, phones on member 2
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->link(8, 'Gi1/0/49', self::D1, 'Gi1/0/3')->link(8, 'Gi1/0/50', self::D2, 'Gi1/0/3');
        $net->device(9, 'ap3', 'wireless')->link(9, 'eth0', 8, 'Gi1/0/10');
        $net->neighbor(8, 'Gi2/0/1', 'phone-1', 'Cisco IP Phone');
        $finding = self::find($net->analyzer()->analyze(), 'unit_spof');

        $this->assertStringContainsString('leave from member/slot 1 (Gi1/0/49 to dist1, Gi1/0/50 to dist2): if member/slot 1 fails', $finding['detail']);
        $this->assertNotContains($net->portId(8, 'Gi1/0/10'), $finding['ports']);
    }

    public function testEqualPiecesAreAllCutOffWhenTheOnlyCoreFails(): void
    {
        $net = new RedundancyFixture;
        $net->device(1, 'dist')->device(2, 'a1')->device(3, 'a2')->device(4, 'a3');
        foreach ([2, 3, 4] as $access) {
            $net->link($access, 'Gi1/0/49', 1, 'Gi1/0/' . $access);
        }
        $finding = self::find($net->analyzer(['redundancy_core' => ''])->analyze(), 'spof_device', 'dist');

        $this->assertSame([1, 2, 3, 4], $finding['devices']);
        $this->assertStringContainsString('the network splits into 3 parts of the same size and 3 monitored devices lose their connection: a1, a2, a3.', $finding['detail']);
    }

    public function testOneDeviceCutOffFromTheLargestPartIsSingular(): void
    {
        $net = new RedundancyFixture;
        $net->device(1, 'dist')->device(2, 'a1')->device(3, 'a2')->device(4, 'a3');
        $net->link(2, 'Gi1/0/49', 1, 'Gi1/0/2')->link(3, 'Gi1/0/49', 1, 'Gi1/0/3')->link(2, 'Gi1/0/50', 3, 'Gi1/0/50')->link(4, 'Gi1/0/49', 1, 'Gi1/0/4');
        $finding = self::find($net->analyzer(['redundancy_core' => ''])->analyze(), 'spof_device', 'dist');

        $this->assertStringContainsString('the network splits into 2 parts and 1 monitored device is cut off from the largest of them: a3.', $finding['detail']);
    }

    public function testLinkWithoutPortsIsSimulatedByItsDevices(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3');
        $net->links[] = RedundancyFixture::row(8, 0, self::D1, null, 'dist1', '');
        $row = array_values(array_filter($net->analyzer()->analyze()['impact'], fn ($r) => $r['kind'] === 'link'))[0];

        $this->assertSame('l:d:3|d:8', $row['fail']);
    }

    public function testImpactTotalIsCountedBeforeTheTableIsCut(): void
    {
        // a daisy chain of 170 switches: every switch and link but the last cuts off the rest
        $net = new RedundancyFixture;
        $net->device(1, 'core');
        for ($id = 2; $id <= 171; $id++) {
            $net->device($id, "sw$id")->link($id, 'Gi1/0/49', $id - 1, 'Gi1/0/48');
        }
        $result = $net->analyzer(['redundancy_core' => 'core'])->analyze();

        $this->assertCount(300, $result['impact']);
        $this->assertSame(339, $result['impact_total']);
        $this->assertSame(339, $result['summary']['single_points']);
    }

    public function testSimulateFailures(): void
    {
        $net = RedundancyFixture::campus();
        $net->device(8, 'acc3')->lagLink(8, 'Port-channel1', ['Gi1/0/49', 'Gi1/0/50'], self::D1, 'Port-channel3', ['Gi1/0/3', 'Gi1/0/4']);
        $net->neighbor(self::A2, 'Gi1/0/20', 'old-sw', 'HP ProCurve')->neighbor(self::A1, 'Gi1/0/20', 'old-sw', 'HP ProCurve');
        $analyzer = $net->analyzer();
        $lost = fn (array $refs) => array_column($analyzer->simulate($refs)['lost'], 'node');

        // dual-homed devices keep their path; acc3 is behind dist1
        $result = $analyzer->simulate(['d:' . self::D1]);
        $this->assertSame([['node' => 'd:8', 'name' => 'acc3', 'device_id' => 8, 'network' => true]], $result['lost']);
        $this->assertSame([['ref' => 'd:3', 'label' => 'dist1']], $result['failed']);
        $this->assertSame(1, $result['network']);
        $this->assertSame('core', $result['reference']);

        // one member of the port-channel: the other one carries on; the port-channel: both fail
        $member = $net->portId(8, 'Gi1/0/49');
        $this->assertSame([], $lost(['p:' . $member]));
        $this->assertSame('acc3 Gi1/0/49 ↔ dist1 Gi1/0/3 (one link of dist1 Po3 ↔ acc3 Po1 (2 links))', $analyzer->simulate(['p:' . $member])['failed'][0]['label']);
        $this->assertSame(['d:8'], $lost(['p:' . $net->portId(8, 'Port-channel1')]));
        $this->assertSame(['d:8'], $lost(['p:' . $member, 'p:' . $net->portId(self::D1, 'Gi1/0/4')]));
        $this->assertSame(['d:8'], $lost(['l:d:8|d:3']));

        // failures add up; the shared unmanaged switch keeps acc1 until it fails too, then ap1 goes with acc1
        $this->assertSame([], $lost(['d:' . self::D2, 'p:' . $net->portId(self::A1, 'Gi1/0/49')]));
        $this->assertSame(['d:' . self::A1, 'd:' . self::AP], $lost(['d:' . self::D2, 'p:' . $net->portId(self::A1, 'Gi1/0/49'), 'u:old-sw']));

        // both core devices: the largest remaining part keeps working
        $both = $analyzer->simulate(['d:' . self::C1, 'd:' . self::C2]);
        $this->assertSame('largest part', $both['reference']);
        $this->assertSame([], $both['lost']);

        // what is down already is not lost again; unknown references are ignored
        $net->devices[8]['status'] = 0;
        $result = $net->analyzer()->simulate(['d:' . self::D1, 'd:999', 'x:1', 'p:99999']);
        $this->assertSame([], $result['lost']);
        $this->assertSame(['dist1'], array_column($result['failed'], 'label'));
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
