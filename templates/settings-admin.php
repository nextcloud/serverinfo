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
?>

<div class="section" id="cpuSection">
	<h2><?php p($l->t('CPU load'));?></h2>
	<canvas id="cpuloadcanvas" width="600" height="150"></canvas>
	<p><em id="cpuFooterInfo"></em></p>
</div>
<div class="section" id="memorySection">
	<h2><?php p($l->t('Memory usage'));?></h2>
	<canvas id="memorycanvas" width="600" height="150"></canvas>
	<p><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAIAAAACUFjqAAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH4QYBDCANxB9ROQAAABl0RVh0Q29tbWVudABDcmVhdGVkIHdpdGggR0lNUFeBDhcAAAAUSURBVBjTY2T4z4AHMDEwjEpjAgBALQETBkU7DgAAAABJRU5ErkJggg==" alt="RAM color" />&nbsp;&nbsp;<em id="memFooterInfo"></em></p>
	<p><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAIAAAACUFjqAAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH4QYBDB0ds0AJ4wAAABl0RVh0Q29tbWVudABDcmVhdGVkIHdpdGggR0lNUFeBDhcAAAASSURBVBjTY/zPgA8wMYxKYwEAQSwBE2ViEDMAAAAASUVORK5CYII=" alt="SWAP color" />&nbsp;&nbsp;<em id="swapFooterInfo"></em></p>
</div>
<div class="section" id="activeUsersSection">
	<h2><?php p($l->t('Active users'));?></h2>
	<br>
	<canvas data-users="<?php p(json_encode($_['activeUsers'])) ?>" class="barchart" id="activeuserscanvas"></canvas>
</div>
<div class="section" id="sharesSection">
	<h2><?php p($l->t('Shares'));?></h2>
	<br>
	<canvas data-shares="<?php p(json_encode($_['shares'])) ?>" class="barchart" id="sharecanvas"></canvas>
</div>
<div class="section" id="storageSection">
	<h2><?php p($l->t('Storage'));?></h2>
	<p><?php p($l->t('Users:'));?> <em id="numUsersStorage"><?php p($_['storage']['num_users']);?></em></p>
	<p><?php p($l->t('Files:'));?> <em id="numFilesStorage"><?php p($_['storage']['num_files']);?></em></p>
</div>
<div class="section" id="phpSection">
	<h2><?php p($l->t('PHP'));?></h2>
	<p><?php p($l->t('Version:'));?> <em id="phpVersion"><?php p($_['php']['version']);?></em></p>
	<p><?php p($l->t('Memory Limit:'));?> <em id="phpMemLimit"><?php p($_['php']['memory_limit']);?></em></p>
	<p><?php p($l->t('Max Execution Time:'));?> <em id="phpMaxExecTime"><?php p($_['php']['max_execution_time']);?></em></p>
	<p><?php p($l->t('Upload max size:'));?> <em id="phpUploadMaxSize"><?php p($_['php']['upload_max_filesize']);?></em></p>
</div>
<div class="section" id="databaseSection">
	<h2><?php p($l->t('Database'));?></h2>
	<p><?php p($l->t('Type:'));?> <em id="databaseType"><?php p($_['database']['type']);?></em></p>
	<p><?php p($l->t('Version:'));?> <em id="databaseVersion"><?php p($_['database']['version']);?></em></p>
	<p><?php p($l->t('Size:'));?> <em id="dataBaseSize"><?php p($_['database']['size']);?></em></p>
</div>

<div class="section" id="ocsEndPoint">
	<h2><?php p($l->t('External monitoring tool'));?></h2>
	<p>
		<?php p($l->t('You can connect an external monitoring tool by using this end point: ') . $_['ocs']);?>
</div>
