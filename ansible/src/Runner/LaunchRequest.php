<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * What the plugin asks the runner to start: a template on a limit of inventory names, with the survey answers, as a
 * dry run (check and diff) or not, and for the batches of a change the commit of the playbook repository its dry run
 * ran. Nothing else (no inventory, playbook, branch or CLI arguments): the template decides those.
 */
final class LaunchRequest
{
    /**
     * @param  string[]  $limit  inventory names of the targets
     * @param  array<string, string|int>  $vars  answers to the survey variables that are not secret
     * @param  array<string, string>  $secrets  answers to the secret ones (never stored by the plugin)
     * @param  string  $message  shown in the runner's task list (no secrets)
     * @param  string|null  $commit  run exactly this commit of the repository (a hex hash), null: the runner's latest
     */
    public function __construct(
        public readonly string $templateId,
        public readonly array $limit,
        public readonly array $vars,
        public readonly array $secrets,
        public readonly string $message,
        public readonly bool $check = false,
        public readonly bool $diff = false,
        public readonly ?string $commit = null,
    ) {
    }
}
