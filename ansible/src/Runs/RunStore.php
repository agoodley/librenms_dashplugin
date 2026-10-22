<?php

namespace Agoodley\LibrenmsAnsible\Runs;

/**
 * Where the run lifecycle keeps runs (Data\RunRepository; an in-memory one in the tests). Every change of a run is a
 * compare-and-set on its version, so a refresh that took long never writes over what another one wrote meanwhile.
 */
interface RunStore
{
    /**
     * One run, its JSON columns decoded; null when there is none.
     */
    public function find(int $id): ?array;

    /**
     * The run's targets (and the flagged hosts it ran on that were not targets).
     *
     * @return array<int, array> device_id => row (counts decoded, unexpected as bool)
     */
    public function targets(int $runId): array;

    /**
     * Change a run that is still at $version, bumping the version; $appendOutput is added to the end of its stored
     * log in the same change. False when the run changed meanwhile: nothing was written.
     *
     * @param  array<string, mixed>  $values  column => value (JSON columns as arrays)
     */
    public function updateAt(int $id, int $version, array $values, string $appendOutput = ''): bool;

    /**
     * Store each target's result.
     *
     * @param  array<int, array{result: string, counts: array<string, int>|null}>  $results  device_id => result
     */
    public function setResults(int $runId, array $results): void;

    /**
     * Record devices the runner ran on that were not targets, flagged as such, with their result.
     *
     * @param  array<int, array{inventory_name: string, hostname: string, display: string, result: string, counts: array<string, int>|null}>  $rows  device_id => row
     */
    public function addUnexpected(int $runId, array $rows): void;
}
