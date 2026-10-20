<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers\Concerns;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\MemoryLimit;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Topology\TopologyService;
use Agoodley\LibrenmsTopology\TopologyServiceProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * Shared by the controllers: plugin settings, the topology service for the current user, server
 * rendered pages, JSON responses and error handling.
 */
trait BuildsTopology
{
    /**
     * Memory of a page or API request when LibreNMS sets no php_memory_limit (MB): the pages fit in
     * PHP's 128M default on the README's benchmark network; this is the margin for larger ones.
     */
    private const PAGE_MEMORY_MB = 256;

    /**
     * Plugin settings saved in LibreNMS, merged with the defaults.
     */
    protected function settings(): PluginSettings
    {
        try {
            $stored = app(PluginManagerInterface::class)->getSettings(TopologyServiceProvider::PLUGIN_NAME);
        } catch (\Throwable) {
            $stored = [];
        }

        return new PluginSettings($stored);
    }

    /**
     * Topology service bound to the logged in user's permissions.
     */
    protected function service(Request $request, ?PluginSettings $settings = null): TopologyService
    {
        return new TopologyService(new TopologyRepository($request->user()), $settings ?? $this->settings(), new HistoryRepository($request->user()));
    }

    /**
     * Log a failure and return the message for the user. Exception text can contain SQL and
     * schema details, so it is only shown when APP_DEBUG is enabled.
     */
    protected function failure(string $what, \Throwable $e): string
    {
        Log::error("topology plugin: $what failed: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString());

        $message = "Unable to build the $what, see logs/librenms.log for details.";

        return config('app.debug') ? $message . ' (' . $e->getMessage() . ')' : $message;
    }

    /**
     * Server rendered page: view topology::$view with the parsed query ($params), $build($params, $settings)
     * as $result (or the $error when it fails), the filter choices and the asset version.
     *
     * @param  callable(array, PluginSettings): mixed  $build
     * @param  array  $data  more view data
     */
    protected function page(Request $request, string $view, string $what, callable $build, array $data = []): View
    {
        MemoryLimit::raise(self::PAGE_MEMORY_MB);
        $settings = $this->settings();
        $params = MapParams::parse($request->query(), $settings);
        $repo = new TopologyRepository($request->user());

        $result = null;
        $error = null;
        try {
            $result = $build($params, $settings);
        } catch (\Throwable $e) {
            $error = $this->failure($what, $e);
        }

        return view("topology::$view", $data + [
            'params' => $params,
            'result' => $result,
            'error' => $error,
            'filters' => $repo->filterOptions(),
            'deviceName' => $params['device'] ? $repo->deviceName($params['device']) : null,
            'assetVersion' => self::assetVersion(),
        ]);
    }

    /**
     * JSON of $build(); a failure becomes {error} with HTTP 500.
     */
    protected function json(string $what, callable $build): JsonResponse
    {
        MemoryLimit::raise(self::PAGE_MEMORY_MB);
        try {
            return response()->json($build());
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure($what, $e)], 500);
        }
    }

    /**
     * Cache busting version of the JS/CSS assets (newest modification time).
     */
    public static function assetVersion(): string
    {
        $files = glob(__DIR__ . '/../../../../resources/assets/*') ?: [];

        return (string) array_reduce($files, fn ($carry, $file) => max($carry, (int) filemtime($file)), 0);
    }
}
