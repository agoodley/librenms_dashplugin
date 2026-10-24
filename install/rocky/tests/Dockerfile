# A Rocky Linux test system for install/rocky/install.sh: systemd as PID 1 (run it --privileged).
# Build: docker build --build-arg BASE=quay.io/rockylinux/rockylinux:9 -t inst-rocky9 install/rocky/tests
ARG BASE=quay.io/rockylinux/rockylinux:9
FROM ${BASE}
ENV container=docker
# Optional extra CA certificates (a TLS-intercepting proxy): put them in ca/ (an empty directory is fine).
COPY ca/ /etc/pki/ca-trust/source/anchors/
RUN update-ca-trust \
 && dnf -y install systemd systemd-sysv procps-ng iproute hostname which findutils tar gzip less cronie util-linux \
 && dnf clean all
# A test container may share the host's network (docker run --network host): nothing in it may touch the host's
# interfaces, routes, firewall, clock, kernel modules or sysctls, so the units that would are masked (systemctl mask
# works before their packages are installed), and entrypoint.sh makes /proc/sys and /sys read-only.
RUN systemctl mask firewalld.service NetworkManager.service NetworkManager-wait-online.service \
      systemd-networkd.service systemd-networkd.socket systemd-networkd-wait-online.service systemd-resolved.service \
      systemd-udevd.service systemd-udevd-control.socket systemd-udevd-kernel.socket systemd-udev-trigger.service \
      systemd-udev-settle.service systemd-sysctl.service systemd-modules-load.service systemd-timesyncd.service \
      chronyd.service auditd.service tuned.service irqbalance.service kdump.service nftables.service iptables.service \
      ip6tables.service ebtables.service lvm2-monitor.service multipathd.service dm-event.service dm-event.socket \
      rngd.service smartd.service systemd-hwdb-update.service getty@tty1.service console-getty.service \
      serial-getty@.service systemd-logind.service \
 && systemctl set-default multi-user.target
COPY entrypoint.sh /usr/local/sbin/test-entrypoint
STOPSIGNAL SIGRTMIN+3
ENTRYPOINT ["/usr/local/sbin/test-entrypoint"]
