<?php

namespace Agoodley\LibrenmsTopology\Path;

use Agoodley\LibrenmsTopology\Stp\Finding;
use Agoodley\LibrenmsTopology\Support\Cidr;

/**
 * The rows of a path from A to B: the Layer 2 path within a subnet or VLAN, or the routed path from A's gateway router
 * by router with the Layer 2 segments between them; a gap with the finding that explains it where the path breaks.
 */
class PathAssembler
{
    /**
     * @param  bool  $admin  the user is a LibreNMS admin (sees the size of the whole route table)
     */
    public function __construct(
        private readonly PathNetwork $network,
        private readonly PathEndpoints $endpoints,
        private readonly PathLog $log,
        private readonly bool $admin = false,
    ) {
    }

    /**
     * Layer 2 within one subnet or VLAN, else routed from A's gateway.
     *
     * @return array{mode: string, rows: array<int, array>, complete: bool, vlan: ?int, b: array} b: B, which the routed path can
     *     tell when its address belongs to several devices
     */
    public function path(array $a, array $b, ?int $vlan): array
    {
        $aIp = $a['ip'];
        $bIp = $b['ip'];
        $routed = $aIp !== null && $bIp !== null && Cidr::family($aIp) === Cidr::family($bIp);
        if ($aIp !== null && $bIp !== null && ! $routed) {
            $this->log->note('info', 'A has an ' . (Cidr::family($aIp) === 4 ? 'IPv4' : 'IPv6') . ' address and B an '
                . (Cidr::family($bIp) === 4 ? 'IPv4' : 'IPv6') . ' one: only the Layer 2 path can be traced.');
        }

        $this->roleNotes();
        $gateway = $routed ? $this->endpoints->gateway($a) : null;
        if ($routed && $gateway === null) {
            $hidden = $this->network->roleHides('ipv4_addresses', 'ipv6_addresses');
            $this->log->note('warning', $hidden ? 'Your LibreNMS role may not view ' . implode(', ', $hidden) . ', so the routers on the way are not known: the Layer 2'
                . ' path is shown instead of the routed one.'
                : 'No router you can see has an interface in the subnet of ' . PathService::display($aIp) . ', so the routed path from A cannot be followed: the Layer 2'
                . ' path is shown instead.');
            $routed = false;
        } elseif ($routed && $gateway['address'] !== null && Cidr::contains((string) Cidr::network($gateway['address'], (int) $gateway['prefixlen']), $bIp)
            && $gateway['device_id'] !== $b['device_id']) {
            $routed = false; // the same subnet: B is reached at Layer 2
            $vlan ??= $this->network->l2Port($gateway['device_id'], $gateway['port_id'])[1];
        }
        $addresses = $this->network->roleHides('ipv4_addresses', 'ipv6_addresses');
        foreach (['A' => $a, 'B' => $b] as $side => $end) {
            if ($end['ip'] === null) {
                $this->log->note('info', $side . ($addresses ? ' has no IP address you may see (your LibreNMS role may not view ' . implode(', ', $addresses) . ')'
                    : ' has no IP address known to LibreNMS' . ($end['kind'] === 'host' ? ' (no ARP entry of it on the routers you can see)' : ''))
                    . ': only the Layer 2 path can be traced.');
            }
        }

        if (! $routed) {
            $vlan ??= $a['vlan'] ?? $b['vlan'];
            if ($a['vlan'] !== null && $b['vlan'] !== null && $a['vlan'] !== $b['vlan']) {
                $this->log->note('warning', 'A is in VLAN ' . $a['vlan'] . ' and B in VLAN ' . $b['vlan']
                    . ($aIp === null || $bIp === null ? ', and the IP address of ' . ($aIp === null ? 'A' : 'B') . ' is not known' : '')
                    . ': the Layer 2 path in VLAN ' . $vlan . ' is shown.');
            }
            [$rows, $complete] = $this->l2Path($a, $b, $vlan);

            return ['mode' => 'l2', 'rows' => $rows, 'complete' => $complete, 'vlan' => $vlan, 'b' => $b];
        }

        [$rows, $complete, $b] = $this->l3Path($a, $b, $gateway);

        return ['mode' => 'l3', 'rows' => $rows, 'complete' => $complete, 'vlan' => null, 'b' => $b];
    }

