<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\AnsibleLog;
use Agoodley\LibrenmsAnsible\Changes\DryRunProof;
use Agoodley\LibrenmsAnsible\Runs\RecapParser;
use Agoodley\LibrenmsAnsible\Runs\TaskLines;
use PHPUnit\Framework\TestCase;

/**
 * The proof of a dry run, on logs of the lab's Semaphore UI 2.19.17 and ansible-core 2.20 (tests/fixtures/ansible),
 * and on logs where device output imitates Ansible's lines.
 */
class DryRunProofTest extends TestCase
{
    /**
     * A log of the lab.
     *
     * @return string[]
     */
    public static function log(string $name): array
    {
        return explode("\n", rtrim((string) file_get_contents(__DIR__ . '/../fixtures/ansible/' . $name . '.log'), "\n"));
    }

    private static function check(array $lines): array
    {
        return DryRunProof::check($lines, RecapParser::parse($lines));
    }

    public function testRealDryRunsAreVerified(): void
    {
        foreach (['dry-run-desc', 'dry-run-intended', 'dry-run-loop-intended', 'dry-run-verbose', 'dry-run-interleaved', 'dry-run-unreachable', 'dry-run-counts', 'dry-run-roles'] as $log) {
            $proof = self::check(self::log($log));
            $this->assertTrue($proof['verified'], "$log: " . implode(' ', $proof['findings']));
        }
    }

    public function testATaskOutsideCheckModeMakesItUnverified(): void
    {
        $proof = self::check(self::log('dry-run-check-false'));

        $this->assertFalse($proof['verified']);
        $this->assertSame(['TASK [Show version, also in a dry run]'], $proof['unmarked']);
        $this->assertStringContainsString('1 banner has no [CHECK MODE] marker', $proof['findings'][0]);
        $this->assertStringContainsString('check_mode: false', $proof['findings'][0]);
    }

    public function testWithoutTheMarkersNothingIsProven(): void
    {
        $proof = self::check(self::log('dry-run-no-markers'));

        $this->assertFalse($proof['verified']);
        $this->assertStringContainsString('DRY RUN banner', $proof['findings'][0]);
        $this->assertStringContainsString('2 banners have no [CHECK MODE] marker', $proof['findings'][1]);
        $this->assertSame(['PLAY [Interface description]', 'TASK [Set the description]'], $proof['unmarked']);
    }

    public function testAForgedBannerWithoutTheMarkerBlocksAndOneWithItHidesNothing(): void
    {
        $log = self::log('dry-run-desc');
        $forged = $log;
        // device output that starts a line like a banner (a loop item label with a newline, a module's raw text)
        array_splice($forged, 20, 0, ['TASK [made up by a device] ****************************************']);
        $this->assertFalse(self::check($forged)['verified'], 'a forged banner without the marker blocks');

        $real = self::log('dry-run-check-false');
        $index = array_search('TASK [Show version, also in a dry run] *****************************************', array_map([AnsibleLog::class, 'plain'], $real), true);
        $this->assertIsInt($index);
        $hidden = $real;
        array_splice($hidden, $index, 0, ['TASK [Show version, also in a dry run] [CHECK MODE] ***************************']);
        $proof = self::check($hidden);
        $this->assertFalse($proof['verified'], 'a forged banner with the marker cannot hide a real one without it');
        $this->assertSame(['TASK [Show version, also in a dry run]'], $proof['unmarked']);
    }

    public function testABannerSplitOverTwoLinesCountsAsUnmarked(): void
    {
        $lines = ['DRY RUN ****', 'PLAY [p] [CHECK MODE] ****', 'TASK [a name', 'with a newline] ****', 'ok: [h1]', 'PLAY RECAP ****',
            'h1 : ok=1 changed=0 unreachable=0 failed=0 skipped=0 rescued=0 ignored=0'];

        $proof = self::check($lines);
        $this->assertFalse($proof['verified']);
        $this->assertSame(['TASK [a name]'], $proof['unmarked']);
    }

