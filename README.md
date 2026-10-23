<!--
 - SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Monitoring

[![REUSE status](https://api.reuse.software/badge/github.com/nextcloud/serverinfo)](https://api.reuse.software/info/github.com/nextcloud/serverinfo)

This apps provides useful server information, such as CPU load, RAM usage,
disk usage, number of users, etc. Check out the provided **Example XML output**
for the details.

The admin can look up this information directly in Nextcloud (Settings ->
Management-> Information) or connect an external monitoring tool to the
provided end-points.

## Installation

This app is part of the standard Nextcloud package and can be found in the
directory **nextcloud/apps/serverinfo**

## Operating system

The app keeps track of what keeps the Linux distribution under Nextcloud
secure:

- pending updates, security fixes first, with the packages Nextcloud itself
  runs on (PHP, the web server, the database, Redis …) marked
- whether a reboot is needed, and why (a newer kernel, updated core libraries,
  the distribution asking for one), with live kernel patches taken into account
- how long the release still receives security updates, and whether a paid
  extension (Ubuntu Pro or Debian ELTS) is active
- automatic updates, package list age, held packages, failed system services,
  time synchronisation, SELinux and AppArmor

Debian and Ubuntu are supported. Inside Docker, Podman, Kubernetes or a snap the status says
what can and cannot be known from inside; LXC and systemd-nspawn guests are
treated like machines without a kernel of their own.

Nothing is installed or changed. To find out, the app reads `/etc/os-release`,
`/proc` (uptime, `/proc/1/cgroup`), `/sys/class/dmi/id`,
`/sys/kernel/livepatch`, the SELinux and AppArmor state under `/sys`, the
package manager's configuration, state and logs, `/run/reboot-required` and the
installed kernel modules. It runs the package manager's query commands (updates
only in simulation mode), `systemctl`, `timedatectl`, `chronyc` and
`systemd-detect-virt`. Anything it may not read or run is reported as unknown.

The status is collected by a background job once an hour, when background
jobs run from system cron; under AJAX or webcron the commands would hold a web
request for minutes. A status older than three job intervals (at least six
hours), or background jobs that do not run from system cron, count as a
warning, so an old all-clear is never shown as current. Admins can also check
it right away with:

```
occ serverinfo:os-status [--refresh] [--output=json]
```

The command shows how old the status is and exits with 0, 1, 2 or 3 for ok,
warning, error and unknown, so it can be used as a monitoring check. Unknown means the status has not been
collected yet, or nothing is wrong but the pending updates could not be
determined. When something counts as a warning or an error is set by
the `os_*_days` app config values, see `lib/Config/ConfigLexicon.php`.

Release support dates are bundled with the app (see
`build/update-os-support.php`, data from [endoflife.date](https://endoflife.date),
MIT License); the app never looks them up online.

### Root helper

The web server cannot see everything: only root can tell which services still
run code an update replaced, and only root can refresh the package lists. The
optional script `bin/serverinfo-os-report` adds that. Run as root, it writes a
small JSON report that the app reads. It only reads the system; it never
installs, upgrades, restarts or reboots anything.

Install it outside the Nextcloud directory: the web server can write there, so
a root cron job pointing into it would hand the web server root.

Take the file from a freshly extracted release archive, not from the
installed app: the Nextcloud server archive, checked against the SHA-256 sum
or signature published next to it on download.nextcloud.com, or the app's own
release archive. A copy taken from `apps/serverinfo/bin` is safe from changes
made after copying, but not from a web server that was already compromised
and changed the file before. `cmp` shows whether the installed app's file
still matches the release. Repeat this when an update ships a newer helper
(the app then reports the installed helper as outdated). The release archive
does not contain this README; the same instructions are at the top of the
script.

```
tar -xjf nextcloud-<version>.tar.bz2 nextcloud/apps/serverinfo/bin/serverinfo-os-report
sudo install -o root -g root -m 0755 nextcloud/apps/serverinfo/bin/serverinfo-os-report /usr/local/sbin/
echo '17 */6 * * * root /usr/local/sbin/serverinfo-os-report --refresh --random-delay' | sudo tee /etc/cron.d/serverinfo-os-report
```

`--random-delay` waits up to 30 minutes first, so that servers sharing this
cron line do not all reach the package mirrors at the same minute; leave it
out when running the helper by hand. Where `flock` is available, a run that
finds another one still going exits without doing anything. Package manager
calls are stopped after a time limit where `timeout` is available.

The report is written to `/var/lib/serverinfo/os-report.json`. Use
`--output` and `occ config:app:set serverinfo os_helper_report_path --value …`
to put it elsewhere. The app ignores a report that anyone but root could have
written, one written before the last reboot or on another host, and one older
than 26 hours.

## API

The API provides a lot of information about a running Nextcloud
instance in XML or JSON format by using the following URL.

```
https://<nextcloud-fqdn>/ocs/v2.php/apps/serverinfo/api/v1/info
```

- To request the information in JSON append the url parameter `format=json`
- Use the url parameter `skipUpdate=true` to omit server updates.
- Use the url parameter `skipApps=true` to omit app updates (including available app updates will send an external request to the app store).

### Example XML output:
```
<?xml version="1.0"?>
<ocs>
	<meta>
		<status>ok</status>
		<statuscode>200</statuscode>
		<message>OK</message>
	</meta>
	<data>
		<nextcloud>
			<system>
				<version>30.0.0.1</version>
				<theme/>
				<enable_avatars>yes</enable_avatars>
				<enable_previews>yes</enable_previews>
				<memcache.local>OC\Memcache\APCu</memcache.local>
				<memcache.distributed>none</memcache.distributed>
				<filelocking.enabled>yes</filelocking.enabled>
				<memcache.locking>OC\Memcache\Redis</memcache.locking>
				<debug>no</debug>
				<freespace>48472801280</freespace>
				<cpuload>
					<element>1.81</element>
					<element>1.39</element>
					<element>1.24</element>
				</cpuload>
				<mem_total>8183664</mem_total>
				<mem_free>5877568</mem_free>
				<swap_total>0</swap_total>
				<swap_free>0</swap_free>
				<apps>
					<!-- only with skipApps=false -->
					<num_installed>53</num_installed>
					<num_updates_available>1</num_updates_available>
					<app_updates>
						<files_antivirus>2.0.1</files_antivirus>
					</app_updates>
				</apps>
				<update>
					<!-- only with skipUpdate=false -->
					<lastupdatedat>1719244666</lastupdatedat>
					<available/>
				</update>
			</system>
			<storage>
				<num_users>7</num_users>
				<num_files>708860</num_files>
				<num_storages>125</num_storages>
				<num_storages_local>7</num_storages_local>
				<num_storages_home>7</num_storages_home>
				<num_storages_other>111</num_storages_other>
			</storage>
			<shares>
				<num_shares>1</num_shares>
				<num_shares_user>0</num_shares_user>
				<num_shares_groups>0</num_shares_groups>
				<num_shares_link>0</num_shares_link>
				<num_shares_link_no_password>0</num_shares_link_no_password>
				<num_fed_shares_sent>0</num_fed_shares_sent>
				<num_fed_shares_received>0</num_fed_shares_received>
				<permissions_4_1>1</permissions_4_1>
			</shares>
		</nextcloud>
		<server>
			<webserver>Apache/2.4</webserver>
			<php>
				<version>7.2.14</version>
				<memory_limit>536870912</memory_limit>
				<max_execution_time>3600</max_execution_time>
				<upload_max_filesize>535822336</upload_max_filesize>
			</php>
			<database>
				<type>mysql</type>
				<version>10.2.21</version>
				<size>331382784</size>
			</database>
		</server>
		<activeUsers>
			<last5minutes>2</last5minutes>
			<last1hour>4</last1hour>
			<last24hours>5</last24hours>
		</activeUsers>
	</data>
</ocs>

```

### Example JSON output:
```json
{"ocs":{"meta":{"status":"ok","statuscode":200,"message":"OK"},"data":{"nextcloud":{"system":{"version":"30.0.0.1","theme":"","enable_avatars":"yes","enable_previews":"yes","memcache.local":"OC\\Memcache\\APCu","memcache.distributed":"none","filelocking.enabled":"yes","memcache.locking":"OC\\Memcache\\Redis","debug":"no","freespace":48472944640,"cpuload":[0.84999999999999997779553950749686919152736663818359375,1.04000000000000003552713678800500929355621337890625,1.1699999999999999289457264239899814128875732421875],"mem_total":8183664,"mem_free":5877156,"swap_total":0,"swap_free":0,"apps":{"num_installed":53,"num_updates_available":1,"app_updates":{"files_antivirus":"2.0.1"}}},"storage":{"num_users":7,"num_files":708860,"num_storages":125,"num_storages_local":7,"num_storages_home":7,"num_storages_other":111},"shares":{"num_shares":1,"num_shares_user":0,"num_shares_groups":0,"num_shares_link":0,"num_shares_link_no_password":0,"num_fed_shares_sent":0,"num_fed_shares_received":0,"permissions_4_1":"1"}},"server":{"webserver":"Apache\/2.4","php":{"version":"7.2.14","memory_limit":536870912,"max_execution_time":3600,"upload_max_filesize":535822336},"database":{"type":"mysql","version":"10.2.21","size":331382784}},"activeUsers":{"last5minutes":2,"last1hour":3,"last24hours":5}}}}
```

## Configuration

##### Background job to update storage statistics

Since collecting storage statistics might take time and cause slow downs, they are updated in the background. A background job runs once every three hours to update the number of storages and files. The interval can be overridden per app settings (the value is specified in seconds):

``php occ config:app:set --value=3600 serverinfo job_interval_storage_stats``

It is also possible to trigger the update manually per occ call. With verbose mode enabled, the current values are being printed.

```
php occ serverinfo:update-storage-statistics -v --output=json_pretty
{
    "num_users": 80,
    "num_files": 3934,
    "num_storages": 2545,
    "num_storages_local": 2,
    "num_storages_home": 2510,
    "num_storages_other": 33
}
```

##### Restricted mode (>= Nextcloud 28)

To obtain information about your server, the serverinfo app reads files outside the application directory (e.g. /proc on Linux) or executes shell commands (e.g. df on Linux). 

If you don't want that (for example, to avoid open_basedir warnings) enable the restricted mode.

Enable:

``php occ config:app:set --value=yes serverinfo restricted_mode``

Disable:

``php occ config:app:delete serverinfo restricted_mode``

##### Show phpinfo (>= Nextcloud 28)

Enable:

``php occ config:app:set --value=yes serverinfo phpinfo``

Disable:

``php occ config:app:delete serverinfo phpinfo``
