<?php

namespace Agoodley\LibrenmsTopology\History;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Illuminate\Support\Facades\Log;

/**
 * Marks on the diagnostics pages from the findings history of the background checks: when a
 * finding was first reported, and whether that was in their latest run ("new").
 */
class FindingBadges
{
    /**
     * Add `history` (first_seen, new) to the findings of an analysis result that the background
     * checks know; the result is unchanged without history.
     *
     * @param  int  $maxAge  seconds after which the latest run is too old for "new" marks (the checks stopped)
     */
    public static function annotate(string $analysis, array $result, HistoryRepository $history, int $maxAge): array
    {
        if (empty($result['findings']) || ! in_array($analysis, HistoryService::ANALYSES, true) || ! $history->available()) {
            return $result;
        }

        try {
            $latest = $history->latestRun($analysis)['started_at'] ?? null;
            $hashes = array_map(fn (array $finding) => FindingHistory::hash($analysis, $finding), $result['findings']);
            $known = $history->findingsByHash($hashes);
            foreach ($result['findings'] as $index => $finding) {
                $row = $known[$hashes[$index]] ?? null;
                if ($row !== null) {
                    $result['findings'][$index]['history'] = [
                        'first_seen' => $row['first_seen'],
                        'new' => self::isNew($row, $latest, time(), $maxAge),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read the findings history: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Was the finding first reported by the latest run of the checks, and is that run recent?
     *
     * @param  array{first_seen: string, initial: bool}  $row  its open history row
     * @param  string|null  $latest  start of the latest finished run of the checks
     */
    public static function isNew(array $row, ?string $latest, int $now, int $maxAge): bool
    {
        return $latest !== null && ! $row['initial'] && $row['first_seen'] >= $latest && $now - (int) strtotime($latest) <= $maxAge;
    }
}
