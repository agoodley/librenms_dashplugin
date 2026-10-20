<?php

namespace Agoodley\LibrenmsTopology\History;

use Agoodley\LibrenmsTopology\Stp\Finding;

/**
 * Findings history of the background checks. A finding is the same finding while its code,
 * devices, ports and VLAN stay the same; its wording and severity may change. A finding that goes
 * away is resolved, and if it comes back later it starts a new period.
 */
class FindingHistory
{
    /** Eventlog severity ranks: a finding is written when it is at least as severe as the setting. */
    private const RANKS = [Finding::INFO => 1, Finding::WARNING => 2, Finding::CRITICAL => 3];

    /** Devices whose eventlog gets the entry of one finding (the first ones of the finding). */
    public const EVENTLOG_DEVICES = 10;

    /**
     * Identity of a finding of an analysis.
     */
    public static function hash(string $analysis, array $finding): string
    {
        $devices = array_map('intval', $finding['devices'] ?? []);
        $ports = array_map('intval', $finding['ports'] ?? []);
        sort($devices);
        sort($ports);

        return sha1(implode('|', [
            $analysis,
            $finding['code'],
            $finding['vlan'] ?? '',
            implode(',', array_unique($devices)),
            implode(',', array_unique($ports)),
        ]));
    }

    /**
     * Compare the open findings of an analysis with the findings of this run.
     *
     * @param  array<int, array{id: int, hash: string, severity: string, title: string}>  $open  findings reported by the
     *                                                                                          previous run, oldest first
     * @param  array<int, array>  $findings  findings of this run
     * @return array{new: array<string, array>, resolved: array<int, array>, update: array<int, array>} new findings by hash,
     *                                                                                                  resolved rows, id => changed finding
     */
    public static function diff(string $analysis, array $open, array $findings): array
    {
        $current = [];
        foreach ($findings as $finding) {
            $current[self::hash($analysis, $finding)] ??= $finding;
        }

        $new = $current;
        $resolved = [];
        $update = [];
        $kept = [];
        foreach ($open as $row) {
            $hash = $row['hash'];
            if (! isset($current[$hash]) || isset($kept[$hash])) {
                $resolved[] = $row;
                continue;
            }
            $kept[$hash] = true;
            unset($new[$hash]);
            if ($row['severity'] !== $current[$hash]['severity'] || $row['title'] !== self::title($current[$hash])) {
                $update[(int) $row['id']] = $current[$hash];
            }
        }

        return ['new' => $new, 'resolved' => $resolved, 'update' => $update];
    }

    /**
     * Eventlog entries for new and resolved findings at or above $minimum severity ('none' writes
     * nothing): one entry per device of the finding, at most EVENTLOG_DEVICES.
     *
     * @param  array<int, array>  $new  new findings
     * @param  array<int, array>  $resolved  resolved rows (severity, title, devices as int[])
     * @return array<int, array{device_id: int, message: string, severity: string, reference: string}> severity: critical,
     *                                                                                                  warning, info or ok
     */
    public static function eventlog(string $analysis, array $new, array $resolved, string $minimum): array
    {
        $rank = self::RANKS[$minimum] ?? null;
        if ($rank === null) {
            return [];
        }

        $entries = [];
        foreach ($new as $finding) {
            if ((self::RANKS[$finding['severity']] ?? 0) < $rank) {
                continue;
            }
            $message = 'Topology ' . $finding['severity'] . ': ' . self::title($finding) . '. ' . trim((string) ($finding['detail'] ?? ''));
            foreach (self::eventlogDevices($finding['devices'] ?? [], $message) as [$device, $text]) {
                $entries[] = ['device_id' => $device, 'message' => $text, 'severity' => $finding['severity'], 'reference' => self::reference($analysis, $finding['code'])];
            }
        }
        foreach ($resolved as $row) {
            if ((self::RANKS[$row['severity']] ?? 0) < $rank) {
                continue;
            }
            $message = 'Topology resolved: ' . $row['title'];
            foreach (self::eventlogDevices($row['devices'] ?? [], $message) as [$device, $text]) {
                $entries[] = ['device_id' => $device, 'message' => $text, 'severity' => 'ok', 'reference' => self::reference($analysis, $row['code'])];
            }
        }

        return $entries;
    }

    /**
     * Title as stored and logged ("Single point of failure: dist1").
     */
    public static function title(array $finding): string
    {
        return mb_substr(ucfirst((string) $finding['title']), 0, 512);
    }

    /**
     * The devices that get the entry, and its text (with a count of the devices left out).
     *
     * @param  int[]  $devices
     * @return array<int, array{0: int, 1: string}>
     */
    private static function eventlogDevices(array $devices, string $message): array
    {
        $devices = array_values(array_unique(array_map('intval', $devices)));
        $more = count($devices) - self::EVENTLOG_DEVICES;
        $text = mb_substr($message, 0, 900) . ($more > 0 ? " (also on $more more devices)" : '');

        return array_map(fn ($device) => [$device, $text], array_slice($devices, 0, self::EVENTLOG_DEVICES));
    }

    private static function reference(string $analysis, string $code): string
    {
        return mb_substr("topology:$analysis:$code", 0, 64);
    }
}
