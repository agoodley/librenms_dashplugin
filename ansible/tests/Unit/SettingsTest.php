<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Inventory\PlatformMap;
use Agoodley\LibrenmsAnsible\Support\AuditText;
use Agoodley\LibrenmsAnsible\Support\Connection;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    public function testDefaultsAndClamping(): void
    {
        $settings = new PluginSettings(['max_targets' => '900', 'output_max_kb' => 'lots', 'lost_after_hours' => '0', 'allowed_group' => '12', 'extra' => 'x']);

        $this->assertSame(500, $settings->maxTargets());
        $this->assertSame(1024 * 1024, $settings->outputMaxBytes());
        $this->assertSame(3600, $settings->lostAfterSeconds());
        $this->assertSame(12, $settings->allowedGroup());
        $this->assertArrayNotHasKey('extra', $settings->all());
        $this->assertNotNull($settings->platformMap()->for('iosxe'));
        $this->assertSame(25, (new PluginSettings())->maxTargets());
        $this->assertNull((new PluginSettings(['allowed_group' => '0']))->allowedGroup());
        $this->assertNull((new PluginSettings(['allowed_group' => 'core']))->allowedGroup());
    }

    private const GROUPS = [3 => 'Ansible lab', 4 => 'Core'];

    private const USERS = [1 => 'admin', 950 => 'ansinv'];

    public function testSettingsFromInputAreCheckedNotClamped(): void
    {
        $result = PluginSettings::fromInput(['max_targets' => '900', 'output_max_kb' => 'lots', 'lost_after_hours' => '0', 'allowed_group' => '12',
            'platform_map' => "ios cisco.ios.ios bogus\nnxos cisco.nxos.nxos", 'inventory_user' => '77'], new PluginSettings(), self::GROUPS, self::USERS);

        $this->assertNull($result['settings']);
        $this->assertSame(['platform_map', 'allowed_group', 'max_targets', 'output_max_kb', 'lost_after_hours', 'inventory_user'], array_keys($result['errors']));
        $this->assertStringContainsString('line 1 (ios cisco.ios.ios bogus)', $result['errors']['platform_map']);
        $this->assertSame('Max targets per run is a whole number from 1 to 500.', $result['errors']['max_targets']);
    }

    public function testSettingsLeftOutKeepTheirValueAndEmptyOnesTheirDefault(): void
    {
        $current = new PluginSettings(['allowed_group' => '3', 'max_targets' => '5', 'inventory_user' => '950']);

        $saved = PluginSettings::fromInput(['max_targets' => ' 007 ', 'output_max_kb' => ''], $current, self::GROUPS, self::USERS)['settings'];
        $this->assertSame(3, $saved->allowedGroup());
        $this->assertSame(950, $saved->inventoryUser());
        $this->assertSame('7', $saved->all()['max_targets']);
        $this->assertSame('1024', $saved->all()['output_max_kb']);

        $cleared = PluginSettings::fromInput(['allowed_group' => '', 'inventory_user' => ''], $current, self::GROUPS, self::USERS)['settings'];
        $this->assertNull($cleared->allowedGroup());
        $this->assertNull($cleared->inventoryUser());
        $this->assertNull((new PluginSettings())->inventoryUser(), 'no inventory user until an admin chooses one');
    }

    public function testChangesForTheEventlog(): void
    {
        $before = new PluginSettings(['allowed_group' => '3', 'max_targets' => '25']);
        $after = PluginSettings::fromInput(['allowed_group' => '', 'max_targets' => '2', 'inventory_user' => '950',
            'platform_map' => PlatformMap::DEFAULT . "\nlinux community.general.linux ssh\nios cisco.ios.ios"], $before, self::GROUPS, self::USERS)['settings'];

        $this->assertSame([
            'platform mapping: added linux (community.general.linux ssh); changed ios (cisco.ios.ios network_cli)',
            'allowed device group Ansible lab (#3) → every device',
            'max targets per run 25 → 2',
            'inventory user not chosen → ansinv (#950)',
        ], $after->changes($before, self::GROUPS, self::USERS));
        $this->assertSame([], $after->changes($after, self::GROUPS, self::USERS));
    }

    public function testUnreadableSettingsCarryTheirError(): void
    {
        $settings = new PluginSettings([], 'cannot be read');

        $this->assertSame('cannot be read', $settings->error);
        $this->assertNull((new PluginSettings())->error);
    }

    public function testAuditTexts(): void
    {
        $this->assertSame('Ansible plugin settings changed by admin: max targets per run 25 → 2; inventory user not chosen → ansinv (#950)',
            AuditText::settingsChanged('admin', ['max targets per run 25 → 2', 'inventory user not chosen → ansinv (#950)']));
        $this->assertSame('Ansible plugin run permission (ansible.run) granted to ansadmin2 by admin', AuditText::permissionGranted('admin', 'ansadmin2'));
        $this->assertSame('Ansible plugin run permission (ansible.run) revoked from bob x by admin', AuditText::permissionRevoked("admin", "bob\nx"));
    }

    private static function input(array $changes = []): array
    {
        return $changes + ['url' => 'https://semaphore.example.com/api/', 'token' => 'abcdefgh12345678=', 'project' => '3', 'inventory' => '7',
            'verify_tls' => '1', 'ca_bundle' => '', 'timeout' => ''];
    }

    public function testConnectionFromInput(): void
    {
        $result = Connection::fromInput(self::input(), new Connection(), 'admin', 1000);

        $this->assertSame([], $result['errors']);
        $connection = $result['connection'];
        $this->assertSame('https://semaphore.example.com', $connection->url);
        $this->assertSame(3, $connection->project);
        $this->assertSame(7, $connection->inventory);
        $this->assertTrue($connection->verifyTls);
        $this->assertSame(15, $connection->timeout);
        $this->assertTrue($connection->complete());
        $this->assertSame('admin', $connection->savedBy);
    }

    public function testAnEmptyTokenKeepsTheSavedOne(): void
    {
        $current = new Connection('https://x', 'saved-token-1');

        $this->assertSame('saved-token-1', Connection::fromInput(self::input(['token' => '']), $current, 'a', 1)['connection']->token);
        $this->assertSame('', Connection::fromInput(self::input(['token' => '']), $current, 'a', 1, true)['connection']->token);
        $this->assertFalse(Connection::fromInput(self::input(['token' => '', 'verify_tls' => '']), new Connection(), 'a', 1)['connection']->complete());
        $this->assertFalse(Connection::fromInput(self::input(['verify_tls' => null]), $current, 'a', 1)['connection']->verifyTls);
    }

    public function testConnectionErrors(): void
    {
        $result = Connection::fromInput(['url' => 'https://user:pw@host/', 'token' => 'a b', 'project' => '0', 'inventory' => 'x', 'ca_bundle' => 'certs.pem',
            'timeout' => '500'], new Connection(), 'a', 1);

        $this->assertNull($result['connection']);
        $this->assertSame(['url', 'token', 'project', 'inventory', 'ca_bundle', 'timeout'], array_keys($result['errors']));
        foreach (['ftp://host', 'https://host/?a=1', 'https://host/#x', 'host:3000', 'https:///x', "https://host/\nx"] as $url) {
            $this->assertArrayHasKey('url', Connection::fromInput(self::input(['url' => $url]), new Connection(), 'a', 1)['errors'], $url);
        }
        $this->assertSame('http://127.0.0.1:3000/semaphore', Connection::fromInput(self::input(['url' => 'http://127.0.0.1:3000/semaphore/']), new Connection(), 'a', 1)['connection']->url);
    }

    public function testThePageNeverGetsTheToken(): void
    {
        $connection = new Connection('https://x', 'the-token-value', 1, 2);
        $page = $connection->forPage();

        $this->assertArrayNotHasKey('token', $page);
        $this->assertTrue($page['has_token']);
        $this->assertStringNotContainsString('the-token-value', json_encode($page));
        $this->assertEquals($connection, Connection::fromArray($connection->toArray()));
    }
}
