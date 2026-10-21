<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Support\MemoryLimit;
use PHPUnit\Framework\TestCase;

class MemoryLimitTest extends TestCase
{
    private string $limit;

    protected function setUp(): void
    {
        $this->limit = (string) ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->limit);
    }

    public function testSizes(): void
    {
        $this->assertSame(134217728, MemoryLimit::bytes('128M'));
        $this->assertSame(2147483648, MemoryLimit::bytes('2g'));
        $this->assertSame(524288, MemoryLimit::bytes('512K'));
        $this->assertSame(1048576, MemoryLimit::bytes('1048576'));
        $this->assertSame(-1, MemoryLimit::bytes('-1'));
        $this->assertSame(-1, MemoryLimit::bytes(''));
    }

    public function testRaisesButNeverLowersTheLimit(): void
    {
        ini_set('memory_limit', '128M');
        MemoryLimit::raise(256);
        $this->assertSame(256 * 1048576, MemoryLimit::bytes((string) ini_get('memory_limit')));

        MemoryLimit::raise(192);
        $this->assertSame(256 * 1048576, MemoryLimit::bytes((string) ini_get('memory_limit')));

        ini_set('memory_limit', '-1');
        MemoryLimit::raise(256);
        $this->assertSame('-1', ini_get('memory_limit'));
    }
}
