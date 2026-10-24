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
 * however far (a priority set on one switch moves the root of all of them), a link seen from one side counting too; the
 * explicit priorities a root fix competes with are the bridges' configured ones, as LibreNMS stores them less the VLAN id.
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

    public function testAnExplicitPriorityIsTheConfiguredOneWithoutTheVlanId(): void
    {
        // PVST+ switches 1 and 2: LibreNMS stores each VLAN's bridge priority with its extended system id (the VLAN id) added, so 4096 reads 4097 in
        // VLAN 1 and 4106 in VLAN 10, 8192 reads 8193 and 8202; 3 is a single-instance bridge (RSTP), whose one priority holds in every VLAN
        $repo = new FakeStpRepository([1, 2, 3], [[1, 2], [2, 3]], [1 => [0, 10], 2 => [0, 10], 3 => [0]], [
            ['device_id' => 1, 'vlan' => 1, 'priority' => 4097, 'bridgeAddress' => '00ab01000000', 'protocolSpecification' => 'ieee8021d'],
            ['device_id' => 1, 'vlan' => 10, 'priority' => 4106, 'bridgeAddress' => '00ab01000000', 'protocolSpecification' => 'ieee8021d'],
            ['device_id' => 2, 'vlan' => 1, 'priority' => 8193, 'bridgeAddress' => '00ab02000000', 'protocolSpecification' => 'ieee8021d'],
            ['device_id' => 2, 'vlan' => 10, 'priority' => 8202, 'bridgeAddress' => '00ab02000000', 'protocolSpecification' => 'ieee8021d'],
            ['device_id' => 3, 'vlan' => null, 'priority' => 24576, 'bridgeAddress' => '00ab03000000', 'protocolSpecification' => 'ieee8021w'],
        ]);
        $sources = new LibrenmsSources($repo, new PluginSettings());

        $this->assertSame([0 => [1 => 4096, 2 => 8192, 3 => 24576], 10 => [1 => 4096, 2 => 8192, 3 => 24576]], $sources->explicitPriorities([0, 10]));
        $this->assertSame([10 => [1 => 4096, 2 => 8192, 3 => 24576]], $sources->explicitPriorities([10]), 'the VLANs asked only');
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
     * @param  array<int, int[]>  $keys  device => its VLAN keys (none given: one instance each)
     * @param  list<array>  $low  stp rows whose priority is below the default, as LibreNMS stores them
     */
    public function __construct(private readonly array $switches, private readonly array $links, private readonly array $keys = [], private readonly array $low = [])
    {
        parent::__construct(null);
    }

    public function deviceIds(?int $groupId = null, ?int $locationId = null): array
    {
        return array_values(array_unique([...$this->switches, ...array_merge(...$this->links)]));
    }

    public function stpKeys(array $ids): array
    {
        $keys = [];
        foreach (array_intersect($this->switches, $ids) as $deviceId) {
            $keys[$deviceId] = $this->keys[$deviceId] ?? [0];
        }

        return ['keys' => $keys, 'bridges' => [], 'count' => count($keys)];
    }

    public function lowPriorityInstances(array $ids): array
    {
        return array_values(array_filter($this->low, fn (array $row) => in_array($row['device_id'], $ids, true)));
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
