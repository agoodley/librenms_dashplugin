<?php

namespace Agoodley\LibrenmsAnsible\Runs;

use Agoodley\LibrenmsAnsible\Changes\AnsibleLog;

/**
 * Per-host results from the PLAY RECAP at the end of an Ansible run (the per-host summary of the runner's API is
 * a paid feature of Semaphore). Device output can contain anything, so only the last PLAY RECAP header that
 * starts a line counts, and only the host lines right after it (they start the line; device output is
 * indented by Ansible's callbacks). Where Ansible prints text as it is (a loop item's label with a newline) it can
 * imitate a whole recap at the start of lines, so the recap also has to end the log (Ansible prints it last), see
 * parse().
 */
final class RecapParser
{
    /** Counters of a recap line, in Ansible's order. */
    public const COUNTERS = ['ok', 'changed', 'unreachable', 'failed', 'skipped', 'rescued', 'ignored'];

    private const HEADER = '/^PLAY RECAP(?: \**)?$/D';

    /**
     * The end of a text Ansible's default callback prints as it is, which a text with newlines leaves on a line of its
     * own: a loop item's label ("(item=<label>)", then the result's details after " => ", with -v and always for a
     * failure), a play's, task's or handler's name in its banner ("[<name>] ****", "[<name>] [CHECK MODE] ****"), a task's
     * name in a retry ("FAILED - RETRYING: [host]: <name> (3 retries left).", with -vv "Result was: ..." after it).
     */
    private const TEXT_END = '/\)\s*(?:=>.*)?$|\]\s*\*{3,}$|\(\d+ retries left\)\.(?:Result was:.*)?$/sD';

    /** Colours: the only escape sequences Ansible itself writes into the lines of a recap. */
    private const COLOUR = '/\x1b\[[0-9;]*m/';

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
     * The counters per host of the PLAY RECAP that ends the log, null when the output has none (the run did not get
     * there, or what reads like one does not end the log).
     *
     * Ansible prints the recap last: after its host lines come only blank lines and the closing DRY RUN banner of a dry
     * run, then the runner's own lines ("Failed to run task: exit status 2"), and Ansible's warnings may land there too
     * (stderr, merged line by line). A recap imitated in a text Ansible prints as it is (a loop item's label, a task's
     * name) is followed by the rest of that text, whose last line ends like the text does (TEXT_END), or ends with the
     * plugin's mark of a line it cut (OutputBuffer::LINE_CUT) when it was too long to keep. So the last header counts
     * only when every line after its host lines is blank, or text that ends neither way and is not Ansible's structure
     * (a banner other than DRY RUN, a task result, an include, a host line); and only when no line from the header on
     * holds an escape sequence other than a colour, or a control character other than a tab: those are dropped before
     * the lines are read, and could hide the end of the text. A warning that ends with ")" also makes the recap not
     * count: the run's results then come from its task lines, and a dry run is not proven (the safe side).
     *
     * @param  string[]  $lines  output lines, ANSI codes allowed
     * @return array<string, array<string, int>>|null host => counter => count
     */
    public static function parse(array $lines): ?array
    {
        $lines = array_values($lines);
        $start = null;
        foreach ($lines as $index => $line) {
            if (preg_match(self::HEADER, rtrim(Ansi::strip($line)))) {
                $start = $index;
            }
        }
        if ($start === null) {
            return null;
        }
        for ($i = $start; $i < count($lines); $i++) {
            if (! self::clean($lines[$i])) {
                return null;
            }
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
        for (; $i < count($lines); $i++) {
            if (! self::after(rtrim(Ansi::strip($lines[$i])))) {
                return null;
            }
        }

        return $hosts;
    }

    /**
     * Is it a line that may follow a recap's host lines: blank, the DRY RUN banner, or text that neither ends a text
     * Ansible prints as it is nor is Ansible's structure?
     */
    private static function after(string $plain): bool
    {
        if ($plain === '') {
            return true;
        }
        $banner = AnsibleLog::banner($plain);
        if ($banner !== null) {
            return $banner['kind'] === 'dry_run';
        }

        return ! preg_match(self::TEXT_END, $plain) && ! str_ends_with($plain, OutputBuffer::LINE_CUT)
            && AnsibleLog::result($plain) === null && AnsibleLog::included($plain) === null
            && ! preg_match(self::HOST_LINE, $plain);
    }

    /**
     * Does the line hold no escape sequence other than a colour, and no control character other than a tab (and a
     * carriage return at its end)?
     */
    private static function clean(string $line): bool
    {
        $text = (string) preg_replace(self::COLOUR, '', mb_scrub(rtrim($line, "\r"), 'UTF-8'));

        return preg_match('/[^\P{Cc}\t]/u', $text) === 0;
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
