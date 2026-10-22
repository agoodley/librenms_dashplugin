<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * A template's variable group (Semaphore UI's "environment"): the names of its environment variables and of its
 * secret environment variables, the values of the environment variables that are not secret (the dry runs need
 * ANSIBLE_CHECK_MODE_MARKERS), a hash of its extra variables and the names of its secret ones. Secret values are
 * never read: Semaphore UI does not return them.
 */
final class RunnerEnvironment
{
    /**
     * @param  string[]  $envNames  environment variables (Variables tab, "Environment variables")
     * @param  string[]  $secretEnvNames  secret environment variables (Secrets tab, "Environment variables")
     * @param  array<string, string>  $env  the environment variables that are not secret, name => value
     * @param  string  $extraVarsHash  SHA-256 of its extra variables (Variables tab, "Extra variables"), "" for none
     * @param  string[]  $secretVarNames  secret extra variables (Secrets tab, "Extra variables")
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $envNames,
        public readonly array $secretEnvNames,
        public readonly array $env = [],
        public readonly string $extraVarsHash = '',
        public readonly array $secretVarNames = [],
    ) {
    }
}
