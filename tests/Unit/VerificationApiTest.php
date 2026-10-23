<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Api\PlanningUnavailable;
use Agoodley\LibrenmsTopology\Api\Verification;
use Agoodley\LibrenmsTopology\History\FactCollector;
use Agoodley\LibrenmsTopology\History\FindingHistory;
use Agoodley\LibrenmsTopology\Tests\Unit\VerificationFixture as Net;
use PHPUnit\Framework\TestCase;

/**
 * The verification API for other plugins (Api\Verification): a state of some devices and what is around them, the
 * difference of two states in the history's words, and the fixes as data; never a device, fact, finding or name the
 * user may not see, the system scope for console commands only, refused while the plugin is disabled.
 *
 * The network: VerificationFixture's (core1, core2, dist1, acc1 and acc2, far1 and rtr; secret, which the user may not
 * see, behind acc1).
 */
class VerificationApiTest extends TestCase
{
    protected function setUp(): void
    {
        FakeLaravel::$console = true;
        FakeLaravel::$enabled = true;
    }

    public function testAStateCoversTheDevicesTheirBlastRadiusTheirNeighborsAndTheirDomain(): void
    {
        $net = new Net();
        $net->findings['redundancy'][] = Net::finding('spof_device', 'warning', 'Single point of failure: dist1', [Net::DIST1, Net::ACC2]);
        $net->findings['links'][] = Net::finding('description_missing', 'info', 'Inter-switch ports without description: far1', [Net::FAR1]);
        $net->findings['routing'][] = Net::finding('ospf_down', 'critical', 'OSPF adjacency down: rtr', [Net::RTR]);

        $state = Verification::withSources($net)->state([Net::DIST1]);

        $this->assertSame('1.0', $state['version']);
        $this->assertSame(['subjects' => [Net::DIST1], 'dependents' => [Net::ACC2], 'neighbors' => [Net::CORE1, Net::ACC1], 'included' => [],
            'domain' => [Net::CORE2, Net::FAR1]], $state['scope'], 'acc2 hangs off dist1 alone: what the batch cuts off is covered');
        $this->assertSame(['subject', 'dependent', 'neighbor', 'neighbor', 'domain', 'domain'], array_column($state['devices'], 'role'));
        $this->assertSame(1_700_000_000, $state['devices'][Net::DIST1]['discovered_at']);

        // the facts of the covered devices (the domain's root bridges only): every cable of dist1, acc1, acc2 and core1
        $texts = array_column($state['facts'], 'text');
        sort($texts);
        $this->assertSame(['core1 Te1/0/1 ↔ core2 Te1/0/1', 'core1 Te1/0/3 ↔ dist1 Te1/0/49', 'core1 Te1/0/7 ↔ far1 Gi1/0/49', 'core2 Te1/0/4 ↔ acc1 Gi1/0/50',
            'dist1 Te1/0/1 ↔ acc1 Gi1/0/49', 'dist1 Te1/0/2 ↔ acc2 Gi1/0/49'], $texts, 'the cable to the device the user may not see is left out');
        $this->assertSame([['root' => Net::MACS[1], 'root_device' => Net::CORE1, 'vlans' => [0], 'devices' => [1, 2, 3, 4, 5, 7]]], $state['roots'],
            'one group for the switches that see the same root in the same VLANs');

        // the findings about any device of the scope, by the findings history's identity; rtr is not in it
        $this->assertEqualsCanonicalizing(['Single point of failure: dist1', 'Inter-switch ports without description: far1'], array_column($state['findings'], 'title'));
        $spof = $state['findings'][FindingHistory::hash('redundancy', $net->findings['redundancy'][0])];
        $this->assertSame(['analysis' => 'redundancy', 'code' => 'spof_device', 'label' => 'Single point of failure (device)', 'severity' => 'warning',
            'title' => 'Single point of failure: dist1', 'devices' => [3, 5], 'subjects' => [3], 'vlans' => [], 'quiet' => false], $spof);
        $this->assertSame(['enabled' => ['stp', 'links', 'redundancy', 'routing'], 'failed' => [], 'facts_failed' => [], 'facts_errors' => []], $state['checks']);
        $this->assertSame([1 => 'core1', 2 => 'core2', 3 => 'dist1', 4 => 'acc1', 5 => 'acc2', 7 => 'far1'], self::sorted($state['names']));
    }

