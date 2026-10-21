<?php

namespace Agoodley\LibrenmsAnsible\Http\Controllers;

use Agoodley\LibrenmsAnsible\AnsibleServiceProvider;
use Agoodley\LibrenmsAnsible\Data\ConnectionRepository;
use Agoodley\LibrenmsAnsible\Data\FetchRepository;
use Agoodley\LibrenmsAnsible\Data\PermissionRepository;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Runs\TemplateCheck;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Support\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The settings LibreNMS does not store: the runner connection (encrypted, the token write-only), the connection
 * test, and the run permission. LibreNMS admins only.
 */
class SettingsController extends Controller
{
    /**
     * POST plugin/ansible/settings/connection: save the runner connection. An empty token keeps the saved one.
     */
    public function connection(Request $request): RedirectResponse
    {
        $repository = new ConnectionRepository();
        $input = $request->only(['url', 'token', 'project', 'inventory', 'verify_tls', 'ca_bundle', 'timeout']);
        $result = Connection::fromInput(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $input), $repository->get(),
            (string) $request->user()->username, time(), $request->boolean('remove_token'));
        if ($result['connection'] === null) {
            unset($input['token']);

            return $this->back()->with('ansible_errors', $result['errors'])->with('ansible_old', $input);
        }
        $repository->save($result['connection']);
        $status = 'The runner connection was saved.';
        $connection = $result['connection'];
        if ($connection->verifyTls && $connection->caBundle !== '' && ! is_readable($connection->caBundle)) {
            $status .= " LibreNMS cannot read the CA bundle {$connection->caBundle}: fix it before testing.";
        } elseif (! $connection->verifyTls) {
            $status .= ' TLS certificates are not verified: anyone between LibreNMS and the runner could read the token.';
        }

        return $this->back()->with('ansible_status', $status);
    }

    /**
     * POST plugin/ansible/settings/test: test the saved connection: the runner's version, the token's role (a
     * loud warning when it can change templates), the LibreNMS inventory and every template, usable or not.
     */
    public function test(): JsonResponse
    {
        $plugin = new Plugin();
        $connection = $plugin->connection();
        try {
            $runner = $plugin->runner($connection);
            $health = $runner->health();
            $templates = $runner->templates();
        } catch (RunnerError $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $this->failure('connection test', $e)]);
        }
        $warnings = [];
        if ($health->tooPowerful()) {
            $warnings[] = "The token's user is $health->role of the project: this token can change templates, and so their playbook and repository, "
                . 'so whoever holds it can run any code with the stored device credentials. Use a Task Runner token.';
        }
        if (! $health->canRun) {
            $warnings[] = "The token's user may not run tasks in the project: give it the Task Runner role.";
        }
        try {
            $inventory = $runner->inventory((string) $connection->inventory);
            $inventoryInfo = ['id' => $inventory->id, 'name' => $inventory->name, 'type' => $inventory->type, 'source' => $inventory->source];
            if (! $inventory->isFile()) {
                $warnings[] = "The LibreNMS inventory #{$inventory->id} ({$inventory->name}) is a {$inventory->type} inventory: make it a file inventory "
                    . 'that runs the plugin\'s inventory script (contrib/librenms_inventory.py), so the runner gets the hosts from LibreNMS.';
            }
        } catch (RunnerError $e) {
            $inventoryInfo = null;
            $warnings[] = "The LibreNMS inventory #{$connection->inventory}: " . $e->getMessage();
        }
        $list = [];
        foreach ($templates as $template) {
            $reasons = TemplateCheck::reasons($template, (string) $connection->inventory);
            $list[] = ['id' => $template->id, 'name' => $template->name, 'playbook' => $template->playbook, 'usable' => ! $reasons,
                'reasons' => $reasons, 'notes' => TemplateCheck::notes($template)];
        }
        if (! array_filter($list, fn ($t) => $t['usable'])) {
            $warnings[] = 'No template can be launched by the plugin yet: change them as their reasons say.';
        }
        if ((new FetchRepository())->latest() === null) {
            $warnings[] = 'The runner has not fetched the inventory from LibreNMS yet: see the setup steps on the Inventory page.';
        }

        return response()->json(['ok' => true, 'runner' => $runner->name(), 'version' => $health->version, 'project' => $health->project,
            'role' => $health->role, 'too_powerful' => $health->tooPowerful(), 'can_run' => $health->canRun, 'inventory' => $inventoryInfo,
            'templates' => $list, 'warnings' => $warnings]);
    }

    /**
     * POST plugin/ansible/settings/permission {user_id, action: grant|revoke}: give or take the run permission.
     */
    public function permission(Request $request): RedirectResponse
    {
        $userId = (int) $request->input('user_id');
        $action = $request->input('action');
        $repository = new PermissionRepository();
        if (! in_array($action, ['grant', 'revoke'], true) || $userId < 1) {
            return $this->back()->with('ansible_errors', ['permission' => 'Choose a user to grant the permission to or revoke it from.']);
        }
        $user = $repository->user($userId);
        if ($user === null) {
            return $this->back()->with('ansible_errors', ['permission' => 'There is no such user.']);
        }
        if ($action === 'grant' && ! $user['admin']) {
            return $this->back()->with('ansible_errors', ['permission' => "{$user['username']} is not a LibreNMS admin: only admins can use the run permission."]);
        }
        $name = $action === 'grant' ? $repository->grant($userId) : $repository->revoke($userId);

        return $this->back()->with('ansible_status', $action === 'grant' ? "$name may now launch and stop runs." : "$name may no longer launch or stop runs.");
    }

    /**
     * Back to the plugin's settings page.
     */
    private function back(): RedirectResponse
    {
        return redirect()->to(url('plugin/settings/' . AnsibleServiceProvider::PLUGIN_NAME));
    }
}
