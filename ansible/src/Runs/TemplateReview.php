<?php

namespace Agoodley\LibrenmsAnsible\Runs;

use Agoodley\LibrenmsAnsible\Runner\Runner;
use Agoodley\LibrenmsAnsible\Runner\RunnerEnvironment;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runner\RunnerHealth;
use Agoodley\LibrenmsAnsible\Runner\RunnerTemplate;

/**
 * Every check of a template (TemplateCheck), with its variable group asked from the runner once per group: what the
 * new run page, the connection test and every launch use.
 */
final class TemplateReview
{
    /** @var array<string, RunnerEnvironment|string> group id => the group, or why it cannot be read */
    private array $environments = [];

    /**
     * @param  RunnerHealth|null  $health  what the token may do, when known: a token that may not run tasks makes every
     *                                    template unusable, said up front rather than at the launch
     */
    public function __construct(private readonly Runner $runner, private readonly string $inventoryId, private readonly ?RunnerHealth $health = null)
    {
    }

    /**
     * Why the plugin may not launch the template; empty when it may.
     *
     * @return string[]
     */
    public function reasons(RunnerTemplate $template): array
    {
        $environment = null;
        $error = null;
        if ($template->environmentId !== null) {
            $found = $this->environments[$template->environmentId] ??= $this->read($template->environmentId);
            $environment = $found instanceof RunnerEnvironment ? $found : null;
            $error = is_string($found) ? $found : null;
        }

        $reasons = array_merge(TemplateCheck::reasons($template, $this->inventoryId), TemplateCheck::environmentReasons($template, $environment, $error));
        if ($this->health !== null && ! $this->health->canRun && ! in_array("the token's user may not run it", $reasons, true)) {
            array_unshift($reasons, "the token's user may not run tasks in the project (" . ($this->health->role ? 'the ' . RunnerHealth::roleName($this->health->role) . ' role' : 'no role')
                . '): give it the Task Runner role');
        }

        return $reasons;
    }

    /**
     * A variable group, or why the runner did not give it.
     */
    private function read(string $id): RunnerEnvironment|string
    {
        try {
            return $this->runner->environment($id);
        } catch (RunnerError $e) {
            return $e->getMessage();
        }
    }
}
