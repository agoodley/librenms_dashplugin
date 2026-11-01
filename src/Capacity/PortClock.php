<?php

namespace Agoodley\LibrenmsTopology\Capacity;

/**
 * When a port last changed its status, from LibreNMS' ports.ifLastChange: the switch's sysUpTime at the
 * change, in hundredths of a second (IF-MIB TimeTicks), stored as polled. The device's uptime (seconds)
 * and the time of its last poll place it on the clock: it changed `uptime - ifLastChange / 100` seconds
 * before that poll, as LibreNMS' ports list computes it.
 *
 * Two cases need care. A port that changed in the first minutes after the switch (or its SNMP agent)
 * started changed because the switch started: every port goes down and comes back then, so that change
 * says nothing about how the port was used before. And sysUpTime is a 32-bit counter that starts over
 * after 497 days, while LibreNMS' uptime (the largest of sysUpTime, snmpEngineTime and hrSystemUptime)
 * does not: on a switch up that long the change is counted on the counter as it runs now (the uptime
 * modulo 497 days), which gives the latest time the change can have happened; it may have been 497 days
 * (or a multiple) earlier. A switch whose uptime is its host's while its SNMP agent restarted later
 * places its ports' changes too early; LibreNMS keeps no other clock to tell.
 * Pure PHP.
 */
class PortClock
{
    /** Seconds a 32-bit TimeTicks counter runs before it starts over (2^32 hundredths of a second: 497.1 days). */
    public const WRAP = 42949672.96;

    /** A change this soon after the switch started (seconds) is the start itself, not a use of the port. */
    public const BOOT_SLACK = 900;

    /** How far a port's poll may follow the poll of the uptime it is counted from (seconds). */
    private const POLL_DELAY = 3600;

    /**
     * When the device started: its last poll minus the uptime it reported then; null when LibreNMS has neither.
     */
    public static function boot(?int $uptime, ?int $polled): ?int
    {
        return $uptime !== null && $uptime > 0 && $polled !== null && $polled > 0 ? $polled - $uptime : null;
    }

    /**
     * The port's last change on the clock: its time (null when it cannot be placed: no uptime or poll, or
     * an ifLastChange that does not fit the uptime), whether it came with the start of the switch
     * (at_boot: an ifLastChange of 0, no change since the start, counts as one) and whether the counter
     * may have started over since (wrapped: the time is the latest the change can have been).
     *
     * @return array{time: ?int, at_boot: bool, wrapped: bool}
     */
    public static function lastChange(?int $uptime, ?int $polled, int|string|null $ifLastChange): array
    {
        $boot = self::boot($uptime, $polled);
        if ($boot === null) {
            return ['time' => null, 'at_boot' => false, 'wrapped' => false];
        }
        $seconds = max(0, (int) $ifLastChange) / 100;
        if ($seconds <= self::BOOT_SLACK) {
            return ['time' => $boot + (int) $seconds, 'at_boot' => true, 'wrapped' => false];
        }
        if ($uptime < self::WRAP) {
            if ($seconds > $uptime + self::POLL_DELAY) {
                return ['time' => null, 'at_boot' => false, 'wrapped' => false];
            }

            // a change between the poll of the uptime and the poll of the port is a change at that poll
            return ['time' => $polled - (int) round(max(0, $uptime - $seconds)), 'at_boot' => false, 'wrapped' => false];
        }
        $counter = fmod((float) $uptime, self::WRAP);
        $age = $seconds <= $counter ? $counter - $seconds : $counter + self::WRAP - $seconds;

        return ['time' => $polled - (int) round($age), 'at_boot' => false, 'wrapped' => true];
    }
}
