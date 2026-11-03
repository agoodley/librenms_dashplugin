#!/usr/bin/env python3
"""install/rocky/lib/device_logins.py: what device-logins.sh does with its CSV file of the devices that have a login of
their own (the others log in with the default one, the installer's --device-user): the rows checked and matched to
LibreNMS' devices (librenms_helper.php device-addresses), what changes since the last run, and the host_vars files that
give Ansible each device's login. The passwords go only to --out (root's alone), never to the screen.

  python3 -I device_logins.py --devices JSON --file CSV --state JSON --out JSON --host-vars DIR [--default-user U]

The CSV: a header line, then a line per device; # starts a comment line.

  device,username,password,enable
  sw5.example.com,localadmin,S3cret,En4ble        (device: hostname, sysName, IP address or LibreNMS device id;
  10.0.0.7,admin2,"with,commas",                   enable: the enable password, empty for the password itself)

--state: {"salt": ..., "devices": {id: hash}} of the last run. Exit code 0; 2 when the file cannot be used (each
problem said; nothing is changed then); 1 on an error.
"""
import argparse
import csv
import hashlib
import json
import os
import re
import sys

COLUMNS = ('device', 'username', 'password', 'enable')
LEFT_OUT = {
    'disabled': 'it is disabled in LibreNMS',
    'outside_group': "it is not in the Ansible plugin's allowed device group",
    'no_mapping': "its os has no line in the Ansible plugin's platform mapping",
    'no_address': 'its address has characters Ansible cannot connect to',
}
SECRET_PREFIX = 'DEVICE_LOGIN_'
GROUP_PREFIX = 'login-'
MARKER = '# device-logins.sh:'


def secret_names(device_id):
    return SECRET_PREFIX + '%d_PASSWORD' % device_id, SECRET_PREFIX + '%d_ENABLE' % device_id


# a host_vars file's name: the device's name in the Ansible plugin's inventory, as the plugin makes one (HostNames)
INVENTORY_NAME = re.compile(r'^[A-Za-z0-9_][A-Za-z0-9._-]{0,252}$')


def printable(text):
    """TEXT with its control and other non-printable characters as "?": LibreNMS' helper, run as librenms, reports it,
    and it goes into the host_vars files (as a comment) and onto the terminal."""
    return ''.join(c if c.isprintable() else '?' for c in str(text))


def bad_text(value):
    """Control characters (a line break, a tab, NUL, DEL) do not belong in a login."""
    return any(ord(c) < 32 or ord(c) == 127 for c in value)


class Device:
    def __init__(self, row):
        self.id = int(row['id'])
        self.hostname = printable(row['hostname'])
        self.sysname = printable(row.get('sysname') or '')
        # the host_vars file root writes is named after it: anything else (a path, "..") is no name the plugin gives
        name = row.get('inventory_name') or ''
        self.inventory_name = name if isinstance(name, str) and INVENTORY_NAME.fullmatch(name) else ''
        self.in_ansible = bool(row.get('ansible'))
        self.in_oxidized = bool(row.get('oxidized'))
        self.left_out = row.get('ansible_left_out') or ''
        self.addresses = {a.lower() for a in ((row.get('ansible') or {}).get('host') or '', (row.get('oxidized') or {}).get('ip') or '') if a}

    @property
    def title(self):
        extra = ', sysName %s' % self.sysname if self.sysname and self.sysname.lower() != self.hostname.lower() else ''
        return '%s (LibreNMS device %d%s)' % (self.hostname, self.id, extra)


def match(devices, name):
    """The devices NAME stands for: a device id, a hostname, a sysName or an address Ansible or Oxidized reach it at."""
    low = name.lower()
    if name.isdigit():
        hits = [d for d in devices if d.id == int(name)]
        if hits:
            return hits
    for test in (lambda d: d.hostname.lower() == low, lambda d: d.sysname.lower() == low, lambda d: low in d.addresses):
        hits = [d for d in devices if test(d)]
        if hits:
            return hits
    return []


def read_csv(path, devices):
    """The logins of the file, matched to devices; and the problems found (none: the file can be used)."""
    problems, logins, notes = [], [], []
    try:
        with open(path, encoding='utf-8-sig', newline='') as f:
            text = f.read()
    except (OSError, UnicodeDecodeError) as e:
        return [], ['%s cannot be read: %s' % (path, e)], []
    numbered = [(n, line) for n, line in enumerate(text.splitlines(), 1) if line.strip() and not line.lstrip().startswith('#')]
    if not numbered:
        return [], ['%s has no header line (%s)' % (path, ','.join(COLUMNS))], []
    rows = list(csv.reader([line for _, line in numbered]))
    header = [h.strip().lower() for h in rows[0]]
    missing = [c for c in COLUMNS[:3] if c not in header]
    if missing:
        return [], ['line %d: the header has no column %s (it needs %s; enable is optional)'
                    % (numbered[0][0], ', '.join(missing), ','.join(COLUMNS))], []
    extra = [h for h in header if h not in COLUMNS]
    if extra:
        notes.append('the columns %s are left out' % ', '.join(extra))
    seen = {}
    for (number, _), row in zip(numbered[1:], rows[1:]):
        values = dict(zip(header, row + [''] * (len(header) - len(row))))
        name = values['device'].strip()
        user = values['username'].strip()
        password, enable = values['password'], values.get('enable', '')
        where = 'line %d' % number
        if len(row) > len(header):
            problems.append('%s: more values than the header has columns (a comma in a password needs "quotes")' % where)
            continue
        if not name:
            problems.append('%s: no device' % where)
            continue
        hits = match(devices, name)
        if not hits:
            problems.append('%s: LibreNMS has no device %s (name it by its hostname, sysName, IP address or id)' % (where, name))
            continue
        if len(hits) > 1:
            problems.append('%s: %s names %d devices (%s): give its hostname or id' % (where, name, len(hits), ', '.join(d.hostname for d in hits)))
            continue
        device = hits[0]
        if device.id in seen:
            problems.append('%s: %s again (line %d has it already)' % (where, device.hostname, seen[device.id]))
            continue
        seen[device.id] = number
        if not user or not password:
            problems.append('%s: %s needs a username and a password' % (where, device.hostname))
            continue
        if len(user) > 128 or bad_text(user) or any(c in user for c in '{}'):
            problems.append('%s: the username of %s has characters a login cannot have here (control characters, { or })' % (where, device.hostname))
            continue
        if bad_text(password) or bad_text(enable):
            problems.append('%s: the password of %s has a control character (a line break, a tab)' % (where, device.hostname))
            continue
        logins.append({'device': device, 'username': user, 'password': password, 'enable': enable or password, 'line': number})
    return logins, problems, notes


