<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\DryRunProof;
use Agoodley\LibrenmsAnsible\Runs\RecapParser;
use Agoodley\LibrenmsAnsible\Runs\RunOutputs;
use PHPUnit\Framework\TestCase;

/**
 * What each task of a run printed per device, grouped by identical output: on the logs of diagnostic runs of the test
 * server (the example playbook show_commands.yml on two fake switches, JSON results), of the lab's dry runs (the yaml
 * result format), and on logs where device output imitates Ansible's lines to put an output under another device.
 */
class RunOutputsTest extends TestCase
{
    private const TARGETS = [501 => 'h1', 502 => 'h2'];

    private static function parse(array $lines, array $targets = self::TARGETS): array
    {
        return RunOutputs::parse($lines, $targets, RecapParser::parse($lines));
    }

    /**
     * A log with the given lines in the task "Show it" and a recap of one ok task per host (or the counts given).
     *
     * @param  array<string, string>  $recap  host => its counters
     */
    private static function log(array $task, array $recap = ['h1' => 'ok=1 changed=0', 'h2' => 'ok=1 changed=0']): array
    {
        $lines = array_merge(['DRY RUN ****', 'PLAY [p] [CHECK MODE] ****', 'TASK [Show it] [CHECK MODE] ****'], $task, ['PLAY RECAP ****']);
        foreach ($recap as $host => $counts) {
            $lines[] = "$host : $counts unreachable=0 failed=0 skipped=0 rescued=0 ignored=0";
        }

        return $lines;
    }

    /** The task of a parse by its name. */
    private static function task(array $result, string $name): array
    {
        foreach ($result['tasks'] as $task) {
            if ($task['task'] === $name) {
                return $task;
            }
        }
        self::fail("no task $name");
    }

    public function testTheShowCommandsOfEachDeviceUnderItsCommand(): void
    {
        $lines = DryRunProofTest::log('diagnostic-show');
        $this->assertTrue(DryRunProof::check($lines, RecapParser::parse($lines))['verified'], 'a diagnostic run of the example playbook is proven to run in check mode');
        $result = self::parse($lines, [1 => 'inst-sw1', 2 => 'inst-sw2']);

        $this->assertSame(['Only show commands', 'Only Cisco IOS', 'Run the show commands', 'What the device answered'], array_column($result['tasks'], 'task'));
        $quiet = self::task($result, 'Run the show commands');
        $this->assertSame([['devices' => [1, 2], 'results' => [['result' => 'ok', 'item' => null, 'blocks' => []]]]], $quiet['groups'],
            'a task that printed nothing: both devices in one group, without output');
        $answered = self::task($result, 'What the device answered');
        $this->assertSame([[1], [2]], array_column($answered['groups'], 'devices'), 'their answers differ (their names): a group each, in the targets\' order');
        $lines = $answered['groups'][0]['results'][0]['blocks'][0]['lines'];
        $this->assertSame('output', $answered['groups'][0]['results'][0]['blocks'][0]['kind']);
        $this->assertSame(['show version', '------------'], array_slice($lines, 0, 2), 'each command over its answer');
        $this->assertContains('inst-sw1 uptime is 1 year, 18 weeks, 5 days, 1 hour, 11 minutes', $lines);
        $vlan = array_search('show vlan', $lines, true);
        $this->assertNotFalse($vlan);
        $this->assertSame(['', '---------'], [$lines[$vlan - 1], $lines[$vlan + 1]], 'a blank line between two commands\' answers');
        $this->assertContains('1    default                          active    Fa0/1, Fa0/2, Fa0/3, Fa0/4', $lines, 'columns kept as the device printed them');
        $this->assertSame([], $result['unattributed']);
        $this->assertFalse($result['cut']);
    }

    public function testAnUnprovenRunShowsWhatItPrinted(): void
    {
        $result = self::parse(DryRunProofTest::log('diagnostic-unproven'), [1 => 'inst-sw1']);

        $this->assertSame([[['devices' => [1], 'results' => [['result' => 'ok', 'item' => null,
            'blocks' => [['kind' => 'output', 'lines' => ['this task ran outside check mode'], 'cut' => false]]]]]]], array_column($result['tasks'], 'groups'));
    }

    public function testDetailsInTheYamlResultFormat(): void
    {
        $result = self::parse(DryRunProofTest::log('dry-run-verbose'), [501 => 'lab-sw1.netops.lab']);
        $this->assertSame([['kind' => 'commands', 'lines' => ['interface FastEthernet0/1', 'description verbose probe'], 'cut' => false]],
            $result['tasks'][0]['groups'][0]['results'][0]['blocks'], 'what a configuration module would send');
        $this->assertSame('changed', $result['tasks'][0]['groups'][0]['results'][0]['result']);

        $failed = self::parse(DryRunProofTest::log('dry-run-unreachable'), [501 => 'lab-sw1.netops.lab', 502 => 'lab-dead.netops.lab']);
        $this->assertSame([[501], [502]], array_column($failed['tasks'][0]['groups'], 'devices'));
        $this->assertSame(['result' => 'failed', 'item' => null, 'blocks' => [['kind' => 'output', 'lines' => ['[Errno None] Unable to connect to port 22 on 127.0.0.19'], 'cut' => false]]],
            $failed['tasks'][0]['groups'][1]['results'][0], 'a failure\'s message');
    }

