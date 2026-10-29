<?php

namespace Agoodley\LibrenmsAnsible\Runs;

use Agoodley\LibrenmsAnsible\Changes\DiffParser;
use Symfony\Component\Yaml\Yaml;

/**
 * What each task of a run printed for each target, for a person to read and compare: per task, the targets grouped by
 * identical output (the show commands of a diagnostic run: the devices that answer the same, and those that differ).
 * From the log of Ansible's default callback, read as DiffParser reads a dry run's (DiffParser::entries()): a result's
 * details (what Ansible prints for a debug task, with -v, and for a failure: JSON, or YAML with the yaml result format,
 * read with LibreNMS' YAML parser) read for what a person wants to see (a debug task's msg or variable, a command
 * module's stdout_lines, a failure's message, a configuration module's commands), and the diff or text a module printed
 * before a changed result (--diff). Details that cannot be read are shown as Ansible printed them.
 *
 * Device output is untrusted, and where Ansible prints it as it is (a loop item's label, a module's text, a diff) it can
 * imitate Ansible's lines. So a result is shown under its device only when nothing in the log contradicts it, as
 * DiffParser decides for diffs: its host is a target, the task's log names that host once (once per loop item), the
 * PLAY RECAP names the host and counts at least as many of its tasks (and changed tasks) as the log shows; and a block
 * of it only when it cannot hold another result's lines: in a loop, only a unified diff (an item label can hold a
 * newline and imitate the next result line, which would take the details printed after it), and no raw text right after
 * details that another line cut short. JSON details hold device text in escaped strings, which cannot imitate a line.
 * The rest is listed as not attributed, with the host the log names and why. Lines are plain text (ANSI codes and
 * control characters removed), cut to sizes the pages can show.
 */
final class RunOutputs
{
    /** Most lines of one block, most characters of one line, most bytes kept per device and in total. */
    public const BLOCK_LINES = 2000;

    public const LINE_CHARS = 2000;

    public const DEVICE_BYTES = 524288;

    public const TOTAL_BYTES = 4194304;

    /** What stands for the blocks left out past TOTAL_BYTES, and past a device's DEVICE_BYTES. */
    public const TOTAL_CUT = '… (the page keeps 4 MB of the output: the rest is in the run\'s log)';

    public const DEVICE_CUT = '… (more of this device\'s output is in the run\'s log)';

    /**
     * Keys of a result that are not what it shows a person: Ansible's own, a command module's bookkeeping, and those shown
     * otherwise (the output, the commands, the diff). What is left of a debug task's result besides them is the variable
     * it shows (debug: var=...).
     */
    private const BOOKKEEPING = ['changed', 'failed', 'skipped', 'skip_reason', 'false_condition', 'invocation', 'warnings', 'deprecations', 'exception',
        'item', 'ansible_loop_var', 'ansible_index_var', 'ansible_loop', 'attempts', 'retries', 'unreachable', 'diff', 'rc', 'start', 'end', 'delta', 'cmd',
        'msg', 'stdout', 'stdout_lines', 'stderr', 'stderr_lines', 'module_stdout', 'module_stderr', 'commands', 'updates', 'banners'];

