<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\ServerInfo\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	private IL10N $l;
	private IURLGenerator $url;

	public function __construct(IL10N $l, IURLGenerator $url) {
		$this->l = $l;
		$this->url = $url;
	}

	/**
	 * returns the ID of the section. It is supposed to be a lower case string
	 */
	#[\Override]
	public function getID(): string {
		return 'serverinfo';
	}

	/**
	 * returns the translated name as it should be displayed, e.g. 'LDAP / AD
	 * integration'. Use the L10N service to translate it.
	 */
	#[\Override]
	public function getName(): string {
		return $this->l->t('System');
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 *             the settings navigation. The sections are arranged in ascending order of
	 *             the priority values. It is required to return a value between 0 and 99.
	 *
	 * keep the server setting at the top, right after "overview" and "basic settings"
	 */
	#[\Override]
	public function getPriority(): int {
		return 90;
	}

	/**
	 * {@inheritdoc}
	 */
	#[\Override]
	public function getIcon(): string {
		return $this->url->imagePath('serverinfo', 'app-dark.svg');
	}
}
