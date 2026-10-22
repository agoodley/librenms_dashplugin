<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * Answer of a connection test: the runner's version, the project, and what the token's user may do: its role in
 * the project, whether it may start tasks, whether it is an admin of the runner. The plugin launches nothing with
 * a token that could change templates, nor with one that may not run tasks (launchBlock()).
 */
final class RunnerHealth
{
    /** Roles that may change templates (and so their playbook and repository): such a token could run any code. */
    public const POWERFUL_ROLES = ['owner', 'manager', 'admin'];

    /**
     * @param  string|null  $role  role of the token's user in the project (owner, manager, task_runner, guest)
     * @param  bool  $canRun  may the token's user start tasks
     * @param  bool  $admin  is the token's user an admin of the runner (who may change every project)
     */
    public function __construct(
        public readonly string $version,
        public readonly string $project,
        public readonly ?string $role,
        public readonly bool $canRun,
        public readonly bool $admin = false,
    ) {
    }

    /**
     * Could this token change templates? Then whoever holds it can run any code with the stored credentials.
     */
    public function tooPowerful(): bool
    {
        return $this->powerReason() !== null;
    }

    /**
     * Why the token could change templates, null when it cannot.
     */
    public function powerReason(): ?string
    {
        if ($this->admin) {
            return 'its user is a Semaphore UI admin';
        }
        if (in_array(strtolower((string) $this->role), self::POWERFUL_ROLES, true)) {
            return 'its user has the ' . self::roleName($this->role) . ' role in the project';
        }

        return null;
    }

    /**
     * Why the plugin launches nothing with this token, and how to fix it; null when it may launch.
     */
    public function launchBlock(): ?string
    {
        $reason = $this->powerReason();
        if ($reason !== null) {
            return "Launches are blocked: the runner token is too powerful ($reason), so it could change templates, and with them their "
                . 'playbook and repository, and so run any code with the device credentials. Create a user that is not an admin, give it '
                . 'the Task Runner role in the project, and save its API token in the plugin settings.';
        }
        if (! $this->canRun) {
            $role = $this->role === null || $this->role === '' ? 'no role' : 'the ' . self::roleName($this->role) . ' role';

            return "Launches are blocked: the token's user has $role in the project, which may not run tasks. Give the user the Task Runner role.";
        }

        return null;
    }

    /**
     * A role as Semaphore UI names it on its pages (task_runner: Task Runner).
     */
    public static function roleName(?string $role): string
    {
        return ucwords(str_replace('_', ' ', strtolower((string) $role)));
    }
}
