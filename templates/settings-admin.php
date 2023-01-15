<?php

declare(strict_types=1);

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

function FormatMegabytes(int $byte): string {
	$unim = ['MB', 'GB', 'TB', 'PB'];
	$count = 0;
	while ($byte >= 1024) {
		$count++;
		$byte /= 1024;
	}
	return number_format($byte, 2, '.', '.') . ' ' . $unim[$count];
}

/** @var \OCA\ServerInfo\Resources\Memory $memory */
$memory = $_['memory'];
/** @var \OCA\ServerInfo\Resources\Disk[] $disks */
$disks = $_['diskinfo'];
?>

<div class="server-info-wrapper">

	<!-- SERVER INFOS -->
	<div class="section server-infos-two">
		<div class="row">
			<div class="col col-6 col-l-12">
				<h2>
					<img class="infoicon" src="<?php p(image_path('core', 'actions/screen.svg')); ?>">
					<?php p($_['hostname']); ?>
				</h2>
				<p><?php p($l->t('Operating System:')); ?> <strong id="numFilesStorage"><?php p($_['osname']); ?></strong></p>
				<p><?php p($l->t('CPU:')); ?>
				<?php if ($_['cpu'] !== 'Unknown Processor'): ?>
				<strong id="numFilesStorage"><?php p($_['cpu']) ?></strong></p>
				<?php else: ?>
				<strong id="numFilesStorage"><?php p($l->t('Unknown Processor')) ?></strong></p>
				<?php endif; ?>
				<p><?php p($l->t('Memory:')); ?>
				<?php if ($memory->getMemTotal() > 0): ?> <strong id="numFilesStorage"><?php p(FormatMegabytes($memory->getMemTotal())) ?></strong></p>
				<?php endif; ?>
				<p><?php p($l->t('Server time:')); ?> <strong id="numFilesStorage"><span class="info" id="servertime"></span></strong></p>
				<p><?php p($l->t('Uptime:')); ?> <strong id="numFilesStorage"><span class="info" id="uptime"></span></strong></p>
			</div>

			<div class="col col-6 col-l-12">
				<h2>
					<img class="infoicon" src="<?php p(image_path('serverinfo', 'app-dark.svg')); ?>">
					<?php p($l->t('Temperature')); ?>
				</h2>
				<div class="table-wrapper">
					<table class="server-infos-table">
						<thead>
						</thead>
						<tbody>
						<?php foreach ($_['thermalzones'] as $thermalzone): ?>
						<tr>
							<td><?php p($thermalzone['type'] . ':') ?></td>
							<td><span class="info" id="<?php p($thermalzone['hash']) ?>"></span>°C</td>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
	
	<div class="section server-infos-two">	
		<div class="row">
			<div class="col col-6 col-l-12">
				<h2>
					<img class="infoicon" src="<?php p(image_path('core', 'actions/screen.svg')); ?>">
					<?php p($l->t('Load')); ?>
				</h2>
				<div id="cpuSection" class="infobox">
					<div class="cpu-wrapper">
						<canvas id="cpuloadcanvas" style="width:100%; height:200px" width="600" height="200"></canvas>
					</div>
				</div>
				<p><em id="cpuFooterInfo"></em></p>
			</div>

			<div class="col col-6 col-l-12">
				<h2>
					<img class="infoicon" src="<?php p(image_path('core', 'actions/quota.svg')); ?>">
					<?php p($l->t('Memory')); ?>
				</h2>
				<div id="memorySection" class="infobox">
					<div class="memory-wrapper">
						<canvas id="memorycanvas" style="width:100%; height:200px" width="600" height="200"></canvas>
					</div>
				</div>
				<p><span class="rambox" id="rambox">&nbsp;&nbsp;</span>&nbsp;&nbsp;<em id="memFooterInfo"></em></p>
				<p><span class="swapbox" id="swapbox">&nbsp;&nbsp;</span>&nbsp;&nbsp;<em id="swapFooterInfo"></em></p>
			</div>

		</div>
	</div>

	<!-- DISK STATUS -->
	<div class="section disk-status">
		<div class="row">
			<div class="col col-12">
				<h2>
					<img class="infoicon" src="<?php p(image_path('core', 'actions/quota.svg')); ?>">
					<?php p($l->t('Disk')); ?>
				</h2>
			</div>
			<?php foreach ($disks as $disk): ?>
				<div class="col col-4 col-xl-6 col-m-12">
					<div class="infobox">
						<div class="diskchart-container">
							<canvas id="DiskChart" class="DiskChart" style="width:100%; height:200px" width="600"
									height="200"></canvas>
						</div>
						<div class="diskinfo-container">
							<h3><?php p(basename($disk->getDevice())); ?></h3>
							<?php p($l->t('Mount:')); ?>
							<span class="info"><?php p($disk->getMount()); ?></span><br>
							<?php p($l->t('Filesystem:')); ?>
							<span class="info"><?php p($disk->getFs()); ?></span><br>
							<?php p($l->t('Size:')); ?>
							<span class="info"><?php p(FormatMegabytes($disk->getUsed() + $disk->getAvailable())); ?></span><br>
							<?php p($l->t('Available:')); ?>
							<span class="info"><?php p(FormatMegabytes($disk->getAvailable())); ?></span><br>
							<?php p($l->t('Used:')); ?>
							<span class="info"><?php p($disk->getPercent()); ?></span><br>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="smallinfo">
			<?php p($l->t('You will get a notification once one of your disks is nearly full.')); ?>
		</div>

		<p><?php p($l->t('Files:')); ?> <strong id="numFilesStorage"><?php p($_['storage']['num_files']); ?></strong></p>
		<p><?php p($l->t('Storages:')); ?> <strong id="numFilesStorages"><?php p($_['storage']['num_storages']); ?></strong></p>
		<?php if ($_['system']['freespace'] !== null): ?>
			<p><?php p($l->t('Free Space:')); ?> <strong id="systemDiskFreeSpace"><?php p($_['system']['freespace']); ?></strong></p>
		<?php endif; ?>
	</div>

	<!-- NETWORK -->
	<div class="section network-infos">
		<div class="row">
			<div class="col col-12">
				<h2>
					<img class="infoicon" src="<?php p(image_path('core', 'categories/integration.svg')); ?>">
					<?php p($l->t('Network')); ?>
				</h2>
			</div>

			<div class="col col-12">
				<?php p($l->t('Hostname:')); ?>
				<span class="info"><?php p($_['networkinfo']['hostname']); ?></span>
			</div>
			<div class="col col-12">
				<?php p($l->t('DNS:')); ?>
				<span class="info"><?php p($_['networkinfo']['dns']); ?></span>
			</div>
			<div class="col col-12">
				<?php p($l->t('Gateway:')); ?>
				<span class="info"><?php p($_['networkinfo']['gateway']); ?></span>
			</div>
			<div class="col col-12">
				<div class="row">
					<?php foreach ($_['networkinterfaces'] as $interface): ?>

						<div class="col col-4 col-l-6 col-m-12">
							<div class="infobox">
								<div class="interface-wrapper">
									<h3><?php p($interface['interface']) ?></h3>
									<?php p($l->t('Status:')); ?>
									<span class="info"><?php p($interface['status']) ?></span><br>
									<?php p($l->t('Speed:')); ?>
									<span
										class="info"><?php p($interface['speed'] . ' ' . $interface['duplex']) ?></span><br>
									<?php if (!empty($interface['mac'])): ?>
										<?php p($l->t('MAC:')); ?>
										<span class="info"><?php p($interface['mac']) ?></span><br>
									<?php endif; ?>
									<?php p($l->t('IPv4:')); ?>
									<span class="info"><?php p($interface['ipv4']) ?></span><br>
									<?php p($l->t('IPv6:')); ?>
									<span class="info"><?php p($interface['ipv6']) ?></span>
								</div>
							</div>
						</div>

					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>

	<!-- ACTIVE USER & SHARES-->
	<div class="section active-users-shares">
		<div class="row">

			<div class="col col-6 col-m-12">
				<!-- ACTIVE USERS -->
				<h2>
					<img class="infoicon" src="<?php p(image_path('core', 'categories/social.svg')); ?>">
					<?php p($l->t('Active users')); ?>
				</h2>
				<div class="infobox">
					<div class="active-users-wrapper">
						<br>
						<div class="chart-container">
							<canvas data-users="<?php p(json_encode($_['activeUsers'])) ?>"
									class="barchart"
									id="activeuserscanvas"
									style="width:100%; height:200px"
									width="300" height="300"
							></canvas>
						</div>
						<p>
							<?php p($l->t('Total users:')); ?>
							<em id="numUsersStorage"><?php p($_['storage']['num_users']); ?></em>
						</p>
					</div>
				</div>
			</div>

			<div class="col col-6 col-m-12">
				<!-- SHARES -->
				<h2>
					<img class="infoicon" src="<?php p(image_path('core', 'places/files.svg')); ?>">
					<?php p($l->t('Shares')); ?>
				</h2>
				<div class="infobox">
					<div class="shares-wrapper">
						<br>
						<div class="chart-container">
							<canvas data-shares="<?php p(json_encode($_['shares'])) ?>"
									class="barchart"
									id="sharecanvas"
									style="width:100%; height:200px"
									width="300" height="300"
							></canvas>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- PHP, DATABASE -->
	<div class="section php-database">
		<div class="row">
			<div class="col col-6 col-m-12">
				<!-- PHPINFO -->
				<h2>
					<img class="infoicon" src="<?php p(image_path('core', 'actions/screen.svg')); ?>">
					<?php p($l->t('PHP')); ?>
				</h2>
				<div class="infobox">
					<div class="phpinfo-wrapper">
						<p>
							<?php p($l->t('Version:')); ?>
							<em id="phpVersion"><?php p($_['php']['version']); ?></em>
						</p>
						<p>
							<?php p($l->t('Memory limit:')); ?>
							<em id="phpMemLimit"><?php p($_['php']['memory_limit']); ?></em>
						</p>
						<p>
							<?php p($l->t('Max execution time:')); ?>
							<em id="phpMaxExecTime"><?php p($_['php']['max_execution_time']); ?></em>
						</p>
						<p>
							<?php p($l->t('Upload max size:')); ?>
							<em id="phpUploadMaxSize"><?php p($_['php']['upload_max_filesize']); ?></em>
						</p>
						<p>
							<?php p($l->t('Extensions:')); ?>
							<em id="phpExtensions"><?php p($_['php']['extensions'] !== null ? implode(', ', $_['php']['extensions']) : $l->t('Unable to list extensions')); ?></em>
						</p>
					</div>
				</div>
			</div>

			<div class="col col-6 col-m-12">
				<!-- DATABASE -->
				<h2>
					<img class="infoicon" src="<?php p(image_path('core', 'actions/screen.svg')); ?>">
					<?php p($l->t('Database')); ?>
				</h2>
				<div class="infobox">
					<div class="database-wrapper">
						<p>
							<?php p($l->t('Type:')); ?>
							<em id="databaseType"><?php p($_['database']['type']); ?></em>
						</p>
						<p>
							<?php p($l->t('Version:')); ?>
							<em id="databaseVersion"><?php p($_['database']['version']); ?></em>
						</p>
						<p>
							<?php p($l->t('Size:')); ?>
							<em id="databaseSize"><?php p($_['database']['size']); ?></em>
						</p>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- EXTERNAL MONITORING-->
	<div class="section monitoring">
		<div class="row">
			<div class="col col-m-12">
				<!-- OCS ENDPOINT -->
				<h2><?php p($l->t('External monitoring tool')); ?></h2>
				<p>
					<?php p($l->t('You can connect an external monitoring tool by using this end point:')); ?>
				</p>
				<div class="monitoring-wrapper">
					<input type="text" readonly="readonly" id="monitoring-endpoint-url" value="<?php echo p($_['ocs']); ?>"/>
					<a class="clipboardButton icon icon-clippy" data-clipboard-target="#monitoring-endpoint-url"></a>
				</div>
				<p class="settings-hint">
					<?php p($l->t('Appending "?format=json" at the end of the URL gives you the result in JSON.')); ?>
				</p>
				<p>
					<?php p($l->t('To use an access token, please generate one then set it using the following command:')); ?>
					<div><i>occ config:app:set serverinfo token --value yourtoken</i></div>
				</p>
				<p>
					<?php p($l->t('Then pass the token with the "NC-Token" header when querying the above URL.')); ?>
				</p>
			</div>
		</div>
	</div>

</div>

