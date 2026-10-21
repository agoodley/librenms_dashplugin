<?php

namespace Agoodley\LibrenmsAnsible\Support;

use Agoodley\LibrenmsAnsible\Inventory\PlatformMap;

/**
 * The plugin settings LibreNMS stores (Plugin Admin → ansible, saved as posted, always strings) merged with the
 * defaults: what may be targeted and how much is kept. The runner connection is not among them (Connection).
 */
final class PluginSettings
{
    public const DEFAULTS = [
        'platform_map' => '',
        'allowed_group' => '',
        'max_targets' => '25',
        'output_max_kb' => '1024',
        'lost_after_hours' => '24',
    ];

    /** Ranges the numbers are clamped to: [minimum, maximum]. */
    public const LIMITS = [
        'max_targets' => [1, 500],
        'output_max_kb' => [16, 65536],
        'lost_after_hours' => [1, 720],
    ];

    /** @var array<string, string> */
    private array $values;

    /**
     * @param  array  $stored  settings saved by LibreNMS for this plugin (strings, may be incomplete)
     */
    public function __construct(array $stored = [])
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
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * A number setting clamped to its range; a value that is not a number uses the default.
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
        $value = $this->values['allowed_group'];

        return preg_match('/^\d+$/D', $value) && (int) $value > 0 ? (int) $value : null;
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
     * After how long an unfinished run is given up as lost, in seconds.
     */
    public function lostAfterSeconds(): int
    {
        return $this->int('lost_after_hours') * 3600;
    }
}
