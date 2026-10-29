<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * What a dry run would change on each device, from the log of Ansible's default callback run with --check --diff.
 *
 * The callback prints a result's diff right before the result's line ("changed: [host]", in a loop "changed: [host]
 * => (item=x)"), and the result's details (with -v, and always for a failure) below a line that ends with "=>":
 * indented YAML lines (blank ones inside), or JSON lines up to a "}". So a block of lines belongs to the "changed" line
 * right after it, and the lines right after a result line ending with "=>" are its details (where the "commands" of
 * cisco.ios.ios_config are). Ansible's own messages (warnings, deprecations, errors) go to stderr, which the runner
 * merges with stdout line by line: they can land anywhere, also between the lines of a result's details, and are
 * skipped. Diffs hold device text, which can imitate Ansible's lines where Ansible prints it as it is (a loop item
 * label with a newline, a module's "prepared" diff text): every line of a unified diff ("--- before", "+++ after",
 * "@@", then " ", "+" or "-") starts with a prefix device text cannot avoid, the rest can. A block is attributed to its
 * device only when nothing in the log contradicts it:
 *
 * - its host is a target of the run, and the task's log names that host once (once per loop item, and not both with
 *   and without an item);
 * - the host does not have more "changed" tasks in the log than the PLAY RECAP counts for it (a line imitated by
 *   device output adds one);
 * - in a loop, only unified diffs: an item label can hold a newline and imitate the next result line, which would take
 *   the details printed after it, and raw text could hide one;
 * - raw text right after a result's details that a line of another kind cut short (a message Ansible printed over
 *   several lines, for example) is not attributed: it may hold the rest of those details, which belong to another
 *   result (a unified diff there is: its lines cannot be details).
 *
 * Everything else is listed as not attributed, with the reason. The results themselves come from the PLAY RECAP, never
 * from here. Lines are plain text (ANSI codes and control characters removed), cut to sizes the pages can show.
 */
final class DiffParser
{
    /** Most lines of one block, most characters of one line, most bytes kept per device and in total. */
    public const BLOCK_LINES = 400;

    public const LINE_CHARS = 1000;

    public const DEVICE_BYTES = 131072;

    public const TOTAL_BYTES = 1048576;

    /** What stands for the blocks of a device, or the blocks not attributed, past TOTAL_BYTES. */
    public const TOTAL_CUT = '… (the page keeps 1 MB of the dry run\'s diffs: the rest is in the run\'s log)';

