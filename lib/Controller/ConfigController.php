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

use DateTime;
use OCA\Onedrive\AppInfo\Application;
use OCA\Onedrive\Service\OnedriveAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;

use OCP\Constants;
use OCP\Contacts\IManager as IContactManager;
use OCP\IConfig;
use OCP\IL10N;

use OCP\IRequest;
use OCP\IURLGenerator;

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
	/**
	 * @var IInitialState
	 */
	private $initialStateService;

	public function __construct(string $appName,
		IRequest $request,
		IConfig $config,
		IURLGenerator $urlGenerator,
		IL10N $l,
		IInitialState $initialStateService,
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
		$this->initialStateService = $initialStateService;
	}

	/**
	 * @NoAdminRequired
	 * Set config values
	 *
	 * @param array<string,string> $values key/value pairs to store in user preferences
	 * @return DataResponse
	 */
	public function setConfig(array $values): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
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
	 * @param array<string,string> $values key/value pairs to store in app config
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
	 * @param string $username
	 * @return TemplateResponse
	 */
	public function popupSuccessPage(string $username): TemplateResponse {
		$this->initialStateService->provideInitialState('popup-data', ['user_name' => $username]);
		return new TemplateResponse(Application::APP_ID, 'popupSuccess', [], TemplateResponse::RENDER_AS_GUEST);
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
			/** @var array{error?: string, access_token?: string, refresh_token?: string, scope?: string, expires_in?: string, user_id?: string} $result */
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
				if (isset($result['expires_in'])) {
					$nowTs = (new DateTime())->getTimestamp();
					$expiresAt = $nowTs + (int) $result['expires_in'];
					$this->config->setUserValue($this->userId, Application::APP_ID, 'token_expires_at', (string) $expiresAt);
				}

				$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $result['user_id'] ?? '');
				$info = $this->onedriveAPIService->request($this->userId, 'me');
				$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $info['displayName'] ?? '??');
				$usePopup = $this->config->getAppValue(Application::APP_ID, 'use_popup', '0') === '1';
				if ($usePopup) {
					return new RedirectResponse(
						$this->urlGenerator->linkToRoute(Application::APP_ID . '.config.popupSuccessPage', ['username' => $info['displayName'] ?? '??'])
					);
				} else {
					return new RedirectResponse(
						$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'migration']) .
						'?onedriveToken=success'
					);
				}
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
		foreach ($addressBooks as $ab) {
			if ($ab->getUri() !== 'system') {
				/** @var int $perms */
				$perms = $ab->getPermissions();
				$result[$ab->getKey()] = [
					'uri' => $ab->getUri(),
					'name' => $ab->getDisplayName(),
					'canEdit' => (bool)(($perms & Constants::PERMISSION_CREATE)),
				];
			}
		}
		return new DataResponse($result);
	}
}
