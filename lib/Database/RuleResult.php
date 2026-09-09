<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ServerInfo\Database;

/**
 * Outcome of evaluating a single Rule against a Snapshot.
 *
 * Three terminal states:
 *  - ok      — the rule passed; nothing to report.
 *  - fail    — the rule's `test` expression evaluated to true.
 *  - skipped — required snapshot keys missing, or evaluation threw.
 */
final class RuleResult implements \JsonSerializable {
	public const STATUS_OK = 'ok';
	public const STATUS_FAIL = 'fail';
	public const STATUS_SKIPPED = 'skipped';

	/**
	 * @param array{variable:string,configKey:string,configFile:string,runtimeWritable:bool,recommendedValue:string,note:?string}|null $apply
	 * @param list<string>|null $details Names behind the numeric value (e.g. the
	 *                                   affected tables), shown by the UI alongside the justification.
	 */
	public function __construct(
		public readonly Rule $rule,
		public readonly string $status,
		public readonly ?string $justification = null,
		public readonly ?float $value = null,
		public readonly ?string $skipReason = null,
		public readonly ?array $apply = null,
		public readonly ?array $details = null,
	) {
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->rule->id,
			'name' => $this->rule->name,
			'category' => $this->rule->category,
			'severity' => $this->rule->severity,
			'status' => $this->status,
			'issue' => $this->rule->issue,
			'recommendation' => $this->rule->recommendation,
			'justification' => $this->justification,
			// json_encode() returns false on INF / NaN.  The expression
			// evaluator documents division-by-zero → INF (phpMyAdmin
			// semantics), so a single such rule would otherwise produce
			// an empty response body for the entire run.  Coerce here so
			// both the live response and the persisted history JSON are
			// always encodable.
			'value' => ($this->value !== null && is_finite($this->value)) ? $this->value : null,
			'skipReason' => $this->skipReason,
			'apply' => $this->apply,
			'details' => $this->details,
			'docUrl' => $this->rule->docUrl,
		];
	}
}
