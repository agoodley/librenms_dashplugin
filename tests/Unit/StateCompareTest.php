<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\History\FactCollector;
use Agoodley\LibrenmsTopology\Verification\StateCompare;
use PHPUnit\Framework\TestCase;

/**
 * The difference of two verification states (Verification\StateCompare): the history's events on the facts, the
 * findings new, worse, changed, milder and resolved (by identity, and by the findings history's successor rule), the
 * devices that went down or came back.
 */
class StateCompareTest extends TestCase
{
    private const NAMES = [1 => 'core1', 2 => 'dist1', 3 => 'acc1'];

    private const T = 1_700_000_000;

    /**
     * A state as Api\Verification::state() gives it, of acc1 with dist1 and core1 around it.
     *
     * @param  array<string, array>  $facts  FactCollector facts
     * @param  array<string, array>  $findings  identity => finding
     */
    private static function state(int $takenAt, array $facts = [], array $findings = [], array $devices = []): array
    {
        $compact = [];
        foreach ($facts as $hash => $fact) {
            $compact[$hash] = ['kind' => $fact['kind'], 'key' => $fact['key'], 'a' => $fact['device_a'], 'b' => $fact['device_b'], 'text' => '', 'data' => $fact['data']];
        }

        return ['version' => '1.0', 'taken_at' => $takenAt, 'scope' => ['subjects' => [3], 'dependents' => [], 'neighbors' => [2], 'included' => [], 'domain' => [1]],
            'devices' => $devices + [3 => ['role' => 'subject', 'up' => true, 'down_since' => null], 2 => ['role' => 'neighbor', 'up' => true, 'down_since' => null]],
            'facts' => $compact, 'roots' => [], 'findings' => $findings, 'names' => self::NAMES];
    }

    private static function finding(string $code, string $severity, array $devices, array $subjects, ?array $vlans = []): array
    {
        return ['analysis' => 'redundancy', 'code' => $code, 'label' => $code, 'severity' => $severity, 'title' => "$code of " . self::NAMES[$subjects[0]],
            'devices' => $devices, 'subjects' => $subjects, 'vlans' => $vlans, 'quiet' => false];
    }

    public function testSeverityAndExtentOfAFindingSayWhetherItGotWorse(): void
    {
        $before = self::state(self::T, findings: [
            'a' => self::finding('spof_device', 'info', [2, 3], [2]),
            'b' => self::finding('spof_link', 'critical', [2, 3], [3]),
            'c' => self::finding('redundancy_lost', 'warning', [1, 2, 3], [2]),
            'd' => self::finding('spof_device', 'warning', [1], [1]),
        ]);
        $after = self::state(self::T + 600, findings: [
            'a' => self::finding('spof_device', 'warning', [2, 3], [2]),        // the same identity, more severe
            'b' => self::finding('spof_link', 'warning', [2, 3], [3]),          // less severe
            'c2' => self::finding('redundancy_lost', 'warning', [2, 3], [2]),   // its successor, about fewer devices
            'd' => self::finding('spof_device', 'warning', [1], [1]),
        ]);

        $findings = StateCompare::compare($before, $after)['findings'];

        $this->assertSame(['a'], array_column($findings['worse'], 'identity'));
        $this->assertSame('info', $findings['worse'][0]['was']['severity']);
        $this->assertSame(['b'], array_column($findings['milder'], 'identity'));
        $this->assertSame(['c2'], array_column($findings['changed'], 'identity'));
        $this->assertSame('c', $findings['changed'][0]['was']['identity']);
        $this->assertSame([], $findings['new']);
        $this->assertSame([], $findings['resolved']);
        $this->assertSame(1, $findings['same']);
    }

