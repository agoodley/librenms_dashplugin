<?php

namespace Agoodley\LibrenmsAnsible\Runner\Http;

/**
 * Status and body of an HTTP answer.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }

    /**
     * The body as JSON, null when it is not JSON.
     */
    public function json(): mixed
    {
        if (trim($this->body) === '') {
            return null;
        }
        try {
            return json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
