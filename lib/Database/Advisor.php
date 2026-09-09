<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ServerInfo\Database;

use Psr\Log\LoggerInterface;

/**
 * Runs a {@see RuleSet} against a {@see Snapshot} and produces one
 * {@see RuleResult} per rule.
 *
 * Per-rule pipeline:
 *   1. Check `requires`: any missing snapshot key → status: skipped.
 *   2. Evaluate `formula` to get the rule's primary `value`.
 *   3. Evaluate `test` with `value` injected into context.
 *   4. If failed, evaluate `justification_formula` (comma-separated)
 *      to interpolate sprintf-style placeholders into `justification`.
 *   5. If `apply` descriptor is set and the rule failed, compute the
 *      recommended value off the snapshot for the UI to pre-fill.
 */
class Advisor {
	/**
	 * Below this uptime, MySQL/MariaDB rate- and ratio-style rules read
	 * off cumulative status counters that haven't accumulated meaningful
	 * data yet — every ratio looks terrible seconds after a restart.  We
	 * skip those rules until the server has been up long enough, rather
	 * than raising false alarms (this is the same caveat phpMyAdmin's
	 * advisor documents upstream).
	 */
	private const MIN_UPTIME_HOURS = 24.0;

	/**
	 * Rule ids whose result derives from counters that reset on restart
	 * (hit ratios, per-query rates, "since startup" totals).  Kept here
	 * as a single reviewable list rather than a flag scattered across ~25
	 * rule definitions.  Rules NOT listed here (config values, current
	 * gauges like dirty-pages, on/off switches) evaluate regardless of
	 * uptime.
	 *
	 * @var list<string>
	 */
	private const COUNTER_SENSITIVE_RULE_IDS = [
		'Aborted_connects',
		'Thread_cache_hit_rate',
		'Slow_query_ratio',
		'Select_full_join',
		'Sort_merge_passes',
		'Created_tmp_disk_tables',
		'Innodb_buffer_pool_hit_rate',
		'Query_cache_efficiency',
		'Key_buffer_hit_rate',
		'Table_locks_waited',
		'Binlog_cache_disk_use',
		'Slow_query_rate',
		'Sort_rows',
		'Sort_merge_passes_rate',
		'Join_without_index_rate',
		'Handler_read_first_rate',
		'Handler_read_rnd_rate',
		'Handler_read_rnd_next_rate',
		'Open_files_rate',
		'Table_lock_wait_rate',
		'Aborted_connects_rate',
		'Aborted_clients_percentage',
		'Aborted_clients_rate',
		'Slow_launch_threads',
	];

