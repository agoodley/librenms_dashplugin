<?php

namespace Agoodley\LibrenmsTopology;

use Agoodley\LibrenmsTopology\Console\ChecksCommand;
use Agoodley\LibrenmsTopology\Console\SnapshotCommand;
use Agoodley\LibrenmsTopology\History\Interval;
use Agoodley\LibrenmsTopology\History\Jobs;
use Agoodley\LibrenmsTopology\Hooks\DeviceOverview;
use Agoodley\LibrenmsTopology\Hooks\MenuEntry;
use Agoodley\LibrenmsTopology\Hooks\Page;
use Agoodley\LibrenmsTopology\Hooks\PortTab;
use Agoodley\LibrenmsTopology\Hooks\Settings;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\PortTabHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * Entry point of the plugin package, auto-discovered by Laravel (see composer.json extra.laravel).
 * Registers the LibreNMS plugin hooks, the views, the plugin's tables (history, saved views, exported
 * custom maps), the history commands and their schedule and, when the plugin is enabled, the routes.
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
     * always known, so `./lnms migrate` (run by LibreNMS updates) creates the plugin's tables.
     */
    public function boot(PluginManagerInterface $pluginManager): void
    {
        $name = self::PLUGIN_NAME;

        $pluginManager->publishHook($name, MenuEntryHook::class, MenuEntry::class);
        $pluginManager->publishHook($name, SinglePageHook::class, Page::class);
        $pluginManager->publishHook($name, SettingsHook::class, Settings::class);
        $pluginManager->publishHook($name, DeviceOverviewHook::class, DeviceOverview::class);
        $pluginManager->publishHook($name, PortTabHook::class, PortTab::class);

        $this->loadViewsFrom(__DIR__ . '/../resources/views', $name);
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([SnapshotCommand::class, ChecksCommand::class]);
            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $this->schedule($schedule, $pluginManager));
        }

        if (! $pluginManager->pluginEnabled($name)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }

    /**
     * The history snapshot, then the background checks, every *History interval* minutes while the
     * plugin and its history are enabled (LibreNMS runs `artisan schedule:run` every minute; see
     * History\Jobs). The output goes to topology-snapshot.log in the LibreNMS log directory, a
     * failure to the eventlog.
     */
    private function schedule(Schedule $schedule, PluginManagerInterface $pluginManager): void
    {
        $cron = Interval::cron(self::settings($pluginManager)->int('history_interval', 5, 1440));
        $log = rtrim((string) self::config('log_dir', storage_path('logs')), '/') . '/topology-snapshot.log';
        $enabled = fn () => $pluginManager->pluginEnabled(self::PLUGIN_NAME) && self::settings($pluginManager)->bool('history_enabled');
        foreach (Jobs::SCHEDULED as $name => $parameters) {
            $schedule->command($name, $parameters)
                ->cron($cron)
                ->when($enabled)
                ->withoutOverlapping(180)
                ->onOneServer()
                ->runInBackground()
                ->appendOutputTo($log)
                ->onFailure(fn () => \App\Models\Eventlog::log("The scheduled command $name of the topology plugin failed. Check topology-snapshot.log in the LibreNMS log directory.",
                    null, 'topology', \LibreNMS\Enum\Severity::Error));
        }
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
