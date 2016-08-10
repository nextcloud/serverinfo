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

namespace OCA\ServerInfo\Controller;

use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCA\ServerInfo\DatabaseStatistics;
use OCA\ServerInfo\PhpStatistics;
use OCA\ServerInfo\SessionStatistics;
use OCA\ServerInfo\ShareStatistics;
use OCA\ServerInfo\StorageStatistics;
use OCA\ServerInfo\SystemStatistics;
use OCP\IURLGenerator;

class PageController extends Controller {

	/** @var SystemStatistics */
	private $systemStatistics;

	/** @var StorageStatistics */
	private $storageStatistics;

	/** @var PhpStatistics */
	private $phpStatistics;

	/** @var DatabaseStatistics  */
	private $databaseStatistics;

	/** @var ShareStatistics */
	private $shareStatistics;

	/** @var SessionStatistics */
	private $sessionStatistics;

	/**
	 * ApiController constructor.
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param SystemStatistics $systemStatistics
	 * @param StorageStatistics $storageStatistics
	 * @param PhpStatistics $phpStatistics
	 * @param DatabaseStatistics $databaseStatistics
	 * @param ShareStatistics $shareStatistics
	 * @param SessionStatistics $sessionStatistics
	 */
	public function __construct($appName,
								IRequest $request,
								SystemStatistics $systemStatistics,
								StorageStatistics $storageStatistics,
								PhpStatistics $phpStatistics,
								DatabaseStatistics $databaseStatistics,
								ShareStatistics $shareStatistics,
								SessionStatistics $sessionStatistics
	) {
		parent::__construct($appName, $request);

		$this->systemStatistics = $systemStatistics;
		$this->storageStatistics = $storageStatistics;
		$this->phpStatistics = $phpStatistics;
		$this->databaseStatistics = $databaseStatistics;
		$this->shareStatistics = $shareStatistics;
		$this->sessionStatistics = $sessionStatistics;
	}

	/**
	 * request data update
	 *
	 * @return JSONResponse
	 */
	public function update() {

		$data = [
			'system' => $this->systemStatistics->getSystemStatistics(),
			'storage' => $this->storageStatistics->getStorageStatistics(),
			'shares' => $this->shareStatistics->getShareStatistics(),
			'php' => $this->phpStatistics->getPhpStatistics(),
			'database' => $this->databaseStatistics->getDatabaseStatistics(),
			'activeUsers' => $this->sessionStatistics->getSessionStatistics()
		];

		return new JSONResponse($data);
	}


}
