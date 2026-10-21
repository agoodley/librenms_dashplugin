<?php

namespace Agoodley\LibrenmsAnsible\Console;

use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Services\RunFollower;
use Illuminate\Console\Command;

/**
 * ansible:sync, every minute through the LibreNMS scheduler: follows every unfinished run on the runner to its
 * end (status, output, results, eventlog), so the audit log is complete when nobody watches the run page. A run
 * whose task the runner no longer has, or that stays unfinished past the Lost after setting, ends as lost.
 */
class SyncCommand extends Command
{
    protected $signature = 'ansible:sync';

    protected $description = 'Follow the unfinished runs of the Ansible plugin on the runner to their end';

    /**
     * Refresh each unfinished run once; runs the runner just reported finished are refreshed again a few seconds
     * later, when their output is complete.
     */
    public function handle(): int
    {
        $runs = new RunRepository();
        if (! $runs->available()) {
            $this->warn('The Ansible plugin\'s tables are missing: run ./lnms migrate.');

            return self::SUCCESS;
        }
        $follower = new RunFollower(new Plugin(), $runs, Plugin::settings());
        $ids = $runs->unfinished();
        $settling = [];
        foreach ($ids as $id) {
            $run = $follower->refresh($id);
            if ($run !== null && RunFollower::settling($run)) {
                $settling[] = $id;
            }
        }
        if ($settling) {
            sleep(RunFollower::INTERVAL + 1);
            foreach ($settling as $id) {
                $follower->refresh($id);
            }
        }
        $left = count($runs->unfinished());
        $this->line(sprintf('%s ansible:sync: %d unfinished run(s) followed, %d still unfinished', date('Y-m-d H:i:s'), count($ids), $left));

        return self::SUCCESS;
    }
}
