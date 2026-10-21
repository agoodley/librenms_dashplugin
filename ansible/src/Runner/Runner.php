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
     * Start a task of a template; the runner's own id of the task and its first status.
     */
    public function launch(LaunchRequest $request): RunnerTask;

    /**
     * The task the runner started for a launch whose answer never came, by the launch's message (unique per run);
     * null when it has none.
     */
    public function findTask(string $templateId, string $message): ?RunnerTask;

    /**
     * The task's status and times.
     */
    public function task(string $id): RunnerTask;

    /**
     * The task's output lines from line $from on (0: all), with ANSI colour codes as the runner sends them.
     */
    public function output(string $taskId, int $from = 0): RunnerOutput;

    /**
     * Ask the runner to stop the task (it ends as stopped).
     */
    public function stop(string $taskId): void;
}
