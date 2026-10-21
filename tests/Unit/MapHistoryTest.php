<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Data\ScopedRepository;
use Agoodley\LibrenmsTopology\History\FactCollector;
use Agoodley\LibrenmsTopology\History\MapHistory;
use Agoodley\LibrenmsTopology\History\PastMap;
use Agoodley\LibrenmsTopology\History\TimeRefused;
use Agoodley\LibrenmsTopology\Stp\StpIndex;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Topology\L2Builder;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use PHPUnit\Framework\TestCase;

/**
 * The maps at another time as MapHistory serves them: the changes since a time read as the Changes page reads them
 * (its window and events), facts of a kind's first snapshot, the baseline, the refusals, and the Time control's
 * times (snapshots start a few seconds into a minute).
 */
class MapHistoryTest extends TestCase
{
    private const NAMES = [1 => 'core1', 2 => 'core2', 3 => 'acc1', 4 => 'newsw'];

    /** Snapshots an hour apart, each 7 seconds into its minute. */
    private const S0 = 1_792_444_807;
    private const S1 = self::S0 + 3600;
    private const S2 = self::S1 + 3600;
    private const NOW = self::S2 + 1800;

    private static int $id = 500;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    private static function ports(): array
    {
        $ports = [];
        foreach ([[11, 1, 'Te1/0/1'], [12, 1, 'Te1/0/2'], [21, 2, 'Te1/0/1'], [31, 3, 'Gi1/0/49'], [41, 4, 'Gi0/1']] as [$id, $device, $name]) {
            $ports[$id] = ['port_id' => $id, 'device_id' => $device, 'ifName' => $name, 'ifOperStatus' => 'up'];
        }

        return $ports;
    }

    private static function link(int $ld, int $lp, int $rd, ?int $rp, string $host, string $remotePort): array
    {
        return ['id' => 0, 'local_device_id' => $ld, 'local_port_id' => $lp, 'remote_device_id' => $rd, 'remote_port_id' => $rp, 'remote_hostname' => $host,
            'remote_port' => $remotePort, 'remote_platform' => 'Cisco IOS', 'remote_version' => '', 'protocol' => 'lldp'];
    }

    /**
     * Stored rows (HistoryRepository::factRow()) of facts with one period.
     *
     * @param  array<string, array>  $facts
     */
    private static function rows(array $facts, int $firstSeen, ?int $removedAt, bool $initial = false): array
    {
        $rows = [];
        foreach ($facts as $fact) {
            $rows[] = ['id' => self::$id++, 'kind' => $fact['kind'], 'hash' => $fact['hash'], 'key' => $fact['key'], 'fact_key' => $fact['key'],
                'device_a' => $fact['device_a'], 'device_b' => $fact['device_b'], 'data' => $fact['data'], 'data_hash' => $fact['data_hash'],
                'initial' => $initial, 'first_seen' => $firstSeen, 'removed_at' => $removedAt];
        }

        return $rows;
    }

    private static function devices(): array
    {
        $devices = [];
        foreach (self::NAMES as $id => $name) {
            $devices[$id] = ['device_id' => $id, 'hostname' => $name, 'role' => 'switch', 'status' => 1, 'disabled' => 0];
        }

        return $devices;
    }

    /**
     * The history: at S0 (the first snapshot) acc1 on core1 and newsw an unmanaged neighbour of core1; at S1 acc1's
     * cable moved to core2 and LibreNMS started to monitor newsw (the same cable).
     */
    private static function history(): array
    {
        $c = new FactCollector(self::NAMES, self::ports());

        return [
            'L1' => self::rows($c->links([self::link(3, 31, 1, 11, 'core1', 'Te1/0/1')]), self::S0, self::S1, true)[0],
            'L2' => self::rows($c->links([self::link(3, 31, 2, 21, 'core2', 'Te1/0/1')]), self::S1, null)[0],
            'U' => self::rows($c->links([self::link(1, 12, 0, null, 'newsw', 'Gi0/1')]), self::S0, self::S1, true)[0],
            'M' => self::rows($c->links([self::link(1, 12, 4, 41, 'newsw', 'Gi0/1')]), self::S1, null)[0],
        ];
    }

