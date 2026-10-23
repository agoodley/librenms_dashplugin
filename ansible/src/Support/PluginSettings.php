<?php

namespace Agoodley\LibrenmsAnsible\Support;

use Agoodley\LibrenmsAnsible\Inventory\PlatformMap;
use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;

/**
 * What runs may reach and how: the platform mapping, the allowed device group, the max targets per change (and run), the
 * output kept, when an unfinished run is stopped, how long a stopped run keeps its devices, and the inventory user
 * (the LibreNMS user whose API token the runner fetches the inventory with); and how changes go (phase 2): approvals,
 * how long a dry run and an approval count, the batches, the Oxidized backups and the maintenance windows; and (phase 3)
 * whether the topology plugin plans the batches and what a batch does when its blast radius grew since the approval or
 * cannot be computed; and (phase 4) how each batch is verified and which template applies each kind of fix. The plugin
 * stores them itself (Data\SettingsRepository) and only LibreNMS admins change them, like the runner connection:
 * LibreNMS' own plugin settings form can be saved by any holder of its plugin.admin permission, which non-admin roles
 * can have, so the plugin keeps nothing there.
 */
final class PluginSettings
{
    public const DEFAULTS = [
        'platform_map' => '',
        'allowed_group' => '',
        'max_targets' => '25',
        'output_max_kb' => '1024',
        'stop_after_hours' => '24',
        'stop_hold_minutes' => '30',
        'inventory_user' => '',
        'approval' => 'required',
        'approval_hours' => '24',
        'dry_run_hours' => '24',
        'canary_size' => '1',
        'batch_size' => '5',
        'stop_at' => '1',
        'wait_after_canary' => '1',
        'batch_pause_minutes' => '0',
        'backups' => '1',
        'backup_policy' => 'refuse',
        'backup_timeout_minutes' => '10',
        'maintenance' => '1',
        'maintenance_behavior' => '',
        'maintenance_minutes' => '60',
        'settle_minutes' => '10',
        'topology_plan' => '1',
        'blast_grew' => 'pause',
        'blast_unknown' => 'pause',
        'verify' => '1',
        'verify_fresh' => 'ask',
        'verify_wait_minutes' => '30',
        'verify_unexpected' => 'pause',
        'verify_unknown' => 'pause',
        'verify_minimum' => 'warning',
        'fix_template_port_descriptions' => '',
        'fix_template_stp_root_priority' => '',
    ];

    /** The settings that map a kind of fix to the template that applies it (Changes\FixAnswers::KINDS). */
    public const FIX_TEMPLATES = ['port_descriptions' => 'fix_template_port_descriptions', 'stp_root_priority' => 'fix_template_stp_root_priority'];

    /** Settings with a fixed set of values: key => value => label. */
    public const CHOICES = [
        'approval' => ['off' => 'no approval', 'required' => 'an approval', 'second' => 'an approval from a second admin'],
        'backup_policy' => ['refuse' => 'refuse the batch', 'warn' => 'continue with a warning'],
        'maintenance_behavior' => ['' => "LibreNMS' default", '1' => 'skip alerts', '2' => 'mute alerts', '3' => 'run alerts'],
        'wait_after_canary' => ['1' => 'on', '0' => 'off'],
        'backups' => ['1' => 'on', '0' => 'off'],
        'maintenance' => ['1' => 'on', '0' => 'off'],
        'topology_plan' => ['1' => 'on', '0' => 'off'],
        'blast_grew' => ['pause' => 'pause until an admin accepts it', 'refuse' => 'refuse the batch'],
        'blast_unknown' => ['pause' => 'pause for an admin', 'refuse' => 'refuse the batch'],
        'verify' => ['1' => 'on', '0' => 'off'],
        'verify_fresh' => ['ask' => 'ask LibreNMS for a discovery', 'wait' => 'wait for LibreNMS\' own discovery and polling'],
        'verify_unexpected' => ['pause' => 'pause until an admin accepts them', 'stop' => 'stop the change', 'record' => 'only record them'],
        'verify_unknown' => ['pause' => 'pause until an admin accepts it', 'stop' => 'stop the change', 'record' => 'only record it'],
        'verify_minimum' => ['critical' => 'critical', 'warning' => 'warning', 'info' => 'info'],
    ];

