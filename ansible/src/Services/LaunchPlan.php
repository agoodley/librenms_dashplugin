<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Runner\Runner;
use Agoodley\LibrenmsAnsible\Runner\RunnerTemplate;

/**
 * What a launch would start (RunLauncher::plan()): the template as the runner has it now, the targets and the
 * refused devices, the checked answers, and what stops it. forPage() is what the preview shows: the secret
 * answers never leave this object except toward the runner.
 */
final class LaunchPlan
{
    /** @var string[] what prevents the launch */
    public array $errors = [];

    /** @var array<string, string> survey variable => what is wrong with its answer */
    public array $variableErrors = [];

    /** @var string[] what the user should know but does not prevent the launch */
    public array $warnings = [];

    public ?RunnerTemplate $template = null;

    public ?Runner $runner = null;

    /** @var array<int, array{device_id: int, name: string, display: string, hostname: string, os: string, platform: string}> device_id => target */
    public array $targets = [];

    /** @var array<int, array{device_id: int, display: string, reason: string, text: string}> device_id => refused device */
    public array $refused = [];

    /** @var array<string, string|int> answers that are not secret */
    public array $values = [];

    /** @var array<string, string> secret answers, for the runner only */
    public array $secrets = [];

    /** @var array<string, string> answers that were refused (not secret ones), as given: the preview shows them with their error */
    public array $rejected = [];

    public int $maxTargets = 0;

    /** The runner's project the run is launched in (the plugin's connection). */
    public int $project = 0;

    /** @var array<string, mixed>|null the template's snapshot (Changes\TemplateSnapshot), for the runs of changes */
    public ?array $snapshot = null;

    /**
     * Can its targets be launched as they are? Refused devices are left out of the run (the preview lists them);
     * a launch request that names one is refused (RunLauncher::launch()).
     */
    public function ok(): bool
    {
        return ! $this->errors && ! $this->variableErrors && $this->targets && $this->template !== null;
    }

    /**
     * The inventory names of the targets: the limit.
     *
     * @return string[]
     */
    public function limit(): array
    {
        return array_values(array_column($this->targets, 'name'));
    }

    /**
     * The answers as the audit log keeps them: secret ones without their value; with whether the survey requires each.
     *
     * @return array<int, array{name: string, label: string, type: string, value: string|int|null, secret: bool, required: bool}>
     */
    public function variables(): array
    {
        $variables = [];
        foreach ($this->template?->surveyVars ?? [] as $var) {
            $given = array_key_exists($var->name, $this->values) || array_key_exists($var->name, $this->secrets);
            $variables[] = ['name' => $var->name, 'label' => $var->label(), 'type' => $var->type,
                'value' => $var->isSecret() ? ($given ? '********' : null) : ($this->values[$var->name] ?? null), 'secret' => $var->isSecret(),
                'required' => $var->required];
        }

        return $variables;
    }

    /**
     * The answers as the preview shows them: like variables(), with each refused answer as it was given and its
     * error (a secret one stays masked).
     *
     * @return array<int, array{name: string, label: string, type: string, value: string|int|null, secret: bool, error: ?string}>
     */
    public function previewVariables(): array
    {
        $variables = [];
        foreach ($this->variables() as $variable) {
            $error = $this->variableErrors[$variable['name']] ?? null;
            if ($error !== null && ! $variable['secret'] && array_key_exists($variable['name'], $this->rejected)) {
                $variable['value'] = $this->rejected[$variable['name']];
            }
            $variables[] = $variable + ['error' => $error];
        }

        return $variables;
    }

    /**
     * The preview: everything but the secret answers.
     *
     * @return array<string, mixed>
     */
    public function forPage(): array
    {
        return [
            'ok' => $this->ok(),
            'errors' => $this->errors,
            'variable_errors' => (object) $this->variableErrors,
            'warnings' => $this->warnings,
            'template' => $this->template === null ? null : ['id' => $this->template->id, 'name' => $this->template->name,
                'playbook' => $this->template->playbook, 'description' => $this->template->description],
            'targets' => array_values($this->targets),
            'refused' => array_values($this->refused),
            'variables' => $this->previewVariables(),
            'limit' => implode(',', $this->limit()),
            'max_targets' => $this->maxTargets,
        ];
    }
}
