<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers\Concerns;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\History\TimeRefused;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Support\RoleData;
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
     * JSON of $build(); a failure becomes {error} with HTTP 500, a map at a time the history cannot draw (TimeRefused)
     * {error, time_refused} with HTTP 422. The answer for a page (RoleData::PAGES) says
     * what the user's LibreNMS role may not view of the data it reads (role_hidden, empty when nothing is
     * hidden), as the page itself does, so that an empty answer is not taken for "nothing found".
     */
    protected function json(string $what, callable $build, ?string $page = null): JsonResponse
    {
        try {
            $result = $build();
            if ($page !== null && is_array($result)) {
                $result['role_hidden'] ??= self::roleHidden($page);
            }

            return response()->json($result);
        } catch (TimeRefused $e) {
            // a map at a time the history cannot draw: why, rather than today's map (HTTP 422)
            return response()->json(['error' => $e->getMessage(), 'time_refused' => true] + ($page !== null ? ['role_hidden' => self::roleHidden($page)] : []), 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure($what, $e)], 500);
        }
    }

    /**
     * What the user's LibreNMS role may not view of the data a page reads (RoleData::PAGES).
     *
     * @return string[]
     */
    protected static function roleHidden(string $page): array
    {
        return (new TopologyRepository(request()->user()))->hiddenKinds(RoleData::PAGES[$page] ?? []);
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
