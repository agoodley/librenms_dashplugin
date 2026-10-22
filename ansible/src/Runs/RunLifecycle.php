<?php

namespace Agoodley\LibrenmsAnsible\Runs;

use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Runner\Runner;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runner\RunnerTask;

/**
 * The life of a run, from its launch to its end, as transitions on a RunStore with a Runner and an EventSink: the
 * run page and the sync command call refresh(), the launcher reports the launch, users stop or release a run.
 *
 * The plugin never ends a run, nor frees its devices, on ambiguous evidence. A run ends with the runner's own final
 * status for its task (settled: Semaphore reports the end before the last lines of the log are readable); as not
 * launched when the runner refused the launch, or when a launch whose answer never came has no task once a search
 * that covered every task since the launch found none; as lost when the runner keeps not finding the task for
 * minutes while the token still sees the project; as released when an admin releases the devices of a run the
 * runner could not be asked about for a while. A run still unfinished after the Stop after setting is stopped, and
 * keeps its devices until the runner confirms the stop. A stopped run keeps them longer: Semaphore UI's stop ends only
 * Ansible's main process, and the step each target was in runs on (with all its loop items), so the devices stay
 * held for the Hold after a stop setting unless an admin releases them, and a target whose step was in progress gets
 * the result unknown. Lost and released runs are still watched for a week, so a later real end is recorded (and a
 * task that runs again takes its devices back). Anything else (a timeout, a server error, a token without access for
 * a moment) is noted on the run, which keeps its devices and is followed on.
 *
 * Every change is a compare-and-set on the run's version: a refresh that took long never writes over a newer one,
 * and only the refresh that records the end writes the results and the eventlog of the targets.
 */
final class RunLifecycle
{
    /** Seconds after the runner's end at which the log is taken as complete anyway. */
    public const SETTLE = 60;

    /** Seconds after which the task of a launch that did not complete is looked for (at once when the answer was unclear). */
    public const LAUNCH_GRACE = 300;

    /** Seconds after which a launch whose task a complete search did not find was not launched. */
    public const LAUNCH_TIMEOUT = 600;

    /** Seconds the runner's clock may be behind LibreNMS' when its tasks are matched to a launch. */
    public const CLOCK_SKEW = 120;

    /** A task is lost when the runner did not find it this many times in a row ... */
    public const MISSING_CHECKS = 3;

    /** ... over at least this many seconds, while the token still saw the project. */
    public const MISSING_SECONDS = 300;

    /** Seconds after a stop the runner has not carried out at which it is asked again, forced. */
    public const FORCE_STOP_AFTER = 300;

    /** Seconds without an answer about the run after which an admin may release its devices. */
    public const RELEASE_AFTER = 900;

    /** Seconds a lost or released run is watched for a late end, and how often it is asked. */
    public const WATCH_SECONDS = 604800;

    public const WATCH_INTERVAL = 300;

    /** Seconds after which a watched run whose task the runner reported finished is asked again, for the rest of its log. */
    public const SETTLE_POLL = 5;

    /**
     * @param  \Closure(): Runner  $runner  the runner, asked for when needed (RunnerError when it is not set up)
     * @param  \Closure(string[]): array<string, array{device_id: int, hostname: string, display: string}>|null  $devices
     *                                                                                                          the devices of inventory names (for hosts the runner ran on that were not targets)
     * @param  int  $holdSeconds  how long a stopped run keeps its devices after the runner's stop (Hold after a stop)
     */
    public function __construct(
        private readonly RunStore $runs,
        private readonly \Closure $runner,
        private readonly EventSink $events,
        private readonly int $outputMaxBytes,
        private readonly int $stopAfterSeconds,
        private readonly ?\Closure $devices = null,
        private readonly int $holdSeconds = 1800,
    ) {
    }

    /**
     * Follow a run one step: an unfinished one on the runner, a lost or released one for a late end. Returns the run
     * as stored afterwards, null when there is no such run.
     */
    public function refresh(int $id, int $now): ?array
    {
        $run = $this->runs->find($id);
        if ($run === null) {
            return null;
        }
        if (RunStatus::isFinal($run['status'])) {
            if (self::watched($run, $now)) {
                $this->watch($run, $now);
            }
        } elseif ($run['runner_task_id'] === null) {
            $this->findLaunch($run, $now);
        } else {
            $this->follow($run, $now);
        }

        return $this->runs->find($id);
    }

