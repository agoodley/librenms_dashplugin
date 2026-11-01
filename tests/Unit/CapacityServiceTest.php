<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Capacity\CapacityAnalyzer;
use Agoodley\LibrenmsTopology\Capacity\CapacityService;
use Agoodley\LibrenmsTopology\Data\CapacityRepository;
use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Carbon\CarbonInterface;
use PHPUnit\Framework\TestCase;

class CapacityServiceTest extends TestCase
{
    private const NOW = 1793530800;

    private const DAY = 86400;

    /**
     * Two switches (1 and 2) and a router (3, no switch: not returned by switches()); switch 1 has an err-disabled port
     * and sends syslog, switch 2 sends none.
     */
    private function repository(bool $record = true, ?array $poe = []): CapacityRepository
    {
        return new class (null, $record, $poe) extends CapacityRepository {
            /** @var int[] the start of each err-disable read */
            public array $since = [];

            public array $saved = [];

            public function __construct(mixed $user, private readonly bool $record, private readonly ?array $poe)
            {
                parent::__construct($user);
            }

            public function scope(array $p): array
            {
                return [[1, 2, 3], null];
            }

            public function deviceIds(?int $groupId = null, ?int $locationId = null): array
            {
                return [1, 2, 3];
            }

            public function withDisabled(): static
            {
                return $this;
            }

            public function switches(array $deviceIds): array
            {
                $switch = fn (int $id) => ['device_id' => $id, 'name' => "sw$id", 'hostname' => "sw$id", 'location_id' => 5, 'location' => 'Closet',
                    'uptime' => 100 * 86400, 'last_polled' => CapacityServiceTest::now(), 'inserted' => CapacityServiceTest::now() - 400 * 86400, 'url' => ''];

                return array_intersect_key([1 => $switch(1), 2 => $switch(2)], array_flip($deviceIds));
            }

            public function ports(array $deviceIds): array
            {
                $port = fn (int $id, int $device, string $oper) => ['port_id' => $id, 'device_id' => $device, 'ifName' => 'Gi1/0/' . ($id % 10), 'ifDescr' => '',
                    'ifOperStatus' => $oper, 'ifAdminStatus' => 'up', 'ifLastChange' => (100 - 1) * 86400 * 100, 'poll_time' => CapacityServiceTest::now()];

                return [11 => $port(11, 1, 'up'), 12 => $port(12, 1, 'down'), 21 => $port(21, 2, 'down')];
            }

            public function neighbors(array $deviceIds): array
            {
                return [];
            }

            public function lags(array $deviceIds): array
            {
                return [];
            }

            public function records(?array $deviceIds): array
            {
                return [];
            }

            public function eventUps(array $deviceIds, int $since): array
            {
                return [];
            }

            public function eventSince(array $devices): array
            {
                return [];
            }

            public function errDisableMessages(array $deviceIds, int $since): array
            {
                $this->since[] = $since;

                return [['device_id' => 1, 'time' => CapacityServiceTest::now() - 86400 - 60,
                    'text' => '%PM-4-ERR_DISABLE: bpduguard error detected on Gi1/0/2, putting Gi1/0/2 in err-disable state']];
            }

            public function syslogSenders(array $deviceIds, int $since): ?array
            {
                return [1];
            }

            public function recordAvailable(): bool
            {
                return $this->record;
            }

            public function recordSince(array $deviceIds): ?int
            {
                return $this->record ? CapacityServiceTest::now() - 10 * 86400 : null;
            }

            public function poePorts(array $ports, array $devices): ?array
            {
                return $this->poe;
            }

            public function saveRecords(array $rows, array $remove, int $now): void
            {
                $this->saved = [$rows, $remove, $now];
            }
        };
    }

    private function history(?array $latest): HistoryRepository
    {
        return new class (null, $latest) extends HistoryRepository {
            public array $finished = [];

            public function __construct(mixed $user, private readonly ?array $latest)
            {
                parent::__construct($user);
            }

            public function latestRun(string $type): ?array
            {
                return $type === 'ports' ? $this->latest : null;
            }

            public function startRun(string $type, CarbonInterface $time): int
            {
                return 7;
            }

            public function finishRun(int $id, array $stats, CarbonInterface $time): void
            {
                $this->finished = [$id, $stats];
            }
        };
    }

