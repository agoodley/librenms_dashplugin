<?php

namespace Agoodley\LibrenmsCompliance\Services;

use Agoodley\LibrenmsCompliance\Support\ErrorText;
use Agoodley\LibrenmsCompliance\Support\Log;

/**
 * The plugin's entries in LibreNMS' eventlog (type "compliance", on the device's own eventlog too). A failure to write
 * is logged, never thrown: a check must not fail because of it.
 */
final class Eventlog implements EventSink
{
    public const TYPE = 'compliance';

    public function write(string $message, ?int $deviceId, string $severity): void
    {
        try {
            $level = match ($severity) {
                'ok' => \LibreNMS\Enum\Severity::Ok,
                'notice' => \LibreNMS\Enum\Severity::Notice,
                'warning' => \LibreNMS\Enum\Severity::Warning,
                'error' => \LibreNMS\Enum\Severity::Error,
                default => \LibreNMS\Enum\Severity::Info,
            };
            \App\Models\Eventlog::log($message, $deviceId, self::TYPE, $level);
        } catch (\Throwable $e) {
            Log::error('cannot write to the eventlog: ' . ErrorText::forLog($e));
        }
    }
}
