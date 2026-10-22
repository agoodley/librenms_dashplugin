<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;

/**
 * Can Oxidized back up the targets of a change? Asked when the change is planned (the preview) and when its dry run is
 * launched, so a device Oxidized cannot back up is known before the apply instead of refusing its batch then (with
 * "Without a fresh backup: refuse"): LibreNMS may leave the device out of Oxidized, or Oxidized may have no node for
 * it. Once Oxidized cannot be reached, the other devices are not asked (each call may wait its timeout).
 */
final class BackupCheck
{
    public const NODE = 'node';

    public const NO_NODE = 'no_node';

    public const EXCLUDED = 'excluded';

    public const UNKNOWN = 'unknown';

    /**
     * What Oxidized says about each device.
     *
     * @param  int[]  $deviceIds
     * @param  (\Closure(): void)|null  $beat  called before each call to Oxidized (long work)
     * @return array{checks: array<int, string>, error: ?string}
     */
    public static function of(ConfigBackups $backups, array $deviceIds, ?\Closure $beat = null): array
    {
        $checks = [];
        $error = null;
        foreach ($deviceIds as $deviceId) {
            if ($beat !== null) {
                $beat();
            }
            if ($error !== null) {
                $checks[(int) $deviceId] = self::UNKNOWN;

                continue;
            }
            try {
                $node = $backups->node((int) $deviceId);
                $checks[(int) $deviceId] = $node === null ? self::NO_NODE : (! empty($node['excluded']) ? self::EXCLUDED : self::NODE);
            } catch (BackupError $e) {
                $checks[(int) $deviceId] = self::UNKNOWN;
                $error = UntrustedText::line($e->getMessage(), 200);
            }
        }

        return ['checks' => $checks, 'error' => $error];
    }

    /**
     * The devices Oxidized cannot back up, in words ("lab-sw4 (Oxidized has no node for it)"); empty when it can back
     * them all up.
     *
     * @param  array<int, string|null>  $checks  device_id => check
     * @param  array<int, string>  $names  device_id => name to show
     * @return string[]
     */
    public static function missing(array $checks, array $names): array
    {
        $missing = [];
        foreach ($checks as $deviceId => $check) {
            if ($check === self::NO_NODE || $check === self::EXCLUDED) {
                $missing[] = UntrustedText::line((string) ($names[$deviceId] ?? "#$deviceId"), 80) . ' ('
                    . ($check === self::NO_NODE ? 'Oxidized has no node for it' : 'LibreNMS leaves it out of Oxidized') . ')';
            }
        }

        return $missing;
    }

    /**
     * The warning for the devices Oxidized cannot back up, as the setting decides what their batches will do; null
     * when there is none.
     *
     * @param  string[]  $missing  missing()
     */
    public static function warning(array $missing, bool $refuse, ?string $error): ?string
    {
        if ($missing) {
            return 'Oxidized cannot back up ' . implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ' and ' . (count($missing) - 10) . ' more' : '')
                . ($refuse ? ': their batches will be refused (setting Without a fresh backup: refuse). Add them to Oxidized, or make the change without them.'
                    : ': their batches will go without a backup, with a warning in the eventlog (setting Without a fresh backup: continue with a warning).');
        }

        return $error !== null ? 'Oxidized could not be asked whether it backs the targets up (' . $error . ')'
            . (str_contains($error, 'oxidized.url') ? '.' : ': check LibreNMS\' oxidized.url setting.') : null;
    }
}
