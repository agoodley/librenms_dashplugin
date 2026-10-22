{{-- The plugin's page in Plugin Admin (Hooks\Page, admins only): where its pages are. --}}
<div style="margin: 15px; max-width: 800px;">
    <h4>Ansible: change automation through Semaphore UI</h4>
    <p>Run reviewed Ansible templates of the runner on LibreNMS devices, follow them live, and keep an audit log
        (also in each device's eventlog).</p>
    <ul>
        <li><a href="{{ route('ansible.runs') }}">Runs</a>: the audit log, and new runs</li>
        <li><a href="{{ route('ansible.inventory') }}">Inventory</a>: what the runner gets from LibreNMS, and the setup steps</li>
        <li><a href="{{ url('plugin/settings/ansible') }}">Settings</a>: the runner connection, the targets and who may launch runs</li>
    </ul>
</div>
