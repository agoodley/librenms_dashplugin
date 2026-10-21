<?php

namespace Agoodley\LibrenmsTopology\Path;

use Agoodley\LibrenmsTopology\Hosts\HostLocator;
use Agoodley\LibrenmsTopology\Hosts\HostQuery;
use Agoodley\LibrenmsTopology\Support\Cidr;
use Agoodley\LibrenmsTopology\Support\InterfaceName;

/**
 * The ends of a path and where their traffic leaves their subnet: a device (and port) given by id, the device owning an
 * address or named so, or a host the host locator finds; the router with the end's subnet connected.
 */
class PathEndpoints
{
    /**
     * @param  (\Closure(string): array)|null  $hosts  host locator search (HostService::search); none: hosts are not located
     * @param  \Closure(string, array): string  $urls  route name and parameters => URL
     */
    public function __construct(
        private readonly PathNetwork $network,
        private readonly PathLog $log,
        private readonly ?\Closure $hosts,
        private readonly \Closure $urls,
    ) {
    }

    /**
     * One end of the path: a device (and port) given by id, the device owning an address or named so, or a host the
     * host locator finds. owners lists the other devices with the address it was typed as.
     */
    public function resolve(string $text, ?int $deviceId, ?int $portId): array
    {
        $endpoint = ['input' => $text, 'resolved' => false, 'message' => null, 'kind' => null, 'label' => $text, 'device' => null, 'port' => null, 'port_url' => null, 'mac' => null,
            'readable' => null, 'vendor' => null, 'ip' => null, 'ips' => [], 'device_id' => null, 'port_id' => null, 'vlan' => null, 'status' => null,
            'notes' => [], 'others' => 0, 'arp' => [], 'owners' => [], 'host_url' => null];

        if ($deviceId) {
            return $this->device($endpoint, $deviceId, $portId);
        }

        $parsed = HostQuery::parse($text);
        if ($parsed['kind'] === HostQuery::INVALID) {
            return ['message' => 'Enter an IPv4 or IPv6 address, a MAC address, a hostname or a device name.'] + $endpoint;
        }
        $ip = in_array($parsed['kind'], [HostQuery::IPV4, HostQuery::IPV6], true) ? (Cidr::canonical($parsed['value']) ?? $parsed['value']) : null;
        if ($ip !== null && ($owners = $this->network->l3()->owners($ip))) {
            // an interface address of a device: the path ends at that device and interface
            $owner = $owners[0];
            $device = $this->device($endpoint, $owner['device_id'], $owner['port_id']);
            $others = array_values(array_diff(array_unique(array_column($owners, 'device_id')), [$owner['device_id']]));
            if ($others && $device['resolved']) {
                $device['owners'] = $others;
                $device['notes'][] = ['level' => 'warning', 'text' => PathService::display($ip) . ' is an address of ' . (count($others) + 1) . ' devices ('
                    . implode(', ', array_map(fn ($id) => $this->network->name($id), array_slice(array_merge([$owner['device_id']], $others), 0, 4)))
                    . (count($others) > 3 ? ', ...' : '') . '): the path uses ' . $device['device'] . '.'];
            }

            return array_merge($device, ['ip' => $ip, 'ips' => array_values(array_unique(array_merge([$ip], $device['ips'])))]);
        }

        if ($this->hosts === null) {
            return ['message' => $text . ' is not an address or name of a device you can see.' . $this->roleHint()] + $endpoint;
        }
        $search = ($this->hosts)($text);
        $device = $search['devices'][0] ?? null;
        if ($device !== null && in_array($device['role'], HostLocator::NETWORK_ROLES, true)) {
            $endpoint = $this->device($endpoint, (int) $device['device_id'], null);
            if (count($search['devices']) > 1) {
                $endpoint['notes'][] = ['level' => 'info', 'text' => count($search['devices']) . ' devices are named like this; the path uses ' . $endpoint['label'] . '.'];
            }

            return $endpoint;
        }

        $endpoint['host_url'] = ($this->urls)('topology.hosts', ['q' => $text]);
        // a host seen at the last discovery of its switch before one that was not
        $usable = array_values(array_filter($search['hosts'], fn ($host) => in_array($host['status'], ['located', 'behind', 'owner', 'gateway'], true)));
        usort($usable, fn ($x, $y) => (int) ! empty($x['stale']) <=> (int) ! empty($y['stale']));
        $host = $usable[0] ?? null;
        if ($host === null) {
            $why = match ($search['hosts'][0]['status'] ?? null) {
                'uplinks' => 'the switches that learned it only list it on links between switches.',
                'none' => 'no switch you can see has it in its forwarding table.',
                default => null,
            };

            return ['message' => ($why !== null ? $text . ' cannot be located: ' . $why : ($search['message'] ?? $text . ' cannot be located.')) . $this->roleHint()] + $endpoint;
        }

        $ips = array_values(array_unique(array_merge($ip !== null ? [$ip] : [],
            array_map(fn ($address) => Cidr::canonical((string) $address['ip']) ?? (string) $address['ip'], $host['addresses']))));
        usort($ips, fn ($x, $y) => Cidr::family($x) <=> Cidr::family($y)); // IPv4 first
        if ($ip !== null) {
            $ips = array_values(array_unique(array_merge([$ip], $ips)));
        }
        $notes = array_slice($host['notes'], 0, 3);
        if (! empty($host['stale']) && in_array($host['status'], ['located', 'behind'], true)) {
            array_unshift($notes, ['level' => 'warning', 'text' => 'The forwarding tables did not list this host at their last discovery: it may be switched off or gone,'
                . ' and the path shows where it was last seen.']);
        }
        // the routers with its ARP / neighbor entries: its gateways when LibreNMS keeps no network of its subnet
        foreach ($host['addresses'] as $address) {
            $this->network->l3()->arpEntries((string) $address['ip'], [$address]);
        }
        $endpoint = array_merge($endpoint, [
            'resolved' => true, 'kind' => 'host', 'mac' => $host['mac'], 'readable' => $host['readable'], 'vendor' => $host['vendor'],
            'ips' => $ips, 'ip' => $ips[0] ?? null, 'status' => $host['status'], 'others' => count($search['hosts']) - 1,
            'arp' => array_map(fn ($address) => ['device_id' => (int) $address['device_id'], 'port_id' => (int) $address['port_id']], $host['addresses']),
            'notes' => array_values(array_unique($notes, SORT_REGULAR)),
            'label' => $host['readable'] && strtolower($host['readable']) !== strtolower($text) ? $text . ' (' . $host['readable'] . ')' : $text,
        ]);

        switch ($host['status']) {
            case 'located':
                $endpoint['device_id'] = (int) $host['edge']['device_id'];
                $endpoint['port_id'] = (int) $host['edge']['port_id'] ?: null;
                $endpoint['port'] = $endpoint['port_id'] ? $host['edge']['port'] : null;
                $endpoint['port_url'] = $host['edge']['port_url'] ?? null;
                $endpoint['vlan'] = $host['vlan'] ?? ($host['edge']['vlans'][0] ?? null);
                break;
            case 'behind':
                $endpoint['device_id'] = (int) $host['device']['device_id'];
                $endpoint['vlan'] = $host['vlan'] ?? ($host['edge']['vlans'][0] ?? null);
                $endpoint['notes'][] = ['level' => 'warning', 'text' => 'Located only as far as ' . $host['device']['name'] . ': the path ends there.'];
                break;
            default:
                // the address belongs to a monitored switch, router or firewall (its own or a virtual router address)
                $device = $this->device($endpoint, (int) $host['device']['device_id'], null);

                return array_merge($device, ['ip' => $endpoint['ip'] ?? $device['ip'], 'ips' => array_values(array_unique(array_merge($ips, $device['ips'])))]);
        }
        $endpoint['device'] = $this->network->name($endpoint['device_id']);

        return $endpoint;
    }

