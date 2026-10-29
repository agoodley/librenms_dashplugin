<?php

namespace Agoodley\LibrenmsTopology\Data;

use Agoodley\LibrenmsTopology\Interfaces\RrdTotals;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Runs rrdtool for Interfaces\RrdTotals: the error and packet totals of ports over a window, from
 * the RRD files LibreNMS writes, a batch of ports per run; read locally (rrd_dir) or through
 * rrdcached (--daemon, paths relative to its base directory) as LibreNMS' settings say. Only
 * read-only rrdtool commands are run.
 */
class RrdReader
{
    /** Ports per rrdtool run. */
    private const BATCH = 40;

    /** Seconds one rrdtool run may take. */
    private const TIMEOUT = 60;

    public function __construct(
        private readonly string $rrdtool,
        private readonly string $directory,
        private readonly ?string $daemon = null,
    ) {
    }

    /**
     * From LibreNMS' settings (rrdtool, rrd_dir, rrdcached); null when LibreNMS writes no RRD files
     * (rrd.enable off) or they can be read neither locally nor through rrdcached.
     */
    public static function fromLibrenms(): ?self
    {
        $config = function (string $key, mixed $default = null): mixed {
            try {
                return class_exists(\App\Facades\LibrenmsConfig::class) ? \App\Facades\LibrenmsConfig::get($key, $default) : $default;
            } catch (\Throwable) {
                return $default;
            }
        };
        if ($config('rrd.enable', true) === false) {
            return null;
        }
        $directory = rtrim((string) $config('rrd_dir', ''), '/');
        $daemon = trim((string) $config('rrdcached', '')) ?: null;
        if ($daemon === null && ($directory === '' || ! is_dir($directory))) {
            return null;
        }

        return new self((string) ($config('rrdtool', 'rrdtool') ?: 'rrdtool'), $directory, $daemon);
    }

    /**
     * The totals of these ports over the last $seconds (RrdTotals::PORT, DOT3 when the port has
     * that file, and the seconds of data). Ports whose RRD file is missing, or that rrdtool cannot
     * read, are left out.
     *
     * @param  array<int, string>  $hostnames  port_id => its device's hostname
     * @return array<int, array<string, int>>
     *
     * @throws \RuntimeException when rrdtool cannot be run at all
     */
    public function totals(array $hostnames, int $seconds): array
    {
        $local = $this->directory !== '' && is_dir($this->directory);
        $files = [];
        foreach ($hostnames as $portId => $hostname) {
            $relative = RrdTotals::safeName(trim($hostname, '[]')) . '/port-id' . $portId;
            if ($local && ! is_file($this->directory . '/' . $relative . '.rrd')) {
                continue;
            }
            $path = fn (string $name) => $this->daemon !== null ? $name : $this->directory . '/' . $name;
            $files[(int) $portId] = [
                'port' => $path($relative . '.rrd'),
                'dot3' => $local && is_file($this->directory . '/' . $relative . '-dot3.rrd') ? $path($relative . '-dot3.rrd') : null,
            ];
        }

        $totals = [];
        foreach (array_chunk($files, self::BATCH, true) as $batch) {
            $read = $this->run($batch, $seconds);
            if ($read === null && count($batch) > 1) {
                // one file rrdtool cannot read fails the whole run: then one port at a time
                $read = [];
                foreach ($batch as $portId => $file) {
                    $read += $this->run([$portId => $file], $seconds) ?? [];
                }
            }
            $totals += $read ?? [];
        }

        return $totals;
    }

    /**
     * @param  array<int, array{port: string, dot3: ?string}>  $files
     * @return array<int, array<string, int>>|null
     */
    private function run(array $files, int $seconds): ?array
    {
        $command = RrdTotals::command($files, $seconds, $this->daemon);
        $process = new Process([$this->rrdtool, ...$command['args']]);
        $process->setTimeout(self::TIMEOUT);
        $process->run();
        if (in_array($process->getExitCode(), [126, 127], true)) {
            throw new \RuntimeException("cannot run $this->rrdtool: " . trim($process->getErrorOutput()));
        }
        if (! $process->isSuccessful()) {
            Log::debug('topology plugin: rrdtool cannot read the totals of ports ' . implode(', ', array_keys($files)) . ': ' . trim($process->getErrorOutput()));

            return null;
        }

        return RrdTotals::parse($process->getOutput(), $command['prints']);
    }
}
