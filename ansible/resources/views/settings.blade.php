{{-- Plugin settings (Hooks\Settings). LibreNMS opens this page to holders of its plugin.admin permission and stores
     what a form posts to it, so the plugin keeps none of its settings there: every form here posts to the plugin's own
     routes, for LibreNMS admins only (the runner connection, encrypted, with a write-only token; the targets and runs;
     who holds the run permission). Anyone else sees a short note. --}}
@php
    $a = $ansible;
    $c = $a['connection'] ?? [];
    $s = $a['settings'] ?? [];
    $old = session('ansible_old', []);
    $oldSettings = session('ansible_old_settings', []);
    $errors_ = session('ansible_errors', []);
    // the part the admin saved: the page returns to its anchor, and shows the answer there
    $section = (string) session('ansible_section', '');
    $section = in_array($section, ['ans-connection-form', 'ans-inventory-user', 'ans-settings-form', 'ans-changes-form', 'ans-backups-form', 'ans-topology-form',
        'ans-permission', 'ans-approve-permission'], true) ? $section : '';
    $invalid = fn (string $key) => isset($errors_[$key]) ? ' ans-invalid' : '';
    $describedBy = fn (string $key) => isset($errors_[$key]) ? new \Illuminate\Support\HtmlString('aria-invalid="true" aria-describedby="ans-error-' . e($key) . '"') : '';
    $fieldError = fn (string $key) => isset($errors_[$key])
        ? new \Illuminate\Support\HtmlString('<span class="help-block ans-field-error" id="ans-error-' . e($key) . '"><i class="fa fa-exclamation-circle"></i> ' . e($errors_[$key]) . '</span>')
        : '';
    $value = fn (string $key, $default = '') => $old[$key] ?? ($c[$key] ?? $default);
    $setting = fn (string $key) => (string) ($oldSettings[$key] ?? ($s[$key] ?? ''));
    $defaults = \Agoodley\LibrenmsAnsible\Support\PluginSettings::DEFAULTS;
    $limits = \Agoodley\LibrenmsAnsible\Support\PluginSettings::LIMITS;
    $numbers = [
        'max_targets' => ['Max targets per change', 'devices one change may have (and one run)'],
        'output_max_kb' => ['Output size (KB)', 'the log the plugin keeps per run; the runner keeps the whole log'],
        'stop_after_hours' => ['Stop after (hours)', 'a run still unfinished after this long is stopped by the plugin (it keeps its devices until the runner confirms the stop)'],
        'stop_hold_minutes' => ['Hold after a stop (minutes)', 'a stopped run keeps its devices this long after the runner stopped it, because the step in progress runs on: '
            . 'an admin with the run permission can release them on the run page once it no longer runs'],
    ];
    $choices = \Agoodley\LibrenmsAnsible\Support\PluginSettings::CHOICES;
    // the settings of changes: [key, label, help]; a key of CHOICES is a list, the others whole numbers
    $changeFields = [
        ['approval', 'Approval', 'after its verified dry run, before it can be applied: by an admin with the approve permission (the requester may approve their own change), '
            . 'or by a second admin with it (never the requester). A new dry run, a change of the template, the targets, the answers or the plan voids an approval'],
        ['approval_hours', 'Approval valid (hours)', 'a change not applied by then needs a new approval'],
        ['dry_run_hours', 'Dry run valid (hours)', 'a verified dry run counts this long for an approval and an apply; a run on its devices since then (of another change, or a run) voids it too'],
        ['canary_size', 'First batch (devices)', 'the first batch, the canary, when the new change does not choose its devices'],
        ['batch_size', 'Batch size (devices)', 'each further batch'],
        ['stop_at', 'Stop at (failed devices in a batch)', 'a batch with this many devices failed, unreachable, unknown or not run stops the change: no further batch starts'],
        ['wait_after_canary', 'Wait after the first batch', 'the change waits for an admin to continue after its first batch'],
        ['batch_pause_minutes', 'Pause between batches (minutes)', 'the time between the end of a batch and the start of the next (0: none)'],
    ];
    $backupFields = [
        ['backups', 'Oxidized backups', 'before and after each batch, of its devices, when LibreNMS uses Oxidized (its oxidized.enabled and oxidized.url settings)'],
        ['backup_policy', 'Without a fresh backup', 'what a batch does when a device has no fresh backup before it (Oxidized cannot be reached, its backup failed, the device is not in Oxidized): '
            . 'refuse the batch (the change stops) or continue with a warning in the eventlog'],
        ['backup_timeout_minutes', 'Backup timeout (minutes)', 'how long the plugin waits for a fresh backup before it counts as failed'],
        ['maintenance', 'Maintenance windows', 'one LibreNMS maintenance window per batch, on its devices, from its start to the settle time after its end'],
        ['maintenance_behavior', 'Maintenance behavior', 'what LibreNMS does with the alerts of the devices in the window'],
        ['maintenance_minutes', 'Expected batch time (minutes)', 'the window\'s end when it opens; the plugin moves it while the batch runs. Also how long a batch waits for the secret answers (at least 15 min)'],
        ['settle_minutes', 'Settle time (minutes)', 'the window closes this long after its batch ended (for polling and alerts to settle)'],
    ];
    // phase 3: the rollout plan and the blast radius from the topology plugin
    $topologyFields = [
        ['topology_plan', 'Plan with the topology plugin', 'a new change plans its batches with the topology plugin\'s rollout planner (farthest from the core first, the core last, never '
            . 'two devices that back each other up in one batch, within the batch size, the first batch taken from its first one) and approves what each batch cuts off; '
            . 'off, or without the topology plugin, the settings above plan them and the blast radius is unknown'],
        ['blast_grew', 'When the blast radius grew', 'before each batch and right before its launch its blast radius is computed again: when it would cut off devices that '
            . 'were not approved (a redundant link went down since), the batch does not start, and the change waits for an admin with the approve permission to accept '
            . 'the new one (recorded), or ends'],
        ['blast_unknown', 'When the blast radius cannot be computed', 'the topology plugin disabled, an error: the batch does not start, and the change waits for an admin '
            . 'with the run permission to continue it without one (recorded; its window then covers the devices approved as cut off), or ends'],
    ];
