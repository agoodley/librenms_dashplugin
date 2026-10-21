<?php

namespace Agoodley\LibrenmsTopology\Data;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Base of the plugin's repositories: the devices the logged in user may see, and helpers that keep
 * every query inside them. Each feature keeps its queries in its own repository in src/Data that
 * extends this class; everything is returned as plain arrays for the LibreNMS independent analyzers.
 */
abstract class ScopedRepository
{
    /**
     * LibreNMS tables the plugin reads whose model has a role permission (a LibreNMS policy) besides the
     * devices a user may see, with what they hold for the page notices. See allowsTable().
     */
    public const TABLE_MODELS = [
        'links' => [\App\Models\Link::class, 'LLDP/CDP links'],
        'ports' => [\App\Models\Port::class, 'ports'],
        'ports_fdb' => [\App\Models\PortsFdb::class, 'forwarding tables'],
        'ipv4_mac' => [\App\Models\Ipv4Mac::class, 'ARP tables'],
        'ipv6_nd' => [\App\Models\Ipv6Nd::class, 'IPv6 neighbor tables'],
        'ports_nac' => [\App\Models\PortsNac::class, '802.1X sessions'],
        'bgpPeers' => [\App\Models\BgpPeer::class, 'BGP sessions'],
        'bgpPeers_cbgp' => [\App\Models\BgpPeer::class, 'BGP sessions'],
        'ospf_nbrs' => [\App\Models\OspfNbr::class, 'OSPF'],
        'ospf_instances' => [\App\Models\OspfInstance::class, 'OSPF'],
        'ospf_areas' => [\App\Models\OspfArea::class, 'OSPF'],
        'ospf_ports' => [\App\Models\OspfPort::class, 'OSPF'],
        'ospfv3_nbrs' => [\App\Models\Ospfv3Nbr::class, 'OSPFv3'],
        'ospfv3_instances' => [\App\Models\Ospfv3Instance::class, 'OSPFv3'],
        'isis_adjacencies' => [\App\Models\IsisAdjacency::class, 'IS-IS adjacencies'],
        'ipsec_tunnels' => [\App\Models\IpsecTunnel::class, 'IPsec tunnels'],
        'mpls_lsps' => [\App\Models\MplsLsp::class, 'MPLS LSPs'],
        'mpls_lsp_paths' => [\App\Models\MplsLspPath::class, 'MPLS LSPs'],
        'pseudowires' => [\App\Models\Pseudowire::class, 'pseudowires'],
        'eventlog' => [\App\Models\Eventlog::class, 'the eventlog'],
        'device_outages' => [\App\Models\DeviceOutage::class, 'outages'],
        'syslog' => [\App\Models\Syslog::class, 'syslog'],
        'stp' => [\App\Models\Stp::class, 'spanning tree'],
        'ports_stp' => [\App\Models\PortStp::class, 'spanning tree ports'],
        // port data with policies of their own (port.view, as the ports)
        'ports_vlans' => [\App\Models\PortVlan::class, 'ports'],
        'ports_stack' => [\App\Models\PortStack::class, 'ports'],
        'ports_statistics' => [\App\Models\PortStatistic::class, 'ports'],
        'ipv4_addresses' => [\App\Models\Ipv4Address::class, 'IP addresses'],
        'ipv6_addresses' => [\App\Models\Ipv6Address::class, 'IP addresses'],
        'ipv4_networks' => [\App\Models\Ipv4Network::class, 'IP addresses'],
        'ipv6_networks' => [\App\Models\Ipv6Network::class, 'IP addresses'],
        'route' => [\App\Models\Route::class, 'routes'],
        // optical receive levels (sensor.view; transceivers are port data, port.view) and stack members / line cards
        // of ports (inventory.view)
        'sensors' => [\App\Models\Sensor::class, 'optical levels'],
        'transceivers' => [\App\Models\Transceiver::class, 'ports'],
        'entPhysical' => [\App\Models\EntPhysical::class, 'the hardware inventory'],
    ];

    /** @var array<int, true>|null */
    private ?array $accessible = null;

    /** @var array<string, bool> role permission per model class, checked once (see allows()) */
    private array $allowed = [];

    /** Every device, for the scheduled jobs (see system()). */
    private bool $system = false;

    /** The system scope with the devices disabled in LibreNMS too (see withDisabled()). */
    private bool $withDisabled = false;

    /**
     * @param  mixed  $user  the logged in LibreNMS user (App\Models\User); every query is limited to what it may see
     */
    public function __construct(protected readonly mixed $user)
    {
    }

