<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Runner\RunnerEnvironment;
use Agoodley\LibrenmsAnsible\Runner\RunnerTemplate;
use Agoodley\LibrenmsAnsible\Runner\SurveyVar;
use Agoodley\LibrenmsAnsible\Runs\TemplateCheck;
use Agoodley\LibrenmsAnsible\Runs\TemplateReview;
use Agoodley\LibrenmsAnsible\Tests\Unit\Fakes\FakeRunner;
use PHPUnit\Framework\TestCase;

class TemplateCheckTest extends TestCase
{
    /**
     * A usable template, with some fields replaced.
     */
    public static function template(array $changes = []): RunnerTemplate
    {
        $fields = $changes + [
            'id' => '7', 'name' => 'Port descriptions', 'description' => '', 'playbook' => 'ports.yml', 'app' => 'ansible', 'type' => '',
            'inventoryId' => '4', 'limitOverride' => true, 'fixedLimit' => [], 'arguments' => [], 'argumentsRaw' => '[]',
            'surveyVars' => [new SurveyVar('interface', 'Interface', '', 'string', '', true)], 'canRun' => true, 'taskOverrides' => [],
            'environmentId' => '4',
        ];

        return new RunnerTemplate(...$fields);
    }

    private static function var(string $name, string $type = 'string', string $target = '', array $values = []): SurveyVar
    {
        return new SurveyVar($name, '', '', $type, $type === 'string' ? '' : $type, false, $target, $values);
    }

    public function testUsableTemplate(): void
    {
        $this->assertSame([], TemplateCheck::reasons(self::template(), '4'));
        $this->assertSame([], TemplateCheck::notes(self::template()));
    }

    public function testInventoryLimitOverrideAndOwnLimit(): void
    {
        $reasons = TemplateCheck::reasons(self::template(['inventoryId' => '2', 'limitOverride' => false, 'fixedLimit' => ['lab-sw1']]), '4');

        $this->assertCount(3, $reasons);
        $this->assertStringContainsString('uses inventory #2, not the LibreNMS inventory (#4)', $reasons[0]);
        $this->assertStringContainsString('run it on every host of the inventory', $reasons[1]);
        $this->assertStringContainsString('tick "Limit" under "Ansible prompts" (not the "Limit" field under "Ansible options"', $reasons[1],
            'in the words of Semaphore UI\'s template editor, with its look-alike');
        $this->assertStringContainsString('limit of its own (lab-sw1): clear the "Limit" field under "Ansible options"', $reasons[2]);
        $this->assertStringContainsString('uses no inventory', TemplateCheck::reasons(self::template(['inventoryId' => null]), '4')[0]);
        $this->assertSame(['the LibreNMS inventory is not chosen in the plugin settings yet'], TemplateCheck::reasons(self::template(), ''));
    }

    public function testTheVariableGroupMustGiveTheInventoryScriptWhatItNeeds(): void
    {
        $good = new RunnerEnvironment('4', 'LibreNMS inventory', ['LIBRENMS_URL'], ['LIBRENMS_TOKEN']);
        $this->assertSame([], TemplateCheck::environmentReasons(self::template(), $good));

        $this->assertStringStartsWith('it has no variable group: give it the LibreNMS one', TemplateCheck::environmentReasons(self::template(['environmentId' => null]), null)[0]);
        $reasons = TemplateCheck::environmentReasons(self::template(), new RunnerEnvironment('4', 'Other', ['FOO'], ['api_key']));
        $this->assertCount(2, $reasons);
        $this->assertStringContainsString('"Other" has no environment variable LIBRENMS_URL (Variables tab', $reasons[0]);
        $this->assertStringContainsString('has no secret environment variable LIBRENMS_TOKEN (Secrets tab', $reasons[1]);
        $plain = TemplateCheck::environmentReasons(self::template(), new RunnerEnvironment('4', 'Plain', ['LIBRENMS_URL', 'LIBRENMS_TOKEN'], []));
        $this->assertSame(['its variable group "Plain" has LIBRENMS_TOKEN as a plain environment variable: make it a secret one (Secrets tab, "Environment variables"), so the token is write-only'], $plain);
        $this->assertSame(['its variable group #4 cannot be read: HTTP 403'], TemplateCheck::environmentReasons(self::template(), null, 'HTTP 403'));
    }

    public function testTheReviewAsksForEachVariableGroupOnce(): void
    {
        $runner = new FakeRunner();
        $review = new TemplateReview($runner, '4');

        $this->assertSame([], $review->reasons(self::template()));
        $this->assertSame([], $review->reasons(self::template(['id' => '8'])));
        $this->assertStringStartsWith('it has no variable group', $review->reasons(self::template(['id' => '9', 'environmentId' => null]))[0]);
    }

