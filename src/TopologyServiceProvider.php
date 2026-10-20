<?php

namespace Agoodley\LibrenmsTopology;

use Agoodley\LibrenmsTopology\Console\SnapshotCommand;
use Agoodley\LibrenmsTopology\Hooks\DeviceOverview;
use Agoodley\LibrenmsTopology\Hooks\MenuEntry;
use Agoodley\LibrenmsTopology\Hooks\Page;
use Agoodley\LibrenmsTopology\Hooks\Settings;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * Entry point of the plugin package, auto-discovered by Laravel (see composer.json extra.laravel).
 * Registers the LibreNMS plugin hooks, the views, the history tables and command and, when the
 * plugin is enabled, the routes.
 */
class TopologyServiceProvider extends ServiceProvider
{
    /** Name of the plugin inside LibreNMS (plugin admin, settings, view namespace). */
    public const PLUGIN_NAME = 'topology';

    /** Base URI of every page registered by this plugin. */
    public const URI_PREFIX = 'plugin/topology';

    /**
     * Hooks are always published so the plugin shows up in Plugin Admin (LibreNMS removes plugins
     * without hooks); routes are only loaded while the plugin is enabled. The migrations are
     * always known, so `./lnms migrate` (run by LibreNMS updates) creates the history tables.
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

        if ($this->app->runningInConsole()) {
            $this->commands([SnapshotCommand::class]);
            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $this->schedule($schedule, $pluginManager));
        }

        if (! $pluginManager->pluginEnabled($name)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }

    /**
     * The history snapshot and background checks every *History interval* minutes, while the
     * plugin and its history are enabled (LibreNMS runs `artisan schedule:run` every minute).
     */
    private function schedule(Schedule $schedule, PluginManagerInterface $pluginManager): void
    {
        $schedule->command(SnapshotCommand::class)
            ->cron(self::cron(self::settings($pluginManager)->int('history_interval', 5, 1440)))
            ->when(fn () => $pluginManager->pluginEnabled(self::PLUGIN_NAME) && self::settings($pluginManager)->bool('history_enabled'))
            ->withoutOverlapping(180)
            ->onOneServer()
            ->runInBackground();
    }

    /**
     * Cron expression for an interval in minutes: a divisor of an hour below one hour, else whole
     * hours (a divisor of a day) at 7 minutes past, away from the pollers' full hours.
     */
    public static function cron(int $minutes): string
    {
        if ($minutes < 60) {
            $step = 5;
            foreach ([5, 10, 15, 20, 30] as $divisor) {
                if ($divisor <= $minutes) {
                    $step = $divisor;
                }
            }

            return "*/$step * * * *";
        }

        $hours = 1;
        foreach ([1, 2, 3, 4, 6, 8, 12, 24] as $divisor) {
            if ($divisor * 60 <= $minutes) {
                $hours = $divisor;
            }
        }

        return $hours === 24 ? '7 3 * * *' : ($hours === 1 ? '7 * * * *' : "7 */$hours * * *");
    }

    /**
     * Plugin settings saved in LibreNMS, merged with the defaults.
     */
    private static function settings(PluginManagerInterface $pluginManager): PluginSettings
    {
        try {
            return new PluginSettings($pluginManager->getSettings(self::PLUGIN_NAME));
        } catch (\Throwable) {
            return new PluginSettings();
        }
    }
}
