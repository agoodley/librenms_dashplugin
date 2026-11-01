<?php

namespace Agoodley\LibrenmsInventory\Tests\Unit;

use Agoodley\LibrenmsInventory\Data\ConfigStore;
use Agoodley\LibrenmsInventory\Data\SettingsRepository;
use Agoodley\LibrenmsInventory\Data\SoftwareRepository;
use Agoodley\LibrenmsInventory\Services\SoftwareCheck;
use Agoodley\LibrenmsInventory\Software\Status;
use Agoodley\LibrenmsInventory\Support\PluginSettings;
use Agoodley\LibrenmsInventory\Tests\Unit\Fakes\FakeNetwork;
use Agoodley\LibrenmsInventory\Tests\Unit\Fakes\RecordingEvents;
use Agoodley\LibrenmsInventory\Tests\Unit\Fakes\SqliteSql;
use PHPUnit\Framework\TestCase;

/**
 * The software check: each device's status stored with since when, written only when it changed; the version changes
 * recorded; what changed in the eventlog; devices no longer covered forgotten; the settings, stored.
 */
class SoftwareCheckTest extends TestCase
{
    private const T1 = 1793520000; // 2026-11-01 08:00:00 UTC

    private SqliteSql $sql;

    private FakeNetwork $network;

    private RecordingEvents $events;

    private int $now = self::T1;

    protected function setUp(): void
    {
        $this->sql = new SqliteSql();
        $this->events = new RecordingEvents();
        $this->network = new FakeNetwork();
        $this->network->devices = [
            1 => ['name' => 'core-sw1', 'os' => 'iosxe', 'hardware' => 'C9300-48P', 'version' => '17.9.5', 'groups' => ['core']],
            2 => ['name' => 'acc-sw1', 'os' => 'iosxe', 'hardware' => 'C9300-24T', 'version' => '17.3.1'],
            3 => ['name' => 'acc-stack', 'os' => 'ios', 'hardware' => 'WS-C2960X-48FPS-L', 'version' => '15.2(4)E10', 'models' => ['WS-C2960X-48FPS-L', 'WS-C2960X-24PS-L']],
            4 => ['name' => 'leaf1', 'os' => 'arista_eos', 'hardware' => 'DCS-7050SX3', 'version' => ''],
            5 => ['name' => 'srv1', 'os' => 'linux', 'hardware' => '', 'version' => '6.1'],
            6 => ['name' => 'old-sw', 'os' => 'ios', 'hardware' => 'WS-C2960X-48FPS-L', 'version' => '15.0(2)SE11', 'disabled' => true],
        ];
        $this->network->groups = ['core'];
    }

    private function check(array $settings = []): SoftwareCheck
    {
        return new SoftwareCheck($this->network, new SoftwareRepository($this->sql), new PluginSettings($settings + ['policies' => PoliciesTest::POLICIES]),
            $this->events, new ConfigStore($this->sql), fn (): int => $this->now);
    }

    private function rows(): array
    {
        return (new SoftwareRepository($this->sql))->rows(null);
    }

    public function testTheFirstCheck(): void
    {
        $summary = $this->check()->run();

        $this->assertNull($summary['error']);
        $this->assertSame(5, $summary['devices'], 'the enabled devices');
        $this->assertSame([1, 1, 0, 1, 0, 1, 1, 2, 1], array_values($summary['counts']));
        $this->assertSame(2, $summary['failing']);
        $rows = $this->rows();
        $this->assertSame([1, 2, 3, 4, 5], array_keys($rows));
        $this->assertSame([Status::APPROVED, Status::BLOCKED, Status::BELOW_MINIMUM, Status::UNKNOWN, Status::NO_POLICY], array_column($rows, 'status'));
        $this->assertSame(['iosxe', 'C9300-24T', '17.3.1', 'IOS-XE advisories', '2026-11-01 08:00:00', '2026-11-01 08:00:00'],
            [$rows[2]['os'], $rows[2]['model'], $rows[2]['version'], $rows[2]['policy'], $rows[2]['status_since'], $rows[2]['version_since']]);
        $this->assertSame([
            ['message' => 'Inventory: software not compliant: 17.3.1 is blocked by the policy IOS-XE advisories. Note: cisco-sa-iosxe-webui-privesc', 'device' => 2, 'severity' => 'error'],
            ['message' => 'Inventory: software not compliant: 15.2(4)E10 is older than 15.2(7)E8, the minimum of the policy Catalyst 2960-X.', 'device' => 3, 'severity' => 'warning'],
        ], $this->events->entries, 'the devices that are not compliant, at their first check');
        $this->assertSame($summary, $this->check()->lastRun());
        $this->assertSame(self::T1, $this->check()->firstRun(), 'the version changes are recorded since');
        $this->now += 3600;
        $this->check()->run();
        $this->assertSame(self::T1, $this->check()->firstRun());
        $this->assertSame('5 devices: 2 not compliant (2 new), 1 compliant, 1 version unknown, 1 without a policy; 0 resolved, 0 upgrades, 0 downgrades',
            SoftwareCheck::describe($summary));
    }

