<?php

namespace Agoodley\LibrenmsTopology\Hooks;

use Agoodley\LibrenmsTopology\Support\PluginSettings;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

/**
 * Settings page in Plugin Admin (only reachable by plugin admins).
 */
class Settings implements SettingsHook
{
    /**
     * The settings route itself is limited to plugin admins by LibreNMS.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @param  array  $settings  saved settings injected by LibreNMS; the form posts them back as settings[...]
     * @return array{content_view: string, settings: array<string, string>}
     */
    public function handle(string $pluginName, array $settings): array
    {
        return [
            'content_view' => "$pluginName::settings",
            'settings' => PluginSettings::merge($settings),
        ];
    }
}
