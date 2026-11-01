<?php

namespace Agoodley\LibrenmsInventory\Services;

use Agoodley\LibrenmsInventory\Data\ConfigStore;
use Agoodley\LibrenmsInventory\Data\LibrenmsNetwork;
use Agoodley\LibrenmsInventory\Data\Network;
use Agoodley\LibrenmsInventory\Data\SettingsRepository;
use Agoodley\LibrenmsInventory\Data\SoftwareRepository;
use Agoodley\LibrenmsInventory\Reports\Summaries;
use Agoodley\LibrenmsInventory\Software\Policies;
use Agoodley\LibrenmsInventory\Software\Policy;
use Agoodley\LibrenmsInventory\Software\Status;
use Agoodley\LibrenmsInventory\Support\Clock;
use Agoodley\LibrenmsInventory\Support\ErrorText;
use Agoodley\LibrenmsInventory\Support\Log;
use Agoodley\LibrenmsInventory\Support\PluginSettings;

/**
 * The software check: each device the settings cover (enabled, of the device group), its software version against the
 * policies, stored with since when (Data\SoftwareRepository), the version changes it sees recorded, what changed in the
 * eventlog. A device's row is written only when something of it changed; a device the settings no longer cover is
 * forgotten, and the version changes of a device LibreNMS no longer has.
 *
 * The eventlog (type inventory) gets an entry when a device's software becomes not compliant (or is not compliant
 * another way: another status, another version), when it is compliant again or no longer checked, and (a setting of
 * its own) for each upgrade or downgrade. Their messages start the same way each time, for alert rules:
 * "Inventory: software not compliant: ", "Inventory: software compliant: ", "Inventory: software not checked any
 * more: ", "Inventory: software upgraded from ", "Inventory: software downgraded from ".
 */
final class SoftwareCheck
{
    /** The ConfigStore row of the last run. */
    public const LAST_RUN = 'last_run';

    /** The ConfigStore row of the first run that checked the devices: the version changes are recorded since. */
    public const FIRST_RUN = 'first_run';

    /** The beginnings of the eventlog's messages. */
    public const NOT_COMPLIANT = 'Inventory: software not compliant: ';

    public const COMPLIANT = 'Inventory: software compliant: ';

    public const NOT_CHECKED = 'Inventory: software not checked any more: ';

    public const UPGRADED = 'Inventory: software upgraded from ';

    public const DOWNGRADED = 'Inventory: software downgraded from ';

    /** How long a check waits for another one to end (seconds), and holds its lock at most. */
    private const WAIT = 60;

    private const HOLD = 900;

    private readonly \Closure $now;

    /** @var \Closure(\Closure): mixed runs a check, one at a time */
    private readonly \Closure $exclusive;

    /**
     * @param  (\Closure(\Closure): mixed)|null  $exclusive  runs the work of a check while no other check runs (LibreNMS' cache lock);
     *     throws when another one does not end in time
     */
    public function __construct(
        private readonly Network $network,
        private readonly SoftwareRepository $software,
        private readonly PluginSettings $settings,
        private readonly EventSink $events,
        private readonly ConfigStore $store,
        ?\Closure $now = null,
        ?\Closure $exclusive = null,
    ) {
        $this->now = $now ?? fn (): int => time();
        $this->exclusive = $exclusive ?? fn (\Closure $work): mixed => $work();
    }

    /**
     * The check of LibreNMS' devices, results in the plugin's tables, entries in LibreNMS' eventlog; the scheduled check,
     * a check now and a save of the settings run one at a time (they would record an upgrade twice).
     */
    public static function forLibrenms(?PluginSettings $settings = null): self
    {
        return new self(new LibrenmsNetwork(), new SoftwareRepository(), $settings ?? (new SettingsRepository())->get(), new Eventlog(), new ConfigStore(), null,
            fn (\Closure $work): mixed => \Illuminate\Support\Facades\Cache::lock('inventory-plugin-software-check', self::HOLD)->block(self::WAIT, $work));
    }

