<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Data\DependencyRepository;
use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Data\ScopedRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Topology\PlanningService;
use PHPUnit\Framework\TestCase;

/**
 * The rollout planner and the device dependencies see the baseline like the redundancy page: an
 * uplink LibreNMS no longer reports (LLDP/CDP neighbor deleted at the next discovery) that is in the
 * baseline is still a path of the design.
 */
class PlanningBaselineTest extends TestCase
{
    /**
     * The planning service over the campus fixture without acc1's uplink to dist2, and a history
     * that has it in the baseline (or no history tables: $baseline empty).
     *
     * @param  array<int, array>  $baseline  links rows of the baseline links that are gone
     */
    private static function planning(RedundancyFixture $net, array $baseline): PlanningService
    {
        $history = new class(null, $baseline) extends HistoryRepository
        {
            public function __construct(mixed $user, private readonly array $missing)
            {
                parent::__construct($user);
            }

            public function missingBaselineLinks(array $ids): array
            {
                return $this->missing;
            }
        };
        $topology = new class(null, $net, $history) extends TopologyRepository
        {
            public function __construct(mixed $user, private readonly RedundancyFixture $net, private readonly HistoryRepository $history)
            {
                parent::__construct($user);
            }

            public function sibling(string $class): ScopedRepository
            {
                return $class === HistoryRepository::class ? $this->history : parent::sibling($class);
            }

            public function deviceIds(?int $groupId = null, ?int $locationId = null): array
            {
                return array_keys($this->net->devices);
            }

            public function scope(array $p): array
            {
                return [$this->deviceIds(), null];
            }

            public function canSee(int $deviceId): bool
            {
                return isset($this->net->devices[$deviceId]);
            }

            public function devices(array $ids, bool $roles = true): array
            {
                return array_intersect_key($this->net->devices, array_flip($ids));
            }

            public function links(array $ids): array
            {
                return $this->net->links;
            }

            public function linksWhere(array $ids, callable $keep): array
            {
                return array_filter($this->net->links, $keep); // keyed by position, as the repository's
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
        $dependencies = new class(null) extends DependencyRepository
        {
            public function parents(array $ids): array
            {
                return ['parents' => [], 'disabled' => [], 'hidden' => []];
            }

            public function outages(array $ids, int $since): array
            {
                return [];
            }
        };

        return new PlanningService($topology, $dependencies, new PluginSettings(['redundancy_core' => 'core1 core2']));
    }

    public function testDependenciesFollowTheBaselineLinksLibreNmsNoLongerReports(): void
    {
        $net = RedundancyFixture::campus();
        $uplink = RedundancyFixture::row(RedundancyFixture::ACC1, $net->portId(RedundancyFixture::ACC1, 'Gi1/0/50'), RedundancyFixture::DIST2,
            $net->portId(RedundancyFixture::DIST2, 'Gi1/0/1'), '', 'Gi1/0/1') + ['id' => null, 'protocol' => 'baseline', 'missing' => '2026-10-20 10:07:00'];
        $net->unlink(RedundancyFixture::ACC1, RedundancyFixture::DIST2);
        $acc1 = fn (PlanningService $planning) => array_values(array_filter($planning->dependencies(['device' => null, 'group' => null, 'location' => null])['rows'],
            fn ($row) => $row['device_id'] === RedundancyFixture::ACC1))[0];

        $this->assertSame([RedundancyFixture::DIST1, RedundancyFixture::DIST2], $acc1(self::planning($net, [$uplink]))['derived'],
            'both distribution switches, the second through the baseline uplink');
        $this->assertSame([RedundancyFixture::DIST1], $acc1(self::planning($net, []))['derived'], 'without the baseline: dist1 only');
    }
}
