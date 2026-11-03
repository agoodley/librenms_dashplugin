<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;

/**
 * What an approval approves, as one hash: the template as the runner has it (TemplateSnapshot: its playbook,
 * inventory, repository, variable groups and every field the template check reads), the targets, how the runner
 * reaches each of them (Connections: its address and platform), the answers that are not secret, the batch plan, and
 * the dry run with the commit it ran (when the runner tells). It is computed when the dry run is launched, compared
 * when the change is approved and before every batch (the template re-read from the runner, the connections from the
 * inventory), so an apply never runs something other than what was dry-run and approved, nor elsewhere. Secret answers
 * are not part of it: the plugin never keeps them.
 */
final class Fingerprint
{
    /** Labels of the parts, for "what changed" messages. */
    private const LABELS = [
        'runner' => 'the runner project',
        'template' => 'the template',
        'environments' => 'its variable groups',
        'inventory' => 'the LibreNMS inventory in the runner',
        'repository' => 'its repository',
        'targets' => 'the targets',
        'connections' => 'how the runner reaches the targets',
        'answers' => 'the answers',
        'plan' => 'the batch plan',
    ];

    /** Labels of the template's fields. */
    private const TEMPLATE_FIELDS = [
        'app' => 'kind', 'type' => 'type', 'playbook' => 'playbook', 'inventory_id' => 'inventory', 'repository_id' => 'repository',
        'environment_ids' => 'variable groups', 'limit_override' => 'limit override', 'fixed_limit' => 'own limit', 'arguments' => 'CLI arguments',
        'task_overrides' => 'task overrides', 'survey' => 'survey variables', 'git_branch' => 'Git branch', 'tags' => 'tags', 'skip_tags' => 'skip tags', 'options' => 'other options (Galaxy install, vaults, runner, image)',
    ];

    /**
     * The hash of a set of parts: SHA-256 of their JSON with the keys of every object sorted (lists keep their order).
     *
     * @param  array<string, mixed>  $parts
     */
    public static function of(array $parts): string
    {
        return hash('sha256', json_encode(self::canonical($parts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * The hash of what an approval approves: the change's parts and the dry run (its run id and the commit it ran).
     */
    public static function approval(string $planFingerprint, int $dryRunId, ?string $commit): string
    {
        return hash('sha256', $planFingerprint . '|' . $dryRunId . '|' . ($commit ?? ''));
    }

    /**
     * The parts of a change: the template's snapshot and what the change itself fixes.
     *
     * @param  array<string, mixed>  $snapshot  TemplateSnapshot::parts()
     * @param  array<int, string>  $targets  device_id => inventory name
     * @param  array<int|string, mixed>  $connections  device_id => how the runner reaches it (Connections::of()), as recorded at the dry run
     * @param  array<string, string|int>  $answers  the answers that are not secret
     * @param  array<string, mixed>  $plan  BatchPlan::toArray()
     * @return array<string, mixed>
     */
    public static function parts(array $snapshot, array $targets, array $connections, array $answers, array $plan): array
    {
        ksort($targets);
        ksort($connections);

        return $snapshot + ['targets' => array_map('strval', $targets), 'connections' => (object) $connections, 'answers' => (object) $answers, 'plan' => $plan];
    }

    /**
     * What differs between two sets of parts, in words ("the template's playbook", "its variable groups"), at most $max.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return string[]
     */
    public static function differences(array $before, array $after, int $max = 8): array
    {
        $before = self::canonical($before);
        $after = self::canonical($after);
        $found = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (($before[$key] ?? null) == ($after[$key] ?? null)) {
                continue;
            }
            if ($key === 'template' && is_array($before[$key] ?? null) && is_array($after[$key] ?? null)) {
                foreach (array_unique(array_merge(array_keys($before[$key]), array_keys($after[$key]))) as $field) {
                    if (($before[$key][$field] ?? null) != ($after[$key][$field] ?? null)) {
                        $found[] = "the template's " . (self::TEMPLATE_FIELDS[$field] ?? UntrustedText::line($field, 40));
                    }
                }

                continue;
            }
            $found[] = self::LABELS[$key] ?? UntrustedText::line((string) $key, 40);
        }

        return array_slice(array_values(array_unique($found)), 0, $max);
    }

    /**
     * A value with the keys of its objects sorted, recursively (objects as arrays).
     */
    private static function canonical(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
            if (! $value) {
                return new \stdClass();
            }
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($item) => self::canonical($item), $value);
    }
}
