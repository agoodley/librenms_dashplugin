<?php

namespace Agoodley\LibrenmsAnsible\Runs;

use Agoodley\LibrenmsAnsible\Runner\SurveyVar;

/**
 * Checks the answers to a template's survey variables on the server: only those variables are sent (no
 * free-form extra variables), each by its type. Ansible renders every string it is given as a Jinja template,
 * so no answer may hold a Jinja delimiter; none may hold control characters, and only multi-line text may hold
 * newlines, in lines that do not read like Ansible's own output. Secret answers are returned apart: they go to the
 * runner only.
 */
final class VariableValidator
{
    /** Longest answers, in characters. */
    public const STRING_MAX = 1000;

    public const TEXT_MAX = 10000;

    /** Jinja's delimiters: variable, statement and comment. */
    private const JINJA = ['{{', '}}', '{%', '%}', '{#', '#}'];

    /**
     * A line the plugin reads as Ansible's own in a run's log (Changes\AnsibleLog, RecapParser, DiffParser, TaskLines), in
     * the shapes the parsers read: a PLAY RECAP or DRY RUN banner, a task result ("ok: [host]", "changed: [host -> x]"), an
     * include, an ignored failure, a recap's host line, a "@@" hunk of a unified diff (and its "+++" header after a "---"
     * one: DIFF_OLD, DIFF_NEW). Ansible prints a
     * loop item's label as it is, so a line of multi-line text a playbook loops over starts a line of the log; in any case
     * (Unicode's too, as a playbook's lower or upper filter folds it).
     */
    private const LOG_LINE = '/^(?:PLAY RECAP(?: \*|\s*$)|DRY RUN \*|(?:ok|changed|skipping|failed|fatal): \[[^\]\s]+(?: -> [^\]]*)?\]'
        . '|included: .+ for [^\s,]+(?:,\s*[^\s,]+)*(?: => \(item=.*)?\s*$|\.\.\.ignoring\s*$|\S+\s+:\s+ok=\d|@@)/iu';

    /** The two header lines of a unified diff, "--- " then "+++ " (blank lines between them are skipped, as the diff parser does). */
    private const DIFF_OLD = '/^--- /u';

    private const DIFF_NEW = '/^\+\+\+ /u';

    /**
     * The start of a play's, task's or handler's banner, as the parsers read it (in its case: one that a case filter makes
     * reads as a banner without the [CHECK MODE] marker, which leaves the dry run not proven, the safe side).
     */
    private const BANNER_LINE = '/^(?:PLAY|TASK|RUNNING HANDLER) \[/u';

    /**
     * Ansible's marker of a banner in a dry run: an answer in a task's name could make the banner of a task run for real
     * read as one. Also split over answers a task's name puts side by side: "check mode" in any answer, and an answer that
     * ends with the marker's start ("[", "[C", ... "[CHECK MOD").
     */
    private const MARKER = '/\[CHECK MODE|CHECK MODE|\[(?:C(?:H(?:E(?:C(?:K(?: (?:M(?:O(?:D)?)?)?)?)?)?)?)?)?$/iuD';

    /**
     * The answers to send ("values", an empty optional answer left out), the secret ones apart ("secrets") and
     * what is wrong with the others ("errors", name => text).
     *
     * @param  SurveyVar[]  $vars  the template's survey variables
     * @param  array<string, mixed>  $input  posted answers, name => value; other names are ignored
     * @return array{values: array<string, string|int>, secrets: array<string, string>, errors: array<string, string>}
     */
    public static function validate(array $vars, #[\SensitiveParameter] array $input): array
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
    private static function check(SurveyVar $var, #[\SensitiveParameter] string $value): array
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
     * most $max characters, no "[CHECK MODE" (MARKER); in multi-line text, no line that reads like Ansible's own output
     * (LOG_LINE, BANNER_LINE), which could make the run's log say what Ansible did not (another host's result or diff, a
     * PLAY RECAP).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function text(#[\SensitiveParameter] string $value, bool $multiLine, int $max): array
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
        if (preg_match(self::MARKER, $value)) {
            return [null, 'may not contain "check mode", nor end with "[" or the start of "[CHECK MODE": Ansible prints an answer in a task\'s name as it is, '
                . 'where it would read as the marker of a dry run'];
        }
        if ($multiLine) {
            $previous = ''; // the last line that is not blank
            foreach (explode("\n", $value) as $number => $line) {
                $diff = preg_match(self::DIFF_NEW, $line) && preg_match(self::DIFF_OLD, $previous);
                $previous = trim($line) === '' ? $previous : $line;
                if ($diff || preg_match(self::LOG_LINE, $line) || preg_match(self::BANNER_LINE, $line)) {
                    return [null, 'may not have a line that starts like a line of Ansible\'s own output (a task result, a banner, a PLAY RECAP line, '
                        . 'a diff header): line ' . ($number + 1) . ' does, and a loop could print it into the run\'s log as it is'];
                }
            }
        }
        if (mb_strlen($value) > $max) {
            return [null, "is longer than $max characters"];
        }

        return [$value, null];
    }
}
