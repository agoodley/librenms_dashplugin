<?php

namespace Agoodley\LibrenmsAnsible\Support;

use Agoodley\LibrenmsAnsible\Inventory\PlatformMap;
use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;

/**
 * What runs may reach and how: the platform mapping, the allowed device group, the max targets per run, the
 * output kept, when an unfinished run is stopped, how long a stopped run keeps its devices, and the inventory user
 * (the LibreNMS user whose API token the runner fetches the inventory with). The plugin stores them itself (Data\SettingsRepository) and only LibreNMS
 * admins change them, like the runner connection: LibreNMS' own plugin settings form can be saved by any holder of
 * its plugin.admin permission, which non-admin roles can have, so the plugin keeps nothing there.
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
    ];

    /** Ranges of the numbers: [minimum, maximum]. */
    public const LIMITS = [
        'max_targets' => [1, 500],
        'output_max_kb' => [16, 8192],
        'stop_after_hours' => [1, 720],
        'stop_hold_minutes' => [1, 1440],
    ];

    /** Names of the settings in messages and the eventlog. */
    public const LABELS = [
        'platform_map' => 'platform mapping',
        'allowed_group' => 'allowed device group',
        'max_targets' => 'max targets per run',
        'output_max_kb' => 'output size (KB)',
        'stop_after_hours' => 'stop after (hours)',
        'stop_hold_minutes' => 'hold after a stop (minutes)',
        'inventory_user' => 'inventory user',
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
     * @return array{settings: self|null, errors: array<string, string>}
     */
    public static function fromInput(array $input, self $current, array $groups, array $users): array
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

        return $errors ? ['settings' => null, 'errors' => $errors] : ['settings' => new self($values), 'errors' => []];
    }

    /**
     * What differs from $before, for the eventlog: "max targets per run 25 → 2".
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
                default => self::LABELS[$key] . " $old → $new",
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
     * A positive id, null for none.
     */
    private static function id(string $value): ?int
    {
        return preg_match('/^\d{1,10}$/D', $value) && (int) $value > 0 ? (int) $value : null;
    }
}