    /**
     * Rows of the L2 path: A, the devices between, B.
     *
     * @return array{0: array<int, array>, 1: bool}
     */
    private function l2Path(array $a, array $b, ?int $vlan): array
    {
        $rows = $a['kind'] === 'host' ? [self::hostRow($a)] : [];
        $ports = ['enter' => $a['port_id'], 'exit' => $b['port_id']];
        $segment = $this->segment($a['device_id'], $b['device_id'], $vlan, $ports);
        $rows = self::append($rows, $segment['rows'] ?? $this->broken($a['device_id'], $b['device_id'], $vlan, $ports, $segment['blockers']));
        if ($segment['rows'] !== null && $b['kind'] === 'host') {
            $rows[] = self::hostRow($b);
        }

        return [$rows, $segment['rows'] !== null];
    }

    /**
     * Rows of the routed path: A, the L2 segment to its gateway, the routers with the segments between them, the segment
     * from the last router to B, B.
     *
     * @param  array{device_id: int, port_id: ?int, vrf: string, address: ?string}  $gateway
     * @return array{0: array<int, array>, 1: bool, 2: array}
     */
    private function l3Path(array $a, array $b, array $gateway): array
    {
        $this->endpoints->gatewayNote($a, $gateway);
        $table = $this->network->routeTable();
        if ($table['hidden']) {
            $this->log->note('warning', 'Your LibreNMS role may not view routes: every router is crossed by an estimate from its subnets'
                . ($this->network->roleHides('ospf_nbrs', 'bgpPeers') ? '.' : ' and neighbors.'));
        } elseif ($table['skipped']) {
            $this->log->note('warning', 'The route table of LibreNMS holds ' . ($this->admin && $table['size'] !== null ? 'about ' . number_format($table['size']) . ' routes, ' : '')
                . 'more than the ' . number_format($table['limit']) . ' routes a path trace reads (setting "Route table limit"): every router is crossed by an estimate from its subnets and neighbors.');
        }
        // a router as A starts the path itself: the traffic does not come in on its interface
        $itself = $a['kind'] === 'device' && $gateway['device_id'] === $a['device_id'];
        $trace = $this->network->l3()->trace($gateway['device_id'], $itself ? null : ($gateway['port_id'] ?: null), $gateway['vrf'], $b['ip']);
        $b = $this->endpoints->reached($b, $trace) ?? $b;
        $hops = $trace['hops'];
        $rows = $a['kind'] === 'host' ? [self::hostRow($a)] : [];
        $complete = true;

        // A to its gateway
        if (! $itself) {
            [$arrive, $vlan] = $this->network->l2Port($gateway['device_id'], $gateway['port_id']);
            $ports = ['enter' => $a['port_id'], 'arrive' => $arrive];
            $segment = $this->segment($a['device_id'], $gateway['device_id'], $vlan ?? $a['vlan'], $ports);
            $complete = $segment['rows'] !== null;
            $rows = self::append($rows, $segment['rows'] ?? $this->broken($a['device_id'], $gateway['device_id'], $vlan ?? $a['vlan'], $ports, $segment['blockers']));
        }

        // router to router; the last one delivers to its connected subnet
        foreach ($hops as $index => $hop) {
            $this->hopNotes($hop);
            $delivers = $index === count($hops) - 1 && $trace['end'] === 'connected' && $trace['egress'] !== null ? self::subnet($trace['egress']) : null;
            $rows = self::append($rows, [self::routerRow(['connected' => $delivers] + $hop)]);
            $next = $hops[$index + 1] ?? null;
            if ($next !== null) {
                [$between, $adjacent] = $this->between($hop, $next);
                $rows = self::append($rows, $between);
                $complete = $complete && $adjacent;
            }
        }

        // the last router to B
        $last = $hops[count($hops) - 1];
        $tail = null;
        $reached = false;
        switch ($trace['end']) {
            case 'connected':
                $tail = $trace['egress'];
                break;
            case 'local':
                $reached = (int) $last['device_id'] === $b['device_id'];
                break;
            case 'outside':
                $complete = false;
                $resume = $this->endpoints->gateway($b);
                $resumes = $resume !== null && $resume['device_id'] !== (int) $last['device_id'] && ! in_array($resume['device_id'], array_map('intval', array_column($hops, 'device_id')), true);
                $rows[] = ['kind' => 'outside', 'name' => 'next hop ' . PathService::display((string) $trace['next_hop'])] + self::emptyRow();
                $this->log->add(Finding::WARNING, 'path_outside', 'the path leaves the devices you can see at ' . PathService::display((string) $trace['next_hop']),
                    $this->network->name((int) $last['device_id']) . ' routes ' . PathService::display($b['ip']) . ' to ' . PathService::display((string) $trace['next_hop'])
                    . ', which is not an address of a device you can see in LibreNMS (not monitored, or out of your sight).'
                    . ($resumes ? ' The path resumes at ' . $this->network->name($resume['device_id']) . ', which has the destination\'s subnet connected: the routers between are not known.' : ''),
                    'Add the routers on the way to LibreNMS to see the whole path.', [(int) $last['device_id']]);
                if ($resumes) {
                    // B itself (a router) is reached on its address; otherwise the router delivers to B's subnet
                    $itself = $b['kind'] === 'device' && $resume['device_id'] === $b['device_id'];
                    $rows[] = self::routerRow(['device_id' => $resume['device_id'], 'vrf' => $resume['vrf'],
                        'in_port' => $itself ? $resume['port_id'] : null, 'in_address' => $itself ? $resume['address'] : null,
                        'out_port' => $itself ? null : $resume['port_id'], 'out_address' => $itself ? null : $resume['address'],
                        'next_hop' => null, 'route' => null, 'estimated' => null, 'connected' => $itself ? null : self::subnet($resume)]);
                    $tail = $itself ? null : $resume;
                    $reached = $itself;
                }
                break;
            default:
                $complete = false;
                $this->routingFinding($trace, $b);
        }

        if ($tail !== null) {
            if ($b['kind'] === 'device' && $tail['device_id'] === $b['device_id'] && $b['port_id'] === null) {
                $reached = true;
            } else {
                [$leave, $vlan] = $this->network->l2Port($tail['device_id'], $tail['port_id'] ?? null);
                $ports = ['leave' => $leave, 'exit' => $b['port_id']];
                $segment = $this->segment($tail['device_id'], $b['device_id'], $vlan ?? $b['vlan'], $ports);
                $reached = $segment['rows'] !== null;
                $rows = self::append($rows, $segment['rows'] ?? $this->broken($tail['device_id'], $b['device_id'], $vlan ?? $b['vlan'], $ports, $segment['blockers']));
            }
        }
        if ($reached && $b['kind'] === 'host') {
            $rows[] = self::hostRow($b);
        }

        return [$rows, $complete && $reached, $b];
    }

