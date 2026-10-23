<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\FixAnswers;
use Agoodley\LibrenmsAnsible\Changes\FixChange;
use Agoodley\LibrenmsAnsible\Changes\FixFilter;
use Agoodley\LibrenmsAnsible\Changes\RolloutPlan;
use Agoodley\LibrenmsAnsible\Changes\TopologyPlan;
use PHPUnit\Framework\TestCase;

/**
 * A change made from fixes (FixChange): the fixes chosen among the topology plugin's answer, the devices they change,
 * and a root priority fix's own batches (the intended root alone first, then its secondary), each with its blast radius.
 */
class FixChangeTest extends TestCase
{
    private const D1 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const D2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const R1 = 'cccccccccccccccccccccccccccccccccccccccc';

    private static function answer(): array
    {
        return ['version' => '1.0', 'fixes' => [
            ['id' => self::D1, 'kind' => 'port_descriptions', 'title' => 'Port descriptions of lab-sw2', 'devices' => [502]],
            ['id' => self::D2, 'kind' => 'port_descriptions', 'title' => 'Port descriptions of lab-sw6', 'devices' => [512]],
            ['id' => self::R1, 'kind' => 'stp_root_priority', 'title' => 'Root bridge of VLAN 1 / CST, VLAN 10: lab-sw1, secondary lab-sw2', 'devices' => [501, 502],
                'primary' => ['device_id' => 501, 'priority' => 4096], 'secondary' => ['device_id' => 502, 'priority' => 8192]],
        ]];
    }

    public function testTheChosenFixesMustStillBeOfferedAndOfOneKind(): void
    {
        $chosen = FixChange::choose('port_descriptions', [self::D2, self::D1, self::D2], self::answer());
        $this->assertNull($chosen['error']);
        $this->assertSame([self::D2, self::D1], array_column($chosen['fixes'], 'id'), 'in the order chosen, once each');

        $gone = FixChange::choose('port_descriptions', [self::D1, str_repeat('d', 40)], self::answer());
        $this->assertSame([], $gone['fixes']);
        $this->assertSame('A fix you chose is no longer offered (its findings were resolved or changed since you loaded the Fixes page): look again.', $gone['error']);
        $this->assertSame('A change is made from fixes of one kind.', FixChange::choose('port_descriptions', [self::D1, self::R1], self::answer())['error']);
        $this->assertSame('Choose fixes of one kind on the Fixes page.', FixChange::choose('reboot', [self::D1], self::answer())['error']);
        $this->assertSame('Choose fixes of one kind on the Fixes page.', FixChange::choose('port_descriptions', ['../x', '1 OR 1=1'], self::answer())['error'],
            'ids that are not of the topology plugin: none chosen');
        $this->assertSame('A change takes at most 200 fixes.', FixChange::choose('port_descriptions', array_map(fn (int $n) => sha1((string) $n), range(1, 201)), self::answer())['error']);
    }

    public function testTheFixesPageFiltersAsTheTopologyPagesDo(): void
    {
        $this->assertSame([], FixFilter::parse([]), 'no filter: every device');
        $this->assertSame(['device' => 502, 'location' => 7], FixFilter::parse(['device' => '502', 'group' => '', 'location' => '7', 'other' => 'x']));
        foreach ([['device' => '0'], ['group' => '1 OR 1=1'], ['location' => ['7']], ['device' => '-3'], ['device' => '12345678901']] as $bad) {
            $this->assertNull(FixFilter::parse($bad), 'not a number: ' . json_encode($bad));
        }

        $devices = [501 => ['location_id' => 7], 502 => ['location_id' => 7], 503 => ['location_id' => 8], 510 => ['location_id' => 7], 511 => ['location_id' => 7, 'disabled' => 1]];
        $memberships = [501 => [3], 502 => [3, 4], 503 => [3], 511 => [3]];
        $visible = [501 => true, 502 => true, 503 => true, 511 => true];
        $this->assertSame([502], FixFilter::keep(['device' => 502], $devices, $memberships, $visible));
        $this->assertSame([501, 502], FixFilter::keep(['group' => 3, 'location' => 7], $devices, $memberships, $visible), 'each filter narrows; 510 is not seen, 511 disabled');
        $this->assertSame([], FixFilter::keep(['device' => 503, 'location' => 7], $devices, $memberships, $visible), 'a device elsewhere: none');
        $this->assertSame([], FixFilter::keep(['device' => 510], $devices, $memberships, $visible), 'a device the admin does not see: none');

        // more devices than the topology plugin takes at once: the fixes of every device, kept for them as it keeps them
        $fixes = self::answer()['fixes'];
        $fixes[2]['findings'] = [['identity' => 'r', 'devices' => [503]]];
        $this->assertSame([self::D2, self::R1], array_column(FixFilter::ofDevices($fixes, [512, 503]), 'id'), 'a fix of one of them, or of a finding about one');
    }

