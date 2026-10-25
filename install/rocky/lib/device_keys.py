#!/usr/bin/env python3
"""install/rocky/lib/device_keys.py: what device-keys.sh does once LibreNMS said where Ansible and Oxidized connect to
each device (librenms_helper.php device-addresses). It takes the SSH host keys found at those addresses (ssh-keyscan)
and compares them with the file of the devices' verified keys (an OpenSSH known_hosts file): the keys the file lacks
are shown by their fingerprints, one device at a time, and added once confirmed; a key that changed is shown with the
one the file has, and is never taken without asking, not even with --accept-new.

  python3 -I device_keys.py --devices JSON --file FILE [--seed FILE] [--list] [--accept-new] [--timeout S] [DEVICE...]

--seed: what FILE starts from when there is no FILE yet (the keys installed now). Exit code: 0 when FILE holds the
keys of every device that answered; 3 when it does not (keys not confirmed, a key that changed); 1 on an error; 2 for
a DEVICE that LibreNMS does not have.
"""
import argparse
import base64
import binascii
import datetime
import hashlib
import hmac
import json
import os
import re
import socket
import subprocess
import sys
import tempfile

KEY_TYPES = 'rsa,ecdsa,ed25519'
LEFT_OUT = {
    'disabled': 'it is disabled in LibreNMS',
    'outside_group': "it is not in the Ansible plugin's allowed device group",
    'no_mapping': "its os has no line in the Ansible plugin's platform mapping",
    'no_address': 'its address has characters Ansible cannot connect to',
}


# ------------------------------------------------------------------------------------------------- keys

def key_name(keytype):
    if keytype.startswith('ecdsa-'):
        return 'ECDSA'
    return {'ssh-rsa': 'RSA', 'ssh-ed25519': 'ED25519', 'ssh-dss': 'DSA'}.get(keytype, keytype)


def key_bits(keytype, blob):
    """The size of a key in bits, as ssh-keygen -l shows it (None when the blob cannot be read)."""
    fields, i = [], 0
    while i + 4 <= len(blob):
        n = int.from_bytes(blob[i:i + 4], 'big')
        fields.append(blob[i + 4:i + 4 + n])
        i += 4 + n
    if keytype == 'ssh-rsa' and len(fields) >= 3:
        return int.from_bytes(fields[2], 'big').bit_length()
    if keytype == 'ssh-dss' and len(fields) >= 2:
        return int.from_bytes(fields[1], 'big').bit_length()
    if keytype.startswith('ecdsa-sha2-nistp') and keytype[16:].isdigit():
        return int(keytype[16:])
    if keytype == 'ssh-ed25519':
        return 256
    return None


def fingerprint(blob):
    return 'SHA256:' + base64.b64encode(hashlib.sha256(blob).digest()).decode().rstrip('=')


def describe(key):
    keytype, blob = key
    return '%-7s %-5s %s' % (key_name(keytype), key_bits(keytype, blob) or '', fingerprint(blob))


def host_name(addr, port):
    """The name a known_hosts file has for an address: [addr]:port for another port than 22."""
    return addr if port == 22 else '[%s]:%d' % (addr, port)


# ------------------------------------------------------------------------------------------------- known_hosts

def _pattern_matches(pattern, name):
    # OpenSSH's match_pattern: * and ? are wildcards, everything else is itself ("[" too), case does not matter
    regex = ''.join('.*' if c == '*' else '.' if c == '?' else re.escape(c) for c in pattern.lower())
    return re.fullmatch(regex, name.lower(), re.DOTALL) is not None


def hosts_match(field, name):
    """Whether a known_hosts host field names NAME: a hashed name (|1|salt|hash), or patterns separated by commas, of
    which one that is negated (!pattern) and matches excludes NAME."""
    if field.startswith('|1|'):
        parts = field.split('|')
        if len(parts) != 4:
            return False
        try:
            salt, want = base64.b64decode(parts[2]), base64.b64decode(parts[3])
        except (binascii.Error, ValueError):
            return False
        return hmac.compare_digest(hmac.new(salt, name.lower().encode(), hashlib.sha1).digest(), want)
    found = False
    for pattern in field.split(','):
        negated = pattern.startswith('!')
        if _pattern_matches(pattern[1:] if negated else pattern, name):
            if negated:
                return False
            found = True
    return found


class Entry:
    """A key line of a known_hosts file."""

    def __init__(self, index, marker, hosts, keytype, blob):
        self.index, self.marker, self.hosts, self.keytype, self.blob = index, marker, hosts, keytype, blob

    @property
    def key(self):
        return (self.keytype, self.blob)

    def literal(self, name):
        """Whether the entry names NAME itself, not through a wildcard: a plain or a hashed name."""
        if self.hosts.startswith('|1|'):
            return hosts_match(self.hosts, name)
        return any(p.lower() == name.lower() for p in self.hosts.split(','))


