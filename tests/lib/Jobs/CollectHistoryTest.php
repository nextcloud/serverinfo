<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\Jobs;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\History\Downsampler;
use OCA\ServerInfo\History\Sampler;
use OCA\ServerInfo\Jobs\CollectHistory;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

class CollectHistoryTest extends \Test\TestCase {
	public function testSamplesThenRollsUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->with('serverinfo', ConfigLexicon::JOB_INTERVAL_HISTORY)->willReturn(240);
		$sampler = $this->createMock(Sampler::class);
		$sampler->expects($this->once())->method('sample');
		$downsampler = $this->createMock(Downsampler::class);
		$downsampler->expects($this->once())->method('run');

		$job = new CollectHistory($this->createMock(ITimeFactory::class), $sampler, $downsampler, $this->createMock(LoggerInterface::class), $appConfig);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame(240, $job->getInterval());
	}

	public function testRollsUpEvenWhenSamplingFails(): void {
		$sampler = $this->createMock(Sampler::class);
		$sampler->method('sample')->willThrowException(new \RuntimeException('disk gone'));
		$downsampler = $this->createMock(Downsampler::class);
		$downsampler->expects($this->once())->method('run');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$job = new CollectHistory($this->createMock(ITimeFactory::class), $sampler, $downsampler, $logger, $this->createMock(IAppConfig::class));
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
