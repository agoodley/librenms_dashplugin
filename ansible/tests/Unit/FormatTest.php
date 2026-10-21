<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Support\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function testDuration(): void
    {
        $this->assertSame('-', Format::duration(null));
        $this->assertSame('-', Format::duration(-1));
        $this->assertSame('45 s', Format::duration(45));
        $this->assertSame('3 min 05 s', Format::duration(185));
        $this->assertSame('2 h 10 min', Format::duration(7800));
        $this->assertSame('3 d 4 h', Format::duration(3 * 86400 + 4 * 3600 + 59));
    }

    public function testRunDuration(): void
    {
        $now = strtotime('2026-10-21 12:00:00');
        $this->assertSame(30, Format::runDuration(['created_at' => '2026-10-21 11:59:00', 'started_at' => '2026-10-21 11:59:10',
            'finished_at' => '2026-10-21 11:59:40'], $now));
        $this->assertSame(60, Format::runDuration(['created_at' => '2026-10-21 11:59:00', 'started_at' => null, 'finished_at' => null], $now),
            'a run that has not started counts from its creation, up to now');
        $this->assertNull(Format::runDuration([], $now));
    }

    public function testCount(): void
    {
        $this->assertSame('1 device', Format::count(1, 'device'));
        $this->assertSame('3 devices', Format::count(3, 'device'));
        $this->assertSame('0 hosts', Format::count(0, 'host'));
        $this->assertSame('2 mice', Format::count(2, 'mouse', 'mice'));
    }
}
