<?php

namespace Agoodley\LibrenmsTopology\Support;

/**
 * The LibreNMS data each page reads that a role permission can hide (ScopedRepository::TABLE_MODELS):
 * the page says what is left out, so an empty answer is not taken for "nothing found". Syslog has its
 * own notice on the spanning tree page.
 */
class RoleData
{
    private const L2 = ['links', 'device_outages', 'stp', 'ports_stp', 'ports', 'ports_stack', 'ports_vlans'];

    private const L3 = ['ospf_nbrs', 'bgpPeers', 'ospfv3_nbrs', 'isis_adjacencies', 'ipsec_tunnels', 'mpls_lsps', 'pseudowires', 'ipv4_addresses', 'ipv6_addresses'];

    /** Navigation key => LibreNMS tables. */
    public const PAGES = [
        'l2' => self::L2,
        'l3' => self::L3,
        'sites' => ['links', 'ospf_nbrs', 'bgpPeers', 'device_outages', 'ipv4_addresses', 'ipv6_addresses', 'ports', 'ports_stack'],
        'stp' => ['stp', 'ports_stp', 'links', 'eventlog', 'ports_fdb', 'ports', 'ports_stack', 'ports_statistics'],
        'links' => ['links', 'ports', 'ports_stack', 'ports_vlans', 'ports_statistics', 'transceivers', 'sensors'],
        'routing' => ['ospf_instances', 'ospf_areas', 'ospf_ports', 'ospf_nbrs', 'bgpPeers', 'links', 'ipv4_addresses', 'ipv6_addresses'],
        'redundancy' => ['links', 'device_outages', 'ports', 'ports_stack', 'entPhysical'],
        'coverage' => ['links', 'ports_fdb', 'ports', 'ports_stack', 'ports_vlans', 'ipv4_addresses', 'ipv6_addresses'],
        'hosts' => ['ports', 'ports_fdb', 'ipv4_mac', 'ipv6_nd', 'ports_nac', 'links', 'ipv4_addresses', 'ipv6_addresses', 'ports_vlans'],
        // its own data, then the host locator's, which locates the ends
        'path' => ['links', 'ports', 'ports_stp', 'ipv4_addresses', 'ipv6_addresses', 'route', 'ospf_nbrs', 'bgpPeers', 'stp', 'ports_fdb', 'ipv4_mac', 'ipv6_nd', 'ports_nac'],
        'rollout' => ['links', 'ports', 'ports_stack'],
        'dependencies' => ['links', 'device_outages', 'ports', 'ports_stack'],
        'changes' => ['links', 'ports', 'stp', 'ports_stp', 'ospf_nbrs', 'bgpPeers'],
        // saved views open the L2 and L3 maps
        'views' => [...self::L2, ...self::L3],
    ];
}
