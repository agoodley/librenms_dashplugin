<?php

namespace Agoodley\LibrenmsCompliance\Services;

use Agoodley\LibrenmsCompliance\Config\Config;
use Agoodley\LibrenmsCompliance\Data\ConfigStore;
use Agoodley\LibrenmsCompliance\Data\LibrenmsNetwork;
use Agoodley\LibrenmsCompliance\Data\Network;
use Agoodley\LibrenmsCompliance\Data\ResultRepository;
use Agoodley\LibrenmsCompliance\Data\SettingsRepository;
use Agoodley\LibrenmsCompliance\Rules\Checker;
use Agoodley\LibrenmsCompliance\Rules\Context;
use Agoodley\LibrenmsCompliance\Rules\Outcome;
use Agoodley\LibrenmsCompliance\Rules\PortRoles;
use Agoodley\LibrenmsCompliance\Rules\Rule;
use Agoodley\LibrenmsCompliance\Support\ErrorText;
use Agoodley\LibrenmsCompliance\Support\Log;
use Agoodley\LibrenmsCompliance\Support\PluginSettings;

/**
 * The checks: each device the settings cover (enabled, of the device group), its latest backup, the rules of the
 * settings on it, the results stored (Data\ResultRepository) and what changed in the eventlog. A device whose backup
 * cannot be read keeps its last results, marked with why; a device without a backup (any more) has none; a device the
 * settings no longer cover is forgotten after a run of them all. The configuration itself is never stored: only each rule's outcome, the findings with their
 * evidence (secrets hidden) and the switch ports.
 *
 * The eventlog gets at most two entries per device and check, for the findings at or above the settings' minimum
 * severity: the new findings and the resolved ones (the first check of a device: its findings), and one entry per run
 * when a backup tool cannot be reached (not for each device), while it was reachable on the run before.
 */
final class CheckService
{
    /** Most findings an eventlog entry names. */
    private const NAMED = 5;

    /** A scheduled run is due this many seconds before its interval has fully passed (the schedule's minute drifts). */
    private const SLACK = 300;

    /** The ConfigStore row of the last run. */
    public const LAST_RUN = 'last_run';

    private readonly \Closure $now;

    public function __construct(
        private readonly Network $network,
        private readonly Backups $backups,
        private readonly ResultRepository $results,
        private readonly PluginSettings $settings,
        private readonly EventSink $events,
        private readonly ConfigStore $store,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? fn (): int => time();
    }

    /**
     * The checks of LibreNMS' devices with their backups, results in the plugin's tables, entries in LibreNMS' eventlog.
     */
    public static function forLibrenms(?PluginSettings $settings = null): self
    {
        return new self(new LibrenmsNetwork(), new LibrenmsBackups(), new ResultRepository(), $settings ?? (new SettingsRepository())->get(), new Eventlog(),
            new ConfigStore());
    }

    /**
     * Is a scheduled run due: has the settings' interval passed since the last run started?
     */
    public function due(): bool
    {
        $last = $this->lastRun();

        return $last === null || ! empty($last['requested']) || (int) ($last['started'] ?? 0) + $this->settings->intervalHours() * 3600 - self::SLACK <= ($this->now)();
    }

    /**
     * Have the next scheduled run check every device, whatever the interval (the settings changed).
     */
    public function request(): void
    {
        $last = $this->lastRun();
        if ($last !== null) {
            $this->store->put(self::LAST_RUN, ['requested' => true] + $last);
        }
    }

