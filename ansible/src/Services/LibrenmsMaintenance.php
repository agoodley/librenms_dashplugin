<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Changes\MaintenanceWindows;
use App\Models\AlertSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use LibreNMS\Enum\MaintenanceBehavior;

/**
 * LibreNMS maintenance windows (App\Models\AlertSchedule), made as LibreNMS' own maintenance page and API make them: a
 * one-time schedule (recurring 0) with a title, notes and a behavior (LibreNMS' default, alert.scheduled_maintenance_
 * default_behavior, unless the plugin's setting names one), start and end through the model, which keeps them in UTC,
 * and the devices attached in the same transaction. Its end is moved by the plugin while the batch runs and set when
 * the batch ended; its devices (and notes) are set again when the batch's blast radius changed before its launch.
 */
final class LibrenmsMaintenance implements MaintenanceWindows
{
    public function open(string $title, string $notes, array $deviceIds, int $start, int $end, ?int $behavior): int
    {
        try {
            $schedule = new AlertSchedule(['title' => mb_substr($title, 0, 255), 'notes' => $notes,
                'behavior' => $behavior !== null ? MaintenanceBehavior::from($behavior) : MaintenanceBehavior::fromConfig(), 'recurring' => 0]);
            $schedule->start = Carbon::createFromTimestamp($start, 'UTC');
            $schedule->end = Carbon::createFromTimestamp($end, 'UTC');
            DB::transaction(function () use ($schedule, $deviceIds): void {
                $schedule->save();
                $schedule->devices()->sync(array_values(array_map('intval', $deviceIds)));
            });
        } catch (\Throwable $e) {
            throw new \RuntimeException('LibreNMS could not save the maintenance window: ' . \Agoodley\LibrenmsAnsible\Support\ErrorText::forLog($e));
        }

        return (int) $schedule->schedule_id;
    }

    public function setEnd(int $id, int $end): bool
    {
        try {
            $schedule = AlertSchedule::find($id);
            if ($schedule === null) {
                return false;
            }
            $schedule->end = Carbon::createFromTimestamp($end, 'UTC');
            $schedule->save();
        } catch (\Throwable $e) {
            throw new \RuntimeException('LibreNMS could not move the maintenance window: ' . \Agoodley\LibrenmsAnsible\Support\ErrorText::forLog($e));
        }

        return true;
    }

    public function setDevices(int $id, array $deviceIds, string $notes): bool
    {
        try {
            $schedule = AlertSchedule::find($id);
            if ($schedule === null) {
                return false;
            }
            DB::transaction(function () use ($schedule, $deviceIds, $notes): void {
                $schedule->notes = $notes;
                $schedule->save();
                $schedule->devices()->sync(array_values(array_map('intval', $deviceIds)));
            });
        } catch (\Throwable $e) {
            throw new \RuntimeException('LibreNMS could not change the devices of the maintenance window: ' . \Agoodley\LibrenmsAnsible\Support\ErrorText::forLog($e));
        }

        return true;
    }
}
