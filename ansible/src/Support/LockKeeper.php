<?php

namespace Agoodley\LibrenmsAnsible\Support;

use Agoodley\LibrenmsAnsible\Changes\LockLost;

/**
 * Renews a lock held during long work (Services\ChangeFollower's heartbeat), and tells when it is no longer this
 * process's. A refresh that answers false is not enough to tell: LibreNMS' database cache driver counts the lock's
 * row as updated only when its expiry changed, so a renewal within the second the lock was taken or last renewed
 * answers false too. So the lock's owner decides: taking a lock always writes its new owner, so while the row still
 * names this process nobody else held it since; an expired one is then taken again.
 */
final class LockKeeper
{
    /**
     * Renew the lock for $seconds; LockLost when another process holds it, or nobody does.
     *
     * @param  \Illuminate\Contracts\Cache\Lock&object  $lock  a Laravel lock (Illuminate\Cache\Lock: refresh(), isOwnedByCurrentProcess())
     *
     * @throws LockLost
     */
    public static function renew(object $lock, int $seconds): void
    {
        try {
            if ($lock->refresh($seconds)) {
                return;
            }
        } catch (\RuntimeException) {
            return; // a cache driver without refresh: the lock keeps its first duration
        }
        if (! method_exists($lock, 'isOwnedByCurrentProcess')) {
            return;
        }
        if (! $lock->isOwnedByCurrentProcess()) {
            throw new LockLost('the lock was taken by another request, or released, while this one worked');
        }
        // still this process's: renewed within the same second, or expired with nobody taking it since; taken again
        $lock->get();
        if (! $lock->isOwnedByCurrentProcess()) {
            throw new LockLost('the lock was taken by another request while this one worked');
        }
    }
}
