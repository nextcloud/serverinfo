<div class="section" id="systemSection">
	<h2><?php p($l->t('System'));?></h2>
	<p><?php p($l->t('CPU'));?></p>
	<canvas id="cpuloadcanvas" width="400" height="100"></canvas>
	<p><em><?php p($l->t('CPU load average (Last minute)')); ?></em></p>
	<br>
	<p><?php p($l->t('Memory'));?></p>
	<canvas id="memorycanvas" width="400" height="100"></canvas>
	<p><em id="memFooterInfo"></em></p>
</div>
<div class="section" id="storageSection">
	<h2><?php p($l->t('Storage'));?></h2>
	<p><?php p($l->t('Users: '));?><em id="numUsersStorage">--</em></p>
	<p><?php p($l->t('Files: '));?><em id="numFilesStorage">--</em></p>
	<p><?php p($l->t('Storages: '));?></p>
	<div id="container" style="width:280px; height:250px;">
		<canvas id="storagescanvas" width="280" height="250"></canvas>
	</div>
</div>
<div class="section" id="shareSection">
	<h2><?php p($l->t('Shares'));?></h2>
	<p><?php p($l->t('Total: '));?></p>
	<div id="container" style="width:350px; height:350px;">
		<canvas id="sharecanvas" width="350" height="350"></canvas>
	</div>
</div>
<div class="section" id="phpSection">
	<h2><?php p($l->t('PHP'));?></h2>
	<p><?php p($l->t('Version: '));?><em id="phpVersion">--</em></p>
	<p><?php p($l->t('Memory Limit: '));?><em id="phpMemLimit">--</em></p>
	<p><?php p($l->t('Max Execution Time: '));?><em id="phpMaxExecTime">--</em></p>
	<p><?php p($l->t('Upload max size: '));?><em id="phpUploadMaxSize">--</em></p>
</div>
<div class="section" id="databaseSection">
	<h2><?php p($l->t('Database'));?></h2>
	<p><?php p($l->t('Type: '));?><em id="databaseType">--</em></p>
	<p><?php p($l->t('Version: '));?><em id="databaseVersion">--</em></p>
	<p><?php p($l->t('Size: '));?><em id="dataBaseSize">--</em></p>
</div>
