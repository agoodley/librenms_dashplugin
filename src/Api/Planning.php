<?php

namespace Agoodley\LibrenmsTopology\Api;

use Agoodley\LibrenmsTopology\Data\DependencyRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Redundancy\RolloutPlanner;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Support\RoleData;
use Agoodley\LibrenmsTopology\Topology\PlanningService;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;

/**
 * The planning API of the topology plugin, for other LibreNMS plugins (the Ansible plugin plans its changes with it).
 * On the network as it is now, with the core and the links of the redundancy page:
 *
 *  - rollout(): the rollout plan of a set of devices in batches of at most a given size, as api/rollout plans picked
 *    devices: farthest from the core first, the core last, never two devices that back each other up in one batch;
 *    per batch its tier, its devices, the monitored devices it cuts off and the unmanaged neighbors that go down with
 *    it; and the devices it cannot plan, with why;
 *  - blastRadius(): what a set of devices going down together cuts off, as api/simulate fails them on the L2 map: the
 *    monitored devices that lose their path to the core and the unmanaged neighbors that go down.
 *
 * Both are scoped as the plugin's pages are. forUser() sees what that LibreNMS user may see (LibreNMS' device
 * permissions, and its role's permissions for LLDP/CDP links and ports, which role_hidden names when they hide
 * something); system() sees every enabled device, for console commands only (like the topology history), and is
 * refused anywhere else. A device outside the scope is "unknown": never planned, failed or named. The topology
 * plugin must be enabled in LibreNMS: forUser() and system() throw PlanningUnavailable when it is not.
 *
 * The API is stable. VERSION's major number changes only when a method or a field changes incompatibly, its minor
 * number when one is added; a caller checks that the class exists (the plugin is installed) and VERSION's major number
 * before using it. One instance builds the redundancy analysis once: plan and compute blast radii with the same one
 * while the network is the same, and make a new one to see the network as it is later.
 */
final class Planning
{
    /** Version of this API ("major.minor"). */
    public const VERSION = '1.0';

    /** Most devices one call takes (more: \InvalidArgumentException). */
    public const MAX_DEVICES = 5000;

    /** Most devices a link to the L2 map's failure simulation fails (the map's own limit). */
    public const SIMULATE_LIMIT = PlanningService::SIMULATE_LIMIT;

    /** Tier of the core devices; a batch's tier is otherwise its hops from the core (1: cabled to a core device). */
    public const CORE = RolloutPlanner::CORE;

    /** Why a device cannot be planned: unknown to the topology (or disabled, or out of the scope's sight), down now, without any LLDP/CDP neighbor, without a path to the core now. */
    public const UNKNOWN = RolloutPlanner::UNKNOWN;

    public const DOWN = RolloutPlanner::DOWN;

    public const ISOLATED = RolloutPlanner::ISOLATED;

    public const UNCONNECTED = RolloutPlanner::UNCONNECTED;

    /** The reasons, as the plugin's pages say them. */
    public const REASONS = [
        self::UNKNOWN => 'unknown, disabled or not visible',
        self::DOWN => 'down now',
        self::ISOLATED => 'no LLDP/CDP neighbors: no topology data',
        self::UNCONNECTED => 'no path to the core now',
    ];

    /** The plugin's name and the base of its pages' paths in LibreNMS (TopologyServiceProvider's, which extends a Laravel class). */
    private const PLUGIN = 'topology';

    private const URI_PREFIX = 'plugin/topology';

