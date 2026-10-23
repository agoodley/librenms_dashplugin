<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Changes\BackupCheck;
use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
use Agoodley\LibrenmsAnsible\Changes\BlastRadius;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Changes\ChangeText;
use Agoodley\LibrenmsAnsible\Changes\ConfigBackups;
use Agoodley\LibrenmsAnsible\Changes\RolloutPlan;
use Agoodley\LibrenmsAnsible\Changes\Topology;
use Agoodley\LibrenmsAnsible\Changes\TopologyError;
use Agoodley\LibrenmsAnsible\Changes\TopologyPlan;
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
 *
 * The batches are planned by the topology plugin when it is installed, enabled and offers a planning API the plugin
 * speaks, and the settings say so (RolloutPlan: its order, each batch's blast radius, the devices it cannot plan left out
 * or planned by hand); else by the settings, as in phase 2, with the reason, and the blast radius is unknown. The plan
 * is computed as the admin who makes the change sees the network.
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
        private readonly ?ConfigBackups $backups = null,
        private readonly ?Topology $topology = null,
    ) {
    }

    /**
     * What a change of the template on the devices would be, or why it cannot be made.
     *
     * @param  int[]  $deviceIds  in the order chosen
     * @param  array<string, mixed>  $answers  the answers that are not secret (secret ones are ignored here)
     * @param  int[]  $canary  the devices chosen for the first batch
     * @param  string  $unplanned  what the topology plugin's plan does with the devices it cannot plan: RolloutPlan::LEAVE (out) or HAND
     * @param  int  $userId  the admin who makes the change: the topology plugin plans as they see the network
     * @return array{plan: LaunchPlan, batches: ?BatchPlan, reference: string, planner: array}
     */
    public function preview(string $templateId, array $deviceIds, #[\SensitiveParameter] array $answers, array $canary, string $reference,
        string $unplanned = RolloutPlan::LEAVE, int $userId = 0): array
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
        $planner = ['used' => false, 'why' => null, 'api' => null, 'unplanned' => $unplanned, 'left_out' => [], 'notes' => []];
        if ($plan->targets) {
            $ordered = array_values(array_filter(array_map('intval', $deviceIds), fn ($id) => isset($plan->targets[$id])));
            $chosen = array_values(array_intersect(array_map('intval', $canary), $ordered));
            $planner = $this->planWithTopology($ordered, $chosen, $unplanned, $userId) + $planner;
            if ($planner['plan'] !== null) {
                $batches = $planner['plan'];
                // the devices the planner cannot plan, left out: not targets of the change
                foreach ($planner['left_out'] as $entry) {
                    unset($plan->targets[$entry['device_id']]);
                }
                $ordered = array_values(array_filter($ordered, fn ($id) => isset($plan->targets[$id])));
            } elseif ($planner['error'] !== null) {
                $plan->errors[] = $planner['error'];
                $ordered = [];
            } else {
                $batches = $this->settings->batchPlan($ordered, $chosen);
            }
            unset($planner['plan'], $planner['error']);
        }
        if ($ordered ?? []) {
            // the targets Oxidized cannot back up, known now rather than when their batch is refused
            $settings = $this->settings->changeSettings();
            $backups = $this->backups ?? new OxidizedBackups();
            if ($settings->backups && $backups->enabled()) {
                $check = BackupCheck::of($backups, $ordered);
                $warning = BackupCheck::warning(BackupCheck::missing($check['checks'], array_map(fn ($t) => (string) $t['display'], $plan->targets)),
                    $settings->refuseWithoutBackup, $check['error']);
                if ($warning !== null) {
                    $plan->warnings[] = $warning;
                }
            }
        }

        return ['plan' => $plan, 'batches' => $batches, 'reference' => self::reference($reference), 'planner' => $planner];
    }

    /**
     * The batches as the topology plugin plans them, when it can and the settings say so: [plan: the batch plan, or null
     * (why: why it was not planned with the topology plugin, so the settings plan it; error: it was, but nothing can be
     * planned), used, api, left_out (with the devices' names), notes].
     *
     * @param  int[]  $ordered  the targets in the order chosen
     * @param  int[]  $canary  the devices ticked for the first batch
     * @return array{plan: ?BatchPlan, used: bool, why: ?string, error: ?string, api: ?string, left_out: array, notes: string[]}
     */
    private function planWithTopology(array $ordered, array $canary, string $unplanned, int $userId): array
    {
        $result = ['plan' => null, 'used' => false, 'why' => null, 'error' => null, 'api' => null, 'left_out' => [], 'notes' => []];
        if (! $this->settings->topologyPlan()) {
            return ['why' => 'planning with the topology plugin is switched off in the settings'] + $result;
        }
        $topology = $this->topology ?? new TopologyPlanning();
        $why = $topology->unavailable();
        if ($why !== null) {
            return ['why' => $why] + $result;
        }
        try {
            $answer = $topology->rollout($ordered, $this->settings->int('batch_size'), $userId);
            $made = RolloutPlan::make($ordered, $canary, $this->settings->int('canary_size'), $this->settings->int('batch_size'), $this->settings->int('stop_at'),
                $this->settings->all()['wait_after_canary'] !== '0', $this->settings->int('batch_pause_minutes'), $answer,
                in_array($unplanned, RolloutPlan::CHOICES, true) ? $unplanned : RolloutPlan::LEAVE, fn (array $devices) => $topology->blastRadius($devices, $userId));
        } catch (TopologyError $e) {
            return ['why' => 'the topology plugin could not plan them: ' . $e->getMessage()] + $result;
        } catch (\InvalidArgumentException $e) {
            return ['why' => 'the topology plugin\'s plan cannot be used (' . UntrustedText::line($e->getMessage(), 300) . ')'] + $result;
        }
        $names = BlastRadius::names($answer);
        $leftOut = array_map(fn (array $entry) => $entry + ['name' => (string) (($names[$entry['device_id']] ?? '') ?: '#' . $entry['device_id']),
            'text' => TopologyPlan::reasonText($entry['reason'])], $made['left_out']);

        // the core the plan is measured from (none from a planning API before 1.1)
        $core = $made['plan']?->topology !== null ? $made['plan']->topology->coreText() : '';

        return ['plan' => $made['plan'], 'used' => true, 'error' => $made['error'], 'api' => (string) ($answer['version'] ?? ''), 'left_out' => $leftOut,
            'notes' => $made['notes'], 'core_text' => $core] + $result;
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
        $topology = $preview['batches']?->topology;
        if ($preview['batches'] !== null) {
            foreach ($preview['batches']->batches as $index => $devices) {
                $page['batches'][] = ['number' => $index + 1, 'devices' => array_map(fn ($id) => ['device_id' => $id, 'display' => $plan->targets[$id]['display'] ?? "#$id",
                    'name' => $plan->targets[$id]['name'] ?? ''], $devices), 'blast' => $topology !== null ? self::blastForPage($topology, $index + 1, $plan->targets) : null];
            }
            $page['plan_text'] = $preview['batches']->describe();
        }
        $page['planner'] = $preview['planner'] ?? null;
        $page['blast_signature'] = $topology !== null ? self::blastSignature($topology) : null;
        $page['secret_note'] = array_filter($plan->template?->surveyVars ?? [], fn ($var) => $var->isSecret())
            ? 'Secret answers are asked at the dry run and at the apply, never kept, and are not part of what is dry-run and approved.' : null;

        return $page;
    }

    /**
     * A batch's blast radius as the pages show it: its place, the devices it cuts off with their names, the unmanaged
     * neighbors, the devices nothing tells about, and the link to the topology plugin's simulation of it.
     *
     * @param  array<int, array>  $targets  device_id => target (display)
     * @return array<string, mixed>|null
     */
    public static function blastForPage(TopologyPlan $topology, int $number, array $targets = []): ?array
    {
        $entry = $topology->batch($number);
        if ($entry === null) {
            return null;
        }
        $name = fn (int $id) => (string) (($topology->names[$id] ?? '') ?: (($targets[$id]['display'] ?? '') ?: "#$id"));

        return ['tier' => $entry['tier'], 'tier_text' => TopologyPlan::tierText($entry['tier']), 'hand' => $entry['hand'],
            'lost' => array_map(fn (int $id) => ['device_id' => $id, 'name' => $name($id)], $entry['lost']), 'network' => $entry['network'],
            'unmanaged' => $entry['unmanaged'], 'reference' => $entry['reference'],
            'skipped' => array_map(fn (array $s) => ['device_id' => $s['device_id'], 'name' => $name($s['device_id']), 'text' => TopologyPlan::reasonText($s['reason'])],
                $entry['skipped']),
            'simulate' => $entry['simulate'] !== null ? (function_exists('url') ? url($entry['simulate']) : $entry['simulate']) : null];
    }

    /**
     * What the topology plugin said that a new change must keep from its preview to its creation: each batch's place,
     * the devices it cuts off, what that is measured from (the core, or the largest piece left when the batch takes
     * every core device of its part down) and the devices nothing tells about, and the devices left out (not the counts
     * of unmanaged neighbors, nor names).
     */
    public static function blastSignature(TopologyPlan $topology): string
    {
        return hash('sha256', (string) json_encode([array_map(fn (array $b) => [$b['tier'], $b['hand'], $b['lost'], $b['reference'], $b['skipped']], $topology->batches),
            $topology->leftOut]));
    }

    /**
     * What a new change's batch plan, planned again at its creation, no longer keeps from the preview it was made from:
     * its batches, or what they cut off (blastSignature(); "" for a plan of the settings, which has none); null when it
     * keeps both, or when the preview's were not given (or there is no plan: the change is not made anyway).
     *
     * @param  ?int[][]  $expected  the batches the preview showed
     * @param  ?string  $expectedBlast  the blast radius the preview showed
     */
    public static function previewDiffers(?BatchPlan $batches, ?array $expected, ?string $expectedBlast): ?string
    {
        if ($batches === null) {
            return null;
        }
        if ($expected !== null && $batches->batches !== $expected) {
            return 'The batches are no longer those of the preview (the settings or the devices changed): preview again.';
        }
        if ($expectedBlast !== null && ($batches->topology !== null ? self::blastSignature($batches->topology) : '') !== $expectedBlast) {
            return 'What the batches cut off is no longer what the preview showed (the topology changed): preview again.';
        }

        return null;
    }

    /**
     * Is this a blast radius a preview gives (blastSignature(), or "" for a plan of the settings, which has none)?
     */
    public static function isBlastSignature(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([0-9a-f]{64})?$/D', $value) === 1;
    }

    /**
     * Create the change as a draft, checked again. The change of an already used form token is returned as it is.
     *
     * @param  \App\Models\User|object  $user
     * @param  int[]  $deviceIds
     * @param  int[]  $canary
     * @param  ?int[][]  $expected  the batches the preview showed: the change is not created when its batches differ
     * @param  string|null  $expectedBlast  the blast radius the preview showed (blastSignature(), "" for none): nor when it differs
     * @return array{change_id: ?int, errors: string[], preview: array, existing: bool}
     */
    public function create($user, string $templateId, array $deviceIds, #[\SensitiveParameter] array $answers, array $canary, string $reference, string $requestToken,
        ?array $expected = null, string $unplanned = RolloutPlan::LEAVE, ?string $expectedBlast = null): array
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $requestToken)) {
            return ['change_id' => null, 'errors' => ['The form is out of date: reload the page.'], 'preview' => [], 'existing' => false];
        }
        $existing = $this->changes->byRequestToken($requestToken);
        if ($existing !== null) {
            return ['change_id' => $existing, 'errors' => [], 'preview' => [], 'existing' => true];
        }
        $preview = $this->preview($templateId, $deviceIds, $answers, $canary, $reference, $unplanned, (int) $user->user_id);
        $plan = $preview['plan'];
        $errors = $plan->errors;
        if ($plan->variableErrors) {
            $errors[] = 'Fix the answers to the survey variables.';
        }
        if ($plan->refused) {
            $errors[] = count($plan->refused) . ' of the chosen devices cannot be targets now (' . implode('; ',
                array_map(fn ($r) => $r['display'] . ': ' . $r['text'], array_slice($plan->refused, 0, 5))) . '): preview again.';
        }
        $differs = self::previewDiffers($preview['batches'], $expected, $expectedBlast);
        if ($differs !== null) {
            $errors[] = $differs;
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
        $planner = $preview['planner'] ?? [];
        if ($batches->topology !== null) {
            $displays = array_map(fn (array $target) => (string) (($target['display'] ?? '') ?: ($target['name'] ?? '')), $plan->targets);
            $core = $batches->topology->coreText();
            $this->changes->addEvent($id, 'created', 'What each batch cuts off while it runs, as the topology plugin computed it when the change was made (checked again before each batch): '
                . $batches->topology->summary($batches->batches, array_filter($displays)) . '.' . ($core !== '' ? ' Measured from the core: ' . $core . '.' : '')
                . implode('', array_map(fn (string $warning) => ' ' . $warning, $batches->topology->coreWarnings())), (string) $user->username, time());
        }
        if ($batches->topology !== null && $batches->topology->leftOut) {
            $this->changes->addEvent($id, 'created', 'Left out, as the topology plugin cannot plan them (nothing tells what depends on them): '
                . RolloutPlan::reasonsText($batches->topology->leftOut, $batches->topology->names) . '.', (string) $user->username, time());
        } elseif ($batches->topology === null) {
            $this->changes->addEvent($id, 'created', 'The blast radius of its batches is unknown: they were planned by the settings, as '
                . UntrustedText::line((string) (($planner['why'] ?? '') ?: 'the topology plugin did not plan them'), 300) . '.', (string) $user->username, time());
        }
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
