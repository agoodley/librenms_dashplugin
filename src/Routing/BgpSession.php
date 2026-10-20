<?php

namespace Agoodley\LibrenmsTopology\Routing;

/**
 * A BGP session as one router reports it (a row of bgpPeers with its bgpPeers_cbgp counters),
 * resolved to the monitored device that owns the peer address.
 */
final class BgpSession
{
    /** States of the BGP finite state machine; anything else (Junos' "unknown" before a peer is matched) is no data. */
    public const STATES = ['idle', 'connect', 'active', 'opensent', 'openconfirm', 'established'];

    /**
     * @param  string  $vrf  VRF name, '' for the global table
     * @param  string  $state  lowercase (established, active...)
     * @param  bool  $shut  shut down on purpose (admin status)
     * @param  int  $time  seconds since the session came up (established) or went down; 0 when it never came up
     * @param  ?Address  $remote  the peer's address row (null when the device has it more than once)
     * @param  ?Address  $local  this router's address of the session
     * @param  list<array{family: string, accepted: int, denied: int, limit: int, threshold: int}>  $prefixes  per address family
     */
    public function __construct(
        public readonly int $device,
        public readonly string $vrf,
        public readonly string $ip,
        public readonly ?int $remoteAs,
        public readonly ?int $localAs,
        public readonly string $state,
        public readonly bool $shut,
        public readonly ?int $errorCode,
        public readonly ?int $errorSubcode,
        public readonly string $errorText,
        public readonly int $time,
        public readonly string $description,
        public readonly ?int $remoteDevice,
        public readonly ?Address $remote,
        public readonly ?Address $local,
        public readonly array $prefixes,
    ) {
    }

    public function established(): bool
    {
        return $this->state === 'established';
    }

    /**
     * Is the state one of the BGP state machine?
     */
    public function known(): bool
    {
        return in_array($this->state, self::STATES, true);
    }

    /**
     * The last error decoded (BgpErrors::describe()), null when none was recorded.
     */
    public function error(): ?array
    {
        return BgpErrors::describe($this->errorCode, $this->errorSubcode);
    }

    public function hasError(int $code, int $subcode): bool
    {
        return $this->errorCode === $code && $this->errorSubcode === $subcode;
    }

    /**
     * iBGP or eBGP, null when either AS is unknown.
     */
    public function type(): ?string
    {
        return $this->remoteAs === null || $this->localAs === null ? null : ($this->remoteAs === $this->localAs ? 'iBGP' : 'eBGP');
    }
}
