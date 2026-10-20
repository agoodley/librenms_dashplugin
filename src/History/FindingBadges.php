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
     */
    public static function annotate(string $analysis, array $result, HistoryRepository $history): array
    {
        if (empty($result['findings']) || ! in_array($analysis, HistoryService::ANALYSES, true) || ! $history->available()) {
            return $result;
        }

        try {
            $latest = $history->latestRuns()['checks']['started_at'] ?? null;
            $hashes = array_map(fn (array $finding) => FindingHistory::hash($analysis, $finding), $result['findings']);
            $known = $history->findingsByHash($hashes);
            foreach ($result['findings'] as $index => $finding) {
                $row = $known[$hashes[$index]] ?? null;
                if ($row !== null) {
                    $result['findings'][$index]['history'] = [
                        'first_seen' => $row['first_seen'],
                        'new' => $latest !== null && ! $row['initial'] && $row['first_seen'] >= $latest,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot read the findings history: ' . $e->getMessage());
        }

        return $result;
    }
}