    public function testTheFixesOfDevicesThePlannerLeavesOutGo(): void
    {
        $fixes = self::answer()['fixes'];
        $names = [501 => 'lab-sw1', 502 => 'lab-sw2', 512 => 'lab-sw6'];
        // lab-sw6 down, left out by the planner: its description fix goes, lab-sw2's stays
        $kept = FixChange::forTargets('port_descriptions', [$fixes[0], $fixes[1]], [502], $names);
        $this->assertSame([self::D1], array_column($kept['fixes'], 'id'));
        $this->assertSame(['Port descriptions of lab-sw6 (lab-sw6 left out)'], $kept['dropped']);
        $this->assertSame(['fixes' => [$fixes[0]], 'dropped' => []], FixChange::forTargets('port_descriptions', [$fixes[0]], [502, 512], $names), 'nothing left out');

        // a root fix: its secondary left out, the intended root is set alone; its intended root left out, the fix goes (the secondary alone would
        // become the root)
        $secondaryOut = FixChange::forTargets('stp_root_priority', [$fixes[2]], [501], $names);
        $this->assertSame([[501], null], [$secondaryOut['fixes'][0]['devices'], $secondaryOut['fixes'][0]['secondary']]);
        $this->assertStringStartsWith('the secondary lab-sw2 of Root bridge of VLAN 1 / CST, VLAN 10', $secondaryOut['dropped'][0]);
        $this->assertSame([[501], []], FixChange::rootBatches($secondaryOut['fixes']));
        $primaryOut = FixChange::forTargets('stp_root_priority', [$fixes[2]], [502], $names);
        $this->assertSame([], $primaryOut['fixes']);
        $this->assertStringEndsWith('(its intended root lab-sw1 is left out)', $primaryOut['dropped'][0]);

        // the lines then built are those of the targets only
        $lines = FixAnswers::lines('stp_root_priority', [['kind' => 'stp_root_priority', 'primary' => ['device_id' => 501, 'priority' => 4096, 'mode' => 'pvst', 'instances' => [1, 10]],
            'secondary' => null]], [501 => 'lab-sw1.netops.lab']);
        $this->assertSame(['lab-sw1.netops.lab pvst 1,10 4096'], $lines['lines']);
    }

    public function testTheDevicesOfTheFixes(): void
    {
        $answer = self::answer()['fixes'];
        $this->assertSame([502, 512], FixChange::devices('port_descriptions', [$answer[0], $answer[1]]));
        $this->assertSame([501, 502], FixChange::devices('stp_root_priority', [$answer[2]]), 'the intended root, then its secondary');

        // a switch that is the intended root of one part and the secondary of another is set as a root, in the first batch
        $other = ['id' => self::D2, 'kind' => 'stp_root_priority', 'primary' => ['device_id' => 502], 'secondary' => ['device_id' => 503]];
        $this->assertSame([[501, 502], [503]], FixChange::rootBatches([$answer[2], $other]));
        $this->assertSame([[501], []], FixChange::rootBatches([['primary' => ['device_id' => 501], 'secondary' => null]]), 'no secondary: one batch');
    }

    public function testARootFixPlansItsOwnBatchesWithTheirBlastRadius(): void
    {
        // the planner's own order: lab-sw2 (tier 1) before the core, lab-sw1
        $planner = RolloutPlanTest::planner([501, 502], 1);
        $this->assertSame([[502], [501]], array_column($planner['batches'], 'devices'));

        $reordered = FixChange::rootPlanner($planner, [501], [502], [501 => 'lab-sw1', 502 => 'lab-sw2']);

        $this->assertSame([['devices' => [501], 'tier' => TopologyPlan::CORE], ['devices' => [502], 'tier' => 1]], $reordered['planner']['batches']);
        $this->assertSame('The fix sets the intended root first, alone, then its secondary, so that the root of each VLAN moves once (to lab-sw1); the topology plugin\'s planner '
            . 'would have started with lab-sw2 (farthest from the core first). What each batch cuts off is computed as for any change.', $reordered['note']);
        $this->assertSame($planner['core_modes'], $reordered['planner']['core_modes'], 'the rest of the answer is kept');

        $made = RolloutPlan::make([501, 502], [501], 1, 1, 1, true, 0, $reordered['planner'], RolloutPlan::HAND, fn (array $batch) => RolloutPlanTest::blast($batch));
        $this->assertNull($made['error']);
        $this->assertSame([[501], [502]], $made['plan']->batches, 'the intended root alone, then its secondary');
        $this->assertSame([[], [512]], array_column($made['plan']->topology->batches, 'lost'), 'what each batch cuts off, as for any change');

        // the planner's own order already starting with the intended root: no note
        $this->assertNull(FixChange::rootPlanner(['batches' => [['devices' => [501], 'tier' => 'core'], ['devices' => [502], 'tier' => 1]]], [501], [502], [])['note']);

        // a device the planner cannot plan is left to RolloutPlan (out or by hand, as for any change)
        $skipped = FixChange::rootPlanner(['batches' => [['devices' => [501], 'tier' => 'core']], 'skipped' => [['device_id' => 502, 'reason' => 'down']]], [501], [502], []);
        $this->assertSame([['devices' => [501], 'tier' => 'core']], $skipped['planner']['batches']);
    }
}
