<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\FixAnswers;
use Agoodley\LibrenmsAnsible\Changes\FixExpectation;
use Agoodley\LibrenmsAnsible\Changes\Freshness;
use Agoodley\LibrenmsAnsible\Changes\VerificationOutcome;
use Agoodley\LibrenmsAnsible\Changes\VerificationRules;
use Agoodley\LibrenmsAnsible\Changes\VerificationText;
use Agoodley\LibrenmsAnsible\Runner\RunnerTemplate;
use Agoodley\LibrenmsAnsible\Runner\SurveyVar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4's pure rules: when LibreNMS' data of a batch's scope is fresh (Freshness), what a comparison of its states
 * means (VerificationRules, with a fix's expectation: FixExpectation), the outcome of a change (VerificationOutcome), and
 * the fix templates' contract (FixAnswers: the lines a change of fixes sends, every field checked).
 */
class VerificationRulesTest extends TestCase
{
    private const T = 1_700_000_000;

    // ------------------------------------------------------------------ freshness

    public function testTheBatchsDevicesNeedADiscoveryAndAPollTheDomainAPoll(): void
    {
        $state = fn (?int $discovered, ?int $polled, array $more = []) => $more + ['up' => true, 'down_since' => null, 'discovered_at' => $discovered, 'polled_at' => $polled,
            'discovery_pending' => false];
        $states = [
            1 => $state(self::T + 10, self::T + 20),          // fresh
            2 => $state(self::T - 10, self::T + 20),          // discovered before
            3 => $state(self::T + 10, self::T + 20, ['discovery_pending' => true]),
            4 => $state(self::T - 900, self::T + 5),          // domain: a poll is enough
            5 => $state(self::T - 900, self::T - 5),          // domain, not polled since
            6 => $state(null, null, ['up' => false, 'down_since' => self::T - 3600]),   // down since before the batch: left out
            7 => $state(null, null, ['up' => false, 'down_since' => self::T - 60]),     // went down during it
        ];

        $fresh = Freshness::check($states, [1, 2, 3, 6, 7, 8], [4, 5], self::T, self::T - 300);

        $this->assertFalse($fresh['fresh']);
        $this->assertSame([2 => 'a discovery', 3 => 'a discovery', 5 => 'a poll'], $fresh['waiting']);
        $this->assertSame([6], $fresh['left_out']);
        $this->assertSame([7], $fresh['down']);
        $this->assertSame([8], $fresh['unknown'], 'a device the API no longer tells about');
        $this->assertTrue(Freshness::check($states, [1], [4], self::T, self::T)['fresh']);
        $this->assertSame('lab-sw2 (a discovery), #3 (a discovery) and 1 more', Freshness::waitingText($fresh['waiting'], [2 => 'lab-sw2', 3 => '#3'], 2));
    }

    // ------------------------------------------------------------------ what a comparison means

    private static function comparison(array $events = [], array $findings = [], array $devices = []): array
    {
        return ['events' => $events, 'findings' => $findings + ['new' => [], 'worse' => [], 'changed' => [], 'milder' => [], 'resolved' => [], 'same' => 0],
            'devices' => $devices + ['down' => [], 'up' => []], 'names' => [1 => 'core1', 2 => 'dist1', 3 => 'acc1', 4 => 'acc2']];
    }

    private static function event(string $type, string $level, string $text, array $more = []): array
    {
        return ['type' => $type, 'category' => str_starts_with($type, 'root') || str_starts_with($type, 'blocking') ? 'stp' : 'links', 'level' => $level, 'text' => $text,
            'devices' => [3]] + $more;
    }

    private static function finding(string $identity, string $severity, string $title, array $more = []): array
    {
        return $more + ['identity' => $identity, 'analysis' => 'redundancy', 'code' => 'spof_link', 'severity' => $severity, 'title' => $title, 'devices' => [3], 'subjects' => [3],
            'vlans' => [], 'quiet' => false];
    }

    public function testWhatNobodyAskedForIsUnexpectedTheRestIsListed(): void
    {
        $result = VerificationRules::evaluate(self::comparison([
            self::event('link_removed', 'warning', 'Link gone: acc1 Gi1/0/49 ↔ dist1 Te1/0/1'),
            self::event('cable_moved', 'warning', 'acc1 Gi1/0/50 now connects to core2 Te1/0/9 (was core1 Te1/0/2)'),
            self::event('root_changed', 'warning', 'VLAN 10: root bridge changed from core1 to acc1', ['vlans' => [10], 'root_after' => ['mac' => '00ab03000000', 'device' => 3]]),
            self::event('blocking_started', 'info', 'acc1 Gi1/0/50 started blocking in VLAN 10', ['vlans' => [10]]),
            self::event('ospf_lost', 'warning', 'OSPF adjacency lost'),
            self::event('link_back', 'info', 'Link back: acc2 Gi1/0/49 ↔ dist1 Te1/0/2'),
            self::event('blocking_stopped', 'info', 'dist1 Te1/0/1 stopped blocking in VLAN 10', ['vlans' => [10]]),
        ], [
            'new' => [self::finding('a', 'warning', 'Single link: acc1'), self::finding('b', 'info', 'Single link: acc2'), self::finding('c', 'critical', 'Loop', ['quiet' => true])],
            'worse' => [self::finding('d', 'critical', 'Single point of failure: dist1', ['was' => ['identity' => 'd0', 'severity' => 'warning', 'devices' => [2]]])],
            'resolved' => [self::finding('e', 'warning', 'Old finding')],
        ], ['down' => [4], 'up' => [1]]), [], [], null, 'warning');

        $this->assertSame('unexpected', $result['result']);
        $this->assertSame(['link_removed', 'cable_moved', 'root_changed', 'blocking_started', 'ospf_lost', 'device_down', 'new', 'worse'], array_column($result['unexpected'], 'type'));
        $this->assertSame('acc2 went down', $result['unexpected'][5]['text']);
        $this->assertSame('Worse finding (critical): Single point of failure: dist1 (was warning)', $result['unexpected'][7]['text']);
        $listed = array_column($result['listed'], 'text', 'type');
        $this->assertSame(['link_back', 'blocking_stopped', 'device_up', 'new', 'new', 'resolved'], array_column($result['listed'], 'type'));
        $this->assertSame(['New finding (info): Single link: acc2', 'below'], [$result['listed'][3]['text'], $result['listed'][3]['why']], 'the info finding is below the setting');
        $this->assertSame(['New finding (critical): Loop', 'quiet'], [$result['listed'][4]['text'], $result['listed'][4]['why']],
            'a check the topology plugin keeps out of the eventlog never counts');
        $this->assertSame('Resolved (warning): Old finding', $listed['resolved']);
        $this->assertSame(8, $result['counts']['unexpected']);

        $this->assertSame('verified', VerificationRules::evaluate(self::comparison([self::event('link_back', 'info', 'Link back')], ['resolved' => [self::finding('e', 'warning', 'x')]]),
            [], [], null, 'warning')['result']);
        $this->assertSame('unexpected', VerificationRules::evaluate(self::comparison([], ['new' => [self::finding('b', 'info', 'Single link: acc2')]]), [], [], null, 'info')['result'],
            'counted from info on');
        $this->assertSame('verified', VerificationRules::evaluate(self::comparison([], ['new' => [self::finding('a', 'warning', 'x')]]), [], [], null, 'critical')['result']);
    }

    /** @return array<string, array{string}> the comparison's events that are changes nobody asked for, named here (the Changes page's types) */
    public static function unexpectedEvents(): array
    {
        return ['a link gone' => ['link_removed'], 'a cable moved' => ['cable_moved'], 'a new unmanaged neighbor' => ['unmanaged_new'],
            'a port-channel member lost' => ['lag_member_removed'], 'a root bridge moved' => ['root_changed'], 'a spanning tree instance gone' => ['root_gone'],
            'a port that started blocking' => ['blocking_started'], 'an OSPF adjacency lost' => ['ospf_lost'], 'a BGP session lost' => ['bgp_lost']];
    }

    #[DataProvider('unexpectedEvents')]
    public function testEachChangeNobodyAskedForIsUnexpectedNeverOnlyListed(string $type): void
    {
        $result = VerificationRules::evaluate(self::comparison([self::event($type, 'warning', "the $type event", ['vlans' => [10]])]), [], [], null, 'warning');

        $this->assertSame(['unexpected', [$type], []], [$result['result'], array_column($result['unexpected'], 'type'), $result['listed']]);
    }

    public function testADescriptionFixExpectsItsFindingsResolved(): void
    {
        $fix = FixAnswers::record(FixAnswers::PORT_DESCRIPTIONS, [['id' => 'f1', 'kind' => 'port_descriptions', 'title' => 'Port descriptions of acc1', 'devices' => [3],
            'resolves' => ['h3'], 'findings' => [['identity' => 'h3', 'code' => 'description_wrong', 'severity' => 'warning', 'title' => 'Port descriptions name the wrong neighbor: acc1',
                'devices' => [3]]]], ['id' => 'f2', 'kind' => 'port_descriptions', 'title' => 'Port descriptions of acc2', 'devices' => [4], 'resolves' => ['h4'],
            'findings' => [['identity' => 'h4', 'code' => 'description_missing', 'severity' => 'info', 'title' => 'Inter-switch ports without description: acc2', 'devices' => [4]]]]],
            ['acc1 Gi1/0/49 dist1 Te1/0/1']);
        $batch = FixExpectation::forBatch($fix, [3]);
        $this->assertSame(['kind' => 'port_descriptions', 'resolves' => ['h3' => 'Port descriptions name the wrong neighbor: acc1']], $batch);
        $this->assertNull(FixExpectation::forBatch(null, [3]));

        $resolved = VerificationRules::evaluate(self::comparison([], ['resolved' => [self::finding('h3', 'warning', 'Port descriptions name the wrong neighbor: acc1')]]), [],
            ['findings' => []], $batch, 'warning');
        $this->assertSame('verified', $resolved['result']);
        $this->assertSame(['Resolved (warning): Port descriptions name the wrong neighbor: acc1'], array_column($resolved['expected'], 'text'));

        $still = VerificationRules::evaluate(self::comparison(), [], ['findings' => ['h3' => self::finding('h3', 'warning', 'Port descriptions name the wrong neighbor: acc1')]],
            $batch, 'warning');
        $this->assertSame(["The fix's finding is still there: Port descriptions name the wrong neighbor: acc1"], array_column($still['unexpected'], 'text'));

        // still there in another form (one of two ports fixed): its successor
        $changed = VerificationRules::evaluate(self::comparison([], ['changed' => [self::finding('h3b', 'warning', 'Port descriptions name the wrong neighbor: acc1',
            ['was' => ['identity' => 'h3', 'severity' => 'warning', 'devices' => [3]]])]]), [], ['findings' => []], $batch, 'warning');
        $this->assertSame(['not_resolved'], array_column($changed['unexpected'], 'type'));
        $this->assertSame([], $changed['listed']);
    }

    /** A root fix: core1 the intended root of VLAN 1 / CST and VLAN 10 (acc1 the root now), dist1 its secondary. */
    private static function rootFix(): array
    {
        return FixAnswers::record(FixAnswers::STP_ROOT_PRIORITY, [['id' => 'r1', 'kind' => 'stp_root_priority', 'title' => 'Root bridge of VLAN 1 / CST, VLAN 10: core1',
            'vlans' => [0, 10], 'primary' => ['device_id' => 1, 'priority' => 4096, 'mode' => 'pvst', 'instances' => [1, 10], 'mac' => '00AB01000000'],
            'secondary' => ['device_id' => 2, 'priority' => 8192, 'mode' => 'pvst', 'instances' => [1, 10], 'mac' => '00ab02000000'], 'chosen_from' => 'the designated root bridges',
            'roots_now' => [0 => ['device_id' => 3, 'mac' => '00ab03000000'], 10 => ['device_id' => 3, 'mac' => '00ab03000000']], 'resolves' => ['p0'],
            'findings' => [['identity' => 'p0', 'code' => 'root_default_priority', 'severity' => 'warning', 'title' => 'Root bridge acc1 was elected with the default priority',
                'devices' => [3]]]]], ['core1 pvst 1,10 4096', 'dist1 pvst 1,10 8192']);
    }

    public function testARootFixIsOnlyWhatMovesInItsVlansToItsIntendedRoot(): void
    {
        $expectation = FixExpectation::forBatch(self::rootFix(), [1]);
        $roots = fn (string $mac, ?int $device, array $vlans, array $devices) => ['root' => $mac, 'root_device' => $device, 'vlans' => $vlans, 'devices' => $devices];
        $before = ['roots' => [$roots('00ab03000000', 3, [0, 10], [1, 2, 3, 4]), $roots('00ab02000000', 2, [30], [1, 2, 3, 4])]];
        $after = ['roots' => [$roots('00ab01000000', 1, [0, 10, 30], [1, 2, 3, 4])], 'findings' => []];
        $result = VerificationRules::evaluate(self::comparison([
            self::event('root_changed', 'warning', 'VLAN 1 / CST, VLAN 10: root bridge changed from acc1 to core1', ['vlans' => [0, 10], 'root_after' => ['mac' => '00ab01000000', 'device' => 1]]),
            // the intended root, but in a VLAN the fix does not set: no part of the fix
            self::event('root_changed', 'warning', 'VLAN 30: root bridge changed from dist1 to core1', ['vlans' => [30], 'root_after' => ['mac' => '00ab01000000', 'device' => 1]]),
            // a port that started blocking in one of the fix's VLANs and in another: no part of it either
            self::event('blocking_started', 'info', 'acc2 Gi1/0/50 started blocking in VLAN 10, VLAN 30', ['vlans' => [10, 30]]),
            self::event('blocking_started', 'info', 'acc2 Gi1/0/48 started blocking in VLAN 10', ['vlans' => [10]]),
        ]), $before, $after, $expectation, 'warning');

        $this->assertSame(['VLAN 1 / CST, VLAN 10: root bridge changed from acc1 to core1', 'acc2 Gi1/0/48 started blocking in VLAN 10'], array_column($result['expected'], 'text'));
        $this->assertSame(['VLAN 30: root bridge changed from dist1 to core1', 'acc2 Gi1/0/50 started blocking in VLAN 10, VLAN 30'], array_column($result['unexpected'], 'text'));

        // a switch of the fix's domain that reports no root bridge after the batch, where it saw the root the fix moves away from
        $lost = ['roots' => [$roots('00ab01000000', 1, [0, 10], [1, 2, 3]), $roots('00ab01000000', 1, [0], [4])], 'findings' => []];
        $none = VerificationRules::evaluate(self::comparison(), ['roots' => [$roots('00ab03000000', 3, [0, 10], [1, 2, 3, 4])]], $lost, $expectation, 'warning');
        $this->assertSame([['root_not_intended', [4]]], array_map(fn (array $e) => [$e['type'], $e['devices']], $none['unexpected']));
        $this->assertSame('VLAN 10: acc2 reports no root bridge, not core1, the root the fix intends', $none['unexpected'][0]['text']);
        $many = VerificationRules::evaluate(self::comparison(), ['roots' => [$roots('00ab03000000', 3, [0, 10], [1, 2, 3, 4])]],
            ['roots' => [$roots('00ab03000000', 3, [0, 10], [1, 2, 3, 4])], 'findings' => []], $expectation, 'warning');
        $this->assertSame(['VLAN 1 / CST: core1, dist1, acc1, acc2 see acc1 as root bridge, not core1, the root the fix intends',
            'VLAN 10: core1, dist1, acc1, acc2 see acc1 as root bridge, not core1, the root the fix intends'], array_column($many['unexpected'], 'text'), 'several: see');
    }

    public function testARootFixExpectsTheIntendedRootAndWhatMovesWithIt(): void
    {
        $fix = FixAnswers::record(FixAnswers::STP_ROOT_PRIORITY, [['id' => 'r1', 'kind' => 'stp_root_priority', 'title' => 'Root bridge of VLAN 1 / CST, VLAN 10: core1',
            'vlans' => [0, 10], 'primary' => ['device_id' => 1, 'priority' => 4096, 'mode' => 'pvst', 'instances' => [1, 10], 'mac' => '00AB01000000'],
            'secondary' => ['device_id' => 2, 'priority' => 8192, 'mode' => 'pvst', 'instances' => [1, 10], 'mac' => '00ab02000000'], 'chosen_from' => 'the designated root bridges',
            'roots_now' => [0 => ['device_id' => 3, 'mac' => '00ab03000000'], 10 => ['device_id' => 3, 'mac' => '00ab03000000']], 'resolves' => ['p0'],
            'findings' => [['identity' => 'p0', 'code' => 'root_default_priority', 'severity' => 'warning', 'title' => 'Root bridge acc1 was elected with the default priority',
                'devices' => [3]]]]], ['core1 pvst 1,10 4096', 'dist1 pvst 1,10 8192']);
        $expectation = FixExpectation::forBatch($fix, [1]);
        $this->assertSame([['vlans' => [0, 10], 'primary' => 1, 'primary_mac' => '00ab01000000', 'old_macs' => ['00ab03000000']]], $expectation['roots']);

        $roots = fn (string $mac, ?int $device, array $devices) => [['root' => $mac, 'root_device' => $device, 'vlans' => [0, 10], 'devices' => $devices]];
        $before = ['roots' => $roots('00ab03000000', 3, [1, 2, 3, 4])];
        $after = ['roots' => $roots('00ab01000000', 1, [1, 2, 3, 4]), 'findings' => []];
        $moved = self::comparison([
            self::event('root_changed', 'warning', 'VLAN 1 / CST, VLAN 10: root bridge changed from acc1 to core1', ['vlans' => [0, 10], 'root_after' => ['mac' => '00ab01000000', 'device' => 1]]),
            self::event('blocking_started', 'info', 'acc2 Gi1/0/50 started blocking in VLAN 10', ['vlans' => [10]]),
            self::event('blocking_stopped', 'info', 'acc1 Gi1/0/49 stopped blocking in VLAN 1 / CST', ['vlans' => [0]]),
            self::event('blocking_started', 'info', 'acc2 Gi1/0/48 started blocking in VLAN 30', ['vlans' => [30]]),
        ], ['resolved' => [self::finding('p0', 'warning', 'Root bridge acc1 was elected with the default priority')]]);

        $result = VerificationRules::evaluate($moved, $before, $after, $expectation, 'warning');

        $this->assertSame(['root_changed', 'blocking_started', 'blocking_stopped', 'resolved'], array_column($result['expected'], 'type'), 'the root moving and what moves with it');
        $named = VerificationRules::evaluate(self::comparison([], ['resolved' => [self::finding('p0', 'warning', 'Root bridge acc1 was elected with the default priority',
            ['analysis' => 'stp', 'vlans' => [10, 0]])]]), $before, $after, $expectation, 'warning');
        $this->assertSame('Resolved (warning): VLAN 1 / CST, VLAN 10: Root bridge acc1 was elected with the default priority', $named['expected'][0]['text'],
            'a spanning tree finding with its VLANs');
        $this->assertSame(['acc2 Gi1/0/48 started blocking in VLAN 30'], array_column($result['unexpected'], 'text'), 'another VLAN is no part of the fix');

        // the intended root not root everywhere: acc2 still sees acc1 in VLAN 10
        $partly = ['roots' => [['root' => '00ab01000000', 'root_device' => 1, 'vlans' => [0, 10], 'devices' => [1, 2, 3]], ['root' => '00ab01000000', 'root_device' => 1, 'vlans' => [0],
            'devices' => [4]], ['root' => '00ab03000000', 'root_device' => 3, 'vlans' => [10], 'devices' => [4]]], 'findings' => []];
        $wrong = VerificationRules::evaluate(self::comparison(), $before, $partly, $expectation, 'warning');
        $this->assertSame(['VLAN 10: acc2 sees acc1 as root bridge, not core1, the root the fix intends'], array_column($wrong['unexpected'], 'text'));

        // the second batch (the secondary): the root stays, nothing moves; a root that moved elsewhere is not what the fix does
        $this->assertSame('verified', VerificationRules::evaluate(self::comparison(), $after, $after, FixExpectation::forBatch($fix, [2]), 'warning')['result']);
        $elsewhere = VerificationRules::evaluate(self::comparison([self::event('root_changed', 'warning', 'VLAN 10: root bridge changed from core1 to dist1', ['vlans' => [10],
            'root_after' => ['mac' => '00ab02000000', 'device' => 2]])]), $after, ['roots' => [['root' => '00ab02000000', 'root_device' => 2, 'vlans' => [10], 'devices' => [1, 2, 3, 4]],
            ['root' => '00ab01000000', 'root_device' => 1, 'vlans' => [0], 'devices' => [1, 2, 3, 4]]], 'findings' => []], FixExpectation::forBatch($fix, [2]), 'warning');
        $this->assertSame(['root_changed', 'root_not_intended'], array_column($elsewhere['unexpected'], 'type'));

        // what LibreNMS reports as the root moves (its eventlog of root and root port changes: the spanning tree check's findings) and the
        // ports the secondary's priority makes block are the fix's doing while the root is the intended one; in any other change they count
        $rootChanged = ['identity' => 'rc', 'analysis' => 'stp', 'code' => 'root_changed', 'severity' => 'warning', 'title' => 'Root bridge changes detected', 'devices' => [1, 2, 3, 4],
            'subjects' => [1, 2, 3, 4], 'vlans' => [], 'quiet' => false];
        $withFinding = self::comparison([self::event('blocking_started', 'info', 'acc1 Gi1/0/50 started blocking in VLAN 10', ['vlans' => [10]])], ['new' => [$rootChanged]]);
        $secondary = VerificationRules::evaluate($withFinding, $after, $after, FixExpectation::forBatch($fix, [2]), 'warning');
        $this->assertSame(['verified', ['blocking_started', 'new']], [$secondary['result'], array_column($secondary['expected'], 'type')]);
        $this->assertSame('unexpected', VerificationRules::evaluate($withFinding, $after, $after, null, 'warning')['result'], 'no fix: the root changes count');
        $this->assertSame(['blocking_started', 'new', 'root_not_intended'], array_values(array_unique(array_column(VerificationRules::evaluate($withFinding, $before, $partly, $expectation,
            'warning')['unexpected'], 'type'))), 'the root not the intended one everywhere: they count too');
    }

    // ------------------------------------------------------------------ the outcome

    public function testTheOutcomeOfAChangesVerification(): void
    {
        $batch = fn (string $status, ?array $verify) => ['status' => $status, 'verify' => $verify];
        $this->assertSame(['outcome' => 'verified', 'note' => null], VerificationOutcome::of([1 => $batch('done', ['result' => 'verified']), 2 => $batch('done', ['result' => 'verified'])]));
        $this->assertSame(['outcome' => 'accepted', 'note' => 'what batch 1 changed was accepted'], VerificationOutcome::of([1 => $batch('done', ['result' => 'unexpected',
            'accepted' => ['by' => 'bob']]), 2 => $batch('done', ['result' => 'verified'])]));
        $this->assertSame(['outcome' => 'not_verified', 'note' => 'batch 1 made unexpected changes; batch 2 failed'], VerificationOutcome::of([1 => $batch('done', ['result' => 'unexpected']),
            2 => $batch('failed', ['before' => []])]));
        $this->assertSame('not_verified', VerificationOutcome::of([1 => $batch('done', ['result' => 'unknown', 'accepted' => ['by' => 'bob']])])['outcome'],
            'going on without a verification does not make it one');
        $this->assertSame(['outcome' => null, 'note' => null], VerificationOutcome::of([1 => $batch('cancelled', null)]), 'nothing ran');
        // a batch verified from a state before it on older data (its wait for fresh data over): said with the outcome
        $older = ['result' => 'verified', 'before' => ['stale' => true, 'older' => 'the Apply']];
        $this->assertSame(['outcome' => 'verified', 'note' => 'batch 1 on data older than the Apply before it'], VerificationOutcome::of([1 => $batch('done', $older),
            2 => $batch('done', ['result' => 'verified'])]));
        $this->assertSame(['outcome' => 'accepted', 'note' => 'what batch 2 changed was accepted; batch 1 on data older than the Apply before it'], VerificationOutcome::of([
            1 => $batch('done', $older), 2 => $batch('done', ['result' => 'unexpected', 'accepted' => ['by' => 'bob']])]));
        $this->assertSame(['outcome' => 'verified', 'note' => null], VerificationOutcome::of([1 => $batch('done', ['result' => 'verified', 'before' => ['stale' => false,
            'older' => 'the Apply']])]), 'fresh: nothing to say');
        $this->assertSame(['outcome' => null, 'note' => null], VerificationOutcome::of([1 => $batch('done', null)]), 'not verified at all (applied before phase 4)');
    }

    public function testWhatAComparisonCouldNotReadIsNamedWithItsErrorAndTheWaysOut(): void
    {
        $this->assertNull(VerificationRules::skippedWhy(['facts' => [], 'checks' => [], 'errors' => ['facts' => [], 'checks' => []]]));
        $failed = VerificationRules::skippedWhy(['facts' => [], 'checks' => ['routing'], 'errors' => ['checks' => ['routing' => ['after' => 'RuntimeException: cannot read the OSPF neighbours']]]]);
        $this->assertSame("the topology plugin's routing check failed in the state after it (RuntimeException: cannot read the OSPF neighbours); nothing else that was "
            . "compared changed. To have it verified: fix it (the details are in logs/librenms.log) or switch the routing check off in the topology plugin's settings "
            . '(Background checks)', $failed['why']);
        $this->assertStringNotContainsString('When a batch cannot be verified', $failed['why'], 'no setting is a way out of a batch paused already');
        $this->assertSame("the topology plugin's routing check failed in the state before or after it", $failed['event']);
        // a check switched on between the two states; the fix's own check failed (switching it off would not verify the fix)
        $both = VerificationRules::skippedWhy(['facts' => [], 'checks' => ['links', 'stp'], 'errors' => ['checks' => ['stp' => ['before' => 'Error: x']]]], 'stp');
        $this->assertStringContainsString("the topology plugin's links check ran in one of the two states only: it was switched on or off in its settings (Background checks) "
            . "between them; the topology plugin's spanning tree check, which found the fix's findings, failed in the state before it (Error: x)", $both['why']);
        $this->assertStringEndsWith("To have it verified: fix it (the details are in logs/librenms.log) or switch the links check back as it was in the topology plugin's "
            . 'settings', $both['why']);
        // nothing that would verify the batch: no way to name (an acceptance is the decision's)
        $this->assertStringEndsWith('is switched off in its settings (Background checks); nothing else that was compared changed',
            VerificationRules::skippedWhy(['facts' => [], 'checks' => []], 'links')['why']);
        $this->assertStringNotContainsString('spanning tree check off', $both['why']);
        // what the API names is data: one line
        $hostile = VerificationRules::skippedWhy(['facts' => ["x\ny"], 'checks' => [], 'errors' => ['facts' => ["x\ny" => ['after' => "a\nb"]]]]);
        $this->assertStringNotContainsString("\n", $hostile['why']);
    }

    public function testTheOutcomeOfAChangeAppliedWithoutVerificationSaysWhy(): void
    {
        $this->assertSame('not verified (switched off)', VerificationText::listed('not_verified', VerificationOutcome::SWITCHED_OFF));
        $this->assertSame('not verified', VerificationText::listed('not_verified', 'the topology plugin is not installed'), 'why is the label\'s title');
        $this->assertSame('verified with accepted changes', VerificationText::listed('accepted', 'what batch 1 changed was accepted'));
        $this->assertNull(VerificationText::listed(null, null));
        $this->assertSame('the topology plugin is not installed when it was applied: its batches run without a verification',
            VerificationText::note('the topology plugin is not installed', true));
        $this->assertSame('batch 1 could not be verified', VerificationText::note('batch 1 could not be verified'));
        $this->assertStringStartsWith('switched off in the settings (Verify changes) when it was applied', (string) VerificationText::note(VerificationOutcome::SWITCHED_OFF));
    }

    // ------------------------------------------------------------------ the fix templates' contract

    public function testADescriptionWithABackslashIsNotOneTheLinkCheckBuilds(): void
    {
        $refused = FixAnswers::check('port_descriptions', 'acc1.lab Gi1/0/49 C:\\temp', [3 => 'acc1.lab']);
        $this->assertSame(['line 1: its description is not one the link check builds (letters, digits, spaces and . _ / : + ( ) # @ , -, at most 80)'], $refused);
        $this->assertSame([], FixAnswers::check('port_descriptions', 'acc1.lab Gi1/0/49 dist1 Te1/0/1 (a/b)', [3 => 'acc1.lab']));
        $this->assertSame('A-Za-z0-9 ._\\/:+()#@,-', FixAnswers::DESCRIPTION_CHARS, 'the topology plugin\'s characters (LinkAnalyzer::DESCRIPTION_CHARS): the slash escaped');
    }

    public function testTheLinesOfDescriptionFixesAreCheckedFieldByField(): void
    {
        $hosts = [3 => 'acc1.lab', 4 => 'acc2.lab'];
        $fix = ['id' => 'f1', 'kind' => 'port_descriptions', 'ports' => [
            ['device_id' => 3, 'port_id' => 31, 'ifName' => 'Gi1/0/49', 'description' => 'dist1 Te1/0/1'],
            ['device_id' => 3, 'port_id' => 32, 'ifName' => 'Gi0/0/1 <&"\'>', 'description' => 'dist1 Te1/0/2'],          // a hostile ifName
            ['device_id' => 3, 'port_id' => 33, 'ifName' => 'Gi1/0/50', 'description' => "x\nusername backdoor"],          // not a description the link check builds
            ['device_id' => 9, 'port_id' => 91, 'ifName' => 'Gi1/0/1', 'description' => 'acc1 Gi1/0/1'],                   // not a target
            ['device_id' => 4, 'port_id' => 41, 'ifName' => 'Ethernet1/1', 'description' => '_n_c_d_ _sw_ _ co Gi1/0/1'],
        ]];

        $built = FixAnswers::lines(FixAnswers::PORT_DESCRIPTIONS, [$fix], $hosts);

        $this->assertSame(['acc1.lab Gi1/0/49 dist1 Te1/0/1', 'acc2.lab Ethernet1/1 _n_c_d_ _sw_ _ co Gi1/0/1'], $built['lines']);
        $this->assertSame([3, 4], $built['devices']);
        $this->assertCount(3, $built['errors']);
        // said by the device's and the port's names, as LibreNMS has them (cleaned)
        $this->assertStringStartsWith('acc1.lab Gi0/0/1 <&"\'>: its interface name is not one the plugin passes to a playbook', $built['errors'][0]);
        $this->assertStringStartsWith('acc1.lab Gi1/0/50: its description is not one the link check builds', $built['errors'][1]);
        $this->assertSame('device #9 Gi1/0/1: it is not a target of the change', $built['errors'][2]);

        // the check of an answer, as the playbook gets it
        $this->assertSame([], FixAnswers::check(FixAnswers::PORT_DESCRIPTIONS, implode("\n", $built['lines']), $hosts));
        $this->assertSame(['line 1: its host is not a target of the change', 'line 2: its interface name is not one the plugin passes to a playbook (letters, digits and / . _ : -, at most 64)',
            'line 3: its description is not one the link check builds (letters, digits, spaces and . _ / : + ( ) # @ , -, at most 80)', 'line 4 is not "<host> <interface> <description>"'],
            FixAnswers::check(FixAnswers::PORT_DESCRIPTIONS, "other.lab Gi1/0/1 x\nacc1.lab Gi1/0/1;reload x\nacc1.lab Gi1/0/1 x;y\nacc1.lab Gi1/0/1", $hosts));
        $this->assertSame(['line 1: its description is not one the link check builds (letters, digits, spaces and . _ / : + ( ) # @ , -, at most 80)'],
            FixAnswers::check(FixAnswers::PORT_DESCRIPTIONS, 'acc1.lab Gi1/0/1 ' . str_repeat('a', 81), $hosts));

        // a description as the link check builds it: no space at its ends, never two in a row; an interface of at most 64 characters, no space;
        // a host as the inventory names one
        $refused = 'line 1: its description is not one the link check builds (letters, digits, spaces and . _ / : + ( ) # @ , -, at most 80)';
        foreach (['acc1.lab Gi1/0/49 dist1 Te1/0/1 ', 'acc1.lab Gi1/0/49 dist1  Te1/0/1', "acc1.lab Gi1/0/49 dist1 Te1/0/1\t"] as $answer) {
            $this->assertSame([$refused], FixAnswers::check(FixAnswers::PORT_DESCRIPTIONS, $answer, $hosts), json_encode($answer));
        }
        $this->assertSame([], FixAnswers::check(FixAnswers::PORT_DESCRIPTIONS, 'acc1.lab G' . str_repeat('i', 63) . ' x', $hosts), 'an interface of 64 characters');
        $this->assertSame(['line 1: its interface name is not one the plugin passes to a playbook (letters, digits and / . _ : -, at most 64)'],
            FixAnswers::check(FixAnswers::PORT_DESCRIPTIONS, 'acc1.lab G' . str_repeat('i', 64) . ' x', $hosts), 'one of 65');
        $spaced = FixAnswers::lines(FixAnswers::PORT_DESCRIPTIONS, [['kind' => 'port_descriptions', 'ports' => [['device_id' => 3, 'port_id' => 34, 'ifName' => 'Gi1/0/49 x',
            'description' => 'dist1 Te1/0/1']]]], $hosts);
        $this->assertSame([], $spaced['lines']);
        $this->assertStringStartsWith('acc1.lab Gi1/0/49 x: its interface name is not one the plugin passes to a playbook', $spaced['errors'][0]);
        $this->assertSame(['line 1: its host is not a target of the change'], FixAnswers::check(FixAnswers::PORT_DESCRIPTIONS, 'acc;1.lab Gi1/0/49 x', [3 => 'acc;1.lab']),
            'a target whose inventory name is not one a line may hold');
        $many = implode("\n", array_fill(0, FixAnswers::LINES_MAX + 1, 'acc1.lab Gi1/0/49 x'));
        $this->assertSame(['More than 2000 lines.'], FixAnswers::check(FixAnswers::PORT_DESCRIPTIONS, $many, $hosts));
        $this->assertSame([], FixAnswers::check(FixAnswers::PORT_DESCRIPTIONS, implode("\n", array_fill(0, FixAnswers::LINES_MAX, 'acc1.lab Gi1/0/49 x')), $hosts));

        // the interface must be a port of its host as LibreNMS knows it
        $interfaces = [3 => ['Gi1/0/49', 'Gi1/0/50'], 4 => ['Ethernet1/1']];
        $this->assertSame([], FixAnswers::check(FixAnswers::PORT_DESCRIPTIONS, implode("\n", $built['lines']), $hosts, $interfaces));
        $this->assertSame(['line 1: its interface is not a port of its host in LibreNMS', 'line 2: its interface is not a port of its host in LibreNMS'],
            FixAnswers::check(FixAnswers::PORT_DESCRIPTIONS, "acc1.lab Ethernet1/1 dist1 Te1/0/1\nacc2.lab Gi1/0/49 dist1 Te1/0/1", $hosts, $interfaces));
    }

    public function testWhatCannotBeALineIsSaidByNameWithWhyADeviceCannotBeATarget(): void
    {
        $hosts = [3 => 'acc1.lab'];
        $names = [3 => 'acc1', 9 => 'lab-sw2', 8 => "edge\n7"];
        $refusals = [9 => 'not in the allowed device group Ansible lab'];
        $port = fn (int $deviceId, int $portId, string $ifName) => ['device_id' => $deviceId, 'port_id' => $portId, 'ifName' => $ifName, 'description' => 'dist1 Te1/0/1'];
        $fixes = [
            ['kind' => 'port_descriptions', 'ports' => [$port(9, 91, 'Fa0/1'), $port(9, 92, 'Fa0/2')]],
            ['kind' => 'port_descriptions', 'ports' => [$port(3, 31, 'Gi1/0/49'), $port(3, 32, "Gi1/0/50\nreload")]],
            ['kind' => 'port_descriptions', 'ports' => [$port(8, 81, ''), ...array_map(fn (int $n) => $port(8, 81 + $n, "Fa0/$n"), range(1, 6))]],
        ];

        $built = FixAnswers::lines(FixAnswers::PORT_DESCRIPTIONS, $fixes, $hosts, $names, $refusals);
        $this->assertSame(['acc1.lab Gi1/0/49 dist1 Te1/0/1'], $built['lines']);
        $this->assertSame([
            'acc1 Gi1/0/50 reload: its interface name is not one the plugin passes to a playbook (letters, digits and / . _ : -, at most 64)',
            // a device that cannot be a target once, with its ports and why (the target check of New change)
            'lab-sw2 Fa0/1, Fa0/2: it cannot be a target: not in the allowed device group Ansible lab',
            'edge 7 port #81, Fa0/1, Fa0/2, Fa0/3, Fa0/4 and 2 more: it is not a target of the change',
        ], $built['errors']);

        // a root fix: its bridge that cannot be a target, by name
        $root = ['kind' => 'stp_root_priority', 'primary' => ['device_id' => 3, 'priority' => 4096, 'mode' => 'pvst', 'instances' => [1]],
            'secondary' => ['device_id' => 9, 'priority' => 8192, 'mode' => 'pvst', 'instances' => [1]]];
        $this->assertSame(['lab-sw2: it cannot be a target: not in the allowed device group Ansible lab'],
            FixAnswers::lines(FixAnswers::STP_ROOT_PRIORITY, [$root], $hosts, $names, $refusals)['errors']);
        $this->assertSame(['acc1: its priority is not a multiple of 4096 from 0 to 61440'],
            FixAnswers::lines(FixAnswers::STP_ROOT_PRIORITY, [['primary' => ['priority' => 4097] + $root['primary'], 'secondary' => null] + $root], $hosts, $names)['errors']);
    }

    public function testAHostileNeighborNameReachesAPlaybookOnlyAsTheLinkChecksDescription(): void
    {
        // the name and port a hostile switch sends by LLDP/CDP, as the topology plugin's link check cleans them for a description
        // (LinkAnalyzer::cleanDescription: anything outside its characters becomes "_", spaces are single, at most 80)
        $hostile = "evil-sw\nusername backdoor privilege 15 secret 0 x\nend Gi0/1;reload";
        $clean = 'evil-sw_username backdoor privilege 15 secret 0 x_end Gi0/1_reload';
        $hosts = [5 => 'lab-sw5.netops.lab'];
        $port = ['device_id' => 5, 'port_id' => 510, 'ifName' => 'Fa0/10', 'current' => 'lab-sw7 Fa0/12'];

        $built = FixAnswers::lines(FixAnswers::PORT_DESCRIPTIONS, [['id' => 'f', 'kind' => 'port_descriptions', 'ports' => [$port + ['description' => $clean]]]], $hosts);
        $this->assertSame(['lab-sw5.netops.lab Fa0/10 ' . $clean], $built['lines'], 'one line, its one field the cleaned text');
        $this->assertSame([], FixAnswers::check(FixAnswers::PORT_DESCRIPTIONS, implode("\n", $built['lines']), $hosts, [5 => ['Fa0/10']]));

        // the raw text never: a fix that would carry it is refused, and an answer with it fails the check line by line
        $raw = FixAnswers::lines(FixAnswers::PORT_DESCRIPTIONS, [['id' => 'f', 'kind' => 'port_descriptions', 'ports' => [$port + ['description' => $hostile]]]], $hosts);
        $this->assertSame([], $raw['lines']);
        $this->assertStringContainsString('its description is not one the link check builds', $raw['errors'][0]);
        $this->assertSame(['line 2: its host is not a target of the change', 'line 3 is not "<host> <interface> <description>"'],
            FixAnswers::check(FixAnswers::PORT_DESCRIPTIONS, 'lab-sw5.netops.lab Fa0/10 ' . $hostile, $hosts, [5 => ['Fa0/10']]));
    }

    public function testAFixTemplateMustHaveTheContractsVariable(): void
    {
        $template = fn (array $vars) => new RunnerTemplate('211', 'Fix <descriptions>', '', 'lnms4_port_descriptions.yml', 'ansible', '', '1', true, [], [], '', $vars);
        $var = fn (string $name, string $type) => new SurveyVar($name, $name, '', $type, $type, true);

        $this->assertNull(FixAnswers::templateProblem($template([$var('port_descriptions', 'text')]), FixAnswers::PORT_DESCRIPTIONS));
        $this->assertSame('template #211 (Fix <descriptions>) has no survey variable stp_priorities, which the fix templates\' contract asks for (see the plugin\'s README, Fixes)',
            FixAnswers::templateProblem($template([$var('port_descriptions', 'text')]), FixAnswers::STP_ROOT_PRIORITY));
        $this->assertSame('the survey variable port_descriptions of template #211 (Fix <descriptions>) is of type string, not text (several lines), as the fix templates\' contract asks',
            FixAnswers::templateProblem($template([$var('port_descriptions', 'string')]), FixAnswers::PORT_DESCRIPTIONS));
        $this->assertSame('the runner has no such template', FixAnswers::templateProblem(null, FixAnswers::PORT_DESCRIPTIONS));
        $this->assertSame('there is no fix of that kind', FixAnswers::templateProblem($template([]), 'reboot'));
    }

    public function testTheLinesOfRootFixesAreCheckedFieldByField(): void
    {
        $hosts = [1 => 'core1.lab', 2 => 'core2.lab'];
        $fix = ['id' => 'r1', 'kind' => 'stp_root_priority', 'primary' => ['device_id' => 1, 'priority' => 4096, 'mode' => 'pvst', 'instances' => [1, 10, 11, 12, 20, 21]],
            'secondary' => ['device_id' => 2, 'priority' => 8192, 'mode' => 'mst', 'instances' => [0]]];
        $this->assertSame(['core1.lab pvst 1,10-12,20,21 4096', 'core2.lab mst 0 8192'], FixAnswers::lines(FixAnswers::STP_ROOT_PRIORITY, [$fix], $hosts)['lines']);
        $single = ['kind' => 'stp_root_priority', 'primary' => ['device_id' => 1, 'priority' => 4096, 'mode' => 'stp', 'instances' => []], 'secondary' => null];
        $this->assertSame(['core1.lab stp - 4096'], FixAnswers::lines(FixAnswers::STP_ROOT_PRIORITY, [$single], $hosts)['lines'], 'one instance: "-"');

        foreach ([['mode' => 'rstp'], ['instances' => [0]], ['instances' => [4095]], ['priority' => 4097], ['priority' => 65536], ['priority' => '4096']] as $wrong) {
            $built = FixAnswers::lines(FixAnswers::STP_ROOT_PRIORITY, [['kind' => 'stp_root_priority', 'primary' => $wrong + $fix['primary'], 'secondary' => null]], $hosts);
            $this->assertSame([], $built['lines'], json_encode($wrong));
            $this->assertNotEmpty($built['errors']);
        }

        $this->assertSame([], FixAnswers::check(FixAnswers::STP_ROOT_PRIORITY, "core1.lab pvst 1,10-12,20,21 4096\ncore2.lab mst 0 8192\ncore2.lab stp - 0", $hosts));
        $this->assertSame([
            'line 1: its instances are not a canonical list of VLANs 1-4094',
            'line 2: its instances are not a canonical list of VLANs 1-4094',
            'line 3: its priority is not a multiple of 4096 from 0 to 61440',
            'line 4: its mode is not pvst, mst or stp',
            'line 5 is not "<host> <mode> <instances> <priority>"',
            'line 6: its instances are not a canonical list of MST instances 0-4094',
        ], FixAnswers::check(FixAnswers::STP_ROOT_PRIORITY, "core1.lab pvst 10,1 4096\ncore1.lab pvst 1,2,3 4096\ncore1.lab pvst 1 4095\ncore1.lab rstp 1 4096\n"
            . "core1.lab pvst 1 4096 extra\ncore2.lab mst 4095 8192", $hosts));
        $this->assertSame('1,10-12,20,21', FixAnswers::instances('pvst', [21, 20, 12, 11, 10, 1, 10]));
        $this->assertNull(FixAnswers::instances('pvst', [0]));
        $this->assertNull(FixAnswers::instances('pvst', ['1']));
    }
}
