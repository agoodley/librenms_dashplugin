<?php

namespace Agoodley\LibrenmsInventory\Tests\Unit;

use Agoodley\LibrenmsInventory\Reports\Summaries;
use Agoodley\LibrenmsInventory\Support\Origin;
use PHPUnit\Framework\TestCase;

class SummariesTest extends TestCase
{
    /** A stack of three (two 48-port, one 24-port), a lone switch of the same model, a device without inventory, one without a model. */
    private function devices(): array
    {
        return [
            1 => ['hardware' => 'WS-C2960X-48FPS-L', 'os' => 'ios', 'version' => '15.2(7)E8'],
            2 => ['hardware' => 'WS-C2960X-48FPS-L', 'os' => 'ios', 'version' => '15.2(4)E10'],
            3 => ['hardware' => 'C9300-48P', 'os' => 'iosxe', 'version' => '17.9.4a'],
            4 => ['hardware' => '', 'os' => 'linux', 'version' => ''],
            5 => ['hardware' => 'C9300-48P', 'os' => 'iosxe', 'version' => '17.12.1'],
        ];
    }

    private function chassis(): array
    {
        return [
            1 => [['model' => 'WS-C2960X-48FPS-L', 'serial' => 'FCW1'], ['model' => 'WS-C2960X-24PS-L', 'serial' => 'FCW2'], ['model' => 'WS-C2960X-48FPS-L', 'serial' => 'FCW3']],
            2 => [['model' => 'WS-C2960X-48FPS-L', 'serial' => 'FCW4']],
            // a chassis without a model: the device's model counts
            5 => [['model' => '', 'serial' => 'FOC5']],
        ];
    }

    public function testModelsCountEachUnit(): void
    {
        $models = Summaries::models($this->devices(), $this->chassis());

        $this->assertSame(['WS-C2960X-48FPS-L', 'C9300-48P', 'WS-C2960X-24PS-L', Summaries::UNKNOWN], array_column($models, 'model'));
        $this->assertSame(3, $models[0]['units'], 'two members of the stack and the lone switch');
        $this->assertSame([1, 2], $models[0]['devices']);
        $this->assertSame(['ios'], $models[0]['os']);
        $this->assertSame(['15.2(7)E8' => 2, '15.2(4)E10' => 1], $models[0]['versions'], 'the newest version first');
        $this->assertSame(2, $models[1]['units']);
        $this->assertSame(['17.12.1' => 1, '17.9.4a' => 1], $models[1]['versions']);
        $this->assertSame(1, $models[2]['units']);
        $this->assertSame([1], $models[2]['devices']);
        $this->assertSame([4], $models[3]['devices'], 'a device without a model last');
        $this->assertSame([Summaries::UNKNOWN => 1], $models[3]['versions']);
    }

    public function testSoftwareVersions(): void
    {
        $versions = Summaries::versions($this->devices(), ['ios' => 'Cisco IOS', 'iosxe' => 'Cisco IOS-XE', 'linux' => 'Linux']);

        $this->assertSame([['Cisco IOS', '15.2(7)E8'], ['Cisco IOS', '15.2(4)E10'], ['Cisco IOS-XE', '17.12.1'], ['Cisco IOS-XE', '17.9.4a'], ['Linux', Summaries::UNKNOWN]],
            array_map(fn ($row) => [$row['os_name'], $row['version']], $versions));
        $this->assertSame([1], $versions[0]['devices']);
        $this->assertSame(['WS-C2960X-48FPS-L' => 1], $versions[0]['models']);
        $this->assertSame([Summaries::UNKNOWN => 1], $versions[4]['models']);
        $this->assertSame('ios', $versions[0]['os']);
        $this->assertSame('arista_eos', Summaries::versions([7 => ['os' => 'arista_eos', 'version' => '4.30']])[0]['os_name'], 'without a name, the os');
    }

    public function testOpticsByKind(): void
    {
        $optics = [
            ['device_id' => 1, 'type' => '1000BaseSX SFP', 'vendor' => '', 'model' => 'GLC-SX-MMD', 'wavelength' => null, 'origin' => null],
            ['device_id' => 1, 'type' => '1000BaseSX SFP', 'vendor' => '', 'model' => 'GLC-SX-MMD', 'wavelength' => 0, 'origin' => null],
            ['device_id' => 2, 'type' => '1000BaseSX SFP', 'vendor' => '', 'model' => 'glc-sx-mmd', 'wavelength' => null, 'origin' => null],
            ['device_id' => 3, 'type' => '10GBASE-SR', 'vendor' => 'FS', 'model' => 'SFP-10GSR-85', 'wavelength' => 850, 'origin' => Origin::THIRD_PARTY],
            ['device_id' => 3, 'type' => '10GBASE-SR', 'vendor' => 'CISCO-FINISAR', 'model' => 'SFP-10G-SR', 'wavelength' => 850, 'origin' => Origin::GENUINE],
        ];

        $kinds = Summaries::optics($optics);

        $this->assertCount(3, $kinds);
        $this->assertSame(['type' => '1000BaseSX SFP', 'model' => 'GLC-SX-MMD', 'wavelength' => null, 'count' => 3, 'devices' => 2, 'origin' => ['unknown' => 3]],
            array_intersect_key($kinds[0], array_flip(['type', 'model', 'wavelength', 'count', 'devices', 'origin'])), 'the model in any case, no wavelength as 0');
        $this->assertSame(850, $kinds[1]['wavelength']);
        $this->assertSame(['genuine' => 1, 'third-party' => 1, 'unknown' => 3], Summaries::origins($optics));
    }

    public function testVersionOrder(): void
    {
        $this->assertLessThan(0, Summaries::compareVersions('15.2(4)E10', '15.2(7)E8'));
        $this->assertLessThan(0, Summaries::compareVersions('17.9.4a', '17.12.1'));
        $this->assertGreaterThan(0, Summaries::compareVersions('4.30.1F', '4.28.3M'));
        $this->assertSame(0, Summaries::compareVersions('7.0.12', '7.0.12'));
    }
}