    /**
     * Today's L2 map: acc1 on core2, newsw monitored on core1.
     */
    private static function today(): array
    {
        return (new L2Builder(self::devices(), [self::link(3, 31, 2, 21, 'core2', 'Te1/0/1'), self::link(1, 12, 4, 41, 'newsw', 'Gi0/1')], self::ports(), [], [],
            new StpIndex))->build();
    }

    public function testChangesSinceATimeAreTheChangesPagesEvents(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW));
        $repo = new FakeMapHistory(array_values(self::history()), [self::S0, self::S1, self::S2], self::NAMES);
        $since = self::S0 + 600;

        $result = (new MapHistory($repo, new PluginSettings))->changes('l2', $since, self::today(), ['unmanaged' => true, 'vlan' => null]);

        $this->assertFalse($repo->droppedAsked, 'a map does not count what a cut window leaves out');
        $changes = $result['changes'];
        $this->assertSame(date('Y-m-d H:i:s', $since + 1), $changes['from'], 'the window starts after the time, the Changes page link too');
        $this->assertSame(['cable_moved'], array_column($changes['events'], 'type'), 'newsw monitored now is the same cable: no event');
        $this->assertEquals(['link_new' => 1, 'link_gone' => 1], $changes['counts'], 'only the moved cable is marked');
        $edges = array_column($result['edges'], null, 'to');
        $this->assertSame('acc1 Gi1/0/49 now connects to core2 Te1/0/1 (was core1 Te1/0/1)', $edges['d:3']['changes'][0]['text']);
        $this->assertSame(date('Y-m-d H:i', self::S1), $edges['d:3']['changes'][0]['when']);
        $this->assertArrayNotHasKey('changes', $edges['d:4'], 'no mark on the cable to newsw');
        $this->assertSame([['d:1', 'd:3']], array_map(fn ($ghost) => [$ghost['from'], $ghost['to']], $changes['ghosts']['edges']), 'the ghost of the old cable only');
        $this->assertSame([], $changes['ghosts']['nodes'], 'no ghost of newsw as an unmanaged neighbour');
    }

    public function testFactsOfAKindsFirstSnapshotExistedBefore(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW));
        $c = new FactCollector(self::NAMES, self::ports());
        // spanning tree recorded from S1 on (its first snapshot): acc1's root bridge stays, its Gi1/0/49 stopped blocking at S2
        $rows = array_merge(
            self::rows($c->stpRoots([['device_id' => 3, 'vlan' => 20, 'designatedRoot' => '0000000000a1', 'bridgeAddress' => '0000000000c1']]), self::S1, null, true),
            self::rows($c->blockedPortFacts([3 => [31 => [20]]]), self::S1, self::S2, true),
        );
        $map = (new L2Builder(self::devices(), [self::link(3, 31, 2, 21, 'core2', 'Te1/0/1')], self::ports(), [], [], new StpIndex))->build();

        $result = (new MapHistory(new FakeMapHistory($rows, [self::S0, self::S1, self::S2], self::NAMES), new PluginSettings))->changes('l2', self::S0 + 60, $map);

        $this->assertEquals(['blocking_stopped' => 1], $result['changes']['counts'], 'the root bridge recorded first at S1 is no new one');
        $this->assertSame('acc1 Gi1/0/49 stopped blocking in VLAN 20', $result['edges'][0]['changes'][0]['text']);
    }

    public function testChangesSinceTheBaseline(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW));
        $history = self::history();
        $repo = new FakeMapHistory(array_values($history), [self::S0, self::S1, self::S2], self::NAMES);
        $repo->baseline = ['saved_at' => date('Y-m-d H:i:s', self::S0 + 30), 'saved_by' => 'admin', 'facts' => 2];
        $repo->baselineFacts = [$history['L1']['hash'] => $history['L1'], $history['M']['hash'] => $history['M']];

        $result = (new MapHistory($repo, new PluginSettings))->changes('l2', MapParams::BASELINE, self::today());

        $changes = $result['changes'];
        $this->assertSame('baseline', $changes['since']);
        $this->assertSame(['saved_at' => date('Y-m-d H:i:s', self::S0 + 30)], $changes['baseline']);
        $this->assertEquals(['link_new' => 1, 'link_gone' => 1], $changes['counts'], 'what differs from the baseline, as its comparison lists it');
        $this->assertSame(self::S1, $changes['ghosts']['edges'][0]['changes'][0]['time'], 'gone when its period ended');
    }

    /**
     * acc1 Gi1/0/49 starts blocking VLAN 20 and VLAN 20's root bridge moves from core1 to core2 at S1; at S2 a data change
     * without a change of the network starts new periods of those facts, which no event names: acc1 renamed, its port
     * re-created under a new id, or the root bridge core2 renamed. The map marks what the Changes page reports, at S1.
     *
     * @return array<string, array{0: array<int, string>, 1: bool, 2: bool}>
     */
    public static function laterDataChanges(): array
    {
        return [
            'no later change' => [self::NAMES, false, false],
            'acc1 renamed' => [[3 => 'acc1-floor2'] + self::NAMES, false, true],
            'port re-created' => [self::NAMES, true, true],
            'root bridge renamed' => [[2 => 'core2-dc1'] + self::NAMES, false, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('laterDataChanges')]
    public function testDataChangedLaterKeepsTheMarks(array $namesThen, bool $recreated, bool $changedAtS2): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW));
        [$a, $b, $bridge] = ['0000000000a1', '0000000000a2', '0000000000c3'];
        $ports = self::ports() + [99 => ['port_id' => 99, 'device_id' => 3, 'ifName' => 'Gi1/0/49', 'ifOperStatus' => 'up']];
        $collector = fn (array $names, bool $new) => new FactCollector($names, $new ? array_diff_key($ports, [31 => true]) : array_diff_key($ports, [99 => true]));
        $roots = fn (FactCollector $c, array $vlan20) => array_values(array_filter($c->stpRoots([
            ['device_id' => 3, 'vlan' => 10, 'designatedRoot' => $a, 'bridgeAddress' => $bridge],
            ['device_id' => 3, 'vlan' => 20, 'designatedRoot' => $vlan20[0], 'bridgeAddress' => $bridge],
            ['device_id' => 1, 'vlan' => 1, 'designatedRoot' => $a, 'bridgeAddress' => $a], ['device_id' => 2, 'vlan' => 1, 'designatedRoot' => $a, 'bridgeAddress' => $b],
        ]), fn ($fact) => $fact['data']['device'] === 3));
        $then = $collector(self::NAMES, false);
        $now = $collector($namesThen, $recreated);
        $port = $recreated ? 99 : 31;
        $later = $changedAtS2 ? self::S2 : null;
        $history = array_merge(
            self::rows($then->blockedPortFacts([3 => [31 => [10]]]), self::S0 - 86400, self::S1),
            self::rows($then->blockedPortFacts([3 => [31 => [10, 20]]]), self::S1, $later),
            self::rows($roots($then, [$a]), self::S0 - 86400, self::S1),
            self::rows($roots($then, [$b]), self::S1, $later),
        );
        if ($changedAtS2) {
            $history = array_merge($history, self::rows($now->blockedPortFacts([3 => [$port => [10, 20]]]), self::S2, null), self::rows($roots($now, [$b]), self::S2, null));
        }
        $map = (new L2Builder(self::devices(), [self::link(3, $port, 1, 11, 'core1', 'Te1/0/1'), self::link(3, 32, 2, 21, 'core2', 'Te1/0/1')],
            $ports + [32 => ['port_id' => 32, 'device_id' => 3, 'ifName' => 'Gi1/0/50', 'ifOperStatus' => 'up']], [], [], new StpIndex))->build();

        $result = (new MapHistory(new FakeMapHistory($history, [self::S0, self::S1, self::S2], $namesThen), new PluginSettings))->changes('l2', self::S0 + 600, $map);

        $marks = [];
        foreach (array_merge($result['nodes'], $result['edges']) as $item) {
            foreach ($item['changes'] ?? [] as $mark) {
                $marks[] = $item['id'] . ' ' . $mark['type'] . ' ' . implode(',', $mark['vlans'] ?? []) . ' ' . ($mark['time'] === self::S1 ? 'S1' : $mark['time']);
            }
        }
        sort($marks);
        $edge = array_values(array_filter($result['edges'], fn ($e) => $e['from'] === 'd:1' && $e['to'] === 'd:3'))[0]['id'];
        $this->assertSame(['d:1 root_old 20 S1', 'd:2 root_new 20 S1', "$edge blocking_started 20 S1"], $marks, 'what the Changes page reports, when it saw it');
        $this->assertSame(0, $result['changes']['unplaced']);
        $this->assertEqualsCanonicalizing(['root_changed', 'blocking_started'], array_column($result['changes']['events'], 'type'));
    }

    /**
     * The marks and ghosts of a map's changes, one line each: "<edge or node id> <type> <when> <text>" and
     * "<from>-><to> <type> <when> <text>".
     *
     * @param  array<int, string>  $times  timestamp => its name in the lines
     * @return string[]
     */
    private static function marks(array $result, array $times): array
    {
        $when = fn (?int $time) => $times[$time] ?? (string) $time;
        $lines = [];
        foreach (array_merge($result['nodes'], $result['edges']) as $item) {
            foreach ($item['changes'] ?? [] as $mark) {
                $lines[] = "{$item['id']} {$mark['type']} {$when($mark['time'])} {$mark['text']}";
            }
        }
        foreach ($result['changes']['ghosts']['edges'] as $ghost) {
            foreach ($ghost['changes'] as $mark) {
                $lines[] = "{$ghost['from']}->{$ghost['to']} {$mark['type']} {$when($mark['time'])} {$mark['text']}";
            }
        }
        sort($lines);

        return $lines;
    }

    /**
     * newsw on core1 Te1/0/2 as an unmanaged neighbour (its period) and once LibreNMS monitors it (its period), with
     * the events the Changes page reports and the marks: new as an unmanaged neighbour at S1 and monitored from S2,
     * today's link is new since S0; an unmanaged neighbour at the start, monitored from S1 and its cable pulled at S2,
     * it is gone, drawn to newsw.
     *
     * @return array<string, array{0: array{0: int, 1: ?int}, 1: array{0: int, 1: ?int}, 2: string[], 3: string[]}>
     */
    public static function monitoredLater(): array
    {
        return [
            'new, then monitored' => [[self::S1, self::S2], [self::S2, null], ['link_added'], ['{link} link_new S1 New link core1 Te1/0/2 ↔ newsw Gi0/1']],
            'monitored, then gone' => [[self::S0 - 86400, self::S1], [self::S1, self::S2], ['link_removed'], ['d:1->d:4 link_gone S2 Link gone: core1 Te1/0/2 ↔ newsw Gi0/1']],
        ];
    }

    /**
     * The same cable is two facts, the link to an unmanaged neighbour and the link between the two devices, which the
     * Changes page takes as one: what it reports of one is marked on the other.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('monitoredLater')]
    public function testANeighbourMonitoredLaterKeepsItsChange(array $unmanagedPeriod, array $monitoredPeriod, array $events, array $marks): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW));
        $c = new FactCollector(self::NAMES, self::ports());
        $history = array_merge(self::rows($c->links([self::link(1, 12, 0, null, 'newsw', 'Gi0/1')]), ...$unmanagedPeriod),
            self::rows($c->links([self::link(1, 12, 4, 41, 'newsw', 'Gi0/1')]), ...$monitoredPeriod));
        $links = [self::link(3, 31, 2, 21, 'core2', 'Te1/0/1')];
        if ($monitoredPeriod[1] === null) {
            $links[] = self::link(1, 12, 4, 41, 'newsw', 'Gi0/1');
        }
        $map = (new L2Builder(self::devices(), $links, self::ports(), [], [], new StpIndex))->build();

        $result = (new MapHistory(new FakeMapHistory($history, [self::S0, self::S1, self::S2], self::NAMES), new PluginSettings))->changes('l2', self::S0 + 600, $map);

        $this->assertSame($events, array_column($result['changes']['events'], 'type'));
        $link = array_column($result['edges'], 'id', 'to')['d:4'] ?? null;
        $this->assertSame(str_replace('{link}', (string) $link, $marks), self::marks($result, [self::S1 => 'S1', self::S2 => 'S2']), 'as the Changes page reports it');
        $this->assertSame(0, $result['changes']['unplaced']);
    }

    /**
     * A link that flapped tells the event of its state now: acc1's uplink went at T1, came back at T2 and went again at
     * T3 (gone since T3, not "back" at T2); a link new at T1, gone at T2 and back at T3 is there since T3.
     */
    public function testAMarkTellsTheEventOfTheStateNow(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW));
        [$t1, $t2, $t3] = [self::S0 + 1800, self::S1, self::S1 + 1800];
        $runs = [self::S0, $t1, $t2, $t3, self::S2];
        $times = [$t1 => 'T1', $t2 => 'T2', $t3 => 'T3'];
        $uplink = (new FactCollector(self::NAMES, self::ports()))->links([self::link(3, 31, 1, 11, 'core1', 'Te1/0/1')]);
        $map = (new L2Builder(self::devices(), [], self::ports(), [], [], new StpIndex))->build();

        $history = array_merge(self::rows($uplink, self::S0 - 86400, $t1), self::rows($uplink, $t2, $t3));
        $result = (new MapHistory(new FakeMapHistory($history, $runs, self::NAMES), new PluginSettings))->changes('l2', self::S0 + 600, $map);
        $this->assertSame(['link_removed', 'link_back'], array_column($result['changes']['events'], 'type'));
        $this->assertSame(['d:1->d:3 link_gone T3 Link gone: core1 Te1/0/1 ↔ acc1 Gi1/0/49'], self::marks($result, $times));

        $history = array_merge(self::rows($uplink, $t1, $t2), self::rows($uplink, $t3, null));
        $map = (new L2Builder(self::devices(), [self::link(3, 31, 1, 11, 'core1', 'Te1/0/1')], self::ports(), [], [], new StpIndex))->build();
        $result = (new MapHistory(new FakeMapHistory($history, $runs, self::NAMES), new PluginSettings))->changes('l2', self::S0 + 600, $map);
        $this->assertSame(['link_back', 'link_added'], array_column($result['changes']['events'], 'type'));
        $this->assertSame([$result['edges'][0]['id'] . ' link_new T3 core1 Te1/0/1 ↔ acc1 Gi1/0/49: back after it was gone 30m'], self::marks($result, $times));
    }

    public function testRefusals(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW));
        $refused = function (callable $call): string {
            try {
                $call();
            } catch (TimeRefused $e) {
                return $e->getMessage();
            }

            return 'not refused';
        };
        $repo = new FakeMapHistory(array_values(self::history()), [self::S0, self::S1, self::S2], self::NAMES);
        $history = new MapHistory($repo, new PluginSettings);

        $earliest = 'The history starts with its first snapshot: the earliest map is as of ' . date('Y-m-d H:i:s', self::S0) . '.';
        $this->assertSame($earliest, $refused(fn () => $history->past(self::S0 - 1)));
        $this->assertSame($earliest, $refused(fn () => $history->changes('l2', self::S0 - 60, self::today())));
        $this->assertSame(self::S0, $history->past(self::S0)->snapshot['time'], 'from the earliest snapshot on');
        $this->assertSame('No baseline is saved for the devices of this map: an admin saves one on the Changes page.',
            $refused(fn () => $history->changes('l2', MapParams::BASELINE, self::today())));

        // a role that may not view LLDP/CDP links gets an empty map: that is why there is nothing to compare
        $repo->hidden = ['links' => 'LLDP/CDP links'];
        $empty = ['nodes' => [], 'edges' => []];
        $this->assertSame('Your LibreNMS role may not view LLDP/CDP links: this map has no devices to compare with the baseline.',
            $refused(fn () => $history->changes('l2', MapParams::BASELINE, $empty)));
        $repo->hidden = [];
        $this->assertSame('This map has no devices to compare with the baseline.', $refused(fn () => $history->changes('l2', MapParams::BASELINE, $empty)));

        $off = new MapHistory($repo, new PluginSettings(['history_enabled' => '0']));
        $this->assertStringContainsString('switched off', $refused(fn () => $off->past(self::S1)));
        $this->assertStringContainsString('switched off', $refused(fn () => $off->changes('l2', MapParams::BASELINE, self::today())));
        $repo->tables = false;
        $this->assertStringContainsString('tables do not exist yet', $refused(fn () => $history->past(self::S1)));
        $this->assertStringContainsString('tables do not exist yet', $history->info([], self::NOW)['reason']);
    }

    public function testMinutesCoverTheSnapshotsThatStartInThem(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW));
        $repo = new FakeMapHistory(array_values(self::history()), [self::S0, self::S1, self::S2], self::NAMES);
        $history = new MapHistory($repo, new PluginSettings);

        // the snapshot of hh:mm:07 is the map of the minute hh:mm, typed or picked
        $typed = MapParams::time(['at' => date('Y-m-d\TH:i', self::S1)], self::NOW)['at'];
        $this->assertSame(self::S1, $history->past($typed)->snapshot['time']);
        $this->assertSame(self::S0, $history->past(MapParams::time(['at' => date('Y-m-d\TH:i', self::S0)], self::NOW)['at'])->snapshot['time'],
            'the minute of the earliest snapshot is not refused');
        $info = $history->info([], self::NOW);
        $this->assertSame([date('Y-m-d\TH:i:s', self::S2), date('Y-m-d\TH:i:s', self::S1), date('Y-m-d\TH:i:s', self::S0)], $info['snapshots'], 'picks carry the seconds');
        $this->assertSame(self::S1, $history->past(MapParams::time(['at' => $info['snapshots'][1]], self::NOW)['at'])->snapshot['time']);
        $this->assertSame(date('Y-m-d\TH:i', self::S0), $info['earliest_minute'], 'the date input starts at the minute of the earliest snapshot');
        $this->assertSame(date('Y-m-d\TH:i:s', self::S0), $info['earliest']);

        // a young history: one snapshot, 23 minutes ago
        $young = new MapHistory(new FakeMapHistory([], [self::S0], self::NAMES), new PluginSettings);
        $now = self::S0 + 23 * 60;
        $info = $young->info([], $now);
        $this->assertSame([true, true, true], array_column($info['picks'], 'early'), 'no quick pick before the history');
        $this->assertSame(['at' => date('Y-m-d\TH:i:s', self::S0), 'since' => date('Y-m-d\TH:i:s', self::S0)], $info['defaults']);
        foreach ($info['defaults'] as $key => $value) {
            $time = MapParams::time([$key => $value], $now)[$key];
            $this->assertSame(self::S0, $time, $key);
            $this->assertSame(self::S0, $young->past($time)->snapshot['time'], "the default of $key draws the snapshot");
        }
        $minute = MapParams::time(['since' => date('Y-m-d\TH:i', self::S0)], $now)['since'];
        $this->assertSame(self::S0 + 52, $minute, 'the minute of the snapshot, to its end');
        $this->assertSame(self::S0, $young->past($minute)->snapshot['time']);
    }

    public function testAsOfSaysWhatItLeavesOut(): void
    {
        $repo = new FakeMapHistory([], [self::S0, self::S0 + 5 * 3600], self::NAMES);
        $history = new MapHistory($repo, new PluginSettings);
        $past = new PastMap($repo, ['time' => self::S0, 'started_at' => date('Y-m-d H:i:s', self::S0), 'next' => self::S0 + 5 * 3600, 'recorded' => FactCollector::KINDS,
            'skipped' => []], [], FactCollector::KINDS);

        $l2 = $history->asOf($past, self::S0 + 3 * 3600, 'l2', [], true);
        $this->assertSame(['phones'], $l2['not_shown'], 'the history leaves phones out');
        $this->assertSame('No snapshot was taken between ' . date('Y-m-d H:i', self::S0) . ' and ' . date('Y-m-d H:i', self::S0 + 5 * 3600)
            . ': the map shows the earlier one.', $l2['notes'][1], 'times written as the page writes them');
        $this->assertSame([], $history->asOf($past, self::S0 + 60, 'l2', [], false)['not_shown'], 'no phones drawn without unmanaged neighbours');
        $l3 = $history->asOf($past, self::S0 + 60, 'l3', ['isis' => 2, 'mpls' => 0]);
        $this->assertSame(['IS-IS adjacencies'], $l3['not_shown']);
        $this->assertSame(['subnets and addresses', 'device details'], $l3['from_today']);
        $this->assertSame(date('Y-m-d H:i:s', self::S0 + 60), $l3['requested']);

        // a role that may view devices only: no ports from today, no phones it could not see anyway
        $repo->hidden = ['links' => 'LLDP/CDP links', 'ports' => 'ports', 'stp' => 'spanning tree', 'ports_stp' => 'spanning tree ports',
            'ipv4_addresses' => 'IP addresses', 'ipv6_addresses' => 'IP addresses'];
        $role = $history->asOf($past, self::S0 + 60, 'l2', [], true);
        $this->assertSame(['device details'], $role['from_today']);
        $this->assertSame([], $role['not_shown']);
        $this->assertSame([], $role['from_history'], 'nothing of the history it may read (links, port-channels and spanning tree hidden)');
        $this->assertSame(['device details'], $history->asOf($past, self::S0 + 60, 'l3')['from_today']);
    }

    public function testThePageFallbackHasEveryKey(): void
    {
        $info = (new MapHistory(new FakeMapHistory([], [self::S0], self::NAMES), new PluginSettings))->info([1], self::NOW);
        $fallback = MapHistory::emptyInfo(new PluginSettings, 'The topology history cannot be read, see logs/librenms.log.', self::NOW);

        $keys = array_keys($info);
        sort($keys);
        $fallbackKeys = array_keys($fallback);
        sort($fallbackKeys);
        $this->assertSame($keys, $fallbackKeys, 'the page reads the same keys either way');
        $this->assertFalse($fallback['available']);
        $this->assertSame(['at' => null, 'since' => null], $fallback['defaults']);
        $this->assertSame([], $fallback['picks']);
    }
}

