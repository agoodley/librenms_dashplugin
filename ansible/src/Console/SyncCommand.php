<?php

namespace Agoodley\LibrenmsAnsible\Console;

use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\DeviceRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Services\ChangeFollower;
use Agoodley\LibrenmsAnsible\Services\Eventlog;
use Agoodley\LibrenmsAnsible\Services\InventoryService;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Services\RunFollower;
use Agoodley\LibrenmsAnsible\Support\Format;
use Illuminate\Console\Command;

/**
 * ansible:sync, every minute through the LibreNMS scheduler: follows every unfinished run on the runner to its
 * end (status, output, results, eventlog), so the audit log is complete when nobody watches the run page, and asks
 * about lost and released runs every few minutes for a week, so a later real end is recorded (Runs\RunLifecycle).
 * Then it moves every change on (Changes\ChangeLifecycle): the end of its dry run, the expiry of its dry run and
 * approval, its batches and their verification (it alone takes their states), and the maintenance windows and backups it
 * still follows, also of changes that ended.
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
            $this->warn('The Ansible plugin\'s tables are missing or out of date: run ./lnms migrate.');

            return self::SUCCESS;
        }
        $settings = Plugin::settings();
        $follower = new RunFollower(new Plugin(), $runs, $settings, new Eventlog(), null, new InventoryService(new DeviceRepository(), $settings));
        foreach ($runs->watched() as $id) {
            $follower->refresh($id);
        }
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
        $changes = new ChangeRepository();
        $followed = 0;
        if ($changes->available()) {
            // the sync command takes the states of the batches' verification (each in a process of its own: ansible:state)
            $follower = new ChangeFollower(new Plugin(), $runs, $changes, $settings, new InventoryService(new DeviceRepository(), $settings), takesStates: true);
            foreach ($changes->pendingIds() as $id) {
                $follower->refresh($id, false);
                $followed++;
            }
        } else {
            $this->warn('The Ansible plugin\'s tables of changes are missing: run ./lnms migrate.');
        }
        $this->line(date('Y-m-d H:i:s T') . ' ansible:sync: ' . Format::count(count($ids), 'unfinished run') . ' followed, ' . $left . ' still unfinished; '
            . Format::count($followed, 'change') . ' followed');

        return self::SUCCESS;
    }
}
