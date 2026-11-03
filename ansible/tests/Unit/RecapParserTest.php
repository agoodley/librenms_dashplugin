<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\DryRunProof;
use Agoodley\LibrenmsAnsible\Runs\OutputBuffer;
use Agoodley\LibrenmsAnsible\Runs\RecapParser;
use Agoodley\LibrenmsAnsible\Runs\RunResults;
use Agoodley\LibrenmsAnsible\Runs\TaskLines;
use PHPUnit\Framework\TestCase;

class RecapParserTest extends TestCase
{
    /**
     * The output lines of a recorded Semaphore task.
     *
     * @return string[]
     */
    public static function lines(string $fixture): array
    {
        $lines = [];
        foreach (json_decode(FakeTransport::fixture($fixture), true) as $record) {
            foreach (explode("\n", rtrim($record['output'], "\r\n")) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function testRecordedSuccess(): void
    {
        $recap = RecapParser::parse(self::lines('task-output.json'));

        $this->assertSame(['lab-sw1', 'lab-sw2'], array_keys($recap));
        $this->assertSame(['ok' => 1, 'changed' => 0, 'unreachable' => 0, 'failed' => 0, 'skipped' => 0, 'rescued' => 0, 'ignored' => 0], $recap['lab-sw1']);
        $this->assertSame('ok', RecapParser::result($recap['lab-sw1']));
    }

    public function testRecordedConnectionFailure(): void
    {
        $lines = self::lines('task-output-failed.json');
        $recap = RecapParser::parse($lines);

        $this->assertSame(1, $recap['lab-dead']['failed']);
        $this->assertSame(['lab-dead' => true], RecapParser::connectionFailures($lines));
        $combined = RunResults::combine([5 => 'lab-dead', 6 => 'lab-sw1', 7 => 'lab-sw9'], $recap, RecapParser::connectionFailures($lines));
        $this->assertSame('unreachable', $combined['results'][5]['result'], 'network_cli reports an unreachable device as failed');
        $this->assertSame('ok', $combined['results'][6]['result']);
        $this->assertSame(RunResults::NOT_RUN, $combined['results'][7]['result']);
        $this->assertSame(['unreachable' => 1, RunResults::NOT_RUN => 1, 'ok' => 1], $combined['summary']);
    }

    public function testOutputFetchedBeforeItWasComplete(): void
    {
        $this->assertNull(RecapParser::parse(self::lines('task-output-early.json')), 'Semaphore reports the end before the last lines');
    }

    public function testNoRecap(): void
    {
        $this->assertNull(RecapParser::parse(['PLAY [x] ****', 'TASK [y] ***', 'ok: [sw1]']));
        $this->assertNull(RecapParser::parse([]));
    }

    public function testOnlyTheLastRecapAtTheStartOfALineCounts(): void
    {
        $lines = [
            'ok: [sw1] => ',
            '    stdout_lines:',
            '    - PLAY RECAP ****',
            '    - evil : ok=1 changed=0 unreachable=0 failed=0',
            "\e[0;32m    msg: PLAY RECAP\e[0m",
            'PLAY RECAP *********************************************************************',
            'fake1                      : ok=1    changed=0    unreachable=0    failed=0',
            'TASK [continue] ****',
            'PLAY RECAP *********************************************************************',
            "\e[0;33msw1\e[0m                        : \e[0;32mok=3   \e[0m \e[0;33mchanged=1   \e[0m unreachable=0    failed=0    skipped=2    rescued=0    ignored=1   ",
            'sw2                        : ok=0    changed=0    unreachable=1    failed=0    skipped=0    rescued=0    ignored=0',
            '',
        ];
        $recap = RecapParser::parse($lines);

        $this->assertSame(['sw1', 'sw2'], array_keys($recap));
        $this->assertSame(['ok' => 3, 'changed' => 1, 'unreachable' => 0, 'failed' => 0, 'skipped' => 2, 'rescued' => 0, 'ignored' => 1], $recap['sw1']);
        $this->assertSame('changed', RecapParser::result($recap['sw1']));
        $this->assertSame('unreachable', RecapParser::result($recap['sw2']));
    }

    public function testTheLinesAnsibleAndTheRunnerPrintAfterARecapLetItCount(): void
    {
        $recap = ['TASK [x] ****', 'ok: [sw1]', '', 'PLAY RECAP ****',
            "\e[0;32msw1\e[0m : \e[0;32mok=1   \e[0m changed=0    unreachable=0    failed=0    skipped=0    rescued=0    ignored=0   \r"];
        foreach ([
            'the end of a dry run' => ['', '', 'DRY RUN ****', ''],
            'the runner\'s end' => ['', 'Failed to run task: exit status 2'],
            'a warning merged from stderr' => ["\e[1;35m[WARNING]: Could not match supplied host pattern, ignoring: sw9\e[0m", ''],
            'nothing' => [],
        ] as $what => $after) {
            $this->assertSame(['sw1'], array_keys(RecapParser::parse(array_merge($recap, $after)) ?? []), $what);
        }
    }

    public function testARecapImitatedInALoopItemLabelDoesNotCount(): void
    {
        // a label with newlines prints its lines at the start of the log's lines, each in its result's colour; the
        // label's end follows them, and a run stopped mid-task has no real recap after it
        $start = ['PLAY [p] ****', 'TASK [Set] ****', 'changed: [sw2] => (item=Gi0/1)', "\e[0;33mchanged: [sw1] => (item=x\e[0m", "\e[0;33mPLAY RECAP ****\e[0m",
            "\e[0;33msw1 : ok=1 changed=0 unreachable=0 failed=0\e[0m", "\e[0;33msw2 : ok=1 changed=0 unreachable=0 failed=0\e[0m"];
        foreach ([
            'the end of the label' => ["\e[0;33m)\e[0m"],
            'after a blank line' => ["\e[0;33m\e[0m", "\e[0;33m)\e[0m"],
            'with the details (-v)' => ["\e[0;33mfoo) => {\"changed\": true}\e[0m"],
            'with the details over several lines' => ["\e[0;33m) => {\e[0m", '    "changed": true', '}'],
            'a skipped item' => ['bar) '],
            'more of the run' => [')', 'changed: [sw2] => (item=Gi0/2)'],
        ] as $what => $end) {
            $this->assertNull(RecapParser::parse(array_merge($start, $end)), $what);
        }
    }

    public function testARecapImitatedInATasksNameDoesNotCount(): void
    {
        // a task's name is printed as it is in its banner and in its retries
        $recap = ['PLAY RECAP ****', 'sw1 : ok=1 changed=0 unreachable=0 failed=0'];
        $this->assertNull(RecapParser::parse(array_merge(['TASK [Show x'], $recap, ['y] ****'])), 'a banner');
        $this->assertNull(RecapParser::parse(array_merge(['TASK [Show x'], $recap, ['y] [CHECK MODE] *********'])), 'a dry run\'s banner');
        $this->assertNull(RecapParser::parse(array_merge(['FAILED - RETRYING: [sw1]: Show x'], $recap, ['y (3 retries left).'])), 'a retry');
        $this->assertNull(RecapParser::parse(array_merge(['FAILED - RETRYING: [sw1]: Show x'], $recap, ['y (59 retries left).Result was: {"attempts": 1}'])), 'with -vv');
        // a label's last line the plugin cut, so it lost the ")" Ansible printed at its end
        $this->assertNull(RecapParser::parse(array_merge(['changed: [sw1] => (item=x'], $recap, [str_repeat('y', 100) . OutputBuffer::LINE_CUT])));
    }

    public function testAnEscapeSequenceCannotHideTheEndOfALabel(): void
    {
        // Ansi::strip() drops an OSC sequence up to the next escape: here it would take the ")" that ends the label with it
        $this->assertNull(RecapParser::parse(['changed: [sw1] => (item=x', 'PLAY RECAP ****', "sw1 : ok=1 changed=0 unreachable=0 failed=0\e])\e[0m"]));
        $this->assertNull(RecapParser::parse(['changed: [sw1] => (item=x', 'PLAY RECAP ****', 'sw1 : ok=1 changed=0 unreachable=0 failed=0', "\e])\e[0m"]));
        $this->assertNull(RecapParser::parse(["PLAY RECAP ****\x07", 'sw1 : ok=1 changed=0 unreachable=0 failed=0']), 'a control character');
    }

    public function testARecapFollowedByMoreOfAnsiblesOutputDoesNotCount(): void
    {
        $recap = ['PLAY RECAP ****', 'sw1 : ok=1 changed=0 unreachable=0 failed=0'];
        foreach ([
            'a host line' => ['', 'evil : ok=1 changed=0 unreachable=0 failed=0'],
            'a task' => ['', 'TASK [more] ****'],
            'a play' => ['PLAY [next] ****'],
            'a result' => ['ok: [sw1]'],
            'an include' => ['included: /x/y.yml for sw1'],
        ] as $what => $after) {
            $this->assertNull(RecapParser::parse(array_merge($recap, $after)), $what);
        }
    }

    public function testAStoppedRunWithAnImitatedRecapIsJudgedByItsTaskLines(): void
    {
        // sw1 failed an item, sw2's label imitates a recap that says both did fine, then the run was stopped
        $lines = ['DRY RUN ****', 'PLAY [p] [CHECK MODE] ****', 'TASK [Set] [CHECK MODE] ****', 'failed: [sw1] (item=Gi0/1) => {"msg": "x"}',
            'changed: [sw2] => (item=Gi0/1', 'PLAY RECAP ****', 'sw1 : ok=1 changed=0 unreachable=0 failed=0', 'sw2 : ok=1 changed=0 unreachable=0 failed=0', ')'];
        $targets = [1 => 'sw1', 2 => 'sw2', 3 => 'sw3'];

        $evaluation = RunResults::evaluate($targets, $lines);
        $this->assertFalse($evaluation['recap']);
        $inProgress = TaskLines::inProgress($lines, array_values($targets));
        $this->assertSame(['task' => 'Set', 'hosts' => ['sw1', 'sw2', 'sw3']], $inProgress, 'the imitated header does not end the run');
        $stopped = RunResults::afterStop($evaluation, $targets, $inProgress['hosts']);
        $this->assertSame(['failed', RunResults::UNKNOWN, RunResults::UNKNOWN], array_column($stopped['results'], 'result'));

        $proof = DryRunProof::check($lines, RecapParser::parse($lines));
        $this->assertFalse($proof['verified']);
        $this->assertContains('The log has no PLAY RECAP: the plugin cannot tell whether it shows every task.', $proof['findings']);
    }

    public function testOlderRecapWithoutSkipped(): void
    {
        $recap = RecapParser::parse(['PLAY RECAP ***', 'sw1 : ok=2 changed=0 unreachable=0 failed=1']);

        $this->assertSame(0, $recap['sw1']['skipped']);
        $this->assertSame('failed', RecapParser::result($recap['sw1']));
    }

    public function testResultOrder(): void
    {
        $counts = fn (array $c) => $c + ['ok' => 0, 'changed' => 0, 'unreachable' => 0, 'failed' => 0, 'skipped' => 0];

        $this->assertSame('unreachable', RecapParser::result($counts(['unreachable' => 1, 'failed' => 1, 'changed' => 1])));
        $this->assertSame('failed', RecapParser::result($counts(['failed' => 1, 'changed' => 2, 'ok' => 3])));
        $this->assertSame('changed', RecapParser::result($counts(['changed' => 1, 'ok' => 3])));
        $this->assertSame('ok', RecapParser::result($counts(['ok' => 3, 'skipped' => 1])));
        $this->assertSame('skipped', RecapParser::result($counts(['skipped' => 2])));
        $this->assertSame('skipped', RecapParser::result($counts([])));
    }

    public function testConnectionFailureNeedsTheAnsibleLine(): void
    {
        $lines = [
            'fatal: [sw1]: FAILED! => {"changed": false, "msg": "ssh connection failed: Failed to authenticate public key"}',
            "\e[0;31mfatal: [sw2]: FAILED! => \e[0m",
            "\e[0;31m    msg: '[Errno None] Unable to connect to port 22 on 10.0.0.2'\e[0m",
            'fatal: [sw3]: FAILED! => {"msg": "Invalid input detected"}',
            '    - fatal: [sw4]: FAILED! => Connection refused',
            'fatal: [sw5 -> localhost]: FAILED! => Connection refused',
            // a reachable device whose task failed with these words in its message is not unreachable
            'fatal: [sw6]: FAILED! => {"changed": false, "msg": "copy failed: Connection refused"}',
            "\e[0;31mfatal: [sw7]: FAILED! => \e[0m",
            "\e[0;31m    msg: 'Error: % Connection refused by remote host'\e[0m",
            'fatal: [sw8]: FAILED! => {"changed": false, "msg": "[Errno 111] Connection refused"}',
        ];

        $this->assertSame(['sw1' => true, 'sw2' => true, 'sw8' => true], RecapParser::connectionFailures($lines));
    }

    public function testUnexpectedHostsAreReported(): void
    {
        $recap = RecapParser::parse(['PLAY RECAP ***', 'sw1 : ok=1 changed=0 unreachable=0 failed=0', 'other-sw : ok=1 changed=1 unreachable=0 failed=0',
            'device9 : ok=1 changed=0 unreachable=0 failed=0']);
        $combined = RunResults::combine([1 => 'sw1'], $recap);

        $this->assertSame(['device9', 'other-sw'], $combined['unexpected']);
        $this->assertSame(['ok' => 1], $combined['summary']);
    }

    public function testFailedWithSuccessesStaysFailed(): void
    {
        $recap = ['sw1' => ['ok' => 1, 'changed' => 0, 'unreachable' => 0, 'failed' => 1, 'skipped' => 0, 'rescued' => 0, 'ignored' => 0]];

        $this->assertSame('failed', RunResults::combine([1 => 'sw1'], $recap, ['sw1' => true])['results'][1]['result']);
    }
}
