# Security policy

This repository holds four LibreNMS plugins (topology, Ansible, compliance, inventory) and an installer for Rocky
Linux. The Ansible plugin changes network devices, so a flaw in it can matter more than a flaw in a dashboard.

## Reporting a vulnerability

Please report it privately, not in a public issue: use **Report a vulnerability** on the repository's *Security* tab
(GitHub's private vulnerability reporting). Say which plugin or installer script it is in, what someone can do with
it, and how to reproduce it. You will get an answer, and a fix is made on `main`; the report is credited unless you
ask otherwise.

## Supported versions

Only the latest commit of `main` is supported: there are no release branches.

## What is in scope

- Access to devices, data or actions a LibreNMS user should not have (a user who sees some devices seeing others; an
  admin without the plugin's run or approve permission changing devices; a change applied other than as dry-run and
  approved).
- Device output (SNMP, LLDP/CDP, configurations, Ansible logs) that makes a plugin show, store or run something it
  should not.
- The installer leaving secrets, files or services more open than it documents.

What the READMEs list as limitations (each plugin's *Limitations* section) is known; a way around one of the measures
they describe is in scope.
