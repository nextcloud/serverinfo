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
	<p><em id="memFooterInfo"></em></p>
</div>
<div class="section" id="activeUsersSection">
	<h2><?php p($l->t('Active users'));?></h2>
	<br>
	<canvas class="barchart" id="activeuserscanvas"></canvas>
</div>
<div class="section" id="sharesSection">
	<h2><?php p($l->t('Shares'));?></h2>
	<br>
	<canvas class="barchart" id="sharecanvas"></canvas>
</div>
<div class="section" id="storageSection">
	<h2><?php p($l->t('Storage'));?></h2>
	<p><?php p($l->t('Users:'));?><em id="numUsersStorage"> -- </em></p>
	<p><?php p($l->t('Files:'));?><em id="numFilesStorage"> -- </em></p>
</div>
<div class="section" id="phpSection">
	<h2><?php p($l->t('PHP'));?></h2>
	<p><?php p($l->t('Version:'));?><em id="phpVersion"> -- </em></p>
	<p><?php p($l->t('Memory Limit:'));?><em id="phpMemLimit"> -- </em></p>
	<p><?php p($l->t('Max Execution Time:'));?><em id="phpMaxExecTime"> -- </em></p>
	<p><?php p($l->t('Upload max size:'));?><em id="phpUploadMaxSize"> -- </em></p>
</div>
<div class="section" id="databaseSection">
	<h2><?php p($l->t('Database'));?></h2>
	<p><?php p($l->t('Type:'));?><em id="databaseType"> -- </em></p>
	<p><?php p($l->t('Version:'));?><em id="databaseVersion"> -- </em></p>
	<p><?php p($l->t('Size:'));?><em id="dataBaseSize"> -- </em></p>
</div>

<div class="section" id="ocsEndPoint">
	<h2><?php p($l->t('External monitoring tool'));?></h2>
	<p>
		<?php p($l->t('You can connect a external monitoring tool by using this end point: ') . $_['ocs']);?>
</div>
