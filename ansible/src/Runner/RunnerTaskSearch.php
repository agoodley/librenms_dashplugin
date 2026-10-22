<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * The answer to a search for the task of a launch (Runner::findTask()): the task when it was found, and whether
 * the search covered every task since the launch, so "not found" can be trusted.
 */
final class RunnerTaskSearch
{
    public function __construct(
        public readonly ?RunnerTask $task,
        public readonly bool $complete,
    ) {
    }
}
