"""device-logins.sh's parts without a server: its CSV file (lib/device_logins.py), the groups of Oxidized's configuration
(ox_config, ox_config_values), and the variable group's secrets (semaphore_api.py logins against a fake API)."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROCKY = Path(__file__).resolve().parents[1]


def device(id, hostname, sysname='', ansible=None, oxidized=None, left_out=None, name=None):
    return {'id': id, 'hostname': hostname, 'sysname': sysname, 'disabled': False,
            'ansible': {'name': name or hostname, 'host': ansible, 'port': 22} if ansible else None, 'ansible_left_out': left_out,
            'inventory_name': name or hostname, 'oxidized': {'ip': oxidized, 'port': 22} if oxidized else None}


DEVICES = [device(12, 'sw5.example.com', 'SW5', ansible='sw5.example.com', oxidized='10.0.0.5'),
           device(15, 'sw9', 'core', ansible='10.0.0.9', oxidized='10.0.0.9'),
           device(16, 'sw10', 'core', ansible='10.0.0.10', oxidized='10.0.0.10'),
           device(20, 'srv1', ansible=None, oxidized='10.0.0.20', left_out='no_mapping')]


class CsvTests(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.dir = Path(tmp.name)
        (self.dir / 'devices.json').write_text(json.dumps({'devices': DEVICES}))
        self.state = {'salt': 'pepper', 'devices': {}}

    def run_logins(self, text):
        (self.dir / 'logins.csv').write_bytes(text.encode('utf-8'))
        (self.dir / 'state.json').write_text(json.dumps(self.state))
        out = self.dir / 'out.json'
        if out.exists():
            out.unlink()
        proc = subprocess.run([sys.executable, '-I', str(ROCKY / 'lib/device_logins.py'), '--devices', str(self.dir / 'devices.json'),
                               '--file', str(self.dir / 'logins.csv'), '--state', str(self.dir / 'state.json'), '--out', str(out),
                               '--host-vars', str(self.dir / 'host_vars'), '--default-user', 'netops'], capture_output=True, text=True)
        result = json.loads(out.read_text()) if out.exists() else None
        return proc.returncode, proc.stdout + proc.stderr, result

    def test_a_file_with_every_form_of_line(self):
        rc, out, result = self.run_logins('﻿Device , Username,Password,Enable,Site\n'
                                          '# a comment, then a blank line\n\n'
                                          'sw5.example.com,localadmin,"pa,ss ""word""",en4ble,London\n'
                                          '15,admin2, spaced ,,Paris\n'
                                          '10.0.0.20,srvadmin,s3rver,\n')
        self.assertEqual(rc, 0, out)
        logins = {x['id']: x for x in result['logins']}
        self.assertEqual(logins[12]['password'], 'pa,ss "word"')
        self.assertEqual(logins[12]['enable'], 'en4ble')
        self.assertEqual(logins[15]['password'], ' spaced ')            # a password keeps its spaces
        self.assertEqual(logins[15]['enable'], ' spaced ')              # no enable: the password itself
        self.assertEqual(logins[12]['group'], 'login-12')
        self.assertEqual(logins[12]['secrets'], ['DEVICE_LOGIN_12_PASSWORD', 'DEVICE_LOGIN_12_ENABLE'])
        self.assertEqual({x['status'] for x in result['logins']}, {'new'})
        self.assertIn('the columns site are left out', out)
        self.assertIn('the others log in with the default one (netops)', out)
        self.assertIn("srv1 (LibreNMS device 20): srvadmin, new; Ansible does not connect to it now (its os has no line in the Ansible plugin's platform mapping)", out)
        for secret in ('pa,ss', 'en4ble', 'spaced', 's3rver'):
            self.assertNotIn(secret, out)
        self.assertEqual((self.dir / 'out.json').stat().st_mode & 0o777, 0o600)
        host_vars = (self.dir / 'host_vars/sw5.example.com.yml').read_text()
        self.assertTrue(host_vars.startswith('# device-logins.sh: the login of sw5.example.com (LibreNMS device 12, sysName SW5)'))
        self.assertIn('ansible_user: "localadmin"\n', host_vars)
        self.assertIn("ansible_password: \"{{ lookup('ansible.builtin.env', 'DEVICE_LOGIN_12_PASSWORD') }}\"\n", host_vars)
        self.assertIn("ansible_become_password: \"{{ lookup('ansible.builtin.env', 'DEVICE_LOGIN_12_ENABLE') }}\"\n", host_vars)
        self.assertNotIn('pa,ss', host_vars)
        self.assertEqual(sorted(result['host_vars']), ['srv1.yml', 'sw5.example.com.yml', 'sw9.yml'])

    def test_problems_are_all_said_and_nothing_is_written(self):
        rc, out, result = self.run_logins('device,username,password\n'
                                          'sw77,a,b\n'                       # no such device
                                          'core,a,b\n'                       # the sysName of two devices
                                          'sw5.example.com,a,b\n'
                                          '12,c,d\n'                         # sw5 again, by its id
                                          'sw9,,b\n'                         # no username
                                          'sw10,a{{x}},b\n'                  # a username Ansible would template
                                          '10.0.0.20,a,"b\tc"\n'             # a tab in the password
                                          '10.0.0.5,a,b,extra\n')            # more values than columns
        self.assertEqual(rc, 2, out)
        self.assertIsNone(result)
        for text in ('line 2: LibreNMS has no device sw77', 'line 3: core names 2 devices (sw9, sw10)', 'line 5: sw5.example.com again (line 4',
                     'line 6: sw9 needs a username and a password', 'line 7: the username of sw10', 'line 8: the password of srv1 has a control character',
                     'line 9: more values than the header has columns'):
            self.assertIn(text, out)
        rc, out, _ = self.run_logins('host,user,password\nsw9,a,b\n')
        self.assertEqual(rc, 2)
        self.assertIn('the header has no column device, username', out)
        rc, out, _ = self.run_logins('# only a comment\n')
        self.assertEqual(rc, 2)
        self.assertIn('has no header line', out)

    def test_what_changed_since_the_last_run(self):
        rc, out, result = self.run_logins('device,username,password,enable\nsw9,admin2,one,\nsw10,admin3,two,\n')
        self.assertEqual(rc, 0, out)
        self.state['devices'] = {str(x['id']): x['hash'] for x in result['logins']}
        self.state['devices']['99'] = 'deadbeef'                          # a device LibreNMS no longer has
        rc, out, result = self.run_logins('device,username,password,enable\nsw9,admin2,one,\nsw10,admin3,TWO,\n')
        self.assertEqual({x['id']: x['status'] for x in result['logins']}, {15: 'as it was', 16: 'login changed'})
        self.assertEqual([r['id'] for r in result['removed']], [99])
        self.assertIn('LibreNMS device 99 (gone): back to the default login', out)
        rc, out, result = self.run_logins('device,username,password,enable\n')
        self.assertEqual(sorted(r['id'] for r in result['removed']), [15, 16, 99])
        self.assertIn('sw9 (LibreNMS device 15, sysName core): back to the default login (no line in', out)


