<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Runs\RecapParser;
use Agoodley\LibrenmsAnsible\Runs\RunResults;
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
            'evil2                      : ok=1    changed=0    unreachable=0    failed=0',
        ];
        $recap = RecapParser::parse($lines);

        $this->assertSame(['sw1', 'sw2'], array_keys($recap));
        $this->assertSame(['ok' => 3, 'changed' => 1, 'unreachable' => 0, 'failed' => 0, 'skipped' => 2, 'rescued' => 0, 'ignored' => 1], $recap['sw1']);
        $this->assertSame('changed', RecapParser::result($recap['sw1']));
        $this->assertSame('unreachable', RecapParser::result($recap['sw2']));
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
        ];

        $this->assertSame(['sw1' => true, 'sw2' => true], RecapParser::connectionFailures($lines));
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