def login_hash(salt, login):
    data = json.dumps([login['device'].id, login['username'], login['password'], login['enable']])
    return hashlib.sha256((salt + data).encode()).hexdigest()


def host_vars(login, path):
    """The host_vars file that gives Ansible the device's login: its username, and its passwords from the variable
    group's secrets (Semaphore UI puts them in the environment of every run of the project's templates)."""
    device = login['device']
    password, enable = secret_names(device.id)
    return '\n'.join([
        '%s the login of %s, from %s.' % (MARKER, device.title, path),
        '# Each run of install/rocky/device-logins.sh writes this file again, and removes it once the device has no line',
        '# there. Its passwords are the secrets %s and %s of the variable group "LibreNMS".' % (password, enable),
        'ansible_user: ' + json.dumps(login['username']),
        'ansible_password: "{{ lookup(\'ansible.builtin.env\', \'%s\') }}"' % password,
        'ansible_become_password: "{{ lookup(\'ansible.builtin.env\', \'%s\') }}"' % enable,
    ]) + '\n'


def write_private(path, data):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, 'w') as f:
        f.write(data)


def main(argv):
    p = argparse.ArgumentParser(prog='device_logins.py')
    p.add_argument('--devices', required=True)
    p.add_argument('--file', required=True)
    p.add_argument('--state', required=True)
    p.add_argument('--out', required=True)
    p.add_argument('--host-vars', required=True)
    p.add_argument('--default-user', default='')
    args = p.parse_args(argv)

    with open(args.devices, encoding='utf-8') as f:
        data = json.load(f)
    with open(args.state, encoding='utf-8') as f:
        state = json.load(f)
    devices = [Device(row) for row in data.get('devices', [])]
    logins, problems, notes = read_csv(args.file, devices)
    if problems:
        print('%s cannot be used, so nothing changed:' % args.file, file=sys.stderr)
        for problem in problems:
            print('  ' + problem, file=sys.stderr)
        return 2

    salt, before = state['salt'], {int(k): v for k, v in (state.get('devices') or {}).items() if v}
    by_id = {d.id: d for d in devices}
    default = ' (%s)' % args.default_user if args.default_user else ''
    print('LibreNMS has %d device%s; %s gives %d of them a login of their own, the others log in with the default one%s:'
          % (len(devices), '' if len(devices) == 1 else 's', args.file, len(logins), default))
    for note in notes:
        print('  (%s)' % note)
    out = {'logins': [], 'removed': [], 'host_vars': {}}
    os.makedirs(args.host_vars, exist_ok=True)
    for login in sorted(logins, key=lambda x: x['device'].id):
        device = login['device']
        digest = login_hash(salt, login)
        status = 'new' if device.id not in before else 'login changed' if before[device.id] != digest else 'as it was'
        where = []
        if not device.in_ansible:
            where.append('Ansible does not connect to it now%s' % (' (%s)' % LEFT_OUT[device.left_out] if device.left_out in LEFT_OUT else ''))
        if not device.in_oxidized:
            where.append("Oxidized does not back it up (LibreNMS' API does not give it to Oxidized)")
        print('  %s: %s, %s%s' % (device.title, login['username'], status, ''.join('; ' + w for w in where)))
        entry = {'id': device.id, 'hostname': device.hostname, 'title': device.title, 'username': login['username'],
                 'password': login['password'], 'enable': login['enable'], 'hash': digest, 'status': status,
                 'group': GROUP_PREFIX + str(device.id), 'secrets': secret_names(device.id), 'inventory_name': device.inventory_name}
        out['logins'].append(entry)
        if device.inventory_name:
            name = device.inventory_name + '.yml'
            with open(os.path.join(args.host_vars, name), 'w', encoding='utf-8') as f:
                f.write(host_vars(login, args.file))
            out['host_vars'][name] = device.id
    for device_id in sorted(set(before) - {login['device'].id for login in logins}):
        device = by_id.get(device_id)
        print('  %s: back to the default login (no line in %s any more)' % (device.title if device else 'LibreNMS device %d (gone)' % device_id, args.file))
        out['removed'].append({'id': device_id, 'title': device.title if device else 'LibreNMS device %d' % device_id})
    if not logins and not out['removed']:
        print('  (none)')
    write_private(args.out, json.dumps(out))
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main(sys.argv[1:]))
    except (OSError, ValueError, KeyError) as e:
        print('device_logins.py: %s' % e, file=sys.stderr)
        sys.exit(1)