    public function testDependentsAreLeftOutWhenAsked(): void
    {
        $state = Verification::withSources(new Net())->state([Net::DIST1], ['dependents' => false]);

        $this->assertSame([], $state['scope']['dependents']);
        $this->assertSame([Net::CORE1, Net::ACC1, Net::ACC2], $state['scope']['neighbors']);
    }

    public function testTheApiNeverWidensWhatTheUserMaySee(): void
    {
        $net = new Net();
        $net->dependentsOf[Net::ACC1] = [Net::SECRET];
        $net->findings['links'][] = Net::finding('description_wrong', 'warning', 'Port descriptions name the wrong neighbor: acc1', [Net::ACC1, Net::SECRET]);
        $net->findings['stp'][] = Net::finding('root_not_preferred', 'warning', 'Root bridge secret is not one of the designated root bridges', [Net::SECRET], 0);
        $api = Verification::withSources($net);

        $state = $api->state([Net::ACC1, Net::SECRET], ['include' => [Net::SECRET], 'domain' => [Net::SECRET]]);

        $everywhere = [...array_merge([], ...array_values($state['scope'])), ...array_keys($state['devices']), ...array_keys($state['names']),
            ...array_merge([], ...array_column($state['roots'], 'devices')), ...array_column($state['facts'], 'a'), ...array_column($state['facts'], 'b'),
            ...array_merge([], ...array_column($state['findings'], 'devices'))];
        $this->assertNotContains(Net::SECRET, $everywhere, 'secret is in no scope, device, name, fact or finding');
        $this->assertSame([], $state['findings'], 'a finding about a device the user may not see is not one the user may see');
        $this->assertStringNotContainsString('secret', json_encode($state));
        $this->assertSame([], $api->state([Net::SECRET])['scope']['subjects']);
        $this->assertSame([], $api->scope([Net::SECRET])['subjects']);
        $this->assertEquals([Net::ACC1 => 'acc1', Net::DIST1 => 'dist1', Net::CORE2 => 'core2', Net::CORE1 => 'core1', Net::ACC2 => 'acc2', Net::FAR1 => 'far1'],
            $api->scope([Net::ACC1, Net::SECRET])['names'], 'the names of every device of its scope, its spanning tree domain too: never one the user may not see');
        $this->assertSame([Net::ACC1], array_keys($api->freshness([Net::ACC1, Net::SECRET])['devices']));

        // nor do the fixes: a fix only changes ports of the device of its finding, a fix of a device the user may not see is none
        $net->findings['links'] = [Net::finding('description_missing', 'info', 'Inter-switch ports without description: acc1', [Net::ACC1], null,
            ['fix' => ['ports' => [61 => 'acc1 Gi1/0/1', 41 => 'dist1 Te1/0/1']]])];
        $fixes = $api->fixes([Net::ACC1]);
        $this->assertSame([[Net::ACC1, 41, 'dist1 Te1/0/1']], array_map(fn (array $row) => [$row['device_id'], $row['port_id'], $row['description']], $fixes['fixes'][0]['ports']),
            'port 61 is secret\'s');
        $this->assertSame(['version' => '1.0', 'fixes' => [], 'not_fixable' => [], 'names' => [], 'role_hidden' => []], $api->fixes([Net::SECRET]));
        $net->findings['links'] = [Net::finding('description_missing', 'info', 'Inter-switch ports without description: secret', [Net::SECRET], null,
            ['fix' => ['ports' => [61 => 'acc1 Gi1/0/1']]])];
        $this->assertSame([], $api->fixes()['fixes']);
    }

