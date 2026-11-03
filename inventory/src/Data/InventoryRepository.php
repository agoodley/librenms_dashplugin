<?php

namespace Agoodley\LibrenmsInventory\Data;

use Agoodley\LibrenmsInventory\Support\Access;
use Agoodley\LibrenmsInventory\Support\Filters;
use Agoodley\LibrenmsInventory\Support\Origin;
use Illuminate\Support\Facades\DB;

/**
 * The LibreNMS data of the inventory, for the devices the user may see: the devices (model, software version, serial
 * number, features), their hardware inventory (entPhysical: chassis, modules, power supplies, fans, pluggables with
 * their serial numbers and revisions) and their optics (transceivers). Read only; nothing is polled.
 */
final class InventoryRepository
{
    /** Classes of the hardware inventory listed for their model even without a serial number. */
    public const MODULE_CLASSES = ['chassis', 'module', 'powerSupply', 'fan', 'port', 'stack'];

    /** Most rows of one kind a search returns. */
    public const SEARCH_LIMIT = 200;

    /** Device ids per query. */
    private const CHUNK = 2000;

    /** @var int[]|null|false the devices the user may see (null: all), false before asked */
    private array|null|false $visible = false;

    /** @var array<string, list<array{id: int, name: string}>> the device groups and locations the user may see, once asked */
    private array $offers = [];

    public function __construct(private readonly mixed $user)
    {
    }

    /**
     * May the user's role view the hardware inventory (LibreNMS' entPhysical)?
     */
    public function allowsModules(): bool
    {
        return Access::allows($this->user, \App\Models\EntPhysical::class);
    }

    /**
     * May the user's role view the optics (LibreNMS' transceivers)?
     */
    public function allowsOptics(): bool
    {
        return Access::allows($this->user, \App\Models\Transceiver::class);
    }

    /**
     * May the user's role view ports (for the port an optic sits in)?
     */
    public function allowsPorts(): bool
    {
        return Access::allows($this->user, \App\Models\Port::class);
    }

    /**
     * The devices of the filters (device, group, location, os, model, version, q) the user may see. A device group or
     * location the user may not see (not one the filters offer them) has none: its id says nothing of its devices,
     * not even whether it exists.
     *
     * @return array<int, array> device_id => device_id, name, hostname, os, hardware, version, features, serial, type,
     *     status, disabled, location_id, location, last_discovered, url
     */
    public function devices(array $filters): array
    {
        $visible = $this->visible();
        if ($visible !== null && ! $this->offered($filters)) {
            return [];
        }
        $query = DB::table('devices')->leftJoin('locations', 'devices.location_id', '=', 'locations.id')
            ->select(['devices.device_id', 'devices.hostname', 'devices.sysName', 'devices.display', 'devices.os', 'devices.hardware', 'devices.version',
                'devices.features', 'devices.serial', 'devices.type', 'devices.status', 'devices.disabled', 'devices.location_id',
                'locations.location as location', 'devices.last_discovered']);
        if ($visible !== null) {
            $query->whereIntegerInRaw('devices.device_id', $visible ?: [0]);
        }
        if ($filters['device'] ?? null) {
            $query->where('devices.device_id', $filters['device']);
        }
        if ($filters['group'] ?? null) {
            $query->whereIn('devices.device_id', DB::table('device_group_device')->where('device_group_id', $filters['group'])->select('device_id'));
        }
        if ($filters['location'] ?? null) {
            $query->where('devices.location_id', $filters['location']);
        }
        if (($filters['os'] ?? '') !== '') {
            $query->where('devices.os', $filters['os']);
        }
        if (($filters['version'] ?? '') !== '') {
            $query->where('devices.version', $filters['version']);
        }
        if (($filters['model'] ?? '') !== '') {
            // the device's model, or one of its chassis (a member of a stack)
            $model = $filters['model'];
            $query->where(function ($q) use ($model): void {
                $q->where('devices.hardware', $model);
                if ($this->allowsModules()) {
                    $q->orWhereIn('devices.device_id', DB::table('entPhysical')->where('entPhysicalClass', 'chassis')
                        ->where('entPhysicalModelName', $model)->select('device_id'));
                }
            });
        }
        if (($filters['q'] ?? '') !== '') {
            $like = Filters::like($filters['q']);
            $query->where(fn ($q) => $q->where('devices.hostname', 'like', $like)->orWhere('devices.sysName', 'like', $like)
                ->orWhere('devices.display', 'like', $like)->orWhere('devices.serial', 'like', $like)->orWhere('devices.hardware', 'like', $like));
        }

        $devices = [];
        foreach ($query->orderBy('devices.hostname')->get() as $row) {
            $row = (array) $row;
            $id = (int) $row['device_id'];
            $devices[$id] = $row + [
                'name' => (string) (($row['display'] ?? '') ?: $row['hostname']),
                'url' => url('device/device=' . $id . '/'),
            ];
        }

        return $devices;
    }

