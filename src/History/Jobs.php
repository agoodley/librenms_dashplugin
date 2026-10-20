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
        {analysis?* : stp, links or redundancy (default: those of the Background checks setting)}';

    /**
     * Run every History interval: command => parameters as Schedule::command() takes them. One
     * entry: the snapshot runs the checks after it, one at a time, so they read the finished
     * snapshot and the memory of one process at a time is needed.
     */
    public const SCHEDULED = ['topology:snapshot' => []];
}
