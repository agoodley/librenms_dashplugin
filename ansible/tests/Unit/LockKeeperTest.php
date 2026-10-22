<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Changes\LockLost;
use Agoodley\LibrenmsAnsible\Support\LockKeeper;
use PHPUnit\Framework\TestCase;

/**
 * The heartbeat of a change's lock: a refresh answering false is a lost lock only when the lock is no longer this
 * process's (LibreNMS' database driver answers false for a renewal within the same second too).
 */
class LockKeeperTest extends TestCase
{
    /**
     * A lock as Laravel's: refresh() answers $refreshes (or throws), isOwnedByCurrentProcess() $owned, get() takes it again
     * unless another process takes it at that moment.
     */
    private static function lock(bool|string $refreshes, bool $owned = true, bool $takenOnGet = false): object
    {
        return new class ($refreshes, $owned, $takenOnGet)
        {
            public int $gets = 0;

            public function __construct(public bool|string $refreshes, public bool $owned, public bool $takenOnGet)
            {
            }

            public function refresh($seconds = null): bool
            {
                if (is_string($this->refreshes)) {
                    throw new \RuntimeException($this->refreshes);
                }

                return $this->refreshes;
            }

            public function isOwnedByCurrentProcess(): bool
            {
                return $this->owned;
            }

            public function get($callback = null): bool
            {
                $this->gets++;
                $this->owned = $this->owned && ! $this->takenOnGet;

                return $this->owned;
            }
        };
    }

    public function testARenewedLockGoesOn(): void
    {
        $lock = self::lock(true);
        LockKeeper::renew($lock, 120);
        $this->assertSame(0, $lock->gets);
    }

    public function testARenewalWithinTheSameSecondIsNoLoss(): void
    {
        // the database driver: the row's expiry did not change, so no row counts as updated
        $lock = self::lock(false);
        LockKeeper::renew($lock, 120);
        $this->assertSame(1, $lock->gets, 'taken again (an expired one that nobody took is renewed so)');
    }

    public function testALockAnotherProcessHoldsIsLost(): void
    {
        $this->expectException(LockLost::class);
        LockKeeper::renew(self::lock(false, false), 120);
    }

    public function testALockTakenByAnotherProcessWhileItIsTakenAgainIsLost(): void
    {
        $this->expectException(LockLost::class);
        LockKeeper::renew(self::lock(false, true, true), 120);
    }

    public function testADriverWithoutRefreshKeepsTheFirstDuration(): void
    {
        $lock = self::lock('This lock driver does not support refreshing locks.');
        LockKeeper::renew($lock, 120);
        $this->assertSame(0, $lock->gets);
    }
}