    /**
     * Each task's results per device, grouped by identical output, and the results not attributed to a device.
     *
     * @param  string[]  $lines  the run's whole log
     * @param  array<int, string>  $targets  device_id => inventory name
     * @param  array<string, array<string, int>>|null  $recap  RecapParser::parse() of the same log
     * @return array{tasks: array<int, array{task: string, loop: bool, groups: array<int, array{devices: int[], results: array<int, array{result: string, item: ?string, blocks: array<int, array{kind: string, lines: string[], cut: bool}>}>}>}>,
     *               unattributed: array<int, array{task: string, host: string, item: ?string, result: string, blocks: array<int, array{kind: string, lines: string[], cut: bool}>, reason: string}>,
     *               cut: bool}
     */
    public static function parse(array $lines, array $targets, ?array $recap): array
    {
        [$entries, $sections] = DiffParser::entries($lines);
        $deviceOf = array_flip(array_map('strval', $targets));
        // the tasks the log names each host in (with any result), and those with a changed result
        $named = [];
        $changed = [];
        foreach ($entries as $entry) {
            $named[$entry['host']][$entry['section']] = true;
            if ($entry['kind'] === 'changed') {
                $changed[$entry['host']][$entry['section']] = true;
            }
        }
        $results = []; // section => device_id => results in the log's order
        $tasks = []; // section => [task, loop]
        $unattributed = [];
        $bytes = [];
        $total = 0;
        $cut = false;
        foreach ($entries as $entry) {
            $section = $entry['section'];
            $tasks[$section] ??= ['task' => $entry['task'], 'loop' => $sections[$section]['loop']];
            $result = self::result($entry);
            $reason = self::refusal($entry, $sections[$section], $deviceOf, count($named[$entry['host']] ?? []), count($changed[$entry['host']] ?? []), $recap);
            $kept = [];
            $refused = [];
            foreach (self::blocks($entry) as $block) {
                $why = $reason ?? self::blockRefusal($entry, $block, $sections[$section]);
                $block = self::capped($block);
                $size = strlen(implode("\n", $block['lines']));
                if ($total + $size > self::TOTAL_BYTES) {
                    // the page shows what fits, the runner keeps the whole log: what is left out is marked, once
                    if (! $cut) {
                        $cut = true;
                        $marker = ['kind' => 'text', 'lines' => [self::TOTAL_CUT], 'cut' => true];
                        $why === null ? $kept[] = $marker : $refused[] = $marker;
                    }

                    continue;
                }
                if ($why === null) {
                    $deviceId = $deviceOf[$entry['host']];
                    if (($bytes[$deviceId] ?? 0) + $size > self::DEVICE_BYTES) {
                        $block = ['kind' => 'text', 'lines' => [self::DEVICE_CUT], 'cut' => true];
                        $size = 0;
                    }
                    $bytes[$deviceId] = ($bytes[$deviceId] ?? 0) + $size;
                    $kept[] = $block;
                } else {
                    $refused[] = $block + ['reason' => $why];
                }
                $total += $size;
            }
            if ($reason === null) {
                $results[$section][$deviceOf[$entry['host']]][] = ['result' => $result, 'item' => $entry['item'], 'blocks' => $kept];
            }
            // a result not attributed is listed with its blocks; a block of an attributed result that is not (raw text in a loop) on its own
            if ($reason !== null || $refused) {
                $byReason = [];
                foreach ($refused as $block) {
                    $byReason[$block['reason'] ?? (string) $reason][] = ['kind' => $block['kind'], 'lines' => $block['lines'], 'cut' => $block['cut']];
                }
                if ($reason !== null && ! $byReason) {
                    $byReason[$reason] = [];
                }
                foreach ($byReason as $why => $blocks) {
                    $unattributed[] = ['task' => $entry['task'], 'host' => mb_substr($entry['host'], 0, 255), 'item' => $entry['item'], 'result' => $result,
                        'blocks' => $blocks, 'reason' => (string) $why];
                }
            }
        }

        $grouped = [];
        foreach ($results as $section => $byDevice) {
            $grouped[] = $tasks[$section] + ['groups' => self::groups($byDevice, array_keys($targets))];
        }

        return ['tasks' => $grouped, 'unattributed' => $unattributed, 'cut' => $cut];
    }