    /**
     * Is a lost or released run due to be asked about a late end? (Also a released one that never got its task: the
     * runner may have started it after all.) Every WATCH_INTERVAL seconds, and every SETTLE_POLL seconds while the
     * runner's log of an end it reported settles.
     */
    public static function watched(array $run, int $now): bool
    {
        if (! in_array($run['status'], RunStatus::WATCHED, true) || $run['watch_until'] === null || strtotime((string) $run['watch_until']) <= $now) {
            return false;
        }

        return $run['polled_at'] === null
            || $now - strtotime((string) $run['polled_at']) >= ($run['runner_final_at'] !== null ? self::SETTLE_POLL : self::WATCH_INTERVAL);
    }

    /**
     * May an admin release the devices of this run: unfinished, and the runner could not be asked about it for
     * RELEASE_AFTER seconds? (The devices of a held stopped run can be released at any time: held().)
     */
    public static function releasable(array $run, int $now): bool
    {
        return ! RunStatus::isFinal($run['status']) && $run['poll_error'] !== null
            && $now - strtotime((string) ($run['runner_seen_at'] ?? $run['created_at'])) >= self::RELEASE_AFTER;
    }

    /**
     * Does a stopped run still hold its devices (the step in progress at the stop may still run on the runner)?
     */
    public static function held(array $run, int $now): bool
    {
        return $run['status'] === RunStatus::STOPPED && ($run['held_until'] ?? null) !== null && strtotime((string) $run['held_until']) > $now;
    }

    // ------------------------------------------------------------------ launch

    /**
     * The runner answered the launch with its task.
     */
    public function launched(int $id, RunnerTask $task, int $now): bool
    {
        $run = $this->runs->find($id);

        return $run !== null && $this->adopt($run, $task, $now);
    }

    /**
     * The runner gave no proper answer to the launch (a timeout, a server error): it may have started the task, so
     * the run keeps its devices while the plugin looks for the task.
     */
    public function launchUncertain(int $id, string $error, int $now): void
    {
        $run = $this->runs->find($id);
        if ($run !== null && $run['runner_task_id'] === null && ! RunStatus::isFinal($run['status'])) {
            $this->runs->updateAt($id, (int) $run['version'], ['poll_error' => UntrustedText::line('The runner gave no proper answer to the launch, so the '
                . 'plugin looks for its task: ' . $error, 500), 'polled_at' => self::time($now)]);
        }
    }

    /**
     * The runner refused the launch (it answered, with an error): nothing ran.
     */
    public function launchRefused(int $id, string $error, int $now): void
    {
        $run = $this->runs->find($id);
        if ($run !== null && $run['runner_task_id'] === null && ! RunStatus::isFinal($run['status'])) {
            $this->notLaunched($run, $now, $error);
        }
    }

    /**
     * A launch without a recorded task: look for the task the runner started for it (by the launch's message, which
     * holds a random nonce, created after the run), and follow it from then on. It was not launched only when a
     * search that covered every task since the launch found none, LAUNCH_TIMEOUT seconds after it; a search that
     * failed or did not reach back far enough keeps the run (and its devices) waiting.
     */
    private function findLaunch(array $run, int $now): void
    {
        $age = $now - strtotime((string) $run['created_at']);
        if ($run['poll_error'] === null && $age <= self::LAUNCH_GRACE) {
            return; // the launching request is still at it
        }
        try {
            $search = ($this->runner)()->findTask((string) $run['template_id'],
                RunText::taskMessage((int) $run['id'], (string) $run['username'], $run['launch_nonce'] ?? null),
                strtotime((string) $run['created_at']) - self::CLOCK_SKEW);
        } catch (RunnerError $e) {
            $this->note($run, $now, 'The launch got no proper answer, and the plugin cannot look for its task in the runner now: '
                . $e->getMessage() . ' The run keeps its devices until the plugin knows.');

            return;
        }
        if ($search->task !== null) {
            $this->adopt($run, $search->task, $now);

            return;
        }
        if ($search->complete && $age > self::LAUNCH_TIMEOUT) {
            $this->notLaunched($run, $now, 'the runner gave no proper answer to the launch, and has no task for it ' . intdiv(self::LAUNCH_TIMEOUT, 60) . ' minutes later');

            return;
        }
        $this->runs->updateAt((int) $run['id'], (int) $run['version'], ['polled_at' => self::time($now),
            'poll_error' => $run['poll_error'] ?? 'The launch did not complete: the plugin looks for its task in the runner.']);
    }

