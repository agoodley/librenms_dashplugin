<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * The outcome of a change's verification, from its batches as they ended (pure): verified (every batch that ran was
 * verified, nothing unexpected changed), accepted (verified with accepted changes: an admin accepted what a batch
 * changed), or not verified (a batch that ran could not be verified, made unexpected changes nobody accepted, failed or
 * was stopped before its verification ended). Null when no batch ran, or the change was not verified at all.
 */
final class VerificationOutcome
{
    public const VERIFIED = 'verified';

    public const ACCEPTED = 'accepted';

    public const NOT_VERIFIED = 'not_verified';

    /**
     * @param  array<int, array>  $batches  the change's batches (ChangeStore::batches())
     * @return array{outcome: ?string, note: ?string}
     */
    public static function of(array $batches): array
    {
        $ran = 0;
        $why = [];
        $accepted = [];
        $any = false;
        foreach ($batches as $number => $batch) {
            if (! in_array($batch['status'] ?? null, ['done', 'failed', 'stopped'], true)) {
                continue;
            }
            $ran++;
            $verify = is_array($batch['verify'] ?? null) ? $batch['verify'] : null;
            $any = $any || $verify !== null;
            $result = $verify['result'] ?? null;
            if (($batch['status'] ?? null) !== 'done') {
                $why[] = "batch $number " . ($batch['status'] === 'failed' ? 'failed' : 'was stopped');
            } elseif ($result === 'verified') {
                continue;
            } elseif ($result === 'unexpected' && is_array($verify['accepted'] ?? null)) {
                $accepted[] = $number;
            } elseif ($result === 'unexpected') {
                $why[] = "batch $number made unexpected changes";
            } elseif ($result === 'unknown') {
                $why[] = "batch $number could not be verified";
            } else {
                $why[] = "the change ended before the verification of batch $number";
            }
        }
        if ($ran === 0 || ! $any) {
            return ['outcome' => null, 'note' => null];
        }
        if ($why) {
            return ['outcome' => self::NOT_VERIFIED, 'note' => implode('; ', array_slice($why, 0, 5)) . (count($why) > 5 ? '; ...' : '')];
        }
        if ($accepted) {
            return ['outcome' => self::ACCEPTED, 'note' => 'what ' . (count($accepted) === 1 ? 'batch ' . $accepted[0] : 'batches ' . implode(', ', $accepted)) . ' changed was accepted'];
        }

        return ['outcome' => self::VERIFIED, 'note' => null];
    }
}
