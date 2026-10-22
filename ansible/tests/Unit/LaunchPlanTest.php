<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Runner\SurveyVar;
use Agoodley\LibrenmsAnsible\Services\LaunchPlan;
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
}
