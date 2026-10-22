<?php

namespace Agoodley\LibrenmsAnsible\Runs;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Support\Clock;

/**
 * Why a run the runner reported successful is incomplete, from its log. The common cause is the inventory: when the
 * inventory script fails (no LIBRENMS_URL or LIBRENMS_TOKEN in the template's variable group, a revoked token, an
 * unreachable LibreNMS), Ansible warns, finds no host, skips every play and still ends with success.
 */
final class RunDiagnosis
{
    /** Most characters quoted from the inventory script's error. */
    private const QUOTE = 300;

    /**
     * The alert of an incomplete run: how many targets did not do what was asked, and the likely cause.
     *
     * @param  string[]  $lines  the run's log
     * @param  array<int, array{result: string, counts: array<string, int>|null}>  $results  device_id => result
     * @param  array<int, string>  $names  device_id => inventory name
     */
    public static function incomplete(array $lines, array $results, array $names): string
    {
        $missing = [];
        $failed = 0;
        foreach ($results as $deviceId => $result) {
            if ($result['result'] === RunResults::NOT_RUN || $result['result'] === RunResults::UNKNOWN) {
                $missing[] = $names[$deviceId] ?? "#$deviceId";
            } elseif (! in_array($result['result'], RunResults::DONE, true)) {
                $failed++;
            }
        }
        $total = count($results);
        if (! $missing) {
            return "$failed of $total targets failed or were unreachable, although the runner reported success (are errors ignored in the playbook?): see their results.";
        }
        $text = count($missing) . " of $total targets did not run: the runner reported success, but the PLAY RECAP does not name them. ";
        $scriptError = self::inventoryScriptError($lines);
        if ($scriptError !== null) {
            return $text . 'The inventory script failed: "' . $scriptError . '". Check the template\'s variable group in Semaphore UI: the environment '
                . 'variable LIBRENMS_URL and the secret LIBRENMS_TOKEN, the API token of the inventory user.';
        }
        if (self::noInventory($lines)) {
            return $text . 'The runner got no inventory from LibreNMS: check the LibreNMS inventory and the template\'s variable group in Semaphore UI.';
        }
        $unmatched = array_values(array_intersect(self::unmatchedHosts($lines), $missing));
        if ($unmatched) {
            return $text . 'The runner\'s inventory did not have ' . implode(', ', array_slice($unmatched, 0, 5)) . (count($unmatched) > 5 ? ' and ' . (count($unmatched) - 5) . ' more' : '')
                . ': the inventory user may not see them, or they left the inventory since the preview.';
        }

        return $text . 'Ansible ran no task on them: check that the plays of the playbook include them (their hosts: patterns).';
    }

    /**
     * The run page's note on a stopped run. Semaphore UI (2.19) ends only Ansible's main process: the step each target
     * was in runs on in a process of its own, with all its loop items (a pause waits for ever), so the plugin holds the
     * devices of a stopped run until held_until (the Hold after a stop setting), unless an admin releases them.
     *
     * @param  string|null  $task  the task the run was in (TaskLines::inProgress()), null when none was
     */
    public static function afterStop(array $run, ?string $task, int $now): string
    {
        if ($task === null && ($run['started_at'] ?? null) === null && ($run['held_until'] ?? null) === null) {
            return 'The run was stopped before the runner started it: nothing ran, and its devices were free at once.';
        }
        $taskId = (string) ($run['runner_task_id'] ?? '');
        $processes = 'the ansible-playbook processes of this run' . ($taskId !== '' ? ' (task #' . UntrustedText::line($taskId, 20) . ')' : '');
        $text = ($task !== null ? 'The run was stopped during the task "' . UntrustedText::line($task, 120) . '". ' : '')
            . 'Semaphore UI stops only Ansible\'s main process: the step each target was in runs on in a process of its own on the runner, with all '
            . 'its loop items, and a pause keeps waiting until that process is killed. ';
        $held = isset($run['held_until']) ? (string) $run['held_until'] : null;
        if ($held !== null && (int) Clock::unix($held) > $now) {
            return $text . 'The devices stay held until ' . self::deadline($held) . " because that step can still run on the runner: check the runner for $processes, "
                . 'end them, and release the devices when it is done.';
        }
        if ($held !== null && ! empty($run['released_by'])) {
            return $text . 'Its devices were released by ' . UntrustedText::line((string) $run['released_by'], 60) . ' at ' . self::minute($held)
                . ", before the end of the hold: if that step still runs, end $processes.";
        }
        if ($held !== null) {
            return $text . 'The plugin held its devices until ' . self::deadline($held) . ": if that step still runs, end $processes.";
        }

        return $text . "Before you change these devices again, look on the runner for $processes and end them.";
    }

    /**
     * For a stopped run whose step may still run on the runner: the command that lists its ansible-playbook processes
     * there (Semaphore UI puts the task's id in their command line); null when it never started or its task is unknown.
     */
    public static function leftoverCommand(array $run): ?string
    {
        $taskId = (string) ($run['runner_task_id'] ?? '');
        if (($run['status'] ?? '') !== RunStatus::STOPPED || ! preg_match('/^\d{1,12}$/D', $taskId)
            || (($run['started_at'] ?? null) === null && ($run['held_until'] ?? null) === null)) {
            return null;
        }

        return "ps -eo pid,args | grep '\"id\":$taskId,'";
    }

    /**
     * A kept time to the minute, as the pages show it (the user's time zone).
     */
    private static function minute(string $time): string
    {
        return (string) Clock::local($time, 'Y-m-d H:i');
    }

    /**
     * A deadline, as the pages show it, with its time zone.
     */
    private static function deadline(string $time): string
    {
        return (string) Clock::local($time, 'Y-m-d H:i T');
    }

    /**
     * The inventory script's error from Ansible's warning ("Standard error from inventory script:" and the lines
     * after it; older Ansible: "... had an execution error: ..."), cleaned and cut; null when there is none.
     *
     * @param  string[]  $lines
     */
    public static function inventoryScriptError(array $lines): ?string
    {
        $lines = array_values(array_map(fn ($line) => trim(Ansi::strip($line)), $lines));
        foreach ($lines as $index => $line) {
            if ($line === 'Standard error from inventory script:') {
                $error = [];
                for ($i = $index + 1; $i < min(count($lines), $index + 6) && $lines[$i] !== ''; $i++) {
                    $error[] = $lines[$i];
                }
                if ($error) {
                    return UntrustedText::line(implode(' ', $error), self::QUOTE);
                }
            }
            if (preg_match('/Inventory script \(.*\) had an execution error: (.+)$/', $line, $m)) {
                return UntrustedText::line($m[1], self::QUOTE);
            }
        }

        return null;
    }

    /**
     * Did Ansible find no inventory at all?
     *
     * @param  string[]  $lines
     */
    private static function noInventory(array $lines): bool
    {
        foreach ($lines as $line) {
            $line = Ansi::strip($line);
            if (str_contains($line, '[WARNING]: No inventory was parsed') || str_contains($line, '[WARNING]: provided hosts list is empty')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The hosts of the limit Ansible did not find in its inventory ("Could not match supplied host pattern").
     *
     * @param  string[]  $lines
     * @return string[]
     */
    private static function unmatchedHosts(array $lines): array
    {
        $hosts = [];
        foreach ($lines as $line) {
            if (preg_match('/\[WARNING\]: Could not match supplied host pattern, ignoring: (\S+)/', Ansi::strip($line), $m)) {
                $hosts[] = $m[1];
            }
        }

        return $hosts;
    }
}
