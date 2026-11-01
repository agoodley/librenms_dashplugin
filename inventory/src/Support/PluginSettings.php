<?php

namespace Agoodley\LibrenmsInventory\Support;

use Agoodley\LibrenmsInventory\Software\Policies;
use Agoodley\LibrenmsInventory\Software\Policy;

/**
 * The plugin's settings: the software policies (YAML, Software\Policies), which devices the software check covers (all,
 * or a device group's) and what goes to the eventlog. The plugin stores them itself (Data\SettingsRepository) and only
 * LibreNMS admins change them, through its own route: LibreNMS' plugin settings form can be saved by any holder of its
 * plugin.admin permission.
 */
final class PluginSettings
{
    public const DEFAULTS = [
        'device_group' => '',
        'eventlog' => '1',
        'eventlog_upgrades' => '1',
        'policies' => '',
    ];

    public const CHOICES = [
        'eventlog' => ['1' => 'on', '0' => 'off'],
        'eventlog_upgrades' => ['1' => 'on', '0' => 'off'],
    ];

    /** Longest text of the policies. */
    public const MAX_POLICIES = 200000;

    /** @var array<string, mixed> */
    private array $values;

    public function __construct(array $values = [], public readonly ?string $error = null)
    {
        $this->values = array_intersect_key($values, self::DEFAULTS) + self::DEFAULTS;
    }

    /**
     * Settings from a form, or what is wrong with it (field => why); the policies must parse without an error.
     *
     * @return array{settings: ?self, errors: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $values = self::DEFAULTS;
        foreach (self::CHOICES as $key => $choices) {
            $value = (string) ($input[$key] ?? '');
            if (! isset($choices[$value])) {
                $errors[$key] = 'must be one of ' . implode(', ', array_keys($choices));
            } else {
                $values[$key] = $value;
            }
        }
        $group = trim((string) ($input['device_group'] ?? ''));
        if (mb_strlen($group) > 255) {
            $errors['device_group'] = 'must be a device group\'s name';
        }
        $values['device_group'] = $group;
        $yaml = str_replace(["\r\n", "\r"], "\n", (string) ($input['policies'] ?? ''));
        if (strlen($yaml) > self::MAX_POLICIES) {
            $errors['policies'] = 'at most ' . number_format(self::MAX_POLICIES) . ' characters';
        } else {
            $parsed = Policies::parse($yaml);
            if ($parsed['errors']) {
                $errors['policies'] = implode(' ', $parsed['errors']);
            }
        }
        $values['policies'] = rtrim($yaml) === '' ? '' : rtrim($yaml) . "\n";

        return $errors ? ['settings' => null, 'errors' => $errors] : ['settings' => new self($values), 'errors' => []];
    }

    /**
     * Every setting, for storing and for the form.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * The device group whose devices the check covers; '' for every device.
     */
    public function deviceGroup(): string
    {
        return trim((string) $this->values['device_group']);
    }

    /**
     * Changes of the status in the eventlog?
     */
    public function eventlog(): bool
    {
        return (string) $this->values['eventlog'] === '1';
    }

    /**
     * Version changes in the eventlog?
     */
    public function eventlogUpgrades(): bool
    {
        return (string) $this->values['eventlog_upgrades'] === '1';
    }

    /**
     * The text of the policies, as the admin wrote it.
     */
    public function policiesText(): string
    {
        return (string) $this->values['policies'];
    }

    /**
     * The policies, and what is wrong with them (the stored ones parsed without an error when they were saved).
     *
     * @return array{policies: list<Policy>, errors: string[]}
     */
    public function policies(): array
    {
        return Policies::parse($this->policiesText());
    }
}
