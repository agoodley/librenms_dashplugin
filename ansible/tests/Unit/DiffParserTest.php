<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\DiffParser;
use Agoodley\LibrenmsAnsible\Runs\RecapParser;
use PHPUnit\Framework\TestCase;

/**
 * The diff per device of a dry run, on logs of the lab (tests/fixtures/ansible) and on logs where device output
 * imitates Ansible's lines to make a diff show under another device.
 */
class DiffParserTest extends TestCase
{
    private const TARGETS = [501 => 'lab-sw1.netops.lab', 502 => 'lab-sw2.netops.lab'];

    private static function parse(array $lines, array $targets = self::TARGETS): array
    {
        return DiffParser::parse($lines, $targets, RecapParser::parse($lines));
    }

    /**
     * A log of two hosts with the given lines in the task "Set it", and a recap.
     *
     * @param  array<string, int>  $changed  host => changed tasks in the recap
     */
    private static function log(array $task, array $changed = ['h1' => 1, 'h2' => 1]): array
    {
        $lines = array_merge(['DRY RUN ****', 'PLAY [p] [CHECK MODE] ****', 'TASK [Set it] [CHECK MODE] ****'], $task, ['PLAY RECAP ****']);
        foreach ($changed as $host => $count) {
            $lines[] = "$host : ok=1 changed=$count unreachable=0 failed=0 skipped=0 rescued=0 ignored=0";
        }

        return $lines;
    }

    private static function diff(string $added): array
    {
        return ['--- before', '+++ after', '@@ -1,2 +1,3 @@', ' interface Fa0/1', '+ description ' . $added, ' interface Fa0/2'];
    }

    public function testAnIntendedDiffGoesToItsDevice(): void
    {
        $result = self::parse(DryRunProofTest::log('dry-run-intended'));

        $this->assertEqualsCanonicalizing([501, 502], array_keys($result['devices']));
        foreach ([501, 502] as $deviceId) {
            $this->assertCount(1, $result['devices'][$deviceId]);
            $block = $result['devices'][$deviceId][0];
            $this->assertSame('Set the description', $block['task']);
            $this->assertSame('diff', $block['kind']);
            $this->assertSame('--- before', $block['lines'][0]);
            $this->assertContains('+ description intended probe', $block['lines']);
            $this->assertNotContains('[WARNING]: To ensure idempotency and correct diff the input configuration lines should be similar to how they appear if present in the running configuration on device', $block['lines'], 'warnings are not part of a diff');
        }
        $this->assertSame([], $result['unattributed']);
        $this->assertSame([501 => 1, 502 => 1], $result['changed']);
    }

    public function testLoopItemsKeepTheirLabel(): void
    {
        $result = self::parse(DryRunProofTest::log('dry-run-loop-intended'));

        $this->assertSame(['FastEthernet0/3', 'FastEthernet0/4'], array_column($result['devices'][501], 'item'));
        $this->assertSame(['FastEthernet0/3', 'FastEthernet0/4'], array_column($result['devices'][502], 'item'));
        $this->assertContains('  description oxidized-check', $result['devices'][501][0]['lines'], 'context lines keep their indentation');
        $this->assertSame([], $result['unattributed']);
    }

    public function testIosConfigWithoutDiffAgainstHasNoDiffButVerboseShowsItsCommands(): void
    {
        $plain = self::parse(DryRunProofTest::log('dry-run-desc'));
        $this->assertSame([], $plain['devices'], 'ios_config prints no diff in check mode without diff_against');
        $this->assertSame([501 => 1, 502 => 1], $plain['changed']);

        $verbose = self::parse(DryRunProofTest::log('dry-run-verbose'), [501 => 'lab-sw1.netops.lab']);
        $this->assertSame([['task' => 'Set the description', 'item' => null, 'kind' => 'commands',
            'lines' => ['interface FastEthernet0/1', 'description verbose probe'], 'cut' => false]], $verbose['devices'][501]);
    }

    public function testCommandsFromJsonDetails(): void
    {
        $result = self::parse(self::log(['changed: [h1] => {"changed": true, "commands": ["interface Fa0/1", "description x"], "updates": ["y"]}',
            'ok: [h2]'], ['h1' => 1, 'h2' => 0]), [1 => 'h1', 2 => 'h2']);

        $this->assertSame(['interface Fa0/1', 'description x'], $result['devices'][1][0]['lines']);
        $this->assertArrayNotHasKey(2, $result['devices']);
    }

