{{-- Plugin settings (Hooks\Settings). LibreNMS opens this page to holders of its plugin.admin permission and stores what a
     form posts to it, so the plugin keeps none of its settings there: the form posts to the plugin's own route, for
     LibreNMS admins only. Anyone else sees a short note. --}}
@php
    use Agoodley\LibrenmsInventory\Services\SoftwareCheck;
    use Agoodley\LibrenmsInventory\Support\Clock;
    use Agoodley\LibrenmsInventory\Support\PluginSettings;
    $c = $inventory;
    $s = $c['settings'] ?? PluginSettings::DEFAULTS;
    $old = session('inventory_old', []);
    $errors_ = session('inventory_errors', []);
    $saved = session('inventory_saved');
    $savedOk = (bool) session('inventory_saved_ok', false);
    $value = fn (string $key) => $old[$key] ?? ($s[$key] ?? PluginSettings::DEFAULTS[$key]);
    $invalid = fn (string $key) => isset($errors_[$key]) ? ' inv-invalid' : '';
    $fieldError = fn (string $key) => isset($errors_[$key])
        ? new \Illuminate\Support\HtmlString('<span class="help-block inv-field-error" id="inv-error-' . e($key) . '"><i class="fa fa-exclamation-circle"></i> ' . e($errors_[$key]) . '</span>')
        : '';
    $describedBy = fn (string $key) => isset($errors_[$key]) ? new \Illuminate\Support\HtmlString('aria-invalid="true" aria-describedby="inv-error-' . e($key) . '"') : '';
    $routes = \Illuminate\Support\Facades\Route::has('inventory.settings.save');
    $group = (string) $value('device_group');
    $groups = $c['groups'] ?? [];
    $last = $c['lastRun'] ?? null;
    $example = "# blocked on every IOS and IOS-XE device, whatever its policy\n- name: IOS-XE advisories\n  os: [ios, iosxe]\n  blocked: ['16.*', '17.3.1']\n"
        . "  note: cisco-sa-iosxe-webui-privesc-j22SaA4z\n\n# the Catalyst 9300 switches: approved versions and a minimum\n- name: Catalyst 9300\n  os: iosxe\n  model: C9300-*\n"
        . "  approved: ['17.9.5', '17.12.*']\n  minimum: '17.9.4'\n\n# Arista EOS: a minimum only\n- name: EOS\n  os: arista_eos\n  minimum: '4.30.1F'\n";
@endphp
@if($routes)
    <link href="{{ route('inventory.asset', 'inventory.css') }}?v={{ \Agoodley\LibrenmsInventory\Http\Controllers\Controller::assetVersion() }}" rel="stylesheet">
@endif
<div class="inv-settings" id="inv-settings" style="max-width: 980px">
@if(! ($c['admin'] ?? false))
    <p class="text-muted" id="inv-settings-admins">The inventory plugin's settings are for LibreNMS admins.</p>
@elseif(! $routes)
    <div class="alert alert-info">Enable the plugin in Plugin Admin first: its settings are saved through its own pages.</div>