    /**
     * Record the runner's task of a launch, once, with the "started" eventlog entry of each target.
     */
    private function adopt(array $run, RunnerTask $task, int $now): bool
    {
        if ($run['runner_task_id'] !== null || RunStatus::isFinal($run['status'])) {
            return false;
        }
        $id = (int) $run['id'];
        if (! $this->runs->updateAt($id, (int) $run['version'], ['runner_task_id' => $task->id, 'runner_status' => $task->rawStatus,
            'status' => RunStatus::isFinal($task->status) ? RunStatus::RUNNING : $task->status, 'poll_error' => null, 'runner_seen_at' => self::time($now),
            'started_at' => $task->started !== null ? self::time($task->started) : null])) {
            return false;
        }
        foreach (array_keys($this->targetNames($id)) as $deviceId) {
            $this->events->write(RunText::started($id, (string) $run['template_name'], (string) $run['username']), $deviceId, 'info', RunText::reference($id),
                (string) $run['username']);
        }

        return true;
    }

    /**
     * Nothing ran: the runner refused the launch, or a complete search found no task for it.
     */
    private function notLaunched(array $run, int $now, string $reason): void
    {
        $id = (int) $run['id'];
        $targets = $this->targetNames($id);
        if (! $this->runs->updateAt($id, (int) $run['version'], ['status' => RunStatus::NOT_LAUNCHED, 'error' => UntrustedText::line($reason, 1000),
            'poll_error' => null, 'finished_at' => self::time($now), 'summary' => [RunResults::NOT_RUN => count($targets)]])) {
            return;
        }
        $this->runs->setResults($id, array_fill_keys(array_keys($targets), ['result' => RunResults::NOT_RUN, 'counts' => null]));
        foreach (array_keys($targets) as $deviceId) {
            $this->events->write(RunText::notLaunched($id, (string) $run['template_name'], (string) $run['username'], $reason), $deviceId, 'warning',
                RunText::reference($id), (string) $run['username']);
        }
    }

    // ------------------------------------------------------------------ following

    /**
     * One refresh of a run with a task: its status, the new log lines, the stop when it is due, and the end once
     * the runner reported it and the log settled.
     */
    private function follow(array $run, int $now): void
    {
        $taskId = (string) $run['runner_task_id'];
        try {
            $runner = ($this->runner)();
            $task = $runner->task($taskId);
        } catch (RunnerError $e) {
            if ($e->kind === RunnerError::NOT_FOUND && isset($runner)) {
                $this->missing($run, $runner, $now);
            } else {
                $this->note($run, $now, $e->getMessage());
            }

            return;
        }
        try {
            $output = $runner->output($taskId, (int) $run['output_lines']);
        } catch (RunnerError $e) {
            $this->note($run, $now, $e->getMessage());

            return;
        }
        $buffer = OutputBuffer::add($run, $output->lines, $this->outputMaxBytes);
        $values = $buffer['values'] + ['output_lines' => $output->next, 'runner_status' => $task->rawStatus, 'poll_error' => null,
            'polled_at' => self::time($now), 'runner_seen_at' => self::time($now), 'missing_checks' => 0, 'missing_since' => null,
            'started_at' => $task->started !== null ? self::time($task->started) : $run['started_at']];
        if (! RunStatus::isFinal($task->status)) {
            // a requested stop stays "stopping" until the runner says how the task ended
            $values['status'] = $run['status'] === RunStatus::STOPPING ? RunStatus::STOPPING : $task->status;
            $this->stopIfDue($run, $runner, $now, $values);
            $this->runs->updateAt((int) $run['id'], (int) $run['version'], $values, $buffer['append']);

            return;
        }
        $settled = ($run['runner_final_at'] !== null && ! $output->lines)
            || ($task->ended !== null && $now - $task->ended >= self::SETTLE)
            || ($run['runner_final_at'] !== null && $now - strtotime((string) $run['runner_final_at']) >= self::SETTLE);
        if (! $settled) {
            $values['runner_final_at'] = $run['runner_final_at'] ?? self::time($now);
            $this->runs->updateAt((int) $run['id'], (int) $run['version'], $values, $buffer['append']);

            return;
        }
        $this->finish($run, $values, $buffer['append'], $task, $runner, $now);
    }

