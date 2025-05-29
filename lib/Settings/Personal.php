<?php

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Onedrive\Settings;

use OCA\Onedrive\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Settings\ISettings;

class Personal implements ISettings {

	public function __construct(
		private IConfig $config,
		private IRootFolder $root,
		private IUserManager $userManager,
		private IInitialState $initialStateService,
		private string $userId,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$token = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');
		$navigationEnabled = $this->config->getUserValue($this->userId, Application::APP_ID, 'navigation_enabled', '0');
		$userName = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_name');

		// for OAuth
		/** @psalm-suppress DeprecatedMethod */
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		/** @psalm-suppress DeprecatedMethod */
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret') !== '';
		/** @psalm-suppress DeprecatedMethod */
		$usePopup = $this->config->getAppValue(Application::APP_ID, 'use_popup', '0');

		// get free space
		$userFolder = $this->root->getUserFolder($this->userId);
		$freeSpace = $userFolder->getStorage()->free_space('/');
		$user = $this->userManager->get($this->userId);

		$onedriveOutputDir = $this->config->getUserValue($this->userId, Application::APP_ID, 'onedrive_output_dir', '/OneDrive import');

		$userConfig = [
			'token' => $token !== '' ? 'dummyToken' : '',
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'use_popup' => ($usePopup === '1'),
			'navigation_enabled' => ($navigationEnabled === '1'),
			'user_name' => $userName,
			'free_space' => $freeSpace,
			'user_quota' => $user !== null? $user->getQuota() : '',
			'onedrive_output_dir' => $onedriveOutputDir,
		];
		$this->initialStateService->provideInitialState('user-config', $userConfig);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'migration';
	}

	public function getPriority(): int {
		return 10;
	}
}
