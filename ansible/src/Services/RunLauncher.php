<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Runner\LaunchRequest;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Runs\RunText;
use Agoodley\LibrenmsAnsible\Runs\TargetSelection;
use Agoodley\LibrenmsAnsible\Runs\TemplateReview;
use Agoodley\LibrenmsAnsible\Runs\VariableValidator;
use Agoodley\LibrenmsAnsible\Support\ErrorText;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Previews and launches runs. Everything is checked on the server, against the runner as it is now: the token (it
 * may run tasks and cannot change templates: RunnerHealth::launchBlock()), the template (TemplateCheck, asked from
 * the runner, never cached), the answers (VariableValidator) and the targets
 * (TargetSelection, at most the max per run). A launch that fails a check is refused before the runner is asked.
 * The busy check and the recording of the run happen under one lock, so two launches cannot take the same
 * device and a submitted form starts one run at most (its one-time token).
 *
 * The runs of a change (a RunSpec: its dry run or a batch) are checked the same way, and more: the template must
 * allow a provable dry run (DryRunCheck), its snapshot must pass the spec's check (the change's fingerprint), and
 * devices reserved by another change being applied are refused. A dry run is launched with --check --diff, a batch
 * pinned to the dry run's commit; each has a one-time token of its own.
 */
final class RunLauncher
{
    /** Lock around the busy check and the insert of a run (and around the switch of a change to applying, which reserves its devices). */
    public const LOCK = 'ansible-plugin-launch';

    public function __construct(
        private readonly Plugin $plugin,
        private readonly RunRepository $runs,
        private readonly InventoryService $inventory,
        private readonly PluginSettings $settings,
        private readonly Eventlog $eventlog = new Eventlog(),
        private readonly ?ChangeRepository $changes = null,
    ) {
    }