    /**
     * Stop the task when the run outlived the Stop after setting, and stop it again, forced, when the runner has
     * not carried out an earlier stop after FORCE_STOP_AFTER seconds. The run stays unfinished, its devices busy,
     * until the runner reports the end.
     *
     * @param  array<string, mixed>  $values  the columns this refresh writes
     */
    private function stopIfDue(array $run, Runner $runner, int $now, array &$values): void
    {
        $requested = $run['stop_requested_at'] !== null ? strtotime((string) $run['stop_requested_at']) : null;
        $overdue = $now - strtotime((string) $run['created_at']) > $this->stopAfterSeconds;
        if (($requested === null && ! $overdue) || ($requested !== null && $now - $requested < self::FORCE_STOP_AFTER)) {
            return;
        }
        $limit = 'still unfinished after ' . self::hours($this->stopAfterSeconds) . ' (Stop after setting)';
        try {
            $runner->stop((string) $run['runner_task_id'], $requested !== null);
        } catch (RunnerError $e) {
            $values['poll_error'] = UntrustedText::line(($requested === null ? ucfirst($limit) . ': ' : 'The task did not stop: ') . 'the plugin asked the '
                . 'runner to stop it, which failed (' . $e->getMessage() . '). The run keeps its devices until the runner reports its end.', 500);

            return;
        }
        $values['status'] = RunStatus::STOPPING;
        $values['stop_requested_at'] = self::time($now);
        if ($requested === null) {
            $values['stop_reason'] = 'stopped by the plugin: ' . $limit;
        }
    }

    /**
     * The runner did not find the task: a run is lost only when that persists over MISSING_CHECKS refreshes and
     * MISSING_SECONDS while the token still sees the project (Semaphore also answers "not found" when the token's
     * user is not in the project, and 400 for a database error).
     */
    private function missing(array $run, Runner $runner, int $now): void
    {
        $taskId = (string) $run['runner_task_id'];
        try {
            $runner->role();
        } catch (RunnerError $e) {
            $this->note($run, $now, "The runner does not find task #$taskId, and the token cannot see the project right now (" . $e->getMessage()
                . '): the plugin keeps following the run.');

            return;
        }
        $checks = (int) $run['missing_checks'] + 1;
        $since = $run['missing_since'] !== null ? strtotime((string) $run['missing_since']) : $now;
        if ($checks >= self::MISSING_CHECKS && $now - $since >= self::MISSING_SECONDS) {
            $this->lose($run, $now, "the runner no longer has task #$taskId (not found $checks times over " . intdiv($now - $since, 60)
                . ' minutes, while the token still saw the project)');

            return;
        }
        $this->runs->updateAt((int) $run['id'], (int) $run['version'], ['missing_checks' => $checks, 'missing_since' => self::time($since),
            'polled_at' => self::time($now), 'poll_error' => "The runner does not find task #$taskId: the plugin asks again, and gives the run up as lost "
                . 'only when it stays missing for ' . intdiv(self::MISSING_SECONDS, 60) . ' minutes.']);
    }

    /**
     * The task is gone from the runner: the run is lost, its devices free; its results come from the log as far as
     * the plugin read it, so a target that log does not name is unknown. It is watched for a late end.
     */
    private function lose(array $run, int $now, string $reason): void
    {
        $id = (int) $run['id'];
        $evaluation = RunResults::evaluate($this->targetNames($id), OutputBuffer::display($run), false);
        if (! $this->runs->updateAt($id, (int) $run['version'], ['status' => RunStatus::LOST, 'error' => UntrustedText::line($reason, 1000), 'poll_error' => null,
            'finished_at' => self::time($now), 'polled_at' => self::time($now), 'summary' => $evaluation['summary'], 'watch_until' => self::time($now + self::WATCH_SECONDS)])) {
            return;
        }
        $this->runs->setResults($id, $evaluation['results']);
        foreach ($evaluation['results'] as $deviceId => $result) {
            $this->events->write(RunText::finished($id, RunStatus::LOST, $result['result'], null, (string) $run['template_name'], (string) $run['username'], $reason),
                $deviceId, 'warning', RunText::reference($id), (string) $run['username']);
        }
    }

