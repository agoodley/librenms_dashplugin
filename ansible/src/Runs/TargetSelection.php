<?php

namespace Agoodley\LibrenmsAnsible\Runs;

use Agoodley\LibrenmsAnsible\Inventory\Inventory;
use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Support\Clock;

/**
 * Which chosen devices can be a run's targets, and by which inventory name. A target must exist, be a host of
 * the inventory (enabled, in the allowed device group, with a platform mapping and a usable address), be
 * visible to the user the runner fetches the inventory as (else the runner would not find it), and not be busy
 * in another unfinished run of the plugin, nor held by a stopped one. The others are refused with the reason.
 */
final class TargetSelection
{
    /**
     * @param  Inventory  $inventory  the inventory of every device (InventoryBuilder::build() without a scope)
     * @param  array<int, true>  $exists  ids of every LibreNMS device
     * @param  array<int, array{run: int, held_until: ?string}>  $busy  device_id => the unfinished run it is a target of, or the stopped run
     *                                                               that holds it until held_until (RunRepository::busy())
     * @param  array<int, true>|null  $runnerVisible  devices the inventory user may see (null: none chosen)
     * @param  string  $runnerUser  that user's name, for the reason
     */
    public function __construct(
        private readonly Inventory $inventory,
        private readonly array $exists,
        private readonly array $busy,
        private readonly ?array $runnerVisible,
        private readonly string $runnerUser = '',
    ) {
    }

    /**
     * Split the chosen devices into targets and refused ones.
     *
     * @param  int[]  $chosen  device ids
     * @param  int|null  $ignoreRun  a run whose targets do not count as busy
     * @return array{targets: array<int, string>, refused: array<int, array{reason: string, text: string}>} targets: device_id => inventory name
     */
    public function select(array $chosen, ?int $ignoreRun = null): array
    {
        $targets = [];
        $refused = [];
        foreach (array_values(array_unique(array_map('intval', $chosen))) as $deviceId) {
            $refusal = $this->refusal($deviceId, $ignoreRun);
            if ($refusal !== null) {
                $refused[$deviceId] = $refusal;
            } else {
                $targets[$deviceId] = (string) $this->inventory->nameOf($deviceId);
            }
        }

        return ['targets' => $targets, 'refused' => $refused];
    }

    /**
     * Why a device cannot be a target, null when it can.
     *
     * @return array{reason: string, text: string}|null
     */
    public function refusal(int $deviceId, ?int $ignoreRun = null): ?array
    {
        if (! isset($this->exists[$deviceId])) {
            return ['reason' => 'not_found', 'text' => 'no such device in LibreNMS'];
        }
        $leftOut = $this->inventory->leftOut()[$deviceId] ?? null;
        if ($leftOut !== null) {
            return $leftOut;
        }
        if ($this->inventory->nameOf($deviceId) === null) {
            return ['reason' => 'not_in_inventory', 'text' => 'not in the inventory'];
        }
        if ($this->runnerVisible !== null && ! isset($this->runnerVisible[$deviceId])) {
            $user = $this->runnerUser !== '' ? ' ' . UntrustedText::line($this->runnerUser, 60) : '';

            return ['reason' => 'not_visible', 'text' => "the inventory user$user may not see it in LibreNMS, so the runner would not find it"];
        }
        $busy = $this->busy[$deviceId] ?? null;
        if ($busy !== null && $busy['run'] !== $ignoreRun) {
            return self::busyRefusal($busy);
        }

        return null;
    }

    /**
     * Why a device busy in a run (RunRepository::busy()) cannot be a target.
     *
     * @param  array{run: int, held_until: ?string}  $busy
     * @return array{reason: string, text: string}
     */
    public static function busyRefusal(array $busy): array
    {
        return ['reason' => 'busy', 'text' => $busy['held_until'] !== null
            ? "held by run #{$busy['run']} until " . Clock::local($busy['held_until'], 'Y-m-d H:i') . ': it was stopped, and the step in progress may still run on the runner '
                . '(an admin can release the devices on the run page)'
            : "busy in run #{$busy['run']}, which has not finished"];
    }
}
