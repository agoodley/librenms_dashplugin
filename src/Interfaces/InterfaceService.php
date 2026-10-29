<?php

namespace Agoodley\LibrenmsTopology\Interfaces;

use Agoodley\LibrenmsTopology\Data\InterfaceRepository;
use Agoodley\LibrenmsTopology\Data\RrdReader;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Agoodley\LibrenmsTopology\Support\Text;
use Illuminate\Support\Facades\Log;

/**
 * Glue between the interface health checks (InterfaceAnalyzer) and LibreNMS: the ports, their
 * changes from the eventlog and the reboots of the devices of a page's scope, and the error
 * totals of the window from LibreNMS' RRD files (RrdReader), else from its last poll. Only ports
 * whose error counters are not zero are read from the RRD files: the others had no errors since
 * their device started counting. The devices are analyzed CHUNK at a time (a port's checks need
 * its own device's changes only), so a large network's week of changes is never held at once.
 */
class InterfaceService
{
    /** Devices analyzed at once (a busy office's 50 switches log some 100,000 port changes a week: 60 MB). */
    private const CHUNK = 50;

    /** The RRD reader once asked for (false: none, the last poll's counters instead). */
    private RrdReader|false|null $reader = null;

    /** Why the error counts are the last poll's, once known. */
    private ?string $pollReason = null;

    /**
     * @param  (\Closure(): ?RrdReader)|null  $rrd  the RRD reader (default: LibreNMS' settings, RrdReader::fromLibrenms())
     */
    public function __construct(
        private readonly InterfaceRepository $repo,
        private readonly PluginSettings $settings,
        private readonly ?\Closure $rrd = null,
    ) {
    }

    /**
     * The interface health of the devices of the scope (one device: its ports only).
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     */
    public function analyze(array $p): array
    {
        [$scope, $device] = $this->repo->scope($p);
        $ids = $device ? [$device] : $scope;
        $now = time();
        $since = $now - InterfaceAnalyzer::eventSeconds($this->settings);
        $seconds = 3600 * InterfaceAnalyzer::windowHours($this->settings);

        $findings = [];
        $rows = [];
        $names = [];
        $ports = 0;
        $capped = false;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $events = $this->repo->events($chunk, $since);
            $capped = $capped || $this->repo->eventsCapped();
            $checked = $this->repo->ports($chunk, array_column($events, 'port_id'));
            // the changes of the ports that are checked (not ignored, deleted or of another type)
            $events = array_values(array_filter($events, fn (array $event) => isset($checked[$event['port_id']])));
            $devices = $this->repo->devices($chunk);
            $result = (new InterfaceAnalyzer($devices, $checked, $events, $this->repo->reboots($chunk, $since), $this->repo->neighbors($chunk),
                $this->counters($checked, $devices, $seconds), $this->reader instanceof RrdReader ? 'rrd' : 'poll', $this->settings, $now))->analyze();
            unset($events, $checked);
            $findings = [...$findings, ...$result['findings']];
            $rows = [...$rows, ...$result['ports']];
            $names += $result['device_names'];
            $ports += $this->repo->portCount($chunk);
        }
        $findings = InterfaceAnalyzer::sortFindings($findings);
        $rows = InterfaceAnalyzer::sortRows($rows);
        $source = $this->reader instanceof RrdReader || ($this->reader === null && $this->usesRrd()) ? 'rrd' : 'poll';

        $notices = array_filter([
            $capped ? 'More than ' . number_format(InterfaceRepository::EVENT_LIMIT) . ' port changes of some devices in the eventlog: '
                . 'their older ones were left out.' : null,
            $source === 'poll' ? 'The error counts are those of the last poll, not of the last ' . Text::count(intdiv($seconds, 3600), 'hour')
                . ': ' . $this->pollReason . '.' : null,
        ]);

        return [
            'device_names' => $names,
            'summary' => InterfaceAnalyzer::summarize($rows, $findings) + ['ports' => $ports],
            'findings' => $findings,
            'ports' => $rows,
            'window' => ['hours' => intdiv($seconds, 3600), 'downgrade_days' => $this->settings->int('interfaces_downgrade_days', 0, 90), 'counters' => $source],
            'notice' => $notices ? implode(' ', $notices) : null,
            'filter' => $p,
        ];
    }

    /**
     * The error totals of the window from the RRD files, else those of the last poll (once rrdtool cannot read them,
     * for the rest of the analysis).
     *
     * @return array<int, array>
     */
    private function counters(array $ports, array $devices, int $seconds): array
    {
        $errored = array_filter($ports, fn (array $port) => (int) ($port['ifInErrors'] ?? 0) > 0 || (int) ($port['ifOutErrors'] ?? 0) > 0
            || (int) ($port['ifInErrors_delta'] ?? 0) > 0 || (int) ($port['ifOutErrors_delta'] ?? 0) > 0);
        if ($errored && $this->usesRrd()) {
            $hostnames = [];
            foreach ($errored as $portId => $port) {
                $hostnames[$portId] = (string) ($devices[(int) $port['device_id']]['hostname'] ?? '');
            }
            try {
                return $this->reader->totals(array_filter($hostnames, fn ($hostname) => $hostname !== ''), $seconds);
            } catch (\Throwable $e) {
                if (class_exists(Log::class)) {
                    Log::warning('topology plugin: cannot read the error totals from the RRD files: ' . $e->getMessage());
                }
                $this->reader = false;
                $this->pollReason = 'the RRD files cannot be read (' . $e->getMessage() . ')';
            }
        }
        if ($this->reader instanceof RrdReader) {
            return [];
        }

        $counters = [];
        foreach ($errored as $portId => $port) {
            $counters[$portId] = ['in_errors' => (int) ($port['ifInErrors_delta'] ?? 0), 'out_errors' => (int) ($port['ifOutErrors_delta'] ?? 0),
                'in_packets' => (int) ($port['ifInUcastPkts_delta'] ?? 0), 'out_packets' => (int) ($port['ifOutUcastPkts_delta'] ?? 0),
                'fcs' => null, 'late_collisions' => null, 'seconds' => (int) ($port['poll_period'] ?? 0)];
        }

        return $counters;
    }

    /**
     * Are the error totals read from the RRD files (the reader asked for once)?
     */
    private function usesRrd(): bool
    {
        if ($this->reader === null) {
            try {
                $this->reader = ($this->rrd !== null ? ($this->rrd)() : RrdReader::fromLibrenms()) ?? false;
            } catch (\Throwable $e) {
                $this->reader = false;
                $this->pollReason = 'the RRD files cannot be read (' . $e->getMessage() . ')';
            }
            $this->pollReason ??= $this->reader === false ? 'LibreNMS writes no RRD files, or they cannot be read here' : null;
        }

        return $this->reader instanceof RrdReader;
    }
}
