<?php

namespace Agoodley\LibrenmsCompliance\Tests\Unit;

use Agoodley\LibrenmsCompliance\Data\ResultRepository;
use Agoodley\LibrenmsCompliance\Rules\BuiltinRules;
use Agoodley\LibrenmsCompliance\Services\Overview;
use Agoodley\LibrenmsCompliance\Support\Csv;
use Agoodley\LibrenmsCompliance\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

/**
 * The settings form (what it accepts, the rules a check runs) and what the overview counts of the results.
 */
class SettingsTest extends TestCase
{
    private static function form(array $values = []): array
    {
        return $values + ['interval_hours' => '6', 'device_group' => ' access ', 'eventlog' => '1', 'eventlog_minimum' => 'critical', 'disabled_rules' => ['syslog', 'nope'],
            'ntp_servers' => "10.0.0.10, 10.0.0.11\n10.0.0.10", 'syslog_servers' => '', 'aaa_servers' => 'tacacs1.example.net', 'custom_rules' => ''];
    }

    public function testTheFormIsCheckedBeforeItIsSaved(): void
    {
        $result = PluginSettings::validate(self::form());
        $this->assertSame([], $result['errors']);
        $settings = $result['settings'];
        $this->assertSame([6, 'access', true, 'critical', ['syslog']], [$settings->intervalHours(), $settings->deviceGroup(), $settings->eventlog(),
            $settings->eventlogMinimum(), $settings->disabledRules()], 'only the plugin\'s rules can be switched off');
        $this->assertSame(['10.0.0.10', '10.0.0.11'], $settings->servers('ntp_servers'), 'each server once');
        $this->assertSame(array_values(array_diff(array_keys(BuiltinRules::RULES), ['syslog'])), array_keys($settings->rules()));

        $errors = PluginSettings::validate(self::form(['interval_hours' => '0', 'eventlog' => 'yes', 'ntp_servers' => '10.0.0.10 ;rm -rf', 'aaa_servers' => implode(' ', range(1, 21)),
            'custom_rules' => "- id: ntp\n  title: mine\n  must: x\n"]))['errors'];
        $this->assertSame(['interval_hours', 'eventlog', 'ntp_servers', 'aaa_servers', 'custom_rules'], array_keys($errors));
        $this->assertSame('not an address or a host name: -rf', $errors['ntp_servers']);
        $this->assertSame('at most 20 servers', $errors['aaa_servers']);
        $this->assertStringContainsString('ntp', $errors['custom_rules'], 'a rule may not take a built-in rule\'s id');
        $this->assertNull(PluginSettings::validate(self::form(['interval_hours' => '25']))['settings'], 'nothing is saved when one is wrong');

        $custom = PluginSettings::validate(self::form(['custom_rules' => "- id: http-off\r\n  title: HTTP off\r\n  must_not: '^ip http server$'\r\n"]))['settings'];
        $this->assertSame("- id: http-off\n  title: HTTP off\n  must_not: '^ip http server$'\n", $custom->all()['custom_rules']);
        $this->assertSame('http-off', array_key_last($custom->rules()), 'the admin\'s rules after the plugin\'s');
        $this->assertSame(['ntp_servers' => ['10.0.0.10', '10.0.0.11'], 'syslog_servers' => [], 'aaa_servers' => ['tacacs1.example.net']], array_map(
            fn (string $key) => $custom->servers($key), array_combine(array_keys(PluginSettings::SERVERS), array_keys(PluginSettings::SERVERS))));

        $defaults = new PluginSettings(['interval_hours' => '99', 'eventlog_minimum' => 'loud', 'unknown' => 'x']);
        $this->assertSame([24, 'warning'], [$defaults->intervalHours(), $defaults->eventlogMinimum()], 'stored values out of range are bounded');
        $this->assertArrayNotHasKey('unknown', $defaults->all());
    }

    public function testWhatTheOverviewCounts(): void
    {
        $device = fn (string $status, array $outcomes) => ['status' => $status, 'outcomes' => $outcomes, 'backup_at' => null, 'provider' => null, 'checked_at' => '2026-11-01 08:00:00',
            'error' => null];
        $devices = [
            1 => $device(ResultRepository::CHECKED, ['ntp' => ['fail', ''], 'aaa' => ['pass', ''], 'stp-root-guard' => ['fail', '']]),
            2 => $device(ResultRepository::CHECKED, ['ntp' => ['pass', ''], 'aaa' => ['pass', '']]),
            3 => $device(ResultRepository::CHECKED, ['ntp' => ['na', 'not an OS the rule reads'], 'aaa' => ['na', '']]),
            4 => $device('no_backup', []),
            5 => $device('error', ['ntp' => ['fail', ''], 'aaa' => ['error', 'the rule failed']]),
        ];
        $counts = [1 => ['warning' => 2], 5 => ['critical' => 1]];

        $this->assertSame(['devices' => 5, 'checked' => 3, 'compliant' => 1, 'with_findings' => 2, 'critical' => 1, 'warning' => 2, 'info' => 0, 'no_backup' => 1, 'errors' => 1,
            'not_applicable' => 1], Overview::cards($devices, $counts), 'a device whose check failed counts with its earlier findings');

        $rules = (new PluginSettings(['disabled_rules' => array_values(array_diff(array_keys(BuiltinRules::RULES), ['ntp', 'aaa', 'stp-root-guard']))]))->rules();
        $stats = Overview::rules($devices, $rules);
        $this->assertSame(['ntp', 'stp-root-guard', 'aaa'], array_keys($stats), 'the rules that fail first, the worst of them and the most failed first');
        $this->assertSame(['fail' => 2, 'pass' => 1, 'na' => 1, 'error' => 0], array_intersect_key($stats['ntp'], array_flip(['fail', 'pass', 'na', 'error'])));
        $this->assertSame(1, $stats['aaa']['error']);

        $names = [1 => ['name' => 'acc-sw1', 'hostname' => '10.0.0.1'], 2 => ['name' => 'acc-sw2', 'hostname' => 'acc-sw2'], 3 => ['name' => 'leaf1', 'hostname' => 'leaf1'],
            4 => ['name' => 'srv1', 'hostname' => 'srv1'], 5 => ['name' => 'dist1', 'hostname' => 'dist1']];
        $this->assertSame([5, 1, 4, 2, 3], array_column(Overview::devices($devices + [9 => $device('error', [])], $counts, $names), 'device_id'),
            'the worst first, then by state and name; not a device LibreNMS no longer has');
        $this->assertSame([2], array_column(Overview::devices($devices, $counts, $names, 'compliant'), 'device_id'));
        $this->assertSame([5], array_column(Overview::devices($devices, $counts, $names, 'critical'), 'device_id'));
        $this->assertSame([5], array_column(Overview::devices($devices, $counts, $names, 'error'), 'device_id'));
        $this->assertSame([1], array_column(Overview::devices($devices, $counts, $names, '', '10.0.0'), 'device_id'), 'by hostname too');
        $this->assertSame(['findings', 'compliant', 'not_applicable', 'no_backup', 'findings'], array_map(fn (int $id) => Overview::state($devices[$id], $counts[$id] ?? []), [1, 2, 3, 4, 5]));
    }

    public function testTheCsvExport(): void
    {
        $this->assertSame("device,message\r\n\"'=HYPERLINK(\"\"x\"\")\",\"a, \"\"b\"\"\"\r\n-5,'-x\r\n", Csv::of([['device', 'message'], ['=HYPERLINK("x")', 'a, "b"'], ['-5', '-x']]),
            'a formula is text; numbers stay numbers');
    }
}