    public function testOnlyWhatChangedIsWritten(): void
    {
        $this->check()->run();
        $this->events->entries = [];
        $this->now += 3600;

        $summary = $this->check()->run();

        $this->assertSame([0, 0, 0, 0], [$summary['failing'], $summary['resolved'], $summary['upgrades'], $summary['downgrades']]);
        $this->assertSame([], $this->events->entries);
        $this->assertSame(['2026-11-01 08:00:00'], array_values(array_unique(array_column($this->rows(), 'updated_at'))), 'no row written again');
    }

    public function testUpgradesAreRecorded(): void
    {
        $this->check()->run();
        $this->events->entries = [];
        $this->now += 3600;
        $this->network->devices[2]['version'] = '17.9.5'; // blocked to approved
        $this->network->devices[3]['version'] = '15.2(7)E10'; // below the minimum to allowed
        $this->network->devices[1]['version'] = '17.10.1'; // approved to not approved

        $summary = $this->check()->run();

        $this->assertSame([1, 2, 3, 0], [$summary['failing'], $summary['resolved'], $summary['upgrades'], $summary['downgrades']]);
        $rows = $this->rows();
        $this->assertSame([Status::NOT_APPROVED, Status::APPROVED, Status::ALLOWED], [$rows[1]['status'], $rows[2]['status'], $rows[3]['status']]);
        $this->assertSame(['2026-11-01 09:00:00', '2026-11-01 09:00:00'], [$rows[2]['status_since'], $rows[2]['version_since']]);
        $this->assertSame('2026-11-01 08:00:00', $rows[4]['status_since'], 'unchanged');
        $this->assertSame([
            ['message' => 'Inventory: software upgraded from 17.9.5 to 17.10.1', 'device' => 1, 'severity' => 'info'],
            ['message' => 'Inventory: software not compliant: 17.10.1 is not approved by the policy Catalyst 9300 (approved: 17.9.5, 17.12.*).', 'device' => 1, 'severity' => 'warning'],
            ['message' => 'Inventory: software upgraded from 17.3.1 to 17.9.5', 'device' => 2, 'severity' => 'info'],
            ['message' => 'Inventory: software compliant: 17.9.5 is approved by the policy Catalyst 9300.', 'device' => 2, 'severity' => 'ok'],
            ['message' => 'Inventory: software upgraded from 15.2(4)E10 to 15.2(7)E10', 'device' => 3, 'severity' => 'info'],
            ['message' => 'Inventory: software compliant: 15.2(7)E10 is allowed by the policy Catalyst 2960-X: not blocked, and not older than its minimum 15.2(7)E8.',
                'device' => 3, 'severity' => 'ok'],
        ], $this->events->entries);
        $upgrades = (new SoftwareRepository($this->sql))->upgrades(null);
        $this->assertSame(3, $upgrades['total']);
        $this->assertSame([3, 'ios', 'WS-C2960X-48FPS-L', '15.2(4)E10', '15.2(7)E10', 'upgrade', '2026-11-01 08:00:00', '2026-11-01 09:00:00'],
            array_values(array_diff_key($upgrades['rows'][0], ['id' => 1])), 'the newest first');
        $this->assertSame([2], array_column((new SoftwareRepository($this->sql))->upgrades([2])['rows'], 'device_id'));

        // back down: a downgrade, a notice; with the setting off, not in the eventlog
        $this->events->entries = [];
        $this->now += 3600;
        $this->network->devices[1]['version'] = '17.9.5';
        $this->network->devices[2]['version'] = '17.3.1';
        $summary = $this->check(['eventlog_upgrades' => '0'])->run();
        $this->assertSame([2, 0], [$summary['downgrades'], $summary['upgrades']]);
        $this->assertSame(['Inventory: software compliant: 17.9.5 is approved by the policy Catalyst 9300.',
            'Inventory: software not compliant: 17.3.1 is blocked by the policy IOS-XE advisories. Note: cisco-sa-iosxe-webui-privesc'], array_column($this->events->entries, 'message'));
        $this->assertSame(['downgrade', '2026-11-01 09:00:00'], [(new SoftwareRepository($this->sql))->upgrades([1])['rows'][0]['direction'],
            (new SoftwareRepository($this->sql))->upgrades([1])['rows'][0]['from_since']]);
    }

