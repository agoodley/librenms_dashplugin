<?php

namespace Agoodley\LibrenmsTopology\Interfaces;

/**
 * Error and packet totals of ports over a window, from the RRD files LibreNMS writes for each port
 * (port-id<id>.rrd: INERRORS, OUTERRORS and the packet counters; port-id<id>-dot3.rrd with
 * enable_ports_etherlike: FCSErrors, LateCollisions). One rrdtool graph command reads a batch of
 * files: a VDEF TOTAL of each data source (the rate of each step times the step) PRINTed in
 * order, and the seconds the window has data for. This class builds the arguments and reads the
 * output; Data\RrdReader runs rrdtool.
 */
class RrdTotals
{
    /** Data sources of port-id<id>.rrd: total => [data sources added up]. */
    public const PORT = [
        'in_errors' => ['INERRORS'],
        'out_errors' => ['OUTERRORS'],
        'in_packets' => ['INUCASTPKTS', 'INNUCASTPKTS'],
        'out_packets' => ['OUTUCASTPKTS', 'OUTNUCASTPKTS'],
    ];

    /** Data sources of port-id<id>-dot3.rrd. */
    public const DOT3 = [
        'fcs' => ['FCSErrors'],
        'late_collisions' => ['LateCollisions'],
    ];

    /**
     * The arguments of one rrdtool graph command after "rrdtool" for a batch of ports, and what each
     * PRINT line of its output is.
     *
     * @param  array<int, array{port: string, dot3: ?string}>  $files  port_id => its RRD file, and its dot3 file (null: none)
     * @return array{args: string[], prints: array<int, array{0: int, 1: string}>} prints: [port_id, total] in output order
     */
    public static function command(array $files, int $seconds, ?string $daemon = null): array
    {
        $args = ['graph', '/dev/null', '--start', 'end-' . max(1, $seconds), '--end', 'now'];
        if ($daemon !== null && $daemon !== '') {
            array_push($args, '--daemon', $daemon);
        }
        $prints = [];
        $n = 0;
        foreach ($files as $portId => $file) {
            $sources = [[$file['port'], self::PORT]];
            if (($file['dot3'] ?? null) !== null) {
                $sources[] = [$file['dot3'], self::DOT3];
            }
            foreach ($sources as $i => [$path, $totals]) {
                foreach ($totals as $total => $dataSources) {
                    $names = [];
                    foreach ($dataSources as $dataSource) {
                        $names[] = $name = 'd' . $n++;
                        $args[] = 'DEF:' . $name . '=' . self::escape($path) . ':' . $dataSource . ':AVERAGE';
                    }
                    // unknown steps count as nothing: the total of the steps that have data
                    $sum = 's' . $n++;
                    $args[] = 'CDEF:' . $sum . '=' . implode(',', array_map(fn ($d) => "$d,UN,0,$d,IF", $names)) . str_repeat(',+', count($names) - 1);
                    $args[] = "VDEF:t$sum=$sum,TOTAL";
                    $args[] = "PRINT:t$sum:%.0lf";
                    $prints[] = [$portId, $total];
                    if ($i === 0 && $total === 'in_errors') {
                        // the seconds of the window the port's file has data for
                        $known = 'k' . $n++;
                        $args[] = "CDEF:$known={$names[0]},UN,0,1,IF";
                        $args[] = "VDEF:t$known=$known,TOTAL";
                        $args[] = "PRINT:t$known:%.0lf";
                        $prints[] = [$portId, 'seconds'];
                    }
                }
            }
        }

        return ['args' => $args, 'prints' => $prints];
    }

    /**
     * The totals of each port from rrdtool's output: its first line is the size of the (empty)
     * image, then one line per PRINT; a total of no data ("nan") is 0.
     *
     * @param  array<int, array{0: int, 1: string}>  $prints
     * @return array<int, array<string, int>>|null null when the output does not fit the command
     */
    public static function parse(string $output, array $prints): ?array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn ($line) => $line !== ''));
        if ($lines && preg_match('/^\d+x\d+$/', $lines[0])) {
            array_shift($lines);
        }
        if (count($lines) !== count($prints)) {
            return null;
        }
        $totals = [];
        foreach ($prints as $i => [$portId, $total]) {
            $value = $lines[$i];
            if (! is_numeric($value) && ! preg_match('/^-?nan$/i', $value)) {
                return null;
            }
            $totals[$portId][$total] = is_numeric($value) ? max(0, (int) round((float) $value)) : 0;
        }

        return $totals;
    }

    /**
     * A file name in a DEF: rrdtool separates its parts with colons, so a colon in the name (an IPv6
     * address as the device's hostname) is escaped.
     */
    public static function escape(string $path): string
    {
        return str_replace(':', '\:', $path);
    }

    /**
     * The name of a device's RRD directory, from its hostname as LibreNMS names it (Rrd::dirFromHost(): an IPv6
     * address without its brackets, through safeName()); null when that name is no directory of its own: nothing,
     * "." (rrd_dir itself) or ".." (the directory above it). safeName() leaves no "/", so no other name leaves rrd_dir.
     */
    public static function directory(string $hostname): ?string
    {
        $name = self::safeName(trim($hostname, '[]'));

        return in_array($name, ['', '.', '..'], true) ? null : $name;
    }

    /**
     * The name LibreNMS gives a device's RRD directory and files (Rrd::safeName()).
     */
    public static function safeName(string $name): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9,._\-]/', '_', $name);
    }
}
