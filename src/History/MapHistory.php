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
 * the facts and events the Changes page reads), and what the map's Time control offers. A time the history cannot
 * answer (switched off, its tables missing, before its earliest snapshot) is refused (TimeRefused) rather than
 * drawn from today's data.
 */
class MapHistory
{
    /** Snapshot times the Time control lists, newest first. */
    public const LISTED_SNAPSHOTS = 48;

    /** Changes listed with a map's marks (ChangeReport events; their counts are complete). */
    public const LISTED_EVENTS = 200;

    /** Quick picks of the Time control: how long ago. */
    private const PICKS = ['1 hour ago' => 3600, 'Yesterday, this time' => 86400, 'Last week, this time' => 7 * 86400];

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

    /** Tables each map is drawn from: a role that may not read them gets an empty map. */
    private const MAP_TABLES = ['l2' => ['links', 'ports', 'stp', 'ports_stp'], 'l3' => ['ipv4_addresses', 'ipv6_addresses', 'ospf_nbrs', 'bgpPeers']];

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
     * what came from the history and what from today, what it leaves out (the L3 overlays the history does not
     * record, and the phones it leaves out), the kinds of facts not recorded yet then.
     *
     * @param  string  $mode  l2 or l3
     * @param  array<string, int>  $layers  L3 overlay => devices of the map with such data today (left out)
     * @param  bool  $phones  the L2 map draws unmanaged neighbors, phones among them today
     */
    public function asOf(PastMap $past, int $time, string $mode, array $layers = [], bool $phones = false): array
    {
        $snapshot = $past->snapshot;
        $kinds = array_keys(self::FROM_HISTORY[$mode]);
        $allowed = array_values(array_filter($kinds, fn ($kind) => $this->service()->factAllowed($kind)));
        $notes = [self::NEUTRAL[$mode]];
        $interval = $this->settings->int('history_interval', 5, 1440) * 60;
        $until = $snapshot['next'] ?? time();
        if ($time - $snapshot['time'] > 2 * $interval && $until - $snapshot['time'] > 2 * $interval) {
            $notes[] = 'No snapshot was taken between ' . self::display($snapshot['time']) . ' and '
                . ($snapshot['next'] !== null ? self::display($snapshot['next']) : 'now') . ': the map shows the earlier one.';
        }
        $skipped = array_values(array_intersect($allowed, $snapshot['skipped']));
        if ($skipped) {
            $notes[] = 'That snapshot could not read the ' . implode(', ', array_map(fn ($kind) => self::FROM_HISTORY[$mode][$kind], $skipped))
                . ': they are as the snapshot before recorded them.';
        }
        $notShown = array_values(array_intersect_key(self::UNRECORDED_LAYERS, array_filter($layers)));
        if ($mode === 'l2' && $phones) {
            $notShown[] = 'phones';
        }

        return [
            'requested' => self::format($time, true),
            'snapshot' => self::format($snapshot['time'], true),
            'next' => $snapshot['next'] !== null ? self::format($snapshot['next'], true) : null,
            'from_history' => array_values(array_map(fn ($kind) => self::FROM_HISTORY[$mode][$kind], $allowed)),
            'from_today' => self::FROM_TODAY[$mode],
            'not_shown' => $notShown,
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
     * its devices: the marks and ghosts (ChangeMarks) and, in changes, the time span, the counts and the events. Since
     * a time, only what the Changes page reports for the same span is marked (its events); since the baseline, what
     * the Changes page's comparison with the baseline lists. The marks follow the map's VLAN and its toggle of
     * unmanaged neighbors.
     *
     * @param  string  $mode  l2 or l3
     * @param  int|string  $since
     * @param  array{vlan?: ?int, unmanaged?: bool}  $p  the map's parameters
     *
     * @throws TimeRefused
     */
    public function changes(string $mode, int|string $since, array $result, array $p = []): array
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
        $options = [
            'vlan' => $mode === 'l2' && isset($p['vlan']) ? (int) $p['vlan'] : null,
            'unmanaged' => (bool) ($p['unmanaged'] ?? true),
            'existing' => $this->history->seesEveryDevice() ? $this->history->existingDevices() : null,
        ];

        if ($since === MapParams::BASELINE) {
            if (($why = $this->unavailable()) !== null) {
                throw new TimeRefused($why);
            }
            if (! $ids) {
                $hidden = $this->history->hiddenKinds(self::MAP_TABLES[$mode]);
                throw new TimeRefused($hidden ? 'Your LibreNMS role may not view ' . self::words($hidden) . ': this map has no devices to compare with the baseline.'
                    : 'This map has no devices to compare with the baseline.');
            }
            $saved = $this->history->baselineInfo($ids);
            if ($saved === null) {
                throw new TimeRefused('No baseline is saved for the devices of this map: an admin saves one on the Changes page.');
            }
            [$before, $after, $goneAt] = $this->baseline($ids, $kinds);
            $result = ChangeMarks::apply($mode, $result, $before, $after, $text, ['gone_at' => $goneAt] + $options);
            $result['changes'] += ['since' => MapParams::BASELINE, 'baseline' => ['saved_at' => $saved['saved_at']], 'from' => $saved['saved_at'],
                'to' => self::format(time(), true), 'events' => [], 'events_total' => null, 'truncated' => false, 'labels' => ChangeMarks::TYPES];

            return self::withWhen($result);
        }

        $this->check((int) $since);
        // the window starts after the time (the state then is the snapshot at or before it), as the Changes page's from
        $from = Carbon::createFromTimestamp((int) $since + 1, date_default_timezone_get());
        $to = Carbon::now();
        $window = $this->service()->window($ids, $from, $to, $text, $kinds, false);
        $start = $window['from']->getTimestamp() - 1;
        $before = [];
        $after = [];
        foreach ($window['rows'] as $row) {
            // a fact of its kind's first snapshot existed before it (ChangeReport does not count it as new either)
            if (PastMap::presentAt($row, $start) || ($row['initial'] && $row['first_seen'] > $start)) {
                $before[] = $row;
            }
            if ($row['removed_at'] === null) {
                $after[] = $row;
            }
        }
        $events = array_values(array_filter($window['report']['events'], fn ($event) => in_array($event['category'], $mode === 'l2' ? ['devices', 'links', 'stp'] : ['devices', 'routing'], true)));
        $result = ChangeMarks::apply($mode, $result, $before, $after, $text, ['events' => $events, 'rows' => $window['rows']] + $options);
        $result['changes'] += [
            'since' => self::format((int) $since, true),
            'baseline' => null,
            // the span of the marks as the Changes page takes it: from the second after the time (or after the cut)
            'from' => $window['from']->toDateTimeString(),
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
                    $item['changes'][$index]['when'] = $mark['time'] !== null ? self::display((int) $mark['time']) : null;
                }
            }

            return $items;
        };
        $result['nodes'] = $when($result['nodes']);
        $result['edges'] = $when($result['edges']);
        $result['changes']['ghosts']['edges'] = $when($result['changes']['ghosts']['edges']);
        foreach ($result['changes']['events'] as $index => $event) {
            $result['changes']['events'][$index]['when'] = self::display((int) $event['time']);
        }

