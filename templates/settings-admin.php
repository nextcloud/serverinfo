<?php
/**
 * @copyright Copyright (c) 2016 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


script('serverinfo', 'script');
script('serverinfo', 'smoothie');
script('serverinfo', 'Chart.min');

style('serverinfo', 'style');

function FormatBytes($byte) {
	$unim = array('B','KB','MB','GB','TB','PB');
	$count = 1;
	while ($byte>=1024) {
		$count++;
		$byte = $byte/1024;
	}
	return number_format($byte,2,'.','.').' '.$unim[$count];
}

?>

<div class="section"><h2><?php echo('<img class="infoicon" src="'.image_path('serverinfo', 'server.svg').'">'); p($_['hostname']); ?></h2>

	<p>
		<?php p($l->t('Operating System').': '); ?>
		<span class="info"><?php p($_['osname']); ?></span>
	</p><p>
		<?php p($l->t('CPU').': '); ?>
		<span class="info"><?php p($_['cpu']); ?></span>
	</p><p>
		<?php p($l->t('Memory').': '); ?>
		<span class="info"><?php p($_['memory']); ?></span>
	</p><p>
		<?php p($l->t('Server time').': '); ?>
		<span class="info" id="servertime"></span>
	</p><p>
		<?php p($l->t('Uptime').': '); ?>
		<span class="info" id="uptime"></span>
	</p><p>
		<?php p($l->t('Time Servers').': '); ?>
		<span class="info" id="timeservers"></span>
	</p>



	<div class="wrapper">
	    <div id="one">

			<div class="infobox" id="cpuSection">
				<h2><?php p($l->t('Load'));?></h2>
				<canvas id="cpuloadcanvas" width="100" height="150"></canvas>
			</div>
			<p><em id="cpuFooterInfo"></em></p>

		</div>
		<div id="two">

			<div class="infobox">
			<h2><?php p($l->t('Memory'));?></h2>
			<canvas id="memorycanvas" width="300" height="150"></canvas>
			</div>
			<p><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAIAAAACUFjqAAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH4QYBDCANxB9ROQAAABl0RVh0Q29tbWVudABDcmVhdGVkIHdpdGggR0lNUFeBDhcAAAAUSURBVBjTY2T4z4AHMDEwjEpjAgBALQETBkU7DgAAAABJRU5ErkJggg==" alt="RAM color" />&nbsp;&nbsp;<em id="memFooterInfo"></em></p>
			<p><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAIAAAACUFjqAAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH4QYBDB0ds0AJ4wAAABl0RVh0Q29tbWVudABDcmVhdGVkIHdpdGggR0lNUFeBDhcAAAASSURBVBjTY/zPgA8wMYxKYwEAQSwBE2ViEDMAAAAASUVORK5CYII=" alt="SWAP color" />&nbsp;&nbsp;<em id="swapFooterInfo"></em></p>


		</div>
	</div>



</div>

<div class="section"><h2><?php echo('<img class="infoicon" src="'.image_path('serverinfo', 'hdd-o.svg').'">'); p($l->t('Disk')); ?></h2>
	<p>
		<?php
		foreach ($_['diskinfo'] as $disk) {

			echo('<div class="infobox">');

			echo('<div class="diskchart-container">');
			echo('<canvas id="DiskChart" class="DiskChart" width="50" height="50"></canvas>');
			echo('</div>');

			echo('<h3>'.basename($disk['device']).'</h3>');
			echo('<p>');
			p($l->t('Mount').': ');
			echo('<span class="info">'.$disk['mount'].'</span>');
			echo('</p><p>');
			p($l->t('Filesystem').': ');
			echo('<span class="info">'.$disk['fs'].'</span>');
			echo('</p><p>');
			p($l->t('Size').': ');
			echo('<span class="info">'.FormatBytes($disk['used']+$disk['available']).'</span>');
			echo('</p><p>');
			p($l->t('Available').': ');
			echo('<span class="info">'.FormatBytes($disk['available']).'</span>');
			echo('</p><p>');
			p($l->t('Used').': ');
			echo('<span class="info">'.$disk['percent'].'</span>');
			echo('</p></div>');
		}
		?>
		<div class="smallinfo"> <?php p($l->t('You will get a notification once one of your disks is nearly full.')); ?></div>

		<p><?php p($l->t('Files:'));?> <em id="numFilesStorage"><?php p($_['storage']['num_files']);?></em></p>
		<p><?php p($l->t('Storages:'));?> <em id="numFilesStorages"><?php p($_['storage']['num_storages']);?></em></p>
		<p><?php p($l->t('Free Space:'));?> <em id="systemDiskFreeSpace"><?php p($_['system']['freespace']);?></em></p>
	</p>
</div>

<div class="section"><h2><?php echo('<img class="infoicon" src="'.image_path('serverinfo', 'sort.svg').'">'); p($l->t('Network')); ?></h2>
	<p>

		<p>
			<?php p($l->t('Hostname').': '); ?>
			<span class="info"><?php p($_['networkinfo']['hostname']); ?></span>
		</p><p>
			<?php p($l->t('DNS').': '); ?>
			<span class="info"><?php p($_['networkinfo']['dns']); ?></span>
		</p><p>
			<?php p($l->t('Gateway').': '); ?>
			<span class="info"><?php p($_['networkinfo']['gateway']); ?></span>
		</p><p>


<?php

		foreach ($_['networkinterfaces'] as $interface) {
				echo('<div class="infobox">');
				echo('<h3>'.$interface['interface'].'</h3>');
				echo('<p>');
				p($l->t('Status').': ');
				echo('<span class="info">'.$interface['status'].'</span>');
				echo('</p><p>');
				p($l->t('Speed').': ');
				echo('<span class="info">'.$interface['speed'].' '.$interface['duplex'].'</span>');
				echo('</p><p>');

				if(!empty($interface['mac'])) {
					p($l->t('MAC').': ');
					echo('<span class="info">'.$interface['mac'].'</span>');
					echo('</p><p>');
				}
				p($l->t('IPv4').': ');
				echo('<span class="info">'.$interface['ipv4'].'</span>');
				echo('</p><p>');
				p($l->t('IPv6').': ');
				echo('<span class="info">'.$interface['ipv6'].'</span>');
				echo('</p></div>');
		}

		?>
	</p>
</div>


<div class="section" id="activeUsersSection">
	<div class="infobox">
	<h2><?php p($l->t('Active users'));?></h2>
	<br>
	<div class="chart-container">'
	<canvas width="400" height="250" data-users="<?php p(json_encode($_['activeUsers'])) ?>" class="barchart" id="activeuserscanvas"></canvas>
	</div>
	<p><?php p($l->t('Total users:'));?> <em id="numUsersStorage"><?php p($_['storage']['num_users']);?></em></p>
</div>
</div>
<div class="section" id="sharesSection">
<div class="infobox">
	<h2><?php p($l->t('Shares'));?></h2>
	<br>
	<div class="chart-container">'
	<canvas data-shares="<?php p(json_encode($_['shares'])) ?>" class="barchart" id="sharecanvas"></canvas>
</div>
</div>
</div>


<div class="section" id="phpSection"><h2><?php echo('<img class="infoicon" src="'.image_path('serverinfo', 'hdd-o.svg').'">'); p($l->t('PHP')); ?></h2>
	<div class="infobox">
	<p><?php p($l->t('Version:'));?> <em id="phpVersion"><?php p($_['php']['version']);?></em></p>
	<p><?php p($l->t('Memory Limit:'));?> <em id="phpMemLimit"><?php p($_['php']['memory_limit']);?></em></p>
	<p><?php p($l->t('Max Execution Time:'));?> <em id="phpMaxExecTime"><?php p($_['php']['max_execution_time']);?></em></p>
	<p><?php p($l->t('Upload max size:'));?> <em id="phpUploadMaxSize"><?php p($_['php']['upload_max_filesize']);?></em></p>
	</div>
</div>

<div class="section" id="databaseSection"><h2><?php echo('<img class="infoicon" src="'.image_path('serverinfo', 'hdd-o.svg').'">'); p($l->t('Database')); ?></h2>
	<div class="infobox">
	<p><?php p($l->t('Type:'));?> <em id="databaseType"><?php p($_['database']['type']);?></em></p>
	<p><?php p($l->t('Version:'));?> <em id="databaseVersion"><?php p($_['database']['version']);?></em></p>
	<p><?php p($l->t('Size:'));?> <em id="databaseSize"><?php p($_['database']['size']);?></em></p>
	</div>
</div>

<div class="section" id="ocsEndPoint">
	<h2><?php p($l->t('External monitoring tool'));?></h2>
	<p>
		<?php p($l->t('You can connect an external monitoring tool by using this end point:')); ?>
	</p>
	<div>
		<input type="text" readonly="readonly" id="monitoring-endpoint-url" value="<?php echo p($_['ocs']); ?>" />
		<a class="clipboardButton icon icon-clippy" data-clipboard-target="#monitoring-endpoint-url"></a>
		<span class="icon-info svg" title="" data-original-title="<?php p($l->t('Did you know?')); ?> <?php p($l->t('Appending "?format=json" at the end of the URL gives you the result in JSON format!')); ?>"></span>
	</div>
</div>
