<?php

namespace Agoodley\LibrenmsAnsible\Data;

use Agoodley\LibrenmsAnsible\Support\Connection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The runner connection (Support\Connection), stored by the plugin in ansible_config, encrypted with Laravel's
 * Crypt (the LibreNMS APP_KEY): the token never sits in the database in clear, and LibreNMS' plugin settings
 * form, which stores what it is posted, never sees it.
 */
final class ConnectionRepository
{
    private const NAME = 'connection';

    /** Why the stored connection could not be read (APP_KEY changed, table missing), null when it could. */
    private ?string $error = null;

    /**
     * The stored connection; an empty one when there is none or it cannot be decrypted.
     */
    public function get(): Connection
    {
        $this->error = null;
        try {
            if (! Schema::hasTable('ansible_config')) {
                $this->error = 'The plugin\'s tables are missing: run ./lnms migrate.';

                return new Connection();
            }
            $value = DB::table('ansible_config')->where('name', self::NAME)->value('value');
            if ($value === null) {
                return new Connection();
            }

            return Connection::fromArray((array) json_decode(Crypt::decryptString((string) $value), true));
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            $this->error = 'The saved runner connection cannot be decrypted (was the LibreNMS APP_KEY changed?): save it again.';
        } catch (\Throwable $e) {
            Log::error('ansible plugin: cannot read the runner connection: ' . \Agoodley\LibrenmsAnsible\Support\ErrorText::forLog($e));
            $this->error = 'The runner connection cannot be read: see logs/librenms.log.';
        }

        return new Connection();
    }

    /**
     * Why get() returned an empty connection, if it could not read the stored one.
     */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * Store the connection, encrypted.
     */
    public function save(Connection $connection): void
    {
        DB::table('ansible_config')->updateOrInsert(['name' => self::NAME],
            ['value' => Crypt::encryptString((string) json_encode($connection->toArray())), 'updated_at' => now()]);
    }
}
