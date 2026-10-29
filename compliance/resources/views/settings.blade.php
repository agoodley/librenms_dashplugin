{{-- Plugin settings (Hooks\Settings). LibreNMS opens this page to holders of its plugin.admin permission and stores what a
     form posts to it, so the plugin keeps none of its settings there: the form posts to the plugin's own route, for
     LibreNMS admins only. Anyone else sees a short note. --}}
@php
    use Agoodley\LibrenmsCompliance\Rules\BuiltinRules;
    use Agoodley\LibrenmsCompliance\Support\Clock;
    use Agoodley\LibrenmsCompliance\Support\PluginSettings;
    $c = $compliance;
    $s = $c['settings'] ?? PluginSettings::DEFAULTS;
    $old = session('compliance_old', []);
    $errors_ = session('compliance_errors', []);
    $saved = (bool) session('compliance_saved', false);
    $value = fn (string $key) => $old[$key] ?? ($s[$key] ?? PluginSettings::DEFAULTS[$key]);
    $disabled = array_fill_keys((array) $value('disabled_rules'), true);
    $invalid = fn (string $key) => isset($errors_[$key]) ? ' cmp-invalid' : '';
    $fieldError = fn (string $key) => isset($errors_[$key])
        ? new \Illuminate\Support\HtmlString('<span class="help-block cmp-field-error" id="cmp-error-' . e($key) . '"><i class="fa fa-exclamation-circle"></i> ' . e($errors_[$key]) . '</span>')
        : '';
    $describedBy = fn (string $key) => isset($errors_[$key]) ? new \Illuminate\Support\HtmlString('aria-invalid="true" aria-describedby="cmp-error-' . e($key) . '"') : '';
    $routes = \Illuminate\Support\Facades\Route::has('compliance.settings.save');
    $group = (string) $value('device_group');
    $groups = $c['groups'] ?? [];
    $last = $c['lastRun'] ?? null;
    $example = "- id: http-server-off\n  title: HTTP server off\n  severity: warning\n  os: [ios, iosxe]\n  must_not: '^ip http server\$'\n"
        . "- id: access-storm-control\n  title: Storm control on access ports\n  section: '^interface '\n  when: '^switchport mode access\$'\n  unless: '^shutdown\$'\n"
        . "  ports: host\n  must: '^storm-control broadcast level'\n  fix: storm-control broadcast level 1.00 on each access port\n";
@endphp
@if($routes)
    <link href="{{ route('compliance.asset', 'compliance.css') }}?v={{ \Agoodley\LibrenmsCompliance\Http\Controllers\Controller::assetVersion() }}" rel="stylesheet">
@endif
<div class="cmp-settings" id="cmp-settings" style="max-width: 980px">
@if(! ($c['admin'] ?? false))
    <p class="text-muted" id="cmp-settings-admins">The compliance plugin's settings are for LibreNMS admins.</p>
@elseif(! $routes)
    <div class="alert alert-info">Enable the plugin in Plugin Admin first: its settings are saved through its own pages.</div>
