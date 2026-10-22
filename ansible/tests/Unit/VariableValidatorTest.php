<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Runner\SurveyVar;
use Agoodley\LibrenmsAnsible\Runs\VariableValidator;
use PHPUnit\Framework\TestCase;

class VariableValidatorTest extends TestCase
{
    /**
     * @return SurveyVar[]
     */
    private static function vars(): array
    {
        return [
            new SurveyVar('description', 'Description', '', 'string', '', true),
            new SurveyVar('vlan', 'VLAN', '', 'int', 'int', false),
            new SurveyVar('mode', 'Mode', '', 'enum', 'enum', false, '', [['name' => 'Access', 'value' => 'access'], ['name' => 'Trunk', 'value' => 'trunk']]),
            new SurveyVar('lines', 'Lines', '', 'text', 'text', false),
            new SurveyVar('password', 'Password', '', 'secret', 'secret', false),
            new SurveyVar('CHANGE_REF', 'Change', '', 'string', '', false, 'env'),
        ];
    }

    public function testValidAnswers(): void
    {
        $result = VariableValidator::validate(self::vars(), ['description' => '  uplink to core1 (Gi1/0/1) ', 'vlan' => '120', 'mode' => 'trunk',
            'lines' => "Fa0/1: uplink\r\nFa0/2: printer", 'password' => ' s3cr3t! ', 'CHANGE_REF' => 'CHG-42', 'ansible_host' => 'evil', 'other' => 'x']);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['description' => 'uplink to core1 (Gi1/0/1)', 'vlan' => 120, 'mode' => 'trunk', 'lines' => "Fa0/1: uplink\nFa0/2: printer",
            'CHANGE_REF' => 'CHG-42'], $result['values'], 'only survey variables are sent');
        $this->assertSame(['password' => ' s3cr3t! '], $result['secrets'], 'a secret is kept apart and as typed');
    }

    public function testRequiredAndOptional(): void
    {
        $result = VariableValidator::validate(self::vars(), ['description' => '   ', 'vlan' => '']);

        $this->assertSame(['description' => 'is required'], $result['errors']);
        $this->assertSame([], $result['values'], 'empty optional answers are left out');
    }

    /**
     * @return array<string, array{0: array, 1: string, 2: string}>
     */
    public static function badAnswers(): array
    {
        return [
            'not a number' => [['vlan' => '12a'], 'vlan', 'whole number'],
            'decimal' => [['vlan' => '1.5'], 'vlan', 'whole number'],
            'not a choice' => [['mode' => 'Trunk'], 'mode', 'one of: access, trunk'],
            'jinja variable' => [['description' => "x {{ lookup('pipe','id') }}"], 'description', 'Jinja'],
            'jinja statement' => [['description' => '{% for x in y %}'], 'description', 'Jinja'],
            'jinja comment' => [['lines' => "ok\n{# x #}"], 'lines', 'Jinja'],
            'jinja end only' => [['description' => 'a }} b'], 'description', 'Jinja'],
            'newline in a string' => [['description' => "a\nb"], 'description', 'new lines'],
            'tab' => [['description' => "a\tb"], 'description', 'control characters'],
            'escape in text' => [['lines' => "a\n\x1b[31mb"], 'lines', 'control characters'],
            'bidi override' => [['description' => "a\u{202E}b"], 'description', 'control characters'],
            'jinja in a secret' => [['password' => 'pw{{x}}'], 'password', 'Jinja'],
            'invalid utf-8' => [['description' => "a\xffb"], 'description', 'UTF-8'],
            'too long' => [['description' => str_repeat('x', 1001)], 'description', 'longer than 1000'],
            'array' => [['description' => ['x']], 'description', 'single value'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badAnswers')]
    public function testBadAnswers(array $input, string $name, string $error): void
    {
        $result = VariableValidator::validate(self::vars(), $input + ['description' => 'ok']);

        $this->assertArrayHasKey($name, $result['errors']);
        $this->assertStringContainsString($error, $result['errors'][$name]);
        $this->assertArrayNotHasKey($name, $result['values']);
        $this->assertArrayNotHasKey($name, $result['secrets']);
    }

    public function testLongText(): void
    {
        $result = VariableValidator::validate(self::vars(), ['description' => 'x', 'lines' => str_repeat("line\n", 1999)]);

        $this->assertSame([], $result['errors']);
        $this->assertArrayHasKey('lines', VariableValidator::validate(self::vars(), ['description' => 'x', 'lines' => str_repeat('y', 10001)])['errors']);
    }

    public function testNegativeAndIntInput(): void
    {
        $result = VariableValidator::validate(self::vars(), ['description' => 'x', 'vlan' => -5]);

        $this->assertSame(-5, $result['values']['vlan']);
    }
}