    /** Why the API cannot be used while the plugin is disabled. */
    private const DISABLED = 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)';

    private function __construct(private readonly PlanningService $planning, private readonly TopologyRepository $repo)
    {
    }

    /**
     * The API as a LibreNMS user sees the network (as the plugin's pages show it to that user).
     *
     * @param  object  $user  the LibreNMS user (App\Models\User) the caller acts for
     *
     * @throws PlanningUnavailable when the topology plugin is disabled
     */
    public static function forUser(object $user): self
    {
        return self::open(new TopologyRepository($user), new DependencyRepository($user));
    }

    /**
     * The API on every enabled device, for console commands that act for nobody (as the topology history does).
     *
     * @throws \LogicException outside the console: no web request can widen what it sees this way
     * @throws PlanningUnavailable when the topology plugin is disabled
     */
    public static function system(): self
    {
        return self::open(TopologyRepository::system(), DependencyRepository::system());
    }

    /**
     * The API on a repository and settings of the caller's own, with no check that the plugin is enabled.
     *
     * @internal for the plugin's tests
     */
    public static function withRepository(TopologyRepository $repo, PluginSettings $settings): self
    {
        return new self(new PlanningService($repo, $repo->sibling(DependencyRepository::class), $settings), $repo);
    }

    /**
     * Why the API cannot be used now (the topology plugin is disabled in LibreNMS), null when it can.
     */
    public static function unavailable(): ?string
    {
        try {
            $manager = self::manager();
        } catch (PlanningUnavailable $e) {
            return $e->getMessage();
        }

        return $manager->pluginEnabled(self::PLUGIN) ? null : self::DISABLED;
    }

    /**
     * The rollout plan of the devices, in batches of at most $batchSize devices (0: no limit).
     *
     * @param  int[]  $deviceIds  the devices to plan; one listed twice counts once, one outside the scope is skipped (unknown)
     * @return array{version: string, batches: list<array{number: int, tier: int|string, devices: int[], lost: int[], network: int, unmanaged: int,
     *     reference: string, simulate: ?string}>, skipped: list<array{device_id: int, reason: string, text: string}>, core: int[],
     *     names: array<int, string>, role_hidden: string[]}
     *     batches in the order to run them: tier (hops from the core, or CORE), devices, lost (the monitored devices it cuts off
     *     the core, switches, routers and firewalls first), network (how many of them are switches, routers or firewalls),
     *     unmanaged (how many unmanaged neighbors go down with it), reference ('core', or 'largest part' when it takes down
     *     every core device of its part of the network: the largest piece left carries on), simulate (the path of the L2 map
     *     with the batch failed, relative to LibreNMS' URL; null for more than SIMULATE_LIMIT devices); skipped: the devices
     *     it cannot plan, with the reason (UNKNOWN, DOWN, ISOLATED or UNCONNECTED) and its text; core: the core
     *     devices; names: the name of every device of the answer the scope sees; role_hidden: what the user's LibreNMS role
     *     may not view of the data the plan reads (LLDP/CDP links, ports): with any, the plan is incomplete
     */
    public function rollout(array $deviceIds, int $batchSize = 0): array
    {
        $deviceIds = self::ids($deviceIds);
        $answer = ['version' => self::VERSION, 'batches' => [], 'skipped' => [], 'core' => [], 'names' => [], 'role_hidden' => $this->roleHidden()];
        if (! $deviceIds) {
            return $answer; // never the whole network: the planner plans every device of its filter without picked ones
        }
        $plan = $this->planning->rollout(['group' => null, 'location' => null], $deviceIds, max(0, $batchSize), false);
        $named = [];
        foreach ($plan['batches'] as $index => $batch) {
            $devices = array_map('intval', $batch['devices']);
            $lost = array_map('intval', $batch['lost']);
            $answer['batches'][] = ['number' => $index + 1, 'tier' => $batch['tier'] === RolloutPlanner::CORE ? self::CORE : (int) $batch['tier'], 'devices' => $devices,
                'lost' => $lost, 'network' => (int) $batch['network'], 'unmanaged' => (int) $batch['unmanaged'], 'reference' => (string) $batch['reference'],
                'simulate' => self::simulatePath($devices)];
            array_push($named, ...$devices, ...$lost);
        }
        foreach ($plan['skipped'] as $skipped) {
            $answer['skipped'][] = self::skipped((int) $skipped['device_id'], (string) $skipped['reason']);
            $named[] = (int) $skipped['device_id'];
        }
        $answer['core'] = array_values(array_filter(array_map('intval', $plan['core']), fn (int $id) => $this->repo->canSee($id)));
        $answer['names'] = $this->names([...$named, ...$answer['core']]);

        return $answer;
    }

    /**
     * The blast radius of the devices going down together, on top of what is down already: the monitored devices that
     * lose their path to the core while they are down, and the unmanaged neighbors that go down with them.
     *
     * @param  int[]  $deviceIds  the devices that go down; one outside the scope is not failed (skipped, unknown)
     * @return array{version: string, devices: int[], lost: int[], network: int, unmanaged: int, reference: string,
     *     skipped: list<array{device_id: int, reason: string, text: string}>, names: array<int, string>, simulate: ?string, role_hidden: string[]}
     *     devices: those failed (the given ones the scope sees); lost, network, unmanaged and reference as for a batch of
     *     rollout(); skipped: the given devices the topology cannot tell anything about now (as rollout() skips them: what
     *     depends on them is not known, so it is not in lost); names, simulate and role_hidden as for rollout()
     */
    public function blastRadius(array $deviceIds): array
    {
        $deviceIds = self::ids($deviceIds);
        $failed = array_values(array_filter($deviceIds, fn (int $id) => $this->repo->canSee($id)));
        $answer = ['version' => self::VERSION, 'devices' => $failed, 'lost' => [], 'network' => 0, 'unmanaged' => 0, 'reference' => 'core', 'skipped' => [],
            'names' => [], 'simulate' => self::simulatePath($failed), 'role_hidden' => $this->roleHidden()];
        if (! $deviceIds) {
            return $answer;
        }
        if ($failed) {
            $simulated = $this->planning->simulate(array_map(fn (int $id) => 'd:' . $id, $failed));
            foreach ($simulated['lost'] as $node) {
                if ($node['device_id'] !== null && ! in_array((int) $node['device_id'], $answer['lost'], true)) {
                    $answer['lost'][] = (int) $node['device_id'];
                }
            }
            $answer['network'] = (int) $simulated['network'];
            $answer['unmanaged'] = (int) $simulated['unmanaged'];
            $answer['reference'] = (string) $simulated['reference'];
        }
        $reasons = $this->planning->unplannable($failed);
        foreach ($deviceIds as $deviceId) {
            $reason = in_array($deviceId, $failed, true) ? ($reasons[$deviceId] ?? null) : self::UNKNOWN;
            if ($reason !== null) {
                $answer['skipped'][] = self::skipped($deviceId, $reason);
            }
        }
        $answer['names'] = $this->names([...$failed, ...$answer['lost']]);

        return $answer;
    }

    /**
     * The path of the L2 map with these devices failed (its failure simulation), relative to LibreNMS' URL; null for
     * none or more than SIMULATE_LIMIT devices.
     *
     * @param  int[]  $deviceIds
     */
    public static function simulatePath(array $deviceIds): ?string
    {
        $deviceIds = self::ids($deviceIds);
        if (! $deviceIds || count($deviceIds) > self::SIMULATE_LIMIT) {
            return null;
        }

        return self::URI_PREFIX . '/l2?' . http_build_query(['fail' => implode(',', array_map(fn (int $id) => 'd:' . $id, $deviceIds))]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The API on a repository, once the plugin is known to be enabled, with its stored settings.
     */
    private static function open(TopologyRepository $repo, DependencyRepository $dependencies): self
    {
        $manager = self::manager();
        if (! $manager->pluginEnabled(self::PLUGIN)) {
            throw new PlanningUnavailable(self::DISABLED);
        }
        try {
            $settings = new PluginSettings($manager->getSettings(self::PLUGIN));
        } catch (\Throwable) {
            $settings = new PluginSettings();
        }

        return new self(new PlanningService($repo, $dependencies, $settings), $repo);
    }

    /**
     * LibreNMS' plugin manager.
     */
    private static function manager(): PluginManagerInterface
    {
        try {
            return app(PluginManagerInterface::class);
        } catch (\Throwable $e) {
            throw new PlanningUnavailable('LibreNMS cannot tell whether the topology plugin is enabled: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * What the user's role may not view of the data the plans read (none for the system scope).
     *
     * @return string[]
     */
    private function roleHidden(): array
    {
        return $this->repo->hiddenKinds(RoleData::PAGES['rollout']);
    }

    /**
     * device_id => name of the devices the scope sees; the others are left out.
     *
     * @param  int[]  $ids
     * @return array<int, string>
     */
    private function names(array $ids): array
    {
        return array_map(fn (array $info) => $info['name'], $this->planning->names(array_values(array_unique($ids))));
    }

    /**
     * A device that cannot be planned, with the reason and its text.
     *
     * @return array{device_id: int, reason: string, text: string}
     */
    private static function skipped(int $deviceId, string $reason): array
    {
        return ['device_id' => $deviceId, 'reason' => $reason, 'text' => self::REASONS[$reason] ?? $reason];
    }

    /**
     * Positive device ids, each once, in the given order; at most MAX_DEVICES.
     *
     * @param  array<int, mixed>  $ids
     * @return int[]
     *
     * @throws \InvalidArgumentException for more than MAX_DEVICES
     */
    private static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($ids, 'is_scalar')), fn (int $id) => $id > 0)));
        if (count($ids) > self::MAX_DEVICES) {
            throw new \InvalidArgumentException('at most ' . self::MAX_DEVICES . ' devices per call');
        }

        return $ids;
    }
}
