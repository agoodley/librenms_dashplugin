<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\History\Baseline;
use Agoodley\LibrenmsTopology\History\FactCollector;
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
                    'devices' => $finding['devices'], 'confirmed' => $index === 0, 'missed_at' => null, 'first_seen' => $time, 'resolved_at' => null];
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

        $this->assertSame([], $entries[3], 'the new extent is not confirmed yet, the old one is missed once');
        $this->assertSame([[4, 'warning'], [2, 'ok']], array_map(fn ($e) => [$e['device_id'], $e['severity']], $entries[4]));
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

    public function testWindowRowsAreTruncatedAtTheLimit(): void
    {
        $row = fn (int $id) => ['id' => $id];

        $this->assertSame(['rows' => [$row(1), $row(2), $row(3)], 'truncated' => false], Window::merge([$row(3), $row(2)], [$row(2), $row(1)], 3));
        $this->assertTrue(Window::merge([$row(3), $row(2), $row(1)], [], 3)['truncated']);
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
