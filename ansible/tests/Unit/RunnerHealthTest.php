<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Runner\RunnerHealth;
use PHPUnit\Framework\TestCase;

class RunnerHealthTest extends TestCase
{
    public function testOnlyATaskRunnerThatIsNoAdminMayLaunch(): void
    {
        $this->assertNull((new RunnerHealth('v2', 'p', 'task_runner', true))->launchBlock());

        foreach (['owner' => 'Owner', 'manager' => 'Manager', 'Manager' => 'Manager'] as $role => $name) {
            $health = new RunnerHealth('v2', 'p', $role, true);
            $this->assertTrue($health->tooPowerful(), $role);
            $this->assertStringContainsString("too powerful (its user has the $name role in the project)", (string) $health->launchBlock());
            $this->assertStringContainsString('Task Runner role', (string) $health->launchBlock());
        }

        $admin = new RunnerHealth('v2', 'p', 'task_runner', true, true);
        $this->assertTrue($admin->tooPowerful());
        $this->assertSame('its user is a Semaphore UI admin', $admin->powerReason());
    }

    public function testATokenThatMayNotRunTasksIsBlockedWithTheFix(): void
    {
        $guest = new RunnerHealth('v2', 'p', 'guest', false);

        $this->assertFalse($guest->tooPowerful());
        $this->assertSame('Launches are blocked: the token\'s user has the Guest role in the project, which may not run tasks. Give the user the Task Runner role.',
            $guest->launchBlock());
        $this->assertStringContainsString('has no role in the project', (string) (new RunnerHealth('v2', 'p', null, false))->launchBlock());
    }
}
