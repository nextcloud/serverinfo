<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\Collector;

use bantu\IniGetWrapper\IniGetWrapper;
use OCA\ServerInfo\Collector\Php;
use PHPUnit\Framework\MockObject\MockObject;

class PhpTest extends \Test\TestCase {
	private IniGetWrapper&MockObject $iniGetWrapper;

	protected function setUp(): void {
		parent::setUp();

		$this->iniGetWrapper = $this->createMock(IniGetWrapper::class);
	}

	/**
	 * @param list<string> $mockedMethods
	 */
	private function getInstance(array $mockedMethods = []): Php&MockObject {
		return $this->getMockBuilder(Php::class)
			->setConstructorArgs([$this->iniGetWrapper])
			->onlyMethods($mockedMethods)
			->getMock();
	}

	public function testReadsIniValues(): void {
		$this->iniGetWrapper->method('getBytes')->willReturnMap([
			['memory_limit', 134217728],
			['upload_max_filesize', 2097152],
			['post_max_size', 8388608],
		]);
		$this->iniGetWrapper->method('getNumeric')->with('max_execution_time')->willReturn(30);

		$data = $this->getInstance()->getData();

		$this->assertSame(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION, $data['version']);
		$this->assertSame(PHP_SAPI, $data['sapi']);
		$this->assertSame(134217728, $data['memoryLimit']);
		$this->assertSame(30, $data['maxExecutionTime']);
		$this->assertSame(2097152, $data['uploadMaxFilesize']);
		$this->assertSame(8388608, $data['postMaxSize']);
	}

	public function testExtensionsAreLowercasedDeduplicatedAndSorted(): void {
		$extensions = $this->getInstance()->getData()['extensions'];

		$this->assertIsArray($extensions);
		$this->assertContains('core', $extensions);
		$this->assertSame(array_values(array_unique($extensions)), $extensions);
		$this->assertSame(array_map('strtolower', $extensions), $extensions);

		$sorted = $extensions;
		natcasesort($sorted);
		$this->assertSame(array_values($sorted), $extensions);
	}
}