@endphp

@if($a['admin'])
    @push('styles')
        <link href="{{ route('ansible.asset', 'ansible.css') }}?v={{ \Agoodley\LibrenmsAnsible\Http\Controllers\Controller::assetVersion() }}" rel="stylesheet">
    @endpush
@endif

<div class="ans-settings" style="margin: 15px; max-width: 980px;">
    <h4>Ansible plugin settings @if($a['admin'])<small><a href="{{ route('ansible.changes') }}">Changes</a> · <a href="{{ route('ansible.runs') }}">Runs</a> · <a href="{{ route('ansible.inventory') }}">Inventory</a></small>@endif</h4>

    @if($section === '' || ! $a['admin'])
        @include('ansible::partials.settings-answer', ['failed' => 'Nothing was saved:'])
    @endif
    @if($a['connectionError'])
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $a['connectionError'] }}</div>
    @endif
    @if($a['settingsError'])
        <div class="alert alert-danger" id="ans-settings-unreadable"><i class="fa fa-exclamation-triangle"></i> {{ $a['settingsError'] }} Until then the plugin launches nothing.</div>
    @endif

    @if(! $a['admin'])
        <div class="alert alert-info" id="ans-settings-admins-only">Only LibreNMS admins can see and change the Ansible plugin's settings:
            the runner connection, which devices runs can reach and how, and who may launch runs. The plugin keeps them itself,
            not in LibreNMS' plugin settings.</div>
    @else
        <form method="post" action="{{ route('ansible.settings.connection') }}" id="ans-connection-form" autocomplete="off">
            @csrf
            <fieldset>
                <legend>Runner connection (Semaphore UI)</legend>
                @if($section === 'ans-connection-form')
                    @include('ansible::partials.settings-answer', ['failed' => 'The connection was not saved:'])
                @endif
                <p class="help-block">Saved by the plugin, encrypted, and only changed by LibreNMS admins: these settings decide where the token goes.
                    The token must be that of a Task Runner of the project who is not a Semaphore admin: with a token that could change templates,
                    and so run any code with the device credentials, the plugin launches nothing.</p>
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-url" style="font-weight: normal">Runner URL</label>
                    <div class="col-sm-8"><input id="ans-url" type="url" class="form-control input-sm{{ $invalid('url') }}" {{ $describedBy('url') }} name="url" value="{{ $value('url') }}" placeholder="https://semaphore.example.com">{{ $fieldError('url') }}</div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-token" style="font-weight: normal">API token</label>
                    <div class="col-sm-8">
                        <input id="ans-token" type="password" class="form-control input-sm{{ $invalid('token') }}" {{ $describedBy('token') }} name="token" value="" autocomplete="new-password"
                               placeholder="{{ ! empty($c['has_token']) ? 'saved: leave empty to keep it (enter it again to change the URL or the TLS checks)' : 'not set' }}">
                        <span class="help-block" style="margin: 2px 0 0; font-size: 12px">write-only: never shown again or sent to a browser
                            @if(! empty($c['has_token']))· saved {{ $c['saved_at'] ? \Agoodley\LibrenmsAnsible\Support\Clock::show((int) $c['saved_at'], 'Y-m-d H:i') : '' }} by {{ $c['saved_by'] }}
                                <label style="font-weight: normal; margin-left: 8px"><input type="checkbox" name="remove_token" value="1"> remove it</label>@endif
                        </span>
                        {{ $fieldError('token') }}
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-project" style="font-weight: normal">Project id</label>
                    <div class="col-sm-8"><input id="ans-project" type="number" min="1" class="form-control input-sm{{ $invalid('project') }}" {{ $describedBy('project') }} style="max-width: 140px" name="project" value="{{ $value('project') ?: '' }}">{{ $fieldError('project') }}
                        <span class="help-block" style="margin: 2px 0 0; font-size: 12px">the number in the project's address in Semaphore UI (/project/&lt;id&gt;/...)</span></div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-inventory" style="font-weight: normal">LibreNMS inventory id</label>
                    <div class="col-sm-8"><input id="ans-inventory" type="number" min="1" class="form-control input-sm{{ $invalid('inventory') }}" {{ $describedBy('inventory') }} style="max-width: 140px" name="inventory" value="{{ $value('inventory') ?: '' }}">{{ $fieldError('inventory') }}
                        <span class="help-block" style="margin: 2px 0 0; font-size: 12px">the file inventory that runs the plugin's inventory script; only templates with this
                            inventory can be launched. Semaphore UI does not show inventory ids: save without it, then Test connection lists the project's inventories.</span></div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-verify" style="font-weight: normal">Verify TLS certificates</label>
                    <div class="col-sm-8"><input id="ans-verify" type="checkbox" name="verify_tls" value="1" @checked(array_key_exists('url', $old) ? ! empty($old['verify_tls']) : ($c['verify_tls'] ?? true))>
                        <span class="help-block" style="margin: 2px 0 0; font-size: 12px">off only for a test lab: anyone between LibreNMS and the runner could read the token</span></div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-ca" style="font-weight: normal">CA bundle</label>
                    <div class="col-sm-8"><input id="ans-ca" type="text" class="form-control input-sm{{ $invalid('ca_bundle') }}" {{ $describedBy('ca_bundle') }} name="ca_bundle" value="{{ $value('ca_bundle') }}" placeholder="/etc/ssl/certs/semaphore-ca.pem">{{ $fieldError('ca_bundle') }}
                        <span class="help-block" style="margin: 2px 0 0; font-size: 12px">optional: a PEM file on the LibreNMS server to verify the runner's certificate with (empty: the system CAs)</span></div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-timeout" style="font-weight: normal">Timeout (seconds)</label>
                    <div class="col-sm-8"><input id="ans-timeout" type="number" min="1" max="120" class="form-control input-sm{{ $invalid('timeout') }}" {{ $describedBy('timeout') }} style="max-width: 140px" name="timeout" value="{{ $value('timeout', 15) }}">{{ $fieldError('timeout') }}
                        <span class="help-block" style="margin: 2px 0 0; font-size: 12px">for each call to the runner · default: 15</span></div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" id="ans-connection-save"><i class="fa fa-save"></i> Save connection</button>
                <button type="button" class="btn btn-default btn-sm" id="ans-test-connection" data-url="{{ route('ansible.settings.test') }}"><i class="fa fa-plug"></i> Test connection</button>
                <span class="ans-muted" style="font-size: 12px">tests the saved connection</span>
                @php
                    $check = $a['lastCheck'];
                @endphp
                @if($check && ! empty($check['blocked']))
                    <div class="ans-warning-loud" id="ans-connection-blocked" style="margin-top: 8px">{{ $check['blocked'] }}
                        <span class="ans-muted">(checked {{ \Agoodley\LibrenmsAnsible\Support\Clock::show((int) $check['checked_at'], 'Y-m-d H:i') }})</span></div>
                @elseif($check && $check['error'])
                    <div class="alert alert-warning" id="ans-connection-check" style="margin: 8px 0 0">The runner could not be asked
                        ({{ \Agoodley\LibrenmsAnsible\Support\Clock::show((int) $check['checked_at'], 'Y-m-d H:i') }}): {{ $check['error'] }}</div>
                @elseif($check)
                    <p class="ans-muted" id="ans-connection-check" style="margin: 6px 0 0; font-size: 12px">Last check {{ \Agoodley\LibrenmsAnsible\Support\Clock::show((int) $check['checked_at'], 'Y-m-d H:i') }}:
                        Semaphore UI {{ $check['version'] ?? '' }}, project "{{ $check['project'] ?? '' }}", the token's role {{ $check['role'] ?: 'none' }}.</p>
                @endif
                <div id="ans-test-result" class="ans-test-result"></div>
            </fieldset>
        </form>

    @php
        $chosen = $setting('inventory_user');
        $fetchedBy = array_column($a['fetches'], null, 'user_id');
    @endphp
    <form method="post" action="{{ route('ansible.settings.runs') }}" id="ans-inventory-user" autocomplete="off">
        @csrf
        <fieldset>
            <legend>Inventory user</legend>
            @if($section === 'ans-inventory-user')
                @include('ansible::partials.settings-answer', ['failed' => 'The inventory user was not saved:'])
            @endif
            <p class="help-block">The LibreNMS user whose API token the runner fetches the inventory with (the secret <span class="ans-code">LIBRENMS_TOKEN</span>
                of the templates' variable group in Semaphore UI). Once it is chosen, the inventory endpoint answers only that user's tokens, and the plugin
                checks the targets against what that user may see. Until then the plugin launches nothing.</p>
            @if($chosen === '' && $fetchedBy)
                <div class="alert alert-info" id="ans-inventory-user-proposal">The inventory was fetched with the token of
                    {{ implode(', ', array_column($a['fetches'], 'username')) }}: choose the user the runner should use.</div>
            @endif
            <div class="form-group row">
                <label class="col-sm-4 control-label" for="ans-setting-inventory_user" style="font-weight: normal">Inventory user</label>
                <div class="col-sm-8">
                    <select id="ans-setting-inventory_user" name="inventory_user" class="form-control input-sm{{ $invalid('inventory_user') }}" {{ $describedBy('inventory_user') }} style="max-width: 460px">
                        <option value="">Not chosen: launches are refused</option>
                        @if($chosen !== '' && ! isset($a['users'][(int) $chosen]))
                            <option value="{{ $chosen }}" selected>#{{ $chosen }} (no longer exists)</option>
                        @endif
                        @if($fetchedBy)
                            <optgroup label="Fetched the inventory">
                                @foreach($fetchedBy as $userId => $fetched)
                                    @if(isset($a['users'][$userId]))
                                        <option value="{{ $userId }}" @selected($chosen === (string) $userId)>{{ $fetched['username'] }} · last fetch {{ \Agoodley\LibrenmsAnsible\Support\Clock::local($fetched['fetched_at']) }}, {{ \Agoodley\LibrenmsAnsible\Support\Format::count($fetched['hosts'], 'host') }}</option>
                                    @endif
                                @endforeach
                            </optgroup>
                        @endif
                        <optgroup label="{{ $fetchedBy ? 'Other users' : 'Users' }}">
                            @foreach($a['users'] as $userId => $user)
                                @unless(isset($fetchedBy[$userId]))
                                    <option value="{{ $userId }}" @selected($chosen === (string) $userId)>{{ $user['username'] }}{{ $user['realname'] ? ' (' . $user['realname'] . ')' : '' }}{{ $user['enabled'] ? '' : ' · disabled' }}</option>
                                @endunless
                            @endforeach
                        </optgroup>
                    </select>
                    {{ $fieldError('inventory_user') }}
                    <span class="help-block" style="margin: 2px 0 0; font-size: 12px">a user whose role sees the devices the runner may change, e.g. global-read</span>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" id="ans-inventory-user-save"><i class="fa fa-save"></i> Save inventory user</button>
        </fieldset>
    </form>

    <form method="post" action="{{ route('ansible.settings.runs') }}" id="ans-settings-form">
        @csrf
        <fieldset>
            <legend>Targets and runs</legend>
            @if($section === 'ans-settings-form')
                @include('ansible::partials.settings-answer', ['failed' => 'Nothing was saved:'])
            @endif
            <p class="help-block">Which devices runs can reach and how, and what the plugin keeps. Saved by the plugin; only LibreNMS admins change them,
                and each change goes to the eventlog.</p>
            <div class="form-group row">
                <label class="col-sm-4 control-label" for="ans-setting-platform_map" style="font-weight: normal">Platform mapping</label>
                <div class="col-sm-8">
                    <textarea id="ans-setting-platform_map" rows="5" class="form-control input-sm ans-code{{ $invalid('platform_map') }}" {{ $describedBy('platform_map') }} name="platform_map" placeholder="asa   cisco.asa.asa   network_cli   become&#10;vyos  -">{{ $setting('platform_map') }}</textarea>
                    {{ $fieldError('platform_map') }}
                    <span class="help-block" style="margin: 2px 0 0; font-size: 12px">your lines add to the defaults below, one per LibreNMS os: os, ansible_network_os,
                        optionally the connection (network_cli, netconf, httpapi or a full name) and "become" (enable mode). A line for an os of the defaults
                        replaces its default; <span class="ans-code">os -</span> switches it off. A device whose os has no line cannot be a target.</span>
                    @foreach($a['mapErrors'] as $mapError)
                        <span class="help-block text-warning" style="margin: 2px 0 0; font-size: 12px"><i class="fa fa-exclamation-triangle"></i> skipped: {{ $mapError }}</span>
                    @endforeach
                    <details style="margin-top: 4px" id="ans-platform-effective">
                        <summary class="ans-muted" style="font-size: 12px">The mapping in effect: {{ \Agoodley\LibrenmsAnsible\Support\Format::count(count(array_filter($a['mapRows'], fn ($r) => $r['platform'] !== null)), 'platform') }}</summary>
                        <div class="table-responsive">
                        <table class="table table-condensed ans-table" style="margin: 4px 0 0; font-size: 12px">
                            <thead><tr><th>LibreNMS os</th><th>ansible_network_os</th><th>Connection</th><th>Enable</th><th>From</th></tr></thead>
                            <tbody>
                            @foreach($a['mapRows'] as $os => $row)
                                <tr data-os="{{ $os }}" @if(! $row['platform']) class="text-muted" @endif>
                                    <td class="ans-code">{{ $os }}</td>
                                    <td class="ans-code">{{ $row['platform']['network_os'] ?? '-' }}</td>
                                    <td class="ans-code">{{ $row['platform'] ? \Illuminate\Support\Str::afterLast($row['platform']['connection'], '.') : '' }}</td>
                                    <td>{{ ! empty($row['platform']['become']) ? 'yes' : '' }}</td>
                                    <td>{{ ['default' => 'default', 'line' => 'your line', 'off' => 'switched off'][$row['source']] ?? $row['source'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        </div>
                    </details>
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 control-label" for="ans-setting-allowed_group" style="font-weight: normal">Allowed device group</label>
                <div class="col-sm-8">
                    <select id="ans-setting-allowed_group" class="form-control input-sm{{ $invalid('allowed_group') }}" {{ $describedBy('allowed_group') }} style="max-width: 320px" name="allowed_group">
                        <option value="">Every device</option>
                        @php
                            $allowed = $setting('allowed_group');
                        @endphp
                        @if($allowed !== '' && ! isset($a['groups'][(int) $allowed]))
                            <option value="{{ $allowed }}" selected>#{{ $allowed }} (no longer exists: no device can be a target)</option>
                        @endif
                        @foreach($a['groups'] as $groupId => $name)
                            <option value="{{ $groupId }}" @selected($allowed === (string) $groupId)>{{ $name }}</option>
                        @endforeach
                    </select>
                    {{ $fieldError('allowed_group') }}
                    <span class="help-block" style="margin: 2px 0 0; font-size: 12px">only the members of this LibreNMS device group are in the inventory and can be targets</span>
                </div>
            </div>
            @foreach($numbers as $name => [$label, $help])
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-setting-{{ $name }}" style="font-weight: normal">{{ $label }}</label>
                    <div class="col-sm-8">
                        <input id="ans-setting-{{ $name }}" type="number" min="{{ $limits[$name][0] }}" max="{{ $limits[$name][1] }}" class="form-control input-sm{{ $invalid($name) }}" {{ $describedBy($name) }} style="max-width: 140px"
                               name="{{ $name }}" value="{{ $setting($name) }}">
                        {{ $fieldError($name) }}
                        <span class="help-block" style="margin: 2px 0 0; font-size: 12px">{{ $help }} · {{ $limits[$name][0] }} to {{ $limits[$name][1] }} · default: {{ $defaults[$name] }}</span>
                    </div>
                </div>
            @endforeach
            <button type="submit" class="btn btn-primary btn-sm" id="ans-settings-save"><i class="fa fa-save"></i> Save</button>
        </fieldset>
    </form>

    @foreach([['ans-changes-form', 'Changes', $changeFields, 'How a change is approved and applied: its dry run, its approval and its batches. A change keeps the batch plan it was created with.', 'ans-changes-save'],
        ['ans-backups-form', 'Backups and maintenance windows', $backupFields, ($a['oxidized'] ? 'LibreNMS uses Oxidized: the plugin asks it for a backup before and after each batch.'
            : 'LibreNMS does not use Oxidized now (oxidized.enabled, oxidized.url): no backups are made, whatever is set here.') . ' The maintenance windows are LibreNMS\' own (Alerts, Maintenance).', 'ans-backups-save'],
        ['ans-topology-form', 'Rollout plan and blast radius', $topologyFields, ($a['topology'] === null ? 'The topology plugin is installed, enabled and offers its planning API (version '
            . $a['topologyApi'] . '): new changes can be planned with it, and each batch\'s maintenance window also covers the devices it cuts off.'
            : 'The topology plugin cannot plan now: ' . $a['topology'] . '. New changes are planned by the settings above, and their blast radius is unknown.'), 'ans-topology-save']]
        as [$formId, $legend, $fields, $intro, $saveId])
        <form method="post" action="{{ route('ansible.settings.runs') }}" id="{{ $formId }}">
            @csrf
            <input type="hidden" name="section" value="{{ $formId }}">
            <fieldset>
                <legend>{{ $legend }}</legend>
                @if($section === $formId)
                    @include('ansible::partials.settings-answer', ['failed' => 'Nothing was saved:'])
                @endif
                <p class="help-block">{{ $intro }} Saved by the plugin; each change goes to the eventlog.</p>
                @foreach($fields as [$name, $label, $help])
                    <div class="form-group row">
                        <label class="col-sm-4 control-label" for="ans-setting-{{ $name }}" style="font-weight: normal">{{ $label }}</label>
                        <div class="col-sm-8">
                            @if(isset($choices[$name]))
                                <select id="ans-setting-{{ $name }}" class="form-control input-sm{{ $invalid($name) }}" {{ $describedBy($name) }} style="max-width: 320px" name="{{ $name }}">
                                    @foreach($choices[$name] as $choice => $choiceLabel)
                                        <option value="{{ $choice }}" @selected($setting($name) === (string) $choice)>{{ $choiceLabel }}</option>
                                    @endforeach
                                </select>
                                {{ $fieldError($name) }}
                                <span class="help-block" style="margin: 2px 0 0; font-size: 12px">{{ $help }} · default: {{ $choices[$name][$defaults[$name]] ?? $defaults[$name] }}</span>
                            @else
                                <input id="ans-setting-{{ $name }}" type="number" min="{{ $limits[$name][0] }}" max="{{ $limits[$name][1] }}" class="form-control input-sm{{ $invalid($name) }}" {{ $describedBy($name) }} style="max-width: 140px"
                                       name="{{ $name }}" value="{{ $setting($name) }}">
                                {{ $fieldError($name) }}
                                <span class="help-block" style="margin: 2px 0 0; font-size: 12px">{{ $help }} · {{ $limits[$name][0] }} to {{ $limits[$name][1] }} · default: {{ $defaults[$name] }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
                <button type="submit" class="btn btn-primary btn-sm" id="{{ $saveId }}"><i class="fa fa-save"></i> Save</button>
            </fieldset>
        </form>
    @endforeach

        <fieldset id="ans-permission">
            <legend>Run permission</legend>
            @if($section === 'ans-permission')
                @include('ansible::partials.settings-answer', ['failed' => 'Nothing was changed:'])
            @endif
            <p class="help-block">LibreNMS admins see the changes, the runs and the inventory; to create, dry-run, apply and stop changes (and stop runs) they also need this permission
                (<span class="ans-code">{{ \Agoodley\LibrenmsAnsible\Support\Access::PERMISSION }}</span>). It is given to each user directly:
                LibreNMS' role editor keeps only its own permissions, so a role would lose it the next time it is saved. Any LibreNMS admin can
                grant it, also to themselves: it is an explicit opt-in, written to the eventlog with who granted or revoked it, not a separation
                of duties between admins.</p>
            <table class="table table-condensed ans-table" id="ans-permission-holders" style="max-width: 700px">
                <thead><tr><th>User</th><th>Name</th><th></th></tr></thead>
                <tbody>
                @forelse($a['holders'] as $holder)
                    <tr data-user="{{ $holder['user_id'] }}">
                        <td>{{ $holder['username'] }} @unless($holder['admin'])<span class="label label-warning">not an admin: cannot use it</span>@endunless
                            @unless($holder['enabled'])<span class="label label-default">disabled</span>@endunless</td>
                        <td>{{ $holder['realname'] }}</td>
                        <td class="ans-num">
                            <form method="post" action="{{ route('ansible.settings.permission') }}" style="display: inline">
                                @csrf
                                <input type="hidden" name="user_id" value="{{ $holder['user_id'] }}">
                                <button type="submit" name="action" value="revoke" class="btn btn-xs btn-danger"><i class="fa fa-times"></i> Revoke</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-muted">Nobody may apply changes yet.</td></tr>
                @endforelse
                </tbody>
            </table>
            @if($a['candidates'])
                <form method="post" action="{{ route('ansible.settings.permission') }}" class="form-inline" id="ans-permission-grant">
                    @csrf
                    <select name="user_id" class="form-control input-sm">
                        @foreach($a['candidates'] as $candidate)
                            <option value="{{ $candidate['user_id'] }}">{{ $candidate['username'] }}{{ $candidate['realname'] ? ' (' . $candidate['realname'] . ')' : '' }}</option>
                        @endforeach
                    </select>
                    <button type="submit" name="action" value="grant" class="btn btn-sm btn-success"><i class="fa fa-check"></i> Grant</button>
                    <span class="ans-muted" style="font-size: 12px">LibreNMS admins without the permission</span>
                </form>
            @endif
        </fieldset>

        <fieldset id="ans-approve-permission">
            <legend>Approve permission</legend>
            @if($section === 'ans-approve-permission')
                @include('ansible::partials.settings-answer', ['failed' => 'Nothing was changed:'])
            @endif
            <p class="help-block">To approve or reject a change after its verified dry run, a LibreNMS admin needs this permission
                (<span class="ans-code">{{ \Agoodley\LibrenmsAnsible\Support\Access::APPROVE }}</span>), given to each user directly like the run permission and written to the eventlog.
                With "an approval from a second admin" (Changes, above) the requester of a change can never approve it, whoever holds this permission.</p>
            <table class="table table-condensed ans-table" id="ans-approve-holders" style="max-width: 700px">
                <thead><tr><th>User</th><th>Name</th><th></th></tr></thead>
                <tbody>
                @forelse($a['approveHolders'] as $holder)
                    <tr data-user="{{ $holder['user_id'] }}">
                        <td>{{ $holder['username'] }} @unless($holder['admin'])<span class="label label-warning">not an admin: cannot use it</span>@endunless
                            @unless($holder['enabled'])<span class="label label-default">disabled</span>@endunless</td>
                        <td>{{ $holder['realname'] }}</td>
                        <td class="ans-num">
                            <form method="post" action="{{ route('ansible.settings.permission') }}" style="display: inline">
                                @csrf
                                <input type="hidden" name="user_id" value="{{ $holder['user_id'] }}">
                                <input type="hidden" name="permission" value="{{ \Agoodley\LibrenmsAnsible\Support\Access::APPROVE }}">
                                <button type="submit" name="action" value="revoke" class="btn btn-xs btn-danger"><i class="fa fa-times"></i> Revoke</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-muted">Nobody may approve changes yet{{ ($a['settings']['approval'] ?? 'required') === 'off' ? ' (the settings ask for no approval)' : ': a change cannot be applied' }}.</td></tr>
                @endforelse
                </tbody>
            </table>
            @if($a['approveCandidates'])
                <form method="post" action="{{ route('ansible.settings.permission') }}" class="form-inline" id="ans-approve-grant">
                    @csrf
                    <input type="hidden" name="permission" value="{{ \Agoodley\LibrenmsAnsible\Support\Access::APPROVE }}">
                    <select name="user_id" class="form-control input-sm">
                        @foreach($a['approveCandidates'] as $candidate)
                            <option value="{{ $candidate['user_id'] }}">{{ $candidate['username'] }}{{ $candidate['realname'] ? ' (' . $candidate['realname'] . ')' : '' }}</option>
                        @endforeach
                    </select>
                    <button type="submit" name="action" value="grant" class="btn btn-sm btn-success"><i class="fa fa-check"></i> Grant</button>
                    <span class="ans-muted" style="font-size: 12px">LibreNMS admins without the permission</span>
                </form>
            @endif
        </fieldset>
    @endif
</div>

@if($a['admin'])
    {{-- the plugin's assets are for admins, like its other routes --}}
    @push('scripts')
        <script src="{{ route('ansible.asset', 'ansible.js') }}?v={{ \Agoodley\LibrenmsAnsible\Http\Controllers\Controller::assetVersion() }}"></script>
        <script>AnsiblePlugin.settings();</script>
    @endpush
@endif
