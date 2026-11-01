<?php

namespace Agoodley\LibrenmsCompliance\Tests\Unit;

use Agoodley\LibrenmsCompliance\Data\ConfigStore;
use Agoodley\LibrenmsCompliance\Data\ResultRepository;
use Agoodley\LibrenmsCompliance\Data\SettingsRepository;
use Agoodley\LibrenmsCompliance\Support\PluginSettings;
use Agoodley\LibrenmsCompliance\Tests\Unit\Fakes\SqliteSql;
use PHPUnit\Framework\TestCase;

/**
 * The plugin's tables: its settings, each device's last check and the findings of the rules that failed, with since
 * when; a check replaces a device's findings at once.
 */
class StorageTest extends TestCase
{
    private const T1 = 1793520000; // 2026-11-01 08:00:00 UTC

    private static function check(array $outcomes = [], array $ports = []): array
    {
        return ['status' => ResultRepository::CHECKED, 'provider' => 'Oxidized', 'backup_at' => self::T1 - 600, 'config_hash' => str_repeat('a', 64), 'os' => 'ios',
            'outcomes' => $outcomes, 'ports' => $ports];
    }

    private static function finding(string $severity, string $message, array $ports = []): array
    {
        return ['severity' => $severity, 'message' => $message, 'evidence' => [[60, 'interface GigabitEthernet1/0/3']], 'ports' => $ports];
    }

    public function testTheSettingsAreStoredAndReadBack(): void
    {
        $sql = new SqliteSql();
        $store = new ConfigStore($sql);
        $this->assertNull($store->get('settings'));
        $store->put('settings', ['interval_hours' => '6']);
        $store->put('settings', ['interval_hours' => '4', 'ntp_servers' => "10.0.0.10\n10.0.0.11"]);
        $this->assertSame(['interval_hours' => '4', 'ntp_servers' => "10.0.0.10\n10.0.0.11"], $store->get('settings'), 'the second save replaces the first');
        $this->assertCount(1, $sql->select('SELECT * FROM `compliance_config`'));

        $repository = new SettingsRepository($store);
        $this->assertSame(4, $repository->get()->intervalHours());
        $this->assertSame(['10.0.0.10', '10.0.0.11'], $repository->get()->servers('ntp_servers'));
        $repository->save(new PluginSettings(['interval_hours' => '12', 'disabled_rules' => ['syslog']]));
        $this->assertSame([12, ['syslog']], [$repository->get()->intervalHours(), $repository->get()->disabledRules()]);

        $sql->pdo->exec("UPDATE `compliance_config` SET `value` = 'not json' WHERE `name` = 'settings'");
        $broken = $repository->get();
        $this->assertNotNull($broken->error, 'settings that cannot be read say so');
        $this->assertSame(1, $broken->intervalHours(), 'and are the defaults');

        $none = new SettingsRepository(new ConfigStore(new SqliteSql(false)));
        $this->assertSame([null, PluginSettings::DEFAULTS], [$none->get()->error, $none->get()->all()], 'no table yet: the defaults');
        $this->assertFalse((new ResultRepository(new SqliteSql(false)))->available());
        $this->assertTrue((new ResultRepository($sql))->available());
    }

    public function testACheckReplacesTheFindingsOfItsDevice(): void
    {
        $sql = new SqliteSql();
        $results = new ResultRepository($sql);
        $first = $results->save(2, self::check(['stp-edge-portfast' => ['fail', ''], 'ntp' => ['pass', '']]),
            ['stp-edge-portfast' => self::finding('warning', '1 access port without portfast', ['GigabitEthernet1/0/3']), 'aaa' => self::finding('critical', 'no AAA')], self::T1);
        $this->assertSame(['stp-edge-portfast', 'aaa'], array_keys($first['new']));
        $this->assertSame([], $first['resolved']);

        $second = $results->save(2, self::check(['stp-edge-portfast' => ['fail', '']]),
            ['stp-edge-portfast' => self::finding('warning', '2 access ports without portfast', ['GigabitEthernet1/0/3', 'GigabitEthernet1/0/4']),
                'ntp' => self::finding('warning', 'no NTP server')], self::T1 + 3600);
        $this->assertSame(['ntp'], array_keys($second['new']));
        $this->assertSame(['aaa'], array_keys($second['resolved']), 'a rule that no longer fails is resolved');
        $this->assertSame('no AAA', $second['resolved']['aaa']['message']);

        $findings = $results->findings(2);
        $this->assertSame(['ntp', 'stp-edge-portfast'], array_keys($findings));
        $portfast = $findings['stp-edge-portfast'];
        $this->assertSame(['2026-11-01 08:00:00', '2026-11-01 09:00:00'], [$portfast['first_seen'], $portfast['last_seen']], 'a rule that failed again keeps its first time');
        $this->assertSame(['2 access ports without portfast', ['GigabitEthernet1/0/3', 'GigabitEthernet1/0/4'], [[60, 'interface GigabitEthernet1/0/3']]],
            [$portfast['message'], $portfast['ports'], $portfast['evidence']]);

        $device = $results->device(2);
        $this->assertSame([ResultRepository::CHECKED, 'Oxidized', '2026-11-01 07:50:00', '2026-11-01 09:00:00', null],
            [$device['status'], $device['provider'], $device['backup_at'], $device['checked_at'], $device['error']]);
        $this->assertSame(['stp-edge-portfast' => ['fail', '']], $device['outcomes']);
        $this->assertNull($results->device(3), 'never checked');
    }

