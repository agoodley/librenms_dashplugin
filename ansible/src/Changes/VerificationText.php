<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Agoodley\LibrenmsAnsible\Support\Format;

/**
 * Texts of a batch's verification (phase 4), for the change's timeline (they name devices and say what changed) and
 * for the eventlog of each target (they count, and name no other device): ChangeText's rules.
 */
final class VerificationText
{
    /** The outcome of a change's verification: label and colour. */
    public const OUTCOMES = ['verified' => ['success', 'verified'], 'accepted' => ['warning', 'verified with accepted changes'],
        'not_verified' => ['danger', 'not verified']];

    /** The settings' words for what a batch does after its verification. */
    public const DECISIONS = ['pause' => 'pause until an admin accepts', 'stop' => 'stop the change', 'record' => 'only record'];

    /** Most unexpected changes a timeline entry names. */
    private const MAX = 5;

    public static function label(?string $outcome): ?string
    {
        return $outcome === null ? null : (self::OUTCOMES[$outcome][1] ?? $outcome);
    }

    /**
     * The outcome as the Changes list shows it: its label, and "(switched off)" for a change applied with Verify changes
     * off, "(applied before the upgrade)" for one applied before phase 4 (why the others are not verified is the label's
     * title).
     */
    public static function listed(?string $outcome, ?string $note): ?string
    {
        return $outcome === null ? null : self::label($outcome) . match ($note) {
            VerificationOutcome::SWITCHED_OFF => ' (switched off)',
            VerificationOutcome::BEFORE_UPGRADE => ' (applied before the upgrade)',
            default => '',
        };
    }

    /**
     * Why a change is not verified, as its page says it: one applied without verification ($atApply: its outcome is its
     * Apply's) says that its batches run without one; one applied with Verify changes off says where; one applied before
     * the upgrade to phase 4, that its first batch ran before it.
     */
    public static function note(?string $note, bool $atApply = false): ?string
    {
        if ($note === VerificationOutcome::SWITCHED_OFF) {
            return 'switched off in the settings (Verify changes) when it was applied: its batches run without a verification';
        }
        if ($note === VerificationOutcome::BEFORE_UPGRADE) {
            return 'applied before the upgrade to phase 4: batch 1 ran before the upgrade, not verified, and the batches after it run as in phase 3, without a '
                . 'verification (whether a change is verified is decided at its Apply)';
        }

        return $note !== null && $atApply ? "$note when it was applied: its batches run without a verification" : $note;
    }

    public static function css(?string $outcome): string
    {
        return self::OUTCOMES[$outcome ?? ''][0] ?? 'default';
    }

    /**
     * The refresh asked before a batch (or after it), in words.
     *
     * @param  string[]  $names  the devices LibreNMS was asked to discover again
     */
    public static function asked(string $how, array $names, int $deadline, string $for): string
    {
        $list = self::list($names);

        return match ($how) {
            'dispatch', 'rediscover' => 'LibreNMS was asked to discover ' . $list . ' again for ' . $for . ' (' . ($how === 'dispatch' ? 'a discovery job each' : 'marked for rediscovery')
                . '); the plugin waits for fresh data until ' . Clock::text($deadline),
            default => 'the plugin waits for LibreNMS\' own discovery and polling of ' . $list . ' for ' . $for . ', until ' . Clock::text($deadline),
        };
    }

    /**
     * A state taken, in words: "8 devices, 25 facts, 18 findings".
     */
    public static function stateSize(array $state): string
    {
        return Format::count(count((array) ($state['devices'] ?? [])), 'device') . ', ' . Format::count(count((array) ($state['facts'] ?? [])), 'fact') . ', '
            . Format::count(count((array) ($state['findings'] ?? [])), 'finding');
    }

    /**
     * Why a batch cannot be verified, for the eventlog of each target (no device named).
     */
    public static function unknownEvent(array $change, int $batch, string $why): string
    {
        return ChangeText::name($change) . ": batch $batch cannot be verified ($why)";
    }

    /**
     * What the comparison after a batch found, and what the change does about it, for the timeline.
     *
     * @param  list<array{text: string}>  $unexpected
     * @param  string  $decision  pause, stop, record, last (the last batch: nothing is left to hold)
     */
    public static function unexpected(int $batch, array $unexpected, int $count, string $decision, string $accepter): string
    {
        $texts = array_map(fn (array $entry) => UntrustedText::line((string) $entry['text'], 300), array_slice($unexpected, 0, self::MAX));

        return "Batch $batch made " . Format::count($count, 'unexpected change') . ': ' . implode('; ', $texts) . ($count > self::MAX ? '; and ' . ($count - self::MAX) . ' more (on the change page)' : '')
            . '. ' . self::then($batch, $decision, $accepter, 'them', 'When a batch makes unexpected changes');
    }

    /**
     * A batch that cannot be verified, and what the change does about it, for the timeline: paused, the ways it goes on (it
     * is verified when it is checked again, $recheck: "every minute"; or an admin accepts going on without it).
     */
    public static function unknown(int $batch, string $why, string $decision, string $accepter, ?string $recheck = null): string
    {
        if ($decision === 'pause' && $recheck !== null) {
            return "Batch $batch cannot be verified: $why. The change waits until batch $batch is verified (it is checked again $recheck; the change then goes on by "
                . "itself) or $accepter accepts going on without its verification: batch " . ($batch + 1) . ' does not start before (setting When a batch cannot be verified: pause)';
        }

        return "Batch $batch cannot be verified: $why. " . self::then($batch, $decision, $accepter, 'going on without its verification', 'When a batch cannot be verified');
    }

