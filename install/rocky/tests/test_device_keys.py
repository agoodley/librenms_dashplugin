"""device-keys.sh's core (lib/device_keys.py) against a fake ssh-keyscan: which keys it finds where, what it asks, and
what it writes to the file of verified keys. No server, no network."""
import base64
import hashlib
import hmac
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROCKY = Path(__file__).resolve().parents[1]

FAKE_KEYSCAN = r'''#!/usr/bin/env python3
# ssh-keyscan as device-keys.sh runs it (-T, -p, -t, -f -), answering from the JSON file $FAKE_KEYSCAN
import json, os, sys
args = sys.argv[1:]
port = int(args[args.index('-p') + 1]) if '-p' in args else 22
answers = json.load(open(os.environ['FAKE_KEYSCAN']))
with open(os.environ['FAKE_KEYSCAN'] + '.calls', 'a') as calls:
    calls.write(' '.join(args) + '\n')
for addr in sys.stdin.read().split():
    entry = answers.get('%s:%d' % (addr, port))
    if entry is None:
        print('connect (`%s\'): Connection timed out' % addr, file=sys.stderr)
        continue
    if entry.get('banner'):
        print('# %s:%d %s' % (addr, port, entry['banner']), file=sys.stderr)
    name = addr if port == 22 else '[%s]:%d' % (addr, port)
    for keytype, b64 in entry.get('keys', []):
        print('%s %s %s' % (name, keytype, b64))
'''


def ssh_string(data):
    return len(data).to_bytes(4, 'big') + data


def ed25519(seed):
    return 'ssh-ed25519', base64.b64encode(ssh_string(b'ssh-ed25519') + ssh_string(bytes([seed]) * 32)).decode()