    /**
     * " Your LibreNMS role may not view ..." when the user's role hides data an end is looked up in: interface addresses
     * and the host locator's tables.
     */
    private function roleHint(): string
    {
        $hidden = $this->network->roleHides('ipv4_addresses', 'ipv6_addresses', 'ports', 'ports_fdb', 'ipv4_mac', 'ipv6_nd', 'ports_nac');

        return $hidden ? ' Your LibreNMS role may not view ' . implode(', ', $hidden) . ', where hosts and addresses are looked up.' : '';
    }

    /**
     * What the page and the API show of an end: no ARP rows or other owners.
     */
    public static function publicView(array $endpoint): array
    {
        unset($endpoint['arp'], $endpoint['owners']);

        return $endpoint;
    }

    /**
     * The router an end's traffic leaves its subnet through: the end itself when it is a router, else a routing device
     * with its subnet connected, preferably the one with its ARP entry.
     *
     * @return array{device_id: int, port_id: ?int, vrf: string, address: ?string, prefixlen?: int}|null
     */
    public function gateway(array $endpoint): ?array
    {
        if ($endpoint['ip'] === null) {
            return null;
        }
        if ($endpoint['kind'] === 'device' && in_array($this->network->role($endpoint['device_id']), PathNetwork::ROUTING_ROLES, true)) {
            foreach ($this->network->l3()->owners($endpoint['ip']) as $own) {
                if ($own['device_id'] === $endpoint['device_id']) {
                    return $own;
                }
            }

            return ['device_id' => $endpoint['device_id'], 'port_id' => null, 'vrf' => '', 'address' => null];
        }

        return $this->gatewayCandidates($endpoint)[0] ?? null;
    }

