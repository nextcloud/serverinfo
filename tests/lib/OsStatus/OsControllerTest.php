<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\Controller\OsController;
use OCA\ServerInfo\Jobs\RefreshOsStatus;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\OsStatus\Notifications\OsNotificationSender;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\OsStatusCollector;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\Restart\PhpProcessSample;
use OCA\ServerInfo\OsStatus\Restart\ServiceCommands;
use OCA\ServerInfo\OsStatus\Restart\ServiceRestart;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use OCA\ServerInfo\OsStatus\Verdict\ConcernTexts;
use OCA\ServerInfo\OsStatus\Verdict\Thresholds;
use OCA\ServerInfo\OsStatus\Verdict\Verdict;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig as IServerConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;

class OsControllerTest extends \Test\TestCase {
	private OsStatusService&MockObject $service;
	private OsStatusCollector&MockObject $collector;
	private OsNotificationSender&MockObject $notifications;
	private IAppConfig&MockObject $config;
	private IJobList&MockObject $jobList;
	private string $cronMode = 'cron';
	private ?string $initSystem = ServiceCommands::SYSTEMD;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(OsStatusService::class);
		$this->service->method('getThresholds')->willReturn(new Thresholds());
		$this->collector = $this->createMock(OsStatusCollector::class);
		$this->notifications = $this->createMock(OsNotificationSender::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getAppValueString')->willReturnArgument(1);
		$this->jobList = $this->createMock(IJobList::class);
	}

	private function controller(): OsController {
		$serverConfig = $this->createMock(IServerConfig::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l10n->method('n')->willReturnCallback(static fn (string $one, string $many, int $count, array $parameters = []): string => vsprintf(str_replace('%n', (string)$count, $count === 1 ? $one : $many), $parameters));
		$os = $this->createMock(Os::class);
		$os->method('getOSName')->willReturn('FreeBSD 14.1-RELEASE amd64');
		$serviceCommands = $this->createMock(ServiceCommands::class);
		$serviceCommands->method('initSystem')->willReturnCallback(fn (): ?string => $this->initSystem);
		$serverConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default): string => $app === 'core' && $key === 'backgroundjobs_mode' ? $this->cronMode : $default);

