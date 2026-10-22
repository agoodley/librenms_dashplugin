<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Services\ChangeActions;
use Agoodley\LibrenmsAnsible\Services\RunSpec;
use PHPUnit\Framework\TestCase;

/**
 * The one-time tokens of a change's runs, and which run a token may stand for.
 */
class RunSpecTest extends TestCase
{
    private const CHANGE = ['id' => 30, 'batch_count' => 2, 'dry_run_commit' => null, 'request_token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'created_at' => '2026-10-22 10:00:00'];

    public function testAChangeWithTheIdOfAnEarlierOneHasOtherTokens(): void
    {
        // the change tables dropped by a rollback and made again: ids start at 1 again, the runs of the earlier changes stay
        $none = fn (array $snapshot): ?string => null;
        $earlier = ChangeActions::spec(self::CHANGE, RunSpec::BATCH, 1, $none);
        $later = ChangeActions::spec(['request_token' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'] + self::CHANGE, RunSpec::BATCH, 1, $none);
        $this->assertNotSame($earlier->token(), $later->token());
        $this->assertSame(64, strlen($later->token()));
        $this->assertSame($earlier->token(), ChangeActions::spec(self::CHANGE, RunSpec::BATCH, 1, $none)->token(), 'the same run of the same change: the same token');
        $this->assertNotSame($earlier->token(), ChangeActions::spec(self::CHANGE, RunSpec::BATCH, 2, $none)->token());
        $this->assertNotSame(ChangeActions::spec(self::CHANGE, RunSpec::DRY_RUN, 1, $none)->token(), ChangeActions::spec(self::CHANGE, RunSpec::DRY_RUN, 2, $none)->token(),
            'each dry run of a change has its own');
        $this->assertSame('created 2026-10-22 10:00:00', RunSpec::saltOf(['request_token' => null] + self::CHANGE), 'a change without a form token: its creation time');
    }

    public function testATokenStandsOnlyForARunOfTheSameChangeKindAndBatch(): void
    {
        $batch = new RunSpec(30, RunSpec::BATCH, 2, '');
        $this->assertTrue($batch->belongs(['change_id' => 30, 'kind' => 'batch', 'batch' => 2]));
        $this->assertFalse($batch->belongs(['change_id' => 31, 'kind' => 'batch', 'batch' => 2]), 'another change');
        $this->assertFalse($batch->belongs(['change_id' => null, 'kind' => null, 'batch' => null]), 'a run whose change the rollback dropped');
        $this->assertFalse($batch->belongs(['change_id' => 30, 'kind' => 'batch', 'batch' => 1]), 'another batch');
        $this->assertFalse($batch->belongs(['change_id' => 30, 'kind' => 'dry_run', 'batch' => null]), 'its dry run');
        $this->assertFalse($batch->belongs(null));
        $this->assertTrue((new RunSpec(30, RunSpec::DRY_RUN, 3, ''))->belongs(['change_id' => 30, 'kind' => 'dry_run', 'batch' => null]));
    }
}
