<?php

namespace Agoodley\LibrenmsInventory;

use Agoodley\LibrenmsInventory\Console\CheckCommand;
use Agoodley\LibrenmsInventory\Hooks\DeviceOverview;
use Agoodley\LibrenmsInventory\Hooks\MenuEntry;
use Agoodley\LibrenmsInventory\Hooks\Page;
use Agoodley\LibrenmsInventory\Hooks\Settings;
use Agoodley\LibrenmsInventory\Support\Clock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * Entry point of the plugin package, auto-discovered by Laravel (see composer.json extra.laravel). Registers the
 * LibreNMS plugin hooks, the views, the plugin's tables (migrations: its settings and the software checks), the software
 * check command and its schedule and, while the plugin is enabled, the routes. The inventory itself is read from
 * LibreNMS.
 */
class InventoryServiceProvider extends ServiceProvider
{
    /** Name of the plugin inside LibreNMS (plugin admin, settings, view namespace). */
    public const PLUGIN_NAME = 'inventory';

    /** Base URI of every page registered by this plugin. */
    public const URI_PREFIX = 'plugin/inventory';

    /**
     * Hooks are always published so the plugin shows up in Plugin Admin (LibreNMS removes plugins without hooks);
     * routes are only loaded while the plugin is enabled. The migrations are always known, so `./lnms migrate` (run by
     * LibreNMS updates) creates the plugin's tables.
     */
    public function boot(PluginManagerInterface $pluginManager): void
    {
        $name = self::PLUGIN_NAME;

        $pluginManager->publishHook($name, MenuEntryHook::class, MenuEntry::class);
        $pluginManager->publishHook($name, SinglePageHook::class, Page::class);
        $pluginManager->publishHook($name, SettingsHook::class, Settings::class);
        $pluginManager->publishHook($name, DeviceOverviewHook::class, DeviceOverview::class);

        $this->loadViewsFrom(__DIR__ . '/../resources/views', $name);
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        // times on the plugin's pages in the user's time zone, as LibreNMS shows its own
        Clock::useUserZone(fn () => $this->app->runningInConsole() ? null : session('preferences.timezone'));

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class]);
            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $this->schedule($schedule, $pluginManager));
        }

        if (! $pluginManager->pluginEnabled($name)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }

    /**
     * inventory:check every hour while the plugin is enabled (LibreNMS runs `artisan schedule:run` every minute). One at
     * a time, on one server; the output goes to inventory-check.log in the LibreNMS log directory, a failure to the
     * eventlog.
     */
    private function schedule(Schedule $schedule, PluginManagerInterface $pluginManager): void
    {
        $log = rtrim((string) self::config('log_dir', storage_path('logs')), '/') . '/inventory-check.log';
        $schedule->command('inventory:check')
            ->hourlyAt(23)
            ->when(fn () => $pluginManager->pluginEnabled(self::PLUGIN_NAME))
            ->withoutOverlapping(60)
            ->onOneServer()
            ->runInBackground()
            ->appendOutputTo($log)
            ->onFailure(fn () => \App\Models\Eventlog::log('The scheduled command inventory:check of the inventory plugin failed. Check inventory-check.log in the LibreNMS log directory.',
                null, 'inventory', \LibreNMS\Enum\Severity::Error));
    }

    /**
     * A LibreNMS setting, $default on versions without the LibrenmsConfig facade.
     */
    private static function config(string $key, mixed $default): mixed
    {
        try {
            return class_exists(\App\Facades\LibrenmsConfig::class) ? \App\Facades\LibrenmsConfig::get($key, $default) : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