    /**
     * The run is over: each target's result (from the PLAY RECAP, else from the task result lines), the status
     * (success only when every target did what was asked), the hosts it ran on that were not targets (recorded
     * on their devices), and the eventlog. The results are read from the stored log, or from the runner's whole log
     * when the stored one was cut.
     *
     * @param  array<string, mixed>  $values  the columns this refresh writes
     * @param  array<string, string>  $append  the text it adds to the stored log (OutputBuffer::add())
     * @param  string[]|null  $whole  the runner's whole log when the caller has it (a late end)
     */
    private function finish(array $run, array $values, array $append, RunnerTask $task, Runner $runner, int $now, bool $late = false, ?array $whole = null): void
    {
        $id = (int) $run['id'];
        $stored = OutputBuffer::applied($run, $values, $append);
        $lines = $whole ?? OutputBuffer::display($stored);
        $complete = true;
        if ($whole === null && ! empty($stored['output_truncated'])) {
            try {
                $lines = $runner->output((string) $stored['runner_task_id'])->lines;
            } catch (RunnerError) {
                $complete = false; // the stored head and tail
            }
        }
        $targets = $this->targetNames($id);
        $evaluation = RunResults::evaluate($targets, $lines, $complete);
        $heldUntil = null;
        if ($task->status === RunStatus::STOPPED) {
            // the step each target was in runs on: its result is unknown, and the devices stay held for a while
            $evaluation = RunResults::afterStop($evaluation, $targets, TaskLines::inProgress($lines, array_values($targets))['hosts']);
            $heldUntil = ($task->ended ?? $now) + $this->holdSeconds;
        }
        $status = RunResults::status($task->status, $evaluation['results']);
        $unexpected = $this->unexpectedDevices($evaluation['unexpected']);
        $values += ['status' => $status, 'summary' => $evaluation['summary'], 'unexpected_hosts' => array_keys($evaluation['unexpected']) ?: null,
            'finished_at' => $task->ended !== null ? self::time($task->ended) : self::time($now), 'watch_until' => null, 'polled_at' => self::time($now),
            'held_until' => $heldUntil !== null ? self::time($heldUntil) : null,
            'error' => $status === RunStatus::INCOMPLETE ? RunDiagnosis::incomplete($lines, $evaluation['results'], $targets) : ($late ? null : $run['error'])];
        if (! $this->runs->updateAt($id, (int) $run['version'], $values, $append)) {
            return; // another refresh got there first
        }
        $this->runs->setResults($id, $evaluation['results']);
        if ($unexpected) {
            $this->runs->addUnexpected($id, $unexpected);
        }
        $user = (string) $run['username'];
        $note = $late ? 'the runner reported this end after the plugin had given the run up as ' . strtolower(RunStatus::label((string) $run['status']))
            : ($task->status === RunStatus::STOPPED ? ($run['stop_reason'] ?? ($run['stopped_by'] ? 'stopped by ' . $run['stopped_by'] : 'stopped in the runner'))
                . '; the step in progress may still run on the runner' . ($heldUntil !== null && $heldUntil > $now ? ': the devices are held until ' . date('Y-m-d H:i', $heldUntil) : '') : null);
        foreach ($evaluation['results'] as $deviceId => $result) {
            $this->events->write(RunText::finished($id, $status, $result['result'], $result['counts'], (string) $run['template_name'], $user, $note), $deviceId,
                RunResults::SEVERITY[$result['result']] ?? 'info', RunText::reference($id), $user);
        }
        foreach ($unexpected as $deviceId => $row) {
            $this->events->write(RunText::unexpectedDevice($id, $row['inventory_name'], $row['result'], (string) $run['template_name'], $user), $deviceId, 'error',
                RunText::reference($id), $user);
        }
        if ($evaluation['unexpected']) {
            $this->events->write(RunText::unexpectedHosts($id, array_keys($evaluation['unexpected']), (string) $run['template_name']), null, 'error',
                RunText::reference($id), $user);
        }
    }

    /**
     * The devices of the hosts the runner ran on that were not targets, with their result.
     *
     * @param  array<string, array{result: string, counts: array<string, int>|null}>  $hosts  inventory name => result
     * @return array<int, array{inventory_name: string, hostname: string, display: string, result: string, counts: array<string, int>|null}>
     */
    private function unexpectedDevices(array $hosts): array
    {
        if (! $hosts || $this->devices === null) {
            return [];
        }
        $rows = [];
        foreach (($this->devices)(array_map('strval', array_keys($hosts))) as $name => $device) {
            if (isset($hosts[$name])) {
                $rows[(int) $device['device_id']] = ['inventory_name' => (string) $name, 'hostname' => $device['hostname'], 'display' => $device['display']]
                    + $hosts[$name];
            }
        }

        return $rows;
    }

