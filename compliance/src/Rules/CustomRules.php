<?php

namespace Agoodley\LibrenmsCompliance\Rules;

use Agoodley\LibrenmsCompliance\Config\Line;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The admin's own rules, written in YAML in the settings: a list of rules, each checking the configuration's lines
 * (without their indentation) against regular expressions (Pattern):
 *
 *     - id: http-server-off             # letters, digits, - and _; not one of the plugin's
 *       title: HTTP server off
 *       severity: warning               # critical, warning (the default) or info
 *       os: [ios, iosxe]                # LibreNMS os names (default: every device)
 *       must_not: '^ip http server$'
 *     - id: access-storm-control
 *       title: Storm control on access ports
 *       section: '^interface '          # each section whose first line matches: its lines are checked
 *       when: '^switchport mode access$'  # only the sections with such a line (several: each)
 *       unless: '^shutdown$'            # not the sections with such a line
 *       ports: host                     # of an interface: what it faces (host, switch, upstream, downstream)
 *       must: '^storm-control broadcast level'
 *       fix: storm-control broadcast level 1.00 on each access port
 *
 * Without a section the lines at the left margin are checked (when and unless too). must: each pattern matches a line;
 * must_not: no line matches one. A rule needs one of them.
 */
final class CustomRules
{
    /** Most rules, and most bytes of YAML. */
    public const MAX_RULES = 200;

    public const MAX_BYTES = 65536;

    private const KEYS = ['id', 'title', 'severity', 'description', 'os', 'section', 'ports', 'when', 'unless', 'must', 'must_not', 'fix'];

    private const PATTERN_KEYS = ['when', 'unless', 'must', 'must_not'];

    private const ROLES = [PortRoles::HOST, PortRoles::SWITCH, PortRoles::UPSTREAM, PortRoles::DOWNSTREAM];

    /** Most sections a failure names. */
    private const NAMED = 6;

    /**
     * The rules of a YAML text, and what is wrong with it (rules with an error are left out).
     *
     * @return array{rules: array<string, Rule>, errors: string[]}
     */
    public static function parse(string $yaml): array
    {
        if (trim($yaml) === '') {
            return ['rules' => [], 'errors' => []];
        }
        if (strlen($yaml) > self::MAX_BYTES) {
            return ['rules' => [], 'errors' => ['The rules are longer than ' . number_format(self::MAX_BYTES) . ' bytes.']];
        }
        try {
            $list = Yaml::parse($yaml);
        } catch (ParseException $e) {
            return ['rules' => [], 'errors' => ['The rules are not valid YAML: ' . $e->getMessage()]];
        }
        if (! is_array($list) || ! array_is_list($list)) {
            return ['rules' => [], 'errors' => ['The rules must be a YAML list: each rule starts with "- id: ...".']];
        }
        if (count($list) > self::MAX_RULES) {
            return ['rules' => [], 'errors' => ['At most ' . self::MAX_RULES . ' rules.']];
        }
        $rules = [];
        $errors = [];
        foreach ($list as $index => $entry) {
            $where = 'Rule ' . ($index + 1) . (is_array($entry) && is_string($entry['id'] ?? null) ? ' (' . $entry['id'] . ')' : '');
            $rule = self::rule($entry, $where, $problems);
            if ($rule === null) {
                array_push($errors, ...$problems);
            } elseif (isset($rules[$rule->id])) {
                $errors[] = "$where: another rule has the id {$rule->id}.";
            } else {
                $rules[$rule->id] = $rule;
            }
        }

        return ['rules' => $rules, 'errors' => $errors];
    }

