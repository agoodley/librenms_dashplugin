<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Runs\EventSink;
use Agoodley\LibrenmsAnsible\Support\ErrorText;
use Illuminate\Support\Facades\Log;

/**
 * Writes the plugin's entries to the LibreNMS eventlog (type "ansible"). The entries of a run carry the user who
 * launched it, whoever's request or the sync command happens to write them; configuration entries carry the admin
 * who made the change. A failure to write is logged, never thrown: the run itself must not fail because of it.
 */
class Eventlog implements EventSink
{
    public const TYPE = 'ansible';

    /**
     * One entry.
     *
     * @param  string  $severity  ok, info, notice, warning or error
     * @param  string|null  $username  the user it is attributed to (null: the logged-in user, as LibreNMS does)
     */
    public function write(string $message, ?int $deviceId, string $severity, string $reference, ?string $username = null): void
    {
        $level = match ($severity) {
            'ok' => \LibreNMS\Enum\Severity::Ok,
            'notice' => \LibreNMS\Enum\Severity::Notice,
            'warning' => \LibreNMS\Enum\Severity::Warning,
            'error' => \LibreNMS\Enum\Severity::Error,
            default => \LibreNMS\Enum\Severity::Info,
        };
        try {
            if ($username === null) {
                \App\Models\Eventlog::log($message, $deviceId, self::TYPE, $level, $reference);

                return;
            }
            $entry = new \App\Models\Eventlog(['reference' => $reference, 'type' => self::TYPE, 'datetime' => now(), 'severity' => $level,
                'message' => $message, 'username' => mb_substr($username, 0, 255)]);
            $entry->device_id = $deviceId;
            $entry->save();
        } catch (\Throwable $e) {
            Log::error('ansible plugin: cannot write to the eventlog: ' . ErrorText::forLog($e));
        }
    }
}
