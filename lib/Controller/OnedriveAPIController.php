<?php

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Onedrive\Controller;

use OCA\Onedrive\AppInfo\Application;
use OCA\Onedrive\Service\OnedriveCalendarAPIService;
use OCA\Onedrive\Service\OnedriveContactAPIService;
use OCA\Onedrive\Service\OnedriveStorageAPIService;
use OCP\AppFramework\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Security\ICrypto;

class OnedriveAPIController extends Controller {

	private string $accessToken;

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private OnedriveStorageAPIService $onedriveStorageApiService,
		private OnedriveCalendarAPIService $onedriveCalendarApiService,
		private OnedriveContactAPIService $onedriveContactApiService,
		private ICrypto $crypto,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
		$accessToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');
		$this->accessToken = $accessToken === '' ? '' : $this->crypto->decrypt($accessToken);
	}

	#[NoAdminRequired]
	public function getStorageSize(): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		/** @var array{error: string} $result */
		$result = $this->onedriveStorageApiService->getStorageSize($this->userId);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}

	#[NoAdminRequired]
	public function importOnedrive(): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		/** @var array{error: string} $result */
		$result = $this->onedriveStorageApiService->startImportOnedrive($this->userId);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}

	#[NoAdminRequired]
	public function getImportOnedriveInformation(): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse([
			'importing_onedrive' => $this->config->getUserValue($this->userId, Application::APP_ID, 'importing_onedrive') === '1',
			'onedrive_import_running' => $this->config->getUserValue($this->userId, Application::APP_ID, 'onedrive_import_running') === '1',
			'last_onedrive_import_timestamp' => (int)$this->config->getUserValue($this->userId, Application::APP_ID, 'last_onedrive_import_timestamp', '0'),
			'imported_size' => (int)$this->config->getUserValue($this->userId, Application::APP_ID, 'imported_size', '0'),
			'nb_imported_files' => (int)$this->config->getUserValue($this->userId, Application::APP_ID, 'nb_imported_files', '0'),
		]);
	}

	#[NoAdminRequired]
	public function getCalendarList(): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		/** @var array{error: string} $result */
		$result = $this->onedriveCalendarApiService->getCalendarList($this->userId);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}

	#[NoAdminRequired]
	public function importCalendar(string $calId, string $calName, ?string $color = null): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		/** @var array{error: string} $result */
		$result = $this->onedriveCalendarApiService->importCalendar($this->userId, $calId, $calName, $color);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}

	#[NoAdminRequired]
	public function getContactNumber(): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		/** @var array{error: string} $result */
		$result = $this->onedriveContactApiService->getContactNumber($this->userId);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}

	#[NoAdminRequired]
	public function importContacts(): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		/** @var array{error: string} $result */
		$result = $this->onedriveContactApiService->importContacts($this->userId);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}
}
