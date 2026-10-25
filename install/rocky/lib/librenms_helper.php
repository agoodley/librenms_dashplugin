<?php

/*
 * install/rocky/lib/librenms_helper.php: what install.sh does inside LibreNMS, as the librenms user:
 *
 *   php librenms_helper.php <command> < request.json
 *
 * The request is a JSON object on stdin (passwords and tokens never go on a command line, where every user could read
 * them); the answer is one JSON object on stdout, an error a JSON object {"error": "..."} with exit code 1.
 *
 *   env-get             {"keys": [...]}                         the values of .env (without booting LibreNMS)
 *   env-set             {"set": {...}, "unset": [...]}          .env written with LibreNMS' EnvHelper, when it changes
 *   version             {}                                      LibreNMS' version, PHP's, the auth mechanism
 *   user-get            {"username"}                            whether the user exists, its roles and e-mail
 *   user-ensure         {"username", "password", "roles", ...}  a user, created when missing (as lnms user:add does)
 *   token-create        {"username", "name"}                    a new API token of the user (lnms api:token-create)
 *   token-check         {"token", "username"}                   whether a token is still valid, and the user's
 *   plugins-status      {}                                      whether the plugins are known to LibreNMS and enabled
 *   ansible-status      {"admin"}                               the Ansible plugin's connection, settings, grants
 *   ansible-setup       {...}                                   the Ansible plugin's connection, inventory user,
 *                                                               fix templates and the admin's permissions, saved as
 *                                                               its settings page saves them (with the same checks
 *                                                               and eventlog entries), then its connection test
 *   device-addresses    {"oxidized_user"}                       every device, with where Ansible and Oxidized connect
 *                                                               to it (device-keys.sh scans their SSH host keys there)
 */

declare(strict_types=1);

use Agoodley\LibrenmsAnsible\Data\ConnectionChecks;
use Agoodley\LibrenmsAnsible\Data\ConnectionRepository;
use Agoodley\LibrenmsAnsible\Data\DeviceRepository;
use Agoodley\LibrenmsAnsible\Data\PermissionRepository;
use Agoodley\LibrenmsAnsible\Data\SettingsRepository;
use Agoodley\LibrenmsAnsible\Data\UserRepository;
use Agoodley\LibrenmsAnsible\Http\Controllers\SettingsController;
use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Services\Eventlog;
use Agoodley\LibrenmsAnsible\Services\InventoryService;
use Agoodley\LibrenmsAnsible\Services\Plugin;
use Agoodley\LibrenmsAnsible\Support\Access;
use Agoodley\LibrenmsAnsible\Support\AuditText;
use Agoodley\LibrenmsAnsible\Support\Connection;
use Agoodley\LibrenmsAnsible\Support\PluginSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

const BASE = '/opt/librenms';

/** @param array<string, mixed> $data */
function answer(array $data): never
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), PHP_EOL;
    exit(0);
}

function refuse(string $message): never
{
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(1);
}

// PHP's own messages go to stderr (the installer logs it): stdout is the JSON answer alone
ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');

$command = $argv[1] ?? '';
$raw = stream_get_contents(STDIN);
$input = trim((string) $raw) === '' ? [] : json_decode((string) $raw, true);
if (! is_array($input)) {
    refuse('the request on stdin is not a JSON object');
}

chdir(BASE);
require BASE . '/vendor/autoload.php';

// ------------------------------------------------------------------------------------------------ without LibreNMS booted

if ($command === 'env-get') {
    $values = [];
    $file = BASE . '/.env';
    $env = is_file($file) ? Dotenv\Dotenv::parse((string) file_get_contents($file)) : [];
    foreach ((array) ($input['keys'] ?? []) as $key) {
        $values[(string) $key] = $env[(string) $key] ?? null;
    }
    answer(['values' => $values, 'exists' => is_file($file)]);
}

if ($command === 'env-set') {
    $file = BASE . '/.env';
    if (! is_file($file)) {
        refuse('LibreNMS has no .env yet (composer install creates it)');
    }
    $before = (string) file_get_contents($file);
    $set = array_map(fn ($v) => $v === null ? null : (string) $v, (array) ($input['set'] ?? []));
    \LibreNMS\Util\EnvHelper::writeEnv($set, array_values((array) ($input['unset'] ?? [])), $file);
    clearstatcache();
    answer(['changed' => $before !== (string) file_get_contents($file)]);
}

