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