    // ------------------------------------------------------------------ watching lost and released runs

    /**
     * A lost or released run: when the runner has its task again, a late end is recorded like any end (with the
     * whole log, once it settled), and an unfinished task is followed again (its devices busy again). A run released
     * before its task was known is looked for by its launch's message (with its nonce), as a launch is.
     */
    private function watch(array $run, int $now): void
    {
        $id = (int) $run['id'];
        $found = [];
        try {
            $runner = ($this->runner)();
            if ($run['runner_task_id'] === null) {
                $task = $runner->findTask((string) $run['template_id'], RunText::taskMessage($id, (string) $run['username'], $run['launch_nonce'] ?? null),
                    strtotime((string) $run['created_at']) - self::CLOCK_SKEW)->task;
                if ($task === null) {
                    $this->runs->updateAt($id, (int) $run['version'], ['polled_at' => self::time($now)]);

                    return;
                }
                $found = ['runner_task_id' => $task->id];
            } else {
                $task = $runner->task((string) $run['runner_task_id']);
            }
            $output = RunStatus::isFinal($task->status) ? $runner->output($task->id) : null;
        } catch (RunnerError) {
            $this->runs->updateAt($id, (int) $run['version'], ['polled_at' => self::time($now)]);

            return;
        }
        if ($output === null) {
            if ($this->runs->updateAt($id, (int) $run['version'], $found + ['status' => $task->status, 'runner_status' => $task->rawStatus, 'finished_at' => null,
                'summary' => null, 'error' => null, 'watch_until' => null, 'missing_checks' => 0, 'missing_since' => null, 'runner_final_at' => null,
                'runner_seen_at' => self::time($now), 'polled_at' => self::time($now), 'started_at' => $task->started !== null ? self::time($task->started) : $run['started_at'],
                'poll_error' => "The runner has task #{$task->id}" . ($found ? '' : ' again') . ', unfinished: the plugin follows the run again (it was '
                    . strtolower(RunStatus::label((string) $run['status'])) . ').'])) {
                $this->runs->setResults($id, array_fill_keys(array_keys($this->targetNames($id)), ['result' => null, 'counts' => null]));
                foreach (array_keys($this->targetNames($id)) as $deviceId) {
                    $this->events->write(RunText::followedAgain($id, $task->id, (string) $run['status'], (string) $run['template_name'], (string) $run['username'], $found !== []),
                        $deviceId, 'warning', RunText::reference($id), (string) $run['username']);
                }
            }

            return;
        }
        // Semaphore reports the end before the last lines are readable: record it once the log has its PLAY RECAP, or a
        // while after the end
        $settled = RecapParser::parse($output->lines) !== null || ($task->ended !== null && $now - $task->ended >= self::SETTLE)
            || ($run['runner_final_at'] !== null && $now - strtotime((string) $run['runner_final_at']) >= self::SETTLE);
        if (! $settled) {
            $this->runs->updateAt($id, (int) $run['version'], $found + ['polled_at' => self::time($now), 'runner_final_at' => $run['runner_final_at'] ?? self::time($now)]);

            return;
        }
        $values = $found + OutputBuffer::replace($output->lines, $this->outputMaxBytes) + ['output_lines' => $output->next, 'runner_status' => $task->rawStatus,
            'poll_error' => null, 'runner_seen_at' => self::time($now)];
        $this->finish($run, $values, [], $task, $runner, $now, true, $output->lines);
    }

    // ------------------------------------------------------------------ users' actions

    /**
     * A user asks the runner to stop a run; null when it was asked, else why it cannot be stopped.
     */
    public function stop(int $id, string $username, int $now): ?string
    {
        $run = $this->runs->find($id);
        if ($run === null) {
            return 'There is no such run.';
        }
        if (RunStatus::isFinal($run['status'])) {
            return 'The run has already ended.';
        }
        if ($run['runner_task_id'] === null) {
            return 'The run is still being launched: try again in a moment.';
        }
        try {
            ($this->runner)()->stop((string) $run['runner_task_id']);
        } catch (RunnerError $e) {
            return $e->getMessage();
        }
        for ($attempt = 0; $attempt < 5 && $run !== null && ! RunStatus::isFinal($run['status']); $attempt++) {
            if ($this->runs->updateAt($id, (int) $run['version'], ['status' => RunStatus::STOPPING, 'stopped_by' => mb_substr($username, 0, 255),
                'stop_requested_at' => self::time($now)])) {
                break;
            }
            $run = $this->runs->find($id);
        }

        return null;
    }