// ------------------------------------------------------------------------------------------------ LibreNMS booted

$app = require BASE . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/** The user of that name: a local (mysql) one first, then one of another auth mechanism. */
function find_user(string $username): ?User
{
    return User::where('username', $username)->orderByRaw("auth_type = 'mysql' DESC")->first();
}

/** @return array<string, mixed> */
function user_row(User $user): array
{
    return ['user_id' => (int) $user->user_id, 'username' => (string) $user->username, 'auth_type' => (string) $user->auth_type,
        'enabled' => (bool) $user->enabled, 'roles' => $user->getRoleNames()->values()->all(), 'admin' => $user->hasRole('admin'),
        'email' => (string) $user->email];
}

// LibreNMS' exception handler would print an error on stdout and exit 0: every failure is a refusal here instead
try {
    switch ($command) {
        case 'version':
            answer(['version' => \LibreNMS\Util\Version::VERSION, 'php' => PHP_VERSION,
                'auth_mechanism' => (string) \App\Facades\LibrenmsConfig::get('auth_mechanism'),
                'extensions' => ['curl' => extension_loaded('curl'), 'mbstring' => extension_loaded('mbstring')]]);

            // no break: answer() exits
        case 'user-get':
            $user = find_user((string) ($input['username'] ?? ''));
            answer($user === null ? ['exists' => false] : ['exists' => true] + user_row($user));

            // no break
        case 'user-ensure':
            $username = (string) ($input['username'] ?? '');
            $roles = array_values(array_map('strval', (array) ($input['roles'] ?? ['user'])));
            $user = find_user($username);
            $created = false;
            if ($user === null) {
                $password = (string) ($input['password'] ?? '');
                $validator = Validator::make(['username' => $username, 'password' => $password, 'email' => $input['email'] ?? null],
                    ['username' => ['required', 'string', 'max:255'], 'password' => ['required', Password::defaults()], 'email' => ['nullable', 'email']]);
                if ($validator->fails()) {
                    refuse("the user $username cannot be created: " . implode(' ', $validator->errors()->all()));
                }
                $available = \Spatie\Permission\Models\Role::pluck('name')->all();
                if ($missing = array_diff($roles, $available)) {
                    refuse('LibreNMS has no role ' . implode(', ', $missing) . ' (its roles: ' . implode(', ', $available) . ')');
                }
                // as lnms user:add makes a user
                $user = new User(['username' => $username, 'realname' => $input['realname'] ?? null, 'email' => $input['email'] ?? null,
                    'descr' => $input['descr'] ?? null, 'auth_type' => 'mysql']);
                $user->setPassword($password);
                $user->save();
                $user->assignRole($roles);
                $user->save();
                $user->refresh();
                $created = true;
            }
            answer(['created' => $created] + user_row($user));

            // no break
        case 'token-create':
            $user = find_user((string) ($input['username'] ?? ''));
            if ($user === null) {
                refuse('there is no LibreNMS user ' . ($input['username'] ?? ''));
            }
            answer(['token' => $user->createToken((string) ($input['name'] ?? 'api-token'))->plainTextToken]);

            // no break
        case 'token-check':
            $model = \Laravel\Sanctum\Sanctum::$personalAccessTokenModel;
            $token = $model::findToken((string) ($input['token'] ?? ''));
            $owner = $token?->tokenable;
            $valid = $owner instanceof User && $owner->enabled && (! isset($input['username']) || $owner->username === $input['username']);
            answer(['valid' => $valid, 'name' => $token?->name]);

            // no break
        case 'plugins-status':
            $rows = Schema::hasTable('plugins') ? DB::table('plugins')->whereIn('plugin_name', ['topology', 'ansible'])->get(['plugin_name', 'plugin_active', 'version']) : collect();
            $status = ['topology' => null, 'ansible' => null];
            foreach ($rows as $row) {
                if ((int) $row->version === 2) {
                    $status[$row->plugin_name] = (bool) $row->plugin_active;
                }
            }
            $pending = [];
            try {
                $migrator = app('migrator');
                $paths = array_merge([database_path('migrations')], $migrator->paths());
                $ran = $migrator->getRepository()->getRan();
                foreach ($migrator->getMigrationFiles($paths) as $name => $file) {
                    if (! in_array($name, $ran, true)) {
                        $pending[] = $name;
                    }
                }
            } catch (\Throwable $e) {
                $pending[] = 'unknown: ' . $e->getMessage();
            }
            answer(['enabled' => $status, 'pending_migrations' => $pending]);

            // no break
        case 'ansible-status':
            if (! class_exists(Connection::class) || ! Schema::hasTable('ansible_config')) {
                answer(['installed' => false]);
            }
            $connection = (new ConnectionRepository())->get();
            $settings = (new SettingsRepository())->get();
            $admin = find_user((string) ($input['admin'] ?? ''));
            $permissions = new PermissionRepository();
            answer(['installed' => true, 'url' => $connection->url, 'project' => $connection->project, 'inventory' => $connection->inventory,
                'token_matches' => isset($input['token']) ? hash_equals($connection->token, (string) $input['token']) : null,
                'has_token' => $connection->token !== '',
                'inventory_user' => $settings->all()['inventory_user'] ?? '',
                'fix_template_port_descriptions' => $settings->all()['fix_template_port_descriptions'] ?? '',
                'fix_template_stp_root_priority' => $settings->all()['fix_template_stp_root_priority'] ?? '',
                'admin' => $admin === null ? null : user_row($admin) + [
                    'run' => (bool) $permissions->user((int) $admin->user_id, Access::PERMISSION)['holds'],
                    'approve' => (bool) $permissions->user((int) $admin->user_id, Access::APPROVE)['holds']]]);

            // no break
        case 'ansible-setup':
            $by = (string) ($input['by'] ?? 'the installer');
            $out = ['connection_changes' => [], 'settings_changes' => [], 'granted' => []];
            $eventlog = new Eventlog();

            // the runner connection, as SettingsController::connection() saves it
            $repository = new ConnectionRepository();
            $before = $repository->get();
            $result = Connection::fromInput(array_map('strval', (array) $input['connection']), $before, $by, time());
            if ($result['connection'] === null) {
                refuse('the Ansible plugin refused the runner connection: ' . implode(' ', $result['errors']));
            }
            $connection = $result['connection'];
            if ($changes = $connection->changes($before)) {
                $repository->save($connection);
                $eventlog->write(AuditText::connectionChanged($by, $changes), null, $connection->verifyTls ? 'notice' : 'warning', AuditText::REFERENCE);
                (new ConnectionChecks())->checkNow(new Plugin(), $connection);
                $out['connection_changes'] = $changes;
            }

            // the inventory user and the fix templates, as SettingsController::runs() saves them
            $settingsRepository = new SettingsRepository();
            $settingsBefore = $settingsRepository->get();
            $groups = (new DeviceRepository())->deviceGroups();
            $users = (new UserRepository())->names();
            $fixTemplate = function (string $kind, string $templateId): ?string {
                try {
                    $plugin = new Plugin();
                    $template = $plugin->runner($plugin->connection())->template($templateId);
                } catch (RunnerError $e) {
                    return 'the runner cannot give template #' . $templateId . ' (' . $e->getMessage() . ')';
                }

                return \Agoodley\LibrenmsAnsible\Changes\FixAnswers::templateProblem($template, $kind);
            };
            $settingsInput = array_map('strval', (array) ($input['settings'] ?? []));
            $settingsResult = PluginSettings::fromInput($settingsInput, $settingsBefore, $groups, $users, $fixTemplate);
            if ($settingsResult['settings'] === null) {
                refuse('the Ansible plugin refused its settings: ' . implode(' ', $settingsResult['errors']));
            }
            $settingsChanges = $settingsResult['settings']->changes($settingsBefore, $groups, $users);
            if ($settingsChanges || $settingsBefore->error !== null) {
                $settingsRepository->save($settingsResult['settings']);
                $eventlog->write(AuditText::settingsChanged($by, $settingsChanges ?: ['saved again']), null, 'notice', AuditText::REFERENCE);
                $out['settings_changes'] = $settingsChanges;
            }

            // the run and approve permissions of the admin, as SettingsController::permission() grants them
            $admin = find_user((string) ($input['admin'] ?? ''));
            if ($admin === null || ! $admin->hasRole('admin')) {
                refuse('there is no LibreNMS admin ' . ($input['admin'] ?? '') . ' to grant the run and approve permissions to');
            }
            $permissions = new PermissionRepository();
            foreach ([Access::PERMISSION, Access::APPROVE] as $permission) {
                if (! $permissions->user((int) $admin->user_id, $permission)['holds']) {
                    $name = (string) $permissions->grant((int) $admin->user_id, $permission);
                    $eventlog->write(AuditText::permissionGranted($by, $name, $permission), null, 'notice', AuditText::REFERENCE);
                    $out['granted'][] = $permission;
                }
            }

            // the settings page's Test connection
            try {
                $out['test'] = (new SettingsController())->test()->getData(true);
            } catch (\Throwable $e) {
                $out['test'] = ['ok' => false, 'error' => 'the connection test failed: ' . $e->getMessage()];
            }
            answer($out);

            // no break
        case 'device-addresses':
            // Every device, and where Ansible and Oxidized connect to it. Ansible: the Ansible plugin's inventory of
            // every device (ansible_host, its overwrite IP else its hostname; port 22, as the inventory sets no
            // ansible_port), or why a device is not in it. Oxidized: what LibreNMS' API gives it (list_oxidized(), for
            // its token's user): the device's IP, which Oxidized connects to (it resolves the hostname when there is
            // none), and its SSH port.
            $devices = [];
            foreach (DB::table('devices')->orderBy('device_id')->get(['device_id', 'hostname', 'sysName', 'disabled']) as $row) {
                $devices[(int) $row->device_id] = ['id' => (int) $row->device_id, 'hostname' => (string) $row->hostname,
                    'sysname' => (string) ($row->sysName ?? ''), 'disabled' => (bool) $row->disabled,
                    'ansible' => null, 'ansible_left_out' => null, 'oxidized' => null];
            }
            $out = ['ansible' => null, 'oxidized' => null];
            if (! class_exists(InventoryService::class) || ! Schema::hasTable('ansible_config')) {
                $out['ansible'] = 'the Ansible plugin is not installed';
            } elseif (($settings = (new SettingsRepository())->get())->error !== null) {
                $out['ansible'] = "the Ansible plugin's settings cannot be read: " . $settings->error;
            } else {
                $inventory = (new InventoryService(new DeviceRepository(), $settings))->build();
                $hosts = $inventory->hosts();
                foreach ($inventory->names() as $id => $name) {
                    if (isset($devices[$id])) {
                        $devices[$id]['ansible'] = ['name' => $name, 'host' => (string) $hosts[$name]['ansible_host'], 'port' => 22];
                    }
                }
                foreach ($inventory->leftOut() as $id => $reason) {
                    if (isset($devices[$id])) {
                        $devices[$id]['ansible_left_out'] = $reason['reason'];
                    }
                }
            }
            $oxidizedUser = find_user((string) ($input['oxidized_user'] ?? ''));
            if (! \App\Facades\LibrenmsConfig::get('oxidized.enabled')) {
                $out['oxidized'] = "LibreNMS' Oxidized integration is off";
            } elseif ($oxidizedUser === null) {
                $out['oxidized'] = 'LibreNMS has no user ' . ($input['oxidized_user'] ?? '') . ' (whose API token Oxidized reads the devices with)';
            } else {
                // as LibreNMS' API answers Oxidized's GET /api/v0/oxidized (LegacyApiController)
                require_once base_path('includes/html/api_functions.inc.php');
                $request = Illuminate\Http\Request::create('/api/v0/oxidized');
                $request->setUserResolver(fn () => $oxidizedUser);
                $ids = [];
                foreach ($devices as $id => $device) {
                    $ids[strtolower($device['hostname'])] = $id;
                }
                foreach ((array) list_oxidized($request)->getData(true) as $node) {
                    $id = $ids[strtolower((string) ($node['hostname'] ?? ''))] ?? null;
                    if ($id !== null) {
                        $devices[$id]['oxidized'] = ['ip' => (string) ($node['ip'] ?? ''), 'port' => (int) ($node['ssh_port'] ?? 0) ?: 22];
                    }
                }
            }
            answer(['devices' => array_values($devices)] + $out);

            // no break
        default:
            refuse("unknown command '$command'");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    refuse(preg_replace('/\$2y\$[^,) ]+/', '<hash>', $e->getMessage()) ?: get_class($e));
}
