<?php

namespace Agoodley\LibrenmsTopology\History;

/**
 * The commands of the topology history and what the LibreNMS scheduler runs, kept apart from the
 * Laravel classes so the unit tests can check that every scheduled command line fits its command.
 */
class Jobs
{
    public const SNAPSHOT = 'topology:snapshot
        {--no-checks : only record the topology, skip the background checks}
        {--baseline : then save the recorded topology as the baseline of every device}';

    public const CHECKS = 'topology:checks
        {analysis?* : stp, links, redundancy or routing (default: those of the Background checks setting)}';

    /**
     * Run every History interval: command => parameters as Schedule::command() takes them. One
     * entry: the snapshot runs the checks after it, one at a time, so they read the finished
     * snapshot and the memory of one process at a time is needed.
     */
    public const SCHEDULED = ['topology:snapshot' => []];

    /**
     * Read each kind of fact, skipping a kind whose reader fails (a read error is no empty kind:
     * its facts stay as they were until the next snapshot).
     *
     * @param  array<string, callable(): array>  $readers  kind => reader
     * @param  callable(string, \Throwable): void  $failed  told about each kind skipped
     * @return \Generator<string, array> kind => what its reader returned, one kind at a time
     */
    public static function read(array $readers, callable $failed): \Generator
    {
        foreach ($readers as $kind => $reader) {
            try {
                $facts = $reader();
            } catch (\Throwable $e) {
                $failed($kind, $e);
                continue;
            }
            yield $kind => $facts;
        }
    }

    /**
     * Run a job for each item, the next ones also when one fails or throws (a check that times out).
     *
     * @param  string[]  $items
     * @param  callable(string): bool  $run  true when the item worked
     * @return array<string, ?string> item => null when it worked, else why not
     */
    public static function each(array $items, callable $run): array
    {
        $results = [];
        foreach ($items as $item) {
            try {
                $results[$item] = $run($item) ? null : 'failed';
            } catch (\Throwable $e) {
                $results[$item] = $e->getMessage();
            }
        }

        return $results;
    }
}
