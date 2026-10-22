<?php

namespace Agoodley\LibrenmsAnsible\Runs;

/**
 * Per-host results from the PLAY RECAP at the end of an Ansible run (the per-host summary of the runner's API is
 * a paid feature of Semaphore). Device output can contain anything, so only the last PLAY RECAP header that
 * starts a line counts, and only the host lines right after it (they start the line; device output is
 * indented by Ansible's callbacks).
 */
final class RecapParser
{
    /** Counters of a recap line, in Ansible's order. */
    public const COUNTERS = ['ok', 'changed', 'unreachable', 'failed', 'skipped', 'rescued', 'ignored'];

    /**
     * Connection errors of paramiko, libssh and OpenSSH as network connections report them: the message starts with
     * one (after an optional "[Errno N]"), as when the connection itself failed. A task whose message only contains
     * such words (a device that answered "% Connection refused" to a command, a module that could not reach a
     * server) is a failure on a reachable device.
     */
    private const CONNECTION_ERRORS = '/^(?:\[Errno [^\]]*\]\s*)?(?:unable to connect to port|connection refused|no route to host|network is unreachable'
        . '|name or service not known|nodename nor servname|could not resolve hostname|temporary failure in name resolution|authentication failed'
        . '|failed to authenticate|connection timed out|timed out while connecting|ssh connect failed|ssh connection failed|failed to connect to the host)/i';

    private const HOST_LINE = '/^(\S+)\s+:\s+ok=(\d+)\s+changed=(\d+)\s+unreachable=(\d+)\s+failed=(\d+)(?:\s+skipped=(\d+))?(?:\s+rescued=(\d+))?(?:\s+ignored=(\d+))?\s*$/D';

    /**
     * The counters per host of the last PLAY RECAP, null when the output has none (the run did not get there).
     *
     * @param  string[]  $lines  output lines, ANSI codes allowed
     * @return array<string, array<string, int>>|null host => counter => count
     */
    public static function parse(array $lines): ?array
    {
        $lines = array_values($lines);
        $start = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/^PLAY RECAP(?: \**)?$/D', rtrim(Ansi::strip($line)))) {
                $start = $index;
            }
        }
        if ($start === null) {
            return null;
        }
        $hosts = [];
        for ($i = $start + 1; $i < count($lines); $i++) {
            $line = rtrim(Ansi::strip($lines[$i]));
            if ($line === '') {
                if ($hosts) {
                    break;
                }

                continue;
            }
            if (! preg_match(self::HOST_LINE, $line, $m)) {
                break;
            }
            $counts = [];
            foreach (self::COUNTERS as $position => $counter) {
                $counts[$counter] = (int) ($m[$position + 2] ?? 0);
            }
            $hosts[$m[1]] = $counts;
        }

        return $hosts;
    }

    /**
     * Hosts whose failure was the connection itself. Network connections (network_cli) report a device that
     * cannot be reached as a failed task, not as unreachable: Ansible's "fatal: [host]: FAILED!" line followed by
     * a connection error of SSH (refused, timed out, no route, unknown name, authentication) tells them apart.
     * Only used for hosts on which nothing succeeded (RunResults).
     *
     * @param  string[]  $lines
     * @return array<string, true>
     */
    public static function connectionFailures(array $lines): array
    {
        $lines = array_values($lines);
        $hosts = [];
        foreach ($lines as $index => $line) {
            if (! preg_match('/^fatal: \[([^\]\s]+)\]: FAILED! =>(.*)$/D', rtrim(Ansi::strip($line)), $m)) {
                continue;
            }
            if (preg_match(self::CONNECTION_ERRORS, trim(trim(self::message($m[2], $lines, $index)), "'\""))) {
                $hosts[$m[1]] = true;
            }
        }

        return $hosts;
    }

    /**
     * The "msg" of a failed result: in the JSON on the same line (default callback) or on the first "msg:" line of
     * the indented block below it (YAML callback); the rest of the result (device output) is not looked at.
     *
     * @param  string[]  $lines
     */
    private static function message(string $rest, array $lines, int $index): string
    {
        if (preg_match('/"msg":\s*"((?:[^"\\\\]|\\\\.)*)"/', $rest, $m)) {
            return $m[1];
        }
        for ($i = $index + 1; $i < min(count($lines), $index + 20); $i++) {
            $line = Ansi::strip($lines[$i]);
            if (! str_starts_with($line, ' ')) {
                break;
            }
            if (preg_match('/^ {2,6}msg: (.*)$/', $line, $m)) {
                return $m[1];
            }
        }

        return '';
    }

    /**
     * One host's result from its counters: unreachable, then failed, changed, ok, skipped (nothing ran).
     *
     * @param  array<string, int>  $counts
     */
    public static function result(array $counts): string
    {
        foreach (['unreachable', 'failed', 'changed', 'ok', 'skipped'] as $result) {
            if (($counts[$result] ?? 0) > 0) {
                return $result;
            }
        }

        return 'skipped';
    }
}
