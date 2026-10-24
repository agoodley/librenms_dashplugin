<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Topology\TopologyService;
use PHPUnit\Framework\TestCase;

/**
 * The link check of some devices (the verification API's states and fixes: TopologyService::links() with ids) finds
 * about them what the check of the whole network finds: it takes the links their neighbors report toward them (a cable
 * LLDP/CDP sees from one side only), and knows the name of every device a description may name.
 */
class LinkScopeTest extends TestCase
{
    /**
     * core1 (1) and core2 (2), acc1 (3) and acc2 (4), as LinkFixture has them; acc1 reports only its cable to core2, its
     * cable to core1 is reported by core1 alone; acc2's uplink to core1 says "to acc1", which is no neighbor of it.
     */
    private static function repository(): FakeLinkRepository
    {
        $links = array_values(array_filter(LinkFixture::links(), fn (array $link) => ! ($link['local_device_id'] === 3 && $link['local_port_id'] === 31)));

        return new FakeLinkRepository(LinkFixture::devices(), $links, LinkFixture::ports([31 => ['ifAlias' => ''], 32 => ['ifAlias' => 'core2 Te1/0/3'],
            41 => ['ifAlias' => 'to acc1'], 42 => ['ifAlias' => 'core2 Te1/0/4']]));
    }

    /** @return array<string, array> code and device => finding */
    private static function descriptions(array $result): array
    {
        $found = [];
        foreach ($result['findings'] as $finding) {
            if (str_starts_with($finding['code'], 'description_')) {
                $found[$finding['code'] . ' ' . implode(',', $finding['devices'])] = $finding;
            }
        }
        ksort($found);

        return $found;
    }

    public function testTheCheckOfSomeDevicesFindsWhatTheWholeNetworksFindsAboutThem(): void
    {
        $service = new TopologyService(self::repository(), new PluginSettings());
        $network = self::descriptions($service->links(MapParams::parse([], new PluginSettings())));
        $this->assertSame(['description_missing 3', 'description_wrong 4'], array_keys($network), 'acc1 Te1/1/1 has none, acc2\'s uplink names acc1');
        $this->assertSame([31 => 'core1 Te1/0/3'], $network['description_missing 3']['fix']['ports'], 'acc1\'s cable to core1, which core1 alone reports');

        foreach ([3, 4] as $deviceId) {
            $alone = self::descriptions($service->links(MapParams::parse([], new PluginSettings()), [$deviceId]));
            $mine = array_filter($network, fn (array $finding) => $finding['devices'] === [$deviceId]);
            $this->assertSame(array_map(fn (array $f) => [$f['title'], $f['ports'], $f['fix']], $mine), array_map(fn (array $f) => [$f['title'], $f['ports'], $f['fix']], $alone),
                "the check of device $deviceId alone");
        }
    }
}

/**
 * What the link check reads, in memory (LinkFixture's devices, links and ports): every device may be seen.
 */
final class FakeLinkRepository extends TopologyRepository
{
    public function __construct(private readonly array $rows, private readonly array $linkRows, private readonly array $portRows)
    {
        parent::__construct(null);
    }

    public function deviceIds(?int $groupId = null, ?int $locationId = null): array
    {
        return array_keys($this->rows);
    }

    public function scope(array $p): array
    {
        return [array_keys($this->rows), null];
    }

    public function linksWhere(array $ids, callable $keep): array
    {
        $kept = [];
        foreach (array_values(array_filter($this->linkRows, fn (array $link) => in_array($link['local_device_id'], $ids, true))) as $position => $link) {
            if ($keep($link)) {
                $kept[$position] = $link;
            }
        }

        return $kept;
    }

    public function linksToward(array $ids): array
    {
        return array_values(array_filter($this->linkRows, fn (array $link) => in_array($link['remote_device_id'], $ids, true) && ! in_array($link['local_device_id'], $ids, true)));
    }

    public function devices(array $ids, bool $roles = true): array
    {
        return array_intersect_key($this->rows, array_flip($ids));
    }

    public function nameRows(): array
    {
        return array_map(fn (array $device) => array_intersect_key($device, array_flip(['hostname', 'sysName', 'display'])), $this->rows);
    }

    public function portDescriptions(array $portIds): iterable
    {
        foreach ($portIds as $portId) {
            if (($this->portRows[$portId]['ifAlias'] ?? '') !== '') {
                yield $portId => $this->portRows[$portId]['ifAlias'];
            }
        }
    }

    public function ports(array $portIds, ?array $columns = null): array
    {
        return array_intersect_key($this->portRows, array_flip($portIds));
    }

    public function lagParents(array $ids, bool $strict = false): array
    {
        return [];
    }

    public function portsWithIp(array $portIds): array
    {
        return [];
    }

    public function portVlanCount(array $portIds): int
    {
        return 0;
    }

    public function portVlans(array $portIds): array
    {
        return [];
    }

    public function activeVlans(array $ids, array $interSwitchPorts): array
    {
        return [];
    }

    public function portStatistics(array $portIds): array
    {
        return [];
    }

    public function opticalRx(array $portIds): array
    {
        return [];
    }

    public function hiddenLinkCount(array $ids): int
    {
        return 0;
    }
}