/**
 * The topology history in memory, for MapHistory: stored fact rows, snapshot runs, the devices (all visible), a role's
 * hidden tables and the baseline.
 */
class FakeMapHistory extends HistoryRepository
{
    public bool $tables = true;

    /** @var array<string, string> table => what the role notices call it, for the tables the role hides */
    public array $hidden = [];

    public ?array $baseline = null;

    /** @var array<string, array> hash => baseline fact */
    public array $baselineFacts = [];

    /** Did windowFacts() count what a cut leaves out? */
    public ?bool $droppedAsked = null;

    /**
     * @param  array<int, array>  $facts  stored rows (HistoryRepository::factRow())
     * @param  int[]  $runs  starts of the finished snapshots
     * @param  array<int, string>  $deviceNames
     */
    public function __construct(private readonly array $facts, private readonly array $runs, private readonly array $deviceNames)
    {
        parent::__construct(null);
    }

    public function available(): bool
    {
        return $this->tables;
    }

    public function names(array $ids): array
    {
        return array_intersect_key($this->deviceNames, array_flip($ids));
    }

    public function deviceIds(?int $groupId = null, ?int $locationId = null): array
    {
        return array_keys($this->deviceNames);
    }

    public function seesEveryDevice(): bool
    {
        return true;
    }

