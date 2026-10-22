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
