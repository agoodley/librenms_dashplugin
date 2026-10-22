<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Runner\RunnerEnvironment;
use Agoodley\LibrenmsAnsible\Runner\RunnerInventory;
use Agoodley\LibrenmsAnsible\Runner\RunnerRepository;
use Agoodley\LibrenmsAnsible\Runner\RunnerTemplate;

/**
 * The template as the runner has it, as the parts of a change's fingerprint (Fingerprint): every field that decides
 * what a launch runs and on which hosts (the fields the template check reads, its playbook, repository and branch),
 * its variable groups (environment variables, extra variables and the names of the secrets; values as hashes, so the
 * stored parts hold none), the LibreNMS inventory and the repository. A change of any of them between the dry run and
 * a batch voids the approval.
 */
final class TemplateSnapshot
{
    /**
     * @param  RunnerEnvironment[]  $groups  the template's variable groups, in its order
     * @return array<string, mixed>
     */
    public static function parts(string $runner, int $project, RunnerTemplate $template, array $groups, RunnerInventory $inventory, ?RunnerRepository $repository): array
    {
        $survey = [];
        foreach ($template->surveyVars as $var) {
            $survey[] = ['name' => $var->name, 'type' => $var->rawType, 'required' => $var->required, 'target' => $var->target,
                'values' => array_values(array_column($var->values, 'value')), 'default' => $var->default];
        }
        $environments = [];
        foreach ($groups as $group) {
            $env = array_map(fn ($value) => hash('sha256', (string) $value), $group->env);
            ksort($env);
            $secretEnv = $group->secretEnvNames;
            sort($secretEnv);
            $secretVars = $group->secretVarNames;
            sort($secretVars);
            $environments[] = ['id' => $group->id, 'env' => (object) $env, 'secret_env' => $secretEnv, 'extra_vars' => $group->extraVarsHash, 'secret_vars' => $secretVars];
        }

        return [
            'runner' => ['kind' => $runner, 'project' => $project],
            'template' => ['id' => $template->id, 'app' => $template->app, 'type' => $template->type, 'playbook' => $template->playbook,
                'inventory_id' => $template->inventoryId, 'repository_id' => $template->repositoryId, 'environment_ids' => $template->variableGroups(),
                'limit_override' => $template->limitOverride, 'fixed_limit' => $template->fixedLimit, 'arguments' => $template->argumentsRaw,
                'task_overrides' => $template->taskOverrides, 'survey' => $survey, 'git_branch' => $template->gitBranch],
            'environments' => $environments,
            'inventory' => ['id' => $inventory->id, 'type' => $inventory->type, 'source' => $inventory->source],
            'repository' => $repository === null ? null : ['id' => $repository->id, 'git_url' => $repository->gitUrl, 'git_branch' => $repository->gitBranch],
        ];
    }
}