    public function testKindAndPermission(): void
    {
        $reasons = TemplateCheck::reasons(self::template(['app' => 'terraform', 'type' => 'deploy', 'canRun' => false]), '4');

        $this->assertSame(['it is a terraform template, not an Ansible one', 'it is a deploy template: only plain task templates can be launched',
            "the token's user may not run it"], $reasons);
        $this->assertSame([], TemplateCheck::reasons(self::template(['canRun' => null]), '4'), 'unknown permission is not a refusal');
    }

    /**
     * @return array<string, array{0: string[]|null, 1: bool}>
     */
    public static function argumentCases(): array
    {
        return [
            'verbosity and diff' => [['-vvv', '--diff', '--check'], true],
            'forks and timeout' => [['--forks=5', '-f', '10', '--timeout', '30', '-T', '5'], true],
            'tags' => [['--tags', 'ntp,snmp', '--skip-tags=reload'], true],
            'limit' => [['--limit', 'all'], false],
            'short limit' => [['-l', 'lab-sw1'], false],
            'abbreviated limit' => [['--lim=x'], false],
            'combined short flags' => [['-vl', 'x'], false],
            'extra vars' => [['-e', 'note=from_args'], false],
            'extra vars file' => [['--extra-vars=@vars.yml'], false],
            'inventory' => [['-i', '/etc/hosts'], false],
            'ssh args' => [['--ssh-common-args=-oProxyCommand=x'], false],
            'forks without a number' => [['--forks', 'many'], false],
            'tags with a pattern' => [['--tags', '*'], false],
            'option at the end' => [['--forks'], false],
            'not a list' => [null, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('argumentCases')]
    public function testArguments(?array $arguments, bool $usable): void
    {
        $reasons = TemplateCheck::reasons(self::template(['arguments' => $arguments]), '4');

        $this->assertSame($usable, $reasons === [], implode('; ', $reasons));
    }

    public function testArgumentReasonNamesTheArgument(): void
    {
        $this->assertStringContainsString('"-e", which could change the targets', TemplateCheck::reasons(self::template(['arguments' => ['-v', '-e', 'x=1']]), '4')[0]);
    }

    /**
     * @return array<string, array{0: SurveyVar, 1: string|null}>
     */
    public static function surveyCases(): array
    {
        return [
            'plain' => [self::var('description'), null],
            'connection variable' => [self::var('ansible_host'), 'connection or magic variable'],
            'connection variable in capitals' => [self::var('ANSIBLE_SSH_COMMON_ARGS'), 'connection or magic variable'],
            'magic variable' => [self::var('hostvars'), 'connection or magic variable'],
            'omit' => [self::var('omit'), 'connection or magic variable'],
            'inventory fact' => [self::var('librenms_location'), 'librenms_* fact'],
            'not a name' => [self::var('my-var'), 'not a valid Ansible variable name'],
            'keyword' => [self::var('class'), 'not a valid Ansible variable name'],
            'env plain' => [self::var('CHANGE_REF', 'string', 'env'), null],
            'env inventory url' => [self::var('LIBRENMS_URL', 'string', 'env'), 'environment variable LIBRENMS_URL'],
            'env proxy lowercase' => [self::var('https_proxy', 'string', 'env'), 'environment variable https_proxy'],
            'env ansible' => [self::var('ANSIBLE_CONFIG', 'string', 'env'), 'environment variable'],
            'env path' => [self::var('PATH', 'string', 'env'), 'environment variable PATH'],
            'unknown target' => [self::var('x', 'string', 'file'), 'goes to "file"'],
            'unknown type' => [new SurveyVar('x', '', '', 'unknown', 'float', false), 'type "float"'],
            'enum without choices' => [self::var('mode', 'enum'), 'without any choice'],
            'enum' => [self::var('mode', 'enum', '', [['name' => 'A', 'value' => 'a']]), null],
            'secret' => [self::var('password', 'secret'), null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surveyCases')]
    public function testSurveyVariables(SurveyVar $var, ?string $reason): void
    {
        $reasons = TemplateCheck::reasons(self::template(['surveyVars' => [$var]]), '4');

        if ($reason === null) {
            $this->assertSame([], $reasons);
        } else {
            $this->assertCount(1, $reasons, implode('; ', $reasons));
            $this->assertStringContainsString($reason, $reasons[0]);
        }
    }

    public function testDuplicateSurveyVariable(): void
    {
        $reasons = TemplateCheck::reasons(self::template(['surveyVars' => [self::var('a'), self::var('a')]]), '4');

        $this->assertSame(['the survey variable "a" is defined twice'], $reasons);
    }

    public function testNotesOnTaskOverrides(): void
    {
        $notes = TemplateCheck::notes(self::template(['taskOverrides' => ['CLI arguments', 'inventory']]));

        $this->assertCount(1, $notes);
        $this->assertStringContainsString('override its CLI arguments, inventory', $notes[0]);
    }
}
