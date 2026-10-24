#!/bin/bash
# install/rocky/tests/fake-switch.sh NAME IP: start a fake Cisco IOS switch NAME on IP:22 inside a test system, the one
# tests/e2e.sh drives (SSH only; login netops / netops-pw, enable password netops-pw): fake-switches 1.5.0 with
# tests/fake-switches.patch, as the lab runs it, in a Python 3.12 virtual environment in /opt/fakesw. The first call
# makes the environment (pip with tests/fake-switches-requirements.txt: fake-switches 1.5.0 asks for a Twisted that
# no longer builds on Python 3) and needs PyPI and dnf's repositories (patch). Run as root on the test system.
set -euo pipefail
NAME=${1:?usage: fake-switch.sh NAME IP} IP=${2:?usage: fake-switch.sh NAME IP}
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
VENV=/opt/fakesw
SITE=$VENV/lib/python3.12/site-packages

if [[ ! -f $VENV/.patched-security ]]; then
    command -v patch >/dev/null || dnf -y -q install patch
    rm -rf "$VENV"
    python3.12 -m venv "$VENV"
    "$VENV/bin/pip" install --disable-pip-version-check --quiet --no-deps -r "$HERE/fake-switches-requirements.txt"
    patch --quiet -p1 -d "$SITE" <"$HERE/fake-switches.patch"
    touch "$VENV/.patched-security"
fi
listening() { local out; out=$(ss -Htln "sport = :22"); [[ $out == *"$IP:22 "* ]]; }
if listening; then
    echo "something listens on $IP:22 already" >&2
    exit 1
fi
setsid nohup "$VENV/bin/fake-switches" --model cisco_generic --hostname "$NAME" --username netops --password netops-pw \
    --listen-host "$IP" --listen-port 22 >"/var/log/fake-switch-$NAME.log" 2>&1 </dev/null &
for _ in $(seq 1 30); do
    if listening; then
        echo "fake switch $NAME listens on $IP:22"
        # The fixture's public key comes from its local package, not an unauthenticated network scan.
        install -d -o oxidized -g oxidized -m 700 /home/oxidized/.ssh
        "$VENV/bin/python" - "$NAME" "$IP" <<'PY' >>/home/oxidized/.ssh/known_hosts
import sys
from fake_switches.transports.ssh_service import getRSAKeys
print(','.join(sys.argv[1:]) + ' ' + getRSAKeys()[0])
PY
        chown oxidized:oxidized /home/oxidized/.ssh/known_hosts
        chmod 600 /home/oxidized/.ssh/known_hosts
        bash "$HERE/security-integration.sh" "$IP"
        exit 0
    fi
    sleep 1
done
echo "the fake switch did not start: /var/log/fake-switch-$NAME.log" >&2
exit 1
