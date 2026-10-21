<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * What the plugin asks the runner to start: a template on a limit of inventory names, with the survey answers.
 * Nothing else (no inventory, playbook, branch or CLI arguments): the template decides those. The check and
 * diff flags are for the dry runs of a later phase and stay off.
 */
final class LaunchRequest
{
    /**
     * @param  string[]  $limit  inventory names of the targets
     * @param  array<string, string|int>  $vars  answers to the survey variables that are not secret
     * @param  array<string, string>  $secrets  answers to the secret ones (never stored by the plugin)
     * @param  string  $message  shown in the runner's task list (no secrets)
     */
    public function __construct(
        public readonly string $templateId,
        public readonly array $limit,
        public readonly array $vars,
        public readonly array $secrets,
        public readonly string $message,
        public readonly bool $check = false,
        public readonly bool $diff = false,
    ) {
    }
}