    /**
     * What the change does after a batch that was not verified, in words.
     */
    private static function then(int $batch, string $decision, string $accepter, string $what, string $setting): string
    {
        return match ($decision) {
            'pause' => 'The change waits for ' . $accepter . ' to accept ' . $what . ': batch ' . ($batch + 1) . " does not start before (setting $setting: pause)",
            'stop' => "The change ends (setting $setting: stop the change)",
            'record' => "Recorded; the change goes on (setting $setting: only record)",
            default => 'It is the last batch: the change ends, not verified (nothing is left to hold)',
        };
    }

    /**
     * The same, counted, for the eventlog of each target.
     *
     * @param  array<string, int>  $counts  what: event, finding, device, fix => how many
     */
    public static function unexpectedEvent(array $change, int $batch, array $counts, string $decision): string
    {
        $parts = [];
        foreach (['event' => ['topology change', null], 'finding' => ['finding', null], 'device' => ['device down', 'devices down'],
            'fix' => ['part of the fix not done', 'parts of the fix not done']] as $what => [$word, $plural]) {
            if (($counts[$what] ?? 0) > 0) {
                $parts[] = Format::count($counts[$what], $word, $plural);
            }
        }

        return ChangeText::name($change) . ": batch $batch made " . Format::count(array_sum($counts), 'unexpected change') . ($parts ? ' (' . implode(', ', $parts) . ')' : '') . ': '
            . match ($decision) {
                'pause' => 'the change waits for an admin to accept them',
                'stop' => 'the change ends',
                'record' => 'recorded, the change goes on',
                default => 'the change ends, not verified',
            };
    }

    /**
     * A batch verified, for the timeline.
     *
     * @param  array<string, int>  $counts  VerificationRules::evaluate()'s
     */
    /**
     * @param  ?string  $older  what the data of its state before it was older than ("the Apply"), when it was (older())
     */
    public static function verified(int $batch, array $counts, ?string $older = null): string
    {
        $expected = (int) ($counts['expected'] ?? 0);

        return "Batch $batch verified" . ($older !== null ? ", on data older than $older before it" : '') . ': nothing unexpected changed ('
            . Format::count((int) ($counts['facts'] ?? 0), 'fact') . ' and ' . Format::count((int) ($counts['findings'] ?? 0), 'finding')
            . ' compared on fresh data' . ($older !== null ? ' after it' : '') . ($expected ? '; ' . Format::count($expected, 'change') . ' the fix was made for' : '')
            . (($counts['listed'] ?? 0) ? '; ' . Format::count((int) $counts['listed'], 'other change') . ' listed on the change page' : '') . ')';
    }

    /**
     * What the data of a batch's state before it was older than (its wait for fresh data over: "the Apply", "the request
     * for it at ..."), null when it was fresh or LibreNMS' data as it is (Fresh data: wait).
     *
     * @param  array<string, mixed>  $verify  the batch's verification
     */
    public static function older(array $verify): ?string
    {
        $before = (array) ($verify['before'] ?? []);
        $older = $before['older'] ?? null;

        return ! empty($before['stale']) && is_string($older) && $older !== '' ? UntrustedText::line($older, 120) : null;
    }

    public static function verifiedEvent(array $change, int $batch): string
    {
        return ChangeText::name($change) . ": batch $batch verified: nothing unexpected changed";
    }

    /**
     * An admin accepted what a batch changed (or that it is not verified), for the timeline.
     */
    public static function accepted(int $batch, string $by, bool $unknown, int $count, string $comment): string
    {
        return UntrustedText::line($by, 60) . ($unknown ? " accepted going on without the verification of batch $batch" : " accepted the " . Format::count($count, 'unexpected change')
            . " of batch $batch") . ($comment !== '' ? ': "' . $comment . '"' : '') . '; the change goes on';
    }

    /**
     * The same for the eventlog of each target, with the comment as written (as an approval's and a blast radius
     * acceptance's are): it names no other device unless the comment does.
     */
    public static function acceptedEvent(array $change, int $batch, string $by, bool $unknown, int $count, string $comment): string
    {
        return ChangeText::name($change) . ': ' . UntrustedText::line($by, 60) . ($unknown ? " accepted going on without the verification of batch $batch"
            : ' accepted the ' . Format::count($count, 'unexpected change') . " of batch $batch") . ChangeText::comment($comment);
    }

    /**
     * The outcome of a change's verification, for its timeline and the eventlog.
     */
    public static function outcome(string $outcome, ?string $note): string
    {
        return 'Verification: ' . self::label($outcome) . ($note !== null && $note !== '' ? ' (' . $note . ')' : '');
    }

    /**
     * Names in a list: "lab-sw4, lab-sw2 and 3 more".
     *
     * @param  string[]  $names
     */
    public static function list(array $names, int $max = 5): string
    {
        $names = array_map(fn ($name) => UntrustedText::line((string) $name, 80), $names);

        return implode(', ', array_slice($names, 0, $max)) . (count($names) > $max ? ' and ' . (count($names) - $max) . ' more' : '');
    }
}