    /**
     * The devices of a task grouped by identical results: the largest group first, then in the targets' order.
     *
     * @param  array<int, array<int, array>>  $byDevice  device_id => its results in the task
     * @param  int[]  $order  the targets' device ids in their order
     * @return array<int, array{devices: int[], results: array}>
     */
    private static function groups(array $byDevice, array $order): array
    {
        $position = array_flip($order);
        $groups = [];
        foreach ($byDevice as $deviceId => $results) {
            $key = hash('sha256', (string) json_encode($results, JSON_INVALID_UTF8_SUBSTITUTE));
            $groups[$key] ??= ['devices' => [], 'results' => $results];
            $groups[$key]['devices'][] = $deviceId;
        }
        foreach ($groups as &$group) {
            usort($group['devices'], fn (int $a, int $b) => ($position[$a] ?? PHP_INT_MAX) <=> ($position[$b] ?? PHP_INT_MAX));
        }
        unset($group);
        $groups = array_values($groups);
        usort($groups, fn (array $a, array $b) => [count($b['devices']), $position[$a['devices'][0]] ?? PHP_INT_MAX]
            <=> [count($a['devices']), $position[$b['devices'][0]] ?? PHP_INT_MAX]);

        return $groups;
    }

    /**
     * The result of an entry as the pages name it: ok, changed, skipped, failed or unreachable.
     */
    private static function result(array $entry): string
    {
        return match ($entry['kind']) {
            'skipping' => 'skipped',
            'fatal', 'failed' => str_starts_with(ltrim($entry['rest']), ': UNREACHABLE!') ? 'unreachable' : 'failed',
            default => $entry['kind'],
        };
    }

    /**
     * Why none of a result may be shown under its host's device; null when it may.
     *
     * @param  array{loop: bool, ambiguous: ?string}  $section
     * @param  array<string, int>  $deviceOf  inventory name => device_id
     * @param  int  $namedTasks  the tasks the log names the host in
     * @param  int  $changedTasks  those with a changed result of it
     * @param  array<string, array<string, int>>|null  $recap
     */
    private static function refusal(array $entry, array $section, array $deviceOf, int $namedTasks, int $changedTasks, ?array $recap): ?string
    {
        if (! isset($deviceOf[$entry['host']])) {
            return 'it names a host that is not a target of the run';
        }
        if ($section['ambiguous'] !== null) {
            return $section['ambiguous'];
        }
        if ($recap === null || ! isset($recap[$entry['host']])) {
            return 'the PLAY RECAP does not name this host';
        }
        $counts = $recap[$entry['host']];
        // Ansible counts one result per task and host (a changed one as ok too, an ignored failure as ok, a rescued one as rescued)
        $counted = array_sum(array_map(fn (string $counter) => (int) ($counts[$counter] ?? 0), ['ok', 'failed', 'skipped', 'unreachable', 'rescued', 'ignored']));
        if ($namedTasks > $counted || $changedTasks > (int) ($counts['changed'] ?? 0)) {
            return 'the log names this host in more tasks than the PLAY RECAP counts: device output may imitate Ansible\'s result lines';
        }

        return null;
    }

    /**
     * Why a block of a result that may be shown is not shown under its device; null when it may.
     *
     * @param  array{kind: string, lines: string[]}  $block
     * @param  array{loop: bool, ambiguous: ?string}  $section
     */
    private static function blockRefusal(array $entry, array $block, array $section): ?string
    {
        if ($section['loop'] && $block['kind'] !== 'diff') {
            return $block['kind'] === 'text'
                ? 'it is text a module printed as it is, in a loop: it could imitate Ansible\'s result lines'
                : 'it was printed after a loop item, whose label could imitate the next result line';
        }
        if ($entry['cut'] && $block['kind'] === 'text') {
            return 'it comes right after the details of another result, cut short by a line Ansible does not print with them: it may hold the rest of those details';
        }

        return null;
    }

