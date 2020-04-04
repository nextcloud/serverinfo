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
		return OCA.Theming && OCA.Theming.inverted ? 'rgb(55, 55, 55)' : 'rgb(200, 200, 200)';
	}

	/**
	 * Reset all canvas widths on window resize so canvas is responsive
	 */
	function resizeSystemCharts() {
		var cpuCanvas = $("#cpuloadcanvas"),
			cpuCanvasWidth = cpuCanvas.parents('.infobox').width() - 30,
			memCanvas = $("#memorycanvas"),
			memCanvasWidth = memCanvas.parents('.infobox').width() - 30,
			activeUsersCanvas = $("#activeuserscanvas"),
			activeUsersCanvasWidth = activeUsersCanvas.parents('.infobox').width() - 30,
			shareCanvas = $("#sharecanvas"),
			shareCanvasWidth = shareCanvas.parents('.infobox').width() - 30;

		// We have to set css width AND attribute width
		cpuCanvas.width(cpuCanvasWidth);
		cpuCanvas.attr('width', cpuCanvasWidth);
		memCanvas.width(memCanvasWidth);
		memCanvas.attr('width', memCanvasWidth);
		activeUsersCanvas.width(activeUsersCanvasWidth);
		activeUsersCanvas.attr('width', activeUsersCanvasWidth);
		shareCanvas.width(shareCanvasWidth);
		shareCanvas.attr('width', shareCanvasWidth);

		updateShareStatistics();
		updateActiveUsersStatistics();
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

		$cpuFooterInfo.text(t('serverinfo', 'Load average') + ": " + cpu1 + " (" + t('serverinfo', 'Last minute') + ")");
		cpuLoadLine.append(new Date().getTime(), cpu1);
	}

	function updateMemoryStatistics(memTotal, memFree, swapTotal, swapFree) {
		var $memFooterInfo = $('#memFooterInfo');
		var $memoryCanvas = $('#memorycanvas');

		if (memTotal === 'N/A' || memFree === 'N/A') {
			$memFooterInfo.text(t('serverinfo', 'Memory info not available'));
			$memoryCanvas.addClass('hidden');
			return;

		} else if ($memoryCanvas.hasClass('hidden')) {
			$memoryCanvas.removeClass('hidden');
		}

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

		$memFooterInfo
			.text("RAM: " + t('serverinfo', 'Total') + ": " + OC.Util.humanFileSize(memTotalBytes) + " - " + t('serverinfo', 'Current usage') + ": " + OC.Util.humanFileSize(memUsageBytes));
		memoryUsageLine.append(new Date().getTime(), memUsageGB);
		$('#swapFooterInfo')
			.text("SWAP: " + t('serverinfo', 'Total') + ": " + OC.Util.humanFileSize(swapTotalBytes) + " - " + t('serverinfo', 'Current usage') + ": " + OC.Util.humanFileSize(swapUsageBytes));
		swapUsageLine.append(new Date().getTime(), swapUsageGB);
	}

	function updateShareStatistics() {

		var shares = $('#sharecanvas').data('shares'),
			sharesData = [shares.num_shares_user,
				shares.num_shares_groups,
				shares.num_shares_link,
				shares.num_shares_mail,
				shares.num_fed_shares_sent,
				shares.num_fed_shares_received,
				shares.num_shares_room
			],
			stepSize = 0;

		if (Math.max.apply(null, sharesData) < 10) {
			stepSize = 1;
		}

		if (typeof sharesChart === 'undefined') {
			var ctx = document.getElementById("sharecanvas");

			sharesChart = new Chart(ctx, {
				type: 'bar',
				data: {
					labels: [
						t('serverinfo', 'Users'),
						t('serverinfo', 'Groups'),
						t('serverinfo', 'Links'),
						t('serverinfo', 'Mails'),
						t('serverinfo', 'Federated sent'),
						t('serverinfo', 'Federated received'),
						t('serverinfo', 'Talk conversations'),
					],
					datasets: [{
						label: " ",
						data: sharesData,
						backgroundColor: [
							'rgba(0, 76, 153, 0.2)',
							'rgba(51, 153, 255, 0.2)',
							'rgba(207, 102, 0, 0.2)',
							'rgba(255, 178, 102, 0.2)',
							'rgba(0, 153, 0, 0.2)',
							'rgba(153, 255, 51, 0.2)',
							'rgba(178, 102, 255, 0.2)'
						],
						borderColor: [
							'rgba(0, 76, 153, 1)',
							'rgba(51, 153, 255, 1)',
							'rgba(207, 102, 0, 1)',
							'rgba(255, 178, 102, 1)',
							'rgba(0, 153, 0, 1)',
							'rgba(153, 255, 51, 1)',
							'rgba(178, 102, 255, 1)'
						],
						borderWidth: 1
					}]
				},
				options: {
					legend: {display: false},
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

	function updateActiveUsersStatistics() {

		var activeUsers = $('#activeuserscanvas').data('users'),
			activeUsersData = [activeUsers.last24hours, activeUsers.last1hour, activeUsers.last5minutes],
			stepSize = 0;

		if (Math.max.apply(null, activeUsersData) < 10) {
			stepSize = 1;
		}

		if (typeof activeUsersChart === 'undefined') {
			var ctx = document.getElementById("activeuserscanvas");

			activeUsersChart = new Chart(ctx, {
				type: 'line',
				data: {
					labels: [
						t('serverinfo', '24 hours'),
						t('serverinfo', '1 hour'),
						t('serverinfo', '5 mins')
					],
					datasets: [{
						label: " ",
						data: activeUsersData,
						fill: false,
						borderColor: [getThemedPrimaryColor()],
						borderWidth: 1,
						borderDashOffset: 0.0,
						borderJoinStyle: 'miter',
						pointBorderColor: getThemedPrimaryColor(),
						pointBackgroundColor: getThemedPassiveColor(),
						pointBorderWidth: 1,
						pointHoverRadius: 5,
						pointHoverBackgroundColor: getThemedPrimaryColor(),
						pointHoverBorderColor: getThemedPrimaryColor(),
						pointHoverBorderWidth: 1,
						pointRadius: 5,
						pointHitRadius: 10,
						lineTension: 0
					}]
				},
				options: {
					legend: {display: false},
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

	function setHumanReadableSizeToElement(elementId) {
		var maxUploadSize = parseInt($('#' + elementId).text());

		if ($.isNumeric(maxUploadSize)) {
			$('#' + elementId).text(OC.Util.humanFileSize(maxUploadSize));
		}
	}

	function initMonitoringLinkToClipboard() {
		var monAPIBox = $("#ocsEndPoint");
		/* reused from settings/js/authtoken_view.js */
		monAPIBox.find('.clipboardButton').tooltip({placement: 'bottom', title: t('core', 'Copy'), trigger: 'hover'});

		// Clipboard!
		var clipboard = new Clipboard('.clipboardButton');
		clipboard.on('success', function (e) {
			var $input = $(e.trigger);
			$input.tooltip('hide')
				.attr('data-original-title', t('core', 'Copied!'))
				.tooltip('fixTitle')
				.tooltip({placement: 'bottom', trigger: 'manual'})
				.tooltip('show');
			_.delay(function () {
				$input.tooltip('hide')
					.attr('data-original-title', t('core', 'Copy'))
					.tooltip('fixTitle');
			}, 3000);
		});
		clipboard.on('error', function (e) {
			var $input = $(e.trigger);
			var actionMsg = '';
			if (/iPhone|iPad/i.test(navigator.userAgent)) {
				actionMsg = t('core', 'Not supported!');
			} else if (/Mac/i.test(navigator.userAgent)) {
				actionMsg = t('core', 'Press ⌘-C to copy.');
			} else {
				actionMsg = t('core', 'Press Ctrl-C to copy.');
			}

			$input.tooltip('hide')
				.attr('data-original-title', actionMsg)
				.tooltip('fixTitle')
				.tooltip({placement: 'bottom', trigger: 'manual'})
				.tooltip('show');
			_.delay(function () {
				$input.tooltip('hide')
					.attr('data-original-title', t('core', 'Copy'))
					.tooltip('fixTitle');
			}, 3000);
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
						labels: ["Used GB", "Available GB"],
						datasets: [
							{
								backgroundColor: [
									getThemedPrimaryColor(),
									getThemedPassiveColor(),
								],
								borderWidth: 0,
								data: diskdata[i]
							}
						]
					};
					var ctx = diskcharts[i];
					var barGraph = new Chart(ctx, {
						type: 'doughnut',
						data: chartdata,
						options: {
							legend: {
								display: false,
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
					document.getElementById("timeservers").innerHTML = data.timeservers;
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
