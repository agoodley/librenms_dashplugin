<?php

namespace Agoodley\LibrenmsAnsible\Services;

/**
 * What makes a launch a run of a change (Changes\ChangeLifecycle): the change, its dry run (--check --diff) or one of
 * its batches, the text its eventlog entries carry ("change #5, batch 1 of 2"), the commit of the repository to run
 * (the dry run's), and a check of the template's snapshot right before the run is recorded (the change's fingerprint).
 * Each run of a change has a one-time token of its own, so it is launched once at most.
 */
final class RunSpec
{
    public const DRY_RUN = 'dry_run';

    public const BATCH = 'batch';

    /**
     * @param  int  $number  the dry run's attempt, or the batch's number
     * @param  \Closure(array): ?string|null  $verify  why the run may not be launched with this template snapshot (TemplateSnapshot::parts()), null when it may
     */
    public function __construct(
        public readonly int $changeId,
        public readonly string $kind,
        public readonly int $number,
        public readonly string $context,
        public readonly ?string $commit = null,
        public readonly ?\Closure $verify = null,
    ) {
    }

    /**
     * Is it the change's dry run (--check --diff)?
     */
    public function isDryRun(): bool
    {
        return $this->kind === self::DRY_RUN;
    }

    /**
     * The one-time token of this run of the change (a run's request token).
     */
    public function token(): string
    {
        return self::tokenOf($this->changeId, $this->kind, $this->number);
    }

    /**
     * The one-time token of a run of a change.
     */
    public static function tokenOf(int $changeId, string $kind, int $number): string
    {
        return hash('sha256', "ansible-change:$changeId:$kind:$number");
    }
}