@else
    @if($c['error'])
        <div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> {{ $c['error'] }}</div>
    @endif
    @if($c['backups'])
        <div class="alert alert-danger" id="cmp-backups-unavailable"><i class="fa fa-exclamation-triangle"></i> {{ $c['backups'] }}</div>
    @endif

    <p class="cmp-help" style="margin: 0 0 10px">The plugin checks the latest configuration backup of each device, as LibreNMS' Config tab shows it (its Oxidized, RANCID
        or Unimus settings). <a href="{{ route('compliance.overview') }}">Overview</a> · <a href="{{ route('compliance.rules') }}">Rules</a></p>
    <p id="cmp-settings-last-run" class="cmp-help" style="margin: 0 0 12px">
        @if($last)
            Last check of every device: {{ Clock::show((int) $last['started']) }} ({{ $last['devices'] }} devices: {{ $last['checked'] }} checked, {{ $last['no_backup'] }} without a
            backup, {{ $last['errors'] }} not checked; {{ $last['findings'] }} findings).
            @if($last['error'])<span class="text-danger">{{ $last['error'] }}</span>@endif
        @else
            The devices were not checked yet.
        @endif
        A check of one device: its page's Check now, or <span class="cmp-code">./lnms compliance:check --device=&lt;hostname&gt;</span>; of every device now:
        <span class="cmp-code">./lnms compliance:check --force</span>.
    </p>

    @if($saved)
        <div class="alert alert-success" id="cmp-settings-saved">Saved. The next scheduled check (every hour) checks every device with these settings.</div>
    @endif
    @if(isset($errors_['']))
        <div class="alert alert-danger">{{ $errors_[''] }}</div>
    @elseif($errors_)
        <div class="alert alert-danger" id="cmp-settings-errors">Nothing was saved: {{ count($errors_) === 1 ? 'a setting is' : count($errors_) . ' settings are' }} wrong, see below.</div>
    @endif

    <form method="post" action="{{ route('compliance.settings.save') }}" id="cmp-settings-form">
        @csrf
        <fieldset>
            <legend>Checks</legend>
            <div class="cmp-fields">
                <div class="form-group">
                    <label for="cmp-interval">Check every (hours)</label>
                    <input type="number" min="{{ PluginSettings::LIMITS['interval_hours'][0] }}" max="{{ PluginSettings::LIMITS['interval_hours'][1] }}" class="form-control{{ $invalid('interval_hours') }}"
                        id="cmp-interval" name="interval_hours" value="{{ $value('interval_hours') }}" {{ $describedBy('interval_hours') }}>
                    <span class="help-block">every device, by the hourly schedule of LibreNMS</span>
                    {{ $fieldError('interval_hours') }}
                </div>
                <div class="form-group cmp-wide">
                    <label for="cmp-group">Devices</label>
                    <select class="form-control{{ $invalid('device_group') }}" id="cmp-group" name="device_group" {{ $describedBy('device_group') }}>
                        <option value="">every enabled device</option>
                        @foreach($groups as $name)
                            <option value="{{ $name }}" @selected($group === $name)>the enabled devices of the device group {{ $name }}</option>
                        @endforeach
                        @if($group !== '' && ! in_array($group, $groups, true))
                            <option value="{{ $group }}" selected>{{ $group }} (LibreNMS no longer has it)</option>
                        @endif
                    </select>
                    <span class="help-block">a device without a configuration backup is listed as such; the results of a device no longer checked are forgotten</span>
                    {{ $fieldError('device_group') }}
                </div>
            </div>
            <div class="cmp-fields">
                <div class="form-group">
                    <label for="cmp-eventlog">Eventlog</label>
                    <select class="form-control{{ $invalid('eventlog') }}" id="cmp-eventlog" name="eventlog">
                        @foreach(PluginSettings::CHOICES['eventlog'] as $key => $label)
                            <option value="{{ $key }}" @selected((string) $value('eventlog') === (string) $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <span class="help-block">new and resolved findings in the device's eventlog (type compliance)</span>
                    {{ $fieldError('eventlog') }}
                </div>
                <div class="form-group">
                    <label for="cmp-minimum">Findings in the eventlog</label>
                    <select class="form-control{{ $invalid('eventlog_minimum') }}" id="cmp-minimum" name="eventlog_minimum">
                        @foreach(PluginSettings::CHOICES['eventlog_minimum'] as $key => $label)
                            <option value="{{ $key }}" @selected((string) $value('eventlog_minimum') === (string) $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    {{ $fieldError('eventlog_minimum') }}
                </div>
            </div>
        </fieldset>

        <fieldset id="cmp-builtin">
            <legend>The plugin's rules</legend>
            <p class="cmp-help">For Cisco IOS, IOS-XE and NX-OS and Arista EOS. <a href="{{ route('compliance.rules') }}">What each checks</a>.</p>
            @foreach(BuiltinRules::RULES as $id => [$title, $severity])
                <label class="cmp-rule-toggle"><input type="checkbox" name="enabled_rules[]" value="{{ $id }}" @checked(! isset($disabled[$id]))>
                    {{ $title }} <span class="cmp-muted">({{ $severity }})</span></label>
            @endforeach
        </fieldset>

        <fieldset id="cmp-servers">
            <legend>Servers</legend>
            <p class="cmp-help">Addresses or host names, separated by spaces or commas, as the configurations name them. Without any, the rule wants at least one.</p>
            @foreach(['ntp_servers' => 'NTP servers', 'syslog_servers' => 'Syslog servers', 'aaa_servers' => 'TACACS+ / RADIUS servers'] as $key => $label)
                <div class="form-group">
                    <label for="cmp-{{ $key }}">{{ $label }}</label>
                    <input class="form-control cmp-code{{ $invalid($key) }}" id="cmp-{{ $key }}" name="{{ $key }}" value="{{ str_replace("\n", ' ', (string) $value($key)) }}" {{ $describedBy($key) }}>
                    {{ $fieldError($key) }}
                </div>
            @endforeach
        </fieldset>

        <fieldset id="cmp-custom">
            <legend>Your rules</legend>
            <p class="cmp-help">YAML: a list of rules, each checking the lines of the configuration (without their indentation) with regular expressions: a pattern
                without delimiters is matched anywhere in a line, ignoring case; one between delimiters (<span class="cmp-code">/…/</span>, <span class="cmp-code">#…#</span>,
                <span class="cmp-code">~…~</span>, with flags) as written. <span class="cmp-code">must</span>: each pattern matches a line;
                <span class="cmp-code">must_not</span>: no line matches one. With a <span class="cmp-code">section</span> (the first line of a block, such as
                <span class="cmp-code">^interface </span>) each such block is checked, filtered by <span class="cmp-code">when</span>, <span class="cmp-code">unless</span> and, for
                interfaces, <span class="cmp-code">ports</span> (what they face: host, switch, upstream, downstream). <span class="cmp-code">os</span> limits a rule to LibreNMS os
                names; <span class="cmp-code">severity</span> is critical, warning (the default) or info.</p>
            @if($c['customErrors'] && ! isset($errors_['custom_rules']))
                <div class="alert alert-danger">{{ implode(' ', $c['customErrors']) }}</div>
            @endif
            <div class="form-group">
                <label for="cmp-custom-rules" class="sr-only">Your rules (YAML)</label>
                <textarea class="form-control cmp-code{{ $invalid('custom_rules') }}" id="cmp-custom-rules" name="custom_rules" rows="12" spellcheck="false"
                    placeholder="{{ $example }}" {{ $describedBy('custom_rules') }}>{{ $value('custom_rules') }}</textarea>
                {{ $fieldError('custom_rules') }}
            </div>
            <details><summary class="cmp-help">An example</summary><pre class="cmp-evidence">{{ $example }}</pre></details>
        </fieldset>

        <button type="submit" class="btn btn-primary" id="cmp-settings-save"><i class="fa fa-save"></i> Save</button>
    </form>
@endif
</div>
