<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Support\InventoryScript;
use PHPUnit\Framework\TestCase;

class InventoryScriptTest extends TestCase
{
    public function testThePluginKnowsTheVersionOfTheScriptItShips(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../contrib/librenms_inventory.py');

        $this->assertMatchesRegularExpression("/^VERSION = '" . InventoryScript::VERSION . "'$/m", $script, 'InventoryScript::VERSION follows the script');
        $this->assertStringContainsString("'User-Agent': 'librenms-ansible-inventory/' + VERSION", $script);
    }

    public function testTheVersionOfAFetch(): void
    {
        $this->assertSame('3', InventoryScript::versionFrom('librenms-ansible-inventory/3'));
        $this->assertSame('1', InventoryScript::versionFrom('librenms-ansible-inventory/1 Python-urllib/3.12'));
        $this->assertNull(InventoryScript::versionFrom('curl/8.5.0'));
        $this->assertNull(InventoryScript::versionFrom('librenms-ansible-inventory/x'));
        $this->assertNull(InventoryScript::versionFrom(null));
        $this->assertTrue(InventoryScript::outdated('1'));
        $this->assertFalse(InventoryScript::outdated(InventoryScript::VERSION));
        $this->assertTrue(InventoryScript::outdated('2'));
        $this->assertFalse(InventoryScript::outdated('4'));
        $this->assertFalse(InventoryScript::outdated(null), 'another client: nothing to compare');
    }
}
