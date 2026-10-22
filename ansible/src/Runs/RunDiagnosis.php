<?php

namespace Agoodley\LibrenmsAnsible\Runs;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;

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