    public function testTwoStatesCompareInTheHistorysWords(): void
    {
        $net = new Net();
        $net->findings['links'][] = Net::finding('description_wrong', 'warning', 'Port descriptions name the wrong neighbor: acc1', [Net::ACC1]);
        $net->findings['redundancy'][] = Net::finding('spof_device', 'warning', 'Single point of failure: dist1', [Net::DIST1, Net::ACC2]);
        $before = Verification::withSources($net)->state([Net::ACC1]);

        // the change: acc1's uplink to core2 is gone, core2 became the root, acc1 hangs off dist1 alone, and its description is right
        $net->unplug(42);
        $net->roots = array_fill_keys(array_keys($net->roots), Net::CORE2);
        $net->findings['links'] = [];
        $net->findings['redundancy'] = [Net::finding('spof_device', 'warning', 'Single point of failure: dist1', [Net::DIST1, Net::ACC1, Net::ACC2]),
            Net::finding('spof_link', 'info', 'Single link: acc1 Gi1/0/49 ↔ dist1 Te1/0/1', [Net::ACC1, Net::DIST1])];
        $net->findings['routing'] = [Net::finding('ospf_down', 'critical', 'OSPF adjacency down: rtr', [Net::RTR])]; // rtr is in neither scope
        $net->states[Net::ACC2] = ['up' => false, 'down_since' => time(), 'discovered_at' => null, 'polled_at' => null, 'discovery_pending' => false];
        $api = Verification::withSources($net);
        $after = $api->state([Net::ACC1], Verification::cover($before));

        $this->assertSame(['include' => [Net::ACC1, Net::DIST1, Net::CORE2], 'domain' => [Net::CORE1, Net::ACC2, Net::FAR1]], Verification::cover($before),
            'the state before: acc1 and its neighbors (secret is not one), the rest of the domain');
        $this->assertSame([Net::CORE2], $after['scope']['included'], 'the state after covers what the state before covered: core2 is no neighbor any more');

        $diff = $api->compare($before, $after);

        $events = array_column($diff['events'], 'text', 'type');
        $this->assertSame('Link gone: core2 Te1/0/4 ↔ acc1 Gi1/0/50', $events['link_removed']);
        $this->assertStringStartsWith('VLAN 1 / CST: root bridge changed from core1 (00:ab:01:00:00:01) to core2 (00:ab:02:00:00:01)', $events['root_changed']);
        $root = array_values(array_filter($diff['events'], fn ($event) => $event['type'] === 'root_changed'))[0];
        $this->assertSame(['vlans' => [0], 'root_before' => ['mac' => Net::MACS[1], 'device' => Net::CORE1], 'root_after' => ['mac' => Net::MACS[2], 'device' => Net::CORE2]],
            array_intersect_key($root, array_flip(['vlans', 'root_before', 'root_after'])), 'what the root bridge event is about, as data');
        $this->assertArrayNotHasKey('vlans', array_values(array_filter($diff['events'], fn ($event) => $event['type'] === 'link_removed'))[0]);
        $this->assertSame(['Single link: acc1 Gi1/0/49 ↔ dist1 Te1/0/1'], array_column($diff['findings']['new'], 'title'));
        $this->assertSame(['Single point of failure: dist1'], array_column($diff['findings']['worse'], 'title'), 'the same finding, cutting off one more switch');
        $this->assertSame([3, 5], $diff['findings']['worse'][0]['was']['devices']);
        $this->assertSame(['Port descriptions name the wrong neighbor: acc1'], array_column($diff['findings']['resolved'], 'title'));
        $this->assertSame(['down' => [Net::ACC2], 'up' => []], $diff['devices']);
        $this->assertNotContains('OSPF adjacency down: rtr', array_column($diff['findings']['new'], 'title'), 'rtr is in neither scope');
        $this->assertArrayNotHasKey(Net::RTR, $diff['names']);
    }

