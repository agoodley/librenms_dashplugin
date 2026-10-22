<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * The automation runner that keeps the playbooks, the device credentials and Ansible itself (Semaphore UI by
 * default; an AWX adapter can implement the same interface). The plugin only lists templates, launches one on
 * a limit with survey answers, follows the task and stops it. Every method throws RunnerError with a message
 * fit for the user (never the token).
 */
interface Runner
{
    /**
     * Kind of runner for messages, e.g. "Semaphore UI".
     */
    public function name(): string;

    /**
     * The runner's version and what the token may do in the project.
     */
    public function health(): RunnerHealth;

    /**
     * The project's templates, as listed (RunnerTemplate::$canRun may be unknown there).
     *
     * @return RunnerTemplate[]
     */
    public function templates(): array;

    /**
     * One template as the runner has it now (asked each time, never cached).
     */
    public function template(string $id): RunnerTemplate;

    /**
     * One inventory of the project.
     */
    public function inventory(string $id): RunnerInventory;

    /**
     * The project's inventories (to choose the LibreNMS one).
     *
     * @return RunnerInventory[]
     */
    public function inventories(): array;

    /**
     * A variable group of the project (the names of its variables, never their values).
     */
    public function environment(string $id): RunnerEnvironment;

    /**
     * The token's role in the project ("" when it has none); RunnerError when the runner cannot tell, e.g. when
     * the token's user is not in the project.
     */
    public function role(): string;

    /**
     * Start a task of a template; the runner's own id of the task and its first status.
     */
    public function launch(LaunchRequest $request): RunnerTask;

    /**
     * The task the runner started for a launch whose answer never came: a task of the template with the launch's
     * message (unique per run: it holds a random nonce) created at $notBefore or later (a Unix time).
     */
    public function findTask(string $templateId, string $message, int $notBefore): RunnerTaskSearch;

    /**
     * The task's status and times.
     */
    public function task(string $id): RunnerTask;

    /**
     * The task's output lines from line $from on (0: all), with ANSI colour codes as the runner sends them.
     */
    public function output(string $taskId, int $from = 0): RunnerOutput;

    /**
     * Ask the runner to stop the task (it ends as stopped); $force when an earlier stop was not carried out.
     */
    public function stop(string $taskId, bool $force = false): void;
}
