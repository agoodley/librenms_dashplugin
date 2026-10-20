<?php

namespace Agoodley\LibrenmsTopology\Hosts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Hostname => IP addresses for the host locator, the way the server resolves names (getent: hosts file,
 * DNS), in a subprocess killed after a hard timeout: a resolver's own timeout applies per nameserver,
 * record type and attempt, and PHP cannot change it once a worker has resolved a name. Answers,
 * failures included, are cached for a few minutes, and each user gets a limited number of lookups per
 * minute.
 */
class DnsResolver
{
    /** Seconds before a lookup is given up. */
    public const TIMEOUT = 2.0;

    /** Seconds an answer (or a failure) is cached. */
    public const CACHE_SECONDS = 300;

    /** Lookups per user and minute that are not cached. */
    public const PER_MINUTE = 20;

    /**
     * Where getent may be: fixed paths only, never PATH (php-fpm clears it, and without PATH the working directory
     * would be searched first).
     */
    public const GETENT = ['/usr/bin/getent', '/bin/getent'];

    /**
     * @param  string  $user  rate limit key of the user
     */
    public function __construct(private readonly string $user = '')
    {
    }

    /**
     * @param  float  $timeout  seconds this lookup may take (a caller with several names to resolve passes what is left)
     * @return string[]|string IP addresses (none when the name does not resolve), or why the name was not looked up
     */
    public function __invoke(string $name, float $timeout = self::TIMEOUT): array|string
    {
        if (! self::plausible($name)) {
            return [];
        }
        $getent = self::getent();
        if ($getent === null) {
            return 'DNS lookups are unavailable: getent was not found on the LibreNMS server.';
        }

        $key = 'topology-plugin:dns:' . sha1($name);
        try {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
            $limit = 'topology-plugin:dns-user:' . $this->user;
            if (RateLimiter::tooManyAttempts($limit, self::PER_MINUTE)) {
                return 'Too many DNS lookups: try again in a minute.';
            }
            RateLimiter::hit($limit, 60);
        } catch (\Throwable $e) {
            Log::debug('topology plugin: no cache for DNS lookups: ' . $e->getMessage());
        }

        $timeout = min(self::TIMEOUT, $timeout);
        $ips = self::lookup($name, $timeout, $getent);
        // a failure within a shortened time is not cached
        if ($ips || $timeout >= self::TIMEOUT) {
            try {
                Cache::put($key, $ips, self::CACHE_SECONDS);
            } catch (\Throwable) {
                // not cached
            }
        }

        return $ips;
    }

    /**
     * Names worth asking the resolver for: not reserved for local or special use (.local is multicast DNS,
     * .invalid never resolves) and without underscores (not valid in a hostname).
     */
    public static function plausible(string $name): bool
    {
        $name = strtolower(rtrim($name, '.'));

        return $name !== '' && $name !== 'localhost' && ! str_contains($name, '_')
            && ! preg_match('/\.(local|localhost|invalid|arpa|onion)$/', $name);
    }

    /**
     * The getent binary, from the fixed paths only.
     */
    public static function getent(): ?string
    {
        foreach (self::GETENT as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The getent command line: options end before the name, which may start with "-" (a device hostname).
     *
     * @return string[]
     */
    public static function command(string $getent, string $database, string $name): array
    {
        return [$getent, $database, '--', $name];
    }

    /**
     * A and AAAA addresses of a name with getent (glibc ahosts, else hosts as on musl), or none when it
     * does not resolve within the timeout.
     *
     * @param  string|null  $getent  the binary (default: getent())
     * @return string[]
     */
    public static function lookup(string $name, float $timeout = self::TIMEOUT, ?string $getent = null): array
    {
        $getent ??= self::getent();
        if ($getent === null) {
            return [];
        }

        foreach (['ahosts', 'hosts'] as $database) {
            $process = new Process(self::command($getent, $database, $name));
            $process->setTimeout($timeout);
            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                Log::debug("topology plugin: DNS lookup of $name timed out");

                return [];
            } catch (\Throwable $e) {
                Log::debug('topology plugin: DNS lookup failed: ' . $e->getMessage());

                return [];
            }
            $output = $process->getOutput();
            // 1 with no output: this getent does not know the database; otherwise the addresses it printed (a getent
            // that takes "--" for a key says 2, one key not found, and still prints the name's addresses)
            if ($process->getExitCode() !== 1 || trim($output) !== '') {
                return self::parse($output);
            }
        }

        return [];
    }

    /**
     * Addresses in getent output (address first on every line).
     *
     * @return string[]
     */
    public static function parse(string $output): array
    {
        $ips = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $address = strtok(trim($line), " \t");
            if ($address !== false && filter_var($address, FILTER_VALIDATE_IP)) {
                $ips[] = $address;
            }
        }

        return array_values(array_unique($ips));
    }
}