    /**
     * What the user's LibreNMS role hides from the whole trace: the ports of the hops (the links then count as up, carrying
     * the VLAN and forwarding).
     */
    private function roleNotes(): void
    {
        if (! $this->network->allows('ports')) {
            $this->log->note('warning', 'Your LibreNMS role may not view ports: the hops show none' . ($this->network->allows('links')
                ? ', and whether the links on the path are up, carry the VLAN and forward is not known.' : '.'));
        }
    }

    /**
     * Notes on one router of the routed path: a next hop several devices have, a recursive next hop its routes do not
     * lead to.
     */
    private function hopNotes(array $hop): void
    {
        $name = $this->network->name((int) $hop['device_id']);
        if ($hop['ambiguous']) {
            $address = PathService::display((string) ($hop['via'] ?? $hop['next_hop']));
            $this->log->note('warning', $address . ', the next hop of ' . $name . ', is an address of ' . (count($hop['ambiguous']) + 1) . ' devices you can see ('
                . implode(', ', array_map(fn ($id) => $this->network->name((int) $id), array_slice($hop['ambiguous'], 0, 3))) . (count($hop['ambiguous']) > 3 ? ', ...' : '')
                . ' as well): neither the LLDP/CDP neighbors of its egress port nor the subnet tell which, so the path goes on at the first whose own route'
                . ' does not lead back, a guess.');
        }
    }

