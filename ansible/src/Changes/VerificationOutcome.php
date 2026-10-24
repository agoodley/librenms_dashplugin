<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * The outcome of a change's verification, from its batches as they ended (pure): verified (every batch that ran was
 * verified, nothing unexpected changed), accepted (verified with accepted changes: an admin accepted what a batch
 * changed), or not verified (a batch that ran could not be verified, made unexpected changes nobody accepted, failed or
 * was stopped before its verification ended). Null when no batch ran.
 *
 * A change applied with Verify changes off, or without the topology plugin's verification API, is not verified from its
 * Apply on (ChangeLifecycle::apply()): its outcome is not verified with why (SWITCHED_OFF, the topology plugin not
 * installed, too old) and stays so. One applied before the upgrade to phase 4 goes on as in phase 3 (its first batch
 * ran before it: ChangeLifecycle::appliedBefore()), and ends not verified (BEFORE_UPGRADE).
 */
final class VerificationOutcome
{
    public const VERIFIED = 'verified';

    public const ACCEPTED = 'accepted';

    public const NOT_VERIFIED = 'not_verified';

    /** Why a change applied with Verify changes off is not verified: it ran as in phase 3. */
    public const SWITCHED_OFF = 'switched off';

    /** Why a change applied before the upgrade to phase 4 is not verified: its first batch ran before it, and it goes on as in phase 3. */
    public const BEFORE_UPGRADE = 'applied before the upgrade';

    /**
     * @param  array<int, array>  $batches  the change's batches (ChangeStore::batches())
     * @return array{outcome: ?string, note: ?string}
     */
    public static function of(array $batches): array
    {
        $ran = 0;
        $why = [];
        $accepted = [];
        $older = [];
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
                // verified from a state before it on older data (its wait for fresh data over): the outcome says so
                if (($was = VerificationText::older($verify)) !== null) {
                    $older[] = "batch $number on data older than $was before it";
                }
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
        $older = $older ? implode('; ', array_slice($older, 0, 3)) . (count($older) > 3 ? '; ...' : '') : null;
        if ($accepted) {
            return ['outcome' => self::ACCEPTED, 'note' => 'what ' . (count($accepted) === 1 ? 'batch ' . $accepted[0] : 'batches ' . implode(', ', $accepted)) . ' changed was accepted'
                . ($older !== null ? "; $older" : '')];
        }

        return ['outcome' => self::VERIFIED, 'note' => $older];
    }
}
