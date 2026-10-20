<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * The LibreNMS data each page reads that a role permission can hide (ScopedRepository::TABLE_MODELS):
 * the page says what is left out, so an empty answer is not taken for "nothing found". Syslog has its
 * own notice on the spanning tree page.
 */
class RoleData
{
    /** Navigation key => LibreNMS tables. */
    public const PAGES = [
        'l2' => ['links', 'device_outages', 'stp', 'ports_stp'],
        'l3' => ['ospf_nbrs', 'bgpPeers', 'ospfv3_nbrs', 'isis_adjacencies', 'ipsec_tunnels', 'mpls_lsps', 'pseudowires'],
        'sites' => ['links', 'ospf_nbrs', 'bgpPeers', 'device_outages'],
        'stp' => ['stp', 'ports_stp', 'links', 'eventlog', 'ports_fdb'],
        'links' => ['links'],
        'routing' => ['ospf_instances', 'ospf_areas', 'ospf_ports', 'ospf_nbrs', 'bgpPeers', 'links'],
        'redundancy' => ['links', 'device_outages'],
        'coverage' => ['links', 'ports_fdb'],
        'hosts' => ['ports', 'ports_fdb', 'ipv4_mac', 'ipv6_nd', 'ports_nac', 'links'],
        'rollout' => ['links'],
        'dependencies' => ['links', 'device_outages'],
        'changes' => ['links', 'ports', 'stp', 'ports_stp', 'ospf_nbrs', 'bgpPeers'],
    ];
}
