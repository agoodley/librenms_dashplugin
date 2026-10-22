<?php

namespace Agoodley\LibrenmsAnsible\Runs;

/**
 * What each host did according to Ansible's task result lines, for a run without a PLAY RECAP (stopped, killed,
 * lost): "ok: [host]", "changed: [host]", "skipping: [host]", "failed: [host]", "fatal: [host]: FAILED!" and
 * "fatal: [host]: UNREACHABLE!", at the start of a line (Ansible's callbacks indent device output; delegation
 * reads "[host -> other]"). A failure followed by "...ignoring" does not count.
 */
final class TaskLines
{
    private const LINE = '/^(ok|changed|skipping|failed|fatal): \[([^\]\s]+)(?: -> [^\]]*)?\](?:: (FAILED|UNREACHABLE)!)?/';

    /** A task banner (a task or a handler), as Ansible's default and YAML callbacks print it. */
    private const BANNER = '/^(?:TASK|RUNNING HANDLER) \[(.*)\] \**$/D';

    /**
     * The number of result lines of each kind per host.
     *
     * @param  string[]  $lines  output lines, ANSI codes allowed
     * @return array<string, array{ok: int, changed: int, skipped: int, failed: int, unreachable: int}> host => counts
     */
    public static function parse(array $lines): array
    {
        $hosts = [];
        $lastFailure = null;
        foreach ($lines as $line) {
            $line = rtrim(Ansi::strip($line));
            if ($line === '...ignoring' && $lastFailure !== null) {
                $hosts[$lastFailure]['failed']--;
                $lastFailure = null;

                continue;
            }
            if (! preg_match(self::LINE, $line, $m)) {
                continue;
            }
            $host = $m[2];
            $hosts[$host] ??= ['ok' => 0, 'changed' => 0, 'skipped' => 0, 'failed' => 0, 'unreachable' => 0];
            $kind = match (true) {
                ($m[3] ?? '') === 'UNREACHABLE' => 'unreachable',
                $m[1] === 'fatal', $m[1] === 'failed' => 'failed',
                $m[1] === 'skipping' => 'skipped',
                default => $m[1],
            };
            $hosts[$host][$kind]++;
            $lastFailure = $kind === 'failed' ? $host : null;
        }

        return $hosts;
    }

    /**
     * Where the log of a stopped run ends: the task it was in and the hosts whose step of that task had no final
     * result yet (no line, or only loop items or retries). Semaphore UI's stop ends only Ansible's main process: the
     * step each such host was in runs on in a process of its own, with all its loop items, so what it did is
     * unknown. A host the play never named before that task (the runner did not find it) has no step in progress,
     * unless the task is the first of its play. A log that has a PLAY RECAP, or ends between two tasks, has none.
     *
     * @param  string[]  $lines  output lines, ANSI codes allowed
     * @param  string[]  $hosts  the hosts to look at (the run's targets)
     * @return array{task: ?string, hosts: string[]}
     */
    public static function inProgress(array $lines, array $hosts): array
    {
        $task = null;
        $tasks = 0; // tasks of the current play so far
        $seen = []; // hosts named by the earlier tasks of the play
        $named = []; // hosts named by the current task
        $done = []; // hosts with the result of their step of the current task
        foreach ($lines as $line) {
            $line = rtrim(Ansi::strip($line));
            if (preg_match(self::BANNER, $line, $m)) {
                [$task, $seen, $named, $done] = [$m[1], $seen + $named, [], []];
                $tasks++;
            } elseif (preg_match('/^PLAY RECAP(?: \**)?$/D', $line)) {
                return ['task' => null, 'hosts' => []];
            } elseif (str_starts_with($line, 'PLAY [')) {
                [$task, $tasks, $seen, $named, $done] = [null, 0, [], [], []]; // the next play: nothing in progress until its first task
            } elseif ($task !== null && preg_match(self::LINE, $line, $m)) {
                $named[$m[2]] = true;
                if (! preg_match('/^ (?:=> )?\(item=/', substr($line, strlen($m[0])))) {
                    $done[$m[2]] = true; // the step's result (a loop item is not the end of its step)
                }
            } elseif ($task !== null && preg_match('/^included: .+ for (.+)$/', $line, $m)) {
                foreach (explode(',', $m[1]) as $host) {
                    $named[trim($host)] = $done[trim($host)] = true;
                }
            } elseif ($task !== null && preg_match('/^FAILED - RETRYING: \[([^\]\s]+)(?: -> [^\]]*)?\]/', $line, $m)) {
                $named[$m[1]] = true;
            }
        }
        $running = $task === null ? [] : array_values(array_filter($hosts,
            fn ($host) => ! isset($done[$host]) && ($tasks === 1 || isset($seen[$host]) || isset($named[$host]))));

        return ['task' => $running ? $task : null, 'hosts' => $running];
    }

    /**
     * A host's result from its lines. Without a PLAY RECAP the evidence is partial: a host that only had ok or
     * skipped results may have done more after the log ends, so its result is unknown.
     *
     * @param  array{ok: int, changed: int, skipped: int, failed: int, unreachable: int}  $counts
     */
    public static function result(array $counts): string
    {
        return match (true) {
            $counts['unreachable'] > 0 => 'unreachable',
            $counts['failed'] > 0 => 'failed',
            $counts['changed'] > 0 => 'changed',
            default => RunResults::UNKNOWN,
        };
    }
}
