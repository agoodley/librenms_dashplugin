<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Services\TopologyVerification;
use PHPUnit\Framework\TestCase;

/**
 * A scope or a state is taken in a process of its own (`artisan ansible:state`, TopologyVerification::run()): its answer
 * is read only up to what the plugin keeps of a state, never whole whatever its size.
 */
class StateProcessTest extends TestCase
{
    public function testTheAnswerIsReadUpToItsCap(): void
    {
        $ok = TopologyVerification::run(['what' => 'state'], null, [PHP_BINARY, '-r', 'echo json_encode(["version" => "1.0", "taken_at" => 5]);'], 100000);
        $this->assertSame(['version' => '1.0', 'taken_at' => 5], $ok);

        $started = microtime(true);
        $big = TopologyVerification::run(['what' => 'state'], null, [PHP_BINARY, '-r', 'while (true) { echo str_repeat("x", 65536); }'], 300000);
        $this->assertSame(['error' => 'its answer is more than 0.3 MB (the plugin keeps a state of at most 32 MB)'], $big, 'an endless answer: stopped at the cap');
        $this->assertLessThan(30, microtime(true) - $started);
        $this->assertGreaterThan(32 * 1024 * 1024, TopologyVerification::OUTPUT_MAX);
    }
}