    /**
     * Routing devices with the end's subnet connected, the one with its ARP entry first.
     *
     * @return array<int, array>
     */
    public function gatewayCandidates(array $endpoint): array
    {
        $candidates = $endpoint['ip'] === null ? [] : $this->network->l3()->gateways($endpoint['ip']);
        $arp = array_column($endpoint['arp'], 'device_id');
        usort($candidates, fn ($x, $y) => [! in_array($x['device_id'], $arp, true), -$x['prefixlen'], $x['device_id']]
            <=> [! in_array($y['device_id'], $arp, true), -$y['prefixlen'], $y['device_id']]);

        return $candidates;
    }

    /**
     * Which of several routers on the end's subnet the path leaves through, and why.
     */
    public function gatewayNote(array $endpoint, array $gateway): void
    {
        if ($endpoint['kind'] === 'device' && $gateway['device_id'] === $endpoint['device_id']) {
            return;
        }
        $others = array_values(array_unique(array_filter(array_column($this->gatewayCandidates($endpoint), 'device_id'), fn ($id) => $id !== $gateway['device_id'])));
        if ($others) {
            $arp = in_array($gateway['device_id'], array_column($endpoint['arp'], 'device_id'), true);
            $this->log->note('info', $this->network->name($others[0]) . (count($others) > 1 ? ' and ' . (count($others) - 1) . ' more' : '')
                . ' also ' . (count($others) > 1 ? 'have' : 'has') . ' an interface in the subnet of ' . PathService::display($endpoint['ip']) . ': the path leaves it through '
                . $this->network->name($gateway['device_id']) . ($arp ? ', which has its ARP entry' : '') . '. With HSRP / VRRP the active router can be another one.');
        }
    }