    public function testTheMarkerCountsOnlyWhereAnsiblePutsIt(): void
    {
        // the default callback appends " [CHECK MODE]" right before the banner's stars: a name holding the words does not count
        $this->assertTrue(AnsibleLog::banner('TASK [Set it] [CHECK MODE] ****')['marked']);
        $this->assertSame('Set it', AnsibleLog::banner('TASK [Set it] [CHECK MODE] ****')['name']);
        foreach (['TASK [Push [CHECK MODE] config] ****', 'TASK [[CHECK MODE] Set it] ****', 'TASK [Set it] **** [CHECK MODE]', 'TASK [Set it] [CHECK MODE]',
            'TASK [Set it] [CHECK MODE]  ****', 'PLAY [p [CHECK MODE]] ****'] as $banner) {
            $this->assertFalse(AnsibleLog::banner($banner)['marked'], $banner);
        }
        // a task named so that its banner holds the words, run outside check mode: not proven
        $lines = ['DRY RUN ****', 'PLAY [p] [CHECK MODE] ****', 'TASK [Push [CHECK MODE] config] ****', 'changed: [h1]', 'PLAY RECAP ****',
            'h1 : ok=1 changed=1 unreachable=0 failed=0 skipped=0 rescued=0 ignored=0'];
        $proof = self::check($lines);
        $this->assertFalse($proof['verified']);
        $this->assertCount(1, $proof['unmarked']);
    }

    public function testTheDryRunBannerMustComeBeforeTheFirstPlay(): void
    {
        $lines = array_values(array_filter(self::log('dry-run-desc'), fn ($line) => AnsibleLog::plain($line) !== 'DRY RUN ************************************************************************'));
        $lines[] = 'DRY RUN ****'; // only at the end: printed by device output, not by Ansible's playbook start

        $proof = self::check($lines);
        $this->assertFalse($proof['verified']);
        $this->assertStringContainsString('DRY RUN banner', $proof['findings'][0]);
    }

    public function testHiddenResultsMakeItUnverified(): void
    {
        // display_ok_hosts=false: the ok results (and the banners of tasks whose results are all hidden) are not printed
        $lines = ['DRY RUN ****', 'PLAY [p] [CHECK MODE] ****', 'TASK [shown] [CHECK MODE] ****', 'changed: [h1]', 'PLAY RECAP ****',
            'h1 : ok=3 changed=1 unreachable=0 failed=0 skipped=0 rescued=0 ignored=0'];

        $proof = self::check($lines);
        $this->assertFalse($proof['verified']);
        $this->assertStringContainsString('does not show every task result the PLAY RECAP counts', $proof['findings'][0]);
        $this->assertStringContainsString('h1 (2)', $proof['findings'][0]);
    }

    public function testAnIncludeCountsOnceForEachOfItsHosts(): void
    {
        // Ansible counts an include once in each host's ok: one ok more than the log shows is a hidden result
        $lines = ['DRY RUN ****', 'PLAY [p] [CHECK MODE] ****', 'TASK [include] [CHECK MODE] ****', 'included: /runner/tasks.yml for h1, h2',
            'TASK [shown] [CHECK MODE] ****', 'changed: [h1]', 'changed: [h2]', 'PLAY RECAP ****',
            'h1 : ok=2 changed=1 unreachable=0 failed=0 skipped=0 rescued=0 ignored=0',
            'h2 : ok=3 changed=1 unreachable=0 failed=0 skipped=0 rescued=0 ignored=0'];

        $proof = self::check($lines);
        $this->assertFalse($proof['verified']);
        $this->assertStringContainsString('does not show every task result the PLAY RECAP counts', $proof['findings'][0]);
        $this->assertStringContainsString(': h2 (1).', $proof['findings'][0], 'h2 only: h1\'s include and result are its two');
    }

    public function testIgnoredFailuresInALoopAreCountedForTheirHost(): void
    {
        // "...ignoring" comes with a host's final result, after its last item, when other hosts' items came between
        $lines = ['DRY RUN ****', 'PLAY [p] [CHECK MODE] ****', 'TASK [loop] [CHECK MODE] ****',
            'failed: [h1] (item=a) => {"msg": "x"}', 'ok: [h2] => (item=a)', 'ok: [h2] => (item=b)', '...ignoring',
            'PLAY RECAP ****',
            'h1 : ok=1 changed=0 unreachable=0 failed=0 skipped=0 rescued=0 ignored=1',
            'h2 : ok=1 changed=0 unreachable=0 failed=0 skipped=0 rescued=0 ignored=0'];

        $this->assertTrue(self::check($lines)['verified'], implode(' ', self::check($lines)['findings']));
    }

