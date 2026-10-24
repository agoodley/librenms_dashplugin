<?php

namespace Agoodley\LibrenmsAnsible\Http\Controllers;

use Agoodley\LibrenmsAnsible\Changes\FixAnswers;
use Agoodley\LibrenmsAnsible\Changes\FixChange;
use Agoodley\LibrenmsAnsible\Changes\FixFilter;
use Agoodley\LibrenmsAnsible\Changes\TopologyError;
use Agoodley\LibrenmsAnsible\Data\ChangeRepository;
use Agoodley\LibrenmsAnsible\Data\DeviceRepository;
use Agoodley\LibrenmsAnsible\Data\RunRepository;
use Agoodley\LibrenmsAnsible\Inventory\UntrustedText;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Services\TopologyVerification;
use Agoodley\LibrenmsAnsible\Support\Access;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The Fixes page (phase 4): the topology plugin's findings whose fix is clear (its verification API's fixes(), as the
 * admin sees the network), by kind, each with what it changes and the findings it resolves, and those it cannot fix with
 * why; Fix opens a new change made from the chosen fixes of one kind (ChangeController::create(): the template the
 * settings map to the kind, the devices, the lines read only). A fix of a device that cannot be a target (the target
 * check of New change: outside the allowed device group, busy in another run...) cannot be chosen, with why, and the
 * fixes chosen change at most Max targets per change devices. LibreNMS admins with the run permission, as New change.
 * The list is loaded after the page (on a large network the topology plugin takes some seconds), of every
 * device the admin sees or, as the topology plugin's pages filter, of a device, a device group, a location (each one
 * given narrows it): the topology plugin then computes the fixes of those devices only.
 */
class FixController extends Controller
{
    /** The cache lock of an admin's computation of the fixes (with their user id). */
    public const LOCK = 'ansible-plugin-fixes-';

    /** The longest a computation holds it (a crashed request's lock ends then). */
    private const LOCK_SECONDS = 300;

    /**
     * plugin/ansible/fixes[?device=&group=&location=]
     */
    public function index(Request $request): View
    {
        $filter = FixFilter::parse($request->query());
        $devices = new DeviceRepository();
        // the device of the filter by its name, when the admin sees it
        $device = $filter['device'] ?? null;
        $deviceName = $device !== null ? (isset($devices->visibleTo($request->user())[$device]) ? ($devices->names([$device])[$device] ?? null) : null) ?? "#$device" : null;

        return view('ansible::fixes', ['assetVersion' => self::assetVersion(),
            'unavailable' => (new TopologyVerification())->unavailable(), 'templates' => array_map(fn (string $kind) => $this->settings()->fixTemplate($kind),
                array_combine(FixAnswers::KINDS, FixAnswers::KINDS)),
            'filter' => $filter ?? [], 'filterInvalid' => $filter === null, 'groups' => $devices->deviceGroups(), 'locations' => $devices->locations(),
            'deviceName' => $deviceName]);
    }

    /**
     * plugin/ansible/api/fixes[?device=&group=&location=]: the fixes as JSON (of the devices the filter keeps, those the admin
     * sees), with the template of each kind and whether it can apply it.
     */
    public function list(Request $request): JsonResponse
    {
        $filter = FixFilter::parse($request->query());
        if ($filter === null) {
            return response()->json(['error' => 'The filter is not valid: a device, a device group and a location are given by their number.'], 422);
        }
        $verification = new TopologyVerification();
        $why = $verification->unavailable();
        if ($why !== null) {
            return response()->json(['error' => 'The topology plugin cannot list the fixes: ' . $why . '.'], 503);
        }
        // one computation of the fixes per admin at a time: of the whole network it takes some seconds and some hundred MB
        $lock = Cache::lock(self::LOCK . (int) $request->user()->user_id, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return response()->json(['error' => 'The fixes are being computed for you already (in another page or tab): wait for it, then load this page again.'], 429);
        }
        try {
            return $this->listFor($request, $verification, $filter);
        } finally {
            $lock->release();
        }
    }