    /**
     * An admin releases the devices of a run the runner could not be asked about for RELEASE_AFTER seconds: the run
     * ends as released, its results from the log as far as the plugin read it (unknown where it says nothing), and
     * it is watched for a late end. Or the devices a stopped run still holds, at any time (the admin checked that the
     * step in progress at the stop no longer runs). Null when released, else why not.
     */
    public function release(int $id, string $username, int $now): ?string
    {
        $run = $this->runs->find($id);
        if ($run === null) {
            return 'There is no such run.';
        }
        if ($run['status'] === RunStatus::STOPPED) {
            return $this->releaseHold($run, $username, $now);
        }
        if (RunStatus::isFinal($run['status'])) {
            return 'The run has already ended.';
        }
        if (! self::releasable($run, $now)) {
            return 'The plugin still gets answers about this run from the runner: wait for its end, or stop it.';
        }
        $evaluation = RunResults::evaluate($this->targetNames($id), OutputBuffer::display($run), false);
        $since = (string) ($run['runner_seen_at'] ?? $run['created_at']);
        $reason = 'the devices were released by ' . UntrustedText::line($username, 60) . ": the runner could not be asked about the run since $since ("
            . $run['poll_error'] . ')';
        if (! $this->runs->updateAt($id, (int) $run['version'], ['status' => RunStatus::RELEASED, 'released_by' => mb_substr($username, 0, 255),
            'error' => UntrustedText::line($reason, 1000), 'finished_at' => self::time($now), 'summary' => $evaluation['summary'],
            'watch_until' => self::time($now + self::WATCH_SECONDS)])) {
            return 'The run changed meanwhile: reload the page.';
        }
        $this->runs->setResults($id, $evaluation['results']);
        foreach ($evaluation['results'] as $deviceId => $result) {
            $this->events->write(RunText::finished($id, RunStatus::RELEASED, $result['result'], null, (string) $run['template_name'], (string) $run['username'],
                'devices released by ' . $username . ' while the runner could not be asked'), $deviceId, 'warning', RunText::reference($id), (string) $run['username']);
        }

        return null;
    }

    /**
     * The devices a stopped run holds become free now; written to the eventlog of each target.
     */
    private function releaseHold(array $run, string $username, int $now): ?string
    {
        if (! self::held($run, $now)) {
            return 'The devices of this run are not held any more.';
        }
        $id = (int) $run['id'];
        if (! $this->runs->updateAt($id, (int) $run['version'], ['held_until' => self::time($now), 'released_by' => mb_substr($username, 0, 255)])) {
            return 'The run changed meanwhile: reload the page.';
        }
        foreach (array_keys($this->targetNames($id)) as $deviceId) {
            $this->events->write(RunText::holdReleased($id, $username, (string) $run['held_until'], (string) $run['template_name'], (string) $run['username']),
                $deviceId, 'warning', RunText::reference($id), (string) $run['username']);
        }

        return null;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Note why the runner could not tell more this time; nothing else changes (the run keeps its devices).
     */
    private function note(array $run, int $now, string $error): void
    {
        $this->runs->updateAt((int) $run['id'], (int) $run['version'], ['poll_error' => UntrustedText::line($error, 500), 'polled_at' => self::time($now)]);
    }

    /**
     * The run's targets (not the flagged hosts it ran on besides them): device_id => inventory name.
     *
     * @return array<int, string>
     */
    private function targetNames(int $runId): array
    {
        $names = [];
        foreach ($this->runs->targets($runId) as $deviceId => $target) {
            if (empty($target['unexpected'])) {
                $names[(int) $deviceId] = (string) $target['inventory_name'];
            }
        }

        return $names;
    }

    /**
     * A time as the database keeps it.
     */
    private static function time(int $time): string
    {
        return date('Y-m-d H:i:s', $time);
    }

    /**
     * A number of seconds in hours, for messages.
     */
    private static function hours(int $seconds): string
    {
        $hours = round($seconds / 3600, 1);

        return ($hours == (int) $hours ? (int) $hours : $hours) . ($hours == 1 ? ' hour' : ' hours');
    }
}
