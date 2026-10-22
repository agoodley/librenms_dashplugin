<?php

namespace Agoodley\LibrenmsAnsible\Runs;

use Agoodley\LibrenmsAnsible\Runner\Runner;
use Agoodley\LibrenmsAnsible\Runner\RunnerEnvironment;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runner\RunnerTemplate;

/**
 * Every check of a template (TemplateCheck), with its variable group asked from the runner once per group: what the
 * new run page, the connection test and every launch use.
 */
final class TemplateReview
{
    /** @var array<string, RunnerEnvironment|string> group id => the group, or why it cannot be read */
    private array $environments = [];

    public function __construct(private readonly Runner $runner, private readonly string $inventoryId)
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

        return array_merge(TemplateCheck::reasons($template, $this->inventoryId), TemplateCheck::environmentReasons($template, $environment, $error));
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
