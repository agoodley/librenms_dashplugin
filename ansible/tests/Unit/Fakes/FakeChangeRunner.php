<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit\Fakes;

use Agoodley\LibrenmsAnsible\Changes\ChangeRunner;
use Agoodley\LibrenmsAnsible\Changes\TemplateSnapshot;
use Agoodley\LibrenmsAnsible\Runner\RunnerEnvironment;
use Agoodley\LibrenmsAnsible\Runner\RunnerInventory;
use Agoodley\LibrenmsAnsible\Runner\RunnerRepository;
use Agoodley\LibrenmsAnsible\Tests\Unit\TemplateCheckTest;

/**
 * The plugin's services as the change lifecycle sees them: launches record runs in a MemoryRunStore (one per token, as
 * the launcher does), the template's snapshot is the test's, users and other runs as the test sets them. Calls are
 * recorded, the secrets given to each launch too (to check they are passed and never kept).
 */
final class FakeChangeRunner implements ChangeRunner
{
    /** @var array<string, mixed> the template's snapshot the runner gives (TemplateSnapshot::parts()) */
    public array $snapshot;

    public ?string $snapshotError = null;

    /** @var string[] errors a launch answers with (nothing launched) */
    public array $launchErrors = [];

    /** @var array<int, string> user id => why they may not run or approve */
    public array $userProblems = [];

    /** @var array<int, int> device => run that changed it since the dry run */
    public array $changed = [];

    /** @var array<int, string[]|null> run id => whole log (null: unreadable) */
    public array $logs = [];

    /** @var array<string, int> "change:kind:number" => run id */
    public array $tokens = [];

    /** @var array<int, array{kind: string, number: int, devices: int[], user: string, secrets: array, commit: ?string}> */
    public array $launches = [];

    /** @var array<int, string> runs asked to stop */
    public array $stops = [];

    public ?string $stopError = null;

    /** The commit the runner reports for the runs it launches (pinned ones report the pin). */
    public ?string $commit = 'abc1234';

    /** @var array<int, array<string, mixed>> device => how the inventory reaches it now (Connections::of()); devices not set get ansible_host 10.0.0.<id % 256> */
    public array $connections = [];

    /** @var \Closure|null called right before a launch's check (to change the inventory at that moment) */
    public ?\Closure $beforeVerify = null;

    public function __construct(private readonly MemoryRunStore $runs, private readonly array $inventoryNames)
    {
        $this->snapshot = self::snapshotOf();
    }

    /**
     * A template's snapshot, with some fields of the template replaced.
     */
    public static function snapshotOf(array $template = []): array
    {
        $env = ['LIBRENMS_URL' => 'https://l', 'ANSIBLE_CHECK_MODE_MARKERS' => 'true'];

        return TemplateSnapshot::parts('semaphore', 8, TemplateCheckTest::template($template + ['environmentIds' => ['13']]),
            [new RunnerEnvironment('13', 'LibreNMS', array_keys($env), ['LIBRENMS_TOKEN'], $env)],
            new RunnerInventory('12', 'LibreNMS', 'file', 'inventory/librenms_inventory.py'), new RunnerRepository('9', 'r', 'file:///repos/r.git', 'main'));
    }

    public function launch(array $change, string $kind, int $number, array $deviceIds, array $user, #[\SensitiveParameter] array $secrets, \Closure $verify): array
    {
        $token = "{$change['id']}:$kind:$number";
        if (isset($this->tokens[$token])) {
            return ['run_id' => $this->tokens[$token], 'errors' => [], 'parts' => $this->snapshot, 'connections' => null];
        }
        if ($this->launchErrors) {
            return ['run_id' => null, 'errors' => $this->launchErrors, 'parts' => null, 'connections' => null];
        }
        if ($this->beforeVerify !== null) {
            ($this->beforeVerify)($kind, $number);
        }
        $connections = $this->connections($deviceIds);
        $refusal = $verify($this->snapshot, $connections);
        if ($refusal !== null) {
            return ['run_id' => null, 'errors' => ["The launch was refused: $refusal."], 'parts' => null, 'connections' => $connections];
        }
        $pin = $kind === 'batch' ? $change['dry_run_commit'] : null;
        $runId = $this->runs->add(['status' => 'queued', 'runner_task_id' => (string) (900 + count($this->runs->runs)), 'username' => $user['username'],
            'commit_hash' => $pin ?? $this->commit, 'change_id' => (int) $change['id'], 'kind' => $kind, 'batch' => $number],
            array_intersect_key($this->inventoryNames, array_flip($deviceIds)));
        $this->tokens[$token] = $runId;
        $this->launches[] = ['kind' => $kind, 'number' => $number, 'devices' => $deviceIds, 'user' => $user['username'], 'secrets' => $secrets, 'commit' => $pin];

        return ['run_id' => $runId, 'errors' => [], 'parts' => $this->snapshot, 'connections' => $connections];
    }

    /** @var string|null what a preflight answers before $verify (a target refused, the runner unreachable...) */
    public ?string $preflightError = null;

    /** Number of preflights. */
    public int $preflights = 0;

    public function preflight(array $change, string $kind, int $number, array $deviceIds, \Closure $verify): ?string
    {
        $this->preflights++;
        if ($this->preflightError !== null) {
            return $this->preflightError;
        }
        if ($this->snapshotError !== null) {
            return 'The template cannot be read completely from the runner: ' . $this->snapshotError . '.';
        }
        $refusal = $verify($this->snapshot, $this->connections($deviceIds));

        return $refusal !== null ? "The launch was refused: $refusal." : null;
    }

    public function connections(array $deviceIds): array
    {
        $connections = [];
        foreach ($deviceIds as $deviceId) {
            $connections[(int) $deviceId] = $this->connections[(int) $deviceId] ?? ['ansible_connection' => 'ansible.netcommon.network_cli',
                'ansible_host' => '10.0.0.' . ((int) $deviceId % 256), 'ansible_network_os' => 'cisco.ios.ios'];
        }
        ksort($connections);

        return $connections;
    }

    public function findRun(int $changeId, string $kind, int $number): ?int
    {
        return $this->tokens["$changeId:$kind:$number"] ?? null;
    }

    public function snapshot(array $change): array
    {
        return $this->snapshotError !== null ? ['parts' => null, 'error' => $this->snapshotError] : ['parts' => $this->snapshot, 'error' => null];
    }

    public function wholeLog(int $runId): ?array
    {
        return array_key_exists($runId, $this->logs) ? $this->logs[$runId] : explode("\n", (string) ($this->runs->runs[$runId]['output'] ?? ''));
    }

    public function stopRun(int $runId, string $username): ?string
    {
        $this->stops[] = "$runId by $username";
        if ($this->stopError !== null) {
            return $this->stopError;
        }
        $this->runs->runs[$runId]['status'] = 'stopping';
        $this->runs->runs[$runId]['version']++;

        return null;
    }

    public function userProblem(int $userId, string $permission): ?string
    {
        return $this->userProblems[$userId] ?? null;
    }

    public function changedSince(array $deviceIds, int $since, int $changeId): array
    {
        return array_intersect_key($this->changed, array_flip($deviceIds));
    }

    /** @var array<int, bool> run id => proven a dry run */
    public array $proofs = [];

    public function recordProof(int $runId, bool $verified): void
    {
        $this->proofs[$runId] = $verified;
    }
}
