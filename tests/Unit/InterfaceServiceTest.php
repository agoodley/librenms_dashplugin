<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Data\InterfaceRepository;
use Agoodley\LibrenmsTopology\Data\RrdReader;
use Agoodley\LibrenmsTopology\Interfaces\InterfaceAnalyzer;
use Agoodley\LibrenmsTopology\Interfaces\InterfaceService;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

class InterfaceServiceTest extends TestCase
{
    /**
     * 150 devices (three chunks), each with one desk port; device 7's port has input errors, device 120's flaps (an access
     * point on it), device 130's port is no Ethernet port.
     */
    private function repository(): InterfaceRepository
    {
        return new class (null) extends InterfaceRepository {
            /** @var array<int, int[]> chunks asked for */
            public array $asked = [];

            public function scope(array $p): array
            {
                return [range(1, 150), null];
            }

            public function events(array $deviceIds, int $since): array
            {
                $this->asked[] = $deviceIds;
                $events = [];
                if (in_array(120, $deviceIds, true)) {
                    foreach ([9000, 6000, 3000] as $ago) {
                        $events[] = ['port_id' => 1120, 'device_id' => 120, 'time' => time() - $ago] + InterfaceAnalyzer::parseEvent('ifOperStatus: up -> down');
                        $events[] = ['port_id' => 1120, 'device_id' => 120, 'time' => time() - $ago + 300] + InterfaceAnalyzer::parseEvent('ifOperStatus: down -> up');
                    }
                }
                if (in_array(130, $deviceIds, true)) {
                    $events[] = ['port_id' => 1130, 'device_id' => 130, 'time' => time() - 600] + InterfaceAnalyzer::parseEvent('ifOperStatus: up -> down');
                }

                return $events;
            }

            public function eventsCapped(): bool
            {
                return false;
            }

            public function ports(array $deviceIds, array $withChanges): array
            {
                $ports = [];
                foreach ($deviceIds as $id) {
                    if ($id === 7 || in_array(1000 + $id, $withChanges, true)) {
                        $ports[1000 + $id] = ['port_id' => 1000 + $id, 'device_id' => $id, 'ifName' => 'Gi1/0/1', 'ifDescr' => 'Gi1/0/1', 'ifAlias' => '',
                            'ifType' => $id === 130 ? 'propVirtual' : 'ethernetCsmacd', 'ifSpeed' => 1000000000, 'ifDuplex' => 'fullDuplex', 'ifOperStatus' => 'up',
                            'ifAdminStatus' => 'up', 'ifInErrors' => $id === 7 ? 5000 : 0, 'ifOutErrors' => 0, 'ifInErrors_delta' => $id === 7 ? 40 : 0,
                            'ifOutErrors_delta' => 0, 'ifInUcastPkts_delta' => 1000, 'ifOutUcastPkts_delta' => 1000, 'poll_period' => 300];
                    }
                }

                return $ports;
            }

            public function portCount(array $deviceIds): int
            {
                return count($deviceIds) * 48;
            }

            public function reboots(array $deviceIds, int $since): array
            {
                return [];
            }

            public function neighbors(array $deviceIds): array
            {
                return in_array(120, $deviceIds, true) ? [1120 => ['class' => InterfaceAnalyzer::NEIGHBOR, 'name' => 'ap-120', 'port' => 'eth0']] : [];
            }

            public function devices(array $deviceIds): array
            {
                return array_combine($deviceIds, array_map(fn ($id) => ['hostname' => "sw$id", 'display' => ''], $deviceIds));
            }
        };
    }

    private static function reader(array $totals): RrdReader
    {
        return new class ($totals) extends RrdReader {
            public array $read = [];

            public function __construct(private readonly array $totals)
            {
                parent::__construct('rrdtool', '/rrd');
            }

            public function totals(array $hostnames, int $seconds): array
            {
                $this->read[] = [$hostnames, $seconds];

                return array_intersect_key($this->totals, $hostnames);
            }
        };
    }

    public function testAnalyzesTheDevicesAChunkAtATimeWithTheRrdTotals(): void
    {
        $repository = $this->repository();
        $reader = self::reader([1007 => ['in_errors' => 1200, 'out_errors' => 0, 'in_packets' => 900000, 'out_packets' => 900000, 'seconds' => 86400]]);
        $result = (new InterfaceService($repository, new PluginSettings(), fn () => $reader))->analyze(['device' => null, 'group' => null, 'location' => null]);

        $this->assertSame([50, 50, 50], array_map('count', $repository->asked));
        $this->assertSame(['link flapping: sw120 Gi1/0/1', 'interface errors: sw7 Gi1/0/1'], array_column($result['findings'], 'title'));
        $this->assertSame('Gi1/0/1: 1,200 input errors (0.13% of packets) in the last 24 hours.', $result['findings'][1]['detail']);
        $this->assertSame([[[1007 => 'sw7'], 86400]], $reader->read, 'only the port whose counters are not zero is read');
        $this->assertSame(150 * 48, $result['summary']['ports']);
        $this->assertSame(2, $result['summary']['rows']);
        $this->assertSame(['hours' => 24, 'downgrade_days' => 7, 'counters' => 'rrd'], $result['window']);
        $this->assertNull($result['notice']);
        $this->assertSame([7 => 'sw7', 120 => 'sw120'], $result['device_names']);
    }

    public function testTakesTheLastPollWithoutRrdFiles(): void
    {
        $result = (new InterfaceService($this->repository(), new PluginSettings(['interfaces_errors' => '10']), fn () => null))
            ->analyze(['device' => null, 'group' => null, 'location' => null]);

        $this->assertSame('poll', $result['window']['counters']);
        $this->assertSame('The error counts are those of the last poll, not of the last 24 hours: LibreNMS writes no RRD files, or they cannot be read here.',
            $result['notice']);
        $errors = array_values(array_filter($result['findings'], fn ($f) => $f['code'] === 'port_errors'));
        $this->assertSame('Gi1/0/1: 40 input errors (3.8% of packets) in the last poll.', $errors[0]['detail']);
    }

    public function testFallsBackToTheLastPollWhenRrdtoolCannotRun(): void
    {
        $broken = new class ('rrdtool', '/rrd') extends RrdReader {
            public function totals(array $hostnames, int $seconds): array
            {
                throw new \RuntimeException('cannot run rrdtool: not found');
            }
        };
        $result = (new InterfaceService($this->repository(), new PluginSettings(['interfaces_errors' => '10']), fn () => $broken))
            ->analyze(['device' => null, 'group' => null, 'location' => null]);

        $this->assertSame('poll', $result['window']['counters']);
        $this->assertStringContainsString('the RRD files cannot be read (cannot run rrdtool: not found)', $result['notice']);
        $this->assertCount(1, array_filter($result['findings'], fn ($f) => $f['code'] === 'port_errors'));
    }
}
