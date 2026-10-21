<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Illuminate\Support\Facades\Log;

/**
 * Writes the plugin's entries to the LibreNMS eventlog (type "ansible"). A failure to write is logged, never
 * thrown: the run itself must not fail because of it.
 */
class Eventlog
{
    public const TYPE = 'ansible';

    /**
     * One entry.
     *
     * @param  string  $severity  ok, info, notice, warning or error
     */
    public function write(string $message, ?int $deviceId, string $severity, string $reference): void
    {
        $level = match ($severity) {
            'ok' => \LibreNMS\Enum\Severity::Ok,
            'notice' => \LibreNMS\Enum\Severity::Notice,
            'warning' => \LibreNMS\Enum\Severity::Warning,
            'error' => \LibreNMS\Enum\Severity::Error,
            default => \LibreNMS\Enum\Severity::Info,
        };
        try {
            \App\Models\Eventlog::log($message, $deviceId, self::TYPE, $level, $reference);
        } catch (\Throwable $e) {
            Log::error('ansible plugin: cannot write to the eventlog: ' . $e->getMessage());
        }
    }
}
