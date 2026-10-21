<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Sharing\SavedView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saved views of the maps (topology_views): storage, and the facts SavedView's rules need (the user,
 * whether they are an admin, the names they may see). A user sees their own views and the ones others
 * shared, and changes their own; LibreNMS admins may also change and delete any shared view. A view's
 * positions are only handed out for the nodes of the map the viewer is shown (layoutFor()).
 */
class ViewRepository extends ScopedRepository
{
    /** @var array{groups: array<int, string>, locations: array<int, string>}|null */
    private ?array $filterNames = null;

    /** @var array<int, ?string> names of the views' centered devices, null for those the user may not see */
    private array $devices = [];

    /** @var array<string, bool> VRF name => a device the user may see has it */
    private array $vrfs = [];

    private ?bool $admin = null;

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

        // the names of all centered devices in one query
        $ids = [];
        foreach ($rows as $row) {
            $params = json_decode((string) $row->query, true);
            if (is_array($params) && is_numeric($params['device'] ?? null)) {
                $ids[] = (int) $params['device'];
            }
        }
        $visible = array_values(array_filter(array_unique($ids), fn (int $id) => $this->canSee($id)));
        $this->devices += $this->deviceNames($visible) + array_fill_keys($ids, null);

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
        if (! $row || ! SavedView::canOpen((array) $row, $this->userId())) {
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
     * What the user is shown of a view (SavedView::present()) and its URL.
     *
     * @return array{id: int, name: string, page: string, params: array, options: array, scope: string, owner: string, mine: bool,
     *     shared: bool, can_edit: bool, updated_at: ?string, url: string}
     */
    public function meta(array $row): array
    {
        $params = json_decode((string) $row['query'], true);
        $view = SavedView::present($row, $this->userId(), $this->isAdmin(), $this->names(is_array($params) ? $params : []));
        $view['url'] = isset(SavedView::PAGES[$view['page']]) ? route('topology.' . $view['page'], ['view' => $view['id']]) : '';

        return $view;
    }

    /**
     * Names of the device, group, location and VRF of a view's parameters that the user may see.
     *
     * @return array{device: ?string, group: ?string, location: ?string, vrf: ?string}
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

        $device = $id('device');
        if ($device && ! array_key_exists($device, $this->devices)) {
            $this->devices[$device] = $this->deviceName($device);
        }

        $vrf = isset($params['vrf']) && is_string($params['vrf']) && $params['vrf'] !== '' ? $params['vrf'] : null;

        return [
            'device' => $device ? $this->devices[$device] : null,
            'group' => $this->filterNames['groups'][$id('group')] ?? null,
            'location' => $this->filterNames['locations'][$id('location')] ?? null,
            'vrf' => $vrf !== null && $this->hasVrf($vrf) ? $vrf : null,
        ];
    }

    /**
     * Does a device the user may see have this VRF (as the L3 map finds it: a VRF of the device, or the
     * context of an address)?
     */
    private function hasVrf(string $vrf): bool
    {
        if (! array_key_exists($vrf, $this->vrfs)) {
            $ids = array_keys($this->accessible());
            $ports = fn ($query) => $query->select('port_id')->from('ports')->whereIntegerInRaw('device_id', $ids);
            try {
                $this->vrfs[$vrf] = $ids && (DB::table('vrfs')->whereIntegerInRaw('device_id', $ids)->where('vrf_name', $vrf)->exists()
                    || ($this->allowsTable('ipv4_addresses') && DB::table('ipv4_addresses')->where('context_name', $vrf)->whereIn('port_id', $ports)->exists())
                    || ($this->allowsTable('ipv6_addresses') && DB::table('ipv6_addresses')->where('context_name', $vrf)->whereIn('port_id', $ports)->exists()));
            } catch (\Throwable) {
                $this->vrfs[$vrf] = false;
            }
        }

        return $this->vrfs[$vrf];
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
        return $this->admin ??= $this->user !== null && ($this->user->can('admin') || (method_exists($this->user, 'isAdmin') && $this->user->isAdmin()));
    }
}
