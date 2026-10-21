<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * Answer of a connection test: the runner's version, the project and the role of the token's user in it.
 */
final class RunnerHealth
{
    /** Roles that may change templates (and so their playbook and repository): such a token could run any code. */
    public const POWERFUL_ROLES = ['owner', 'manager', 'admin'];

    /**
     * @param  string|null  $role  role of the token's user in the project (owner, manager, task_runner, guest)
     * @param  bool  $canRun  may the token's user start tasks
     */
    public function __construct(
        public readonly string $version,
        public readonly string $project,
        public readonly ?string $role,
        public readonly bool $canRun,
    ) {
    }

    /**
     * Could this token change templates? Then whoever holds it can run any code with the stored credentials.
     */
    public function tooPowerful(): bool
    {
        return in_array(strtolower((string) $this->role), self::POWERFUL_ROLES, true);
    }
}