    /** Ranges of the numbers: [minimum, maximum]. */
    public const LIMITS = [
        'max_targets' => [1, 500],
        'output_max_kb' => [16, 8192],
        'stop_after_hours' => [1, 720],
        'stop_hold_minutes' => [1, 1440],
        'approval_hours' => [1, 720],
        'dry_run_hours' => [1, 720],
        'canary_size' => [1, 100],
        'batch_size' => [1, 500],
        'stop_at' => [1, 500],
        'batch_pause_minutes' => [0, 1440],
        'backup_timeout_minutes' => [1, 120],
        'maintenance_minutes' => [5, 1440],
        'settle_minutes' => [0, 1440],
        'verify_wait_minutes' => [1, 1440],
    ];

    /** Names of the settings in messages and the eventlog. */
    public const LABELS = [
        'platform_map' => 'platform mapping',
        'allowed_group' => 'allowed device group',
        'max_targets' => 'max targets per change',
        'output_max_kb' => 'output size (KB)',
        'stop_after_hours' => 'stop after (hours)',
        'stop_hold_minutes' => 'hold after a stop (minutes)',
        'inventory_user' => 'inventory user',
        'approval' => 'approval',
        'approval_hours' => 'approval valid (hours)',
        'dry_run_hours' => 'dry run valid (hours)',
        'canary_size' => 'first batch (devices)',
        'batch_size' => 'batch size',
        'stop_at' => 'stop at failed devices in a batch',
        'wait_after_canary' => 'wait after the first batch',
        'batch_pause_minutes' => 'pause between batches (minutes)',
        'backups' => 'Oxidized backups',
        'backup_policy' => 'without a fresh backup',
        'backup_timeout_minutes' => 'backup timeout (minutes)',
        'maintenance' => 'maintenance windows',
        'maintenance_behavior' => 'maintenance behavior',
        'maintenance_minutes' => 'expected batch time (minutes)',
        'settle_minutes' => 'settle time (minutes)',
        'topology_plan' => 'plan with the topology plugin',
        'blast_grew' => 'when the blast radius grew',
        'blast_unknown' => 'when the blast radius cannot be computed',
        'verify' => 'verify changes',
        'verify_fresh' => 'fresh data',
        'verify_wait_minutes' => 'wait for fresh data (minutes)',
        'verify_unexpected' => 'when a batch makes unexpected changes',
        'verify_unknown' => 'when a batch cannot be verified',
        'verify_minimum' => 'count findings from',
        'fix_template_port_descriptions' => 'template for port description fixes',
        'fix_template_stp_root_priority' => 'template for root priority fixes',
    ];

    /** Most characters of the platform mapping. */
    public const PLATFORM_MAP_MAX = 20000;

    /** @var array<string, string> */
    private array $values;

    /**
     * @param  array  $stored  the stored settings (strings, may be incomplete)
     * @param  string|null  $error  why the stored settings could not be read: the plugin then launches nothing and
     *                              serves no inventory, rather than fall back to the defaults (which may reach more)
     */
    public function __construct(array $stored = [], public readonly ?string $error = null)
    {
        $this->values = self::merge($stored);
    }

