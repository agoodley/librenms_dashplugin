<?php

namespace Agoodley\LibrenmsCompliance\Tests\Unit;

use Agoodley\LibrenmsCompliance\Data\ConfigStore;
use Agoodley\LibrenmsCompliance\Data\ResultRepository;
use Agoodley\LibrenmsCompliance\Services\Backup;
use Agoodley\LibrenmsCompliance\Services\CheckService;
use Agoodley\LibrenmsCompliance\Support\PluginSettings;
use Agoodley\LibrenmsCompliance\Tests\Unit\Fakes\FakeBackups;
use Agoodley\LibrenmsCompliance\Tests\Unit\Fakes\FakeNetwork;
use Agoodley\LibrenmsCompliance\Tests\Unit\Fakes\RecordingEvents;
use Agoodley\LibrenmsCompliance\Tests\Unit\Fakes\SqliteSql;
use PHPUnit\Framework\TestCase;

/**
 * The checks of the devices: each device's latest backup and the rules on it, the results stored, what changed in the
 * eventlog; devices without a backup, a backup tool that cannot be reached, devices no longer checked, the interval.
 */
class CheckServiceTest extends TestCase
{
    private const T1 = 1793520000; // 2026-11-01 08:00:00 UTC

    private SqliteSql $sql;

    private FakeNetwork $network;

    private FakeBackups $backups;

    private RecordingEvents $events;

    private int $now = self::T1;

    protected function setUp(): void
    {
        $this->sql = new SqliteSql();
        $this->events = new RecordingEvents();
        $this->backups = new FakeBackups();
        $this->network = new FakeNetwork();
        // acc-sw1 (2): Po1 (Gi1/0/47-48) up to core-sw1 (1), Gi1/0/5 down to acc-sw2 (3); leaf1 (5); a server (8); dist-nx1 (9)
        $this->network->devices = [2 => ['name' => 'acc-sw1', 'os' => 'ios', 'groups' => ['access']], 5 => ['name' => 'leaf1', 'os' => 'arista_eos'],
            8 => ['name' => 'srv1', 'os' => 'linux'], 9 => ['name' => 'dist-nx1', 'os' => 'nxos', 'groups' => ['access']], 10 => ['name' => 'old-sw', 'os' => 'ios', 'disabled' => true]];
        $this->network->groups = ['access'];
        $this->network->ports[2] = [100 => ['ifName' => 'Po1', 'ifDescr' => 'Port-channel1'], 103 => ['ifName' => 'Gi1/0/3', 'ifDescr' => 'GigabitEthernet1/0/3'],
            105 => ['ifName' => 'Gi1/0/5', 'ifDescr' => 'GigabitEthernet1/0/5'], 147 => ['ifName' => 'Gi1/0/47', 'ifDescr' => 'GigabitEthernet1/0/47'],
            148 => ['ifName' => 'Gi1/0/48', 'ifDescr' => 'GigabitEthernet1/0/48']];
        $this->network->neighborhoods[2] = ['links' => [
            ['local_port_id' => 147, 'remote_device_id' => 1, 'remote_port_id' => 11, 'remote_hostname' => 'core-sw1', 'remote_port' => 'Te1/1/1'],
            ['local_port_id' => 148, 'remote_device_id' => 1, 'remote_port_id' => 12, 'remote_hostname' => 'core-sw1', 'remote_port' => 'Te1/1/2'],
            ['local_port_id' => 105, 'remote_device_id' => 3, 'remote_port_id' => 349, 'remote_hostname' => 'acc-sw2', 'remote_port' => 'Gi1/0/49'],
        ], 'bridges' => [1 => true, 2 => true, 3 => true], 'rootPorts' => [2 => [100], 3 => [349]], 'lagParent' => [147 => 100, 148 => 100],
            'names' => [1 => 'core-sw1', 3 => 'acc-sw2']];
        $this->backups->backups = [2 => Backup::found('Oxidized', ConfigTest::fixture('ios-access'), self::T1 - 600),
            5 => Backup::found('Oxidized', ConfigTest::fixture('eos-leaf'), self::T1 - 900),
            9 => Backup::failed('Oxidized', 'Oxidized cannot be reached.', true)];
    }

