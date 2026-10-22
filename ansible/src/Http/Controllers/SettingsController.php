<?php

namespace Agoodley\LibrenmsAnsible\Http\Controllers;

use Agoodley\LibrenmsAnsible\AnsibleServiceProvider;
use Agoodley\LibrenmsAnsible\Data\ConnectionChecks;
use Agoodley\LibrenmsAnsible\Data\ConnectionRepository;
use Agoodley\LibrenmsAnsible\Data\DeviceRepository;
use Agoodley\LibrenmsAnsible\Data\PermissionRepository;
use Agoodley\LibrenmsAnsible\Data\SettingsRepository;
use Agoodley\LibrenmsAnsible\Data\UserRepository;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runs\TemplateCheck;
use Agoodley\LibrenmsAnsible\Runs\TemplateReview;
use Agoodley\LibrenmsAnsible\Services\Eventlog;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Support\AuditText;
use Agoodley\LibrenmsAnsible\Support\Connection;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The plugin's settings, which it stores itself (LibreNMS' plugin settings form is open to plugin.admin, a
 * permission non-admin roles can have): the runner connection (encrypted, the token write-only), the connection
 * test, the targets and runs, and the run permission. LibreNMS admins only; every change is written to the
 * eventlog.
 */
class SettingsController extends Controller
{
    /**
     * POST plugin/ansible/settings/runs: save the settings of the targets and runs (and the inventory user). A
     * setting the form leaves out keeps its value; nothing is saved when one is wrong.
     */
    public function runs(Request $request): RedirectResponse
    {
        $repository = new SettingsRepository();
        $before = $repository->get();
        $groups = (new DeviceRepository())->deviceGroups();
        $users = (new UserRepository())->names();
        $input = [];
        foreach (array_keys(PluginSettings::DEFAULTS) as $key) {
            if ($request->has($key)) {
                $input[$key] = is_scalar($request->input($key)) ? (string) $request->input($key) : '';
            }
        }
        $result = PluginSettings::fromInput($input, $before, $groups, $users);
        $anchor = array_key_exists('inventory_user', $input) && count($input) === 1 ? 'ans-inventory-user' : 'ans-settings-form';
        if ($result['settings'] === null) {
            return $this->back($anchor)->with('ansible_errors', $result['errors'])->with('ansible_old_settings', $input);
        }
        $changes = $result['settings']->changes($before, $groups, $users);
        if (! $changes && $before->error === null) {
            return $this->back($anchor)->with('ansible_status', 'Nothing changed.');
        }
        $repository->save($result['settings']);
        $this->audit(AuditText::settingsChanged((string) $request->user()->username, $changes ?: ['saved again']));

        return $this->back($anchor)->with('ansible_status', 'The settings were saved' . ($changes ? ': ' . implode('; ', $changes) : '') . '.');
    }

    /**
     * POST plugin/ansible/settings/connection: save the runner connection. An empty token keeps the saved one, unless
     * the token would then go elsewhere (another URL, TLS verification off, another CA bundle).
     */
    public function connection(Request $request): RedirectResponse
    {
        $repository = new ConnectionRepository();
        $before = $repository->get();
        $input = $request->only(['url', 'token', 'project', 'inventory', 'verify_tls', 'ca_bundle', 'timeout']);
        $result = Connection::fromInput(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $input), $before,
            (string) $request->user()->username, time(), $request->boolean('remove_token'));
        if ($result['connection'] === null) {
            unset($input['token']);

            return $this->back('ans-connection-form')->with('ansible_errors', $result['errors'])->with('ansible_old', $input);
        }
        $connection = $result['connection'];
        $changes = $connection->changes($before);
        if (! $changes) {
            return $this->back('ans-connection-form')->with('ansible_status', 'Nothing changed.');
        }
        $repository->save($connection);
        $this->audit(AuditText::connectionChanged((string) $request->user()->username, $changes), $connection->verifyTls ? 'notice' : 'warning');
        $status = 'The runner connection was saved: ' . implode('; ', $changes) . '.';
        // ask the runner at once what the token may do, so the page says whether launches are blocked
        (new ConnectionChecks())->checkNow(new Plugin(), $connection);
        if ($connection->verifyTls && $connection->caBundle !== '' && ! is_readable($connection->caBundle)) {
            $status .= " LibreNMS cannot read the CA bundle {$connection->caBundle}: fix it before testing.";
        } elseif (! $connection->verifyTls) {
            $status .= ' TLS certificates are not verified: anyone between LibreNMS and the runner could read the token.';
        }