    /**
     * The last run: when, how many devices of each status, what changed, why it could not run; null before the first.
     */
    public function lastRun(): ?array
    {
        try {
            return $this->store->get(self::LAST_RUN);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * When the first run checked the devices (Unix time): the version changes are recorded since; null before it.
     */
    public function firstRun(): ?int
    {
        try {
            $first = $this->store->get(self::FIRST_RUN);

            return isset($first['at']) ? (int) $first['at'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Check every device the settings cover, then forget the others; the run's summary, also stored as the last run.
     *
     * @param  \Closure(int, string, array): void|null  $progress  called after each device with its id, name and verdict
     * @return array{started: int, finished: int, devices: int, counts: array<string, int>, failing: int, resolved: int, upgrades: int, downgrades: int,
     *               forgotten: int, error: ?string}
     */
    public function run(?\Closure $progress = null): array
    {
        $summary = ['started' => ($this->now)(), 'finished' => 0, 'devices' => 0, 'counts' => Policies::counts([]), 'failing' => 0, 'resolved' => 0, 'upgrades' => 0,
            'downgrades' => 0, 'forgotten' => 0, 'error' => null];
        try {
            $policies = $this->settings->policies();
            $summary['error'] = $this->settings->error
                ?? ($this->software->available() ? null : 'The plugin\'s tables are missing: run ./lnms migrate in the LibreNMS directory.')
                ?? ($policies['errors'] ? 'The software policies of the settings have errors: ' . implode(' ', $policies['errors']) : null);
            if ($summary['error'] === null) {
                ($this->exclusive)(function () use (&$summary, $policies, $progress): void {
                    $devices = $this->network->devices($this->settings->deviceGroup());
                    $summary['devices'] = count($devices);
                    $stored = $this->software->rows(null);
                    $verdicts = [];
                    foreach ($devices as $deviceId => $device) {
                        $result = $this->device((int) $deviceId, $device, $stored[(int) $deviceId] ?? null, $policies['policies']);
                        $verdicts[] = $result;
                        foreach (['failing', 'resolved'] as $key) {
                            $summary[$key] += $result[$key] ? 1 : 0;
                        }
                        if ($result['upgrade'] !== null) {
                            $summary[$result['upgrade'] === 'upgrade' ? 'upgrades' : 'downgrades']++;
                        }
                        if ($progress !== null) {
                            $progress((int) $deviceId, $device['name'], $result);
                        }
                    }
                    $summary['counts'] = Policies::counts($verdicts);
                    $summary['forgotten'] = $this->software->forgetOthers(array_keys($devices));
                    $this->software->pruneUpgrades($this->network->deviceIds());
                    if ($this->firstRun() === null) {
                        $this->store->put(self::FIRST_RUN, ['at' => $summary['started']]);
                    }
                });
            }
        } catch (\InvalidArgumentException $e) {
            $summary['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            if (is_a($e, 'Illuminate\\Contracts\\Cache\\LockTimeoutException')) {
                $summary['error'] = 'Another software check did not end within ' . self::WAIT . ' seconds: nothing checked.';
            } else {
                Log::error('the software check failed: ' . ErrorText::forLog($e));
                $summary['error'] = 'The software check failed: see logs/librenms.log.';
            }
        }
        $summary['finished'] = ($this->now)();
        try {
            $this->store->put(self::LAST_RUN, $summary);
        } catch (\Throwable $e) {
            Log::error('cannot store the last run: ' . ErrorText::forLog($e));
        }

        return $summary;
    }

    /**
     * "120 devices: 3 not compliant (1 new), 100 compliant, 2 version unknown, 15 without a policy; 1 resolved, 2 upgrades".
     */
    public static function describe(array $summary): string
    {
        $counts = $summary['counts'];
        $n = fn (int $count, string $one, string $many) => number_format($count) . ' ' . ($count === 1 ? $one : $many);

        return $n((int) $summary['devices'], 'device', 'devices') . ': ' . number_format($counts['not_compliant']) . ' not compliant (' . number_format($summary['failing'])
            . ' new), ' . number_format($counts['compliant']) . ' compliant, ' . number_format($counts[Status::UNKNOWN]) . ' version unknown, '
            . number_format($counts[Status::NO_POLICY]) . ' without a policy; ' . number_format($summary['resolved']) . ' resolved, '
            . $n((int) $summary['upgrades'], 'upgrade', 'upgrades') . ', ' . $n((int) $summary['downgrades'], 'downgrade', 'downgrades')
            . ($summary['forgotten'] ? ', ' . $n((int) $summary['forgotten'], 'device', 'devices') . ' forgotten' : '');
    }

    /**
     * One device: its status against the policies, its row written when something changed, a version change recorded,
     * the eventlog told.
     *
     * @param  array{name: string, os: string, hardware: string, version: string, models: string[]}  $device
     * @param  array|null  $stored  its row of the last check, null for its first check
     * @param  list<Policy>  $policies
     * @return array{status: string, policy: ?string, message: string, failing: bool, resolved: bool, upgrade: ?string}
     */
    private function device(int $deviceId, array $device, ?array $stored, array $policies): array
    {
        $now = ($this->now)();
        // as the row keeps them, so that an unchanged device compares equal
        $device['os'] = mb_substr(trim($device['os']), 0, 64);
        $device['hardware'] = mb_substr(trim($device['hardware']), 0, 255);
        $version = mb_substr(trim($device['version']), 0, 255);
        $verdict = Policies::evaluate($policies, $device['os'], $device['models'], $version);
        $before = $stored !== null ? trim((string) $stored['version']) : '';
        if ($version === '' && $before !== '') {
            // LibreNMS lost the version (a failed poll): the row keeps the last one, so its next version is a change from it
            $version = $before;
            $verdict['message'] .= " The last version seen: $before.";
        }
        $result = $verdict + ['failing' => false, 'resolved' => false, 'upgrade' => null];
        $old = $stored !== null ? (string) $stored['status'] : null;
        $statusChanged = $old !== $verdict['status'];
        $versionChanged = $stored !== null && $before !== $version;
        $row = ['os' => $device['os'], 'model' => $device['hardware'], 'version' => $version, 'status' => $verdict['status'], 'policy' => $verdict['policy'],
            'message' => $verdict['message'], 'status_since' => $statusChanged ? $now : (Clock::unix($stored['status_since'] ?? null) ?? $now),
            'version_since' => $stored === null || $versionChanged ? $now : (Clock::unix($stored['version_since'] ?? null) ?? $now), 'updated_at' => $now];
        if ($stored !== null && ! $statusChanged && ! $versionChanged && (string) $stored['os'] === $row['os'] && (string) $stored['model'] === $row['model']
            && ($stored['policy'] ?? null) === ($row['policy'] !== null ? mb_substr($row['policy'], 0, 100) : null)
            && (string) $stored['message'] === mb_substr($row['message'], 0, 2000)) {
            return $result;
        }
        $upgrade = $versionChanged && $before !== '' ? (Summaries::compareVersions($version, $before) >= 0 ? 'upgrade' : 'downgrade') : null;
        $this->software->transaction(function () use ($deviceId, $row, $stored, $upgrade, $before, $version, $device, $now): void {
            $this->software->put($deviceId, $row, $stored !== null);
            if ($upgrade !== null) {
                $this->software->addUpgrade($deviceId, $device['os'], $device['hardware'], $before, $version, $upgrade, Clock::unix($stored['version_since'] ?? null), $now);
            }
        });
        $result['upgrade'] = $upgrade;
        if ($upgrade !== null && $this->settings->eventlogUpgrades()) {
            $this->events->write(ErrorText::line(($upgrade === 'upgrade' ? self::UPGRADED : self::DOWNGRADED) . "$before to $version", 1000), $deviceId,
                $upgrade === 'upgrade' ? 'info' : 'notice');
        }
        $failing = Status::failing($verdict['status']);
        $wasFailing = $old !== null && Status::failing($old);
        if ($failing && ($statusChanged || $versionChanged)) {
            $result['failing'] = true;
            $this->event(self::NOT_COMPLIANT . $verdict['message'], $deviceId, $verdict['status'] === Status::BLOCKED ? 'error' : 'warning');
        } elseif ($wasFailing && ! $failing) {
            $result['resolved'] = true;
            Status::compliant($verdict['status'])
                ? $this->event(self::COMPLIANT . $verdict['message'], $deviceId, 'ok')
                : $this->event(self::NOT_CHECKED . $verdict['message'], $deviceId, 'notice');
        }

        return $result;
    }

    private function event(string $message, int $deviceId, string $severity): void
    {
        if ($this->settings->eventlog()) {
            $this->events->write(ErrorText::line($message, 1000), $deviceId, $severity);
        }
    }
}
