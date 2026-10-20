<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Data\CustomMapRepository;
use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Sharing\CustomMapLayout;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Export of the L2 / L3 map to a LibreNMS Custom Map (admins only): a preview of what will be written,
 * then writing it. The browser sends the map's parameters and its positions; the map itself is built
 * again here, so only the positions of its own nodes are used.
 */
class ExportController extends Controller
{
    use BuildsTopology;

    /** Positions accepted from the browser, more than any map draws (Maximum devices and their neighbors). */
    private const MAX_POSITIONS = 20000;

    /**
     * May this user export custom maps? LibreNMS admins who may create custom maps.
     */
    public static function allowed(mixed $user): bool
    {
        if ($user === null || ! ($user->can('admin') || (method_exists($user, 'isAdmin') && $user->isAdmin()))) {
            return false;
        }

        return ! class_exists(\App\Models\CustomMap::class) || $user->can('create', \App\Models\CustomMap::class);
    }

    /**
     * POST api/custom-map/preview: what the export would write (page, query, positions, name, menu_group,
     * replace), with the plugin's earlier maps and a fingerprint of the map and the replaced map's state that
     * the export must send back. It writes nothing.
     */
    public function preview(Request $request): JsonResponse
    {
        abort_unless(self::allowed($request->user()), 403);

        return $this->respond($request, function (array $plan) {
            return response()->json($this->summary($plan));
        });
    }

    /**
     * POST api/custom-map: write the custom map that was previewed (same input and its fingerprint).
     */
    public function create(Request $request): JsonResponse
    {
        abort_unless(self::allowed($request->user()), 403);

        return $this->respond($request, function (array $plan, CustomMapRepository $repo) use ($request) {
            $fingerprint = $request->input('fingerprint');
            if (! is_string($fingerprint) || ! hash_equals($plan['fingerprint'], $fingerprint)) {
                return response()->json(['error' => 'The map, or the custom map it replaces, changed since the preview: check the new preview and export again.',
                    'preview' => $this->summary($plan)], 409);
            }
            $id = $repo->write($plan['layout'], $plan['name'], $plan['group'], $plan['page'], $plan['params'], $plan['replace']['id'] ?? null);

            return response()->json([
                'id' => $id,
                'name' => $plan['name'],
                'replaced' => $plan['replace'] !== null,
                'url' => self::mapUrl('maps.custom.show', $id, 'maps/custom/' . $id),
                'edit_url' => self::mapUrl('maps.custom.edit', $id, 'maps/custom/' . $id . '/edit'),
            ]);
        });
    }

