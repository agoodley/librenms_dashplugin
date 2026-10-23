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
}
