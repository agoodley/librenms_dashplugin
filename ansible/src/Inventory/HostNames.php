<?php

namespace Agoodley\LibrenmsAnsible\Inventory;

/**
 * Inventory names of the devices. The plugin builds the run's limit from these names only, so they must be
 * plain host names to Ansible: no pattern characters (, : ! & * ? [ ] ~ @), and never the name of a group
 * (a limit naming a group selects all of its hosts). A device whose hostname is not such a name, or is
 * taken, is called device<id>.
 */
final class HostNames
{
    /** Names Ansible gives a meaning of its own. */
    public const RESERVED = ['all', 'ungrouped', 'localhost'];

    /**
     * Letters, digits, ".", "_" and "-", not starting with "-" or ".", at most 253 characters: a name Ansible
     * takes as one host, never as a pattern (IPv6 addresses and names with spaces or other characters are not).
     */
    public static function isSafe(string $name): bool
    {
        return strlen($name) <= 253 && preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]*$/D', $name) === 1;
    }

    /**
     * The fallback name of a device.
     */
    public static function fallback(int $deviceId): string
    {
        return 'device' . $deviceId;
    }

    /**
     * Inventory names for all devices: the hostname when it is safe, not reserved, not a group name and not
     * of the form device<number> (the fallbacks), else device<id>. A name used twice (ignoring case) goes to
     * the device with the lowest id. Computed over every device, so a device keeps its name whichever devices
     * a user may see.
     *
     * @param  array<int, string>  $hostnames  device_id => LibreNMS hostname
     * @param  string[]  $taken  names hosts may not have (the group names)
     * @return array<int, string> device_id => inventory name
     */
    public static function assign(array $hostnames, array $taken = []): array
    {
        ksort($hostnames);
        $used = array_fill_keys(array_map('strtolower', array_merge(self::RESERVED, $taken)), true);
        $names = [];
        foreach ($hostnames as $deviceId => $hostname) {
            $hostname = (string) $hostname;
            $key = strtolower($hostname);
            if (self::isSafe($hostname) && ! isset($used[$key]) && ! preg_match('/^device\d+$/D', $key)) {
                $names[(int) $deviceId] = $hostname;
                $used[$key] = true;
            } else {
                $names[(int) $deviceId] = self::fallback((int) $deviceId);
            }
        }

        return $names;
    }
}