def rsa(seed, bits=2048):
    n = (1 << (bits - 1)) | (seed * 2 + 1)
    raw = b'\0' + n.to_bytes(bits // 8, 'big')
    return 'ssh-rsa', base64.b64encode(ssh_string(b'ssh-rsa') + ssh_string(b'\x01\x00\x01') + ssh_string(raw)).decode()


def line(names, key):
    return '%s %s %s\n' % (names, key[0], key[1])


def device(id, hostname, ansible=None, oxidized=None, port=22, sysname='', disabled=False, left_out=None):
    return {'id': id, 'hostname': hostname, 'sysname': sysname, 'disabled': disabled,
            'ansible': {'name': hostname, 'host': ansible, 'port': 22} if ansible else None,
            'ansible_left_out': left_out,
            'oxidized': {'ip': oxidized, 'port': port} if oxidized is not None else None}


SW1_RSA, SW1_ED = rsa(1), ed25519(1)
OTHER_RSA, OTHER_ED = rsa(7), ed25519(7)


class DeviceKeysTests(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.dir = Path(tmp.name)
        bin_dir = self.dir / 'bin'
        bin_dir.mkdir()
        (bin_dir / 'ssh-keyscan').write_text(FAKE_KEYSCAN)
        (bin_dir / 'ssh-keyscan').chmod(0o755)
        self.env = {**os.environ, 'PATH': '%s:%s' % (bin_dir, os.environ['PATH']), 'FAKE_KEYSCAN': str(self.dir / 'answers.json')}
        self.file = self.dir / 'device-known-hosts'

    def run_keys(self, devices, answers, args=(), stdin='', notes=None):
        (self.dir / 'devices.json').write_text(json.dumps({'devices': devices, 'ansible': None, 'oxidized': notes}))
        (self.dir / 'answers.json').write_text(json.dumps(answers))
        proc = subprocess.run([sys.executable, '-I', str(ROCKY / 'lib/device_keys.py'), '--devices', str(self.dir / 'devices.json'),
                               '--file', str(self.file), *args], input=stdin, text=True, capture_output=True, env=self.env)
        return proc.returncode, proc.stdout + proc.stderr

    def keys(self):
        """The key lines of the file, without its comments."""
        return [x for x in self.file.read_text().splitlines() if x and not x.startswith('#')]

    def test_a_new_device_is_added_once_confirmed_and_known_after(self):
        devices = [device(1, 'sw1', ansible='10.0.0.1', oxidized='10.0.0.1')]
        answers = {'10.0.0.1:22': {'keys': [SW1_RSA, SW1_ED], 'banner': 'SSH-2.0-Cisco-1.25'}}
        rc, out = self.run_keys(devices, answers, stdin='y\n')
        self.assertEqual(rc, 0, out)
        self.assertIn('sw1 (LibreNMS device 1): new', out)
        self.assertIn('Ansible and Oxidized connect to 10.0.0.1', out)
        sha = 'SHA256:' + base64.b64encode(hashlib.sha256(base64.b64decode(SW1_ED[1])).digest()).decode().rstrip('=')
        self.assertIn(sha, out)
        self.assertIn('RSA     2048', out)
        self.assertEqual(sorted(self.keys()), sorted([line('10.0.0.1', SW1_RSA).strip(), line('10.0.0.1', SW1_ED).strip()]))
        self.assertIn('# sw1 (LibreNMS device 1): confirmed on ', self.file.read_text())
        self.assertEqual(self.file.stat().st_mode & 0o777, 0o600)
        before = self.file.read_text()
        rc, out = self.run_keys(devices, answers)
        self.assertEqual(rc, 0, out)
        self.assertIn('sw1 (LibreNMS device 1): known', out)
        self.assertEqual(self.file.read_text(), before)
        # every address of a port in one ssh-keyscan, for the three key types a device may have
        calls = (self.dir / 'answers.json.calls').read_text().splitlines()
        self.assertEqual(calls[-1], '-T 5 -p 22 -t rsa,ecdsa,ed25519 -f -')

    def test_a_device_not_confirmed_is_not_added(self):
        devices = [device(1, 'sw1', ansible='10.0.0.1', oxidized='10.0.0.1'), device(2, 'sw2', oxidized='10.0.0.2')]
        answers = {'10.0.0.1:22': {'keys': [SW1_ED]}, '10.0.0.2:22': {'keys': [OTHER_ED]}}
        rc, out = self.run_keys(devices, answers, stdin='n\ny\n')
        self.assertEqual(rc, 3, out)
        self.assertEqual(self.keys(), [line('10.0.0.2', OTHER_ED).strip()])
        self.assertIn('Not in %s: sw1' % self.file, out)
        # q: no more questions; a file that would hold nothing new is not written
        self.file.unlink()
        rc, out = self.run_keys(devices, answers, stdin='q\n')
        self.assertEqual(rc, 3, out)
        self.assertIn('not asking any more', out)
        self.assertFalse(self.file.exists())

    def test_one_line_for_the_name_ansible_uses_and_the_ip_oxidized_uses(self):
        devices = [device(3, 'sw3.example.com', ansible='sw3.example.com', oxidized='10.0.0.3', sysname='sw3')]
        answers = {'sw3.example.com:22': {'keys': [SW1_ED]}, '10.0.0.3:22': {'keys': [SW1_ED]}}
        rc, out = self.run_keys(devices, answers, stdin='y\n')
        self.assertEqual(rc, 0, out)
        self.assertIn('sw3.example.com (LibreNMS device 3, sysName sw3): new', out)
        self.assertIn('Ansible connects to sw3.example.com, Oxidized connects to 10.0.0.3', out)
        self.assertEqual(self.keys(), [line('sw3.example.com,10.0.0.3', SW1_ED).strip()])

    def test_known_keys_at_a_new_address_are_added_without_asking(self):
        self.file.write_text(line('sw3.example.com', SW1_ED))
        devices = [device(3, 'sw3.example.com', ansible='sw3.example.com', oxidized='10.0.0.33')]
        answers = {'sw3.example.com:22': {'keys': [SW1_ED]}, '10.0.0.33:22': {'keys': [SW1_ED]}}
        rc, out = self.run_keys(devices, answers, args=['--list'])
        self.assertEqual(rc, 3, out)
        self.assertEqual(self.keys(), [line('sw3.example.com', SW1_ED).strip()])
        rc, out = self.run_keys(devices, answers)
        self.assertEqual(rc, 0, out)
        self.assertIn('known, at a new address', out)
        self.assertEqual(self.keys(), [line('sw3.example.com', SW1_ED).strip(), line('10.0.0.33', SW1_ED).strip()])

    def test_a_changed_key_is_shown_and_taken_only_when_confirmed(self):
        self.file.write_text('# mine\n' + line('10.0.0.1', SW1_RSA) + line('10.0.0.9', OTHER_ED))
        devices = [device(1, 'sw1', ansible='10.0.0.1', oxidized='10.0.0.1')]
        answers = {'10.0.0.1:22': {'keys': [OTHER_RSA]}}
        before = self.file.read_text()
        for args in (['--accept-new'], ['--list'], []):
            with self.subTest(args=args):
                rc, out = self.run_keys(devices, answers, args=args, stdin='n\n')
                self.assertEqual(rc, 3, out)
                self.assertIn('KEY CHANGED', out)
                self.assertEqual(self.file.read_text(), before)
        self.assertIn('never takes a key that changed', self.run_keys(devices, answers, args=['--accept-new'])[1])
        rc, out = self.run_keys(devices, answers, stdin='y\n')
        self.assertEqual(rc, 0, out)
        text = self.file.read_text()
        self.assertEqual(self.keys(), [line('10.0.0.9', OTHER_ED).strip(), line('10.0.0.1', OTHER_RSA).strip()])
        # the old key stays in the file, commented out with why
        self.assertIn('# the key of sw1 changed, ', text)
        self.assertIn(line('10.0.0.1', SW1_RSA).strip(), text)
        self.assertTrue(text.startswith('# mine\n'))

    def test_a_changed_key_on_a_line_of_several_names_keeps_the_others(self):
        self.file.write_text(line('10.0.0.1,sw1-old,10.0.0.100', SW1_ED))
        devices = [device(1, 'sw1', oxidized='10.0.0.1')]
        rc, out = self.run_keys(devices, {'10.0.0.1:22': {'keys': [OTHER_ED]}}, stdin='y\n')
        self.assertEqual(rc, 0, out)
        self.assertEqual(self.keys(), [line('sw1-old,10.0.0.100', SW1_ED).strip(), line('10.0.0.1', OTHER_ED).strip()])

    def test_a_wildcard_with_the_old_key_is_left_to_the_admin(self):
        self.file.write_text(line('10.0.0.*', SW1_ED))
        devices = [device(1, 'sw1', oxidized='10.0.0.1')]
        rc, out = self.run_keys(devices, {'10.0.0.1:22': {'keys': [OTHER_ED]}}, stdin='y\n')
        self.assertEqual(rc, 3, out)
        self.assertIn('name it through a wildcard', out)
        self.assertIn('line 1: 10.0.0.* ssh-ed25519', out)
        self.assertEqual(self.keys(), [line('10.0.0.*', SW1_ED).strip()])

    def test_hashed_names_negations_ports_and_revoked_keys(self):
        salt = b'0123456789abcdefghij'
        digest = hmac.new(salt, b'10.0.0.1', hashlib.sha1).digest()
        hashed = '|1|%s|%s' % (base64.b64encode(salt).decode(), base64.b64encode(digest).decode())
        self.file.write_text(line(hashed, SW1_ED) + line('10.0.0.*,!10.0.0.2', OTHER_ED)
                             + line('[10.0.0.5]:2222', SW1_RSA) + '@revoked * ' + ' '.join(OTHER_RSA) + '\n')
        devices = [device(1, 'sw1', oxidized='10.0.0.1'), device(2, 'sw2', oxidized='10.0.0.2'),
                   device(5, 'sw5', oxidized='10.0.0.5', port=2222), device(6, 'sw6', oxidized='10.0.0.6')]
        answers = {'10.0.0.1:22': {'keys': [SW1_ED]}, '10.0.0.2:22': {'keys': [OTHER_ED]},
                   '10.0.0.5:2222': {'keys': [SW1_RSA]}, '10.0.0.6:22': {'keys': [OTHER_RSA]}}
        rc, out = self.run_keys(devices, answers, args=['--list'])
        self.assertEqual(rc, 3, out)
        self.assertIn('sw1 (LibreNMS device 1): known', out)
        self.assertIn('sw2 (LibreNMS device 2): new', out)        # !10.0.0.2: the wildcard leaves it out
        self.assertIn('sw5 (LibreNMS device 5): known', out)
        self.assertIn('Oxidized connects to [10.0.0.5]:2222', out)
        self.assertIn('sw6 (LibreNMS device 6): REVOKED', out)
        calls = (self.dir / 'answers.json.calls').read_text()
        self.assertIn('-p 2222', calls)
        rc, out = self.run_keys(devices, answers, args=['--accept-new'])
        self.assertEqual(rc, 3, out)                               # sw6: a revoked key is never added
        self.assertIn(line('10.0.0.2', OTHER_ED).strip(), self.keys())
        self.assertNotIn(line('10.0.0.6', OTHER_RSA).strip(), self.keys())

    def test_a_new_key_type_is_asked_about(self):
        self.file.write_text(line('10.0.0.1', SW1_RSA))
        devices = [device(1, 'sw1', oxidized='10.0.0.1')]
        rc, out = self.run_keys(devices, {'10.0.0.1:22': {'keys': [SW1_RSA, SW1_ED]}}, stdin='n\n')
        self.assertEqual(rc, 3, out)
        self.assertIn('sw1 (LibreNMS device 1): new keys', out)
        self.assertIn('(in the file already)', out)
        # --accept-new is for devices the file has no key for: this one is asked about (no answer: not added)
        rc, out = self.run_keys(devices, {'10.0.0.1:22': {'keys': [SW1_RSA, SW1_ED]}}, args=['--accept-new'])
        self.assertEqual(rc, 3, out)
        self.assertEqual(self.keys(), [line('10.0.0.1', SW1_RSA).strip()])
        rc, out = self.run_keys(devices, {'10.0.0.1:22': {'keys': [SW1_RSA, SW1_ED]}}, args=['--accept-new'], stdin='y\n')
        self.assertEqual(rc, 0, out)
        self.assertEqual(self.keys(), [line('10.0.0.1', SW1_RSA).strip(), line('10.0.0.1', SW1_ED).strip()])

    def test_addresses_with_different_keys_are_asked_about_apart(self):
        devices = [device(4, 'sw4.example.com', ansible='sw4.example.com', oxidized='10.0.0.4')]
        answers = {'sw4.example.com:22': {'keys': [SW1_ED]}, '10.0.0.4:22': {'keys': [OTHER_ED]}}
        rc, out = self.run_keys(devices, answers, stdin='y\nn\n')
        self.assertEqual(rc, 3, out)
        self.assertIn('its addresses answer with different keys', out)
        self.assertEqual(self.keys(), [line('sw4.example.com', SW1_ED).strip()])

    def test_silent_devices_and_ones_without_a_key_it_takes(self):
        devices = [device(1, 'sw1', oxidized='10.0.0.1'), device(2, 'sw2', oxidized='10.0.0.2'),
                   device(3, 'srv3', disabled=True), device(4, 'srv4', left_out='no_mapping')]
        answers = {'10.0.0.2:22': {'keys': [], 'banner': 'SSH-2.0-Cisco-1.25'}}
        rc, out = self.run_keys(devices, answers, args=['--list'])
        self.assertEqual(rc, 0, out)
        self.assertIn('sw1 (LibreNMS device 1): no SSH answer at 10.0.0.1', out)
        self.assertIn('answers SSH at 10.0.0.2 (SSH-2.0-Cisco-1.25), but with no key ssh-keyscan takes', out)
        self.assertIn('LibreNMS has 4 devices, 2 that Ansible or Oxidized connect to', out)
        self.assertNotIn('srv3', out)
        # named, a device neither connects to says why
        rc, out = self.run_keys(devices, answers, args=['--list', 'srv3', '4'])
        self.assertIn('srv3 (LibreNMS device 3): it is disabled in LibreNMS', out)
        self.assertIn("its os has no line in the Ansible plugin's platform mapping", out)

    def test_devices_are_named_by_hostname_sysname_address_or_id(self):
        devices = [device(1, 'sw1.example.com', ansible='sw1.example.com', oxidized='10.0.0.1', sysname='SW1'),
                   device(2, 'sw2', oxidized='10.0.0.2')]
        answers = {'sw1.example.com:22': {'keys': [SW1_ED]}, '10.0.0.1:22': {'keys': [SW1_ED]}, '10.0.0.2:22': {'keys': [OTHER_ED]}}
        for name in ('sw1.example.com', 'sw1', '10.0.0.1', '1'):
            with self.subTest(name=name):
                rc, out = self.run_keys(devices, answers, args=['--list', name])
                self.assertEqual(rc, 3, out)
                self.assertIn('sw1.example.com (LibreNMS device 1', out)
                self.assertNotIn('LibreNMS device 2', out)
        rc, out = self.run_keys(devices, answers, args=['--list', 'sw9'])
        self.assertEqual(rc, 2, out)
        self.assertIn('LibreNMS has no device sw9', out)

    def test_no_file_yet_starts_from_the_installed_keys(self):
        seed = self.dir / 'installed'
        seed.write_text(line('10.0.0.1', SW1_ED))
        devices = [device(1, 'sw1', oxidized='10.0.0.1')]
        answers = {'10.0.0.1:22': {'keys': [SW1_ED]}}
        rc, out = self.run_keys(devices, answers, args=['--list', '--seed', str(seed)])
        self.assertEqual(rc, 0, out)
        self.assertFalse(self.file.exists())
        rc, out = self.run_keys(devices, answers, args=['--seed', str(seed)])
        self.assertEqual(rc, 0, out)
        self.assertEqual(self.file.read_text(), seed.read_text())

    def test_oxidized_resolves_a_device_librenms_has_no_ip_for(self):
        devices = [device(1, 'localhost', oxidized='')]
        rc, out = self.run_keys(devices, {'127.0.0.1:22': {'keys': [SW1_ED]}}, args=['--list'])
        self.assertIn('Oxidized resolves localhost to 127.0.0.1: LibreNMS has no IP for it yet', out)
        self.assertIn('Oxidized connects to 127.0.0.1', out)

    @unittest.skipUnless(shutil.which('ssh-keygen'), 'no ssh-keygen here')
    def test_fingerprints_and_sizes_are_the_ones_ssh_keygen_shows(self):
        sys.path.insert(0, str(ROCKY / 'lib'))
        try:
            import device_keys
        finally:
            sys.path.pop(0)
        for keytype, bits in (('ed25519', None), ('rsa', '2048'), ('ecdsa', '384')):
            with self.subTest(keytype=keytype):
                key = self.dir / ('k-' + keytype)
                subprocess.run(['ssh-keygen', '-q', '-t', keytype, *(['-b', bits] if bits else []), '-N', '', '-f', str(key)], check=True)
                fields = (self.dir / ('k-' + keytype + '.pub')).read_text().split()
                shown = subprocess.run(['ssh-keygen', '-l', '-f', str(key) + '.pub'], capture_output=True, text=True, check=True).stdout.split()
                ours = device_keys.describe((fields[0], base64.b64decode(fields[1]))).split()
                self.assertEqual([ours[1], ours[2]], [shown[0], shown[1]])
                self.assertEqual('(%s)' % ours[0], shown[-1])


if __name__ == '__main__':
    unittest.main()