    /**
     * The fixes of the devices the filter keeps (list()), as JSON.
     *
     * @param  array<string, int>  $filter
     */
    private function listFor(Request $request, TopologyVerification $verification, array $filter): JsonResponse
    {
        $ids = [];
        if ($filter) {
            $devices = new DeviceRepository();
            $ids = FixFilter::keep($filter, $devices->devices(), isset($filter['group']) ? $devices->memberships() : [], $devices->visibleTo($request->user()));
        }
        if ($filter && ! $ids) {
            return response()->json(['fixes' => [], 'not_fixable' => [], 'templates' => $this->templates(), 'role_hidden' => [], 'can_run' => Access::canRun($request->user()),
                'new_change' => route('ansible.changes.new'), 'filter' => $filter, 'devices' => 0, 'max_targets' => $this->settings()->maxTargets()]);
        }
        try {
            // the fixes of the devices the filter keeps, computed for them (more than the topology plugin takes at once: of every device,
            // then kept for them as it keeps them)
            $scoped = count($ids) <= FixFilter::MAX_DEVICES;
            $answer = $verification->fixes($scoped ? $ids : [], (int) $request->user()->user_id);
            if (! $scoped) {
                $answer['fixes'] = FixFilter::ofDevices((array) ($answer['fixes'] ?? []), $ids);
            }
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
        // the target check of New change, for the devices of the fixes: a fix of a device that cannot be a target cannot be chosen
        $runs = new RunRepository();
        $changes = new ChangeRepository();
        $selection = $runs->available() && $changes->available() ? $this->inventoryService()->targetSelection($runs, $changes) : null;
        $refusal = fn ($deviceId) => $selection?->refusal((int) $deviceId)['text'] ?? ($selection === null ? 'the Ansible plugin\'s tables are missing or out of date' : null);
        $fixes = [];
        foreach ((array) ($answer['fixes'] ?? []) as $fix) {
            if (! is_array($fix) || ! in_array($fix['kind'] ?? null, FixAnswers::KINDS, true) || ! is_string($fix['id'] ?? null)) {
                continue;
            }
            $entry = ['id' => $fix['id'], 'kind' => $fix['kind'], 'title' => UntrustedText::line((string) ($fix['title'] ?? ''), 300),
                'devices' => array_map(fn ($id) => ['device_id' => (int) $id, 'name' => $name($id)], (array) ($fix['devices'] ?? [])),
                // a spanning tree finding with its VLANs (two VLANs' findings can have the same title)
                'findings' => array_map(fn (array $f) => ['identity' => self::identity($f['identity'] ?? null), 'severity' => (string) ($f['severity'] ?? ''),
                    'title' => (($f['analysis'] ?? null) === 'stp' && array_filter((array) ($f['vlans'] ?? []), 'is_int') ? FixAnswers::vlanText(array_filter((array) $f['vlans'], 'is_int')) . ': ' : '')
                        . UntrustedText::line((string) ($f['title'] ?? ''), 300),
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
            // the devices of the fix that cannot be targets, and why (the change it opens would refuse them)
            $entry['refusals'] = [];
            foreach (FixChange::devices($fix['kind'], [$fix]) as $deviceId) {
                $why = $refusal($deviceId);
                if ($why !== null) {
                    $entry['refusals'][] = ['device' => $name($deviceId), 'text' => UntrustedText::line((string) $why, 300)];
                }
            }
            $fixes[] = $entry;
        }
        $notFixable = array_map(fn (array $entry) => ['identity' => self::identity($entry['identity'] ?? null), 'title' => UntrustedText::line((string) ($entry['title'] ?? ''), 300),
            'why' => UntrustedText::line((string) ($entry['why'] ?? ''), 400),
            'devices' => array_map(fn ($id) => $name($id), (array) ($entry['devices'] ?? []))], array_values(array_filter((array) ($answer['not_fixable'] ?? []), 'is_array')));

        return response()->json(['fixes' => $fixes, 'not_fixable' => $notFixable, 'templates' => $this->templates(),
            'role_hidden' => array_values(array_filter((array) ($answer['role_hidden'] ?? []), 'is_string')), 'can_run' => Access::canRun($request->user()),
            'new_change' => route('ansible.changes.new'), 'filter' => $filter, 'devices' => $filter ? count($ids) : null, 'max_targets' => $this->settings()->maxTargets()]);
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
