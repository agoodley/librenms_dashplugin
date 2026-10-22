<?php

namespace Agoodley\LibrenmsAnsible\Data;

use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runner\RunnerHealth;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Support\Connection;
use Agoodley\LibrenmsAnsible\Support\ErrorText;
use Illuminate\Support\Facades\Log;

/**
 * The last check of the runner connection (when the connection was saved, or tested): the runner's version, the
 * token's role and whether launches are blocked, or why the runner could not be asked. The settings page shows it
 * without a new test; previews and launches always ask the runner again.
 */
final class ConnectionChecks
{
    private const NAME = 'last_check';

    public function __construct(private readonly ConfigStore $store = new ConfigStore())
    {
    }

    /**
     * The last check, null when there is none (or it cannot be read).
     *
     * @return array{checked_at: int, error: ?string, version?: string, role?: ?string, admin?: bool, blocked?: ?string}|null
     */
    public function last(): ?array
    {
        try {
            $check = $this->store->get(self::NAME);
        } catch (\Throwable) {
            return null;
        }

        return is_array($check) && isset($check['checked_at']) ? $check : null;
    }

    /**
     * Keep a check.
     */
    public function save(array $check): void
    {
        try {
            $this->store->put(self::NAME, $check);
        } catch (\Throwable $e) {
            Log::warning('ansible plugin: cannot keep the connection check: ' . ErrorText::forLog($e));
        }
    }

    /**
     * Ask the runner now (its version, the token's role and user) and keep the answer.
     */
    public function checkNow(Plugin $plugin, Connection $connection): void
    {
        if (! $connection->canConnect()) {
            $this->store->forget(self::NAME);

            return;
        }
        try {
            $this->save(self::fromHealth($plugin->runner($connection, false)->health(), time()));
        } catch (RunnerError $e) {
            $this->save(self::failed($e->getMessage(), time()));
        }
    }

    /**
     * A check the runner answered.
     */
    public static function fromHealth(RunnerHealth $health, int $now): array
    {
        return ['checked_at' => $now, 'error' => null, 'version' => $health->version, 'project' => $health->project, 'role' => $health->role,
            'admin' => $health->admin, 'blocked' => $health->launchBlock()];
    }

    /**
     * A check the runner did not answer.
     */
    public static function failed(string $error, int $now): array
    {
        return ['checked_at' => $now, 'error' => $error];
    }
}