    /**
     * One rule, or null with what is wrong with it.
     *
     * @param  string[]|null  $problems
     */
    private static function rule(mixed $entry, string $where, ?array &$problems): ?Rule
    {
        $problems = [];
        if (! is_array($entry) || array_is_list($entry)) {
            $problems[] = "$where: a rule is a map of keys (id, title, must...).";

            return null;
        }
        foreach (array_diff(array_keys($entry), self::KEYS) as $key) {
            $problems[] = "$where: unknown key $key (the keys are " . implode(', ', self::KEYS) . ').';
        }
        $id = $entry['id'] ?? null;
        if (! is_string($id) || preg_match('~^[a-z0-9][a-z0-9_-]{0,47}$~i', $id) !== 1) {
            $problems[] = "$where: id must be letters, digits, - and _ (at most 48).";
        } elseif (isset(BuiltinRules::RULES[strtolower($id)])) {
            $problems[] = "$where: $id is one of the plugin's rules: choose another id.";
        }
        $title = $entry['title'] ?? null;
        if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 120) {
            $problems[] = "$where: title must be a text of at most 120 characters.";
        }
        $severity = $entry['severity'] ?? Rule::WARNING;
        if (! in_array($severity, Rule::SEVERITIES, true)) {
            $problems[] = "$where: severity must be " . implode(', ', Rule::SEVERITIES) . '.';
        }
        foreach (['description', 'fix'] as $key) {
            if (isset($entry[$key]) && (! is_string($entry[$key]) || mb_strlen($entry[$key]) > 500)) {
                $problems[] = "$where: $key must be a text of at most 500 characters.";
            }
        }
        $os = self::strings($entry['os'] ?? []);
        if ($os === null) {
            $problems[] = "$where: os must be a LibreNMS os name or a list of them.";
        }
        $section = null;
        if (isset($entry['section'])) {
            $section = is_string($entry['section']) ? Pattern::compile($entry['section']) : null;
            if ($section === null) {
                $problems[] = "$where: section must be a regular expression that compiles.";
            }
        }
        $ports = $entry['ports'] ?? null;
        if ($ports !== null && ! in_array($ports, self::ROLES, true)) {
            $problems[] = "$where: ports must be " . implode(', ', self::ROLES) . '.';
        } elseif ($ports !== null && $section === null && ! isset($entry['section'])) {
            $problems[] = "$where: ports needs a section (of interfaces: section: '^interface ').";
        }
        $patterns = [];
        foreach (self::PATTERN_KEYS as $key) {
            $list = self::strings($entry[$key] ?? []);
            if ($list === null) {
                $problems[] = "$where: $key must be a pattern or a list of patterns.";
                continue;
            }
            foreach ($list as $pattern) {
                $regex = Pattern::compile($pattern);
                if ($regex === null) {
                    $problems[] = "$where: $key: the pattern $pattern does not compile.";
                } else {
                    $patterns[$key][$pattern] = $regex;
                }
            }
        }
        if (! isset($entry['must']) && ! isset($entry['must_not'])) {
            $problems[] = "$where: a rule needs must or must_not.";
        }
        if ($problems) {
            return null;
        }

