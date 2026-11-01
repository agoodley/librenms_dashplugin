<?php

namespace Agoodley\LibrenmsTopology\Capacity;

use Agoodley\LibrenmsTopology\Data\CapacityRepository;
use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Carbon\Carbon;

/**
 * Glue between the port capacity analysis (CapacityAnalyzer) and LibreNMS: the switches of a page's scope with
 * their ports, neighbors, port-channels, the eventlog's port changes, err-disable messages and PoE, and the
 * plugin's record of the ports (PortUsage), which record() keeps with every topology snapshot. The page reads
 * the err-disable messages since the record's last run (the record has the older ones); without the record,
 * those of the last LIVE_HOURS.
 */
class CapacityService
{
    /** Hours of err-disable messages the page reads when the plugin's record does not have the older ones. */
    public const LIVE_HOURS = 24;

    /** Days of err-disable messages the record's first run reads. */
    public const FIRST_SCAN_DAYS = 7;

    /** The record's runs read the messages since the run before, this much earlier (seconds): syslog can arrive late. */
    private const OVERLAP = 900;

    /** Days of syslog that show a switch sends LibreNMS syslog. */
    private const SYSLOG_DAYS = 7;

    public function __construct(
        private readonly CapacityRepository $repo,
        private readonly PluginSettings $settings,
        private readonly ?HistoryRepository $history = null,
    ) {
    }

    /**
     * The port capacity of the switches of the scope (one device: its ports only).
     *
     * @param  array{device: ?int, group: ?int, location: ?int}  $p
     * @param  int|null  $days  days without use that make a port unused (null: the setting)
     */
    public function analyze(array $p, ?int $days = null, ?int $now = null): array
    {
        $now ??= time();
        [$scope, $device] = $this->repo->scope($p);
        $devices = $this->repo->switches($device ? [$device] : $scope);
        $ids = array_keys($devices);
        $ports = $this->repo->ports($ids);

        $record = $this->recordState();
        $since = $record['run'] !== null ? max($now - 86400 * self::FIRST_SCAN_DAYS, $record['run'] - self::OVERLAP) : $now - 3600 * self::LIVE_HOURS;
        $errDisable = new ErrDisable($this->settings);
        $errors = $errDisable->usable() ? $errDisable->latest($this->repo->errDisableMessages($ids, $since), ErrDisable::portIndex($ports)) : [];
        $poe = $this->repo->poePorts($ports, $devices);
        $eventSince = $this->repo->eventSince($devices);

        // the record as it is, also when the history was switched off since: what it showed before still holds
        $result = (new CapacityAnalyzer($devices, $ports, $this->repo->neighbors($ids), $this->repo->lags($ids), $this->repo->records($ids),
            $this->repo->eventUps($ids, 0), $eventSince, $errors, $poe ?? [], $this->settings, $now, $days))->analyze();

        $senders = $ids && $errDisable->usable() ? $this->repo->syslogSenders($ids, $now - 86400 * self::SYSLOG_DAYS) : null;
        $result['sources'] = [
            'eventlog_since' => $eventSince ? min($eventSince) : null,
            'record' => $record['available'],
            'record_since' => $record['available'] ? $this->repo->recordSince($ids) : null,
            'record_run' => $record['run'],
            'errors_since' => $since,
            'syslog_senders' => $senders === null ? null : count($senders),
            'poe' => $poe === null ? 'unreadable' : ($poe ? 'some' : 'none'),
        ];
        $result['notices'] = [...$result['notices'], ...$this->notices($result, $errDisable, $senders, count($devices), $poe)];
        $result['filter'] = $p;

        return $result;
    }

