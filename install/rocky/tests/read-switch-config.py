"""Read running/startup config from the local CI switch using its independently supplied host key."""
import ipaddress
import re
import sys
import time

import paramiko


host, kind = sys.argv[1:]
if not ipaddress.ip_address(host).is_loopback or kind not in ('running', 'startup'):
    raise SystemExit('only the local CI fixture and running/startup configuration are supported')


def receive(channel, pattern):
    text = ''
    deadline = time.monotonic() + 20
    while time.monotonic() < deadline:
        if channel.recv_ready():
            data = channel.recv(65536)
            if not data:
                raise RuntimeError('fixture closed the SSH channel')
            text += data.decode('utf-8', 'replace')
            if re.search(pattern, text):
                return text
        time.sleep(0.05)
    raise TimeoutError('fixture did not return the expected prompt')


with paramiko.SSHClient() as client:
    client.load_host_keys('/etc/ssh/ssh_known_hosts')
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect(host, username='netops', password='netops-pw', look_for_keys=False, allow_agent=False, timeout=10)
    channel = client.invoke_shell()
    receive(channel, r'>\s*$')
    channel.sendall('enable\n')
    receive(channel, r'Password:\s*$')
    channel.sendall('netops-pw\n')
    receive(channel, r'#\s*$')
    channel.sendall(f'show {kind}-config\n')
    print(receive(channel, r'#\s*$'))
