<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * LibreNMS maintenance windows (Services\LibrenmsMaintenance over App\Models\AlertSchedule; a fake in the tests): the
 * plugin opens one for the devices of each batch and moves its end.
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
}
