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
		activeusersChart,
		sharesChart;

	$(document).ready(function () {

		var updateTimer = setInterval(updateInfo, 1000);

		resizeSystemCharts();

		function updateInfo() {
			var url = OC.generateUrl('/apps/serverinfo/update');

			$.get(url).success(function (response) {
				updateCPUStatistics(response.system.cpuload);
				updateMemoryStatistics(response.system.mem_total, response.system.mem_free);
				updateActiveUsersStatistics(response.activeUsers);
				updateStoragesStatistics(response.storage)
				updateShareStatistics(response.shares);
				updatePHPStatistics(response.php);
				updateDatabaseStatistics(response.database);
			});
		}
	});

	$(window).resize(function () {
		resizeSystemCharts();
	});

	function updateCPUStatistics (cpuload) {

		var cpu1 = cpuload[0],
			cpu2 = cpuload[1],
			cpu3 = cpuload[2];

		if (typeof cpuLoadChart === 'undefined') {
			cpuLoadChart = new SmoothieChart(
			{
				millisPerPixel:250,
				minValue:0,
				grid:{fillStyle:'rgba(0,0,0,0.03)',strokeStyle:'transparent'},
				labels:{fillStyle:'rgba(0,0,0,0.4)', fontSize:12}
			});
			cpuLoadChart.streamTo(document.getElementById("cpuloadcanvas"), 1000/*delay*/);
			cpuLoadLine = new TimeSeries();
			cpuLoadChart.addTimeSeries(cpuLoadLine, {lineWidth:1, strokeStyle:'rgb(0, 0, 255)', fillStyle:'rgba(0, 0, 255, 0.2)'});
		}
		
		$('#cpuFooterInfo').text("Load average: "+cpu1+" (Last minute)");
		cpuLoadLine.append(new Date().getTime(), cpu1);
	}

	function updateMemoryStatistics (memTotal, memFree) {
		if (memTotal === 'N/A' || memFree === 'N/A') {
			$('#memFooterInfo').text(t('serverinfo', 'Memory info not available'));
			$('#memorycanvas').addClass('hidden');
			return;
		}

		var memTotalBytes = memTotal * 1024,
			memUsageBytes = (memTotal - memFree) * 1024,
			memTotalGB = memTotal / (1024 * 1024),
			memUsageGB = (memTotal - memFree) / (1024 * 1024);

		if (typeof memoryUsageChart === 'undefined') {
			memoryUsageChart = new SmoothieChart(
			{
				millisPerPixel:250, 
				maxValue:memTotalGB, 
				minValue:0, 
				grid:{fillStyle:'rgba(0,0,0,0.03)',strokeStyle:'transparent'},
				labels:{fillStyle:'rgba(0,0,0,0.4)', fontSize:12}
			});
			memoryUsageChart.streamTo(document.getElementById("memorycanvas"), 1000/*delay*/);
			memoryUsageLine = new TimeSeries();
			memoryUsageChart.addTimeSeries(memoryUsageLine, {lineWidth:1, strokeStyle:'rgb(0, 255, 0)', fillStyle:'rgba(0, 255, 0, 0.2)'});
		}

		$('#memFooterInfo').text("Total: "+bytesToSize(memTotalBytes)+" - Current usage: "+bytesToSize(memUsageBytes));
		memoryUsageLine.append(new Date().getTime(), memUsageGB);
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
			files_storages 	= storages.num_files;

		$('#numUsersStorage').text(' ' + users_storages);
		$('#numFilesStorage').text(' ' + files_storages);
	}

	function updateShareStatistics (shares) {

		var shares_data = [shares.num_shares_user, shares.num_shares_groups, shares.num_shares_link, shares.num_fed_shares_sent, shares.num_fed_shares_received],
			stepSize = 0; 

		if (Math.max.apply(null, shares_data) < 10) {stepSize = 1;} 

		if (typeof sharesChart === 'undefined') {
			var ctx = document.getElementById("sharecanvas");

			sharesChart = new Chart(ctx, {
									    type: 'bar',
									    data: {
									        labels: ["Users", "Groups", "Links", "Federated sent", "Federated received"],
									        datasets: [{
									        	label: " ",
									            data: shares_data,
									            backgroundColor: [
									                'rgba(0, 0, 255, 0.2)',
									                'rgba(0, 255, 0, 0.2)',
									                'rgba(255, 0, 0, 0.2)',
									                'rgba(0, 255, 255, 0.2)',
									                'rgba(255, 0, 255, 0.2)'
									            ],
									            borderColor: [
									                'rgba(0, 0, 255, 1)',
									                'rgba(0, 255, 0, 1)',
									                'rgba(255, 0, 0, 1)',
									                'rgba(0, 255, 255, 1)',
									                'rgba(255, 0, 255, 1)'
									            ],
									            borderWidth: 1
									        }]
									    },
									    options: {
									       	legend: {display:false},
									       	scales: {
									            yAxes: [{
									                ticks: {
									                	min: 0,
									                    stepSize: stepSize
									                }
									            }]
									        }
									    }
			});
		}

		sharesChart.update();
	}

	function updateActiveUsersStatistics (activeUsers) {

		var activeusers_data = [activeUsers.last24hours, activeUsers.last1hour, activeUsers.last5minutes],
			stepSize = 0;

		if (Math.max.apply(null, activeusers_data) < 10) {stepSize = 1;} 

		if (typeof activeusersChart === 'undefined') {
			var ctx = document.getElementById("activeuserscanvas");

			activeusersChart = new Chart(ctx, {
									    type: 'line',
									    data: {
									        labels: ["Last 24 hours", "Last 1 hour", "Last 5 mins"],
									        datasets: [{
									        	label: " ",
									            data: activeusers_data,
									            fill: false,
									            borderColor: ['rgba(0, 0, 255, 1)'],
									            borderWidth: 1,
									            borderDashOffset: 0.0,
									            borderJoinStyle: 'miter',
									            pointBorderColor: 'rgba(0, 0, 255, 1)',
									            pointBackgroundColor: "#fff",
									            pointBorderWidth: 1,
									            pointHoverRadius: 5,
									            pointHoverBackgroundColor: "rgba(0,0,255,0.6)",
									            pointHoverBorderColor: "rgba(0, 0, 255, 1)",
									            pointHoverBorderWidth: 1,
									            pointRadius: 5,
									            pointHitRadius: 10,
									            lineTension:0
									        }]
									    },
									    options: {
									       	legend: {display:false},
									       	scales: {
									            yAxes: [{
									                ticks: {
									                	min: 0,
									                    stepSize: stepSize
									                }
									            }]
									        }
									    }
			});
		}
	}

	function updatePHPStatistics (php) {

		$('#phpVersion').text(' ' + php.version);
		$('#phpMemLimit').text(' ' + bytesToSize(php.memory_limit));
		$('#phpMaxExecTime').text(' ' + php.max_execution_time);
		$('#phpUploadMaxSize').text(' ' + bytesToSize(php.upload_max_filesize));
	}

	function updateDatabaseStatistics (database) {

		$('#databaseType').text(' ' + database.type);
		$('#databaseVersion').text(' ' + database.version);
		if (database.size === 'N/A') {
			$('#dataBaseSize').text(' ' + database.size);
		} else {
			$('#dataBaseSize').text(' ' + bytesToSize(database.size));
		}
	}

	function resizeSystemCharts () {

		var cpu_canvas = document.getElementById("cpuloadcanvas"),
			mem_canvas = document.getElementById("memorycanvas");

		var newWidth = $('#cpuSection').width();
			newHeight = newWidth / 4

		if (newWidth <= 100) newWidth = 100;
		if (newHeight > 150) newHeight = 150;

		cpuloadcanvas.width = newWidth;
		cpuloadcanvas.height = newHeight;
		
		mem_canvas.width = newWidth;
		mem_canvas.height = newHeight;
	}

})(jQuery, OC);
