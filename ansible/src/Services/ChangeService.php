<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Changes\ChangeText;
use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;

/**
 * Prepares changes: the preview of a new change (the template as the runner has it now, the targets and refused
 * devices, the answers that are not secret, the batch plan) and its creation as a draft. A change is checked like a
 * launch (RunLauncher::plan()) and more: a dry run of the template must be provable (DryRunCheck), and the batch plan
 * covers every target. Secret answers are not asked here: they are asked at the dry run and at the apply, and never
 * kept. Nothing is launched until the dry run.
 */
final class ChangeService
{
    /** Most characters of a change's reference. */
    public const REFERENCE_MAX = 120;

    public function __construct(
        private readonly Plugin $plugin,
        private readonly RunRepository $runs,
        private readonly ChangeRepository $changes,
        private readonly InventoryService $inventory,
        private readonly PluginSettings $settings,
        private readonly Eventlog $eventlog = new Eventlog(),
    ) {
    }

    /**
     * What a change of the template on the devices would be, or why it cannot be made.
     *
     * @param  int[]  $deviceIds  in the order chosen
     * @param  array<string, mixed>  $answers  the answers that are not secret (secret ones are ignored here)
     * @param  int[]  $canary  the devices chosen for the first batch
     * @return array{plan: LaunchPlan, batches: ?BatchPlan, reference: string}
     */
    public function preview(string $templateId, array $deviceIds, #[\SensitiveParameter] array $answers, array $canary, string $reference): array
    {
        $launcher = new RunLauncher($this->plugin, $this->runs, $this->inventory, $this->settings, new Eventlog(), $this->changes);
        $plan = $launcher->plan($templateId, $deviceIds, $answers, new RunSpec(0, RunSpec::DRY_RUN, 0, ''));
        // secret answers are given at the dry run and the apply, not now
        foreach ($plan->template?->surveyVars ?? [] as $var) {
            if ($var->isSecret()) {
                unset($plan->variableErrors[$var->name], $plan->secrets[$var->name]);
            }
        }
        $batches = null;
        if ($plan->targets) {
            $ordered = array_values(array_filter(array_map('intval', $deviceIds), fn ($id) => isset($plan->targets[$id])));
            $batches = $this->settings->batchPlan($ordered, array_values(array_intersect(array_map('intval', $canary), $ordered)));
        }

        return ['plan' => $plan, 'batches' => $batches, 'reference' => self::reference($reference)];
    }

    /**
     * The preview as the page shows it: the launch preview with the batch plan and the reference.
     *
     * @param  array{plan: LaunchPlan, batches: ?BatchPlan, reference: string}  $preview
     * @return array<string, mixed>
     */
    public static function forPage(array $preview): array
    {
        $plan = $preview['plan'];
        $page = $plan->forPage();
        $page['reference'] = $preview['reference'];
        $page['batches'] = [];
        if ($preview['batches'] !== null) {
            foreach ($preview['batches']->batches as $index => $devices) {
                $page['batches'][] = ['number' => $index + 1, 'devices' => array_map(fn ($id) => ['device_id' => $id, 'display' => $plan->targets[$id]['display'] ?? "#$id",
                    'name' => $plan->targets[$id]['name'] ?? ''], $devices)];
            }
            $page['plan_text'] = $preview['batches']->describe();
        }
        $page['secret_note'] = array_filter($plan->template?->surveyVars ?? [], fn ($var) => $var->isSecret())
            ? 'Secret answers are asked at the dry run and at the apply, never kept, and are not part of what is dry-run and approved.' : null;

        return $page;
    }

    /**
     * Create the change as a draft, checked again. The change of an already used form token is returned as it is.
     *
     * @param  \App\Models\User|object  $user
     * @param  int[]  $deviceIds
     * @param  int[]  $canary
     * @param  ?int[][]  $expected  the batches the preview showed: the change is not created when its batches differ
     * @return array{change_id: ?int, errors: string[], preview: array, existing: bool}
     */
    public function create($user, string $templateId, array $deviceIds, #[\SensitiveParameter] array $answers, array $canary, string $reference, string $requestToken,
        ?array $expected = null): array
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $requestToken)) {
            return ['change_id' => null, 'errors' => ['The form is out of date: reload the page.'], 'preview' => [], 'existing' => false];
        }
        $existing = $this->changes->byRequestToken($requestToken);
        if ($existing !== null) {
            return ['change_id' => $existing, 'errors' => [], 'preview' => [], 'existing' => true];
        }
        $preview = $this->preview($templateId, $deviceIds, $answers, $canary, $reference);
        $plan = $preview['plan'];
        $errors = $plan->errors;
        if ($plan->variableErrors) {
            $errors[] = 'Fix the answers to the survey variables.';
        }
        if ($plan->refused) {
            $errors[] = count($plan->refused) . ' of the chosen devices cannot be targets now (' . implode('; ',
                array_map(fn ($r) => $r['display'] . ': ' . $r['text'], array_slice($plan->refused, 0, 5))) . '): preview again.';
        }
        if ($expected !== null && $preview['batches'] !== null && $preview['batches']->batches !== $expected) {
            $errors[] = 'The batches are no longer those of the preview (the settings or the devices changed): preview again.';
        }
        if ($errors || ! $plan->ok() || $plan->refused || $preview['batches'] === null) {
            return ['change_id' => null, 'errors' => $errors ?: ['The change cannot be made: preview it again.'], 'preview' => self::forPage($preview), 'existing' => false];
        }
        try {
            $id = $this->record($user, $preview, $requestToken);
        } catch (\PDOException|\Illuminate\Database\QueryException $e) {
            $existing = $this->changes->byRequestToken($requestToken); // a second submit of the same form, at the same moment
            if ($existing !== null) {
                return ['change_id' => $existing, 'errors' => [], 'preview' => [], 'existing' => true];
            }
            throw $e;
        }

        return ['change_id' => $id, 'errors' => [], 'preview' => self::forPage($preview), 'existing' => false];
    }

    /**
     * Record the draft, its targets in their batches, its timeline's first entry and the eventlog of every target.
     *
     * @param  array{plan: LaunchPlan, batches: BatchPlan, reference: string}  $preview
     */
    private function record($user, array $preview, string $requestToken): int
    {
        $plan = $preview['plan'];
        $batches = $preview['batches'];
        $targets = [];
        foreach ($batches->batches as $index => $devices) {
            foreach ($devices as $deviceId) {
                $target = $plan->targets[$deviceId];
                $targets[$deviceId] = ['inventory_name' => $target['name'], 'hostname' => $target['hostname'], 'display' => $target['display'], 'batch' => $index + 1];
            }
        }
        $change = ['user_id' => (int) $user->user_id, 'username' => (string) $user->username, 'template_id' => $plan->template->id,
            'template_name' => mb_substr($plan->template->name, 0, 255), 'playbook' => mb_substr($plan->template->playbook, 0, 255), 'reference' => $preview['reference'],
            'status' => ChangeStatus::DRAFT, 'variables' => $plan->variables(), 'has_secrets' => (bool) array_filter($plan->template->surveyVars, fn ($var) => $var->isSecret()),
            'plan' => $batches->toArray(), 'target_count' => count($targets), 'batch_count' => $batches->count(), 'request_token' => $requestToken];
        $id = $this->changes->create($change, $targets, $batches->count());
        $change['id'] = $id;
        $this->changes->addEvent($id, 'created', 'Created (' . $batches->describe() . ').', (string) $user->username, time());
        foreach (array_keys($targets) as $deviceId) {
            $this->eventlog->write(ChangeText::created($change, count($targets), $batches->count()), (int) $deviceId, 'info', ChangeText::reference($id), (string) $user->username);
        }

        return $id;
    }

    /**
     * A change's reference (a ticket number): untrusted text, kept to letters, digits, spaces and . , : _ - / ( ) @ + =,
     * at most REFERENCE_MAX characters.
     */
    public static function reference(string $reference): string
    {
        return UntrustedText::clean($reference, self::REFERENCE_MAX);
    }
}
