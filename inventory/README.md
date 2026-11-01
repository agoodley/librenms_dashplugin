# LibreNMS Inventory plugin

Inventory reports across the network from what [LibreNMS](https://www.librenms.org) already collects, for the devices
each user may see:

* **models** across the network, each unit counted (each member of a stack by its own model), with the software
  versions they run;
* **software versions**: each OS and version with its devices and their models;
* **serial numbers** of the devices and of their hardware inventory: chassis, line cards, power supplies, fans and
  pluggables, with their hardware, firmware and software revisions;
* **optics** by type, vendor, model and wavelength, with the port each sits in, and whether it is the vendor of the
  switch it sits in or a third-party one;
* a **search** for a serial number or part number: which device, module or optic has it, and where;

filtered by device group, location and OS, each exported as CSV, and an **Inventory** panel on each device's page (its
stack members with their serial numbers, how many serial numbers and optics it has). The plugin polls nothing and keeps
no data: it reads LibreNMS' tables (`devices`, `entPhysical`, `transceivers`). It is phase 1 of the
[inventory & lifecycle plan](../ROADMAP.md#inventory--lifecycle); software compliance and end-of-life dates come next.

It is a plugin of its own in this repository, next to the [topology plugin](../README.md), the
[Ansible plugin](../ansible/README.md) and the [compliance plugin](../compliance/README.md), and needs none of them.

![The overview](docs/images/overview.png)

*(Screenshots: the test network of the [installer](../install/rocky/README.md), with a simulated stack of three Cisco
switches.)*

## Contents

* [Requirements](#requirements)
* [Installation](#installation)
* [Pages](#pages)
* [Optics: genuine or third-party](#optics-genuine-or-third-party)
* [Permissions](#permissions)
* [Limitations](#limitations)

## Requirements

* LibreNMS 26.x (developed and tested against 26.10) with PHP 8.2+.
* LibreNMS' discovery modules `os` (model, version, serial number and features of each device), `entity-physical`
  (the hardware inventory: LibreNMS' **Inventory** tab) and `transceivers` (the optics, for the OSes LibreNMS reads them
  of). They are on by default; LibreNMS refreshes them at discovery (every 6 hours by default).

## Installation

The plugin is the Composer package `agoodley/librenms-inventory-plugin` in the `inventory/` directory of this
repository, installed from a clone through a Composer *path* repository, as the other plugins are. The
[installer](../install/rocky/README.md) for Rocky Linux sets it up with the other three. By hand, create the directory
of the clone as root, then run everything else as the `librenms` user:

```bash
# as root: a directory for the clone, owned by the librenms user (it cannot create one in /opt itself)
mkdir -p /opt/librenms-plugins && chown librenms:librenms /opt/librenms-plugins

sudo su - librenms

# 1. a clone of the repository (skip when another of its plugins installed one)
git clone https://github.com/agoodley/librenms_dashplugin /opt/librenms-plugins/librenms_dashplugin

# 2. tell composer where the package is (stored in the librenms user's global composer config, so it survives
#    LibreNMS updates)
composer config --global repositories.librenms-inventory '{"type": "path", "url": "/opt/librenms-plugins/librenms_dashplugin/inventory", "options": {"symlink": true}}'

# 3. install it with LibreNMS' plugin installer, then renew LibreNMS' route cache (plugin:add leaves it without the
#    plugin's pages, which then answer 500: "Route [inventory.overview] not defined")
cd /opt/librenms
./lnms plugin:add agoodley/librenms-inventory-plugin @dev
./lnms route:cache
```

The plugin is enabled when it is installed (LibreNMS enables new plugins). Its pages: **Overview → Plugins →
Inventory**. It has no tables and no settings.

**Updating:** `git pull` in the clone, then `./lnms route:cache`.

**Removing:** `./lnms plugin:remove agoodley/librenms-inventory-plugin`.

## Pages

Every page has the filters device group, location and OS, its own filters and a CSV export of what it shows (all of
it, not one page of it), and a search box for a serial number or part number.

**Overview** (`plugin/inventory/overview`): the devices, how many models (units: each member of a stack counted),
software versions, serial numbers and optics; then

* **Models:** each chassis of a device's hardware inventory by its model, so a stack of two 48-port and one 24-port
  switch counts as two units of one model and one of the other; a device without a chassis that names its model counts
  by the model LibreNMS has for it. With the devices, their OS and the software versions the units run, newest first.
  A model links to its devices.
* **Software versions:** each OS and version (newest first) with its devices and their models; a version links to its
  devices.

**Devices** (`plugin/inventory/devices`): each device's location, OS, model, software version and features, the serial
number LibreNMS shows for it (a stack's: its first member's) and each of its chassis with their serial numbers, how many
serial numbers its hardware inventory has and how many optics. Filtered by model (a member of a stack too), version and
a text of the name, serial number or model.

![Devices](docs/images/devices.png)

**Modules & serial numbers** (`plugin/inventory/modules`): the entries of the hardware inventory with a serial number,
and the chassis, modules, power supplies, fans and pluggables with a model: their class, name, model, serial number,
hardware, firmware and software revisions, whether they are field replaceable, and their description. Filtered by class
and a text of the serial number, model, name or description.

![Modules and serial numbers](docs/images/modules.png)

**Optics** (`plugin/inventory/optics`): how many are genuine, third-party or unknown, then the optics by kind (type,
vendor, model and wavelength, the most common first) and each optic with its device, port (and the port's status when
it is not up), type, vendor, model and revision, serial number, wavelength, distance and origin. Filtered by type,
vendor, origin and a text of the serial number, model or port.

![Optics](docs/images/optics.png)

**Search** (`plugin/inventory/search?q=FCW19`): a serial number or part number, or a part of it (3 characters or more),
in the devices (their serial number and model), their hardware inventory and their optics: where each match is, with
its device, location and port.

![Search](docs/images/search.png)

**Device page:** an **Inventory** panel with the device's chassis when it has several (a stack's members with their
serial numbers and models), how many serial numbers its hardware inventory has and its optics, with links to them. A
device with none of these gets no panel.

![The device panel](docs/images/device-panel.png)

**Exports** (`plugin/inventory/export/devices|modules|optics|models|versions`, with the page's filters): CSV with a header
row, quoted where needed; a cell a spreadsheet would read as a formula gets an apostrophe first.

## Optics: genuine or third-party

An optic is **genuine** when its vendor names the maker of the device it sits in (Cisco optics report "CISCO-FINISAR",
Arista ones "Arista Networks") or its part number is that maker's (Juniper's `740-…`, HPE's `J9150A`), **third-party**
when its vendor is another one, and **unknown** when LibreNMS has no vendor for it (often for Cisco optics: then the
model, such as `GLC-SX-MMD`, says more) or the device's maker is not one the plugin knows: Cisco, Juniper, Arista,
HPE Aruba, Dell, Extreme, Huawei, MikroTik, Ubiquiti, Fortinet and Palo Alto Networks, by the device's LibreNMS OS.
Optics coded for a switch report that switch's maker whoever made them: this tells the coding, not the factory.

## Permissions

Every LibreNMS user may open the pages, each for the devices LibreNMS lets them see, as on LibreNMS' own device and
inventory pages; the search and the exports too. The hardware inventory, the optics and the ports have permissions of
their own in LibreNMS (its policies for `EntPhysical`, `Transceiver` and `Port`, which custom roles can leave out):
what a user's role may not view, the pages leave out and say so.

## Limitations

* The data is what LibreNMS' discovery found at its last run (every 6 hours by default): a module swapped since shows
  after the next discovery. The plugin keeps no history of it yet (phase 4 of the plan).
* A device's serial number is the one LibreNMS shows for it, its first stack member's for a stack; each member's comes
  from the hardware inventory, which some OSes report only in part or not at all.
* LibreNMS reads optics for the OSes it supports; a switch it reads none of shows none. Their vendor is often empty for
  Cisco optics, so their origin is unknown.
* The lists show 500 rows a page; the exports have every row.

## License

GPL-3.0-or-later, like LibreNMS.
