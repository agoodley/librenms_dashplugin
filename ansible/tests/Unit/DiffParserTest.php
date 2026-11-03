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

    public function testAnsiblesMessagesBetweenTheLinesOfDetailsLeaveThemWithTheirResult(): void
    {
        // a dry run of eight switches on the lab: the runner merged stderr's warnings into lab-sw3's details
        $targets = [];
        foreach (range(1, 8) as $n) {
            $targets[500 + $n] = "lab-sw$n.netops.lab";
        }
        $lines = DryRunProofTest::log('dry-run-interleaved');
        $this->assertStringContainsString('[WARNING]', implode("\n", array_slice($lines, 19, 3)), 'the warnings are inside lab-sw3\'s details');

        $result = self::parse($lines, $targets);
        foreach ($targets as $deviceId => $name) {
            $this->assertSame([['task' => 'Set the description', 'item' => null, 'kind' => 'commands',
                'lines' => ['interface FastEthernet0/10', 'description batch mvjbalij'], 'cut' => false]], $result['devices'][$deviceId], $name);
        }
        $this->assertSame([], $result['unattributed']);
    }

    public function testTheDetailsOfAFailureStayWithIt(): void
    {
        // lab-dead's error is printed below its "fatal" line; it is not text of lab-sw1, whose result comes next
        $result = self::parse(DryRunProofTest::log('dry-run-unreachable'), [501 => 'lab-sw1.netops.lab', 504 => 'lab-dead.netops.lab']);

        $this->assertSame([], $result['devices']);
        $this->assertSame([], $result['unattributed']);
        $this->assertSame([501 => 1, 504 => 0], $result['changed']);
    }

    public function testDetailsCutShortByAnotherLineLeaveTheTextAfterThemUnattributed(): void
    {
        // a message over several lines (its first line is skipped, not the others) cuts h1's details; what follows may be their rest
        $lines = self::log(['changed: [h1] => ', '    banners: {}', 'Origin: /repos/play.yml:8:7', '    commands:', '    - interface Fa0/9',
            'changed: [h2] => ', '    commands:', '    - interface Fa0/2']);
        $result = self::parse($lines, [1 => 'h1', 2 => 'h2']);

        $this->assertArrayNotHasKey(1, $result['devices']);
        $this->assertSame([['task' => 'Set it', 'item' => null, 'kind' => 'commands', 'lines' => ['interface Fa0/2'], 'cut' => false]], $result['devices'][2]);
        $this->assertCount(1, $result['unattributed']);
        $this->assertSame('h2', $result['unattributed'][0]['host']);
        $this->assertContains('    - interface Fa0/9', $result['unattributed'][0]['lines']);
        $this->assertStringContainsString('may hold the rest of those details', $result['unattributed'][0]['reason']);

        // a unified diff right after details is the next result's: its lines cannot be details
        $lines = self::log(array_merge(['changed: [h1] => ', '    changed: true'], self::diff('two'), ['changed: [h2]']));
        $result = self::parse($lines, [1 => 'h1', 2 => 'h2']);
        $this->assertSame('diff', $result['devices'][2][0]['kind']);
        $this->assertSame([], $result['unattributed']);
    }

    public function testDetailsWithBlankLinesFoldedItemsAndDeeperKeys(): void
    {
        // a block scalar keeps its blank lines unindented; a long command is folded; a deeper "commands:" is a device's text
        $lines = self::log(['changed: [h1] => ', '    changed: true', '    commands:', '    - interface FastEthernet0/1',
            '    - description a long description that PyYAML folds onto the next line once it is longer', '      than eighty characters',
            '    stdout: |-', '        commands:', '        - evil', '', '        more', 'changed: [h2] => ', '    backup:', '      commands:', '      - evil', '    changed: true']);
        $result = self::parse($lines, [1 => 'h1', 2 => 'h2']);

        $this->assertSame(['interface FastEthernet0/1', 'description a long description that PyYAML folds onto the next line once it is longer than eighty characters'],
            $result['devices'][1][0]['lines']);
        $this->assertArrayNotHasKey(2, $result['devices'], 'h2 has no first-level commands');
        $this->assertSame([], $result['unattributed']);
    }

    public function testCommandsFromJsonOverSeveralLines(): void
    {
        $lines = self::log(['changed: [h1] => {', '    "changed": true,', '    "commands": [', '        "interface Fa0/1",', '        "description x"', '    ]', '}',
            'changed: [h2]']);
        $result = self::parse($lines, [1 => 'h1', 2 => 'h2']);

        $this->assertSame(['interface Fa0/1', 'description x'], $result['devices'][1][0]['lines']);
        $this->assertArrayNotHasKey(2, $result['devices'], 'the closing brace is not text of the next result');
        $this->assertSame([], $result['unattributed']);
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

    public function testAnItemLabelOverSeveralLinesMakesTheTaskAmbiguous(): void
    {
        // h1's label holds newlines: the lines up to its end can be another host's diff and result line
        $lines = self::log(array_merge(self::diff('one'), ['changed: [h1] => (item=a'], self::diff('evil'), ['changed: [h2] => (item=b)']));
        $result = self::parse($lines, [1 => 'h1', 2 => 'h2']);

        $this->assertSame([], $result['devices'], 'nothing of the task is attributed');
        $this->assertSame(['h1', 'h2'], array_column($result['unattributed'], 'host'));
        foreach ($result['unattributed'] as $block) {
            $this->assertStringContainsString('label in the log of this task runs over several lines', $block['reason']);
        }
    }

    public function testALabelOverSeveralLinesMakesTheLaterTasksAmbiguousToo(): void
    {
        // the label's text can imitate the banner of a next task, so what follows it is in a task of its own
        $lines = self::log(array_merge(['changed: [h1] => (item=a', 'TASK [Next] [CHECK MODE] ****'], self::diff('evil'), ['changed: [h2] => (item=b)',
            'TASK [Last] [CHECK MODE] ****'], self::diff('real'), ['changed: [h2]']));
        $result = self::parse($lines, [1 => 'h1', 2 => 'h2']);

        $this->assertSame([], $result['devices']);
        $this->assertStringContainsString('label earlier in the log runs over several lines', end($result['unattributed'])['reason']);
    }

    public function testAnIncludesLabelOverSeveralLinesMakesTheTaskAmbiguous(): void
    {
        $lines = self::log(array_merge(['included: /x.yml for h1 => (item=a'], self::diff('evil'), ['changed: [h2] => (item=b)']));
        $result = self::parse($lines, [1 => 'h1', 2 => 'h2']);

        $this->assertSame([], $result['devices']);
        $this->assertStringContainsString('runs over several lines', $result['unattributed'][0]['reason']);
    }

    public function testASkippedItemWithItsDetailsIsALoopItem(): void
    {
        // with -v the default callback prints a skipped item as "skipping: [h] => (item=x)  => {...}" (two spaces)
        $lines = self::log(array_merge(self::diff('one'), ['changed: [h1] => (item=Fa0/1) => {"changed": true}',
            'skipping: [h2] => (item=Fa0/1)  => {"changed": false, "false_condition": "x"}']), ['h1' => 1, 'h2' => 0]);
        $result = self::parse($lines, [1 => 'h1', 2 => 'h2']);

        $this->assertSame('diff', $result['devices'][1][0]['kind'], 'h1\'s diff stays attributed');
        $this->assertSame('Fa0/1', $result['devices'][1][0]['item']);
        $this->assertSame([], $result['unattributed']);
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

    public function testDiffsPastTheChangesCapAreMarkedNotDropped(): void
    {
        // twelve devices that would each change about 108 KB: the change keeps 1 MB of diffs
        $big = array_merge(['--- before', '+++ after', '@@ -1 +1,120 @@'], array_map(fn ($i) => '+line ' . $i . ' ' . str_repeat('x', 900), range(1, 120)));
        $task = [];
        $targets = [];
        foreach (range(1, 12) as $n) {
            $task = array_merge($task, $big, ["changed: [h$n]"]);
            $targets[$n] = "h$n";
        }
        $task = array_merge($task, $big, ['changed: [other]']);
        $changed = array_fill_keys(array_values($targets), 1) + ['other' => 1];
        $result = self::parse(self::log($task, $changed), $targets);

        $this->assertSame(range(1, 12), array_keys($result['devices']), 'every device that would change has something to show');
        $kept = array_filter($result['devices'], fn ($blocks) => $blocks[0]['lines'] !== [DiffParser::TOTAL_CUT]);
        $marked = array_diff_key($result['devices'], $kept);
        $this->assertCount(9, $kept, 'what fits in 1 MB');
        foreach ($marked as $blocks) {
            $this->assertSame([['task' => 'Set it', 'item' => null, 'kind' => 'diff', 'lines' => [DiffParser::TOTAL_CUT], 'cut' => true]], $blocks, 'the rest is marked, once');
        }
        $this->assertLessThanOrEqual(DiffParser::TOTAL_BYTES, array_sum(array_map(fn ($blocks) => strlen(implode("\n", array_merge(...array_column($blocks, 'lines')))), $result['devices'])));
        $this->assertSame([DiffParser::TOTAL_CUT], $result['unattributed'][0]['lines'], 'the blocks not attributed too');
        $this->assertSame(['other', true], [$result['unattributed'][0]['host'], $result['unattributed'][0]['cut']]);
    }

    public function testUnifiedDiffShape(): void
    {
        $this->assertTrue(DiffParser::isUnifiedDiff(self::diff('x')));
        $this->assertFalse(DiffParser::isUnifiedDiff(['--- before', '+++ after', ' no hunk']));
        $this->assertFalse(DiffParser::isUnifiedDiff(array_merge(self::diff('x'), ['changed: [h2]'])));
        $this->assertFalse(DiffParser::isUnifiedDiff(['[edit interfaces]', '+ description x;']));
    }
}
