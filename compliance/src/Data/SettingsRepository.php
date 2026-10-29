<?php

namespace Agoodley\LibrenmsCompliance\Data;

use Agoodley\LibrenmsCompliance\Support\ErrorText;
use Agoodley\LibrenmsCompliance\Support\Log;
use Agoodley\LibrenmsCompliance\Support\PluginSettings;

/**
 * The plugin's settings (Support\PluginSettings), stored in compliance_config and changed only through its admin-only
 * route.
 */
final class SettingsRepository
{
    private const NAME = 'settings';

    public function __construct(private readonly ConfigStore $store = new ConfigStore())
    {
    }

    /**
     * The stored settings over the defaults (the defaults when none are stored); settings that cannot be read come with an
     * error, and the defaults.
     */
    public function get(): PluginSettings
    {
        try {
            return new PluginSettings($this->store->get(self::NAME) ?? []);
        } catch (\Throwable $e) {
            Log::error('cannot read the settings: ' . ErrorText::forLog($e));

            return new PluginSettings([], 'The plugin\'s settings cannot be read (see logs/librenms.log): save them again on the settings page.');
        }
    }

    public function save(PluginSettings $settings): void
    {
        $this->store->put(self::NAME, $settings->all());
    }
}
