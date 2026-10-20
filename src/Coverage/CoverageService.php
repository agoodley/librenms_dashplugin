<?php

namespace Agoodley\LibrenmsTopology\Coverage;

use Agoodley\LibrenmsTopology\Data\CoverageRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * Builds the coverage report of the logged in user: loads what CoverageAnalyzer needs, limited to
 * the devices the user may see.
 */
class CoverageService
{
    /** Port columns the report needs (names, description, mode and type). */
    private const PORT_COLUMNS = ['port_id', 'device_id', 'ifIndex', 'ifName', 'ifDescr', 'ifAlias', 'ifType', 'ifTrunk', 'ifOperStatus', 'deleted'];

    public function __construct(
        private CoverageRepository $repo,
        private TopologyRepository $topology,
        private PluginSettings $settings,
    ) {
    }

    /**
     * Coverage of the devices selected by the page parameters (group, location); with a device, only
     * what involves it.
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p  see MapParams::parse()
     */
    public function report(array $p): array
    {
        [$scope, $device] = $this->repo->scope($p);
        $links = $this->repo->links($scope);

        // the analyzed devices, the devices that see them and the monitored neighbours they report
        $ids = $scope;
        $portIds = [];
        $addresses = [];
        foreach ($links as $link) {
            $ids[] = (int) $link['local_device_id'];
            if ($link['remote_device_id'] && $this->repo->canSee((int) $link['remote_device_id'])) {
                $ids[] = (int) $link['remote_device_id'];
            }
            $portIds[] = (int) $link['local_port_id'];
            $portIds[] = (int) $link['remote_port_id'];
            $host = trim((string) $link['remote_hostname']);
            if (! $link['remote_device_id'] && filter_var($host, FILTER_VALIDATE_IP)) {
                $addresses[] = $host;
            }
        }
        $devices = $this->topology->devices(array_values(array_unique($ids)));

        $threshold = $this->settings->int('coverage_mac_threshold', 0, 1000000);
        $busy = $threshold ? $this->repo->busyPorts($scope, $threshold) : [];

        $analyzer = new CoverageAnalyzer(
            $devices,
            $scope,
            $links,
            $this->topology->ports(array_merge($portIds, array_keys($busy)), self::PORT_COLUMNS),
            $this->topology->lagParents($scope),
            $this->repo->knownDevices($addresses),
            $busy,
            $busy ? $this->topology->portVlans(array_keys($busy)) : [],
            $this->repo->protocolsModuleOff(array_intersect_key($devices, array_flip($scope))),
            $this->repo->xdpAutodiscovery(),
            $this->settings,
        );

        $result = $analyzer->analyze($device);
        $result['mac_threshold'] = $threshold;
        $result['filter'] = $p;

        return $result;
    }
}
