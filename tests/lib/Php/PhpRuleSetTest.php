<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\Php;

use OCA\ServerInfo\Database\Advisor;
use OCA\ServerInfo\Database\ExpressionEvaluator;
use OCA\ServerInfo\Database\Rule;
use OCA\ServerInfo\Database\RuleResult;
use OCA\ServerInfo\Database\Snapshot;
use OCA\ServerInfo\Php\PhpRuleSet;
use OCA\ServerInfo\Tests\OsStatus\FakeL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;

class PhpRuleSetTest extends \Test\TestCase {
	private const HEALTHY = [
		'php_memory_limit_mb' => 512.0,
		'mem_total_mb' => 16000,
		'mem_available_mb' => 8000,
		'fpm_max_children_reached' => 0,
		'fpm_listen_queue' => 0,
		'fpm_max_listen_queue' => 0,
		'fpm_total_processes' => 10,
		'fpm_peak_processes' => 12,
		'fpm_worker_mb' => 80.0,
		'fpm_worker_measured' => 10,
		'fpm_suggested_max_children' => 90,
		'fpm_headroom' => 78,
		'opcache_memory_used_mb' => 100.0,
		'opcache_memory_wasted_mb' => 2.0,
		'opcache_memory_total_mb' => 256.0,
		'opcache_memory_consumption_mb' => 256,
		'opcache_oom_restarts' => 0,
		'opcache_strings_used_mb' => 8.0,
		'opcache_strings_total_mb' => 32.0,
		'opcache_keys_used' => 5000,
		'opcache_keys_max' => 16229,
		'opcache_validate_timestamps' => 1,
		'opcache_revalidate_freq' => 60,
	];

	private Advisor $advisor;
	private PhpRuleSet $ruleSet;

	protected function setUp(): void {
		parent::setUp();

		$this->advisor = new Advisor(new ExpressionEvaluator(), $this->createMock(LoggerInterface::class));
		$this->ruleSet = new PhpRuleSet(new FakeL10N());
	}

	/**
	 * @param array<string, scalar> $override
	 * @param list<string> $drop
	 * @param array<string, string> $unavailable
	 */
	private function resultFor(string $ruleId, array $override = [], array $drop = [], array $unavailable = []): RuleResult {
		$derived = array_diff_key($override + self::HEALTHY, array_flip($drop));
		$snapshot = new Snapshot(Snapshot::FLAVOUR_PHP, '8.4.0', [], [], $derived, [], $unavailable);

		foreach ($this->advisor->run($this->ruleSet, $snapshot) as $result) {
			if ($result->rule->id === $ruleId) {
				return $result;
			}
		}
		$this->fail("Rule '$ruleId' was not evaluated");
	}

	public function testEveryRulePassesOnAHealthyServer(): void {
		$snapshot = new Snapshot(Snapshot::FLAVOUR_PHP, '8.4.0', [], [], self::HEALTHY);

		$results = $this->advisor->run($this->ruleSet, $snapshot);

		$this->assertCount(9, $results);
		foreach ($results as $result) {
			$this->assertSame(RuleResult::STATUS_OK, $result->status, $result->rule->id);
		}
	}

	public function testRuleIdsAreUniqueAndFlavourIsPhp(): void {
		$ids = array_map(static fn (Rule $rule) => $rule->id, $this->ruleSet->rules());

		$this->assertSame(array_values(array_unique($ids)), $ids);
		$this->assertSame(Snapshot::FLAVOUR_PHP, $this->ruleSet->flavour());
	}