        return $this->back('ans-connection-form')->with('ansible_status', $status);
    }

    /**
     * POST plugin/ansible/settings/test: test the saved connection: the runner's version, the token's role (and why
     * launches are blocked when it could change templates or may not run them), the project's inventories (to choose
     * the LibreNMS one: Semaphore UI does not show their ids) and every template, usable or not. Works before the
     * LibreNMS inventory is chosen. The result is kept, so the settings page shows a blocked token without a test.
     */
    public function test(): JsonResponse
    {
        $plugin = new Plugin();
        $connection = $plugin->connection();
        $checks = new ConnectionChecks();
        try {
            $runner = $plugin->runner($connection, false);
            $health = $runner->health();
            $checks->save(ConnectionChecks::fromHealth($health, time()));
            $templates = $runner->templates();
            $inventories = $runner->inventories();
        } catch (RunnerError $e) {
            $checks->save(ConnectionChecks::failed($e->getMessage(), time()));

            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $this->failure('connection test', $e)]);
        }
        // a token that could change templates or may not run them: said once, as the reason launches are blocked
        $warnings = [];
        $chosen = (string) $connection->inventory;
        $inventoryList = [];
        foreach ($inventories as $inventory) {
            $inventoryList[] = ['id' => $inventory->id, 'name' => $inventory->name, 'type' => $inventory->type, 'source' => $inventory->source,
                'file' => $inventory->isFile(), 'script' => $inventory->isFile() && str_ends_with($inventory->source, 'librenms_inventory.py'),
                'chosen' => $inventory->id === $chosen];
        }
        $inventoryInfo = null;
        if ($connection->inventory < 1) {
            $warnings[] = 'Choose the LibreNMS inventory: the file inventory that runs the plugin\'s inventory script (see the list of the project\'s '
                . 'inventories below), then save the connection.';
        } else {
            $found = array_values(array_filter($inventories, fn ($inventory) => $inventory->id === $chosen));
            if (! $found) {
                $warnings[] = "The project has no inventory #{$connection->inventory}: choose the LibreNMS inventory among those listed below.";
            } else {
                $inventoryInfo = ['id' => $found[0]->id, 'name' => $found[0]->name, 'type' => $found[0]->type, 'source' => $found[0]->source];
                if (! $found[0]->isFile()) {
                    $warnings[] = "The LibreNMS inventory #{$found[0]->id} ({$found[0]->name}) is a {$found[0]->type} inventory: make it a file inventory "
                        . 'that runs the plugin\'s inventory script (contrib/librenms_inventory.py), so the runner gets the hosts from LibreNMS.';
                }
            }
        }
        $list = [];
        $review = new TemplateReview($runner, $chosen);
        foreach ($templates as $template) {
            $reasons = $review->reasons($template);
            $list[] = ['id' => $template->id, 'name' => $template->name, 'playbook' => $template->playbook, 'usable' => ! $reasons,
                'reasons' => $reasons, 'notes' => TemplateCheck::notes($template)];
        }
        if (! array_filter($list, fn ($t) => $t['usable'])) {
            $warnings[] = 'No template can be launched by the plugin yet: change them as their reasons say.';
        }
        $inventoryService = $this->inventoryService();
        $inventoryUser = $inventoryService->inventoryUser();
        if ($inventoryUser === null) {
            $warnings[] = (string) $inventoryService->inventoryUserProblem();
        } elseif ($inventoryUser['fetch'] === null) {
            $warnings[] = 'The runner has not fetched the inventory with the inventory user ' . $inventoryUser['user']->username . '\'s token yet: '
                . 'the first run makes it fetch it (see the setup steps on the Inventory page).';
        }

        return response()->json(['ok' => true, 'runner' => $runner->name(), 'version' => $health->version, 'project' => $health->project,
            'role' => $health->role, 'admin' => $health->admin, 'too_powerful' => $health->tooPowerful(), 'can_run' => $health->canRun,
            'blocked' => $health->launchBlock(), 'inventory' => $inventoryInfo, 'inventories' => $inventoryList,
            'templates' => $list, 'warnings' => $warnings]);
    }

    /**
     * POST plugin/ansible/settings/permission {user_id, action: grant|revoke}: give or take the run permission. Any
     * admin may grant it, also to themselves: it is an explicit opt-in, written to the eventlog.
     */
    public function permission(Request $request): RedirectResponse
    {
        $userId = (int) $request->input('user_id');
        $action = $request->input('action');
        $repository = new PermissionRepository();
        if (! in_array($action, ['grant', 'revoke'], true) || $userId < 1) {
            return $this->back('ans-permission')->with('ansible_errors', ['permission' => 'Choose a user to grant the permission to or revoke it from.']);
        }
        $user = $repository->user($userId);
        if ($user === null) {
            return $this->back('ans-permission')->with('ansible_errors', ['permission' => 'There is no such user.']);
        }
        if ($action === 'grant' && ! $user['admin']) {
            return $this->back('ans-permission')->with('ansible_errors', ['permission' => "{$user['username']} is not a LibreNMS admin: only admins can use the run permission."]);
        }
        if ($user['holds'] === ($action === 'grant')) {
            return $this->back('ans-permission')->with('ansible_status', $action === 'grant' ? "{$user['username']} already holds the run permission." : "{$user['username']} does not hold the run permission.");
        }
        $by = (string) $request->user()->username;
        if ($action === 'grant') {
            $name = (string) $repository->grant($userId);
            $this->audit(AuditText::permissionGranted($by, $name));

            return $this->back('ans-permission')->with('ansible_status', "$name may now launch and stop runs.");
        }
        $name = (string) $repository->revoke($userId);
        $this->audit(AuditText::permissionRevoked($by, $name));

        return $this->back('ans-permission')->with('ansible_status', "$name may no longer launch or stop runs.");
    }

    /**
     * Back to the plugin's settings page, at the part that was saved.
     */
    private function back(string $anchor = ''): RedirectResponse
    {
        return redirect()->to(url('plugin/settings/' . AnsibleServiceProvider::PLUGIN_NAME) . ($anchor !== '' ? '#' . $anchor : ''));
    }

    /**
     * A configuration change in the eventlog (who, what; never the token).
     */
    private function audit(string $message, string $severity = 'notice'): void
    {
        (new Eventlog())->write($message, null, $severity, AuditText::REFERENCE);
    }
}
