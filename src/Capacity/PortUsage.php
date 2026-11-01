<?php

namespace Agoodley\LibrenmsTopology\Capacity;

/**
 * The plugin's own record of each Ethernet port (topology_port_usage), kept with every topology
 * snapshot: when it was last known up and its latest err-disable message. A switch that restarts
 * starts its ports' ifLastChange over, and LibreNMS keeps its eventlog and syslog for 30 days by
 * default; the record keeps what they showed, so the port capacity page still knows that a port down
 * since last week's firmware upgrade had been unused for a year, or that a port is still err-disabled
 * after its message was purged.
 *
 * A port is known up at its last poll while LibreNMS sees it up, at its last change while it is down
 * (the port was up until then) unless that change came with the start of the switch, and when the
 * switch err-disabled it (for what came over its link). A recovery of an err-disable forgets it. Pure
 * PHP: the times are Unix timestamps.
 */
class PortUsage
{
    /** A port that stays up has its last_up written again once it is this much newer (seconds). */
    public const REFRESH = 600;

    /**
     * The latest time the port is known to have been up from LibreNMS' data now, null when it shows none:
     * the port's last poll while it is up (its device's when the port has no poll time), its last change
     * while it is down. ifOperStatus dormant counts as up (a link waiting for authentication).
     *
     * @param  array  $port  ifOperStatus, ifLastChange, poll_time
     * @param  array  $device  uptime, last_polled (timestamps)
     */
    public static function evidence(array $port, array $device): ?int
    {
        if (self::inUse($port)) {
            $polled = (int) ($port['poll_time'] ?? 0) ?: (int) ($device['last_polled'] ?? 0);

            return $polled > 0 ? $polled : null;
        }
        $change = PortClock::lastChange(self::int($device['uptime'] ?? null), self::int($device['last_polled'] ?? null), $port['ifLastChange'] ?? null);

        return $change['time'] !== null && ! $change['at_boot'] ? $change['time'] : null;
    }

    /**
     * Is the port up (or dormant) as LibreNMS last polled it?
     */
    public static function inUse(array $port): bool
    {
        return in_array(strtolower((string) ($port['ifOperStatus'] ?? '')), CapacityAnalyzer::IN_USE_STATES, true);
    }

    /**
     * What to write to the record for the ports as LibreNMS has them now: a row for each new port and for
     * each port whose last_up or err-disable message is newer than recorded (port_id => device_id,
     * first_seen, last_up, err_disabled_at, err_disabled_message), and the recorded ports LibreNMS no
     * longer has (deleted, or no longer of an Ethernet type), to forget.
     *
     * @param  array<int, array>  $ports  port_id => device_id, ifOperStatus, ifLastChange, poll_time
     * @param  array<int, array>  $devices  device_id => uptime, last_polled
     * @param  array<int, array>  $records  port_id => device_id, first_seen, last_up, err_disabled_at, err_disabled_message
     * @param  array<int, array{time: ?int, message: ?string, recovered: ?int}>  $errDisabled  port_id => its latest err-disable
     *                                                                                      message and recovery found (ErrDisable::latest())
     * @return array{write: array<int, array>, remove: int[]}
     */
    public static function update(array $ports, array $devices, array $records, array $errDisabled, int $now): array
    {
        $write = [];
        foreach ($ports as $portId => $port) {
            $portId = (int) $portId;
            $deviceId = (int) ($port['device_id'] ?? 0);
            $record = $records[$portId] ?? null;
            $lastUp = self::int($record['last_up'] ?? null);
            $error = $errDisabled[$portId] ?? null;
            $errorTime = self::int($error['time'] ?? null);
            $evidence = self::evidence($port, $devices[$deviceId] ?? []);
            $evidence = $errorTime !== null && ($evidence === null || $errorTime > $evidence) ? $errorTime : $evidence;
            // a port that stays up is written every REFRESH seconds, a newer change at once
            $newerUp = $evidence !== null && ($lastUp === null || $evidence > $lastUp + (self::inUse($port) ? self::REFRESH - 1 : 0));

            $errAt = self::int($record['err_disabled_at'] ?? null);
            $errMessage = $record['err_disabled_message'] ?? null;
            $holding = ErrDisable::holding($error, $errAt === null ? null : ['time' => $errAt, 'message' => (string) $errMessage]);
            $changedError = ($holding['time'] ?? null) !== $errAt;

            if ($record !== null && ! $newerUp && ! $changedError && (int) ($record['device_id'] ?? 0) === $deviceId) {
                continue;
            }
            $write[$portId] = [
                'device_id' => $deviceId,
                'first_seen' => self::int($record['first_seen'] ?? null) ?? $now,
                'last_up' => $newerUp ? $evidence : $lastUp,
                'err_disabled_at' => $holding['time'] ?? null,
                'err_disabled_message' => $holding === null ? null : mb_substr($holding['message'], 0, ErrDisable::MESSAGE_LENGTH),
            ];
        }

        return ['write' => $write, 'remove' => array_values(array_map('intval', array_diff(array_keys($records), array_keys($ports))))];
    }

    private static function int(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