class OxidizedConfigTests(unittest.TestCase):
    """ox_config writes the groups and ox_config_values reads them back: a run of the installer without device-logins.sh
    writes the same configuration again."""

    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.work = Path(tmp.name)

    def bash(self, script, **env):
        bootstrap = '''
for lib in common librenms plugins semaphore oxidized; do . "$ROCKY/lib/$lib.sh"; done
WORK=$TEST_WORK; STATE_FILE=$WORK/state; FILES_DIR=$ROCKY/files
OX_HOME=$WORK/oxidized; OX_DIR=$OX_HOME/.config/oxidized; OX_CONF=$OX_DIR/config
mkdir -p "$OX_DIR"
DEVICE_USER=netops; DEVICE_PASSWORD=fixture; DEVICE_ENABLE_PASSWORD=enable-fixture
LIBRENMS_RESOLVED=http://localhost; OX_TOKEN=fixture-token; SERVER_NAME=lnms.example.com
OXIDIZED_LISTEN=127.0.0.1; OXIDIZED_PORT=8888
as() { shift; "$@"; }
put_text() { printf '%s' "$1" >"$2"; }
'''
        result = subprocess.run(['bash', '-euo', 'pipefail', '-c', bootstrap + script], text=True, capture_output=True,
                                env={**os.environ, 'ROCKY': str(ROCKY), 'TEST_WORK': str(self.work), **env})
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return result.stdout

    GROUPS = [{'name': 'login-12', 'device': 'sw5.example.com (LibreNMS device 12)', 'username': 'local admin',
               'password': 'p"a\\ss: #wörd', 'enable': "it's"},
              {'name': 'login-15', 'device': 'sw9 (LibreNMS device 15)\nlater', 'username': 'admin2', 'password': 'two', 'enable': 'two'}]

    def test_the_groups_are_written_and_read_back(self):
        out = self.bash('''
OX_GROUPS=$TEST_GROUPS ox_config
cp "$OX_CONF" "$WORK/first"
ox_config_values
state_set oxidized_config "$(sha256sum "$OX_CONF" | cut -d' ' -f1)"
OX_GROUPS=''; DEVICE_USER=''; DEVICE_PASSWORD=''; DEVICE_ENABLE_PASSWORD=''
ox_config >/dev/null
[[ $(sha256sum <"$OX_CONF") == "$(sha256sum <"$WORK/first")" ]] && echo SAME
''', TEST_GROUPS=json.dumps(self.GROUPS))
        lines = out.splitlines()
        values = json.loads(lines[0])
        self.assertIn('SAME', lines)
        self.assertEqual([{k: g[k] for k in ('name', 'username', 'password', 'enable')} for g in values['groups']],
                         [{k: g[k] for k in ('name', 'username', 'password', 'enable')} for g in self.GROUPS])
        self.assertEqual(values['groups'][1]['device'], 'sw9 (LibreNMS device 15)?later')    # a comment of one line
        self.assertEqual((values['username'], values['librenms_url'], values['token'], values['listen'], values['port'], values['server']),
                         ('netops', 'http://localhost', 'fixture-token', '127.0.0.1', '8888', 'lnms.example.com'))
        config = (self.work / 'oxidized/.config/oxidized/config').read_text()
        self.assertIn('  git:\n    user: Oxidized\n', config)
        self.assertIn('    single_repo: true\n', config)
        self.assertIn('  login-12:\n    username: "local admin"\n    password: "p\\"a\\\\ss: #w\\u00f6rd"\n    vars:\n      enable: "it\'s"\n', config)
        # the default login stays the configuration's own (its lines do not take the groups' for theirs)
        self.assertIn('\nusername: "netops"\npassword: "fixture"\n', config)

    def test_no_groups_no_section_and_a_bad_name_is_refused(self):
        self.bash('OX_GROUPS="[]" ox_config')
        config = (self.work / 'oxidized/.config/oxidized/config').read_text()
        self.assertNotIn('groups:', config)
        self.assertIn('    single_repo: true\n', config)
        result = subprocess.run(['bash', '-c', '. "$ROCKY/lib/common.sh"; . "$ROCKY/lib/librenms.sh"; . "$ROCKY/lib/oxidized.sh"; '
                                 'OX_CONF=/nonexistent; WORK=$TEST_WORK; DEVICE_USER=a; DEVICE_PASSWORD=b; DEVICE_ENABLE_PASSWORD=c; '
                                 'LIBRENMS_RESOLVED=x; OX_TOKEN=x; OXIDIZED_LISTEN=x; OXIDIZED_PORT=1; SERVER_NAME=x; '
                                 'OX_GROUPS=\'[{"name": "x: y", "username": "a", "password": "b", "enable": "c"}]\'; ox_config'],
                                text=True, capture_output=True, env={**os.environ, 'ROCKY': str(ROCKY), 'TEST_WORK': str(self.work)})
        self.assertIn('is not a group of device-logins.sh', result.stdout + result.stderr)


