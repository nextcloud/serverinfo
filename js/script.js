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

(function ($, OC) {

	var memoryUsageChart,
		memoryUsageLine,
		cpuLoadChart,
		cpuLoadLine,
		storagesCgart,
		sharesChart;

	$(document).ready(function () {

		var updateTimer = setInterval(updateInfo, 1000);

		function updateInfo() {
			var url = OC.generateUrl('/apps/serverinfo/update');

			$.get(url).success(function (response) {
				updateCPUStatistics(response.system.cpuload);
				updateMemoryStatistics(response.system.mem_total, response.system.mem_free);
				updateStoragesStatistics(response.storage)
				updateShareStatistics(response.shares);
				updatePHPStatistics(response.php);
				updateDatabaseStatistics(response.database);
			});
		}
	});

	function updateCPUStatistics (cpuload) {

		var cpu1 = cpuload[0],
			cpu2 = cpuload[1],
			cpu3 = cpuload[2];

		if (typeof cpuLoadChart === 'undefined') {
			cpuLoadChart = new SmoothieChart();
			cpuLoadChart.streamTo(document.getElementById("cpuloadcanvas"), 1000/*delay*/);
			cpuLoadLine = new TimeSeries();
			cpuLoadChart.addTimeSeries(cpuLoadLine, {lineWidth:3,strokeStyle:'#00ff00'});
		}
		
		cpuLoadLine.append(new Date().getTime(), cpu1);
	}

	function updateMemoryStatistics (memTotal, memFree) {

		var memTotalBytes = memTotal * 1024,
			memUsageBytes = (memTotal - memFree) * 1024;

		if (typeof memoryUsageChart === 'undefined') {
			memoryUsageChart = new SmoothieChart({labels:{disabled:true},maxValue:memTotalBytes,minValue:0});
			memoryUsageChart.streamTo(document.getElementById("memorycanvas"), 1000/*delay*/);
			memoryUsageLine = new TimeSeries();
			memoryUsageChart.addTimeSeries(memoryUsageLine, {lineWidth:3,strokeStyle:'#00ff00'});
		}

		$('#memFooterInfo').text("Total: "+bytesToSize(memTotalBytes)+" - Used: "+bytesToSize(memUsageBytes));
		memoryUsageLine.append(new Date().getTime(), memUsageBytes);
	}

	/**
	 * human readable byte size
	 *
	 * @return human readable byte size string
	 */
	function bytesToSize(bytes) {
    	var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    	if (bytes == 0) return 'n/a';
    	var i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)));
    	if (i == 0) return bytes + ' ' + sizes[i];
    	return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i];
	};

	function updateStoragesStatistics (storages) {

		var users_storages 	= storages.num_users,
			files_storages 	= storages.num_files,
			local_storages 	= storages.num_storages_local,
			home_storages 	= storages.num_storages_home,
			other_storages	= storages.num_storages_other;

		$('#numUsersStorage').text(' ' + users_storages);
		$('#numFilesStorage').text(' ' + files_storages);

		if (typeof storagesCgart === 'undefined') {
			var ctx = document.getElementById("storagescanvas");

			storagesCgart = new Chart(ctx, {
									    type: 'doughnut',
									    data: {
									        labels: ["Local", "Home", "Others"],
									        datasets: [{
									            data: [local_storages, home_storages, other_storages],
									            backgroundColor: [
									                'rgba(0, 0, 255, 0.5)',
									                'rgba(0, 255, 0, 0.5)',
									                'rgba(255, 0, 0, 0.5)'
									            ],
									            borderColor: [
									                'rgba(0,0,0,0.4)',
									                'rgba(0,0,0,0.4)',
									                'rgba(0,0,0,0.4)'
									            ],
									            borderWidth: 1
									        }]
									    },
									    options: {
									    }
			});
		}
	}

	function updateShareStatistics (shares) {

		var num_shares 					= shares.num_shares,
			num_shares_user 			= shares.num_shares_user,
			num_shares_groups 			= shares.num_shares_groups,
			num_shares_link 			= shares.num_shares_link,
			num_shares_link_no_password = shares.num_shares_link_no_password,
			num_fed_shares_sent 		= shares.num_fed_shares_sent,
			num_fed_shares_received 	= shares.num_fed_shares_received;

		$('#totalShares').text(' ' + num_shares); 

		if (typeof sharesChart === 'undefined') {
			var ctx = document.getElementById("sharecanvas");

			sharesChart = new Chart(ctx, {
									    type: 'doughnut',
									    data: {
									        labels: ["Users", "Groups", "Links", "No-Password Links", "Federated sent", "Federated received"],
									        datasets: [{
									            data: [num_shares_user, num_shares_groups, num_shares_link, num_shares_link_no_password, num_fed_shares_sent, num_fed_shares_received],
									            backgroundColor: [
									                'rgba(0, 0, 255, 0.5)',
									                'rgba(0, 255, 0, 0.5)',
									                'rgba(255, 0, 0, 0.5)',
									                'rgba(0, 255, 255, 0.5)',
									                'rgba(255, 0, 255, 0.5)',
									                'rgba(255, 255, 0, 0.5)'
									            ],
									            borderColor: [
									                'rgba(0,0,0,0.4)',
									                'rgba(0,0,0,0.4)',
									                'rgba(0,0,0,0.4)',
									                'rgba(0,0,0,0.4)',
									                'rgba(0,0,0,0.4)',
									                'rgba(0,0,0,0.4)'
									            ],
									            borderWidth: 1
									        }]
									    },
									    options: {
									    }
			});
		}
	}

	function updatePHPStatistics (php) {

		$('#phpVersion').text(' ' + php.version);
		$('#phpMemLimit').text(' ' + php.memory_limit);
		$('#phpMaxExecTime').text(' ' + php.max_execution_time);
		$('#phpUploadMaxSize').text(' ' + php.upload_max_filesize);
	}

	function updateDatabaseStatistics (database) {

		$('#databaseType').text(' ' + database.type);
		$('#databaseVersion').text(' ' + database.version);
		$('#dataBaseSize').text(' ' + database.size);
	}

})(jQuery, OC);