    /**
     * Rows between two consecutive routers: their direct link, the switches between their interfaces, or the two routers
     * alone with a note when no LLDP/CDP link the user may see joins them. adjacent: the second is a neighbor of the
     * first (on a subnet they share): the routers between them, if any, are not skipped.
     *
     * @return array{0: array<int, array>, 1: bool}
     */
    private function between(array $hop, array $next): array
    {
        $from = (int) $hop['device_id'];
        $to = (int) $next['device_id'];
        [$leave, $leaveVlan] = $this->network->l2Port($from, $hop['out_port']);
        [$arrive, $arriveVlan] = $this->network->l2Port($to, $next['in_port']);
        if ($leaveVlan === null && $arriveVlan === null) {
            $edge = $this->network->directEdge($from, $to, $leave, $arrive);
            if ($edge !== null) {
                return [[
                    ['node' => 'd:' . $from, 'kind' => 'device', 'device_id' => $from, 'out_edge' => $edge, 'out_port' => L2Tracer::portOn($edge, 'd:' . $from)] + self::emptyRow(),
                    ['node' => 'd:' . $to, 'kind' => 'device', 'device_id' => $to, 'in_edge' => $edge, 'in_port' => L2Tracer::portOn($edge, 'd:' . $to)] + self::emptyRow(),
                ], true];
            }
        }
        $segment = $this->segment($from, $to, $leaveVlan ?? $arriveVlan, ['leave' => $leave, 'arrive' => $arrive]);
        if ($segment['rows'] !== null) {
            return [$segment['rows'], true];
        }

        // no Layer 2 path: still neighbors, unless the second was reached by a recursive next hop the first one's routes do not lead to
        $adjacent = ! $hop['recursive'];
        $names = $this->network->name($from) . ' and ' . $this->network->name($to);
        $links = $this->network->allows('links');
        if ($adjacent) {
            $note = $links ? "No LLDP/CDP link you can see joins $names, neighbors on a subnet they share: the switches between them, if any, are not known."
                : "Your LibreNMS role may not view LLDP/CDP links: the switches between $names (neighbors on a subnet they share), if any, are not shown.";
        } else {
            $why = $hop['unread'] !== null ? 'its routes toward ' . PathService::display((string) $hop['unread']) . ' were not read (a path trace reads the route table toward '
                . PathNetwork::ROUTE_READS . ' addresses at most)' : 'its routes do not lead to it';
            $note = ($links ? "No LLDP/CDP link you can see joins $names" : "Your LibreNMS role may not view LLDP/CDP links between $names") . ': the next hop '
                . PathService::display((string) $hop['next_hop']) . ' is not on a subnet of ' . $this->network->name($from) . " and $why, so the routers between them are not known.";
        }

        return [[
            ['node' => 'd:' . $from, 'kind' => 'device', 'device_id' => $from, 'link_note' => $note] + self::emptyRow(),
            ['node' => 'd:' . $to, 'kind' => 'device', 'device_id' => $to] + self::emptyRow(),
        ], $adjacent];
    }

    /**
     * The Layer 2 path between two devices in a VLAN; rows null when there is no forwarding path (blockers: what stops it).
     *
     * @param  array{leave?: ?int, arrive?: ?int, enter?: ?int, exit?: ?int}  $ports
     * @return array{rows: ?array<int, array>, blockers: array}
     */
    private function segment(int $from, int $to, ?int $vlan, array $ports): array
    {
        $search = $this->network->l2($from, $to, $vlan, $ports);
        $trace = $search['trace'];
        if ($search['capped']) {
            $this->log->note('warning', 'The Layer 2 search between ' . $this->network->name($from) . ' and ' . $this->network->name($to) . ' stopped after reading the links of '
                . number_format(PathNetwork::MAX_EXPANDED) . ' devices: a shorter path through devices further away is not looked for.');
        }
        if (! $trace['found']) {
            return ['rows' => null, 'blockers' => $search['blockers']];
        }
        if ($trace['alternatives'] > 0) {
            $this->log->note('info', 'Between ' . $this->network->name($from) . ' and ' . $this->network->name($to) . ' '
                . ($trace['alternatives'] === 1 ? 'another path' : $trace['alternatives'] . ' other paths') . ' of the same length exist'
                . ($trace['mlag'] ? ': a port-channel spans a switch pair (MLAG / vPC), so traffic is spread over both.'
                    : ($trace['unknown_stp'] ? ': some of their ports have no spanning tree data, so the one in use cannot be told. The path shown prefers ports known to forward, then faster links.'
                        : '. The path shown is one of them.')));
        }
        $common = $this->network->commonInstance(array_values(array_filter(array_column($trace['hops'], 'device_id'))), $vlan);
        if ($common) {
            $one = count($common) === 1;
            $this->log->note('info', implode(', ', array_map(fn ($id) => $this->network->name($id), array_slice($common, 0, 4))) . (count($common) > 4 ? ' and ' . (count($common) - 4) . ' more' : '')
                . ($one ? ' runs' : ' run') . ' a single spanning tree instance in LibreNMS (RSTP, or MSTP, whose other instances LibreNMS does not read): '
                . ($one ? 'its' : 'their') . ' port states are taken for VLAN ' . $vlan . '. With MSTP the VLAN can map to another instance whose states differ.');
        }

        return ['rows' => array_map(fn ($hop) => array_merge(self::emptyRow(), $hop, ['in_vlan' => $vlan, 'out_vlan' => $vlan]), $trace['hops']), 'blockers' => []];
    }

