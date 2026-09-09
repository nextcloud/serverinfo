<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ServerInfo\Database\RuleSet;

use OCA\ServerInfo\Database\Rule;

/**
 * Nextcloud schema-integrity rules shared by the MySQL and PostgreSQL
 * rule sets.  They fire on the counts produced by
 * {@see \OCA\ServerInfo\Database\NextcloudSchema} — the same missing-index
 * / -column / -primary-key detection behind the `occ db:add-missing-*`
 * commands — so the recommended fix is exactly the occ command that
 * resolves them.
 *
 * These are schema migrations, not runtime tunables, so none carry an
 * ApplyDescriptor: the UI shows the recommendation text rather than an
 * Apply / snippet button.
 */
trait NextcloudSchemaRules {
	/**
	 * @return list<Rule>
	 */
	private function nextcloudSchemaRules(): array {
		return [
			new Rule(
				id: 'nc.missing_primary_keys',
				name: 'Missing primary keys',
				category: 'Nextcloud',
				severity: Rule::SEVERITY_ALERT,
				formula: 'nc_missing_primary_keys',
				test: 'value > 0',
				issue: 'One or more Nextcloud tables are missing a primary key. Tables without a primary key hurt replication and cluster performance.',
				recommendation: 'Run "occ db:add-missing-primary-keys" while the instance is in maintenance mode.',
				justification: '%s Nextcloud table(s) are missing an expected primary key.',
				justificationFormula: 'nc_missing_primary_keys',
				requires: ['nc_missing_primary_keys'],
				docUrl: 'https://docs.nextcloud.com/server/latest/admin_manual/occ_command.html#commands-label',
			),
			new Rule(
				id: 'nc.missing_indices',
				name: 'Missing indices',
				category: 'Nextcloud',
				severity: Rule::SEVERITY_WARNING,
				formula: 'nc_missing_indices',
				test: 'value > 0',
				issue: 'Nextcloud expects indices that are not present on the database. Missing indices force full-table scans and slow the instance down.',
				recommendation: 'Run "occ db:add-missing-indices" to create them (safe to run while online, though large tables take time).',
				justification: '%s expected index(es) are missing.',
				justificationFormula: 'nc_missing_indices',
				requires: ['nc_missing_indices'],
				docUrl: 'https://docs.nextcloud.com/server/latest/admin_manual/occ_command.html#commands-label',
			),
			new Rule(
				id: 'nc.missing_columns',
				name: 'Missing columns',
				category: 'Nextcloud',
				severity: Rule::SEVERITY_WARNING,
				formula: 'nc_missing_columns',
				test: 'value > 0',
				issue: 'Nextcloud expects columns that are not present on the database, usually left over from an interrupted upgrade.',
				recommendation: 'Run "occ db:add-missing-columns" while the instance is in maintenance mode.',
				justification: '%s expected column(s) are missing.',
				justificationFormula: 'nc_missing_columns',
				requires: ['nc_missing_columns'],
				docUrl: 'https://docs.nextcloud.com/server/latest/admin_manual/occ_command.html#commands-label',
			),
		];
	}
}
