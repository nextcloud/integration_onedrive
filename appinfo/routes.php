<?php
/**
 * Nextcloud - onedrive
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

return [
    'routes' => [
        ['name' => 'config#oauthRedirect', 'url' => '/oauth-redirect', 'verb' => 'GET'],
        ['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
        ['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
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