    /**
     * Rows for a missing Layer 2 path: the two ends and a gap, with the finding that explains it.
     *
     * @param  array{leave?: ?int, arrive?: ?int, enter?: ?int, exit?: ?int}  $ports
     * @param  array<int, array{device_id: int, port_id: int, why: string}>  $blockers
     */
    private function broken(int $from, int $to, ?int $vlan, array $ports, array $blockers): array
    {
        $where = $vlan === null ? '' : ' in VLAN ' . $vlan;
        $gap = $blockers ? 'no forwarding path' . $where : 'not linked by LLDP/CDP';
        if (! $this->network->allows('links')) {
            // not a problem of the network: the user's role may not view the links the path would follow
            $gap = 'not shown: your role may not view LLDP/CDP links';
            $this->log->note('warning', 'The Layer 2 path between ' . $this->network->name($from) . ' and ' . $this->network->name($to) . $where
                . ' is not shown: your LibreNMS role may not view LLDP/CDP links.');
        } elseif ($blockers) {
            $list = array_map(fn ($blocker) => $this->network->name($blocker['device_id']) . ' ' . $this->network->portName($blocker['port_id']) . ' (' . $blocker['why'] . ')', array_slice($blockers, 0, 8));
            $this->log->add(Finding::CRITICAL, 'no_l2_path', 'no forwarding path' . $where . ' between ' . $this->network->name($from) . ' and ' . $this->network->name($to),
                'Every shortest chain of links between them is stopped: ' . implode(', ', $list) . (count($blockers) > 8 ? ' and ' . (count($blockers) - 8) . ' more' : '') . '.',
                'Check that the VLAN is allowed on the trunks between them and that their links are up; a port blocked by spanning tree on every path means a link is missing or down.',
                array_values(array_unique(array_merge([$from, $to], array_column($blockers, 'device_id')))), array_column($blockers, 'port_id'), $vlan);
        } else {
            $this->log->add(Finding::WARNING, 'not_linked', $this->network->name($from) . ' and ' . $this->network->name($to) . ' are not linked by LLDP/CDP',
                'No chain of LLDP/CDP links you can see joins them' . $where . ', so the switches between them are not known.',
                'Enable LLDP or CDP on the switches between them and check that LibreNMS polls them (and that you may see them).', [$from, $to], [], $vlan);
        }

        return [
            ['node' => 'd:' . $from, 'kind' => 'device', 'device_id' => $from, 'in_port' => $ports['enter'] ?? null, 'out_port' => $ports['leave'] ?? null,
                'in_vlan' => $vlan, 'out_vlan' => $vlan] + self::emptyRow(),
            ['kind' => 'gap', 'name' => $gap, 'in_vlan' => $vlan, 'out_vlan' => $vlan] + self::emptyRow(),
            ['node' => 'd:' . $to, 'kind' => 'device', 'device_id' => $to, 'in_port' => $ports['arrive'] ?? null, 'out_port' => $ports['exit'] ?? null,
                'in_vlan' => $vlan, 'out_vlan' => $vlan] + self::emptyRow(),
        ];
    }