class KnownHosts:
    def __init__(self, text):
        self.lines = text.splitlines()

    def entries(self):
        for index, line in enumerate(self.lines):
            fields = line.split()
            if not fields or fields[0].startswith('#'):
                continue
            marker = fields.pop(0) if fields[0].startswith('@') else None
            if len(fields) < 3:
                continue
            try:
                blob = base64.b64decode(fields[2], validate=True)
            except (binascii.Error, ValueError):
                continue
            yield Entry(index, marker, fields[0], fields[1], blob)

    def matching(self, name, marker=None):
        return [e for e in self.entries() if e.marker == marker and hosts_match(e.hosts, name)]

    def keys_for(self, name):
        return {e.key for e in self.matching(name)}

    def names_with(self, key, leave_out=()):
        """The plain names the file holds KEY for, other than those of LEAVE_OUT."""
        skip = {n.lower() for n in leave_out}
        names = []
        for e in self.entries():
            if e.marker is None and e.key == key and not e.hosts.startswith('|1|'):
                names += [n for n in e.hosts.split(',') if n.lower() not in skip and not n.startswith('!')]
        return names

    def stuck(self, name, keytype):
        """The lines with another key of KEYTYPE for NAME that name it only through a wildcard: drop() cannot take
        NAME out of them. As (line number, line)."""
        return [(e.index + 1, self.lines[e.index]) for e in self.matching(name) if e.keytype == keytype and not e.literal(name)]

    def drop(self, name, keytype, why):
        """Take NAME out of the lines that hold a key of KEYTYPE for it (the old key of one that changed): a line that
        names nothing else is commented out, with WHY, so the file keeps what it held."""
        for e in sorted(self.matching(name), key=lambda e: -e.index):
            if e.keytype != keytype or not e.literal(name):
                continue
            line = self.lines[e.index]
            rest = [] if e.hosts.startswith('|1|') else [n for n in e.hosts.split(',') if n.lower() != name.lower()]
            if [n for n in rest if not n.startswith('!')]:
                self.lines[e.index:e.index + 1] = ['# %s: %s taken out of the next line' % (why, name),
                                                   line.replace(e.hosts, ','.join(rest), 1)]
            else:
                self.lines[e.index] = '# %s: %s' % (why, line)

    def text(self):
        return ''.join(line + '\n' for line in self.lines)


# ------------------------------------------------------------------------------------------------- scanning

class Scan:
    def __init__(self):
        self.keys = set()
        self.banner = None


def resolve(name):
    """An address of NAME, as Oxidized resolves a device LibreNMS has no IP for (IPv4 first); None when none."""
    try:
        infos = socket.getaddrinfo(name, None)
    except (socket.gaierror, UnicodeError):
        return None
    addrs = [i[4][0] for i in infos]
    v4 = [a for a in addrs if ':' not in a]
    return (v4 or addrs or [None])[0]


def keyscan(targets, timeout):
    """The keys found at each (address, port), by ssh-keyscan (all addresses of a port at once): a Scan by name."""
    found = {host_name(a, p): Scan() for a, p in targets}
    by_port = {}
    for addr, port in targets:
        if addr not in by_port.setdefault(port, []):
            by_port[port].append(addr)
    for port, addrs in sorted(by_port.items()):
        try:
            proc = subprocess.run(['ssh-keyscan', '-T', str(timeout), '-p', str(port), '-t', KEY_TYPES, '-f', '-'],
                                  input=''.join(a + '\n' for a in addrs), capture_output=True, text=True, check=False)
        except FileNotFoundError:
            raise SystemExit('device-keys: ssh-keyscan is missing (dnf install openssh-clients)')
        names = {host_name(a, port).lower(): host_name(a, port) for a in addrs}
        for line in proc.stdout.splitlines():
            fields = line.split()
            if len(fields) < 3 or fields[0].startswith('#') or fields[0].lower() not in names:
                continue
            try:
                found[names[fields[0].lower()]].keys.add((fields[1], base64.b64decode(fields[2], validate=True)))
            except (binascii.Error, ValueError):
                continue
        # "# 10.0.0.1:22 SSH-2.0-Cisco-1.25": the address answered SSH
        for line in proc.stderr.splitlines():
            m = re.match(r'# (\S+):(\d+) (SSH-\S+)', line)
            if m and int(m.group(2)) == port and host_name(m.group(1), port).lower() in names:
                found[names[host_name(m.group(1), port).lower()]].banner = m.group(3)
    return found


