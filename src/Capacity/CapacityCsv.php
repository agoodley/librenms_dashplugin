<?php

namespace Agoodley\LibrenmsTopology\Capacity;

/**
 * The rows of the port capacity exports (Support\Csv): a list of ports, the switches or the locations of an
 * analysis (CapacityAnalyzer::analyze()), a header first. Times are ISO 8601 with the server's offset. Pure PHP.
 */
class CapacityCsv
{
    /** What can be exported. */
    public const KINDS = ['ports', 'switches', 'locations'];

    private const STATES = [
        CapacityAnalyzer::IN_USE => 'in use', CapacityAnalyzer::FREE => 'free', CapacityAnalyzer::SHUTDOWN => 'shut down',
        CapacityAnalyzer::ERR_DISABLED => 'err-disabled', CapacityAnalyzer::UPLINK => 'uplink (not counted)', CapacityAnalyzer::TRUNK => 'trunk (not counted)',
        CapacityAnalyzer::NOT_PRESENT => 'not present (not counted)', CapacityAnalyzer::EXCLUDED => 'excluded by the settings',
    ];

    private const KINDS_OF_AGE = [CapacityAnalyzer::EXACT => 'exact', CapacityAnalyzer::SAMPLED => 'sampled', CapacityAnalyzer::AT_LEAST => 'at least'];

    /**
     * @return list<array<int, string|int|float|bool|null>>
     */
    public static function rows(string $kind, array $result, string $list = 'not_in_use'): array
    {
        $days = (int) $result['days'];

        return match ($kind) {
            'switches' => [self::countHeader('switch', ['location', 'uptime (days)'], $days), ...array_map(fn (array $device) => self::countRow([$device['name'],
                $device['location'], $device['uptime'] === null ? null : intdiv((int) $device['uptime'], 86400)], $device), $result['devices'])],
            'locations' => [self::countHeader('location', ['switches'], $days), ...array_map(fn (array $location) => self::countRow([
                $location['location_id'] === null ? '(no location)' : $location['name'], count($location['devices'])], $location), $result['locations'])],
            default => [
                ['switch', 'location', 'port', 'description', 'VLAN', 'speed (Mbps)', 'state', 'ifOperStatus', 'ifAdminStatus', 'down since', 'days down',
                    'down time', "unused $days+ days", 'PoE', 'err-disabled since', 'err-disable message', 'neighbor', 'LibreNMS port page'],
                ...array_map(fn (array $port) => [
                    $port['device'], $port['location'], $port['name'], $port['alias'], $port['vlan'], $port['speed'] ? intdiv((int) $port['speed'], 1000000) : null,
                    self::STATES[$port['state']] ?? $port['state'], $port['oper'], $port['admin'], self::time($port['down_since']), $port['age_days'],
                    self::KINDS_OF_AGE[$port['age_kind']] ?? '', $port['unused'] === null ? '' : $port['unused'], $port['poe'] === null ? '' : $port['poe'],
                    self::time($port['err']['time'] ?? null), $port['err']['message'] ?? '', $port['neighbor'], $port['url'],
                ], CapacityAnalyzer::filter($result['ports'], $list)),
            ],
        };
    }

    private static function countHeader(string $what, array $more, int $days): array
    {
        return [$what, ...$more, 'access ports', 'in use', 'use %', 'available', 'free', 'shut down', 'err-disabled', "unused $days+ days",
            "down under $days days", 'down time unknown', 'PoE ports', 'PoE available', 'uplinks', 'trunks', 'not present', 'excluded'];
    }

    private static function countRow(array $first, array $counts): array
    {
        return [...$first, $counts['ports'], $counts['in_use'], $counts['use_percent'], $counts['available'], $counts['free'], $counts['shutdown'],
            $counts['err_disabled'], $counts['unused'], $counts['recent'], $counts['unknown_age'], $counts['poe'], $counts['poe_available'],
            $counts['uplinks'], $counts['trunks'], $counts['not_present'], $counts['excluded']];
    }

    private static function time(?int $time): string
    {
        return $time === null ? '' : date('c', $time);
    }
}
