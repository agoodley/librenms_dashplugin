<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * A template of the runner as the plugin needs it to decide whether it can launch it safely
 * (Runs\TemplateCheck): its inventory, whether a launch may set the limit, what else could set the
 * targets (a limit or CLI arguments of its own), and the variables it asks for.
 */
final class RunnerTemplate
{
    /**
     * @param  string|null  $inventoryId  the inventory every task of the template uses
     * @param  bool  $limitOverride  may a task set the limit (else the runner ignores it and runs on every host)
     * @param  string[]  $fixedLimit  the template's own limit
     * @param  string[]|null  $arguments  the template's fixed CLI arguments, null when unreadable
     * @param  SurveyVar[]  $surveyVars
     * @param  bool|null  $canRun  may the token's user run it (null: unknown)
     * @param  string[]  $taskOverrides  what else a task may override (arguments, inventory, branch, ...)
     * @param  string|null  $environmentId  the template's variable group (null: none)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly string $playbook,
        public readonly string $app,
        public readonly string $type,
        public readonly ?string $inventoryId,
        public readonly bool $limitOverride,
        public readonly array $fixedLimit,
        public readonly ?array $arguments,
        public readonly string $argumentsRaw,
        public readonly array $surveyVars,
        public readonly ?bool $canRun = null,
        public readonly array $taskOverrides = [],
        public readonly ?string $environmentId = null,
    ) {
    }

    /**
     * A survey variable by name.
     */
    public function surveyVar(string $name): ?SurveyVar
    {
        foreach ($this->surveyVars as $var) {
            if ($var->name === $name) {
                return $var;
            }
        }

        return null;
    }
}