    /**
     * What a launch of the template on the devices with the answers would start, or why it cannot.
     *
     * @param  int[]  $deviceIds
     * @param  array<string, mixed>  $answers
     */
    public function plan(string $templateId, array $deviceIds, #[\SensitiveParameter] array $answers, ?RunSpec $spec = null): LaunchPlan
    {
        $plan = new LaunchPlan();
        $plan->maxTargets = $this->settings->maxTargets();
        if ($this->settings->error !== null) {
            // the defaults could reach more devices than the settings an admin saved
            $plan->errors[] = $this->settings->error;

            return $plan;
        }
        $connection = $this->plugin->connection();
        try {
            $plan->runner = $this->plugin->runner($connection);
            // a token that could change templates, or may not run them, launches nothing (asked each time, like the template)
            $block = $plan->runner->health()->launchBlock();
            if ($block !== null) {
                $plan->errors[] = $block;

                return $plan;
            }
            $plan->template = $plan->runner->template($templateId);
            $inventory = $plan->runner->inventory((string) $connection->inventory);
        } catch (RunnerError $e) {
            $plan->errors[] = $e->getMessage();

            return $plan;
        }
        if (! $inventory->isFile()) {
            // a static copy of the inventory drifts from LibreNMS (and from the targets the plugin allows)
            $plan->errors[] = "The LibreNMS inventory (#{$inventory->id}, {$inventory->name}) is a {$inventory->type} inventory in the runner: "
                . 'make it a file inventory that runs the plugin\'s inventory script, so the runner gets the hosts from LibreNMS.';
        }
        $reasons = (new TemplateReview($plan->runner, (string) $connection->inventory))->reasons($plan->template);
        if ($reasons) {
            $plan->errors[] = 'The template "' . $plan->template->name . '" cannot be launched safely: ' . implode('; ', $reasons) . '.';
        }
        if ($spec !== null) {
            $this->changeChecks($plan, $inventory, $connection->project, $spec);
        }
        $checked = VariableValidator::validate($plan->template->surveyVars, $answers);
        [$plan->values, $plan->secrets, $plan->variableErrors] = [$checked['values'], $checked['secrets'], $checked['errors']];
        foreach (array_keys($plan->variableErrors) as $name) {
            $var = $plan->template->surveyVar((string) $name);
            if ($var !== null && ! $var->isSecret() && isset($answers[$name]) && is_scalar($answers[$name])) {
                $plan->rejected[$name] = mb_substr((string) $answers[$name], 0, 2000);
            }
        }
        $this->targets($plan, $deviceIds, $spec);

        return $plan;
    }

    /**
     * The checks of a change's run: a dry run of the template must be provable, and its snapshot must pass the change's
     * check (the fingerprint of what was dry-run and approved).
     */
    private function changeChecks(LaunchPlan $plan, \Agoodley\LibrenmsAnsible\Runner\RunnerInventory $inventory, int $project, RunSpec $spec): void
    {
        $reader = new TemplateReader($plan->runner, $project);
        $dryRun = $reader->dryRunReasons($plan->template);
        if ($dryRun) {
            $plan->errors[] = 'The template "' . $plan->template->name . '" cannot be used for changes: ' . implode('; ', $dryRun) . '.';

            return;
        }
        $snapshot = $reader->snapshot($plan->template, $inventory);
        if ($snapshot['parts'] === null) {
            $plan->errors[] = 'The template "' . $plan->template->name . '" cannot be read completely from the runner: ' . $snapshot['error'] . '.';

            return;
        }
        $plan->snapshot = $snapshot['parts'];
        $refusal = $spec->verify !== null ? ($spec->verify)($snapshot['parts']) : null;
        if ($refusal !== null) {
            $plan->errors[] = 'The launch was refused: ' . $refusal . '.';
        }
    }

    /**
     * Sort the chosen devices into targets and refused ones, and check their number.
     *
     * @param  int[]  $deviceIds
     */
    private function targets(LaunchPlan $plan, array $deviceIds, ?RunSpec $spec = null): void
    {
        $devices = $this->inventory->devices();
        $inventoryUser = $this->inventory->inventoryUser();
        if ($inventoryUser === null) {
            $plan->errors[] = (string) $this->inventory->inventoryUserProblem();
        } elseif ($inventoryUser['fetch'] === null) {
            $plan->warnings[] = 'The runner has not fetched the inventory with the inventory user ' . $inventoryUser['user']->username . '\'s token yet: '
                . 'the first run makes it fetch it (see the setup steps on the Inventory page).';
        }
        $selection = new TargetSelection($this->inventory->build(), $devices->exists(), $this->runs->busy(), $inventoryUser['visible'] ?? null,
            (string) ($inventoryUser['user']->username ?? ''), $this->reserved($spec));
        $chosen = $selection->select($deviceIds);
        $rows = $devices->devices();
        $names = $devices->names(array_keys($chosen['targets'] + $chosen['refused']));
        $map = $this->settings->platformMap();
        foreach ($chosen['targets'] as $deviceId => $name) {
            $plan->targets[$deviceId] = ['device_id' => $deviceId, 'name' => $name, 'display' => $names[$deviceId] ?? (string) $deviceId,
                'hostname' => (string) $rows[$deviceId]['hostname'], 'os' => (string) $rows[$deviceId]['os'],
                'platform' => (string) ($map->for((string) $rows[$deviceId]['os'])['network_os'] ?? '')];
        }
        foreach ($chosen['refused'] as $deviceId => $refusal) {
            $plan->refused[$deviceId] = ['device_id' => $deviceId, 'display' => $names[$deviceId] ?? "#$deviceId"] + $refusal;
        }
        $stopped = $this->runs->recentStops(array_keys($plan->targets));
        if ($stopped) {
            $plan->warnings[] = 'Stopped in the last hour: ' . implode('; ', array_map(fn ($deviceId, $stop) => $plan->targets[$deviceId]['display']
                . " (run #{$stop['run_id']}, task #{$stop['runner_task_id']})", array_keys(array_slice($stopped, 0, 5, true)), array_slice($stopped, 0, 5, true)))
                . '. A stop does not end the task a run is in: make sure it no longer runs on the runner before you change these devices again.';
        }
        if (! $plan->targets) {
            $plan->errors[] = 'Choose at least one device that can be a target.';
        } elseif (count($plan->targets) > $plan->maxTargets) {
            $plan->errors[] = 'A run may have at most ' . $plan->maxTargets . ' targets (Max targets per run setting); ' . count($plan->targets) . ' are chosen.';
        }
    }

    /**
     * The devices reserved by changes being applied (not by the spec's own change).
     *
     * @return array<int, int> device_id => change id
     */
    private function reserved(?RunSpec $spec): array
    {
        $changes = $this->changes ?? new ChangeRepository();

        return $changes->available() ? $changes->reserved($spec?->changeId) : [];
    }

    /**
     * Launch: check everything again, record the run, then ask the runner. The run of an already used form
     * token is returned as it is.
     *
     * @param  \App\Models\User  $user
     * @param  int[]  $deviceIds
     * @param  array<string, mixed>  $answers
     * @return array{run_id: int|null, plan: LaunchPlan, errors: string[], existing: bool}
     */
    public function launch($user, string $templateId, array $deviceIds, #[\SensitiveParameter] array $answers, string $requestToken, ?RunSpec $spec = null): array
    {
        $plan = new LaunchPlan();
        if ($spec !== null) {
            $requestToken = $spec->token();
        }
        if (! preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/D', $requestToken)) {
            return self::refused($plan, ['The launch form is out of date: reload the page.']);
        }
        $existing = $this->runs->byRequestToken($requestToken);
        if ($existing !== null) {
            return ['run_id' => $existing, 'plan' => $plan, 'errors' => [], 'existing' => true];
        }
        $plan = $this->plan($templateId, $deviceIds, $answers, $spec);
        if (! $plan->ok() || $plan->refused) {
            // a second submit of the same form sees the targets busy in the run the first one just recorded
            $existing = $this->runs->byRequestToken($requestToken);
            if ($existing !== null) {
                return ['run_id' => $existing, 'plan' => $plan, 'errors' => [], 'existing' => true];
            }
            $errors = $plan->errors;
            if ($plan->variableErrors) {
                $errors[] = 'Fix the answers to the survey variables.';
            }
            if ($plan->refused) {
                $errors[] = count($plan->refused) . ' of the chosen devices cannot be targets now (' . implode('; ',
                    array_map(fn ($r) => $r['display'] . ': ' . $r['text'], array_slice($plan->refused, 0, 5))) . '): preview again.';
            }

            return self::refused($plan, $errors);
        }

        $lock = Cache::lock(self::LOCK, 30);
        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            return self::refused($plan, ['Another launch is being recorded: try again in a moment.']);
        }
        try {
            $existing = $this->runs->byRequestToken($requestToken);
            if ($existing !== null) {
                return ['run_id' => $existing, 'plan' => $plan, 'errors' => [], 'existing' => true];
            }
            $busy = array_intersect_key($this->runs->busy(), $plan->targets);
            if ($busy) {
                return self::refused($plan, array_map(fn ($deviceId, $entry) => $plan->targets[$deviceId]['display'] . " became busy in run #{$entry['run']}: preview again.",
                    array_keys($busy), $busy));
            }
            $reserved = array_intersect_key($this->reserved($spec), $plan->targets);
            if ($reserved) {
                return self::refused($plan, array_map(fn ($deviceId, $changeId) => $plan->targets[$deviceId]['display'] . " became reserved by change #$changeId, which is being applied.",
                    array_keys($reserved), $reserved));
            }
            $nonce = bin2hex(random_bytes(6));
            $runId = $this->record($user, $plan, $requestToken, $nonce, $spec);
        } finally {
            $lock->release();
        }
        $this->start($runId, $plan, (string) $user->username, $nonce, $spec);

        return ['run_id' => $runId, 'plan' => $plan, 'errors' => [], 'existing' => false];
    }

    /**
     * Record the run (pending) and its targets.
     *
     * @param  \App\Models\User  $user
     */
    private function record($user, LaunchPlan $plan, string $requestToken, string $nonce, ?RunSpec $spec = null): int
    {
        $connection = $this->plugin->connection();
        $targets = [];
        foreach ($plan->targets as $deviceId => $target) {
            $targets[$deviceId] = ['inventory_name' => $target['name'], 'hostname' => $target['hostname'], 'display' => $target['display']];
        }

        return $this->runs->create([
            'user_id' => (int) $user->user_id,
            'username' => (string) $user->username,
            'runner' => 'semaphore',
            'runner_project' => $connection->project,
            'template_id' => $plan->template->id,
            'template_name' => mb_substr($plan->template->name, 0, 255),
            'playbook' => mb_substr($plan->template->playbook, 0, 255),
            'limit' => implode(',', $plan->limit()),
            'variables' => $plan->variables(),
            'request_token' => $requestToken,
            'launch_nonce' => $nonce,
            'status' => RunStatus::PENDING,
        ] + ($spec === null ? [] : ['change_id' => $spec->changeId, 'kind' => $spec->kind, 'batch' => $spec->kind === RunSpec::BATCH ? $spec->number : null,
            'context' => mb_substr($spec->context, 0, 120)]), $targets);
    }

    /**
     * Ask the runner to start the recorded run (the lifecycle records the task and the "started" eventlog of its
     * targets). When no proper answer came (a timeout, a server error) the runner may have started the task anyway:
     * the run stays launching, keeps its devices, and the lifecycle looks for its task by the launch's message (with
     * its nonce), so the audit log never says "not launched" for a task that runs. Only a launch the runner refused
     * (it answered, with an error) is not launched at once.
     */
    private function start(int $runId, LaunchPlan $plan, string $username, string $nonce, ?RunSpec $spec = null): void
    {
        $template = $plan->template;
        $lifecycle = (new RunFollower($this->plugin, $this->runs, $this->settings, $this->eventlog, $plan->runner, $this->inventory))->lifecycle();
        try {
            $task = $plan->runner->launch(new LaunchRequest($template->id, $plan->limit(), $plan->values, $plan->secrets, RunText::taskMessage($runId, $username, $nonce),
                $spec?->isDryRun() ?? false, $spec?->isDryRun() ?? false, $spec?->commit));
            $lifecycle->launched($runId, $task, time());
        } catch (RunnerError $e) {
            if ($e->uncertain) {
                $lifecycle->launchUncertain($runId, $e->getMessage(), time());
            } else {
                $lifecycle->launchRefused($runId, $e->getMessage(), time());
            }
        } catch (\Throwable $e) {
            // the launch request may have reached the runner: look for the task rather than say it was not launched
            Log::error("ansible plugin: launching run #$runId failed: " . ErrorText::forLog($e));
            $lifecycle->launchUncertain($runId, 'the launch failed in LibreNMS (see logs/librenms.log)', time());
        }
    }

    /**
     * A refused launch.
     *
     * @param  string[]  $errors
     * @return array{run_id: null, plan: LaunchPlan, errors: string[], existing: bool}
     */
    private static function refused(LaunchPlan $plan, array $errors): array
    {
        return ['run_id' => null, 'plan' => $plan, 'errors' => $errors, 'existing' => false];
    }
}
