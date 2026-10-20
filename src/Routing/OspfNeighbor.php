<?php

namespace Agoodley\LibrenmsTopology\Routing;

/**
 * An OSPF neighbor as one router reports it (a row of ospf_nbrs), resolved to the monitored device
 * that owns its address and to the local interface facing it.
 */
final class OspfNeighbor
{
    /**
     * @param  string  $vrf  SNMP context of the local OSPF instance
     * @param  string  $state  neighbor state, lowercase (full, exchangestart...)
     * @param  ?Address  $remote  the neighbor's address row (null when the device has it more than once)
     * @param  ?Address  $local  the local address whose subnet holds the neighbor's address
     */
    public function __construct(
        public readonly int $device,
        public readonly string $vrf,
        public readonly string $ip,
        public readonly string $routerId,
        public readonly string $state,
        public readonly ?int $remoteDevice,
        public readonly ?Address $remote,
        public readonly ?Address $local,
        public readonly ?OspfInterface $interface,
    ) {
    }

    /**
     * Full, or 2-Way where that is normal (two routers that are neither DR nor BDR of a broadcast segment).
     */
    public function ok(): bool
    {
        return $this->state === 'full' || ($this->state === 'twoway' && ($this->interface->type ?? null) !== 'pointtopoint');
    }
}
