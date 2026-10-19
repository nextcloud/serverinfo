<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\OsStatus\OsRelease;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\OsStatusStore;
use OCA\ServerInfo\OsStatus\Restart\PhpProcessSample;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;

class OsStatusStoreTest extends \Test\TestCase {
	/** @var array<string, array|int> */
	private array $values = [];
	private int $writes = 0;
	/** @var array<string, true> */
	private array $folders = [];
	/** @var array<string, string> folder/file => content */
	private array $files = [];
	/** @var array<string, mixed> */
	private array $cached = [];
	private int $cacheReads = 0;
	private int $fileReads = 0;
	private IAppConfig&MockObject $config;
	private IAppData&MockObject $appData;
	private ICacheFactory&MockObject $cacheFactory;
	private OsStatusStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $config = $this->createMock(IAppConfig::class);
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

		$this->appData = $this->createMock(IAppData::class);
		$this->appData->method('getFolder')->willReturnCallback(function (string $name): ISimpleFolder {
			if (!isset($this->folders[$name])) {
				throw new NotFoundException();
			}

			return $this->folder($name);
		});
		$this->appData->method('newFolder')->willReturnCallback(function (string $name): ISimpleFolder {
			$this->folders[$name] = true;

			return $this->folder($name);
		});

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(function (string $key): mixed {
			$this->cacheReads++;

			return $this->cached[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
			$this->cached[$key] = $value;

			return true;
		});
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cacheFactory->method('createDistributed')->willReturn($cache);

		$this->store = $this->newStore();
	}

	/**
	 * A store as the next request sees it, sharing app data and the cache.
	 */
	private function newStore(): OsStatusStore {
		return new OsStatusStore($this->config, $this->appData, $this->cacheFactory);
	}

	private function folder(string $name): ISimpleFolder {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturnCallback(fn (string $file): bool => isset($this->files[$name . '/' . $file]));
		$folder->method('getFile')->willReturnCallback(function (string $file) use ($name): ISimpleFile {
			if (!isset($this->files[$name . '/' . $file])) {
				throw new NotFoundException();
			}

			return $this->file($name . '/' . $file);
		});
		$folder->method('newFile')->willReturnCallback(function (string $file, mixed $content = null) use ($name): ISimpleFile {
			$this->files[$name . '/' . $file] = (string)$content;

			return $this->file($name . '/' . $file);
		});

		return $folder;
	}

	private function file(string $path): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturnCallback(function () use ($path): string {
			$this->fileReads++;

			return $this->files[$path];
		});
		$file->method('putContent')->willReturnCallback(function (mixed $data) use ($path): void {
			$this->files[$path] = (string)$data;
		});

		return $file;
	}

	private function osStatus(int $collectedAt = 1792411200): OsStatus {
		return new OsStatus(OsStatus::STATE_OK, $collectedAt, new OsRelease('debian', [], 'Debian GNU/Linux', 'Debian GNU/Linux 12 (bookworm)', '12', 'bookworm'), '6.1.0-26-amd64');
	}

	public function testTheStatusIsAFileInAppData(): void {
		$this->store->save($this->osStatus());

		$this->assertSame(1, json_decode($this->files['os-status/status.json'] ?? '', true)['schema'] ?? null);
		$this->assertArrayNotHasKey('cached_os_status', $this->values, 'Not in app config');

		$this->cached = [];
		$loaded = $this->newStore()->load();
		$this->assertSame('Debian GNU/Linux 12 (bookworm)', $loaded?->release?->prettyName);
		$this->assertSame('6.1.0-26-amd64', $loaded->kernel);
	}

	public function testReadsGoThroughTheCache(): void {
		$this->store->save($this->osStatus());

		$this->assertSame(1792411200, $this->newStore()->load()?->collectedAt);
		$this->assertSame(0, $this->fileReads, 'Saving filled the cache');

		$this->cached = [];
		$this->newStore()->load();
		$this->assertSame(1, $this->fileReads);
		$this->newStore()->load();
		$this->assertSame(1, $this->fileReads, 'The file read filled the cache');
	}

	public function testASaveReplacesTheCachedStatus(): void {
		$this->store->save($this->osStatus(100));
		$this->newStore()->save($this->osStatus(200));

		$this->assertSame(200, $this->newStore()->load()?->collectedAt);
		$this->assertSame(100, $this->store->load()?->collectedAt, 'A store that saved knows what it saved');
	}

	public function testOneReadPerRequest(): void {
		$this->store->load();
		$this->store->load();

		$this->assertSame(1, $this->cacheReads);
	}

	public function testNothingCollectedYet(): void {
		$this->assertNull($this->store->load());
		$this->assertSame('', $this->cached['status'] ?? null, 'Remembered, so app data is not asked on every request');
		$this->assertNull($this->newStore()->load());
	}

	public function testADamagedStatusReadsAsNone(): void {
		$this->cached['status'] = '{"schema":';

		$this->assertNull($this->store->load());
	}

	public function testSavingRemovesTheStatusFromAppConfig(): void {
		$this->config->method('hasAppKey')->with('cached_os_status', null)->willReturn(true);
		$this->config->expects($this->once())->method('deleteAppValue')->with('cached_os_status');

		$this->store->save($this->osStatus());
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

	public function testNotifiedLevels(): void {
		$this->store->setNotifiedLevels(['updates' => 'warning']);

		$this->assertSame(['updates' => 'warning'], $this->store->notifiedLevels());
	}
}
