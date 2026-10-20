<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * Typed access to the plugin settings stored by LibreNMS (always strings) merged with defaults.
 */
class PluginSettings
{
    public const DEFAULTS = [
        'default_depth' => '2',
        'max_nodes' => '400',
        'show_unmanaged' => '1',
        'collapse_lag' => '1',
        'l3_hide_stubs' => '1',
        'l3_p2p_prefix' => '29',
        'l3_exclude' => '',
        'stp_tc_recent_minutes' => '60',
        'stp_tc_rate_per_day' => '50',
        'stp_flap_transitions' => '100',
        'stp_max_diameter' => '7',
        'stp_preferred_roots' => '',
        'stp_event_days' => '7',
    ];

    /** @var array<string, string> */
    private array $values;

    public function __construct(array $stored = [])
    {
        $this->values = self::merge($stored);
    }

    /**
     * @return array<string, string>
     */
    public static function merge(array $stored): array
    {
        $values = self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists($key, $stored) && $stored[$key] !== null) {
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

    public function int(string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $value = $this->values[$key] ?? self::DEFAULTS[$key] ?? '0';
        $int = is_numeric($value) ? (int) $value : (int) (self::DEFAULTS[$key] ?? 0);

        return max($min, min($max, $int));
    }

    public function bool(string $key): bool
    {
        return in_array(strtolower($this->values[$key] ?? ''), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Split a comma / whitespace / newline separated setting into a list.
     *
     * @return string[]
     */
    public function list(string $key): array
    {
        $parts = preg_split('/[\s,;]+/', $this->values[$key] ?? '') ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($part) => $part !== ''));
    }
}