    /**
     * Finding for a routed path that stops before B.
     */
    private function routingFinding(array $trace, array $b): void
    {
        $last = $trace['hops'][count($trace['hops']) - 1];
        $id = (int) $last['device_id'];
        $name = $this->network->name($id);
        $to = PathService::display((string) $b['ip']);
        $devices = array_map(fn ($hop) => (int) $hop['device_id'], $trace['hops']);
        $loopNames = fn () => implode(' → ', array_map(fn ($hop) => $this->network->name((int) $hop['device_id']), array_slice($trace['hops'], (int) $trace['loop'])))
            . ' → ' . $this->network->name((int) $trace['hops'][(int) $trace['loop']]['device_id']);
        // a router forwarding toward a recursive next hop, as it has no route to B itself
        $toward = $last['toward'] !== null ? ' nor ' . PathService::display((string) $last['toward']) . ' (the next hop the route of '
            . $this->network->name((int) $last['toward_from']) . ' points at)' : '';
        // reached through an estimated or guessed next hop: it may not be on the real path
        $uncertain = ' It was reached through an estimated or guessed next hop (see the notes and the hops), so the real path may not cross it.';
        $missing = "The route table of $name in LibreNMS has no prefix that covers $to$toward and no default route";
        match (true) {
            $trace['end'] === 'no_route' && $trace['uncertain'] => $this->log->add(Finding::WARNING, 'path_unknown', "the way on from $name toward $to is not known",
                $missing . '.' . $uncertain, 'Let LibreNMS read the route tables of the routers before it, or tell the routers with the next hop\'s address apart, to follow the real path.', $devices),
            $trace['end'] === 'no_route' => $this->log->add(Finding::CRITICAL, 'no_route', "$name has no route to $to" . ($toward !== '' ? ' nor to its next hop ' . PathService::display((string) $last['toward']) : ''),
                $missing . ', so it drops the traffic.',
                'Add a route (or a default route) toward the destination, or check the routing protocol that should provide it.', [$id]),
            $trace['end'] === 'blackhole' && $trace['uncertain'] => $this->log->add(Finding::WARNING, 'path_unknown', "the way on from $name toward $to is not known",
                "The best route of $name toward " . ($last['toward'] !== null ? PathService::display((string) $last['toward']) : $to) . ' (' . ($last['route']['prefix'] ?? '?') . ') is a reject / null route.' . $uncertain,
                'Let LibreNMS read the route tables of the routers before it, or tell the routers with the next hop\'s address apart, to follow the real path.', $devices),
            $trace['end'] === 'blackhole' => $this->log->add(Finding::CRITICAL, 'blackhole_route', "$name discards traffic to $to",
                "The best route of $name toward " . ($last['toward'] !== null ? PathService::display((string) $last['toward']) . ' (the next hop the route of '
                    . $this->network->name((int) $last['toward_from']) . ' points at)' : $to) . ' (' . ($last['route']['prefix'] ?? '?') . ') is a reject / null route.',
                'Remove the null route, or add a more specific route if the destination should be reachable.', [$id]),
            $trace['end'] === 'loop' && $trace['uncertain_loop'] => $this->log->add(Finding::WARNING, 'path_unknown', "the way on toward $to is not known",
                'Following the routes leads back to ' . $this->network->name((int) $trace['hops'][(int) $trace['loop']]['device_id']) . ' (' . $loopNames() . '), but an estimated'
                . ' or guessed next hop is part of that way: the router without routes in LibreNMS, or the one with the next hop\'s address, may be another.',
                'Let LibreNMS read the route tables of the estimated routers, or tell the routers with the next hop\'s address apart, to follow the real path.', $devices),
            $trace['end'] === 'loop' => $this->log->add(Finding::CRITICAL, 'routing_loop', "routing loop toward $to",
                'The next hops lead back to ' . $this->network->name((int) $trace['hops'][(int) $trace['loop']]['device_id']) . ': ' . $loopNames() . '.',
                'Check the routes of these routers toward the destination: they point at each other.', $devices),
            $trace['end'] === 'limit' => $this->log->add(Finding::WARNING, 'hop_limit', 'more than ' . L3Tracer::MAX_HOPS . ' routers toward ' . $to,
                'The path was followed for ' . L3Tracer::MAX_HOPS . ' routers without reaching the destination.', 'Check for a routing loop through devices you cannot see.', []),
            default => $this->log->add(Finding::WARNING, 'path_unknown', "the way on from $name toward $to is not known",
                self::noRoutes($name, (string) $trace['stuck'], (string) $last['vrf']) . ", and no router you can see on its subnets or among its OSPF / BGP neighbors (other than the ones already on the path) leads to $to's subnet.",
                self::routesAdvice((string) $trace['stuck']) . ' Add the router with the destination\'s subnet to LibreNMS if it is missing.', [$id]),
        };
    }

