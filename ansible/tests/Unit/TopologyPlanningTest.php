<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Services\TopologyPlanning;
use PHPUnit\Framework\TestCase;

/**
 * Whether the topology plugin can plan changes: installed, recent enough for the planning API, of a major version this
 * plugin speaks, and enabled; and why not, in words an admin can act on.
 */
class TopologyPlanningTest extends TestCase
{
    public function testAMissingTopologyPluginIsTold(): void
    {
        $this->assertSame('the topology plugin is not installed', (new TopologyPlanning('No\Such\Planning', 'No\Such\Provider'))->unavailable());
    }

    public function testATopologyPluginTooOldForThePlanningApiIsToldToBeUpdated(): void
    {
        // installed (its service provider is there) but from before the planning API: its pages and menus work, planning does not
        $this->assertSame('the topology plugin is too old for the planning API this plugin needs: update it (it comes from the same repository as this plugin)',
            (new TopologyPlanning('No\Such\Planning', FakeTopologyProvider::class))->unavailable());
    }

    public function testAPlanningApiOfAnotherMajorVersionIsRefused(): void
    {
        $this->assertSame('the topology plugin\'s planning API is version 2.0, and this plugin speaks version 1.x: update both plugins',
            (new TopologyPlanning(FakePlanningTwo::class, FakeTopologyProvider::class))->unavailable());
    }

    public function testADisabledTopologyPluginSaysSoAndAnEnabledOneCanPlan(): void
    {
        FakePlanningOne::$why = 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)';
        $this->assertSame(FakePlanningOne::$why, (new TopologyPlanning(FakePlanningOne::class, FakeTopologyProvider::class))->unavailable());
        FakePlanningOne::$why = null;
        $this->assertNull((new TopologyPlanning(FakePlanningOne::class, FakeTopologyProvider::class))->unavailable(), 'version 1.1 of a 1.x API');
    }
}

/** The topology plugin's service provider, as a topology plugin from before the planning API has it. */
final class FakeTopologyProvider
{
}

/** A planning API of version 1.1, enabled or not. */
final class FakePlanningOne
{
    public const VERSION = '1.1';

    public static ?string $why = null;

    public static function unavailable(): ?string
    {
        return self::$why;
    }
}

/** A planning API of a later major version. */
final class FakePlanningTwo
{
    public const VERSION = '2.0';

    public static function unavailable(): ?string
    {
        return null;
    }
}
