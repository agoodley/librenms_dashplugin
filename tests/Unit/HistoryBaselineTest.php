<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\History\Baseline;
use Agoodley\LibrenmsTopology\History\ChangeReport;
use Agoodley\LibrenmsTopology\History\FactCollector;
use Agoodley\LibrenmsTopology\History\FactText;
use Agoodley\LibrenmsTopology\History\FindingBadges;
use Agoodley\LibrenmsTopology\History\FindingHistory;
use Agoodley\LibrenmsTopology\History\Runs;
use Agoodley\LibrenmsTopology\History\Window;
use PHPUnit\Framework\TestCase;

class HistoryBaselineTest extends TestCase
{
    private static function collector(RedundancyFixture $net): FactCollector
    {
        return new FactCollector(array_map(fn ($d) => $d['display'], $net->devices), $net->ports);
    }

    public function testCompareLeavesBlockedPortsOut(): void
    {
        $baseline = FactCollector::fact('link', 'a|b', 1, 2, []) + FactCollector::fact('lag', 'c', 1, null, []);
        $current = FactCollector::fact('link', 'a|b', 1, 2, []) + FactCollector::fact('ospf', 'o', 1, 2, [])
            + FactCollector::fact('stp_blocked', 'x', 1, null, []);

        $result = Baseline::compare($baseline, $current);

        $this->assertSame(['lag'], array_column($result['missing'], 'kind'));
        $this->assertSame(['ospf'], array_column($result['new'], 'kind'));
    }

    public function testMissingBaselineUplinkIsLostRedundancyNotASinglePointOfFailure(): void
    {
        $net = RedundancyFixture::campus();
        $baseline = self::collector($net)->links($net->links);

        // LibreNMS deleted the LLDP rows of acc1's uplink to dist2 at the next discovery
        $net->unlink(RedundancyFixture::ACC1, RedundancyFixture::DIST2);
        $withoutBaseline = $net->analyzer()->analyze();
        $this->assertNotNull(self::find($withoutBaseline, 'spof_link', 'acc1'), 'without a baseline acc1 looks single-homed by design');

        $missing = Baseline::goneLinks($baseline, self::collector($net)->links($net->links));
        $this->assertCount(1, $missing);
        $rows = Baseline::linkRows($missing, $net->devices, [array_key_first($missing) => '2026-10-20 14:00:00']);
        $this->assertEqualsCanonicalizing([RedundancyFixture::ACC1, RedundancyFixture::DIST2], [$rows[0]['local_device_id'], $rows[0]['remote_device_id']]);
        $this->assertSame('baseline', $rows[0]['protocol']);
        $net->links = array_merge($net->links, $rows);

        $result = $net->analyzer()->analyze();
        $lost = self::find($result, 'redundancy_lost', 'acc1');
        $this->assertNotNull($lost, 'the missing uplink is redundancy lost');
        $this->assertStringContainsString('missing since 2026-10-20 14:00: in the baseline, no longer reported by LLDP/CDP', $lost['detail']);
        $this->assertStringContainsString('an admin accepts that on the Topology Changes page: plugin/topology/changes?device=', $lost['recommendation']);
        $this->assertNull(self::find($result, 'spof_link', 'acc1'), 'by design acc1 still has two uplinks');
    }

    public function testMovedCableIsNoMissingLink(): void
    {
        $net = RedundancyFixture::campus();
        $baseline = self::collector($net)->links($net->links);

        // acc2's uplink to dist2 now goes to dist1 on the same port: a moved cable, not a lost path
        $net->unlink(RedundancyFixture::ACC2, RedundancyFixture::DIST2);
        $net->links[] = RedundancyFixture::row(RedundancyFixture::ACC2, $net->portId(RedundancyFixture::ACC2, 'Gi1/0/50'), RedundancyFixture::DIST1,
            $net->port(RedundancyFixture::DIST1, 'Gi1/0/9'), '', 'Gi1/0/9');
        $current = self::collector($net)->links($net->links);

        $this->assertCount(1, array_diff_key($baseline, $current));
        $this->assertSame([], Baseline::goneLinks($baseline, $current));

        // a cable pulled for good is gone
        $net->unlink(RedundancyFixture::ACC1, RedundancyFixture::DIST2);
        $this->assertCount(1, Baseline::goneLinks($baseline, self::collector($net)->links($net->links)));
    }

