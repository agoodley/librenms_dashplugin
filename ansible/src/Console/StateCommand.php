<?php

namespace Agoodley\LibrenmsAnsible\Console;

use Agoodley\LibrenmsAnsible\Services\TopologyVerification;
use Agoodley\LibrenmsAnsible\Support\ErrorText;
use Agoodley\LibrenmsAnsible\Support\MemoryLimit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ansible:state, the process of its own in which the sync command takes the scope or the state of a batch's devices
 * with the topology plugin's verification API (Services\TopologyVerification): the request on its input as JSON
 * ({what: scope or state, user_id, devices, cover}), the answer on its output as JSON (the API's answer, or {error}).
 * On a large network a state takes a few hundred MB for some seconds: they are this process's, never the sync
 * command's. Not meant to be run by hand.
 */
class StateCommand extends Command
{
    protected $signature = 'ansible:state';

    protected $description = 'Take the scope or the state of a batch for the Ansible plugin\'s verification (used by ansible:sync)';

    /** Memory of the process when LibreNMS sets no php_memory_limit (MB). */
    private const MEMORY_MB = 2048;

    public function handle(): int
    {
        $request = json_decode((string) stream_get_contents(STDIN), true);
        if (! is_array($request) || ! in_array($request['what'] ?? null, ['scope', 'state'], true) || ! is_int($request['user_id'] ?? null) || ! is_array($request['devices'] ?? null)) {
            return $this->answer(['error' => 'not a request of the sync command']);
        }
        MemoryLimit::raise(self::MEMORY_MB);
        try {
            $api = TopologyVerification::API;
            if (! class_exists($api)) {
                return $this->answer(['error' => 'the topology plugin is not installed']);
            }
            $user = User::find($request['user_id']);
            if ($user === null) {
                return $this->answer(['error' => 'the LibreNMS user #' . $request['user_id'] . ' no longer exists']);
            }
            $verification = $api::forUser($user);
            $cover = (array) ($request['cover'] ?? []);
            $answer = $request['what'] === 'scope' ? $verification->scope($request['devices'])
                : $verification->state($request['devices'], ['include' => (array) ($cover['include'] ?? []), 'domain' => (array) ($cover['domain'] ?? [])]);
        } catch (\Throwable $e) {
            Log::error('ansible plugin: ansible:state could not take the ' . $request['what'] . ': ' . ErrorText::forLog($e));

            return $this->answer(['error' => is_a($e, 'Agoodley\\LibrenmsTopology\\Api\\PlanningUnavailable') ? $e->getMessage() : 'see logs/librenms.log']);
        }

        return $this->answer($answer);
    }

    private function answer(array $answer): int
    {
        $this->getOutput()->write((string) json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), false,
            \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);

        return isset($answer['error']) ? self::FAILURE : self::SUCCESS;
    }
}
