<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Changes\DryRunCheck;
use Agoodley\LibrenmsAnsible\Changes\TemplateSnapshot;
use Agoodley\LibrenmsAnsible\Runner\Runner;
use Agoodley\LibrenmsAnsible\Runner\RunnerEnvironment;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runner\RunnerInventory;
use Agoodley\LibrenmsAnsible\Runner\RunnerTemplate;

/**
 * Reads what a change needs of a template from the runner, each time (never cached): every variable group of the
 * template (DryRunCheck: can a dry run of it be proven?) and its repository, for its snapshot (TemplateSnapshot, the
 * runner's part of a change's fingerprint).
 */
final class TemplateReader
{
    /** @var array<string, RunnerEnvironment|string> group id => the group, or why it cannot be read */
    private array $groups = [];

    public function __construct(private readonly Runner $runner, private readonly int $project)
    {
    }

    /**
     * The template's variable groups in its order, or why one cannot be read.
     *
     * @return array<int, RunnerEnvironment|string>
     */
    public function groups(RunnerTemplate $template): array
    {
        $groups = [];
        foreach ($template->variableGroups() as $id) {
            $groups[] = $this->groups[$id] ??= $this->group($id);
        }

        return $groups;
    }

    /**
     * Why a dry run of the template cannot be proven; empty when it can.
     *
     * @return string[]
     */
    public function dryRunReasons(RunnerTemplate $template): array
    {
        return DryRunCheck::reasons($template, $this->groups($template));
    }

    /**
     * The template's snapshot with the LibreNMS inventory, or why it cannot be read (a variable group or the repository).
     *
     * @return array{parts: ?array, error: ?string}
     */
    public function snapshot(RunnerTemplate $template, RunnerInventory $inventory): array
    {
        $groups = $this->groups($template);
        foreach ($groups as $index => $group) {
            if (! $group instanceof RunnerEnvironment) {
                return ['parts' => null, 'error' => 'variable group #' . ($template->variableGroups()[$index] ?? '?') . ' cannot be read: ' . $group];
            }
        }
        try {
            $repository = $template->repositoryId !== null ? $this->runner->repository($template->repositoryId) : null;
        } catch (RunnerError $e) {
            return ['parts' => null, 'error' => 'its repository cannot be read: ' . $e->getMessage()];
        }

        return ['parts' => TemplateSnapshot::parts(strtolower(str_replace(' ', '_', $this->runner->name())), $this->project, $template, $groups, $inventory, $repository),
            'error' => null];
    }

    /**
     * A variable group, or why the runner did not give it.
     */
    private function group(string $id): RunnerEnvironment|string
    {
        try {
            return $this->runner->environment($id);
        } catch (RunnerError $e) {
            return $e->getMessage();
        }
    }
}
