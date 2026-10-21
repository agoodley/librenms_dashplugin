<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * Output lines of a task from a position on, and the position after them (the next call's $from).
 */
final class RunnerOutput
{
    /**
     * @param  string[]  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $next,
    ) {
    }
}
