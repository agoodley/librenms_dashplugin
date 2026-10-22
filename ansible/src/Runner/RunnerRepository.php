<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * A repository of the runner's project, where a template's playbook comes from: its address and branch are part of
 * what a change's approval approves (Changes\TemplateSnapshot).
 */
final class RunnerRepository
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $gitUrl,
        public readonly string $gitBranch,
    ) {
    }

    /**
     * Does the runner check out a commit of it for each task (a Git address), so a task can say and be pinned to the
     * commit it runs? Semaphore UI runs a local directory ("/path") as it is, without a commit.
     */
    public function isGit(): bool
    {
        return $this->gitUrl !== '' && ! str_starts_with($this->gitUrl, '/') && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $this->gitUrl);
    }
}