    public function testMissingLinksOfDevicesOutsideTheAnalysisAreLeftOut(): void
    {
        $net = RedundancyFixture::campus();
        $facts = self::collector($net)->links($net->links);
        $devices = $net->devices;
        unset($devices[RedundancyFixture::DIST2]); // a device the user may not see

        $rows = Baseline::linkRows($facts, $devices);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertNotSame(RedundancyFixture::DIST2, $row['local_device_id']);
            $this->assertNotSame(RedundancyFixture::DIST2, $row['remote_device_id']);
            $this->assertSame('', $row['missing']);
        }
    }

    public function testUnmanagedNeighborLinkComesBackAsUnmanaged(): void
    {
        $net = RedundancyFixture::campus();
        $net->neighbor(RedundancyFixture::ACC2, 'Gi1/0/30', 'old-sw', 'HP ProCurve');
        $facts = array_filter(self::collector($net)->links($net->links), fn ($fact) => isset($fact['data']['b']['node']));

        $rows = Baseline::linkRows($facts, $net->devices);

        $this->assertSame([[RedundancyFixture::ACC2, 0, 'old-sw', 'HP ProCurve']],
            array_map(fn ($r) => [$r['local_device_id'], $r['remote_device_id'], $r['remote_hostname'], $r['remote_platform']], $rows));
    }

    public function testFindingIdentity(): void
    {
        $finding = ['code' => 'spof_link', 'severity' => 'warning', 'title' => 'single link: a', 'devices' => [3, 1], 'ports' => [20, 10], 'vlan' => null];

        $this->assertSame(FindingHistory::hash('redundancy', $finding), FindingHistory::hash('redundancy', ['devices' => [1, 3, 3], 'ports' => [10, 20], 'title' => 'other wording'] + $finding));
        $this->assertNotSame(FindingHistory::hash('redundancy', $finding), FindingHistory::hash('links', $finding));
        $this->assertNotSame(FindingHistory::hash('stp', $finding), FindingHistory::hash('stp', ['vlan' => 30] + $finding));
    }

    /**
     * Runs of one analysis over the findings history, like HistoryService::checks() with the database.
     *
     * @param  array<int, array<int, array>>  $runs  findings of each run; the first run is the initial one
     * @return array<int, array<int, array>> eventlog entries of each run
     */
    private static function simulate(array $runs, string $minimum = 'warning', array $skip = []): array
    {
        $rows = [];
        $entries = [];
        foreach ($runs as $index => $findings) {
            $time = sprintf('2026-10-20 %02d:07:00', $index);
            $open = array_values(array_filter($rows, fn ($row) => $row['resolved_at'] === null));
            $run = FindingHistory::run('links', $open, $findings, $time, $index === 0);
            foreach ($run['insert'] as $hash => $finding) {
                $id = count($rows) + 1;
                $rows[$id] = ['id' => $id, 'hash' => $hash, 'code' => $finding['code'], 'severity' => $finding['severity'], 'title' => FindingHistory::title($finding),
                    'devices' => $finding['devices'], 'confirmed' => in_array($hash, $run['confirmed'], true), 'missed_at' => null, 'first_seen' => $time, 'resolved_at' => null];
            }
            foreach ($run['resolve'] as $id => $at) {
                $rows[$id]['resolved_at'] = $at;
            }
            foreach ($run['miss'] as $id) {
                $rows[$id]['missed_at'] = $time;
            }
            foreach ($run['unmiss'] as $id) {
                $rows[$id]['missed_at'] = null;
            }
            foreach ($run['confirm'] as $id) {
                $rows[$id]['confirmed'] = true;
            }
            $entries[] = $index === 0 ? [] : FindingHistory::eventlog('links', $run['before'], $run['after'], $minimum, $skip);
        }

        return $entries;
    }

    private static function finding(string $code, array $devices, array $ports = [], string $severity = 'warning', string $title = 'a finding'): array
    {
        return ['code' => $code, 'severity' => $severity, 'title' => $title, 'detail' => 'acc-sw8, acc-sw9 lose their path', 'devices' => $devices, 'ports' => $ports, 'vlan' => null];
    }

    public function testFindingIsLoggedOnceTwoRunsInARowReportIt(): void
    {
        $finding = self::finding('speed_mismatch', [3, 1], [31, 12]);

        $entries = self::simulate([[], [$finding], [$finding], [$finding], [], []]);

        $this->assertSame([], $entries[1], 'first report: not confirmed yet');
        $this->assertSame([[1, 'warning'], [3, 'warning']], array_map(fn ($e) => [$e['device_id'], $e['severity']], $entries[2]), 'two runs in a row');
        $this->assertSame([], $entries[3]);
        $this->assertSame([], $entries[4], 'one run without it: not resolved yet');
        $this->assertSame(['ok', 'ok'], array_column($entries[5], 'severity'));
        $this->assertSame('Topology resolved: Speed mismatch (link & VLAN check)', $entries[5][0]['message']);
    }

    public function testFlickeringFindingDoesNotFillTheEventlog(): void
    {
        // a link whose error counter of the last poll crosses the threshold in some runs only
        $finding = self::finding('speed_mismatch', [3, 1], [31, 12]);
        $runs = array_map(fn ($present) => $present ? [$finding] : [], [1, 0, 1, 1, 0, 1, 0, 1]);

        $this->assertSame(0, array_sum(array_map('count', self::simulate($runs))), 'present at the start, never gone two runs in a row');
        $this->assertSame(2, array_sum(array_map('count', self::simulate(array_merge([[]], $runs)))), 'confirmed once (2 devices), never resolved');
    }

    public function testCountersOfTheLastPollAreKeptOutOfTheEventlog(): void
    {
        $finding = self::finding('link_errors', [3, 1], [31, 12]);

        $this->assertSame([], array_merge(...self::simulate([[], [$finding], [$finding]], 'warning', ['link_errors'])));
    }

    public function testEventlogEntryNamesTheCheckOnly(): void
    {
        $spof = self::finding('spof_device', [5, 11, 12], [], 'critical', 'single point of failure: acc-sw3');

        $entries = self::simulate([[], [$spof], [$spof]]);

        $this->assertSame([5, 11, 12], array_column($entries[2], 'device_id'));
        $this->assertSame('Topology critical: Single point of failure (device) (link & VLAN check), details: plugin/topology/links?device=5', $entries[2][0]['message']);
        $this->assertSame('topology:links:spof_device', $entries[2][0]['reference']);
        foreach ($entries[2] as $entry) {
            $this->assertDoesNotMatchRegularExpression('/acc-sw|lose their path/', $entry['message'], 'the eventlog of a device is readable by everyone who may see it');
        }
        $this->assertSame('Port-channel imbalance', FindingHistory::label('lag_imbalance'));
        $this->assertSame('MTU mismatch', FindingHistory::label('mtu_mismatch'));
    }

    public function testDeviceGetsEntriesWhenItGetsItsFirstOrLosesItsLastFindingOfACheck(): void
    {
        // "inter-switch ports without spanning tree" now also lists device 4 and no longer device 2 (a new identity)
        $before = self::finding('stp_disabled_port', [1, 2, 3], [10, 20, 30]);
        $after = self::finding('stp_disabled_port', [1, 3, 4], [10, 30, 40]);

        $entries = self::simulate([[], [$before], [$before], [$after], [$after], [$after]]);

        $this->assertSame([[4, 'warning']], array_map(fn ($e) => [$e['device_id'], $e['severity']], $entries[3]),
            'the check goes on on devices 1 and 3: the new extent is confirmed at once, the old one is missed once');
        $this->assertSame([[2, 'ok']], array_map(fn ($e) => [$e['device_id'], $e['severity']], $entries[4]), 'the old extent is resolved');
        $this->assertSame([], $entries[5]);

        $many = self::finding('no_stp_data', range(1, 14));
        $capped = self::simulate([[], [$many], [$many]])[2];
        $this->assertCount(FindingHistory::EVENTLOG_DEVICES, $capped);
        $this->assertStringEndsWith('(also on 4 more devices)', $capped[0]['message']);
        $this->assertSame([], self::simulate([[], [$many], [$many]], 'critical')[2]);
        $this->assertSame([], self::simulate([[], [$many], [$many]], 'none')[2]);
    }

    public function testFindingHistoryRun(): void
    {
        $kept = self::finding('a', [1]);
        $changed = self::finding('b', [2], [], 'critical', 'worse now');
        $open = [
            ['id' => 1, 'hash' => FindingHistory::hash('stp', $kept), 'code' => 'a', 'severity' => 'warning', 'title' => 'A finding', 'devices' => [1],
                'confirmed' => true, 'missed_at' => null, 'first_seen' => '2026-10-20 01:00:00'],
            ['id' => 2, 'hash' => FindingHistory::hash('stp', $changed), 'code' => 'b', 'severity' => 'warning', 'title' => 'A finding', 'devices' => [2],
                'confirmed' => false, 'missed_at' => null, 'first_seen' => '2026-10-20 01:00:00'],
            ['id' => 3, 'hash' => sha1('gone'), 'code' => 'c', 'severity' => 'warning', 'title' => 'Gone', 'devices' => [3],
                'confirmed' => true, 'missed_at' => '2026-10-20 01:00:00', 'first_seen' => '2026-10-19 01:00:00'],
            ['id' => 4, 'hash' => FindingHistory::hash('stp', $kept), 'code' => 'a', 'severity' => 'warning', 'title' => 'A finding', 'devices' => [1],
                'confirmed' => true, 'missed_at' => null, 'first_seen' => '2026-10-20 01:00:00'],
            ['id' => 5, 'hash' => sha1('blip'), 'code' => 'd', 'severity' => 'warning', 'title' => 'Blip', 'devices' => [5],
                'confirmed' => false, 'missed_at' => null, 'first_seen' => '2026-10-20 01:00:00'],
        ];

        $run = FindingHistory::run('stp', $open, [$kept, $changed, self::finding('c2', [9])], '2026-10-20 02:00:00', false);

        $this->assertSame([FindingHistory::hash('stp', self::finding('c2', [9]))], array_keys($run['insert']));
        $this->assertSame([3 => '2026-10-20 01:00:00', 4 => '2026-10-20 02:00:00', 5 => '2026-10-20 02:00:00'], $run['resolve'],
            'gone twice in a row: resolved since the first miss; a duplicate period; never confirmed: resolved now');
        $this->assertSame([2], $run['confirm']);
        $this->assertSame([2], array_keys($run['update']));
        $this->assertSame([], $run['miss']);
    }

    public function testInitialRunsAreTheFirstSuccessfulOnes(): void
    {
        $this->assertTrue(Runs::initial(null, 'stp'));
        $failed = ['analyses' => ['stp' => ['error' => 1]], 'recorded' => Runs::record(null, [])];
        $this->assertTrue(Runs::initial($failed, 'stp'), 'a run that failed recorded nothing');
        $done = ['recorded' => Runs::record($failed, ['stp', 'links'])];
        $this->assertFalse(Runs::initial($done, 'stp'));
        $this->assertTrue(Runs::initial($done, 'redundancy'));
        $this->assertSame(['links', 'redundancy', 'stp'], Runs::record(['recorded' => Runs::record($done, [])], ['redundancy']),
            'kept by every later run: what was recorded once stays recorded, whatever was pruned');
    }

    public function testWindowOfTheChangesPage(): void
    {
        $now = strtotime('2026-10-20 15:30:00');

        $this->assertSame([$now - 86400, $now, '1d'], Window::resolve([], $now));
        $this->assertSame([$now - 7 * 86400, $now, '7d'], Window::resolve(['window' => '7d'], $now));
        $this->assertSame([strtotime('2026-10-01 00:00:00'), strtotime('2026-10-05 23:59:59'), 'custom'], Window::resolve(['from' => '2026-10-01', 'to' => '2026-10-05'], $now));
        $this->assertSame([strtotime('2026-10-19 00:00:00'), $now, 'custom'], Window::resolve(['from' => '2026-10-19', 'to' => '2026-10-20'], $now), 'never past now');
        $this->assertSame([strtotime('2025-10-20 15:30:00'), $now, 'custom'], Window::resolve(['from' => '2020-01-01'], $now), 'at most a year');
        foreach ([['window' => ['7d']], ['from' => ['x']], ['from' => '2026-10-01', 'to' => ['y']], ['from' => 'garbage'], ['from' => '2026-10-05', 'to' => '2026-10-01']] as $query) {
            $this->assertSame([$now - 86400, $now, '1d'], Window::resolve($query, $now), json_encode($query));
        }
    }

    public function testWindowRowsAreCutAfterACommonBoundary(): void
    {
        $added = fn (int $id, int $time) => ['id' => $id, 'first_seen' => $time, 'removed_at' => null];
        $removed = fn (int $id, int $time) => ['id' => $id, 'first_seen' => 0, 'removed_at' => $time];

        $this->assertSame(['rows' => [$removed(1, 10), $added(2, 20), $added(3, 30)], 'truncated' => false, 'since' => null],
            Window::merge([$added(3, 30), $added(2, 20)], [$removed(1, 10)], 3));

        // the added facts were capped inside the snapshot of 30: it goes as a whole, with the removed facts up to it
        $cut = Window::merge([$added(5, 50), $added(4, 40), $added(3, 30)], [$removed(1, 20), $removed(2, 35), $removed(6, 30)], 3);
        $this->assertSame([2, 4, 5], array_column($cut['rows'], 'id'));
        $this->assertSame([true, 30], [$cut['truncated'], $cut['since']]);

        // both capped: after the later of the two oldest times
        $both = Window::merge([$added(5, 50), $added(4, 40)], [$removed(1, 45), $removed(2, 42)], 2);
        $this->assertSame([42, [1, 5]], [$both['since'], array_column($both['rows'], 'id')]);
    }

    public function testCutWindowKeepsBothHalvesOfAMovedCable(): void
    {
        $names = [1 => 'core1', 2 => 'core2', 3 => 'acc3', 4 => 'acc4', 5 => 'acc5', 6 => 'acc6'];
        $ports = [];
        foreach ([[31, 3, 'Gi1/0/49'], [41, 4, 'Gi1/0/49'], [51, 5, 'Gi1/0/49'], [61, 6, 'Gi1/0/49'], [11, 1, 'Te1/0/3'], [12, 1, 'Te1/0/4'],
            [21, 2, 'Te1/0/3'], [22, 2, 'Te1/0/4'], [13, 1, 'Te1/0/5'], [14, 1, 'Te1/0/6']] as [$id, $device, $name]) {
            $ports[$id] = ['port_id' => $id, 'device_id' => $device, 'ifName' => $name];
        }
        $collector = new FactCollector($names, $ports);
        $link = fn ($ld, $lp, $rd, $rp) => array_values($collector->links([['local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd,
            'remote_port_id' => $rp, 'remote_hostname' => '', 'remote_port' => '', 'remote_platform' => '', 'remote_version' => '', 'protocol' => 'lldp']]))[0];
        $row = fn ($fact, $id, $first, $removed) => ['id' => $id, 'kind' => $fact['kind'], 'fact_key' => $fact['key'], 'device_a' => $fact['device_a'],
            'device_b' => $fact['device_b'], 'data' => $fact['data'], 'initial' => false, 'first_seen' => $first, 'removed_at' => $removed];
        $t1 = 1_700_000_000;
        $t2 = $t1 + 3600;
        // at T1 acc3 and acc4 moved from core1 to core2, at T2 acc5 and acc6 got new links; read newest first, capped at 3
        $removed = [$row($link(3, 31, 1, 11), 2, $t1 - 86400, $t1), $row($link(4, 41, 1, 12), 3, $t1 - 86400, $t1)];
        $added = [$row($link(6, 61, 1, 14), 11, $t2, null), $row($link(5, 51, 1, 13), 10, $t2, null), $row($link(4, 41, 2, 22), 5, $t1, null)];

        $window = Window::merge($added, $removed, 3);
        $events = ChangeReport::build($window['rows'], $window['since'] + 1, $t2 + 10, new FactText($names))['events'];

        $this->assertSame($t1, $window['since']);
        $this->assertSame(['link_added', 'link_added'], array_column($events, 'type'), 'no "link gone" for the half that was cut');
    }

    public function testFindingWhosePortsChangeEveryRunGoesOn(): void
    {
        // link errors on another port of the same switch every run: the check stays on the switch
        $runs = [];
        foreach ([31, 32, 33, 34, 35, 36] as $port) {
            $runs[] = [self::finding('speed_mismatch', [3], [$port])];
        }
        $runs[] = [];
        $runs[] = [];

        $this->assertSame(['r7 3 ok'], self::flatten(self::simulate($runs)), 'no false "resolved" while another port has it, resolved after two runs without it');

        // without a confirmed predecessor a finding that changes every run is never confirmed
        $this->assertSame([], self::flatten(self::simulate(array_merge([[]], $runs))));
    }

    public function testOtherFindingsOfTheCheckOnASharedDeviceConfirmNothing(): void
    {
        // core2 (2) has a lost uplink toward acc4 for days; one run catches acc7's uplink down too
        $acc4 = self::finding('redundancy_lost', [6, 2], [61, 21]);
        $acc7 = self::finding('redundancy_lost', [10, 2], [101, 22]);

        $blip = self::simulate([[$acc4], [$acc4], [$acc4, $acc7], [$acc4], [$acc4], [$acc4]]);
        $this->assertSame([], self::flatten($blip), 'a one-run blip on acc7 is no news');

        $every3 = self::simulate([[$acc4], [$acc4], [$acc4, $acc7], [$acc4], [$acc4], [$acc4, $acc7], [$acc4], [$acc4], [$acc4, $acc7], [$acc4], [$acc4]]);
        $this->assertSame([], self::flatten($every3));

        $twice = self::simulate([[$acc4], [$acc4], [$acc4, $acc7], [$acc4, $acc7], [$acc4], [$acc4]]);
        $this->assertSame(['r3 10 warning', 'r5 10 ok'], self::flatten($twice), 'two runs in a row: new, and resolved after two runs without it');
    }

    /**
     * @param  array<int, array<int, array>>  $entries  eventlog entries of each run
     * @return string[] "r<run> <device> <severity>"
     */
    private static function flatten(array $entries): array
    {
        $lines = [];
        foreach ($entries as $run => $runEntries) {
            foreach ($runEntries as $entry) {
                $lines[] = "r$run {$entry['device_id']} {$entry['severity']}";
            }
        }

        return $lines;
    }

    public function testRootBridgeChangesAreGroupedAndAcceptedForAllTheirSwitches(): void
    {
        $names = [1 => 'core1', 2 => 'core2', 3 => 'acc3', 4 => 'acc4', 5 => 'acc5'];
        $collector = new FactCollector($names, []);
        $stp = fn (array $vlansOf, callable $root) => $collector->stpRoots(array_merge(...array_map(fn ($device) => array_map(fn ($vlan) => ['device_id' => $device,
            'vlan' => $vlan, 'designatedRoot' => $root($vlan), 'bridgeAddress' => "00ab0{$device}000001"], $vlansOf[$device]), array_keys($vlansOf))));
        $vlans = [3 => range(1, 40), 4 => range(1, 40), 5 => [1, 31]];
        $design = fn ($vlan) => $vlan % 2 ? '00ab01000001' : '00ab02000001';
        $baseline = $stp($vlans, $design);
        // VLAN 31 moved from core1 to core2 on every switch, VLAN 41 is new on acc3
        $now = $stp([3 => array_merge(range(1, 40), [41])] + $vlans, fn ($vlan) => $vlan === 31 || $vlan === 41 ? '00ab02000001' : $design($vlan));

        $changes = Baseline::rootChanges($baseline, $now);

        $this->assertSame([['00ab01000001', '00ab02000001', [31], [3, 4, 5]], [null, '00ab02000001', [41], [3]]],
            array_map(fn ($c) => [$c['from']['root'] ?? null, $c['to']['root'] ?? null, $c['vlans'], $c['devices']], $changes), 'one change for all switches');
        $text = new FactText($names);
        // (the cores report no spanning tree here, so their bridges are not named)
        $this->assertSame('VLAN 31: root bridge 00:ab:01:00:00:01 → 00:ab:02:00:00:01 on acc3, acc4, acc5', $text->rootChange($changes[0]));
        $this->assertSame('VLAN 41: root bridge 00:ab:02:00:00:01 on acc3', $text->rootChange($changes[1]));

        // accept the move: VLAN 31 leaves core1's fact and joins core2's on all three switches; acc5 had no core2 fact
        $ops = Baseline::acceptRootChange($baseline, $now, $changes[0]);
        $this->assertCount(5, $ops['update']);
        $this->assertCount(1, $ops['insert']);
        $after = $baseline;
        foreach ($ops['update'] as $hash => $data) {
            $after[$hash]['data'] = $data;
        }
        foreach ($ops['delete'] as $hash) {
            unset($after[$hash]);
        }
        $after += $ops['insert'];
        $this->assertSame([[null, '00ab02000001', [41], [3]]],
            array_map(fn ($c) => [$c['from']['root'] ?? null, $c['to']['root'] ?? null, $c['vlans'], $c['devices']], Baseline::rootChanges($after, $now)));
    }

    public function testVlansOfAFormAreStrict(): void
    {
        $this->assertSame([30, 31], Baseline::parseVlans('30,31'));
        $this->assertSame([0], Baseline::parseVlans('0'));
        foreach (['', '30,', '30,,31', 'abc', '30;31', '30 31', '12345', null, ['30']] as $value) {
            $this->assertNull(Baseline::parseVlans($value), json_encode($value));
        }
    }

    public function testNewMarksExpireWhenTheChecksStop(): void
    {
        $row = ['first_seen' => '2026-10-20 10:07:00', 'initial' => false];
        $now = strtotime('2026-10-20 10:30:00');

        $this->assertTrue(FindingBadges::isNew($row, '2026-10-20 10:07:00', $now, 7200));
        $this->assertFalse(FindingBadges::isNew($row, '2026-10-20 11:07:00', $now, 7200), 'an older finding');
        $this->assertFalse(FindingBadges::isNew(['initial' => true] + $row, '2026-10-20 10:07:00', $now, 7200), 'found by the first run');
        $this->assertFalse(FindingBadges::isNew($row, '2026-10-20 10:07:00', $now + 86400, 7200), 'the checks stopped running');
        $this->assertFalse(FindingBadges::isNew($row, null, $now, 7200));
    }

    public function testFindingsOfTheWindow(): void
    {
        $row = fn (string $code, string $title, string $first, ?string $resolved) => ['analysis' => 'stp', 'code' => $code, 'title' => $title,
            'severity' => 'warning', 'devices' => [1], 'first_seen' => $first, 'resolved_at' => $resolved];
        $rows = [
            $row('isl_no_stp', 'Inter-switch ports without spanning tree', '2026-10-20 10:00:00', null),
            $row('isl_no_stp', 'Inter-switch ports without spanning tree', '2026-10-19 09:00:00', '2026-10-20 10:00:00'),
            $row('root_moved', 'Root bridge moved', '2026-10-20 10:00:00', null),
            $row('flapping', 'Port flapping', '2026-10-20 08:00:00', '2026-10-20 09:00:00'),
            $row('rogue_root', 'Rogue root bridge', '2026-10-20 11:00:00', '2026-10-21 08:00:00'),
        ];

        $window = FindingHistory::window($rows, strtotime('2026-10-20 00:00:00'), strtotime('2026-10-20 23:59:59'));

        $this->assertSame([['isl_no_stp', 'changed'], ['root_moved', 'new'], ['flapping', 'resolved'], ['rogue_root', 'new']],
            array_map(fn ($r) => [$r['code'], $r['status']], $window), 'resolved after the window: new in the window');
    }

    private static function find(array $result, string $code, string $titlePart): ?array
    {
        foreach ($result['findings'] as $finding) {
            if ($finding['code'] === $code && str_contains($finding['title'], $titlePart)) {
                return $finding;
            }
        }

        return null;
    }
}