    public function testAPortThatStartedBlockingIsAnEventAndALinkWhoseDetailsChangedIsNone(): void
    {
        $ports = [31 => ['port_id' => 31, 'device_id' => 3, 'ifName' => 'Gi1/0/49'], 32 => ['port_id' => 32, 'device_id' => 3, 'ifName' => 'Gi1/0/50'],
            21 => ['port_id' => 21, 'device_id' => 2, 'ifName' => 'Te1/0/1']];
        $collector = new FactCollector(self::NAMES, $ports);
        $link = fn (string $platform) => $collector->links([['local_device_id' => 3, 'local_port_id' => 31, 'remote_device_id' => 2, 'remote_port_id' => 21,
            'remote_hostname' => 'dist1', 'remote_port' => 'Te1/0/1', 'remote_platform' => $platform, 'remote_version' => '', 'protocol' => 'lldp']]);
        $before = self::state(self::T, $link('WS-C3850'));
        $after = self::state(self::T + 600, $link('C9300') + $collector->blockedPorts([['device_id' => 3, 'port_id' => 32, 'vlan' => 1, 'state' => 'blocking']]));

        $events = StateCompare::compare($before, $after)['events'];

        $this->assertSame([['type' => 'blocking_started', 'category' => 'stp', 'level' => 'info', 'text' => 'acc1 Gi1/0/50 started blocking in VLAN 1 / CST',
            'devices' => [3], 'vlans' => [0]]], $events);
    }

    public function testADeviceThatWentDownBetweenTheStatesIsSaidAndOneDownBeforeIsNot(): void
    {
        $before = self::state(self::T, devices: [1 => ['role' => 'domain', 'up' => false, 'down_since' => self::T - 60]]);
        $after = self::state(self::T + 600, devices: [
            3 => ['role' => 'subject', 'up' => false, 'down_since' => self::T + 300],
            1 => ['role' => 'domain', 'up' => true, 'down_since' => null],
            9 => ['role' => 'neighbor', 'up' => false, 'down_since' => self::T + 200],   // only the state after covers it: down since after the first
        ]);

        $diff = StateCompare::compare($before, $after);

        $this->assertSame(['down' => [3, 9], 'up' => [1]], $diff['devices']);
        $this->assertSame([1 => 'core1', 3 => 'acc1'], $diff['names']);
    }

    public function testTheComparisonIsFromTheFirstStateToTheSecond(): void
    {
        $diff = StateCompare::compare(self::state(self::T), self::state(self::T - 10));

        $this->assertSame(self::T, $diff['from']);
        $this->assertSame(self::T, $diff['to'], 'never backwards');
        $this->assertSame([3, 2], StateCompare::covered(self::state(self::T)));
        $this->assertSame([1, 3, 2], StateCompare::domain(self::state(self::T)));
    }

    public function testADeviceTheSecondStateNoLongerCoversIsNamed(): void
    {
        $before = self::state(self::T);
        // dist1 (2) and core1 (1, of the domain) no longer seen: disabled, deleted, or no longer visible to the user
        $after = ['scope' => ['subjects' => [3], 'dependents' => [], 'neighbors' => [], 'included' => [], 'domain' => []]] + self::state(self::T + 600);
        $this->assertSame([1, 2], StateCompare::compare($before, $after)['skipped']['devices'], 'what the first state covered or had in its domain');
        $this->assertSame([], StateCompare::compare($before, self::state(self::T + 600))['skipped']['devices']);
        // a device that only moved from the covered devices to the domain is no longer covered either
        $moved = ['scope' => ['subjects' => [3], 'dependents' => [], 'neighbors' => [], 'included' => [], 'domain' => [1, 2]]] + self::state(self::T + 600);
        $this->assertSame([2], StateCompare::compare($before, $moved)['skipped']['devices']);
    }

