<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Data\DeviceRepository;
use Agoodley\LibrenmsAnsible\Data\FetchRepository;
use Agoodley\LibrenmsAnsible\Inventory\Inventory;
use Agoodley\LibrenmsAnsible\Inventory\InventoryBuilder;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use App\Models\User;

/**
 * The inventory from the LibreNMS data and the settings: for the user of an API token (what the runner gets),
 * for every device (the target checks), and for the user the runner last fetched it as (the Inventory page).
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
     * The inventory a LibreNMS user gets: only the devices that user may see.
     *
     * @param  User  $user
     */
    public function forUser($user): Inventory
    {
        return $this->build($this->devices->visibleTo($user));
    }

    /**
     * The user the runner last fetched the inventory as, with what that user may see; null when it never did
     * (or the user no longer exists).
     *
     * @return array{fetch: array, user: User, visible: array<int, true>}|null
     */
    public function runnerUser(): ?array
    {
        $fetch = $this->fetches->latest();
        $user = $fetch !== null ? User::find($fetch['user_id']) : null;
        if ($fetch === null || $user === null) {
            return null;
        }

        return ['fetch' => $fetch, 'user' => $user, 'visible' => $this->devices->visibleTo($user)];
    }

    /**
     * The device data the inventory was built from.
     */
    public function devices(): DeviceRepository
    {
        return $this->devices;
    }
}
