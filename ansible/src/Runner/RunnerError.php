<?php

namespace Agoodley\LibrenmsAnsible\Runner;

/**
 * A call to the runner that failed, with a kind the plugin acts on and a message that says what to fix.
 * Messages name the runner's address and path at most, never the token.
 */
final class RunnerError extends \RuntimeException
{
    public const UNREACHABLE = 'unreachable';
    public const UNAUTHORIZED = 'unauthorized';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const BAD_RESPONSE = 'bad_response';
    public const REFUSED = 'refused';
    public const NOT_CONFIGURED = 'not_configured';

    /**
     * @param  bool  $uncertain  the request may have been carried out by the runner although no proper answer came (a
     *                           timeout, a broken answer): a launch may have started a task
     */
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $status = null,
        public readonly bool $uncertain = false,
    ) {
        parent::__construct($message);
    }
}