    public function testWhatEitherStateCouldNotReadIsNotComparedAndSaidSo(): void
    {
        $ports = [31 => ['port_id' => 31, 'device_id' => 3, 'ifName' => 'Gi1/0/49'], 21 => ['port_id' => 21, 'device_id' => 2, 'ifName' => 'Te1/0/1']];
        $link = (new FactCollector(self::NAMES, $ports))->links([['local_device_id' => 3, 'local_port_id' => 31, 'remote_device_id' => 2, 'remote_port_id' => 21,
            'remote_hostname' => 'dist1', 'remote_port' => 'Te1/0/1', 'remote_platform' => 'C9300', 'remote_version' => '', 'protocol' => 'lldp']]);
        $spof = ['s' => self::finding('spof_device', 'warning', [2, 3], [2])];
        $route = ['r' => ['analysis' => 'routing', 'code' => 'ospf_down', 'label' => 'x', 'severity' => 'warning', 'title' => 'OSPF of acc1', 'devices' => [3],
            'subjects' => [3], 'vlans' => [], 'quiet' => false]];
        $checks = fn (array $failed = [], array $factsErrors = [], array $enabled = ['redundancy', 'routing']) => ['checks' => ['enabled' => $enabled, 'failed' => $failed,
            'facts_failed' => array_keys($factsErrors), 'facts_errors' => $factsErrors]];
        $before = $checks() + self::state(self::T, $link, $spof + $route);

        // the links could not be read after the batch, and the redundancy check failed: no "link gone", no finding "resolved"
        $after = $checks(['redundancy' => 'TypeError: an argument is null'], [FactCollector::LINK => 'database error 42S02/1146: no such table']) + self::state(self::T + 600, [], $route);
        $compared = StateCompare::compare($before, $after);
        $this->assertSame([], $compared['events']);
        $this->assertSame([], $compared['findings']['resolved']);
        $this->assertSame(1, $compared['findings']['same'], 'the routing finding, which both read');
        $this->assertSame(['facts' => [FactCollector::LINK], 'checks' => ['redundancy'], 'errors' => ['facts' => [FactCollector::LINK => ['after' => 'database error 42S02/1146: no such table']],
            'checks' => ['redundancy' => ['after' => 'TypeError: an argument is null']]], 'devices' => []], $compared['skipped'], 'with the error of each, in the state it failed in');

        // the same check failed in both states
        $compared = StateCompare::compare($checks(['redundancy' => 'TypeError: before']) + self::state(self::T, $link, $route), $after);
        $this->assertSame(['before' => 'TypeError: before', 'after' => 'TypeError: an argument is null'], $compared['skipped']['errors']['checks']['redundancy']);

        // a check enabled in one state only (an admin switched it on between them): its findings are not new
        $after = $checks([], [], ['redundancy', 'routing', 'links']) + self::state(self::T + 600, $link, $spof + $route + ['l' => ['analysis' => 'links',
            'code' => 'description_wrong', 'label' => 'x', 'severity' => 'warning', 'title' => 'Port descriptions of acc1', 'devices' => [3], 'subjects' => [3], 'vlans' => [],
            'quiet' => false]]);
        $compared = StateCompare::compare($before, $after);
        $this->assertSame([], $compared['findings']['new']);
        $this->assertSame(['facts' => [], 'checks' => ['links'], 'errors' => ['facts' => [], 'checks' => []], 'devices' => []], $compared['skipped'],
            'a check without an error: it ran in one of the states only');

        // both read everything: nothing skipped
        $this->assertSame(['facts' => [], 'checks' => [], 'errors' => ['facts' => [], 'checks' => []], 'devices' => []],
            StateCompare::compare($before, $checks() + self::state(self::T + 600, $link, $spof + $route))['skipped']);
    }

    public function testASpanningTreeFactWhoseVlansChangedIsAChange(): void
    {
        $collector = new FactCollector(self::NAMES, [32 => ['port_id' => 32, 'device_id' => 3, 'ifName' => 'Gi1/0/50']]);
        $blocking = fn (array $vlans) => $collector->blockedPorts(array_map(fn (int $vlan) => ['device_id' => 3, 'port_id' => 32, 'vlan' => $vlan, 'state' => 'blocking'], $vlans));

        // the same port, the same fact: it blocks VLAN 10 too after the batch
        $events = StateCompare::compare(self::state(self::T, $blocking([1])), self::state(self::T + 600, $blocking([1, 10])))['events'];

        $this->assertSame([['blocking_started', [10], 'acc1 Gi1/0/50 started blocking in VLAN 10']], array_map(fn (array $e) => [$e['type'], $e['vlans'], $e['text']], $events));
        $this->assertSame([], StateCompare::compare(self::state(self::T, $blocking([1, 10])), self::state(self::T + 600, $blocking([1, 10])))['events']);
    }

