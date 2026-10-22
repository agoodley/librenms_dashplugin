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
}