	/**
	 * Every rule is skipped, with the probe's reason, when its data is missing.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function ruleAndRequiredKey(): array {
		return [
			'max children' => ['php.fpm_max_children', 'fpm_max_children_reached'],
			'memory headroom' => ['php.fpm_memory_headroom', 'fpm_headroom'],
			'listen queue' => ['php.fpm_listen_queue', 'fpm_listen_queue'],
			'opcache memory' => ['php.opcache_memory', 'opcache_memory_total_mb'],
			'interned strings' => ['php.opcache_interned_strings', 'opcache_strings_total_mb'],
			'max accelerated files' => ['php.opcache_max_accelerated_files', 'opcache_keys_max'],
			'revalidate freq' => ['php.opcache_revalidate_freq', 'opcache_revalidate_freq'],
			'memory limit' => ['php.memory_limit', 'php_memory_limit_mb'],
			'memory limit and ram' => ['php.memory_limit_ram', 'fpm_peak_processes'],
		];
	}

	#[DataProvider('ruleAndRequiredKey')]
	public function testRuleIsSkippedWithTheReasonWhenItsDataIsMissing(string $ruleId, string $key): void {
		$result = $this->resultFor($ruleId, [], [$key], [$key => 'Not here.']);

		$this->assertSame(RuleResult::STATUS_SKIPPED, $result->status);
		$this->assertSame('Not here.', $result->skipReason);
	}

	public function testMaxChildrenReachedSuggestsAValueFromMeasuredWorkers(): void {
		$result = $this->resultFor('php.fpm_max_children', ['fpm_max_children_reached' => 7]);

		$this->assertSame(RuleResult::STATUS_FAIL, $result->status);
		$this->assertSame('PHP-FPM reached pm.max_children 7 times since it started, with up to 12 processes at once.', $result->justification);
		$this->assertNotNull($result->apply);
		$this->assertSame('pm.max_children', $result->apply['configKey']);
		$this->assertSame('php-fpm.conf', $result->apply['configFile']);
		$this->assertSame('90', $result->apply['recommendedValue']);
		$this->assertSame('Planned with 80 % of the 8000 MB of memory available now and 80 MB of private memory per worker, the average of 10 PHP-FPM processes.', $result->apply['note']);
	}

	public function testMaxChildrenSuggestionSaysWhenItFellBackToMemoryLimit(): void {
		$result = $this->resultFor('php.fpm_max_children', [
			'fpm_max_children_reached' => 3,
			'fpm_worker_mb' => 512.0,
			'fpm_worker_measured' => 0,
			'fpm_suggested_max_children' => 22,
			'fpm_headroom' => 10,
		]);

		$this->assertSame('22', $result->apply['recommendedValue'] ?? null);
		$this->assertStringContainsString('could not be read', (string)$result->apply['note']);
		$this->assertStringContainsString('memory_limit of 512 MB, an upper bound', (string)$result->apply['note']);
	}

	public function testMaxChildrenWithoutMemoryForMoreWorkersOffersNoValueButFlagsTheMemory(): void {
		$override = ['fpm_max_children_reached' => 3, 'fpm_suggested_max_children' => 12, 'fpm_headroom' => 0];

		$children = $this->resultFor('php.fpm_max_children', $override);
		$headroom = $this->resultFor('php.fpm_memory_headroom', $override);

		$this->assertSame(RuleResult::STATUS_FAIL, $children->status);
		$this->assertNull($children->apply);
		$this->assertSame(RuleResult::STATUS_FAIL, $headroom->status);
		$this->assertSame('8000 MB of memory is available, and a PHP-FPM worker takes about 80 MB.', $headroom->justification);
	}

	public function testMaxChildrenStillFailsWhenTheMemoryIsUnknown(): void {
		$result = $this->resultFor(
			'php.fpm_max_children',
			['fpm_max_children_reached' => 3],
			['mem_available_mb', 'fpm_suggested_max_children', 'fpm_headroom'],
		);

		$this->assertSame(RuleResult::STATUS_FAIL, $result->status);
		$this->assertNull($result->apply);
	}

	public function testMemoryHeadroomPassesWhileTheLimitWasNeverReached(): void {
		$result = $this->resultFor('php.fpm_memory_headroom', ['fpm_headroom' => -3]);

		$this->assertSame(RuleResult::STATUS_OK, $result->status);
	}

	/**
	 * @return array<string, array{int, int, string}>
	 */
	public static function listenQueues(): array {
		return [
			'waiting now' => [2, 2, RuleResult::STATUS_FAIL],
			'waited since start' => [0, 5, RuleResult::STATUS_FAIL],
			'never waited' => [0, 0, RuleResult::STATUS_OK],
		];
	}

	#[DataProvider('listenQueues')]
	public function testListenQueue(int $now, int $max, string $status): void {
		$result = $this->resultFor('php.fpm_listen_queue', ['fpm_listen_queue' => $now, 'fpm_max_listen_queue' => $max]);

		$this->assertSame($status, $result->status);
		if ($status === RuleResult::STATUS_FAIL) {
			$this->assertSame("$now requests are waiting now; at most $max waited at once since PHP-FPM started.", $result->justification);
		}
	}

	public function testOpcacheMemoryNearlyFull(): void {
		$result = $this->resultFor('php.opcache_memory', [
			'opcache_memory_used_mb' => 120.0,
			'opcache_memory_wasted_mb' => 6.0,
			'opcache_memory_total_mb' => 128.0,
			'opcache_memory_consumption_mb' => 128,
		]);

		$this->assertSame(RuleResult::STATUS_FAIL, $result->status);
		$this->assertSame('126 of 128 MB are used or wasted; OPcache restarted 0 times because it ran out of memory.', $result->justification);
		$this->assertSame('256', $result->apply['recommendedValue'] ?? null);
		$this->assertSame('php.ini', $result->apply['configFile'] ?? null);
	}

	public function testOpcacheOutOfMemoryRestartsFailEvenWithFreeMemory(): void {
		$result = $this->resultFor('php.opcache_memory', ['opcache_oom_restarts' => 2]);

		$this->assertSame(RuleResult::STATUS_FAIL, $result->status);
		$this->assertSame('512', $result->apply['recommendedValue'] ?? null);
	}