    public function existingDevices(): array
    {
        return array_fill_keys(array_keys($this->deviceNames), true);
    }

    public function allowsTable(string $table): bool
    {
        return ! isset($this->hidden[$table]);
    }

    public function hiddenKinds(array $tables): array
    {
        return array_values(array_unique(array_intersect_key($this->hidden, array_flip($tables))));
    }

    public function sibling(string $class): ScopedRepository
    {
        return new $class(null);
    }

    public function snapshotAt(int $time): ?array
    {
        $before = array_filter($this->runs, fn ($run) => $run <= $time);
        if (! $before) {
            return null;
        }
        $start = max($before);
        $later = array_filter($this->runs, fn ($run) => $run > $start);

        return ['time' => $start, 'started_at' => date('Y-m-d H:i:s', $start), 'next' => $later ? min($later) : null, 'recorded' => FactCollector::KINDS, 'skipped' => []];
    }

    public function snapshotTimes(int $recent): array
    {
        $runs = $this->runs;
        rsort($runs);

        return ['earliest' => $runs ? min($runs) : null, 'latest' => $runs ? max($runs) : null, 'count' => count($runs), 'recent' => array_slice($runs, 0, $recent)];
    }

    public function factsAt(string $at, array $ids, array $kinds, bool $reporting = false): \Generator
    {
        $time = strtotime($at);
        foreach ($this->facts as $row) {
            if (in_array($row['kind'], $kinds, true) && $row['first_seen'] <= $time && ($row['removed_at'] === null || $row['removed_at'] > $time)) {
                yield $row;
            }
        }
    }

