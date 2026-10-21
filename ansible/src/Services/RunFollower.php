<?php

namespace Agoodley\LibrenmsAnsible\Services;

use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Runner\Runner;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runner\RunnerTask;
use Agoodley\LibrenmsAnsible\Runs\OutputBuffer;
use Agoodley\LibrenmsAnsible\Runs\RecapParser;
use Agoodley\LibrenmsAnsible\Runs\RunResults;
use Agoodley\LibrenmsAnsible\Runs\RunStatus;
use Agoodley\LibrenmsAnsible\Runs\RunText;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Follows a run on the runner until it ends: its status, its output (appended, capped), and at the end the
 * per-target results from the PLAY RECAP and the eventlog of every target. The run page and the sync command
 * both call it: a lock per run keeps them from refreshing the same run at once, a run is asked at most once
 * every two seconds, and only one of them can finish it. Semaphore reports a task as finished before the last
 * lines of its output are readable, so a run ends once a refresh after that brings no new lines.
 */
final class RunFollower
{
    /** Least seconds between two refreshes of a run from the runner. */
    public const INTERVAL = 2;

    /** Seconds after the runner's end at which the output is taken as complete anyway. */
    private const SETTLE = 60;

    /** Seconds a run may stay recorded without a runner task (the launch never completed). */
    private const LAUNCH_TIMEOUT = 600;

    public function __construct(
        private readonly Plugin $plugin,
        private readonly RunRepository $runs,
        private readonly PluginSettings $settings,
        private readonly Eventlog $eventlog = new Eventlog(),
        private readonly ?Runner $runner = null,
    ) {
    }

    /**
     * Refresh an unfinished run from the runner, unless it was refreshed less than INTERVAL seconds ago (or another
     * request is refreshing it). Returns the run as stored afterwards, null when there is no such run.
     */
    public function refresh(int $runId, bool $throttle = true): ?array
    {
        $run = $this->runs->find($runId);
        if ($run === null || RunStatus::isFinal($run['status'])) {
            return $run;
        }
        $lock = Cache::lock("ansible-plugin-run-$runId", 60);
        if (! $lock->get()) {
            return $run;
        }
        try {
            $run = $this->runs->find($runId);
            if ($run !== null && ! RunStatus::isFinal($run['status']) && (! $throttle || self::due($run))) {
                $this->follow($run);
                $run = $this->runs->find($runId);
            }
        } catch (\Throwable $e) {
            Log::error("ansible plugin: following run #$runId failed: " . $e->getMessage());
        } finally {
            $lock->release();
        }

        return $run;
    }

    /**
     * Is the run's last refresh old enough?
     */
    private static function due(array $run): bool
    {
        return $run['polled_at'] === null || time() - strtotime((string) $run['polled_at']) >= self::INTERVAL;
    }

    /**
     * Has the runner reported the run finished, but its output may still be incomplete (refresh it again soon)?
     */
    public static function settling(array $run): bool
    {
        return ! RunStatus::isFinal($run['status']) && $run['runner_final_at'] !== null;
    }

    /**
     * One refresh: status, new output lines, and the end when the run is over.
     */
    private function follow(array $run): void
    {
        $id = (int) $run['id'];
        $age = time() - strtotime((string) $run['created_at']);
        if ($run['runner_task_id'] === null) {
            if ($age > self::LAUNCH_TIMEOUT) {
                $this->lose($run, 'the launch did not complete: no task of the runner was recorded');
            }

            return;
        }
        try {
            $runner = $this->runner ?? $this->plugin->runner();
            $task = $runner->task((string) $run['runner_task_id']);
            $output = $runner->output((string) $run['runner_task_id'], (int) $run['output_lines']);
        } catch (RunnerError $e) {
            if ($e->kind === RunnerError::NOT_FOUND) {
                $this->lose($run, 'the runner no longer has task #' . $run['runner_task_id']);
            } elseif ($age > $this->settings->lostAfterSeconds()) {
                $this->lose($run, 'still unfinished after ' . $this->settings->int('lost_after_hours') . ' hours, and the runner cannot be asked: ' . $e->getMessage());
            } else {
                $this->runs->update($id, ['poll_error' => UntrustedText::line($e->getMessage(), 500), 'polled_at' => now()]);
            }

            return;
        }

        $buffer = OutputBuffer::append((string) $run['output'], $output->lines, $this->settings->outputMaxBytes(), (bool) $run['output_truncated']);
        $values = ['output' => $buffer['text'], 'output_truncated' => $buffer['truncated'], 'output_lines' => $output->next,
            'runner_status' => $task->rawStatus, 'poll_error' => null, 'polled_at' => now(),
            'started_at' => $task->started !== null ? date('Y-m-d H:i:s', $task->started) : $run['started_at']];

        if (! RunStatus::isFinal($task->status)) {
            // a requested stop stays "stopping" until the runner says how the task ended
            $values['status'] = $run['status'] === RunStatus::STOPPING ? RunStatus::STOPPING : $task->status;
            if ($age > $this->settings->lostAfterSeconds()) {
                $this->runs->update($id, $values);
                $this->lose($run, 'still unfinished after ' . $this->settings->int('lost_after_hours') . ' hours (Lost after setting)');

                return;
            }
            $this->runs->update($id, $values);

            return;
        }
        $settled = ($run['runner_final_at'] !== null && ! $output->lines)
            || ($task->ended !== null && time() - $task->ended >= self::SETTLE)
            || ($run['runner_final_at'] !== null && time() - strtotime((string) $run['runner_final_at']) >= self::SETTLE);
        if (! $settled) {
            $values['runner_final_at'] = $run['runner_final_at'] ?? now();
            $this->runs->update($id, $values);

            return;
        }
        $this->finish($run, $values, $task, $runner);
    }

