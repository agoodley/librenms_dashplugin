<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Runs\OutputBuffer;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\SqliteSql;
use PHPUnit\Framework\TestCase;

/**
 * RunRepository's statements as written for LibreNMS' database, run on SQLite: the compare-and-set of the run
 * lifecycle, the log in pieces, and which devices are busy.
 */
class RunRepositoryTest extends TestCase
{
    private SqliteSql $sql;

    private RunRepository $runs;

    protected function setUp(): void
    {
        $this->sql = new SqliteSql();
        $this->runs = new RunRepository($this->sql);
    }

    /**
     * A run as the launcher records it.
     *
     * @param  array<int, string>  $targets  device_id => inventory name
     */
    private function record(array $targets = [501 => 'lab-sw1.netops.lab', 502 => 'lab-sw2.netops.lab'], array $run = []): int
    {
        $rows = array_map(fn ($name) => ['inventory_name' => $name, 'hostname' => $name, 'display' => strtok($name, '.')], $targets);

        return $this->runs->create($run + ['user_id' => 1, 'username' => 'alice', 'runner' => 'semaphore', 'runner_project' => 3, 'template_id' => '11',
            'template_name' => 'Show version', 'playbook' => 'show_version.yml', 'limit' => implode(',', $targets), 'variables' => [['name' => 'note', 'value' => 'x']],
            'request_token' => bin2hex(random_bytes(16)), 'launch_nonce' => 'abcdef123456', 'status' => 'pending'], $rows);
    }

    public function testARunIsRecordedWithItsTargets(): void
    {
        $id = $this->record([502 => 'lab-sw2.netops.lab', 501 => 'lab-sw1.netops.lab'], ['request_token' => str_repeat('a', 32)]);

        $run = $this->runs->find($id);
        $this->assertSame('pending', $run['status']);
        $this->assertSame(0, (int) $run['version']);
        $this->assertSame([['name' => 'note', 'value' => 'x']], $run['variables'], 'JSON columns come back decoded');
        $this->assertSame('lab-sw2.netops.lab,lab-sw1.netops.lab', $run['limit']);
        $this->assertSame([501, 502], array_keys($this->runs->targets($id)), 'by inventory name');
        $this->assertFalse($this->runs->targets($id)[501]['unexpected']);
        $this->assertSame($id, $this->runs->byRequestToken(str_repeat('a', 32)));
        $this->assertNull($this->runs->byRequestToken(str_repeat('b', 32)));
        $this->assertNull($this->runs->find($id + 1));
    }

    public function testAChangeIsACompareAndSetOnTheVersion(): void
    {
        $id = $this->record();

        $this->assertTrue($this->runs->updateAt($id, 0, ['status' => 'running', 'output_truncated' => false, 'summary' => ['ok' => 2]]));
        $run = $this->runs->find($id);
        $this->assertSame(1, (int) $run['version'], 'every change bumps the version');
        $this->assertSame('running', $run['status']);
        $this->assertSame(['ok' => 2], $run['summary']);

        $this->assertFalse($this->runs->updateAt($id, 0, ['status' => 'success']), 'a change made from an older read is refused');
        $this->assertSame('running', $this->runs->find($id)['status']);
        $this->assertSame(1, (int) $this->runs->find($id)['version']);

        // another request writes between this one's read and its write
        $this->sql->beforeChange = fn () => $this->runs->updateAt($id, 1, ['status' => 'stopping', 'stopped_by' => 'bob']);
        $this->assertFalse($this->runs->updateAt($id, 1, ['status' => 'running', 'output_lines' => 10]));
        $run = $this->runs->find($id);
        $this->assertSame(['stopping', 'bob', 0, 2], [$run['status'], $run['stopped_by'], (int) $run['output_lines'], (int) $run['version']]);
        $this->assertTrue($this->runs->updateAt($id, 2, ['status' => 'stopped']));
    }

    public function testOnlyTheRunsOwnColumnsCanBeChanged(): void
    {
        $id = $this->record();

        $this->expectException(\InvalidArgumentException::class);
        $this->runs->updateAt($id, 0, ['status`=1, `version' => 'x']);
    }

