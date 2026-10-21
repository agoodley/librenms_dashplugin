<?php

namespace Agoodley\LibrenmsTopology\History;

/**
 * Time window of the Changes page and the facts it reads.
 */
class Window
{
    /** Windows of the Changes page: key => [label, length in days]. */
    public const WINDOWS = ['1d' => ['last 24 hours', 1], '7d' => ['last 7 days', 7], '30d' => ['last 30 days', 30]];

    /**
     * The window a query asks for: `window` (1d, 7d, 30d), or `from` / `to` (custom, at most a
     * year, never past now; a date without a time as `to` includes that whole day). Anything
     * invalid gives the default window of 24 hours.
     *
     * @return array{0: int, 1: int, 2: string} from, to (timestamps), window key ('custom' for dates)
     */
    public static function resolve(array $query, int $now): array
    {
        $from = self::time($query['from'] ?? null);
        if ($from !== null) {
            $to = isset($query['to']) && $query['to'] !== '' ? self::time($query['to']) : $now;
            if ($to !== null && is_string($query['to'] ?? null) && strlen(trim($query['to'])) <= 10) {
                $to = (int) strtotime('tomorrow', $to) - 1; // a date includes that whole day
            }
            if ($to !== null && $from < $to) {
                $to = min($to, $now);

                return [max($from, (int) strtotime('-1 year', $to)), $to, 'custom'];
            }
        }

        $window = is_string($query['window'] ?? null) && isset(self::WINDOWS[$query['window']]) ? $query['window'] : '1d';

        return [$now - self::WINDOWS[$window][1] * 86400, $now, $window];
    }

    /**
     * Facts that started and facts that ended in the window, each read newest first and capped at
     * $limit rows, as one list in the order they were recorded. When a cap was reached, both lists
     * are cut after the later of their oldest times (`since`, excluded: the cap may have cut inside
     * that snapshot), so a change never loses its other half (a moved cable is a removed and an
     * added fact); the changes up to it are left out.
     *
     * @param  array<int, array>  $added  rows with id and first_seen (timestamp)
     * @param  array<int, array>  $removed  rows with id and removed_at (timestamp)
     * @return array{rows: array<int, array>, truncated: bool, since: ?int}
     */
    public static function merge(array $added, array $removed, int $limit): array
    {
        $since = null;
        if (count($added) >= $limit) {
            $since = min(array_column($added, 'first_seen'));
        }
        if (count($removed) >= $limit) {
            $since = max($since ?? 0, min(array_column($removed, 'removed_at')));
        }

        $rows = [];
        foreach ($added as $row) {
            if ($since === null || $row['first_seen'] > $since) {
                $rows[(int) $row['id']] ??= $row;
            }
        }
        foreach ($removed as $row) {
            if ($since === null || $row['removed_at'] > $since) {
                $rows[(int) $row['id']] ??= $row;
            }
        }
        ksort($rows);

        return ['rows' => array_values($rows), 'truncated' => $since !== null, 'since' => $since];
    }

    /**
     * Snapshots a cut window (merge()) leaves out, up to and with `since`, newest first: the facts
     * that started or ended per kind for the $lines newest of them, and those of all earlier ones
     * together.
     *
     * @param  array<int, array{time: int, kind: string, facts: int}>  $counts  facts per snapshot and kind, complete for
     *                                                                         the $lines newest snapshots at least
     * @param  array<string, int>  $totals  facts per kind of all the snapshots left out
     * @return array{snapshots: array<int, array{time: int, kinds: array<string, int>, facts: int}>,
     *     earlier: array{kinds: array<string, int>, facts: int}|null}
     */
    public static function dropped(array $counts, array $totals, int $lines): array
    {
        $snapshots = [];
        foreach ($counts as $count) {
            $snapshots[$count['time']][$count['kind']] = ($snapshots[$count['time']][$count['kind']] ?? 0) + $count['facts'];
        }
        krsort($snapshots);
        $order = array_flip(FactCollector::KINDS);
        $sorted = fn (array $kinds) => array_filter(array_replace(array_intersect_key($order, $kinds), $kinds));

        $listed = [];
        foreach (array_slice($snapshots, 0, $lines, true) as $time => $kinds) {
            $kinds = $sorted($kinds);
            $listed[] = ['time' => $time, 'kinds' => $kinds, 'facts' => array_sum($kinds)];
            foreach ($kinds as $kind => $facts) {
                $totals[$kind] = ($totals[$kind] ?? 0) - $facts;
            }
        }
        $earlier = $sorted(array_filter($totals, fn ($facts) => $facts > 0));

        return ['snapshots' => $listed, 'earlier' => $earlier ? ['kinds' => $earlier, 'facts' => array_sum($earlier)] : null];
    }

    private static function time(mixed $value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? null : $time;
    }
}
