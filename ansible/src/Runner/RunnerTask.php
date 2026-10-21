<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * A task of the runner: its id, its status in the plugin's terms (Runs\RunStatus) and the runner's own, and
 * its times (Unix timestamps).
 */
final class RunnerTask
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $rawStatus,
        public readonly ?int $started = null,
        public readonly ?int $ended = null,
    ) {
    }
}
