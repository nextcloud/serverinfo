/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

(function ($, OC) {

	var memoryUsageChart,
		memoryUsageLine,
		swapUsageLine,
		cpuLoadChart,
		cpuLoadLine

	const chartOptions = {
		millisPerPixel: 100,
		minValue: 0,
		grid: {fillStyle: 'rgba(0,0,0,0)', strokeStyle: 'transparent'},
		labels: {fillStyle: getThemedPassiveColor(), fontSize: 12, precision: 1},
		responsive: true,
		tooltip: true,
		tooltipLine: {
			strokeStyle: getThemedPassiveColor()
		}
	};

	$(function () {
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

			$.get(url)
				.done(function (response) {
					updateCPUStatistics(response.system.cpuload, response.system.cpunum)
					updateMemoryStatistics(response.system.mem_total, response.system.mem_free, response.system.swap_total, response.system.swap_free)
				})
				.always(function () {
					setTimeout(updateInfo, 2000)
				})
		}

		setTimeout(updateInfo, 0)
	});

	window.addEventListener('load', resizeSystemCharts)
	window.addEventListener('resize', resizeSystemCharts)

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
		let cpuCanvas = $("#cpuloadcanvas"),
			cpuCanvasWidth = cpuCanvas.parents('.infobox').width(),
			memCanvas = $("#memorycanvas"),
			memCanvasWidth = memCanvas.parents('.infobox').width();


		// We have to set css width AND attribute width
		cpuCanvas.width(cpuCanvasWidth);
		cpuCanvas.attr('width', cpuCanvasWidth);
		memCanvas.width(memCanvasWidth);
		memCanvas.attr('width', memCanvasWidth);
	}

	function updateCPUStatistics(cpuload, numCpus) {
		let $cpuFooterInfo = $('#cpuFooterInfo');
		let $cpuLoadCanvas = $('#cpuloadcanvas');

		// We need to stop touch events here, since they cause the tooltip to open, but never close again
		$cpuLoadCanvas[0].addEventListener('touchstart', (e) => {
			e.preventDefault();
		})

		if (cpuload === false || numCpus === -1) {
			$cpuFooterInfo.text(t('serverinfo', 'CPU info not available'));
			$cpuLoadCanvas.addClass('hidden');
			return;
		} else if ($cpuLoadCanvas.hasClass('hidden')) {
			$cpuLoadCanvas.removeClass('hidden');
		}

		let cpuloadFixed = cpuload.map((load) => load.toFixed(2));
		let cpuloadPercentageFixed = cpuload.map((load) => ((load / numCpus) * 100).toFixed(1));

		if (typeof cpuLoadChart === 'undefined') {
			const percentageFormatter = (val, precision) => val.toFixed(precision) + " %";

			cpuLoadChart = new SmoothieChart({
				...chartOptions,
				yMinFormatter: percentageFormatter,
				yMaxFormatter: percentageFormatter,
				maxValue: 100
			});
			cpuLoadChart.streamTo(document.getElementById("cpuloadcanvas"), 1000/*delay*/);
			cpuLoadLine = new TimeSeries();
			cpuLoadChart.addTimeSeries(cpuLoadLine, {
				lineWidth: 1,
				strokeStyle: getThemedPassiveColor(),
				fillStyle: getThemedPrimaryColor(),
				tooltipLabel: t('serverinfo', 'CPU Usage:')
			});
		}

		$cpuFooterInfo.text(t('serverinfo', 'Load average: {percentage} % ({load}) last minute', { percentage: cpuloadPercentageFixed[0], load: cpuloadFixed[0] }));
		$cpuFooterInfo[0].title = t(
			'serverinfo',
			'{lastMinutePercentage} % ({lastMinute}) last Minute\n{last5MinutesPercentage} % ({last5Minutes}) last 5 Minutes\n{last15MinutesPercentage} % ({last15Minutes}) last 15 Minutes',
			{
				lastMinute: cpuloadFixed[0],
				lastMinutePercentage: cpuloadPercentageFixed[0],
				last5Minutes: cpuloadFixed[1],
				last5MinutesPercentage: cpuloadPercentageFixed[1],
				last15Minutes: cpuloadFixed[2],
				last15MinutesPercentage: cpuloadPercentageFixed[2]
			}
		);

		cpuLoadLine.append(new Date().getTime(), cpuload[0] / numCpus * 100);
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

		// We need to stop touch events here, since they cause the tooltip to open, but never close again
		$memoryCanvas[0].addEventListener('touchstart', (e) => {
			e.preventDefault();
		})

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
			const gbFormatter = (val, precision) => val.toFixed(precision) + " GB";

			memoryUsageChart = new SmoothieChart(
				{
					...chartOptions,
					maxValue: maxValueOfChart,
					yMinFormatter: gbFormatter,
					yMaxFormatter: gbFormatter
				});
			memoryUsageChart.streamTo(document.getElementById("memorycanvas"), 1000/*delay*/);
			memoryUsageLine = new TimeSeries();
			memoryUsageChart.addTimeSeries(memoryUsageLine, {
				lineWidth: 1,
				strokeStyle: getThemedPassiveColor(),
				fillStyle: getThemedPrimaryColor(),
				tooltipLabel: t('serverinfo', 'RAM Usage:')
			});
			swapUsageLine = new TimeSeries();
			memoryUsageChart.addTimeSeries(swapUsageLine, {
				lineWidth: 1,
				strokeStyle: getThemedPassiveColor(),
				fillStyle: 'rgba(100, 100, 100, 0.2)',
				tooltipLabel: t('serverinfo', 'SWAP Usage:')
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
			OC.Notification.show(t('serverinfo', 'Copied!'), { type: 'success' })
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
		const url = OC.linkToOCS('apps/serverinfo/api/v1/', 2) + 'diskdata?format=json';
		$.get(url)
			.done(function (response) {
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
			});

		var interval = 10000;  // 1000 = 1 second, 3000 = 3 seconds
		function doAjax() {
			const url = OC.linkToOCS('apps/serverinfo/api/v1/', 2) + 'basicdata?format=json';
			$.get(url)
				.done(function (response) {
					var data = response.ocs.data;
					document.getElementById("servertime").innerHTML = data.servertime;
					document.getElementById("uptime").innerHTML = data.uptime;
					for (const thermalzone of data.thermalzones) {
						document.getElementById(thermalzone.zone).textContent = thermalzone.temp;
					}
				})
				.always(function () {
					setTimeout(doAjax, interval);
				});
		}

		setTimeout(doAjax, 0);
	}

})(jQuery, OC);

function updateMonitoringUrl(event) {
	const $endpointUrl = document.getElementById('monitoring-endpoint-url');
	const $params = document.querySelectorAll('.update-monitoring-endpoint-url');

	const url = new URL($endpointUrl.value)
	url.searchParams.delete('format')
	url.searchParams.delete('skipApps')
	url.searchParams.delete('skipUpdate')

	for (const $param of $params) {
		if ($param.name === 'format_json' && $param.checked) {
			url.searchParams.set('format', 'json')
		}
		if ($param.name === 'skip_apps' && !$param.checked) {
			url.searchParams.set('skipApps', 'false')
		}
		if ($param.name === 'skip_update' && !$param.checked) {
			url.searchParams.set('skipUpdate', 'false')
		}
	}

	$endpointUrl.value = url.toString()
}

document.addEventListener('DOMContentLoaded', function (event) {
	const $params = document.querySelectorAll('.update-monitoring-endpoint-url');
	$params.forEach($param => $param.addEventListener('change', updateMonitoringUrl));
});
