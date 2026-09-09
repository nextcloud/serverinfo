<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ServerInfo\Database;

/**
 * A single advisor rule.
 *
 * Rules are pure data + two small expressions (`formula`, `test`) and
 * one English template (`justification`).  All expressions are
 * evaluated by the safe ExpressionEvaluator — there is no PHP `eval()`
 * in the chain.
 *
 * `requires` lists the snapshot keys the rule depends on.  When any
 * of those keys are absent (e.g. on a different DB flavour, or when
 * the connection lacks privileges to read them), the Advisor marks
 * the rule's result as "skipped" instead of evaluating with a
 * potentially garbage value.
 *
 * Severities map to score deductions in {@see \OCA\ServerInfo\Database\Score}.
 */
final class Rule {
	public const SEVERITY_ALERT = 'alert';
	public const SEVERITY_WARNING = 'warning';
	public const SEVERITY_NOTICE = 'notice';

	/**
	 * @param string $id Stable identifier; for ports of phpMyAdmin rules this matches the upstream id verbatim.
	 * @param string $name Short human title.
	 * @param string $category Visual grouping ("Performance" | "Memory" | …).
	 * @param self::SEVERITY_* $severity One of SEVERITY_*.
	 * @param string $formula Expression yielding the rule's primary numeric `value`.
	 * @param string $test Boolean expression — when it returns true, the rule is considered failed.
	 * @param string $issue One-sentence English description of what's wrong when the rule fails.
	 * @param string $recommendation One-sentence English suggestion for how to fix it.
	 * @param string $justification sprintf-template that interpolates one or more values for the result line.
	 * @param string $justificationFormula Comma-separated expressions whose values fill the template.
	 * @param ApplyDescriptor|null $apply Optional applicator for runtime-writable variables.
	 * @param list<string> $requires Snapshot keys the rule reads (status / variables names).
	 * @param string|null $docUrl Optional "learn more" link shown on the rule card.
	 * @param string|null $detailsKey Optional key into the snapshot's details map;
	 *                                when the rule fails, the named list (e.g. affected table names) is
	 *                                attached to the result for the UI to show alongside the justification.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $category,
		public readonly string $severity,
		public readonly string $formula,
		public readonly string $test,
		public readonly string $issue,
		public readonly string $recommendation,
		public readonly string $justification,
		public readonly string $justificationFormula,
		public readonly ?ApplyDescriptor $apply = null,
		public readonly array $requires = [],
		public readonly ?string $docUrl = null,
		public readonly ?string $detailsKey = null,
	) {
	}
}
