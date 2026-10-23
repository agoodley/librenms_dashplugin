<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Changes\LockLost;
use Agoodley\LibrenmsAnsible\Changes\TopologyError;
use Agoodley\LibrenmsAnsible\Changes\Verifier;
use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Support\ErrorText;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The topology plugin's verification API (Agoodley\LibrenmsTopology\Api\Verification, version 1.x), when the plugin is
 * installed, enabled and offers it: the plugin never reaches into the topology plugin's other classes. Each call sees the
 * network as a LibreNMS user sees it (Verification::forUser()).
 *
 * A scope and a state are taken in a process of their own (`artisan ansible:state`, Console\StateCommand), as the topology
 * plugin runs each background check in its own: on a large network a state takes a few hundred MB for some seconds, which
 * never stays in the sync command's process. Only the sync command takes them ($takesStates); the freshness of devices
 * (one query) and the comparison of two states (pure) are asked in the process itself.
 */
final class TopologyVerification implements Verifier
{
    /** The class of the topology plugin's verification API. */
    public const API = 'Agoodley\\LibrenmsTopology\\Api\\Verification';

    /** The class of its planning API: there before the verification API was (a topology plugin too old for it). */
    public const PLANNING = TopologyPlanning::API;

    /** The major version of the verification API this plugin speaks. */
    public const MAJOR = 1;

    /** Seconds a scope or a state may take in its process (a state of the large test network takes about 16). */
    public const TIMEOUT = 600;

    private ?\Closure $heartbeat = null;

    /**
     * @param  bool  $takesStates  the sync command: it takes scopes and states (in a process of their own)
     * @param  string  $api  the class of the verification API (API; another in the tests)
     * @param  (\Closure(array): array)|null  $child  takes a scope or a state for a request ({what, user_id, devices, cover}) in a process of
     *                                               its own (the artisan command; another in the tests)
     */
    public function __construct(private readonly bool $takesStates = false, private readonly string $api = self::API, private readonly ?\Closure $child = null)
    {
    }

    public function unavailable(): ?string
    {
        if (! class_exists($this->api)) {
            return class_exists(self::PLANNING) ? 'the topology plugin is too old for the verification API this plugin needs: update both plugins together '
                . '(this plugin\'s README, Upgrade from phase 3)' : 'the topology plugin is not installed';
        }
        $version = (string) constant($this->api . '::VERSION');
        if ((int) $version !== self::MAJOR) {
            return 'the topology plugin\'s verification API is version ' . UntrustedText::line($version, 20) . ', and this plugin speaks version ' . self::MAJOR
                . '.x: update both plugins';
        }
        try {
            $why = ($this->api)::unavailable();
        } catch (\Throwable $e) {
            Log::error('ansible plugin: the topology plugin cannot say whether it is enabled: ' . ErrorText::forLog($e));

            return 'the topology plugin cannot say whether it is enabled (see logs/librenms.log)';
        }

        return is_string($why) ? UntrustedText::line($why, 300) : null;
    }

    public function takesStates(): bool
    {
        return $this->takesStates;
    }

    public function setHeartbeat(?\Closure $heartbeat): void
    {
        $this->heartbeat = $heartbeat;
    }

    public function freshness(array $deviceIds, int $userId): array
    {
        $answer = $this->call('read how fresh LibreNMS\' data is', fn () => ($this->api)::forUser(self::user($userId))->freshness(self::ids($deviceIds)));
        $states = [];
        foreach ((array) ($answer['devices'] ?? []) as $deviceId => $state) {
            if (is_array($state) && preg_match('/^\d{1,10}$/D', (string) $deviceId)) {
                $states[(int) $deviceId] = ['up' => ! empty($state['up']), 'down_since' => self::time($state['down_since'] ?? null),
                    'discovered_at' => self::time($state['discovered_at'] ?? null), 'polled_at' => self::time($state['polled_at'] ?? null),
                    'discovery_pending' => ! empty($state['discovery_pending'])];
            }
        }

        return $states;
    }

    public function scope(array $deviceIds, int $userId): array
    {
        return $this->taken(['what' => 'scope', 'user_id' => $userId, 'devices' => self::ids($deviceIds), 'cover' => []], 'compute the scope of the batch');
    }

    public function state(array $deviceIds, int $userId, array $cover = []): array
    {
        $state = $this->taken(['what' => 'state', 'user_id' => $userId, 'devices' => self::ids($deviceIds),
            'cover' => ['include' => self::ids((array) ($cover['include'] ?? [])), 'domain' => self::ids((array) ($cover['domain'] ?? []))]], 'take the state of the batch');
        if ((int) ($state['version'] ?? 0) !== self::MAJOR || ! is_int($state['taken_at'] ?? null)) {
            throw new TopologyError('the topology plugin\'s state is not one of its verification API ' . self::MAJOR . '.x');
        }
        $hidden = array_values(array_filter((array) ($state['role_hidden'] ?? []), 'is_string'));
        if ($hidden) {
            throw new TopologyError('the LibreNMS role of the admin who starts the batch may not view ' . UntrustedText::line(implode(', ', $hidden), 200)
                . ', so the topology plugin cannot verify it');
        }

        return $state;
    }