    /**
     * The chassis of these devices' hardware inventory (the members of a stack each), in their order: those with a model or
     * a serial number (a stack's empty slots left out).
     *
     * @param  int[]  $deviceIds
     * @return array<int, list<array{model: string, serial: string, name: string, revision: string}>>
     */
    public function chassis(array $deviceIds): array
    {
        if (! $deviceIds || ! $this->allowsModules()) {
            return [];
        }
        $chassis = [];
        foreach (array_chunk($deviceIds, self::CHUNK) as $chunk) {
            // a stack's empty slots are chassis without a model and a serial number (Cisco SB, Eltex): not members
            foreach (DB::table('entPhysical')->whereIntegerInRaw('device_id', $chunk)->where('entPhysicalClass', 'chassis')
                ->where(fn ($q) => $q->where('entPhysicalModelName', '<>', '')->orWhere('entPhysicalSerialNum', '<>', ''))
                ->orderBy('device_id')->orderBy('entPhysicalIndex')
                ->get(['device_id', 'entPhysicalModelName', 'entPhysicalSerialNum', 'entPhysicalName', 'entPhysicalHardwareRev']) as $row) {
                $chassis[(int) $row->device_id][] = ['model' => trim((string) $row->entPhysicalModelName), 'serial' => trim((string) $row->entPhysicalSerialNum),
                    'name' => trim((string) $row->entPhysicalName), 'revision' => trim((string) $row->entPhysicalHardwareRev)];
            }
        }

        return $chassis;
    }