    public function windowFacts(CarbonInterface $from, CarbonInterface $to, array $ids, ?array $kinds = null, bool $dropped = true): array
    {
        $this->droppedAsked = $dropped;
        [$start, $end] = [$from->getTimestamp(), $to->getTimestamp()];
        $rows = array_values(array_filter($this->facts, fn ($row) => ($kinds === null || in_array($row['kind'], $kinds, true))
            && ((! $row['initial'] && $row['first_seen'] >= $start && $row['first_seen'] <= $end) || ($row['removed_at'] !== null && $row['removed_at'] >= $start && $row['removed_at'] <= $end))));

        return ['rows' => $rows, 'truncated' => false, 'since' => null, 'dropped' => null];
    }

    public function devicesAddedSince(array $ids, CarbonInterface $from): array
    {
        return [];
    }

    public function newToHistory(array $firstSeen): array
    {
        return [];
    }

    public function priorNodes(array $nodes, CarbonInterface $before): array
    {
        return array_fill_keys($nodes, true);
    }

    public function baselineInfo(array $ids): ?array
    {
        return $this->baseline;
    }

    public function baselineHashes(array $ids): array
    {
        return array_map(fn ($fact) => $fact['kind'], $this->baselineFacts);
    }

    public function currentHashes(array $ids, array $kinds): array
    {
        return array_map(fn ($row) => $row['kind'], array_filter($this->open(), fn ($row) => in_array($row['kind'], $kinds, true)));
    }

    public function baselineFactsByHash(array $hashes, int $limit): array
    {
        return array_values(array_intersect_key($this->baselineFacts, array_flip($hashes)));
    }

    public function currentFactsByHash(array $hashes, int $limit): array
    {
        return array_values(array_intersect_key($this->open(), array_flip($hashes)));
    }

    public function baselineFactsOfKind(array $ids, string $kind, bool $lock = false): array
    {
        return array_filter($this->baselineFacts, fn ($fact) => $fact['kind'] === $kind);
    }

    public function currentFactsOfKind(array $ids, string $kind): array
    {
        return array_filter($this->open(), fn ($row) => $row['kind'] === $kind);
    }

    public function goneSince(array $hashes): array
    {
        $gone = [];
        foreach ($this->facts as $row) {
            if (in_array($row['hash'], $hashes, true) && $row['removed_at'] !== null) {
                $gone[$row['hash']] = date('Y-m-d H:i:s', $row['removed_at']);
            }
        }

        return $gone;
    }

    /**
     * @return array<string, array> hash => the facts present now
     */
    private function open(): array
    {
        $open = [];
        foreach ($this->facts as $row) {
            if ($row['removed_at'] === null) {
                $open[$row['hash']] = $row;
            }
        }

        return $open;
    }
}
