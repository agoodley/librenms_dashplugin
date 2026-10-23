<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * LibreNMS maintenance windows (Services\LibrenmsMaintenance over App\Models\AlertSchedule; a fake in the tests): the
 * plugin opens one for each batch, on its devices and the monitored devices it cuts off, moves its end, and sets its
 * devices again when the batch's blast radius, recomputed right before its launch, differs from the one it opened with.
 */
interface MaintenanceWindows
{
    /**
     * Open a one-time window for the devices from $start to $end (Unix times) with a behavior (null: LibreNMS' default);
     * its id.
     *
     * @param  int[]  $deviceIds
     *
     * @throws \RuntimeException when it cannot be opened
     */
    public function open(string $title, string $notes, array $deviceIds, int $start, int $end, ?int $behavior): int;

    /**
     * Move a window's end; false when LibreNMS no longer has the window (an admin removed it).
     */
    public function setEnd(int $id, int $end): bool;

    /**
     * Set a window's devices (exactly these) and its notes; false when LibreNMS no longer has the window.
     *
     * @param  int[]  $deviceIds
     *
     * @throws \RuntimeException when it cannot be changed
     */
    public function setDevices(int $id, array $deviceIds, string $notes): bool;
}
