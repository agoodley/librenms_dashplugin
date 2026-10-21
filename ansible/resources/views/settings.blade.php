{{-- Plugin settings (Hooks\Settings). Three parts: the runner connection (saved by the plugin, encrypted; LibreNMS
     admins only; the token is write-only), the targets and runs (a form LibreNMS stores as posted, settings[...]),
     and who holds the run permission (admins only). --}}
@php
    $a = $ansible;
    $c = $a['connection'] ?? [];
    $old = session('ansible_old', []);
    $errors_ = session('ansible_errors', []);
    $value = fn (string $key, $default = '') => $old[$key] ?? ($c[$key] ?? $default);
    $defaults = \Agoodley\LibrenmsAnsible\Support\PluginSettings::DEFAULTS;
    $limits = \Agoodley\LibrenmsAnsible\Support\PluginSettings::LIMITS;
    $numbers = [
        'max_targets' => ['Max targets per run', 'devices one run may change'],
        'output_max_kb' => ['Output size (KB)', 'the log the plugin keeps per run; the runner keeps the whole log'],
        'lost_after_hours' => ['Lost after (hours)', 'a run still unfinished after this long ends as lost'],
    ];
@endphp

@push('styles')
    <link href="{{ route('ansible.asset', 'ansible.css') }}?v={{ \Agoodley\LibrenmsAnsible\Http\Controllers\Controller::assetVersion() }}" rel="stylesheet">
@endpush

