<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Data\RoutingRepository;
use Agoodley\LibrenmsTopology\Routing\RoutingService;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

/**
 * The background routing check reads strictly: a table that cannot be read, or only in part, fails
 * the check instead of becoming findings (sessions "configured on one side only") or resolving them.
 */
class RoutingStrictTest extends TestCase
{
    /**
     * Repository whose bgpPeers read breaks after its first row (a lost connection), over two routers
     * that peer with each other.
     */
    private static function failing(): RoutingRepository
    {
        return new class(null) extends RoutingRepository
        {
            public function deviceIds(?int $groupId = null, ?int $locationId = null): array
            {
                return [1, 2];
            }

            public function scope(array $p): array
            {
                return [[1, 2], null];
            }

            protected function existing(string $table, array $wanted): array
            {
                return $wanted;
            }

            protected function select(string $table, array $ids, array $columns): iterable
            {
                $peer = fn (int $device, string $peer) => ['device_id' => $device, 'vrf_id' => null, 'astext' => '', 'bgpPeerIdentifier' => $peer,
                    'bgpPeerRemoteAs' => 65000, 'bgpPeerState' => 'established', 'bgpPeerAdminStatus' => 'start', 'bgpLocalAddr' => '0.0.0.0',
                    'bgpPeerRemoteAddr' => '0.0.0.0', 'bgpPeerFsmEstablishedTime' => 999999, 'context_name' => ''];
                if ($table === 'devices') {
                    yield ['device_id' => 1, 'hostname' => 'r1', 'sysName' => 'r1', 'display' => 'r1', 'status' => 1, 'uptime' => 9999999, 'bgpLocalAs' => 65000];
                    yield ['device_id' => 2, 'hostname' => 'r2', 'sysName' => 'r2', 'display' => 'r2', 'status' => 1, 'uptime' => 9999999, 'bgpLocalAs' => 65000];
                } elseif ($table === 'bgpPeers') {
                    yield $peer(1, '10.0.0.2');
                    throw new \RuntimeException('Lost connection to MySQL server during query');
                }
            }

            public function addresses(array $ids): \Generator
            {
                yield from [];
            }

            public function links(array $ids): \Generator
            {
                yield from [];
            }
        };
    }

    public function testAReadThatBreaksFailsTheStrictRepository(): void
    {
        $peers = self::failing()->strict()->bgp([1, 2])['peers'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot read bgpPeers: Lost connection to MySQL server during query');
        iterator_to_array($peers);
    }

    public function testTheBackgroundRoutingCheckFailsOnAPartialRead(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot read bgpPeers');
        (new RoutingService(self::failing()->strict(), new PluginSettings([])))->analyze(['device' => null, 'group' => null, 'location' => null, 'vrf' => null]);
    }

    public function testStrictReadsWhatCanBeRead(): void
    {
        $repository = self::failing();

        $this->assertNotSame($repository, $repository->strict(), 'a copy: the page keeps its forgiving repository');
        $this->assertSame([1, 2], array_keys($repository->strict()->devices([1, 2])));
    }
}