        return new Rule((string) $id, trim((string) $title), (string) $severity, trim((string) ($entry['description'] ?? '')), trim((string) ($entry['fix'] ?? '')),
            true, ['os' => array_map('strtolower', $os), 'section' => $section, 'ports' => $ports, 'when' => $patterns['when'] ?? [],
                'unless' => $patterns['unless'] ?? [], 'must' => $patterns['must'] ?? [], 'must_not' => $patterns['must_not'] ?? []]);
    }

    /**
     * A text or a list of texts as a list; null for anything else.
     *
     * @return string[]|null
     */
    private static function strings(mixed $value): ?array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        foreach ($value as $item) {
            if (! is_string($item) && ! is_int($item)) {
                return null;
            }
        }

        return array_map('strval', $value);
    }

    /**
     * How a rule of the admin's ends on a device.
     */
    public static function evaluate(Rule $rule, Context $context): Outcome
    {
        $spec = $rule->spec;
        if ($spec['os'] && ! in_array(strtolower($context->os), $spec['os'], true)) {
            return Outcome::na('for ' . implode(', ', $spec['os']) . ', not ' . ($context->os ?: 'an unknown os'));
        }
        try {
            if ($spec['section'] === null) {
                $failure = self::check($context->config->top, $spec);
                if ($failure === false) {
                    return Outcome::na('no line matches when, or one matches unless');
                }

                return $failure === null ? Outcome::pass() : Outcome::fail(ucfirst($failure[0]), $failure[1]);
            }
            $checked = 0;
            $failing = [];
            $byProblem = [];
            $lines = [];
            foreach ($context->config->top as $section) {
                if (! Pattern::matches($spec['section'], $section->text)) {
                    continue;
                }
                if ($spec['ports'] !== null && ! self::faces($context, $section, $spec['ports'])) {
                    continue;
                }
                $failure = self::check($section->descendants(), $spec, false);
                if ($failure === false) {
                    continue;
                }
                $checked++;
                if ($failure !== null) {
                    $failing[] = $section;
                    $byProblem[$failure[0]][] = $section->text;
                    $lines[] = $section;
                }
            }
        } catch (PatternFailed $e) {
            return Outcome::error($e->getMessage());
        }
        if ($checked === 0) {
            return Outcome::na('no section matches' . ($spec['when'] || $spec['unless'] || $spec['ports'] !== null ? ' (with when, unless and ports)' : ''));
        }
        if (! $failing) {
            return Outcome::pass();
        }

        // the sections grouped by what is wrong with them: "no line matches X: interface A, interface B and 3 more"
        $problems = [];
        foreach ($byProblem as $problem => $sections) {
            $problems[] = $problem . ': ' . Outcome::list($sections, self::NAMED);
        }

        return Outcome::fail(Outcome::count(count($failing), 'section') . ' of ' . $checked . ' ' . (count($failing) === 1 ? 'fails' : 'fail') . ', '
            . implode('; ', $problems), $lines, array_map(fn (Line $section) => trim((string) preg_replace('~^interface\s+~i', '', $section->text)), $lines), true);
    }

    /**
     * What is wrong with these lines: null when nothing is, false when the rule does not apply to them (when, unless),
     * else what is wrong and the lines that show it. With $numbers the line that must not be names its number (the
     * sections' failures leave it out, to be grouped: the evidence has the lines).
     *
     * @param  Line[]  $lines
     * @return array{0: string, 1: Line[]}|false|null
     *
     * @throws PatternFailed
     */
    private static function check(array $lines, array $spec, bool $numbers = true): array|false|null
    {
        foreach ($spec['when'] as $regex) {
            if (self::first($lines, $regex) === null) {
                return false;
            }
        }
        foreach ($spec['unless'] as $regex) {
            if (self::first($lines, $regex) !== null) {
                return false;
            }
        }
        $problems = [];
        $shown = [];
        foreach ($spec['must'] as $pattern => $regex) {
            if (self::first($lines, $regex) === null) {
                $problems[] = 'no line matches ' . $pattern;
            }
        }
        foreach ($spec['must_not'] as $pattern => $regex) {
            foreach ($lines as $line) {
                if (Pattern::matches($regex, $line->text)) {
                    $problems[] = ($numbers ? 'line ' . $line->number : 'a line') . ' matches ' . $pattern;
                    $shown[] = $line;
                    break;
                }
            }
        }

        return $problems ? [implode('; ', $problems), $shown] : null;
    }

    /**
     * @param  Line[]  $lines
     *
     * @throws PatternFailed
     */
    private static function first(array $lines, string $regex): ?Line
    {
        foreach ($lines as $line) {
            if (Pattern::matches($regex, $line->text)) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Does an interface section face what the rule asks for (switch: any switch)?
     */
    private static function faces(Context $context, Line $section, string $role): bool
    {
        if (preg_match('~^interface\s+(\S+)$~i', $section->text, $m) !== 1 || ! isset($context->ports[$m[1]])) {
            return false;
        }
        $actual = $context->role($m[1]);

        return $role === PortRoles::SWITCH ? in_array($actual, PortRoles::SWITCHES, true) : $actual === $role;
    }
}