# ------------------------------------------------------------------------------------------------- devices

class Device:
    def __init__(self, row):
        self.id = row['id']
        self.hostname = row['hostname']
        self.sysname = row.get('sysname') or ''
        self.row = row
        self.targets = []   # (who, address, port)
        self.notes = []

    @property
    def title(self):
        extra = ', sysName %s' % self.sysname if self.sysname and self.sysname.lower() != self.hostname.lower() else ''
        return '%s (LibreNMS device %d%s)' % (self.hostname, self.id, extra)

    def names(self):
        out = []
        for _, addr, port in self.targets:
            if host_name(addr, port) not in out:
                out.append(host_name(addr, port))
        return out

    def who(self, name):
        return ' and '.join(w for w, a, p in self.targets if host_name(a, p) == name)

    def connects(self):
        names = self.names()
        if len(names) == 1:
            who = self.who(names[0])
            return '%s connect%s to %s' % (who, '' if ' and ' in who else 's', names[0])
        return ', '.join('%s connects to %s' % (self.who(n), n) for n in names)

    def why_not(self):
        if self.row.get('disabled'):
            return 'it is disabled in LibreNMS'
        reasons = self.notes[:]
        if not self.row.get('ansible'):
            reasons.append('Ansible does not connect to it (%s)' % LEFT_OUT.get(self.row.get('ansible_left_out') or '',
                                                                              "it is not in the Ansible plugin's inventory"))
        if not self.row.get('oxidized'):
            reasons.append("Oxidized does not (LibreNMS' API does not give it to Oxidized)")
        return '; '.join(reasons)


def load_devices(data):
    devices = []
    for row in data.get('devices', []):
        d = Device(row)
        if row.get('ansible'):
            d.targets.append(('Ansible', row['ansible']['host'], int(row['ansible'].get('port') or 22)))
        if row.get('oxidized'):
            port = int(row['oxidized'].get('port') or 22)
            ip = row['oxidized'].get('ip') or ''
            if not ip:
                ip = resolve(d.hostname) or ''
                d.notes.append('Oxidized resolves %s to %s: LibreNMS has no IP for it yet' % (d.hostname, ip) if ip else
                               'Oxidized cannot connect to it: LibreNMS has no IP for it, and %s does not resolve' % d.hostname)
            if ip:
                d.targets.append(('Oxidized', ip, port))
        devices.append(d)
    return devices


def select(devices, wanted):
    """The devices WANTED names (an id, hostname, sysName or an address they are reached at), else every device that
    Ansible or Oxidized connects to; and the names that match none."""
    if not wanted:
        return [d for d in devices if d.targets], []
    chosen, unknown = [], []
    for w in wanted:
        lw = w.lower()
        hits = [d for d in devices if str(d.id) == w or d.hostname.lower() == lw or d.sysname.lower() == lw
                or any(a.lower() == lw for _, a, _ in d.targets)]
        if not hits:
            unknown.append(w)
        chosen += [d for d in hits if d not in chosen]
    return chosen, unknown


# ------------------------------------------------------------------------------------------------- the run