    public function testALostVersionIsNoChange(): void
    {
        $this->check()->run();
        $this->now += 3600;
        $this->network->devices[1]['version'] = '';
        $this->check()->run();
        $row = $this->rows()[1];
        $this->assertSame([Status::UNKNOWN, '17.9.5', '2026-11-01 08:00:00'], [$row['status'], $row['version'], $row['version_since']], 'the row keeps the last version');
        $this->assertStringEndsWith('The last version seen: 17.9.5.', $row['message']);

        $this->now += 3600;
        $this->network->devices[1]['version'] = '17.9.5';
        $this->events->entries = [];
        $summary = $this->check()->run();
        $this->assertSame([0, []], [$summary['upgrades'], $this->events->entries], 'back as it was: no upgrade');
        $this->assertSame(Status::APPROVED, $this->rows()[1]['status']);

        // a first version is no upgrade either
        $this->network->devices[4]['version'] = '4.30.1F';
        $this->assertSame(0, $this->check()->run()['upgrades']);
        $this->assertSame([Status::APPROVED, '4.30.1F'], [$this->rows()[4]['status'], $this->rows()[4]['version']]);
    }

    public function testAVersionWrittenAnotherWayIsNoUpgrade(): void
    {
        $this->check()->run();
        $this->events->entries = [];
        $this->now += 3600;
        $this->network->devices[1]['version'] = '17.09.05'; // 17.9.5, as Cisco pads it in some outputs

        $summary = $this->check()->run();

        $this->assertSame([0, 0, []], [$summary['upgrades'], $summary['downgrades'], $this->events->entries], 'the same release: no upgrade, nothing in the eventlog');
        $row = $this->rows()[1];
        $this->assertSame([Status::APPROVED, '17.09.05', '2026-11-01 08:00:00'], [$row['status'], $row['version'], $row['version_since']],
            'the row takes the new text, and keeps since when the device runs the release');

        $this->now += 3600;
        $this->network->devices[1]['version'] = '17.09.04a';
        $summary = $this->check()->run();
        $this->assertSame([0, 1], [$summary['upgrades'], $summary['downgrades']]);
        $this->assertSame(['Inventory: software downgraded from 17.09.05 to 17.09.04a',
            'Inventory: software not compliant: 17.09.04a is not approved by the policy Catalyst 9300 (approved: 17.9.5, 17.12.*).'], array_column($this->events->entries, 'message'),
            'not below the minimum 17.9.4');
    }

    public function testAVersionWithTheImageFirstIsItsRelease(): void
    {
        $this->network->devices[1]['version'] = 'CAT9K_IOSXE 17.9.5';
        $this->network->devices[2]['version'] = 'CAT9K_IOSXE 16.12.4';
        $this->check()->run();
        $this->assertSame([Status::APPROVED, Status::BLOCKED], [$this->rows()[1]['status'], $this->rows()[2]['status']]);
        $this->events->entries = [];

        $this->now += 3600;
        $this->network->devices[1]['version'] = '17.9.5'; // the same release, written without the image
        $this->network->devices[2]['version'] = 'CAT9K_IOSXE 17.12.4';
        $summary = $this->check()->run();

        $this->assertSame([1, 0], [$summary['upgrades'], $summary['downgrades']], 'only the real upgrade');
        $this->assertSame(['Inventory: software upgraded from CAT9K_IOSXE 16.12.4 to CAT9K_IOSXE 17.12.4',
            'Inventory: software compliant: CAT9K_IOSXE 17.12.4 is approved by the policy Catalyst 9300.'], array_column($this->events->entries, 'message'));
        $this->assertSame(['17.9.5', '2026-11-01 08:00:00'], [$this->rows()[1]['version'], $this->rows()[1]['version_since']]);
    }

    public function testThePoliciesChangeTheStatus(): void
    {
        $this->check()->run();
        $this->events->entries = [];
        $this->now += 600;

        $summary = $this->check(['policies' => ''])->run();

        $this->assertSame(2, $summary['resolved']);
        $this->assertSame(array_fill(0, 5, Status::NO_POLICY), array_column($this->rows(), 'status'));
        $this->assertSame(['Inventory: software not checked any more: No policy applies to it.', 'Inventory: software not checked any more: No policy applies to it.'],
            array_column($this->events->entries, 'message'));
        $this->assertSame(['notice', 'notice'], array_column($this->events->entries, 'severity'));
        $this->events->entries = [];

        $this->check(['policies' => PoliciesTest::POLICIES, 'eventlog' => '0'])->run();
        $this->assertSame([], $this->events->entries, 'the eventlog off');
    }