    /**
     * The findings whose fix is clear, as the user sees the network (the verification API's fixes()): of these devices, every
     * device the user sees when none are given. Asked in the request itself (the Fixes page and a new change from fixes).
     *
     * @param  int[]  $deviceIds
     * @return array<string, mixed>
     *
     * @throws TopologyError
     */
    public function fixes(array $deviceIds, int $userId): array
    {
        return $this->call('list the fixes', fn () => ($this->api)::forUser(self::user($userId))->fixes(self::ids($deviceIds)));
    }

    public function compare(array $before, array $after): array
    {
        return $this->call('compare the states of the batch', fn () => ($this->api)::system()->compare($before, $after));
    }

    /**
     * A scope or a state, from a process of its own; TopologyError with why when it cannot be had.
     *
     * @param  array{what: string, user_id: int, devices: int[], cover: array}  $request
     */
    private function taken(array $request, string $what): array
    {
        if (! $this->takesStates) {
            throw new TopologyError('only the sync command takes the states of a batch');
        }
        $why = $this->unavailable();
        if ($why !== null) {
            throw new TopologyError($why);
        }
        $answer = ($this->child ?? fn (array $request) => self::run($request, $this->heartbeat))($request);
        if (isset($answer['error'])) {
            throw new TopologyError('the topology plugin could not ' . $what . ': ' . UntrustedText::line((string) $answer['error'], 300));
        }

        return $answer;
    }

    /**
     * Ask the API in this process; TopologyError with why when it cannot answer.
     */
    private function call(string $what, \Closure $work): array
    {
        $why = $this->unavailable();
        if ($why !== null) {
            throw new TopologyError($why);
        }
        try {
            $answer = $work();
        } catch (TopologyError $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("ansible plugin: the topology plugin could not $what: " . ErrorText::forLog($e));

            throw new TopologyError(is_a($e, 'Agoodley\\LibrenmsTopology\\Api\\PlanningUnavailable') ? UntrustedText::line($e->getMessage(), 300)
                : "the topology plugin could not $what (see logs/librenms.log)", 0, $e);
        }
        if (! is_array($answer)) {
            throw new TopologyError("the topology plugin could not $what: its answer is not one");
        }

        return $answer;
    }

    /**
     * Take a scope or a state in a process of its own: `php artisan ansible:state`, the request on its input, the answer
     * on its output (JSON), stopped after TIMEOUT seconds; the heartbeat is called while it runs.
     *
     * @param  array{what: string, user_id: int, devices: int[], cover: array}  $request
     * @return array<string, mixed> the answer, or {error}
     */
    public static function run(array $request, ?\Closure $heartbeat = null): array
    {
        $artisan = function_exists('base_path') ? base_path('artisan') : 'artisan';
        $process = proc_open([PHP_BINARY, $artisan, 'ansible:state'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            function_exists('base_path') ? base_path() : null);
        if (! is_resource($process)) {
            return ['error' => 'its process could not be started'];
        }
        fwrite($pipes[0], (string) json_encode($request));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $errors = '';
        $start = time();
        $beat = time();
        try {
            while (true) {
                $read = [$pipes[1], $pipes[2]];
                $write = null;
                $except = null;
                if (@stream_select($read, $write, $except, 1) === false) {
                    break;
                }
                foreach ($read as $pipe) {
                    $chunk = (string) fread($pipe, 1 << 16);
                    if ($pipe === $pipes[1]) {
                        $output .= $chunk;
                    } else {
                        $errors .= mb_substr($chunk, 0, 4000);
                    }
                }
                if (feof($pipes[1]) && feof($pipes[2])) {
                    break;
                }
                if (time() - $start > self::TIMEOUT) {
                    proc_terminate($process, 9);

                    return ['error' => 'it took more than ' . self::TIMEOUT . ' seconds'];
                }
                if ($heartbeat !== null && time() - $beat >= 5) {
                    $beat = time();
                    $heartbeat();
                }
            }
        } catch (LockLost $e) {
            proc_terminate($process, 9);

            throw $e;
        } finally {
            foreach ([1, 2] as $index) {
                if (is_resource($pipes[$index])) {
                    fclose($pipes[$index]);
                }
            }
        }
        $code = proc_close($process);
        $answer = json_decode($output, true);
        if (! is_array($answer)) {
            Log::error('ansible plugin: ansible:state ended with code ' . $code . ' and no answer: ' . UntrustedText::line($errors, 1000));

            return ['error' => 'its process ended without an answer (code ' . $code . ', see logs/librenms.log)'];
        }

        return $answer;
    }

    /**
     * The LibreNMS user whose view of the network a call takes.
     */
    private static function user(int $userId): object
    {
        $user = $userId > 0 ? User::find($userId) : null;
        if ($user === null) {
            throw new TopologyError("the LibreNMS user #$userId the batch is verified for no longer exists");
        }

        return $user;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return int[]
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', array_filter($ids, 'is_scalar')), fn (int $id) => $id > 0)));
    }

    private static function time(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }
}