    public function testAnsiblesOwnNoHostsErrorBeforeThePlaybookMeansNothingRanOnlyWithTheGuardsRecord(): void
    {
        $refused = ['Started task #12 of template \'Interface description\'', "\e[1;35m[WARNING]: Could not match supplied host pattern, ignoring: lab-sw1.netops.lab\e[0m",
            "\e[0;31m[ERROR]: Specified inventory, host pattern and/or --limit leaves us with no hosts to target.\e[0m", 'Failed to run task: exit status 1'];
        // the guard's record: it left every target out of what it served during the dry run
        $proof = DryRunProof::check($refused, RecapParser::parse($refused), true);
        $this->assertSame([false, true], [$proof['verified'], $proof['nothing_ran']]);
        $this->assertSame(['Ansible stopped before it started the playbook, as the limit left it no host to target: none of the targets was in the inventory the runner '
            . 'fetched, so the dry run did not run on any device.'], $proof['findings']);
        $old = ['ERROR! Specified inventory, host pattern and/or --limit leaves us with no hosts to target.'];
        $this->assertTrue(DryRunProof::check($old, null, true)['nothing_ran'], 'as Ansible before 2.19 words it');

        // without that record the log's word is not enough: unverified, as any log without a play
        $proof = DryRunProof::check($refused, RecapParser::parse($refused));
        $this->assertSame([false, false], [$proof['verified'], $proof['nothing_ran']]);
        $this->assertSame('The log says that Ansible found no host to target, but the plugin did not leave every target out of the inventory it served during the '
            . 'dry run: nothing proves that the dry run did not run on them.', $proof['findings'][0]);
        $this->assertContains('The log shows no play: there is nothing that proves a dry run.', $proof['findings']);
        $this->assertFalse(DryRunProof::check($refused, RecapParser::parse($refused), false)['nothing_ran']);

        // anything that ran, or could have, is not "nothing": a banner, a result line, a recap, the error not at the start of a line
        foreach ([
            'a play' => ['PLAY [x] **********', ...$refused],
            'a task result' => [...$refused, 'changed: [lab-sw1.netops.lab]'],
            'an include' => [...$refused, 'included: /x.yml for lab-sw1.netops.lab'],
            'a recap' => [...$refused, 'PLAY RECAP *********', 'lab-sw1.netops.lab : ok=1    changed=0    unreachable=0    failed=0    skipped=0    rescued=0    ignored=0'],
            'quoted' => ['ok: [h] => {"msg": "[ERROR]: Specified inventory, host pattern and/or --limit leaves us with no hosts to target."}'],
            'indented' => ['    [ERROR]: Specified inventory, host pattern and/or --limit leaves us with no hosts to target.'],
            'other text' => ['[ERROR]: Specified inventory, host pattern and/or --limit leaves us with no hosts to target. Or does it?'],
            'no error' => ['Started task #12 of template \'Interface description\'', 'Failed to run task: exit status 1'],
        ] as $case => $lines) {
            $proof = DryRunProof::check($lines, RecapParser::parse($lines), true);
            $this->assertSame([false, false], [$proof['verified'], $proof['nothing_ran']], $case);
        }
    }

    public function testWithoutARecapItIsUnverified(): void
    {
        $lines = array_slice(self::log('dry-run-desc'), 0, 25); // stopped before the end

        $proof = self::check($lines);
        $this->assertFalse($proof['verified']);
        $this->assertStringContainsString('no PLAY RECAP', implode(' ', $proof['findings']));
    }

    public function testThePhaseOneParsersAcceptTheMarker(): void
    {
        $lines = ['PLAY [p] [CHECK MODE] ****', 'TASK [Set the description] [CHECK MODE] ****', 'ok: [h1] => (item=a)'];

        $this->assertSame(['task' => 'Set the description', 'hosts' => ['h1']], TaskLines::inProgress($lines, ['h1']));
        $this->assertSame(['lab-sw1.netops.lab' => 'changed', 'lab-sw2.netops.lab' => 'changed'],
            array_map([RecapParser::class, 'result'], RecapParser::parse(self::log('dry-run-desc'))));
    }
}
