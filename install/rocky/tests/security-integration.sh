#!/bin/bash
# CI test container only: exercise the installed accounts, MariaDB and the local fake SSH switch.
# shellcheck source-path=SCRIPTDIR
set -euo pipefail
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=../lib/common.sh
. "$HERE/../lib/common.sh"
# shellcheck source=../lib/librenms.sh
. "$HERE/../lib/librenms.sh"
# shellcheck source=../lib/plugins.sh
. "$HERE/../lib/plugins.sh"
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
chmod 755 "$fixture"
install -d -o librenms -g librenms "$fixture/repo"
PLUGIN_CLONE=$fixture/repo
PLUGIN_SOURCE=$PLUGIN_CLONE
as librenms git init -q "$PLUGIN_CLONE"
as librenms git -C "$PLUGIN_CLONE" -c user.name=fixture -c user.email=fixture@example.invalid commit -q --allow-empty -m fixture
cat >"$fixture/hook" <<'HOOK'
#!/bin/bash
id -u >uid
printf 'token\0'
HOOK
chmod 755 "$fixture/hook"
as librenms git -C "$PLUGIN_CLONE" config core.fsmonitor "$fixture/hook"
clone_git status --porcelain --untracked-files=no
[[ $(cat "$PLUGIN_CLONE/uid") == "$(id -u librenms)" ]]
rm "$PLUGIN_CLONE/uid"
source_git status --porcelain --untracked-files=no
[[ $(cat "$PLUGIN_CLONE/uid") == "$(id -u librenms)" ]]
echo 'PASS  Git fsmonitor cannot run as root through installer helpers'

mkdir -m 700 "$fixture/root-only"
printf 'unchanged' >"$fixture/root-only/config"
printf 'new content' >"$fixture/source"
install -d -o librenms -g librenms -m 700 "$fixture/service"
ln -s "$fixture/root-only" "$fixture/service/link"
if (put_file "$fixture/source" "$fixture/service/link/config" librenms:librenms 600) >/dev/null 2>&1; then
    echo 'FAIL  service-controlled parent redirected a privileged write' >&2
    exit 1
fi
[[ $(cat "$fixture/root-only/config") == unchanged ]]
put_file "$fixture/source" "$fixture/service/config" librenms:librenms 600
[[ $(stat -c '%U:%G %a' "$fixture/service/config") == 'librenms:librenms 600' ]]
echo 'PASS  service-controlled destinations cannot redirect root writes'

# Keep the real mysql_root quoting/mode/client invocation; capture its result instead of writing the log.
run_input() { local sql=$1; shift; printf '%s\n' "$sql" | "$@" --batch --skip-column-names; }
password="quote'\\; SELECT 'injected'; --
\\! touch $fixture/sql-marker
end"
expected=$(printf '%s' "$password" | od -An -v -tx1 | tr -d ' \n' | tr '[:lower:]' '[:upper:]')
actual=$(mysql_root "SELECT HEX($(sql_literal "$password"));")
[[ $actual == "$expected" && ! -e $fixture/sql-marker ]]
echo 'PASS  MariaDB treats quotes, backslashes and client commands in passwords as data'

# What librenms installs from paths that only root may read (wheels, a Git checkout in /root) reaches it as a copy.
WORK=$fixture
mkdir -m 700 "$fixture/root-only-wheels"
printf 'wheel' >"$fixture/root-only-wheels/fixture.whl"
copy=$(readable_copy librenms "$fixture/root-only-wheels" wheels)
[[ $copy == "$fixture/wheels" && $(stat -c %G:%a "$copy/fixture.whl") == librenms:640 ]] && as librenms cat "$copy/fixture.whl" >/dev/null
install -d -m 755 "$fixture/open"
printf 'x' >"$fixture/open/file"
[[ $(readable_copy librenms "$fixture/open" open-copy) == "$fixture/open" && ! -e $fixture/open-copy ]]
echo 'PASS  what librenms cannot read reaches it through a readable copy, and only then'

# The devices' keys as the installer installed them (--device-known-hosts): /etc/ssh/ssh_known_hosts, root's.
[[ $(stat -c '%U:%G %a' /etc/ssh/ssh_known_hosts) == 'root:root 644' ]] && grep -q " $1 \| $1,\|,$1 " /etc/ssh/ssh_known_hosts
# Oxidized: net-ssh with Oxidized's options and its configuration's vars.ssh_host_key, against a switch that offers its
# RSA key's SHA-1 signature (ssh-rsa) too, as IOS does.
runuser -u oxidized -- ruby -rnet/ssh -ryaml -rtempfile -rbase64 -ropenssl - "$1" <<'RUBY'
host = ARGV.fetch(0)
config = YAML.safe_load(File.read('/home/oxidized/.config/oxidized/config'))
raise 'Oxidized does not enforce SSH host keys' unless config.dig('input', 'ssh', 'secure') == true
order = config.dig('vars', 'ssh_host_key').to_s.split(/,\s*/)
raise "Oxidized's ssh_host_key does not ask for SHA-2 first: #{order}" unless order.first(2) == %w[rsa-sha2-512 rsa-sha2-256]
options = { password: 'netops-pw', auth_methods: ['password'], non_interactive: true, verify_host_key: :always,
            append_all_supported_algorithms: true, timeout: 10 }
