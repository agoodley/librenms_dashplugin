#!/bin/bash
# install/rocky/tests/diagnose.sh [--run-poller]: what tells why LibreNMS' polling, or a service, of a test system does
# not work: crond, its journal and its PAM configuration, LibreNMS' cron file, the pollers table, the tail of LibreNMS'
# log, the services. CI prints it into the job log when a step fails (its artifacts cannot always be read). With
# --run-poller it then runs LibreNMS' poller cron job as cron runs it, and the wrapper with debug output: they update
# the pollers table, so they come after what shows whether cron did. The librenms account comes first: crond's PAM
# account check of it ("FAILED to authorize user with PAM" in crond's journal) decides whether cron runs any of its
# jobs. Run as root on the test system.
set -uo pipefail
LNMS=/opt/librenms
section() { printf '\n===== %s\n' "$*"; }
pollers() { mysql -t -e 'SELECT poller_name, last_polled, NOW() AS now, devices, time_taken FROM librenms.pollers' 2>&1; }

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
section "the librenms account, as crond's PAM account check reads it"
getent passwd librenms || echo "(getent passwd librenms: none)"
# the password field only when it is a lock (!, !!, *), never a hash
getent shadow librenms | awk -F: 'BEGIN { OFS = ":" } { if ($2 !~ /^[!*]*$/) $2 = "<hash>"; print }' || echo "(getent shadow librenms: none)"
echo "PAM's account check for crond (as crond makes it): $(python3 -I "$HERE/../lib/pam_account.py" crond librenms 2>&1)"
chage -l librenms 2>&1
ls -l --time-style=full-iso /etc/passwd /etc/shadow /etc/group /etc/gshadow /etc/passwd- /etc/shadow- 2>&1
echo "users in /etc/passwd without an /etc/shadow entry: $(comm -23 <(cut -d: -f1 /etc/passwd | sort) <(cut -d: -f1 /etc/shadow | sort) | tr '\n' ' ')"
grep -E '^(passwd|shadow|group):' /etc/nsswitch.conf
echo "authselect: $(authselect current 2>&1 | head -n 1)"
echo "pwck -r:"; pwck -r 2>&1 | head -n 20
echo "rpm -V setup shadow-utils pam:"; rpm -V setup shadow-utils pam 2>&1
# Rocky's pam_unix reads /etc/shadow (mode 000) only through this helper, which a container's host may confine
ls -l /usr/sbin/unix_chkpwd 2>&1
echo "the kernel's AppArmor refusals of unix_chkpwd (a container's host):"
dmesg 2>/dev/null | grep -E 'apparmor="DENIED".*unix-chkpwd' | tail -n 3
echo "the installer's lines about users:"
grep -h -E 'useradd|usermod|userdel|groupadd|pwconv|librenms user|the user |account check' /var/log/librenms-installer/install-*.log 2>/dev/null | tail -n 20
echo "the packages installed last (rpm -qa --last):"; rpm -qa --last 2>/dev/null | head -n 25

section "systemctl status crond"
systemctl status crond --no-pager -l 2>&1 | head -n 20
section "journalctl -u crond (the last 40 lines)"
journalctl -u crond --no-pager -n 40 -o short-iso 2>&1 | grep -v '^-- '
section "crond's first and last PAM refusals (when they began)"
journalctl -u crond --no-pager -o short-iso 2>&1 | grep -E 'PAM|FAILED|unix_chkpwd' | sed -n '1,4p'
echo "..."
journalctl -u crond --no-pager -o short-iso 2>&1 | grep -E 'PAM|FAILED|unix_chkpwd' | tail -n 2
echo "crond's first job of librenms that ran: $(journalctl -u crond --no-pager -o short-iso 2>&1 | grep -m 1 '(librenms) CMD ' || echo none)"
section "/etc/cron.d/librenms"
cat /etc/cron.d/librenms 2>&1
section "/etc/pam.d/crond"
cat /etc/pam.d/crond 2>&1
section "the pollers table"
pollers
section "the tail of $LNMS/logs/librenms.log"
tail -n 40 "$LNMS/logs/librenms.log" 2>&1
section "the services"
for unit in crond librenms-scheduler.timer nginx php-fpm mariadb semaphore oxidized snmpd; do
    printf '%-26s %s\n' "$unit" "$(systemctl is-active "$unit" 2>&1)"
done
systemctl --failed --no-pager --no-legend 2>&1

if [[ ${1:-} == --run-poller ]]; then
    section "LibreNMS' poller cron job, run as cron runs it (as librenms, with cron's environment)"
    line=$(grep -E '^[^#]*poller-wrapper\.py' /etc/cron.d/librenms 2>/dev/null | head -n 1)
    read -r -a fields <<<"$line"
    command=${fields[*]:6}
    echo "+ $command"
    (cd / && runuser -u librenms -- env -i PATH=/usr/bin:/bin HOME="$LNMS" SHELL=/bin/sh USER=librenms LOGNAME=librenms /bin/sh -c "$command" 2>&1)
    echo "(exit code $?)"
    section "the poller wrapper with debug output (./poller-wrapper.py -d 1, as librenms)"
    (cd "$LNMS" && runuser -u librenms -- ./poller-wrapper.py -d 1 2>&1 | tail -n 40)
    section "the pollers table after these runs"
    pollers
fi
exit 0
