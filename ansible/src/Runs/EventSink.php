<?php

namespace Agoodley\LibrenmsAnsible\Runs;

/**
 * Where the run lifecycle writes its eventlog entries (Services\Eventlog; a recording one in the tests).
 */
interface EventSink
{
    /**
     * One entry, attributed to $username (the user who launched the run) rather than to whoever happens to be
     * logged in when it is written.
     *
     * @param  string  $severity  ok, info, notice, warning or error
     */
    public function write(string $message, ?int $deviceId, string $severity, string $reference, ?string $username = null): void;
}
