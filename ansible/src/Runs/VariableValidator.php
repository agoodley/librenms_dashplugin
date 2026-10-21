<?php

namespace Agoodley\LibrenmsAnsible\Runs;

use Agoodley\LibrenmsAnsible\Runner\SurveyVar;

/**
 * Checks the answers to a template's survey variables on the server: only those variables are sent (no
 * free-form extra variables), each by its type. Ansible renders every string it is given as a Jinja template,
 * so no answer may hold a Jinja delimiter; none may hold control characters, and only multi-line text may hold
 * newlines. Secret answers are returned apart: they go to the runner only.
 */
final class VariableValidator
{
    /** Longest answers, in characters. */
    public const STRING_MAX = 1000;

    public const TEXT_MAX = 10000;

    /** Jinja's delimiters: variable, statement and comment. */
    private const JINJA = ['{{', '}}', '{%', '%}', '{#', '#}'];

    /**
     * The answers to send ("values", an empty optional answer left out), the secret ones apart ("secrets") and
     * what is wrong with the others ("errors", name => text).
     *
     * @param  SurveyVar[]  $vars  the template's survey variables
     * @param  array<string, mixed>  $input  posted answers, name => value; other names are ignored
     * @return array{values: array<string, string|int>, secrets: array<string, string>, errors: array<string, string>}
     */
    public static function validate(array $vars, array $input): array
    {
        $values = [];
        $secrets = [];
        $errors = [];
        foreach ($vars as $var) {
            $raw = $input[$var->name] ?? null;
            if ($raw !== null && ! is_string($raw) && ! is_int($raw)) {
                $errors[$var->name] = 'must be a single value';

                continue;
            }
            $value = $raw === null ? '' : (string) $raw;
            if ($var->type !== 'text' && $var->type !== 'secret') {
                $value = trim($value);
            }
            if ($value === '') {
                if ($var->required) {
                    $errors[$var->name] = 'is required';
                }

                continue;
            }
            [$checked, $error] = self::check($var, $value);
            if ($error !== null) {
                $errors[$var->name] = $error;
            } elseif ($var->isSecret()) {
                $secrets[$var->name] = $checked;
            } else {
                $values[$var->name] = $checked;
            }
        }

        return ['values' => $values, 'secrets' => $secrets, 'errors' => $errors];
    }

    /**
     * One answer: the value to send and null, or null and what is wrong.
     *
     * @return array{0: string|int|null, 1: string|null}
     */
    private static function check(SurveyVar $var, string $value): array
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return [null, 'is not valid UTF-8 text'];
        }

        return match ($var->type) {
            'int' => preg_match('/^-?\d{1,18}$/D', $value) ? [(int) $value, null] : [null, 'must be a whole number'],
            'enum' => in_array($value, array_column($var->values, 'value'), true)
                ? [$value, null] : [null, 'must be one of: ' . implode(', ', array_column($var->values, 'value'))],
            'text' => self::text(str_replace("\r\n", "\n", $value), true, self::TEXT_MAX),
            'string', 'secret' => self::text($value, false, self::STRING_MAX),
            default => [null, 'has a type the plugin does not know'],
        };
    }

    /**
     * Free text: no control or format characters (newlines only in multi-line text), no Jinja delimiters, at
     * most $max characters.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function text(string $value, bool $multiLine, int $max): array
    {
        $checked = $multiLine ? str_replace("\n", '', $value) : $value;
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $checked)) {
            return [null, $multiLine ? 'may not contain control characters (tabs included) other than new lines'
                : 'may not contain new lines, tabs or other control characters'];
        }
        foreach (self::JINJA as $delimiter) {
            if (str_contains($value, $delimiter)) {
                return [null, 'may not contain Jinja delimiters ({{ }}, {% %}, {# #}): Ansible would run them as a template'];
            }
        }
        if (mb_strlen($value) > $max) {
            return [null, "is longer than $max characters"];
        }

        return [$value, null];
    }
}
