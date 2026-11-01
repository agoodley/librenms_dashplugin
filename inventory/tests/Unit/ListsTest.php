<?php

namespace Agoodley\LibrenmsInventory\Tests\Unit;

use Agoodley\LibrenmsInventory\Reports\Exports;
use Agoodley\LibrenmsInventory\Reports\Lists;
use Agoodley\LibrenmsInventory\Reports\Summaries;
use Agoodley\LibrenmsInventory\Support\Csv;
use Agoodley\LibrenmsInventory\Support\Filters;
use Agoodley\LibrenmsInventory\Support\Origin;
use PHPUnit\Framework\TestCase;

class ListsTest extends TestCase
{
    private function optics(): array
    {
        $optic = fn (int $id, string $type, string $vendor, string $model, string $serial, string $port, ?string $origin) => [
            'id' => $id, 'device_id' => 1, 'port_id' => $id, 'port' => $port, 'port_alias' => 'uplink ' . $id, 'port_status' => 'up', 'type' => $type,
            'vendor' => $vendor, 'model' => $model, 'revision' => 'V01', 'serial' => $serial, 'date' => '', 'ddm' => true, 'cable' => '', 'distance' => 300,
            'wavelength' => 850, 'connector' => 'LC', 'channels' => 1, 'origin' => $origin, 'port_url' => null,
        ];

        return [
            $optic(1, '10GBASE-SR', 'CISCO-FINISAR', 'SFP-10G-SR', 'FNS1', 'Te1/1/1', Origin::GENUINE),
            $optic(2, '10GBASE-SR', 'FS', 'SFP-10GSR-85', 'F2', 'Te1/1/2', Origin::THIRD_PARTY),
            $optic(3, '1000BaseSX SFP', '', 'GLC-SX-MMD', 'FNS3', 'Gi1/0/52', null),
        ];
    }

    public function testFiltersOfTheQuery(): void
    {
        $filters = Filters::parse(['device' => '12', 'group' => 'x', 'location' => '-3', 'os' => ' ios ', 'origin' => 'nonsense', 'q' => str_repeat('a', 300),
            'page' => '4', 'type' => ['array'], 'other' => 'ignored']);

        $this->assertSame(12, $filters['device']);
        $this->assertNull($filters['group']);
        $this->assertNull($filters['location']);
        $this->assertSame('ios', $filters['os']);
        $this->assertSame('', $filters['origin'], 'not an origin');
        $this->assertSame(Filters::MAX_LENGTH, mb_strlen($filters['q']));
        $this->assertSame('', $filters['type']);
        $this->assertSame(4, $filters['page']);
        $this->assertArrayNotHasKey('other', $filters);
        $this->assertSame(1, Filters::parse(['page' => '0'])['page']);
        $this->assertSame(['device' => 12, 'os' => 'ios'], Filters::query(['q' => ''] + $filters, ['q']) + [], 'without the empty ones, the page and those left out');
        $this->assertSame('%50\%\_off\\\\%', Filters::like('50%_off\\'), 'LIKE wildcards taken literally');
    }

    public function testOpticsOfTheFilters(): void
    {
        $ids = fn (array $filters) => array_column(Lists::optics($this->optics(), $filters + ['type' => '', 'vendor' => '', 'origin' => '', 'q' => '']), 'id');

        $this->assertSame([1, 2, 3], $ids([]));
        $this->assertSame([1, 2], $ids(['type' => '10gbase-sr']), 'any case');
        $this->assertSame([2], $ids(['vendor' => 'FS']));
        $this->assertSame([2], $ids(['origin' => Origin::THIRD_PARTY]));
        $this->assertSame([3], $ids(['origin' => 'unknown']));
        $this->assertSame([1], $ids(['origin' => Origin::GENUINE]));
        $this->assertSame([3], $ids(['q' => 'gi1/0/52']), 'the port');
        $this->assertSame([1, 3], $ids(['q' => 'fns']), 'the serial number');
        $this->assertSame([2], $ids(['q' => 'UPLINK 2']), 'the port description');
    }

    public function testPages(): void
    {
        $this->assertSame(1, Lists::pages(0));
        $this->assertSame(1, Lists::pages(Lists::PAGE));
        $this->assertSame(2, Lists::pages(Lists::PAGE + 1));
        $this->assertSame(2, Lists::page(9, Lists::PAGE + 1), 'past the last page: the last');
        $this->assertSame(1, Lists::page(0, 10));
    }

    public function testExports(): void
    {
        $devices = [1 => ['name' => 'acc-sw1', 'hostname' => 'acc-sw1.example.net', 'location' => 'HQ', 'os' => 'ios', 'hardware' => 'WS-C2960X-48FPS-L',
            'version' => '15.2(7)E8', 'features' => 'LANBASEK9', 'serial' => 'FCW1', 'last_discovered' => '2026-11-01 04:57:54']];
        $chassis = [1 => [['model' => 'WS-C2960X-48FPS-L', 'serial' => 'FCW1'], ['model' => 'WS-C2960X-24PS-L', 'serial' => 'FCW2']]];
        $rows = Exports::devices($devices, [1 => ['serials' => 8, 'optics' => 2]], ['ios' => 'Cisco IOS'], $chassis);
        $this->assertSame('device', $rows[0][0]);
        $this->assertSame(['acc-sw1', 'acc-sw1.example.net', 'HQ', 'Cisco IOS', 'WS-C2960X-48FPS-L', '15.2(7)E8', 'LANBASEK9', 'FCW1',
            'WS-C2960X-48FPS-L FCW1; WS-C2960X-24PS-L FCW2', 8, 2, '2026-11-01 04:57:54'], $rows[1]);

        $names = [1 => ['name' => 'acc-sw1', 'location' => 'HQ']];
        $modules = Exports::modules([['device_id' => 1, 'index' => 1006, 'class' => 'powerSupply', 'name' => 'PS1', 'description' => 'Power Supply 1',
            'model' => 'PWR-C1-715WAC', 'serial' => 'DCB1', 'hardware_rev' => 'V02', 'firmware_rev' => '', 'software_rev' => '', 'fru' => true, 'manufacturer' => '']], $names);
        $this->assertSame(['acc-sw1', 'HQ', 'powerSupply', 'PS1', 'Power Supply 1', 'PWR-C1-715WAC', 'DCB1', 'V02', '', '', true, ''], $modules[1]);

        $optics = Exports::optics($this->optics(), $names);
        $this->assertCount(4, $optics);
        $this->assertSame(['acc-sw1', 'HQ', 'Te1/1/2', 'uplink 2', '10GBASE-SR', 'FS', 'SFP-10GSR-85', 'F2', 'V01', 850, 300, 'LC', true, 'third-party', ''], $optics[2]);
        $this->assertSame('unknown', $optics[3][13]);

        $models = Exports::models(Summaries::models([1 => $devices[1]], $chassis), ['ios' => 'Cisco IOS']);
        $this->assertSame(['WS-C2960X-24PS-L', 1, 1, 'Cisco IOS', '15.2(7)E8 (1)'], $models[1], 'one unit each: by name');
        $versions = Exports::versions(Summaries::versions([1 => $devices[1]], ['ios' => 'Cisco IOS']));
        $this->assertSame(['Cisco IOS', '15.2(7)E8', 1, 'WS-C2960X-48FPS-L (1)'], $versions[1]);

        $this->assertSame("a,\"b,c\",'=HYPERLINK(1),yes\r\n", Csv::of([['a', 'b,c', '=HYPERLINK(1)', true]]));
    }
}
