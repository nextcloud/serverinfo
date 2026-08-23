<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\Collector;

use bantu\IniGetWrapper\IniGetWrapper;
use OCA\ServerInfo\Collector\Opcache;
use PHPUnit\Framework\MockObject\MockObject;

class OpcacheTest extends \Test\TestCase {
	private IniGetWrapper&MockObject $iniGetWrapper;

	protected function setUp(): void {
		parent::setUp();

		$this->iniGetWrapper = $this->createMock(IniGetWrapper::class);
	}

	/**
	 * @param list<string> $mockedMethods
	 */
	private function getInstance(array $mockedMethods): Opcache&MockObject {
		return $this->getMockBuilder(Opcache::class)
			->setConstructorArgs([$this->iniGetWrapper])
			->onlyMethods($mockedMethods)
			->getMock();
	}

	public function testDisabled(): void {
		$instance = $this->getInstance([]);
		$this->iniGetWrapper->method('getBool')->with('opcache.enable')->willReturn(false);

		$this->assertSame(['status' => 'disabled'], $instance->getData());
	}

	public function testApiRestricted(): void {
		$instance = $this->getInstance([]);
		$this->iniGetWrapper->method('getBool')->willReturn(true);
		$this->iniGetWrapper->method('getString')->willReturnMap([
			['opcache.restrict_api', '/some/other/path'],
		]);

		$this->assertSame(['status' => 'api_restricted'], $instance->getData());
	}

	public function testApiRestrictedRequiresAPathBoundary(): void {
		// A string prefix of SERVERROOT that is not an actual parent directory
		// (e.g. "/srv/nextclou" for "/srv/nextcloud") must not count as permitted.
		$instance = $this->getInstance([]);
		$this->iniGetWrapper->method('getBool')->willReturn(true);
		$this->iniGetWrapper->method('getString')->willReturnMap([
			['opcache.restrict_api', substr(\OC::$SERVERROOT, 0, -1)],
		]);

		$this->assertSame(['status' => 'api_restricted'], $instance->getData());
	}

	public function testApiPermittedOnExactServerRootMatch(): void {
		$instance = $this->getInstance(['readStatus']);
		$instance->method('readStatus')->willReturn(false);
		$this->iniGetWrapper->method('getBool')->willReturn(true);
		$this->iniGetWrapper->method('getString')->willReturnMap([
			['opcache.restrict_api', \OC::$SERVERROOT],
			['disable_functions', ''],
		]);

		$this->assertSame(['status' => 'status_unavailable'], $instance->getData());
	}

	public function testStatusUnavailableWhenTheFunctionIsDisabled(): void {
		$instance = $this->getInstance([]);
		$this->iniGetWrapper->method('getBool')->willReturn(true);
		$this->iniGetWrapper->method('getString')->willReturnMap([
			['opcache.restrict_api', ''],
			['disable_functions', 'exec,opcache_get_status,shell_exec'],
		]);

		$this->assertSame(['status' => 'status_unavailable'], $instance->getData());
	}

	public function testStatusUnavailableWhenTheCallReturnsFalse(): void {
		$instance = $this->getInstance(['readStatus']);
		$instance->method('readStatus')->willReturn(false);
		$this->iniGetWrapper->method('getBool')->willReturn(true);
		$this->iniGetWrapper->method('getString')->willReturnMap([
			['opcache.restrict_api', ''],
			['disable_functions', ''],
		]);

		$this->assertSame(['status' => 'status_unavailable'], $instance->getData());
	}

	public function testOk(): void {
		$instance = $this->getInstance(['readStatus']);
		$instance->method('readStatus')->willReturn([
			'cache_full' => false,
			'memory_usage' => [
				'used_memory' => 1_000_000,
				'free_memory' => 2_000_000,
				'wasted_memory' => 500_000,
			],
			'interned_strings_usage' => [
				'buffer_size' => 8_000_000,
				'used_memory' => 6_000_000,
				'free_memory' => 2_000_000,
			],
			'opcache_statistics' => [
				'num_cached_scripts' => 250,
				'num_cached_keys' => 300,
				'max_cached_keys' => 500,
				'opcache_hit_rate' => 99.98,
				'oom_restarts' => 3,
				'last_restart_time' => 1_700_000_000,
			],
			'jit' => [
				'enabled' => true,
				'buffer_size' => 67_108_864,
				'buffer_free' => 50_000_000,
			],
		]);
		$this->iniGetWrapper->method('getBool')->willReturn(true);
		$this->iniGetWrapper->method('getString')->willReturnMap([
			['opcache.restrict_api', ''],
			['disable_functions', ''],
		]);
		$this->iniGetWrapper->method('getNumeric')->with('opcache.revalidate_freq')->willReturn(2);

		$this->assertSame([
			'status' => 'ok',
			'memory' => ['used' => 1_000_000, 'wasted' => 500_000, 'free' => 2_000_000, 'total' => 3_500_000],
			'internedStrings' => ['used' => 6_000_000, 'free' => 2_000_000, 'total' => 8_000_000],
			'keys' => ['used' => 300, 'max' => 500],
			'hitRate' => 99.98,
			'cachedScripts' => 250,
			'oomRestarts' => 3,
			'cacheFull' => false,
			'revalidateFreq' => 2,
			'validateTimestamps' => true,
			'lastRestart' => 1_700_000_000,
			'jit' => ['enabled' => true, 'bufferUsed' => 17_108_864, 'bufferTotal' => 67_108_864],
		], $instance->getData());
	}

	public function testOkWithoutJit(): void {
		$instance = $this->getInstance(['readStatus']);
		$instance->method('readStatus')->willReturn([
			'cache_full' => true,
			'memory_usage' => ['used_memory' => 1, 'free_memory' => 0, 'wasted_memory' => 0],
			'interned_strings_usage' => ['buffer_size' => 1, 'used_memory' => 0, 'free_memory' => 1],
			'opcache_statistics' => [
				'num_cached_scripts' => 1,
				'num_cached_keys' => 1,
				'max_cached_keys' => 1,
				'opcache_hit_rate' => 0.0,
				'oom_restarts' => 0,
			],
		]);
		$this->iniGetWrapper->method('getBool')->willReturn(true);
		$this->iniGetWrapper->method('getString')->willReturnMap([
			['opcache.restrict_api', ''],
			['disable_functions', ''],
		]);
		$this->iniGetWrapper->method('getNumeric')->willReturn(0);

		$data = $instance->getData();

		$this->assertSame('ok', $data['status']);
		$this->assertTrue($data['cacheFull']);
		$this->assertNull($data['jit']);
		$this->assertNull($data['lastRestart']);
	}
}
