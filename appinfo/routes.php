<?php
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'config#oauthRedirect', 'url' => '/oauth-redirect', 'verb' => 'GET'],
		['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
		['name' => 'config#setSensitiveAdminConfig', 'url' => '/sensitive-admin-config', 'verb' => 'PUT'],
		['name' => 'config#popupSuccessPage', 'url' => '/popup-success', 'verb' => 'GET'],

		['name' => 'onedriveAPI#getStorageSize', 'url' => '/storage-size', 'verb' => 'GET'],
		['name' => 'onedriveAPI#importOnedrive', 'url' => '/import-files', 'verb' => 'GET'],
		['name' => 'onedriveAPI#getImportOnedriveInformation', 'url' => '/import-files-info', 'verb' => 'GET'],
		['name' => 'onedriveAPI#getCalendarList', 'url' => '/calendars', 'verb' => 'GET'],
		['name' => 'onedriveAPI#importCalendar', 'url' => '/import-calendar', 'verb' => 'GET'],
		['name' => 'onedriveAPI#getContactNumber', 'url' => '/contact-number', 'verb' => 'GET'],
		['name' => 'onedriveAPI#importContacts', 'url' => '/import-contacts', 'verb' => 'GET'],
		['name' => 'config#getLocalAddressBooks', 'url' => '/local-addressbooks', 'verb' => 'GET'],
	]
];