    /**
     * Repository that sees every enabled device, for the scheduled jobs that keep the topology
     * history. Refused outside the console, so no web request can widen its scope this way.
     */
    public static function system(): static
    {
        if (! app()->runningInConsole()) {
            throw new \LogicException('the system scope is only available to console commands');
        }

        $repository = new static(null);
        $repository->system = true;

        return $repository;
    }

    /**
     * This system scope with the devices disabled in LibreNMS too. LibreNMS keeps the data of a
     * disabled device as it was, so the topology history keeps its last state rather than reporting
     * its links as gone and its neighbors as new unmanaged devices.
     */
    public function withDisabled(): static
    {
        if (! $this->system) {
            throw new \LogicException('only the system scope can include disabled devices');
        }

        $repository = clone $this;
        $repository->withDisabled = true;
        $repository->accessible = null;

        return $repository;
    }

    /**
     * Another repository of the plugin for the same user, sharing the list of devices it may see.
     *
     * @template T of ScopedRepository
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function sibling(string $class): self
    {
        $sibling = new $class($this->user);
        $sibling->accessible = $this->accessible();
        $sibling->system = $this->system;
        $sibling->withDisabled = $this->withDisabled;
        $sibling->allowed = $this->allowed;

        return $sibling;
    }

    /**
     * May the user read this kind of LibreNMS data? Besides the devices a user may see, LibreNMS checks a
     * role permission per kind (port.view, link.view, routing.view ... through its policies), and admins
     * can create roles without some of them; the plugin then leaves that data out, as LibreNMS' own pages
     * do. A LibreNMS version without a policy for the model shows it to anyone who may see the device, and
     * the system scope of the scheduled jobs reads everything. Checked once per model.
     *
     * @param  class-string  $modelClass  e.g. \App\Models\Port::class
     */
    public function allows(string $modelClass): bool
    {
        if ($this->system) {
            return true;
        }

        return $this->allowed[$modelClass] ??= $this->policyAllows($modelClass);
    }

    /**
     * allows() for the model of a LibreNMS table (TABLE_MODELS), also given with an alias ("ipv4_addresses as a");
     * other tables are readable.
     */
    public function allowsTable(string $table): bool
    {
        $model = self::TABLE_MODELS[explode(' ', trim($table), 2)[0]][0] ?? null;

        return $model === null || $this->allows($model);
    }

    /**
     * What a role permission hides among these tables, for the page notices ("BGP sessions", ...).
     *
     * @param  string[]  $tables
     * @return string[]
     */
    public function hiddenKinds(array $tables): array
    {
        $hidden = [];
        foreach ($tables as $table) {
            if (! $this->allowsTable($table)) {
                $hidden[self::TABLE_MODELS[$table][1]] = true;
            }
        }

        return array_keys($hidden);
    }

    /**
     * The LibreNMS policy's viewAny for the user (true without such a model or policy).
     */
    protected function policyAllows(string $modelClass): bool
    {
        try {
            if (! class_exists($modelClass) || Gate::getPolicyFor($modelClass) === null) {
                return true;
            }

            return Gate::forUser($this->user)->allows('viewAny', $modelClass);
        } catch (\Throwable $e) {
            Log::debug("topology plugin: cannot check the permission for $modelClass: " . $e->getMessage());

            return false;
        }
    }

