<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Runs\Ansi;

/**
 * The structure of a log of Ansible's default callback, as the dry-run proof (DryRunProof) and the diff parser
 * (DiffParser) read it: banners (DRY RUN, PLAY, TASK, RUNNING HANDLER, PLAY RECAP), task result lines ("ok: [host]",
 * "changed: [host] => (item=x)", "fatal: [host]: FAILED! => ..."), "...ignoring" and "included: file for hosts".
 * Only what starts a line counts, without its ANSI codes and control characters: Ansible indents the details of a
 * result, and prefixes each line of a unified diff. Device output that Ansible prints as it is (an item label with
 * a newline, a "prepared" diff) can still imitate these lines: the callers treat what they find as untrusted and
 * check it against the PLAY RECAP.
 */
final class AnsibleLog
{
    /** What the default callback appends to the banners of a play, task or handler in check mode (check_mode_markers). */
    public const MARKER = '[CHECK MODE]';

    /** Banner kinds that must carry the marker in a dry run. */
    public const MARKED_KINDS = ['play', 'task', 'handler'];

    /**
     * A line as the parsers read it: without ANSI codes and control characters, trailing spaces trimmed.
     */
    public static function plain(string $line): string
    {
        return rtrim(Ansi::strip($line));
    }

    /**
     * A banner of the default callback: its kind (dry_run, play, task, handler, recap), its name and whether it carries
     * the check-mode marker; null for any other line. A line that starts like a play, task or handler banner counts
     * as one even when it does not end like one (a name with a newline, device output): without the marker at its end
     * it is a banner without the marker.
     *
     * @return array{kind: string, name: string, marked: bool}|null
     */
    public static function banner(string $plain): ?array
    {
        if (preg_match('/^DRY RUN \*{3,}$/D', $plain)) {
            return ['kind' => 'dry_run', 'name' => '', 'marked' => true];
        }
        if (preg_match('/^PLAY RECAP(?: \*+)?$/D', $plain)) {
            return ['kind' => 'recap', 'name' => '', 'marked' => true];
        }
        if (! preg_match('/^(PLAY|TASK|RUNNING HANDLER) \[/', $plain, $m)) {
            return null;
        }
        $kind = ['PLAY' => 'play', 'TASK' => 'task', 'RUNNING HANDLER' => 'handler'][$m[1]];
        $marked = (bool) preg_match('/ \[CHECK MODE\] \*{3,}$/D', $plain);
        $name = preg_match('/^(?:PLAY|TASK|RUNNING HANDLER) \[(.*?)\](?: \[CHECK MODE\])? \*{3,}$/D', $plain, $n)
            ? $n[1] : substr($plain, strlen($m[0]));

        return ['kind' => $kind, 'name' => mb_substr($name, 0, 255), 'marked' => $marked];
    }

    /**
     * A task result line: its kind (ok, changed, skipping, failed, fatal), the host (without "-> delegate"), the item
     * label of a loop, whether it is an unreachable host, and the rest of the line (" => " and the result details in
     * JSON, when Ansible prints them on the same line); null for any other line.
     *
     * @return array{kind: string, host: string, item: ?string, unreachable: bool, rest: string}|null
     */
    public static function result(string $plain): ?array
    {
        if (! preg_match('/^(ok|changed|skipping|failed|fatal): \[([^\]\s]+)(?: -> [^\]]*)?\](.*)$/sD', $plain, $m)) {
            return null;
        }
        $rest = $m[3];
        $item = null;
        // a skipped item reads "(item=x) " before its details with -v: "skipping: [host] => (item=x)  => {...}"
        if (preg_match('/^(?: =>)? \(item=(.*?)\) ?(?: =>(.*))?\s*$/sD', $rest, $i)) {
            $item = mb_substr($i[1], 0, 255);
            $rest = isset($i[2]) ? ' =>' . $i[2] : '';
        }

        return ['kind' => $m[1], 'host' => $m[2], 'item' => $item, 'unreachable' => str_starts_with($rest, ': UNREACHABLE!'), 'rest' => $rest];
    }

    /**
     * The hosts of an "included: <file or role> for host1, host2" line (Ansible counts an include as an ok result of each
     * of them); null for any other line.
     *
     * @return string[]|null
     */
    public static function included(string $plain): ?array
    {
        if (! preg_match('/^included: .+ for (.+?)(?: => \(item=.*\))?$/D', $plain, $m)) {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $m[1])), fn ($host) => $host !== '' && ! preg_match('/\s/', $host)));
    }

    /**
     * Is it Ansible's "...ignoring" after a failure whose error is ignored (ignore_errors)?
     */
    public static function ignoring(string $plain): bool
    {
        return $plain === '...ignoring';
    }

    /**
     * Is it the error ansible-playbook stops with, before it starts the playbook, when the limit leaves no host of the
     * inventory to target ("[ERROR]: Specified inventory, host pattern and/or --limit leaves us with no hosts to
     * target.", "ERROR! ..." before Ansible 2.19)?
     */
    public static function noHosts(string $plain): bool
    {
        return (bool) preg_match('#^(?:\[ERROR\]: |ERROR! )Specified inventory, host pattern and/or --limit leaves us with no hosts to target\.$#', $plain);
    }

    /**
     * Is it a message of Ansible itself (a warning, an error, a retry, an async poll, a pause), never part of a diff?
     */
    public static function message(string $plain): bool
    {
        return (bool) preg_match('/^(?:\[(?:WARNING|DEPRECATION WARNING|ERROR)\]: |FAILED - RETRYING: |ASYNC (?:POLL|OK|FAILED) on |Pausing for \d|\(ctrl\+C then )/', $plain);
    }
}
