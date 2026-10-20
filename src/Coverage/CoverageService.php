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
    /** Port columns of the LLDP/CDP ports (names and description), and of the counted access ports (also mode and type). */
    private const NAME_COLUMNS = ['port_id', 'device_id', 'ifIndex', 'ifName', 'ifDescr', 'ifAlias'];

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
        $lagParent = $this->topology->lagParents($scope);

        // MAC addresses are only counted on access ports without a neighbour that explains them, of the
        // centered device alone when there is one
        $threshold = $this->settings->int('coverage_mac_threshold', 0, 1000000);
        $busy = [];
        if ($threshold) {
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
            ($busy ? $this->topology->ports(array_keys($busy), self::PORT_COLUMNS) : []) + $this->topology->ports($portIds, self::NAME_COLUMNS),
            $lagParent,
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