def expect_rejection(host, options, file, error)
  Net::SSH.start(host, 'netops', **options, user_known_hosts_file: [file], global_known_hosts_file: []).close
  raise 'untrusted host was accepted'
rescue Net::SSH::HostKeyError => e
  raise unless e.class == error
end
expect_rejection(host, options.merge(host_key: order), '/dev/null', Net::SSH::HostKeyUnknown)
Tempfile.create('wrong-host-key') do |file|
  key = OpenSSL::PKey::RSA.new(2048)
  file.write("#{host} #{key.ssh_type} #{Base64.strict_encode64(key.to_blob)}\n")
  file.flush
  expect_rejection(host, options.merge(host_key: order), file.path, Net::SSH::HostKeyMismatch)
end
system_file = { user_known_hosts_file: [], global_known_hosts_file: ['/etc/ssh/ssh_known_hosts'] }
Net::SSH.start(host, 'netops', **options, **system_file, host_key: order) do |ssh|
  negotiated = ssh.transport.algorithms.host_key
  raise "negotiated #{negotiated}, not a SHA-2 signature" unless negotiated.start_with?('rsa-sha2-')
end
# net-ssh's own order (no ssh_host_key) asks for ssh-rsa first: Rocky's crypto policy refuses to verify it
begin
  Net::SSH.start(host, 'netops', **options, **system_file).close
  puts "INFO  without ssh_host_key, net-ssh's own order worked here too"
rescue StandardError => e
  puts "INFO  without ssh_host_key, net-ssh's own order fails here: #{e.class}: #{e.message[0, 80]}"
end
puts 'PASS  Oxidized refuses unknown and changed host keys, and checks the known one by its SHA-2 signature'
RUBY

# Ansible as Semaphore UI runs it (ANSIBLE_HOST_KEY_CHECKING=False) with the variable group's ansible_host_key_checking:
# the known key passes, a changed or an unknown one is refused (it would not be without the check). paramiko takes a
# host's first key, the system file's: the test changes that file for a moment.
ansible_show() {
    as semaphore env ANSIBLE_HOST_KEY_CHECKING=False ANSIBLE_NOCOLOR=1 /opt/ansible/bin/ansible all -i "$1," \
        -e '{"ansible_host_key_checking": true, "ansible_connection": "ansible.netcommon.network_cli",
             "ansible_network_os": "cisco.ios.ios", "ansible_user": "netops", "ansible_password": "netops-pw",
             "ansible_become": true, "ansible_become_method": "enable", "ansible_become_password": "netops-pw"}' \
        -m cisco.ios.ios_command -a 'commands="show privilege"' 2>&1
}
out=$(ansible_show "$1") || { echo "FAIL  Ansible refused the known key: $out" >&2; exit 1; }
cp -p /etc/ssh/ssh_known_hosts "$fixture/ssh_known_hosts"
trap 'cp -p "$fixture/ssh_known_hosts" /etc/ssh/ssh_known_hosts; rm -rf "$fixture"' EXIT
wrong=$(/opt/ansible/bin/python -I -c 'from cryptography.hazmat.primitives import serialization as s
from cryptography.hazmat.primitives.asymmetric import rsa
k = rsa.generate_private_key(public_exponent=65537, key_size=2048).public_key()
print(k.public_bytes(s.Encoding.OpenSSH, s.PublicFormat.OpenSSH).decode())')
printf '%s %s\n' "$1" "$wrong" >/etc/ssh/ssh_known_hosts
if out=$(ansible_show "$1"); then echo "FAIL  Ansible accepted a changed host key: $out" >&2; exit 1; fi
grep -q -i 'host key mismatch' <<<"$out" || { echo "FAIL  Ansible failed otherwise with a changed key: $out" >&2; exit 1; }
: >/etc/ssh/ssh_known_hosts
if out=$(ansible_show "$1"); then echo "FAIL  Ansible accepted an unknown host key: $out" >&2; exit 1; fi
grep -q -i "authenticity of host" <<<"$out" || { echo "FAIL  Ansible failed otherwise with an unknown key: $out" >&2; exit 1; }
cp -p "$fixture/ssh_known_hosts" /etc/ssh/ssh_known_hosts
echo 'PASS  Ansible checks host keys despite Semaphore UI'"'"'s ANSIBLE_HOST_KEY_CHECKING=False: known passes, changed and unknown do not'
