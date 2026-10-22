<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Inventory\InventoryBuilder;
use Agoodley\LibrenmsAnsible\Inventory\PlatformMap;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Runs\RunText;
use Agoodley\LibrenmsAnsible\Runs\TargetSelection;
use PHPUnit\Framework\TestCase;

class RunsTest extends TestCase
{
    private function selection(array $busy = [], ?array $runnerVisible = null): TargetSelection
    {
        $devices = InventoryFixture::devices();
        $inventory = (new InventoryBuilder(PlatformMap::parse('')))->build($devices, InventoryFixture::memberships(), InventoryFixture::groups(), InventoryFixture::locations());

        return new TargetSelection($inventory, array_fill_keys(array_keys($devices), true), $busy, $runnerVisible, 'ansible-inventory');
    }

    public function testTargetsAndRefusals(): void
    {
        $result = $this->selection([2 => ['run' => 41, 'held_until' => null]])->select([1, '3', 3, 4, 2, 7, 8, 11, 999]);

        $this->assertSame([1 => 'core1.example.net', 3 => 'acc-sw1', 4 => 'device4'], $result['targets']);
        $this->assertSame([2, 7, 8, 11, 999], array_keys($result['refused']));
        $this->assertSame('busy', $result['refused'][2]['reason']);
        $this->assertSame('busy in run #41, which has not finished', $result['refused'][2]['text']);
        $this->assertSame('disabled', $result['refused'][7]['reason']);
        $this->assertSame('no_mapping', $result['refused'][8]['reason']);
        $this->assertSame('no_address', $result['refused'][11]['reason']);
        $this->assertSame('not_found', $result['refused'][999]['reason']);
    }

    public function testTheRunnerMustSeeTheTarget(): void
    {
        $selection = $this->selection([], [1 => true]);

        $this->assertNull($selection->refusal(1));
        $this->assertSame('not_visible', $selection->refusal(3)['reason']);
        $this->assertStringContainsString("inventory user ansible-inventory may not see it", $selection->refusal(3)['text']);
        $this->assertSame('disabled', $selection->refusal(7)['reason'], 'its own reason first');
    }

    public function testARunDoesNotBlockItself(): void
    {
        $this->assertNull($this->selection([1 => ['run' => 5, 'held_until' => null]])->refusal(1, 5));
        $this->assertSame('busy', $this->selection([1 => ['run' => 5, 'held_until' => null]])->refusal(1, 6)['reason']);
    }

    public function testADeviceHeldByAStoppedRunIsRefusedWithWhyAndUntilWhen(): void
    {
        $refusal = $this->selection([1 => ['run' => 7, 'held_until' => '2026-10-22 10:35:00']])->refusal(1);

        $this->assertSame('busy', $refusal['reason']);
        $this->assertSame('held by run #7 until 2026-10-22 10:35 UTC: it was stopped, and the step in progress may still run on the runner '
            . '(an admin can release the devices on the run page)', $refusal['text']);
    }

    public function testStatuses(): void
    {
        $this->assertSame(RunStatus::QUEUED, RunStatus::fromSemaphore('waiting'));
        $this->assertSame(RunStatus::QUEUED, RunStatus::fromSemaphore('starting'));
        $this->assertSame(RunStatus::QUEUED, RunStatus::fromSemaphore('waiting_confirmation'));
        $this->assertSame(RunStatus::RUNNING, RunStatus::fromSemaphore('running'));
        $this->assertSame(RunStatus::STOPPING, RunStatus::fromSemaphore('stopping'));
        $this->assertSame(RunStatus::SUCCESS, RunStatus::fromSemaphore('success'));
        $this->assertSame(RunStatus::FAILED, RunStatus::fromSemaphore('error'));
        $this->assertSame(RunStatus::FAILED, RunStatus::fromSemaphore('rejected'));
        $this->assertSame(RunStatus::STOPPED, RunStatus::fromSemaphore('Stopped'));
        $this->assertSame(RunStatus::RUNNING, RunStatus::fromSemaphore('new_status'), 'unknown statuses are followed on');
        $this->assertTrue(RunStatus::isFinal(RunStatus::LOST));
        $this->assertFalse(RunStatus::isFinal(RunStatus::PENDING));
        $this->assertSame([], array_intersect(RunStatus::FINAL, RunStatus::UNFINISHED));
        $this->assertSame('Not launched', RunStatus::label(RunStatus::NOT_LAUNCHED));
    }

    public function testTexts(): void
    {
        $this->assertSame('LibreNMS Ansible run #12 by admin', RunText::taskMessage(12, 'admin'));
        $this->assertSame('plugin/ansible/runs/12', RunText::reference(12));
        $this->assertLessThanOrEqual(64, strlen(RunText::reference(PHP_INT_MAX)));
        $this->assertSame("Ansible run #12 started: template 'Port descriptions' by admin", RunText::started(12, 'Port descriptions', 'admin'));
        $this->assertSame("Ansible run #12 finished: changed (ok=2 changed=1 unreachable=0 failed=0 skipped=0), template 'Port descriptions' by admin",
            RunText::finished(12, RunStatus::SUCCESS, 'changed', ['ok' => 2, 'changed' => 1], 'Port descriptions', 'admin'));
        $this->assertSame("Ansible run #12 was stopped: not run, template 'X' by admin (stopped by bob)",
            RunText::finished(12, RunStatus::STOPPED, 'not_run', null, 'X', 'admin', 'stopped by bob'));
        $this->assertStringContainsString('was not launched', RunText::notLaunched(3, "a\nb", 'admin', 'HTTP 403'));
        $this->assertStringNotContainsString("\n", RunText::notLaunched(3, "a\nb", 'admin', "x\ny"));
        $this->assertStringContainsString('ran on hosts that were not its targets: other-sw, sw9.', RunText::unexpectedHosts(3, ['other-sw', 'sw9'], 'X'));
    }
}
