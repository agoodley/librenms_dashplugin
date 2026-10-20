<?php

namespace Agoodley\LibrenmsTopology\Hosts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Process\ExecutableFinder;
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
     * @param  string  $user  rate limit key of the user
     */
    public function __construct(private readonly string $user = '')
    {
    }

    /**
     * @return string[]|null IP addresses (none when the name does not resolve), null when the user made too many lookups
     */
    public function __invoke(string $name): ?array
    {
        if (! self::plausible($name)) {
            return [];
        }

        $key = 'topology-plugin:dns:' . sha1($name);
        try {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
            $limit = 'topology-plugin:dns-user:' . $this->user;
            if (RateLimiter::tooManyAttempts($limit, self::PER_MINUTE)) {
                return null;
            }
            RateLimiter::hit($limit, 60);
        } catch (\Throwable $e) {
            Log::debug('topology plugin: no cache for DNS lookups: ' . $e->getMessage());
        }

        $ips = self::lookup($name);
        try {
            Cache::put($key, $ips, self::CACHE_SECONDS);
        } catch (\Throwable) {
            // not cached
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
     * A and AAAA addresses of a name with getent (glibc ahosts, else hosts as on musl), or none when it
     * does not resolve within the timeout.
     *
     * @return string[]
     */
    public static function lookup(string $name, float $timeout = self::TIMEOUT): array
    {
        $getent = class_exists(ExecutableFinder::class) ? (new ExecutableFinder())->find('getent', null, ['/usr/bin', '/bin', '/usr/local/bin']) : null;
        if ($getent === null) {
            Log::debug('topology plugin: getent not found, no DNS lookups');

            return [];
        }

        foreach (['ahosts', 'hosts'] as $database) {
            $process = new Process([$getent, $database, $name]);
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
            if ($process->getExitCode() === 0) {
                return self::parse($process->getOutput());
            }
            if ($process->getExitCode() !== 1) {
                return []; // 2: not found
            }
            // 1: this getent does not know the database
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
