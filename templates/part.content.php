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