    public function testALongLogIsWrittenInPiecesThatEachFitAStatement(): void
    {
        $id = $this->record();
        $log = str_repeat("TASK [é] ****\nok: [lab-sw1.netops.lab] => " . str_repeat('x', 200) . "\n", 4000); // about 1 MB, multibyte
        $this->assertGreaterThan(OutputBuffer::CHUNK * 1.5, strlen($log));

        $largest = fn (array $statements) => max(array_map(fn ($s) => max(array_map(fn ($b) => is_string($b) ? strlen($b) : 0, $s['bindings'] ?: [0])), $statements));
        $this->sql->statements = [];
        $this->assertTrue($this->runs->updateAt($id, 0, ['output' => $log, 'output_tail' => $log, 'output_truncated' => true]));
        $statements = $this->sql->statements;
        $this->assertSame($log, $this->runs->find($id)['output']);
        $this->assertSame($log, $this->runs->find($id)['output_tail']);
        $this->assertLessThanOrEqual(OutputBuffer::CHUNK, $largest($statements), 'no statement holds more than a piece');
        $this->assertCount(2 * count(OutputBuffer::pieces($log)) - 1, $statements, 'the change with the first pieces, then one statement per piece');

        // new lines appended to a long log: in pieces too
        $this->sql->statements = [];
        $this->assertTrue($this->runs->updateAt($id, 1, ['output_lines' => 9000], ['output' => "\n" . $log, 'output_tail' => "\nthe end"]));
        $this->assertLessThanOrEqual(OutputBuffer::CHUNK, $largest($this->sql->statements));
        $this->assertSame($log . "\n" . $log, $this->runs->find($id)['output']);
        $this->assertSame($log . "\nthe end", $this->runs->find($id)['output_tail'], 'only the new lines are sent for the tail too');
        $this->assertSame(2, (int) $this->runs->find($id)['version'], 'the pieces belong to one change');
    }

    public function testAChangeInPiecesIsAllOrNothing(): void
    {
        $id = $this->record();
        $log = str_repeat("ok: [lab-sw1.netops.lab] " . str_repeat('x', 300) . "\n", 4000);
        $this->sql->onChange = function (string $query, int $before): void {
            if ($before === 2) {
                throw new \RuntimeException('the database went away between two pieces');
            }
        };

        try {
            $this->runs->updateAt($id, 0, ['status' => 'running', 'output' => $log]);
            $this->fail('no error');
        } catch (\RuntimeException) {
        }
        $run = $this->runs->find($id);
        $this->assertSame([0, 'pending', null], [(int) $run['version'], $run['status'], $run['output']], 'nothing of the change was kept');
        $this->sql->onChange = null;
        $this->assertTrue($this->runs->updateAt($id, 0, ['status' => 'running', 'output' => $log]));
        $this->assertSame($log, $this->runs->find($id)['output']);
    }

    public function testBusyDevicesAreTheTargetsOfUnfinishedRunsAndOfStoppedRunsThatHoldThem(): void
    {
        $now = strtotime('2026-10-22 10:00:00');
        $running = $this->record([501 => 'lab-sw1.netops.lab', 502 => 'lab-sw2.netops.lab'], ['status' => 'running']);
        $this->runs->addUnexpected($running, [503 => ['inventory_name' => 'lab-sw3.netops.lab', 'hostname' => 'lab-sw3', 'display' => 'lab-sw3', 'result' => 'ok', 'counts' => null]]);
        $held = $this->record([504 => 'lab-sw4.netops.lab'], ['status' => 'stopped', 'held_until' => '2026-10-22 10:20:00']);
        $this->record([505 => 'lab-sw5.netops.lab'], ['status' => 'stopped', 'held_until' => '2026-10-22 09:59:00']);
        $this->record([506 => 'lab-sw6.netops.lab'], ['status' => 'stopped']);
        $this->record([507 => 'lab-sw7.netops.lab'], ['status' => 'success']);
        $later = $this->record([502 => 'lab-sw2.netops.lab'], ['status' => 'stopping']);

        $this->assertSame([501 => ['run' => $running, 'held_until' => null], 502 => ['run' => $later, 'held_until' => null],
            504 => ['run' => $held, 'held_until' => '2026-10-22 10:20:00']], $this->runs->busy($now),
            'not a host the runner ran on that was no target (503), nor a hold that ended, nor ended runs; the newest run of a device');
        $this->assertArrayNotHasKey(504, $this->runs->busy(strtotime('2026-10-22 10:20:00')), 'the hold ends at held_until');
    }

