{{-- The plugin's page in Plugin Admin (Hooks\Page, admins only): where its pages are. --}}
<div style="margin: 15px; max-width: 800px;">
    <h4>Ansible: change automation through Semaphore UI</h4>
    <p>Prepare changes from reviewed Ansible templates of the runner, dry-run them with the diff per device, have them
        approved, and apply them in batches with Oxidized backups and LibreNMS maintenance windows; follow them live,
        and keep an audit log (also in each device's eventlog).</p>
    <ul>
        <li><a href="{{ route('ansible.changes') }}">Changes</a>: prepared, dry-run, approved and applied changes, and new ones</li>
        <li><a href="{{ route('ansible.runs') }}">Runs</a>: the audit log of the runner's tasks (dry runs and batches)</li>
        <li><a href="{{ route('ansible.inventory') }}">Inventory</a>: what the runner gets from LibreNMS, and the setup steps</li>
        <li><a href="{{ url('plugin/settings/ansible') }}">Settings</a>: the runner connection, the targets, how changes go, and who may run and approve them</li>
    </ul>
</div>