		return new OsController(
			'serverinfo',
			$this->createMock(IRequest::class),
			$this->service,
			$this->collector,
			$this->notifications,
			$this->config,
			$this->createMock(ITimeFactory::class),
			$this->jobList,
			$serverConfig,
			$serviceCommands,
			new ConcernTexts($this->createMock(IDateTimeFormatter::class)),
			$l10n,
			$os,
		);
	}

	public function testSettingsAreValidatedBeforeAnythingIsStored(): void {
		$this->config->expects($this->never())->method('setAppValueInt');
		$this->config->expects($this->never())->method('setAppValueBool');
		$this->notifications->expects($this->never())->method('sync');

		$response = $this->controller()->updateSettings(['securityWarning' => 20, 'securityError' => 10], true);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['field' => 'rebootWarning'], $response->getData(), 'The first missing field is named');
	}

	public function testSettingsWithoutNotificationsAreRejected(): void {
		$this->config->expects($this->never())->method('setAppValueInt');
		$this->notifications->expects($this->never())->method('sync');

		$response = $this->controller()->updateSettings((new Thresholds())->toArray());

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['field' => 'notifications'], $response->getData());
	}

	public function testSettingsWithoutThresholdsAreRejected(): void {
		$response = $this->controller()->updateSettings('3', true);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['field' => 'securityWarning'], $response->getData());
	}

	public function testSettingsNeedThePasswordConfirmed(): void {
		$attributes = (new \ReflectionMethod(OsController::class, 'updateSettings'))->getAttributes(PasswordConfirmationRequired::class);

		$this->assertCount(1, $attributes);
		$this->assertFalse($attributes[0]->newInstance()->getStrict());
	}

	public function testSendsTheDefaultsAndLimitsOfTheSettings(): void {
		$data = $this->controller()->status()->getData();

		$this->assertSame((new Thresholds())->toArray(), $data['settingsDefaults']['thresholds']);
		$this->assertTrue($data['settingsDefaults']['notifications']);
		$this->assertSame(3650, $data['thresholdLimits']['maxDays']);
		$this->assertContains(['rebootWarning', 'rebootError'], $data['thresholdLimits']['pairs']);
	}

	public function testValidSettingsAreStoredAndApplied(): void {
		$this->config->expects($this->exactly(8))->method('setAppValueInt');
		$this->config->expects($this->once())->method('setAppValueBool')->with('os_notifications', false);
		$this->notifications->expects($this->once())->method('sync');

		$response = $this->controller()->updateSettings((new Thresholds(rebootWarning: 2))->toArray(), false);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('/usr/local/sbin/serverinfo-os-report', $response->getData()['helper']['installPath']);
	}

	public function testStatusSamplesThePhpProcess(): void {
		$this->service->expects($this->once())->method('samplePhpProcess');

		$data = $this->controller()->status()->getData();

		$this->assertNull($data['status']);
		$this->assertSame('/var/lib/serverinfo/os-report.json', $data['helper']['reportPath']);
	}

	public function testRefreshQueuesACheckAndReturnsRightAway(): void {
		$this->jobList->expects($this->once())->method('add')->with(RefreshOsStatus::class);
		$this->collector->expects($this->never())->method('collectAndStore');

		$response = $this->controller()->refresh();

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
	}

	public function testRefreshRefusesWhileACheckRuns(): void {
		$this->collector->method('isCollecting')->willReturn(true);
		$this->jobList->expects($this->never())->method('add');

		$response = $this->controller()->refresh();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['reason' => 'running'], $response->getData());
	}

	public function testRefreshNeedsSystemCron(): void {
		$this->cronMode = 'ajax';
		$this->jobList->expects($this->never())->method('add');

		$response = $this->controller()->refresh();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['reason' => 'cron'], $response->getData());
	}

	public function testStatusSaysWhetherACheckIsUnderway(): void {
		$this->jobList->method('has')->with(RefreshOsStatus::class, null)->willReturn(true);
		$this->assertSame(['systemCron' => true, 'running' => true], $this->controller()->status()->getData()['refresh']);

		$this->cronMode = 'webcron';
		$this->jobList = $this->createMock(IJobList::class);
		$this->assertSame(['systemCron' => false, 'running' => false], $this->controller()->status()->getData()['refresh']);
	}

	public function testSendsTheCommandsForTheInitSystem(): void {
		$this->service->method('getStatus')->willReturn(new OsStatus(OsStatus::STATE_OK, 1792411200, restartServices: [new ServiceRestart('cron.service'), new ServiceRestart('foo\\x2dbar.service')]));
		$this->service->method('getPhpSample')->willReturn(new PhpProcessSample(1792411200, 'fpm-fcgi', ['libssl.so.3'], 'php-fpm83', null));

		$data = $this->controller()->status()->getData();
		$this->assertSame(ServiceCommands::SYSTEMD, $data['initSystem']);
		$this->assertSame([
			'reboot' => 'sudo systemctl reboot',
			'php' => 'sudo systemctl restart php-fpm83',
			'services' => ['cron.service' => 'sudo systemctl restart cron', 'foo\\x2dbar.service' => "sudo systemctl restart 'foo\\x2dbar'"],
		], $data['commands']);

		$this->initSystem = ServiceCommands::OPENRC;
		$this->assertSame('sudo rc-service php-fpm83 restart', $this->controller()->status()->getData()['commands']['php']);

		$this->initSystem = null;
		$this->assertSame(['reboot' => null, 'php' => null, 'services' => ['cron.service' => null, 'foo\\x2dbar.service' => null]], $this->controller()->status()->getData()['commands']);
	}

	public function testSendsTheSentencesOfEachConcern(): void {
		$status = new OsStatus(OsStatus::STATE_OK, 1792411200);
		$this->service->method('getStatus')->willReturn($status);
		$this->service->method('evaluate')->with($status)->willReturn(new Verdict([
			new Concern(Concern::UPDATES, Concern::LEVEL_WARNING, ['classified' => true, 'security' => 7, 'other' => 2, 'stack' => 0, 'days' => 4]),
			new Concern(Concern::TIME_SYNC, Concern::LEVEL_WARNING, ['source' => null]),
		]));

		$verdict = $this->controller()->status()->getData()['verdict'];

		$this->assertSame(Concern::LEVEL_WARNING, $verdict['level']);
		$this->assertSame(['id' => 'updates', 'level' => 'warning'], array_intersect_key($verdict['concerns'][0], ['id' => 1, 'level' => 1]));
		$this->assertSame('7 security updates pending', $verdict['concerns'][0]['title']);
		$this->assertSame('The oldest has been waiting for 4 days.', $verdict['concerns'][0]['detail']);
		$this->assertSame('The system clock is not synchronized', $verdict['concerns'][1]['title']);
		$this->assertStringStartsWith('Two-factor codes', $verdict['concerns'][1]['detail']);
	}

	public function testNamesTheSystemOnlyWhenItIsNotSupported(): void {
		$this->service->method('getStatus')->willReturnOnConsecutiveCalls(OsStatus::unsupported(1792411200, '14.1-RELEASE', 'amd64'), OsStatus::restricted(1792411200));

		$this->assertSame('FreeBSD 14.1-RELEASE amd64', $this->controller()->status()->getData()['osName']);
		$this->assertNull($this->controller()->status()->getData()['osName']);
	}
}
