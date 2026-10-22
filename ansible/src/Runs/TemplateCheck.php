<?php

namespace Agoodley\LibrenmsAnsible\Runs;

use Agoodley\LibrenmsAnsible\Runner\RunnerEnvironment;
use Agoodley\LibrenmsAnsible\Runner\RunnerTemplate;
use Agoodley\LibrenmsAnsible\Runner\SurveyVar;

/**
 * Can the plugin launch a template safely? Only when the runner runs it on the plugin's targets and nothing
 * else: it uses the LibreNMS inventory, a launch may set its limit (else Semaphore silently runs it on every
 * host), it has no limit or CLI arguments of its own that could widen or replace the targets or override the
 * answers, its survey variables cannot set Ansible's connection or magic variables, and its variable group gives
 * the inventory script what it needs (without it the script fails, Ansible finds no host and the task still ends
 * with success). Each failed check is a reason that says what to change in the runner, in the words of Semaphore
 * UI's template editor. Checked against the template as the runner has it right before every launch.
 */
final class TemplateCheck
{
    /** CLI arguments a template may have: they do not change the targets, the inventory or the variables. */
    private const FLAGS = ['-v', '-vv', '-vvv', '-vvvv', '-vvvvv', '-vvvvvv', '--verbose', '-D', '--diff', '-C', '--check',
        '--force-handlers', '--flush-cache'];

    /** Allowed options with a value, and the value's pattern. */
    private const OPTIONS = [
        '-f' => '/^\d{1,4}$/D', '--forks' => '/^\d{1,4}$/D',
        '-T' => '/^\d{1,5}$/D', '--timeout' => '/^\d{1,5}$/D',
        '-t' => '/^[A-Za-z0-9_,.-]{1,200}$/D', '--tags' => '/^[A-Za-z0-9_,.-]{1,200}$/D',
        '--skip-tags' => '/^[A-Za-z0-9_,.-]{1,200}$/D',
    ];

    /**
     * Ansible's magic variables and names it reserves: an answer must never replace them.
     * See https://docs.ansible.com/ansible/latest/reference_appendices/special_variables.html
     */
    private const RESERVED_VARS = ['hostvars', 'groups', 'group_names', 'inventory_hostname', 'inventory_hostname_short',
        'inventory_dir', 'inventory_file', 'omit', 'play_hosts', 'playbook_dir', 'role_name', 'role_names', 'role_path',
        'environment', 'vars', 'lookup', 'query', 'q', 'now', 'undef'];

    /** Python keywords: not valid variable names in Ansible. */
    private const KEYWORDS = ['false', 'none', 'true', 'and', 'as', 'assert', 'async', 'await', 'break', 'class', 'continue',
        'def', 'del', 'elif', 'else', 'except', 'finally', 'for', 'from', 'global', 'if', 'import', 'in', 'is', 'lambda',
        'nonlocal', 'not', 'or', 'pass', 'raise', 'return', 'try', 'while', 'with', 'yield'];

    /**
     * Environment variables an answer must not set: they change how Ansible, Python, SSH or the inventory script
     * run (where it fetches the inventory, with which token and through which proxy).
     */
    private const ENV_PREFIXES = ['ANSIBLE_', 'LIBRENMS_', 'LD_', 'DYLD_', 'PYTHON', 'SSL_', 'SSH_', 'GIT_', 'BASH_', 'PERL', 'RUBY', 'NODE_'];

