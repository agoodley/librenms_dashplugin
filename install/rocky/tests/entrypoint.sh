#!/bin/sh
# PID 1 of a test container: make /proc/sys and /sys read-only (in this container's mount namespace only), so that
# with --network host nothing here can change the host's sysctls or network interfaces, then start systemd.
# Arguments go to systemd (on a cgroup v1 host, Rocky 10's systemd needs the two run-test.sh passes).
set -e
mount --bind /proc/sys /proc/sys && mount -o remount,bind,ro /proc/sys
mount --rbind /sys /sys && mount -o remount,bind,ro /sys
exec /usr/sbin/init "$@"
