<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * A task of the runner: its id, its status in the plugin's terms (Runs\RunStatus) and the runner's own, its times
 * (Unix timestamps), and the commit of the playbook repository it runs when the runner tells (Semaphore UI does for a
 * Git repository, not for a local directory).
 */
final class RunnerTask
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $rawStatus,
        public readonly ?int $started = null,
        public readonly ?int $ended = null,
        public readonly ?int $created = null,
        public readonly ?string $commit = null,
    ) {
    }
}
