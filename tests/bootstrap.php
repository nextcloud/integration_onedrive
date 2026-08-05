<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/../../../tests/bootstrap.php';

use OCA\Onedrive\AppInfo\Application;

\OC_App::loadApp(Application::APP_ID);
OC_Hook::clear();
