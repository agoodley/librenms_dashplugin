<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Changes\Topology;
use Agoodley\LibrenmsAnsible\Changes\TopologyError;
use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Support\ErrorText;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The topology plugin's planning API (Agoodley\LibrenmsTopology\Api\Planning, version 1.x), when the plugin is installed,
 * enabled in LibreNMS and offers it: the plugin never reaches into the topology plugin's other classes. Each call sees the
 * network as a LibreNMS user sees it (Planning::forUser(), scoped as the topology pages): the admin who makes the change,
 * or the one who starts the batch, also when the sync command starts it. One analysis of the network per user and
 * instance: the plugin makes an instance per request, so a plan and the blast radii of its batches are computed on the
 * same network, and each later check on the network as it is then.
 */
final class TopologyPlanning implements Topology
{
    /** The class of the topology plugin's planning API. */
    public const API = 'Agoodley\\LibrenmsTopology\\Api\\Planning';

    /** The major version of the planning API this plugin speaks. */
    public const MAJOR = 1;

    /** @var array<int, object> user id => the planning API as that user sees the network now */
    private array $planning = [];

    public function unavailable(): ?string
    {
        if (! class_exists(self::API)) {
            return 'the topology plugin is not installed';
        }
        $version = (string) constant(self::API . '::VERSION');
        if ((int) $version !== self::MAJOR) {
            return 'the topology plugin\'s planning API is version ' . UntrustedText::line($version, 20) . ', and this plugin speaks version ' . self::MAJOR
                . '.x: update both plugins';
        }
        try {
            $why = (self::API)::unavailable();
        } catch (\Throwable $e) {
            Log::error('ansible plugin: the topology plugin cannot say whether it is enabled: ' . ErrorText::forLog($e));

            return 'the topology plugin cannot say whether it is enabled (see logs/librenms.log)';
        }

        return is_string($why) ? UntrustedText::line($why, 300) : null;
    }

    public function rollout(array $deviceIds, int $batchSize, int $userId): array
    {
        return $this->call($userId, 'plan the batches', fn (object $planning) => $planning->rollout(array_values(array_map('intval', $deviceIds)), max(0, $batchSize)));
    }

    public function blastRadius(array $deviceIds, int $userId): array
    {
        return $this->call($userId, 'compute the blast radius', fn (object $planning) => $planning->blastRadius(array_values(array_map('intval', $deviceIds))));
    }

    /**
     * Ask the planning API as the user sees the network; TopologyError with why when it cannot answer, or answers
     * without the data it needs (the user's role may not view the LLDP/CDP links or the ports).
     *
     * @param  \Closure(object): mixed  $work
     */
    private function call(int $userId, string $what, \Closure $work): array
    {
        $why = $this->unavailable();
        if ($why !== null) {
            throw new TopologyError($why);
        }
        try {
            $planning = $this->planning[$userId] ??= (self::API)::forUser($this->user($userId));
            $answer = $work($planning);
        } catch (TopologyError $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("ansible plugin: the topology plugin could not $what: " . ErrorText::forLog($e));
            // the API's own refusals (disabled meanwhile) say what to do; anything else is in LibreNMS' log
            throw new TopologyError(is_a($e, 'Agoodley\\LibrenmsTopology\\Api\\PlanningUnavailable') ? UntrustedText::line($e->getMessage(), 300)
                : "the topology plugin could not $what (see logs/librenms.log)", 0, $e);
        }
        if (! is_array($answer)) {
            throw new TopologyError("the topology plugin could not $what: its answer is not one");
        }
        $hidden = array_values(array_filter((array) ($answer['role_hidden'] ?? []), 'is_string'));
        if ($hidden) {
            throw new TopologyError('the LibreNMS role of ' . UntrustedText::line((string) $this->user($userId)->username, 60) . ' may not view '
                . UntrustedText::line(implode(', ', $hidden), 200) . ', so the topology plugin cannot tell what the devices cut off');
        }

        return $answer;
    }

    /**
     * The LibreNMS user whose view of the network a call takes.
     */
    private function user(int $userId): object
    {
        $user = $userId > 0 ? User::find($userId) : null;
        if ($user === null) {
            throw new TopologyError("the LibreNMS user #$userId the topology is computed for no longer exists");
        }

        return $user;
    }
}
