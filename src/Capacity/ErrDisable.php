<?php

namespace Agoodley\LibrenmsTopology\Capacity;

use Agoodley\LibrenmsTopology\Stp\SyslogPatterns;
use Agoodley\LibrenmsTopology\Support\InterfaceName;
use Agoodley\LibrenmsTopology\Support\PluginSettings;

/**
 * Err-disabled ports from what the switches report when they shut a port down: syslog messages
 * (Cisco %PM-4-ERR_DISABLE, NX-OS IF_DOWN_ERROR_DISABLED, Arista ERRDISABLE ...) and the eventlog
 * entries of LibreNMS' handler of Cisco's err-disable SNMP trap. IF-MIB shows such a port down like an
 * unplugged one, and LibreNMS does not poll CISCO-ERR-DISABLE-MIB. A message counts when it matches
 * the err-disable pattern of the spanning tree settings (stp_syslog_err_disabled) and not the messages
 * to ignore (stp_syslog_ignore: configuration commands and recoveries); the port is the one of the
 * sending device it names. One that also names a recovery (Cisco %PM-4-ERR_RECOVER, NX-OS
 * IF_ERRDIS_RECOVERY: errdisable recovery re-enabled the port) ends it: a port the switch err-disables
 * again logs a new message. Whether the port is still err-disabled is the analyzer's call: not after a
 * recovery, after it was up again, nor after the switch restarted. Pure PHP.
 */
class ErrDisable
{
    /** SQL LIKE filter that every message the default pattern matches passes (err-disable, error-disabled, disabled due to). */
    public const LIKE = '%disable%';

    /** Longest message kept for a port. */
    public const MESSAGE_LENGTH = 255;

    /** What a message reports: a port the switch shut down, or one it re-enabled (errdisable recovery). */
    public const DISABLED = 'disabled';

    public const RECOVERED = 'recovered';

    /** An err-disable message that names a recovery. */
    private const RECOVERY = '~recover~i';

    /** The err-disable pattern, null when it is empty or no valid regular expression. */
    private ?string $pattern;

    private ?string $ignore;

    /** Is the err-disable pattern set but no valid regular expression? */
    private bool $invalid;

    public function __construct(PluginSettings $settings)
    {
        $values = $settings->all();
        $source = trim((string) ($values['stp_syslog_err_disabled'] ?? ''));
        $this->pattern = $source === '' ? null : SyslogPatterns::compile($source);
        $this->invalid = $source !== '' && $this->pattern === null;
        $ignore = trim((string) ($values[SyslogPatterns::IGNORE] ?? ''));
        $this->ignore = $ignore === '' ? null : SyslogPatterns::compile($ignore);
    }

    /**
     * Can err-disable messages be recognized (the pattern is set and valid)?
     */
    public function usable(): bool
    {
        return $this->pattern !== null;
    }

    /**
     * Is the err-disable pattern set but no valid regular expression (a notice for the page)?
     */
    public function invalid(): bool
    {
        return $this->invalid;
    }

    /**
     * What the message (program and text) reports: DISABLED for a port shut down by the switch, RECOVERED for one
     * errdisable recovery re-enabled, null for anything else. A pattern that fails on it (backtracking limit)
     * counts as no match.
     */
    public function classify(string $text): ?string
    {
        if ($this->pattern === null || @preg_match($this->pattern, $text) !== 1) {
            return null;
        }
        if (preg_match(self::RECOVERY, $text) === 1) {
            return self::RECOVERED;
        }

        return $this->ignore === null || @preg_match($this->ignore, $text) !== 1 ? self::DISABLED : null;
    }

    /**
     * Does the message report a port shut down by the switch?
     */
    public function matches(string $text): bool
    {
        return $this->classify($text) === self::DISABLED;
    }

    /**
     * The latest err-disable message of each port and its latest recovery: the messages that match, assigned to
     * the port of their device they name (the first such name; a sub-interface counts for its port).
     *
     * @param  iterable<array{device_id: int, time: int, text: string}>  $messages
     * @param  array<int, array<string, int>>  $index  device_id => normalized port name => port_id (portIndex())
     * @return array<int, array{time: ?int, message: ?string, recovered: ?int}> port_id => its latest err-disable
     *     (time and message, null without one) and the time of its latest recovery (null without one)
     */
    public function latest(iterable $messages, array $index): array
    {
        $latest = [];
        foreach ($messages as $message) {
            $text = (string) ($message['text'] ?? '');
            $ports = $index[(int) ($message['device_id'] ?? 0)] ?? [];
            $kind = $ports ? $this->classify($text) : null;
            if ($kind === null) {
                continue;
            }
            foreach (SyslogPatterns::portCandidates($text) as $candidate) {
                $name = InterfaceName::normalize($candidate);
                $portId = $ports[$name] ?? $ports[InterfaceName::parentName($name) ?? $name] ?? null;
                if ($portId === null) {
                    continue;
                }
                $time = (int) $message['time'];
                $latest[$portId] ??= ['time' => null, 'message' => null, 'recovered' => null];
                if ($kind === self::RECOVERED) {
                    $latest[$portId]['recovered'] = max($latest[$portId]['recovered'] ?? $time, $time);
                } elseif ($latest[$portId]['time'] === null || $time >= $latest[$portId]['time']) {
                    $latest[$portId]['time'] = $time;
                    $latest[$portId]['message'] = mb_substr(trim($text), 0, self::MESSAGE_LENGTH);
                }
                break;
            }
        }

        return $latest;
    }

    /**
     * The err-disable that still holds of a port from its latest messages (latest()) and the one recorded before:
     * the later of the two, unless a recovery came after it; null when none holds.
     *
     * @param  array{time: ?int, message: ?string, recovered: ?int}|null  $latest
     * @param  array{time: int, message: string}|null  $recorded
     * @return array{time: int, message: string}|null
     */
    public static function holding(?array $latest, ?array $recorded): ?array
    {
        $disabled = $latest !== null && $latest['time'] !== null ? ['time' => (int) $latest['time'], 'message' => (string) $latest['message']] : null;
        if ($recorded !== null && ($disabled === null || $recorded['time'] > $disabled['time'])) {
            $disabled = $recorded;
        }
        if ($disabled === null || ($latest['recovered'] ?? null) !== null && $latest['recovered'] >= $disabled['time']) {
            return null;
        }

        return $disabled;
    }

    /**
     * Normalized ifName and ifDescr of the ports => port_id, per device (the first port of a name wins).
     *
     * @param  array<int, array>  $ports  port_id => device_id, ifName, ifDescr
     * @return array<int, array<string, int>>
     */
    public static function portIndex(array $ports): array
    {
        $index = [];
        foreach ($ports as $portId => $port) {
            foreach (['ifName', 'ifDescr'] as $field) {
                $name = InterfaceName::normalize((string) ($port[$field] ?? ''));
                if ($name !== '') {
                    $index[(int) ($port['device_id'] ?? 0)][$name] ??= (int) $portId;
                }
            }
        }

        return $index;
    }
}
