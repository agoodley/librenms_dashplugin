<?php

namespace Agoodley\LibrenmsInventory\Reports;

use Agoodley\LibrenmsInventory\Software\Versions;
use Agoodley\LibrenmsInventory\Support\Origin;

/**
 * The summaries of the inventory: the models across the network, the software versions and the optics by kind. Pure
 * PHP: the data comes in as arrays.
 */
final class Summaries
{
    /** Shown for a value LibreNMS does not have. */
    public const UNKNOWN = '(unknown)';

    /**
     * The models across the network, each unit counted: the chassis of a device's hardware inventory (each member of a
     * stack, by its own model) and, for a device without a chassis that names its model, the model LibreNMS has for the
     * device. The most common first.
     *
     * @param  array<int, array>  $devices  device_id => hardware, os, version
     * @param  array<int, list<array{model: string, serial: string}>>  $chassis  device_id => its chassis
     * @return list<array{model: string, units: int, devices: int[], os: string[], versions: array<string, int>}>
     */
    public static function models(array $devices, array $chassis): array
    {
        $models = [];
        foreach ($devices as $deviceId => $device) {
            $units = array_values(array_filter(array_map(fn (array $unit) => trim((string) ($unit['model'] ?? '')), $chassis[$deviceId] ?? []),
                fn (string $model) => $model !== ''));
            if (! $units) {
                $units = [trim((string) ($device['hardware'] ?? '')) ?: self::UNKNOWN];
            }
            $version = trim((string) ($device['version'] ?? '')) ?: self::UNKNOWN;
            foreach ($units as $model) {
                $row = &$models[$model];
                $row ??= ['model' => $model, 'units' => 0, 'devices' => [], 'os' => [], 'versions' => []];
                $row['units']++;
                $row['devices'][(int) $deviceId] = (int) $deviceId;
                $row['os'][(string) ($device['os'] ?? '')] = true;
                $row['versions'][$version] = ($row['versions'][$version] ?? 0) + 1;
                unset($row);
            }
        }
        foreach ($models as &$row) {
            $row['devices'] = array_values($row['devices']);
            $row['os'] = array_values(array_filter(array_keys($row['os']), fn ($os) => $os !== ''));
            uksort($row['versions'], fn ($a, $b) => self::compareVersions((string) $b, (string) $a));
        }
        unset($row);
        $models = array_values($models);
        usort($models, fn ($a, $b) => [$a['model'] === self::UNKNOWN, -$a['units'], strtolower($a['model'])]
            <=> [$b['model'] === self::UNKNOWN, -$b['units'], strtolower($b['model'])]);

        return $models;
    }

    /**
     * The software versions across the network: each os and version with its devices and their models, by os and the
     * newest version first.
     *
     * @param  array<int, array>  $devices  device_id => os, version, hardware
     * @param  array<string, string>  $osNames  os => the name LibreNMS gives it (Cisco IOS)
     * @return list<array{os: string, os_name: string, version: string, devices: int[], models: array<string, int>}>
     */
    public static function versions(array $devices, array $osNames = []): array
    {
        $versions = [];
        foreach ($devices as $deviceId => $device) {
            $os = (string) ($device['os'] ?? '');
            $version = trim((string) ($device['version'] ?? '')) ?: self::UNKNOWN;
            $model = trim((string) ($device['hardware'] ?? '')) ?: self::UNKNOWN;
            $row = &$versions[$os . "\0" . $version];
            $row ??= ['os' => $os, 'os_name' => $osNames[$os] ?? $os, 'version' => $version, 'devices' => [], 'models' => []];
            $row['devices'][] = (int) $deviceId;
            $row['models'][$model] = ($row['models'][$model] ?? 0) + 1;
            unset($row);
        }
        foreach ($versions as &$row) {
            arsort($row['models']);
        }
        unset($row);
        $versions = array_values($versions);
        usort($versions, fn ($a, $b) => strcasecmp($a['os_name'], $b['os_name']) ?: (($a['version'] === self::UNKNOWN) <=> ($b['version'] === self::UNKNOWN))
            ?: self::compareVersions($b['version'], $a['version']));

        return $versions;
    }

    /**
     * The optics by kind (type, vendor, model, wavelength), with how many of each, on how many devices, and their
     * origin: the most common first.
     *
     * @param  iterable<array>  $optics  rows with device_id, type, vendor, model, wavelength, origin
     * @return list<array{type: string, vendor: string, model: string, wavelength: ?int, count: int, devices: int, origin: array<string, int>}>
     */
    public static function optics(iterable $optics): array
    {
        $kinds = [];
        foreach ($optics as $optic) {
            $wavelength = isset($optic['wavelength']) && (int) $optic['wavelength'] > 0 ? (int) $optic['wavelength'] : null;
            $key = implode("\0", [mb_strtolower(trim((string) ($optic['type'] ?? ''))), mb_strtolower(trim((string) ($optic['vendor'] ?? ''))),
                mb_strtolower(trim((string) ($optic['model'] ?? ''))), (string) $wavelength]);
            $row = &$kinds[$key];
            $row ??= ['type' => trim((string) ($optic['type'] ?? '')), 'vendor' => trim((string) ($optic['vendor'] ?? '')),
                'model' => trim((string) ($optic['model'] ?? '')), 'wavelength' => $wavelength, 'count' => 0, 'devices' => [], 'origin' => []];
            $row['count']++;
            $row['devices'][(int) ($optic['device_id'] ?? 0)] = true;
            $origin = $optic['origin'] ?? null ?: 'unknown';
            $row['origin'][$origin] = ($row['origin'][$origin] ?? 0) + 1;
            unset($row);
        }
        $kinds = array_map(fn (array $row) => array_replace($row, ['devices' => count($row['devices'])]), array_values($kinds));
        usort($kinds, fn ($a, $b) => [-$a['count'], strtolower($a['type']), strtolower($a['model'])] <=> [-$b['count'], strtolower($b['type']), strtolower($b['model'])]);

        return $kinds;
    }

    /**
     * Two software versions in order (Software\Versions): number by number, each number as a number, so 15.2(7)E10 after
     * 15.2(4)E10, 17.12.1 after 17.9.4a, and 17.09.04a the same as 17.9.4a.
     */
    public static function compareVersions(string $a, string $b): int
    {
        return Versions::compare($a, $b);
    }

    /**
     * How many optics of these origins (Support\Origin), with unknown for those without one.
     *
     * @param  iterable<array>  $optics
     * @return array{genuine: int, third-party: int, unknown: int}
     */
    public static function origins(iterable $optics): array
    {
        $counts = [Origin::GENUINE => 0, Origin::THIRD_PARTY => 0, 'unknown' => 0];
        foreach ($optics as $optic) {
            $counts[$optic['origin'] ?? null ?: 'unknown']++;
        }

        return $counts;
    }
}
