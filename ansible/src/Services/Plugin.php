<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\AnsibleServiceProvider;
use Agoodley\LibrenmsAnsible\Data\ConnectionRepository;
use Agoodley\LibrenmsAnsible\Runner\Http\CurlTransport;
use Agoodley\LibrenmsAnsible\Runner\Runner;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runner\Semaphore\SemaphoreRunner;
use Agoodley\LibrenmsAnsible\Support\Connection;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * The plugin's settings and its runner, as the controllers, hooks and the sync command need them.
 */
final class Plugin
{
    public function __construct(private readonly ConnectionRepository $connections = new ConnectionRepository())
    {
    }

    /**
     * The settings LibreNMS stores for the plugin, merged with the defaults.
     */
    public static function settings(): PluginSettings
    {
        try {
            return new PluginSettings(app(PluginManagerInterface::class)->getSettings(AnsibleServiceProvider::PLUGIN_NAME));
        } catch (\Throwable) {
            return new PluginSettings();
        }
    }

    /**
     * The runner connection the plugin stored.
     */
    public function connection(): Connection
    {
        return $this->connections->get();
    }

    /**
     * Why the stored connection cannot be read, null when it can.
     */
    public function connectionError(): ?string
    {
        return $this->connections->error();
    }

    /**
     * The runner of a connection (the stored one by default); RunnerError when it is not set up.
     */
    public function runner(?Connection $connection = null): Runner
    {
        $connection ??= $this->connection();
        if (! $connection->complete()) {
            throw new RunnerError(RunnerError::NOT_CONFIGURED, $this->connections->error()
                ?? 'The runner connection is not set up: enter the Semaphore UI URL, token, project and inventory in Plugin Admin → ansible.');
        }
        if ($connection->verifyTls && $connection->caBundle !== '' && ! is_readable($connection->caBundle)) {
            throw new RunnerError(RunnerError::NOT_CONFIGURED, "LibreNMS cannot read the CA bundle {$connection->caBundle}: fix the path or its permissions.");
        }

        return new SemaphoreRunner(new CurlTransport($connection->timeout, $connection->verifyTls, $connection->caBundle ?: null),
            $connection->url, $connection->token, $connection->project);
    }
}
