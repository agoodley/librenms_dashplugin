<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\History\Jobs;
use PHPUnit\Framework\TestCase;

/**
 * Every command line the LibreNMS scheduler runs fits its command, as Symfony binds it at run time
 * (a flag given a value or an unknown option makes the scheduled run fail every interval).
 */
class HistoryJobsTest extends TestCase
{
    private const SIGNATURES = [Jobs::SNAPSHOT, Jobs::CHECKS];

    public function testScheduledCommandLinesFitTheirCommands(): void
    {
        $this->assertNotEmpty(Jobs::SCHEDULED);
        foreach (Jobs::SCHEDULED as $name => $parameters) {
            self::bind(self::compile($parameters), self::definition($name));
            $this->addToAssertionCount(1);
        }
    }

    public function testTheBindingCatchesBadParameters(): void
    {
        self::bind(self::compile(['--no-checks']), self::definition('topology:snapshot'));
        self::bind(self::compile(['stp', 'links']), self::definition('topology:checks'));

        foreach ([['topology:snapshot', ['--no-checks' => true]], ['topology:snapshot', ['--checks']], ['topology:snapshot', ['stp']]] as [$name, $parameters]) {
            try {
                self::bind(self::compile($parameters), self::definition($name));
                $this->fail(json_encode($parameters) . ' should not bind');
            } catch (\RuntimeException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Arguments and options of a Laravel command signature.
     *
     * @return array{arguments: array<int, array{name: string, array: bool}>, options: array<string, bool>} option => accepts a value
     */
    private static function definition(string $name): array
    {
        foreach (self::SIGNATURES as $signature) {
            if (strtok(trim($signature), " \n") !== $name) {
                continue;
            }
            preg_match_all('/\{\s*([^}]+?)\s*\}/', $signature, $groups);
            $definition = ['arguments' => [], 'options' => []];
            foreach ($groups[1] as $group) {
                $token = trim(explode(' : ', $group, 2)[0]);
                if (str_starts_with($token, '--')) {
                    $option = explode('|', substr($token, 2));
                    $definition['options'][explode('=', end($option))[0]] = str_contains($token, '=');
                } else {
                    $definition['arguments'][] = ['name' => rtrim($token, '?*'), 'array' => str_ends_with($token, '*')];
                }
            }

            return $definition;
        }
        throw new \RuntimeException("no command $name");
    }

    /**
     * The tokens Schedule::command() puts after the command name (Schedule::compileParameters()).
     *
     * @return string[]
     */
    private static function compile(array $parameters): array
    {
        $tokens = [];
        foreach ($parameters as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $item = (string) $item;
                if (! is_numeric($item) && ! preg_match('/^(-.$|--.*)/i', $item)) {
                    $item = "'" . $item . "'";
                }
                $tokens[] = is_numeric($key) ? $item : "$key=$item";
            }
        }

        return $tokens;
    }

    /**
     * Bind command line tokens as Symfony's input does: known options, values only for options that
     * take one, no more arguments than declared.
     */
    private static function bind(array $tokens, array $definition): void
    {
        $position = 0;
        foreach ($tokens as $token) {
            if (str_starts_with($token, '--')) {
                [$name, $value] = array_pad(explode('=', substr($token, 2), 2), 2, null);
                if (! array_key_exists($name, $definition['options'])) {
                    throw new \RuntimeException("The \"--$name\" option does not exist.");
                }
                if ($value !== null && ! $definition['options'][$name]) {
                    throw new \RuntimeException("The \"--$name\" option does not accept a value.");
                }
                continue;
            }
            $last = end($definition['arguments']);
            if ($position >= count($definition['arguments']) && ! ($last['array'] ?? false)) {
                throw new \RuntimeException("Too many arguments: $token");
            }
            $position++;
        }
    }
}
