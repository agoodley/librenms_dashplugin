<?php

namespace Agoodley\LibrenmsAnsible\Services;

/**
 * What makes a launch a run of a change (Changes\ChangeLifecycle): the change, its dry run (--check --diff) or one of
 * its batches, the text its eventlog entries carry ("change #5, batch 1 of 2"), the commit of the repository to run
 * (the dry run's), and a check of the template's snapshot right before the run is recorded (the change's fingerprint).
 * Each run of a change has a one-time token of its own, so it is launched once at most. The token is made of the
 * change's id and its own random token (the one its creation form had), so a change that gets the id of an earlier one
 * (the change tables dropped by a rollback and made again) never takes over that change's runs; a run found by a token
 * counts only when it is a run of the same change, kind and batch (belongs()).
 */
final class RunSpec
{
    public const DRY_RUN = 'dry_run';

    public const BATCH = 'batch';

    /**
     * @param  int  $number  the dry run's attempt, or the batch's number
     * @param  \Closure(array): ?string|null  $verify  why the run may not be launched with this template snapshot (TemplateSnapshot::parts()), null when it may
     * @param  (\Closure(): ?string)|null  $halt  why the launch must not go on now (the change's stop was asked for), null when it may: asked right
     *                                            before the run is recorded (under the launch lock) and right before the runner is asked to start it
     */
    public function __construct(
        public readonly int $changeId,
        public readonly string $kind,
        public readonly int $number,
        public readonly string $context,
        public readonly ?string $commit = null,
        public readonly ?\Closure $verify = null,
        public readonly ?\Closure $halt = null,
        public readonly string $salt = '',
    ) {
    }

    /**
     * Why the launch must not go on now; null when it may (or nothing can stop it).
     */
    public function halted(): ?string
    {
        return $this->halt !== null ? ($this->halt)() : null;
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
        return self::tokenOf($this->changeId, $this->kind, $this->number, $this->salt);
    }

    /**
     * The one-time token of a run of a change; $salt: the change's own random token (saltOf()).
     */
    public static function tokenOf(int $changeId, string $kind, int $number, string $salt): string
    {
        return hash('sha256', "ansible-change:$changeId:$salt:$kind:$number");
    }

    /**
     * A change's own random component of its runs' tokens: the token of the form that created it (unique per change), or
     * its creation time when it has none.
     */
    public static function saltOf(array $change): string
    {
        return (string) (($change['request_token'] ?? null) ?: 'created ' . ($change['created_at'] ?? ''));
    }

    /**
     * Is the run, found by this spec's token, a run of this change, kind and batch? A run of something else that holds the
     * token is never taken for this one.
     */
    public function belongs(?array $run): bool
    {
        return $run !== null && (int) ($run['change_id'] ?? 0) === $this->changeId && ($run['kind'] ?? null) === $this->kind
            && ($this->kind !== self::BATCH || (int) ($run['batch'] ?? 0) === $this->number);
    }
}
