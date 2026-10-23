<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Data\ScopedRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Data\VerificationRepository;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Verification\LibrenmsSources;
use PHPUnit\Framework\TestCase;

/**
 * The verification API's sources in LibreNMS (Verification\LibrenmsSources), on repositories in memory: the spanning
 * tree domain of devices is every switch with spanning tree data they reach by LLDP/CDP links between such switches,
 * however far (a priority set on one switch moves the root of all of them), a link seen from one side counting too.
 */
class LibrenmsSourcesTest extends TestCase
{
    public function testTheDomainOfDevicesIsEverySwitchTheyReachThroughOtherSwitches(): void
    {
        // 1 ── 2 ── 3 ── 4 (4 reports its link to 3, 3 does not), 5 hangs off 3 without spanning tree data; 6 ── 7 another domain
        $repo = new FakeStpRepository([1, 2, 3, 4, 6, 7], [[1, 2], [2, 3], [4, 3], [3, 5], [6, 7]]);
        $sources = new LibrenmsSources($repo, new PluginSettings());

        $this->assertSame([1, 2, 3, 4], $sources->stpDomain([1]));
        $this->assertSame([4, 3, 2, 1], $sources->stpDomain([4]), 'from the far end too');
        $this->assertSame([6, 7], $sources->stpDomain([6]));
        $this->assertSame([], $sources->stpDomain([5]), 'no spanning tree data: no domain');
        $this->assertSame([2, 6, 1, 3, 7, 4], $sources->stpDomain([2, 6]), 'two domains at once');
    }
}

/**
 * The topology a domain is found on: the switches with spanning tree data (one instance each) and the LLDP/CDP links,
 * one row per link as the switch at its local end reports it; every device may be seen.
 */
final class FakeStpRepository extends TopologyRepository
{
    /**
     * @param  int[]  $switches  the devices with spanning tree data
     * @param  list<array{int, int}>  $links  local device, remote device
     */
    public function __construct(private readonly array $switches, private readonly array $links)
    {
        parent::__construct(null);
    }

    public function deviceIds(?int $groupId = null, ?int $locationId = null): array
    {
        return array_values(array_unique([...$this->switches, ...array_merge(...$this->links)]));
    }

    public function stpKeys(array $ids): array
    {
        $keys = array_fill_keys(array_values(array_intersect($this->switches, $ids)), [0]);

        return ['keys' => $keys, 'bridges' => [], 'count' => count($keys)];
    }

    public function linksWhere(array $ids, callable $keep): array
    {
        $rows = [];
        foreach ($this->links as [$local, $remote]) {
            $row = ['local_device_id' => $local, 'local_port_id' => $local * 10 + $remote, 'remote_device_id' => $remote, 'remote_port_id' => null];
            if (in_array($local, $ids, true) && $keep($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function sibling(string $class): ScopedRepository
    {
        return $class === VerificationRepository::class ? new EveryDeviceVisible(null) : parent::sibling($class);
    }
}

/**
 * The verification repository of a user who may see every device.
 */
final class EveryDeviceVisible extends VerificationRepository
{
    public function visibleOf(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }
}