    /**
     * The modules of these devices: the entries of their hardware inventory with a serial number, or with a model of the
     * MODULE_CLASSES, filtered (class, q: serial number, model, name or description), a page of them and how many in all.
     *
     * @param  int[]  $deviceIds
     * @return array{rows: list<array>, total: int}
     */
    public function modules(array $deviceIds, array $filters, ?int $limit = null, int $offset = 0): array
    {
        if (! $deviceIds || ! $this->allowsModules()) {
            return ['rows' => [], 'total' => 0];
        }
        $query = DB::table('entPhysical')->whereIntegerInRaw('device_id', $deviceIds)
            ->where(fn ($q) => $q->where('entPhysicalSerialNum', '<>', '')
                ->orWhere(fn ($m) => $m->where('entPhysicalModelName', '<>', '')->whereIn('entPhysicalClass', self::MODULE_CLASSES)));
        if (($filters['class'] ?? '') !== '') {
            $query->where('entPhysicalClass', $filters['class']);
        }
        if (($filters['q'] ?? '') !== '') {
            $like = Filters::like($filters['q']);
            $query->where(fn ($q) => $q->where('entPhysicalSerialNum', 'like', $like)->orWhere('entPhysicalModelName', 'like', $like)
                ->orWhere('entPhysicalName', 'like', $like)->orWhere('entPhysicalDescr', 'like', $like));
        }
        $total = (clone $query)->count();
        $query->orderBy('device_id')->orderBy('entPhysicalIndex');
        if ($limit !== null) {
            $query->limit($limit)->offset($offset);
        }
        $rows = [];
        foreach ($query->get(['device_id', 'entPhysicalIndex', 'entPhysicalClass', 'entPhysicalName', 'entPhysicalDescr', 'entPhysicalModelName',
            'entPhysicalSerialNum', 'entPhysicalHardwareRev', 'entPhysicalFirmwareRev', 'entPhysicalSoftwareRev', 'entPhysicalIsFRU',
            'entPhysicalMfgName']) as $row) {
            $rows[] = [
                'device_id' => (int) $row->device_id,
                'index' => (int) $row->entPhysicalIndex,
                'class' => (string) $row->entPhysicalClass,
                'name' => trim((string) $row->entPhysicalName),
                'description' => trim((string) $row->entPhysicalDescr),
                'model' => trim((string) $row->entPhysicalModelName),
                'serial' => trim((string) $row->entPhysicalSerialNum),
                'hardware_rev' => trim((string) $row->entPhysicalHardwareRev),
                'firmware_rev' => trim((string) $row->entPhysicalFirmwareRev),
                'software_rev' => trim((string) $row->entPhysicalSoftwareRev),
                'fru' => strtolower(trim((string) $row->entPhysicalIsFRU)) === 'true',
                'manufacturer' => trim((string) $row->entPhysicalMfgName),
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * The optics of these devices with the port each sits in (when the user's role may view ports) and its origin
     * (Support\Origin, from the device's os).
     *
     * @param  int[]  $deviceIds
     * @param  array<int, array>  $devices  device_id => os
     * @return list<array>
     */
    public function optics(array $deviceIds, array $devices): array
    {
        if (! $deviceIds || ! $this->allowsOptics()) {
            return [];
        }
        $ports = $this->allowsPorts();
        $optics = [];
        foreach (array_chunk($deviceIds, self::CHUNK) as $chunk) {
            $query = DB::table('transceivers as t')->whereIntegerInRaw('t.device_id', $chunk)->orderBy('t.device_id')->orderBy('t.id');
            $columns = ['t.id', 't.device_id', 't.port_id', 't.type', 't.vendor', 't.model', 't.revision', 't.serial', 't.date', 't.ddm', 't.cable',
                't.distance', 't.wavelength', 't.connector', 't.channels'];
            if ($ports) {
                $query->leftJoin('ports as p', 'p.port_id', '=', 't.port_id');
                array_push($columns, 'p.ifName', 'p.ifDescr', 'p.ifAlias', 'p.ifOperStatus');
            }
            foreach ($query->get($columns) as $row) {
                $row = (array) $row;
                $deviceId = (int) $row['device_id'];
                $optics[] = [
                    'id' => (int) $row['id'],
                    'device_id' => $deviceId,
                    'port_id' => $row['port_id'] !== null ? (int) $row['port_id'] : null,
                    'port' => trim((string) (($row['ifName'] ?? '') ?: ($row['ifDescr'] ?? ''))),
                    'port_alias' => trim((string) ($row['ifAlias'] ?? '')),
                    'port_status' => (string) ($row['ifOperStatus'] ?? ''),
                    'type' => trim((string) $row['type']),
                    'vendor' => trim((string) $row['vendor']),
                    'model' => trim((string) $row['model']),
                    'revision' => trim((string) $row['revision']),
                    'serial' => trim((string) $row['serial']),
                    'date' => trim((string) $row['date']),
                    'ddm' => $row['ddm'] === null ? null : (bool) $row['ddm'],
                    'cable' => trim((string) $row['cable']),
                    'distance' => $row['distance'] !== null && (int) $row['distance'] > 0 ? (int) $row['distance'] : null,
                    'wavelength' => $row['wavelength'] !== null && (int) $row['wavelength'] > 0 ? (int) $row['wavelength'] : null,
                    'connector' => trim((string) $row['connector']),
                    'channels' => $row['channels'] !== null ? (int) $row['channels'] : null,
                    'origin' => Origin::of($devices[$deviceId]['os'] ?? null, (string) $row['vendor'], (string) $row['model']),
                    'port_url' => $ports && $row['port_id'] !== null
                        ? url('device/device=' . $deviceId . '/tab=port/port=' . (int) $row['port_id'] . '/') : null,
                ];
            }
        }

        return $optics;
    }

    /**
     * How many modules with a serial number and how many optics each of these devices has.
     *
     * @param  int[]  $deviceIds
     * @return array<int, array{serials: int, optics: int}>
     */
    public function counts(array $deviceIds): array
    {
        $counts = [];
        foreach (array_chunk($deviceIds, self::CHUNK) as $chunk) {
            if ($this->allowsModules()) {
                foreach (DB::table('entPhysical')->whereIntegerInRaw('device_id', $chunk)->where('entPhysicalSerialNum', '<>', '')
                    ->groupBy('device_id')->selectRaw('device_id, COUNT(*) as c')->get() as $row) {
                    $counts[(int) $row->device_id]['serials'] = (int) $row->c;
                }
            }
            if ($this->allowsOptics()) {
                foreach (DB::table('transceivers')->whereIntegerInRaw('device_id', $chunk)->groupBy('device_id')->selectRaw('device_id, COUNT(*) as c')->get() as $row) {
                    $counts[(int) $row->device_id]['optics'] = (int) $row->c;
                }
            }
        }

        return array_map(fn (array $count) => $count + ['serials' => 0, 'optics' => 0], $counts);
    }

    /**
     * Serial numbers and part numbers that contain $text: of the devices, their hardware inventory and their optics, at
     * most SEARCH_LIMIT of each.
     *
     * @return list<array{kind: string, device_id: int, what: string, model: string, serial: string, port_id: ?int}>
     */
    public function search(string $text): array
    {
        $visible = $this->visible();
        $like = Filters::like($text);
        $scope = fn ($query, string $column) => $visible === null ? $query : $query->whereIntegerInRaw($column, $visible ?: [0]);
        $found = [];
        foreach ($scope(DB::table('devices'), 'device_id')->where(fn ($q) => $q->where('serial', 'like', $like)->orWhere('hardware', 'like', $like))
            ->limit(self::SEARCH_LIMIT)->get(['device_id', 'serial', 'hardware']) as $row) {
            $found[] = ['kind' => 'device', 'device_id' => (int) $row->device_id, 'what' => 'the device', 'model' => trim((string) $row->hardware),
                'serial' => trim((string) $row->serial), 'port_id' => null];
        }
        if ($this->allowsModules()) {
            foreach ($scope(DB::table('entPhysical'), 'device_id')->where(fn ($q) => $q->where('entPhysicalSerialNum', 'like', $like)
                ->orWhere('entPhysicalModelName', 'like', $like))->limit(self::SEARCH_LIMIT)
                ->get(['device_id', 'entPhysicalClass', 'entPhysicalName', 'entPhysicalModelName', 'entPhysicalSerialNum']) as $row) {
                $found[] = ['kind' => 'module', 'device_id' => (int) $row->device_id,
                    'what' => trim((string) $row->entPhysicalName) ?: (string) $row->entPhysicalClass, 'model' => trim((string) $row->entPhysicalModelName),
                    'serial' => trim((string) $row->entPhysicalSerialNum), 'port_id' => null, 'class' => (string) $row->entPhysicalClass];
            }
        }
        if ($this->allowsOptics()) {
            foreach ($scope(DB::table('transceivers'), 'device_id')->where(fn ($q) => $q->where('serial', 'like', $like)->orWhere('model', 'like', $like))
                ->limit(self::SEARCH_LIMIT)->get(['device_id', 'port_id', 'type', 'model', 'serial']) as $row) {
                $found[] = ['kind' => 'optic', 'device_id' => (int) $row->device_id, 'what' => trim((string) $row->type) ?: 'optic',
                    'model' => trim((string) $row->model), 'serial' => trim((string) $row->serial),
                    'port_id' => $row->port_id !== null ? (int) $row->port_id : null];
            }
        }

        return $found;
    }

    /**
     * Port names of these ports (for the search), when the user's role may view ports.
     *
     * @param  int[]  $portIds
     * @return array<int, string>
     */
    public function portNames(array $portIds): array
    {
        if (! $portIds || ! $this->allowsPorts()) {
            return [];
        }
        $names = [];
        foreach (DB::table('ports')->whereIntegerInRaw('port_id', array_values(array_unique($portIds)))->get(['port_id', 'ifName', 'ifDescr']) as $row) {
            $names[(int) $row->port_id] = trim((string) ($row->ifName ?: $row->ifDescr));
        }

        return $names;
    }

    /**
     * Names and locations of these devices (the user may see them: callers pass devices from devices() or search()).
     *
     * @param  int[]  $deviceIds
     * @return array<int, array{name: string, location: string}>
     */
    public function names(array $deviceIds): array
    {
        $names = [];
        foreach (array_chunk(array_values(array_unique($deviceIds)), self::CHUNK) as $chunk) {
            foreach (DB::table('devices')->leftJoin('locations', 'devices.location_id', '=', 'locations.id')->whereIntegerInRaw('devices.device_id', $chunk)
                ->get(['devices.device_id', 'devices.display', 'devices.hostname', 'locations.location']) as $row) {
                $names[(int) $row->device_id] = ['name' => (string) ($row->display ?: $row->hostname), 'location' => (string) ($row->location ?? '')];
            }
        }

        return $names;
    }

    /**
     * What the filters offer: the device groups and locations the user may see, the oses and the module classes of
     * their devices.
     *
     * @return array{groups: list<array{id: int, name: string}>, locations: list<array{id: int, name: string}>, os: array<string, string>}
     */
    public function options(): array
    {
        $groups = $this->groups();
        $locations = $this->locations();
        $visible = $this->visible();
        $query = DB::table('devices')->distinct()->orderBy('os');
        if ($visible !== null) {
            $query->whereIntegerInRaw('device_id', $visible ?: [0]);
        }
        $os = $query->pluck('os')->map(fn ($os) => (string) $os)->filter()->all();

        return ['groups' => $groups, 'locations' => $locations, 'os' => self::osNames($os)];
    }

    /**
     * The names LibreNMS gives these oses (Cisco IOS for ios), the os itself without one.
     *
     * @param  string[]  $os
     * @return array<string, string>
     */
    public static function osNames(array $os): array
    {
        $names = [];
        foreach (array_unique($os) as $name) {
            $text = null;
            try {
                $text = class_exists(\App\Facades\LibrenmsConfig::class) ? \App\Facades\LibrenmsConfig::get("os.$name.text") : null;
            } catch (\Throwable) {
                $text = null;
            }
            $names[$name] = is_string($text) && $text !== '' ? $text : $name;
        }
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /**
     * Are the filters' device group and location (when they have one) among those the user may see, as options() offers?
     */
    private function offered(array $filters): bool
    {
        $group = (int) ($filters['group'] ?? 0);
        $location = (int) ($filters['location'] ?? 0);

        return ($group === 0 || in_array($group, array_column($this->groups(), 'id'), true))
            && ($location === 0 || in_array($location, array_column($this->locations(), 'id'), true));
    }

    /**
     * The device groups the user may see.
     *
     * @return list<array{id: int, name: string}>
     */
    private function groups(): array
    {
        if (! isset($this->offers['groups'])) {
            try {
                $this->offers['groups'] = \App\Models\DeviceGroup::hasAccess($this->user)->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($g) => ['id' => (int) $g->id, 'name' => (string) $g->name])->all();
            } catch (\Throwable) {
                $this->offers['groups'] = []; // a LibreNMS without device groups for this user
            }
        }

        return $this->offers['groups'];
    }

    /**
     * The locations the user may see.
     *
     * @return list<array{id: int, name: string}>
     */
    private function locations(): array
    {
        if (! isset($this->offers['locations'])) {
            try {
                $this->offers['locations'] = \App\Models\Location::hasAccess($this->user)->orderBy('location')->get(['id', 'location'])
                    ->map(fn ($l) => ['id' => (int) $l->id, 'name' => (string) $l->location])->all();
            } catch (\Throwable) {
                $this->offers['locations'] = []; // as above
            }
        }

        return $this->offers['locations'];
    }

    /**
     * @return int[]|null
     */
    private function visible(): ?array
    {
        if ($this->visible === false) {
            $this->visible = Access::deviceIds($this->user);
        }

        return $this->visible;
    }
}
