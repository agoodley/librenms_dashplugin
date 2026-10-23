<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit {

    use Agoodley\LibrenmsTopology\Api\Planning;
    use Agoodley\LibrenmsTopology\Api\PlanningUnavailable;
    use Agoodley\LibrenmsTopology\Data\HistoryRepository;
    use Agoodley\LibrenmsTopology\Data\ScopedRepository;
    use Agoodley\LibrenmsTopology\Data\TopologyRepository;
    use Agoodley\LibrenmsTopology\Redundancy\RolloutPlanner;
    use Agoodley\LibrenmsTopology\Support\PluginSettings;
    use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
    use PHPUnit\Framework\TestCase;

    /**
     * The planning API for other plugins (Api\Planning): the data of api/rollout and api/simulate, scoped as the pages
     * are (a device the user may not see is unknown: never planned, failed or named), its system scope for console
     * commands only, and refused while the plugin is disabled.
     *
     * The network: the campus of RedundancyFixture (core1 and core2, dist1 and dist2 cabled to both, acc1 and acc2
     * dual-homed to the dist switches, ap1 behind acc1) and acc3, single-homed to dist1, with a phone.
     */
    class PlanningApiTest extends TestCase
    {
        private const C1 = RedundancyFixture::CORE1;
        private const C2 = RedundancyFixture::CORE2;
        private const D1 = RedundancyFixture::DIST1;
        private const D2 = RedundancyFixture::DIST2;
        private const A1 = RedundancyFixture::ACC1;
        private const A2 = RedundancyFixture::ACC2;
        private const AP = RedundancyFixture::AP1;
        private const A3 = 8;

        protected function setUp(): void
        {
            FakeLaravel::$console = true;
            FakeLaravel::$enabled = true;
        }

        private static function network(): RedundancyFixture
        {
            $net = RedundancyFixture::campus();
            $net->device(self::A3, 'acc3')->link(self::A3, 'Gi1/0/49', self::D1, 'Gi1/0/5')->neighbor(self::A3, 'Gi1/0/3', 'SEP00AB11CD22EF', 'Cisco IP Phone 8841');

            return $net;
        }

        /**
         * The API on the network as a user who may see $visible (null: every device) sees it, through a repository that
         * reads the fixture the way TopologyRepository reads LibreNMS: only the visible devices' links, a link to a device
         * the user may not see as an unmanaged neighbor.
         *
         * @param  int[]|null  $visible
         * @param  string[]  $denied  model classes the user's role may not view
         */
        private static function api(RedundancyFixture $net, ?array $visible = null, array $denied = [], string $core = 'core1 core2', array $unseen = []): Planning
        {
            return Planning::withRepository(self::repository($net, $visible, $denied, $unseen), new PluginSettings(['redundancy_core' => $core]));
        }

        /**
         * @param  int[]  $unseen  devices the scope's own check (canSee()) refuses although the analysis was handed them: what the API
         *                         answers is filtered by that check, whatever the analysis it used
         */
        private static function repository(RedundancyFixture $net, ?array $visible, array $denied, array $unseen = []): TopologyRepository
        {
            $history = new class(null) extends HistoryRepository
            {
                public function missingBaselineLinks(array $ids): array
                {
                    return [];
                }
            };

            return new class(null, $net, $visible ?? array_keys($net->devices), $denied, $history, $unseen) extends TopologyRepository
            {
                public function __construct(mixed $user, private readonly RedundancyFixture $net, private readonly array $visible, private readonly array $denied,
                    private readonly HistoryRepository $history, private readonly array $unseen)
                {
                    parent::__construct($user);
                }

                public function sibling(string $class): ScopedRepository
                {
                    return $class === HistoryRepository::class ? $this->history : parent::sibling($class);
                }

                protected function policyAllows(string $modelClass): bool
                {
                    return ! in_array($modelClass, $this->denied, true);
                }

                public function deviceIds(?int $groupId = null, ?int $locationId = null): array
                {
                    return array_values(array_intersect(array_keys($this->net->devices), $this->visible));
                }

                public function canSee(int $deviceId): bool
                {
                    return in_array($deviceId, $this->deviceIds(), true) && ! in_array($deviceId, $this->unseen, true);
                }

                /** As TopologyRepository::devices(): the devices asked for, whoever may see them (its callers scope). */
                public function devices(array $ids, bool $roles = true): array
                {
                    return array_intersect_key($this->net->devices, array_flip(array_map('intval', $ids)));
                }

                public function linksWhere(array $ids, callable $keep): array
                {
                    if (! $this->allowsTable('links')) {
                        return [];
                    }
                    $links = [];
                    foreach ($this->net->links as $position => $link) {
                        if (! in_array($link['local_device_id'], $ids, true)) {
                            continue;
                        }
                        if ($link['remote_device_id'] && ! $this->canSee((int) $link['remote_device_id'])) {
                            // as LLDP reported it: its name, no device
                            $link['remote_hostname'] = $this->net->devices[$link['remote_device_id']]['hostname'];
                            $link['remote_device_id'] = 0;
                            $link['remote_port_id'] = null;
                        }
                        if ($keep($link)) {
                            $links[$position] = $link;
                        }
                    }

                    return $links;
                }

                public function ports(array $portIds, ?array $columns = null): array
                {
                    return array_intersect_key($this->net->ports, array_flip($portIds));
                }

                public function lagParents(array $ids, bool $strict = false): array
                {
                    return $this->net->lag;
                }

                public function stpPriorities(array $ids): array
                {
                    return [];
                }
            };
        }

        // ------------------------------------------------------------------ the data of api/rollout and api/simulate

        public function testARolloutPlanIsTheRolloutPlannersAsApiRolloutGivesIt(): void
        {
            $net = self::network();
            $plan = self::api($net)->rollout([self::C1, self::C2, self::D1, self::D2, self::A1, self::A2, self::A3, self::A1], 2);

            $this->assertSame(Planning::VERSION, $plan['version']);
            $this->assertSame([[self::A1, self::A2], [self::A3], [self::D1], [self::D2], [self::C1], [self::C2]], array_column($plan['batches'], 'devices'),
                'the farthest first, at most 2 devices a batch, the redundant pairs split, the core last');
            $this->assertSame([1, 2, 3, 4, 5, 6], array_column($plan['batches'], 'number'));
            $this->assertSame([2, 2, 1, 1, Planning::CORE, Planning::CORE], array_column($plan['batches'], 'tier'), 'hops from the core');
            $this->assertSame([[self::AP], [], [self::A3], [], [], []], array_column($plan['batches'], 'lost'), 'dist1 cuts off acc3, acc1 the access point');
            $this->assertSame([0, 0, 1, 0, 0, 0], array_column($plan['batches'], 'network'));
            $this->assertSame([0, 1, 1, 0, 0, 0], array_column($plan['batches'], 'unmanaged'), 'the phone behind acc3 goes down with it and with dist1');
            $this->assertSame('plugin/topology/l2?fail=d%3A5%2Cd%3A6', $plan['batches'][0]['simulate'], 'the L2 map with the batch failed');
            $this->assertSame([], $plan['skipped']);
            $this->assertSame([self::C1, self::C2], $plan['core']);
            $this->assertSame([self::C1 => 'configured', self::C2 => 'configured'], $plan['core_modes'], 'from the Core devices setting');
            $this->assertSame('acc3', $plan['names'][self::A3]);
            $this->assertSame('ap1', $plan['names'][self::AP], 'the devices it cuts off are named too');
            $this->assertSame([], $plan['role_hidden']);
        }

        public function testTheCoreSaysHowItWasChosen(): void
        {
            // without Core devices (nor designated root bridges or a spanning tree root) the core is a guess: the most central switches
            $plan = self::api(self::network(), null, [], '')->rollout([self::D1, self::A1]);
            $this->assertNotSame([], $plan['core']);
            $this->assertSame(array_fill_keys($plan['core'], 'automatic'), $plan['core_modes']);

            // another part of the network, with its own core: core names it, core_modes only the core of the planned devices' part
            $net = self::network();
            $net->device(40, 'branch1')->device(41, 'branch2')->link(40, 'Gi0/1', 41, 'Gi0/1');
            $plan = self::api($net)->rollout([self::D1, self::A1]);
            $this->assertSame([self::C1 => 'configured', self::C2 => 'configured'], $plan['core_modes'], 'the core their plan is measured from');
            $this->assertNotEmpty(array_intersect([40, 41], $plan['core']), 'the branch\'s own core is in core');
            $this->assertSame([self::C1, self::C2], array_values(array_intersect($plan['core'], [self::C1, self::C2])));
            $branch = self::api($net)->rollout([41]);
            $this->assertSame(['automatic'], array_values(array_unique($branch['core_modes'])), 'a branch without a configured core: a guess');
            $this->assertSame([], array_intersect(array_keys($branch['core_modes']), [self::C1, self::C2]));
        }

        public function testABlastRadiusIsTheFailureSimulation(): void
        {
            $net = self::network();
            $api = self::api($net);

            $dist1 = $api->blastRadius([self::D1]);
            $this->assertSame([self::D1], $dist1['devices']);
            $this->assertSame([self::A3], $dist1['lost'], 'acc3 hangs off dist1 alone');
            $this->assertSame([1, 1, 'core'], [$dist1['network'], $dist1['unmanaged'], $dist1['reference']]);
            $this->assertSame([], $dist1['skipped']);
            $this->assertSame([self::D1 => 'dist1', self::A3 => 'acc3'], $dist1['names']);
            $this->assertSame('plugin/topology/l2?fail=d%3A3', $dist1['simulate']);

            $pair = $api->blastRadius([self::D1, self::D2]);
            $this->assertSame([self::A1, self::A2, self::A3, self::AP], $pair['lost'], 'a redundant pair down together cuts off everything behind it, switches first');
            $this->assertSame(3, $pair['network']);
            $simulated = $net->analyzer()->simulate(['d:' . self::D1, 'd:' . self::D2]);
            $this->assertSame(array_values(array_filter(array_column($simulated['lost'], 'device_id'))), $pair['lost'], 'as api/simulate');
            $this->assertSame($simulated['unmanaged'], $pair['unmanaged']);

            $this->assertSame([], $api->blastRadius([self::A2])['lost'], 'an access switch cuts off nothing');
            $core = $api->blastRadius([self::C1, self::C2]);
            $this->assertSame('largest part', $core['reference'], 'every core device down: counted from the largest piece left, as the simulation does');
        }

        public function testDevicesTheTopologyCannotTellAboutAreSkippedWithWhy(): void
        {
            $net = self::network();
            $net->devices[self::A2]['status'] = 0;
            $net->device(11, 'lonely-sw'); // no LLDP/CDP neighbor
            $net->device(12, 'cut-sw')->link(12, 'Gi1/0/49', self::D2, 'Gi1/0/7')->setPort(12, 'Gi1/0/49', ['ifOperStatus' => 'down']);
            $api = self::api($net);

            $expected = [
                ['device_id' => self::A2, 'reason' => RolloutPlanner::DOWN, 'text' => 'down now'],
                ['device_id' => 11, 'reason' => RolloutPlanner::ISOLATED, 'text' => 'no LLDP/CDP neighbors: no topology data'],
                ['device_id' => 12, 'reason' => RolloutPlanner::UNCONNECTED, 'text' => 'no path to the core now'],
                ['device_id' => 999, 'reason' => RolloutPlanner::UNKNOWN, 'text' => 'unknown, disabled or not visible'],
            ];
            $plan = $api->rollout([self::A1, self::A2, 11, 12, 999]);
            $this->assertSame([[self::A1]], array_column($plan['batches'], 'devices'));
            $this->assertSame($expected, $plan['skipped']);
            $this->assertArrayNotHasKey(999, $plan['names']);

            $blast = $api->blastRadius([self::A2, 11, 12, 999]);
            $this->assertSame($expected, $blast['skipped'], 'nothing tells what depends on them');
            $this->assertSame([self::A2, 11, 12], $blast['devices'], 'an unknown device is not failed');
            $this->assertSame([], $blast['lost']);
        }

        public function testNoDevicesIsNoPlanNeverTheWholeNetwork(): void
        {
            $api = self::api(self::network());

            $this->assertSame([], $api->rollout([])['batches'], 'the planner plans every device of its filter without picked ones: the API never does');
            $this->assertSame([], $api->rollout([0, -3, 'x'])['batches']);
            $this->assertSame([], $api->blastRadius([])['lost']);
            $this->assertNull(Planning::simulatePath([]));
        }

        public function testMoreDevicesThanAllowedAreRefused(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            self::api(self::network())->blastRadius(range(1, Planning::MAX_DEVICES + 1));
        }

        public function testASimulationLinkFailsAtMostWhatTheMapTakes(): void
        {
            $this->assertSame('plugin/topology/l2?fail=d%3A12%2Cd%3A7', Planning::simulatePath([12, 7, 12]));
            $this->assertNotNull(Planning::simulatePath(range(1, Planning::SIMULATE_LIMIT)));
            $this->assertNull(Planning::simulatePath(range(1, Planning::SIMULATE_LIMIT + 1)));
        }

        // ------------------------------------------------------------------ scope

        public function testTheApiNeverWidensWhatTheUserMaySee(): void
        {
            $net = self::network();
            // the user may not see dist1: the API neither plans, fails nor names it
            $api = self::api($net, [self::C1, self::C2, self::D2, self::A1, self::A2, self::AP, self::A3]);

            $plan = $api->rollout([self::D1, self::D2, self::A3]);
            $this->assertNotContains(self::D1, array_merge(...array_column($plan['batches'], 'devices')));
            $this->assertContains(['device_id' => self::D1, 'reason' => RolloutPlanner::UNKNOWN, 'text' => 'unknown, disabled or not visible'], $plan['skipped'],
                'said as a device that does not exist is said');
            $this->assertArrayNotHasKey(self::D1, $plan['names']);
            $this->assertSame([], array_diff(array_keys($plan['core_modes']), [self::C1, self::C2, self::D2, self::A1, self::A2, self::AP, self::A3]),
                'nor tells how a core device out of their sight was chosen');

            $blast = $api->blastRadius([self::D1]);
            $this->assertSame([], $blast['devices'], 'not failed: a user cannot probe what a device out of their sight cuts off');
            $this->assertSame([], $blast['lost']);
            $this->assertNull($blast['simulate']);
            $this->assertSame([], $blast['names']);
            $this->assertSame([['device_id' => self::D1, 'reason' => RolloutPlanner::UNKNOWN, 'text' => 'unknown, disabled or not visible']], $blast['skipped']);

            // what the visible devices cut off: only devices the user may see (dist1 is an unmanaged neighbor of theirs)
            $blast = $api->blastRadius([self::D2]);
            $this->assertSame([], array_diff($blast['lost'], [self::C1, self::C2, self::D2, self::A1, self::A2, self::AP, self::A3]));
            $this->assertSame([], array_diff(array_keys($blast['names']), [self::C1, self::C2, self::D2, self::A1, self::A2, self::AP, self::A3]));
        }

        public function testWhatTheApiAnswersIsFilteredByTheScopesOwnCheck(): void
        {
            // the analysis was handed core2, which the scope's own check refuses: the answer neither lists nor names it, whatever the analysis
            $plan = self::api(self::network(), null, [], 'core1 core2', [self::C2])->rollout([self::D1, self::A1]);
            $this->assertSame([self::C1], $plan['core']);
            $this->assertArrayNotHasKey(self::C2, $plan['core_modes']);
            $this->assertArrayNotHasKey(self::C2, $plan['names']);
        }

        public function testUnmanagedNodesAreCountedNeverListedAsDevices(): void
        {
            // an unmanaged switch behind acc3 (LLDP sees it on two ports, so it is a node of the graph, not a leaf): dist1 cuts it off with acc3
            $net = self::network();
            $net->neighbor(self::A3, 'Gi1/0/10', 'unmanaged-sw', 'unknown switch')->neighbor(self::A3, 'Gi1/0/11', 'unmanaged-sw', 'unknown switch');
            $before = self::api(self::network())->blastRadius([self::D1]);
            $blast = self::api($net)->blastRadius([self::D1]);
            $this->assertContains(null, array_column($net->analyzer()->simulate(['d:' . self::D1])['lost'], 'device_id'), 'the simulation loses an unmanaged node');
            $this->assertSame([self::A3], $blast['lost'], 'lost lists monitored devices only: no 0 for an unmanaged node');
            $this->assertSame($before['unmanaged'] + 1, $blast['unmanaged'], 'the unmanaged switch is counted');
        }

        public function testWhatTheUsersRoleHidesIsSaid(): void
        {
            $plan = self::api(self::network(), null, ['App\Models\Link'])->rollout([self::A1, self::A2]);

            $this->assertSame(['LLDP/CDP links'], $plan['role_hidden'], 'without the links the plan is incomplete: the caller is told');
            $this->assertSame([], $plan['batches'], 'nothing planned on data the role may not view');
        }

        public function testTheSystemScopeIsForConsoleCommandsOnly(): void
        {
            FakeLaravel::$console = false;
            try {
                Planning::system();
                $this->fail('the system scope outside the console');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('only available to console commands', $e->getMessage());
            }

            FakeLaravel::$console = true;
            $this->assertInstanceOf(Planning::class, Planning::system());
        }

        public function testADisabledPluginIsUnavailable(): void
        {
            $this->assertNull(Planning::unavailable());
            $this->assertInstanceOf(Planning::class, Planning::forUser(new \stdClass()));

            FakeLaravel::$enabled = false;
            $this->assertSame('the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)', Planning::unavailable());
            foreach ([fn () => Planning::forUser(new \stdClass()), fn () => Planning::system()] as $open) {
                try {
                    $open();
                    $this->fail('the API of a disabled plugin');
                } catch (PlanningUnavailable $e) {
                    $this->assertStringContainsString('disabled', $e->getMessage());
                }
            }
        }

        public function testTheVersionIsMajorDotMinor(): void
        {
            $this->assertMatchesRegularExpression('/^1\.\d+$/D', Planning::VERSION);
        }
    }

    /**
     * What the planning API asks Laravel's app() for in the tests: whether it runs in the console, and LibreNMS' plugin
     * manager (the topology plugin enabled or not).
     */
    final class FakeLaravel
    {
        public static bool $console = true;

        public static bool $enabled = true;

        public static function app(?string $abstract): mixed
        {
            if ($abstract === PluginManagerInterface::class) {
                return new class implements PluginManagerInterface
                {
                    public function publishHook(string $pluginName, string $hookType, string $implementationClass): bool
                    {
                        return true;
                    }

                    public function hasHooks(string $hookType, array $args = [], ?string $plugin = null): bool
                    {
                        return false;
                    }

                    public function call(string $hookType, array $args = [], ?string $plugin = null): array
                    {
                        return [];
                    }

                    public function getSettings(string $pluginName): array
                    {
                        return [];
                    }

                    public function setSettings(string $pluginName, array $settings): bool
                    {
                        return true;
                    }

                    public function pluginExists(string $pluginName): bool
                    {
                        return true;
                    }

                    public function pluginEnabled(string $pluginName): bool
                    {
                        return $pluginName === 'topology' && FakeLaravel::$enabled;
                    }

                    public function cleanupPlugins(): void
                    {
                    }
                };
            }

            return new class
            {
                public function runningInConsole(): bool
                {
                    return FakeLaravel::$console;
                }
            };
        }
    }
}

// Laravel's app() helper, as the code under test calls it (unqualified) in these namespaces
namespace Agoodley\LibrenmsTopology\Data {
    function app(?string $abstract = null): mixed
    {
        return \Agoodley\LibrenmsTopology\Tests\Unit\FakeLaravel::app($abstract);
    }
}

namespace Agoodley\LibrenmsTopology\Api {
    function app(?string $abstract = null): mixed
    {
        return \Agoodley\LibrenmsTopology\Tests\Unit\FakeLaravel::app($abstract);
    }
}