    /**
     * Keep the plugin's record of every switch port (topology_port_usage) up to date: when each was last known up,
     * and the err-disable messages since the run before (the first run: those of the last FIRST_SCAN_DAYS). Run with
     * every topology snapshot, in the system scope; devices disabled in LibreNMS keep their ports' record.
     *
     * @return array<string, int|float> counts for the command output and the run record
     */
    public function record(HistoryRepository $history, ?int $now = null): array
    {
        $now ??= time();
        $started = microtime(true);
        $previous = $history->latestRun('ports');
        $run = $history->startRun('ports', Carbon::createFromTimestamp($now));

        $repo = $this->repo->withDisabled();
        $devices = $repo->switches($repo->deviceIds());
        $ports = $repo->ports(array_keys($devices));
        $since = max($now - 86400 * self::FIRST_SCAN_DAYS, (int) ($previous['stats']['until'] ?? 0) - self::OVERLAP);
        $errDisable = new ErrDisable($this->settings);
        $errors = $errDisable->usable() ? $errDisable->latest($repo->errDisableMessages(array_keys($devices), $since), ErrDisable::portIndex($ports)) : [];
        $update = PortUsage::update($ports, $devices, $repo->records(null), $errors, $now);
        $repo->saveRecords($update['write'], $update['remove'], $now);

        $stats = ['switches' => count($devices), 'ports' => count($ports), 'written' => count($update['write']), 'removed' => count($update['remove']),
            'err_disabled' => count(array_filter($errors, fn (array $error) => $error['time'] !== null)), 'until' => $now,
            'seconds' => round(microtime(true) - $started, 2)];
        $history->finishRun($run, $stats, Carbon::now());

        return $stats;
    }

    /**
     * Whether the plugin keeps its record (the table exists and the history is enabled) and when its last run started.
     *
     * @return array{available: bool, run: ?int}
     */
    private function recordState(): array
    {
        if (! $this->repo->recordAvailable()) {
            return ['available' => false, 'run' => null];
        }
        $run = null;
        try {
            $latest = $this->history?->latestRun('ports');
            $run = $latest !== null ? ((int) ($latest['stats']['until'] ?? 0) ?: CapacityRepository::time($latest['started_at'])) : null;
        } catch (\Throwable) {
            $run = null;
        }

        return ['available' => $this->settings->bool('history_enabled'), 'run' => $run];
    }

    /**
     * What the page should say about its sources: PoE, err-disable messages and the record.
     *
     * @param  array<int, bool>|null  $poe
     * @param  int[]|null  $senders
     * @return string[]
     */
    private function notices(array $result, ErrDisable $errDisable, ?array $senders, int $switches, ?array $poe): array
    {
        $notices = [];
        if ($switches === 0) {
            return ['No switch in this scope: the page counts the Ethernet ports of devices LibreNMS classifies as switches.'];
        }
        if (! $errDisable->usable()) {
            $notices[] = 'The err-disable pattern of the spanning tree settings (stp_syslog_err_disabled) is ' . ($errDisable->invalid()
                ? 'no valid regular expression' : 'empty') . ': err-disabled ports are counted as free.';
        } elseif ($senders !== null && count($senders) < $switches) {
            $silent = $switches - count($senders);
            $notices[] = match (true) {
                $switches === 1 => 'The switch sent',
                $silent === $switches => 'None of the ' . number_format($switches) . ' switches sent',
                default => number_format($silent) . ' of the ' . number_format($switches) . ' switches sent',
            } . ($silent === $switches && $switches > 1 ? '' : ' no') . ' syslog to LibreNMS in the last ' . self::SYSLOG_DAYS . ' days: '
                . ($switches === 1 ? 'its' : 'their') . ' err-disabled ports are counted as free unless an SNMP trap reported them.';
        }
        if ($poe === null) {
            $notices[] = 'LibreNMS\' RRD files are not on this server (rrdcached elsewhere): which ports have PoE is unknown.';
        } elseif (! $poe) {
            $notices[] = (CapacityRepository::config('enable_ports_poe', false) ? 'LibreNMS has no PoE data of these switches' : 'LibreNMS polls no PoE')
                . ': which ports have PoE is unknown. It polls the PoE of ports with enable_ports_poe on (lnms config:set enable_ports_poe true), '
                . 'for Cisco IOS and IOS-XE, Huawei VRP, Brocade IronWare, TP-Link JetStream and Linksys switches.';
        }
        if (! $this->repo->recordAvailable()) {
            $notices[] = 'The plugin\'s record of when ports were last up does not exist yet (./lnms migrate creates it): how long a port has been '
                . 'down comes from LibreNMS alone, which forgets it when a switch restarts.';
        } elseif (! $this->settings->bool('history_enabled')) {
            $notices[] = 'The topology history is off (plugin settings), so the plugin keeps no record of when ports were last up: how long a port '
                . 'has been down comes from LibreNMS alone, which forgets it when a switch restarts.';
        }

        return $notices;
    }
}
