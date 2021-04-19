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

use OCP\App\IAppManager;
use OCP\Files\IAppData;
use OCP\AppFramework\Http\DataDisplayResponse;

use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IServerContainer;
use OCP\IL10N;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;

use OCP\AppFramework\Http\ContentSecurityPolicy;

use Psr\Log\LoggerInterface;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Onedrive\Service\OnedriveStorageAPIService;
use OCA\Onedrive\Service\OnedriveCalendarAPIService;
use OCA\Onedrive\Service\OnedriveContactAPIService;
use OCA\Onedrive\AppInfo\Application;

class OnedriveAPIController extends Controller {


	private $userId;
	private $config;
	private $dbconnection;
	private $dbtype;

	public function __construct($AppName,
								IRequest $request,
								IServerContainer $serverContainer,
								IConfig $config,
								IL10N $l10n,
								IAppManager $appManager,
								IAppData $appData,
								LoggerInterface $logger,
								OnedriveStorageAPIService $onedriveStorageApiService,
								OnedriveCalendarAPIService $onedriveCalendarApiService,
								OnedriveContactAPIService $onedriveContactApiService,
								$userId) {
		parent::__construct($AppName, $request);
		$this->userId = $userId;
		$this->AppName = $AppName;
		$this->l10n = $l10n;
		$this->appData = $appData;
		$this->serverContainer = $serverContainer;
		$this->config = $config;
		$this->logger = $logger;
		$this->onedriveStorageApiService = $onedriveStorageApiService;
		$this->onedriveCalendarApiService = $onedriveCalendarApiService;
		$this->onedriveContactApiService = $onedriveContactApiService;
		$this->accessToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'token', '');
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getStorageSize(): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		$result = $this->onedriveStorageApiService->getStorageSize($this->accessToken, $this->userId);
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
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		$result = $this->onedriveStorageApiService->startImportOnedrive($this->accessToken, $this->userId);
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
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		$response = new DataResponse([
			'importing_onedrive' => $this->config->getUserValue($this->userId, Application::APP_ID, 'importing_onedrive', '') === '1',
			'onedrive_import_running' => $this->config->getUserValue($this->userId, Application::APP_ID, 'onedrive_import_running', '') === '1',
			'last_onedrive_import_timestamp' => (int) $this->config->getUserValue($this->userId, Application::APP_ID, 'last_onedrive_import_timestamp', '0'),
			'imported_size' => (int) $this->config->getUserValue($this->userId, Application::APP_ID, 'imported_size', '0'),
			'nb_imported_files' => (int) $this->config->getUserValue($this->userId, Application::APP_ID, 'nb_imported_files', '0'),
		]);
		return $response;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getCalendarList(): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		$result = $this->onedriveCalendarApiService->getCalendarList($this->accessToken, $this->userId);
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
		if ($this->accessToken === '') {
			return new DataResponse('', 400);
		}
		$result = $this->onedriveCalendarApiService->importCalendar($this->accessToken, $this->userId, $calId, $calName, $color);
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
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		$result = $this->onedriveContactApiService->getContactNumber($this->accessToken, $this->userId);
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
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		$result = $this->onedriveContactApiService->importContacts($this->accessToken, $this->userId);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}
}
