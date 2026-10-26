<?php

namespace Agoodley\LibrenmsAnsible\Data;

use Agoodley\LibrenmsAnsible\Inventory\OwnLogins;
use App\Models\Device;

/**
 * What the plugin reads of the devices' own logins (Inventory\OwnLogins): LibreNMS' Oxidized group mapping and group
 * support, and the devices' values of the mapping's other fields, as LibreNMS' list_oxidized() reads them. Never a
 * login: those are in Oxidized's configuration and the runner's secrets only.
 */
final class OwnLoginRepository
{
    /** @var array<int, Device|null> device_id => the device, read once */
    private array $devices = [];

    /**
     * The own logins as LibreNMS' settings give them (none on versions without the LibrenmsConfig facade).
     */
    public function ownLogins(): OwnLogins
    {
        if (! class_exists(\App\Facades\LibrenmsConfig::class)) {
            return OwnLogins::from([], false);
        }

        return OwnLogins::from(\App\Facades\LibrenmsConfig::get('oxidized.maps', []), \App\Facades\LibrenmsConfig::get('oxidized.group_support', false));
    }

    /**
     * A device's value of a field of the mapping (the device read once, when a field is first asked for).
     */
    public function value(int $deviceId, string $field): mixed
    {
        if (! array_key_exists($deviceId, $this->devices)) {
            $this->devices[$deviceId] = Device::with('location')->find($deviceId);
        }

        return $this->devices[$deviceId] === null ? null : self::field($this->devices[$deviceId], $field);
    }

    /**
     * A device's value of a field of the mapping as list_oxidized() reads it: $device->$field, sysname as sysName,
     * location as its name. type is always empty there (list_oxidized() does not read that column), so it is here.
     * Only the fields LibreNMS lets the mapping have: never a relation for another.
     *
     * @param  Device  $device
     */
    public static function field($device, string $field): mixed
    {
        return match ($field) {
            'sysname' => $device->sysName,
            'location' => $device->location?->location,
            'hostname', 'sysName', 'sysDescr', 'sysObjectID', 'hardware', 'os', 'ip', 'purpose', 'notes', 'poller_group' => $device->getAttribute($field),
            default => null,
        };
    }
}
