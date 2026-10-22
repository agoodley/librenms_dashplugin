<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\Inventory;
use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;

/**
 * How the runner reaches each device of a change: the ansible_* variables the LibreNMS inventory gives the device's
 * host (ansible_host, the address Ansible connects to: the device's overwrite IP, else its hostname; then
 * ansible_network_os, ansible_connection and privileged mode from the Platform mapping setting), or why the device is
 * not a host. They are recorded when the dry run is launched and are part of the fingerprint, then compared with the
 * inventory as it is before the approval, before every batch (and right before its launch) and after it: a device that
 * would be reached at another address or as another platform is not the device that was dry-run and approved (an
 * admin who may edit devices could point it at another one), so the dry run no longer counts. The librenms_* facts
 * (sysName, location, version, ...) are not part of it: they change nothing about where the playbook runs.
 */
final class Connections
{
    /** The key a device that is not a host has instead of its variables, with why. */
    public const NOT_A_HOST = 'not_a_host';

    /**
     * The connection of each device, as an inventory gives it.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array<string, mixed>> device_id => its ansible_* variables (sorted by name), or [NOT_A_HOST => why]
     */
    public static function of(Inventory $inventory, array $deviceIds): array
    {
        $hosts = $inventory->hosts();
        $leftOut = $inventory->leftOut();
        $connections = [];
        foreach ($deviceIds as $deviceId) {
            $deviceId = (int) $deviceId;
            $name = $inventory->nameOf($deviceId);
            if ($name === null || ! isset($hosts[$name])) {
                $connections[$deviceId] = [self::NOT_A_HOST => (string) ($leftOut[$deviceId]['text'] ?? 'it is not in LibreNMS any more')];

                continue;
            }
            $vars = array_filter($hosts[$name], fn ($name) => str_starts_with((string) $name, 'ansible_'), ARRAY_FILTER_USE_KEY);
            ksort($vars, SORT_STRING);
            $connections[$deviceId] = $vars;
        }
        ksort($connections);

        return $connections;
    }

    /**
     * The devices of $now whose connection is not the one recorded in $recorded, in words ("sec-sw1: ansible_host
     * 127.0.1.11 → 127.0.1.12"), at most $max; empty when they are all the same.
     *
     * @param  array<int|string, mixed>  $recorded  device_id => variables, as recorded at the dry run
     * @param  array<int, array<string, mixed>>  $now  device_id => variables, as the inventory gives them now
     * @param  array<int, string>  $names  device_id => the name to show
     * @return string[]
     */
    public static function differences(array $recorded, array $now, array $names, int $max = 5): array
    {
        $found = [];
        foreach ($now as $deviceId => $vars) {
            $was = $recorded[$deviceId] ?? null;
            $was = is_array($was) || is_object($was) ? (array) $was : null;
            if ($was !== null && Fingerprint::of($was) === Fingerprint::of($vars)) {
                continue;
            }
            $name = UntrustedText::line((string) ($names[$deviceId] ?? "device #$deviceId"), 80);
            if (isset($vars[self::NOT_A_HOST])) {
                $found[] = "$name is no longer a host of the inventory (" . UntrustedText::line((string) $vars[self::NOT_A_HOST], 200) . ')';
            } elseif ($was === null || isset($was[self::NOT_A_HOST])) {
                $found[] = "$name: the dry run recorded no address for it";
            } else {
                $changes = [];
                foreach (array_unique(array_merge(array_keys($was), array_keys($vars))) as $var) {
                    if (Fingerprint::of(['v' => $was[$var] ?? null]) !== Fingerprint::of(['v' => $vars[$var] ?? null])) {
                        $changes[] = UntrustedText::line((string) $var, 40) . ' ' . self::show($was[$var] ?? null) . ' → ' . self::show($vars[$var] ?? null);
                    }
                }
                $found[] = "$name: " . implode(', ', $changes);
            }
        }

        return count($found) > $max ? [...array_slice($found, 0, $max), (count($found) - $max) . ' more'] : $found;
    }

    /**
     * The address a device was dry-run at (its recorded ansible_host), null when none was recorded.
     *
     * @param  array<int|string, mixed>  $recorded
     */
    public static function address(array $recorded, int $deviceId): ?string
    {
        $vars = $recorded[$deviceId] ?? null;
        $vars = is_array($vars) || is_object($vars) ? (array) $vars : [];

        return isset($vars['ansible_host']) && is_scalar($vars['ansible_host']) ? (string) $vars['ansible_host'] : null;
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null => '(none)',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => UntrustedText::line((string) $value, 120),
            default => UntrustedText::line((string) json_encode($value), 120),
        };
    }
}