class Run:
    def __init__(self, args, known):
        self.args = args
        self.known = known
        self.changed = False
        self.added, self.new_keys, self.missing, self.no_answer = [], [], [], []
        self.stop_asking = False
        self.today = datetime.date.today().isoformat()

    def ask(self, question):
        if self.stop_asking:
            return 'q'
        sys.stdout.write('  %s [y/N/q] ' % question)
        sys.stdout.flush()
        line = sys.stdin.readline()
        if not sys.stdin.isatty():
            sys.stdout.write(line if line.endswith('\n') else line + '\n')
        answer = line.strip().lower()
        if not line or answer in ('q', 'quit'):
            self.stop_asking = True
            return 'q'
        return 'y' if answer in ('y', 'yes') else 'n'

    def add(self, device, names, keys, how):
        """KEYS for each of NAMES that lacks them, below a line that says whose they are."""
        lines = []
        for key in sorted(keys):
            lacking = [n for n in names if key not in self.known.keys_for(n)]
            if lacking:
                lines.append('%s %s %s' % (','.join(lacking), key[0], base64.b64encode(key[1]).decode()))
        if lines:
            self.known.lines.append('# %s: %s on %s (device-keys.sh)' % (device.title, how, self.today))
            self.known.lines.extend(lines)
            self.changed = True

    def device(self, device, scans):
        names = device.names()
        print()
        if not names:
            print('%s: %s' % (device.title, device.why_not()))
            return
        for note in device.notes:
            print('%s: %s' % (device.title, note))
        groups = {}
        for n in names:
            if scans[n].keys:
                groups.setdefault(frozenset(scans[n].keys), []).append(n)
        silent = [n for n in names if not scans[n].keys]
        if not groups:
            answered = [n for n in silent if scans[n].banner]
            if answered:
                print('%s: answers SSH at %s (%s), but with no key ssh-keyscan takes: it may offer only ssh-rsa with SHA-1, '
                      "which this server's crypto policy refuses (install/rocky/README.md, Device SSH host keys)"
                      % (device.title, ', '.join(answered), scans[answered[0]].banner))
            else:
                print('%s: no SSH answer at %s: its keys stay as they are' % (device.title, ', '.join(silent)))
            self.no_answer.append(device.hostname)
            return
        if len(groups) > 1:
            print('%s: WARNING: its addresses answer with different keys (%s): each is shown on its own. They are not the '
                  'same device, or someone answers in its place on one of them'
                  % (device.title, '; '.join(', '.join(g) for g in groups.values())))
        known_any = set()
        for n in names:
            known_any |= self.known.keys_for(n)
        for keys, group in groups.items():
            self.entry(device, names, group, set(keys), known_any, silent, len(groups) > 1)

    def entry(self, device, names, group, keys, known_any, silent, split):
        title = device.title + (' at ' + ', '.join(group) if split else '')
        connects = '    ' + device.connects() + (' (no SSH answer at %s)' % ', '.join(silent) if silent else '')
        known = {n: self.known.keys_for(n) for n in group}
        revoked = set()
        for n in group:
            revoked |= {e.key for e in self.known.matching(n, '@revoked')}
        conflicts = []   # (name, the key the file has, the one it answers with)
        for n in group:
            for key in keys:
                old = [k for k in known[n] if k[0] == key[0]]
                if old and key not in old:
                    conflicts += [(n, k, key) for k in old]
        lacking = sorted(k for k in keys if any(k not in known[n] for n in group))

        if keys & revoked:
            print('%s: REVOKED: it answers with a key the file marks @revoked, which is never added' % title)
            print(connects)
            for key in sorted(keys & revoked):
                print('    ' + describe(key))
            self.missing.append(device.hostname)
            return
        if not conflicts and not lacking:
            print('%s: known' % title)
            print(connects)
            return
        if not conflicts and keys <= known_any:
            # the keys confirmed for another of its addresses: no one else can answer with them
            print('%s: known, at a new address' % title)
            print(connects)
            print('    %s: the keys confirmed for %s' % (', '.join(n for n in group if keys - known[n]),
                                                       ', '.join(n for n in names if keys <= self.known.keys_for(n)) or 'it'))
            if self.args.list:
                self.missing.append(device.hostname)
            else:
                self.add(device, group, keys, 'its keys at a new address')
                print('    added to %s' % self.args.file)
            return

        if conflicts:
            print('%s: KEY CHANGED' % title)
            print(connects)
            for n, old, new in conflicts:
                print('    the file has    %s   for %s' % (describe(old), n))
                print('    it answers with %s' % describe(new))
            for key in [k for k in lacking if k not in [c[2] for c in conflicts]]:
                print('    and a new key   %s' % describe(key))
            print('    Ansible and Oxidized refuse it until the new key is confirmed. Confirm it only when you know why it')
            print('    changed (the device was replaced, or its keys were made again): someone may answer in its place.')
        else:
            print('%s: %s' % (title, 'new keys' if any(known[n] for n in group) else 'new'))
            print(connects)
            for key in lacking:
                print('    ' + describe(key))
            for key in sorted(keys - set(lacking)):
                print('    %s (in the file already)' % describe(key))
            for key in lacking:
                others = self.known.names_with(key, leave_out=names)
                if others:
                    print('    the file has this %s key for %s too' % (key_name(key[0]), ', '.join(others)))

        if self.args.list:
            self.missing.append(device.hostname)
            return
        if conflicts and self.args.accept_new:
            print('    left as it is: --accept-new never takes a key that changed')
            self.missing.append(device.hostname)
            return
        stuck = []
        for n, old, _ in conflicts:
            stuck += [s for s in self.known.stuck(n, old[0]) if s not in stuck]
        if stuck:
            print('    these lines of %s name it through a wildcard, with the key it had: edit them first' % self.args.file)
            for number, line in stuck:
                print('      line %d: %s' % (number, line))
            self.missing.append(device.hostname)
            return
        if self.args.accept_new and not any(known[n] for n in group):
            answer = 'y'
            print('    added without asking (--accept-new)')
        else:
            answer = self.ask('Does %s show %s fingerprints (on its console, or another source you trust)?'
                              % (device.hostname, 'these new' if conflicts else 'these'))
        if answer != 'y':
            print('    not added%s' % (': not asking any more' if answer == 'q' else ''))
            self.missing.append(device.hostname)
            return
        for n, old, _ in conflicts:
            self.known.drop(n, old[0], 'the key of %s changed, %s (device-keys.sh)' % (device.hostname, self.today))
        self.add(device, group, keys, 'its new key, confirmed' if conflicts else 'confirmed')
        (self.new_keys if conflicts else self.added).append(device.hostname)
        print('    added to %s' % self.args.file)