<div class="ans-settings" style="margin: 15px; max-width: 980px;">
    <h4>Ansible plugin settings <small><a href="{{ route('ansible.runs') }}">Runs</a> · <a href="{{ route('ansible.inventory') }}">Inventory</a></small></h4>

    @if(session('ansible_status'))
        <div class="alert alert-success" id="ans-settings-status">{{ session('ansible_status') }}</div>
    @endif
    @if($errors_)
        <div class="alert alert-danger" id="ans-settings-errors"><ul style="margin: 0">@foreach($errors_ as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @if($a['connectionError'])
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $a['connectionError'] }}</div>
    @endif

    @if($a['admin'])
        <form method="post" action="{{ route('ansible.settings.connection') }}" id="ans-connection-form" autocomplete="off">
            @csrf
            <fieldset>
                <legend>Runner connection (Semaphore UI)</legend>
                <p class="help-block">Saved by the plugin, encrypted, and only changed by LibreNMS admins: these settings decide where the token goes.
                    The token should be a Task Runner's: one that may change templates could run any code with the device credentials.</p>
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-url" style="font-weight: normal">Runner URL</label>
                    <div class="col-sm-8"><input id="ans-url" type="url" class="form-control input-sm" name="url" value="{{ $value('url') }}" placeholder="https://semaphore.example.com"></div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-token" style="font-weight: normal">API token</label>
                    <div class="col-sm-8">
                        <input id="ans-token" type="password" class="form-control input-sm" name="token" value="" autocomplete="new-password"
                               placeholder="{{ ! empty($c['has_token']) ? 'saved: leave empty to keep it' : 'not set' }}">
                        <span class="help-block" style="margin: 2px 0 0; font-size: 12px">write-only: never shown again or sent to a browser
                            @if(! empty($c['has_token']))· saved {{ $c['saved_at'] ? date('Y-m-d H:i', $c['saved_at']) : '' }} by {{ $c['saved_by'] }}
                                <label style="font-weight: normal; margin-left: 8px"><input type="checkbox" name="remove_token" value="1"> remove it</label>@endif
                        </span>
                    </div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-project" style="font-weight: normal">Project id</label>
                    <div class="col-sm-8"><input id="ans-project" type="number" min="1" class="form-control input-sm" style="max-width: 140px" name="project" value="{{ $value('project') ?: '' }}">
                        <span class="help-block" style="margin: 2px 0 0; font-size: 12px">the number in the project's address in Semaphore UI (/project/&lt;id&gt;/...)</span></div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-inventory" style="font-weight: normal">LibreNMS inventory id</label>
                    <div class="col-sm-8"><input id="ans-inventory" type="number" min="1" class="form-control input-sm" style="max-width: 140px" name="inventory" value="{{ $value('inventory') ?: '' }}">
                        <span class="help-block" style="margin: 2px 0 0; font-size: 12px">the file inventory that runs the plugin's inventory script; only templates with this inventory can be launched</span></div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-verify" style="font-weight: normal">Verify TLS certificates</label>
                    <div class="col-sm-8"><input id="ans-verify" type="checkbox" name="verify_tls" value="1" @checked(array_key_exists('url', $old) ? ! empty($old['verify_tls']) : ($c['verify_tls'] ?? true))>
                        <span class="help-block" style="margin: 2px 0 0; font-size: 12px">off only for a test lab: anyone between LibreNMS and the runner could read the token</span></div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-ca" style="font-weight: normal">CA bundle</label>
                    <div class="col-sm-8"><input id="ans-ca" type="text" class="form-control input-sm" name="ca_bundle" value="{{ $value('ca_bundle') }}" placeholder="/etc/ssl/certs/semaphore-ca.pem">
                        <span class="help-block" style="margin: 2px 0 0; font-size: 12px">optional: a PEM file on the LibreNMS server to verify the runner's certificate with (empty: the system CAs)</span></div>
                </div>
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-timeout" style="font-weight: normal">Timeout (seconds)</label>
                    <div class="col-sm-8"><input id="ans-timeout" type="number" min="1" max="120" class="form-control input-sm" style="max-width: 140px" name="timeout" value="{{ $value('timeout', 15) }}">
                        <span class="help-block" style="margin: 2px 0 0; font-size: 12px">for each call to the runner · default: 15</span></div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm" id="ans-connection-save"><i class="fa fa-save"></i> Save connection</button>
                <button type="button" class="btn btn-default btn-sm" id="ans-test-connection" data-url="{{ route('ansible.settings.test') }}"><i class="fa fa-plug"></i> Test connection</button>
                <span class="ans-muted" style="font-size: 12px">tests the saved connection</span>
                <div id="ans-test-result" class="ans-test-result"></div>
            </fieldset>
        </form>
    @else
        <div class="alert alert-info">The runner connection and the run permission can only be changed by LibreNMS admins.</div>
    @endif

    <form method="post" id="ans-settings-form">
        @csrf
        <fieldset>
            <legend>Targets and runs</legend>
            <div class="form-group row">
                <label class="col-sm-4 control-label" for="ans-setting-platform_map" style="font-weight: normal">Platform mapping</label>
                <div class="col-sm-8">
                    <textarea id="ans-setting-platform_map" rows="11" class="form-control input-sm ans-code" name="settings[platform_map]" placeholder="{{ \Agoodley\LibrenmsAnsible\Inventory\PlatformMap::DEFAULT }}">{{ $settings['platform_map'] ?? '' }}</textarea>
                    <span class="help-block" style="margin: 2px 0 0; font-size: 12px">one line per LibreNMS os: os, ansible_network_os, optionally the connection
                        (network_cli, netconf, httpapi or a full name) and "become" (enable mode); empty: the defaults shown. A device whose os has no line cannot be a target.</span>
                    @foreach($a['mapErrors'] as $mapError)
                        <span class="help-block text-warning" style="margin: 2px 0 0; font-size: 12px"><i class="fa fa-exclamation-triangle"></i> skipped: {{ $mapError }}</span>
                    @endforeach
                </div>
            </div>
            <div class="form-group row">
                <label class="col-sm-4 control-label" for="ans-setting-allowed_group" style="font-weight: normal">Allowed device group</label>
                <div class="col-sm-8">
                    <select id="ans-setting-allowed_group" class="form-control input-sm" style="max-width: 320px" name="settings[allowed_group]">
                        <option value="">Every device</option>
                        @php($allowed = (string) ($settings['allowed_group'] ?? ''))
                        @if($allowed !== '' && ! isset($a['groups'][(int) $allowed]))
                            <option value="{{ $allowed }}" selected>#{{ $allowed }}{{ $a['groupsVisible'] ? ' (no longer exists: no device can be a target)' : '' }}</option>
                        @endif
                        @foreach($a['groups'] as $groupId => $name)
                            <option value="{{ $groupId }}" @selected($allowed === (string) $groupId)>{{ $name }}</option>
                        @endforeach
                    </select>
                    <span class="help-block" style="margin: 2px 0 0; font-size: 12px">only the members of this LibreNMS device group are in the inventory and can be targets</span>
                </div>
            </div>
            @foreach($numbers as $name => [$label, $help])
                <div class="form-group row">
                    <label class="col-sm-4 control-label" for="ans-setting-{{ $name }}" style="font-weight: normal">{{ $label }}</label>
                    <div class="col-sm-8">
                        <input id="ans-setting-{{ $name }}" type="number" min="{{ $limits[$name][0] }}" max="{{ $limits[$name][1] }}" class="form-control input-sm" style="max-width: 140px"
                               name="settings[{{ $name }}]" value="{{ $settings[$name] ?? $defaults[$name] }}">
                        <span class="help-block" style="margin: 2px 0 0; font-size: 12px">{{ $help }} · {{ $limits[$name][0] }} to {{ $limits[$name][1] }} · default: {{ $defaults[$name] }}</span>
                    </div>
                </div>
            @endforeach
            <button type="submit" class="btn btn-primary btn-sm" id="ans-settings-save"><i class="fa fa-save"></i> Save</button>
        </fieldset>
    </form>

    @if($a['admin'])
        <fieldset id="ans-permission">
            <legend>Run permission</legend>
            <p class="help-block">LibreNMS admins see the runs and the inventory; to launch and stop runs they also need this permission
                (<span class="ans-code">{{ \Agoodley\LibrenmsAnsible\Support\Access::PERMISSION }}</span>). It is given to each user directly:
                LibreNMS' role editor keeps only its own permissions, so a role would lose it the next time it is saved.</p>
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
                    <tr><td colspan="3" class="text-muted">Nobody may launch runs yet.</td></tr>
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
    @endif
</div>

@push('scripts')
    <script src="{{ route('ansible.asset', 'ansible.js') }}?v={{ \Agoodley\LibrenmsAnsible\Http\Controllers\Controller::assetVersion() }}"></script>
    <script>AnsiblePlugin.settings();</script>
@endpush
