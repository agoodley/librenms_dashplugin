<?php

namespace Agoodley\LibrenmsTopology\Hooks;

use Agoodley\LibrenmsTopology\Support\PluginSettings;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

/**
 * Settings page in Plugin Admin (only reachable by plugin admins).
 */
class Settings implements SettingsHook
{
    public function authorize(): bool
    {
        return true;
    }

    public function handle(string $pluginName, array $settings): array
    {
        return [
            'content_view' => "$pluginName::settings",
            'settings' => PluginSettings::merge($settings),
        ];
    }
}
