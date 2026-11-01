<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\History\Baseline;
use Agoodley\LibrenmsTopology\History\ChangeReport;
use Agoodley\LibrenmsTopology\History\FactCollector;
use Agoodley\LibrenmsTopology\History\FactText;
use Agoodley\LibrenmsTopology\History\FindingBadges;
use Agoodley\LibrenmsTopology\History\FindingHistory;
use Agoodley\LibrenmsTopology\History\HistoryService;
use Agoodley\LibrenmsTopology\History\Runs;
use Agoodley\LibrenmsTopology\History\Window;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
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
     * Runs of one analysis over the findings history, like HistoryService::checks() with the database
     * (HistoryRepository::applyFindings()).
     *
     * @param  array<int, array<int, array>>  $runs  findings of each run; the first run is the initial one
     * @param  array<int, string>|\Closure  $names  device id => name, as the titles name the devices (a Closure: of a run)
     * @param  array<int, array>|null  $history  the rows of the findings history after the runs
     * @return array<int, array<int, array>> eventlog entries of each run
     */
    private static function simulate(array $runs, string $minimum = 'warning', array $skip = [], array|\Closure $names = [], ?array &$history = null): array
    {
        $rows = [];
        $entries = [];
        foreach ($runs as $index => $findings) {
            $time = sprintf('2026-10-20 %02d:07:00', $index);
            $open = array_values(array_filter($rows, fn ($row) => $row['resolved_at'] === null));
            $run = FindingHistory::run('links', $open, $findings, $time, $index === 0, $names instanceof \Closure ? $names($index) : $names);
            foreach ($run['insert'] as $hash => $finding) {
                $id = count($rows) + 1;
                $rows[$id] = ['id' => $id, 'hash' => $hash, 'code' => $finding['code'], 'severity' => $finding['severity'], 'title' => FindingHistory::title($finding),
                    'devices' => $finding['devices'], 'vlan' => $finding['vlan'] ?? null, 'subjects' => $finding['subjects'],
                    'confirmed' => in_array($hash, $run['confirmed'], true), 'missed_at' => null, 'first_seen' => $time, 'resolved_at' => null];
            }
            foreach ($run['update'] as $id => $finding) {
                $rows[$id] = ['severity' => $finding['severity'], 'title' => FindingHistory::title($finding), 'subjects' => $finding['subjects']] + $rows[$id];
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
        $history = $rows;

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

        $this->assertSame([], $entries[3], 'neither extent is about all the devices of the other: the new one waits for a second run, the old one is missed once');
        $this->assertSame([[4, 'warning'], [2, 'ok']], array_map(fn ($e) => [$e['device_id'], $e['severity']], $entries[4]),
            'the new extent is confirmed and the old one resolved: devices 1 and 3 keep the check');
        $this->assertSame([], $entries[5]);

        // with one more device, the extent is about all the devices of the one before: it goes on at once
        $grown = self::finding('stp_disabled_port', [1, 2, 3, 4], [10, 20, 30, 40]);
        $this->assertSame([[4, 'warning']], array_map(fn ($e) => [$e['device_id'], $e['severity']], self::simulate([[], [$before], [$before], [$grown]])[3]));

        $many = self::finding('no_stp_data', range(1, 14));
        $capped = self::simulate([[], [$many], [$many]])[2];
        $this->assertCount(FindingHistory::EVENTLOG_DEVICES, $capped);
        $this->assertStringEndsWith('(and on other devices)', $capped[0]['message'], 'no count: a reader of one eventlog may not see the others');
        $this->assertDoesNotMatchRegularExpression('/\d+ more/', $capped[0]['message']);
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

    public function testFindingReportedAgainIsConfirmedWhenTheRunsShareASecond(): void
    {
        // ./lnms topology:checks right after the checks of a snapshot: both runs in the same second
        $finding = self::finding('speed_mismatch', [3, 1], [31, 12]);
        $open = [['id' => 7, 'hash' => FindingHistory::hash('links', $finding), 'code' => 'speed_mismatch', 'severity' => 'warning', 'title' => 'A finding',
            'devices' => [3, 1], 'confirmed' => false, 'missed_at' => null, 'first_seen' => '2026-10-20 10:07:00']];

        $this->assertSame([7], FindingHistory::run('links', $open, [$finding], '2026-10-20 10:07:00', false)['confirm']);
    }

    public function testRoutingFindingsReachTheEventlogByTheirCheck(): void
    {
        $state = fn (string $code, string $severity) => ['code' => $code, 'severity' => $severity, 'devices' => [7, 8]];

        $entries = FindingHistory::eventlog('routing', [], [$state('ip_duplicate', 'warning')], 'warning');

        $this->assertSame([7, 8], array_column($entries, 'device_id'));
        $this->assertSame('Topology warning: Duplicate IP address (routing & IP check), details: plugin/topology/routing?device=7', $entries[0]['message']);
        $this->assertSame('topology:routing:ip_duplicate', $entries[0]['reference']);
        $this->assertSame('Duplicate OSPF router ID', FindingHistory::label('ospf_duplicate_router_id'));
        $this->assertSame('BGP session down', FindingHistory::label('bgp_session_down'));
        $this->assertSame(['stp', 'links', 'redundancy', 'routing', 'interfaces', 'capacity'], HistoryService::ANALYSES, 'routing runs after the topology checks');
        $this->assertSame('stp links redundancy routing interfaces capacity', (new PluginSettings([]))->all()['history_checks']);
        $this->assertSame(['interfaces'], HistoryService::WINDOW_ANALYSES, 'the verification of a change compares states');
        $this->assertSame(['interfaces', 'capacity'], HistoryService::UNVERIFIED_ANALYSES, 'free ports are planning, not health a change breaks');
        $this->assertSame('Topology warning: Link flapping (interface health check), details: plugin/topology/interfaces?device=7',
            FindingHistory::eventlog('interfaces', [], [$state('link_flapping', 'warning')], 'warning')[0]['message']);
    }

    public function testOneSeverityOfACheckCanBeKeptOutOfTheEventlog(): void
    {
        // a shared gateway or two separate networks reusing an address (info) against a real duplicate (warning)
        $skip = array_map('strtolower', (new PluginSettings([]))->list('history_eventlog_skip'));
        $this->assertContains('ip_duplicate:info', $skip);
        $this->assertContains('bgp_recent_reset', $skip);
        $state = fn (string $code, string $severity, int $device) => ['code' => $code, 'severity' => $severity, 'devices' => [$device]];
        $after = [$state('ip_duplicate', 'info', 1), $state('ip_duplicate', 'warning', 2), $state('bgp_recent_reset', 'warning', 3), $state('ip_mask_mismatch', 'info', 4)];

        $this->assertSame([[2, 'warning'], [4, 'info']], array_map(fn ($e) => [$e['device_id'], $e['severity']], FindingHistory::eventlog('routing', [], $after, 'info', $skip)),
            'even at the info severity');
        $this->assertSame([[1, 'warning']], array_map(fn ($e) => [$e['device_id'], $e['severity']],
            FindingHistory::eventlog('routing', [], [$state('ip_duplicate', 'info', 1), $state('ip_duplicate', 'warning', 1)], 'info', $skip)),
            'a device with both: the warning counts');
    }

    public function testAFindingThatGetsMilderStaysOpenForTheEventlog(): void
    {
        // a duplicate address of two routers is taken for separate networks (info) while an OSPF split flaps
        $names = [7 => 'rtr7', 8 => 'rtr8'];
        $duplicate = fn (?string $severity) => $severity ? [self::finding('ip_duplicate', [7, 8], [70, 80], $severity, 'duplicate IP address 10.20.40.5 on rtr7 Vl30, rtr8 Vl40')] : [];
        $runs = array_map($duplicate, ['warning', 'warning', 'info', 'warning', 'info', 'warning']);

        $this->assertSame([], self::flatten(self::simulate($runs, 'warning', ['ip_duplicate:info'], $names, $history)), 'no "resolved" while it is open');
        $this->assertSame('warning', $history[1]['severity'], 'the history keeps the highest severity of the period');
        $this->assertSame([], self::flatten(self::simulate($runs, 'warning', [], $names)), 'the same without the code:severity entry');
        $this->assertSame(['r8 7 ok', 'r8 8 ok'], self::flatten(self::simulate(array_merge($runs, [$duplicate('info'), [], []]), 'warning', ['ip_duplicate:info'], $names)),
            'resolved once it is gone, at its first severity');
    }

    public function testRisingToTheEventlogSeverityIsLogged(): void
    {
        // more devices end up behind a single point of failure: info, then warning
        $spof = fn (?string $severity) => $severity ? [self::finding('spof_device', [7], [], $severity, 'single point of failure: rtr7')] : [];
        $runs = array_map($spof, [null, 'info', 'info', 'warning', 'warning', 'info', null, null]);

        $this->assertSame(['r3 7 warning', 'r7 7 ok'], self::flatten(self::simulate($runs, 'warning', [], [7 => 'rtr7'])),
            'logged when it reaches the eventlog severity, not when it gets milder again, resolved when gone');

        // at the info severity a skipped info finding is no entry, its rise to a warning is
        $duplicate = fn (?string $severity) => $severity ? [self::finding('ip_duplicate', [7, 8], [70, 80], $severity, 'duplicate IP address on rtr7, rtr8')] : [];
        $this->assertSame(['r3 7 warning', 'r3 8 warning'], self::flatten(self::simulate(array_map($duplicate, [null, 'info', 'info', 'warning', 'info']), 'info',
            FindingHistory::skipList('ip_duplicate : info'), [7 => 'rtr7', 8 => 'rtr8'])));
    }

    public function testFindingsWithoutNamesInTheirTitleGoOnOnlyAboutTheSameDevicesAndVlan(): void
    {
        // two unrelated one-run findings of the spanning tree check that share the core switch (2)
        $isl = fn (array $devices, array $ports, ?int $vlan = null) => ['vlan' => $vlan]
            + self::finding('stp_disabled_port', $devices, $ports, 'warning', 'Inter-switch ports without spanning tree');
        $this->assertSame([], self::flatten(self::simulate([[], [], [$isl([10, 2], [101])], [$isl([12, 2], [121])], [], [], []])), 'a shared hub links nothing');
        $this->assertSame(['r3 2 warning', 'r3 10 warning', 'r5 2 ok', 'r5 10 ok'],
            self::flatten(self::simulate([[], [], [$isl([10, 2], [101])], [$isl([10, 2], [102])], [], [], []])), 'the same devices go on');

        // per VLAN: the same devices in another VLAN are another finding
        $this->assertSame([], self::flatten(self::simulate([[], [], [$isl([3, 4], [31], 10)], [$isl([3, 4], [31], 20)], [], [], []])));
        $this->assertSame(['r3 3 warning', 'r3 4 warning', 'r5 3 ok', 'r5 4 ok'],
            self::flatten(self::simulate([[], [], [$isl([3, 4], [31], 10)], [$isl([3, 4], [32], 10)], [], [], []])));
    }

    public function testARenamedDeviceIsMatchedByItsId(): void
    {
        // acc-sw7 (10) is renamed acc-sw7b in the run its lost uplink comes back, and core2 (2) blips once
        $names = fn (int $run) => [1 => 'core1', 2 => 'core2', 3 => 'core3', 10 => $run < 2 ? 'acc-sw7' : 'acc-sw7b'];
        $acc7 = self::finding('redundancy_lost', [10, 2], [101, 21], 'warning', 'redundancy lost: acc-sw7');
        $core2 = self::finding('redundancy_lost', [2, 1], [21, 11], 'warning', 'redundancy lost: core2');

        $this->assertSame(['r3 2 ok', 'r3 10 ok'], self::flatten(self::simulate([[$acc7], [$acc7], [$core2], [], [], []], 'warning', [], $names)),
            'the old row is still about acc-sw7 (10), not about every device it has: core2 does not go on in its place');

        // its own finding under the new name goes on at once
        $renamed = self::finding('redundancy_lost', [10, 3], [101, 31], 'warning', 'redundancy lost: acc-sw7b');
        $this->assertSame(['r2 3 warning', 'r3 2 ok'], self::flatten(self::simulate([[$acc7], [$acc7], [$renamed], [$renamed]], 'warning', [], $names)));
    }

    public function testSkipListOfTheSettings(): void
    {
        $this->assertSame(['link_errors', 'ip_duplicate:info', 'bgp_recent_reset', 'ip_duplicate:info'],
            FindingHistory::skipList(' Link_Errors ip_duplicate : info,  bgp_recent_reset;ip_duplicate: INFO '), 'spaces around the colon, any case');
        $this->assertSame(['bogus', 'ip_duplicate:fatal', 'ip_duplicate:'],
            FindingHistory::unknownSkips('link_errors bogus ip_duplicate:fatal tc_rate:info ip_duplicate:', HistoryService::checkCodes()));
        $this->assertSame([], FindingHistory::unknownSkips(PluginSettings::DEFAULTS['history_eventlog_skip'], HistoryService::checkCodes()),
            'the defaults are check codes');
        $this->assertContains('mac_flapping', HistoryService::checkCodes());
        $this->assertContains('ospf_duplicate_router_id', HistoryService::checkCodes());
    }

    public function testRootBridgeChangeNamesTheSwitchesOfEachVlan(): void
    {
        $change = ['from' => ['root' => '00ab01000001'], 'to' => ['root' => '00ab02000001'], 'vlans' => [31, 33], 'devices' => [3, 4, 5],
            'device_vlans' => [3 => [31, 33], 4 => [31], 5 => [31]]];
        $this->assertSame('VLAN 31 on 3 switches, VLAN 33 on 1 switch', FactText::changeVlans($change), 'acc3 alone moved VLAN 33 too');
        $this->assertSame('VLAN 31', FactText::changeVlans(['vlans' => [31], 'device_vlans' => [3 => [31], 4 => [31], 5 => [31]]] + $change));

        $many = ['vlans' => range(10, 17), 'devices' => range(1, 8), 'device_vlans' => array_combine(range(1, 8), array_map(fn ($d) => range(10, 9 + $d), range(1, 8)))];
        $this->assertSame('VLAN 10 on 8 switches, VLAN 11 on 7 switches, VLAN 12 on 6 switches, VLAN 13 on 5 switches, VLAN 14 on 4 switches, VLAN 15 on 3 switches '
            . 'and 2 more VLANs', FactText::changeVlans($many + $change));
    }

    public function testTheSameRoutingFindingInTwoVrfsIsTwoFindings(): void
    {
        $down = ['code' => 'bgp_session_down', 'severity' => 'warning', 'title' => 'BGP session rtr1 → 198.18.2.1 down', 'devices' => [1], 'ports' => [], 'vlan' => null];

        $this->assertNotSame(FindingHistory::hash('routing', ['vrfs' => ['PROD']] + $down), FindingHistory::hash('routing', ['vrfs' => ['DEV']] + $down));
        $this->assertSame(FindingHistory::hash('routing', ['vrfs' => ['DEV', 'PROD']] + $down), FindingHistory::hash('routing', ['vrfs' => ['PROD', 'DEV']] + $down));
        $this->assertSame(sha1('links|bgp_session_down||1|'), FindingHistory::hash('links', $down), 'findings without VRFs keep their identity');
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
    }

    public function testChurningFindingIsConfirmedOnItsSecondRunAndLoggedOnce(): void
    {
        // a persistent problem on switch 3 whose ports change every run, from before the history knew it
        $runs = [[]];
        foreach ([31, 32, 33, 34, 35, 36] as $port) {
            $runs[] = [self::finding('speed_mismatch', [3], [$port])];
        }

        $this->assertSame(['r2 3 warning'], self::flatten(self::simulate($runs)), 'confirmed on its second run, logged once');
        $this->assertSame(['r2 3 warning', 'r8 3 ok'], self::flatten(self::simulate(array_merge($runs, [[], []]))), 'resolved after two runs without it');
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

    public function testSuccessorIsAFindingAboutTheSameDevicesAsItsTitleNamesThem(): void
    {
        // access switches behind core2: a lost uplink of one names that switch, not core2
        $names = [1 => 'dist1', 2 => 'core2', 3 => 'acc1', 4 => 'acc2', 6 => 'acc-sw4', 10 => 'acc-sw7', 12 => 'acc-sw9', 13 => 'core3'];
        $lost = fn (int $device, int $core, string $name) => self::finding('redundancy_lost', [$device, $core], [$device * 10 + 1, $core * 10 + 1], 'warning', "redundancy lost: $name");
        [$acc4, $acc7, $acc9] = [$lost(6, 2, 'acc-sw4'), $lost(10, 2, 'acc-sw7'), $lost(12, 2, 'acc-sw9')];
        $run = fn (array $runs) => self::flatten(self::simulate($runs, 'warning', [], $names));

        $this->assertSame([], $run([[], [], [$acc7], [$acc9], [], [], []]), 'one-run blips of two switches behind the same core are no news');
        $this->assertSame([], $run([[], [$acc7], [$acc9], [$acc7], [$acc9], [$acc7], [], [], []]), 'nor when they alternate');
        $this->assertSame(['r3 2 ok', 'r3 6 ok'], $run([[$acc4], [$acc4], [$acc7], [], [], []]),
            'acc4 repaired in the run acc7 blips: acc7 does not go on in its place');

        // the same switch is about the same devices whichever link of it is down, and so is a list with it and more
        $this->assertSame(['r2 10 warning', 'r2 13 warning'], $run([[], [$acc7], [$lost(10, 13, 'acc-sw7')], [$lost(10, 13, 'acc-sw7')]]));
        $both = self::finding('redundancy_lost', [10, 12, 2], [101, 121, 21], 'warning', 'redundancy lost: acc-sw7, acc-sw9');
        $this->assertSame(['r2 2 warning', 'r2 10 warning', 'r2 12 warning'], $run([[], [$acc7], [$both], [$both]]));

        // a link names both ends: another access switch's link to the same distribution switch is another link
        $pair = fn (int $device, string $name, int $port) => self::finding('speed_mismatch', [$device, 1], [$device * 10, $port], 'warning',
            "speed mismatch: $name Gi1/0/49 ↔ dist1 Te1/0/$port");
        $this->assertSame([], $run([[], [], [$pair(3, 'acc1', 3)], [$pair(4, 'acc2', 4)], [], [], []]));
        $this->assertSame(['r2 1 warning', 'r2 3 warning'], $run([[], [$pair(3, 'acc1', 3)], [$pair(3, 'acc1', 5)], [$pair(3, 'acc1', 5)]]),
            'the same link on other ports goes on');
    }

    public function testSubjectsAreTheDevicesTheTitleNames(): void
    {
        $names = [2 => 'core2', 3 => 'acc1', 10 => 'acc-sw7', 30 => 'acc10', 40 => 'sw(4)'];
        $subjects = fn (string $title, array $devices) => FindingHistory::subjects(['title' => $title, 'devices' => $devices], $names);

        $this->assertSame(['devices' => [10], 'named' => true], $subjects('Redundancy lost: ACC-SW7', [10, 2]), 'in any case');
        $this->assertSame(['devices' => [30], 'named' => true], $subjects('speed mismatch on acc10', [3, 30]), 'whole names only');
        $this->assertSame(['devices' => [3, 30], 'named' => true], $subjects('MTU mismatch: acc1 Gi1/0/49 ↔ acc10 Gi1/0/1', [30, 3]));
        $this->assertSame(['devices' => [40], 'named' => true], $subjects('single point of failure: sw(4)', [40, 2]));
        $this->assertSame(['devices' => [2, 3], 'named' => false], $subjects('ports in broken state', [3, 2, 3]), 'a title without names: all its devices');
        $this->assertSame(['devices' => [2, 10], 'named' => false], FindingHistory::subjects(['title' => 'redundancy lost: acc-sw7', 'devices' => [10, 2]], []),
            'without names: all its devices');
    }

    public function testFindingThatChurnsAcrossAnEmptyRunGoesOn(): void
    {
        // the check missed acc1 once, then reports it on other ports every run: still open, so taken over
        $runs = [[self::finding('speed_mismatch', [3], [31])], [self::finding('speed_mismatch', [3], [31])], []];
        foreach ([32, 33, 34] as $port) {
            $runs[] = [self::finding('speed_mismatch', [3], [$port])];
        }

        $this->assertSame([], self::flatten(self::simulate($runs)), 'no false "resolved" and "new" around the empty run');
        $this->assertSame(['r7 3 ok'], self::flatten(self::simulate(array_merge($runs, [[], []]))));
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

    public function testRootBridgeMoveIsOneChangeWhateverVlansEachSwitchCarries(): void
    {
        $names = [1 => 'core1', 2 => 'core2', 3 => 'acc3', 4 => 'acc4', 5 => 'acc5'];
        $collector = new FactCollector($names, []);
        $stp = fn (array $vlansOf, callable $root) => $collector->stpRoots(array_merge(...array_map(fn ($device) => array_map(fn ($vlan) => ['device_id' => $device,
            'vlan' => $vlan, 'designatedRoot' => $root($vlan), 'bridgeAddress' => "00ab0{$device}000001"], $vlansOf[$device]), array_keys($vlansOf))));
        // the cores carry VLAN 1 and the user VLANs 10-14, each access switch VLAN 1 and some of them
        $vlans = [1 => [1, 10, 11, 12, 13, 14], 2 => [1, 10, 11, 12, 13, 14], 3 => [1, 10, 11, 12], 4 => [1, 11, 13], 5 => [1, 12, 14]];
        $baseline = $stp($vlans, fn ($vlan) => '00ab01000001');
        $now = $stp($vlans, fn ($vlan) => $vlan === 1 ? '00ab01000001' : '00ab02000001');

        $changes = Baseline::rootChanges($baseline, $now);

        $this->assertCount(1, $changes, 'one move of the user VLANs from core1 to core2');
        $this->assertSame([10, 11, 12, 13, 14], $changes[0]['vlans']);
        $this->assertSame([1, 2, 3, 4, 5], $changes[0]['devices']);
        $this->assertSame([3 => [10, 11, 12], 4 => [11, 13], 5 => [12, 14]], array_intersect_key($changes[0]['device_vlans'], [3 => 1, 4 => 1, 5 => 1]));
        $this->assertSame('VLAN 10, VLAN 13, VLAN 14 on 3 switches each, VLAN 11, VLAN 12 on 4 switches each: root bridge core1 (00:ab:01:00:00:01) → '
            . 'core2 (00:ab:02:00:00:01) on acc3, acc4, acc5, core1, core2', (new FactText($names))->rootChange($changes[0]),
            'the text has the VLANs of all its switches, with the switches of each');

        // one Accept moves the VLANs of each switch, and only those
        $ops = Baseline::acceptRootChange($baseline, $now, $changes[0]);
        $after = $baseline;
        foreach ($ops['update'] as $hash => $data) {
            $after[$hash]['data'] = $data;
        }
        foreach ($ops['delete'] as $hash) {
            unset($after[$hash]);
        }
        $after += $ops['insert'];
        $this->assertSame([], Baseline::rootChanges($after, $now), 'nothing left to accept');
        $core2 = fn (int $device) => FactText::vlans($after[FactCollector::hash(FactCollector::STP_ROOT, "d$device|00ab02000001")]['data']);
        $this->assertSame([[10, 11, 12], [11, 13], [12, 14]], [$core2(3), $core2(4), $core2(5)]);
        $this->assertSame([0], FactText::vlans($after[FactCollector::hash(FactCollector::STP_ROOT, 'd4|00ab01000001')]['data']), 'VLAN 1 / CST stays');
    }

    public function testSnapshotsACutWindowLeavesOutAreCounted(): void
    {
        // one snapshot at 10:07 moved the root bridge of 3,000 switch / VLAN pairs (3,000 facts gone, 3,000 new)
        $added = array_map(fn ($id) => ['id' => $id, 'first_seen' => 1007, 'removed_at' => null], range(6000, 1001, -1));
        $window = Window::merge($added, array_map(fn ($id) => ['id' => $id, 'first_seen' => 1, 'removed_at' => 1007], range(5000, 1, -1)), 5000);
        $this->assertSame([[], true, 1007], [$window['rows'], $window['truncated'], $window['since']], 'nothing of it can be listed');

        $counts = [['time' => 1007, 'kind' => 'stp_root', 'facts' => 3000], ['time' => 905, 'kind' => 'stp_root', 'facts' => 1],
            ['time' => 1007, 'kind' => 'stp_root', 'facts' => 3000], ['time' => 905, 'kind' => 'link', 'facts' => 2], ['time' => 803, 'kind' => 'bgp', 'facts' => 1]];
        $totals = ['stp_root' => 6001, 'link' => 9, 'bgp' => 1];

        $this->assertSame(['snapshots' => [['time' => 1007, 'kinds' => ['stp_root' => 6000], 'facts' => 6000],
            ['time' => 905, 'kinds' => ['link' => 2, 'stp_root' => 1], 'facts' => 3], ['time' => 803, 'kinds' => ['bgp' => 1], 'facts' => 1]],
            'earlier' => ['kinds' => ['link' => 7], 'facts' => 7]], Window::dropped($counts, $totals, 10), 'newest first, kinds in their order');
        $this->assertSame(['snapshots' => [['time' => 1007, 'kinds' => ['stp_root' => 6000], 'facts' => 6000]],
            'earlier' => ['kinds' => ['link' => 9, 'stp_root' => 1, 'bgp' => 1], 'facts' => 11]], Window::dropped($counts, $totals, 1), 'the earlier ones together');
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