	public function testOpcacheInternedStringsFull(): void {
		$fail = $this->resultFor('php.opcache_interned_strings', ['opcache_strings_used_mb' => 7.8, 'opcache_strings_total_mb' => 8.0]);
		$pass = $this->resultFor('php.opcache_interned_strings', ['opcache_strings_used_mb' => 7.0, 'opcache_strings_total_mb' => 8.0]);

		$this->assertSame(RuleResult::STATUS_FAIL, $fail->status);
		$this->assertSame('16', $fail->apply['recommendedValue'] ?? null);
		$this->assertSame(RuleResult::STATUS_OK, $pass->status);
	}

	public function testOpcacheKeysNearTheRoundedLimit(): void {
		$result = $this->resultFor('php.opcache_max_accelerated_files', ['opcache_keys_used' => 7900, 'opcache_keys_max' => 7963]);

		$this->assertSame(RuleResult::STATUS_FAIL, $result->status);
		$this->assertStringStartsWith('7900 of 7963 keys are in use.', (string)$result->justification);
		$this->assertSame('16229', $result->apply['recommendedValue'] ?? null);
	}

	/**
	 * @return array<string, array{int, int}>
	 */
	public static function effectiveSizes(): array {
		return [
			'below the minimum' => [100, 223],
			'php default' => [10000, 16229],
			'exactly a prime' => [16229, 16229],
			'just above a prime' => [16230, 32531],
			'the maximum' => [1000000, 1048793],
			'above the maximum' => [5000000, 1048793],
		];
	}

	#[DataProvider('effectiveSizes')]
	public function testEffectiveMaxFilesRoundsUpLikeOpcache(int $configured, int $effective): void {
		$this->assertSame($effective, PhpRuleSet::effectiveMaxFiles($configured));
	}

	public function testRecommendedMaxFiles(): void {
		$this->assertSame('32531', PhpRuleSet::recommendedMaxFiles(16000, 16229));
		$this->assertSame('16229', PhpRuleSet::recommendedMaxFiles(200, 7963));
		$this->assertSame('1000000', PhpRuleSet::recommendedMaxFiles(520000, 524521));
		$this->assertNull(PhpRuleSet::recommendedMaxFiles(1048000, 1048793));
	}

	public function testOpcacheKeysAtTheLargestSizeOfferNoValue(): void {
		$result = $this->resultFor('php.opcache_max_accelerated_files', ['opcache_keys_used' => 1048000, 'opcache_keys_max' => 1048793]);

		$this->assertSame(RuleResult::STATUS_FAIL, $result->status);
		$this->assertNull($result->apply);
	}

	/**
	 * @return array<string, array{int, int, string}>
	 */
	public static function revalidation(): array {
		return [
			'every request' => [1, 0, RuleResult::STATUS_FAIL],
			'every second' => [1, 1, RuleResult::STATUS_FAIL],
			'php default' => [1, 2, RuleResult::STATUS_OK],
			'timestamps off' => [0, 0, RuleResult::STATUS_OK],
		];
	}

	#[DataProvider('revalidation')]
	public function testRevalidateFreqIsOnlyAHint(int $validate, int $freq, string $status): void {
		$result = $this->resultFor('php.opcache_revalidate_freq', [
			'opcache_validate_timestamps' => $validate,
			'opcache_revalidate_freq' => $freq,
		]);

		$this->assertSame($status, $result->status);
		$this->assertSame(Rule::SEVERITY_NOTICE, $result->rule->severity);
	}

	/**
	 * @return array<string, array{float, string}>
	 */
	public static function memoryLimits(): array {
		return [
			'too low' => [256.0, RuleResult::STATUS_FAIL],
			'recommended' => [512.0, RuleResult::STATUS_OK],
			'unlimited' => [-1.0, RuleResult::STATUS_OK],
		];
	}

	#[DataProvider('memoryLimits')]
	public function testMemoryLimit(float $limit, string $status): void {
		$result = $this->resultFor('php.memory_limit', ['php_memory_limit_mb' => $limit]);

		$this->assertSame($status, $result->status);
		if ($status === RuleResult::STATUS_FAIL) {
			$this->assertSame('512M', $result->apply['recommendedValue'] ?? null);
		}
	}

	public function testMemoryLimitTimesProcessesAboveRam(): void {
		$fail = $this->resultFor('php.memory_limit_ram', ['fpm_peak_processes' => 40, 'mem_total_mb' => 16000]);
		$pass = $this->resultFor('php.memory_limit_ram', ['fpm_peak_processes' => 20, 'mem_total_mb' => 16000]);
		$unlimited = $this->resultFor('php.memory_limit_ram', ['fpm_peak_processes' => 40, 'php_memory_limit_mb' => -1.0]);

		$this->assertSame(RuleResult::STATUS_FAIL, $fail->status);
		$this->assertSame('40 processes × 512 MB memory_limit = 20480 MB, more than the 16000 MB of RAM.', $fail->justification);
		$this->assertSame(Rule::SEVERITY_NOTICE, $fail->rule->severity);
		$this->assertSame(RuleResult::STATUS_OK, $pass->status);
		$this->assertSame(RuleResult::STATUS_OK, $unlimited->status);
	}
}
