<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Stp\SyslogTurns;
use PHPUnit\Framework\TestCase;

class SyslogTurnsTest extends TestCase
{
    private const NOISE = ['%SEC-6-IPACCESSLOGP', 'list 101 denied tcp 10.1.2.3(51234) -> 10.9.8.7(443), 1 packet'];

    private const EVENT = ['%PM-4-ERR_DISABLE', 'bpduguard error detected on Gi1/0/21, putting Gi1/0/21 in err-disable state'];

    private int $seq = 0;

    /**
     * Messages of a device, newest first: $events are the positions (0 = newest) of BPDU guard events among noise.
     */
    private function log(int $deviceId, int $count, array $events = []): array
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            [$program, $msg] = in_array($i, $events, true) ? self::EVENT : self::NOISE;
            $messages[] = (object) ['seq' => 10000000 - ++$this->seq, 'device_id' => $deviceId, 'timestamp' => date('Y-m-d H:i:s', 1792500000 - $i * 10),
                'program' => $program, 'msg' => $msg];
        }

        return $messages;
    }

    /**
     * Reads pages like LoopEvidenceRepository::syslogPages() from device_id => messages (newest first).
     */
    private static function reader(array $logs): callable
    {
        return function (array $turns) use ($logs): array {
            $pages = [];
            foreach ($turns as $deviceId => $turn) {
                $messages = $logs[$deviceId] ?? [];
                if ($turn['after']) {
                    $messages = array_filter($messages, fn ($m) => [$m->timestamp, $m->seq] < $turn['after']);
                }
                $pages[$deviceId] = array_slice(array_values($messages), 0, $turn['limit']);
            }

            return $pages;
        };
    }

    private static function classify(): callable
    {
        return fn (string $text) => str_contains($text, 'bpduguard') ? 'bpdu_guard' : null;
    }

    private static function perDevice(array $result): array
    {
        $found = [];
        foreach ($result['rows'] as $row) {
            $found[$row['device_id']] = ($found[$row['device_id']] ?? 0) + 1;
        }
        ksort($found);

        return $found;
    }

    public function testABusyDeviceDoesNotCrowdOutTheOthers(): void
    {
        // a router logging 30,000 ACL hits with one event deep down, and 40 switches with 3 events among 300 messages
        $logs = [1 => $this->log(1, 30000, [25000])];
        for ($d = 2; $d <= 41; $d++) {
            $logs[$d] = $this->log($d, 300, [0, 150, 299]);
        }
        $result = (new SyslogTurns(20000, 500))->read(range(1, 41), self::reader($logs), self::classify());

        $this->assertSame(array_fill(2, 40, 3), self::perDevice($result), 'every switch with all its events');
        $this->assertSame(20000, $result['scanned']);
        $this->assertSame(1, $result['limited'], 'the router was not searched to the start of the window');
        $this->assertSame(0, $result['unsearched']);
        $this->assertFalse($result['truncated']);
        $this->assertLessThan(20, $result['reads']);
    }

    public function testManyDevicesShareTheBudgetAndAreCounted(): void
    {
        // 300 switches, the newest message of each an event: 10 messages each fit the budget
        $logs = [];
        for ($d = 1; $d <= 300; $d++) {
            $logs[$d] = $this->log($d, 50, [0]);
        }
        $all = (new SyslogTurns(3000, 5000))->read(range(1, 300), self::reader($logs), self::classify());
        $this->assertCount(300, self::perDevice($all));
        $this->assertSame(['scanned' => 3000, 'reads' => 2, 'limited' => 300, 'unsearched' => 0],
            array_intersect_key($all, array_flip(['scanned', 'reads', 'limited', 'unsearched'])));

        // a smaller budget: the devices first in line (the most recently active) are searched, the others counted
        $part = (new SyslogTurns(1000, 5000))->read(range(1, 300), self::reader($logs), self::classify());
        $this->assertSame(range(1, 100), array_keys(self::perDevice($part)));
        $this->assertSame(200, $part['unsearched']);
        $this->assertSame(100, $part['limited']);

        // and a bound on the reads
        $bounded = (new SyslogTurns(3000, 5000, 1))->read(range(1, 300), self::reader($logs), self::classify());
        $this->assertSame(1, $bounded['reads']);
        $this->assertSame(200, count(self::perDevice($bounded)), 'one read covers 200 devices');
        $this->assertSame(100, $bounded['unsearched']);
    }

    public function testALoopingDeviceGetsAShareOfTheMatches(): void
    {
        // every message of device 1 is an event; devices 2 to 5 have a few
        $logs = [1 => $this->log(1, 2000, range(0, 1999))];
        for ($d = 2; $d <= 5; $d++) {
            $logs[$d] = $this->log($d, 40, [0, 1, 2, 3, 4]);
        }
        $result = (new SyslogTurns(100000, 500))->read(range(1, 5), self::reader($logs), self::classify());

        $found = self::perDevice($result);
        $this->assertSame([2 => 5, 3 => 5, 4 => 5, 5 => 5], array_slice($found, 1, null, true));
        $this->assertGreaterThanOrEqual(480, $found[1]);
        $this->assertTrue($result['truncated']);
        $this->assertSame(1, $result['limited']);
        $this->assertLessThan(800, $result['scanned'], 'a turn cut short by the matches reads no more next time');
    }

    public function testTheLimitThatStoppedTheReadIsNamed(): void
    {
        // thousands of senders with one event each: the match cap ends the read, not the message budget
        $logs = [];
        for ($d = 1; $d <= 4500; $d++) {
            $logs[$d] = $this->log($d, 1, [0]);
        }
        $matches = (new SyslogTurns(100000, 500))->read(range(1, 4500), self::reader($logs), self::classify());
        $this->assertSame('matches', $matches['stopped']);
        $this->assertTrue($matches['truncated']);
        $this->assertSame(4500 - 600, $matches['unsearched'], 'three reads of 200 devices');

        $few = [];
        for ($d = 1; $d <= 300; $d++) {
            $few[$d] = $this->log($d, 50, [0]);
        }
        $scan = (new SyslogTurns(1000, 5000))->read(range(1, 300), self::reader($few), self::classify());
        $this->assertSame('scan', $scan['stopped']);
        $this->assertFalse($scan['truncated']);
        $this->assertSame('reads', (new SyslogTurns(100000, 5000, 1))->read(range(1, 300), self::reader($few), self::classify())['stopped']);
        $this->assertNull((new SyslogTurns)->read(range(1, 300), self::reader($few), self::classify())['stopped'], 'everything read');
    }

    public function testNoSendersNoReads(): void
    {
        $reads = 0;
        $result = (new SyslogTurns)->read([], function () use (&$reads) {
            $reads++;

            return [];
        }, self::classify());
        $this->assertSame(0, $reads);
        $this->assertSame(['rows' => [], 'scanned' => 0, 'reads' => 0, 'truncated' => false, 'limited' => 0, 'unsearched' => 0, 'stopped' => null], $result);
    }
}
