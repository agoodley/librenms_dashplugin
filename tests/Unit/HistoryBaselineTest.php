<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\History\Baseline;
use Agoodley\LibrenmsTopology\History\FactCollector;
use Agoodley\LibrenmsTopology\History\FindingHistory;
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
        $this->assertStringContainsString('save the baseline again', $lost['recommendation']);
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

    public function testFindingHistoryTransitions(): void
    {
        $kept = ['code' => 'a', 'severity' => 'warning', 'title' => 'kept', 'devices' => [1]];
        $changed = ['code' => 'b', 'severity' => 'critical', 'title' => 'worse now', 'devices' => [2]];
        $fresh = ['code' => 'c', 'severity' => 'info', 'title' => 'new', 'devices' => [3]];
        $open = [
            ['id' => 1, 'hash' => FindingHistory::hash('stp', $kept), 'severity' => 'warning', 'title' => 'Kept'],
            ['id' => 2, 'hash' => FindingHistory::hash('stp', $changed), 'severity' => 'warning', 'title' => 'Worse now'],
            ['id' => 3, 'hash' => sha1('gone'), 'severity' => 'warning', 'title' => 'Gone'],
            ['id' => 4, 'hash' => FindingHistory::hash('stp', $kept), 'severity' => 'warning', 'title' => 'Kept'],
        ];

        $diff = FindingHistory::diff('stp', $open, [$kept, $changed, $fresh, $fresh]);

        $this->assertSame([FindingHistory::hash('stp', $fresh)], array_keys($diff['new']));
        $this->assertSame([3, 4], array_column($diff['resolved'], 'id'));
        $this->assertSame([2], array_keys($diff['update']));
    }

    public function testEventlogEntries(): void
    {
        $new = [
            ['code' => 'spof_device', 'severity' => 'warning', 'title' => 'single point of failure: dist1', 'detail' => 'If dist1 fails...', 'devices' => [3, 8, 9]],
            ['code' => 'uplinks_same_device', 'severity' => 'info', 'title' => 'several links', 'detail' => '', 'devices' => [5]],
        ];
        $resolved = [['code' => 'outage', 'severity' => 'critical', 'title' => 'Outage: acc8 is down', 'devices' => [11]]];

        $entries = FindingHistory::eventlog('redundancy', $new, $resolved, 'warning');

        $this->assertSame([3, 8, 9, 11], array_column($entries, 'device_id'));
        $this->assertSame('Topology warning: Single point of failure: dist1. If dist1 fails...', $entries[0]['message']);
        $this->assertSame(['warning', 'warning', 'warning', 'ok'], array_column($entries, 'severity'));
        $this->assertSame('topology:redundancy:spof_device', $entries[0]['reference']);
        $this->assertSame('Topology resolved: Outage: acc8 is down', $entries[3]['message']);

        $this->assertCount(5, FindingHistory::eventlog('redundancy', $new, $resolved, 'info'));
        $this->assertCount(1, FindingHistory::eventlog('redundancy', $new, $resolved, 'critical'));
        $this->assertSame([], FindingHistory::eventlog('redundancy', $new, $resolved, 'none'));

        $many = [['code' => 'x', 'severity' => 'critical', 'title' => 't', 'detail' => '', 'devices' => range(1, 14)]];
        $capped = FindingHistory::eventlog('redundancy', $many, [], 'warning');
        $this->assertCount(FindingHistory::EVENTLOG_DEVICES, $capped);
        $this->assertStringEndsWith('(also on 4 more devices)', $capped[0]['message']);
    }

    public function testFindingThatOnlyChangedItsExtentGoesOnInTheEventlog(): void
    {
        // "inter-switch ports without spanning tree" now also lists device 4, and no longer device 2
        $now = ['code' => 'isl_no_stp', 'severity' => 'warning', 'title' => 'inter-switch ports without spanning tree', 'detail' => '',
            'devices' => [1, 3, 4], 'ports' => [10, 30, 40]];
        $before = ['code' => 'isl_no_stp', 'severity' => 'warning', 'title' => 'Inter-switch ports without spanning tree', 'devices' => [1, 2, 3]];
        $this->assertNotSame(FindingHistory::hash('stp', $now), FindingHistory::hash('stp', $before + ['ports' => [10, 20, 30]]));

        $entries = FindingHistory::eventlog('stp', [$now], [$before], 'warning');

        $this->assertSame([[4, 'warning'], [2, 'ok']], array_map(fn ($e) => [$e['device_id'], $e['severity']], $entries));

        // another title is another finding: a link that moved is gone on one port and new on the other
        $moved = ['code' => 'spof_link', 'severity' => 'warning', 'title' => 'single link: acc1 Gi1/0/49 - core2', 'detail' => '', 'devices' => [3, 2]];
        $old = ['code' => 'spof_link', 'severity' => 'warning', 'title' => 'Single link: acc1 Gi1/0/49 - core1', 'devices' => [3, 1]];
        $this->assertCount(4, FindingHistory::eventlog('redundancy', [$moved], [$old], 'warning'));
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
