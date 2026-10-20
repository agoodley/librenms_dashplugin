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
    private const PORT_COLUMNS = ['port_id', 'device_id', 'ifIndex', 'ifName', 'ifDescr', 'ifAlias', 'ifType', 'ifTrunk', 'deleted'];

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
        // streamed from the database each time it is read: here for the ports to count, then twice by the analyzer
        $links = $this->repo->links($scope);
        $devices = $this->topology->devices($scope);
        $lagParent = $this->topology->lagParents($scope);

        // MAC addresses are only counted on access ports without a neighbour that explains them, of the
        // centered device alone when there is one
        $threshold = $this->settings->int('coverage_mac_threshold', 0, 1000000);
        $busy = [];
        if ($threshold && $scope) {
            $candidates = array_diff_key(
                $this->repo->accessPorts($device !== null ? [$device] : $scope),
                CoverageAnalyzer::explainedPorts($links), $lagParent, array_flip($lagParent),
            );
            $busy = $this->repo->busyPorts($candidates, $threshold);
        }

        $analyzer = new CoverageAnalyzer(
            $devices,
            $scope,
            $links,
            fn (array $portIds) => $this->topology->ports($portIds, self::PORT_COLUMNS),
            $lagParent,
            $this->repo->knownDevices($this->repo->addressNames($scope)),
            $busy,
            $busy ? $this->topology->portVlans(array_keys($busy)) : [],
            $this->repo->protocolsModuleOff($devices),
            $this->repo->xdpAutodiscovery(),
            $this->settings,
        );

        $result = $analyzer->analyze($device);
        $result['mac_threshold'] = $threshold;
        $result['filter'] = $p;

        return $result;
    }
}