    private const ENV_NAMES = ['PATH', 'HOME', 'SHELL', 'ENV', 'IFS', 'PS4', 'TMPDIR', 'TMP', 'TEMP', 'USER', 'LOGNAME',
        'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'NO_PROXY', 'FTP_PROXY', 'REQUESTS_CA_BUNDLE', 'CURL_CA_BUNDLE'];

    /**
     * Why the plugin may not launch the template; empty when it may.
     *
     * @return string[]
     */
    public static function reasons(RunnerTemplate $template, string $inventoryId): array
    {
        $reasons = [];
        if (strtolower($template->app) !== 'ansible') {
            $reasons[] = "it is a {$template->app} template, not an Ansible one";
        }
        if ($template->type !== '') {
            $reasons[] = "it is a {$template->type} template: only plain task templates can be launched";
        }
        if ($inventoryId === '' || $inventoryId === '0') {
            $reasons[] = 'the LibreNMS inventory is not chosen in the plugin settings yet';
        } elseif ($template->inventoryId !== $inventoryId) {
            $reasons[] = 'it uses ' . ($template->inventoryId === null ? 'no inventory' : "inventory #{$template->inventoryId}")
                . ", not the LibreNMS inventory (#$inventoryId): set its inventory to the LibreNMS one";
        }
        if (! $template->limitOverride) {
            $reasons[] = 'a launch may not set its limit, so Semaphore would ignore the limit and run it on every host of the inventory: in the template, '
                . 'tick "Limit" under "Ansible prompts" (not the "Limit" field under "Ansible options", which is a limit of the template\'s own)';
        }
        if ($template->fixedLimit) {
            $reasons[] = 'it has a limit of its own (' . implode(', ', array_slice($template->fixedLimit, 0, 5)) . '): clear the "Limit" field under '
                . '"Ansible options" in the template, the plugin sets the limit';
        }
        $argument = self::badArgument($template->arguments);
        if ($argument !== null) {
            $reasons[] = $argument;
        }
        if ($template->canRun === false) {
            $reasons[] = "the token's user may not run it";
        }

        return array_merge($reasons, self::surveyReasons($template->surveyVars));
    }

    /**
     * Reasons about the template's variable group (Semaphore UI's "environment"): the inventory script needs the
     * environment variable LIBRENMS_URL and the secret environment variable LIBRENMS_TOKEN.
     *
     * @param  RunnerEnvironment|null  $environment  the group, null when the template has none or it cannot be read
     * @param  string|null  $error  why it cannot be read
     * @return string[]
     */
    public static function environmentReasons(RunnerTemplate $template, ?RunnerEnvironment $environment, ?string $error = null): array
    {
        if ($template->environmentId === null) {
            return ['it has no variable group: give it the LibreNMS one, with the environment variable LIBRENMS_URL and the secret environment variable '
                . 'LIBRENMS_TOKEN that the inventory script needs (without them Ansible finds no host)'];
        }
        if ($environment === null) {
            return ["its variable group #{$template->environmentId} cannot be read" . ($error !== null && $error !== '' ? ": $error" : '')];
        }
        $reasons = [];
        $name = '"' . $environment->name . '"';
        if (! in_array('LIBRENMS_URL', array_merge($environment->envNames, $environment->secretEnvNames), true)) {
            $reasons[] = "its variable group $name has no environment variable LIBRENMS_URL (Variables tab, \"Environment variables\"): the inventory "
                . 'script needs the address of LibreNMS';
        }
        if (! in_array('LIBRENMS_TOKEN', $environment->secretEnvNames, true)) {
            $reasons[] = in_array('LIBRENMS_TOKEN', $environment->envNames, true)
                ? "its variable group $name has LIBRENMS_TOKEN as a plain environment variable: make it a secret one (Secrets tab, \"Environment "
                    . 'variables"), so the token is write-only'
                : "its variable group $name has no secret environment variable LIBRENMS_TOKEN (Secrets tab, \"Environment variables\"; an extra variable "
                    . 'does not reach the inventory script): the script needs the inventory user\'s API token';
        }

        return $reasons;
    }

    /**
     * What the plugin does not need from the template but the token could use: the overrides it allows a task.
     *
     * @return string[]
     */
    public static function notes(RunnerTemplate $template): array
    {
        if (! $template->taskOverrides) {
            return [];
        }

        return ['it lets a task override its ' . implode(', ', $template->taskOverrides) . ': the plugin never sends these, but whoever holds the token could; switch them off unless needed'];
    }

    /**
     * The first CLI argument outside the allowed ones, as a reason; null when all are allowed.
     *
     * @param  string[]|null  $arguments
     */
    private static function badArgument(?array $arguments): ?string
    {
        if ($arguments === null) {
            return 'its CLI arguments are not a JSON list of strings, so the plugin cannot check them: fix them in the template';
        }
        $allowed = 'only verbosity (-v), --diff, --forks, --timeout, --tags, --skip-tags, --force-handlers and --flush-cache are accepted (not --check: a change\'s '
            . 'dry run adds it itself)';
        for ($i = 0; $i < count($arguments); $i++) {
            $argument = trim($arguments[$i]);
            if (in_array($argument, self::FLAGS, true)) {
                continue;
            }
            if (preg_match('/^(--[a-z-]+)=(.*)$/sD', $argument, $m) && isset(self::OPTIONS[$m[1]]) && preg_match(self::OPTIONS[$m[1]], $m[2])) {
                continue;
            }
            if (isset(self::OPTIONS[$argument]) && isset($arguments[$i + 1]) && preg_match(self::OPTIONS[$argument], trim($arguments[$i + 1]))) {
                $i++;

                continue;
            }

            return 'its CLI arguments include "' . mb_substr($argument, 0, 60) . "\", which could change the targets, the inventory or the variables ($allowed): remove it";
        }

        return null;
    }

    /**
     * Reasons about the survey variables: names, types and choices.
     *
     * @param  SurveyVar[]  $vars
     * @return string[]
     */
    private static function surveyReasons(array $vars): array
    {
        $reasons = [];
        $seen = [];
        foreach ($vars as $var) {
            $name = $var->name;
            $label = '"' . mb_substr($name, 0, 60) . '"';
            if (isset($seen[$name])) {
                $reasons[] = "the survey variable $label is defined twice";

                continue;
            }
            $seen[$name] = true;
            $reason = $var->target === 'env' ? self::envReason($name) : self::varReason($name);
            if ($reason !== null) {
                $reasons[] = "the survey variable $label $reason";
            }
            if ($var->target !== '' && $var->target !== 'env') {
                $reasons[] = "the survey variable $label goes to \"{$var->target}\", which the plugin does not know";
            }
            if (! in_array($var->type, SurveyVar::TYPES, true)) {
                $reasons[] = "the survey variable $label has the type \"{$var->rawType}\", which the plugin does not know";
            } elseif ($var->type === 'enum' && ! $var->values) {
                $reasons[] = "the survey variable $label is a list of choices without any choice";
            }
        }

        return $reasons;
    }

    /**
     * Why a name may not be an extra variable, null when it may.
     */
    private static function varReason(string $name): ?string
    {
        $lower = strtolower($name);
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,99}$/D', $name) || in_array($lower, self::KEYWORDS, true)) {
            return 'is not a valid Ansible variable name';
        }
        if (str_starts_with($lower, 'ansible_') || in_array($lower, self::RESERVED_VARS, true)) {
            return 'is an Ansible connection or magic variable: an answer could change where or how the playbook connects';
        }
        if (str_starts_with($lower, 'librenms_')) {
            return 'would replace a librenms_* fact of the inventory with an answer';
        }

        return null;
    }

    /**
     * Why a name may not be an environment variable of the run, null when it may.
     */
    private static function envReason(string $name): ?string
    {
        $upper = strtoupper($name);
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,99}$/D', $name)) {
            return 'is not a valid environment variable name';
        }
        foreach (self::ENV_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return "sets the environment variable $name, which changes how Ansible, Python, SSH or the inventory script run";
            }
        }
        if (in_array($upper, self::ENV_NAMES, true)) {
            return "sets the environment variable $name, which changes how Ansible, Python, SSH or the inventory script run";
        }

        return null;
    }
}
