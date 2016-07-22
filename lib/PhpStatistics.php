<?php
/**
 * @copyright Copyright (c) 2016 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\ServerInfo;


use bantu\IniGetWrapper\IniGetWrapper;

/**
 * Class php
 *
 * @package OCA\Survey_Client\Categories
 */
class PhpStatistics {

	/** @var IniGetWrapper */
	protected $phpIni;

	/**
	 * @param IniGetWrapper $phpIni
	 */
	public function __construct(IniGetWrapper $phpIni) {
		$this->phpIni = $phpIni;
	}

	public function getPhpStatistics() {
		return [
			'version' => $this->cleanVersion(PHP_VERSION),
			'memory_limit' => $this->phpIni->getBytes('memory_limit'),
			'max_execution_time' => $this->phpIni->getNumeric('max_execution_time'),
			'upload_max_filesize' => $this->phpIni->getBytes('upload_max_filesize'),
		];
	}

	/**
	 * Try to strip away additional information
	 *
	 * @param string $version E.g. `5.5.30-1+deb.sury.org~trusty+1`
	 * @return string `5.5.30`
	 */
	protected function cleanVersion($version) {
		$matches = [];
		preg_match('/^(\d+)(\.\d+)(\.\d+)/', $version, $matches);
		if (isset($matches[0])) {
			return $matches[0];
		}
		return $version;
	}
}
