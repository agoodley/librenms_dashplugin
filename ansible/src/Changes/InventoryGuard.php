<?php

namespace Agoodley\LibrenmsAnsible\Changes;

use Agoodley\LibrenmsAnsible\Inventory\Inventory;
use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Runs\EventSink;

/**
 * What the inventory endpoint serves the runner while changes run. A launch checks how the runner reaches each target
 * (Connections) right before it asks the runner, but the runner fetches the inventory later, when the task really
 * starts: Semaphore UI runs a template's tasks one after another, so a batch may wait behind another task, and then its
 * checkout. An address edited in between would send the batch to another device. So each fetch compares the hosts of
 * the dry runs and batches in progress with the connections recorded at the dry run: a host whose connection is not
 * that one is left out (the runner cannot reach it; its new address is never served, it may be another device's), the
 * change's timeline and the device's eventlog say so the first time, and what the fetch served each target is recorded
 * (the hash of its connection, or that it was left out), so the end of the dry run or batch checks what the runner got
 * rather than LibreNMS as it is by then. A dry run whose launch has not recorded its connections yet is only recorded.
 * The run's limit names each target by the inventory name it had at the launch, so names are checked too: a target
 * whose inventory name changed is left out, and so is another device that carries a target's launch-time name (the
 * limit would reach it instead), for instance one added under the hostname of a target that was renamed or deleted.
 */
final class InventoryGuard
{
    /** What a target left out of a fetch is marked (it stays so until its next dry run or batch). */
    public const LEFT_OUT = 'left_out';

    /** What a target is marked when fetches served it different connections (before its dry run recorded one). */
    public const MIXED = 'mixed';

    /** Why the Inventory page lists a host left out by the guard. */
    public const REASON = 'withheld';

    public function __construct(private readonly ChangeStore $changes, private readonly ?EventSink $events = null)
    {
    }

    /**
     * Each target in progress as the inventory has it now: its change, phase (dry_run or batch) and batch, the hash of
     * its connection, or why it must be left out (note: "lab-sw5: ansible_host 10.0.0.5 → 10.0.0.6", "lab-sw5 is no
     * longer a host of the inventory (...)", "lab-sw5's inventory name changed (...)"), and the other device that
     * carries its launch-time inventory name now, if one does (holder: left out too).
     *
     * @return array<int, array{change: array, phase: string, batch: ?array, device_id: int, target: array, hash: ?string, note: ?string, holder: ?int}>
     */
    public function check(Inventory $inventory): array
    {
        $found = [];
        $holders = array_flip($inventory->names()); // inventory name => the device that carries it now
        foreach ($this->changes->inProgress() as $entry) {
            $change = $entry['change'];
            // a dry run records its connections once its launch returns: until then nothing is compared, only recorded
            $recorded = $entry['phase'] === 'batch' || ($change['dry_run_state'] ?? null) === 'running' ? Connections::recordedIn($change) : null;
            $names = array_map(fn (array $target) => (string) (($target['display'] ?? '') ?: ($target['hostname'] ?? '') ?: ($target['inventory_name'] ?? '')),
                $entry['targets']);
            foreach (Connections::of($inventory, array_keys($entry['targets'])) as $deviceId => $vars) {
                // the run's limit holds the name the target had at the launch: it must still be that device's, and only its
                $limitName = (string) ($entry['targets'][$deviceId]['inventory_name'] ?? '');
                $holder = $limitName !== '' && isset($holders[$limitName]) && (int) $holders[$limitName] !== $deviceId ? (int) $holders[$limitName] : null;
                $current = $inventory->nameOf($deviceId);
                $name = UntrustedText::line((string) ($names[$deviceId] ?? "device #$deviceId"), 80);
                $note = null;
                if ($holder !== null) {
                    $note = "another device (#$holder) now carries $name's inventory name " . UntrustedText::line($limitName, 120);
                } elseif ($current !== null && $current !== $limitName) {
                    $note = "$name's inventory name changed (" . UntrustedText::line($limitName, 120) . ' → ' . UntrustedText::line($current, 120) . ')';
                } elseif (isset($vars[Connections::NOT_A_HOST])) {
                    $note = Connections::differences([], [$deviceId => $vars], $names)[0] ?? null;
                } elseif ($recorded !== null) {
                    $note = Connections::differences($recorded, [$deviceId => $vars], $names)[0] ?? null;
                }
                $found[] = ['change' => $change, 'phase' => $entry['phase'], 'batch' => $entry['batch'], 'device_id' => $deviceId,
                    'target' => $entry['targets'][$deviceId], 'hash' => $note === null ? Connections::hash($vars) : null, 'note' => $note, 'holder' => $holder];
            }
        }

        return $found;
    }

