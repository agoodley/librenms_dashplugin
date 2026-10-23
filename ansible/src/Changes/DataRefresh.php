<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * Asks LibreNMS for fresh data of devices (Services\LibrenmsRefresh; a fake in the tests): a discovery of each, as
 * LibreNMS' own scheduling would run it, so that a batch's verification does not wait for the next one (every 6 hours by
 * default). LibreNMS polls every device every few minutes on its own.
 */
interface DataRefresh
{
    /**
     * Ask for a discovery of the devices now. How: "dispatch" (a discovery job per device on LibreNMS' queue, as its
     * dispatcher does when its discovery is scheduled by Laravel) or "rediscover" (each device marked for rediscovery, as
     * its Rediscover button does: the discovery cron or the dispatcher service picks it up).
     *
     * @param  int[]  $deviceIds
     * @return array{how: string, devices: int[]}
     *
     * @throws \RuntimeException when LibreNMS cannot be asked
     */
    public function request(array $deviceIds): array;
}