    private function service(array $settings = []): CheckService
    {
        return new CheckService($this->network, $this->backups, new ResultRepository($this->sql), new PluginSettings($settings), $this->events,
            new ConfigStore($this->sql), fn () => $this->now);
    }

    private function results(): ResultRepository
    {
        return new ResultRepository($this->sql);
    }

    public function testACheckOfEveryDevice(): void
    {
        $seen = [];
        $summary = $this->service()->checkAll(function (int $id, string $name, array $result) use (&$seen): void {
            $seen[$name] = $result['status'];
        });

        $this->assertSame(['acc-sw1' => Backup::FOUND, 'leaf1' => Backup::FOUND, 'srv1' => Backup::NO_BACKUP, 'dist-nx1' => Backup::ERROR], $seen, 'not the disabled device');
        $this->assertSame([4, 2, 1, 1, null], [$summary['devices'], $summary['checked'], $summary['no_backup'], $summary['errors'], $summary['error']]);
        $this->assertSame(['Oxidized' => 'Oxidized cannot be reached.'], $summary['unreachable']);
        $this->assertSame($summary['findings'], $summary['new'], 'every finding is new');

        $results = $this->results();
        $this->assertSame(['snmp-community', 'snmp-v3-priv', 'ssh-only', 'stp-edge-bpduguard', 'stp-edge-portfast', 'stp-portfast-switch', 'stp-root-guard'],
            array_keys($results->findings(2)));
        $this->assertSame('critical', $results->findings(2)['stp-portfast-switch']['severity']);
        $device = $results->device(2);
        $this->assertSame([ResultRepository::CHECKED, 'Oxidized', '2026-11-01 07:50:00', hash('sha256', ConfigTest::fixture('ios-access'))],
            [$device['status'], $device['provider'], $device['backup_at'], $device['config_hash']]);
        $this->assertSame(['pass', ''], $device['outcomes']['aaa']);
        $this->assertSame('fail', $device['outcomes']['stp-root-guard'][0]);
        $ports = array_column($device['ports'], null, 'name');
        $this->assertSame([105, 'downstream', 'on'], [$ports['GigabitEthernet1/0/5']['port_id'], $ports['GigabitEthernet1/0/5']['role'], $ports['GigabitEthernet1/0/5']['portfast']]);
        $this->assertSame('upstream', $ports['Port-channel1']['role']);
        $this->assertSame([Backup::NO_BACKUP, []], [$results->device(8)['status'], $results->device(8)['outcomes']]);
        $this->assertSame([Backup::ERROR, 'Oxidized cannot be reached.'], [$results->device(9)['status'], $results->device(9)['error']]);
        $this->assertNull($results->device(10));

        $messages = array_map(fn (array $entry) => [$entry['device'], $entry['severity'], $entry['message']], $this->events->entries);
        $this->assertSame([2, 'error'], array_slice($messages[0], 0, 2));
        $this->assertSame('Compliance: first check, 7 findings: No portfast toward a switch (critical), Edge ports have portfast (warning), Edge ports have BPDU guard (warning), '
            . 'Root guard toward the switches below (warning), No SNMPv1/v2c community (warning) and 2 more', $messages[0][2]);
        $this->assertSame(5, $messages[1][0]);
        $this->assertSame([null, 'warning', 'Compliance: Oxidized cannot be reached. The devices it backs up were not checked; their last results stay.'], end($messages),
            'one entry for the backup tool, none for each of its devices');
        $this->assertCount(3, $messages, 'nothing for the device without a backup');
        $this->assertSame(['started' => self::T1, 'checked' => 2], array_intersect_key($this->service()->lastRun(), ['started' => 0, 'checked' => 0]));
    }

