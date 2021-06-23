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

use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Contacts\IManager as IContactManager;
use OCP\Constants;

use OCP\IRequest;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Onedrive\Service\OnedriveAPIService;
use OCA\Onedrive\AppInfo\Application;

class ConfigController extends Controller {

	/**
	 * @var string|null
	 */
	private $userId;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IContactManager
	 */
	private $contactsManager;
	/**
	 * @var IURLGenerator
	 */
	private $urlGenerator;
	/**
	 * @var OnedriveAPIService
	 */
	private $onedriveAPIService;
	/**
	 * @var IL10N
	 */
	private $l;

	public function __construct($appName,
								IRequest $request,
								IConfig $config,
								IURLGenerator $urlGenerator,
								IL10N $l,
								IContactManager $contactsManager,
								OnedriveAPIService $onedriveAPIService,
								?string $userId) {
		parent::__construct($appName, $request);
		$this->l = $l;
		$this->userId = $userId;
		$this->config = $config;
		$this->contactsManager = $contactsManager;
		$this->urlGenerator = $urlGenerator;
		$this->onedriveAPIService = $onedriveAPIService;
	}

	/**
	 * @NoAdminRequired
	 * Set config values
	 *
	 * @param array $values key/value pairs to store in user preferences
	 * @return DataResponse
	 */
	public function setConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
		}
		$result = [];

		if (isset($values['user_name']) && $values['user_name'] === '') {
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'token');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'refresh_token');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_id');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_name');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'oauth_state');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'redirect_uri');
			$result['user_name'] = '';
		}
		return new DataResponse($result);
	}

	/**
	 * Set admin config values
	 *
	 * @param array $values key/value pairs to store in app config
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}
		return new DataResponse(1);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * Receive oauth code and get oauth access token
	 *
	 * @param string $code request code to use when requesting oauth token
	 * @return RedirectResponse to user settings
	 */
	public function oauthRedirect(string $code = ''): RedirectResponse {
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');

		// anyway, reset state
		$this->config->setUserValue($this->userId, Application::APP_ID, 'oauth_state', '');

		if ($clientID && $clientSecret && $code !== '') {
			$redirectUri = $this->config->getUserValue($this->userId, Application::APP_ID, 'redirect_uri');
			$result = $this->onedriveAPIService->requestOAuthAccessToken([
				'client_id' => $clientID,
				'client_secret' => $clientSecret,
				'code' => $code,
				// 'state' => $state,
				'grant_type' => 'authorization_code',
				'redirect_uri' => $redirectUri,
			]);
			if (isset($result['access_token'], $result['refresh_token'])) {
				$accessToken = $result['access_token'];
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $accessToken);
				$refreshToken = $result['refresh_token'];
				$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', $refreshToken);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'scope', $result['scope'] ?? '');

				//$this->storeUserInfo($accessToken);
				$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $result['user_id'] ?? '');
				$info = $this->onedriveAPIService->request($accessToken, $this->userId, 'me');
				$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $info['displayName'] ?? '??');
				return new RedirectResponse(
					$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'migration']) .
					'?onedriveToken=success'
				);
			}
			$result = $this->l->t('Error getting OAuth access token') . ' ' . ($result['error'] ?? '');
		} else {
			$result = $this->l->t('Error during OAuth exchanges');
		}
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'migration']) .
			'?onedriveToken=error&message=' . urlencode($result)
		);
	}

	/**
	 * @NoAdminRequired
	 * Get local address book list
	 *
	 * @return DataResponse
	 */
	public function getLocalAddressBooks(): DataResponse {
		$addressBooks = $this->contactsManager->getUserAddressBooks();
		$result = [];
		foreach ($addressBooks as $k => $ab) {
			if ($ab->getUri() !== 'system') {
				$result[$ab->getKey()] = [
					'uri' => $ab->getUri(),
					'name' => $ab->getDisplayName(),
					'canEdit' => ($ab->getPermissions() & Constants::PERMISSION_CREATE) ? true : false,
				];
			}
		}
		return new DataResponse($result);
	}
}
