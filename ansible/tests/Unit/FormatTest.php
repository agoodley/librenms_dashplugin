<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\ChangeText;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Agoodley\LibrenmsAnsible\Support\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function testThePagesShowTimesInTheUsersTimeZone(): void
    {
        try {
            // LibreNMS' session preference, which its pages set from the browser
            Clock::useUserZone(fn () => 'Europe/Berlin');
            $this->assertSame('2026-10-22 12:00:00', Clock::local('2026-10-22 10:00:00'));
            $this->assertSame('2026-10-22 12:00 CEST', Clock::local('2026-10-22 10:00:00', 'Y-m-d H:i T'), 'a deadline carries its zone');
            $this->assertSame('2026-10-22 12:00:00', Clock::show(gmmktime(10, 0, 0, 10, 22, 2026)));
            $this->assertSame('Europe/Berlin', Clock::zone()->getName());
            Clock::useUserZone(fn () => 'Mars/Olympus');
            $this->assertSame(date_default_timezone_get(), Clock::zone()->getName(), 'not a time zone: PHP\'s');
            Clock::useUserZone(fn () => throw new \RuntimeException('no session'));
            $this->assertSame(date_default_timezone_get(), Clock::zone()->getName(), 'no session (the sync command): PHP\'s');
            Clock::useUserZone(fn () => null);
            $this->assertSame('2026-10-22 10:00:00', Clock::local('2026-10-22 10:00:00'), 'no preference: PHP\'s (UTC here)');
        } finally {
            Clock::useUserZone(null);
        }
    }

    public function testTimesAreKeptInUtcAndShownInLibreNmsTimeZone(): void
    {
        $zone = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Berlin');
            $time = gmmktime(10, 0, 0, 10, 22, 2026);
            $this->assertSame('2026-10-22 10:00:00', Clock::store($time));
            $this->assertSame($time, Clock::unix('2026-10-22 10:00:00'));
            $this->assertSame('2026-10-22 12:00:00', Clock::local('2026-10-22 10:00:00'), 'Berlin is two hours ahead in October');
            $this->assertSame('2026-10-22 12:00 CEST', Clock::local('2026-10-22 10:00:00', 'Y-m-d H:i T'));
            date_default_timezone_set('America/New_York');
            $this->assertSame($time, Clock::unix(Clock::store($time)));
            $this->assertSame('2026-10-22 06:00:00', Clock::local('2026-10-22 10:00:00'));
            $this->assertNull(Clock::unix(null));
            $this->assertNull(Clock::local(''));
            $this->assertSame(3600, Format::runDuration(['started_at' => '2026-10-22 10:00:00', 'finished_at' => '2026-10-22 11:00:00']));
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function testStoredTextsCarryUtcAndPagesShowTheReadersZone(): void
    {
        $zone = date_default_timezone_get();
        try {
            // the sync command in a PHP time zone of its own, no user: a stored text says UTC
            date_default_timezone_set('America/New_York');
            Clock::useUserZone(fn () => throw new \RuntimeException('no session'));
            $time = gmmktime(10, 0, 0, 10, 22, 2026);
            $this->assertSame('2026-10-22 10:00 UTC', Clock::text($time));
            $this->assertSame('2026-10-22 10:00:30 UTC', Clock::text($time + 30, 'Y-m-d H:i:s'));
            $waiting = ChangeText::waiting(['id' => 3, 'reference' => ''], 2, 'secrets', $time);
            $this->assertStringContainsString('until 2026-10-22 10:00 UTC, then the change ends', $waiting, 'not EDT');
            $this->assertStringEndsWith('opened until 2026-10-22 10:00 UTC', ChangeText::windowOpened(['id' => 3, 'reference' => ''], 1, 7, $time));

            // a page shows those times in its reader's zone, with its zone; other text stays as it is
            Clock::useUserZone(fn () => 'Europe/Berlin');
            $this->assertSame('Batch 2 waits until 2026-10-22 12:00 CEST, then 2026-10-22 12:00:30 CEST; not 2026-10-22 10:00 EDT, nor 10:00 UTC',
                Clock::localize('Batch 2 waits until 2026-10-22 10:00 UTC, then 2026-10-22 10:00:30 UTC; not 2026-10-22 10:00 EDT, nor 10:00 UTC'));
            $this->assertSame('', Clock::localize(null));
        } finally {
            Clock::useUserZone(null);
            date_default_timezone_set($zone);
        }
    }

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

    public function testMemorySizesAsPhpWritesThem(): void
    {
        $this->assertSame([128 * 1048576, 2 * 1073741824, 512 * 1024, 1000, -1, -1], array_map([\Agoodley\LibrenmsAnsible\Support\MemoryLimit::class, 'bytes'],
            ['128M', '2G', '512k', '1000', '-1', '']));
    }
}
