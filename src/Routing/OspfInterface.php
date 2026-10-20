<?php

namespace Agoodley\LibrenmsTopology\Routing;

/**
 * An OSPF interface (a row of ospf_ports) with its own address row, kept small like Address.
 */
final class OspfInterface
{
    /** an OSPF neighbor is seen on this interface */
    public bool $talking = false;

    /**
     * @param  string  $vrf  SNMP context of the OSPF instance, '' for the default one
     * @param  string  $type  network type, lowercase (broadcast, pointtopoint...)
     * @param  string  $state  interface state, lowercase (designatedrouter, pointtopoint...)
     * @param  string  $auth  authentication type, lowercase (none, md5...)
     */
    public function __construct(
        public readonly int $device,
        public readonly string $vrf,
        public readonly ?string $ip,
        public readonly ?Address $address,
        public readonly ?int $portId,
        public readonly string $area,
        public readonly string $type,
        public readonly string $state,
        public readonly ?int $hello,
        public readonly ?int $dead,
        public readonly string $auth,
        public readonly ?int $cost,
        public readonly ?string $dr,
    ) {
    }

    /**
     * Interface name, else its address.
     */
    public function port(): string
    {
        return $this->address->port ?? ($this->ip ?? '');
    }
}
