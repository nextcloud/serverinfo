<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\OsStatus\OsStatusStore;
use OCA\ServerInfo\OsStatus\Restart\PhpProcessSample;
use OCP\AppFramework\Services\IAppConfig;

class OsStatusStoreTest extends \Test\TestCase {
	/** @var array<string, array|int> */
	private array $values = [];
	private int $writes = 0;
	private OsStatusStore $store;

	protected function setUp(): void {
		parent::setUp();
		$config = $this->createMock(IAppConfig::class);
		$config->method('getAppValueArray')->willReturnCallback(fn (string $key): array => (array)($this->values[$key] ?? []));
		$config->method('setAppValueArray')->willReturnCallback(function (string $key, array $value): bool {
			$this->values[$key] = $value;
			$this->writes++;

			return true;
		});
		$config->method('getAppValueInt')->willReturnCallback(fn (string $key): int => $this->values[$key] ?? 0);
		$config->method('setAppValueInt')->willReturnCallback(function (string $key, int $value): bool {
			$this->values[$key] = $value;
			$this->writes++;

			return true;
		});
		$this->store = new OsStatusStore($config);
	}

	public function testFirstSeenIsKeptAcrossRuns(): void {
		$this->assertSame(['update:a:1' => 100], $this->store->firstSeen(['update:a:1' => null], 100, ['update']));
		$this->assertSame(['update:a:1' => 100, 'update:b:1' => 200], $this->store->firstSeen(['update:a:1' => null, 'update:b:1' => null], 200, ['update']));
	}

	public function testAResolvedFindingStartsOverWhenItReturns(): void {
		$this->store->firstSeen(['update:a:1' => null], 100, ['update']);
		$this->store->firstSeen([], 200, ['update']);

		$this->assertSame(['update:a:1' => 300], $this->store->firstSeen(['update:a:1' => null], 300, ['update']));
	}

	public function testOtherScopesAreLeftAlone(): void {
		$this->store->firstSeen(['reboot:kernel:6.8' => 50], 100, ['reboot']);
		$this->store->firstSeen([], 200, ['update']);

		$this->assertSame(['reboot:kernel:6.8' => 50], $this->store->firstSeen(['reboot:kernel:6.8' => null], 300, ['reboot']));
	}

	public function testAKnownStartWins(): void {
		$this->assertSame(['reboot:distribution:' => 40], $this->store->firstSeen(['reboot:distribution:' => 40], 100, ['reboot']));
	}

	public function testPhpSamplesAreThrottledButChangesAreNot(): void {
		$this->store->savePhpSample(new PhpProcessSample(1000, 'fpm-fcgi', [], 'php-fpm', null));
		$writes = $this->writes;

		$this->store->savePhpSample(new PhpProcessSample(1060, 'fpm-fcgi', [], 'php-fpm', null));
		$this->assertSame($writes, $this->writes, 'The same finding a minute later is not written again');

		$this->store->savePhpSample(new PhpProcessSample(1120, 'fpm-fcgi', ['libssl.so.3'], 'php-fpm', null));
		$this->assertSame(['libssl.so.3'], $this->store->loadPhpSample()?->outdatedLibraries);
		$this->assertSame(1120, $this->store->phpOutdatedSince());

		$this->store->savePhpSample(new PhpProcessSample(2000, 'fpm-fcgi', [], 'php-fpm', null));
		$this->assertNull($this->store->phpOutdatedSince());
	}

	public function testThePhpSampleLeavesTheCollectorsFirstSeenTimesAlone(): void {
		$this->store->firstSeen(['update:a:1' => null], 100, ['update']);
		$stored = $this->values[ConfigLexicon::CACHED_OS_FIRST_SEEN];

		$this->store->savePhpSample(new PhpProcessSample(1120, 'fpm-fcgi', ['libssl.so.3'], 'php-fpm', null));
		$this->store->savePhpSample(new PhpProcessSample(2000, 'fpm-fcgi', ['libssl.so.3'], 'php-fpm', null));

		$this->assertSame($stored, $this->values[ConfigLexicon::CACHED_OS_FIRST_SEEN]);
		$this->assertSame(1120, $this->store->phpOutdatedSince(), 'Still outdated, so still counting from the first sighting');
	}
}