    public function testAHoldMeansTheSameInEveryPhpTimeZone(): void
    {
        $zone = date_default_timezone_get();
        try {
            date_default_timezone_set('America/New_York');
            $now = time();
            $held = $this->record([504 => 'lab-sw4.netops.lab'], ['status' => 'stopped', 'held_until' => Clock::store($now + 600)]);
            date_default_timezone_set('Europe/Berlin');
            $this->assertSame($held, $this->runs->busy($now)[504]['run'] ?? null, 'written in one time zone, read in another');
            $this->assertArrayNotHasKey(504, $this->runs->busy($now + 601));
            $this->assertSame(Clock::store($now), $this->runs->find($held)['created_at'], 'kept in UTC');
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function testResultsAndHostsThatWereNotTargets(): void
    {
        $id = $this->record();

        $this->runs->setResults($id, [501 => ['result' => 'changed', 'counts' => ['ok' => 1, 'changed' => 1]], 502 => ['result' => 'unknown', 'counts' => null]]);
        $this->runs->addUnexpected($id, [502 => ['inventory_name' => 'lab-sw2.netops.lab', 'hostname' => 'x', 'display' => 'x', 'result' => 'ok', 'counts' => null],
            509 => ['inventory_name' => 'lab-sw9.netops.lab', 'hostname' => 'lab-sw9', 'display' => 'lab-sw9', 'result' => 'failed', 'counts' => ['failed' => 1]]]);
        $targets = $this->runs->targets($id);
        $this->assertSame(['changed', ['ok' => 1, 'changed' => 1]], [$targets[501]['result'], $targets[501]['counts']]);
        $this->assertSame(['unknown', null, false], [$targets[502]['result'], $targets[502]['counts'], $targets[502]['unexpected']], 'a target stays a target');
        $this->assertSame(['failed', ['failed' => 1], true], [$targets[509]['result'], $targets[509]['counts'], $targets[509]['unexpected']]);
        $this->assertSame([501, 502, 509], array_keys($targets), 'the targets first');
    }

    public function testRunsToFollowAndToWatch(): void
    {
        $now = strtotime('2026-10-22 10:00:00');
        $pending = $this->record([], ['status' => 'pending']);
        $stopping = $this->record([], ['status' => 'stopping']);
        $this->record([], ['status' => 'stopped', 'held_until' => '2026-10-22 10:20:00']);
        $lost = $this->record([], ['status' => 'lost', 'watch_until' => '2026-10-29 10:00:00']);
        $this->record([], ['status' => 'released', 'watch_until' => '2026-10-22 09:00:00']);
        $this->record([], ['status' => 'released']);

        $this->assertSame([$pending, $stopping], $this->runs->unfinished());
        $this->assertSame([$lost], $this->runs->watched($now));
    }

    public function testRecentStopsOfDevices(): void
    {
        $old = $this->record([501 => 'lab-sw1.netops.lab'], ['status' => 'stopped', 'runner_task_id' => '7', 'finished_at' => date('Y-m-d H:i:s', time() - 7200)]);
        $recent = $this->record([501 => 'lab-sw1.netops.lab', 502 => 'lab-sw2.netops.lab'], ['status' => 'stopped', 'runner_task_id' => '9',
            'finished_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->record([503 => 'lab-sw3.netops.lab'], ['status' => 'success', 'finished_at' => date('Y-m-d H:i:s', time() - 60)]);

        $stops = $this->runs->recentStops([501, 503]);
        $this->assertSame([501], array_keys($stops));
        $this->assertSame([$recent, '9'], [$stops[501]['run_id'], $stops[501]['runner_task_id']]);
        $this->assertNotSame($old, $stops[501]['run_id']);
        $this->assertSame([], $this->runs->recentStops([]));
    }
}
