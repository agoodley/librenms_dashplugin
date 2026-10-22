<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit\Fakes;

use Agoodley\LibrenmsAnsible\Runner\LaunchRequest;
use Agoodley\LibrenmsAnsible\Runner\Runner;
use Agoodley\LibrenmsAnsible\Runner\RunnerEnvironment;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runner\RunnerHealth;
use Agoodley\LibrenmsAnsible\Runner\RunnerInventory;
use Agoodley\LibrenmsAnsible\Runner\RunnerOutput;
use Agoodley\LibrenmsAnsible\Runner\RunnerTask;
use Agoodley\LibrenmsAnsible\Runner\RunnerTaskSearch;
use Agoodley\LibrenmsAnsible\Runner\RunnerTemplate;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;

/**
 * A runner whose answers the test sets: the status of each task (or an error), its whole log, the token's role, the
 * search for a launch's task, and whether stops work. Calls are recorded.
 */
final class FakeRunner implements Runner
{
    /** @var array<string, string|RunnerError> task id => Semaphore status, or the error task() throws */
    public array $tasks = [];

    /** @var array<string, string[]> task id => whole log */
    public array $logs = [];

    public ?RunnerError $outputError = null;

    public string|RunnerError $role = 'task_runner';

    public RunnerTaskSearch|RunnerError|null $search = null;

    public ?RunnerError $stopError = null;

    /** @var array<int, array{0: string, 1: bool}> stops asked: task id, forced */
    public array $stops = [];

    /** @var string[] */
    public array $calls = [];

    /** The times a task reports (Unix times; null: not known). */
    public ?int $startedAt = null;

    public ?int $endedAt = null;

    public function name(): string
    {
        return 'Fake';
    }

    public function health(): RunnerHealth
    {
        return new RunnerHealth('v1', 'Fake project', is_string($this->role) ? $this->role : null, true);
    }

    public function templates(): array
    {
        return [];
    }

    public function template(string $id): RunnerTemplate
    {
        throw new RunnerError(RunnerError::NOT_FOUND, "no template $id");
    }

    public function inventory(string $id): RunnerInventory
    {
        return new RunnerInventory($id, 'LibreNMS', 'file', 'inventory/librenms_inventory.py');
    }

    public function inventories(): array
    {
        return [];
    }

    public function environment(string $id): RunnerEnvironment
    {
        return new RunnerEnvironment($id, 'LibreNMS', ['LIBRENMS_URL'], ['LIBRENMS_TOKEN']);
    }

    public function role(): string
    {
        $this->calls[] = 'role';
        if ($this->role instanceof RunnerError) {
            throw $this->role;
        }

        return $this->role;
    }

    public function launch(LaunchRequest $request): RunnerTask
    {
        throw new RunnerError(RunnerError::REFUSED, 'not in these tests');
    }

    public function findTask(string $templateId, string $message, int $notBefore): RunnerTaskSearch
    {
        $this->calls[] = "find $message";
        if ($this->search instanceof RunnerError) {
            throw $this->search;
        }

        return $this->search ?? new RunnerTaskSearch(null, true);
    }

    public function task(string $id): RunnerTask
    {
        $this->calls[] = "task $id";
        $status = $this->tasks[$id] ?? new RunnerError(RunnerError::NOT_FOUND, "Semaphore UI does not have task $id (HTTP 404).");
        if ($status instanceof RunnerError) {
            throw $status;
        }

        return new RunnerTask($id, RunStatus::fromSemaphore($status), $status, $this->startedAt, RunStatus::isFinal(RunStatus::fromSemaphore($status)) ? $this->endedAt : null);
    }

    public function output(string $taskId, int $from = 0): RunnerOutput
    {
        $this->calls[] = "output $taskId $from";
        if ($this->outputError !== null) {
            throw $this->outputError;
        }
        $lines = $this->logs[$taskId] ?? [];

        return new RunnerOutput(array_slice($lines, $from), count($lines));
    }

    public function stop(string $taskId, bool $force = false): void
    {
        $this->calls[] = "stop $taskId" . ($force ? ' forced' : '');
        if ($this->stopError !== null) {
            throw $this->stopError;
        }
        $this->stops[] = [$taskId, $force];
    }
}
