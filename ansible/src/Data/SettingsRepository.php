<?php

namespace Agoodley\LibrenmsAnsible\Data;

use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use Illuminate\Support\Facades\Log;

/**
 * The plugin's settings (Support\PluginSettings), stored by the plugin in ansible_config and changed only through its
 * admin-only route: never in LibreNMS' plugin settings, which holders of plugin.admin can save.
 */
final class SettingsRepository
{
    private const NAME = 'settings';

    public function __construct(private readonly ConfigStore $store = new ConfigStore())
    {
    }

    /**
     * The stored settings over the defaults (the defaults when none are stored). Settings that cannot be read come
     * with an error, which stops launches and the inventory endpoint.
     */
    public function get(): PluginSettings
    {
        try {
            return new PluginSettings($this->store->get(self::NAME) ?? []);
        } catch (\Throwable $e) {
            Log::error('ansible plugin: cannot read the settings: ' . \Agoodley\LibrenmsAnsible\Support\ErrorText::forLog($e));

            return new PluginSettings([], 'The plugin\'s settings cannot be read (see logs/librenms.log): save them again on the settings page.');
        }
    }

    /**
     * Store the settings.
     */
    public function save(PluginSettings $settings): void
    {
        $this->store->put(self::NAME, $settings->all());
    }
}
