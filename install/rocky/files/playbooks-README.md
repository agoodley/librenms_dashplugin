# Playbooks of the LibreNMS Ansible plugin

This Git repository is the one Semaphore UI's project "LibreNMS changes" runs its templates from. The LibreNMS
installer (`install/rocky/install.sh` of the plugins' repository) made it and keeps these files up to date:

* `inventory/librenms_inventory.py`: the plugin's inventory script (`ansible/contrib/librenms_inventory.py`): the
  runner fetches the devices from LibreNMS with it (`LIBRENMS_URL` and the secret `LIBRENMS_TOKEN` of the variable
  group "LibreNMS").
* `fix_port_descriptions.yml`, `fix_stp_root_priority.yml`: the example fix playbooks (`ansible/contrib/playbooks/`,
  Cisco IOS) of the templates "Fix port descriptions" and "Fix spanning tree root priority". Review and adapt them.

Only reviewed playbooks belong here: whoever holds the plugin's runner token can run any playbook of this repository
on the inventory's devices (the plugin's README, Safety). Add your own playbooks with a commit, and a template for
each in Semaphore UI (the plugin's README, Semaphore UI setup, step 9). A file you change is yours: the installer then
leaves it as it is and says so.

Clone it on the server as the semaphore user, who owns it (git refuses a repository of another user, root too:
"dubious ownership"): `sudo -u semaphore git clone /var/lib/semaphore/playbooks.git /tmp/playbooks`; commit and push
there as the semaphore user too (`sudo -u semaphore git -C /tmp/playbooks push`).
