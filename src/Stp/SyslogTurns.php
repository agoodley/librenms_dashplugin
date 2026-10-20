<?php

namespace Agoodley\LibrenmsTopology\Stp;

/**
 * Reads the syslog of many devices within a budget, newest messages first. The devices take turns,
 * the most recently active first: each turn reads a fair part of what is left and keeps at most a
 * share of the matches, so a busy or looping device cannot use up what the others get, and one read
 * covers the turns of many devices. The messages come from a callable (LoopEvidenceRepository reads
 * them from the database), so the turns can be tested without one.
 */
class SyslogTurns
{
    /** Messages searched at most per page view. */
    public const SCAN = 100000;

    /** Matching messages kept at most. */
    public const MATCHES = 5000;

    /** Reads (database queries) at most. */
    public const READS = 200;

    /** Messages, and devices, one read covers at most. */
    private const READ_ROWS = 5000;

    private const READ_DEVICES = 200;

    /** A turn reads at least this many messages, and may keep at least this many matches. */
    private const MIN_PAGE = 10;

    private const MIN_SHARE = 50;

    public function __construct(
        private int $scan = self::SCAN,
        private int $matches = self::MATCHES,
        private int $reads = self::READS,
    ) {
    }

    /**
     * @param  int[]  $senders  the devices that sent syslog in the window, the most recently active first
     * @param  callable(array<int, array{after: array{0: string, 1: int}|null, limit: int}>): array<int, object[]>  $read
     *     the next messages of these devices: device_id => the last message read before (timestamp and seq, null for the
     *     newest) and how many => device_id => messages (seq, device_id, timestamp, program, msg), newest first
     * @param  callable(string): ?string  $classify  check code of a message (program and text), null when it is no evidence
     * @return array{rows: array<int, array>, scanned: int, reads: int, truncated: bool, limited: int, unsearched: int}
     *     the matching messages with their code; truncated: matching messages were left out; limited: devices whose older
     *     messages in the window were not searched; unsearched: devices that were not searched at all
     */
    public function read(array $senders, callable $read, callable $classify): array
    {
        $result = ['rows' => [], 'scanned' => 0, 'reads' => 0, 'truncated' => false, 'limited' => 0, 'unsearched' => 0];
        $queue = array_fill_keys($senders, ['after' => null, 'size' => null]);
        $searched = [];
        while ($queue && $result['scanned'] < $this->scan && count($result['rows']) < $this->matches && $result['reads'] < $this->reads) {
            // a fair part of what is left for each device still in the queue
            $page = max(self::MIN_PAGE, min(self::READ_ROWS, intdiv($this->scan - $result['scanned'], count($queue))));
            $share = max(self::MIN_SHARE, intdiv($this->matches - count($result['rows']), count($queue)));
            $turns = [];
            $rows = 0;
            $room = min(self::READ_ROWS, $this->scan - $result['scanned']);
            foreach ($queue as $deviceId => $state) {
                $limit = min($page, $state['size'] ?? $page);
                if ($turns && ($rows + $limit > $room || count($turns) >= self::READ_DEVICES)) {
                    break;
                }
                $turns[$deviceId] = ['after' => $state['after'], 'limit' => $limit + 1]; // one more tells whether more are left
                $rows += $limit;
                unset($queue[$deviceId]);
            }

            $pages = $read($turns);
            $result['reads']++;
            foreach ($turns as $deviceId => $turn) {
                $searched[$deviceId] = true;
                $messages = $pages[$deviceId] ?? [];
                $limit = $turn['limit'] - 1;
                $more = count($messages) > $limit;
                $messages = array_slice($messages, 0, $limit);
                $kept = 0;
                $done = 0;
                foreach ($messages as $message) {
                    $last = $message;
                    $done++;
                    $code = $classify(trim($message->program . ' ' . $message->msg));
                    if ($code !== null) {
                        $result['rows'][] = ['code' => $code] + (array) $message;
                        if (++$kept >= $share) {
                            $more = $more || $done < count($messages);
                            break;
                        }
                    }
                }
                $result['scanned'] += $done;
                if ($more) {
                    // another turn after the last message read; when the matches cut this one short, the next reads as many
                    $queue[$deviceId] = ['after' => [(string) $last->timestamp, (int) $last->seq], 'size' => $kept >= $share ? $done : null];
                }
            }
        }
        $result['unsearched'] = count(array_diff_key($queue, $searched));
        $result['limited'] = count($queue) - $result['unsearched'];
        $result['truncated'] = $queue && count($result['rows']) >= $this->matches;

        return $result;
    }
}