@else
    @if($c['error'])
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $c['error'] }}</div>
    @endif
    @if(! ($c['tables'] ?? true))
        <div class="alert alert-warning">The plugin's tables are missing: run <span class="inv-code">./lnms migrate</span> in the LibreNMS directory before saving the settings.</div>
    @endif

    <p class="inv-help" style="margin: 0 0 10px">The software check compares the software version LibreNMS has for each device with these policies every hour, and records
        each device's upgrades and downgrades. <a href="{{ route('inventory.software') }}">Software compliance</a> · <a href="{{ route('inventory.upgrades') }}">Upgrades</a></p>
    <p id="inv-settings-last-run" class="inv-help" style="margin: 0 0 12px">
        @if($last)
            Last check: {{ Clock::show((int) $last['started']) }}, @if($last['error'])<span class="text-danger">{{ $last['error'] }}</span>@else{{ SoftwareCheck::describe($last) }}.@endif
        @else
            The devices were not checked yet.
        @endif
        A check now: <span class="inv-code">./lnms inventory:check</span>, or Check now on the software compliance page.
    </p>

    @if($saved !== null)
        <div class="alert {{ $savedOk ? 'alert-success' : 'alert-warning' }}" id="inv-settings-saved">Saved, and the devices checked with these settings: {{ $saved }}</div>
    @endif
    @if(isset($errors_['']))
        <div class="alert alert-danger">{{ $errors_[''] }}</div>
    @elseif($errors_)
        <div class="alert alert-danger" id="inv-settings-errors">Nothing was saved: {{ count($errors_) === 1 ? 'a setting is' : count($errors_) . ' settings are' }} wrong, see below.</div>
    @endif

    <form method="post" action="{{ route('inventory.settings.save') }}" id="inv-settings-form">
        @csrf
        <fieldset>
            <legend>Software check</legend>
            <div class="inv-fields">
                <div class="form-group inv-wide">
                    <label for="inv-group">Devices</label>
                    <select class="form-control{{ $invalid('device_group') }}" id="inv-group" name="device_group" {{ $describedBy('device_group') }}>
                        <option value="">every enabled device</option>
                        @foreach($groups as $name)
                            <option value="{{ $name }}" @selected($group === $name)>the enabled devices of the device group {{ $name }}</option>
                        @endforeach
                        @if($group !== '' && ! in_array($group, $groups, true))
                            <option value="{{ $group }}" selected>{{ $group }} (LibreNMS no longer has it)</option>
                        @endif
                    </select>
                    <span class="help-block">the status of a device left out is forgotten; its upgrades stay</span>
                    {{ $fieldError('device_group') }}
                </div>
                <div class="form-group">
                    <label for="inv-eventlog">Eventlog</label>
                    <select class="form-control{{ $invalid('eventlog') }}" id="inv-eventlog" name="eventlog">
                        @foreach(PluginSettings::CHOICES['eventlog'] as $key => $label)
                            <option value="{{ $key }}" @selected((string) $value('eventlog') === (string) $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <span class="help-block">a device that is no longer compliant, or is again (type inventory)</span>
                    {{ $fieldError('eventlog') }}
                </div>
                <div class="form-group">
                    <label for="inv-eventlog-upgrades">Upgrades in the eventlog</label>
                    <select class="form-control{{ $invalid('eventlog_upgrades') }}" id="inv-eventlog-upgrades" name="eventlog_upgrades">
                        @foreach(PluginSettings::CHOICES['eventlog_upgrades'] as $key => $label)
                            <option value="{{ $key }}" @selected((string) $value('eventlog_upgrades') === (string) $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <span class="help-block">each upgrade and downgrade the check sees</span>
                    {{ $fieldError('eventlog_upgrades') }}
                </div>
            </div>
        </fieldset>

        <fieldset id="inv-policies">
            <legend>Software policies</legend>
            <p class="inv-help">YAML: a list of policies. Each is for an <span class="inv-code">os</span> (LibreNMS os names, such as <span class="inv-code">iosxe</span>), a
                <span class="inv-code">model</span> (the device's or a stack member's) or both, and has <span class="inv-code">approved</span> versions, a
                <span class="inv-code">minimum</span>, <span class="inv-code">blocked</span> versions or several of them; a <span class="inv-code">name</span> and a
                <span class="inv-code">note</span> (shown with its findings) are optional. Models and versions take <span class="inv-code">*</span> for any text and
                <span class="inv-code">?</span> for one character, ignoring case. Write versions in quotes: YAML reads <span class="inv-code">4.30</span> as the number 4.3.</p>
            <p class="inv-help">A device gets the policy for its model, else the one for its os (of several, the first): blocked versions are <strong>blocked</strong>
                (critical), an older version than the minimum is <strong>below the minimum</strong> and one that is none of the approved versions <strong>not approved</strong>
                (warnings); the blocked versions of every policy for the device count, so a policy for an os can block versions on every model. Versions are compared
                number by number, leading zeros ignored: 15.2(7)E10 is newer than 15.2(7)E8, 17.12.1 than 17.9.4, and 17.09.04a is 17.9.4a.</p>
            @if($c['policyErrors'] && ! isset($errors_['policies']))
                <div class="alert alert-danger">{{ implode(' ', $c['policyErrors']) }}</div>
            @endif
            <div class="form-group">
                <label for="inv-policies-text" class="sr-only">Software policies (YAML)</label>
                <textarea class="form-control inv-code{{ $invalid('policies') }}" id="inv-policies-text" name="policies" rows="16" spellcheck="false"
                    placeholder="{{ $example }}" {{ $describedBy('policies') }}>{{ $value('policies') }}</textarea>
                {{ $fieldError('policies') }}
            </div>
            <details><summary class="inv-help">An example</summary><pre class="inv-example">{{ $example }}</pre></details>
        </fieldset>

        <button type="submit" class="btn btn-primary" id="inv-settings-save"><i class="fa fa-save"></i> Save and check</button>
    </form>
@endif
</div>