    /**
     * The last run of every device: when, how many devices, checked, without a backup, failed, the findings new and
     * resolved, the devices forgotten, why it could not run; null before the first.
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
     * Check every device the settings cover, then forget the others; the run's summary, also stored as the last run.
     *
     * @param  \Closure(int, string, array): void|null  $progress  called after each device with its id, name and result
     * @return array{started: int, finished: int, devices: int, checked: int, no_backup: int, errors: int, findings: int, new: int, resolved: int,
     *               forgotten: int, unreachable: array<string, string>, error: ?string}
     */
    public function checkAll(?\Closure $progress = null): array
    {
        $summary = ['started' => ($this->now)(), 'finished' => 0, 'devices' => 0, 'checked' => 0, 'no_backup' => 0, 'errors' => 0, 'findings' => 0, 'new' => 0,
            'resolved' => 0, 'forgotten' => 0, 'unreachable' => [], 'error' => null];
        $previous = $this->lastRun();
        try {
            $summary['error'] = $this->backups->unavailable() ?? $this->settings->error
                ?? ($this->results->available() ? null : 'The plugin\'s tables are missing: run ./lnms migrate in the LibreNMS directory.');
            if ($summary['error'] === null) {
                $devices = $this->network->devices($this->settings->deviceGroup());
                $summary['devices'] = count($devices);
                $rules = $this->settings->rules();
                foreach ($devices as $deviceId => $device) {
                    $result = $this->check((int) $deviceId, $device, $rules);
                    $summary[match ($result['status']) {
                        Backup::FOUND => 'checked',
                        Backup::NO_BACKUP => 'no_backup',
                        default => 'errors',
                    }]++;
                    $summary['findings'] += $result['findings'];
                    $summary['new'] += $result['new'];
                    $summary['resolved'] += $result['resolved'];
                    if ($result['unreachable'] !== null) {
                        $summary['unreachable'][(string) $result['provider']] = $result['unreachable'];
                    }
                    if ($progress !== null) {
                        $progress((int) $deviceId, $device['name'], $result);
                    }
                }
                $summary['forgotten'] = $this->results->forgetOthers(array_keys($devices));
            }
        } catch (\InvalidArgumentException $e) {
            $summary['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            Log::error('the check of every device failed: ' . ErrorText::forLog($e));
            $summary['error'] = 'The check failed: see logs/librenms.log.';
        }
        $summary['finished'] = ($this->now)();
        foreach ($summary['unreachable'] as $provider => $why) {
            if (! isset($previous['unreachable'][$provider])) {
                $this->event("Compliance: $why The devices it backs up were not checked; their last results stay.", null, 'warning');
            }
        }
        try {
            $this->store->put(self::LAST_RUN, $summary);
        } catch (\Throwable $e) {
            Log::error('cannot store the last run: ' . ErrorText::forLog($e));
        }

        return $summary;
    }

    /**
     * Check one device the settings cover now (the device page's Check now, compliance:check --device).
     *
     * @return array{status: string, why: ?string, provider: ?string, findings: int, new: int, resolved: int, unreachable: ?string}
     *
     * @throws \InvalidArgumentException a device the settings do not cover, or a device group LibreNMS does not have
     */
    public function checkDevice(int $deviceId): array
    {
        $why = $this->backups->unavailable() ?? $this->settings->error
            ?? ($this->results->available() ? null : 'The plugin\'s tables are missing: run ./lnms migrate in the LibreNMS directory.');
        if ($why !== null) {
            throw new \InvalidArgumentException($why);
        }
        $device = $this->network->devices($this->settings->deviceGroup())[$deviceId] ?? null;
        if ($device === null) {
            throw new \InvalidArgumentException($this->network->device($deviceId) === null ? 'LibreNMS has no such device.' : ($this->settings->deviceGroup() !== ''
                ? 'The plugin checks the enabled devices of the device group ' . $this->settings->deviceGroup() . ' (its settings): not this one.'
                : 'The plugin checks the enabled devices: this one is disabled.'));
        }

        return $this->check($deviceId, $device, $this->settings->rules());
    }

    /**
     * Does the plugin check this device (enabled, of the settings' device group)?
     */
    public function covers(int $deviceId): bool
    {
        try {
            return isset($this->network->devices($this->settings->deviceGroup())[$deviceId]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * One device: its backup, the rules on it, its results stored, the eventlog told.
     *
     * @param  array{name: string, os: string}  $device
     * @param  array<string, Rule>  $rules
     * @return array{status: string, why: ?string, provider: ?string, findings: int, new: int, resolved: int, unreachable: ?string}
     */
    private function check(int $deviceId, array $device, array $rules): array
    {
        $result = ['status' => Backup::ERROR, 'why' => null, 'provider' => null, 'findings' => 0, 'new' => 0, 'resolved' => 0, 'unreachable' => null];
        try {
            $stored = $this->results->device($deviceId);
            $backup = $this->backups->latest($deviceId);
            $result['provider'] = $backup->provider;
            if ($backup->status === Backup::FOUND) {
                try {
                    $config = Config::parse((string) $backup->content);
                    if ($config->top === []) {
                        // only comments: no configuration to check (a tool's message instead of a backup)
                        unset($config);
                        $backup = Backup::missing($backup->provider, 'The backup has no configuration lines.');
                    }
                } catch (\LengthException) {
                    $backup = Backup::failed($backup->provider, 'The backup has more than ' . number_format(Config::MAX_LINES) . ' lines: too long to check.');
                }
            }
            if ($backup->status !== Backup::FOUND || ! isset($config)) {
                $result['status'] = $backup->status;
                $result['why'] = $backup->why;
                $result['unreachable'] = $backup->unreachable ? $backup->why : null;
                // a backup that cannot be read keeps the results of the last one checked; a device without a backup has none
                $gone = $this->results->saveFailure($deviceId, $backup->status, $backup->provider, (string) $backup->why, $device['os'], ($this->now)(),
                    $backup->status === Backup::ERROR);
                if ($backup->status === Backup::ERROR && ! $backup->unreachable && ($stored['status'] ?? null) === ResultRepository::CHECKED) {
                    $this->event('Compliance: the configuration cannot be checked: ' . $backup->why, $deviceId, 'warning');
                } elseif ($backup->status === Backup::NO_BACKUP && ! empty($stored['outcomes'])) {
                    $this->event('Compliance: no backup to check any more: ' . $backup->why . ($gone ? ' Its ' . Outcome::count(count($gone), 'finding') . ' went with it.' : ''),
                        $deviceId, 'notice');
                }

                return $result;
            }
            $context = new Context($config, $device['os'], $this->network->ports($deviceId), $this->roles($deviceId));
            $outcomes = (new Checker($rules, $this->settings->builtin()))->check($context);
            $findings = [];
            foreach ($outcomes as $id => $outcome) {
                if ($outcome->status === Rule::FAIL) {
                    $findings[$id] = ['severity' => $rules[$id]->severity, 'message' => $outcome->message, 'evidence' => $outcome->evidence, 'ports' => $outcome->ports];
                }
            }
            $changes = $this->results->save($deviceId, [
                'status' => ResultRepository::CHECKED,
                'provider' => $backup->provider,
                'backup_at' => $backup->date,
                'config_hash' => hash('sha256', (string) $backup->content),
                'os' => $device['os'],
                'outcomes' => array_map(fn (Outcome $outcome) => [$outcome->status, $outcome->status === Rule::FAIL ? '' : ErrorText::line($outcome->message, 300)], $outcomes),
                'ports' => Checker::ports($context),
            ], $findings, ($this->now)());
            $result = ['status' => Backup::FOUND, 'why' => null, 'provider' => $backup->provider, 'findings' => count($findings), 'new' => count($changes['new']),
                'resolved' => count($changes['resolved']), 'unreachable' => null];
            $this->report($deviceId, $stored === null || ! $stored['outcomes'], $changes, $rules);
        } catch (\Throwable $e) {
            Log::error("the check of device $deviceId failed: " . ErrorText::forLog($e));
            $result['why'] = 'The check failed: see logs/librenms.log.';
            try {
                $this->results->saveFailure($deviceId, Backup::ERROR, $result['provider'], $result['why'], $device['os'], ($this->now)(), true);
            } catch (\Throwable) {
                // the database itself fails: the run's other devices say so
            }
        }

        return $result;
    }

    /**
     * What the device's ports face, from LibreNMS' links, spanning tree and port-channels.
     *
     * @return array<int, array{role: string, neighbors: string[]}>
     */
    private function roles(int $deviceId): array
    {
        $around = $this->network->neighborhood($deviceId);

        return PortRoles::compute($deviceId, $around['links'], $around['bridges'], $around['rootPorts'], $around['lagParent'], $around['names']);
    }

    /**
     * The eventlog entries of a device's check: its new findings and those resolved, at or above the minimum severity
     * (the first check: its findings).
     *
     * @param  array{new: array<string, array>, resolved: array<string, array>}  $changes
     * @param  array<string, Rule>  $rules
     */
    private function report(int $deviceId, bool $first, array $changes, array $rules): void
    {
        $minimum = Rule::rank($this->settings->eventlogMinimum());
        $pick = fn (array $findings) => array_filter($findings, fn (array $finding) => Rule::rank((string) $finding['severity']) <= $minimum);
        $new = $pick($changes['new']);
        if ($new) {
            uasort($new, fn (array $a, array $b) => Rule::rank((string) $a['severity']) <=> Rule::rank((string) $b['severity']));
            $worst = (string) reset($new)['severity'];
            $this->event('Compliance: ' . ($first ? 'first check, ' . Outcome::count(count($new), 'finding') : Outcome::count(count($new), 'new finding'))
                . ': ' . $this->titles($new, $rules, true), $deviceId, match ($worst) {
                    Rule::CRITICAL => 'error',
                    Rule::WARNING => 'warning',
                    default => 'info',
                });
        }
        $resolved = $pick($changes['resolved']);
        if ($resolved) {
            $this->event('Compliance: ' . Outcome::count(count($resolved), 'finding') . ' resolved: ' . $this->titles($resolved, $rules, false), $deviceId, 'ok');
        }
    }

    /**
     * "Edge ports have portfast (warning), NTP servers (warning) and 2 more".
     *
     * @param  array<string, array>  $findings
     * @param  array<string, Rule>  $rules
     */
    private function titles(array $findings, array $rules, bool $severity): string
    {
        $names = [];
        foreach ($findings as $id => $finding) {
            $names[] = ($rules[$id]->title ?? (string) $id) . ($severity ? ' (' . $finding['severity'] . ')' : '');
        }

        return Outcome::list($names, self::NAMED);
    }

    private function event(string $message, ?int $deviceId, string $severity): void
    {
        if ($this->settings->eventlog()) {
            $this->events->write(ErrorText::line($message, 1000), $deviceId, $severity);
        }
    }
}