    /**
     * B typed as an address of several devices: the one the routed path reaches (on the last router's egress link or
     * subnet, or the last router itself); null when that does not tell.
     */
    public function reached(array $endpoint, array $trace): ?array
    {
        if (! $endpoint['owners'] || ! $trace['hops']) {
            return null;
        }
        $last = $trace['hops'][count($trace['hops']) - 1];
        $candidates = array_merge([$endpoint['device_id']], $endpoint['owners']);
        $pick = null;
        if ($trace['end'] === 'local' && in_array((int) $last['device_id'], $candidates, true)) {
            $pick = (int) $last['device_id'];
        } elseif ($trace['end'] === 'connected' && $trace['egress'] !== null && $trace['egress']['port_id']) {
            $linked = array_values(array_intersect($candidates, array_keys($this->network->linkedDevices((int) $last['device_id'], (int) $trace['egress']['port_id']))));
            $pick = $linked[0] ?? null;
        }
        if ($pick === null || $pick === $endpoint['device_id']) {
            return null;
        }
        $owner = null;
        foreach ($this->network->l3()->owners((string) $endpoint['ip']) as $own) {
            if ($own['device_id'] === $pick) {
                $owner = $own;
                break;
            }
        }
        $device = $this->device(['input' => $endpoint['input'], 'notes' => []] + $endpoint, $pick, $owner['port_id'] ?? null);
        if (! $device['resolved']) {
            return null;
        }
        $device['owners'] = array_values(array_diff($candidates, [$pick]));
        $device['notes'] = [['level' => 'info', 'text' => PathService::display((string) $endpoint['ip']) . ' is an address of ' . count($candidates) . ' devices: the path reaches '
            . $device['device'] . ', the one ' . ($trace['end'] === 'local' ? 'that routes it' : 'on the link of ' . $this->network->name((int) $last['device_id']) . '\'s interface') . '.']];

        return array_merge($device, ['ip' => $endpoint['ip'], 'ips' => array_values(array_unique(array_merge([$endpoint['ip']], $device['ips'])))]);
    }

    /**
     * A monitored device (and port) as an end of the path; its addresses are where routing toward it ends.
     */
    private function device(array $endpoint, int $deviceId, ?int $portId): array
    {
        $device = $this->network->device($deviceId);
        if ($device === null) {
            return ['message' => 'Device #' . $deviceId . ' is not a device you can see.'] + $endpoint;
        }
        $name = $this->network->name($deviceId);
        $port = null;
        if ($portId !== null && ! $this->network->allows('ports')) {
            $endpoint['notes'][] = ['level' => 'warning', 'text' => 'Port #' . $portId . ' is left out: your LibreNMS role may not view ports.'];
            $portId = null;
        }
        if ($portId !== null) {
            $port = $this->network->port($portId);
            if ($port === null || (int) $port['device_id'] !== $deviceId) {
                return ['message' => 'Port #' . $portId . ' is not a port of ' . $name . '.'] + $endpoint;
            }
        }

        // the address of the port, the one LibreNMS polls the device on, any other interface address
        $interfaces = $this->network->interfacesOf($deviceId);
        $ips = array_merge(
            array_column(array_filter($interfaces, fn ($row) => $portId !== null && $row['port_id'] === $portId), 'address'),
            $this->network->pollAddresses($deviceId),
            array_column($interfaces, 'address'),
        );
        $ips = array_values(array_unique(array_filter(array_map(fn ($ip) => Cidr::canonical((string) $ip), $ips), fn ($ip) => $ip !== null && ! Cidr::isIgnorable($ip))));
        $vlan = $port !== null && is_numeric($port['ifVlan'] ?? null) && (int) $port['ifVlan'] > 0 ? (int) $port['ifVlan'] : null;

        return array_merge($endpoint, [
            'resolved' => true, 'message' => null, 'kind' => 'device', 'status' => 'device', 'device_id' => $deviceId, 'port_id' => $portId, 'vlan' => $vlan,
            'ips' => $ips, 'ip' => $ips[0] ?? null, 'device' => $name,
            'port' => $port !== null ? InterfaceName::short(InterfaceName::label($port)) : null, 'port_url' => $port['url'] ?? null,
            'label' => $name . ($port !== null ? ' ' . InterfaceName::short(InterfaceName::label($port)) : ''),
        ]);
    }
}
