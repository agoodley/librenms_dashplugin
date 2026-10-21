<?php

namespace Agoodley\LibrenmsTopology\History;

use Agoodley\LibrenmsTopology\Data\HistoryRepository;
use Agoodley\LibrenmsTopology\Data\TopologyRepository;
use Agoodley\LibrenmsTopology\Support\MapParams;
use Agoodley\LibrenmsTopology\Support\PluginSettings;
use Carbon\Carbon;

/**
 * The L2 and L3 maps at another time, from the topology history: a map as of a time (the last snapshot at or
 * before it, PastMap), today's map with what changed since a time or since the saved baseline (ChangeMarks, from
 * the facts the Changes page reads), and what the map's Time control offers. A time the history cannot answer
 * (switched off, its tables missing, before its earliest snapshot) is refused (TimeRefused) rather than drawn
 * from today's data.
 */
class MapHistory
{
    /** Snapshot times the Time control lists, newest first. */
    public const LISTED_SNAPSHOTS = 48;

    /** Changes listed with a map's marks (ChangeReport events; their counts are complete). */
    public const LISTED_EVENTS = 200;

    /** What a past map takes from the history, and what from today, by kind of fact (the banner's words). */
    private const FROM_HISTORY = [
        'l2' => [FactCollector::LINK => 'links', FactCollector::LAG => 'port-channel members', FactCollector::STP_ROOT => 'root bridges',
            FactCollector::STP_BLOCKED => 'blocked ports'],
        'l3' => [FactCollector::OSPF => 'OSPF adjacencies', FactCollector::BGP => 'BGP sessions'],
    ];

    private const FROM_TODAY = [
        'l2' => ['ports with their speeds and VLANs', 'device details'],
        'l3' => ['subnets and addresses', 'device details'],
    ];

    /** How a past map draws what has no state in the history. */
    private const NEUTRAL = [
        'l2' => 'Links are drawn without a status (the history keeps none), devices as they were (an outage then).',
        'l3' => 'Adjacencies, sessions and subnets are drawn without a status (the history keeps none), devices as they were.',
    ];

    /** L3 overlays the history does not record, as the banner names them. */
    public const UNRECORDED_LAYERS = ['ospfv3' => 'OSPFv3 adjacencies', 'isis' => 'IS-IS adjacencies', 'ipsec' => 'IPsec tunnels',
        'mpls' => 'MPLS LSPs', 'pw' => 'pseudowires'];

    private ?HistoryService $service = null;

    public function __construct(private readonly HistoryRepository $history, private readonly PluginSettings $settings)
    {
    }

    /**
     * Why there is no map at another time now: the history's tables are missing or it is switched off; null when
     * there can be.
     */
    public function unavailable(): ?string
    {
        if (! $this->history->available()) {
            return 'The topology history tables do not exist yet: run ./lnms migrate in the LibreNMS directory.';
        }
        if (! $this->settings->bool('history_enabled')) {
            return 'The topology history is switched off (plugin settings, History): there are no maps of the past.';
        }

        return null;
    }

    /**
     * The map as of $time: the last snapshot at or before it, for the devices the user may see and the kinds of
     * facts their role may read.
     *
     * @throws TimeRefused
     */
    public function past(int $time): PastMap
    {
        $this->check($time);
        $snapshot = $this->history->snapshotAt($time);
        if ($snapshot === null) {
            throw new TimeRefused($this->tooEarly($this->history->snapshotTimes(0)['earliest']));
        }
        $visible = array_fill_keys($this->history->deviceIds(), true);
        $kinds = array_values(array_filter(FactCollector::KINDS, fn ($kind) => $this->service()->factAllowed($kind)));

        return new PastMap($this->history, $snapshot, $visible, $kinds, $this->history->seesEveryDevice() ? $this->history->existingDevices() : null);
    }

