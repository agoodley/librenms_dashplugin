<?php

namespace Agoodley\LibrenmsAnsible\Runs;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;

/**
 * Texts the plugin writes outside its pages: the message of the runner's task and the LibreNMS eventlog
 * entries of a run. They name the run, the template and the user, never a variable (answers can be secret).
 */
final class RunText
{
    /**
     * The task's message in the runner's task list.
     */
    public static function taskMessage(int $runId, string $user): string
    {
        return 'LibreNMS Ansible run #' . $runId . ' by ' . UntrustedText::line($user, 60);
    }

    /**
     * Eventlog reference of a run: the path of its page, relative to LibreNMS' URL.
     */
    public static function reference(int $runId): string
    {
        return 'plugin/ansible/runs/' . $runId;
    }

    /**
     * Eventlog entry of each target when the runner accepted the run.
     */
    public static function started(int $runId, string $template, string $user): string
    {
        return "Ansible run #$runId started: template " . self::quote($template) . ' by ' . UntrustedText::line($user, 60);
    }

    /**
     * Eventlog entry of each target when the runner refused the run or could not be reached.
     */
    public static function notLaunched(int $runId, string $template, string $user, string $error): string
    {
        return "Ansible run #$runId was not launched: template " . self::quote($template) . ' by ' . UntrustedText::line($user, 60)
            . ': ' . UntrustedText::line($error, 300);
    }

    /**
     * Eventlog entry of a target when its run ended: the target's result, with its counters when the PLAY RECAP
     * named it, and how the run ended when it did not succeed.
     *
     * @param  array<string, int>|null  $counts
     */
    public static function finished(int $runId, string $status, string $result, ?array $counts, string $template, string $user, ?string $note = null): string
    {
        $text = "Ansible run #$runId " . self::ending($status) . ': ' . strtolower(RunResults::LABELS[$result] ?? $result);
        if ($counts !== null) {
            $text .= ' (' . implode(' ', array_map(fn ($c) => $c . '=' . ($counts[$c] ?? 0), ['ok', 'changed', 'unreachable', 'failed', 'skipped'])) . ')';
        }
        $text .= ', template ' . self::quote($template) . ' by ' . UntrustedText::line($user, 60);

        return $note !== null && $note !== '' ? $text . ' (' . UntrustedText::line($note, 200) . ')' : $text;
    }

    /**
     * Eventlog entry (no device) when the runner reported hosts that were not targets.
     *
     * @param  string[]  $hosts
     */
    public static function unexpectedHosts(int $runId, array $hosts, string $template): string
    {
        $names = implode(', ', array_map(fn ($h) => UntrustedText::clean($h, 60), array_slice($hosts, 0, 10))) . (count($hosts) > 10 ? ', ...' : '');

        return "Ansible run #$runId ran on hosts that were not its targets: $names. Check in the runner that template "
            . self::quote($template) . ' uses the LibreNMS inventory and allows the limit override.';
    }

    /**
     * How a run ended, for the eventlog.
     */
    private static function ending(string $status): string
    {
        return match ($status) {
            RunStatus::SUCCESS => 'finished',
            RunStatus::FAILED => 'failed',
            RunStatus::STOPPED => 'was stopped',
            RunStatus::LOST => 'was lost',
            default => 'ended',
        };
    }

    /**
     * A template name in quotes.
     */
    private static function quote(string $name): string
    {
        return "'" . UntrustedText::line($name, 100) . "'";
    }
}
