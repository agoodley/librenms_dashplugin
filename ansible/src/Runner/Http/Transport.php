<?php

namespace Agoodley\LibrenmsAnsible\Runner\Http;

/**
 * The HTTP calls of a runner adapter, behind an interface so the adapter is tested with recorded answers.
 */
interface Transport
{
    /**
     * Send one request; throws TransportError when no answer came (connection, TLS, timeout).
     *
     * @param  array<string, string>  $headers
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