    /**
     * The blocks of a result: the diff or text printed right before a changed result (--diff), and what its details show
     * (output: a debug task's msg or variable, a command module's stdout_lines, a failure's message; commands: what a
     * configuration module sends; yaml: details in the yaml result format, as they are).
     *
     * @return array<int, array{kind: string, lines: string[]}>
     */
    private static function blocks(array $entry): array
    {
        $blocks = [];
        if ($entry['kind'] === 'changed') {
            $lines = self::trimmed(array_map(fn (string $line) => self::clean($line), $entry['block']));
            if ($lines) {
                $blocks[] = ['kind' => DiffParser::isUnifiedDiff($lines) ? 'diff' : 'text', 'lines' => $lines];
            }
        }
        // "fatal: [host]: FAILED! => {...}", "ok: [host] => {...}": the details' JSON follows "=> "
        $rest = trim((string) preg_replace('/^\s*:\s*(?:FAILED|UNREACHABLE)!/', '', $entry['rest']));
        if (str_starts_with($rest, '=> {')) {
            $text = substr($rest, 3);
            if ($text === '{') {
                $text .= "\n" . implode("\n", $entry['details']);
            }
            $json = json_decode($text, true, 64, JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_array($json)) {
                return array_merge($blocks, self::shown($json));
            }
            $lines = self::trimmed(array_map(fn (string $line) => self::clean($line), explode("\n", $text)));

            return $lines ? array_merge($blocks, [['kind' => 'output', 'lines' => $lines]]) : $blocks;
        }
        if ($rest === '=>' && $entry['details']) {
            $lines = self::trimmed(self::dedented(array_map(fn (string $line) => self::clean($line), $entry['details'])));
            $yaml = $lines ? self::yaml($lines) : null;
            if ($yaml !== null) {
                return array_merge($blocks, self::shown($yaml));
            }
            if ($lines) {
                $blocks[] = ['kind' => 'yaml', 'lines' => $lines];
            }
        }

        return $blocks;
    }

    /**
     * Details in the yaml result format as a result's keys, null when they cannot be read (no YAML parser, a tag).
     * Symfony's parser makes no objects and refuses tags unless asked to.
     *
     * @param  string[]  $lines
     */
    private static function yaml(array $lines): ?array
    {
        if (! class_exists(Yaml::class)) {
            return null;
        }
        try {
            $value = Yaml::parse(implode("\n", $lines));
        } catch (\Throwable) {
            return null;
        }

        return is_array($value) && ! array_is_list($value) ? $value : null;
    }

    /**
     * What a result's JSON shows a person: its output (msg, else stdout_lines, else stdout, else the variable a debug task
     * shows, else what is left besides the bookkeeping), a failure's stderr, and the commands of a configuration module.
     *
     * @return array<int, array{kind: string, lines: string[]}>
     */
    private static function shown(array $json): array
    {
        $blocks = [];
        $value = null;
        foreach (['msg', 'stdout_lines', 'stdout'] as $key) {
            if (array_key_exists($key, $json) && $json[$key] !== '' && $json[$key] !== []) {
                $value = $json[$key];
                break;
            }
        }
        if ($value === null) {
            $rest = array_filter(array_diff_key($json, array_flip(self::BOOKKEEPING)), fn ($key) => ! str_starts_with((string) $key, '_ansible'), ARRAY_FILTER_USE_KEY);
            $value = count($rest) === 1 ? reset($rest) : ($rest ?: null);
        }
        if ($value !== null) {
            $blocks[] = ['kind' => 'output', 'lines' => self::lines($value)];
        }
        foreach (['stderr_lines', 'stderr', 'module_stderr'] as $key) {
            if (! empty($json['failed']) && isset($json[$key]) && $json[$key] !== '' && $json[$key] !== []) {
                $blocks[] = ['kind' => 'output', 'lines' => self::lines($json[$key])];
                break;
            }
        }
        foreach (['commands', 'updates'] as $key) {
            if (is_array($json[$key] ?? null) && array_is_list($json[$key]) && $json[$key]) {
                $blocks[] = ['kind' => 'commands', 'lines' => self::lines($json[$key])];
                break;
            }
        }

        return array_values(array_filter(array_map(fn (array $block) => ['kind' => $block['kind'], 'lines' => self::trimmed($block['lines'])], $blocks),
            fn (array $block) => $block['lines'] !== []));
    }

