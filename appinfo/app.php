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

namespace OCA\ServerInfo\AppInfo;

use OCP\AppFramework\App;

$app = new App('serverinfo');
$container = $app->getContainer();

$groupManager = \OC::$server->getGroupManager();
$user = \OC::$server->getUserSession()->getUser();
$isAdmin = $user !== null && $groupManager->isAdmin($user->getUID());


if ($isAdmin) {
	$container->query('OCP\INavigationManager')->add(function () use ($container) {
		$urlGenerator = $container->query('OCP\IURLGenerator');
		$l10n = $container->query('OCP\IL10N');
		return [
			// the string under which your app will be referenced in owncloud
			'id' => 'serverinfo',

			// sorting weight for the navigation. The higher the number, the higher
			// will it be listed in the navigation
			'order' => 10,

			// the route that will be shown on startup
			'href' => $urlGenerator->linkToRoute('serverinfo.page.index'),

			// the icon that will be shown in the navigation
			// this file needs to exist in img/
			'icon' => $urlGenerator->imagePath('serverinfo', 'app.svg'),

			// the title of your application. This will be used in the
			// navigation or on the settings page of your app
			'name' => $l10n->t('Server Info'),
		];
	});
}
