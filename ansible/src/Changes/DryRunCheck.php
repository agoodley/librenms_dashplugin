<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Runner\RunnerEnvironment;
use Agoodley\LibrenmsAnsible\Runner\RunnerTemplate;

/**
 * Can the plugin prove a dry run of the template? Only when Ansible marks what runs in check mode: the template's
 * variable groups (merged as Semaphore UI merges them, a later group over an earlier one, a secret over a variable)
 * must set the environment variable ANSIBLE_CHECK_MODE_MARKERS to true, and must not switch Ansible to another output
 * (ANSIBLE_STDOUT_CALLBACK) or hide results (ANSIBLE_DISPLAY_OK_HOSTS, ANSIBLE_DISPLAY_SKIPPED_HOSTS). And a template
 * whose own CLI arguments hold --check would apply nothing. Each reason says what to change in the runner.
 */
final class DryRunCheck
{
    public const MARKERS = 'ANSIBLE_CHECK_MODE_MARKERS';

    /** Values Ansible reads as true in a boolean setting. */
    private const TRUE = ['y', 'yes', 'on', '1', 'true', 't'];

    /** Settings that change what Ansible prints, and the values that keep what the plugin reads. */
    private const OUTPUT = [
        'ANSIBLE_STDOUT_CALLBACK' => ['default', 'ansible.builtin.default'],
        'ANSIBLE_DISPLAY_OK_HOSTS' => self::TRUE,
        'ANSIBLE_DISPLAY_SKIPPED_HOSTS' => self::TRUE,
    ];

    /**
     * Why the plugin cannot prove a dry run of the template; empty when it can.
     *
     * @param  array<int, RunnerEnvironment|string>  $groups  the template's variable groups in its order, or why one cannot be read
     * @return string[]
     */
    public static function reasons(RunnerTemplate $template, array $groups): array
    {
        $reasons = [];
        foreach ($template->arguments ?? [] as $argument) {
            if (in_array(trim($argument), ['-C', '--check'], true)) {
                $reasons[] = 'its CLI arguments hold ' . trim($argument) . ', so every run of it is a dry run and an apply would change nothing: remove it (the plugin '
                    . 'launches the dry run itself)';
            }
        }
        if (! $groups) {
            return array_merge($reasons, ['it has no variable group, so nothing sets ' . self::MARKERS . '=true, which the plugin needs to prove a dry run']);
        }
        $env = [];
        $secret = [];
        foreach ($groups as $index => $group) {
            if (! $group instanceof RunnerEnvironment) {
                $reasons[] = 'its variable group #' . ($template->variableGroups()[$index] ?? '?') . ' cannot be read' . (is_string($group) && $group !== '' ? ": $group" : '');

                continue;
            }
            $env = array_merge($env, $group->env);
            foreach ($group->secretEnvNames as $name) {
                $secret[strtoupper($name)] = $group->name;
            }
        }
        $value = $env[self::MARKERS] ?? null;
        if (isset($secret[self::MARKERS])) {
            $reasons[] = 'its variable group "' . $secret[self::MARKERS] . '" has ' . self::MARKERS . ' as a secret, whose value the plugin cannot read: make it a '
                . 'variable (Variables tab, "Environment variables") with the value true';
        } elseif ($value === null) {
            $reasons[] = 'its variable groups do not set the environment variable ' . self::MARKERS . ' to true (Variables tab, "Environment variables"): '
                . 'without it Ansible does not mark what runs in check mode, and the plugin cannot prove a dry run';
        } elseif (! in_array(strtolower(trim($value)), self::TRUE, true)) {
            $reasons[] = 'its variable groups set ' . self::MARKERS . ' to "' . mb_substr($value, 0, 20) . '": set it to true, so Ansible marks what runs in check mode';
        }
        foreach (self::OUTPUT as $name => $good) {
            if (isset($secret[$name])) {
                $reasons[] = "its variable group \"{$secret[$name]}\" sets $name as a secret, which can change what Ansible prints: remove it";
            } elseif (isset($env[$name]) && ! in_array(strtolower(trim($env[$name])), $good, true)) {
                $reasons[] = "its variable groups set $name to \"" . mb_substr($env[$name], 0, 40) . '", which changes what Ansible prints (the plugin reads its '
                    . 'default output, with every result): remove it';
            }
        }

        return $reasons;
    }
}
