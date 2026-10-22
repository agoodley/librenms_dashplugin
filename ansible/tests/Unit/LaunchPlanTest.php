<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Runner\SurveyVar;
use Agoodley\LibrenmsAnsible\Services\ChangeActions;
use Agoodley\LibrenmsAnsible\Services\LaunchPlan;
use Agoodley\LibrenmsAnsible\Services\RunLauncher;
use Agoodley\LibrenmsAnsible\Services\RunSpec;
use PHPUnit\Framework\TestCase;

class LaunchPlanTest extends TestCase
{
    public function testThePreviewShowsAnswersAsGivenAndRefusedOnesWithTheirError(): void
    {
        $plan = new LaunchPlan();
        $plan->template = TemplateCheckTest::template(['surveyVars' => [new SurveyVar('lines', 'Lines', '', 'text', 'text', false),
            new SurveyVar('count', 'Count', '', 'int', 'int', false), new SurveyVar('pw', 'Password', '', 'secret', 'secret', false)]]);
        $plan->values = ['lines' => "line one\n  indented two\n\nfour"];
        $plan->variableErrors = ['count' => 'must be a whole number', 'pw' => 'has control characters'];
        $plan->rejected = ['count' => '12a <b>'];
        $plan->secrets = [];

        $variables = $plan->forPage()['variables'];
        $this->assertSame("line one\n  indented two\n\nfour", $variables[0]['value'], 'several lines stay several lines');
        $this->assertNull($variables[0]['error']);
        $this->assertSame('12a <b>', $variables[1]['value'], 'a refused answer shows as given (the page inserts it as text)');
        $this->assertSame('must be a whole number', $variables[1]['error']);
        $this->assertNull($variables[2]['value'], 'a secret answer is never shown, refused or not');
        $this->assertSame('has control characters', $variables[2]['error']);
        $this->assertNull($plan->variables()[1]['value'], 'the audit log keeps only accepted answers');
    }

    public function testADryRunIsAskedInCheckModeWithDiffsABatchPinnedToItsDryRunsCommit(): void
    {
        $plan = new LaunchPlan();
        $plan->template = TemplateCheckTest::template(['id' => '77']);
        $plan->targets = [501 => ['device_id' => 501, 'name' => 'lab-sw1.netops.lab', 'display' => 'lab-sw1', 'hostname' => 'lab-sw1.netops.lab', 'os' => 'ios', 'platform' => '']];
        $plan->values = ['interface' => 'Fa0/1'];
        $plan->secrets = ['key' => 's3cret'];
        $change = ['id' => 5, 'batch_count' => 2, 'dry_run_commit' => str_repeat('ab', 20)];
        $none = fn (array $snapshot): ?string => null;

        $dryRun = ChangeActions::spec($change, RunSpec::DRY_RUN, 1, $none);
        $this->assertSame(['change #5, dry run', null], [$dryRun->context, $dryRun->commit], 'a dry run runs the runner\'s latest commit (it records which)');
        $request = RunLauncher::request($plan, 'run #9', $dryRun);
        $this->assertSame(['77', ['lab-sw1.netops.lab'], ['interface' => 'Fa0/1'], ['key' => 's3cret'], 'run #9', true, true, null],
            [$request->templateId, $request->limit, $request->vars, $request->secrets, $request->message, $request->check, $request->diff, $request->commit],
            'the dry run: --check --diff');

        $batch = ChangeActions::spec($change, RunSpec::BATCH, 2, $none);
        $this->assertSame(['change #5, batch 2 of 2', str_repeat('ab', 20)], [$batch->context, $batch->commit]);
        $request = RunLauncher::request($plan, 'run #10', $batch);
        $this->assertSame([false, false, str_repeat('ab', 20)], [$request->check, $request->diff, $request->commit], 'a batch: for real, pinned to the dry run\'s commit');
        $this->assertNotSame($dryRun->token(), $batch->token(), 'each run of a change has its own one-time token');

        $request = RunLauncher::request($plan, 'run #11');
        $this->assertSame([false, false, null], [$request->check, $request->diff, $request->commit], 'a run of phase 1 as it is');
    }
}