    /**
     * The run is over: per-target results from the PLAY RECAP, the summary, and the eventlog of each target (and
     * of the run, when the runner ran on hosts that were not targets). The PLAY RECAP is read from the stored
     * output, or from the runner's whole log when the stored one was capped.
     *
     * @param  array<string, mixed>  $values  the columns this refresh writes
     */
    private function finish(array $run, array $values, RunnerTask $task, Runner $runner): void
    {
        $id = (int) $run['id'];
        $lines = $values['output_truncated'] ? $runner->output((string) $run['runner_task_id'])->lines : OutputBuffer::lines((string) $values['output']);
        $targets = $this->runs->targets($id);
        $combined = RunResults::combine(array_map(fn ($t) => (string) $t['inventory_name'], $targets), RecapParser::parse($lines),
            RecapParser::connectionFailures($lines));
        $values += ['status' => $task->status, 'summary' => $combined['summary'], 'unexpected_hosts' => $combined['unexpected'] ?: null,
            'finished_at' => $task->ended !== null ? date('Y-m-d H:i:s', $task->ended) : now()];
        if (! $this->runs->updateUnfinished($id, $values)) {
            return; // another process finished it
        }
        $this->runs->setResults($id, $combined['results']);
        $note = $task->status === RunStatus::STOPPED && $run['stopped_by'] ? 'stopped by ' . $run['stopped_by'] : null;
        foreach ($combined['results'] as $deviceId => $result) {
            $this->eventlog->write(RunText::finished($id, $task->status, $result['result'], $result['counts'], (string) $run['template_name'],
                (string) $run['username'], $note), $deviceId, RunResults::SEVERITY[$result['result']] ?? 'info', RunText::reference($id));
        }
        if ($combined['unexpected']) {
            $this->eventlog->write(RunText::unexpectedHosts($id, $combined['unexpected'], (string) $run['template_name']), null, 'error', RunText::reference($id));
        }
    }

    /**
     * Give a run up as lost: its targets did not run as far as the plugin knows.
     */
    private function lose(array $run, string $reason): void
    {
        $id = (int) $run['id'];
        $targets = $this->runs->targets($id);
        if (! $this->runs->updateUnfinished($id, ['status' => RunStatus::LOST, 'error' => UntrustedText::line($reason, 1000), 'finished_at' => now(),
            'summary' => [RunResults::NOT_RUN => count($targets)]])) {
            return;
        }
        $this->runs->setResults($id, array_fill_keys(array_keys($targets), ['result' => RunResults::NOT_RUN, 'counts' => null]));
        foreach (array_keys($targets) as $deviceId) {
            $this->eventlog->write(RunText::finished($id, RunStatus::LOST, RunResults::NOT_RUN, null, (string) $run['template_name'], (string) $run['username'], $reason),
                $deviceId, 'warning', RunText::reference($id));
        }
    }

    /**
     * Ask the runner to stop a run; null when it was asked, else why it cannot be stopped.
     */
    public function stop(int $runId, string $username): ?string
    {
        $run = $this->runs->find($runId);
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
            ($this->runner ?? $this->plugin->runner())->stop((string) $run['runner_task_id']);
        } catch (RunnerError $e) {
            return $e->getMessage();
        }
        $this->runs->updateUnfinished($runId, ['status' => RunStatus::STOPPING, 'stopped_by' => mb_substr($username, 0, 255)]);

        return null;
    }
}
