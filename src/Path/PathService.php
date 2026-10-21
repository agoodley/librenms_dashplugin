<?php

namespace Agoodley\LibrenmsTopology\Path;

use Agoodley\LibrenmsTopology\Data\PathRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Hosts\HostQuery;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Support\RoleData;

/**
 * A→B path trace on the data the user may see: resolves both ends (PathEndpoints: a host located with the host
 * locator, or a device and port), follows the Layer 2 path within a subnet or VLAN or the routed path from A's gateway
 * router by router with the Layer 2 segments between the routers (PathAssembler, on what PathNetwork reads), and
 * describes every hop and the problems on the path (PathReport).
 */
class PathService
{
    /** What the traces of this request have read of the network (no notes or findings: those are per trace). */
    private ?PathNetwork $network = null;

    /** @var (\Closure(string): array)|null */
    private ?\Closure $hosts;

    private \Closure $urls;

    /**
     * @param  (\Closure(string): array)|null  $hosts  host locator search (HostService::search); none: hosts are not located
     * @param  (\Closure(string, array): string)|null  $urls  route name and parameters => URL (default: route())
     * @param  bool  $admin  the user is a LibreNMS admin
     */
    public function __construct(
        private readonly PathRepository $repo,
        private readonly TopologyRepository $topology,
        private readonly PluginSettings $settings,
        ?\Closure $hosts = null,
        ?\Closure $urls = null,
        private readonly bool $admin = false,
    ) {
        $this->hosts = $hosts;
        $this->urls = $urls ?? fn (string $name, array $parameters = []) => route($name, $parameters);
    }

    /**
     * @param  array{a?: string, b?: string, a_device?: ?int, a_port?: ?int, b_device?: ?int, b_port?: ?int, vlan?: ?int}  $query
     * @return array{query: array, a: ?array, b: ?array, mode: ?string, vlan: ?int, complete: bool, hops: array, findings: array,
     *     device_names: array, notes: array, summary: ?array, map_url: ?string, message: ?string, role_hidden: string[]}
     *     role_hidden: what the user's LibreNMS role may not view of the data a trace uses (the trace leaves it out)
     */
    public function trace(array $query): array
    {
        $query = [
            'a' => trim((string) ($query['a'] ?? '')), 'b' => trim((string) ($query['b'] ?? '')),
            'a_device' => $query['a_device'] ?? null, 'a_port' => $query['a_port'] ?? null,
            'b_device' => $query['b_device'] ?? null, 'b_port' => $query['b_port'] ?? null,
            'vlan' => $query['vlan'] ?? null,
        ];
        $result = ['query' => $query, 'a' => null, 'b' => null, 'mode' => null, 'vlan' => null, 'complete' => false, 'hops' => [],
            'findings' => [], 'device_names' => [], 'notes' => [], 'summary' => null, 'map_url' => null, 'message' => null,
            'role_hidden' => $this->repo->hiddenKinds(RoleData::PAGES['path'])];
        $hasA = $query['a'] !== '' || $query['a_device'];
        $hasB = $query['b'] !== '' || $query['b_device'];
        if (! $hasA || ! $hasB) {
            $result['message'] = $hasA || $hasB ? 'Enter both ends of the path.' : null;

            return $result;
        }

        $log = new PathLog;
        $network = $this->network ??= new PathNetwork($this->repo, $this->topology, $this->settings);
        $endpoints = new PathEndpoints($network, $log, $this->hosts, $this->urls);
        $a = $endpoints->resolve($query['a'], $query['a_device'] ? (int) $query['a_device'] : null, $query['a_port'] ? (int) $query['a_port'] : null);
        $b = $endpoints->resolve($query['b'], $query['b_device'] ? (int) $query['b_device'] : null, $query['b_port'] ? (int) $query['b_port'] : null);
        $result['a'] = PathEndpoints::publicView($a);
        $result['b'] = PathEndpoints::publicView($b);
        if (! $a['resolved'] || ! $b['resolved']) {
            $result['message'] = implode(' ', array_filter([$a['resolved'] ? null : 'A: ' . $a['message'], $b['resolved'] ? null : 'B: ' . $b['message']]));

            return $result;
        }

        $path = (new PathAssembler($network, $endpoints, $log, $this->admin))->path($a, $b, $query['vlan'] ? (int) $query['vlan'] : null);
        $report = new PathReport($this->settings, $this->urls);
        $hops = $report->hops($path['rows'], $this->details($network, $path['rows']));
        $report->check($hops, $log);
        $findings = $log->findings();

        return array_merge($result, [
            'b' => PathEndpoints::publicView($path['b']),
            'mode' => $path['mode'],
            'vlan' => $path['vlan'],
            'complete' => $path['complete'],
            'hops' => $hops,
            'findings' => $findings,
            'device_names' => $network->names(array_merge([], ...array_column($findings, 'devices'))),
            'notes' => $log->notes(),
            'summary' => $report->summary($hops, $findings, $path['complete'], $path['mode']),
            'map_url' => $report->mapUrl($hops, $path['vlan']),
        ]);
    }

    /**
     * What PathReport reads of the hops: their devices, their ports with counters (one query), discards, spanning tree
     * states.
     */
    private function details(PathNetwork $network, array $rows): array
    {
        $portIds = PathReport::portIds($rows);
        $network->loadPorts($portIds);
        $network->wantStp($portIds);
        $deviceIds = array_values(array_filter(array_column($rows, 'device_id')));
        $network->loadDevices($deviceIds);

        return [
            'devices' => array_filter(array_combine($deviceIds, array_map(fn ($id) => $network->device($id), $deviceIds)) ?: []),
            'ports' => array_filter(array_combine($portIds, array_map(fn ($id) => $network->port($id), $portIds)) ?: []),
            'statistics' => $network->statistics($portIds),
            'stp' => fn (int $deviceId, int $portId, ?int $vlan) => $network->stpStatus($deviceId, $portId, $vlan),
        ];
    }

    /**
     * 850 Mbit/s, 10 Gbit/s.
     */
    public static function speedText(int $bps): string
    {
        return PathReport::speedText($bps);
    }

    /**
     * IPv6 in its short form, IPv4 as is.
     */
    public static function display(string $ip): string
    {
        return str_contains($ip, ':') ? HostQuery::ipv6Short($ip) : $ip;
    }
}
