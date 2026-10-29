<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runner\RunnerTemplate;
use Agoodley\LibrenmsAnsible\Runs\OutputBuffer;
use Agoodley\LibrenmsAnsible\Runs\RecapParser;
use Agoodley\LibrenmsAnsible\Runs\RunOutputs;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Runs\TemplateCheck;
use Agoodley\LibrenmsAnsible\Runs\TemplateReview;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;

/**
 * Diagnostic runs: a template run on chosen devices in check mode (--check --diff), not as part of a change, to read
 * what the devices answer (show commands) or what a template would change on them (a compliance check), with no
 * approval, batches, backups or maintenance windows. It is checked and launched as a change's dry run is (RunLauncher
 * with RunSpec::diagnostic()): the template must allow a provable dry run, the targets must be free (not busy in another
 * run, nor reserved by a change being applied), and its log must prove that it ran in check mode (Changes\DryRunProof,
 * recorded at its end, as for a dry run): one that is not proven may have changed its devices, which its page and the
 * eventlog of its devices say in red. Its page shows what each task printed per device (Runs\RunOutputs).
 */
final class DiagnosticService
{
    /** Most devices a link may choose (the map's selection, a run again): the settings' highest Max targets per change. */
    public const MAX_DEVICES = 500;

    /** The log a page reads per device: the stored one, the runner's whole log (the stored one was cut), or none of them. */
    public const STORED = 'stored';

    public const RUNNER = 'runner';

    public const CUT = 'cut';

    /**
     * @param  (\Closure(string, int[], array, string): array)|null  $launcher  the tests' launch (null: RunLauncher's)
     * @param  (\Closure(): \Agoodley\LibrenmsAnsible\Runner\Runner)|null  $runner  the tests' runner (null: the plugin's)
     */
    public function __construct(
        private readonly Plugin $plugin,
        private readonly RunRepository $runs,
        private readonly InventoryService $inventory,
        private readonly PluginSettings $settings,
        private readonly Eventlog $eventlog = new Eventlog(),
        private readonly ?ChangeRepository $changes = null,
        private readonly ?\Closure $launcher = null,
        private readonly ?\Closure $runner = null,
    ) {
    }

    /**
     * The runner's templates for the form: those a diagnostic run can use (the template checks, and the dry-run check
     * but --check), the others with why not, the chosen one (the only usable one when none is chosen) with its reasons
     * and notes, and what keeps the runner from launching anything.
     *
     * @return array{usable: RunnerTemplate[], unusable: array<int, array{template: RunnerTemplate, reasons: string[]}>, template: ?RunnerTemplate,
     *               templateReasons: string[], templateNotes: string[], blocked: string[], runnerError: ?string}
     */
    public function templates(string $templateId): array
    {
        $data = ['usable' => [], 'unusable' => [], 'template' => null, 'templateReasons' => [], 'templateNotes' => [], 'blocked' => [], 'runnerError' => null];
        try {
            $connection = $this->plugin->connection();
            $runner = $this->plugin->runner($connection);
            $health = $runner->health();
            $block = $health->launchBlock();
            if ($block !== null) {
                $data['blocked'][] = $block;
            }
            $review = new TemplateReview($runner, (string) $connection->inventory, $health);
            $reader = new TemplateReader($runner, $connection->project);
            $reasons = fn (RunnerTemplate $template) => array_merge($review->reasons($template), $reader->diagnosticReasons($template));
            foreach ($runner->templates() as $template) {
                $why = $reasons($template);
                if ($why) {
                    $data['unusable'][] = ['template' => $template, 'reasons' => $why];
                } else {
                    $data['usable'][] = $template;
                }
            }
            if ($templateId === '' && count($data['usable']) === 1) {
                $templateId = $data['usable'][0]->id;
            }
            if ($templateId !== '') {
                $data['template'] = $runner->template($templateId);
                $data['templateReasons'] = $reasons($data['template']);
                $data['templateNotes'] = TemplateCheck::notes($data['template']);
                if ($data['templateReasons']) {
                    $chosen = $data['template'];
                    $data['usable'] = array_values(array_filter($data['usable'], fn (RunnerTemplate $t) => $t->id !== $chosen->id));
                    $data['unusable'] = array_values(array_filter($data['unusable'], fn (array $u) => $u['template']->id !== $chosen->id));
                    $data['unusable'][] = ['template' => $chosen, 'reasons' => $data['templateReasons']];
                }
            }
        } catch (RunnerError $e) {
            $data['runnerError'] = $e->getMessage();
        }

        return $data;
    }