    /**
     * Why LibreNMS has no current routes of a router (an L3Tracer::ROUTES_ state).
     */
    public static function noRoutes(string $name, string $state, string $vrf): string
    {
        return match ($state) {
            L3Tracer::ROUTES_HIDDEN => "Your LibreNMS role may not view the routes of $name",
            L3Tracer::ROUTES_UNREAD => "The routes of $name toward this address were not read (a path trace reads the route table toward " . PathNetwork::ROUTE_READS
                . ' addresses at most)',
            L3Tracer::ROUTES_SKIPPED => 'The route table of LibreNMS was not read (it holds more routes than a path trace reads)',
            L3Tracer::ROUTES_STALE => "LibreNMS' routes of $name were not read again at its last discovery",
            L3Tracer::ROUTES_VRF => "LibreNMS has routes of $name, but none " . ($vrf === '' ? 'in its global table' : 'in VRF ' . $vrf),
            default => "LibreNMS has no routes of $name",
        };
    }

    /**
     * What makes LibreNMS read the routes of routers in that state.
     */
    public static function routesAdvice(string $state): string
    {
        return match ($state) {
            L3Tracer::ROUTES_HIDDEN => 'A LibreNMS admin can give your role the permission to view routing data (routing.view).',
            L3Tracer::ROUTES_UNREAD => 'Trace toward the next hop of the last router whose routes were read to follow the path further.',
            L3Tracer::ROUTES_SKIPPED => 'Raise the setting "Route table limit" if the database scans the route table quickly enough.',
            L3Tracer::ROUTES_STALE => 'Check that the route discovery module still runs for them and that they have fewer routes than LibreNMS reads of a device'
                . ' (lnms config:set routes_max_number, 1000 by default).',
            L3Tracer::ROUTES_VRF => 'LibreNMS reads the routes of VRFs for some operating systems only: check the route discovery output of these routers.',
            default => 'Enable the route discovery module for the routers on the path (lnms config:set discovery_modules.route true, or per device).',
        };
    }

    /**
     * Join a segment to the rows: when it starts on the device the rows end on, that row leaves by the segment's first
     * link and keeps where it came in, its routing decision and its VLAN.
     */
    private static function append(array $rows, array $segment): array
    {
        $last = count($rows) - 1;
        if ($segment && $last >= 0 && $rows[$last]['node'] !== null && $rows[$last]['node'] === $segment[0]['node']) {
            $first = array_shift($segment);
            if ($first['out_edge'] !== null || $first['out_port'] !== null) {
                $rows[$last]['out_edge'] = $first['out_edge'];
                $rows[$last]['out_port'] = $first['out_port'];
                $rows[$last]['out_vlan'] = $first['out_vlan'];
            }
            foreach (['in_port', 'in_edge', 'in_vlan', 'out_vlan', 'l3', 'link_note'] as $key) {
                $rows[$last][$key] ??= $first[$key];
            }
        }

        return array_merge($rows, $segment);
    }

    private static function hostRow(array $endpoint): array
    {
        return ['kind' => 'host', 'name' => $endpoint['label'], 'endpoint' => $endpoint, 'in_vlan' => $endpoint['vlan'], 'out_vlan' => $endpoint['vlan']] + self::emptyRow();
    }

    private static function routerRow(array $hop): array
    {
        return ['node' => 'd:' . $hop['device_id'], 'kind' => 'device', 'device_id' => (int) $hop['device_id'], 'l3' => $hop + ['connected' => null]] + self::emptyRow();
    }

    public static function emptyRow(): array
    {
        return ['node' => null, 'kind' => null, 'device_id' => null, 'name' => null, 'role' => null, 'in_edge' => null, 'out_edge' => null,
            'in_port' => null, 'out_port' => null, 'in_vlan' => null, 'out_vlan' => null, 'l3' => null, 'link_note' => null, 'endpoint' => null];
    }

    /**
     * 10.90.130.0/24 of an interface.
     */
    private static function subnet(array $interface): ?string
    {
        return ($interface['address'] ?? null) !== null && ($interface['prefixlen'] ?? 0) > 0 ? Cidr::network((string) $interface['address'], (int) $interface['prefixlen']) : null;
    }
}