    /**
     * What a map as of $time shows (its response's as_of): the time asked for, the snapshot drawn and the next one,
     * what came from the history and what from today, the overlays left out, the kinds of facts not recorded yet then.
     *
     * @param  string  $mode  l2 or l3
     * @param  array<string, int>  $layers  L3 overlay => devices of the map with such data today (left out)
     */
    public function asOf(PastMap $past, int $time, string $mode, array $layers = []): array
    {
        $snapshot = $past->snapshot;
        $kinds = array_keys(self::FROM_HISTORY[$mode]);
        $allowed = array_values(array_filter($kinds, fn ($kind) => $this->service()->factAllowed($kind)));
        $notes = [self::NEUTRAL[$mode]];
        $interval = $this->settings->int('history_interval', 5, 1440) * 60;
        $until = $snapshot['next'] ?? time();
        if ($time - $snapshot['time'] > 2 * $interval && $until - $snapshot['time'] > 2 * $interval) {
            $notes[] = 'No snapshot was taken between ' . self::format($snapshot['time']) . ' and ' . ($snapshot['next'] !== null ? self::format($snapshot['next']) : 'now')
                . ': the map shows the earlier one.';
        }
        $skipped = array_values(array_intersect($allowed, $snapshot['skipped']));
        if ($skipped) {
            $notes[] = 'That snapshot could not read the ' . implode(', ', array_map(fn ($kind) => self::FROM_HISTORY[$mode][$kind], $skipped))
                . ': they are as the snapshot before recorded them.';
        }

        return [
            'requested' => self::format($time, true),
            'snapshot' => self::format($snapshot['time'], true),
            'next' => $snapshot['next'] !== null ? self::format($snapshot['next'], true) : null,
            'from_history' => array_values(array_map(fn ($kind) => self::FROM_HISTORY[$mode][$kind], $allowed)),
            'from_today' => self::FROM_TODAY[$mode],
            'not_shown' => array_values(array_intersect_key(self::UNRECORDED_LAYERS, array_filter($layers))),
            'not_recorded' => array_values(array_map(fn ($kind) => self::FROM_HISTORY[$mode][$kind], array_diff($allowed, $snapshot['recorded']))),
            'notes' => $notes,
        ];
    }

    /**
     * What the devices of a map were at $time (HistoryRepository::deviceStatesAt()).
     *
     * @param  int[]  $ids
     * @return array<int, string>
     */
    public function deviceStates(array $ids, int $time): array
    {
        return $this->history->deviceStatesAt($ids, $time);
    }