    public function testACheckThatReadNoBackupKeepsTheResultsBefore(): void
    {
        $sql = new SqliteSql();
        $results = new ResultRepository($sql);
        $results->save(2, self::check(['ntp' => ['fail', '']], [['name' => 'GigabitEthernet1/0/1']]), ['ntp' => self::finding('warning', 'no NTP server')], self::T1);
        $results->saveFailure(2, 'error', 'Oxidized', 'Oxidized cannot be reached.', 'ios', self::T1 + 3600);

        $device = $results->device(2);
        $this->assertSame(['error', 'Oxidized cannot be reached.', '2026-11-01 09:00:00'], [$device['status'], $device['error'], $device['checked_at']]);
        $this->assertSame([['ntp' => ['fail', '']], [['name' => 'GigabitEthernet1/0/1']], '2026-11-01 07:50:00'], [$device['outcomes'], $device['ports'], $device['backup_at']],
            'the outcomes, ports and backup of the check before stay');
        $this->assertSame(['ntp'], array_keys($results->findings(2)));

        $this->assertSame([], $results->saveFailure(9, 'no_backup', null, 'No configuration backup tool of LibreNMS backs this device up.', 'linux', self::T1, false));
        $this->assertSame(['no_backup', [], []], [$results->device(9)['status'], $results->device(9)['outcomes'], $results->device(9)['ports']]);

        $sql->failOn = 'INSERT INTO `compliance_findings`';
        try {
            $results->save(2, self::check(), ['ntp' => self::finding('warning', 'no NTP server'), 'aaa' => self::finding('critical', 'no AAA')], self::T1 + 7200);
            $this->fail('the database failed');
        } catch (\RuntimeException) {
        }
        $this->assertSame('error', $results->device(2)['status'], 'a check that failed to be stored changes nothing');
        $this->assertSame(['ntp'], array_keys($results->findings(2)));
        $sql->failOn = null;

        $gone = $results->saveFailure(2, 'no_backup', 'Oxidized', 'Oxidized has no node for this device.', 'ios', self::T1 + 7200, false);
        $this->assertSame(['ntp'], array_keys($gone), 'a device without a backup has no results');
        $this->assertSame([[], [], null, null], [$results->device(2)['outcomes'], $results->device(2)['ports'], $results->device(2)['backup_at'], $results->device(2)['config_hash']]);
        $this->assertSame([], $results->findings(2));
    }

    public function testTheFindingsOfTheDevicesFilteredTheWorstFirst(): void
    {
        $results = new ResultRepository(new SqliteSql());
        $results->save(1, self::check(), ['ntp' => self::finding('warning', 'no NTP server'), 'snmp-community' => self::finding('warning', 'community 100%_used')], self::T1);
        $results->save(2, self::check(), ['stp-portfast-switch' => self::finding('critical', 'portfast toward acc-sw2'), 'syslog' => self::finding('info', 'no syslog server')],
            self::T1);
        $results->save(3, self::check(), ['ntp' => self::finding('warning', 'no NTP server')], self::T1);

        $all = $results->page(null, [], 1, 50);
        $this->assertSame(5, $all['total']);
        $this->assertSame([[2, 'stp-portfast-switch'], [1, 'ntp'], [1, 'snmp-community'], [3, 'ntp'], [2, 'syslog']],
            array_map(fn (array $row) => [$row['device_id'], $row['rule']], $all['rows']), 'the worst first, then by device and rule');
        $this->assertSame([[1, 'snmp-community'], [3, 'ntp']], array_map(fn (array $row) => [$row['device_id'], $row['rule']], $results->page(null, [], 2, 2)['rows']));
        $this->assertSame([1, 3], array_column($results->page(null, ['rule' => 'ntp'], 1, 50)['rows'], 'device_id'));
        $this->assertSame(['syslog'], array_column($results->page(null, ['severity' => 'info'], 1, 50)['rows'], 'rule'));
        $this->assertSame(['snmp-community'], array_column($results->page(null, ['text' => '100%_'], 1, 50)['rows'], 'rule'), '% and _ are text');
        $this->assertSame(0, $results->page(null, ['text' => '100%x'], 1, 50)['total']);
        $this->assertSame([3], array_column($results->page([3, 4], ['rule' => 'ntp'], 1, 50)['rows'], 'device_id'), 'only the devices of the scope');
        $this->assertSame(0, $results->page([], [], 1, 50)['total'], 'a scope without devices');
        $this->assertSame([1 => ['warning' => 2], 2 => ['critical' => 1, 'info' => 1]], $results->counts([1, 2]));
    }

    public function testDevicesNoLongerCheckedAreForgotten(): void
    {
        $results = new ResultRepository(new SqliteSql());
        foreach ([1, 2, 3] as $id) {
            $results->save($id, self::check(['ntp' => ['fail', '']], [['name' => 'Ethernet1']]), ['ntp' => self::finding('warning', 'no NTP server')], self::T1);
        }
        $summaries = $results->summaries([1, 2]);
        $this->assertSame([1, 2], array_keys($summaries));
        $this->assertArrayNotHasKey('ports', $summaries[1], 'without the ports');
        $this->assertSame(['ntp' => ['fail', '']], $summaries[1]['outcomes']);
        $this->assertSame([['name' => 'Ethernet1']], $results->devices([1])[1]['ports']);

        $this->assertSame(2, $results->forgetOthers([2]));
        $this->assertSame([2], array_keys($results->devices(null)));
        $this->assertSame(1, $results->page(null, [], 1, 50)['total'], 'their findings are gone too');
        $this->assertSame(0, $results->forgetOthers([2]));
    }
}
