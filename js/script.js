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
		activeUsersChart,
		sharesChart;

	$(document).ready(function () {
		var rambox = document.getElementById('rambox');
		rambox.style.backgroundColor = OCA.Theming ? OCA.Theming.color : 'rgb(54, 129, 195)';

		var swapbox = document.getElementById('swapbox');
		swapbox.style.backgroundColor = 'rgba(100, 100, 100, 0.8)';

		initDiskCharts();

		setHumanReadableSizeToElement("databaseSize");
		setHumanReadableSizeToElement("phpMemLimit");
		setHumanReadableSizeToElement("phpUploadMaxSize");
		setHumanReadableSizeToElement("systemDiskFreeSpace");

		initMonitoringLinkToClipboard();
		$("#monitoring-endpoint-url").on('click', function () {
			$(this).select();
		});

		function updateInfo() {
			const url = OC.generateUrl('/apps/serverinfo/update')

			$.get(url).success(function(response) {
				updateCPUStatistics(response.system.cpuload)
				updateMemoryStatistics(response.system.mem_total, response.system.mem_free, response.system.swap_total, response.system.swap_free)
			}).complete(function() {
				setTimeout(updateInfo, 300)
			})
		}

		setTimeout(updateInfo, 0)
	});

	$(window).load(function(){
		resizeSystemCharts();
	});

	$(window).resize(function () {
		resizeSystemCharts();
	});

	function getThemedPrimaryColor() {
		return OCA.Theming ? OCA.Theming.color : 'rgb(54, 129, 195)';
	}

	function getThemedPassiveColor() {
		return 'rgb(148, 148, 148)';
	}

	/**
	 * Reset all canvas widths on window resize so canvas is responsive
	 */
	function resizeSystemCharts() {
		var cpuCanvas = $("#cpuloadcanvas"),
			cpuCanvasWidth = cpuCanvas.parents('.infobox').width() - 30,
			memCanvas = $("#memorycanvas"),
			memCanvasWidth = memCanvas.parents('.infobox').width() - 30;


		// We have to set css width AND attribute width
		cpuCanvas.width(cpuCanvasWidth);
		cpuCanvas.attr('width', cpuCanvasWidth);
		memCanvas.width(memCanvasWidth);
		memCanvas.attr('width', memCanvasWidth);
	}

	function updateCPUStatistics(cpuload) {
		var $cpuFooterInfo = $('#cpuFooterInfo');
		var $cpuLoadCanvas = $('#cpuloadcanvas');

		if (cpuload === 'N/A') {
			$cpuFooterInfo.text(t('serverinfo', 'CPU info not available'));
			$cpuLoadCanvas.addClass('hidden');
			return;

		} else if ($cpuLoadCanvas.hasClass('hidden')) {
			$cpuLoadCanvas.removeClass('hidden');
		}

		var cpu1 = cpuload[0],
			cpu2 = cpuload[1],
			cpu3 = cpuload[2];

		if (typeof cpuLoadChart === 'undefined') {
			cpuLoadChart = new SmoothieChart(
				{
					millisPerPixel: 100,
					minValue: 0,
					grid: {fillStyle: 'rgba(0,0,0,0)', strokeStyle: 'transparent'},
					labels: {fillStyle: 'rgba(0,0,0,0.4)', fontSize: 12},
					responsive: true
				});
			cpuLoadChart.streamTo(document.getElementById("cpuloadcanvas"), 1000/*delay*/);
			cpuLoadLine = new TimeSeries();
			cpuLoadChart.addTimeSeries(cpuLoadLine, {
				lineWidth: 1,
				strokeStyle: getThemedPassiveColor(),
				fillStyle: getThemedPrimaryColor()
			});
		}

		$cpuFooterInfo.text(t('serverinfo', 'Load average: {cpu} (last minute)', { cpu: cpu1 }));
		cpuLoadLine.append(new Date().getTime(), cpu1);
	}

	function isMemoryStat(memTotal, memFree) {
		if (memTotal === 'N/A' || memFree === 'N/A') {
			return false;
		} else {
			return true;
		}
	}

	function isSwapStat(swapTotal, swapFree) {
		if (swapTotal === 'N/A' || swapFree === 'N/A') {
			return false;
		} else {
			return true;
		}
	}

	function updateMemoryStatistics(memTotal, memFree, swapTotal, swapFree) {
		var $memFooterInfo = $('#memFooterInfo');
		var $swapFooterInfo = $('#swapFooterInfo');
		var $memoryCanvas = $('#memorycanvas');

		var memTotalBytes = memTotal * 1024,
			memUsageBytes = (memTotal - memFree) * 1024,
			memTotalGB = memTotal / (1024 * 1024),
			memUsageGB = (memTotal - memFree) / (1024 * 1024);

		var swapTotalBytes = swapTotal * 1024,
			swapUsageBytes = (swapTotal - swapFree) * 1024,
			swapTotalGB = swapTotal / (1024 * 1024),
			swapUsageGB = (swapTotal - swapFree) / (1024 * 1024);

		var maxValueOfChart = swapTotalGB;
		if (memTotalGB > swapTotalGB) {
			maxValueOfChart = memTotalGB;
		}

		if (typeof memoryUsageChart === 'undefined') {
			memoryUsageChart = new SmoothieChart(
				{
					millisPerPixel: 100,
					maxValue: maxValueOfChart,
					minValue: 0,
					grid: {fillStyle: 'rgba(0,0,0,0)', strokeStyle: 'transparent'},
					labels: {fillStyle: 'rgba(0,0,0,0.4)', fontSize: 12},
					responsive: true
				});
			memoryUsageChart.streamTo(document.getElementById("memorycanvas"), 1000/*delay*/);
			memoryUsageLine = new TimeSeries();
			memoryUsageChart.addTimeSeries(memoryUsageLine, {
				lineWidth: 1,
				strokeStyle: getThemedPassiveColor(),
				fillStyle: getThemedPrimaryColor()
			});
			swapUsageLine = new TimeSeries();
			memoryUsageChart.addTimeSeries(swapUsageLine, {
				lineWidth: 1,
				strokeStyle: 'rgb(100, 100, 100)',
				fillStyle: 'rgba(100, 100, 100, 0.2)'
			});
		}

		if (isMemoryStat(memTotal, memFree)) {
			$memFooterInfo.text(t('serverinfo','RAM: Total: {memTotalBytes}/Current usage: {memUsageBytes}', { memTotalBytes: OC.Util.humanFileSize(memTotalBytes), memUsageBytes: OC.Util.humanFileSize(memUsageBytes) }));
			memoryUsageLine.append(new Date().getTime(), memUsageGB);

			if ($memoryCanvas.hasClass('hidden')) {
				$memoryCanvas.removeClass('hidden');
			}
		} else {
			$memFooterInfo.text(t('serverinfo', 'RAM info not available'));
			$memoryCanvas.addClass('hidden');
		}

		if (isSwapStat(swapTotal, swapFree)) {
			$swapFooterInfo.text(t('serverinfo','SWAP: Total: {swapTotalBytes}/Current usage: {swapUsageBytes}', { swapTotalBytes: OC.Util.humanFileSize(swapTotalBytes), swapUsageBytes: OC.Util.humanFileSize(swapUsageBytes) }));
			swapUsageLine.append(new Date().getTime(), swapUsageGB);
		} else {
			$swapFooterInfo.text(t('serverinfo', 'SWAP info not available'));
		}
	}

	function setHumanReadableSizeToElement(elementId) {
		var maxUploadSize = parseInt($('#' + elementId).text());

		if ($.isNumeric(maxUploadSize)) {
			$('#' + elementId).text(OC.Util.humanFileSize(maxUploadSize));
		}
	}

	function initMonitoringLinkToClipboard() {
		var clipboard = new Clipboard('.clipboardButton');
		clipboard.on('success', function (e) {
			OC.Notification.show('Copied!', { type: 'success' })
		});
		clipboard.on('error', function () {
			var actionMsg = '';
			if (/iPhone|iPad/i.test(navigator.userAgent)) {
				actionMsg = t('core', 'Not supported!');
			} else if (/Mac/i.test(navigator.userAgent)) {
				actionMsg = t('core', 'Press ⌘-C to copy.');
			} else {
				actionMsg = t('core', 'Press Ctrl-C to copy.');
			}
			OC.Notification.show(actionMsg, { type: 'error' })
		});
	}

	function initDiskCharts() {
		$.ajax({
			url: OC.linkToOCS('apps/serverinfo/api/v1/', 2) + 'diskdata?format=json',
			method: "GET",
			success: function (response) {
				var diskdata = response.ocs.data;
				var diskcharts = document.querySelectorAll(".DiskChart");
				var i;
				for (i = 0; i < diskcharts.length; i++) {
					var chartdata = {
						datasets: [
							{
								backgroundColor: [
									getThemedPrimaryColor(),
									getThemedPassiveColor(),
								],
								data: diskdata[i],
							}
						]
					};
					var ctx = diskcharts[i];
					var barGraph = new Chart(ctx, {
						type: 'doughnut',
						data: chartdata,
						options: {
							plugins: {
								legend: { display: false },
								tooltip: {
									enabled: false
								}
							},
							tooltips: {
								enabled: true,
							},
							cutoutPercentage: 60,
						}
					});
				}
			},
			error: function (data) {
				console.log(data);
			}
		});

		var interval = 1000;  // 1000 = 1 second, 3000 = 3 seconds
		function doAjax() {
			$.ajax({
				url: OC.linkToOCS('apps/serverinfo/api/v1/', 2) + 'basicdata?format=json',
				method: "GET",
				success: function (response) {
					var data = response.ocs.data;
					document.getElementById("servertime").innerHTML = data.servertime;
					document.getElementById("uptime").innerHTML = data.uptime;
					for (i in data.thermalzones) {
						document.getElementById(data.thermalzones[i]['hash']).innerHTML = data.thermalzones[i]['temp'];
					}
				},
				error: function (data) {
					console.log(data);
				},
				complete: function (data) {
					setTimeout(doAjax, interval);
				}
			});
		}

		setTimeout(doAjax, interval);
	}

})(jQuery, OC);