    /**
     * Launch a diagnostic run: everything checked again, the run recorded, then the runner asked (RunLauncher). Its id,
     * or why it was refused (the answers' errors apart). A form token that already launched one gives that run.
     *
     * @param  \App\Models\User|object  $user
     * @param  int[]  $deviceIds
     * @param  array<string, mixed>  $answers  every answer, the secret ones too (they reach the runner only)
     * @return array{run_id: ?int, errors: string[], variable_errors: array<string, string>, existing: bool}
     */
    public function launch($user, string $templateId, array $deviceIds, #[\SensitiveParameter] array $answers, string $requestToken): array
    {
        $result = $this->launcher !== null ? ($this->launcher)($templateId, $deviceIds, $answers, $requestToken)
            : (new RunLauncher($this->plugin, $this->runs, $this->inventory, $this->settings, $this->eventlog, $this->changes))
                ->launch($user, $templateId, $deviceIds, $answers, $requestToken, RunSpec::diagnostic());
        $errors = $result['errors'];
        if ($result['run_id'] === null && ! $errors) {
            $errors = $result['plan']->errors ?: ['The launch was refused.'];
        }

        return ['run_id' => $result['run_id'], 'errors' => array_values(array_unique($errors)), 'existing' => (bool) $result['existing'],
            'variable_errors' => $result['run_id'] === null ? $result['plan']->variableErrors : []];
    }

    /**
     * What a diagnostic run again starts from (an earlier run, of any kind): its template, its targets (not the hosts it
     * ran on without them being targets) and its answers but the secret ones; null when there is no such run.
     *
     * @return array{run_id: int, template: string, devices: int[], vars: array<string, string|int>}|null
     */
    public function prefill(int $runId): ?array
    {
        $run = $this->runs->find($runId);
        if ($run === null) {
            return null;
        }
        $vars = [];
        foreach ($run['variables'] ?? [] as $variable) {
            if (is_array($variable) && empty($variable['secret']) && isset($variable['name']) && (is_string($variable['value'] ?? null) || is_int($variable['value'] ?? null))) {
                $vars[(string) $variable['name']] = $variable['value'];
            }
        }
        $devices = array_keys(array_filter($this->runs->targets($runId), fn (array $target) => empty($target['unexpected'])));

        return ['run_id' => (int) $run['id'], 'template' => (string) $run['template_id'], 'devices' => array_slice(array_map('intval', $devices), 0, self::MAX_DEVICES),
            'vars' => $vars];
    }

    /**
     * What each task of a finished run printed per device (Runs\RunOutputs), from its whole log: the stored one, else the
     * runner's (the stored one was cut to the Output size setting); when neither is whole, nothing is attributed (a
     * device's output would be read across the gap) and the page says so. Null when there is no such run.
     *
     * @return array<string, mixed>|null
     */
    public function outputs(int $runId): ?array
    {
        $run = $this->runs->find($runId);
        if ($run === null) {
            return null;
        }
        if (! RunStatus::isFinal((string) $run['status'])) {
            return ['final' => false];
        }
        $targets = array_filter($this->runs->targets($runId), fn (array $target) => empty($target['unexpected']));
        $devices = [];
        foreach ($targets as $deviceId => $target) {
            $devices[$deviceId] = (string) (($target['display'] ?? '') ?: ($target['hostname'] ?? '') ?: $target['inventory_name']);
        }
        $source = self::STORED;
        $lines = OutputBuffer::display($run);
        if (! empty($run['output_truncated'])) {
            $source = self::CUT;
            if ($run['runner_task_id'] !== null) {
                try {
                    $lines = ($this->runner !== null ? ($this->runner)() : $this->plugin->runner())->output((string) $run['runner_task_id'])->lines;
                    $source = self::RUNNER;
                } catch (RunnerError) {
                    // the runner no longer has it, or cannot be reached: nothing is attributed
                }
            }
        }
        $parsed = $source === self::CUT ? ['tasks' => [], 'unattributed' => [], 'cut' => false]
            : RunOutputs::parse($lines, array_map(fn (array $target) => (string) $target['inventory_name'], $targets), RecapParser::parse($lines));

        return ['final' => true, 'source' => $source, 'devices' => (object) $devices] + $parsed;
    }
}