    /**
     * Today's map with what changed since $since (a timestamp) or since the saved baseline (MapParams::BASELINE), for
     * its devices: the marks and ghosts (ChangeMarks) and, in changes, the time span, the counts and the events.
     *
     * @param  string  $mode  l2 or l3
     * @param  int|string  $since
     *
     * @throws TimeRefused
     */
    public function changes(string $mode, int|string $since, array $result): array
    {
        $ids = [];
        foreach ($result['nodes'] as $node) {
            if (($node['type'] ?? '') === 'device' && isset($node['device_id']) && empty($node['outside'])) {
                $ids[] = (int) $node['device_id'];
            }
        }
        $visible = array_fill_keys($this->history->deviceIds(), true);
        $text = new FactText($this->history->names(array_keys($visible)), $visible);
        $kinds = array_keys(self::FROM_HISTORY[$mode]);

        if ($since === MapParams::BASELINE) {
            if (($why = $this->unavailable()) !== null) {
                throw new TimeRefused($why);
            }
            $saved = $ids ? $this->history->baselineInfo($ids) : null;
            if ($saved === null) {
                throw new TimeRefused('No baseline is saved for the devices of this map: an admin saves one on the Changes page.');
            }
            [$before, $after, $goneAt] = $this->baseline($ids, $kinds);
            $result = ChangeMarks::apply($mode, $result, $before, $after, $text, [], $goneAt);
            $result['changes'] += ['since' => MapParams::BASELINE, 'baseline' => ['saved_at' => $saved['saved_at']], 'from' => $saved['saved_at'],
                'to' => self::format(time(), true), 'events' => [], 'events_total' => null, 'truncated' => false, 'labels' => ChangeMarks::TYPES];

            return self::withWhen($result);
        }

        $this->check((int) $since);
        $from = Carbon::createFromTimestamp((int) $since + 1, date_default_timezone_get());
        $to = Carbon::now();
        $window = $this->service()->window($ids, $from, $to, $text, $kinds);
        $rows = $window['rows'];
        $start = $window['from']->getTimestamp() - 1;
        $before = [];
        $after = [];
        foreach ($rows as $row) {
            // facts of a kind's first snapshot existed before: no change (ChangeReport leaves them out too)
            $initial = $row['initial'] && $row['first_seen'] > $start;
            if (PastMap::presentAt($row, $start) || $initial) {
                $before[] = $row;
            }
            if ($row['removed_at'] === null) {
                $after[] = $row;
            }
        }
        $events = array_values(array_filter($window['report']['events'], fn ($event) => in_array($event['category'], $mode === 'l2' ? ['devices', 'links', 'stp'] : ['devices', 'routing'], true)));
        $result = ChangeMarks::apply($mode, $result, $before, $after, $text, $events);
        $result['changes'] += [
            'since' => self::format((int) $since, true),
            'baseline' => null,
            'from' => $window['since'] !== null ? $window['since']->toDateTimeString() : self::format((int) $since, true),
            'to' => $to->toDateTimeString(),
            'events' => array_map(fn ($event) => array_diff_key($event, ['facts' => true]), array_slice($events, 0, self::LISTED_EVENTS)),
            'events_total' => count($events),
            'truncated' => $window['truncated'],
            'labels' => ChangeMarks::TYPES,
        ];

        return self::withWhen($result);
    }

    /**
     * The marks of a map with changes and its events, with their time as the server tells it (when): the page shows
     * the server's times.
     */
    private static function withWhen(array $result): array
    {
        $when = function (array $items): array {
            foreach ($items as &$item) {
                foreach ($item['changes'] ?? [] as $index => $mark) {
                    $item['changes'][$index]['when'] = $mark['time'] !== null ? date('Y-m-d H:i', (int) $mark['time']) : null;
                }
            }

            return $items;
        };
        $result['nodes'] = $when($result['nodes']);
        $result['edges'] = $when($result['edges']);
        $result['changes']['ghosts']['edges'] = $when($result['changes']['ghosts']['edges']);
        foreach ($result['changes']['events'] as $index => $event) {
            $result['changes']['events'][$index]['when'] = date('Y-m-d H:i', (int) $event['time']);
        }

        return $result;
    }

    /**
     * The Time control of a map page: whether the history can draw maps at other times (reason when not), the
     * earliest and latest snapshots (an earlier time is refused), the latest ones to pick from, when the baseline of
     * these devices was saved, and the server's time and time zone (the times are its).
     *
     * @param  int[]  $ids  devices of the map's scope
     */
    public function info(array $ids): array
    {
        $info = ['available' => false, 'reason' => null, 'earliest' => null, 'latest' => null, 'snapshots' => [], 'count' => 0, 'baseline' => null,
            'now' => self::format(time()), 'timezone' => date_default_timezone_get(), 'interval' => $this->settings->int('history_interval', 5, 1440)];
        if (($why = $this->unavailable()) !== null) {
            return ['reason' => $why] + $info;
        }
        $times = $this->history->snapshotTimes(self::LISTED_SNAPSHOTS);
        if ($times['earliest'] === null) {
            return ['reason' => 'The topology history has no snapshot yet: the first one is taken within the history interval.'] + $info;
        }
        $baseline = $ids ? $this->history->baselineInfo($ids) : null;

        return [
            'available' => true,
            'earliest' => self::format($times['earliest']),
            'latest' => self::format((int) $times['latest']),
            'snapshots' => array_map(fn ($time) => self::format($time), $times['recent']),
            'count' => $times['count'],
            'baseline' => $baseline !== null ? self::format((int) strtotime($baseline['saved_at'])) : null,
        ] + $info;
    }