    public function testAResultLineImitatedInRawTextIsCaughtByTheDuplicate(): void
    {
        // h1's module prints raw text (a "prepared" diff) holding a line that imitates h2's result, then h2's real one comes
        $result = self::parse(self::log(['[edit]', '+ evil for h2', 'changed: [h2]', '+ rest of h1', 'changed: [h1]', '[edit]', '+ real h2', 'changed: [h2]']),
            [1 => 'h1', 2 => 'h2']);

        $this->assertSame([], $result['devices'], 'nothing of an ambiguous task is attributed');
        $this->assertCount(3, $result['unattributed']);
        $this->assertStringContainsString('names h2 twice', $result['unattributed'][0]['reason']);
    }

    public function testAResultLineImitatedForAHostOutsideTheTaskIsCaughtByTheRecap(): void
    {
        // h2 had no result in this task (it failed before), so the imitated line is its only one: the recap counts no change for it
        $result = self::parse(self::log(['[edit]', '+ evil', 'changed: [h2]', '+ rest', 'changed: [h1]'], ['h1' => 1, 'h2' => 0]), [1 => 'h1', 2 => 'h2']);

        $this->assertSame(['+ rest'], $result['devices'][1][0]['lines']);
        $this->assertArrayNotHasKey(2, $result['devices']);
        $this->assertSame('h2', $result['unattributed'][0]['host']);
        $this->assertStringContainsString('more changed tasks for this host than the PLAY RECAP counts', $result['unattributed'][0]['reason']);
    }

    public function testInALoopOnlyUnifiedDiffsAreAttributed(): void
    {
        // an item label with a newline can imitate the next result line and take the details printed after it
        $lines = self::log(array_merge(self::diff('one'), ['changed: [h1] => (item=a)', 'changed: [h2] => (item=z) =>', '    commands:', '    - stolen'],
            ['[edit]', '+ raw text', 'changed: [h2] => (item=b)']));
        $result = self::parse($lines, [1 => 'h1', 2 => 'h2']);

        $this->assertSame('diff', $result['devices'][1][0]['kind']);
        $this->assertArrayNotHasKey(2, $result['devices']);
        $reasons = array_column($result['unattributed'], 'reason');
        $this->assertCount(2, $reasons);
        $this->assertStringContainsString('printed after a loop item', $reasons[0]);
        $this->assertStringContainsString('text a module printed as it is, in a loop', $reasons[1]);
    }

    public function testAHostWithAndWithoutAnItemMakesTheTaskAmbiguous(): void
    {
        $lines = self::log(array_merge(self::diff('x'), ['changed: [h1] => (item=a)', 'changed: [h1]']), ['h1' => 1]);

        $result = self::parse($lines, [1 => 'h1']);
        $this->assertSame([], $result['devices']);
        $this->assertStringContainsString('with and without a loop item', $result['unattributed'][0]['reason']);
    }

    public function testHostsThatAreNotTargetsAndBlocksBeforeOtherResults(): void
    {
        $lines = self::log(array_merge(self::diff('x'), ['changed: [other]', 'task path 6 gather_facts: false', 'fatal: [h1]: FAILED! => {}', 'skipping: [h2]']),
            ['h1' => 0, 'h2' => 0, 'other' => 1]);

        $result = self::parse($lines, [1 => 'h1', 2 => 'h2']);
        $this->assertSame([], $result['devices']);
        $this->assertCount(1, $result['unattributed'], 'only blocks before a changed line are diffs');
        $this->assertSame('other', $result['unattributed'][0]['host']);
        $this->assertStringContainsString('not a target', $result['unattributed'][0]['reason']);
    }

    public function testBlocksAreCut(): void
    {
        $long = array_merge(['--- before', '+++ after', '@@ -1 +1,500 @@'], array_map(fn ($i) => '+line ' . $i . str_repeat('x', $i === 3 ? 2000 : 0), range(1, 500)));
        $result = self::parse(self::log(array_merge($long, ['changed: [h1]']), ['h1' => 1]), [1 => 'h1']);

        $block = $result['devices'][1][0];
        $this->assertCount(DiffParser::BLOCK_LINES, $block['lines']);
        $this->assertTrue($block['cut']);
        $this->assertLessThan(1100, mb_strlen($block['lines'][5]));
    }

    public function testUnifiedDiffShape(): void
    {
        $this->assertTrue(DiffParser::isUnifiedDiff(self::diff('x')));
        $this->assertFalse(DiffParser::isUnifiedDiff(['--- before', '+++ after', ' no hunk']));
        $this->assertFalse(DiffParser::isUnifiedDiff(array_merge(self::diff('x'), ['changed: [h2]'])));
        $this->assertFalse(DiffParser::isUnifiedDiff(['[edit interfaces]', '+ description x;']));
    }
}
