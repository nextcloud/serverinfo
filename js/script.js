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
		swapUsageLine,
		cpuLoadChart,
		cpuLoadLine,
		activeusersChart,
		sharesChart;

	$(document).ready(function () {

		var updateTimer = setInterval(updateInfo, 1000);

		resizeSystemCharts();
		updateActiveUsersStatistics();
		updateShareStatistics();
		setHumanReadableSizeToElement("dataBaseSize");
		setHumanReadableSizeToElement("phpMemLimit");
		setHumanReadableSizeToElement("phpUploadMaxSize");

		function updateInfo() {
			var url = OC.generateUrl('/apps/serverinfo/update');

			$.get(url).success(function (response) {
				updateCPUStatistics(response.system.cpuload);
				updateMemoryStatistics(response.system.mem_total, response.system.mem_free, response.system.swap_total, response.system.swap_free);
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

		$('#cpuFooterInfo').text(t('serverinfo', 'Load average')+": "+cpu1+" ("+t('serverinfo', 'Last minute')+")");
		cpuLoadLine.append(new Date().getTime(), cpu1);
	}

	function updateMemoryStatistics (memTotal, memFree, swapTotal, swapFree) {
		if (memTotal === 'N/A' || memFree === 'N/A') {
			$('#memFooterInfo').text(t('serverinfo', 'Memory info not available'));
			$('#memorycanvas').addClass('hidden');
			return;
		}

		var memTotalBytes = memTotal * 1024,
			memUsageBytes = (memTotal - memFree) * 1024,
			memTotalGB = memTotal / (1024 * 1024),
			memUsageGB = (memTotal - memFree) / (1024 * 1024);

		var swapTotalBytes = swapTotal * 1024,
			swapUsageBytes = (swapTotal - swapFree) * 1024,
			swapTotalGB = swapTotal / (1024 * 1024),
			swapUsageGB = (swapTotal - swapFree) / (1024 * 1024);

		if (memTotalGB > swapTotalGB) {
			var maxValueOfChart = memTotalGB;
		} else {
			var maxValueOfChart = swapTotalGB;
		}

		if (typeof memoryUsageChart === 'undefined') {
			memoryUsageChart = new SmoothieChart(
			{
				millisPerPixel:250,
				maxValue:maxValueOfChart,
				minValue:0,
				grid:{fillStyle:'rgba(0,0,0,0.03)',strokeStyle:'transparent'},
				labels:{fillStyle:'rgba(0,0,0,0.4)', fontSize:12}
			});
			memoryUsageChart.streamTo(document.getElementById("memorycanvas"), 1000/*delay*/);
			memoryUsageLine = new TimeSeries();
			memoryUsageChart.addTimeSeries(memoryUsageLine, {lineWidth:1, strokeStyle:'rgb(0, 255, 0)', fillStyle:'rgba(0, 255, 0, 0.2)'});
			swapUsageLine = new TimeSeries();
			memoryUsageChart.addTimeSeries(swapUsageLine, {lineWidth:1, strokeStyle:'rgb(255, 0, 0)', fillStyle:'rgba(255, 0, 0, 0.2)'});
		}

		$('#memFooterInfo').text(t('serverinfo', 'Total')+": "+OC.Util.humanFileSize(memTotalBytes)+" - "+t('serverinfo', 'Current usage')+": "+OC.Util.humanFileSize(memUsageBytes));
		memoryUsageLine.append(new Date().getTime(), memUsageGB);
		$('#swapFooterInfo').text("SWAP "+t('serverinfo', 'Total')+": "+OC.Util.humanFileSize(swapTotalBytes)+" - "+t('serverinfo', 'Current usage')+": "+OC.Util.humanFileSize(swapUsageBytes));
		swapUsageLine.append(new Date().getTime(), swapUsageGB);
	}

	function updateShareStatistics () {

		var shares = $('#sharecanvas').data('shares'),
			shares_data = [shares.num_shares_user, shares.num_shares_groups, shares.num_shares_link, shares.num_fed_shares_sent, shares.num_fed_shares_received],
			stepSize = 0;

		if (Math.max.apply(null, shares_data) < 10) {stepSize = 1;}

		if (typeof sharesChart === 'undefined') {
			var ctx = document.getElementById("sharecanvas");

			sharesChart = new Chart(ctx, {
									    type: 'bar',
									    data: {
									        labels: [t('serverinfo', 'Users'),
									        		t('serverinfo', 'Groups'),
									        		t('serverinfo', 'Links'),
									        		t('serverinfo', 'Federated sent'),
									        		t('serverinfo', 'Federated received')],
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

	function updateActiveUsersStatistics () {

		var activeUsers = $('#activeuserscanvas').data('users'),
			activeUsers_data = [activeUsers.last24hours, activeUsers.last1hour, activeUsers.last5minutes],
			stepSize = 0;

		if (Math.max.apply(null, activeUsers_data) < 10) {stepSize = 1;}

		if (typeof activeusersChart === 'undefined') {
			var ctx = document.getElementById("activeuserscanvas");

			activeusersChart = new Chart(ctx, {
									    type: 'line',
									    data: {
									        labels: [t('serverinfo', 'Last 24 hours'),
									        		t('serverinfo', 'Last 1 hour'),
									        		t('serverinfo', 'Last 5 mins')],
									        datasets: [{
									        	label: " ",
									            data: activeUsers_data,
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

	function setHumanReadableSizeToElement (elementId) {
		var maxUploadSize = parseInt($('#' + elementId).text());

		if ($.isNumeric(maxUploadSize)) {
			$('#' + elementId).text(OC.Util.humanFileSize(maxUploadSize));
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