    public function testTheSameOutputGroupsItsDevicesTheLargestGroupFirst(): void
    {
        $result = self::parse(self::log(['ok: [h3] => {', '    "msg": "12.2(58)SE2"', '}', 'ok: [h1] => {', '    "msg": "15.0(2)SE"', '}', 'ok: [h2] => {', '    "msg": "12.2(58)SE2"', '}'],
            ['h1' => 'ok=1 changed=0', 'h2' => 'ok=1 changed=0', 'h3' => 'ok=1 changed=0']), [501 => 'h1', 502 => 'h2', 503 => 'h3']);

        $groups = $result['tasks'][0]['groups'];
        $this->assertSame([[502, 503], [501]], array_column($groups, 'devices'));
        $this->assertSame(['12.2(58)SE2'], $groups[0]['results'][0]['blocks'][0]['lines']);
    }

    public function testWhatAResultShows(): void
    {
        $result = self::parse(self::log([
            // a command module run with -v: its stdout_lines, one list per command
            'ok: [h1] => {"changed": false, "stdout": ["a\nb", "c"], "stdout_lines": [["a", "b"], ["c"]]}',
            // a debug task's variable (debug: var=answers): what is left besides the bookkeeping
            'ok: [h2] => {',
            '    "answers": {',
            '        "device": {"model": "WS-C2960X"}',
            '    },',
            '    "changed": false',
            '}',
        ]));

        $this->assertSame(['a', 'b', '', 'c'], $result['tasks'][0]['groups'][0]['results'][0]['blocks'][0]['lines']);
        $this->assertSame(['{', '    "device": {', '        "model": "WS-C2960X"', '    }', '}'], $result['tasks'][0]['groups'][1]['results'][0]['blocks'][0]['lines'],
            'a map that is not text, as JSON');
    }

    public function testDeviceTextIsCleaned(): void
    {
        $result = self::parse(self::log(['ok: [h1] => {', '    "msg": "\u001b[31mred\u001b[0m\tand ‮evil\u0007"', '}']));

        $this->assertSame(['red    and evil'], $result['tasks'][0]['groups'][0]['results'][0]['blocks'][0]['lines'], 'no ANSI code, control or bidi character');
    }

    public function testLongOutputIsCut(): void
    {
        $long = str_repeat('x', RunOutputs::LINE_CHARS + 5);
        $many = array_map(fn (int $i) => "line $i", range(1, RunOutputs::BLOCK_LINES + 3));
        $result = self::parse(self::log(['ok: [h1] => ' . json_encode(['msg' => array_merge([$long], $many)])]));
        $block = $result['tasks'][0]['groups'][0]['results'][0]['blocks'][0];

        $this->assertTrue($block['cut']);
        $this->assertSame(str_repeat('x', RunOutputs::LINE_CHARS) . ' …', $block['lines'][0]);
        $this->assertCount(RunOutputs::BLOCK_LINES + 1, $block['lines']);
        $this->assertSame('… (4 more lines in the run\'s log)', end($block['lines']));
    }

    public function testALoopItemsDetailsAreNotAttributed(): void
    {
        // an item label is printed as it is: one with a newline could make the details after it another host's
        $result = self::parse(self::log(['ok: [h1] => (item=show version) => {', '    "msg": "15.0(2)SE"', '}', 'ok: [h2] => (item=show version) => {', '    "msg": "x"', '}']));

        $this->assertTrue($result['tasks'][0]['loop']);
        $this->assertSame([], $result['tasks'][0]['groups'][0]['results'][0]['blocks'], 'the result is attributed, its details are not');
        $this->assertSame(['h1', 'h2'], array_column($result['unattributed'], 'host'));
        $this->assertSame('show version', $result['unattributed'][0]['item']);
        $this->assertSame(['15.0(2)SE'], $result['unattributed'][0]['blocks'][0]['lines']);
        $this->assertStringContainsString('printed after a loop item', $result['unattributed'][0]['reason']);
    }

    public function testDeviceOutputThatImitatesAResultIsNotAttributed(): void
    {
        // a module's raw text before h1's changed line names h2, which the log names again: the task is ambiguous
        $twice = self::parse(self::log(['ok: [h2] => {', '    "msg": "real"', '}', '--- before', 'ok: [h2] => {', '}', 'changed: [h1]'],
            ['h1' => 'ok=1 changed=1', 'h2' => 'ok=1 changed=0']));
        $this->assertSame([], $twice['tasks']);
        $this->assertSame('the log of this task names h2 twice: device output may imitate Ansible\'s result lines', $twice['unattributed'][0]['reason']);

        // a host the log names in more tasks than the recap counts
        $more = self::parse(array_merge(['DRY RUN ****', 'PLAY [p] [CHECK MODE] ****', 'TASK [One] [CHECK MODE] ****', 'ok: [h1] => {', '    "msg": "real"', '}',
            'TASK [Two] [CHECK MODE] ****', 'ok: [h1] => {', '    "msg": "imitated"', '}', 'PLAY RECAP ****', 'h1 : ok=1 changed=0 unreachable=0 failed=0 skipped=0 rescued=0 ignored=0']));
        $this->assertSame([], $more['tasks']);
        $this->assertCount(2, $more['unattributed']);
        $this->assertStringContainsString('more tasks than the PLAY RECAP counts', $more['unattributed'][0]['reason']);
    }

    public function testHostsTheRunDidNotTargetOrTheRecapDoesNotName(): void
    {
        $other = self::parse(self::log(['ok: [h3] => {', '    "msg": "x"', '}'], ['h3' => 'ok=1 changed=0']));
        $this->assertSame([], $other['tasks']);
        $this->assertSame('it names a host that is not a target of the run', $other['unattributed'][0]['reason']);

        $stopped = self::parse(['DRY RUN ****', 'PLAY [p] [CHECK MODE] ****', 'TASK [Show it] [CHECK MODE] ****', 'ok: [h1] => {', '    "msg": "x"', '}']);
        $this->assertSame([], $stopped['tasks'], 'no recap (a stopped run): nothing is attributed');
        $this->assertSame('the PLAY RECAP does not name this host', $stopped['unattributed'][0]['reason']);
    }
}