    /**
     * The blocks per device, and those not attributed.
     *
     * @param  string[]  $lines  the run's whole log
     * @param  array<int, string>  $targets  device_id => inventory name
     * @param  array<string, array<string, int>>|null  $recap  RecapParser::parse() of the same log
     * @return array{devices: array<int, array<int, array{task: string, item: ?string, kind: string, lines: string[], cut: bool}>>,
     *               unattributed: array<int, array{task: string, item: ?string, host: string, kind: string, lines: string[], cut: bool, reason: string}>,
     *               changed: array<int, int>}
     */
    public static function parse(array $lines, array $targets, ?array $recap): array
    {
        [$entries, $sections] = self::entries($lines);
        $deviceOf = array_flip(array_map('strval', $targets));
        $changedSections = [];
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'changed') {
                $changedSections[$entry['host']][$entry['section']] = true;
            }
        }
        $devices = [];
        $unattributed = [];
        $bytes = [];
        $total = 0;
        $over = [];
        foreach ($entries as $entry) {
            if ($entry['kind'] !== 'changed') {
                continue;
            }
            foreach (self::blocksOf($entry) as $block) {
                $reason = self::refusal($entry, $block, $sections[$entry['section']], $deviceOf, count($changedSections[$entry['host']] ?? []), $recap);
                $block = self::capped($block);
                $size = strlen(implode("\n", $block['lines']));
                if ($total + $size > self::TOTAL_BYTES) {
                    // the pages show what fits, the runner keeps the whole log: what is left out is marked, once per device (and
                    // once for the blocks not attributed), so a device never reads as one whose module printed no diff
                    $deviceId = $reason === null ? $deviceOf[$entry['host']] : null;
                    if (! isset($over[$deviceId ?? 'unattributed'])) {
                        $over[$deviceId ?? 'unattributed'] = true;
                        $marker = ['task' => $entry['task'], 'item' => $entry['item'], 'kind' => $block['kind'], 'lines' => [self::TOTAL_CUT], 'cut' => true];
                        if ($deviceId !== null) {
                            $devices[$deviceId][] = $marker;
                        } else {
                            $unattributed[] = $marker + ['host' => $entry['host'], 'reason' => 'the page keeps 1 MB of the dry run\'s diffs'];
                        }
                    }

                    continue;
                }
                if ($reason === null) {
                    $deviceId = $deviceOf[$entry['host']];
                    if (($bytes[$deviceId] ?? 0) + $size > self::DEVICE_BYTES) {
                        $block = ['kind' => $block['kind'], 'lines' => ['… (more of this device\'s diff is in the run\'s log)'], 'cut' => true];
                        $size = 0;
                        if (! empty($devices[$deviceId]) && end($devices[$deviceId])['lines'] === $block['lines']) {
                            continue;
                        }
                    }
                    $devices[$deviceId][] = ['task' => $entry['task'], 'item' => $entry['item']] + $block;
                    $bytes[$deviceId] = ($bytes[$deviceId] ?? 0) + $size;
                } else {
                    $unattributed[] = ['task' => $entry['task'], 'item' => $entry['item'], 'host' => $entry['host']] + $block + ['reason' => $reason];
                }
                $total += $size;
            }
        }
        $changed = [];
        foreach ($targets as $deviceId => $name) {
            $changed[$deviceId] = count($changedSections[(string) $name] ?? []);
        }

        return ['devices' => $devices, 'unattributed' => $unattributed, 'changed' => $changed];
    }

    /**
     * Why a block may not be shown under its host's device; null when it may.
     *
     * @param  array{kind: string, lines: string[]}  $block
     * @param  array{loop: bool, ambiguous: ?string}  $section
     * @param  array<string, int>  $deviceOf  inventory name => device_id
     * @param  array<string, array<string, int>>|null  $recap
     */
    private static function refusal(array $entry, array $block, array $section, array $deviceOf, int $changedTasks, ?array $recap): ?string
    {
        if (! isset($deviceOf[$entry['host']])) {
            return 'it names a host that is not a target of the dry run';
        }
        if ($section['ambiguous'] !== null) {
            return $section['ambiguous'];
        }
        if ($recap === null || ! isset($recap[$entry['host']])) {
            return 'the PLAY RECAP does not name this host';
        }
        if ($changedTasks > (int) ($recap[$entry['host']]['changed'] ?? 0)) {
            return 'the log has more changed tasks for this host than the PLAY RECAP counts: device output may imitate Ansible\'s result lines';
        }
        if ($section['loop'] && $block['kind'] !== 'diff') {
            return $block['kind'] === 'commands'
                ? 'it was printed after a loop item, whose label could imitate the next result line'
                : 'it is text a module printed as it is, in a loop: it could imitate Ansible\'s result lines';
        }
        if ($entry['cut'] && $block['kind'] === 'text') {
            return 'it comes right after the details of another result, cut short by a line Ansible does not print with them: '
                . 'it may hold the rest of those details';
        }

        return null;
    }

    /**
     * The result lines of the log, each with the block before it and its details, and per task whether it is a loop
     * and why its log is ambiguous. The output of a run per device (Runs\RunOutputs) reads the log so too.
     *
     * @param  string[]  $lines
     * @return array{0: array<int, array{section: int, task: string, kind: string, host: string, item: ?string, block: string[], details: string[], rest: string, cut: bool}>,
     *               1: array<int, array{loop: bool, ambiguous: ?string}>}
     */
    public static function entries(array $lines): array
    {
        $entries = [];
        $sections = [0 => ['loop' => false, 'ambiguous' => null]];
        $section = 0;
        $task = '';
        $pending = [];
        $details = null; // index of the entry whose details follow
        $json = false; // they are JSON, up to a "}"
        $cut = false; // the pending lines started where another line cut a result's details short
        $seen = []; // section => host => item key => true
        foreach ($lines as $line) {
            $plain = AnsibleLog::plain($line);
            if ($details !== null) {
                if ($plain === '' || $plain[0] === ' ' || ($json && $plain === '}')) {
                    $entries[$details]['details'][] = $plain;
                    if ($json && $plain === '}') {
                        $details = null;
                    }

                    continue;
                }
                if (AnsibleLog::message($plain)) {
                    continue; // stderr, merged line by line into the log
                }
                $details = null;
                $cut = AnsibleLog::banner($plain) === null && AnsibleLog::result($plain) === null
                    && ! AnsibleLog::ignoring($plain) && AnsibleLog::included($plain) === null;
            }
            $banner = AnsibleLog::banner($plain);
            if ($banner !== null) {
                $pending = [];
                $cut = false;
                if (in_array($banner['kind'], AnsibleLog::MARKED_KINDS, true)) {
                    $section++;
                    $sections[$section] = ['loop' => false, 'ambiguous' => null];
                    $task = $banner['kind'] === 'play' ? '' : $banner['name'];
                }

                continue;
            }
            $result = AnsibleLog::result($plain);
            if ($result !== null) {
                $host = $result['host'];
                $key = $result['item'] === null ? "\0" : 'i:' . $result['item'];
                if ($result['item'] !== null) {
                    $sections[$section]['loop'] = true;
                }
                $hostKeys = $seen[$section][$host] ?? [];
                if (isset($hostKeys[$key])) {
                    $sections[$section]['ambiguous'] ??= 'the log of this task names ' . mb_substr($host, 0, 80) . ' twice'
                        . ($result['item'] !== null ? ' for one loop item' : '') . ': device output may imitate Ansible\'s result lines';
                } elseif ($hostKeys && (isset($hostKeys["\0"]) || $key === "\0")) {
                    $sections[$section]['ambiguous'] ??= 'the log of this task names ' . mb_substr($host, 0, 80) . ' with and without a loop item: '
                        . 'device output may imitate Ansible\'s result lines';
                }
                $seen[$section][$host][$key] = true;
                $entries[] = ['section' => $section, 'task' => $task, 'kind' => $result['kind'], 'host' => $host, 'item' => $result['item'],
                    'block' => $pending, 'details' => [], 'rest' => $result['rest'], 'cut' => $cut];
                $pending = [];
                $cut = false;
                // the details follow on the next lines when the line ends with "=>" (YAML) or "=> {" (JSON over several lines)
                $tail = rtrim($result['rest']);
                $json = str_ends_with($tail, '=> {');
                $details = $json || str_ends_with($tail, '=>') ? count($entries) - 1 : null;

                continue;
            }
            if (AnsibleLog::message($plain) || AnsibleLog::ignoring($plain) || AnsibleLog::included($plain) !== null) {
                continue;
            }
            $pending[] = $plain;
        }

        return [$entries, $sections];
    }

    /**
     * The blocks of a changed result: the lines before its line (a unified diff, or a module's own text), and the
     * configuration commands in its details (cisco.ios.ios_config and the resource modules return them; Ansible shows
     * them with -v).
     *
     * @return array<int, array{kind: string, lines: string[]}>
     */
    private static function blocksOf(array $entry): array
    {
        $blocks = [];
        $lines = self::trimmed($entry['block']);
        if ($lines) {
            $blocks[] = ['kind' => self::isUnifiedDiff($lines) ? 'diff' : 'text', 'lines' => $lines];
        }
        $commands = self::commands($entry['rest'], $entry['details']);
        if ($commands) {
            $blocks[] = ['kind' => 'commands', 'lines' => $commands];
        }

        return $blocks;
    }

    /**
     * Is it a unified diff as Ansible prints one: a "--- " and a "+++ " header, then hunks whose every line starts with
     * "@@", " ", "+", "-" or "\" (several diffs may follow each other)?
     *
     * @param  string[]  $lines
     */
    public static function isUnifiedDiff(array $lines): bool
    {
        $lines = array_values(array_filter($lines, fn ($line) => $line !== ''));
        if (count($lines) < 3 || ! str_starts_with($lines[0], '--- ') || ! str_starts_with($lines[1], '+++ ')) {
            return false;
        }
        $hunk = false;
        foreach ($lines as $line) {
            if (str_starts_with($line, '@@')) {
                $hunk = true;
            } elseif (! preg_match('/^[ +\\\\-]/', $line)) {
                return false;
            }
        }

        return $hunk;
    }

    /**
     * The "commands" (else "updates") list of a result's details: YAML lines below the result line ("    commands:" then
     * "    - interface Fa0/1"), or the JSON after "=> " (on the same line, or below it up to a "}").
     *
     * @param  string[]  $details
     * @return string[]
     */
    private static function commands(string $rest, array $details): array
    {
        $rest = trim($rest);
        if (str_starts_with($rest, '=> {')) {
            $text = substr($rest, 3);
            if ($text === '{') {
                $text .= "\n" . implode("\n", $details);
            }
            $json = json_decode($text, true);
            foreach (['commands', 'updates'] as $key) {
                if (is_array($json) && is_array($json[$key] ?? null) && array_is_list($json[$key])) {
                    return array_values(array_map(fn ($c) => is_scalar($c) ? (string) $c : json_encode($c), $json[$key]));
                }
            }

            return [];
        }
        foreach (['commands', 'updates'] as $key) {
            $found = self::yamlList($details, $key);
            if ($found) {
                return $found;
            }
        }

        return [];
    }

    /**
     * A list of scalars under a key at the details' first level (the indentation of their first line), as Ansible's
     * YAML callback format prints them: the items at the key's indentation ("- x"), a long one folded onto the next
     * lines (indented two more). Deeper keys are not read: text a device returned sits there.
     *
     * @param  string[]  $details
     * @return string[]
     */
    private static function yamlList(array $details, string $key): array
    {
        $first = current(array_filter($details, fn ($line) => $line !== ''));
        if ($first === false) {
            return [];
        }
        $indent = substr($first, 0, strlen($first) - strlen(ltrim($first, ' ')));
        $items = [];
        $inside = false;
        foreach ($details as $line) {
            if (! $inside) {
                $inside = $line === $indent . $key . ':';

                continue;
            }
            if (str_starts_with($line, $indent . '- ')) {
                $items[] = substr($line, strlen($indent) + 2);
            } elseif ($items && str_starts_with($line, $indent . '  ')) {
                $items[count($items) - 1] .= ' ' . ltrim($line, ' ');
            } else {
                break;
            }
        }

        return array_map(fn ($item) => self::unquote($item), $items);
    }

    /**
     * A YAML scalar without its quotes.
     */
    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $decoded = json_decode($value);

            return is_string($decoded) ? $decoded : substr($value, 1, -1);
        }

        return $value;
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

        return ['kind' => $block['kind'], 'lines' => $lines, 'cut' => $cut];
    }
}
