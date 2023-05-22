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

namespace OCA\Onedrive\Controller;

use OCA\Onedrive\AppInfo\Application;
use OCA\Onedrive\Service\OnedriveCalendarAPIService;
use OCA\Onedrive\Service\OnedriveContactAPIService;
use OCA\Onedrive\Service\OnedriveStorageAPIService;
use OCP\AppFramework\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class OnedriveAPIController extends Controller {

	/**
	 * @var OnedriveContactAPIService
	 */
	private $onedriveContactApiService;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var OnedriveStorageAPIService
	 */
	private $onedriveStorageApiService;
	/**
	 * @var OnedriveCalendarAPIService
	 */
	private $onedriveCalendarApiService;
	/**
	 * @var string|null
	 */
	private $userId;
	/**
	 * @var string
	 */
	private $accessToken;

	public function __construct(string $appName,
		IRequest $request,
		IConfig $config,
		OnedriveStorageAPIService $onedriveStorageApiService,
		OnedriveCalendarAPIService $onedriveCalendarApiService,
		OnedriveContactAPIService $onedriveContactApiService,
		?string $userId) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->onedriveStorageApiService = $onedriveStorageApiService;
		$this->onedriveCalendarApiService = $onedriveCalendarApiService;
		$this->onedriveContactApiService = $onedriveContactApiService;
		$this->userId = $userId;
		$this->accessToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
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

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
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

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getImportOnedriveInformation(): DataResponse {
		if ($this->accessToken === '' || $this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse([
			'importing_onedrive' => $this->config->getUserValue($this->userId, Application::APP_ID, 'importing_onedrive') === '1',
			'onedrive_import_running' => $this->config->getUserValue($this->userId, Application::APP_ID, 'onedrive_import_running') === '1',
			'last_onedrive_import_timestamp' => (int) $this->config->getUserValue($this->userId, Application::APP_ID, 'last_onedrive_import_timestamp', '0'),
			'imported_size' => (int) $this->config->getUserValue($this->userId, Application::APP_ID, 'imported_size', '0'),
			'nb_imported_files' => (int) $this->config->getUserValue($this->userId, Application::APP_ID, 'nb_imported_files', '0'),
		]);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
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

	/**
	 * @NoAdminRequired
	 *
	 * @param string $calId
	 * @param string $calName
	 * @param ?string $color
	 * @return DataResponse
	 */
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

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
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

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
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
