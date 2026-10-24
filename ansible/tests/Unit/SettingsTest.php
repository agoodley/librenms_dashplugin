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
        $settings = new PluginSettings(['max_targets' => '900', 'output_max_kb' => 'lots', 'stop_after_hours' => '0', 'allowed_group' => '12', 'extra' => 'x',
            'stop_hold_minutes' => '99999']);

        $this->assertSame(500, $settings->maxTargets());
        $this->assertSame(1024 * 1024, $settings->outputMaxBytes());
        $this->assertSame(3600, $settings->stopAfterSeconds());
        $this->assertSame(1440 * 60, $settings->stopHoldSeconds());
        $this->assertSame(30 * 60, (new PluginSettings())->stopHoldSeconds(), 'a stopped run holds its devices for 30 minutes by default');
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
        $result = PluginSettings::fromInput(['max_targets' => '900', 'output_max_kb' => 'lots', 'stop_after_hours' => '0', 'allowed_group' => '12',
            'platform_map' => "ios cisco.ios.ios bogus\nnxos cisco.nxos.nxos", 'inventory_user' => '77', 'stop_hold_minutes' => '0'], new PluginSettings(), self::GROUPS, self::USERS);

        $this->assertNull($result['settings']);
        $this->assertSame(['platform_map', 'allowed_group', 'max_targets', 'output_max_kb', 'stop_after_hours', 'stop_hold_minutes', 'inventory_user'],
            array_keys($result['errors']));
        $this->assertSame('Hold after a stop (minutes) is a whole number from 1 to 1440.', $result['errors']['stop_hold_minutes']);
        $this->assertStringContainsString('line 1 (ios cisco.ios.ios bogus)', $result['errors']['platform_map']);
        $this->assertSame('Max targets per change is a whole number from 1 to 500.', $result['errors']['max_targets']);
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
            'max targets per change 25 → 2',
            'inventory user not chosen → ansinv (#950)',
        ], $after->changes($before, self::GROUPS, self::USERS));
        $this->assertSame([], $after->changes($after, self::GROUPS, self::USERS));
    }

    public function testTheSettingsOfChanges(): void
    {
        $defaults = (new PluginSettings())->changeSettings();
        $this->assertSame(['required', 86400, 86400, true, true, 600, true, null, 3600, 600, 'plugin/ansible/changes/%d'], [$defaults->approval, $defaults->approvalSeconds,
            $defaults->dryRunSeconds, $defaults->backups, $defaults->refuseWithoutBackup, $defaults->backupTimeoutSeconds, $defaults->maintenance,
            $defaults->maintenanceBehavior, $defaults->expectedSeconds, $defaults->settleSeconds, $defaults->changeUrl], 'an approval, backups that refuse, windows');
        $plan = (new PluginSettings())->batchPlan([1, 2, 3, 4, 5, 6, 7, 8]);
        $this->assertSame([[1], [2, 3, 4, 5, 6], [7, 8]], $plan->batches);
        $this->assertSame('3 batches: 1, then 5, then 2 devices; stops at 1 failed device in a batch; waits for an admin to continue after the first batch', $plan->describe());
        $this->assertSame([[6], [1, 2, 3, 4, 5], [7, 8]], (new PluginSettings())->batchPlan([1, 2, 3, 4, 5, 6, 7, 8], [6])->batches, 'the devices ticked "first" form the first batch');

        $settings = new PluginSettings(['approval' => 'second', 'approval_hours' => '2', 'dry_run_hours' => '9999', 'canary_size' => '0', 'batch_size' => '3', 'stop_at' => '2',
            'wait_after_canary' => '0', 'batch_pause_minutes' => '5', 'backups' => '0', 'backup_policy' => 'warn', 'backup_timeout_minutes' => '1', 'maintenance' => '0',
            'maintenance_behavior' => '2', 'maintenance_minutes' => '30', 'settle_minutes' => '0']);
        $changes = $settings->changeSettings();
        $this->assertSame(['second', 7200, 720 * 3600, false, false, 60, false, 2, 1800, 0], [$changes->approval, $changes->approvalSeconds, $changes->dryRunSeconds,
            $changes->backups, $changes->refuseWithoutBackup, $changes->backupTimeoutSeconds, $changes->maintenance, $changes->maintenanceBehavior, $changes->expectedSeconds,
            $changes->settleSeconds], 'stored values out of range are clamped');
        $this->assertSame('3 batches: 1, then 3, then 1 device; stops at 2 failed devices in a batch; 5 min between batches', $settings->batchPlan([1, 2, 3, 4, 5])->describe());
        $this->assertSame('required', (new PluginSettings(['approval' => 'whatever']))->changeSettings()->approval, 'an unknown approval mode asks for an approval');

        $result = PluginSettings::fromInput(['approval' => 'nobody', 'canary_size' => '0', 'batch_size' => '501', 'stop_at' => 'x', 'wait_after_canary' => 'maybe',
            'backup_policy' => 'ignore', 'backup_timeout_minutes' => '0', 'maintenance_behavior' => '9', 'maintenance_minutes' => '1', 'settle_minutes' => '-1'],
            new PluginSettings(), self::GROUPS, self::USERS);
        $this->assertNull($result['settings']);
        $this->assertSame(['approval', 'canary_size', 'batch_size', 'stop_at', 'wait_after_canary', 'backup_policy', 'backup_timeout_minutes', 'maintenance_behavior',
            'maintenance_minutes', 'settle_minutes'], array_values(array_intersect(array_keys(PluginSettings::DEFAULTS), array_keys($result['errors']))), 'each wrong value is refused');
        $this->assertSame('First batch (devices) is a whole number from 1 to 100.', $result['errors']['canary_size']);
        $this->assertSame('Approval is one of: no approval, an approval, an approval from a second admin.', $result['errors']['approval']);

        $result = PluginSettings::fromInput(['approval' => 'second', 'backups' => '0', 'settle_minutes' => '1'], new PluginSettings(), self::GROUPS, self::USERS);
        $this->assertSame(['approval an approval → an approval from a second admin', 'Oxidized backups on → off', 'settle time (minutes) 10 → 1'],
            $result['settings']->changes(new PluginSettings(), self::GROUPS, self::USERS));
    }

    public function testTheSettingsOfVerificationAndFixes(): void
    {
        $defaults = (new PluginSettings())->changeSettings();
        $this->assertSame([true, 'ask', 1800, 'pause', 'pause', 'warning'], [$defaults->verify, $defaults->verifyFresh, $defaults->verifyWaitSeconds,
            $defaults->verifyUnexpected, $defaults->verifyUnknown, $defaults->verifyMinimum], 'on, asking for fresh data, waiting 30 minutes, pausing, from warning');
        $this->assertNull((new PluginSettings())->fixTemplate('port_descriptions'), 'no fix template until an admin sets one');

        $settings = new PluginSettings(['verify' => '0', 'verify_fresh' => 'wait', 'verify_wait_minutes' => '99999', 'verify_unexpected' => 'stop', 'verify_unknown' => 'record',
            'verify_minimum' => 'everything', 'fix_template_port_descriptions' => '211', 'fix_template_stp_root_priority' => 'x']);
        $changes = $settings->changeSettings();
        $this->assertSame([false, 'wait', 1440 * 60, 'stop', 'record', 'warning'], [$changes->verify, $changes->verifyFresh, $changes->verifyWaitSeconds,
            $changes->verifyUnexpected, $changes->verifyUnknown, $changes->verifyMinimum], 'stored values out of range are clamped, unknown ones take the default');
        $this->assertSame(['211', null], [$settings->fixTemplate('port_descriptions'), $settings->fixTemplate('stp_root_priority')]);

        $result = PluginSettings::fromInput(['verify' => 'yes', 'verify_fresh' => 'poll', 'verify_wait_minutes' => '0', 'verify_unexpected' => 'ignore',
            'verify_unknown' => 'go', 'verify_minimum' => 'notice', 'fix_template_port_descriptions' => '21a'], new PluginSettings(), self::GROUPS, self::USERS);
        $this->assertSame(['verify', 'verify_fresh', 'verify_wait_minutes', 'verify_unexpected', 'verify_unknown', 'verify_minimum', 'fix_template_port_descriptions'],
            array_values(array_intersect(array_keys(PluginSettings::DEFAULTS), array_keys($result['errors']))), 'each wrong value is refused');
        $this->assertSame('Wait for fresh data (minutes) is a whole number from 10 to 1440.', $result['errors']['verify_wait_minutes']);
        // above LibreNMS' polling and discovery intervals (5 minutes each): at least 10 minutes; a smaller value stored before counts as 10
        $this->assertArrayHasKey('verify_wait_minutes', PluginSettings::fromInput(['verify_wait_minutes' => '9'], new PluginSettings(), self::GROUPS, self::USERS)['errors']);
        $this->assertArrayNotHasKey('verify_wait_minutes', PluginSettings::fromInput(['verify_wait_minutes' => '10'], new PluginSettings(), self::GROUPS, self::USERS)['errors']);
        $this->assertSame(600, (new PluginSettings(['verify_wait_minutes' => '5']))->changeSettings()->verifyWaitSeconds);
        $this->assertSame('Count findings from is one of: critical, warning, info.', $result['errors']['verify_minimum']);
        $this->assertSame('Template for port description fixes is the id of a template of the runner, or none.', $result['errors']['fix_template_port_descriptions']);

        // a fix template must have the contract's survey variable: asked of the runner when it changes
        $asked = [];
        $check = function (string $kind, string $id) use (&$asked): ?string {
            $asked[] = [$kind, $id];

            return $id === '186' ? 'template #186 (Show version) has no survey variable port_descriptions' : null;
        };
        $refused = PluginSettings::fromInput(['fix_template_port_descriptions' => '186'], new PluginSettings(), self::GROUPS, self::USERS, $check);
        $this->assertSame('Template for port description fixes: template #186 (Show version) has no survey variable port_descriptions',
            $refused['errors']['fix_template_port_descriptions']);
        $saved = PluginSettings::fromInput(['fix_template_port_descriptions' => '211', 'fix_template_stp_root_priority' => '212', 'verify_minimum' => 'critical'],
            new PluginSettings(), self::GROUPS, self::USERS, $check)['settings'];
        $this->assertSame(['211', '212'], [$saved->fixTemplate('port_descriptions'), $saved->fixTemplate('stp_root_priority')]);
        $asked = [];
        PluginSettings::fromInput(['fix_template_port_descriptions' => '211', 'verify' => '0'], $saved, self::GROUPS, self::USERS, $check);
        $this->assertSame([], $asked, 'an unchanged template is not asked again (a new change checks it)');
        $this->assertSame(['count findings from warning → critical', 'template for port description fixes none → #211', 'template for root priority fixes none → #212'],
            $saved->changes(new PluginSettings(), self::GROUPS, self::USERS));
    }

    public function testUnreadableSettingsCarryTheirError(): void
    {
        $settings = new PluginSettings([], 'cannot be read');

        $this->assertSame('cannot be read', $settings->error);
        $this->assertNull((new PluginSettings())->error);
    }

    public function testAuditTexts(): void
    {
        $this->assertSame('Ansible plugin settings changed by admin: max targets per change 25 → 2; inventory user not chosen → ansinv (#950)',
            AuditText::settingsChanged('admin', ['max targets per change 25 → 2', 'inventory user not chosen → ansinv (#950)']));
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
        $current = new Connection('https://semaphore.example.com', 'saved-token-1');

        $this->assertSame('saved-token-1', Connection::fromInput(self::input(['token' => '']), $current, 'a', 1)['connection']->token);
        $this->assertSame('', Connection::fromInput(self::input(['token' => '']), $current, 'a', 1, true)['connection']->token);
        $this->assertFalse(Connection::fromInput(self::input(['token' => '', 'verify_tls' => '']), new Connection(), 'a', 1)['connection']->complete());
        $this->assertFalse(Connection::fromInput(self::input(['verify_tls' => null]), $current, 'a', 1)['connection']->verifyTls);
    }

    public function testTheSavedTokenOnlyGoesWhereItWasSavedFor(): void
    {
        $current = new Connection('https://semaphore.example.com', 'saved-token-1', 3, 7, true, '');
        $keep = fn (array $changes) => Connection::fromInput(self::input(['token' => ''] + $changes), $current, 'a', 1);

        $this->assertSame('saved-token-1', $keep([])['connection']->token);
        $this->assertSame('saved-token-1', $keep(['url' => 'HTTPS://Semaphore.Example.com:443/'])['connection']->token, 'the same place, written otherwise');
        $this->assertSame('saved-token-1', $keep(['project' => '4', 'timeout' => '30'])['connection']->token);
        foreach (['https://evil.example.com', 'http://semaphore.example.com', 'https://semaphore.example.com:8443', 'https://semaphore.example.com/other'] as $url) {
            $result = $keep(['url' => $url]);
            $this->assertNull($result['connection'], $url);
            $this->assertStringStartsWith('The runner URL changed: enter the API token again.', $result['errors']['token']);
        }
        $this->assertStringStartsWith('TLS verification was turned off: enter the API token again', $keep(['verify_tls' => ''])['errors']['token']);
        $this->assertStringStartsWith('The CA bundle changed: enter the API token again', $keep(['ca_bundle' => '/tmp/mine.pem'])['errors']['token']);
        $this->assertSame('The runner URL changed and TLS verification was turned off: enter the API token again. The saved token is only sent to the runner it was saved for, checked as it was then.',
            $keep(['url' => 'https://evil.example.com', 'verify_tls' => ''])['errors']['token']);

        // with the token entered again, or removed, or no token saved, the URL may change
        $this->assertSame('new-token-22', Connection::fromInput(self::input(['url' => 'https://evil.example.com', 'token' => 'new-token-22']), $current, 'a', 1)['connection']->token);
        $this->assertSame('', Connection::fromInput(self::input(['url' => 'https://evil.example.com', 'token' => '']), $current, 'a', 1, true)['connection']->token);
        $this->assertSame([], Connection::fromInput(self::input(['url' => 'https://evil.example.com', 'token' => '']), new Connection('https://x'), 'a', 1)['errors']);
        // TLS verification turned back on, with or without a CA bundle, makes the token safer: no need to enter it
        $lab = new Connection('http://127.0.0.1:3000', 'saved-token-1', 3, 7, false, '/old.pem');
        $this->assertSame([], Connection::fromInput(self::input(['url' => 'http://127.0.0.1:3000', 'token' => '', 'ca_bundle' => '/new.pem']), $lab, 'a', 1)['errors']);
    }

    public function testConnectionChangesNeverShowTheToken(): void
    {
        $before = new Connection('http://127.0.0.1:3000', 'old-token-value', 3, 4, true, '', 15);
        $after = new Connection('http://127.0.0.1:3999', 'new-token-value', 3, 5, false, '/etc/ca.pem', 3);

        $changes = $after->changes($before);
        $this->assertSame(['runner URL http://127.0.0.1:3000 → http://127.0.0.1:3999', 'token replaced', 'LibreNMS inventory 4 → 5', 'TLS verification off',
            'CA bundle system CAs → /etc/ca.pem', 'timeout 15 → 3 s'], $changes);
        $this->assertStringNotContainsString('token-value', implode(' ', $changes));
        $this->assertSame(['token set'], (new Connection('', 'x-token-123'))->changes(new Connection()));
        $this->assertSame(['token removed'], (new Connection())->changes(new Connection('', 'x-token-123')));
        $this->assertSame([], $after->changes($after));
        $this->assertSame('https://a.example:443/x', Connection::destination('https://A.example/x/'));
        $this->assertSame('http://a.example:80', Connection::destination('http://a.example'));
    }

    public function testTheInventoryMayBeChosenAfterATest(): void
    {
        $connection = Connection::fromInput(self::input(['inventory' => '']), new Connection(), 'a', 1)['connection'];

        $this->assertSame(0, $connection->inventory);
        $this->assertTrue($connection->canConnect(), 'Test connection can list the inventories');
        $this->assertFalse($connection->complete(), 'nothing is launched without the LibreNMS inventory');
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
