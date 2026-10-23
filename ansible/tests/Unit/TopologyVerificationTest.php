<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\TopologyError;
use Agoodley\LibrenmsAnsible\Services\TopologyVerification;
use PHPUnit\Framework\TestCase;

/**
 * What the plugin takes of the topology plugin's verification API: a state of its major version (with when it was
 * taken), as an admin whose LibreNMS role may view everything the state reads; anything else is no state to verify a
 * batch with. The state comes from the process the sync command takes it in (here the test's).
 */
class TopologyVerificationTest extends TestCase
{
    private const STATE = ['version' => '1.4', 'taken_at' => 1_700_000_000, 'scope' => ['subjects' => [3]], 'facts' => [], 'role_hidden' => []];

    /** The verification API (the test's), and the state its process answers. */
    private static function verifier(array $answer): TopologyVerification
    {
        return new TopologyVerification(true, FakeVerificationApi::class, fn (array $request) => $answer);
    }

    private function refusal(array $answer): string
    {
        try {
            self::verifier($answer)->state([3], 1);
        } catch (TopologyError $e) {
            return $e->getMessage();
        }
        $this->fail('a state was taken: ' . json_encode($answer));
    }

    public function testAStateIsOneOfTheApisMajorVersionWithWhenItWasTaken(): void
    {
        $this->assertSame(self::STATE, self::verifier(self::STATE)->state([3], 1), 'version 1.x: taken');
        foreach ([['version' => '2.0'], ['version' => '0.9'], ['taken_at' => null], ['taken_at' => '1700000000']] as $other) {
            $this->assertSame('the topology plugin\'s state is not one of its verification API 1.x', $this->refusal($other + self::STATE), json_encode($other));
        }
        $this->assertSame('the topology plugin\'s state is not one of its verification API 1.x', $this->refusal(['taken_at' => 1] + array_diff_key(self::STATE, ['version' => 1])));
    }

    public function testAStateTheAdminsRoleMayNotViewAllOfIsNone(): void
    {
        $this->assertSame('the LibreNMS role of the admin who starts the batch may not view links, ports, so the topology plugin cannot verify it',
            $this->refusal(['role_hidden' => ['links', 'ports']] + self::STATE));
        $this->assertSame(['role_hidden' => [7]] + self::STATE, self::verifier(['role_hidden' => [7]] + self::STATE)->state([3], 1), 'what is no table name hides no table');
    }

    public function testOnlyTheSyncCommandTakesAState(): void
    {
        $page = new TopologyVerification(false, FakeVerificationApi::class, fn (array $request) => self::STATE);
        $this->expectExceptionObject(new TopologyError('only the sync command takes the states of a batch'));
        $page->state([3], 1);
    }
}

/**
 * The topology plugin's verification API as the plugin checks it is there: of version 1.x, enabled.
 */
final class FakeVerificationApi
{
    public const VERSION = '1.4';

    public static function unavailable(): ?string
    {
        return null;
    }
}
