#!/bin/bash
# install/rocky/tests/diagnose.sh [--run-poller]: what tells why LibreNMS' polling, or a service, of a test system does
# not work: crond, its journal and its PAM configuration, LibreNMS' cron file, the pollers table, the tail of LibreNMS'
# log, the services. CI prints it into the job log when a step fails (its artifacts cannot always be read). With
# --run-poller it then runs LibreNMS' poller cron job as cron runs it, and the wrapper with debug output: they update
# the pollers table, so they come after what shows whether cron did. Run as root on the test system.
set -uo pipefail
LNMS=/opt/librenms
section() { printf '\n===== %s\n' "$*"; }
pollers() { mysql -t -e 'SELECT poller_name, last_polled, NOW() AS now, devices, time_taken FROM librenms.pollers' 2>&1; }

section "systemctl status crond"
systemctl status crond --no-pager -l 2>&1 | head -n 20
section "journalctl -u crond (the last 40 lines)"
journalctl -u crond --no-pager -n 40 -o short-iso 2>&1 | grep -v '^-- '
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
