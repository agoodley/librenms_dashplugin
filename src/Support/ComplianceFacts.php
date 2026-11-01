<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * What the compliance plugin (compliance/ of this repository) read in the configuration backups, on the spanning tree
 * page: the findings that suspect edge ports without portfast (ports with many transitions to forwarding, the likely
 * sources of a high rate of topology changes) say what the backups configure on those ports, which confirms the guess
 * or not. Only when that plugin is installed with its API (major version 1), enabled, and lets the user read
 * configuration backups; otherwise the page is as it was. The topology plugin uses that plugin's API class only, and
 * never changes its own findings (their texts, history and background checks) for it.
 */
final class ComplianceFacts
{
    /** The compliance plugin's API for other plugins. */
    public const API = 'Agoodley\\LibrenmsCompliance\\Api\\Compliance';

    /** The spanning tree checks whose findings name ports that may lack portfast. */
    public const CHECKS = ['port_flapping', 'tc_rate'];

    /** Most ports a part of the line names. */
    private const NAMED = 8;

    /**
     * The analysis' findings with what the backups say of their ports (a "compliance" line), on the spanning tree page.
     */
    public static function annotate(string $analysis, array $result, mixed $user): array
    {
        if ($analysis !== 'stp' || ! class_exists(self::API) || (int) explode('.', (string) constant(self::API . '::VERSION'))[0] !== 1) {
            return $result;
        }
        $devices = [];
        foreach ($result['findings'] ?? [] as $finding) {
            if (in_array($finding['code'] ?? '', self::CHECKS, true) && ! empty($finding['ports'])) {
                foreach ($finding['devices'] ?? [] as $deviceId) {
                    $devices[(int) $deviceId] = true;
                }
            }
        }
        if (! $devices) {
            return $result;
        }
        try {
            $facts = (self::API)::forUser($user)->ports(array_keys($devices));
        } catch (\Throwable) {
            return $result; // the compliance plugin is disabled, or its tables are missing
        }
        $ports = [];
        foreach ($facts as $deviceId => $device) {
            foreach ($device['ports'] ?? [] as $portId => $port) {
                $ports[(int) $portId] = $port + ['device_id' => (int) $deviceId];
            }
        }
        foreach ($result['findings'] as $index => $finding) {
            if (in_array($finding['code'] ?? '', self::CHECKS, true)) {
                $line = self::line($finding['ports'] ?? [], $ports, $result['device_names'] ?? []);
                if ($line !== null) {
                    $result['findings'][$index]['compliance'] = $line;
                }
            }
        }

        return $result;
    }

    /**
     * What the backups configure on these ports: those without portfast that face a host (portfast would end their
     * topology changes), those that have it, those that face a switch (portfast is no fix there); null when the backups
     * say nothing of them.
     *
     * @param  int[]  $portIds
     * @param  array<int, array{device_id: int, name: string, mode: string, portfast: string, role: string}>  $ports  the compliance API's facts by port id
     * @param  array<int, string>  $names  device id => name
     */
    public static function line(array $portIds, array $ports, array $names): ?string
    {
        $off = $on = $switch = [];
        foreach ($portIds as $portId) {
            $port = $ports[(int) $portId] ?? null;
            if ($port === null) {
                continue;
            }
            $name = trim(($names[$port['device_id']] ?? '#' . $port['device_id']) . ' ' . $port['name']);
            if ($port['role'] !== 'host') {
                $switch[] = $name;
            } elseif ($port['portfast'] === 'off') {
                $off[] = $name . ($port['mode'] === 'trunk' ? ' (a trunk)' : '');
            } else {
                $on[] = $name;
            }
        }
        $parts = [];
        if ($off) {
            $parts[] = 'no portfast on ' . self::list($off) . ', facing a host: portfast would end ' . (count($off) === 1 ? 'its' : 'their') . ' topology changes';
        }
        if ($on) {
            $parts[] = 'portfast already on ' . self::list($on);
        }
        if ($switch) {
            $parts[] = self::list($switch) . (count($switch) === 1 ? ' faces' : ' face') . ' a switch: portfast is no fix there, the link is';
        }

        return $parts ? 'The configuration backups (compliance plugin): ' . implode('; ', $parts) . '.' : null;
    }

    /**
     * @param  string[]  $names
     */
    private static function list(array $names): string
    {
        $shown = array_slice($names, 0, self::NAMED);
        $more = count($names) - count($shown);
        $last = $more > 0 ? $more . ' more' : array_pop($shown);

        return $shown ? implode(', ', $shown) . ' and ' . $last : (string) $last;
    }
}
