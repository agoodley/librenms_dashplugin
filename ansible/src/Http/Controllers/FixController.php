<?php

namespace Agoodley\LibrenmsAnsible\Http\Controllers;

use Agoodley\LibrenmsAnsible\Changes\FixAnswers;
use Agoodley\LibrenmsAnsible\Changes\TopologyError;
use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Services\TopologyVerification;
use Agoodley\LibrenmsAnsible\Support\Access;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Fixes page (phase 4): the topology plugin's findings whose fix is clear (its verification API's fixes(), as the
 * admin sees the network), by kind, each with what it changes and the findings it resolves, and those it cannot fix with
 * why; Fix opens a new change made from the chosen fixes of one kind (ChangeController::create(): the template the
 * settings map to the kind, the devices, the lines read only). LibreNMS admins; making the change needs the run
 * permission. The list is loaded after the page (on a large network the topology plugin takes some seconds).
 */
class FixController extends Controller
{
    /**
     * plugin/ansible/fixes
     */
    public function index(Request $request): View
    {
        return view('ansible::fixes', ['assetVersion' => self::assetVersion(), 'canRun' => Access::canRun($request->user()),
            'unavailable' => (new TopologyVerification())->unavailable(), 'templates' => array_map(fn (string $kind) => $this->settings()->fixTemplate($kind),
                array_combine(FixAnswers::KINDS, FixAnswers::KINDS))]);
    }

    /**
     * plugin/ansible/api/fixes: the fixes as JSON, with the template of each kind and whether it can apply it.
     */
    public function list(Request $request): JsonResponse
    {
        $verification = new TopologyVerification();
        $why = $verification->unavailable();
        if ($why !== null) {
            return response()->json(['error' => 'The topology plugin cannot list the fixes: ' . $why . '.'], 503);
        }
        try {
            $answer = $verification->fixes([], (int) $request->user()->user_id);
        } catch (TopologyError $e) {
            return response()->json(['error' => 'The topology plugin cannot list the fixes: ' . $e->getMessage() . '.'], 503);
        } catch (\Throwable $e) {
            return response()->json(['error' => $this->failure('fixes list', $e)], 500);
        }
        $names = [];
        foreach ((array) ($answer['names'] ?? []) as $deviceId => $name) {
            if (is_scalar($name) && preg_match('/^\d{1,10}$/D', (string) $deviceId)) {
                $names[(int) $deviceId] = UntrustedText::line((string) $name, 120);
            }
        }
        $name = fn ($deviceId) => $names[(int) $deviceId] ?? '#' . (int) $deviceId;
        $fixes = [];
        foreach ((array) ($answer['fixes'] ?? []) as $fix) {
            if (! is_array($fix) || ! in_array($fix['kind'] ?? null, FixAnswers::KINDS, true) || ! is_string($fix['id'] ?? null)) {
                continue;
            }
            $entry = ['id' => $fix['id'], 'kind' => $fix['kind'], 'title' => UntrustedText::line((string) ($fix['title'] ?? ''), 300),
                'devices' => array_map(fn ($id) => ['device_id' => (int) $id, 'name' => $name($id)], (array) ($fix['devices'] ?? [])),
                'findings' => array_map(fn (array $f) => ['identity' => self::identity($f['identity'] ?? null), 'severity' => (string) ($f['severity'] ?? ''),
                    'title' => UntrustedText::line((string) ($f['title'] ?? ''), 300),
                    'devices' => array_map(fn ($id) => (int) $id, (array) ($f['devices'] ?? []))], array_values(array_filter((array) ($fix['findings'] ?? []), 'is_array')))];
            if ($fix['kind'] === FixAnswers::PORT_DESCRIPTIONS) {
                $entry['ports'] = array_map(fn (array $port) => ['device' => $name($port['device_id'] ?? 0), 'interface' => UntrustedText::line((string) ($port['ifName'] ?? ''), 80),
                    'current' => UntrustedText::line((string) ($port['current'] ?? ''), 200), 'description' => UntrustedText::line((string) ($port['description'] ?? ''), 120),
                    'passes' => (bool) preg_match(FixAnswers::INTERFACE, (string) ($port['ifName'] ?? ''))], array_values(array_filter((array) ($fix['ports'] ?? []), 'is_array')));
            } else {
                $bridge = fn ($b) => is_array($b) ? ['name' => $name($b['device_id'] ?? 0), 'priority' => (int) ($b['priority'] ?? 0), 'mode' => (string) ($b['mode'] ?? ''),
                    'instances' => FixAnswers::instances((string) ($b['mode'] ?? ''), (array) ($b['instances'] ?? [])) ?? '?'] : null;
                $entry += ['vlans' => FixAnswers::vlanText((array) ($fix['vlans'] ?? [])), 'primary' => $bridge($fix['primary'] ?? null),
                    'secondary' => $bridge($fix['secondary'] ?? null), 'chosen_from' => UntrustedText::line((string) ($fix['chosen_from'] ?? ''), 80),
                    'roots_now' => implode(', ', array_unique(array_map(fn ($root) => $name($root['device_id'] ?? 0), (array) ($fix['roots_now'] ?? []))))];
            }
            $fixes[] = $entry;
        }
        $notFixable = array_map(fn (array $entry) => ['identity' => self::identity($entry['identity'] ?? null), 'title' => UntrustedText::line((string) ($entry['title'] ?? ''), 300),
            'why' => UntrustedText::line((string) ($entry['why'] ?? ''), 400),
            'devices' => array_map(fn ($id) => $name($id), (array) ($entry['devices'] ?? []))], array_values(array_filter((array) ($answer['not_fixable'] ?? []), 'is_array')));

        return response()->json(['fixes' => $fixes, 'not_fixable' => $notFixable, 'templates' => $this->templates(),
            'role_hidden' => array_values(array_filter((array) ($answer['role_hidden'] ?? []), 'is_string')), 'can_run' => Access::canRun($request->user()),
            'new_change' => route('ansible.changes.new')]);
    }

    /**
     * A finding's identity (the topology plugin's history hash, 40 hex characters), '' when it is not one: the topology
     * plugin's pages link a finding to this page by it (?finding=), which marks its fix.
     */
    private static function identity(mixed $identity): string
    {
        return is_string($identity) && preg_match('/^[0-9a-f]{40}$/D', $identity) ? $identity : '';
    }

    /**
     * The template the settings map to each kind of fix, and why it cannot apply it (the contract's survey variable), as the
     * runner has it now.
     *
     * @return array<string, array{id: ?string, name: ?string, problem: ?string}>
     */
    private function templates(): array
    {
        $templates = [];
        $runner = null;
        foreach (FixAnswers::KINDS as $kind) {
            $id = $this->settings()->fixTemplate($kind);
            if ($id === null) {
                $templates[$kind] = ['id' => null, 'name' => null, 'problem' => 'no template is set: an admin sets it in the plugin\'s settings (Verification and fixes)'];

                continue;
            }
            try {
                $plugin = new Plugin();
                $runner ??= $plugin->runner($plugin->connection());
                $template = $runner->template($id);
                $templates[$kind] = ['id' => $id, 'name' => UntrustedText::line($template->name, 120), 'problem' => FixAnswers::templateProblem($template, $kind)];
            } catch (RunnerError $e) {
                $templates[$kind] = ['id' => $id, 'name' => null, 'problem' => 'the runner cannot give template #' . $id . ' (' . UntrustedText::line($e->getMessage(), 200) . ')'];
            }
        }

        return $templates;
    }
}
