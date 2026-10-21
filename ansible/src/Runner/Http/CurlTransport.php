<?php

namespace Agoodley\LibrenmsAnsible\Runner\Http;

/**
 * HTTP with PHP's curl extension (which LibreNMS requires). TLS certificates are verified unless the settings
 * turn it off, against the system CA store or a CA bundle file; only http and https are spoken, redirects are
 * not followed (the token would go wherever a redirect points), and every call has a timeout.
 */
final class CurlTransport implements Transport
{
    /** Largest answer read, in bytes (the output of a long run can be large). */
    private const MAX_BYTES = 64 * 1024 * 1024;

    /**
     * @param  int  $timeout  seconds for the whole call; connecting gets at most 10 of them
     * @param  string|null  $caBundle  CA certificates (PEM file) to verify the runner's certificate with
     */
    public function __construct(
        private readonly int $timeout = 15,
        private readonly bool $verifyTls = true,
        private readonly ?string $caBundle = null,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        if (! preg_match('#^https?://#i', $url)) {
            throw new TransportError('the runner URL must start with http:// or https://');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportError('curl could not start');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        $received = '';
        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => max(1, min(10, $this->timeout)),
            CURLOPT_TIMEOUT => max(1, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$received): int {
                if (strlen($received) + strlen($chunk) > self::MAX_BYTES) {
                    return 0; // aborts the transfer
                }
                $received .= $chunk;

                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($this->verifyTls && $this->caBundle !== null && $this->caBundle !== '') {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        unset($handle); // a CurlHandle is freed with its last reference (curl_close() does nothing since PHP 8)

        if ($ok === false || $status === 0) {
            throw new TransportError($error !== '' ? $error : 'no answer');
        }

        return new HttpResponse($status, $received);
    }
}
