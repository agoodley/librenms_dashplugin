<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Sharing\SavedView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saved views of the maps (topology_views, Sharing\SavedView). A user sees their own views and the ones
 * others shared, and changes their own; LibreNMS admins may also change and delete any shared view. A
 * view's positions are only handed out for the nodes of the map the viewer is shown (layoutFor()).
 */
class ViewRepository extends ScopedRepository
{
    /** @var array{groups: array<int, string>, locations: array<int, string>}|null */
    private ?array $filterNames = null;

    /**
     * Does the table exist (the plugin's migration ran)?
     */
    public function available(): bool
    {
        try {
            return Schema::hasTable('topology_views');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Views the user may open, of one map or all: their own and the shared ones, by map and name,
     * without positions.
     *
     * @return array<int, array> see meta()
     */
    public function visible(?string $page = null): array
    {
        if (! $this->available()) {
            return [];
        }
        $me = $this->userId();
        $rows = DB::table('topology_views as v')->leftJoin('users as u', 'u.user_id', '=', 'v.user_id')
            ->where(fn ($q) => $q->where('v.user_id', $me)->orWhere('v.shared', 1))
            ->when($page !== null, fn ($q) => $q->where('v.page', $page))
            ->orderBy('v.page')->orderBy('v.name')->orderBy('v.id')
            ->get(['v.id', 'v.user_id', 'v.name', 'v.page', 'v.query', 'v.options', 'v.shared', 'v.updated_at', 'u.username']);

        return array_map(fn ($row) => $this->meta((array) $row), $rows->all());
    }

    /**
     * A view the user may open (their own or a shared one) with its positions, null otherwise: a view
     * that does not exist and another user's personal view look the same.
     */
    public function find(int $id): ?array
    {
        if (! $this->available()) {
            return null;
        }
        $row = DB::table('topology_views as v')->leftJoin('users as u', 'u.user_id', '=', 'v.user_id')->where('v.id', $id)
            ->first(['v.id', 'v.user_id', 'v.name', 'v.page', 'v.query', 'v.layout', 'v.options', 'v.shared', 'v.updated_at', 'u.username']);
        if (! $row || ((int) $row->user_id !== $this->userId() && ! $row->shared)) {
            return null;
        }

        $view = $this->meta((array) $row);
        $layout = json_decode((string) $row->layout, true);
        $view['positions'] = SavedView::positions(is_array($layout) ? $layout : []);

        return $view;
    }

    /**
     * The view to show with a map: its description and its positions for the map's nodes, null when
     * the user may not open it or it belongs to the other map.
     *
     * @param  array<int, array>  $nodes  the nodes of the map built for this user
     * @return array{view: array, layout: array<string, array{x: float, y: float}>}|null
     */
    public function layoutFor(int $id, string $page, array $nodes): ?array
    {
        $view = $this->find($id);
        if ($view === null || $view['page'] !== $page) {
            return null;
        }
        $layout = SavedView::forMap($view['positions'], $nodes);
        unset($view['positions']);

        return ['view' => $view, 'layout' => $layout];
    }

    /**
     * Save a new view of the user.
     *
     * @param  array<string, array{0: float, 1: float}>  $positions
     */
    public function create(string $name, string $page, array $params, array $positions, array $options, bool $shared): int
    {
        $now = now();

        return (int) DB::table('topology_views')->insertGetId([
            'user_id' => $this->userId(),
            'name' => $name,
            'page' => $page,
            'query' => json_encode($params),
            'layout' => json_encode($positions),
            'options' => json_encode($options, JSON_FORCE_OBJECT),
            'shared' => $shared,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Change a view the user may change (name, shared, params, positions, options): false when the
     * view does not exist or is not theirs (or, for an admin, not shared).
     *
     * @param  array{name?: string, shared?: bool, params?: array, positions?: array, options?: array}  $changes
     */
    public function update(int $id, array $changes): bool
    {
        $view = $this->find($id);
        if ($view === null || ! $view['can_edit']) {
            return false;
        }
        $values = ['updated_at' => now()];
        foreach (['name' => 'name', 'shared' => 'shared'] as $key => $column) {
            if (array_key_exists($key, $changes)) {
                $values[$column] = $changes[$key];
            }
        }
        if (array_key_exists('params', $changes)) {
            $values['query'] = json_encode($changes['params']);
        }
        if (array_key_exists('positions', $changes)) {
            $values['layout'] = json_encode($changes['positions']);
        }
        if (array_key_exists('options', $changes)) {
            $values['options'] = json_encode($changes['options'], JSON_FORCE_OBJECT);
        }
        DB::table('topology_views')->where('id', $id)->update($values);

        return true;
    }

    /**
     * Delete a view the user may change; false otherwise.
     */
    public function delete(int $id): bool
    {
        $view = $this->find($id);
        if ($view === null || ! $view['can_edit']) {
            return false;
        }

        return DB::table('topology_views')->where('id', $id)->delete() > 0;
    }

    /**
     * What is shown about a view: name, map, parameters (without a device, group or location the user may
     * not see), scope in words (only names the user may see), owner, whether it is the user's, shared, may
     * be changed by the user, and its URL.
     *
     * @return array{id: int, name: string, page: string, params: array, options: array, scope: string, owner: string, mine: bool,
     *     shared: bool, can_edit: bool, updated_at: ?string, url: string}
     */
    public function meta(array $row): array
    {
        $params = json_decode((string) $row['query'], true);
        $params = is_array($params) ? $params : [];
        $options = json_decode((string) ($row['options'] ?? ''), true);
        $mine = (int) $row['user_id'] === $this->userId();
        $shared = (bool) $row['shared'];
        $page = (string) $row['page'];
        $names = $this->names($params);
        $scope = SavedView::describe($page, $params, $names);
        // the device, group and location the user may not see are left out, as their ids would name them
        foreach (['device', 'group', 'location'] as $key) {
            if (! empty($params[$key]) && $names[$key] === null) {
                $params[$key] = null;
            }
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'page' => $page,
            'params' => $params,
            'options' => SavedView::options($options),
            'scope' => $scope,
            'owner' => $mine ? 'you' : (string) ($row['username'] ?? 'a former user'),
            'mine' => $mine,
            'shared' => $shared,
            'can_edit' => $mine || ($shared && $this->isAdmin()),
            'updated_at' => $row['updated_at'] ?? null,
            'url' => isset(SavedView::PAGES[$page]) ? route('topology.' . $page, ['view' => (int) $row['id']]) : '',
        ];
    }

    /**
     * Names of the device, group and location of a view's parameters that the user may see.
     *
     * @return array{device: ?string, group: ?string, location: ?string}
     */
    private function names(array $params): array
    {
        if ($this->filterNames === null) {
            $options = $this->filterOptions();
            $this->filterNames = [
                'groups' => array_column($options['groups'], 'name', 'id'),
                'locations' => array_column($options['locations'], 'name', 'id'),
            ];
        }
        $id = fn (string $key) => isset($params[$key]) && is_numeric($params[$key]) ? (int) $params[$key] : 0;

        return [
            'device' => $id('device') ? $this->deviceName($id('device')) : null,
            'group' => $this->filterNames['groups'][$id('group')] ?? null,
            'location' => $this->filterNames['locations'][$id('location')] ?? null,
        ];
    }

    private function userId(): int
    {
        return (int) ($this->user->user_id ?? 0);
    }

    /**
     * LibreNMS admins may change and delete any shared view.
     */
    private function isAdmin(): bool
    {
        return $this->user !== null && ($this->user->can('admin') || (method_exists($this->user, 'isAdmin') && $this->user->isAdmin()));
    }
}
