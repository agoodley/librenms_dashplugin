<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\TopologyError;
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
        $this->assertSame('the topology plugin is too old for the planning API this plugin needs: update it with this plugin (this plugin\'s README, Installation, From '
            . 'phase 2: both come from the same repository)',
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

    protected function tearDown(): void
    {
        FakePlanningOne::$why = null;
        FakePlanningOne::$answer = ['version' => '1.1', 'batches' => [], 'skipped' => [], 'role_hidden' => []];
        FakePlanningOne::$users = [];
    }

    /** The adapter on the fake API version 1.1, with the LibreNMS users alice (#1) and bob (#2). */
    private static function planning(string $api = FakePlanningOne::class): TopologyPlanning
    {
        $users = [1 => (object) ['user_id' => 1, 'username' => 'alice'], 2 => (object) ['user_id' => 2, 'username' => 'bob']];

        return new TopologyPlanning($api, FakeTopologyProvider::class, fn (int $id) => $users[$id] ?? null);
    }

    public function testACallIsAsTheUserSeesTheNetworkOneAnalysisPerUser(): void
    {
        $planning = self::planning();
        $this->assertSame(FakePlanningOne::$answer, $planning->rollout([502, '510'], 5, 1));
        $this->assertSame(['devices' => [502, 510], 'size' => 5], FakePlanningOne::$asked);
        $planning->blastRadius([502], 1);
        $planning->blastRadius([502], 2);
        $this->assertSame(['alice', 'bob'], FakePlanningOne::$users, 'one analysis of the network per user (and instance), each as that user sees it');
    }

    public function testAnAnswerWithoutTheDataThePlanReadsIsRefused(): void
    {
        // the LibreNMS role of the user may not view the LLDP/CDP links: the plan would be incomplete, so nothing is planned on it
        FakePlanningOne::$answer['role_hidden'] = ['LLDP/CDP links'];
        try {
            self::planning()->blastRadius([502], 1);
            $this->fail('an answer of a role that may not view the links is refused');
        } catch (TopologyError $e) {
            $this->assertSame('the LibreNMS role of alice may not view LLDP/CDP links, so the topology plugin cannot tell what the devices cut off', $e->getMessage());
        }
    }

    public function testAUserThatNoLongerExistsIsSaid(): void
    {
        try {
            self::planning()->rollout([502], 5, 7);
            $this->fail('a user that no longer exists is said');
        } catch (TopologyError $e) {
            $this->assertSame('the LibreNMS user #7 the topology is computed for no longer exists', $e->getMessage());
        }
        $this->assertSame([], FakePlanningOne::$users, 'the API is not asked');
    }

    public function testACallWithoutAPlanningApiItSpeaksIsRefusedWithWhy(): void
    {
        foreach ([['No\Such\Planning', 'the topology plugin is too old for the planning API this plugin needs'],
            [FakePlanningTwo::class, 'the topology plugin\'s planning API is version 2.0, and this plugin speaks version 1.x']] as [$api, $why]) {
            try {
                self::planning($api)->blastRadius([502], 1);
                $this->fail("refused: $why");
            } catch (TopologyError $e) {
                $this->assertStringStartsWith($why, $e->getMessage());
            }
        }
        FakePlanningOne::$why = 'the topology plugin is disabled in LibreNMS (Overview, Plugins, Plugin Admin)';
        try {
            self::planning()->rollout([502], 5, 1);
            $this->fail('refused while disabled');
        } catch (TopologyError $e) {
            $this->assertSame(FakePlanningOne::$why, $e->getMessage());
        }
    }

    public function testAnAnswerThatIsNotOneIsRefused(): void
    {
        FakePlanningOne::$answer = 'nothing';
        $this->expectException(TopologyError::class);
        $this->expectExceptionMessage('the topology plugin could not compute the blast radius: its answer is not one');
        self::planning()->blastRadius([502], 1);
    }
}

/** The topology plugin's service provider, as a topology plugin from before the planning API has it. */
final class FakeTopologyProvider
{
}

/** A planning API of version 1.1, enabled or not, that answers $answer and records what it was asked, and for whom. */
final class FakePlanningOne
{
    public const VERSION = '1.1';

    public static ?string $why = null;

    public static mixed $answer = ['version' => '1.1', 'batches' => [], 'skipped' => [], 'role_hidden' => []];

    /** @var string[] the users an instance was made for, in order */
    public static array $users = [];

    public static array $asked = [];

    public static function unavailable(): ?string
    {
        return self::$why;
    }

    public static function forUser(object $user): self
    {
        self::$users[] = $user->username;

        return new self();
    }

    public function rollout(array $deviceIds, int $batchSize): mixed
    {
        self::$asked = ['devices' => $deviceIds, 'size' => $batchSize];

        return self::$answer;
    }

    public function blastRadius(array $deviceIds): mixed
    {
        self::$asked = ['devices' => $deviceIds];

        return self::$answer;
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
