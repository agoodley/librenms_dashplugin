<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Data\DeviceRepository;
use Agoodley\LibrenmsAnsible\Data\FetchRepository;
use Agoodley\LibrenmsAnsible\Inventory\Inventory;
use Agoodley\LibrenmsAnsible\Inventory\InventoryBuilder;
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
     * The device data the inventory was built from.
     */
    public function devices(): DeviceRepository
    {
        return $this->devices;
    }
}
