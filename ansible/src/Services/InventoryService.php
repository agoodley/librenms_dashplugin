<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\DeviceRepository;
use Agoodley\LibrenmsAnsible\Data\FetchRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Inventory\Inventory;
use Agoodley\LibrenmsAnsible\Inventory\InventoryBuilder;
use Agoodley\LibrenmsAnsible\Runs\TargetSelection;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use App\Models\User;

/**
 * The inventory from the LibreNMS data and the settings: for the user of an API token (what the runner gets), for
 * every device (the target checks), and for the inventory user an admin chose (whose token the runner uses: the
 * targets are checked against what that user may see, and the Inventory page shows its inventory).
 */
final class InventoryService
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly PluginSettings $settings,
        private readonly FetchRepository $fetches = new FetchRepository(),
    ) {
    }

    /**
     * The inventory of the devices in $visible (every device when null).
     *
     * @param  array<int, true>|null  $visible
     */
    public function build(?array $visible = null): Inventory
    {
        return (new InventoryBuilder($this->settings->platformMap(), $this->settings->allowedGroup()))
            ->build($this->devices->devices(), $this->devices->memberships(), $this->devices->deviceGroups(), $this->devices->locations(), $visible);
    }

    /**
     * The names of these devices' ports as LibreNMS knows them (ifName).
     *
     * @param  int[]  $deviceIds
     * @return array<int, string[]>
     */
    public function interfaces(array $deviceIds): array
    {
        return $this->devices->interfaces($deviceIds);
    }

    /**
     * The inventory a LibreNMS user gets: only the devices that user may see.
     *
     * @param  User  $user
     */
    public function forUser($user): Inventory
    {
        return $this->build($this->devices->visibleTo($user));
    }

    /**
     * The inventory user chosen in the settings, with what that user may see and its latest fetch (null when the
     * runner has not fetched the inventory with its token yet); null when none is chosen or it no longer exists.
     *
     * @return array{user: User, visible: array<int, true>, fetch: array|null}|null
     */
    public function inventoryUser(): ?array
    {
        $userId = $this->settings->inventoryUser();
        $user = $userId !== null ? User::find($userId) : null;
        if ($user === null) {
            return null;
        }

        return ['user' => $user, 'visible' => $this->devices->visibleTo($user), 'fetch' => $this->fetches->forUser($userId)];
    }

    /**
     * Why there is no inventory user (launches are refused until there is), null when there is one.
     */
    public function inventoryUserProblem(): ?string
    {
        $userId = $this->settings->inventoryUser();
        if ($userId === null) {
            return 'Choose the inventory user in the plugin settings: the LibreNMS user whose API token the runner uses to fetch the inventory. '
                . 'Until then the plugin launches nothing, as it cannot tell which devices the runner will find.';
        }

        return User::find($userId) === null ? "The inventory user chosen in the plugin settings (#$userId) no longer exists in LibreNMS: choose another one." : null;
    }

    /**
     * The devices of inventory names (any device, host or not), for the hosts a runner reports.
     *
     * @param  string[]  $names
     * @return array<string, array{device_id: int, hostname: string, display: string}> inventory name => device
     */
    public function devicesNamed(array $names): array
    {
        $rows = $this->devices->devices();
        $all = (new InventoryBuilder($this->settings->platformMap(), $this->settings->allowedGroup()))
            ->names($rows, $this->devices->deviceGroups(), $this->devices->locations());
        $found = [];
        foreach (array_intersect($all, $names) as $deviceId => $name) {
            $found[$name] = ['device_id' => (int) $deviceId, 'hostname' => (string) ($rows[$deviceId]['hostname'] ?? ''),
                'display' => (string) (($rows[$deviceId]['display'] ?? '') ?: ($rows[$deviceId]['hostname'] ?? ''))];
        }

        return $found;
    }

    /**
     * The target check of a new change, as New change makes it for every device (TargetSelection): the inventory of every
     * device, what the inventory user may see, the runs that hold devices and the changes being applied.
     */
    public function targetSelection(RunRepository $runs, ChangeRepository $changes): TargetSelection
    {
        $inventoryUser = $this->inventoryUser();

        return new TargetSelection($this->build(), $this->devices->exists(), $runs->busy(), $inventoryUser['visible'] ?? null,
            (string) ($inventoryUser['user']->username ?? ''), $changes->reserved());
    }

    /**
     * The device data the inventory was built from.
     */
    public function devices(): DeviceRepository
    {
        return $this->devices;
    }
}