    public function testWhatChangedGoesToTheEventlog(): void
    {
        $custom = "- id: http-server-off\n  title: HTTP server off\n  os: [ios]\n  must_not: '^ip http server$'\n";
        $this->service(['custom_rules' => $custom])->checkAll();
        $this->events->entries = [];

        // Gi1/0/5 loses its portfast, the HTTP server is switched on
        $config = str_replace(" spanning-tree portfast trunk\n", '', ConfigTest::fixture('ios-access')) . "ip http server\n";
        $this->backups->backups[2] = Backup::found('Oxidized', $config, self::T1 + 1800);
        $this->now = self::T1 + 3600;
        $summary = $this->service(['custom_rules' => $custom])->checkAll();

        $this->assertSame([1, 1], [$summary['new'], $summary['resolved']]);
        $entries = array_values(array_filter($this->events->entries, fn (array $entry) => $entry['device'] === 2));
        $this->assertSame([['warning', 'Compliance: 1 new finding: HTTP server off (warning)'], ['ok', 'Compliance: 1 finding resolved: No portfast toward a switch']],
            array_map(fn (array $entry) => [$entry['severity'], $entry['message']], $entries));
        $this->assertNotContains(5, array_column($this->events->entries, 'device'), 'nothing changed on leaf1');
        $this->assertCount(2, $this->events->entries, 'Oxidized was already unreachable on the run before');
        $findings = $this->results()->findings(2);
        $this->assertSame(['2026-11-01 08:00:00', '2026-11-01 09:00:00'], [$findings['ssh-only']['first_seen'], $findings['ssh-only']['last_seen']]);
        $this->assertSame([[126, 'ip http server']], $findings['http-server-off']['evidence']);

        // only the critical findings, then none
        $this->events->entries = [];
        $this->backups->backups[2] = Backup::found('Oxidized', ConfigTest::fixture('ios-access'), self::T1 + 5400);
        $this->service(['eventlog_minimum' => 'critical'])->checkAll();
        $this->assertSame(['Compliance: 1 new finding: No portfast toward a switch (critical)'], array_column($this->events->entries, 'message'),
            'the resolved HTTP finding is a warning');
        $this->events->entries = [];
        $this->backups->backups[2] = Backup::found('Oxidized', $config, self::T1 + 7200);
        $this->service(['eventlog' => '0'])->checkAll();
        $this->assertSame([], $this->events->entries);
    }

    public function testABackupThatCannotBeReadKeepsTheResultsBefore(): void
    {
        $this->service()->checkAll();
        $before = $this->results()->findings(2);
        $this->events->entries = [];

        $this->backups->backups[2] = Backup::failed('Oxidized', 'Oxidized answered with an error.');
        $this->now = self::T1 + 3600;
        $this->service()->checkAll();
        $device = $this->results()->device(2);
        $this->assertSame([Backup::ERROR, 'Oxidized answered with an error.', '2026-11-01 07:50:00'], [$device['status'], $device['error'], $device['backup_at']]);
        $this->assertSame(array_keys($before), array_keys($this->results()->findings(2)), 'the findings stay');
        $this->assertNotEmpty($device['outcomes']);
        $this->assertSame([[2, 'warning', 'Compliance: the configuration cannot be checked: Oxidized answered with an error.']],
            array_map(fn (array $entry) => [$entry['device'], $entry['severity'], $entry['message']], $this->events->entries), 'once, when it starts failing');

        $this->events->entries = [];
        $this->service()->checkAll();
        $this->assertSame([], $this->events->entries, 'not again while it fails');

        $this->backups->backups[2] = Backup::found('Oxidized', str_repeat("hostname x\n", 200001), self::T1);
        $result = $this->service()->checkDevice(2);
        $this->assertSame([Backup::ERROR, 'The backup has more than 200,000 lines: too long to check.'], [$result['status'], $result['why']]);
        $this->assertSame(array_keys($before), array_keys($this->results()->findings(2)), 'the findings stay while the backup cannot be read');

        // the device has no backup any more: nothing is known of it
        $this->events->entries = [];
        $this->backups->backups[2] = Backup::found('Oxidized', "! only a comment\n!\nend\n", self::T1);
        $result = $this->service()->checkDevice(2);
        $this->assertSame([Backup::NO_BACKUP, 'The backup has no configuration lines.'], [$result['status'], $result['why']]);
        $this->assertSame([], $this->results()->findings(2));
        $device = $this->results()->device(2);
        $this->assertSame([[], [], null], [$device['outcomes'], $device['ports'], $device['backup_at']]);
        $this->assertSame([[2, 'notice', 'Compliance: no backup to check any more: The backup has no configuration lines. Its 7 findings went with it.']],
            array_map(fn (array $entry) => [$entry['device'], $entry['severity'], $entry['message']], $this->events->entries), 'not reported as resolved');
    }

