<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

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