    public function testOnlyTheFactsOfDevicesBothStatesCoverAreCompared(): void
    {
        $ports = [21 => ['port_id' => 21, 'device_id' => 2, 'ifName' => 'Te1/0/1'], 11 => ['port_id' => 11, 'device_id' => 1, 'ifName' => 'Te1/0/9']];
        $uplink = (new FactCollector(self::NAMES, $ports))->links([['local_device_id' => 2, 'local_port_id' => 21, 'remote_device_id' => 1, 'remote_port_id' => 11,
            'remote_hostname' => 'core1', 'remote_port' => 'Te1/0/9', 'remote_platform' => '', 'remote_version' => '', 'protocol' => 'lldp']]);
        // dist1 (2) a neighbor in the state before, in no scope after: the state after did not look at its uplink to core1
        $before = self::state(self::T, $uplink);
        $after = ['scope' => ['subjects' => [3], 'dependents' => [], 'neighbors' => [], 'included' => [], 'domain' => [1]]] + self::state(self::T + 600);

        $compared = StateCompare::compare($before, $after);

        $this->assertSame([], $compared['events'], 'not "Link gone": the state after did not cover dist1');
        $this->assertSame([2], $compared['skipped']['devices'], 'it is said instead');
        $this->assertSame(['link_removed'], array_column(StateCompare::compare($before, self::state(self::T + 600))['events'], 'type'), 'covered by both: gone');
    }

    public function testTheRootBridgeOfEachSwitchOfBothDomainsIsCompared(): void
    {
        // core1 (1) is in the domain of both states only (no fact of it is recorded): its root bridge moved
        $roots = fn (string $mac, int $owner) => ['roots' => [['root' => $mac, 'root_device' => $owner, 'vlans' => [0], 'devices' => [1]]]];
        $events = StateCompare::compare($roots('00ab01000000', 1) + self::state(self::T), $roots('00ab02000000', 2) + self::state(self::T + 600))['events'];

        $this->assertSame([['root_changed', [1], [0]]], array_map(fn (array $e) => [$e['type'], $e['devices'], $e['vlans']], $events));
        $this->assertSame(['mac' => '00ab01000000', 'device' => 1], $events[0]['root_before']);
        $this->assertSame(['mac' => '00ab02000000', 'device' => 2], $events[0]['root_after']);
    }

    public function testAFindingTakesThePlaceOfOneOfTheSameVlanOnly(): void
    {
        $stp = fn (array $vlans) => ['analysis' => 'stp', 'code' => 'root_default_priority', 'label' => 'x', 'severity' => 'warning', 'title' => 'Root bridge acc1 elected with the default priority',
            'devices' => [3], 'subjects' => [3], 'vlans' => $vlans, 'quiet' => false];
        $compared = StateCompare::compare(self::state(self::T, [], ['v10' => $stp([10])]), self::state(self::T + 600, [], ['v20' => $stp([20])]))['findings'];

        $this->assertSame([['v20'], ['v10'], []], [array_column($compared['new'], 'identity'), array_column($compared['resolved'], 'identity'), $compared['changed']],
            'VLAN 20\'s finding is a new one, VLAN 10\'s resolved');
        $same = StateCompare::compare(self::state(self::T, [], ['a' => $stp([10, 20])]), self::state(self::T + 600, [], ['b' => $stp([10])]))['findings'];
        $this->assertSame([['b'], 'a'], [array_column($same['changed'], 'identity'), $same['changed'][0]['was']['identity']], 'of the same VLAN (its first): its successor');
    }
}
