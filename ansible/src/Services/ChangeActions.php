<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Changes\ChangeLifecycle;
use Agoodley\LibrenmsAnsible\Changes\ChangeRunner;
use Agoodley\LibrenmsAnsible\Changes\Connections;
use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\PermissionRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runs\TargetSelection;
use Agoodley\LibrenmsAnsible\Runs\OutputBuffer;
use Agoodley\LibrenmsAnsible\Support\Access;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The change lifecycle's actions on the plugin's services (Changes\ChangeRunner): a change's dry run and batches are
 * launched by phase 1's launcher (RunLauncher, with a RunSpec) and followed by its run lifecycle; templates are read
 * from the runner each time; users are checked against their permissions now.
 */
final class ChangeActions implements ChangeRunner
{
    public function __construct(
        private readonly Plugin $plugin,
        private readonly RunRepository $runs,
        private readonly ChangeRepository $changes,
        private readonly InventoryService $inventory,
        private readonly PluginSettings $settings,
        private readonly Eventlog $eventlog = new Eventlog(),
        private readonly PermissionRepository $permissions = new PermissionRepository(),
    ) {
    }

    public function launch(array $change, string $kind, int $number, array $deviceIds, array $user, #[\SensitiveParameter] array $secrets, \Closure $verify,
        ?\Closure $halt = null): array
    {
        $parts = null;
        $connections = null;
        $spec = self::spec($change, $kind, $number, function (array $snapshot) use ($verify, $deviceIds, &$parts, &$connections): ?string {
            // right before the run is recorded and launched: the template as the runner has it, the devices as the inventory reaches them
            $parts = $snapshot;
            $connections = $this->connections($deviceIds);

            return $verify($snapshot, $connections);
        }, $halt);
        $launcher = new RunLauncher($this->plugin, $this->runs, $this->inventory, $this->settings, $this->eventlog, $this->changes);
        $result = $launcher->launch((object) ['user_id' => (int) $user['user_id'], 'username' => (string) $user['username']], (string) $change['template_id'],
            $deviceIds, ChangeLifecycle::answers($change) + $secrets, '', $spec);
        $errors = $result['errors'];
        if ($result['run_id'] === null && ! $errors) {
            $errors = $result['plan']->errors ?: ['the launch was refused'];
        }
        if ($result['run_id'] === null && $result['plan']->variableErrors) {
            foreach ($result['plan']->variableErrors as $name => $error) {
                $errors[] = 'The answer "' . $name . '" ' . $error . '.';
            }
        }

        return ['run_id' => $result['run_id'], 'errors' => array_values(array_unique($errors)), 'parts' => $parts ?? $result['plan']->snapshot,
            'connections' => $connections];
    }

    /**
     * What makes a launch a run of the change: its dry run (no commit: the runner's latest), or one of its batches,
     * pinned to the commit its dry run ran; with the text of its eventlog entries and the check right before the launch.
     *
     * @param  \Closure(array): ?string  $check
     * @param  (\Closure(): ?string)|null  $halt  why the launch must not go on now (RunSpec)
     */
    public static function spec(array $change, string $kind, int $number, \Closure $check, ?\Closure $halt = null): RunSpec
    {
        $context = $kind === RunSpec::DRY_RUN ? 'change #' . (int) $change['id'] . ', dry run'
            : 'change #' . (int) $change['id'] . ", batch $number of " . (int) $change['batch_count'];

        return new RunSpec((int) $change['id'], $kind, $number, $context, $kind === RunSpec::BATCH ? ($change['dry_run_commit'] ?? null) : null, $check, $halt,
            RunSpec::saltOf($change));
    }

    public function preflight(array $change, string $kind, int $number, array $deviceIds, \Closure $verify): ?string
    {
        $spec = new RunSpec((int) $change['id'], $kind, $number, '', null,
            fn (array $snapshot): ?string => $verify($snapshot, $this->connections($deviceIds)));
        $launcher = new RunLauncher($this->plugin, $this->runs, $this->inventory, $this->settings, $this->eventlog, $this->changes);
        $plan = $launcher->plan((string) $change['template_id'], $deviceIds, ChangeLifecycle::answers($change), $spec);
        foreach ($plan->template?->surveyVars ?? [] as $var) {
            if ($var->isSecret()) {
                unset($plan->variableErrors[$var->name]); // the secret answers come with the launch
            }
        }
        $errors = $plan->errors;
        foreach ($plan->refused as $refusal) {
            $errors[] = $refusal['display'] . ': ' . $refusal['text'] . '.';
        }
        foreach ($plan->variableErrors as $name => $error) {
            $errors[] = 'The answer "' . $name . '" ' . $error . '.';
        }

        return $errors ? implode(' ', array_values(array_unique($errors))) : null;
    }

    public function exclusive(\Closure $work): ?string
    {
        $lock = Cache::lock(RunLauncher::LOCK, 30);
        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            return 'Another launch is being recorded: try again in a moment.';
        }
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    public function connections(array $deviceIds): array
    {
        return Connections::of($this->inventory->build(), $deviceIds);
    }

    public function busy(array $deviceIds): array
    {
        $busy = [];
        foreach (array_intersect_key($this->runs->busy(), array_flip(array_map('intval', $deviceIds))) as $deviceId => $entry) {
            $busy[(int) $deviceId] = TargetSelection::busyRefusal($entry)['text'];
        }

        return $busy;
    }

    public function findRun(int $changeId, string $kind, int $number): ?int
    {
        $change = $this->changes->find($changeId);
        if ($change === null) {
            return null;
        }
        $runId = $this->runs->byRequestToken(RunSpec::tokenOf($changeId, $kind, $number, RunSpec::saltOf($change)));
        $spec = new RunSpec($changeId, $kind, $number, '');

        return $runId !== null && $spec->belongs($this->runs->find($runId)) ? $runId : null;
    }

    public function snapshot(array $change): array
    {
        $template = null;
        try {
            $connection = $this->plugin->connection();
            $runner = $this->plugin->runner($connection);
            $template = $runner->template((string) $change['template_id']);
            $inventory = $runner->inventory((string) $connection->inventory);
        } catch (RunnerError $e) {
            return ['parts' => null, 'error' => $e->getMessage(), 'missing' => $template === null && $e->kind === RunnerError::NOT_FOUND];
        }

        return (new TemplateReader($runner, $connection->project))->snapshot($template, $inventory);
    }

    public function wholeLog(int $runId): ?array
    {
        $run = $this->runs->find($runId);
        if ($run === null) {
            return null;
        }
        if (empty($run['output_truncated'])) {
            return OutputBuffer::display($run);
        }
        if ($run['runner_task_id'] === null) {
            return null;
        }
        try {
            return $this->plugin->runner()->output((string) $run['runner_task_id'])->lines;
        } catch (RunnerError) {
            return null;
        }
    }

    public function stopRun(int $runId, string $username): ?string
    {
        return (new RunFollower($this->plugin, $this->runs, $this->settings, $this->eventlog, null, $this->inventory))->stop($runId, $username);
    }

    public function userProblem(int $userId, string $permission): ?string
    {
        return $this->permissions->problem($userId, $permission === 'approve' ? Access::APPROVE : Access::PERMISSION);
    }

    public function changedSince(array $deviceIds, int $since, int $changeId): array
    {
        return $this->runs->changedSince($deviceIds, $since, $changeId);
    }

    public function recordProof(int $runId, bool $verified): void
    {
        $this->runs->setProof($runId, $verified ? 'verified' : 'unverified');
    }
}