    public function testOnlyWhatBothStatesCoverIsCompared(): void
    {
        $net = new Net();
        $net->findings['links'] = [Net::finding('description_missing', 'info', 'Inter-switch ports without description: far1', [Net::FAR1])];
        $before = Verification::withSources($net)->state([Net::DIST1], ['dependents' => false]);
        $this->assertContains(Net::FAR1, $before['scope']['domain']);

        // a finding about a device only the second state covers: the first did not look, so it is not new
        $net->findings['routing'] = [Net::finding('ospf_down', 'critical', 'OSPF adjacency down: rtr', [Net::RTR])];
        $net->links[] = Net::link(8, 81, 1, 18);
        $net->ports[81] = ['port_id' => 81, 'device_id' => 8, 'ifName' => 'Gi0/0', 'ifDescr' => 'Gi0/0', 'ifAlias' => ''];
        $net->ports[18] = ['port_id' => 18, 'device_id' => 1, 'ifName' => 'Te1/0/8', 'ifDescr' => 'Te1/0/8', 'ifAlias' => ''];
        $after = Verification::withSources($net)->state([Net::DIST1, Net::CORE1], Verification::cover($before) + ['dependents' => false]);
        $this->assertContains(Net::RTR, $after['scope']['neighbors']);

        $diff = Verification::withSources($net)->compare($before, $after);
        $this->assertSame([], $diff['findings']['new']);
        $this->assertSame(1, $diff['findings']['same'], 'far1\'s finding is in both: the same');
        $this->assertSame(['link_added'], array_column($diff['events'], 'type'), 'core1 was covered by both: its new cable is new');
    }

    public function testAStateOfAnotherVersionIsRefused(): void
    {
        $api = Verification::withSources(new Net());
        $state = $api->state([Net::ACC1]);
        foreach ([['version' => '2.0'] + $state, ['version' => 1] + $state, ['taken_at' => '1700000000'] + $state, []] as $wrong) {
            try {
                $api->compare($state, $wrong);
                $this->fail('a state of another version compared');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('not a state of the verification API 1.x', $e->getMessage());
            }
        }
        $this->assertSame([], $api->compare($state, $state)['events']);
    }

    public function testQuietChecksAndFailedChecksAreSaid(): void
    {
        $net = new Net();
        $net->quiet = ['spof_link', 'description_missing:info'];
        $net->findings['redundancy'] = [Net::finding('spof_link', 'warning', 'Single link: acc1 Gi1/0/49 ↔ dist1 Te1/0/1', [Net::ACC1, Net::DIST1])];
        $net->findings['links'] = [Net::finding('description_missing', 'info', 'Inter-switch ports without description: acc1', [Net::ACC1]),
            Net::finding('description_wrong', 'warning', 'Port descriptions name the wrong neighbor: acc1', [Net::ACC1])];
        $net->failedChecks = ['routing' => 'RuntimeException: cannot read the OSPF neighbours: database error 42S02/1146: Table \'librenms.ospf_nbrs\' doesn\'t exist'];
        $net->failedFacts = [FactCollector::STP_BLOCKED => 'TypeError: an argument is null'];
        $net->hidden = ['OSPF neighbors'];

        $state = Verification::withSources($net)->state([Net::ACC1]);

        $this->assertSame(['Inter-switch ports without description: acc1' => true, 'Port descriptions name the wrong neighbor: acc1' => false,
            'Single link: acc1 Gi1/0/49 ↔ dist1 Te1/0/1' => true], array_column($state['findings'], 'quiet', 'title'));
        $this->assertSame(['routing' => 'RuntimeException: cannot read the OSPF neighbours: database error 42S02/1146: Table \'librenms.ospf_nbrs\' doesn\'t exist'],
            $state['checks']['failed'], 'a check that failed, with its error');
        $this->assertSame([[FactCollector::STP_BLOCKED], [FactCollector::STP_BLOCKED => 'TypeError: an argument is null']],
            [$state['checks']['facts_failed'], $state['checks']['facts_errors']], 'facts that could not be read, with why');
        $this->assertSame(['OSPF neighbors'], $state['role_hidden']);
    }

