<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * A template's variable group (Semaphore UI's "environment"): the names of its environment variables and of its
 * secret environment variables. Values are never read: the plugin only checks that the inventory script gets
 * LIBRENMS_URL and the secret LIBRENMS_TOKEN.
 */
final class RunnerEnvironment
{
    /**
     * @param  string[]  $envNames  environment variables (Variables tab, "Environment variables")
     * @param  string[]  $secretEnvNames  secret environment variables (Secrets tab, "Environment variables")
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $envNames,
        public readonly array $secretEnvNames,
    ) {
    }
}