    /**
     * Date and time for the Time control (2026-10-21T09:00, what a datetime-local input holds), with seconds as the
     * history stores them.
     */
    public static function format(int $time, bool $seconds = false): string
    {
        return date($seconds ? 'Y-m-d H:i:s' : 'Y-m-d\TH:i', $time);
    }

    /**
     * The history can answer a time from its earliest snapshot on.
     *
     * @throws TimeRefused
     */
    private function check(int $time): void
    {
        if (($why = $this->unavailable()) !== null) {
            throw new TimeRefused($why);
        }
        $earliest = $this->history->snapshotTimes(0)['earliest'];
        if ($earliest === null || $time < $earliest) {
            throw new TimeRefused($this->tooEarly($earliest));
        }
    }

    /**
     * Why a time before the earliest snapshot has no map: the history started later, or the retention dropped it.
     */
    private function tooEarly(?int $earliest): string
    {
        if ($earliest === null) {
            return 'The topology history has no snapshot yet: the first one is taken within the history interval.';
        }
        $days = $this->settings->int('history_retention_days', 1, 3650);
        $pruned = $earliest <= time() - $days * 86400 + 2 * 60 * $this->settings->int('history_interval', 5, 1440);

        return ($pruned ? "The history keeps $days days" : 'The history starts with its first snapshot')
            . ': the earliest map is as of ' . date('Y-m-d H:i', $earliest) . '.';
    }

    /**
     * Facts of the baseline against those of the last snapshot, of the kinds of the map that the user's role may
     * read: missing (before, with when each went), new (after), and the root bridges of both in full (they compare
     * per switch and VLAN). At most HistoryRepository::WINDOW_LIMIT of each.
     *
     * @param  int[]  $ids
     * @param  string[]  $kinds
     * @return array{0: array<int, array>, 1: array<int, array>, 2: array<string, int>}
     */
    private function baseline(array $ids, array $kinds): array
    {
        $kinds = array_values(array_filter(array_intersect($kinds, Baseline::KINDS), fn ($kind) => $this->service()->factAllowed($kind)));
        $byHash = array_values(array_diff($kinds, [FactCollector::STP_ROOT]));
        $saved = array_filter($this->history->baselineHashes($ids), fn ($kind) => in_array($kind, $byHash, true));
        $current = $byHash ? $this->history->currentHashes($ids, $byHash) : [];
        $missing = array_keys(array_diff_key($saved, $current));
        $baseline = fn (array $fact) => $fact + ['initial' => false, 'first_seen' => null, 'removed_at' => null];
        $before = array_map($baseline, $this->history->baselineFactsByHash($missing, HistoryRepository::WINDOW_LIMIT));
        $after = $this->history->currentFactsByHash(array_keys(array_diff_key($current, $saved)), HistoryRepository::WINDOW_LIMIT);
        if (in_array(FactCollector::STP_ROOT, $kinds, true)) {
            $before = array_merge($before, array_values(array_map($baseline, $this->history->baselineFactsOfKind($ids, FactCollector::STP_ROOT))));
            $after = array_merge($after, array_values($this->history->currentFactsOfKind($ids, FactCollector::STP_ROOT)));
        }
        $goneAt = array_map(fn ($date) => (int) strtotime($date), $this->history->goneSince(array_slice($missing, 0, HistoryRepository::WINDOW_LIMIT)));

        return [$before, $after, $goneAt];
    }

    private function service(): HistoryService
    {
        return $this->service ??= new HistoryService($this->history, $this->history->sibling(TopologyRepository::class), $this->settings);
    }
}