    /**
     * Read and check the input, build the map and its custom map layout, and answer with $then($plan, $repo);
     * invalid input is a 422, an unsupported LibreNMS a 409 and a failure a 500, each as {error}.
     *
     * @param  callable(array, CustomMapRepository): JsonResponse  $then
     */
    private function respond(Request $request, callable $then): JsonResponse
    {
        $page = $request->input('page');
        $name = CustomMapLayout::mapName($request->input('name'));
        $group = CustomMapLayout::mapName($request->input('menu_group'));
        $replace = $request->input('replace');
        $error = match (true) {
            ! in_array($page, ['l2', 'l3'], true) => 'Unknown map.',
            $name === '' => 'Give the map a name.',
            mb_strlen($name) > 100 => 'The name is longer than 100 characters.',
            mb_strlen($group) > 100 => 'The menu group is longer than 100 characters.',
            $replace !== null && $replace !== '' && ! (is_scalar($replace) && ctype_digit((string) $replace)) => 'Unknown map to replace.',
            default => null,
        };
        if ($error !== null) {
            return response()->json(['error' => $error], 422);
        }

        try {
            $repo = new CustomMapRepository($request->user());
            if (($problem = $repo->unsupported()) !== null) {
                return response()->json(['error' => $problem], 409);
            }
            $maps = $repo->pluginMaps();
            $replace = $replace === null || $replace === '' ? null : (int) $replace;
            if ($replace !== null && ! isset($maps[$replace])) {
                return response()->json(['error' => 'That map was not created by this plugin, or no longer exists: only maps the plugin created can be replaced.'], 422);
            }
            if ($replace !== null && class_exists(\App\Models\CustomMap::class) && ! $request->user()->can('update', \App\Models\CustomMap::find($replace))) {
                return response()->json(['error' => 'You may not change custom maps.'], 403);
            }

            $settings = $this->settings();
            $query = [];
            parse_str(is_string($request->input('query')) ? $request->input('query') : '', $query);
            $params = MapParams::parse($query, $settings);
            $data = $this->service($request, $settings)->$page($params);
            $layout = CustomMapLayout::build($data, self::positions($request->input('positions')), $repo->style());
            if (! $layout['nodes']) {
                return response()->json(['error' => 'The map is empty: there is nothing to export.'], 422);
            }

            $plan = [
                'page' => $page,
                'params' => $params,
                'name' => $name,
                'group' => $group === '' ? null : $group,
                'replace' => $replace !== null ? $maps[$replace] : null,
                'maps' => array_values($maps),
                'layout' => $layout,
                'scope' => $data['scope'] ?? [],
            ];
            // what is written and what it replaces, as previewed: the map, and the state of the map it replaces (changes made
            // in LibreNMS' editor since change its update times and counts); not the name and menu group, typed after the preview
            $plan['fingerprint'] = sha1((string) json_encode([$page, $params, $plan['replace'], $layout['nodes'], $layout['edges'], $layout['width'], $layout['height']]));

            return $then($plan, $repo);
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure('custom map export', $e)], 500);
        }
    }

    /**
     * What the preview shows: the counts, the size, the map it replaces and the notices.
     */
    private function summary(array $plan): array
    {
        $stats = $plan['layout']['stats'];
        $notices = [];
        if (! empty($plan['scope']['truncated'])) {
            $notices[] = 'The map shows ' . $plan['scope']['shown'] . ' of ' . $plan['scope']['total'] . ' devices (Maximum devices): so does the custom map.';
        }
        if ($stats['unplaced']) {
            $notices[] = $stats['unplaced'] . ' node' . ($stats['unplaced'] === 1 ? ' is' : 's are') . ' new since the page was loaded and placed below the map.';
        }
        if ($stats['edges'] > $stats['ports']) {
            $notices[] = ($stats['edges'] - $stats['ports']) . ' link' . ($stats['edges'] - $stats['ports'] === 1 ? ' has' : 's have') . ' no port of a monitored device (adjacencies, sessions, links to subnets without one): LibreNMS draws ' . ($stats['edges'] - $stats['ports'] === 1 ? 'it' : 'them') . ' without utilization.';
        }
        if (! $stats['devices']) {
            $notices[] = 'The map has no monitored device: LibreNMS only shows custom maps without devices to users who may see every map.';
        }

        return [
            'page' => $plan['page'],
            'name' => $plan['name'],
            'menu_group' => $plan['group'],
            'stats' => $stats,
            'width' => $plan['layout']['width'],
            'height' => $plan['layout']['height'],
            'replace' => $plan['replace'],
            'maps' => $plan['maps'],
            'notices' => $notices,
            'fingerprint' => $plan['fingerprint'],
        ];
    }

    /**
     * Positions the browser sent, {node id: [x, y] or {x, y}}; anything else is dropped.
     *
     * @return array<string, array{0: float, 1: float}>
     */
    private static function positions(mixed $input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! is_array($input)) {
            return [];
        }

        $positions = [];
        foreach (array_slice($input, 0, self::MAX_POSITIONS, true) as $id => $position) {
            if (! is_string($id) || strlen($id) > 300 || ! is_array($position)) {
                continue;
            }
            $x = $position['x'] ?? $position[0] ?? null;
            $y = $position['y'] ?? $position[1] ?? null;
            if (is_numeric($x) && is_numeric($y) && abs((float) $x) < 1e7 && abs((float) $y) < 1e7) {
                $positions[$id] = [(float) $x, (float) $y];
            }
        }

        return $positions;
    }

    /**
     * URL of a LibreNMS custom map page (route name, else the path of current versions).
     */
    private static function mapUrl(string $route, int $id, string $path): string
    {
        return \Illuminate\Support\Facades\Route::has($route) ? route($route, ['map' => $id]) : url($path);
    }
}
