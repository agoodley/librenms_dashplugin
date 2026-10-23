<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Changes\BackupCheck;
use Agoodley\LibrenmsAnsible\Changes\BatchPlan;
use Agoodley\LibrenmsAnsible\Changes\BlastRadius;
use Agoodley\LibrenmsAnsible\Changes\ChangeStatus;
use Agoodley\LibrenmsAnsible\Changes\ChangeText;
use Agoodley\LibrenmsAnsible\Changes\ConfigBackups;
use Agoodley\LibrenmsAnsible\Changes\FixAnswers;
use Agoodley\LibrenmsAnsible\Changes\FixChange;
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
 *
 * A change made from fixes (phase 4, the Fixes page) takes the template the settings map to the kind of fix, the fixes'
 * devices and the lines of the template's fix variable, computed here from the topology plugin's fixes (as the admin sees
 * them) and checked field by field (FixAnswers): the page shows them read only, and the creation computes them again and
 * refuses when they are not what the page showed. A root priority fix plans its own batches: the intended roots alone
 * first, then their secondaries (FixChange), each batch's blast radius as for any change.
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
        private readonly ?\Closure $fixes = null,
    ) {
    }

    /**
     * What a change made from fixes is: the template the settings map to the kind, the devices the fixes change, the lines of
     * the template's fix variable (as the topology plugin's fixes are now, as the admin sees them), a reference naming them and
     * what the change is meant to do; errors when it cannot be made.
     *
     * @param  string[]  $ids  the fixes chosen
     * @param  int[]  $scope  the devices the Fixes page listed them for (the topology plugin computes the fixes of these)
     * @return array{kind: string, label: string, ids: string[], scope: int[], devices: int[], template: ?string, variable: string, lines: string[], title: string,
     *     effect: string, errors: string[], fixes: list<array>, names: array<int, string>}
     */
    public function fixDraft(string $kind, array $ids, array $scope, int $userId): array
    {
        $draft = ['kind' => $kind, 'label' => FixAnswers::LABELS[$kind] ?? $kind, 'ids' => array_values(array_map('strval', $ids)), 'scope' => array_values(array_map('intval', $scope)),
            'devices' => [], 'template' => $this->settings->fixTemplate($kind), 'variable' => FixAnswers::VARIABLES[$kind] ?? '', 'lines' => [], 'title' => '', 'effect' => '',
            'errors' => [], 'fixes' => [], 'names' => []];
        if (! in_array($kind, FixAnswers::KINDS, true)) {
            return ['errors' => ['There is no fix of that kind.']] + $draft;
        }
        if ($draft['template'] === null) {
            $draft['errors'][] = 'No template is set for ' . strtolower($draft['label']) . ' fixes: an admin sets it in the plugin\'s settings (Verification and fixes).';
        }
        try {
            // only what the fixes of this kind need: of a description fix, the link check of its devices and their neighbors; of a root
            // fix, the spanning tree check of its domain
            $answer = ($this->fixes ?? fn (array $devices, int $user, string $kind) => (new TopologyVerification())->fixes($devices, $user, $kind))($draft['scope'], $userId, $kind);
        } catch (TopologyError $e) {
            return ['errors' => [...$draft['errors'], 'The topology plugin cannot list the fixes now: ' . $e->getMessage() . '.']] + $draft;
        }
        $chosen = FixChange::choose($kind, $draft['ids'], $answer);
        if ($chosen['error'] !== null) {
            return ['errors' => [...$draft['errors'], $chosen['error']]] + $draft;
        }
        $names = [];
        foreach ((array) ($answer['names'] ?? []) as $deviceId => $name) {
            if (is_scalar($name) && preg_match('/^\d{1,10}$/D', (string) $deviceId)) {
                $names[(int) $deviceId] = UntrustedText::line((string) $name, 120);
            }
        }
        $devices = FixChange::devices($kind, $chosen['fixes']);
        // the hosts of the lines: the devices' inventory names (a device that is no host of the inventory cannot be a target)
        $inventory = $this->inventory->build();
        $hosts = [];
        foreach ($devices as $deviceId) {
            $name = $inventory->nameOf($deviceId);
            if ($name !== null) {
                $hosts[$deviceId] = $name;
            }
        }
        $built = FixAnswers::lines($kind, $chosen['fixes'], $hosts);

        return ['devices' => $devices, 'lines' => $built['lines'], 'title' => FixAnswers::title($kind, $chosen['fixes']),
            'effect' => FixAnswers::effect($kind, $chosen['fixes'], $names), 'errors' => [...$draft['errors'], ...$built['errors']], 'fixes' => $chosen['fixes'],
            'names' => $names] + $draft;
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
        string $unplanned = RolloutPlan::LEAVE, int $userId = 0, ?array $fix = null): array
    {
        // a change made from fixes: the template, the devices and the fix variable's lines are the fix's, computed now; the page's lines must be them
        $draft = null;
        if ($fix !== null) {
            $draft = $this->fixDraft((string) ($fix['kind'] ?? ''), (array) ($fix['ids'] ?? []), (array) ($fix['scope'] ?? []), $userId);
            $templateId = $draft['template'] ?? $templateId;
            $deviceIds = $draft['devices'];
            $canary = [];
            $answers[$draft['variable']] = implode("\n", $draft['lines']);
            if (is_array($fix['lines'] ?? null) && array_values(array_map('strval', $fix['lines'])) !== $draft['lines'] && ! $draft['errors']) {
                $draft['errors'][] = FixAnswers::CHANGED;
            }
        }
        $launcher = new RunLauncher($this->plugin, $this->runs, $this->inventory, $this->settings, new Eventlog(), $this->changes);
        $plan = $launcher->plan($templateId, $deviceIds, $answers, new RunSpec(0, RunSpec::DRY_RUN, 0, ''));
        if ($draft !== null) {
            array_push($plan->errors, ...$draft['errors']);
            // the template must have the variable the contract names, and the lines pass the contract's checks for these targets
            $problem = $plan->template !== null ? FixAnswers::templateProblem($plan->template, $draft['kind']) : null;
            if ($problem !== null) {
                $plan->errors[] = ucfirst($problem) . '.';
            }
        }
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
            $planner = ($draft !== null && $draft['kind'] === FixAnswers::STP_ROOT_PRIORITY ? $this->planRootFix($draft, $ordered, $userId)
                : $this->planWithTopology($ordered, $chosen, $unplanned, $userId)) + $planner;
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
        if ($draft !== null) {
            // the fix's lines are those of the change's targets only, once the planner left devices out: a line of another device in
            // the approved answer would reach a playbook (a delegate_to) outside the change, its windows and its blast radius
            $kept = FixChange::forTargets($draft['kind'], $draft['fixes'], array_keys($plan->targets), $draft['names']);
            if ($kept['dropped']) {
                $built = FixAnswers::lines($draft['kind'], $kept['fixes'], array_map(fn (array $target) => (string) $target['name'], $plan->targets));
                $draft = ['fixes' => $kept['fixes'], 'lines' => $built['lines'], 'devices' => array_values(array_intersect($draft['devices'], array_keys($plan->targets)))] + $draft;
                $plan->values[$draft['variable']] = implode("\n", $built['lines']);
                array_push($plan->errors, ...array_map(fn (string $error) => 'The fix\'s ' . $error . '.', $built['errors']));
                $plan->warnings[] = 'Left out, as the topology plugin\'s planner leaves their devices out (the change\'s lines are those of its targets only): '
                    . implode('; ', array_slice($kept['dropped'], 0, 20)) . (count($kept['dropped']) > 20 ? '; ...' : '') . '.';
            }
            // the lines pass the contract's checks for the change's targets
            if (! $draft['errors'] && $plan->targets) {
                $interfaces = $draft['kind'] === FixAnswers::PORT_DESCRIPTIONS ? $this->inventory->interfaces(array_keys($plan->targets)) : null;
                foreach (FixAnswers::check($draft['kind'], implode("\n", $draft['lines']), array_map(fn (array $target) => (string) $target['name'], $plan->targets), $interfaces) as $error) {
                    $plan->errors[] = 'The fix\'s ' . $error . '.';
                }
            }
            if ($reference === '') {
                $reference = FixAnswers::reference($draft['kind'], count($draft['devices']));
            }
        } elseif ($plan->template !== null && $plan->targets) {
            // a template the settings map to a kind of fix takes only lines that pass the contract's checks for the change's targets, also
            // in a change not made from fixes
            $kind = FixAnswers::mappedKind(array_combine(FixAnswers::KINDS, array_map(fn (string $kind) => $this->settings->fixTemplate($kind), FixAnswers::KINDS)),
                (string) $plan->template->id);
            if ($kind !== null && array_key_exists(FixAnswers::VARIABLES[$kind], $plan->values)) {
                $interfaces = $kind === FixAnswers::PORT_DESCRIPTIONS ? $this->inventory->interfaces(array_keys($plan->targets)) : null;
                foreach (FixAnswers::check($kind, (string) $plan->values[FixAnswers::VARIABLES[$kind]], array_map(fn (array $target) => (string) $target['name'], $plan->targets),
                    $interfaces) as $error) {
                    $plan->errors[] = 'The answer of ' . FixAnswers::VARIABLES[$kind] . ' (this is the template of ' . strtolower(FixAnswers::LABELS[$kind]) . ' fixes): '
                        . $error . '.';
                }
            }
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

        return ['plan' => $plan, 'batches' => $batches, 'reference' => self::reference($reference), 'planner' => $planner, 'fix' => $draft];
    }

    /**
     * The batches of a root priority fix: the intended roots alone first, then their secondaries, so that the root of each VLAN
     * moves once; each batch's blast radius from the topology plugin, as for any change (when it can plan: else the settings'
     * words, the blast radius unknown). The note says why when the planner's own order was another.
     *
     * @param  int[]  $ordered  the targets
     * @return array{plan: ?BatchPlan, used: bool, why: ?string, error: ?string, api: ?string, left_out: array, notes: string[]}
     */
    private function planRootFix(array $draft, array $ordered, int $userId): array
    {
        [$primaries, $secondaries] = FixChange::rootBatches($draft['fixes']);
        $primaries = array_values(array_intersect($primaries, $ordered));
        $secondaries = array_values(array_intersect($secondaries, $ordered));
        $result = ['plan' => null, 'used' => false, 'why' => null, 'error' => null, 'api' => null, 'left_out' => [], 'notes' => []];
        $own = 'The fix sets the intended root first, alone, then its secondary, so that the root of each VLAN moves once.';
        $byHand = fn (string $why) => ['plan' => new BatchPlan(array_values(array_filter([$primaries, $secondaries])), max(1, $this->settings->int('stop_at')),
            $this->settings->all()['wait_after_canary'] !== '0', $this->settings->int('batch_pause_minutes')), 'why' => $why, 'notes' => [$own]] + $result;
        if (! $primaries) {
            return ['error' => 'The fix\'s intended root cannot be a target of the change.'] + $result;
        }
        if (! $this->settings->topologyPlan()) {
            return $byHand('planning with the topology plugin is switched off in the settings');
        }
        $topology = $this->topology ?? new TopologyPlanning();
        $why = $topology->unavailable();
        if ($why !== null) {
            return $byHand($why);
        }
        try {
            $answer = $topology->rollout($ordered, 1, $userId);
            $reordered = FixChange::rootPlanner($answer, $primaries, $secondaries, $draft['names'] + BlastRadius::names($answer));
            $made = RolloutPlan::make($ordered, $primaries, count($primaries), max(1, count($primaries), count($secondaries)), $this->settings->int('stop_at'),
                $this->settings->all()['wait_after_canary'] !== '0', $this->settings->int('batch_pause_minutes'), $reordered['planner'], RolloutPlan::HAND,
                fn (array $devices) => $topology->blastRadius($devices, $userId));
        } catch (TopologyError $e) {
            return $byHand('the topology plugin could not plan them: ' . $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $byHand('the topology plugin\'s plan cannot be used (' . UntrustedText::line($e->getMessage(), 300) . ')');
        }

        // the intended root is a core device of its part: a first batch of the core is what the fix is, not a plan to check
        $notes = array_values(array_filter($made['notes'], fn (string $note) => ! str_starts_with($note, 'The first batch is of the core')));

        return ['plan' => $made['plan'], 'used' => true, 'error' => $made['error'], 'api' => (string) ($answer['version'] ?? ''),
            'notes' => [$reordered['note'] ?? $own, ...$notes], 'core_text' => $made['plan']?->topology?->coreText() ?? ''] + $result;
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
        // a change made from fixes: what it is meant to do, and the lines its fix variable gets (read only on the page)
        $draft = $preview['fix'] ?? null;
        $page['fix'] = $draft === null ? null : ['kind' => $draft['kind'], 'label' => $draft['label'], 'ids' => $draft['ids'], 'scope' => $draft['scope'], 'devices' => $draft['devices'],
            'variable' => $draft['variable'], 'lines' => $draft['lines'], 'title' => $draft['title'], 'effect' => $draft['effect'], 'template' => $draft['template']];
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
     * The blast radius a request to create a change says its preview showed, for create(): the preview's blastSignature(),
     * or "" when it had none: the request says so with "" (the new change page's, which LibreNMS turns into null) or by
     * naming none, so a request that leaves it out is compared as one whose preview had no blast radius, never let past
     * the check.
     *
     * @throws \InvalidArgumentException when what it names is not one a preview gives
     */
    public static function expectedBlast(mixed $value): string
    {
        $value ??= '';
        if (! self::isBlastSignature($value)) {
            throw new \InvalidArgumentException('The blast radius of the preview is not one: preview again.');
        }

        return $value;
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
        ?array $expected = null, string $unplanned = RolloutPlan::LEAVE, ?string $expectedBlast = null, ?array $fix = null): array
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $requestToken)) {
            return ['change_id' => null, 'errors' => ['The form is out of date: reload the page.'], 'preview' => [], 'existing' => false];
        }
        $existing = $this->changes->byRequestToken($requestToken);
        if ($existing !== null) {
            return ['change_id' => $existing, 'errors' => [], 'preview' => [], 'existing' => true];
        }
        if ($fix !== null && ! is_array($fix['lines'] ?? null)) {
            // a change of fixes is made from the lines its page showed: they are compared with the fix as it is now
            return ['change_id' => null, 'errors' => [FixAnswers::CHANGED], 'preview' => [], 'existing' => false];
        }
        $preview = $this->preview($templateId, $deviceIds, $answers, $canary, $reference, $unplanned, (int) $user->user_id, $fix);
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
        $draft = $preview['fix'] ?? null;
        $change = ['user_id' => (int) $user->user_id, 'username' => (string) $user->username, 'template_id' => $plan->template->id,
            'template_name' => mb_substr($plan->template->name, 0, 255), 'playbook' => mb_substr($plan->template->playbook, 0, 255), 'reference' => $preview['reference'],
            'status' => ChangeStatus::DRAFT, 'variables' => $plan->variables(), 'has_secrets' => (bool) array_filter($plan->template->surveyVars, fn ($var) => $var->isSecret()),
            'plan' => $batches->toArray(), 'target_count' => count($targets), 'batch_count' => $batches->count(), 'request_token' => $requestToken]
            + ($draft !== null ? ['fix' => FixAnswers::record($draft['kind'], $draft['fixes'], $draft['lines'])] : []);
        $id = $this->changes->create($change, $targets, $batches->count());
        $change['id'] = $id;
        $this->changes->addEvent($id, 'created', 'Created (' . $batches->describe() . ').', (string) $user->username, time());
        $planner = $preview['planner'] ?? [];
        if ($batches->topology !== null) {
            $displays = array_map(fn (array $target) => (string) (($target['display'] ?? '') ?: ($target['name'] ?? '')), $plan->targets);
            $core = $batches->topology->coreText();
            $this->changes->addEvent($id, 'created', 'What each batch cuts off while it runs, as the topology plugin computed it when the change was made (checked again before each batch): '
                . $batches->topology->summary($batches->batches, array_filter($displays)) . '.' . ($core !== '' ? ' Measured from the core: ' . $core . '.' : '')
                . implode('', array_map(fn (string $warning) => ' ' . $warning, $batches->topology->coreWarnings(($draft['kind'] ?? null) === FixAnswers::STP_ROOT_PRIORITY))),
                (string) $user->username, time());
        }
        if ($batches->topology !== null && $batches->topology->leftOut) {
            $this->changes->addEvent($id, 'created', 'Left out, as the topology plugin cannot plan them (nothing tells what depends on them): '
                . RolloutPlan::reasonsText($batches->topology->leftOut, $batches->topology->names) . '.', (string) $user->username, time());
        } elseif ($batches->topology === null) {
            $this->changes->addEvent($id, 'created', 'The blast radius of its batches is unknown: they were planned by the settings, as '
                . UntrustedText::line((string) (($planner['why'] ?? '') ?: 'the topology plugin did not plan them'), 300) . '.', (string) $user->username, time());
        }
        if ($draft !== null) {
            $this->changes->addEvent($id, 'created', 'Made from ' . (count($draft['fixes']) === 1 ? 'a fix' : count($draft['fixes']) . ' fixes') . ' of the topology plugin ('
                . strtolower($draft['label']) . '): ' . UntrustedText::line($draft['title'], 600) . '. ' . UntrustedText::line($draft['effect'], 600)
                . (($planner['notes'][0] ?? null) !== null && $draft['kind'] === FixAnswers::STP_ROOT_PRIORITY ? ' ' . UntrustedText::line((string) $planner['notes'][0], 600) : ''),
                (string) $user->username, time());
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
