<?php

namespace Agoodley\LibrenmsInventory\Tests\Unit;

use Agoodley\LibrenmsInventory\Support\Origin;
use PHPUnit\Framework\TestCase;

class OriginTest extends TestCase
{
    public function testMakerOfTheDevice(): void
    {
        $this->assertSame('Cisco', Origin::maker('ios'));
        $this->assertSame('Cisco', Origin::maker('iosxe'));
        $this->assertSame('Cisco', Origin::maker('nxos'));
        $this->assertSame('Juniper', Origin::maker('junos'));
        $this->assertSame('Arista', Origin::maker('arista_eos'));
        $this->assertSame('HPE Aruba', Origin::maker('arubaos-cx'));
        $this->assertSame('HPE Aruba', Origin::maker('procurve'));
        $this->assertSame('Dell', Origin::maker('dell-os10'));
        $this->assertNull(Origin::maker('linux'));
        $this->assertNull(Origin::maker(''));
        $this->assertNull(Origin::maker(null));
    }

    public function testGenuineThirdPartyOrUnknown(): void
    {
        $this->assertSame(Origin::GENUINE, Origin::of('ios', 'CISCO-FINISAR', 'SFP-10G-SR'));
        $this->assertSame(Origin::THIRD_PARTY, Origin::of('ios', 'FS', 'SFP-10GSR-85'));
        $this->assertNull(Origin::of('ios', '', 'GLC-SX-MMD'), 'LibreNMS has no vendor for many Cisco optics');
        $this->assertSame(Origin::GENUINE, Origin::of('arista_eos', 'Arista Networks', 'SFP-10G-SR'));
        $this->assertSame(Origin::THIRD_PARTY, Origin::of('arista_eos', 'FLEXOPTIX', 'S.1312.10.D'));
        // Juniper's own optics report the factory as vendor, with Juniper's part number
        $this->assertSame(Origin::GENUINE, Origin::of('junos', 'FINISAR CORP.', '740-021308'));
        $this->assertSame(Origin::THIRD_PARTY, Origin::of('junos', 'FINISAR CORP.', 'FTLX8571D3BCL'));
        $this->assertSame(Origin::GENUINE, Origin::of('procurve', 'HP', 'J9150A'));
        $this->assertSame(Origin::GENUINE, Origin::of('arubaos-cx', 'Aruba', 'JL484A'));
        $this->assertSame(Origin::THIRD_PARTY, Origin::of('arubaos-cx', '10Gtek', 'AXS85-192-M3'));
        $this->assertNull(Origin::of('linux', 'Intel', 'FTLX8571D3BCV-IT'), 'an unknown maker');
    }
}
