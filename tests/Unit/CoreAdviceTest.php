<?php

namespace Agoodley\LibrenmsTopology\Tests\Unit;

use Agoodley\LibrenmsTopology\Redundancy\CoreAdvice;
use PHPUnit\Framework\TestCase;

class CoreAdviceTest extends TestCase
{
    public function testCorePairNeedsNoAdvice(): void
    {
        $this->assertSame([], CoreAdvice::singleCores(RedundancyFixture::campus()->analyzer()));
    }

    public function testSecondCoreSwitchIsNamed(): void
    {
        $analyzer = RedundancyFixture::campus()->analyzer(['redundancy_core' => 'core1']);

        $this->assertSame([['core' => RedundancyFixture::CORE1, 'lookalike' => RedundancyFixture::CORE2, 'shared' => 2]], CoreAdvice::singleCores($analyzer));
    }

    public function testStarHasNoSecondCoreSwitch(): void
    {
        // three access switches on one core switch, and a branch star with an automatic core
        $net = new RedundancyFixture;
        $net->device(1, 'core1')->device(2, 'acc1')->device(3, 'acc2')->device(4, 'acc3');
        $net->link(2, 'Gi1/0/49', 1, 'Gi1/0/1')->link(3, 'Gi1/0/49', 1, 'Gi1/0/2')->link(4, 'Gi1/0/49', 1, 'Gi1/0/3');
        $net->device(20, 'branch')->device(21, 'branch-acc1')->device(22, 'branch-acc2');
        $net->link(21, 'Gi1/0/49', 20, 'Gi1/0/1')->link(22, 'Gi1/0/49', 20, 'Gi1/0/2');
        // a lone switch with an access point: no other switch to name
        $net->device(30, 'lab')->device(31, 'lab-ap', 'wireless')->link(31, 'eth0', 30, 'Gi1/0/1');

        $this->assertSame([
            ['core' => 1, 'lookalike' => null, 'shared' => 0],
            ['core' => 20, 'lookalike' => null, 'shared' => 0],
        ], CoreAdvice::singleCores($net->analyzer(['redundancy_core' => 'core1'])));
    }

    public function testFewSharedSwitchesAreNoCoreCabling(): void
    {
        // core2 has links to two of the six distribution switches of core1 only
        $net = new RedundancyFixture;
        $net->device(1, 'core1')->device(2, 'core2')->link(1, 'Te1/1/1', 2, 'Te1/1/1');
        for ($id = 3; $id <= 8; $id++) {
            $net->device($id, "dist$id")->link($id, 'Te1/1/1', 1, "Te1/0/$id");
        }
        $net->link(3, 'Te1/1/2', 2, 'Te1/0/3')->link(4, 'Te1/1/2', 2, 'Te1/0/4');

        $this->assertSame([['core' => 1, 'lookalike' => null, 'shared' => 0]], CoreAdvice::singleCores($net->analyzer(['redundancy_core' => 'core1'])));

        // with four of six it is
        $net->link(5, 'Te1/1/2', 2, 'Te1/0/5')->link(6, 'Te1/1/2', 2, 'Te1/0/6');
        $this->assertSame([['core' => 1, 'lookalike' => 2, 'shared' => 4]], CoreAdvice::singleCores($net->analyzer(['redundancy_core' => 'core1'])));
    }
}
