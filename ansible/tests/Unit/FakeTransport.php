<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Runner\Http\HttpResponse;
use Agoodley\LibrenmsAnsible\Runner\Http\Transport;
use Agoodley\LibrenmsAnsible\Runner\Http\TransportError;

/**
 * Answers runner calls from recorded responses (tests/fixtures) and records the requests.
 */
final class FakeTransport implements Transport
{
    /** @var array<int, array{method: string, url: string, headers: array, body: ?string}> */
    public array $requests = [];

    /**
     * @param  array<string, HttpResponse|\Throwable|callable>  $routes  "METHOD /path" => answer (path after the base URL)
     */
    public function __construct(private array $routes = [])
    {
    }

    /**
     * The JSON of a recorded Semaphore answer.
     */
    public static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/semaphore/' . $name);
    }

    /**
     * Answer one route.
     */
    public function on(string $route, HttpResponse|\Throwable|callable $answer): self
    {
        $this->routes[$route] = $answer;

        return $this;
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $path = (string) preg_replace('#^https?://[^/]+#', '', $url);
        $answer = $this->routes["$method $path"] ?? null;
        if ($answer === null) {
            return new HttpResponse(404, '');
        }
        if ($answer instanceof \Throwable) {
            throw $answer;
        }
        if (is_callable($answer)) {
            return $answer($body);
        }

        return $answer;
    }

    /**
     * The JSON body of the last request.
     */
    public function lastBody(): array
    {
        $last = end($this->requests);

        return json_decode((string) ($last['body'] ?? 'null'), true) ?? [];
    }

    /**
     * A transport error, as curl reports one.
     */
    public static function unreachable(): TransportError
    {
        return new TransportError('Failed to connect to 127.0.0.1 port 3999 after 0 ms: Couldn\'t connect to server');
    }
}
