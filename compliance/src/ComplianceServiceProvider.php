<?php

namespace Agoodley\LibrenmsCompliance;

use Agoodley\LibrenmsCompliance\Console\CheckCommand;
use Agoodley\LibrenmsCompliance\Hooks\DeviceOverview;
use Agoodley\LibrenmsCompliance\Hooks\MenuEntry;
use Agoodley\LibrenmsCompliance\Hooks\Page;
use Agoodley\LibrenmsCompliance\Hooks\Settings;
use Agoodley\LibrenmsCompliance\Support\Clock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * Entry point of the plugin package, auto-discovered by Laravel (see composer.json extra.laravel). Registers the
 * LibreNMS plugin hooks, the views, the plugin's tables (migrations), the check command and its schedule and, while
 * the plugin is enabled, the routes.
 */
class ComplianceServiceProvider extends ServiceProvider
{
    /** Name of the plugin inside LibreNMS (plugin admin, settings, view namespace). */
    public const PLUGIN_NAME = 'compliance';

    /** Base URI of every page registered by this plugin. */
    public const URI_PREFIX = 'plugin/compliance';

    /**
     * Hooks are always published so the plugin shows up in Plugin Admin (LibreNMS removes plugins without hooks);
     * routes are only loaded while the plugin is enabled. The migrations are always known, so `./lnms migrate`
     * (run by LibreNMS updates) creates the plugin's tables.
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
     * compliance:check every hour while the plugin is enabled (LibreNMS runs `artisan schedule:run` every minute); it
     * checks the devices when the settings' interval has passed. One at a time, on one server; the output goes to
     * compliance-check.log in the LibreNMS log directory, a failure to the eventlog.
     */
    private function schedule(Schedule $schedule, PluginManagerInterface $pluginManager): void
    {
        $log = rtrim((string) self::config('log_dir', storage_path('logs')), '/') . '/compliance-check.log';
        $schedule->command('compliance:check')
            ->hourlyAt(37)
            ->when(fn () => $pluginManager->pluginEnabled(self::PLUGIN_NAME))
            ->withoutOverlapping(180)
            ->onOneServer()
            ->runInBackground()
            ->appendOutputTo($log)
            ->onFailure(fn () => \App\Models\Eventlog::log('The scheduled command compliance:check of the compliance plugin failed. Check compliance-check.log in the LibreNMS log directory.',
                null, 'compliance', \LibreNMS\Enum\Severity::Error));
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
