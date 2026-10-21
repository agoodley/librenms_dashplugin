<?php

namespace Agoodley\LibrenmsAnsible\Runner\Http;

/**
 * No HTTP answer: the connection, TLS or a timeout failed. $maybeSent says whether the request may have reached
 * the server (a timeout or a broken answer) or certainly did not (no connection was made).
 */
final class TransportError extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $maybeSent = false)
    {
        parent::__construct($message);
    }
}
