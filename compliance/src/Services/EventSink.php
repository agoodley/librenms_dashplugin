<?php

namespace Agoodley\LibrenmsCompliance\Services;

/**
 * Where a check reports what changed: LibreNMS' eventlog (Eventlog); the tests keep the entries.
 */
interface EventSink
{
    /**
     * One entry, about a device or (null) about the plugin.
     *
     * @param  string  $severity  ok, info, notice, warning or error
     */
    public function write(string $message, ?int $deviceId, string $severity): void;
}