def write_file(path, text):
    """PATH with TEXT, atomically; a new file is root's alone (mode 600), an existing one keeps its mode."""
    directory = os.path.dirname(os.path.abspath(path))
    mode = os.stat(path).st_mode & 0o777 if os.path.exists(path) else 0o600
    fd, tmp = tempfile.mkstemp(dir=directory, prefix='.' + os.path.basename(path) + '.')
    try:
        with os.fdopen(fd, 'w') as f:
            f.write(text)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(tmp, mode)
        os.replace(tmp, path)
    except BaseException:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise


def plural(items, one, many):
    return '%d %s (%s)' % (len(items), one if len(items) == 1 else many, ', '.join(items))


def main(argv):
    p = argparse.ArgumentParser(prog='device_keys.py')
    p.add_argument('--devices', required=True)
    p.add_argument('--file', required=True)
    p.add_argument('--seed')
    p.add_argument('--list', action='store_true')
    p.add_argument('--accept-new', action='store_true')
    p.add_argument('--timeout', type=int, default=5)
    p.add_argument('wanted', nargs='*')
    args = p.parse_args(argv)

    with open(args.devices, encoding='utf-8') as f:
        data = json.load(f)
    devices = load_devices(data)
    chosen, unknown = select(devices, args.wanted)
    if unknown:
        print('device-keys.sh: LibreNMS has no device %s (name a device by its hostname, sysName, IP address or id)'
              % ', '.join(unknown), file=sys.stderr)
        return 2

    seeded = False
    if os.path.exists(args.file):
        with open(args.file, encoding='utf-8') as f:
            text = f.read()
        source = args.file
    elif args.seed:
        with open(args.seed, encoding='utf-8') as f:
            text = f.read()
        seeded = True
        source = '%s, which starts as a copy of %s (the keys installed now)' % (args.file, args.seed)
    else:
        text = ("# The devices' verified SSH host keys (install/rocky/device-keys.sh), installed as /etc/ssh/ssh_known_hosts:\n"
                '# Ansible and Oxidized check every device against them.\n')
        source = '%s (new)' % args.file
    run = Run(args, KnownHosts(text))

    used = [d for d in devices if d.targets]
    print('LibreNMS has %d device%s, %d that Ansible or Oxidized connect to. The verified keys: %s'
          % (len(devices), '' if len(devices) == 1 else 's', len(used), source))
    for part, name in (('ansible', 'Ansible'), ('oxidized', 'Oxidized')):
        if data.get(part):
            print('  %s: %s' % (name, data[part]))
    if not used and not args.wanted:
        print('No device to scan: add the devices to LibreNMS first (Devices > Add Device, or lnms device:add).')
    targets = []
    for d in chosen:
        targets += [(a, p) for _, a, p in d.targets if (a, p) not in targets]
    scans = keyscan(targets, args.timeout) if targets else {}
    for device in chosen:
        run.device(device, scans)

    print()
    if not args.list:
        if run.changed or seeded:
            write_file(args.file, run.known.text())
        done = []
        if run.added:
            done.append(plural(run.added, 'device added', 'devices added'))
        if run.new_keys:
            done.append('the new key of ' + ', '.join(run.new_keys))
        print('%s: %s' % (args.file, '; '.join(done) if done else 'nothing added'))
    if run.missing:
        print('Not in %s: %s (%s)' % (args.file, ', '.join(run.missing),
                                    'device-keys.sh without --list asks about them' if args.list
                                    else 'Ansible and Oxidized refuse them until they are'))
    elif args.list:
        print('%s holds the keys of every device that answered.' % args.file)
    if run.no_answer:
        print('No SSH answer: %s (their keys stay as they are)' % ', '.join(run.no_answer))
    return 3 if run.missing else 0


if __name__ == '__main__':
    try:
        sys.exit(main(sys.argv[1:]))
    except KeyboardInterrupt:
        print('\nStopped: nothing was written.', file=sys.stderr)
        sys.exit(130)
