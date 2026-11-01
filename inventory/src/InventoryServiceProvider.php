<?php

namespace Agoodley\LibrenmsInventory;

use Agoodley\LibrenmsInventory\Hooks\DeviceOverview;
use Agoodley\LibrenmsInventory\Hooks\MenuEntry;
use Agoodley\LibrenmsInventory\Hooks\Page;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * Entry point of the plugin package, auto-discovered by Laravel (see composer.json extra.laravel). Registers the
 * LibreNMS plugin hooks, the views and, while the plugin is enabled, the routes. The plugin keeps no data of its own:
 * it reads what LibreNMS collects.
 */
class InventoryServiceProvider extends ServiceProvider
{
    /** Name of the plugin inside LibreNMS (plugin admin, view namespace). */
    public const PLUGIN_NAME = 'inventory';

    /** Base URI of every page registered by this plugin. */
    public const URI_PREFIX = 'plugin/inventory';

    /**
     * Hooks are always published so the plugin shows up in Plugin Admin (LibreNMS removes plugins without hooks);
     * routes are only loaded while the plugin is enabled.
     */
    public function boot(PluginManagerInterface $pluginManager): void
    {
        $name = self::PLUGIN_NAME;

        $pluginManager->publishHook($name, MenuEntryHook::class, MenuEntry::class);
        $pluginManager->publishHook($name, SinglePageHook::class, Page::class);
        $pluginManager->publishHook($name, DeviceOverviewHook::class, DeviceOverview::class);

        $this->loadViewsFrom(__DIR__ . '/../resources/views', $name);

        if (! $pluginManager->pluginEnabled($name)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }
}
