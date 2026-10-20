<?php

namespace Agoodley\LibrenmsTopology\Http\Controllers;

use Agoodley\LibrenmsTopology\Data\ViewRepository;
use Agoodley\LibrenmsTopology\Http\Controllers\Concerns\BuildsTopology;
use Agoodley\LibrenmsTopology\Sharing\SavedView;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Saved views of the maps: the Saved Views page and its actions, and the JSON API the maps' Views menu
 * uses (list, save, change, delete). Everyone who can open the maps saves their own views and may share
 * them; LibreNMS admins may also change and delete any shared view.
 */
class ViewController extends Controller
{
    use BuildsTopology;

    /**
     * plugin/topology/views: the user's views and the shared ones.
     */
    public function index(Request $request): View
    {
        $views = new ViewRepository($request->user());

        return view('topology::views', [
            'available' => $views->available(),
            'views' => $views->visible(),
            'pages' => SavedView::PAGES,
            'done' => $request->session()->get('topology_views'),
            'assetVersion' => self::assetVersion(),
            'params' => [],
        ]);
    }

    /**
     * POST plugin/topology/views: rename, share, stop sharing or delete a view (id, action, name), then back
     * to the page with what happened.
     */
    public function act(Request $request): RedirectResponse
    {
        $views = new ViewRepository($request->user());
        $id = $request->input('id');
        $id = is_scalar($id) && ctype_digit((string) $id) ? (int) $id : 0;
        $action = $request->input('action');
        $view = $id ? $views->find($id) : null;

        $name = SavedView::name($request->input('name'));
        $done = fn (bool $changed, string $message) => $changed
            ? [true, $message] : [false, "\"{$view['name']}\" could not be changed: it may have been changed or deleted meanwhile."];
        [$ok, $message] = match (true) {
            $view === null || ! $view['can_edit'] => [false, 'You may not change that view.'],
            $action === 'rename' && $name === null => [false, 'Give the view a name.'],
            $action === 'rename' => $done($views->update($id, ['name' => $name]), "Renamed \"{$view['name']}\" to \"$name\"."),
            $action === 'share' => $done($views->update($id, ['shared' => true]), "\"{$view['name']}\" is shared with everyone who can open the maps."),
            $action === 'unshare' => $done($views->update($id, ['shared' => false]), "\"{$view['name']}\" is no longer shared."),
            $action === 'delete' => $done($views->delete($id), "Deleted \"{$view['name']}\"."),
            default => [false, 'Unknown action.'],
        };

        return redirect()->route('topology.views')->with('topology_views', ['ok' => (bool) $ok, 'message' => $message]);
    }

    /**
     * GET api/views: the views the user may open (?page=l2 or l3), without positions.
     */
    public function list(Request $request): JsonResponse
    {
        $page = $request->query('page');

        return $this->json('saved views', fn () => ['views' => (new ViewRepository($request->user()))->visible(isset(SavedView::PAGES[$page]) ? $page : null)]);
    }

    /**
     * POST api/views: save a new view (page, query, positions, options, name, shared).
     */
    public function store(Request $request): JsonResponse
    {
        $page = $request->input('page');
        $name = SavedView::name($request->input('name'));
        if (! is_string($page) || ! isset(SavedView::PAGES[$page]) || $name === null) {
            return response()->json(['error' => $name === null ? 'Give the view a name.' : 'Unknown map.'], 422);
        }
        $views = new ViewRepository($request->user());
        if (! $views->available()) {
            return response()->json(['error' => 'The plugin\'s table topology_views is missing: run ./lnms migrate.'], 409);
        }

        return $this->json('saved view', function () use ($request, $views, $page, $name) {
            $id = $views->create($name, $page, $this->params($request), SavedView::positions($request->input('positions')),
                SavedView::options($request->input('options')), $request->boolean('shared'));
            $view = $views->find($id);
            unset($view['positions']);

            return $view;
        });
    }

    /**
     * POST api/views/{id}: change a view the user may change; each of name, shared, query (with page),
     * positions and options is optional.
     */
    public function save(Request $request, int $id): JsonResponse
    {
        $views = new ViewRepository($request->user());
        $view = $views->find($id);
        if ($view === null || ! $view['can_edit']) {
            return response()->json(['error' => 'You may not change that view.'], $view === null ? 404 : 403);
        }

        $changes = [];
        if ($request->has('name')) {
            if (($changes['name'] = SavedView::name($request->input('name'))) === null) {
                return response()->json(['error' => 'Give the view a name.'], 422);
            }
        }
        if ($request->has('shared')) {
            $changes['shared'] = $request->boolean('shared');
        }
        if ($request->has('query')) {
            $changes['params'] = $this->params($request);
        }
        if ($request->has('positions')) {
            $changes['positions'] = SavedView::positions($request->input('positions'));
        }
        if ($request->has('options')) {
            $changes['options'] = SavedView::options($request->input('options'));
        }
        $views->update($id, $changes);
        $view = $views->find($id);
        unset($view['positions']);

        return response()->json($view);
    }

    /**
     * POST api/views/{id}/delete: delete a view the user may change.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $views = new ViewRepository($request->user());
        $view = $views->find($id);
        if ($view === null || ! $view['can_edit']) {
            return response()->json(['error' => 'You may not change that view.'], $view === null ? 404 : 403);
        }
        $views->delete($id);

        return response()->json(['deleted' => $id]);
    }

    /**
     * The map parameters of the request's query string, as MapParams parses them.
     */
    private function params(Request $request): array
    {
        $query = [];
        parse_str(is_string($request->input('query')) ? $request->input('query') : '', $query);
        unset($query['view']);

        return MapParams::parse($query, $this->settings());
    }
}
