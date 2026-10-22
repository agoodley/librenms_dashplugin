<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * The state of a configuration backup the plugin asked Oxidized for, before or after a batch. Oxidized backs up a
 * node when asked (PUT node/next), and its node/show answer has the last attempt ("last": start, end, status; null
 * while one runs). A backup is fresh when an attempt that started at or after the request succeeded; an attempt that
 * failed is noted while the plugin waits, as Oxidized may retry; past the timeout the backup is given up. The version
 * kept is the newest one Oxidized has: its Git output stores a version only when the configuration changed.
 */
final class BackupState
{
    /** Waiting for Oxidized. */
    public const REQUESTED = 'requested';

    public const FRESH = 'fresh';

    /** No successful attempt before the timeout. */
    public const FAILED = 'failed';

    /** Oxidized could not be asked (unreachable, the request refused). */
    public const UNAVAILABLE = 'unavailable';

    /** Oxidized has no node for the device. */
    public const NO_NODE = 'no_node';

    /** LibreNMS leaves the device out of Oxidized (its os, type or the device's own setting). */
    public const EXCLUDED = 'excluded';

    /** States the plugin no longer asks about. */
    public const SETTLED = [self::FRESH, self::FAILED, self::UNAVAILABLE, self::NO_NODE, self::EXCLUDED];

    public const LABELS = [self::REQUESTED => 'waiting for Oxidized', self::FRESH => 'backed up', self::FAILED => 'failed',
        self::UNAVAILABLE => 'Oxidized not reachable', self::NO_NODE => 'not in Oxidized', self::EXCLUDED => 'not backed up by Oxidized'];

    /**
     * A requested backup after Oxidized's answer about its node: fresh, still waiting (with the error of a failed
     * attempt), or failed when the timeout passed.
     *
     * @param  array{requested_at: int, error?: ?string}  $state
     * @param  array{start?: ?string, end?: ?string, status?: ?string}|null  $last  node/show's "last", null while a backup runs
     * @return array{status: string, at: ?int, error: ?string}
     */
    public static function evaluate(array $state, ?array $last, int $now, int $timeoutSeconds): array
    {
        $requested = (int) $state['requested_at'];
        $start = self::time($last['start'] ?? null);
        $end = self::time($last['end'] ?? null);
        $error = $state['error'] ?? null;
        if ($last !== null && $start !== null && $start >= $requested) {
            if (($last['status'] ?? '') === 'success') {
                return ['status' => self::FRESH, 'at' => $end ?? $start, 'error' => null];
            }
            $error = 'Oxidized\'s backup ' . (is_string($last['status'] ?? null) ? 'ended with "' . mb_substr($last['status'], 0, 40) . '"' : 'failed');
        }
        if ($now - $requested > $timeoutSeconds) {
            return ['status' => self::FAILED, 'at' => null, 'error' => $error ?? 'Oxidized took no backup within ' . intdiv($timeoutSeconds, 60) . ' minutes'];
        }

        return ['status' => self::REQUESTED, 'at' => null, 'error' => $error];
    }

    /**
     * An Oxidized time ("2026-10-22 06:15:01 UTC") as a Unix time; null for none.
     */
    public static function time(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d/', $value)) {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? null : $time;
    }
}
