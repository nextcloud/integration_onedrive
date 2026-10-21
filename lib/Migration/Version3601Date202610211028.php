<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\Onedrive\Migration;

use Closure;
use OCA\Onedrive\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version3601Date202610211028 extends SimpleMigrationStep {

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// mark the app config values lazy that earlier versions stored as non-lazy ones
		$this->appConfig->updateLazy(Application::APP_ID, 'client_id', true);
		$this->appConfig->updateLazy(Application::APP_ID, 'client_secret', true);
		$this->appConfig->updateLazy(Application::APP_ID, 'use_popup', true);
		$this->appConfig->updateLazy(Application::APP_ID, 'api_base_url', true);
		$this->appConfig->updateLazy(Application::APP_ID, 'import_batch_size', true);
	}
}
