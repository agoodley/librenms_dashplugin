<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Services\RunFollower;
use PHPUnit\Framework\TestCase;

class RunFollowerTest extends TestCase
{
    public function testTheLockOfARefreshOutlastsItsSlowestRunnerCalls(): void
    {
        // a refresh may make RunFollower::CALLS runner calls (a search reads up to five pages), each up to the connection's timeout
        $this->assertSame(7, RunFollower::CALLS);
        $this->assertSame(7 * 15 + 60, RunFollower::lockSecondsFor(15));
        $this->assertSame(7 * 120 + 60, RunFollower::lockSecondsFor(120));
        $this->assertSame(67, RunFollower::lockSecondsFor(1));
    }

    public function testTheLockOfAChangeOutlastsALaunchsRunnerCallsBetweenTwoHeartbeats(): void
    {
        // a launch makes 10 runner calls between two heartbeats (the token's checks, the template, inventory, variable groups,
        // repository, then the POST): more than a refresh of a run
        $this->assertSame(12, \Agoodley\LibrenmsAnsible\Services\ChangeFollower::LAUNCH_CALLS);
        $this->assertSame(12 * 15 + 60, \Agoodley\LibrenmsAnsible\Services\ChangeFollower::lockSecondsFor(15));
        $this->assertGreaterThan(RunFollower::lockSecondsFor(15), \Agoodley\LibrenmsAnsible\Services\ChangeFollower::lockSecondsFor(15));
    }
}