        return $result;
    }

    /**
     * The Time control of a map page (emptyInfo(), controls()): whether the history can draw maps at other times
     * (reason when not), its snapshots, the quick picks and defaults, and when the baseline of these devices was saved.
     *
     * @param  int[]  $ids  devices of the map's scope
     */
    public function info(array $ids, ?int $now = null): array
    {
        $now ??= time();
        $info = self::emptyInfo($this->settings, null, $now);
        if (($why = $this->unavailable()) !== null) {
            return ['reason' => $why] + $info;
        }
        $times = $this->history->snapshotTimes(self::LISTED_SNAPSHOTS);
        if ($times['earliest'] === null) {
            return ['reason' => 'The topology history has no snapshot yet: the first one is taken within the history interval.'] + $info;
        }
        $baseline = $ids ? $this->history->baselineInfo($ids) : null;

        return ['available' => true, 'count' => $times['count'], 'baseline' => $baseline !== null ? self::stamp((int) strtotime($baseline['saved_at'])) : null]
            + self::controls($times['earliest'], (int) $times['latest'], $times['recent'], $now) + $info;
    }

    /**
     * The Time control without maps at other times (the history unavailable: $reason), with every key info() has,
     * so that the page draws the control disabled with the reason.
     */
    public static function emptyInfo(PluginSettings $settings, ?string $reason = null, ?int $now = null): array
    {
        $now ??= time();

        return [
            'available' => false, 'reason' => $reason, 'earliest' => null, 'earliest_minute' => null, 'latest' => null, 'snapshots' => [], 'count' => 0,
            'baseline' => null, 'picks' => [], 'defaults' => ['at' => null, 'since' => null], 'now' => self::format($now),
            'timezone' => date_default_timezone_get(), 'zone' => date('T', $now), 'interval' => $settings->int('history_interval', 5, 1440),
        ];
    }

    /**
     * What the Time control offers for the snapshots the history keeps, as the date input and the URL take them:
     * the earliest snapshot (with its seconds, and its minute for the input: a minute covers the snapshots in it,
     * MapParams::time()), the latest, the recent ones (a pick draws exactly that snapshot), the quick picks (a
     * minute, disabled when it ends before the earliest snapshot) and what As of / Changes since start with: an hour
     * ago and yesterday, else the latest and the earliest snapshot.
     *
     * @param  int[]  $recent  starts of the latest snapshots, newest first
     * @return array{earliest: string, earliest_minute: string, latest: string, snapshots: string[],
     *     picks: array<int, array{label: string, value: string, early: bool}>, defaults: array{at: string, since: string}}
     */
    public static function controls(int $earliest, int $latest, array $recent, int $now): array
    {
        $picks = [];
        foreach (self::PICKS as $label => $ago) {
            $minute = intdiv($now - $ago, 60) * 60;
            $picks[] = ['label' => $label, 'value' => self::format($minute), 'early' => $minute + 59 < $earliest];
        }

        return [
            'earliest' => self::stamp($earliest),
            'earliest_minute' => self::format($earliest),
            'latest' => self::stamp($latest),
            'snapshots' => array_map(fn ($time) => self::stamp((int) $time), $recent),
            'picks' => $picks,
            'defaults' => ['at' => $picks[0]['early'] ? self::stamp($latest) : $picks[0]['value'], 'since' => $picks[1]['early'] ? self::stamp($earliest) : $picks[1]['value']],
        ];
    }

    /**
     * Date and time for the Time control's input (2026-10-21T09:00, what a datetime-local input holds), with seconds
     * as the history stores them (2026-10-21 09:00:03).
     */
    public static function format(int $time, bool $seconds = false): string
    {
        return date($seconds ? 'Y-m-d H:i:s' : 'Y-m-d\TH:i', $time);
    }

    /**
     * A snapshot's time as the URL and the Time control's picks carry it, to the second (2026-10-21T09:00:03).
     */
    public static function stamp(int $time): string
    {
        return date('Y-m-d\TH:i:s', $time);
    }

    /**
     * A time as the page writes it (2026-10-21 09:00).
     */
    public static function display(int $time): string
    {
        return date('Y-m-d H:i', $time);
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
            . ': the earliest map is as of ' . date('Y-m-d H:i:s', $earliest) . '.';
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

    /**
     * "a, b and c".
     *
     * @param  string[]  $words
     */
    private static function words(array $words): string
    {
        $last = array_pop($words);

        return $words ? implode(', ', $words) . ' and ' . $last : (string) $last;
    }

    private function service(): HistoryService
    {
        return $this->service ??= new HistoryService($this->history, $this->history->sibling(TopologyRepository::class), $this->settings);
    }
}