    /**
     * The inventory without the hosts check() leaves out, which are listed as left out with why (the Inventory page shows
     * what the runner would get now).
     *
     * @param  array<int, array>|null  $checked  check()'s answer, when already asked
     */
    public function filter(Inventory $inventory, ?array $checked = null): Inventory
    {
        $leave = [];
        foreach ($checked ?? $this->check($inventory) as $entry) {
            if ($entry['note'] !== null && $inventory->nameOf($entry['device_id']) !== null) {
                $leave[$entry['device_id']] = ['reason' => self::REASON, 'text' => self::during($entry) . ' is in progress on it, and the runner no longer reaches it as '
                    . ($entry['phase'] === 'batch' ? 'at the dry run' : 'when the dry run was launched') . ' (' . $entry['note'] . '): the runner does not get it meanwhile'];
            }
            if (($entry['holder'] ?? null) !== null) {
                // the device that carries a target's name in the run's limit now: the run would reach it instead
                $leave[$entry['holder']] = ['reason' => self::REASON, 'text' => 'it carries the inventory name ' . UntrustedText::line((string) $entry['target']['inventory_name'], 120)
                    . ' of a target of ' . self::during($entry) . ', which is in progress: the runner does not get it meanwhile'];
            }
        }

        return $leave ? $inventory->without($leave) : $inventory;
    }

    /**
     * The inventory to serve the runner now (filter()), with what this fetch served each target in progress recorded;
     * for a fetch of one host's variables ($host: --host), that host's only. A target left out for the first time is said
     * in its change's timeline (its batch's note) and in the device's eventlog.
     */
    public function serve(Inventory $inventory, int $now, ?string $host = null): Inventory
    {
        $checked = $this->check($inventory);
        foreach ($checked as $entry) {
            $deviceId = $entry['device_id'];
            if ($host !== null && $host !== (string) ($entry['target']['inventory_name'] ?? '') && $host !== $inventory->nameOf($deviceId)) {
                continue;
            }
            $changeId = (int) $entry['change']['id'];
            if ($this->changes->served($changeId, $deviceId, $entry['hash'], $entry['note'], $now)) {
                $this->leftOut($entry, $now);
            }
        }

        return $this->filter($inventory, $checked);
    }

    /**
     * A target left out of a fetch for the first time: said in the change's timeline, its batch's note and the device's
     * eventlog.
     */
    private function leftOut(array $entry, int $now): void
    {
        $change = $entry['change'];
        $changeId = (int) $change['id'];
        $note = UntrustedText::line((string) $entry['note'], 400);
        $display = UntrustedText::line((string) (($entry['target']['display'] ?? '') ?: ($entry['target']['hostname'] ?? '')), 80);
        if ($entry['phase'] === 'batch') {
            $number = (int) $entry['batch']['number'];
            // the plugin cannot tell which task fetched the inventory: the batch's own task, or another one meanwhile
            $this->changes->addEvent($changeId, 'batch', "The plugin left $display out of an inventory the runner fetched during batch $number: the runner no "
                . "longer reaches it as at the dry run ($note). The batch may not run on it, and the change stops when the batch ends.", null, $now);
            $this->changes->updateBatch($changeId, $number, ['note' => UntrustedText::line("$display left out of an inventory the runner fetched ($note)", 500)]);
            $text = ChangeText::name($change) . ", batch $number: left out of an inventory the runner fetched, as the runner no longer reaches it as at the dry run ($note)";
            $user = (string) (($entry['batch']['username'] ?? null) ?: (($change['applied_by'] ?? null) ?: $change['username']));
        } else {
            $this->changes->addEvent($changeId, 'dry_run', "The plugin left $display out of an inventory the runner fetched during the dry run: the runner no longer "
                . "reaches it as when the dry run was launched ($note). The dry run may not run on it, so it will not count.", null, $now);
            $text = ChangeText::name($change) . ': left out of an inventory the runner fetched during its dry run, as the runner no longer reaches it as when the dry '
                . "run was launched ($note)";
            $user = (string) $change['username'];
        }
        $this->events?->write($text, $entry['device_id'], 'warning', ChangeText::reference($changeId), $user);
    }

    /**
     * "Change #12's batch 2", "Change #12's dry run".
     */
    private static function during(array $entry): string
    {
        return 'change #' . (int) $entry['change']['id'] . '\'s ' . ($entry['phase'] === 'batch' ? 'batch ' . (int) $entry['batch']['number'] : 'dry run');
    }
}