    public function testFixesAreDataForTheFindingsTheUserMaySee(): void
    {
        $net = new Net();
        $net->settings['stp_preferred_roots'] = [Net::CORE2, Net::CORE1];
        $net->findings['links'] = [Net::finding('description_wrong', 'warning', 'Port descriptions name the wrong neighbor: acc1', [Net::ACC1], null,
            ['fix' => ['ports' => [42 => 'core2 Te1/0/4', 41 => 'dist1 Te1/0/1']]])];
        $net->findings['stp'] = [Net::finding('root_not_preferred', 'warning', 'Root bridge core1 is not one of the designated root bridges', [Net::CORE1], 0,
            ['vlans' => [0], 'fix' => ['root' => Net::CORE1, 'root_mac' => Net::MACS[1]]])];

        $fixes = Verification::withSources($net)->fixes();

        $this->assertSame(['port_descriptions', 'stp_root_priority'], array_column($fixes['fixes'], 'kind'));
        [$descriptions, $root] = $fixes['fixes'];
        $this->assertSame('Port descriptions of acc1', $descriptions['title']);
        $this->assertSame([['Gi1/0/49', 'dist1 Te1/0/1'], ['Gi1/0/50', 'core2 Te1/0/4']], array_map(fn ($row) => [$row['ifName'], $row['description']], $descriptions['ports']));
        $this->assertSame([FindingHistory::hash('links', $net->findings['links'][0])], $descriptions['resolves']);
        $this->assertSame('Root bridge of VLAN 1 / CST: core2, secondary core1', $root['title']);
        $this->assertSame([Net::CORE2, Net::CORE1], $root['devices']);
        $this->assertSame(['device_id' => Net::CORE2, 'priority' => 4096, 'mode' => 'stp', 'instances' => [], 'mac' => Net::MACS[2], 'now' => [0 => 32768]], $root['primary']);
        $this->assertSame(8192, $root['secondary']['priority']);
        $this->assertSame([0 => ['device_id' => Net::CORE1, 'mac' => Net::MACS[1]]], $root['roots_now']);
        $this->assertSame('the designated root bridges', $root['chosen_from']);
        $this->assertSame([], $fixes['not_fixable']);

        // of some devices: the fixes of those devices and of the findings about them; the link check still runs over the whole
        // network (a description is wrong when it names a monitored device, wherever that is)
        $net->calls = [];
        $this->assertSame(['port_descriptions'], array_column(Verification::withSources($net)->fixes([Net::ACC1])['fixes'], 'kind'));
        $this->assertSame([['linkFindings', null]], array_values(array_filter($net->calls, fn (array $call) => $call[0] === 'linkFindings')));
        $this->assertSame(['stp_root_priority'], array_column(Verification::withSources($net)->fixes([Net::CORE1])['fixes'], 'kind'));
    }

    public function testNoDevicesIsAnEmptyScopeNeverTheWholeNetwork(): void
    {
        $net = new Net();
        $state = Verification::withSources($net)->state([]);

        $this->assertSame(['subjects' => [], 'dependents' => [], 'neighbors' => [], 'included' => [], 'domain' => []], $state['scope']);
        $this->assertSame([], $state['facts']);
        $this->assertSame([], $net->calls, 'nothing read');
    }

    public function testMoreDevicesThanAllowedAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Verification::withSources(new Net())->state(range(1, Verification::MAX_DEVICES + 1));
    }

    public function testTheSystemScopeIsForConsoleCommandsOnly(): void
    {
        FakeLaravel::$console = false;
        try {
            Verification::system();
            $this->fail('the system scope outside the console');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('only available to console commands', $e->getMessage());
        }

        FakeLaravel::$console = true;
        $this->assertInstanceOf(Verification::class, Verification::system());
    }

    public function testADisabledPluginIsUnavailable(): void
    {
        $this->assertNull(Verification::unavailable());
        $this->assertInstanceOf(Verification::class, Verification::forUser(new \stdClass()));

        FakeLaravel::$enabled = false;
        $this->assertSame('the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)', Verification::unavailable());
        foreach ([fn () => Verification::forUser(new \stdClass()), fn () => Verification::system()] as $open) {
            try {
                $open();
                $this->fail('the API of a disabled plugin');
            } catch (PlanningUnavailable $e) {
                $this->assertStringContainsString('disabled', $e->getMessage());
            }
        }
    }

    public function testTheVersionIsMajorDotMinor(): void
    {
        $this->assertMatchesRegularExpression('/^1\.\d+$/D', Verification::VERSION);
    }

    private static function sorted(array $names): array
    {
        ksort($names);

        return $names;
    }
}