    public function testDevicesNoLongerCoveredAreForgotten(): void
    {
        $this->network->devices[2]['version'] = '17.6.1';
        $this->check()->run();
        $this->network->devices[2]['version'] = '17.9.5';
        $this->check()->run();
        $this->assertSame(1, (new SoftwareRepository($this->sql))->upgrades([2])['total']);

        $summary = $this->check(['device_group' => 'core'])->run();
        $this->assertSame([1, 4], [$summary['devices'], $summary['forgotten']]);
        $this->assertSame([1], array_keys($this->rows()));
        $this->assertSame(1, (new SoftwareRepository($this->sql))->upgrades([2])['total'], 'the version changes of a device LibreNMS has stay');

        unset($this->network->devices[2]);
        $this->check(['device_group' => 'core'])->run();
        $this->assertSame(0, (new SoftwareRepository($this->sql))->upgrades(null)['total'], 'those of a device LibreNMS no longer has go');

        $this->assertSame('LibreNMS has no device group nope', $this->check(['device_group' => 'nope'])->run()['error']);
        $this->assertSame([1], array_keys($this->rows()), 'a run that fails forgets nothing');
    }

    public function testWhatStopsACheck(): void
    {
        $none = new SoftwareCheck($this->network, new SoftwareRepository(new SqliteSql(false)), new PluginSettings(), $this->events, new ConfigStore(new SqliteSql(false)));
        $this->assertSame('The plugin\'s tables are missing: run ./lnms migrate in the LibreNMS directory.', $none->run()['error']);
        $this->assertNull($none->lastRun());
        $this->assertNull($none->firstRun());

        $this->assertStringStartsWith('The software policies of the settings have errors: Policy 1', $this->check(['policies' => "- os: ios\n"])->run()['error']);

        $locked = 0;
        $exclusive = function (\Closure $work) use (&$locked): mixed {
            $locked++;

            return $work();
        };
        $settings = new PluginSettings(['policies' => PoliciesTest::POLICIES]);
        (new SoftwareCheck($this->network, new SoftwareRepository($this->sql), $settings, $this->events, new ConfigStore($this->sql), fn (): int => $this->now, $exclusive))->run();
        $this->assertSame([1, 5], [$locked, count($this->rows())], 'the work of a check runs while no other check runs');
        $busy = fn (\Closure $work): mixed => throw new \RuntimeException('the lock is taken');
        $this->assertSame('The software check failed: see logs/librenms.log.', (new SoftwareCheck($this->network, new SoftwareRepository($this->sql), $settings, $this->events,
            new ConfigStore($this->sql), fn (): int => $this->now, $busy))->run()['error']);
        $this->sql->pdo->exec('DELETE FROM `inventory_software`');

        $this->sql->failOn = 'inventory_software';
        $summary = $this->check()->run();
        $this->assertSame('The software check failed: see logs/librenms.log.', $summary['error']);
        $this->assertSame([], $this->rows(), 'a device whose row fails leaves nothing behind');
    }

    public function testTheSettingsAreCheckedAndStored(): void
    {
        $result = PluginSettings::validate(['device_group' => ' core ', 'eventlog' => '1', 'eventlog_upgrades' => '0', 'policies' => "- os: ios\r\n  minimum: '15.2'\r\n\r\n"]);
        $this->assertSame([], $result['errors']);
        $settings = $result['settings'];
        $this->assertSame(['core', true, false, "- os: ios\n  minimum: '15.2'\n"], [$settings->deviceGroup(), $settings->eventlog(), $settings->eventlogUpgrades(),
            $settings->policiesText()]);
        $this->assertCount(1, $settings->policies()['policies']);

        $errors = PluginSettings::validate(['eventlog' => 'yes', 'eventlog_upgrades' => '1', 'policies' => "- os: ios\n"])['errors'];
        $this->assertSame(['eventlog', 'policies'], array_keys($errors));
        $this->assertNull(PluginSettings::validate(['eventlog' => 'yes'])['settings'], 'nothing is saved when one is wrong');

        $repository = new SettingsRepository(new ConfigStore($this->sql));
        $this->assertSame(PluginSettings::DEFAULTS, $repository->get()->all());
        $repository->save($settings);
        $this->assertSame($settings->all(), $repository->get()->all());
        $this->sql->pdo->exec("UPDATE `inventory_config` SET `value` = 'not json' WHERE `name` = 'settings'");
        $this->assertNotNull($repository->get()->error, 'settings that cannot be read say so');
        $this->assertSame(PluginSettings::DEFAULTS, (new SettingsRepository(new ConfigStore(new SqliteSql(false))))->get()->all(), 'no table yet: the defaults');
    }
}
