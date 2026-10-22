<?php

namespace Agoodley\LibrenmsAnsible;

use Agoodley\LibrenmsAnsible\Console\SyncCommand;
use Agoodley\LibrenmsAnsible\Data\ConnectionRepository;
use Agoodley\LibrenmsAnsible\Hooks\DeviceOverview;
use Agoodley\LibrenmsAnsible\Support\Clock;
use Agoodley\LibrenmsAnsible\Hooks\MenuEntry;
use Agoodley\LibrenmsAnsible\Hooks\Page;
use Agoodley\LibrenmsAnsible\Hooks\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * Entry point of the plugin package, auto-discovered by Laravel (see composer.json extra.laravel). Registers the
 * LibreNMS plugin hooks, the views, the plugin's tables and permission (migrations), the sync command and its
 * schedule and, while the plugin is enabled, the routes.
 */
class AnsibleServiceProvider extends ServiceProvider
{
    /** Name of the plugin inside LibreNMS (plugin admin, settings, view namespace). */
    public const PLUGIN_NAME = 'ansible';

    /** Base URI of every page registered by this plugin. */
    public const URI_PREFIX = 'plugin/ansible';

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
        // times on the plugin's pages in the user's time zone, as LibreNMS shows its own (LibreNMS\Util\Time::format())
        Clock::useUserZone(fn () => $this->app->runningInConsole() ? null : session('preferences.timezone'));

        if ($this->app->runningInConsole()) {
            $this->commands([SyncCommand::class]);
            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $this->schedule($schedule, $pluginManager));
        }

        if (! $pluginManager->pluginEnabled($name)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }

    /**
     * ansible:sync every minute while the plugin is enabled and its runner connection is set up (LibreNMS runs
     * `artisan schedule:run` every minute); one at a time, on one server. The output goes to ansible-sync.log in
     * the LibreNMS log directory, a failure to the eventlog.
     */
    private function schedule(Schedule $schedule, PluginManagerInterface $pluginManager): void
    {
        $log = rtrim((string) self::config('log_dir', storage_path('logs')), '/') . '/ansible-sync.log';
        $schedule->command('ansible:sync')
            ->everyMinute()
            ->when(fn () => $pluginManager->pluginEnabled(self::PLUGIN_NAME) && self::configured())
            ->withoutOverlapping(10)
            ->onOneServer()
            ->runInBackground()
            ->appendOutputTo($log)
            ->onFailure(fn () => \App\Models\Eventlog::log('The scheduled command ansible:sync of the Ansible plugin failed. Check ansible-sync.log in the LibreNMS log directory.',
                null, 'ansible', \LibreNMS\Enum\Severity::Error));
    }

    /**
     * Is the runner connection set up?
     */
    private static function configured(): bool
    {
        try {
            return (new ConnectionRepository())->get()->complete();
        } catch (\Throwable) {
            return false;
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
}
