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

# Match the transport selected by Oxidized, using independently supplied local fixture keys.
runuser -u oxidized -- ruby -rnet/ssh -ryaml -rtempfile -rbase64 -ropenssl - "$1" <<'RUBY'
host = ARGV.fetch(0)
config = YAML.safe_load(File.read('/home/oxidized/.config/oxidized/config'))
raise 'Oxidized does not enforce SSH host keys' unless config.dig('input', 'ssh', 'secure') == true
options = { password: 'netops-pw', auth_methods: ['password'], non_interactive: true,
            verify_host_key: :always, global_known_hosts_file: [], timeout: 10 }
def expect_rejection(host, options, file, error)
  begin
    Net::SSH.start(host, 'netops', **options, user_known_hosts_file: [file]).close
    raise 'untrusted host was accepted'
  rescue Net::SSH::HostKeyError => e
    raise unless e.class == error
  end
end
expect_rejection(host, options, '/dev/null', Net::SSH::HostKeyUnknown)
Tempfile.create('wrong-host-key') do |file|
  key = OpenSSL::PKey::RSA.new(2048)
  file.write("#{host} #{key.ssh_type} #{Base64.strict_encode64(key.to_blob)}\n")
  file.flush
  expect_rejection(host, options, file.path, Net::SSH::HostKeyMismatch)
end
Net::SSH.start(host, 'netops', **options, user_known_hosts_file: ['/home/oxidized/.ssh/known_hosts']).close
puts 'PASS  SSH rejects unknown/changed host keys and accepts the independently supplied fixture key'
RUBY