    public static function now(): int
    {
        return self::NOW;
    }

    public function testAnalysisWithTheRecord(): void
    {
        $repo = $this->repository();
        $result = (new CapacityService($repo, new PluginSettings, $this->history(['started_at' => '2026-11-01 11:00:00', 'stats' => ['until' => self::NOW - 3600]])))
            ->analyze(['device' => null, 'group' => null, 'location' => null], null, self::NOW);

        $this->assertSame([self::NOW - 3600 - 900], $repo->since, 'the messages since the record\'s last run, with an overlap');
        $ports = array_column($result['ports'], null, 'port_id');
        $this->assertSame(CapacityAnalyzer::ERR_DISABLED, $ports[12]['state']);
        $this->assertSame(CapacityAnalyzer::FREE, $ports[21]['state']);
        $this->assertSame(['sw1', 'sw2'], array_column($result['devices'], 'name'), 'only switches');
        $this->assertTrue($result['sources']['record']);
        $this->assertSame(self::NOW - 3600, $result['sources']['record_run']);
        $this->assertSame(1, $result['sources']['syslog_senders']);
        $this->assertSame('none', $result['sources']['poe']);
        $notices = implode(' | ', $result['notices']);
        $this->assertStringContainsString('1 of the 2 switches sent no syslog to LibreNMS in the last 7 days', $notices);
        $this->assertStringContainsString('LibreNMS polls no PoE', $notices);
        $this->assertStringNotContainsString('record', $notices);
    }

    public function testAnalysisWithoutTheRecord(): void
    {
        $repo = $this->repository(record: false, poe: null);
        $result = (new CapacityService($repo, new PluginSettings, $this->history(null)))->analyze(['device' => null, 'group' => null, 'location' => null], 90, self::NOW);

        $this->assertSame([self::NOW - 24 * 3600], $repo->since, 'the last day of messages');
        $this->assertSame(90, $result['days']);
        $this->assertFalse($result['sources']['record']);
        $this->assertSame('unreadable', $result['sources']['poe']);
        $notices = implode(' | ', $result['notices']);
        $this->assertStringContainsString('./lnms migrate', $notices);
        $this->assertStringContainsString('rrdcached', $notices);

        $off = (new CapacityService($this->repository(), new PluginSettings(['history_enabled' => '0']), $this->history(null)))
            ->analyze(['device' => null, 'group' => null, 'location' => null], null, self::NOW);
        $this->assertStringContainsString('The topology history is off', implode(' | ', $off['notices']));
    }

    public function testRecordRun(): void
    {
        $repo = $this->repository();
        $history = $this->history(['started_at' => '2026-10-31 12:00:00', 'stats' => ['until' => self::NOW - 2 * self::DAY]]);

        $stats = (new CapacityService($repo, new PluginSettings, $history))->record($history, self::NOW);

        $this->assertSame([self::NOW - 2 * self::DAY - 900], $repo->since);
        $this->assertSame(['switches' => 2, 'ports' => 3, 'written' => 3, 'removed' => 0, 'err_disabled' => 1, 'until' => self::NOW], array_diff_key($stats, ['seconds' => 1]));
        [$rows, $remove, $now] = $repo->saved;
        $this->assertSame(self::NOW, $rows[11]['last_up'], 'up at its last poll');
        $this->assertSame(self::NOW - self::DAY, $rows[12]['last_up'], 'down since its last change');
        $this->assertSame(self::NOW - self::DAY - 60, $rows[12]['err_disabled_at']);
        $this->assertSame(self::NOW, $now);
        $this->assertSame(7, $history->finished[0]);

        // the first run reads a week
        $repo = $this->repository();
        (new CapacityService($repo, new PluginSettings, $this->history(null)))->record($this->history(null), self::NOW);
        $this->assertSame([self::NOW - 7 * self::DAY], $repo->since);
    }
}