    /**
     * The stored settings over the defaults, as strings.
     *
     * @return array<string, string>
     */
    public static function merge(array $stored): array
    {
        $values = self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists($key, $stored) && is_scalar($stored[$key])) {
                $values[$key] = trim((string) $stored[$key]);
            }
        }

        return $values;
    }

    /**
     * The settings posted by an admin over the current ones, or what is wrong with them. A setting left out of
     * $input keeps its current value; an empty one takes its default.
     *
     * @param  array<string, mixed>  $input  platform_map, allowed_group, max_targets, output_max_kb, stop_after_hours, stop_hold_minutes, inventory_user
     * @param  array<int, string>  $groups  the device groups (id => name)
     * @param  array<int, string>  $users  the LibreNMS users (id => username)
     * @param  (\Closure(string, string): ?string)|null  $fixTemplate  why a template may not apply a kind of fix (kind, template id), null when it
     *                                                               may: it must have the survey variable the fix templates' contract names
     * @return array{settings: self|null, errors: array<string, string>}
     */
    public static function fromInput(array $input, self $current, array $groups, array $users, ?\Closure $fixTemplate = null): array
    {
        $values = $current->values;
        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists($key, $input)) {
                $values[$key] = is_scalar($input[$key]) ? trim(str_replace("\r\n", "\n", (string) $input[$key])) : '';
            }
        }
        $errors = [];
        if (mb_strlen($values['platform_map']) > self::PLATFORM_MAP_MAX) {
            $errors['platform_map'] = 'The platform mapping is longer than ' . self::PLATFORM_MAP_MAX . ' characters.';
        } elseif ($mapErrors = PlatformMap::parse($values['platform_map'])->errors()) {
            $errors['platform_map'] = 'Fix the platform mapping: ' . implode('; ', array_slice($mapErrors, 0, 5))
                . (count($mapErrors) > 5 ? '; and ' . (count($mapErrors) - 5) . ' more' : '') . '.';
        }
        if ($values['allowed_group'] !== '' && ! (ctype_digit($values['allowed_group']) && isset($groups[(int) $values['allowed_group']]))) {
            $errors['allowed_group'] = 'The allowed device group is one of LibreNMS\' device groups, or every device.';
        }
        foreach (self::LIMITS as $key => [$min, $max]) {
            if ($values[$key] === '') {
                $values[$key] = self::DEFAULTS[$key];
            } elseif (! preg_match('/^\d{1,9}$/D', $values[$key]) || (int) $values[$key] < $min || (int) $values[$key] > $max) {
                $errors[$key] = ucfirst(self::LABELS[$key]) . " is a whole number from $min to $max.";
            } else {
                $values[$key] = (string) (int) $values[$key];
            }
        }
        if ($values['inventory_user'] !== '' && ! (ctype_digit($values['inventory_user']) && isset($users[(int) $values['inventory_user']]))) {
            $errors['inventory_user'] = 'The inventory user is one of LibreNMS\' users, or not chosen yet.';
        }
        foreach (self::CHOICES as $key => $choices) {
            if (! array_key_exists($values[$key], $choices)) {
                $errors[$key] = ucfirst(self::LABELS[$key]) . ' is one of: ' . implode(', ', $choices) . '.';
            }
        }
        // the template of each kind of fix: a template of the runner with the survey variable the contract names (checked when it changes)
        foreach (self::FIX_TEMPLATES as $kind => $key) {
            if ($values[$key] === '' || $values[$key] === $current->values[$key]) {
                continue;
            }
            if (! preg_match('/^\d{1,10}$/D', $values[$key])) {
                $errors[$key] = ucfirst(self::LABELS[$key]) . ' is the id of a template of the runner, or none.';
            } elseif ($fixTemplate !== null && ($why = $fixTemplate($kind, $values[$key])) !== null) {
                $errors[$key] = ucfirst(self::LABELS[$key]) . ': ' . $why;
            }
        }

        return $errors ? ['settings' => null, 'errors' => $errors] : ['settings' => new self($values), 'errors' => []];
    }

    /**
     * What differs from $before, for the eventlog: "max targets per change 25 → 2".
     *
     * @param  array<int, string>  $groups  the device groups (id => name)
     * @param  array<int, string>  $users  the LibreNMS users (id => username)
     * @return string[]
     */
    public function changes(self $before, array $groups, array $users): array
    {
        $changes = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            [$old, $new] = [$before->values[$key], $this->values[$key]];
            if ($old === $new) {
                continue;
            }
            $changes[] = match ($key) {
                'platform_map' => 'platform mapping: ' . self::mapChanges($before->platformMap(), $this->platformMap()),
                'allowed_group' => 'allowed device group ' . self::name($old, $groups, 'every device') . ' → ' . self::name($new, $groups, 'every device'),
                'inventory_user' => 'inventory user ' . self::name($old, $users, 'not chosen') . ' → ' . self::name($new, $users, 'not chosen'),
                'fix_template_port_descriptions', 'fix_template_stp_root_priority' => self::LABELS[$key] . ' ' . ($old === '' ? 'none' : "#$old") . ' → '
                    . ($new === '' ? 'none' : "#$new"),
                default => self::LABELS[$key] . ' ' . (self::CHOICES[$key][$old] ?? $old) . ' → ' . (self::CHOICES[$key][$new] ?? $new),
            };
        }

        return $changes;
    }

    /**
     * The os added, changed and removed between two mappings.
     */
    private static function mapChanges(PlatformMap $before, PlatformMap $after): string
    {
        [$old, $new] = [$before->all(), $after->all()];
        $changed = array_filter(array_intersect_key($new, $old), fn ($platform, $os) => $platform !== $old[$os], ARRAY_FILTER_USE_BOTH);
        $parts = [];
        foreach (['added' => array_diff_key($new, $old), 'changed' => $changed, 'removed' => array_diff_key($old, $new)] as $what => $entries) {
            if ($entries) {
                $parts[] = "$what " . implode(', ', array_map(fn ($os) => $os . ($what === 'removed' ? '' : ' (' . PlatformMap::describe($new[$os]) . ')'),
                    array_keys($entries)));
            }
        }

        return $parts ? implode('; ', $parts) : 'rewritten, same platforms';
    }

    /**
     * A device group or user for the eventlog: its name and id.
     *
     * @param  array<int, string>  $names
     */
    private static function name(string $id, array $names, string $none): string
    {
        if ($id === '') {
            return $none;
        }

        return isset($names[(int) $id]) ? UntrustedText::line($names[(int) $id], 80) . " (#$id)" : "#$id";
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * A number setting within its range; a value that is not a number uses the default.
     */
    public function int(string $key): int
    {
        [$min, $max] = self::LIMITS[$key] ?? [PHP_INT_MIN, PHP_INT_MAX];
        $value = $this->values[$key] ?? '';
        $int = preg_match('/^-?\d+$/D', $value) ? (int) $value : (int) (self::DEFAULTS[$key] ?? 0);

        return max($min, min($max, $int));
    }

    /**
     * The platform mapping (the defaults when the setting is empty).
     */
    public function platformMap(): PlatformMap
    {
        return PlatformMap::parse($this->values['platform_map']);
    }

    /**
     * The device group whose members alone may be targets, null for every device.
     */
    public function allowedGroup(): ?int
    {
        return self::id($this->values['allowed_group']);
    }

    /**
     * The LibreNMS user whose API token the runner uses for the inventory, null while no admin chose one.
     */
    public function inventoryUser(): ?int
    {
        return self::id($this->values['inventory_user']);
    }

    /**
     * Most targets of one run.
     */
    public function maxTargets(): int
    {
        return $this->int('max_targets');
    }

    /**
     * Most output stored per run, in bytes.
     */
    public function outputMaxBytes(): int
    {
        return $this->int('output_max_kb') * 1024;
    }

    /**
     * After how long the plugin stops a run that is still unfinished, in seconds.
     */
    public function stopAfterSeconds(): int
    {
        return $this->int('stop_after_hours') * 3600;
    }

    /**
     * How long a stopped run keeps its devices after the runner reported the stop, in seconds: Semaphore UI ends only
     * Ansible's main process, and the step each device was in runs on in a process of its own.
     */
    public function stopHoldSeconds(): int
    {
        return $this->int('stop_hold_minutes') * 60;
    }

    /**
     * How changes go (the change lifecycle's settings).
     *
     * @param  string  $changeUrl  the address of a change's page, with %d for its id
     */
    public function changeSettings(string $changeUrl = 'plugin/ansible/changes/%d'): \Agoodley\LibrenmsAnsible\Changes\ChangeSettings
    {
        return new \Agoodley\LibrenmsAnsible\Changes\ChangeSettings(
            $this->values['approval'] === '' || ! isset(self::CHOICES['approval'][$this->values['approval']]) ? 'required' : $this->values['approval'],
            $this->int('approval_hours') * 3600,
            $this->int('dry_run_hours') * 3600,
            $this->values['backups'] !== '0',
            $this->values['backup_policy'] !== 'warn',
            $this->int('backup_timeout_minutes') * 60,
            $this->values['maintenance'] !== '0',
            in_array($this->values['maintenance_behavior'], ['1', '2', '3'], true) ? (int) $this->values['maintenance_behavior'] : null,
            $this->int('maintenance_minutes') * 60,
            $this->int('settle_minutes') * 60,
            $changeUrl,
            $this->values['blast_grew'] === 'refuse',
            $this->values['blast_unknown'] === 'refuse',
            $this->values['verify'] !== '0',
            $this->values['verify_fresh'] === 'wait' ? 'wait' : 'ask',
            $this->int('verify_wait_minutes') * 60,
            self::choice($this->values['verify_unexpected'], 'verify_unexpected', 'pause'),
            self::choice($this->values['verify_unknown'], 'verify_unknown', 'pause'),
            self::choice($this->values['verify_minimum'], 'verify_minimum', 'warning'),
        );
    }

    /**
     * The template that applies a kind of fix (its id), null when none is set.
     */
    public function fixTemplate(string $kind): ?string
    {
        $key = self::FIX_TEMPLATES[$kind] ?? null;

        return $key !== null && preg_match('/^\d{1,10}$/D', $this->values[$key]) ? $this->values[$key] : null;
    }

    /**
     * A setting's value when it is one of its choices, else $default.
     */
    private static function choice(string $value, string $key, string $default): string
    {
        return array_key_exists($value, self::CHOICES[$key]) ? $value : $default;
    }

    /**
     * Does a new change plan its batches with the topology plugin (when it is installed, enabled and offers a planning
     * API the plugin speaks)?
     */
    public function topologyPlan(): bool
    {
        return $this->values['topology_plan'] !== '0';
    }

    /**
     * The batch plan of the settings for a change's targets, with the canary devices an admin chose.
     *
     * @param  int[]  $deviceIds
     * @param  int[]  $canary
     */
    public function batchPlan(array $deviceIds, array $canary = []): \Agoodley\LibrenmsAnsible\Changes\BatchPlan
    {
        return \Agoodley\LibrenmsAnsible\Changes\BatchPlan::make($deviceIds, $canary, $this->int('canary_size'), $this->int('batch_size'), $this->int('stop_at'),
            $this->values['wait_after_canary'] !== '0', $this->int('batch_pause_minutes'));
    }

    /**
     * The batch rule of the settings, in words (BatchPlan::rule()).
     */
    public function batchRule(): string
    {
        return \Agoodley\LibrenmsAnsible\Changes\BatchPlan::rule($this->int('canary_size'), $this->int('batch_size'), $this->int('stop_at'),
            $this->values['wait_after_canary'] !== '0', $this->int('batch_pause_minutes'));
    }

    /**
     * A positive id, null for none.
     */
    private static function id(string $value): ?int
    {
        return preg_match('/^\d{1,10}$/D', $value) && (int) $value > 0 ? (int) $value : null;
    }
}