class SemaphoreSecretTests(unittest.TestCase):
    def setUp(self):
        sys.path.insert(0, str(ROCKY / 'lib'))
        try:
            import semaphore_api
        finally:
            sys.path.pop(0)
        self.api = semaphore_api
        semaphore_api.changes.clear()
        self.calls = []
        self.secrets = [{'id': 1, 'name': 'LIBRENMS_TOKEN', 'type': 'env'}, {'id': 2, 'name': 'DEVICE_LOGIN_15_PASSWORD', 'type': 'env'},
                        {'id': 3, 'name': 'DEVICE_LOGIN_15_ENABLE', 'type': 'env'}, {'id': 4, 'name': 'DEVICE_LOGIN_99_PASSWORD', 'type': 'env'}]

        def call(api, method, path, body=None, ok=None):
            self.calls.append((method, path, body))
            if path == '/projects':
                return [{'id': 7, 'name': 'LibreNMS changes'}]
            if path == '/project/7/environment':
                return [{'id': 3, 'name': 'LibreNMS'}]
            if method == 'GET' and path == '/project/7/environment/3':
                return {'id': 3, 'name': 'LibreNMS', 'json': '{"ansible_host_key_checking": true}', 'env': '{"LIBRENMS_URL": "x"}',
                        'secrets': self.secrets}
            return None
        self.original = semaphore_api.Api.call
        semaphore_api.Api.call = call
        self.addCleanup(setattr, semaphore_api.Api, 'call', self.original)

    def request(self, **extra):
        return dict({'url': 'http://127.0.0.1:3000', 'token': 't', 'project': 'LibreNMS changes', 'environment': 'LibreNMS',
                     'prefix': 'DEVICE_LOGIN_', 'secrets': {'DEVICE_LOGIN_12_PASSWORD': 'p12', 'DEVICE_LOGIN_12_ENABLE': 'e12',
                                                            'DEVICE_LOGIN_15_PASSWORD': 'p15', 'DEVICE_LOGIN_15_ENABLE': 'e15'},
                     'changed': ['DEVICE_LOGIN_12_PASSWORD', 'DEVICE_LOGIN_12_ENABLE', 'DEVICE_LOGIN_15_ENABLE']}, **extra)

    def test_secrets_are_made_set_again_and_removed(self):
        self.api.logins(self.request())
        puts = [c for c in self.calls if c[0] == 'PUT']
        self.assertEqual(len(puts), 1)
        body = puts[0][2]
        ops = sorted((s['operation'], s['name'], s.get('secret'), s.get('id')) for s in body['secrets'])
        self.assertEqual(ops, [('create', 'DEVICE_LOGIN_12_ENABLE', 'e12', None), ('create', 'DEVICE_LOGIN_12_PASSWORD', 'p12', None),
                               ('delete', 'DEVICE_LOGIN_99_PASSWORD', None, 4), ('update', 'DEVICE_LOGIN_15_ENABLE', 'e15', 3)])
        # the group's variables and its other secrets stay as they are
        self.assertEqual((body['json'], body['env']), ('{"ansible_host_key_checking": true}', '{"LIBRENMS_URL": "x"}'))
        self.assertNotIn('LIBRENMS_TOKEN', [s['name'] for s in body['secrets']])
        self.assertFalse(any('p12' in c or 'e15' in c for c in self.api.changes))

    def test_nothing_to_do_and_a_foreign_name(self):
        self.secrets[:] = [{'id': 2, 'name': 'DEVICE_LOGIN_15_PASSWORD', 'type': 'env'}, {'id': 3, 'name': 'DEVICE_LOGIN_15_ENABLE', 'type': 'env'}]
        self.api.logins(self.request(secrets={'DEVICE_LOGIN_15_PASSWORD': 'p', 'DEVICE_LOGIN_15_ENABLE': 'e'}, changed=[]))
        self.assertFalse([c for c in self.calls if c[0] == 'PUT'])
        with self.assertRaises(self.api.ApiError):
            self.api.logins(self.request(secrets={'LIBRENMS_TOKEN': 'x'}))


if __name__ == '__main__':
    unittest.main()
