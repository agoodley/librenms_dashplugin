<?php

namespace Agoodley\LibrenmsAnsible\Http\Controllers;

use Agoodley\LibrenmsAnsible\Data\DeviceRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Services\Eventlog;
use Agoodley\LibrenmsAnsible\Services\InventoryService;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Services\RunFollower;
use Agoodley\LibrenmsAnsible\Services\RunLauncher;
use Agoodley\LibrenmsAnsible\Support\ErrorText;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;

/**
 * Shared by the plugin's controllers: its services, error messages and the asset version.
 */
abstract class Controller extends BaseController
{
    private ?PluginSettings $settings = null;

    /**
     * The settings LibreNMS stores for the plugin.
     */
    protected function settings(): PluginSettings
    {
        return $this->settings ??= Plugin::settings();
    }

    protected function inventoryService(): InventoryService
    {
        return new InventoryService(new DeviceRepository(), $this->settings());
    }

    protected function launcher(): RunLauncher
    {
        return new RunLauncher(new Plugin(), new RunRepository(), $this->inventoryService(), $this->settings());
    }

    protected function follower(): RunFollower
    {
        return new RunFollower(new Plugin(), new RunRepository(), $this->settings(), new Eventlog(), null, $this->inventoryService());
    }

    /**
     * Log a failure and say what to do. Exception texts can hold SQL and paths, so they are only shown with
     * APP_DEBUG.
     */
    protected function failure(string $what, \Throwable $e): string
    {
        Log::error("ansible plugin: $what failed: " . ErrorText::forLog($e) . PHP_EOL . self::trace($e));
        $message = "The $what failed: see logs/librenms.log for details.";

        return config('app.debug') ? $message . ' (' . ErrorText::forLog($e) . ')' : $message;
    }

    /**
     * The stack trace without the arguments of the calls: they can hold survey answers and the runner token.
     */
    protected static function trace(\Throwable $e): string
    {
        $lines = [];
        foreach ($e->getTrace() as $index => $frame) {
            $lines[] = "#$index " . ($frame['file'] ?? '[internal]') . '(' . ($frame['line'] ?? '') . '): '
                . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Cache busting version of the JS/CSS assets (newest modification time).
     */
    public static function assetVersion(): string
    {
        $files = glob(__DIR__ . '/../../../resources/assets/*') ?: [];

        return (string) array_reduce($files, fn ($carry, $file) => max($carry, (int) filemtime($file)), 0);
    }
}