	public function __construct(
		private ExpressionEvaluator $evaluator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return list<RuleResult>
	 */
	public function run(RuleSet $ruleSet, Snapshot $snapshot): array {
		$context = $snapshot->context();
		$results = [];

		foreach ($ruleSet->rules() as $rule) {
			$results[] = $this->evaluateRule($rule, $snapshot, $context);
		}

		return $results;
	}

	/**
	 * @param array<string, scalar|null> $context
	 */
	private function evaluateRule(Rule $rule, Snapshot $snapshot, array $context): RuleResult {
		// Skip if any required snapshot key is missing.  This is common
		// across MySQL versions (e.g. query_cache_* removed in 8.0) and
		// across flavours (a MariaDB-specific status doesn't exist on
		// MySQL).  Skipping is preferable to evaluating with a default
		// 0 — the result would be misleading.
		foreach ($rule->requires as $req) {
			if (!array_key_exists($req, $context)) {
				return new RuleResult(
					$rule,
					RuleResult::STATUS_SKIPPED,
					skipReason: "Required value '$req' not present in this database's reports.",
				);
			}
		}

		// Uptime gate for counter-derived rules (see the constant docs).
		if (in_array($rule->id, self::COUNTER_SENSITIVE_RULE_IDS, true)) {
			$uptimeHours = $context['Uptime_hours'] ?? null;
			if (is_numeric($uptimeHours) && (float)$uptimeHours < self::MIN_UPTIME_HOURS) {
				return new RuleResult(
					$rule,
					RuleResult::STATUS_SKIPPED,
					skipReason: sprintf(
						'Server has been up %.1f h; counter-based statistics need at least %d h to be reliable.',
						(float)$uptimeHours,
						(int)self::MIN_UPTIME_HOURS,
					),
				);
			}
		}

		try {
			$value = $this->evaluator->evaluate($rule->formula, $context);
		} catch (ExpressionException $e) {
			$this->logger->warning(
				'serverinfo: failed to evaluate formula for rule {id}: {msg}',
				['id' => $rule->id, 'msg' => $e->getMessage(), 'app' => 'serverinfo'],
			);
			return new RuleResult(
				$rule,
				RuleResult::STATUS_SKIPPED,
				skipReason: 'Formula could not be evaluated: ' . $e->getMessage(),
			);
		}

		$valueFloat = is_bool($value) ? ($value ? 1.0 : 0.0) : $value;

		// `test` is evaluated against the same context augmented with
		// `value`.  phpMyAdmin's rules consistently reference `value`
		// as the result of `formula` so we follow that convention.
		$testCtx = $context;
		$testCtx['value'] = $valueFloat;

		try {
			$failed = $this->evaluator->evaluate($rule->test, $testCtx);
			$failed = is_bool($failed) ? $failed : ($valueFloat !== 0.0);
		} catch (ExpressionException $e) {
			$this->logger->warning(
				'serverinfo: failed to evaluate test for rule {id}: {msg}',
				['id' => $rule->id, 'msg' => $e->getMessage(), 'app' => 'serverinfo'],
			);
			return new RuleResult(
				$rule,
				RuleResult::STATUS_SKIPPED,
				skipReason: 'Test could not be evaluated: ' . $e->getMessage(),
			);
		}

		if (!$failed) {
			return new RuleResult($rule, RuleResult::STATUS_OK, value: $valueFloat);
		}

		// Failure path — interpolate the justification.
		$justification = $this->interpolate(
			$rule->justification,
			$rule->justificationFormula,
			$testCtx,
		);

		// Optional apply descriptor.
		$apply = null;
		if ($rule->apply !== null) {
			try {
				$recommended = $rule->apply->compute($snapshot);
				$apply = [
					'variable' => $rule->apply->variable,
					'configKey' => $rule->apply->configKey,
					'configFile' => $rule->apply->configFile,
					'runtimeWritable' => $rule->apply->runtimeWritable,
					'recommendedValue' => $recommended,
					'note' => $rule->apply->note,
				];
			} catch (\Throwable $e) {
				// A broken applier shouldn't sink the whole rule.
				$this->logger->warning(
					'serverinfo: apply descriptor failed for rule {id}: {msg}',
					['id' => $rule->id, 'msg' => $e->getMessage(), 'app' => 'serverinfo'],
				);
			}
		}

		// Optional name list backing the numeric value (e.g. which
		// tables triggered a count-based rule).  Only attached on
		// failure — passing rules don't need the extra payload.
		$details = null;
		if ($rule->detailsKey !== null) {
			$list = $snapshot->details[$rule->detailsKey] ?? null;
			if (is_array($list) && $list !== []) {
				$details = array_map('strval', $list);
			}
		}

		return new RuleResult(
			$rule,
			RuleResult::STATUS_FAIL,
			justification: $justification,
			value: $valueFloat,
			apply: $apply,
			details: $details,
		);
	}

	/**
	 * sprintf-style interpolation driven by a comma-separated list of
	 * expressions in `$formula`.  Each expression's value fills one
	 * `%s` (or numeric specifier) in `$template`.
	 *
	 * @param array<string, scalar|null> $ctx
	 */
	private function interpolate(string $template, string $formula, array $ctx): string {
		if ($formula === '') {
			return $template;
		}

		// Split on top-level commas only.  The expressions are simple
		// enough that we don't need real parsing here, but a function
		// call could embed a comma — handle by tracking paren depth.
		$parts = [];
		$depth = 0;
		$buf = '';
		foreach (str_split($formula) as $ch) {
			if ($ch === '(') {
				$depth++;
				$buf .= $ch;
			} elseif ($ch === ')') {
				$depth--;
				$buf .= $ch;
			} elseif ($ch === ',' && $depth === 0) {
				$parts[] = trim($buf);
				$buf = '';
			} else {
				$buf .= $ch;
			}
		}
		if ($buf !== '') {
			$parts[] = trim($buf);
		}

		$values = [];
		foreach ($parts as $part) {
			try {
				$v = $this->evaluator->evaluate($part, $ctx);
				$values[] = is_bool($v) ? ($v ? 'true' : 'false') : $this->formatNumber($v);
			} catch (ExpressionException) {
				$values[] = '?';
			}
		}

		// vsprintf without strict checking — phpMyAdmin's templates use
		// %s and we coerce values to strings beforehand, so we don't
		// expect type mismatches.  Suppress warnings if the template
		// has fewer placeholders than we have values.
		$rendered = @vsprintf($template, $values);
		return $rendered === false ? $template : $rendered;
	}

	private function formatNumber(float $f): string {
		if (is_infinite($f)) {
			return '∞';
		}
		if (is_nan($f)) {
			return 'NaN';
		}
		// Drop trailing zeros after a decimal point (1.500 → 1.5,
		// 1.000 → 1) but keep precision when it matters.
		if (floor($f) === $f && abs($f) < 1e15) {
			return (string)(int)$f;
		}
		return rtrim(rtrim(sprintf('%.4f', $f), '0'), '.');
	}
}
