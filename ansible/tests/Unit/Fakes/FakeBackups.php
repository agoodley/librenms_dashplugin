<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit\Fakes;

use Agoodley\LibrenmsAnsible\Changes\BackupError;
use Agoodley\LibrenmsAnsible\Changes\ConfigBackups;

/**
 * Oxidized as the change lifecycle sees it: a node per device whose "last" attempt the test sets (or a backup that
 * succeeds a few seconds after each request), requests recorded.
 */
final class FakeBackups implements ConfigBackups
{
    public bool $enabled = true;

    /** @var array<int, array|null> device => node (name, full_name, last, excluded), null: not in Oxidized */
    public array $nodes = [];

    /** Answer node() with this error. */
    public ?string $unreachable = null;

    /** @var array<int, array{node: string, message: string, user: string}> */
    public array $requests = [];

    /** A request makes the next attempt succeed at the request time (Oxidized with next_adds_job). */
    public bool $succeed = true;

    public int $now = 0;

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** Calls of node(). */
    public int $nodeCalls = 0;

    public function node(int $deviceId): ?array
    {
        $this->nodeCalls++;
        if ($this->unreachable !== null) {
            throw new BackupError($this->unreachable);
        }

        return array_key_exists($deviceId, $this->nodes) ? $this->nodes[$deviceId]
            : ['name' => "dev$deviceId", 'full_name' => "lab/dev$deviceId", 'last' => ['start' => '2026-10-01 00:00:00 UTC', 'end' => '2026-10-01 00:00:04 UTC', 'status' => 'success']];
    }

    /** @var \Closure|null called at each request (to make something happen at that moment) */
    public ?\Closure $onRequest = null;

    public function request(string $nodeName, string $message, string $username): bool
    {
        $this->requests[] = ['node' => $nodeName, 'message' => $message, 'user' => $username];
        if ($this->onRequest !== null) {
            ($this->onRequest)($nodeName);
        }
        if (! $this->succeed) {
            return true;
        }
        $last = ['start' => gmdate('Y-m-d H:i:s', $this->now) . ' UTC', 'end' => gmdate('Y-m-d H:i:s', $this->now + 4) . ' UTC', 'status' => 'success'];
        foreach ($this->nodes as $deviceId => $node) {
            if (is_array($node) && ($node['name'] ?? null) === $nodeName) {
                $this->nodes[$deviceId]['last'] = $last;

                return true;
            }
        }
        if (preg_match('/^dev(\d+)$/D', $nodeName, $m)) {
            $this->nodes[(int) $m[1]] = ['name' => $nodeName, 'full_name' => "lab/$nodeName", 'last' => $last];
        }

        return true;
    }

    public function latestVersion(string $nodeFull): ?array
    {
        return ['oid' => substr(hash('sha1', $nodeFull . count($this->requests)), 0, 40), 'time' => $this->now];
    }
}