    public function testDevicesNoLongerCheckedAreForgotten(): void
    {
        $this->service()->checkAll();
        $this->assertNotEmpty($this->results()->findings(5));

        $this->network->devices[5]['disabled'] = true;
        $summary = $this->service()->checkAll();
        $this->assertSame(1, $summary['forgotten']);
        $this->assertNull($this->results()->device(5));
        $this->assertSame([], $this->results()->findings(5));

        $summary = $this->service(['device_group' => 'access'])->checkAll();
        $this->assertSame([2, 1], [$summary['devices'], $summary['forgotten']], 'the device group\'s devices only: srv1 goes (leaf1 went before)');
        $this->assertSame([2, 9], array_keys($this->results()->devices(null)));

        $summary = $this->service(['device_group' => 'gone'])->checkAll();
        $this->assertSame('LibreNMS has no device group gone (the Device group setting)', $summary['error']);
        $this->assertSame([2, 9], array_keys($this->results()->devices(null)), 'nothing is forgotten when the run cannot run');
    }

    public function testOneDeviceNow(): void
    {
        $result = $this->service()->checkDevice(2);
        $this->assertSame([Backup::FOUND, 'Oxidized', 7, 7, 0], [$result['status'], $result['provider'], $result['findings'], $result['new'], $result['resolved']]);
        $this->assertNull($this->service()->lastRun(), 'no run of every device');
        $this->assertTrue($this->service()->covers(2));
        $this->assertFalse($this->service()->covers(10));

        foreach ([[10, [], 'The plugin checks the enabled devices: this one is disabled.'], [77, [], 'LibreNMS has no such device.'],
            [5, ['device_group' => 'access'], 'The plugin checks the enabled devices of the device group access (its settings): not this one.']] as [$id, $settings, $message]) {
            try {
                $this->service($settings)->checkDevice($id);
                $this->fail("device $id is not checked");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function testWhenTheChecksDoNotRun(): void
    {
        $this->backups->unavailable = 'This LibreNMS has no configuration backup interface the plugin can read.';
        $summary = $this->service()->checkAll();
        $this->assertSame([$this->backups->unavailable, 0, []], [$summary['error'], $summary['devices'], $this->backups->asked]);

        $this->backups->unavailable = null;
        $broken = new CheckService($this->network, $this->backups, new ResultRepository($this->sql), new PluginSettings([], 'The plugin\'s settings cannot be read.'),
            $this->events, new ConfigStore($this->sql), fn () => $this->now);
        $this->assertSame('The plugin\'s settings cannot be read.', $broken->checkAll()['error'], 'never with the defaults instead of the settings');

        $empty = new SqliteSql(false);
        $none = new CheckService($this->network, $this->backups, new ResultRepository($empty), new PluginSettings(), $this->events, new ConfigStore($empty), fn () => $this->now);
        $this->assertSame('The plugin\'s tables are missing: run ./lnms migrate in the LibreNMS directory.', $none->checkAll()['error']);
        $this->expectException(\InvalidArgumentException::class);
        $none->checkDevice(2);
    }

    public function testTheInterval(): void
    {
        $service = $this->service(['interval_hours' => '4']);
        $this->assertTrue($service->due(), 'never checked');
        $service->checkAll();
        $this->now = self::T1 + 3600;
        $this->assertFalse($service->due());
        $this->now = self::T1 + 4 * 3600 - 300;
        $this->assertTrue($service->due(), 'the hourly schedule drifts by seconds: a few minutes early is due');

        $this->now = self::T1 + 3600;
        $service->request();
        $this->assertTrue($service->due(), 'the settings changed');
        $service->checkAll();
        $this->assertFalse($service->due(), 'the run clears the request');
    }
}
