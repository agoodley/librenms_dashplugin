"""Installer regressions using real functions and isolated command/filesystem fixtures; no server required."""
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import unittest


ROCKY = Path(__file__).resolve().parents[1]


class InstallerSecurityTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.work = Path(self.tmp.name)

    def bash(self, script, **env):
        bootstrap = '''
for lib in common librenms plugins semaphore oxidized; do . "$ROCKY/lib/$lib.sh"; done
WORK=$TEST_WORK; STATE_FILE=$WORK/state; FILES_DIR=$ROCKY/files
LNMS_DIR=$WORK/live; mkdir -p "$LNMS_DIR"
'''
        result = subprocess.run(
            ['bash', '-euo', 'pipefail', '-c', bootstrap + script], text=True, capture_output=True,
            env={**os.environ, 'ROCKY': str(ROCKY), 'TEST_WORK': str(self.work), **env})
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return result.stdout

    def test_firewall_reconciles_each_state_independently(self):
        for item in ['http', '3000/tcp']:
            for runtime in [False, True]:
                for permanent in [False, True]:
                    for check in [False, True]:
                        with self.subTest(item=item, runtime=runtime, permanent=permanent, check=check):
                            out = self.bash('''
CHECK=$TEST_CHECK
firewalld_active() { return 0; }
firewall-cmd() {
    if [[ $1 == --get-default-zone ]]; then echo public; return; fi
    local scope=runtime present=$RUNTIME
    if [[ " $* " == *' --permanent '* ]]; then scope=permanent; present=$PERMANENT; fi
    if [[ " $* " == *' --query-'* ]]; then [[ $present == 1 ]]; return; fi
    printf 'ADD %s %s\n' "$scope" "$*"
}
run() { "$@"; }
firewall_open "$ITEM"
''', ITEM=item, RUNTIME=str(int(runtime)), PERMANENT=str(int(permanent)), TEST_CHECK=str(int(check)))
                            adds = [line for line in out.splitlines() if line.startswith('ADD ')]
                            self.assertEqual(len(adds), 0 if check else int(not runtime) + int(not permanent))
                            if not check:
                                self.assertEqual(any('ADD runtime ' in x for x in adds), not runtime)
                                self.assertEqual(any('ADD permanent ' in x for x in adds), not permanent)
                            self.assertNotIn('runtime-to-permanent', out)

    def test_listener_urls_reach_bound_socket(self):
        cases = [('127.0.0.1', '127.0.0.1'), ('127.0.0.2', '127.0.0.2'),
                 ('0.0.0.0', '127.0.0.1'), ('::1', '::1'), ('::', '::1')]
        for bind, connect in cases:
            with self.subTest(bind=bind):
                family = socket.AF_INET6 if ':' in bind else socket.AF_INET
                try:
                    server = socket.socket(family)
                    server.bind((bind, 0))
                except OSError:
                    server.close()
                    if family == socket.AF_INET6:
                        continue  # CI/container kernel may disable IPv6.
                    raise
                with server:
                    server.listen()
                    port = server.getsockname()[1]
                    url = self.bash('local_service_url "$BIND" "$PORT"', BIND=bind, PORT=str(port))
                    host = '[' + connect + ']' if ':' in connect else connect
                    self.assertEqual(url, f'http://{host}:{port}')
                    with socket.create_connection((connect, port), timeout=2):
                        peer, _ = server.accept()
                        peer.close()

    def test_plugin_connection_uses_the_same_semaphore_url(self):
        self.bash('''
SEMAPHORE_LISTEN=::1; SEMAPHORE_PORT=3000; SEM_API=$(semaphore_api_url)
SEM_RUNNER_TOKEN=fixture; SEM_PROJECT_ID=1; SEM_INVENTORY_ID=2; LNMS_INVENTORY_ID=3
SEM_TEMPLATE_PORTS=4; SEM_TEMPLATE_STP=5; ADMIN_USER=admin
helper() { printf '%s' "$2" >"$WORK/request.json"; HELPER_OUT='{"test":{"ok":false,"error":"fixture"}}'; }
ansible_connect
''')
        self.assertEqual(json.loads((self.work / 'request.json').read_text())['connection']['url'], 'http://[::1]:3000')

    def test_semaphore_bind_config_supports_ipv6_and_is_idempotent(self):
        for addr, expected in [('127.0.0.2', '127.0.0.2'), ('0.0.0.0', '0.0.0.0'), ('::1', '[::1]'), ('::', '[::]')]:
            with self.subTest(addr=addr):
                out = self.bash('''
SEM_CONF=$WORK/semaphore.json; SEM_HOME=$WORK/semaphore
SEM_DB_PASSWORD=fixture; SEMAPHORE_LISTEN=$ADDR; SEMAPHORE_PORT=3000
install() { :; }; as() { shift; "$@"; }
put_text() { printf '%s' "$1" >"$2"; }
sem_config
CHECK=1
sem_config
''', ADDR=addr)
                config = json.loads((self.work / 'semaphore.json').read_text())
                self.assertEqual(config['interface'], expected)
                self.assertEqual(config['port'], ':3000')
                self.assertNotIn('would change', out)

    def test_oxidized_requires_host_keys_and_preserves_credentials(self):
        self.bash('''
OX_HOME=$WORK/oxidized; OX_DIR=$OX_HOME/.config/oxidized; OX_CONF=$OX_DIR/config
mkdir -p "$OX_DIR"
DEVICE_USER=netops; DEVICE_PASSWORD=fixture; DEVICE_ENABLE_PASSWORD=enable-fixture
LIBRENMS_RESOLVED=http://localhost; OX_TOKEN=fixture-token; SERVER_NAME=fixture
OXIDIZED_LISTEN=::1; OXIDIZED_PORT=8888
install() { :; }
as() { shift; "$@"; }
put_text() { printf '%s' "$1" >"$2"; }
ox_config
DEVICE_USER=; DEVICE_PASSWORD=; DEVICE_ENABLE_PASSWORD=
ox_config
ox_previous_url >"$WORK/legacy-url"
state_set oxidized_url 'http://[::1]:8888'
ox_previous_url >"$WORK/saved-url"
''')
        content = (self.work / 'oxidized/.config/oxidized/config').read_text()
        self.assertIn('  ssh:\n    secure: true\n', content)
        # net-ssh would ask an RSA key for ssh-rsa (SHA-1) first, which Rocky's crypto policy refuses to verify
        order = [line for line in content.splitlines() if line.startswith('  ssh_host_key: ')]
        self.assertEqual(order, ['  ssh_host_key: "rsa-sha2-512,rsa-sha2-256,ssh-ed25519,ecdsa-sha2-nistp256,'
                                 'ecdsa-sha2-nistp384,ecdsa-sha2-nistp521"'])
        self.assertIn('password: "fixture"', content)
        self.assertIn('  enable: "enable-fixture"', content)
        self.assertEqual((self.work / 'legacy-url').read_text(), 'http://127.0.0.1:8888')
        self.assertEqual((self.work / 'saved-url').read_text(), 'http://[::1]:8888')

    def test_privileged_files_never_come_from_the_live_tree(self):
        self.bash('''
mkdir -p "$LNMS_DIR/dist" "$LNMS_DIR/misc"
for f in dist/librenms.cron dist/librenms-scheduler.service dist/librenms-scheduler.timer misc/lnms-completion.bash misc/librenms.logrotate snmpd.conf.example; do
    printf 'UNTRUSTED ROOT COMMAND\n' >"$LNMS_DIR/$f"
done
put_file() { printf '%s|%s\n' "$1" "$2" >>"$WORK/copies"; }
put_text() { printf '%s' "$1" >"$WORK/snmp"; }
run() { :; }; act() { :; }; service_enable_start() { :; }; restart_if_pending() { :; }; lnms_cron_account() { :; }
rpm() { :; }; secret_for() { echo fixture-community; }
DISTRO_SOURCE=; OFFLINE_DIR=$WORK
download() { echo 'unexpected download in isolated fixture' >&2; return 99; }
install() { echo 'unexpected install in isolated fixture' >&2; return 99; }
lnms_completion; lnms_cron_scheduler; lnms_logrotate; lnms_snmpd
''')
        copies = (self.work / 'copies').read_text().splitlines()
        self.assertEqual(len(copies), 6)
        for entry in copies:
            source, dest = entry.split('|')
            self.assertEqual(Path(source).parent, ROCKY / 'files', dest)
            self.assertNotIn('UNTRUSTED', Path(source).read_text())
        self.assertNotIn('UNTRUSTED', (self.work / 'snmp').read_text())
        self.assertIn('fixture-community', (self.work / 'snmp').read_text())

    def test_pip_requirements_execute_only_as_service_user(self):
        out = self.bash('''
PIP_WHEELS=
probe() { return 1; }
act() { shift; printf '%s\n' "$*"; }
lnms_python
''')
        self.assertIn('as librenms python3 -m pip install --user ', out)

    def test_git_status_hook_runs_through_service_identity(self):
        self.bash(r'''
PLUGIN_CLONE=$WORK/git; PLUGIN_SOURCE=$PLUGIN_CLONE
git init -q "$PLUGIN_CLONE"
git -C "$PLUGIN_CLONE" -c user.name=fixture -c user.email=fixture@example.invalid commit -q --allow-empty -m fixture
cat >"$WORK/hook" <<'HOOK'
#!/bin/bash
printf '%s' "${TEST_IDENTITY:-root}" >"$TEST_WORK/hook-result"
printf 'token\0'
HOOK
chmod +x "$WORK/hook"
git -C "$PLUGIN_CLONE" config core.fsmonitor "$WORK/hook"
as() { local identity=$1; shift; TEST_IDENTITY=$identity "$@"; }
clone_git status --porcelain --untracked-files=no
source_git status --porcelain --untracked-files=no
''')
        self.assertEqual((self.work / 'hook-result').read_text(), 'librenms')

    def test_database_password_remains_a_single_sql_literal(self):
        password = "quote'\\; DROP USER 'fixture'; --\n\\! echo UNTRUSTED\nend"
        out = self.bash('sql_literal "$PASSWORD"', PASSWORD=password)
        self.assertEqual(out, "'" + password.replace("'", "''") + "'")
        self.bash('''
mysql() { return 0; }
run_input() { printf '%s' "$1" >"$WORK/sql"; shift; printf '%s\n' "$*" >"$WORK/mysql-command"; }
mysql_root "SELECT $(sql_literal "$PASSWORD");"
''', PASSWORD=password)
        self.assertEqual((self.work / 'mysql-command').read_text().strip(), 'mysql --binary-mode -u root')
        self.assertEqual((self.work / 'sql').read_text(), "SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES';\nSELECT " + out + ';')

    KEY = '10.0.0.1,sw1 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl\n'

    def test_known_hosts_files_are_checked(self):
        cases = {
            'keys': (self.KEY + '# a comment\n\n[10.0.0.2]:2222 ssh-rsa AAAAB3NzaC1yc2E=\n', True),
            'marker': ('@revoked * ssh-rsa AAAAB3NzaC1yc2E=\n', True),
            'comments only': ('# nothing\n\n', False),
            'no key': ('10.0.0.1 ssh-ed25519\n', False),
            'not base64': ('10.0.0.1 ssh-ed25519 not-a-key!\n', False),
        }
        for name, (text, ok) in cases.items():
            with self.subTest(name=name):
                (self.work / 'kh').write_text(text)
                out = self.bash('if known_hosts_ok "$WORK/kh"; then echo yes; else echo no; fi')
                self.assertEqual(out.strip(), 'yes' if ok else 'no')
        (self.work / 'kh').write_text(self.KEY + '# a comment\n')
        self.assertEqual(self.bash('known_hosts_keys "$WORK/kh"').strip(), '1')

    def test_device_known_hosts_are_the_system_file_and_a_servers_own_stays(self):
        (self.work / 'keys').write_text(self.KEY)
        out = self.bash(r"""
KNOWN_HOSTS_FILE=$WORK/etc-ssh/ssh_known_hosts; DEVICE_KNOWN_HOSTS=$WORK/keys
put_file() { printf 'PUT %s %s %s\n' "$2" "$3" "$4"; mkdir -p "${2%/*}"; cp "$1" "$2"; }
act() { shift; "$@"; }
device_known_hosts; device_known_hosts
cmp "$DEVICE_KNOWN_HOSTS" "$KNOWN_HOSTS_FILE" && echo SAME
KNOWN_HOSTS_DONE=0; device_known_hosts 2>&1
echo '10.9.9.9 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl' >>"$KNOWN_HOSTS_FILE"
KNOWN_HOSTS_DONE=0; device_known_hosts 2>&1
grep -c . "$KNOWN_HOSTS_FILE"
DEVICE_KNOWN_HOSTS=; KNOWN_HOSTS_FILE=$WORK/none; KNOWN_HOSTS_DONE=0; device_known_hosts 2>&1
""")
        lines = out.splitlines()
        puts = [line for line in lines if line.startswith('PUT ')]
        # once a run, and in a later run (where put_file finds it up to date); the file the admin changed stays
        self.assertEqual(len(puts), 2, out)
        self.assertTrue(puts[0].endswith('etc-ssh/ssh_known_hosts root:root 644'), puts[0])
        self.assertIn('SAME', lines)
        self.assertTrue(any("is this server's own: left as it is" in line for line in lines), out)
        self.assertIn('2', lines)
        self.assertTrue(any('refuse devices whose SSH host keys they do not know' in line for line in lines), out)

    def test_variable_group_gets_host_key_checking_when_it_has_none(self):
        import importlib.util
        spec = importlib.util.spec_from_file_location('semaphore_api', ROCKY / 'lib' / 'semaphore_api.py')
        api_mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(api_mod)
        want = {'name': 'LibreNMS', 'env': {'LIBRENMS_URL': 'http://localhost', 'ANSIBLE_CHECK_MODE_MARKERS': 'true'},
                'extra_vars': {'ansible_host_key_checking': True}, 'secret_env': {'LIBRENMS_TOKEN': 'secret'}}

        class Api:
            def __init__(self, existing):
                self.existing, self.calls = existing, []

            def call(self, method, path, body=None):
                self.calls.append((method, path, body))
                if method == 'GET' and path.endswith('/environment'):
                    return [dict(self.existing, id=7)] if self.existing else []
                if method == 'GET':
                    return dict(self.existing, id=7)
                return {'id': 7}

        secrets = [{'id': 1, 'type': 'env', 'name': 'LIBRENMS_TOKEN'}]
        env = json.dumps(want['env'])
        cases = [
            ('a new group', None, {'ansible_host_key_checking': True}),
            ('a group without it', {'name': 'LibreNMS', 'env': env, 'json': '{"other": 1}', 'secrets': secrets},
             {'other': 1, 'ansible_host_key_checking': True}),
            ("an admin's own value", {'name': 'LibreNMS', 'env': env, 'json': '{"ansible_host_key_checking": false}',
                                      'secrets': secrets}, None),
        ]
        for name, existing, sent in cases:
            with self.subTest(name=name):
                api_mod.changes.clear()
                api = Api(existing)
                self.assertEqual(api_mod.ensure_environment(api, 1, want), 7)
                writes = [body for method, _, body in api.calls if method in ('POST', 'PUT')]
                if sent is None:
                    self.assertEqual(writes, [])
                else:
                    self.assertEqual(len(writes), 1)
                    self.assertEqual(json.loads(writes[0]['json']), sent)
                    self.assertTrue(any('ansible_host_key_checking: true' in c for c in api_mod.changes), api_mod.changes)

    def test_wheels_librenms_cannot_read_come_from_a_copy(self):
        for readable in [False, True]:
            with self.subTest(readable=readable):
                for leftover in ('wheels', 'wheels-root'):
                    subprocess.run(['rm', '-rf', str(self.work / leftover)], check=True)
                out = self.bash(r"""
PIP_WHEELS=$WORK/wheels-root; mkdir -p "$PIP_WHEELS"; : >"$PIP_WHEELS/fixture.whl"
probe() { return 1; }
act() { shift; printf '%s\n' "$*"; }
readable_by() { [[ $TEST_READABLE == 1 ]]; }
id() { command id -gn; }  # the copy's group: librenms' (this test's user's here)
lnms_python
""", TEST_READABLE=str(int(readable)))
                where = self.work / ('wheels-root' if readable else 'wheels')
                self.assertIn('--find-links %s ' % where, out)
                self.assertTrue((where / 'fixture.whl').exists())
                self.assertEqual((self.work / 'wheels').exists(), not readable)
                if not readable:  # the group's, no one else's
                    self.assertEqual(oct((where / 'fixture.whl').stat().st_mode & 0o077), oct(0o040))

    def test_local_checkout_librenms_cannot_read_is_cloned_from_a_copy(self):
        out = self.bash(r"""
src=$WORK/root-checkout
git init -q -b main "$src"
git -C "$src" -c user.name=fixture -c user.email=fixture@example.invalid commit -q --allow-empty -m fixture
PLUGINS_DIR=$WORK/plugins; PLUGIN_CLONE=$PLUGINS_DIR/librenms_dashplugin; mkdir -p "$PLUGINS_DIR"
PLUGIN_SOURCE=$src; PLUGIN_BRANCH=; GITHUB_TOKEN_VALUE=
as() { shift; "$@"; }
install() { mkdir -p "${*: -1}"; }
readable_by() { [[ $2 != "$src" ]]; }  # librenms may not read the checkout (one in /root, say)
id() { command id -gn; }
plugins_clone
echo "origin $(git -C "$PLUGIN_CLONE" config --get remote.origin.url) branch $PLUGIN_BRANCH_USED"
git -C "$src" -c user.name=fixture -c user.email=fixture@example.invalid commit -q --allow-empty -m second
plugins_clone
echo "head $(git -C "$PLUGIN_CLONE" rev-parse HEAD) source $(git -C "$src" rev-parse HEAD)"
""")
        self.assertIn('origin %s branch main' % (self.work / 'root-checkout'), out)
        head = [line for line in out.splitlines() if line.startswith('head ')][0].split()
        self.assertEqual(head[1], head[3], out)
        self.assertNotIn('take the plugins from', out)


if __name__ == '__main__':
    unittest.main()