    /**
     * May the user see every device (LibreNMS' viewAll on devices), also those LibreNMS no longer has? A map of
     * the past names such a device by the name the history stored for this user only.
     */
    public function seesEveryDevice(): bool
    {
        if ($this->system) {
            return true;
        }
        try {
            return Gate::forUser($this->user)->allows('viewAll', Device::class);
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot check the viewAll permission: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Is this the system scope of the scheduled jobs (no user)?
     */
    protected function isSystem(): bool
    {
        return $this->system;
    }

    /**
     * Devices the user may see, optionally limited to a device group and/or location.
     *
     * @return int[]
     */
    public function deviceIds(?int $groupId = null, ?int $locationId = null): array
    {
        $query = $this->system ? Device::query() : Device::hasAccess($this->user);
        if (! $this->withDisabled) {
            $query->where('devices.disabled', 0);
        }
        if ($groupId) {
            $query->inDeviceGroup($groupId);
        }
        if ($locationId) {
            $query->where('devices.location_id', $locationId);
        }

        return array_map('intval', $query->pluck('devices.device_id')->all());
    }

    /**
     * Device scope of a page: the group / location filter, widened to every device the user may see
     * when the centered device is outside it; a centered device the user may not see is dropped.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p  see MapParams::parse()
     * @return array{0: int[], 1: int|null} device ids, centered device
     */
    public function scope(array $p): array
    {
        $scope = $this->deviceIds($p['group'] ?? null, $p['location'] ?? null);
        $root = ($p['device'] ?? null) ?: null;
        if ($root && ! in_array($root, $scope, true)) {
            $all = $this->deviceIds();
            if (in_array($root, $all, true)) {
                $scope = $all;
            } else {
                $root = null; // not visible to this user
            }
        }

        return [$scope, $root];
    }

    /**
     * scope() for a request that writes: a device, group or location that was given but is not one the
     * user may see (or no longer exists) is refused instead of falling back to every device.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p  see MapParams::parse()
     * @return array{0: int[], 1: int|null}
     *
     * @throws \InvalidArgumentException
     */
    public function strictScope(array $p): array
    {
        $device = ($p['device'] ?? null) ?: null;
        if ($device && ! $this->canSee($device)) {
            throw new \InvalidArgumentException('The device of this request is not one you may see (disabled, deleted or not permitted).');
        }
        if (($p['group'] ?? null) && ! in_array((int) $p['group'], array_column($this->filterOptions()['groups'], 'id'), true)) {
            throw new \InvalidArgumentException('The device group of this request is not one you may see.');
        }
        if (($p['location'] ?? null) && ! in_array((int) $p['location'], array_column($this->filterOptions()['locations'], 'id'), true)) {
            throw new \InvalidArgumentException('The location of this request is not one you may see.');
        }

        return $this->scope($p);
    }

    /**
     * May the user see this device (enabled and permitted)?
     */
    public function canSee(int $deviceId): bool
    {
        return isset($this->accessible()[$deviceId]);
    }

    /**
     * Device groups and locations the user may filter by.
     */
    public function filterOptions(): array
    {
        $groups = [];
        $locations = [];
        try {
            $groups = \App\Models\DeviceGroup::hasAccess($this->user)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($g) => ['id' => (int) $g->id, 'name' => (string) $g->name])->all();
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot list device groups: ' . $e->getMessage());
        }
        try {
            $locations = \App\Models\Location::hasAccess($this->user)->orderBy('location')->get(['id', 'location'])
                ->map(fn ($l) => ['id' => (int) $l->id, 'name' => (string) $l->location])->all();
        } catch (\Throwable $e) {
            Log::debug('topology plugin: cannot list locations: ' . $e->getMessage());
        }

        return ['groups' => $groups, 'locations' => $locations];
    }

    /**
     * Display name of a device, null when the user may not see it.
     */
    public function deviceName(int $deviceId): ?string
    {
        if (! $this->canSee($deviceId)) {
            return null;
        }
        $row = DB::table('devices')->where('device_id', $deviceId)->first(['display', 'hostname']);

        return $row ? (string) ($row->display ?: $row->hostname) : null;
    }

    /**
     * @return array<int, true> device ids the user may see
     */
    protected function accessible(): array
    {
        if ($this->accessible === null) {
            $this->accessible = array_flip($this->deviceIds());
        }

        return $this->accessible;
    }

    /**
     * Display names of devices the caller already scoped.
     *
     * @param  int[]  $deviceIds
     * @return array<int, string> device_id => display name
     */
    protected function deviceNames(array $deviceIds): array
    {
        $deviceIds = array_values(array_unique($deviceIds));
        if (! $deviceIds) {
            return [];
        }

        $names = [];
        foreach (DB::table('devices')->whereIntegerInRaw('device_id', $deviceIds)->get(['device_id', 'display', 'hostname']) as $row) {
            $names[(int) $row->device_id] = (string) ($row->display ?: $row->hostname);
        }

        return $names;
    }

    /**
     * All rows of a per-device table for the given devices, or none when the table does not exist on
     * this LibreNMS version or the user's role may not read it.
     *
     * @param  int[]  $ids
     * @param  string[]  $columns
     * @return array<int, array>
     */
    protected function rows(string $table, array $ids, array $columns): array
    {
        if (! $ids || ! $this->allowsTable($table)) {
            return [];
        }

        try {
            return DB::table($table)->whereIntegerInRaw('device_id', $ids)->get($columns)->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable $e) {
            Log::debug("topology plugin: cannot read $table: " . $e->getMessage());

            return [];
        }
    }

    /**
     * Devices among $ids with at least one row in a per-device table (none when the table does not exist).
     *
     * @param  int[]  $ids
     * @return int[]
     */
    protected function distinctDevices(string $table, array $ids): array
    {
        if (! $this->allowsTable($table)) {
            return [];
        }
        try {
            return array_map('intval', DB::table($table)->whereIntegerInRaw('device_id', $ids)->distinct()->pluck('device_id')->all());
        } catch (\Throwable $e) {
            Log::debug("topology plugin: cannot read $table: " . $e->getMessage());

            return [];
        }
    }
}