    /**
     * A value as lines: text line by line, a list item by item (a blank line between two lists: the outputs of two
     * commands in stdout_lines), a map of text (a command's answer per command) as each key underlined over its text,
     * anything else as indented JSON.
     *
     * @return string[]
     */
    private static function lines(mixed $value): array
    {
        if (is_array($value) && $value && ! array_is_list($value) && self::textual($value)) {
            $lines = [];
            foreach ($value as $key => $text) {
                $title = self::clean((string) $key);
                array_push($lines, ...($lines ? [''] : []), ...[$title, str_repeat('-', min(120, max(3, mb_strlen($title))))], ...self::lines($text));
            }

            return $lines;
        }
        if (is_string($value)) {
            return array_map(fn (string $line) => self::clean($line), preg_split('/\r\n|\r|\n/', $value) ?: []);
        }
        if (is_bool($value)) {
            return [$value ? 'true' : 'false'];
        }
        if ($value === null || is_int($value) || is_float($value)) {
            return [$value === null ? 'null' : (string) $value];
        }
        if (is_array($value) && array_is_list($value)) {
            $lines = [];
            foreach ($value as $item) {
                if (is_array($item) && array_is_list($item) && $lines) {
                    $lines[] = '';
                }
                array_push($lines, ...self::lines($item));
            }

            return $lines;
        }
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return array_map(fn (string $line) => self::clean($line), explode("\n", (string) $json));
    }

    /**
     * Is the value text all through: a string, or a list of strings and lists of strings (a map's values, for lines())?
     */
    private static function textual(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item) ? ! array_is_list($item) || ! self::textual($item) : ! is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A line as the pages show it: valid UTF-8, without ANSI codes, control and format characters (bidi overrides
     * among them), tabs as spaces.
     */
    private static function clean(string $line): string
    {
        $line = Ansi::strip(mb_scrub($line, 'UTF-8'));

        return rtrim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', str_replace("\t", '    ', $line)));
    }

    /**
     * Lines without the indentation they all share.
     *
     * @param  string[]  $lines
     * @return string[]
     */
    private static function dedented(array $lines): array
    {
        $indent = null;
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $indent = min($indent ?? PHP_INT_MAX, strlen($line) - strlen(ltrim($line, ' ')));
            }
        }

        return $indent ? array_map(fn (string $line) => (string) substr($line, min($indent, strlen($line))), $lines) : $lines;
    }

    /**
     * Lines without the blank ones at the start and the end.
     *
     * @param  string[]  $lines
     * @return string[]
     */
    private static function trimmed(array $lines): array
    {
        while ($lines && trim((string) reset($lines)) === '') {
            array_shift($lines);
        }
        while ($lines && trim((string) end($lines)) === '') {
            array_pop($lines);
        }

        return array_values($lines);
    }

    /**
     * A block cut to the sizes the pages show.
     *
     * @param  array{kind: string, lines: string[]}  $block
     * @return array{kind: string, lines: string[], cut: bool}
     */
    private static function capped(array $block): array
    {
        $cut = count($block['lines']) > self::BLOCK_LINES;
        $lines = [];
        foreach (array_slice($block['lines'], 0, self::BLOCK_LINES) as $line) {
            if (mb_strlen($line) > self::LINE_CHARS) {
                $line = mb_substr($line, 0, self::LINE_CHARS) . ' …';
                $cut = true;
            }
            $lines[] = $line;
        }
        if (count($block['lines']) > self::BLOCK_LINES) {
            $lines[] = '… (' . number_format(count($block['lines']) - self::BLOCK_LINES) . ' more lines in the run\'s log)';
        }

        return ['kind' => $block['kind'], 'lines' => $lines, 'cut' => $cut];
    }
}
